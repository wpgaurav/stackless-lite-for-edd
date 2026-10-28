<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Dodo Payments can take.
 *
 * Each checkout sells one Dodo product: one-time purchases of any size work
 * (several items become a single "store order" product), but a subscription
 * must be the only thing in the cart, and only one subscription.
 */
final class Eligibility
{
    public const OK = '';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';
    public const MIXED_CART = 'mixed_cart';

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

    public static function message(string $reason): string
    {
        switch ($reason) {
            case self::TOO_MANY_SUBSCRIPTIONS:
                return __('Dodo Payments can bill one subscription per checkout. Buy each subscription separately or choose another payment method.', 'stackless-lite-edd');
            case self::MIXED_CART:
                return __('Dodo Payments can bill a subscription only when it is the only item in the cart. Buy other items separately or choose another payment method.', 'stackless-lite-edd');
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
            $priceId = isset($item['options']['price_id']) && is_numeric($item['options']['price_id']) ? (int) $item['options']['price_id'] : null;
            $lines[] = stackless_edd_is_subscription_product((int) $item['id'], $priceId);
        }
        return $lines;
    }

    /** Reason for the current cart. */
    public static function cartReason(): string
    {
        if (!function_exists('edd_get_cart_contents')) {
            return self::OK;
        }
        return self::reason(self::cartLines(), stackless_edd_cart_subscription_count());
    }

    /** Reason for an existing order (pay links, renewals, the order checkout just built). */
    public static function orderReason(Order $order): string
    {
        // A renewal paid through Dodo is a one-time payment of that renewal order.
        if (stackless_edd_is_renewal_order((int) $order->id)) {
            return self::OK;
        }
        $lines = [];
        foreach ($order->get_items() as $item) {
            $priceId = $item->price_id !== null && $item->price_id !== '' ? (int) $item->price_id : null;
            $lines[] = stackless_edd_is_subscription_product((int) $item->product_id, $priceId);
        }
        $subscriptions = stackless_edd_order_contains_subscription((int) $order->id) ? max(1, count(stackless_edd_get_order_subscriptions((int) $order->id))) : 0;
        return self::reason($lines, $subscriptions);
    }
}
