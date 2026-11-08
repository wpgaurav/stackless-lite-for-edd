<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Thin helpers over the EDD 3 order API, so the payment flows read like the WooCommerce
 * originals (is_paid, get_meta, update_status with a note). Orders are looked up only through
 * edd_get_orders() with a meta_query, never through edd_payment posts.
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

    /** Most recent sale or renewal order carrying this meta value (of one gateway when given). */
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
     * Signature for links that come back from Creem (return URLs). The payment key is not put
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

    /** The receipt page of an order. */
    public static function receiptUrl(Order $order): string
    {
        return function_exists('edd_get_receipt_page_uri') ? (string) edd_get_receipt_page_uri((int) $order->id) : (string) edd_get_success_page_uri();
    }

    /**
     * EDD keeps a transaction's gateway in a 20-character column (edd_order_transactions.gateway)
     * and wpdb refuses longer values, so a transaction for a copy such as
     * "stackless_edd_creem_copy2" would never be stored. Its gateway is cut to fit; the order
     * keeps the full gateway id.
     */
    public static function transactionGateway(string $gateway): string
    {
        return substr($gateway, 0, 20);
    }

    /** Record a provider transaction on an order (or refund) once. */
    public static function addTransaction(int $objectId, string $transactionId, string $gateway, float $total): void
    {
        if ($objectId <= 0 || $transactionId === '' || !function_exists('edd_add_order_transaction') || !function_exists('edd_get_order_transactions')) {
            return;
        }
        $existing = edd_get_order_transactions(['object_id' => $objectId, 'object_type' => 'order', 'transaction_id' => $transactionId, 'number' => 1]);
        if ($existing) {
            return;
        }
        edd_add_order_transaction([
            'object_id' => $objectId,
            'object_type' => 'order',
            'transaction_id' => $transactionId,
            'gateway' => self::transactionGateway($gateway),
            'status' => 'complete',
            'total' => $total,
        ]);
    }

    /** Mark an order paid with its provider transaction (see transactionGateway()). */
    public static function complete(int $orderId, string $transactionId, string $note): void
    {
        $order = self::get($orderId);
        if ($order) {
            self::addTransaction($orderId, $transactionId, (string) $order->gateway, (float) $order->total);
        }
        stackless_edd_complete_order($orderId, $transactionId, $note);
    }

    /** "1,234.50 USD"-style plain text, for notes. @param float|string $amount */
    public static function money($amount, string $currency): string
    {
        return wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, strtoupper($currency)), ENT_QUOTES, 'UTF-8'));
    }
}
