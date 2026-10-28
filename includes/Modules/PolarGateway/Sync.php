<?php

namespace StacklessEDD\Modules\PolarGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Polar payment, renewal, refund and subscription state to EDD.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while
 * unpaid, renewals are keyed by Polar order id, refunds by Polar refund id.
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

    public static function findOrderByMeta(string $key, string $value): ?Order
    {
        return Orders::findByMeta($key, $value);
    }

    /**
     * Subscription linked to a Polar subscription: by the Polar id stored on its parent or
     * renewal orders, else by the checkout metadata (this site, this order).
     */
    public function findSubscription(string $polarId, array $metadata): ?SubscriptionData
    {
        if ($polarId !== '') {
            $order = Orders::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarId);
            if ($order) {
                $found = self::pick(stackless_edd_get_order_subscriptions((int) $order->id), $polarId);
                if ($found) {
                    return $found;
                }
            }
        }

        $site = (string) ($metadata['edd_site'] ?? '');
        if ($site !== '' && $site !== Catalog::siteHost()) {
            return null;
        }
        $parent = absint($metadata['edd_order_id'] ?? 0);
        $eddId = absint($metadata['edd_subscription_id'] ?? 0);
        $subscription = null;
        if ($eddId) {
            $candidate = stackless_edd_get_subscription($eddId);
            $subscription = $candidate instanceof SubscriptionData ? $candidate : null;
        } elseif ($parent) {
            $subscription = self::pick(stackless_edd_get_order_subscriptions($parent), $polarId);
        }
        if (!$subscription) {
            return null;
        }
        $linked = self::metaString($subscription, SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_order_id() !== $parent) || ($linked !== '' && $polarId !== '' && $linked !== $polarId)) {
            return null;
        }
        return $subscription;
    }

    /**
     * The one subscription among $subscriptions linked to $polarId (or the only one).
     *
     * @param mixed[] $subscriptions
     */
    private static function pick(array $subscriptions, string $polarId): ?SubscriptionData
    {
        $subscriptions = array_values(array_filter($subscriptions, static function ($subscription) {
            return $subscription instanceof SubscriptionData;
        }));
        foreach ($subscriptions as $subscription) {
            if ($polarId !== '' && self::metaString($subscription, SubscriptionData::META_GATEWAY_SUBSCRIPTION) === $polarId) {
                return $subscription;
            }
        }
        return count($subscriptions) === 1 ? $subscriptions[0] : null;
    }

    private static function metaString(SubscriptionData $subscription, string $key): string
    {
        $value = $subscription->get_meta($key);
        return is_scalar($value) ? (string) $value : '';
    }

    /** Polar can only be synced while the Subscriptions module runs: throw so Polar retries later. */
    private static function requireSubscriptions(): void
    {
        if (!stackless_edd_module_active('subscriptions')) {
            throw new \RuntimeException('Subscriptions module is not active; cannot apply the subscription event.');
        }
    }

    /* ---- Checkout confirmation ---------------------------------------------------- */

    /**
     * Look up a checkout and confirm its paid order (or its free trial).
     *
     * @return true|\WP_Error
     */
    public function confirmCheckout(Order $order, string $checkoutId)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->orderMode($orderId);
        $api = $this->gateway->api($mode);
        $checkout = $api->request('GET', 'checkouts/' . rawurlencode($checkoutId));
        if (is_wp_error($checkout)) {
            return $checkout;
        }
        if ((string) ($checkout['metadata']['edd_order_id'] ?? '') !== (string) $orderId) {
            return new \WP_Error('stackless_edd_polar_mismatch', __('This Polar checkout belongs to a different order.', 'stackless-lite-edd'));
        }
        $status = (string) ($checkout['status'] ?? '');
        if ($status !== 'succeeded') {
            return new \WP_Error('stackless_edd_polar_checkout_' . ($status !== '' ? $status : 'unknown'), $status === 'confirmed'
                ? __('Polar is still processing this payment. It will be confirmed automatically.', 'stackless-lite-edd')
                /* translators: %s: Polar checkout status */
                : sprintf(__('The Polar checkout is %s, not paid.', 'stackless-lite-edd'), $status !== '' ? $status : 'unknown'));
        }

        $orders = $api->request('GET', 'orders/', ['checkout_id' => $checkoutId, 'limit' => 10]);
        if (is_wp_error($orders)) {
            return $orders;
        }
        foreach ((array) ($orders['items'] ?? []) as $polarOrder) {
            if (is_array($polarOrder) && EventMap::isPaid($polarOrder) && EventMap::orderKind($polarOrder) === 'first') {
                $this->confirmPayment($order, $polarOrder, $mode);
                return true;
            }
        }

        // A free trial starts a subscription without charging, so there may be no paid order.
        $polarSubscription = (string) ($checkout['subscription_id'] ?? '');
        if ($polarSubscription !== '' && (float) $order->total <= 0) {
            $remote = $api->request('GET', 'subscriptions/' . rawurlencode($polarSubscription));
            if (!is_wp_error($remote) && in_array($remote['status'] ?? '', ['trialing', 'active'], true)) {
                $this->confirmTrial($order, $remote, $mode);
                return true;
            }
        }
        return new \WP_Error('stackless_edd_polar_pending', __('Polar has not created the order for this checkout yet. It will be confirmed automatically.', 'stackless-lite-edd'));
    }

    /** Route a Polar order to the first payment or a renewal. */
    public function confirmOrder(array $polarOrder, string $mode): string
    {
        if (!EventMap::isPaid($polarOrder)) {
            return 'unpaid';
        }
        $kind = EventMap::orderKind($polarOrder);
        if ($kind === 'renewal') {
            return $this->recordRenewal($polarOrder, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored'; // Plan changes and metered cycles are started in Polar, not here.
        }

        $metadata = (array) ($polarOrder['metadata'] ?? []);
        $site = (string) ($metadata['edd_site'] ?? '');
        if ($site !== '' && $site !== Catalog::siteHost()) {
            return 'other site';
        }
        $order = Orders::get(absint($metadata['edd_order_id'] ?? 0));
        if (!$order || (string) $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        $checkoutId = (string) ($polarOrder['checkout_id'] ?? '');
        $stored = Orders::meta((int) $order->id, Gateway::META_CHECKOUT_ID);
        if ($checkoutId !== '' && $stored !== '' && $checkoutId !== $stored) {
            return 'checkout mismatch';
        }
        return $this->confirmPayment($order, $polarOrder, $mode);
    }

    public function confirmPayment(Order $order, array $polarOrder, string $mode): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $polarOrder, $mode) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return 'gone';
            }
            $orderId = (int) $order->id;
            $polarId = (string) ($polarOrder['id'] ?? '');
            if (Orders::isPaid($order)) {
                if (Orders::meta($orderId, Gateway::META_ORDER_ID) === '' && $polarId !== '') {
                    Orders::setMeta($orderId, Gateway::META_ORDER_ID, $polarId);
                }
                return 'already paid';
            }

            $orderCurrency = strtolower((string) $order->currency);
            $currency = strtolower((string) ($polarOrder['currency'] ?? ''));
            if ($currency !== '' && $currency !== $orderCurrency) {
                Orders::setMeta($orderId, Gateway::META_ORDER_ID, $polarId);
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Polar charged in %2$s but the order is in %1$s. Check the payment in Polar.', 'stackless-lite-edd'),
                    strtoupper($orderCurrency),
                    strtoupper($currency)
                ));
                return 'currency mismatch';
            }

            $currency = $currency !== '' ? $currency : $orderCurrency;
            $this->storeAmounts($orderId, $polarOrder, $currency, $mode);

            if (!Money::paidMatches($polarOrder, Money::toMinor((float) $order->total, $currency))) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: amount Polar charged, 2: order total */
                    __('Polar charged %1$s but the order total is %2$s. Check the payment in Polar before delivering the order.', 'stackless-lite-edd'),
                    Orders::money(Money::fromMinor((int) ($polarOrder['net_amount'] ?? 0), $currency), $currency),
                    Orders::money((float) $order->total, (string) $order->currency)
                ));
                return 'amount mismatch';
            }

            $polarSubscription = (string) ($polarOrder['subscription_id'] ?? '');
            $customer = (string) ($polarOrder['customer_id'] ?? '');
            if ($polarSubscription !== '') {
                $this->linkSubscription($orderId, $polarSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }

            stackless_edd_complete_order($orderId, $polarId, sprintf(
                /* translators: 1: Polar order id, 2: total charged including tax */
                __('Polar order %1$s paid (%2$s charged including tax).', 'stackless-lite-edd'),
                $polarId,
                Orders::money(Money::fromMinor((int) ($polarOrder['total_amount'] ?? 0), $currency), $currency)
            ));
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Polar subscription. */
    public function confirmTrial(Order $order, array $remote, string $mode): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $remote, $mode) {
            $order = Orders::get((int) $order->id);
            if (!$order || Orders::isPaid($order)) {
                return 'already paid';
            }
            if ((float) $order->total > 0) {
                return 'not a trial';
            }
            $orderId = (int) $order->id;
            $this->linkSubscription($orderId, (string) ($remote['id'] ?? ''), (string) ($remote['customer_id'] ?? ''), $mode);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            /* translators: %s: Polar subscription id */
            stackless_edd_complete_order($orderId, '', sprintf(__('Polar subscription %s started its free trial.', 'stackless-lite-edd'), (string) ($remote['id'] ?? '')));
            return 'trial';
        }, 'busy');
    }

    /** Write the Polar subscription on the parent order (before it completes) and on the subscription created from it. */
    public function linkSubscription(int $orderId, string $polarSubscription, string $customer, string $mode): void
    {
        if ($polarSubscription === '') {
            return;
        }
        Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarSubscription);
        if ($customer !== '') {
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        foreach (stackless_edd_get_order_subscriptions($orderId) as $subscription) {
            if (!$subscription instanceof SubscriptionData) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarSubscription);
            if ($customer !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $subscription->update_meta(Gateway::META_MODE, $mode);
        }
    }

    private function storeAmounts(int $orderId, array $polarOrder, string $currency, string $mode): void
    {
        $decimals = Money::decimals($currency);
        Orders::setMeta($orderId, Gateway::META_ORDER_ID, (string) ($polarOrder['id'] ?? ''));
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_TAX, stackless_edd_decimal(Money::fromMinor((int) ($polarOrder['tax_amount'] ?? 0), $currency), $decimals));
        Orders::setMeta($orderId, Gateway::META_TOTAL, stackless_edd_decimal(Money::fromMinor((int) ($polarOrder['total_amount'] ?? 0), $currency), $decimals));
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Polar charged becomes a paid EDD renewal order. */
    public function recordRenewal(array $polarOrder, string $mode): string
    {
        $polarId = (string) ($polarOrder['id'] ?? '');
        $polarSubscription = (string) ($polarOrder['subscription_id'] ?? '');
        if ($polarId === '' || $polarSubscription === '') {
            return 'no subscription';
        }
        if (self::findOrderByMeta(Gateway::META_ORDER_ID, $polarId)) {
            return 'duplicate';
        }
        self::requireSubscriptions();
        $metadata = (array) ($polarOrder['subscription']['metadata'] ?? ($polarOrder['metadata'] ?? []));
        $subscription = $this->findSubscription($polarSubscription, $metadata);
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $polarId . ' arrived for unknown Polar subscription ' . $polarSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $polarId, function () use ($polarOrder, $polarId, $subscription, $mode) {
            if (self::findOrderByMeta(Gateway::META_ORDER_ID, $polarId)) {
                return 'duplicate';
            }
            $renewal = null;
            $awaiting = absint(self::metaString($subscription, Gateway::META_AWAITING));
            if ($awaiting) {
                $candidate = Orders::get($awaiting);
                if ($candidate && !Orders::isPaid($candidate) && Orders::meta($awaiting, Gateway::META_AWAITING) === 'yes') {
                    $renewal = $candidate;
                }
            }
            if (!$renewal) {
                $created = stackless_edd_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Polar retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = Orders::get((int) $created);
                if (!$renewal) {
                    throw new \RuntimeException('The renewal order could not be loaded.');
                }
            }
            $renewalId = (int) $renewal->id;

            $currency = strtolower((string) ($polarOrder['currency'] ?? $renewal->currency));
            Orders::setGateway($renewal, $this->gateway->id());
            Orders::deleteMeta($renewalId, Gateway::META_AWAITING);
            $this->storeAmounts($renewalId, $polarOrder, $currency, $mode);
            Orders::setMeta($renewalId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, (string) ($polarOrder['subscription_id'] ?? ''));

            $subscription->update_meta(Gateway::META_AWAITING, '');
            $end = (string) ($polarOrder['subscription']['current_period_end'] ?? '');
            if ($end !== '') {
                $subscription->update_meta(Gateway::META_PERIOD_END, $end);
            }

            if ($currency !== strtolower((string) $renewal->currency)) {
                Orders::setStatus($renewalId, 'on_hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Polar charged this renewal in %2$s but the subscription bills in %1$s. Check it in Polar.', 'stackless-lite-edd'),
                    strtoupper((string) $renewal->currency),
                    strtoupper($currency)
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($polarOrder, Money::toMinor((float) $renewal->total, $currency))) {
                // Polar already took the money; record it and flag the difference.
                Orders::note($renewalId, sprintf(
                    /* translators: 1: amount Polar charged, 2: renewal total */
                    __('Polar charged %1$s for this renewal; the EDD renewal total is %2$s.', 'stackless-lite-edd'),
                    Orders::money(Money::fromMinor((int) ($polarOrder['net_amount'] ?? 0), $currency), $currency),
                    Orders::money((float) $renewal->total, (string) $renewal->currency)
                ));
            }
            /* translators: %s: Polar order id */
            stackless_edd_complete_order($renewalId, $polarId, sprintf(__('Polar charged this renewal (order %s).', 'stackless-lite-edd'), $polarId));
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

    /** Mirror a Polar refund (made here or in the Polar dashboard) once. */
    public function recordRefund(array $refund, string $mode): string
    {
        if (($refund['status'] ?? '') !== 'succeeded') {
            return 'not succeeded';
        }
        $order = self::findOrderByMeta(Gateway::META_ORDER_ID, (string) ($refund['order_id'] ?? ''));
        if (!$order || (string) $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        $refundId = (string) ($refund['id'] ?? '');

        return (string) Lock::run('refund-' . $order->id, function () use ($order, $refund, $refundId) {
            $order = Orders::get((int) $order->id);
            if (!$order || self::hasRefundId((int) $order->id, $refundId)) {
                return 'duplicate';
            }
            $orderId = (int) $order->id;
            $currency = strtolower((string) ($refund['currency'] ?? $order->currency));
            if ($currency !== strtolower((string) $order->currency)) {
                self::recordRefundId($orderId, $refundId);
                Orders::note($orderId, __('Polar reported a refund in a different currency. Record it manually.', 'stackless-lite-edd'));
                return 'currency mismatch';
            }
            $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0), $currency), (float) edd_get_order_total($orderId));
            if ($amount <= 0) {
                self::recordRefundId($orderId, $refundId);
                return 'nothing left';
            }
            Refunds::recordExternal($order, $amount, $refundId, sprintf(
                /* translators: 1: amount, 2: Polar refund id */
                __('Refunded %1$s in Polar (%2$s).', 'stackless-lite-edd'),
                Orders::money($amount, (string) $order->currency),
                $refundId
            ));
            self::recordRefundId($orderId, $refundId);
            return 'refunded';
        }, 'busy');
    }

    /** order.refunded carries the order; its refunds come from the refunds list. */
    public function refundsForOrder(array $polarOrder, string $mode): string
    {
        $refunds = $this->gateway->api($mode)->request('GET', 'refunds/', ['order_id' => (string) ($polarOrder['id'] ?? ''), 'limit' => 100]);
        if (is_wp_error($refunds)) {
            throw new \RuntimeException(esc_html($refunds->get_error_message()));
        }
        $done = 0;
        $busy = false;
        foreach ((array) ($refunds['items'] ?? []) as $refund) {
            $outcome = is_array($refund) ? $this->recordRefund($refund, $mode) : '';
            if ($outcome === 'refunded') {
                $done++;
            } elseif ($outcome === 'busy') {
                $busy = true;
            }
        }
        // A refund skipped behind the order lock must be retried; recorded ones are skipped next time.
        return $busy ? 'busy' : 'refunds recorded: ' . $done;
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a subscription in line with a Polar subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $polarId = (string) ($remote['id'] ?? '');
        if ($polarId === '') {
            return 'no id';
        }
        self::requireSubscriptions();
        $subscription = $this->findSubscription($polarId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarId);
        if (!empty($remote['customer_id'])) {
            $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer_id']);
        }
        $subscription->update_meta(Gateway::META_MODE, $mode);
        $subscription->update_meta(Gateway::META_CANCEL_AT_END, !empty($remote['cancel_at_period_end']) ? 'yes' : 'no');
        $end = (string) (($remote['status'] ?? '') === 'trialing' && !empty($remote['trial_end']) ? $remote['trial_end'] : ($remote['current_period_end'] ?? ''));
        if ($end !== '') {
            $subscription->update_meta(Gateway::META_PERIOD_END, $end);
            // Polar runs the schedule; the subscription shows Polar's next charge date.
            $next = self::date($end);
            if ($next && ($remote['status'] ?? '') !== 'canceled' && empty($remote['cancel_at_period_end'])) {
                $subscription->set_next_payment_date($next);
            }
        }

        $target = EventMap::status($remote, $event);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: order.paid (or the trial path) activates it.
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($target !== null && in_array($target, $active, true) && $parent && !Orders::isPaid($parent)) {
            if ((float) $parent->total <= 0 && in_array($remote['status'] ?? '', ['trialing', 'active'], true) && (string) $parent->gateway === $this->gateway->id()) {
                return $this->confirmTrial($parent, $remote, $mode);
            }
            return 'awaiting first payment';
        }

        if ($target === null) {
            return 'no status change';
        }
        $current = $subscription->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }

        $note = $target === SubscriptionData::STATUS_CANCELLED && !empty($remote['customer_cancellation_reason'])
            /* translators: %s: reason given in Polar */
            ? sprintf(__('Canceled in Polar: %s', 'stackless-lite-edd'), (string) $remote['customer_cancellation_reason'])
            /* translators: %s: Polar subscription status */
            : sprintf(__('Polar subscription is now %s.', 'stackless-lite-edd'), (string) ($remote['status'] ?? $event));
        if (!Subscriptions::setStatus($subscription, $target, $note)) {
            $this->gateway->log('Could not move subscription #' . $subscription->get_id() . ' to ' . $target . ' after Polar ' . $event . '.', 'warning');
            return 'status not applied';
        }
        do_action('stackless_edd_polar_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }

    /** A Polar ISO 8601 timestamp as a UTC date, or null. */
    public static function date(string $value): ?\DateTimeImmutable
    {
        $timestamp = strtotime($value);
        if (!$timestamp) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
