<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Creem can take.
 *
 * Each checkout sells one Creem product: one-time purchases of any size work
 * (several items become a single "store order" product), but a
 * subscription must be the only thing in the cart, and only one subscription.
 * A renewal of a subscription that a provider bills itself is never paid here.
 */
final class Eligibility
{
    public const OK = '';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';
    public const MIXED_CART = 'mixed_cart';
    public const PROVIDER_BILLED = 'provider_billed';

    /**
     * @param bool[] $lines           One entry per cart line/order item: true when it is a subscription download.
     * @param int    $subscriptions   Distinct subscriptions the cart/order creates (one per billing schedule).
     * @return string Reason code, or self::OK.
     */
    public static function reason(array $lines, int $subscriptions): string
    {
        $subscriptionLines = count(array_filter($lines));
        $subscriptions = max($subscriptions, $subscriptionLines > 0 ? 1 : 0);

        if ($subscriptions === 0) {
            return self::OK;
        }
        if ($subscriptions > 1 || $subscriptionLines > 1) {
            return self::TOO_MANY_SUBSCRIPTIONS;
        }
        if (count($lines) !== 1) {
            return self::MIXED_CART;
        }
        return self::OK;
    }

    /** @param bool[] $lines */
    public static function allowed(array $lines, int $subscriptions): bool
    {
        return self::reason($lines, $subscriptions) === self::OK;
    }

    /**
     * Whether a renewal order belongs to a subscription a provider charges itself.
     *
     * @param string $gatewaySubscription The provider subscription id stored on the renewal order.
     * @param bool   $payLinkAllowed      What stackless_edd_order_pay_link_allowed() says about it.
     */
    public static function providerBilled(string $gatewaySubscription, bool $payLinkAllowed): bool
    {
        return $gatewaySubscription !== '' || !$payLinkAllowed;
    }

    public static function message(string $reason): string
    {
        switch ($reason) {
            case self::TOO_MANY_SUBSCRIPTIONS:
                return __('Creem can bill one subscription per checkout. Buy each subscription separately or choose another payment method.', 'stackless-lite-edd');
            case self::MIXED_CART:
                return __('Creem can bill a subscription only when it is the only item in the cart. Buy other items separately or choose another payment method.', 'stackless-lite-edd');
            case self::PROVIDER_BILLED:
                return __("Creem bills this subscription itself and keeps retrying the card on file, so this renewal can't be paid again here. Update the card in Creem's customer portal (linked in your Creem receipts).", 'stackless-lite-edd');
            default:
                return '';
        }
    }

    /** Lines of the current cart. @return bool[] */
    public static function cartLines(): array
    {
        $lines = [];
        if (!function_exists('edd_get_cart_contents')) {
            return $lines;
        }
        foreach ((array) edd_get_cart_contents() as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $priceId = $item['options']['price_id'] ?? null;
            $lines[] = stackless_edd_is_subscription_product((int) $item['id'], is_numeric($priceId) ? (int) $priceId : null);
        }
        return $lines;
    }

    /** Reason for the current cart (including a pending order EDD resumed into it). */
    public static function cartReason(): string
    {
        if (!function_exists('edd_get_cart_contents')) {
            return self::OK;
        }
        $resumed = self::resumedOrder();
        if ($resumed && self::orderReason($resumed) === self::PROVIDER_BILLED) {
            return self::PROVIDER_BILLED;
        }
        return self::reason(self::cartLines(), stackless_edd_cart_subscription_count());
    }

    /** The pending order EDD's recovery link loaded into the cart, if any. */
    public static function resumedOrder(): ?Order
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return null;
        }
        $orderId = absint(EDD()->session->get('edd_resume_payment'));
        return $orderId ? Orders::get($orderId) : null;
    }

    /** Reason for an existing order (at purchase, after EDD created the pending order). */
    public static function orderReason(Order $order): string
    {
        $orderId = (int) $order->id;
        if (stackless_edd_is_renewal_order($orderId)) {
            // Creem and other providers keep charging their own subscriptions: paying such a
            // renewal here as well would charge the customer twice. Other renewals are a one-time
            // payment of that order.
            $allowed = function_exists('stackless_edd_order_pay_link_allowed') ? stackless_edd_order_pay_link_allowed($orderId) : true;
            return self::providerBilled(Orders::meta($orderId, SubscriptionData::META_GATEWAY_SUBSCRIPTION), $allowed) ? self::PROVIDER_BILLED : self::OK;
        }
        $lines = [];
        foreach ((array) $order->get_items() as $item) {
            if (!is_object($item)) {
                continue;
            }
            $priceId = $item->price_id;
            $lines[] = stackless_edd_is_subscription_product((int) $item->product_id, is_numeric($priceId) ? (int) $priceId : null);
        }
        $subscriptions = stackless_edd_order_contains_subscription($orderId) ? max(1, count(stackless_edd_get_order_subscriptions($orderId))) : 0;
        return self::reason($lines, $subscriptions);
    }
}
