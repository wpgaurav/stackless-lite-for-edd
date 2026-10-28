<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Dodo Payments payment, renewal, refund and subscription state to EDD.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while unpaid,
 * renewals are keyed by Dodo payment id, refunds by Dodo refund id. Dodo sends
 * payment.succeeded and subscription.renewed for every renewal; whichever lands
 * first records it.
 */
class Sync
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /* ---- Lookups ------------------------------------------------------------------ */

    /** An order of this gateway (original or copy) with a meta value. */
    public function findOrder(string $key, string $value): ?Order
    {
        return Orders::findByMeta($key, $value, $this->gateway->id());
    }

    /** Stackless subscription linked to a Dodo subscription (by stored id, else by checkout metadata). */
    public function findSubscription(string $dodoId, array $metadata): ?SubscriptionData
    {
        if ($dodoId !== '') {
            // The parent order and every renewal carry the Dodo id; each leads to its subscription.
            foreach (Orders::findAllByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoId) as $order) {
                $subscriptions = array_values(array_filter(stackless_edd_get_order_subscriptions((int) $order->id), static function ($s): bool {
                    return $s instanceof SubscriptionData;
                }));
                foreach ($subscriptions as $subscription) {
                    if ((string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === $dodoId) {
                        return $subscription;
                    }
                }
                if (count($subscriptions) === 1 && $subscriptions[0]->get_parent_order_id() === (int) $order->id) {
                    return $subscriptions[0];
                }
            }
        }
        if (!self::forThisSite($metadata) || (string) ($metadata['edd_gateway'] ?? $this->gateway->id()) !== $this->gateway->id()) {
            return null;
        }
        $storeId = absint($metadata['edd_subscription_id'] ?? 0);
        $subscription = $storeId ? stackless_edd_get_subscription($storeId) : null;
        if (!$subscription instanceof SubscriptionData) {
            return null;
        }
        $parent = absint($metadata['edd_order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_order_id() !== $parent) || ($linked !== '' && $dodoId !== '' && $linked !== $dodoId)) {
            return null;
        }
        return $subscription;
    }

    private static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['edd_site'] ?? '');
        return $site === '' || $site === Catalog::siteHost();
    }

    /* ---- First payment ------------------------------------------------------------- */

    /**
     * Look up the order's checkout session and confirm its payment (or its free trial).
     *
     * @param string $subscriptionHint Dodo subscription id from the return URL, used for free trials.
     * @return true|\WP_Error
     */
    public function confirmCheckout(Order $order, string $subscriptionHint = '')
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->orderMode($orderId);
        $api = $this->gateway->api($mode);
        $sessionId = Orders::meta($orderId, Gateway::META_SESSION_ID);
        if ($sessionId === '') {
            return new \WP_Error('stackless_edd_dodo_no_session', __('No Dodo Payments checkout was started for this order.', 'stackless-lite-edd'));
        }
        $session = $api->request('GET', 'checkouts/' . rawurlencode($sessionId));
        if (is_wp_error($session)) {
            return $session;
        }

        $paymentId = (string) ($session['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment = $api->request('GET', 'payments/' . rawurlencode($paymentId));
            if (is_wp_error($payment)) {
                return $payment;
            }
            if (!$this->belongsTo($payment, $order, $sessionId)) {
                return new \WP_Error('stackless_edd_dodo_mismatch', __('This Dodo Payments payment belongs to a different order.', 'stackless-lite-edd'));
            }
            $status = (string) ($payment['status'] ?? '');
            if (in_array($status, ['failed', 'cancelled'], true)) {
                return new \WP_Error('stackless_edd_dodo_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'));
            }
            if ($status !== 'succeeded') {
                return new \WP_Error('stackless_edd_dodo_pending', __('Dodo Payments has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite-edd'));
            }
            $this->confirmPayment($payment, $mode, $order);
            return true;
        }

        // A free trial saves the card without charging, so there may be no payment.
        if ($subscriptionHint !== '' && (float) $order->total <= 0) {
            $remote = $api->request('GET', 'subscriptions/' . rawurlencode($subscriptionHint));
            if (!is_wp_error($remote) && (string) ($remote['metadata']['edd_order_id'] ?? '') === (string) $orderId && ($remote['status'] ?? '') === 'active') {
                $this->confirmTrial($order, $remote, $mode);
                return true;
            }
        }
        return new \WP_Error('stackless_edd_dodo_pending', __('Dodo Payments has not completed this checkout yet. It will be confirmed automatically.', 'stackless-lite-edd'));
    }

    private function belongsTo(array $payment, Order $order, string $sessionId): bool
    {
        $orderId = (string) ($payment['metadata']['edd_order_id'] ?? '');
        if ($orderId !== '') {
            return $orderId === (string) $order->id && self::forThisSite((array) $payment['metadata']);
        }
        return (string) ($payment['checkout_session_id'] ?? '') === $sessionId;
    }

    /** Route a succeeded Dodo payment to the first payment or a renewal. */
    public function confirmPayment(array $payment, string $mode, ?Order $order = null): string
    {
        if ((string) ($payment['status'] ?? '') !== 'succeeded') {
            return 'not succeeded';
        }
        $kind = EventMap::paymentKind($payment);
        if ($kind === 'renewal') {
            return $this->recordRenewal($payment, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored';
        }

        if (!$order) {
            $order = $this->orderFor($payment);
        }
        if (!$order || $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }

        // A free trial's first "payment" is the zero-amount card authorization.
        $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
        if ((float) $order->total <= 0 && $dodoSubscription !== '') {
            $remote = $this->gateway->api($mode)->request('GET', 'subscriptions/' . rawurlencode($dodoSubscription));
            if (is_wp_error($remote)) {
                throw new \RuntimeException(esc_html($remote->get_error_message()));
            }
            return $this->confirmTrial($order, $remote, $mode);
        }
        return $this->settle($order, $payment, $mode);
    }

    /** The order a first payment was started for: by metadata, then by checkout session. */
    private function orderFor(array $payment): ?Order
    {
        $metadata = (array) ($payment['metadata'] ?? []);
        if (!empty($metadata['edd_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            return Orders::get(absint($metadata['edd_order_id']));
        }
        $sessionId = (string) ($payment['checkout_session_id'] ?? '');
        return $sessionId !== '' ? $this->findOrder(Gateway::META_SESSION_ID, $sessionId) : null;
    }

    public function settle(Order $order, array $payment, string $mode): string
    {
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $payment, $mode) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'gone';
            }
            $paymentId = (string) ($payment['payment_id'] ?? '');
            if (Orders::isPaid($order)) {
                if (Orders::meta($orderId, Gateway::META_PAYMENT_ID) === '' && $paymentId !== '') {
                    Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
                }
                return 'already paid';
            }

            $orderCurrency = strtoupper((string) $order->currency);
            $currency = strtoupper((string) ($payment['currency'] ?? ''));
            if ($currency !== '' && $currency !== $orderCurrency) {
                Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
                Orders::hold($order, sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Dodo Payments charged in %2$s but the order is in %1$s. Check the payment in Dodo before fulfilling.', 'stackless-lite-edd'),
                    $orderCurrency,
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : $orderCurrency;
            self::storeAmounts($orderId, $payment, $currency, $mode);

            $expected = (int) Orders::meta($orderId, Gateway::META_AMOUNT) ?: Money::toMinor($order->total, $currency);
            if (!Money::paidMatches($payment, $expected)) {
                Orders::hold($order, sprintf(
                    /* translators: 1: amount Dodo charged, 2: order total */
                    __('Dodo Payments charged %1$s but the order total is %2$s. Check the payment in Dodo before fulfilling.', 'stackless-lite-edd'),
                    Money::label(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), $currency),
                    Money::label($order->total, $orderCurrency)
                ));
                return 'amount mismatch';
            }

            $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
            $customer = (string) ($payment['customer']['customer_id'] ?? '');
            if ($dodoSubscription !== '') {
                $this->linkSubscription($orderId, $dodoSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }

            stackless_edd_add_order_note($orderId, sprintf(
                /* translators: 1: Dodo payment id, 2: total charged including tax */
                __('Dodo Payments payment %1$s succeeded (%2$s charged including tax).', 'stackless-lite-edd'),
                $paymentId,
                Money::label(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), $currency)
            ));
            stackless_edd_complete_order($orderId, $paymentId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Dodo subscription. */
    public function confirmTrial(Order $order, array $remote, string $mode): string
    {
        // Dodo activates a trial subscription once the card is saved.
        if (($remote['status'] ?? '') !== 'active') {
            return 'trial not active';
        }
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $remote, $mode) {
            $order = Orders::get($orderId);
            if (!$order || Orders::isPaid($order)) {
                return 'already paid';
            }
            if ((float) $order->total > 0) {
                return 'not a trial';
            }
            $dodoId = (string) ($remote['subscription_id'] ?? '');
            $this->linkSubscription($orderId, $dodoId, (string) ($remote['customer']['customer_id'] ?? ''), $mode);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            /* translators: %s: Dodo subscription id */
            stackless_edd_add_order_note($orderId, sprintf(__('Dodo Payments subscription %s started its free trial.', 'stackless-lite-edd'), $dodoId));
            stackless_edd_complete_order($orderId);
            return 'trial';
        }, 'busy');
    }

    /** Write the Dodo subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(int $orderId, string $dodoSubscription, string $customer, string $mode): void
    {
        if ($dodoSubscription === '') {
            return;
        }
        Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoSubscription);
        if ($customer !== '') {
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        $product = Orders::meta($orderId, Gateway::META_PRODUCT_ID);
        foreach (stackless_edd_get_order_subscriptions($orderId) as $subscription) {
            if (!$subscription instanceof SubscriptionData) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoSubscription);
            if ($customer !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            if ($product !== '') {
                $subscription->update_meta(Gateway::META_PRODUCT_ID, $product);
            }
            $subscription->update_meta(Gateway::META_MODE, $mode);
        }
    }

    private static function storeAmounts(int $orderId, array $payment, string $currency, string $mode): void
    {
        Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, (string) ($payment['payment_id'] ?? ''));
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_TAX, stackless_edd_decimal(Money::fromMinor((int) ($payment['tax'] ?? 0), $currency), Money::decimals($currency)));
        Orders::setMeta($orderId, Gateway::META_TOTAL, (string) (int) ($payment['total_amount'] ?? 0));
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Dodo charged becomes a paid EDD renewal order, once per Dodo payment. */
    public function recordRenewal(array $payment, string $mode): string
    {
        $paymentId = (string) ($payment['payment_id'] ?? '');
        $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
        if ($paymentId === '' || $dodoSubscription === '') {
            return 'no subscription';
        }
        if ($this->findOrder(Gateway::META_PAYMENT_ID, $paymentId)) {
            return 'duplicate';
        }
        // Usage settlements that do not pay for a billing period are not renewals.
        if (!empty($payment['includes_usage']) && empty($payment['service_periods'])) {
            return 'usage charge';
        }
        $subscription = $this->findSubscription($dodoSubscription, (array) ($payment['metadata'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $paymentId . ' arrived for unknown Dodo subscription ' . $dodoSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $paymentId, function () use ($payment, $paymentId, $dodoSubscription, $subscription, $mode) {
            if ($this->findOrder(Gateway::META_PAYMENT_ID, $paymentId)) {
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
                    // Thrown so the webhook answers 500 and Dodo retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = Orders::get((int) $created);
                if (!$renewal) {
                    throw new \RuntimeException('Could not load renewal order ' . (int) $created . '.');
                }
            }
            $renewalId = (int) $renewal->id;

            $renewalCurrency = strtoupper((string) $renewal->currency);
            $currency = strtoupper((string) ($payment['currency'] ?? $renewalCurrency));
            Orders::setGateway($renewal, $this->gateway->id());
            Orders::deleteMeta($renewalId, Gateway::META_AWAITING);
            self::storeAmounts($renewalId, $payment, $currency, $mode);
            Orders::setMeta($renewalId, Gateway::META_TAX_INCLUSIVE, $this->gateway->taxInclusive() ? 'yes' : 'no');
            Orders::setMeta($renewalId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoSubscription);
            $product = (string) $subscription->get_meta(Gateway::META_PRODUCT_ID);
            if ($product !== '') {
                Orders::setMeta($renewalId, Gateway::META_PRODUCT_ID, $product);
            }
            $subscription->update_meta(Gateway::META_AWAITING, '');

            if ($currency !== $renewalCurrency) {
                Orders::hold($renewal, sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Dodo Payments charged this renewal in %2$s but the subscription bills in %1$s. Check it in Dodo.', 'stackless-lite-edd'),
                    $renewalCurrency,
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($payment, Money::toMinor($renewal->total, $currency))) {
                // Dodo already took the money; record it and flag the difference.
                stackless_edd_add_order_note($renewalId, sprintf(
                    /* translators: 1: amount Dodo charged, 2: renewal total */
                    __('Dodo Payments charged %1$s including tax for this renewal; the EDD renewal total is %2$s.', 'stackless-lite-edd'),
                    Money::label(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), $currency),
                    Money::label($renewal->total, $renewalCurrency)
                ));
            }
            /* translators: %s: Dodo payment id */
            stackless_edd_add_order_note($renewalId, sprintf(__('Dodo Payments charged this renewal (payment %s).', 'stackless-lite-edd'), $paymentId));
            stackless_edd_complete_order($renewalId, $paymentId);
            return 'renewed';
        }, 'busy');
    }

    /* ---- Refunds ------------------------------------------------------------------ */

    public static function recordRefundId(int $orderId, string $refundId): void
    {
        if ($refundId === '') {
            return;
        }
        $ids = array_filter(explode(',', Orders::meta($orderId, Gateway::META_REFUND_IDS)));
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::setMeta($orderId, Gateway::META_REFUND_IDS, implode(',', $ids));
        }
    }

    public static function hasRefundId(int $orderId, string $refundId): bool
    {
        return in_array($refundId, array_filter(explode(',', Orders::meta($orderId, Gateway::META_REFUND_IDS))), true);
    }

    /** Mirror a Dodo refund (made here or in the Dodo dashboard) once. */
    public function recordRefund(array $refund): string
    {
        $order = $this->findOrder(Gateway::META_PAYMENT_ID, (string) ($refund['payment_id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $orderId = (int) $order->id;
        $refundId = (string) ($refund['refund_id'] ?? '');
        $status = (string) ($refund['status'] ?? '');

        if ($status === 'failed') {
            if (self::hasRefundId($orderId, $refundId)) {
                stackless_edd_add_order_note($orderId, sprintf(
                    /* translators: %s: Dodo refund id */
                    __('Dodo Payments could not complete refund %s. The EDD refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite-edd'),
                    $refundId
                ));
                return 'failed noted';
            }
            return 'failed';
        }
        if ($status !== 'succeeded') {
            return 'not succeeded';
        }

        return (string) Lock::run('refund-' . $orderId, function () use ($orderId, $refund, $refundId) {
            $order = Orders::get($orderId);
            if (!$order || self::hasRefundId($orderId, $refundId)) {
                return 'duplicate';
            }
            $orderCurrency = strtoupper((string) $order->currency);
            $currency = strtoupper((string) ($refund['currency'] ?? $orderCurrency));
            if ($currency !== $orderCurrency) {
                self::recordRefundId($orderId, $refundId);
                stackless_edd_add_order_note($orderId, __('Dodo Payments reported a refund in a different currency. Record it manually.', 'stackless-lite-edd'));
                return 'currency mismatch';
            }
            $minor = Money::orderRefund((int) ($refund['amount'] ?? 0), Money::toMinor($order->total, $currency), (int) Orders::meta($orderId, Gateway::META_TOTAL));
            $amount = min(Money::fromMinor($minor, $currency), Orders::remainingRefund($order));
            if ($amount <= 0) {
                self::recordRefundId($orderId, $refundId);
                return 'nothing left';
            }
            $created = Orders::recordRefund($order, $amount);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            self::recordRefundId($orderId, $refundId);
            /* translators: 1: amount, 2: Dodo refund id */
            stackless_edd_add_order_note($orderId, sprintf(__('Refunded %1$s in Dodo Payments (%2$s).', 'stackless-lite-edd'), Money::label($amount, $currency), $refundId));
            return 'refunded';
        }, 'busy');
    }

    /* ---- Disputes ------------------------------------------------------------------ */

    public function noteDispute(string $type, array $dispute): string
    {
        $order = $this->findOrder(Gateway::META_PAYMENT_ID, (string) ($dispute['payment_id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        stackless_edd_add_order_note((int) $order->id, sprintf(
            /* translators: 1: dispute event, 2: Dodo dispute id */
            __('Dodo Payments reported %1$s (dispute %2$s). Review it in the Dodo Payments dashboard.', 'stackless-lite-edd'),
            $type,
            (string) ($dispute['dispute_id'] ?? '')
        ));
        return 'noted';
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a Stackless subscription in line with a Dodo subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $dodoId = (string) ($remote['subscription_id'] ?? '');
        if ($dodoId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($dodoId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        $cancelAtEnd = !empty($remote['cancel_at_next_billing_date']);
        $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoId);
        if (!empty($remote['customer']['customer_id'])) {
            $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer']['customer_id']);
        }
        if (!empty($remote['product_id'])) {
            $subscription->update_meta(Gateway::META_PRODUCT_ID, (string) $remote['product_id']);
        }
        $subscription->update_meta(Gateway::META_MODE, $mode);
        $subscription->update_meta(Gateway::META_CANCEL_AT_END, $cancelAtEnd ? 'yes' : 'no');
        $next = (string) ($remote['next_billing_date'] ?? '');
        if ($next !== '') {
            $subscription->update_meta(Gateway::META_NEXT_BILLING, $next);
            // Dodo runs the schedule; let the Subscriptions module show Dodo's next charge date.
            $date = Subscriptions::date($next);
            if ($date && ($remote['status'] ?? '') === 'active' && !$cancelAtEnd) {
                $subscription->set_next_payment_date($date);
            }
        }

        $target = EventMap::status($remote);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: payment.succeeded (or the trial path) activates it.
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($target !== null && in_array($target, $active, true) && $parent && !Orders::isPaid($parent)) {
            if ((float) $parent->total <= 0 && ($remote['status'] ?? '') === 'active' && $parent->gateway === $this->gateway->id()) {
                return $this->confirmTrial($parent, $remote, $mode);
            }
            return 'awaiting first payment';
        }

        if ($target === null) {
            return 'no status change';
        }
        $subscription = Subscriptions::fresh($subscription);
        $current = $subscription->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }

        $note = $target === SubscriptionData::STATUS_CANCELLED && !empty($remote['cancellation_comment'])
            /* translators: %s: reason given in Dodo */
            ? sprintf(__('Cancelled in Dodo Payments: %s', 'stackless-lite-edd'), (string) $remote['cancellation_comment'])
            /* translators: %s: Dodo subscription status */
            : sprintf(__('Dodo Payments subscription is now %s.', 'stackless-lite-edd'), (string) ($remote['status'] ?? $event));
        if (!Subscriptions::setStatus($subscription, $target, $note)) {
            $this->gateway->log('Subscription ' . $subscription->get_id() . ' could not move to ' . $target . ' (' . $event . ').', 'warning');
            return 'status ' . $target . ' not applied';
        }
        do_action('stackless_edd_dodo_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
