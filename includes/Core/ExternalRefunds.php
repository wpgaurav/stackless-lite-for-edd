<?php

namespace StacklessEDD\Core;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Refunds made at a provider and synced into EDD, and what any refund does to a hold.
 *
 * The store's edd_should_process_order_refund filter is respected: a refund it refuses is never
 * recorded and never changes the order. The money has left all the same, so each one is noted on
 * its order and stays in an admin notice until the merchant marks it handled.
 *
 * EDD makes an order partially refunded after any partial refund, which would deliver an order on
 * hold. An order of a Stackless gateway keeps the hold, whoever recorded the refund, and releasing
 * the hold later ends partially refunded. Other gateways' orders are left to EDD.
 */
final class ExternalRefunds
{
    public const ACTION = 'stackless_edd_unrecorded_refund_done';

    /** Order meta, one row per refund waiting for the merchant. */
    public const META_OPEN = '_stackless_edd_unrecorded_refund';

    /** Order meta, one row per refund ever left to the merchant, kept so a redelivery is noted once. */
    public const META_SEEN = '_stackless_edd_unrecorded_refund_seen';

    /** Code of the exception thrown when the note or alert could not be saved: the delivery must be retried. */
    public const NOT_SAVED = 57503;

    private const CAPABILITY = 'edit_shop_payments';
    private const GATEWAYS = 'stackless_edd_'; // What the id of every Stackless gateway starts with.
    private const SHOWN = 20;

    /** @var array<int,bool> Orders EDD is recording a refund of. */
    private static $refunding = [];

    /** @var array<int,bool> Orders whose hold was released in this request. */
    private static $released = [];

    public static function boot(): void
    {
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('edd_transition_order_status', [self::class, 'onTransition'], 1, 3);
        add_action('edd_payment_saved', [self::class, 'onSaved']);
        add_filter('edd_filter_order_item', [self::class, 'onOrderWrite'], PHP_INT_MAX);
        add_filter('edd_filter_order_item_item', [self::class, 'onItemWrite'], PHP_INT_MAX);
        add_action('edd_refund_order', [self::class, 'onRefunded'], PHP_INT_MIN);
    }

    /* ---- Keeping a hold ----------------------------------------------------------- */

    /*
     * edd_refund_order() has no hook between validating a refund and writing it, so the refund's
     * own row marks the start and the edd_refund_order action the end. In between, EDD's writes of
     * "partially refunded" to an order on hold are changed before they reach the database: no
     * status transition fires and no item becomes deliverable. The status is read at each write,
     * because a dispute may land while the refund is being recorded.
     */

    /**
     * @param mixed $row An order's row, about to be written.
     * @return mixed
     */
    public static function onOrderWrite($row)
    {
        if (!is_array($row)) {
            return $row;
        }
        $parent = (int) ($row['parent'] ?? 0);
        // A refund being inserted: EDD dates it when it has finished.
        if ($parent > 0 && ($row['type'] ?? '') === 'refund' && ($row['status'] ?? '') === 'complete' && empty($row['date_completed']) && strpos((string) ($row['gateway'] ?? ''), self::GATEWAYS) === 0) {
            self::$refunding[$parent] = true;
            // Before EDD counts what is left to refund. The store's filter already allowed this one.
            self::closeRefundedItems($parent, true);
            return $row;
        }
        if (!self::$refunding || ($row['status'] ?? '') !== 'partially_refunded') {
            return $row;
        }
        foreach (array_keys(self::$refunding) as $orderId) {
            $stored = self::stored($orderId);
            // The row has no id by now.
            if ($stored && $stored['status'] === 'on_hold' && $stored['uuid'] === (string) ($row['uuid'] ?? '') && $stored['payment_key'] === (string) ($row['payment_key'] ?? '')) {
                $row['status'] = 'on_hold';
                break;
            }
        }
        return $row;
    }

    /**
     * @param mixed $row An order item's row, about to be written.
     * @return mixed
     */
    public static function onItemWrite($row)
    {
        if (!is_array($row) || ($row['status'] ?? '') !== 'partially_refunded') {
            return $row;
        }
        $orderId = (int) ($row['order_id'] ?? 0);
        if (empty(self::$refunding[$orderId])) {
            return $row;
        }
        $stored = self::stored($orderId);
        if ($stored && $stored['status'] === 'on_hold') {
            $row['status'] = 'on_hold';
        }
        return $row;
    }

    /** @param mixed $orderId */
    public static function onRefunded($orderId): void
    {
        unset(self::$refunding[(int) $orderId]);
    }

    /* ---- Recording ---------------------------------------------------------------- */

    /**
     * edd_refund_order() for money the provider already returned. It keeps a hold whatever gateway
     * the order names by now: the payment is the caller's.
     *
     * @param array<int,array<string,mixed>>|string $items
     * @param array<int,array<string,mixed>>|string $adjustments
     * @return int|\WP_Error
     */
    public static function record(int $orderId, $items = 'all', $adjustments = 'all')
    {
        if ($items === 'all') {
            // EDD's Validator reads them, so before it runs.
            self::closeRefundedItems($orderId);
        }
        self::$refunding[$orderId] = true;
        try {
            return edd_refund_order($orderId, $items, $adjustments);
        } finally {
            unset(self::$refunding[$orderId]);
        }
    }

    /** The status to fall back on when a partial refund can only be noted. An order on hold keeps its hold. */
    public static function markPartlyRefunded(int $orderId): void
    {
        $order = edd_get_order($orderId);
        if ($order && (string) $order->status !== 'on_hold') {
            edd_update_order_status($orderId, 'partially_refunded');
        }
    }

    /** On hold after it was paid, for a dispute or a review: a payment event does not release it. */
    public static function heldAfterPayment(Order $order): bool
    {
        return (string) $order->status === 'on_hold' && self::wasPaid($order);
    }

    /** Paid at some point, whatever its status says now. */
    public static function wasPaid(Order $order): bool
    {
        $completed = isset($order->date_completed) ? (string) $order->date_completed : '';
        return $completed !== '' && strpos($completed, '0000-00-00') !== 0;
    }

    /** On hold and never paid: a payment that did not match, or one still to be confirmed. */
    public static function heldBeforePayment(Order $order): bool
    {
        return (string) $order->status === 'on_hold' && !self::heldAfterPayment($order);
    }

    /** On hold with a refund recorded. EDD would have called it partially refunded: nothing charges or fails it. */
    public static function heldWithRefund(Order $order): bool
    {
        return (string) $order->status === 'on_hold' && self::hasRefund((int) $order->id);
    }

    /* ---- Refused by the store ----------------------------------------------------- */

    /**
     * Whether a refund went unrecorded because the store's filter refuses it.
     *
     * @param mixed $result What recording returned, null when it was not attempted.
     */
    public static function refused(int $orderId, $result = null): bool
    {
        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'refund_not_allowed') {
                return true;
            }
        } elseif ($result) {
            return false;
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter.
        return apply_filters('edd_should_process_order_refund', true, $orderId) !== true;
    }

    /**
     * Note and alert a refund the store refused. Call with the order's lock held, before any fallback.
     *
     * @param string        $reference The provider's refund id.
     * @param mixed         $result    What recording returned, null when it was not attempted.
     * @param callable|null $forget    Undoes "this refund id is known" when nothing could be saved.
     * @param string        $currency  The refund's own, when it may differ from the order's.
     * @return bool True when it is the merchant's now: change nothing else and acknowledge it.
     * @throws \RuntimeException When the note or alert could not be saved, so the provider delivers again.
     */
    public static function leftToMerchant(string $provider, Order $order, float $amount, string $reference, $result = null, ?callable $forget = null, string $currency = ''): bool
    {
        $orderId = (int) $order->id;
        if (!self::refused($orderId, $result)) {
            return false;
        }
        if (self::report($provider, $order, $amount, $reference, $currency !== '' ? $currency : (string) $order->currency)) {
            return true;
        }
        if ($forget) {
            $forget();
        }
        throw new \RuntimeException(esc_html(sprintf('Refund %1$s of order %2$d could not be noted; retry later.', $reference, $orderId)), (int) self::NOT_SAVED);
    }

    /** @return bool Whether the refund is noted and alerted, now or before. */
    private static function report(string $provider, Order $order, float $amount, string $reference, string $currency): bool
    {
        global $wpdb;
        $orderId = (int) $order->id;
        $key = md5($orderId . '|' . $provider . '|' . ($reference !== '' ? $reference : number_format($amount, 4, '.', '')));
        $lock = 'stackless-edd-' . md5($wpdb->prefix . self::META_OPEN . $key);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock; two events may name one refund at once.
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ((string) $locked === '0') {
            return false;
        }
        try {
            wp_cache_delete($orderId, 'edd_order_meta');
            if (in_array($key, array_map('strval', (array) edd_get_order_meta($orderId, self::META_SEEN, false)), true)) {
                return true;
            }
            $row = self::open($orderId, $key);
            if (!$row) {
                $row = [
                    'key' => $key,
                    'provider' => $provider,
                    'amount' => wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, strtoupper($currency)), ENT_QUOTES, 'UTF-8')),
                    'reference' => sanitize_text_field($reference),
                ];
                if (!edd_add_order_meta($orderId, self::META_OPEN, wp_slash($row))) {
                    return false;
                }
            }
            if (edd_add_order_meta($orderId, self::META_SEEN, $key)) {
                // Not stackless_edd_add_order_note(): whether it was saved decides the retry.
                if (edd_add_note(['object_id' => $orderId, 'object_type' => 'order', 'content' => self::sentence($orderId, $row), 'user_id' => 0])) {
                    return true;
                }
                edd_delete_order_meta($orderId, self::META_SEEN, $key);
            }
            // All or nothing: the retry may find that the store allows the refund, and records it.
            edd_delete_order_meta($orderId, self::META_OPEN, wp_slash($row));
            return false;
        } finally {
            if ((string) $locked === '1') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the named lock.
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /** @return array<string,string> The waiting row, [] when there is none. */
    private static function open(int $orderId, string $key): array
    {
        foreach ((array) edd_get_order_meta($orderId, self::META_OPEN, false) as $row) {
            if (is_array($row) && ($row['key'] ?? '') === $key) {
                return $row;
            }
        }
        return [];
    }

    /** The merchant handled it by hand. */
    public static function dismiss(int $orderId, string $key): bool
    {
        if ($orderId < 1 || !current_user_can(self::CAPABILITY)) {
            return false;
        }
        $row = self::open($orderId, $key);
        // An empty value would delete every row of the order.
        if (!$row || !edd_delete_order_meta($orderId, self::META_OPEN, wp_slash($row))) {
            return false;
        }
        $name = (string) ($row['reference'] ?? '') !== '' ? (string) $row['reference'] : (string) ($row['amount'] ?? '');
        /* translators: %s: refund id, or its amount */
        stackless_edd_add_order_note($orderId, sprintf(__('Unrecorded refund %s was marked as handled.', 'stackless-lite-edd'), $name));
        return true;
    }

    public static function handle(): void
    {
        $key = isset($_GET['refund']) ? sanitize_key(wp_unslash($_GET['refund'])) : '';
        check_admin_referer(self::ACTION . $key);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite-edd'), '', ['response' => 403]);
        }
        self::dismiss(isset($_GET['order']) ? absint($_GET['order']) : 0, $key);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function notice(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the open rows of every order, by the indexed meta key.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT edd_order_id, meta_value FROM {$wpdb->edd_ordermeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d", self::META_OPEN, self::SHOWN), ARRAY_A);
        if (!$rows) {
            return;
        }
        $items = [];
        foreach ($rows as $found) {
            $orderId = (int) $found['edd_order_id'];
            $row = maybe_unserialize($found['meta_value']);
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $order = admin_url('edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $orderId);
            $done = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&order=' . $orderId . '&refund=' . rawurlencode($key)), self::ACTION . $key);
            $items[] = esc_html(self::sentence($orderId, $row))
                . ' <a href="' . esc_url($order) . '">' . esc_html__('View order', 'stackless-lite-edd') . '</a>'
                . ' | <a href="' . esc_url($done) . '">' . esc_html__('Mark as handled', 'stackless-lite-edd') . '</a>';
        }
        $total = count($rows);
        if ($total >= self::SHOWN) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- only when the list is cut.
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->edd_ordermeta} WHERE meta_key = %s", self::META_OPEN));
        }
        ExtraPayments::box(__('Refunds to record by hand', 'stackless-lite-edd'), $items, $total);
    }

    /** @param array<string,mixed> $row */
    private static function sentence(int $orderId, array $row): string
    {
        $provider = (string) ($row['provider'] ?? '');
        $amount = (string) ($row['amount'] ?? '');
        $reference = (string) ($row['reference'] ?? '');
        $text = $reference !== ''
            /* translators: 1: payment provider, 2: amount, 3: order id, 4: refund id */
            ? sprintf(__('%1$s refunded %2$s of order #%3$d (refund %4$s), but this store\'s refund rules stopped EDD from recording it.', 'stackless-lite-edd'), $provider, $amount, $orderId, $reference)
            /* translators: 1: payment provider, 2: amount, 3: order id */
            : sprintf(__('%1$s refunded %2$s of order #%3$d, but this store\'s refund rules stopped EDD from recording it.', 'stackless-lite-edd'), $provider, $amount, $orderId);
        /* translators: 1: payment provider */
        return $text . ' ' . sprintf(__('The money has already left. Record the refund by hand: in EDD once the store\'s refund rule allows it, otherwise in the store\'s own records. Do not send it through %1$s again: if you record it in EDD, leave the box that refunds in %1$s unticked.', 'stackless-lite-edd'), $provider);
    }

    /* ---- Releasing a hold --------------------------------------------------------- */

    /**
     * @param mixed $old
     * @param mixed $new
     * @param mixed $orderId
     */
    public static function onTransition($old, $new, $orderId): void
    {
        if ((string) $old === 'on_hold' && in_array((string) $new, ['complete', 'partially_refunded'], true)) {
            self::$released[(int) $orderId] = true;
        }
    }

    /**
     * A hold released (a dispute won) on an order with refunds: partially refunded. EDD gives every
     * item the order's new status as it saves, so items refunded in full are closed again. This
     * waits for that save; completing a purchase that was never completed has run by then.
     *
     * @param mixed $orderId
     */
    public static function onSaved($orderId): void
    {
        $orderId = (int) $orderId;
        if (empty(self::$released[$orderId])) {
            return;
        }
        $order = edd_get_order($orderId);
        if (!$order instanceof Order || strpos((string) $order->gateway, self::GATEWAYS) !== 0 || !in_array((string) $order->status, ['complete', 'partially_refunded'], true)) {
            return;
        }
        if (!self::hasRefund($orderId)) {
            return;
        }
        foreach ($order->get_items() as $item) {
            $status = self::refundStatus($item);
            if ($status !== '' && (string) $item->status !== $status) {
                edd_update_order_item((int) $item->id, ['status' => $status]);
            }
        }
        $status = (float) edd_get_order_total($orderId) > 0 ? 'partially_refunded' : 'refunded';
        if ((string) $order->status !== $status) {
            // Not edd_update_order_status(): it would give every item the order's status again.
            edd_update_order($orderId, ['status' => $status]);
        }
    }

    private static function hasRefund(int $orderId): bool
    {
        return (bool) edd_get_orders(['type' => 'refund', 'parent' => $orderId, 'status' => 'complete', 'number' => 1, 'fields' => 'ids']);
    }

    /**
     * Holding an order sets every item on hold, those refunded in full too. EDD then never counts
     * the order as refunded in full, and a full refund fails on their zero amounts when taxes are on.
     *
     * @param bool $allowed Whether the store's filter already allowed the refund being recorded.
     */
    private static function closeRefundedItems(int $orderId, bool $allowed = false): void
    {
        $order = edd_get_order($orderId);
        if (!$order || (string) $order->status !== 'on_hold') {
            return;
        }
        $close = [];
        foreach ($order->get_items() as $item) {
            if ((string) $item->status !== 'refunded' && self::refundStatus($item) === 'refunded') {
                $close[] = (int) $item->id;
            }
        }
        // A refund the store refuses changes nothing, these items included.
        if (!$close || (!$allowed && !stackless_edd_order_accepts_refund($orderId))) {
            return;
        }
        foreach ($close as $itemId) {
            edd_update_order_item($itemId, ['status' => 'refunded']);
        }
    }

    /**
     * refunded|partially_refunded by the item's refund records, '' when it has none.
     *
     * @param mixed $item
     */
    private static function refundStatus($item): string
    {
        if (!is_object($item) || !method_exists($item, 'get_refunded_items') || !$item->get_refunded_items()) {
            return '';
        }
        $left = (array) $item->get_refundable_amounts();
        return (float) ($left['total'] ?? 0) > 0 ? 'partially_refunded' : 'refunded';
    }

    /** @return array{status:string,uuid:string,payment_key:string}|null The order's row as stored now. */
    private static function stored(int $orderId): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the committed status, not a cached one.
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, uuid, payment_key FROM {$wpdb->edd_orders} WHERE id = %d", $orderId), ARRAY_A);
        return is_array($row) ? ['status' => (string) $row['status'], 'uuid' => (string) $row['uuid'], 'payment_key' => (string) $row['payment_key']] : null;
    }
}
