<?php

namespace StacklessEDD\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Thin EDD 3 order helpers the payment engine uses in place of WC_Order methods: meta, notes,
 * statuses and the "can this order still take a payment" checks.
 *
 * Status map from the WooCommerce edition: Woo "pending/failed" = EDD pending/failed/abandoned
 * (recoverable), Woo "on-hold for an authorization or a mismatch" = EDD on_hold, Woo "on-hold
 * while the bank processes" = EDD processing, Woo "processing/completed" = EDD complete.
 */
final class Orders
{
    public static function get(int $order_id): ?\EDD\Orders\Order
    {
        if ($order_id < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        $order = edd_get_order($order_id);
        return $order instanceof \EDD\Orders\Order ? $order : null;
    }

    /** @return mixed '' when the key is not set */
    public static function meta(int $order_id, string $key)
    {
        $value = edd_get_order_meta($order_id, $key, true);
        return ($value === false || $value === null) ? '' : $value;
    }

    /** @param mixed $value */
    public static function updateMeta(int $order_id, string $key, $value): void
    {
        edd_update_order_meta($order_id, $key, $value);
    }

    public static function deleteMeta(int $order_id, string $key): void
    {
        edd_delete_order_meta($order_id, $key);
    }

    public static function note(int $order_id, string $note): void
    {
        stackless_edd_add_order_note($order_id, $note);
    }

    /** Change the status (once) and leave a note explaining why. */
    public static function setStatus(int $order_id, string $status, string $note = ''): void
    {
        $order = self::get($order_id);
        if ($order && $order->status !== $status) {
            edd_update_order_status($order_id, $status);
        }
        if ($note !== '') {
            self::note($order_id, $note);
        }
    }

    /** @param string|string[] $statuses */
    public static function hasStatus(\EDD\Orders\Order $order, $statuses): bool
    {
        return in_array((string) $order->status, (array) $statuses, true);
    }

    /** The WC_Order::needs_payment() counterpart: unpaid and recoverable. */
    public static function needsPayment(\EDD\Orders\Order $order): bool
    {
        return self::hasStatus($order, ['pending', 'failed', 'abandoned']);
    }

    /** Paid at least once (refunds included). */
    public static function isPaid(\EDD\Orders\Order $order): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        return in_array((string) $order->status, $complete, true) || (!empty($order->date_completed) && $order->date_completed !== '0000-00-00 00:00:00');
    }

    /** Orders that can still take this payment: unpaid, processing at the bank, or authorized and on hold. */
    public static function awaitingPayment(\EDD\Orders\Order $order): bool
    {
        if (self::needsPayment($order) || self::hasStatus($order, 'processing')) {
            return true;
        }
        return self::hasStatus($order, 'on_hold') && !self::isPaid($order);
    }

    /** Record a gateway transaction on the order once (EDD's equivalent of set_transaction_id). */
    public static function addTransaction(\EDD\Orders\Order $order, string $transaction_id, string $status = 'pending'): void
    {
        if ($transaction_id === '' || !function_exists('edd_add_order_transaction')) {
            return;
        }
        $existing = edd_get_order_transactions(['object_id' => (int) $order->id, 'object_type' => 'order', 'transaction_id' => $transaction_id, 'number' => 1]);
        if ($existing) {
            return;
        }
        edd_add_order_transaction([
            'object_id' => (int) $order->id,
            'object_type' => 'order',
            'transaction_id' => $transaction_id,
            'gateway' => (string) $order->gateway,
            'status' => $status,
            'total' => $order->total,
        ]);
    }

    /** First order (any type or status) carrying $key = $value in its meta. */
    public static function findByMeta(string $key, string $value): ?\EDD\Orders\Order
    {
        if ($value === '') {
            return null;
        }
        $orders = edd_get_orders([
            'number' => 1,
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds one order by the provider's ID, once per webhook or return.
        ]);
        $order = $orders ? reset($orders) : null;
        return $order instanceof \EDD\Orders\Order ? $order : null;
    }

    public static function customerName(\EDD\Orders\Order $order): string
    {
        $address = $order->get_address();
        $name = trim(self::field($address, 'first_name') . ' ' . self::field($address, 'last_name'));
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        return $name;
    }

    /** Order number for display (EDD sequential numbers when enabled). */
    public static function number(\EDD\Orders\Order $order): string
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
}
