<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Creem checkout, payment, renewal, refund and subscription state to EDD.
 *
 * Every entry point (browser return, webhook) funnels through these methods and each is safe
 * to repeat: orders are settled under a lock and only while unpaid, renewals are keyed by
 * Creem transaction id, refunds by Creem refund id. Creem sends checkout.completed and
 * subscription.paid for a subscription's first payment; whichever lands first records it.
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

    /** A sale or renewal order of this gateway (original or copy) with a meta value. */
    public function findOrder(string $key, string $value): ?Order
    {
        return Orders::findByMeta($key, $value, $this->gateway->id());
    }

    /**
     * Subscription linked to a Creem subscription: by the Creem id stored on its parent or
     * renewal orders, else by the checkout metadata (this site, this gateway, this order).
     */
    public function findSubscription(string $creemId, array $metadata): ?SubscriptionData
    {
        if ($creemId !== '') {
            $order = Orders::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
            if ($order) {
                $found = self::pick(stackless_edd_get_order_subscriptions((int) $order->id), $creemId);
                if ($found) {
                    return $found;
                }
            }
        }
        if (!self::forThisSite($metadata) || (string) ($metadata['edd_gateway'] ?? $this->gateway->id()) !== $this->gateway->id()) {
            return null;
        }
        $parent = absint($metadata['edd_order_id'] ?? 0);
        $eddId = absint($metadata['edd_subscription_id'] ?? 0);
        $subscription = null;
        if ($eddId) {
            $subscription = Subscriptions::fresh($eddId);
        } elseif ($parent) {
            $subscription = self::pick(stackless_edd_get_order_subscriptions($parent), $creemId);
        }
        if (!$subscription) {
            return null;
        }
        $linked = Subscriptions::metaString($subscription, SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_order_id() !== $parent) || ($linked !== '' && $creemId !== '' && $linked !== $creemId)) {
            return null;
        }
        return $subscription;
    }

    /**
     * The one subscription among $subscriptions linked to $creemId (or the only one).
     *
     * @param mixed[] $subscriptions
     */
    private static function pick(array $subscriptions, string $creemId): ?SubscriptionData
    {
        $subscriptions = array_values(array_filter($subscriptions, static function ($subscription) {
            return $subscription instanceof SubscriptionData;
        }));
        foreach ($subscriptions as $subscription) {
            if ($creemId !== '' && Subscriptions::metaString($subscription, SubscriptionData::META_GATEWAY_SUBSCRIPTION) === $creemId) {
                return $subscription;
            }
        }
        return count($subscriptions) === 1 ? $subscriptions[0] : null;
    }

    private static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['edd_site'] ?? '');
        return $site === '' || $site === Catalog::siteHost();
    }

    /** Creem subscriptions are synced only while the Subscriptions module runs: throw so Creem retries later. */
    private static function requireSubscriptions(): void
    {
        if (!stackless_edd_module_active('subscriptions')) {
            throw new \RuntimeException('Subscriptions module is not active; cannot apply the subscription event.');
        }
    }

    /** @return array|\WP_Error */
    public function subscription(string $creemId, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'v1/subscriptions', ['subscription_id' => $creemId]);
    }

    /* ---- First payment ------------------------------------------------------------- */

    /**
     * Read the order's checkout session back from Creem and apply it.
     *
     * @return true|\WP_Error stackless_edd_creem_expired when the session ran out unpaid.
     */
    public function confirmCheckout(Order $order)
    {
        $orderId = (int) $order->id;
        $checkoutId = Orders::meta($orderId, Gateway::META_CHECKOUT_ID);
        if ($checkoutId === '') {
            return new \WP_Error('stackless_edd_creem_no_session', __('No Creem checkout was started for this order.', 'stackless-lite-edd'));
        }
        $mode = $this->gateway->orderMode($orderId);
        $session = $this->gateway->api($mode)->request('GET', 'v1/checkouts', ['checkout_id' => $checkoutId]);
        if (is_wp_error($session)) {
            return $session;
        }
        $status = (string) ($session['status'] ?? '');
        if ($status === 'expired') {
            return new \WP_Error('stackless_edd_creem_expired', __('This Creem checkout expired before it was paid.', 'stackless-lite-edd'));
        }
        if ($status !== 'completed') {
            return new \WP_Error('stackless_edd_creem_pending', __('Creem has not completed this checkout yet. It will be confirmed automatically.', 'stackless-lite-edd'));
        }
        $outcome = $this->applyCheckout($session, $mode, $order);
        return in_array($outcome, ['paid', 'trial', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_edd_creem_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** The order a checkout was started for: by metadata, then by session id. */
    private function orderFor(array $checkout): ?Order
    {
        $metadata = is_array($checkout['metadata'] ?? null) ? $checkout['metadata'] : [];
        if (!empty($metadata['edd_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            return Orders::get(absint($metadata['edd_order_id']));
        }
        return $this->findOrder(Gateway::META_CHECKOUT_ID, (string) ($checkout['id'] ?? ''));
    }

    /** A completed checkout (from the return, a repeated checkout or checkout.completed). */
    public function applyCheckout(array $checkout, string $mode, ?Order $order = null): string
    {
        if ((string) ($checkout['status'] ?? '') !== 'completed') {
            return 'not completed';
        }
        $order = $order ?: $this->orderFor($checkout);
        if (!$order || (string) $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        $metadata = is_array($checkout['metadata'] ?? null) ? $checkout['metadata'] : [];
        $sameSession = (string) ($checkout['id'] ?? '') === Orders::meta((int) $order->id, Gateway::META_CHECKOUT_ID);
        if (!$sameSession && (string) ($metadata['edd_order_id'] ?? '') !== (string) $order->id) {
            return 'not this order';
        }

        $creemOrder = is_array($checkout['order'] ?? null) ? $checkout['order'] : [];
        $subscriptionId = EventMap::id($checkout['subscription'] ?? '');
        $customer = EventMap::id($checkout['customer'] ?? '') ?: EventMap::id($creemOrder['customer'] ?? '');

        // A free trial saves the card without charging.
        if ((float) $order->total <= 0) {
            if ($subscriptionId === '') {
                return 'no subscription';
            }
            $remote = is_array($checkout['subscription'] ?? null) && !empty($checkout['subscription']['status']) ? $checkout['subscription'] : $this->subscription($subscriptionId, $mode);
            if (is_wp_error($remote)) {
                throw new \RuntimeException(esc_html($remote->get_error_message()));
            }
            return $this->confirmTrial($order, $remote, $mode);
        }
        if (!$creemOrder) {
            return 'no order yet';
        }
        if ((string) ($creemOrder['status'] ?? '') !== 'paid') {
            return 'not paid';
        }
        return $this->settle($order, $creemOrder, $mode, $subscriptionId, $customer);
    }

    /**
     * Mark the order paid from a paid Creem order or transaction, once.
     *
     * @param array $paid A Creem order (checkout.completed) or transaction (subscription.paid).
     */
    public function settle(Order $order, array $paid, string $mode, string $subscriptionId = '', string $customer = ''): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $paid, $mode, $subscriptionId, $customer) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return 'gone';
            }
            $orderId = (int) $order->id;
            $isTransaction = (string) ($paid['object'] ?? '') === 'transaction';
            $transactionId = $isTransaction ? EventMap::id($paid['id'] ?? '') : EventMap::id($paid['transaction'] ?? '');
            $creemOrderId = $isTransaction ? EventMap::id($paid['order'] ?? '') : EventMap::id($paid['id'] ?? '');
            // A payment from the other mode never settles an order.
            $orderMode = Orders::meta($orderId, Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Creem payment for ' . $orderMode . '-mode order ' . $orderId . '.', 'warning');
                return 'other mode';
            }
            if (Orders::isPaid($order)) {
                if (Orders::meta($orderId, Gateway::META_TRANSACTION_ID) === '' && $transactionId !== '') {
                    Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
                }
                return 'already paid';
            }

            $orderCurrency = strtoupper((string) $order->currency);
            $currency = strtoupper((string) ($paid['currency'] ?? ''));
            if ($currency !== '' && $currency !== $orderCurrency) {
                Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Creem charged in %2$s but the order is in %1$s. Check the payment in Creem before delivering the order.', 'stackless-lite-edd'),
                    $orderCurrency,
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : $orderCurrency;
            $this->storeAmounts($orderId, $paid, $transactionId, $creemOrderId, $mode);

            $expected = (int) Orders::meta($orderId, Gateway::META_AMOUNT) ?: Money::toMinor((float) $order->total);
            if (!Money::paidMatches($paid, $expected)) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: amount Creem charged, 2: order total */
                    __('Creem charged %1$s but the order total is %2$s. A discount code entered on Creem\'s checkout also lands here. Check the payment in Creem before delivering the order.', 'stackless-lite-edd'),
                    Orders::money(Money::fromMinor(Money::paidTotal($paid)), $currency),
                    Orders::money((float) $order->total, $orderCurrency)
                ));
                return 'amount mismatch';
            }

            // Written before the order completes: Subscriptions reads it at that moment.
            if ($subscriptionId !== '') {
                $this->linkSubscription($orderId, $subscriptionId, $customer, $mode);
            } elseif ($customer !== '') {
                Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }

            Orders::complete($orderId, $transactionId !== '' ? $transactionId : $creemOrderId, sprintf(
                /* translators: 1: Creem transaction id, 2: total charged including tax */
                __('Creem payment %1$s succeeded (%2$s charged including tax).', 'stackless-lite-edd'),
                $transactionId !== '' ? $transactionId : $creemOrderId,
                Orders::money(Money::fromMinor(Money::paidTotal($paid)), $currency)
            ));
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Creem subscription. */
    public function confirmTrial(Order $order, array $remote, string $mode): string
    {
        if (!in_array((string) ($remote['status'] ?? ''), ['trialing', 'active'], true)) {
            return 'trial not active';
        }
        return (string) Lock::run('order-' . $order->id, function () use ($order, $remote, $mode) {
            $order = Orders::get((int) $order->id);
            if (!$order || Orders::isPaid($order)) {
                return 'already paid';
            }
            if ((float) $order->total > 0) {
                return 'not a trial';
            }
            $orderId = (int) $order->id;
            $orderMode = Orders::meta($orderId, Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                return 'other mode';
            }
            $creemId = EventMap::id($remote['id'] ?? '');
            $this->linkSubscription($orderId, $creemId, EventMap::id($remote['customer'] ?? ''), $mode);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            /* translators: %s: Creem subscription id */
            Orders::complete($orderId, '', sprintf(__('Creem subscription %s started its free trial.', 'stackless-lite-edd'), $creemId));
            return 'trial';
        }, 'busy');
    }

    /** Write the Creem subscription on the parent order (before it completes) and on the subscription created from it. */
    public function linkSubscription(int $orderId, string $creemSubscription, string $customer, string $mode): void
    {
        if ($creemSubscription === '') {
            return;
        }
        Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemSubscription);
        if ($customer !== '') {
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        foreach (stackless_edd_get_order_subscriptions($orderId) as $subscription) {
            if (!$subscription instanceof SubscriptionData) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemSubscription);
            if ($customer !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $subscription->update_meta(Gateway::META_MODE, $mode);
        }
    }

    private function storeAmounts(int $orderId, array $paid, string $transactionId, string $creemOrderId, string $mode): void
    {
        Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
        if ($creemOrderId !== '') {
            Orders::setMeta($orderId, Gateway::META_ORDER_ID, $creemOrderId);
        }
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_TAX, stackless_edd_decimal(Money::fromMinor((int) ($paid['tax_amount'] ?? 0)), 2));
        Orders::setMeta($orderId, Gateway::META_TOTAL, (string) Money::paidTotal($paid));
    }

    /* ---- Renewals ------------------------------------------------------------------ */

    /**
     * subscription.paid: the first payment (when it beats checkout.completed) or a renewal
     * Creem charged, recorded once per Creem transaction; then the subscription state.
     */
    public function subscriptionPaid(array $remote, string $mode, string $event): string
    {
        self::requireSubscriptions();
        $transaction = is_array($remote['last_transaction'] ?? null) ? $remote['last_transaction'] : [];
        $transactionId = EventMap::id($transaction['id'] ?? '') ?: (string) ($remote['last_transaction_id'] ?? '');
        if ($transactionId !== '' && !$transaction) {
            $fetched = $this->gateway->api($mode)->request('GET', 'v1/transactions', ['transaction_id' => $transactionId]);
            if (is_wp_error($fetched)) {
                throw new \RuntimeException(esc_html($fetched->get_error_message()));
            }
            $transaction = $fetched;
        }
        $transaction['object'] = 'transaction';
        $outcome = 'no transaction';
        if ($transactionId !== '' && (string) ($transaction['status'] ?? 'paid') === 'paid') {
            $outcome = $this->recordPaid($remote, $transaction, $mode);
            if ($outcome === 'busy') {
                return 'busy';
            }
        }
        return $outcome . '; ' . $this->syncSubscription($remote, $mode, $event);
    }

    private function recordPaid(array $remote, array $transaction, string $mode): string
    {
        $transactionId = EventMap::id($transaction['id'] ?? '');
        if ($this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId)) {
            return 'duplicate';
        }
        $subscription = $this->findSubscription(EventMap::id($remote['id'] ?? ''), (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Payment ' . $transactionId . ' arrived for unknown Creem subscription ' . EventMap::id($remote['id'] ?? '') . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($parent && !Orders::isPaid($parent) && (string) $parent->gateway === $this->gateway->id() && (float) $parent->total > 0) {
            // The first payment, before checkout.completed arrived.
            return $this->settle($parent, $transaction, $mode, EventMap::id($remote['id'] ?? ''), EventMap::id($remote['customer'] ?? ''));
        }
        return $this->recordRenewal($remote, $transaction, $subscription, $mode);
    }

    /** A renewal Creem charged becomes a paid EDD renewal order, once per Creem transaction. */
    public function recordRenewal(array $remote, array $transaction, SubscriptionData $subscription, string $mode): string
    {
        $transactionId = EventMap::id($transaction['id'] ?? '');
        $creemId = EventMap::id($remote['id'] ?? '');
        return (string) Lock::run('renewal-' . $transactionId, function () use ($transaction, $transactionId, $creemId, $subscription, $mode) {
            if ($this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId)) {
                return 'duplicate';
            }
            $subscription = Subscriptions::fresh($subscription->get_id()) ?: $subscription;
            $renewal = $this->awaitingRenewal($subscription);
            if (!$renewal) {
                $renewal = $this->createRenewal($subscription);
            }
            $renewalId = (int) $renewal->id;

            $currency = strtoupper((string) ($transaction['currency'] ?? $renewal->currency));
            Orders::setGateway($renewal, $this->gateway->id());
            Orders::deleteMeta($renewalId, Gateway::META_AWAITING);
            $this->storeAmounts($renewalId, $transaction, $transactionId, EventMap::id($transaction['order'] ?? ''), $mode);
            Orders::setMeta($renewalId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
            $product = Subscriptions::metaString($subscription, Gateway::META_PRODUCT_ID);
            if ($product !== '') {
                Orders::setMeta($renewalId, Gateway::META_PRODUCT_ID, $product);
            }
            $subscription->update_meta(Gateway::META_AWAITING, '');

            if ($currency !== strtoupper((string) $renewal->currency)) {
                Orders::setStatus($renewalId, 'on_hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Creem charged this renewal in %2$s but the subscription bills in %1$s. Check it in Creem.', 'stackless-lite-edd'),
                    strtoupper((string) $renewal->currency),
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($transaction, Money::toMinor((float) $renewal->total))) {
                // Creem already took the money; record it and flag the difference.
                Orders::note($renewalId, sprintf(
                    /* translators: 1: amount Creem charged, 2: renewal total */
                    __('Creem charged %1$s including tax for this renewal; the EDD renewal total is %2$s.', 'stackless-lite-edd'),
                    Orders::money(Money::fromMinor(Money::paidTotal($transaction)), $currency),
                    Orders::money((float) $renewal->total, (string) $renewal->currency)
                ));
            }
            /* translators: %s: Creem transaction id */
            Orders::complete($renewalId, $transactionId, sprintf(__('Creem charged this renewal (transaction %s).', 'stackless-lite-edd'), $transactionId));
            return 'renewed';
        }, 'busy');
    }

    /** The unpaid renewal order of this gateway the subscription is waiting on, if any. */
    private function awaitingRenewal(SubscriptionData $subscription): ?Order
    {
        $awaiting = absint(Subscriptions::metaString($subscription, Gateway::META_AWAITING));
        $candidate = $awaiting ? Orders::get($awaiting) : null;
        if ($candidate && !Orders::isPaid($candidate) && (string) $candidate->gateway === $this->gateway->id()) {
            return $candidate;
        }
        return null;
    }

    /** A renewal order from Subscriptions (or the unpaid one it already has for the period). */
    private function createRenewal(SubscriptionData $subscription): Order
    {
        $created = stackless_edd_create_renewal_order($subscription);
        if (is_wp_error($created)) {
            // Thrown so the webhook answers 500 and Creem retries later.
            throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
        }
        $renewal = Orders::get((int) $created);
        if (!$renewal) {
            throw new \RuntimeException('The renewal order could not be loaded.');
        }
        return $renewal;
    }

    /**
     * subscription.past_due / subscription.unpaid: Creem could not take a renewal.
     *
     * Subscriptions creates no renewal orders for provider-billed subscriptions, so the failed
     * charge gets one here (or reuses the one still waiting). The decline category is written
     * on it before it is failed, so dunning sees it; Creem's later subscription.paid pays that
     * same order. A renewal that already failed is left as it is.
     */
    public function renewalFailed(array $remote, string $mode, string $event): string
    {
        self::requireSubscriptions();
        $creemId = EventMap::id($remote['id'] ?? '');
        $subscription = $this->findSubscription($creemId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }
        // Deliveries can arrive late or out of order: act only on what Creem says now.
        $current = $this->subscription($creemId, $mode);
        if (is_wp_error($current)) {
            throw new \RuntimeException(esc_html($current->get_error_message()));
        }
        $status = (string) ($current['status'] ?? '');
        if (!in_array($status, ['past_due', 'unpaid'], true)) {
            return 'stale (' . $status . '); ' . $this->syncSubscription($current, $mode, 'subscription.update');
        }
        [$category, $code] = EventMap::decline('subscription.' . $status);
        $subscriptionId = $subscription->get_id();
        $outcome = (string) Lock::run('decline-' . $subscriptionId, function () use ($subscriptionId, $creemId, $category, $code) {
            $subscription = Subscriptions::fresh($subscriptionId);
            if (!$subscription) {
                return 'gone';
            }
            $renewal = $this->awaitingRenewal($subscription);
            if (!$renewal) {
                $renewal = $this->createRenewal($subscription);
                if (Orders::isPaid($renewal)) {
                    return 'renewal already paid';
                }
                Orders::setGateway($renewal, $this->gateway->id());
                Orders::setMeta((int) $renewal->id, Gateway::META_AWAITING, 'yes');
                Orders::setMeta((int) $renewal->id, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
                $subscription->update_meta(Gateway::META_AWAITING, (string) $renewal->id);
            }
            $renewalId = (int) $renewal->id;
            if ((string) $renewal->status === 'failed') {
                return 'already failed';
            }
            // The category first: dunning reacts to the status change.
            Orders::setMeta($renewalId, SubscriptionData::META_DECLINE, $category);
            Orders::setMeta($renewalId, SubscriptionData::META_DECLINE_CODE, $code);
            Orders::setStatus($renewalId, 'failed', sprintf(
                /* translators: %s: Creem status such as past_due */
                __('Creem could not take this renewal payment (%s). Creem retries the card on its own schedule; this order is paid when a retry succeeds.', 'stackless-lite-edd'),
                $code
            ));
            return 'renewal failed';
        }, 'busy');
        if ($outcome === 'busy') {
            return 'busy';
        }
        return $outcome . '; ' . $this->syncSubscription($current, $mode, $event);
    }

    /* ---- Refunds and disputes ------------------------------------------------------ */

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

    /**
     * The order a Creem transaction or order belongs to.
     *
     * @param mixed $transaction
     * @param mixed $creemOrder
     */
    private function orderForPayment($transaction, $creemOrder): ?Order
    {
        return $this->findOrder(Gateway::META_TRANSACTION_ID, EventMap::id($transaction))
            ?: $this->findOrder(Gateway::META_ORDER_ID, EventMap::id($creemOrder));
    }

    /**
     * refund.created (sent once the refund succeeded): mirror it once. A full refund made from
     * EDD is already recorded, so nothing is left to refund and only its id is kept.
     */
    public function recordRefund(array $refund): string
    {
        $order = $this->orderForPayment($refund['transaction'] ?? '', $refund['order'] ?? '');
        if (!$order) {
            return 'not ours';
        }
        $refundId = EventMap::id($refund['id'] ?? '');
        if (!in_array((string) ($refund['status'] ?? 'succeeded'), ['succeeded', ''], true)) {
            return 'not succeeded';
        }
        return (string) Lock::run('refund-' . $order->id, function () use ($order, $refund, $refundId) {
            $order = Orders::get((int) $order->id);
            if (!$order || ($refundId !== '' && self::hasRefundId((int) $order->id, $refundId))) {
                return 'duplicate';
            }
            $orderId = (int) $order->id;
            $currency = strtoupper((string) ($refund['refund_currency'] ?? $order->currency));
            if ($currency !== strtoupper((string) $order->currency)) {
                self::recordRefundId($orderId, $refundId);
                Orders::note($orderId, __('Creem reported a refund in a different currency. Record it manually.', 'stackless-lite-edd'));
                return 'currency mismatch';
            }
            $minor = Money::orderRefund((int) ($refund['refund_amount'] ?? 0), Money::toMinor((float) $order->total), (int) Orders::meta($orderId, Gateway::META_TOTAL));
            $amount = min(Money::fromMinor($minor), (float) edd_get_order_total($orderId));
            if ($amount <= 0) {
                self::recordRefundId($orderId, $refundId);
                return 'nothing left';
            }
            Refunds::recordExternal($order, $amount, '', sprintf(
                /* translators: 1: amount, 2: Creem refund id */
                __('Refunded %1$s in Creem (%2$s).', 'stackless-lite-edd'),
                Orders::money($amount, (string) $order->currency),
                $refundId
            ));
            self::recordRefundId($orderId, $refundId);
            return 'refunded';
        }, 'busy');
    }

    public function noteDispute(array $dispute): string
    {
        $order = $this->orderForPayment($dispute['transaction'] ?? '', $dispute['order'] ?? '');
        if (!$order) {
            return 'not ours';
        }
        /* translators: %s: Creem dispute id */
        Orders::note((int) $order->id, sprintf(__('Creem reported a dispute (%s). Review it in the Creem dashboard.', 'stackless-lite-edd'), EventMap::id($dispute['id'] ?? '')));
        return 'noted';
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a subscription in line with a Creem subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $creemId = EventMap::id($remote['id'] ?? '');
        if ($creemId === '') {
            return 'no id';
        }
        self::requireSubscriptions();
        $subscription = $this->findSubscription($creemId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        $status = (string) ($remote['status'] ?? '');
        $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
        $customer = EventMap::id($remote['customer'] ?? '');
        if ($customer !== '') {
            $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $product = EventMap::id($remote['product'] ?? '');
        if ($product !== '') {
            $subscription->update_meta(Gateway::META_PRODUCT_ID, $product);
        }
        $subscription->update_meta(Gateway::META_MODE, $mode);
        $subscription->update_meta(Gateway::META_CANCEL_AT_END, $status === 'scheduled_cancel' ? 'yes' : 'no');
        $next = (string) ($remote['current_period_end_date'] ?? ($remote['next_transaction_date'] ?? ''));
        if ($next !== '') {
            $subscription->update_meta(Gateway::META_NEXT_BILLING, $next);
            // Creem runs the schedule; the subscription shows Creem's next charge date.
            $date = self::date($next);
            if ($date && in_array($status, ['active', 'trialing'], true)) {
                $subscription->set_next_payment_date($date);
            }
        }

        $target = EventMap::status($remote, $event);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: checkout.completed (or the trial path) activates it.
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($target !== null && in_array($target, $active, true) && $parent && !Orders::isPaid($parent)) {
            if ((float) $parent->total <= 0 && in_array($status, ['trialing', 'active'], true) && (string) $parent->gateway === $this->gateway->id()) {
                return $this->confirmTrial($parent, $remote, $mode);
            }
            return 'awaiting first payment';
        }

        if ($target === null) {
            return 'no status change';
        }
        $current = (Subscriptions::fresh($subscription->get_id()) ?: $subscription)->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }
        /* translators: %s: Creem subscription status */
        $note = sprintf(__('Creem subscription is now %s.', 'stackless-lite-edd'), $event === 'subscription.expired' ? 'expired' : $status);
        if (!Subscriptions::setStatus($subscription, $target, $note)) {
            $this->gateway->log('Could not move subscription #' . $subscription->get_id() . ' to ' . $target . ' after Creem ' . $event . '.', 'warning');
            return 'status not applied';
        }
        do_action('stackless_edd_creem_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }

    /** A Creem ISO 8601 timestamp as a UTC date, or null. */
    public static function date(string $value): ?\DateTimeImmutable
    {
        $timestamp = strtotime($value);
        if (!$timestamp) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
