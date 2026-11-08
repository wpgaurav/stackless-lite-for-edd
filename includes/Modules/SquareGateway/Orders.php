<?php

namespace StacklessEDD\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Thin EDD 3 order helpers used in place of the WC_Order methods the WooCommerce edition called:
 * meta, notes, statuses, lookups and the buyer's name and address.
 *
 * Status map from the WooCommerce edition: Woo pending/failed = EDD pending/failed/abandoned
 * (still payable), Woo on-hold for a mismatch = EDD on_hold, Woo on-hold while Square processes
 * = EDD processing, Woo processing/completed = EDD complete.
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
        if ($order && (string) $order->status !== $status) {
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

    /** The WC_Order::needs_payment() counterpart: unpaid and still payable. */
    public static function needsPayment(\EDD\Orders\Order $order): bool
    {
        return self::hasStatus($order, ['pending', 'failed', 'abandoned']);
    }

    /** Paid at least once (refunds included). */
    public static function isPaid(\EDD\Orders\Order $order): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        return in_array((string) $order->status, $complete, true);
    }

    /** Record a gateway transaction on the order once (set_transaction_id in WooCommerce). */
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

    /** The sale (not refund) order carrying $key = $value in its meta. */
    public static function findByMeta(string $key, string $value): ?\EDD\Orders\Order
    {
        if ($value === '') {
            return null;
        }
        $orders = edd_get_orders([
            'number' => 1,
            'type' => 'sale',
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup by the Square payment id per webhook.
        ]);
        $order = $orders ? reset($orders) : null;
        return $order instanceof \EDD\Orders\Order ? $order : null;
    }

    /** Order number for display (EDD sequential numbers when enabled). */
    public static function number(\EDD\Orders\Order $order): string
    {
        $number = (string) $order->get_number();
        return $number !== '' ? $number : (string) $order->id;
    }

    /** @return array{0:string, 1:string} first and last name of the buyer */
    public static function names(\EDD\Orders\Order $order): array
    {
        $name = self::field($order->get_address(), 'name');
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        if ($name === '' && (int) $order->user_id > 0) {
            $user = get_userdata((int) $order->user_id);
            if ($user) {
                return [(string) $user->first_name, (string) $user->last_name];
            }
        }
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    /**
     * The order's billing address as a Square Address (empty values left out).
     *
     * @return array<string,string>
     */
    public static function squareAddress(\EDD\Orders\Order $order): array
    {
        $address = $order->get_address();
        [$first, $last] = self::names($order);
        return array_filter([
            'address_line_1' => self::field($address, 'address'),
            'address_line_2' => self::field($address, 'address2'),
            'locality' => self::field($address, 'city'),
            'administrative_district_level_1' => self::field($address, 'region'),
            'postal_code' => self::field($address, 'postal_code'),
            'country' => strtoupper(self::field($address, 'country')),
            'first_name' => $first,
            'last_name' => $last,
        ], static function ($value) {
            return (string) $value !== '';
        });
    }

    /** @param mixed $address */
    private static function field($address, string $key): string
    {
        return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? trim((string) $address->{$key}) : '';
    }
}
