<?php

namespace StacklessEDD\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a Core\Gateway that can renew Stackless subscriptions automatically.
 *
 * Third-party EDD gateways have no gateway object; subscriptions bought with them renew in
 * manual mode (a pending renewal order plus a payment link, see stackless_edd_order_pay_url()).
 *
 * Flow the Subscriptions module follows:
 *  1. Checkout: the gateway sees stackless_edd_order_requires_saved_payment_method($order_id) === true,
 *     saves a reusable method, writes SubscriptionData::META_TOKEN_ID (+ customer id) on the parent
 *     order, then completes it with stackless_edd_complete_order(). Zero-total (all-trial) orders still
 *     go through the gateway so the method can be saved.
 *  2. Renewal due: Subscriptions creates a pending renewal order (same currency, items, gateway,
 *     token meta) and calls stackless_edd_charge_renewal().
 *  3. Subscription state follows the renewal ORDER status: complete => renewed, failed => dunning.
 *     Async gateways return 'pending' and complete the order later from their webhook.
 */
interface SubscriptionGateway
{
    /** auto_renewal, multiple_subscriptions, zero_total_setup, payment_method_change, amount_change, date_change, gateway_managed */
    public const FEATURES = [
        'auto_renewal',
        'multiple_subscriptions',
        'zero_total_setup',
        'payment_method_change',
        'amount_change',
        'date_change',
        'gateway_managed',
    ];

    public function stackless_edd_supports(string $feature): bool;

    /**
     * Charge a renewal off-session. Must be idempotent per renewal order
     * (use the order id as the provider idempotency key).
     *
     * On success call stackless_edd_complete_order($renewal_order->id, $transaction_id) yourself.
     *
     * @return array{result:string, message?:string} result: success|pending|failed
     */
    public function stackless_edd_charge_renewal(\EDD\Orders\Order $renewal_order, SubscriptionData $subscription): array;

    /**
     * For gateway_managed gateways (provider runs the schedule): cancel or schedule cancellation
     * at the provider. Others return true.
     */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool;
}
