<?php

namespace StacklessEDD\Modules\StripeGateway\Managed;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Modules\StripeGateway\Amount;
use StacklessEDD\Modules\StripeGateway\Declines;
use StacklessEDD\Modules\StripeGateway\ErrorMapper;
use StacklessEDD\Modules\StripeGateway\Orders;
use StacklessEDD\Modules\StripeGateway\Payments;
use StacklessEDD\Modules\StripeGateway\Settings;
use StacklessEDD\Modules\StripeGateway\StripeApiException;

defined('ABSPATH') || exit;

/**
 * Applies Managed Payments checkouts, renewals and subscription state to EDD.
 *
 * Every entry point (browser return, webhooks) funnels through these methods and each is
 * safe to repeat: orders are settled under the Stripe module's order lock and only while
 * unpaid, renewals are keyed by Stripe invoice id, and objects are always read back from
 * Stripe before anything changes.
 */
class Sync
{
    /** Thrown code that makes the webhook answer 409 so Stripe retries later. */
    public const DEFERRED = 409;

    /** True while a status change Stripe already made is applied; the gateway's cancel then skips the API. */
    public static $applying = false;

    /* ---- First payments ---------------------------------------------------------- */

    /**
     * Reads a session back from Stripe and settles the order when it is paid (browser return).
     *
     * @return true|\WP_Error
     */
    public function confirmById(Order $order, string $session_id)
    {
        $mode = Gateway::orderMode((int) $order->id) === 'live' ? 'live' : 'test';
        try {
            $session = Gateway::client($mode)->get('checkout/sessions/' . rawurlencode($session_id));
        } catch (StripeApiException $e) {
            return new \WP_Error('stackless_edd_stripe_managed_lookup', $e->getMessage());
        }
        if (($session['status'] ?? '') === 'open') {
            return new \WP_Error('stackless_edd_stripe_managed_open', __('The Stripe checkout is still open.', 'stackless-lite-edd'));
        }
        $result = $this->confirmSession($order, $session, $mode);
        if ($result === 'busy') {
            return new \WP_Error('stackless_edd_stripe_managed_busy', __('The order is being updated by another request.', 'stackless-lite-edd'));
        }
        return true;
    }

    /**
     * Settles an order from its completed Checkout Session.
     *
     * @param array<string,mixed> $session
     * @return string Outcome for logs: paid, processing, failed, expired, mismatch, busy…
     */
    public function confirmSession(Order $order, array $session, string $mode): string
    {
        $metadata = (array) ($session['metadata'] ?? []);
        $order_id = (int) $order->id;
        if ((string) ($metadata['order_id'] ?? '') !== (string) $order_id || !self::isOurs($metadata)) {
            return 'not ours';
        }
        $session_id = (string) ($session['id'] ?? '');
        $lock = Payments::lock($order_id);
        if ($lock === null) {
            return 'busy';
        }
        try {
            $order = Orders::get($order_id) ?: $order;
            if ((string) $order->gateway !== Gateway::ID || (string) Orders::meta($order_id, Gateway::META_SESSION) !== $session_id) {
                return 'session mismatch';
            }
            $status = (string) ($session['status'] ?? '');
            $payment_status = (string) ($session['payment_status'] ?? '');
            if (Orders::isPaid($order)) {
                $this->linkFromSession($order_id, $session, $mode);
                return 'already paid';
            }
            if ($status === 'expired') {
                if (Orders::needsPayment($order)) {
                    /* translators: %s: Checkout Session id */
                    Orders::note($order_id, sprintf(__('Stripe checkout %s expired before the customer paid.', 'stackless-lite-edd'), $session_id));
                }
                return 'expired';
            }
            if ($status !== 'complete') {
                return 'open';
            }

            $order_currency = strtoupper((string) $order->currency);
            $currency = strtoupper((string) ($session['currency'] ?? ''));
            if ($currency !== '' && $currency !== $order_currency) {
                Orders::setStatus($order_id, 'on_hold', sprintf(
                    /* translators: 1: order currency, 2: charged currency */
                    __('Stripe charged in %2$s but the order is in %1$s. Check the payment in Stripe.', 'stackless-lite-edd'),
                    $order_currency,
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $order_currency;
            $decimals = Amount::decimals($currency);
            Orders::updateMeta($order_id, Payments::META_MODE, $mode);
            Orders::updateMeta($order_id, Gateway::META_TAX, stackless_edd_decimal(Amount::fromMinor((int) ($session['total_details']['amount_tax'] ?? 0), $currency), $decimals));
            Orders::updateMeta($order_id, Gateway::META_TOTAL, stackless_edd_decimal(Amount::fromMinor((int) ($session['amount_total'] ?? 0), $currency), $decimals));
            $this->linkFromSession($order_id, $session, $mode);

            if ((int) ($session['amount_subtotal'] ?? -1) !== Amount::toMinor($order->total, $currency)) {
                Orders::setStatus($order_id, 'on_hold', sprintf(
                    /* translators: 1: amount before tax Stripe charged, 2: order total */
                    __('Stripe charged %1$s before tax but the order total is %2$s. Check the payment in Stripe before delivering the files.', 'stackless-lite-edd'),
                    Amount::fromMinor((int) ($session['amount_subtotal'] ?? 0), $currency) . ' ' . $currency,
                    stackless_edd_decimal($order->total, $decimals) . ' ' . $currency
                ));
                return 'amount mismatch';
            }

            if ($payment_status === 'unpaid') {
                if (!Orders::hasStatus($order, 'processing')) {
                    Orders::setStatus($order_id, 'processing', __('Stripe is processing the payment. The order updates when the bank confirms it.', 'stackless-lite-edd'));
                }
                return 'processing';
            }
            if (!in_array($payment_status, ['paid', 'no_payment_required'], true)) {
                return 'unknown payment status';
            }

            $intent = $this->sessionPaymentIntent($session, $mode);
            if ($intent !== '') {
                self::rememberIntent($order_id, $intent);
            }
            Orders::note($order_id, sprintf(
                /* translators: 1: Checkout Session id, 2: total charged including tax */
                __('Stripe Managed Payments checkout %1$s paid (%2$s charged including tax).', 'stackless-lite-edd'),
                $session_id,
                wp_strip_all_tags(stackless_edd_price(Amount::fromMinor((int) ($session['amount_total'] ?? 0), $currency), $currency))
            ));
            stackless_edd_complete_order($order_id, $intent !== '' ? $intent : $session_id);
            return 'paid';
        } finally {
            Payments::unlock($order_id, $lock);
        }
    }

    /**
     * Async payment (bank debit) failed after the session completed.
     *
     * @param array<string,mixed> $session
     */
    public function sessionFailed(Order $order, array $session): string
    {
        $order_id = (int) $order->id;
        if (Orders::isPaid($order) || (string) Orders::meta($order_id, Gateway::META_SESSION) !== (string) ($session['id'] ?? '')) {
            return 'ignored';
        }
        Orders::setStatus($order_id, 'failed', __('The customer’s bank declined the Stripe Managed Payments payment.', 'stackless-lite-edd'));
        return 'failed';
    }

    /**
     * Write the Stripe subscription and customer on the order and its subscription.
     *
     * @param array<string,mixed> $session
     */
    private function linkFromSession(int $order_id, array $session, string $mode): void
    {
        $customer = EventMap::idOf($session['customer'] ?? '');
        $remote = EventMap::idOf($session['subscription'] ?? '');
        $invoice = EventMap::idOf($session['invoice'] ?? '');
        if ($invoice !== '') {
            Orders::updateMeta($order_id, Gateway::META_INVOICE, $invoice);
        }
        if ($customer !== '') {
            Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        if ($remote === '') {
            return;
        }
        Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote);
        foreach (stackless_edd_get_order_subscriptions($order_id) as $subscription) {
            if (!$subscription instanceof SubscriptionData) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote);
            if ($customer !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $subscription->update_meta(Payments::META_MODE, $mode);
        }
    }

    /**
     * PaymentIntent that paid a session: its own (payment mode) or its first invoice's (subscription mode).
     *
     * @param array<string,mixed> $session
     */
    private function sessionPaymentIntent(array $session, string $mode): string
    {
        $intent = EventMap::idOf($session['payment_intent'] ?? '');
        if ($intent !== '') {
            return $intent;
        }
        $invoice = EventMap::idOf($session['invoice'] ?? '');
        if ($invoice === '') {
            return '';
        }
        try {
            return EventMap::invoicePaymentIntent(Gateway::client($mode)->get('invoices/' . rawurlencode($invoice), ['expand' => ['payments']]));
        } catch (StripeApiException $e) {
            Settings::log('Could not read invoice ' . $invoice . ': ' . $e->logSummary(), 'warning');
            return '';
        }
    }

    /** The intent that paid the order, kept where refunds, disputes and Dashboard refunds look. */
    private static function rememberIntent(int $order_id, string $intent): void
    {
        Orders::updateMeta($order_id, Payments::META_INTENT, $intent);
        $history = Orders::meta($order_id, Payments::META_INTENTS);
        $history = is_array($history) ? $history : [];
        if (!in_array($intent, $history, true)) {
            $history[] = $intent;
            Orders::updateMeta($order_id, Payments::META_INTENTS, array_slice($history, -20));
        }
        Orders::updateMeta($order_id, Payments::META_CAPTURED, 'yes');
    }

    /** Objects created by another site on the same Stripe account carry a different marker. */
    public static function isOurs(array $metadata): bool
    {
        $marker = (string) ($metadata['stackless_site'] ?? '');
        return $marker === '' || $marker === Settings::siteMarker();
    }

    /* ---- Lookups ------------------------------------------------------------------ */

    /** The Stackless subscription linked to a Stripe subscription (by stored id, else metadata). */
    public function findSubscription(string $remote_id, array $metadata): ?SubscriptionData
    {
        if ($remote_id !== '') {
            // The parent order and every renewal carry the Stripe id; each leads to its subscription.
            foreach (self::ordersWithMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote_id) as $order) {
                $subscriptions = array_values(array_filter(stackless_edd_get_order_subscriptions((int) $order->id), static function ($s): bool {
                    return $s instanceof SubscriptionData;
                }));
                foreach ($subscriptions as $subscription) {
                    if ((string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === $remote_id) {
                        return $subscription;
                    }
                }
                if (count($subscriptions) === 1 && $subscriptions[0]->get_parent_order_id() === (int) $order->id) {
                    return $subscriptions[0];
                }
            }
        }
        if (!self::isOurs($metadata) || empty($metadata['stackless_managed'])) {
            return null;
        }
        $id = absint($metadata['subscription_id'] ?? 0);
        $subscription = $id ? stackless_edd_get_subscription($id) : null;
        if (!$subscription instanceof SubscriptionData) {
            return null;
        }
        $parent = absint($metadata['order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_order_id() !== $parent) || ($linked !== '' && $remote_id !== '' && $linked !== $remote_id)) {
            return null;
        }
        return $subscription;
    }

    /** @return Order[] Sale orders (newest first) whose $key meta is exactly $value. */
    public static function ordersWithMeta(string $key, string $value, int $limit = 5): array
    {
        if ($value === '' || !function_exists('edd_get_orders')) {
            return [];
        }
        $orders = edd_get_orders([
            'type__not_in' => ['refund'],
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds orders by the provider's ID, once per webhook.
            'number' => $limit,
            'orderby' => 'id',
            'order' => 'DESC',
        ]);
        $found = [];
        foreach ((array) $orders as $order) {
            if ($order instanceof Order && (string) Orders::meta((int) $order->id, $key) === $value) {
                $found[] = $order;
            }
        }
        return $found;
    }

    /* ---- Invoices ------------------------------------------------------------------ */

    /**
     * invoice.paid: the first invoice settles the order; cycle invoices become renewal orders.
     *
     * @param array<string,mixed> $invoice
     */
    public function invoicePaid(array $invoice, string $mode): string
    {
        $kind = EventMap::invoiceKind($invoice);
        if ($kind === 'first') {
            $metadata = EventMap::invoiceSubscriptionMetadata($invoice);
            if (empty($metadata['stackless_managed']) || !self::isOurs($metadata)) {
                return 'not ours';
            }
            $order = Orders::get(absint($metadata['order_id'] ?? 0));
            if (!$order || (string) $order->gateway !== Gateway::ID) {
                return 'no matching order';
            }
            if (Orders::isPaid($order)) {
                return 'already paid';
            }
            $session = (string) Orders::meta((int) $order->id, Gateway::META_SESSION);
            $result = $session !== '' ? $this->confirmById($order, $session) : new \WP_Error('none', '');
            if (is_wp_error($result) && $result->get_error_code() === 'stackless_edd_stripe_managed_busy') {
                throw new \RuntimeException('Order is locked by another request; retry later.', self::DEFERRED);
            }
            return 'first invoice: ' . (is_wp_error($result) ? $result->get_error_code() : 'confirmed');
        }
        if ($kind !== 'renewal') {
            return 'ignored ' . (string) ($invoice['billing_reason'] ?? '');
        }
        return $this->recordRenewal($invoice, $mode);
    }

    /**
     * A renewal Stripe charged becomes a paid EDD renewal order.
     *
     * @param array<string,mixed> $invoice
     */
    public function recordRenewal(array $invoice, string $mode): string
    {
        $invoice_id = (string) ($invoice['id'] ?? '');
        $remote_id = EventMap::invoiceSubscription($invoice);
        if ($invoice_id === '' || $remote_id === '') {
            return 'no subscription';
        }
        $subscription = $this->findSubscription($remote_id, EventMap::invoiceSubscriptionMetadata($invoice));
        if (!$subscription) {
            Settings::log('Renewal invoice ' . $invoice_id . ' arrived for unknown Stripe subscription ' . $remote_id . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', Gateway::ID], true)) {
            return 'other gateway';
        }
        $lock = self::lock('renewal-' . $invoice_id);
        if ($lock === null) {
            throw new \RuntimeException('Subscription is locked by another request; retry later.', self::DEFERRED);
        }
        try {
            if (self::ordersWithMeta(Gateway::META_INVOICE, $invoice_id, 1)) {
                return 'duplicate';
            }
            $renewal = null;
            $candidate = Orders::get(absint($subscription->get_meta(Gateway::META_AWAITING)));
            if ($candidate && !Orders::isPaid($candidate) && Orders::meta((int) $candidate->id, Gateway::META_AWAITING) === 'yes') {
                $renewal = $candidate;
            }
            if (!$renewal) {
                $created = stackless_edd_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Stripe retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = Orders::get((int) $created);
                if (!$renewal) {
                    throw new \RuntimeException('Could not load renewal order ' . (int) $created . '.');
                }
            }
            $renewal_id = (int) $renewal->id;
            $renewal_currency = strtoupper((string) $renewal->currency);
            $currency = strtoupper((string) ($invoice['currency'] ?? $renewal_currency));
            $decimals = Amount::decimals($currency);
            $intent = EventMap::invoicePaymentIntent($invoice);
            Gateway::setGateway($renewal);
            Orders::deleteMeta($renewal_id, Gateway::META_AWAITING);
            Orders::updateMeta($renewal_id, Gateway::META_INVOICE, $invoice_id);
            Orders::updateMeta($renewal_id, Payments::META_MODE, $mode);
            Orders::updateMeta($renewal_id, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote_id);
            if ($intent !== '') {
                self::rememberIntent($renewal_id, $intent);
            }
            Orders::updateMeta($renewal_id, Gateway::META_TOTAL, stackless_edd_decimal(Amount::fromMinor((int) ($invoice['amount_paid'] ?? $invoice['total'] ?? 0), $currency), $decimals));
            Orders::updateMeta($renewal_id, Gateway::META_TAX, stackless_edd_decimal(Amount::fromMinor(self::invoiceTax($invoice), $currency), $decimals));
            $subscription->update_meta(Gateway::META_AWAITING, '');

            if ($currency !== $renewal_currency) {
                Orders::setStatus($renewal_id, 'on_hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Stripe charged this renewal in %2$s but the subscription bills in %1$s. Check it in Stripe.', 'stackless-lite-edd'),
                    $renewal_currency,
                    $currency
                ));
                return 'currency mismatch';
            }
            $subtotal = (int) ($invoice['subtotal'] ?? -1);
            if ($subtotal !== Amount::toMinor($renewal->total, $currency)) {
                // Stripe already took the money; record it and flag the difference.
                Orders::note($renewal_id, sprintf(
                    /* translators: 1: amount before tax Stripe charged, 2: renewal total */
                    __('Stripe charged %1$s before tax for this renewal; the EDD renewal total is %2$s.', 'stackless-lite-edd'),
                    Amount::fromMinor(max(0, $subtotal), $currency) . ' ' . $currency,
                    stackless_edd_decimal($renewal->total, $decimals) . ' ' . $currency
                ));
            }
            /* translators: %s: Stripe invoice id */
            Orders::note($renewal_id, sprintf(__('Stripe charged this renewal (invoice %s).', 'stackless-lite-edd'), $invoice_id));
            stackless_edd_complete_order($renewal_id, $intent !== '' ? $intent : $invoice_id);
            return 'renewed';
        } finally {
            self::unlock('renewal-' . $invoice_id, $lock);
        }
    }

    /**
     * invoice.payment_failed: Stripe retries the invoice itself (Smart Retries) and reports
     * past_due through the subscription, so the renewal order is not failed here. The decline
     * category is still recorded on it, for Billing Health and for Dunning if it runs.
     *
     * @param array<string,mixed> $invoice
     */
    public function invoiceFailed(array $invoice, string $mode): string
    {
        $kind = EventMap::invoiceKind($invoice);
        $remote_id = EventMap::invoiceSubscription($invoice);
        $subscription = $remote_id !== '' ? $this->findSubscription($remote_id, EventMap::invoiceSubscriptionMetadata($invoice)) : null;
        $order = null;
        if ($kind === 'renewal' && $subscription) {
            $order = Orders::get(absint($subscription->get_meta(Gateway::META_AWAITING)));
        } elseif ($kind === 'first') {
            $metadata = EventMap::invoiceSubscriptionMetadata($invoice);
            $candidate = self::isOurs($metadata) ? Orders::get(absint($metadata['order_id'] ?? 0)) : null;
            $order = $candidate && (string) $candidate->gateway === Gateway::ID ? $candidate : null;
        }
        if (!$order && !($kind === 'renewal' && $subscription)) {
            return 'not ours';
        }
        [$category, $code] = $this->invoiceDecline($invoice, $mode);
        $note = sprintf(
            /* translators: 1: Stripe invoice id, 2: decline code */
            __('Stripe could not collect invoice %1$s (%2$s). Stripe retries it on its own schedule.', 'stackless-lite-edd'),
            (string) ($invoice['id'] ?? ''),
            $code
        );
        if ($order) {
            if (!Orders::isPaid($order)) {
                Declines::write((int) $order->id, $category, $code);
            }
            Orders::note((int) $order->id, $note);
        } elseif ($subscription) {
            self::note($subscription, $note);
        }
        return 'noted ' . $category;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array{0:string,1:string} Category and code of an invoice's failed payment.
     */
    private function invoiceDecline(array $invoice, string $mode): array
    {
        $intent = EventMap::invoicePaymentIntent($invoice);
        if ($intent !== '') {
            try {
                $pi = Gateway::client($mode)->get('payment_intents/' . rawurlencode($intent));
                $error = isset($pi['last_payment_error']) && is_array($pi['last_payment_error']) ? $pi['last_payment_error'] : [];
                if ($error) {
                    $code = (string) ($error['decline_code'] ?? '') ?: (string) ($error['code'] ?? '');
                    return [ErrorMapper::declineCategoryFromIntentError($error), $code !== '' ? $code : 'declined'];
                }
            } catch (StripeApiException $e) {
                Settings::log('Could not read ' . $intent . ': ' . $e->logSummary(), 'warning');
            }
        }
        $code = EventMap::invoiceDeclineCode($invoice);
        return [ErrorMapper::declineCategory($code), $code !== '' ? $code : 'declined'];
    }

    /** @param array<string,mixed> $invoice */
    private static function invoiceTax(array $invoice): int
    {
        if (isset($invoice['tax'])) {
            return (int) $invoice['tax'];
        }
        $tax = 0;
        foreach ((array) ($invoice['total_taxes'] ?? []) as $row) {
            $tax += is_array($row) ? (int) ($row['amount'] ?? 0) : 0;
        }
        return $tax;
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /**
     * Bring a Stackless subscription in line with a Stripe subscription.
     *
     * @param array<string,mixed> $remote
     */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $remote_id = (string) ($remote['id'] ?? '');
        if ($remote_id === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($remote_id, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', Gateway::ID], true)) {
            return 'other gateway';
        }

        $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $remote_id);
        $customer = EventMap::idOf($remote['customer'] ?? '');
        if ($customer !== '') {
            $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $subscription->update_meta(Payments::META_MODE, $mode);
        $subscription->update_meta(Gateway::META_CANCEL_AT_END, EventMap::cancelScheduled($remote) ? 'yes' : 'no');
        $end = EventMap::periodEnd($remote);
        if ($end > 0) {
            $subscription->update_meta(Gateway::META_PERIOD_END, (string) $end);
        }
        // Stripe runs the schedule; let the Subscriptions module show Stripe's next charge date.
        $next = EventMap::nextCharge($remote);
        if ($next > 0) {
            $subscription->set_next_payment_date(new \DateTimeImmutable('@' . $next));
        }

        $target = EventMap::status($remote);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($target !== null && in_array($target, $active, true) && $parent && !Orders::isPaid($parent)) {
            return 'awaiting first payment';
        }
        if ($target === null) {
            return 'no status change';
        }
        $fresh = stackless_edd_get_subscription($subscription->get_id());
        $subscription = $fresh instanceof SubscriptionData ? $fresh : $subscription;
        $current = $subscription->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }
        /* translators: %s: Stripe subscription status */
        $note = sprintf(__('Stripe subscription is now %s.', 'stackless-lite-edd'), (string) ($remote['status'] ?? $event));
        if ($target === SubscriptionData::STATUS_PENDING_CANCEL) {
            $note = __('Stripe subscription is set to end at the close of the paid period.', 'stackless-lite-edd');
        } elseif ($target === SubscriptionData::STATUS_PAUSED) {
            $note = __('Stripe paused collection for this subscription.', 'stackless-lite-edd');
        }
        $applied = self::setStatus($subscription, $target, $note);
        if ($applied === '') {
            Settings::log('Subscription ' . $subscription->get_id() . ' could not move to ' . $target . ' (' . $event . ').', 'warning');
            return 'status ' . $target . ' not applied';
        }
        do_action('stackless_edd_stripe_managed_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $applied;
    }

    /**
     * Moves a subscription to the status Stripe reports, without calling Stripe back. A paused
     * subscription falls back to on hold while Subscriptions doesn't know the paused status.
     *
     * @return string The status applied, or '' when none could be.
     */
    public static function setStatus(SubscriptionData $subscription, string $status, string $note): string
    {
        if (!function_exists('stackless_edd_update_subscription_status')) {
            return '';
        }
        self::$applying = true;
        try {
            $result = stackless_edd_update_subscription_status($subscription->get_id(), $status, $note);
            if (is_wp_error($result) && $status === SubscriptionData::STATUS_PAUSED && $result->get_error_code() === 'stackless_edd_invalid_status') {
                $status = SubscriptionData::STATUS_ON_HOLD;
                $result = $subscription->get_status() === $status ? true : stackless_edd_update_subscription_status($subscription->get_id(), $status, $note);
            }
        } finally {
            self::$applying = false;
        }
        return $result === true ? $status : '';
    }

    /** A note on the subscription (falls back to the parent order's notes). */
    public static function note(SubscriptionData $subscription, string $note): void
    {
        if ($note === '') {
            return;
        }
        if (function_exists('stackless_edd_add_subscription_note')) {
            stackless_edd_add_subscription_note($subscription->get_id(), $note);
            return;
        }
        /* translators: 1: subscription id, 2: note */
        stackless_edd_add_order_note($subscription->get_parent_order_id(), sprintf(__('Subscription #%1$d: %2$s', 'stackless-lite-edd'), $subscription->get_id(), $note));
    }

    /* ---- Locks ---------------------------------------------------------------------- */

    /** A short-lived named lock in the options table; null when another request holds it. */
    public static function lock(string $name): ?string
    {
        global $wpdb;
        $option = 'stackless_edd_stripe_managed_lock_' . md5($name);
        $value = time() . ':' . bin2hex(random_bytes(10));
        for ($i = 0; $i < 20; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, $value))) {
                return $value;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
            $since = (int) $current;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($since > 0 && time() - $since > 60 && $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $option, $current))) {
                return $value;
            }
            usleep(250000);
        }
        return null;
    }

    public static function unlock(string $name, string $token): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => 'stackless_edd_stripe_managed_lock_' . md5($name), 'option_value' => $token]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases this request's lock only while it still holds the token.
    }
}
