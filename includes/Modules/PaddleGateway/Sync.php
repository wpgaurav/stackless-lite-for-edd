<?php

namespace StacklessEDD\Modules\PaddleGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Paddle payment, renewal and subscription state to EDD.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while
 * unpaid, renewals are keyed by Paddle transaction id.
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

    /** True when custom data names another site sharing this Paddle account. */
    public static function otherSite(array $customData): bool
    {
        $site = (string) ($customData['edd_site'] ?? '');
        return $site !== '' && $site !== Checkout::siteHost();
    }

    /** Stackless subscription linked to a Paddle subscription (by stored id, else by custom data). */
    public function findSubscription(string $paddleId, array $customData): ?SubscriptionData
    {
        if ($paddleId !== '') {
            // The parent order and every renewal carry the Paddle id; each leads to its subscription.
            foreach (Orders::findAllByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleId) as $order) {
                $subscriptions = array_values(array_filter(stackless_edd_get_order_subscriptions((int) $order->id), static function ($s): bool {
                    return $s instanceof SubscriptionData;
                }));
                foreach ($subscriptions as $subscription) {
                    if ((string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === $paddleId) {
                        return $subscription;
                    }
                }
                if (count($subscriptions) === 1 && $subscriptions[0]->get_parent_order_id() === (int) $order->id) {
                    return $subscriptions[0];
                }
            }
        }

        if (self::otherSite($customData)) {
            return null;
        }
        $storeId = absint($customData['edd_subscription_id'] ?? 0);
        $subscription = $storeId ? stackless_edd_get_subscription($storeId) : null;
        if (!$subscription instanceof SubscriptionData) {
            return null;
        }
        $parent = absint($customData['edd_order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_order_id() !== $parent) || ($linked !== '' && $paddleId !== '' && $linked !== $paddleId)) {
            return null;
        }
        return $subscription;
    }

    /* ---- First payments ---------------------------------------------------------- */

    /**
     * Look up a transaction and confirm the order when it is paid (browser return).
     *
     * @return true|\WP_Error
     */
    public function confirmById(Order $order, string $transactionId)
    {
        $mode = $this->gateway->orderMode((int) $order->id);
        $transaction = $this->gateway->api($mode)->request('GET', 'transactions/' . rawurlencode($transactionId));
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if ((string) ($transaction['custom_data']['edd_order_id'] ?? '') !== (string) $order->id) {
            return new \WP_Error('stackless_edd_paddle_mismatch', __('This Paddle transaction belongs to a different order.', 'stackless-lite-edd'));
        }
        if (!EventMap::isPaid($transaction)) {
            /* translators: %s: Paddle transaction status */
            return new \WP_Error('stackless_edd_paddle_unpaid', sprintf(__('The Paddle transaction is %s, not paid yet. It will be confirmed automatically.', 'stackless-lite-edd'), (string) ($transaction['status'] ?? 'unknown')));
        }
        $this->confirmPayment($order, $transaction, $mode);
        return true;
    }

    /** Route a paid Paddle transaction to the first payment or a renewal (webhook). */
    public function confirmTransaction(array $transaction, string $mode): string
    {
        if (!EventMap::isPaid($transaction)) {
            return 'unpaid';
        }
        $kind = EventMap::transactionKind($transaction);
        if ($kind === 'renewal') {
            return $this->recordRenewal($transaction, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored'; // Plan changes, one-off charges and card updates are started in Paddle, not here.
        }

        $customData = (array) ($transaction['custom_data'] ?? []);
        if (self::otherSite($customData)) {
            return 'other site';
        }
        $order = Orders::get(absint($customData['edd_order_id'] ?? 0));
        if (!$order || $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        $stored = Orders::meta((int) $order->id, Gateway::META_TRANSACTION_ID);
        if ($stored !== '' && $stored !== (string) ($transaction['id'] ?? '')) {
            return 'transaction mismatch';
        }
        return $this->confirmPayment($order, $transaction, $mode);
    }

    public function confirmPayment(Order $order, array $transaction, string $mode): string
    {
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $transaction, $mode) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'gone';
            }
            $transactionId = (string) ($transaction['id'] ?? '');
            $paddleSubscription = (string) ($transaction['subscription_id'] ?? '');
            $customer = (string) ($transaction['customer_id'] ?? '');

            if (Orders::isPaid($order)) {
                // transaction.completed after transaction.paid: the subscription id arrives now.
                if ($paddleSubscription !== '' && Orders::meta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION) === '') {
                    $this->linkSubscription($orderId, $paddleSubscription, $customer, $mode);
                    return 'linked subscription';
                }
                return 'already paid';
            }

            $totals = (array) ($transaction['details']['totals'] ?? []);
            $currency = strtoupper((string) ($transaction['currency_code'] ?? ($totals['currency_code'] ?? '')));
            $orderCurrency = strtoupper((string) $order->currency);
            Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);

            if ($currency !== '' && $currency !== $orderCurrency) {
                Orders::hold($order, sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Paddle charged in %2$s but the order is in %1$s. Check the payment in Paddle.', 'stackless-lite-edd'),
                    $orderCurrency,
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : $orderCurrency;
            self::storeAmounts($orderId, $totals, $currency);

            if (!Money::paidMatches($totals, Money::toMinor($order->total, $currency))) {
                Orders::hold($order, sprintf(
                    /* translators: 1: amount Paddle charged, 2: order total */
                    __('Paddle charged %1$s but the order total is %2$s. Check the payment in Paddle before fulfilling.', 'stackless-lite-edd'),
                    self::price((int) ($totals['grand_total'] ?? 0), $currency),
                    Money::label($order->total, $orderCurrency)
                ));
                return 'amount mismatch';
            }

            if ($paddleSubscription !== '') {
                $this->linkSubscription($orderId, $paddleSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }

            stackless_edd_add_order_note($orderId, sprintf(
                /* translators: 1: Paddle transaction id, 2: total charged including tax */
                __('Paddle transaction %1$s paid (%2$s charged including tax).', 'stackless-lite-edd'),
                $transactionId,
                self::price((int) ($totals['grand_total'] ?? 0), $currency)
            ));
            stackless_edd_complete_order($orderId, $transactionId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial when the subscription shows up before its zero-amount transaction is reported. */
    public function confirmTrial(Order $order, array $remote, string $mode): string
    {
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $remote, $mode) {
            $order = Orders::get($orderId);
            if (!$order || Orders::isPaid($order)) {
                return 'already paid';
            }
            if ((float) $order->total > 0) {
                return 'not a trial';
            }
            $this->linkSubscription($orderId, (string) ($remote['id'] ?? ''), (string) ($remote['customer_id'] ?? ''), $mode);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            /* translators: %s: Paddle subscription id */
            stackless_edd_add_order_note($orderId, sprintf(__('Paddle subscription %s started its free trial.', 'stackless-lite-edd'), (string) ($remote['id'] ?? '')));
            stackless_edd_complete_order($orderId, Orders::meta($orderId, Gateway::META_TRANSACTION_ID));
            return 'trial';
        }, 'busy');
    }

    /** Write the Paddle subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(int $orderId, string $paddleSubscription, string $customer, string $mode): void
    {
        if ($paddleSubscription === '') {
            return;
        }
        Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleSubscription);
        if ($customer !== '') {
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        foreach (stackless_edd_get_order_subscriptions($orderId) as $subscription) {
            if (!$subscription instanceof SubscriptionData) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleSubscription);
            if ($customer !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $subscription->update_meta(Gateway::META_MODE, $mode);
        }
    }

    private static function storeAmounts(int $orderId, array $totals, string $currency): void
    {
        $decimals = Money::decimals($currency);
        Orders::setMeta($orderId, Gateway::META_TAX, stackless_edd_decimal(Money::fromMinor((int) ($totals['tax'] ?? 0), $currency), $decimals));
        Orders::setMeta($orderId, Gateway::META_TOTAL, stackless_edd_decimal(Money::fromMinor((int) ($totals['grand_total'] ?? ($totals['total'] ?? 0)), $currency), $decimals));
    }

    private static function price(int $minor, string $currency): string
    {
        return Money::label(Money::fromMinor($minor, $currency), $currency);
    }

    /* ---- Failed payments ---------------------------------------------------------- */

    /** Note a failed attempt; Paddle retries renewals itself and reports past_due through the subscription. */
    public function paymentFailed(array $transaction, string $mode): string
    {
        $customData = (array) ($transaction['custom_data'] ?? []);
        if (self::otherSite($customData)) {
            return 'other site';
        }
        $error = '';
        foreach ((array) ($transaction['payments'] ?? []) as $payment) {
            if (is_array($payment) && !empty($payment['error_code'])) {
                $error = (string) $payment['error_code'];
                break;
            }
        }
        $note = sprintf(
            /* translators: 1: Paddle transaction id, 2: decline reason code */
            __('Paddle could not take a payment for transaction %1$s (%2$s).', 'stackless-lite-edd'),
            (string) ($transaction['id'] ?? ''),
            $error !== '' ? $error : __('no reason given', 'stackless-lite-edd')
        );
        if (EventMap::transactionKind($transaction) === 'renewal') {
            $subscription = $this->findSubscription((string) ($transaction['subscription_id'] ?? ''), $customData);
            if (!$subscription) {
                return 'not ours';
            }
            $awaiting = Orders::get(absint($subscription->get_meta(Gateway::META_AWAITING)));
            if ($awaiting) {
                stackless_edd_add_order_note((int) $awaiting->id, $note);
            } else {
                Subscriptions::note($subscription, $note);
            }
            return 'noted';
        }
        $order = Orders::get(absint($customData['edd_order_id'] ?? 0));
        if (!$order || $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        stackless_edd_add_order_note((int) $order->id, $note);
        return 'noted';
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Paddle charged becomes a paid EDD renewal order. */
    public function recordRenewal(array $transaction, string $mode): string
    {
        $transactionId = (string) ($transaction['id'] ?? '');
        $paddleSubscription = (string) ($transaction['subscription_id'] ?? '');
        if ($transactionId === '' || $paddleSubscription === '') {
            return 'no subscription';
        }
        if (self::findOrderByMeta(Gateway::META_TRANSACTION_ID, $transactionId)) {
            return 'duplicate';
        }
        $subscription = $this->findSubscription($paddleSubscription, (array) ($transaction['custom_data'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $transactionId . ' arrived for unknown Paddle subscription ' . $paddleSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $transactionId, function () use ($transaction, $transactionId, $paddleSubscription, $subscription, $mode) {
            if (self::findOrderByMeta(Gateway::META_TRANSACTION_ID, $transactionId)) {
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
                    // Thrown so the webhook answers 500 and Paddle retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = Orders::get((int) $created);
                if (!$renewal) {
                    throw new \RuntimeException('Could not load renewal order ' . (int) $created . '.');
                }
            }
            $renewalId = (int) $renewal->id;

            $totals = (array) ($transaction['details']['totals'] ?? []);
            $renewalCurrency = strtoupper((string) $renewal->currency);
            $currency = strtoupper((string) ($transaction['currency_code'] ?? $renewalCurrency));
            Orders::setGateway($renewal, $this->gateway->id());
            Orders::deleteMeta($renewalId, Gateway::META_AWAITING);
            Orders::setMeta($renewalId, Gateway::META_TRANSACTION_ID, $transactionId);
            Orders::setMeta($renewalId, Gateway::META_MODE, $mode);
            Orders::setMeta($renewalId, SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleSubscription);
            self::storeAmounts($renewalId, $totals, $currency);

            $subscription->update_meta(Gateway::META_AWAITING, '');
            $end = (string) ($transaction['billing_period']['ends_at'] ?? '');
            if ($end !== '') {
                $subscription->update_meta(Gateway::META_PERIOD_END, $end);
            }

            if ($currency !== $renewalCurrency) {
                Orders::hold($renewal, sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Paddle charged this renewal in %2$s but the subscription bills in %1$s. Check it in Paddle.', 'stackless-lite-edd'),
                    $renewalCurrency,
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($totals, Money::toMinor($renewal->total, $currency))) {
                // Paddle already took the money; record it and flag the difference.
                stackless_edd_add_order_note($renewalId, sprintf(
                    /* translators: 1: amount Paddle charged, 2: renewal total */
                    __('Paddle charged %1$s for this renewal; the EDD renewal total is %2$s.', 'stackless-lite-edd'),
                    self::price((int) ($totals['grand_total'] ?? 0), $currency),
                    Money::label($renewal->total, $renewalCurrency)
                ));
            }
            /* translators: %s: Paddle transaction id */
            stackless_edd_add_order_note($renewalId, sprintf(__('Paddle charged this renewal (transaction %s).', 'stackless-lite-edd'), $transactionId));
            stackless_edd_complete_order($renewalId, $transactionId);
            return 'renewed';
        }, 'busy');
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a Stackless subscription in line with a Paddle subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $paddleId = (string) ($remote['id'] ?? '');
        if ($paddleId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($paddleId, (array) ($remote['custom_data'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_gateway(), ['', $this->gateway->id()], true)) {
            return 'other gateway';
        }

        // Webhooks can arrive out of order; ignore one older than what was applied.
        $updated = strtotime((string) ($remote['updated_at'] ?? '')) ?: 0;
        $applied = (int) $subscription->get_meta(Gateway::META_SYNCED_AT);
        if ($updated && $applied && $updated < $applied) {
            return 'stale';
        }

        $subscription->update_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleId);
        if (!empty($remote['customer_id'])) {
            $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer_id']);
        }
        $subscription->update_meta(Gateway::META_MODE, $mode);
        $subscription->update_meta(Gateway::META_CANCEL_AT_END, EventMap::cancelScheduled($remote) ? 'yes' : 'no');
        if ($updated) {
            $subscription->update_meta(Gateway::META_SYNCED_AT, (string) $updated);
        }
        $end = (string) ($remote['current_billing_period']['ends_at'] ?? '');
        if ($end !== '') {
            $subscription->update_meta(Gateway::META_PERIOD_END, $end);
        }
        // Paddle runs the schedule; let the Subscriptions module show Paddle's next charge date.
        $next = Subscriptions::date(EventMap::nextCharge($remote));
        if ($next) {
            $subscription->set_next_payment_date($next);
        }

        $target = EventMap::status($remote);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: the transaction webhook (or the trial path) activates it.
        $parent = Orders::get($subscription->get_parent_order_id());
        if ($target !== null && in_array($target, $active, true) && $parent && !Orders::isPaid($parent)) {
            if ((float) $parent->total <= 0 && in_array($remote['status'] ?? '', ['trialing', 'active'], true) && $parent->gateway === $this->gateway->id()) {
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

        /* translators: %s: Paddle subscription status */
        $note = sprintf(__('Paddle subscription is now %s.', 'stackless-lite-edd'), (string) ($remote['status'] ?? $event));
        if (EventMap::cancelScheduled($remote) && $target === SubscriptionData::STATUS_PENDING_CANCEL) {
            $note = __('Paddle subscription is set to end at the close of the paid period.', 'stackless-lite-edd');
        }
        if (!Subscriptions::setStatus($subscription, $target, $note)) {
            $this->gateway->log('Subscription ' . $subscription->get_id() . ' could not move to ' . $target . ' (' . $event . ').', 'warning');
            return 'status ' . $target . ' not applied';
        }
        do_action('stackless_edd_paddle_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
