<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Thin helpers over the EDD 3 order API, so the payment flows read like the WooCommerce
 * originals (is_paid, get_meta, update_status with a note).
 *
 * Status map from the WooCommerce edition: Woo pending/failed = EDD pending/failed/abandoned
 * (can still be paid), Woo on-hold (amount mismatch, eNACH waiting for the bank) = EDD on_hold,
 * Woo processing/completed = EDD complete.
 */
final class Orders
{
    public static function get(int $id): ?Order
    {
        if ($id < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        $order = edd_get_order($id);
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
        $order = self::get($orderId);
        if ($order && (string) $order->status !== $status) {
            edd_update_order_status($orderId, $status);
        }
        if ($note !== '') {
            self::note($orderId, $note);
        }
    }

    /**
     * EDD keeps a transaction's gateway in a 20-character column, and "stackless_edd_razorpay" is
     * longer, so edd_add_order_transaction() refuses it. Transactions are written here with the
     * gateway id cut to fit (the order itself keeps the full id).
     */
    public static function transactionGateway(): string
    {
        return substr(Gateway::ID, 0, 20);
    }

    /** Record a Razorpay payment or refund id on an EDD order (or refund order) once. */
    public static function addTransaction(int $objectId, string $transactionId, float $total, string $status = 'complete'): void
    {
        if ($objectId < 1 || $transactionId === '' || !function_exists('edd_add_order_transaction')) {
            return;
        }
        if (edd_get_order_transactions(['object_id' => $objectId, 'object_type' => 'order', 'transaction_id' => $transactionId, 'number' => 1])) {
            return;
        }
        edd_add_order_transaction([
            'object_id' => $objectId,
            'object_type' => 'order',
            'transaction_id' => $transactionId,
            'gateway' => self::transactionGateway(),
            'status' => $status,
            'total' => $total,
        ]);
    }

    /** Mark an order paid with its Razorpay payment id (stackless_edd_complete_order(), transaction written here). */
    public static function complete(int $orderId, string $transactionId = ''): void
    {
        $order = self::get($orderId);
        if ($order && $transactionId !== '') {
            self::addTransaction($orderId, $transactionId, (float) $order->total);
        }
        stackless_edd_complete_order($orderId);
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

    /** Paid, or past paid (partially refunded, refunded, revoked). */
    public static function isPaid(Order $order): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        return in_array((string) $order->status, $complete, true);
    }

    /**
     * Most recent sale order carrying this meta value.
     */
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
     * Signature for links to this gateway's own pages (the payment page and the return). The
     * payment key itself is not put in those URLs: it also unlocks the receipt and its downloads.
     */
    public static function returnKey(Order $order, string $purpose): string
    {
        return substr(hash_hmac('sha256', $purpose . '|' . $order->id . '|' . $order->payment_key, wp_salt('auth')), 0, 32);
    }

    /** Order number for display (EDD sequential numbers when enabled). */
    public static function number(Order $order): string
    {
        $number = (string) $order->get_number();
        return $number !== '' ? $number : (string) $order->id;
    }

    public static function customerName(Order $order): string
    {
        $address = $order->get_address();
        $field = static function ($key) use ($address): string {
            return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? (string) $address->{$key} : '';
        };
        $name = trim($field('first_name') . ' ' . $field('last_name'));
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        return $name;
    }

    /**
     * "₹1,234.50"-style plain text, for notes.
     *
     * @param float|string $amount
     */
    public static function money($amount, string $currency): string
    {
        return wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, strtoupper($currency)), ENT_QUOTES, 'UTF-8'));
    }
}
