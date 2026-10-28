<?php

namespace StacklessEDD\Modules\PolarGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Thin helpers over the EDD 3 order API, so the payment flows read like the WooCommerce
 * originals (is_paid, get_meta, update_status with a note).
 */
final class Orders
{
    public static function get(int $id): ?Order
    {
        $order = $id > 0 ? edd_get_order($id) : false;
        return $order instanceof Order ? $order : null;
    }

    /** Paid, or past paid (partially refunded, refunded, revoked). */
    public static function isPaid(Order $order): bool
    {
        return in_array((string) $order->status, edd_get_complete_order_statuses(), true);
    }

    public static function meta(int $orderId, string $key): string
    {
        $value = edd_get_order_meta($orderId, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param mixed $value */
    public static function setMeta(int $orderId, string $key, $value): void
    {
        edd_update_order_meta($orderId, $key, $value);
    }

    public static function deleteMeta(int $orderId, string $key): void
    {
        edd_delete_order_meta($orderId, $key);
    }

    public static function note(int $orderId, string $note): void
    {
        stackless_edd_add_order_note($orderId, $note);
    }

    /** Move an order to a status with a note, like WC_Order::update_status(). */
    public static function setStatus(int $orderId, string $status, string $note = ''): void
    {
        if ($note !== '') {
            self::note($orderId, $note);
        }
        $order = self::get($orderId);
        if ($order && (string) $order->status !== $status) {
            edd_update_order_status($orderId, $status);
        }
    }

    public static function setGateway(Order $order, string $gateway): void
    {
        if ((string) $order->gateway !== $gateway) {
            edd_update_order($order->id, ['gateway' => $gateway]);
        }
    }

    /** Most recent sale or renewal order carrying this meta value. */
    public static function findByMeta(string $key, string $value, string $gateway = ''): ?Order
    {
        if ($value === '') {
            return null;
        }
        $args = [
            'number' => 1,
            'type' => 'sale',
            'orderby' => 'id',
            'order' => 'DESC',
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds one order by the provider's ID, once per webhook or return.
        ];
        if ($gateway !== '') {
            $args['gateway'] = $gateway;
        }
        $orders = edd_get_orders($args);
        $order = is_array($orders) ? reset($orders) : false;
        return $order instanceof Order ? $order : null;
    }

    /**
     * Signature for links that come back from Polar (return URLs). The payment key is not put
     * in those URLs: it also unlocks the receipt and its download links.
     */
    public static function returnKey(Order $order, string $purpose): string
    {
        return substr(hash_hmac('sha256', $purpose . '|' . $order->id . '|' . $order->payment_key, wp_salt('auth')), 0, 32);
    }

    /** Where a buyer finishes paying a pending order: EDD's order recovery link, else checkout. */
    public static function payUrl(Order $order): string
    {
        $url = function_exists('stackless_edd_order_pay_url') ? stackless_edd_order_pay_url((int) $order->id) : '';
        return $url !== '' ? $url : edd_get_checkout_uri();
    }

    /** "1,234.50 USD"-style plain text, for notes. @param float|string $amount */
    public static function money($amount, string $currency): string
    {
        return wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, strtoupper($currency)), ENT_QUOTES, 'UTF-8'));
    }
}
