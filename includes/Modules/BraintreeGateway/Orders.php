<?php

namespace StacklessEDD\Modules\BraintreeGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * The EDD side of the payment engine. Every call drops EDD's cached copy first, because another
 * request may have settled the order while this one waited for the lock.
 *
 * Status map from the WooCommerce edition: Woo pending/failed = EDD pending/failed/abandoned
 * (still payable), Woo on-hold while Braintree confirms = EDD processing, Woo on-hold for a
 * mismatch or dispute = EDD on_hold, Woo processing/completed = EDD complete.
 */
final class Orders
{
    private const PAYABLE = ['pending', 'failed', 'abandoned'];
    private const WAITING = ['processing', 'on_hold'];

    public static function get(int $order_id): ?Order
    {
        if ($order_id < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        wp_cache_delete($order_id, 'edd-orders');
        wp_cache_delete($order_id, 'edd_order_meta');
        $order = edd_get_order($order_id);
        return $order instanceof Order ? $order : null;
    }

    /**
     * @return array{id:int, number:string, gateway:string, status:string, total:string, currency:string, user:int, email:string, first:string, last:string, transaction:string, paid:bool, payable:bool, completable:bool}|null
     */
    public static function summary(int $order_id): ?array
    {
        $order = self::get($order_id);
        if (!$order || (string) $order->type !== 'sale') {
            return null;
        }
        $status = (string) $order->status;
        $paid = self::isPaid($order);
        $payable = !$paid && in_array($status, self::PAYABLE, true);
        $currency = strtoupper((string) $order->currency);
        [$first, $last] = self::names($order);
        return [
            'id' => (int) $order->id,
            'number' => self::number($order),
            'gateway' => (string) $order->gateway,
            'status' => $status,
            // What checkout and the receipt show: EDD can store more decimals than it displays.
            'total' => stackless_edd_decimal((float) $order->total, null, $currency),
            'currency' => $currency,
            'user' => (int) $order->user_id,
            'email' => (string) $order->email,
            'first' => $first,
            'last' => $last,
            'transaction' => (string) $order->get_transaction_id(),
            'paid' => $paid,
            'payable' => $payable,
            'completable' => !$paid && ($payable || in_array($status, self::WAITING, true)),
        ];
    }

    /** Paid at least once: refunds and a dispute hold included. */
    public static function isPaid(Order $order): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        if (in_array((string) $order->status, $complete, true)) {
            return true;
        }
        $completed = (string) ($order->date_completed ?? '');
        return $completed !== '' && strpos($completed, '0000-00-00') !== 0;
    }

    /** @return mixed '' when the key is not set */
    public static function meta(int $order_id, string $key)
    {
        wp_cache_delete($order_id, 'edd_order_meta');
        $value = edd_get_order_meta($order_id, $key, true);
        return ($value === false || $value === null) ? '' : $value;
    }

    /**
     * EDD answers false for an unchanged value too, so success is judged by reading back.
     *
     * @param array<string,mixed> $values null deletes a key
     */
    public static function setMeta(int $order_id, array $values): bool
    {
        if ($order_id < 1) {
            return false;
        }
        $saved = true;
        foreach ($values as $key => $value) {
            if ($value === null) {
                edd_delete_order_meta($order_id, $key);
                continue;
            }
            edd_update_order_meta($order_id, $key, wp_slash($value));
            $saved = self::meta($order_id, $key) == $value && $saved; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- meta comes back as strings.
        }
        return $saved;
    }

    public static function note(int $order_id, string $note): void
    {
        stackless_edd_add_order_note($order_id, $note);
    }

    private static function setStatus(int $order_id, string $status, string $note = ''): void
    {
        $order = self::get($order_id);
        if ($order && (string) $order->status !== $status) {
            edd_update_order_status($order_id, $status);
        }
        if ($note !== '') {
            self::note($order_id, $note);
        }
    }

    public static function complete(int $order_id, string $transaction): void
    {
        self::setMeta($order_id, [Meta::CONFIRMING => null]);
        stackless_edd_complete_order($order_id, $transaction);
    }

    /** Out of "pending" while Braintree confirms: a processing order cannot be resumed or recovered. */
    public static function awaitProvider(int $order_id, string $note): void
    {
        $order = self::get($order_id);
        if (!$order || self::isPaid($order) || !in_array((string) $order->status, self::PAYABLE, true)) {
            return;
        }
        self::setMeta($order_id, [Meta::CONFIRMING => 'yes']);
        self::setStatus($order_id, 'processing', $note);
    }

    public static function holdForReview(int $order_id, string $note): void
    {
        self::setMeta($order_id, [Meta::CONFIRMING => null]);
        self::setStatus($order_id, 'on_hold', $note);
    }

    /** Fail an order that was waiting on this charge. The decline meta is already written. */
    public static function fail(int $order_id, string $note): void
    {
        $order = self::get($order_id);
        if (!$order) {
            return;
        }
        $status = (string) $order->status;
        $confirming = (string) self::meta($order_id, Meta::CONFIRMING) !== '';
        if ($status === 'pending' || ($confirming && $status === 'processing')) {
            self::setMeta($order_id, [Meta::CONFIRMING => null]);
            self::setStatus($order_id, 'failed', $note);
        } else {
            self::note($order_id, $note);
        }
    }

    /** @return array<string,string> An AddressInput (empty values left out). */
    public static function billing(int $order_id): array
    {
        $order = self::get($order_id);
        if (!$order) {
            return [];
        }
        $address = $order->get_address();
        [$first, $last] = self::names($order);
        $postcode = self::field($address, 'postal_code');
        $country = strtoupper(self::field($address, 'country'));
        $fields = [
            'firstName' => $first,
            'lastName' => $last,
            'addressLine1' => self::field($address, 'address'),
            'addressLine2' => self::field($address, 'address2'),
            'adminArea2' => self::field($address, 'city'),
            'adminArea1' => self::field($address, 'region'),
            // Braintree takes nine letters or digits, plus spaces and hyphens.
            'postalCode' => preg_match('/^[A-Za-z0-9 -]+$/', $postcode) && strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $postcode)) <= 9 ? $postcode : '',
            'countryCode' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        ];
        $clean = [];
        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $clean[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 255) : substr($value, 0, 255);
            }
        }
        return $clean;
    }

    public static function requiresSave(int $order_id): bool
    {
        return stackless_edd_order_requires_saved_payment_method($order_id);
    }

    public static function isRenewal(int $order_id): bool
    {
        return stackless_edd_is_renewal_order($order_id);
    }

    public static function payUrl(int $order_id): string
    {
        return stackless_edd_order_pay_url($order_id);
    }

    public static function findByMeta(string $key, string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $orders = edd_get_orders([
            'number' => 1,
            'type' => 'sale',
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup by transaction id.
        ]);
        $order = $orders ? reset($orders) : null;
        return $order instanceof Order ? (int) $order->id : 0;
    }

    /** @return int[] Orders carrying a key, newest first (for Billing Health). */
    public static function withMeta(string $key, int $days): array
    {
        $orders = edd_get_orders([
            'number' => 20,
            'type' => 'sale',
            'date_created_query' => ['after' => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)],
            'meta_query' => [['key' => $key, 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin report over a bounded window.
        ]);
        $ids = [];
        foreach ((array) $orders as $order) {
            if ($order instanceof Order) {
                $ids[] = (int) $order->id;
            }
        }
        return $ids;
    }

    public static function refundable(int $order_id): float
    {
        $order = self::get($order_id);
        if (!$order) {
            return 0.0;
        }
        return function_exists('edd_get_order_total') ? (float) edd_get_order_total($order_id) : (float) $order->total;
    }

    /**
     * A refund made at Braintree, written to EDD without calling Braintree again.
     *
     * @return true|\WP_Error
     */
    public static function recordExternalRefund(int $order_id, float $amount, string $reason, string $remote_id)
    {
        $order = self::get($order_id);
        if (!$order) {
            return new \WP_Error('stackless_edd_braintree_refund', __('Order not found.', 'stackless-lite-edd'));
        }
        $created = Refunds::recordExternal($order, $amount);
        if (is_wp_error($created) || !$created) {
            return is_wp_error($created) ? $created : new \WP_Error('stackless_edd_braintree_refund', __('EDD refused the refund.', 'stackless-lite-edd'));
        }
        $refund = self::get((int) $created);
        if ($refund) {
            self::addTransaction($refund, $remote_id);
        }
        self::note($order_id, $reason);
        return true;
    }

    /** Record a Braintree id on a refund order once. */
    public static function addTransaction(Order $order, string $transaction_id): void
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
            // The column holds 20 characters; a longer id would drop the row.
            'gateway' => substr((string) $order->gateway, 0, 20),
            'status' => 'complete',
            'total' => $order->total,
        ]);
    }

    public static function number(Order $order): string
    {
        $number = (string) $order->get_number();
        return $number !== '' ? $number : (string) $order->id;
    }

    /** @return array{0:string, 1:string} first and last name of the buyer */
    private static function names(Order $order): array
    {
        $name = self::field($order->get_address(), 'name');
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    /** @param mixed $address */
    private static function field($address, string $key): string
    {
        return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? trim((string) $address->{$key}) : '';
    }
}
