<?php

namespace StacklessEDD\Modules\StripeGateway\Managed;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Stripe Managed Payments can take.
 *
 * Stripe is the merchant of record only for digital goods (software, digital media, online
 * courses, electronically supplied services). EDD downloads are digital, but professional
 * services are not eligible, so downloads Service Products marks as a service keep the method
 * hidden. One Checkout Session creates at most one Stripe subscription with one billing
 * schedule; one-time items may ride along on its first invoice.
 */
final class Eligibility
{
    public const OK = '';
    public const NOT_DIGITAL = 'not_digital';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';

    /**
     * @param array<int,array{digital:bool,subscription:bool}> $lines One per cart line/order item.
     * @param int $subscriptions Distinct subscriptions the cart/order creates (one per billing schedule).
     * @return string Reason code, or self::OK.
     */
    public static function reason(array $lines, int $subscriptions): string
    {
        $subscriptionLines = 0;
        foreach ($lines as $line) {
            if (empty($line['digital'])) {
                return self::NOT_DIGITAL;
            }
            if (!empty($line['subscription'])) {
                $subscriptionLines++;
            }
        }
        $subscriptions = max($subscriptions, $subscriptionLines > 0 ? 1 : 0);
        return $subscriptions > 1 ? self::TOO_MANY_SUBSCRIPTIONS : self::OK;
    }

    public static function message(string $reason): string
    {
        switch ($reason) {
            case self::NOT_DIGITAL:
                return __('Stripe Managed Payments sells digital products only, not services. Remove the service or choose another payment method.', 'stackless-lite-edd');
            case self::TOO_MANY_SUBSCRIPTIONS:
                return __('Stripe Managed Payments can bill one subscription schedule per checkout. Buy subscriptions with different billing schedules separately or choose another payment method.', 'stackless-lite-edd');
        }
        return '';
    }

    /** True for downloads Stripe can sell as merchant of record: every download except services. */
    public static function isDigital(int $download_id): bool
    {
        $digital = !apply_filters('stackless_edd_is_service_download', false, $download_id);
        return (bool) apply_filters('stackless_edd_stripe_managed_is_digital', $digital, $download_id);
    }

    /** Lines of the current cart. @return array<int,array{digital:bool,subscription:bool}> */
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
            $id = (int) $item['id'];
            $price_id = isset($item['options']['price_id']) && is_numeric($item['options']['price_id']) ? (int) $item['options']['price_id'] : null;
            $lines[] = ['digital' => self::isDigital($id), 'subscription' => stackless_edd_is_subscription_product($id, $price_id)];
        }
        return $lines;
    }

    public static function cartReason(): string
    {
        if (!function_exists('edd_get_cart_contents')) {
            return self::OK;
        }
        return self::reason(self::cartLines(), stackless_edd_cart_subscription_count());
    }

    /** Reason for an existing order (the order checkout just built). */
    public static function orderReason(Order $order): string
    {
        $lines = [];
        foreach ($order->get_items() as $item) {
            $price_id = $item->price_id !== null && $item->price_id !== '' ? (int) $item->price_id : null;
            $lines[] = ['digital' => self::isDigital((int) $item->product_id), 'subscription' => stackless_edd_is_subscription_product((int) $item->product_id, $price_id)];
        }
        $subscriptions = stackless_edd_order_contains_subscription((int) $order->id) ? max(1, count(stackless_edd_get_order_subscriptions((int) $order->id))) : 0;
        return self::reason($lines, $subscriptions);
    }
}
