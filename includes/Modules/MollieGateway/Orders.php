<?php

namespace StacklessEDD\Modules\MollieGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Thin EDD 3 order helpers, so the payment flows read like the WooCommerce originals
 * (needs_payment, is_paid, get_meta, update_status with a note).
 *
 * Status map from the WooCommerce edition: Woo "pending/failed" = EDD pending/failed/abandoned
 * (recoverable), Woo "on-hold for an authorization, a mismatch or a chargeback" = EDD on_hold,
 * Woo "on-hold while the bank processes" = EDD processing, Woo "processing/completed" = EDD complete.
 */
final class Orders
{
    public static function get(int $orderId): ?Order
    {
        if ($orderId < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        $order = edd_get_order($orderId);
        return $order instanceof Order ? $order : null;
    }

    /** @return mixed '' when the key is not set */
    public static function meta(int $orderId, string $key)
    {
        $value = edd_get_order_meta($orderId, $key, true);
        return ($value === false || $value === null) ? '' : $value;
    }

    public static function metaString(int $orderId, string $key): string
    {
        $value = self::meta($orderId, $key);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param mixed $value */
    public static function updateMeta(int $orderId, string $key, $value): void
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

    /** Change the status (once) and leave a note explaining why. */
    public static function setStatus(int $orderId, string $status, string $note = ''): void
    {
        $order = self::get($orderId);
        if ($order && (string) $order->status !== $status) {
            edd_update_order_status($orderId, $status);
        }
        if ($note !== '') {
            self::note($orderId, $note);
        }
    }

    /** @param string|string[] $statuses */
    public static function hasStatus(Order $order, $statuses): bool
    {
        return in_array((string) $order->status, (array) $statuses, true);
    }

    /** The WC_Order::needs_payment() counterpart: unpaid and recoverable. */
    public static function needsPayment(Order $order): bool
    {
        return self::hasStatus($order, ['pending', 'failed', 'abandoned']);
    }

    /** Paid at least once (refunds and a later hold for a chargeback included). */
    public static function isPaid(Order $order): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        $completed = isset($order->date_completed) ? (string) $order->date_completed : '';
        return in_array((string) $order->status, $complete, true) || ($completed !== '' && $completed !== '0000-00-00 00:00:00');
    }

    /** Orders that can still take this payment: unpaid, processing at the bank, or authorized and on hold. */
    public static function awaitingPayment(Order $order): bool
    {
        if (self::needsPayment($order) || self::hasStatus($order, 'processing')) {
            return true;
        }
        return self::hasStatus($order, 'on_hold') && !self::isPaid($order);
    }

    /** Most recent order (any type) carrying $key = $value in its meta. */
    public static function findByMeta(string $key, string $value): ?Order
    {
        if ($value === '') {
            return null;
        }
        $orders = edd_get_orders([
            'number' => 1,
            'orderby' => 'id',
            'order' => 'DESC',
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds one order by the Mollie payment id, once per webhook or return.
        ]);
        $order = is_array($orders) ? reset($orders) : false;
        return $order instanceof Order ? $order : null;
    }

    /** Order number for display (EDD sequential numbers when enabled). */
    public static function number(Order $order): string
    {
        $number = (string) $order->get_number();
        return $number !== '' ? $number : (string) $order->id;
    }

    /**
     * A field of an order address (EDD returns an Order_Address, or a plain object when none is stored).
     *
     * @param mixed $address
     */
    public static function field($address, string $key): string
    {
        return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? (string) $address->{$key} : '';
    }

    public static function customerName(Order $order): string
    {
        $address = $order->get_address();
        $name = trim(self::field($address, 'first_name') . ' ' . self::field($address, 'last_name'));
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        return $name;
    }

    /**
     * Signature for links that come back from Mollie (return URLs). The payment key is not put
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

    /** "12.00 EUR"-style plain text, for notes. @param float|string $amount */
    public static function money($amount, string $currency): string
    {
        return wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, strtoupper($currency)), ENT_QUOTES, 'UTF-8'));
    }
}
