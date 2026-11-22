<?php

namespace StacklessEDD\Modules\RevolutGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Everything the payment flows read or write on an EDD order, by order id, so the flows
 * themselves are the same code in the WooCommerce and EDD editions.
 *
 * Status map from the WooCommerce edition: "pending/failed" = pending, failed, abandoned
 * (recoverable), "on-hold for a mismatch or a dispute" = on_hold, "processing/completed" = complete.
 */
final class Orders
{
    public static function get(int $orderId): ?Order
    {
        if ($orderId < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        $order = edd_get_order($orderId);
        // Refunds are orders of their own type and are never paid.
        return $order instanceof Order && (string) $order->type === 'sale' ? $order : null;
    }

    public static function exists(int $orderId): bool
    {
        return self::get($orderId) !== null;
    }

    /**
     * Forget cached copies: called once an order lock is held, since edd_get_order() and order
     * meta otherwise answer with what this request read before it waited.
     */
    public static function refresh(int $orderId): void
    {
        wp_cache_delete($orderId, 'edd-orders');
        wp_cache_delete($orderId, 'edd_order_meta');
    }

    public static function meta(int $orderId, string $key): string
    {
        $value = edd_get_order_meta($orderId, $key, true);
        return is_scalar($value) && $value !== false ? (string) $value : '';
    }

    /** @return array<int|string,mixed> */
    public static function metaList(int $orderId, string $key): array
    {
        $value = edd_get_order_meta($orderId, $key, true);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $values */
    public static function setMeta(int $orderId, array $values): void
    {
        foreach ($values as $key => $value) {
            edd_update_order_meta($orderId, $key, $value);
        }
    }

    public static function deleteMeta(int $orderId, string $key): void
    {
        edd_delete_order_meta($orderId, $key);
    }

    /* ---- Reading --------------------------------------------------------------- */

    public static function currency(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? strtoupper((string) $order->currency) : '';
    }

    public static function total(int $orderId): float
    {
        $order = self::get($orderId);
        return $order ? (float) $order->total : 0.0;
    }

    public static function user(int $orderId): int
    {
        $order = self::get($orderId);
        return $order ? (int) $order->user_id : 0;
    }

    public static function email(int $orderId): string
    {
        $order = self::get($orderId);
        return $order ? (string) $order->email : '';
    }

    public static function name(int $orderId): string
    {
        $order = self::get($orderId);
        if (!$order) {
            return '';
        }
        $address = $order->get_address();
        $name = trim(self::field($address, 'first_name') . ' ' . self::field($address, 'last_name'));
        if ($name === '' && (int) $order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        return $name;
    }

    public static function number(int $orderId): string
    {
        $order = self::get($orderId);
        $number = $order ? (string) $order->get_number() : '';
        return $number !== '' ? $number : (string) $orderId;
    }

    /** live|test: the mode the order was paid in, or is being paid in. */
    public static function mode(int $orderId): string
    {
        $mode = self::meta($orderId, Keys::MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : Settings::mode();
    }

    /** @return array<string,string> billingAddress for the checkout widget */
    public static function billing(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        $address = $order->get_address();
        return [
            'countryCode' => strtoupper(self::field($address, 'country')),
            'postcode' => self::field($address, 'postal_code'),
            'region' => self::field($address, 'region'),
            'city' => self::field($address, 'city'),
            'streetLine1' => self::field($address, 'address'),
            'streetLine2' => self::field($address, 'address2'),
        ];
    }

    /**
     * A field of an order address (EDD returns an Order_Address, or a plain object when none is stored).
     *
     * @param mixed $address
     */
    private static function field($address, string $key): string
    {
        return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? (string) $address->{$key} : '';
    }

    /** @return array<int,array{name:string, quantity:float, total:float, type:string}> */
    public static function lines(int $orderId): array
    {
        $order = self::get($orderId);
        if (!$order) {
            return [];
        }
        $lines = [];
        foreach ((array) $order->get_items() as $item) {
            if (is_object($item)) {
                $lines[] = [
                    'name' => (string) $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'total' => (float) $item->total,
                    // Downloads are never shipped.
                    'type' => 'service',
                ];
            }
        }
        return $lines;
    }

    /** @return array<string,mixed> Downloads are not shipped. */
    public static function shipping(int $orderId): array
    {
        return [];
    }

    /* ---- Status ---------------------------------------------------------------- */

    /** Paid at least once (refunds and a later hold for a dispute included). */
    public static function settled(int $orderId): bool
    {
        $order = self::get($orderId);
        if (!$order) {
            return false;
        }
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        $completed = isset($order->date_completed) ? (string) $order->date_completed : '';
        return in_array((string) $order->status, $complete, true) || ($completed !== '' && $completed !== '0000-00-00 00:00:00');
    }

    /** By status only: a free order that must save a card still needs the gateway. */
    public static function needsPayment(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && in_array((string) $order->status, ['pending', 'failed', 'abandoned'], true) && !self::settled($orderId);
    }

    public static function isPending(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && (string) $order->status === 'pending';
    }

    public static function underReview(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && in_array((string) $order->status, ['on_hold', 'processing'], true);
    }

    public static function isOurs(int $orderId): bool
    {
        $order = self::get($orderId);
        return $order && (string) $order->gateway === Keys::GATEWAY_ID;
    }

    /* ---- Writing --------------------------------------------------------------- */

    public static function note(int $orderId, string $note): void
    {
        stackless_edd_add_order_note($orderId, $note);
    }

    private static function setStatus(int $orderId, string $status, string $note): void
    {
        $order = self::get($orderId);
        if ($order && (string) $order->status !== $status) {
            edd_update_order_status($orderId, $status);
        }
        self::note($orderId, $note);
    }

    public static function hold(int $orderId, string $note): void
    {
        self::setStatus($orderId, 'on_hold', $note);
    }

    public static function fail(int $orderId, string $note): void
    {
        self::setStatus($orderId, 'failed', $note);
    }

    /** Complete the order, moving it to this gateway when the money arrived here. True once it is paid. */
    public static function complete(int $orderId, string $transactionId): bool
    {
        $order = self::get($orderId);
        if (!$order) {
            return false;
        }
        if ((string) $order->gateway !== Keys::GATEWAY_ID) {
            edd_update_order($orderId, ['gateway' => Keys::GATEWAY_ID]);
        }
        stackless_edd_complete_order($orderId, $transactionId);
        self::refresh($orderId);
        return self::settled($orderId);
    }

    public static function remainingRefund(int $orderId): float
    {
        $order = self::get($orderId);
        if (!$order) {
            return 0.0;
        }
        return function_exists('edd_get_order_total') ? (float) edd_get_order_total($orderId) : (float) $order->total;
    }

    /** A refund made in Revolut, recorded without calling Revolut again. */
    public static function recordRefund(int $orderId, float $amount, string $refundId, string $note): bool
    {
        $order = self::get($orderId);
        if (!$order) {
            return false;
        }
        try {
            Refunds::recordExternal($order, $amount, $refundId, $note);
        } catch (\Throwable $e) {
            Settings::log(sprintf('Could not record Revolut refund %s on order %d: %s', $refundId, $orderId, $e->getMessage()), 'error');
            return false;
        }
        return true;
    }

    public static function dispute(int $orderId, string $disputeId, string $note): void
    {
        $order = self::get($orderId);
        if (!$order) {
            return;
        }
        if (in_array((string) $order->status, ['complete', 'partially_refunded'], true)) {
            if (function_exists('edd_record_order_dispute')) {
                // EDD's own dispute record: On hold, so file access pauses.
                edd_record_order_dispute($orderId, $disputeId, 'dispute');
            } else {
                edd_update_order_status($orderId, 'on_hold');
            }
        }
        self::note($orderId, $note);
    }

    /* ---- Subscriptions --------------------------------------------------------- */

    /** @return mixed the first subscription of the order, or null */
    public static function subscriptionFor(int $orderId)
    {
        $subscriptions = stackless_edd_get_order_subscriptions($orderId);
        return $subscriptions ? reset($subscriptions) : null;
    }

    /** @param mixed $subscription */
    public static function subscriptionValue($subscription, string $key): string
    {
        $value = $subscription instanceof SubscriptionData ? $subscription->get_meta($key) : '';
        return is_scalar($value) && $value !== false ? (string) $value : '';
    }

    /* ---- Links ----------------------------------------------------------------- */

    /** EDD's receipt page, through the same filter edd_send_to_success_page() applies (custom thank-you pages). */
    public static function successUrl(int $orderId): string
    {
        return (string) apply_filters('edd_success_page_redirect', edd_get_success_page_uri(), Keys::GATEWAY_ID, null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter, applied the way EDD's gateways do.
    }

    /** Where a buyer finishes paying a pending order: EDD's order recovery link, else checkout. */
    public static function payUrl(int $orderId): string
    {
        $url = self::exists($orderId) ? stackless_edd_order_pay_url($orderId) : '';
        return $url !== '' ? $url : (string) edd_get_checkout_uri();
    }

    /* ---- Billing Health -------------------------------------------------------- */

    /** @return string[] order numbers of saved-card charges still unresolved */
    public static function stuckCharges(int $before): array
    {
        return self::numbers(Keys::CHARGE, ['status' => 'pending', 'gateway' => Keys::GATEWAY_ID, 'date_created_query' => ['before' => gmdate('Y-m-d H:i:s', $before)]]);
    }

    /** @return string[] order numbers of payments to review */
    public static function toReview(int $since): array
    {
        return self::numbers(Keys::REVIEW, ['number' => 50], $since);
    }

    /**
     * @param array<string,mixed> $args
     * @return string[]
     */
    private static function numbers(string $key, array $args, int $since = 0): array
    {
        if (!function_exists('edd_get_orders')) {
            return [];
        }
        $args = $args + ['type' => 'sale', 'number' => 20];
        $args['meta_query'] = [['key' => $key, 'value' => 'yes']]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Billing Health only, at most 50 rows.
        $numbers = [];
        foreach ((array) edd_get_orders($args) as $order) {
            if ($order instanceof Order && ($since === 0 || (int) self::meta((int) $order->id, Keys::REVIEW_AT) >= $since)) {
                $numbers[] = '#' . self::number((int) $order->id);
            }
        }
        return $numbers;
    }
}
