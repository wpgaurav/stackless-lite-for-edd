<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use EDD\Orders\Order;
use StacklessEDD\Core\ExternalRefunds;

defined('ABSPATH') || exit;

/**
 * Refunds in EDD's admin, around Refunds (which talks to Airwallex).
 *
 * Out: EDD's Refund dialog gets a "Refund the payment in Airwallex" box. Airwallex is called
 * before EDD writes its refund record (wp_ajax_edd_process_refund_form at priority 1), so a
 * refused refund leaves nothing behind in EDD. Refunds created in code reach Airwallex when the
 * stackless_edd_refund_at_gateway filter says so.
 *
 * In: refunds made in Airwallex become EDD refund records (a full refund, or a partial one
 * spread across the order's items) without calling Airwallex back.
 */
final class AdminRefunds
{
    public const FIELD = 'stackless-edd-airwallex-refund';

    /** @var array<int,array{id:string,status:string}> Refunds made by the preflight, by order id. */
    private static $preflight = [];

    /** @var bool True while an Airwallex dashboard refund is written to EDD (never sent back). */
    private static $syncing = false;

    public static function registerHooks(): void
    {
        add_action('edd_after_submit_refund_table', [self::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [self::class, 'preflight'], 1);
        add_action('edd_refund_order', [self::class, 'onRefund'], 10, 3);
    }

    /** Whether the order has an Airwallex payment a refund can go to. */
    public static function refundable(int $orderId): bool
    {
        $sale = Sale::get($orderId);
        return $sale && $sale->isOurs() && Refunds::refundable($sale);
    }

    /** @param mixed $order */
    public static function checkbox($order): void
    {
        if (!$order instanceof Order || !self::refundable((int) $order->id)) {
            return;
        }
        echo '<div class="edd-form-group stackless-airwallex-refund"><div class="edd-form-group__control">';
        echo '<input type="checkbox" id="' . esc_attr(self::FIELD) . '" name="' . esc_attr(self::FIELD) . '" class="edd-form-group__input" value="1" checked>';
        echo ' <label for="' . esc_attr(self::FIELD) . '" class="edd-form-group__label">' . esc_html__('Refund the payment in Airwallex', 'stackless-lite-edd') . '</label>';
        echo '</div></div>';
    }

    /** @return array<string,mixed> the submitted refund form, or [] */
    private static function formData(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the form nonce is checked by the callers; values are sanitized where used.
        $raw = isset($_POST['data']) && is_string($_POST['data']) ? wp_unslash($_POST['data']) : '';
        if ($raw === '') {
            return [];
        }
        $form = [];
        parse_str($raw, $form);
        return map_deep($form, 'sanitize_text_field');
    }

    /** Before EDD records the refund: refund in Airwallex, and stop EDD when Airwallex refuses. */
    public static function preflight(): void
    {
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below through EDD's refund form nonce.
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!self::refundable($orderId)) {
            return;
        }
        $form = self::formData();
        if (!$form || empty($form[self::FIELD]) || !\StacklessEDD\Core\RefundForm::supported() || !\EDD\Orders\Refunds\FormParser::verify_nonce($form)) {
            return;
        }
        // The checks edd_refund_order() makes: no money moves for a refund EDD would then refuse.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter.
        if (edd_is_order_refundable($orderId) === false || apply_filters('edd_should_process_order_refund', true, $orderId) !== true) {
            return;
        }
        $order = edd_get_order($orderId);
        try {
            $validator = new \EDD\Orders\Refunds\Validator($order, \EDD\Orders\Refunds\FormParser::parse_order_items($form), \EDD\Orders\Refunds\FormParser::parse_adjustments($form));
            $validator->validate_and_calculate_totals();
        } catch (\Throwable $e) {
            return; // EDD's own handler reports the same validation error.
        }
        $total = abs((float) $validator->total);
        if ($total <= 0) {
            return;
        }
        $result = (new Refunds())->create($orderId, $total, 'seq-' . (count((array) edd_get_order_refunds($orderId)) + 1));
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$preflight[$orderId] = $result;
    }

    /**
     * After EDD recorded a refund (edd_refund_order): note the Airwallex result, or refund now
     * for refunds made in code. Refunds written from Airwallex's own data never call it back.
     *
     * @param mixed $orderId
     * @param mixed $refundId
     * @param mixed $allRefunded
     */
    public static function onRefund($orderId, $refundId, $allRefunded = false): void
    {
        $orderId = (int) $orderId;
        $refundId = (int) $refundId;
        if (self::$syncing) {
            return;
        }
        $order = edd_get_order($orderId);
        if (!$order instanceof Order || (string) $order->gateway !== Settings::GATEWAY_ID) {
            return;
        }
        $refund = edd_get_order($refundId);
        $refund = $refund instanceof Order ? $refund : null;

        if (isset(self::$preflight[$orderId])) {
            $done = self::$preflight[$orderId];
            unset(self::$preflight[$orderId]);
            if ($refund) {
                self::recordTransaction($refund, $done['id']);
            }
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; EDD verified the refund form.
        $fromModal = !empty($_POST['data']) && wp_doing_ajax();
        if ($fromModal) {
            if (self::refundable($orderId)) {
                stackless_edd_add_order_note($orderId, __('Payment not refunded in Airwallex, as the box was not ticked.', 'stackless-lite-edd'));
            }
            return;
        }
        $amount = $refund ? abs((float) $refund->total) : 0.0;
        /** This filter is documented in includes/Modules/StripeGateway/Refunds.php */
        if ($amount <= 0 || !self::refundable($orderId) || !apply_filters('stackless_edd_refund_at_gateway', false, $orderId, $refundId)) {
            return;
        }
        $result = (new Refunds())->create($orderId, $amount, 'id-' . $refundId);
        if (is_wp_error($result)) {
            /* translators: %s: error message */
            stackless_edd_add_order_note($orderId, sprintf(__('The Airwallex refund failed: %s', 'stackless-lite-edd'), $result->get_error_message()));
        } elseif ($refund) {
            self::recordTransaction($refund, $result['id']);
        }
    }

    private static function recordTransaction(Order $refund, string $transactionId): void
    {
        if ($transactionId === '' || !function_exists('edd_add_order_transaction')) {
            return;
        }
        $existing = edd_get_order_transactions(['object_id' => (int) $refund->id, 'object_type' => 'order', 'transaction_id' => $transactionId, 'number' => 1]);
        if ($existing) {
            return;
        }
        edd_add_order_transaction([
            'object_id' => (int) $refund->id,
            'object_type' => 'order',
            'transaction_id' => $transactionId,
            // EDD keeps a transaction's gateway in 20 characters; this gateway's id is longer.
            'gateway' => substr(Settings::GATEWAY_ID, 0, 20),
            'status' => 'complete',
            'total' => (float) $refund->total,
        ]);
    }

    /**
     * A refund made in Airwallex: record it in EDD. A full refund refunds what is left; a partial
     * one is spread across the order's items in proportion. When EDD cannot express it, the order
     * is marked partially refunded with a note.
     *
     * @return int EDD refund id, 0 when it was only noted, or left to the merchant by the store's filter.
     */
    public static function recordExternal(Order $order, float $amount, string $transactionId, string $note): int
    {
        $orderId = (int) $order->id;
        $decimals = stackless_edd_price_decimals((string) $order->currency);
        $remaining = (float) edd_get_order_total($orderId);
        $full = round($amount, $decimals) >= round($remaining, $decimals);

        $items = $full ? 'all' : self::partialItems($order, $amount, $decimals);
        if ($items === []) {
            if (ExternalRefunds::leftToMerchant('Airwallex', $order, $amount, $transactionId)) {
                return 0;
            }
            self::noteOnly($order, $note);
            return 0;
        }

        self::$syncing = true;
        add_filter('edd_is_order_refundable_by_override', '__return_true');
        try {
            $refundId = ExternalRefunds::record($orderId, $items, $full ? 'all' : []);
        } finally {
            remove_filter('edd_is_order_refundable_by_override', '__return_true');
            self::$syncing = false;
        }

        if (is_wp_error($refundId) || !$refundId) {
            if (ExternalRefunds::leftToMerchant('Airwallex', $order, $amount, $transactionId, $refundId)) {
                return 0;
            }
            if ($full) {
                throw new \RuntimeException(esc_html(is_wp_error($refundId) ? $refundId->get_error_message() : 'EDD did not create the refund.'));
            }
            self::noteOnly($order, $note);
            return 0;
        }
        stackless_edd_add_order_note($orderId, $note);
        $refund = edd_get_order((int) $refundId);
        if ($refund instanceof Order) {
            self::recordTransaction($refund, $transactionId);
        }
        return (int) $refundId;
    }

    /** A partial refund EDD cannot express. An order on hold for a dispute stays on hold. */
    private static function noteOnly(Order $order, string $note): void
    {
        stackless_edd_add_order_note((int) $order->id, $note);
        if ((string) $order->status === 'complete') {
            edd_update_order_status((int) $order->id, 'partially_refunded');
        }
    }

    /**
     * Spread a partial refund over the refundable order items, in proportion to what each can
     * still refund; tax is refunded in the item's own tax ratio. Empty when the items cannot
     * cover the amount.
     *
     * @return array<int,array{order_item_id:int,quantity:int,subtotal:float,tax:float,total:float}>
     */
    public static function partialItems(Order $order, float $amount, int $decimals): array
    {
        $rows = [];
        $refundable = 0.0;
        foreach ((array) $order->get_items() as $item) {
            if (!is_object($item) || (string) $item->status === 'refunded' || !method_exists($item, 'get_refundable_amounts')) {
                continue;
            }
            $max = (array) $item->get_refundable_amounts();
            $total = (float) ($max['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $rows[] = ['id' => (int) $item->id, 'max' => $max, 'total' => $total];
            $refundable += $total;
        }
        return self::distribute($rows, $refundable, $amount, $decimals);
    }

    /**
     * @param array<int,array{id:int,max:array,total:float}> $rows
     * @return array<int,array{order_item_id:int,quantity:int,subtotal:float,tax:float,total:float}>
     */
    public static function distribute(array $rows, float $refundable, float $amount, int $decimals): array
    {
        if (!$rows || $amount <= 0 || round($amount, $decimals) > round($refundable, $decimals)) {
            return [];
        }
        $out = [];
        $left = round($amount, $decimals);
        $last = count($rows) - 1;
        foreach (array_values($rows) as $index => $row) {
            $share = $index === $last ? $left : round($amount * $row['total'] / $refundable, $decimals);
            $share = min($share, $row['total'], $left);
            $left = round($left - $share, $decimals);
            if ($share <= 0) {
                continue;
            }
            $tax = round($share * (float) ($row['max']['tax'] ?? 0) / $row['total'], $decimals);
            $subtotal = round($share - $tax, $decimals);
            $out[] = [
                'order_item_id' => $row['id'],
                'quantity' => self::refundedUnits(max(1, (int) ($row['max']['quantity'] ?? 1)), $share, (float) $row['total'], $decimals),
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => round($subtotal + $tax, $decimals),
            ];
        }
        return $left > 0 ? [] : $out;
    }

    /**
     * Units a refund takes from an item: all that are left when it refunds the rest of the item,
     * otherwise only the units the amount fully covers (none for a small partial refund).
     */
    public static function refundedUnits(int $quantity, float $share, float $total, int $decimals): int
    {
        if ($total <= 0 || round($share, $decimals) >= round($total, $decimals)) {
            return max(1, $quantity);
        }
        return max(0, min($quantity, (int) floor($quantity * $share / $total + 0.000001)));
    }
}
