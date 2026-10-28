<?php

namespace StacklessEDD\Modules\PayPalGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Refunds both ways.
 *
 * Out: EDD's Refund dialog gets a "Refund transaction in PayPal" box. PayPal is called before
 * EDD writes its refund record, so a refused refund leaves nothing behind in EDD.
 *
 * In: refunds made in PayPal arrive by webhook and become EDD refund records (a full refund, or
 * a partial one spread across the order's items), once per PayPal refund id.
 */
final class Refunds
{
    public const FIELD = 'stackless-edd-paypal-refund';

    /** @var array<int,array{id:string,status:string}> PayPal refunds made earlier in this request, by order id. */
    private static $done = [];

    public static function register(): void
    {
        add_action('edd_after_submit_refund_table', [self::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [self::class, 'preflight'], 1);
        add_action('edd_refund_order', [self::class, 'afterRefund'], 10, 3);
    }

    /** @param mixed $order */
    public static function checkbox($order): void
    {
        if (!$order instanceof Order || (string) $order->gateway !== Gateway::ID) {
            return;
        }
        $orderId = (int) $order->id;
        if (Orders::meta($orderId, Gateway::META_CAPTURE_ID) === '' && (string) $order->get_transaction_id() === '') {
            return;
        }
        echo '<div class="edd-form-group stackless-paypal-refund"><div class="edd-form-group__control">';
        echo '<input type="checkbox" id="' . esc_attr(self::FIELD) . '" name="' . esc_attr(self::FIELD) . '" class="edd-form-group__input" value="1" checked>';
        echo ' <label for="' . esc_attr(self::FIELD) . '" class="edd-form-group__label">' . esc_html__('Refund transaction in PayPal', 'stackless-lite-edd') . '</label>';
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
        return $form;
    }

    /** Before EDD records the refund: refund in PayPal, and stop EDD when PayPal refuses. */
    public static function preflight(): void
    {
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below through EDD's refund form nonce.
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = Orders::get($orderId);
        if (!$order || (string) $order->gateway !== Gateway::ID) {
            return;
        }
        $form = self::formData();
        if (!$form || empty($form[self::FIELD]) || !class_exists(\EDD\Orders\Refunds\FormParser::class) || !\EDD\Orders\Refunds\FormParser::verify_nonce($form)) {
            return;
        }
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
        $result = Gateway::instance()->payments()->refund($order, $total);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$done[$orderId] = $result;
    }

    /**
     * After EDD recorded a refund (edd_refund_order). Refunds created from PayPal webhooks
     * carry no form data, so they never call PayPal back.
     *
     * @param mixed $orderId
     * @param mixed $refundId
     * @param mixed $allRefunded
     */
    public static function afterRefund($orderId, $refundId, $allRefunded = false): void
    {
        $orderId = (int) $orderId;
        $refundId = (int) $refundId;
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        $order = Orders::get($orderId);
        $form = self::formData();
        if (!$order || (string) $order->gateway !== Gateway::ID || !$form) {
            return;
        }
        if (empty($form[self::FIELD])) {
            Orders::note($orderId, __('Transaction not refunded in PayPal, as the box was not ticked.', 'stackless-lite-edd'));
            return;
        }

        $result = self::$done[$orderId] ?? null;
        unset(self::$done[$orderId]);
        if ($result === null) {
            $refund = Orders::get($refundId);
            $amount = $refund ? abs((float) $refund->total) : 0.0;
            if ($amount <= 0) {
                return;
            }
            $result = Gateway::instance()->payments()->refund($order, $amount);
            if (is_wp_error($result)) {
                /* translators: %s: error */
                Orders::note($orderId, sprintf(__('PayPal refund failed: %s', 'stackless-lite-edd'), $result->get_error_message()));
                return;
            }
        }
        self::recordTransaction($refundId, $result['id'], (string) $order->currency);
    }

    private static function recordTransaction(int $refundId, string $transactionId, string $currency): void
    {
        if ($refundId <= 0 || $transactionId === '' || !function_exists('edd_add_order_transaction') || edd_get_order_transaction_by('transaction_id', $transactionId)) {
            return;
        }
        $refund = Orders::get($refundId);
        edd_add_order_transaction([
            'object_id' => $refundId,
            'object_type' => 'order',
            'transaction_id' => $transactionId,
            'gateway' => Gateway::ID,
            'status' => 'complete',
            'total' => $refund ? (float) $refund->total : 0.0,
        ]);
    }

    /**
     * A refund made in PayPal: record it in EDD. A full refund refunds what is left; a partial one
     * is spread across the order's items in proportion. When EDD cannot express it (for example a
     * refund that only covers fees), the order is marked partially refunded with a note.
     *
     * @return int EDD refund id, 0 when only the status and a note were recorded.
     */
    public static function recordExternal(Order $order, float $amount, string $transactionId, string $note): int
    {
        $orderId = (int) $order->id;
        $currency = (string) $order->currency;
        $decimals = stackless_edd_price_decimals($currency);
        $remaining = (float) edd_get_order_total($orderId);
        $full = round($amount, $decimals) >= round($remaining, $decimals);

        $items = $full ? 'all' : self::partialItems($order, $amount, $decimals);
        if ($items === []) {
            Orders::note($orderId, $note);
            edd_update_order_status($orderId, 'partially_refunded');
            return 0;
        }

        add_filter('edd_is_order_refundable_by_override', '__return_true');
        $refundId = edd_refund_order($orderId, $items, $full ? 'all' : []);
        remove_filter('edd_is_order_refundable_by_override', '__return_true');

        if (is_wp_error($refundId) || !$refundId) {
            if ($full) {
                throw new \RuntimeException(esc_html(is_wp_error($refundId) ? $refundId->get_error_message() : 'EDD did not create the refund.'));
            }
            Orders::note($orderId, $note);
            edd_update_order_status($orderId, 'partially_refunded');
            return 0;
        }
        Orders::note($orderId, $note);
        self::recordTransaction((int) $refundId, $transactionId, $currency);
        return (int) $refundId;
    }

    /**
     * Spread a partial refund over the refundable order items, in proportion to what each can
     * still refund; tax is refunded in the item's own tax ratio.
     *
     * Empty when the items cannot cover the amount.
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
     * otherwise only the units the amount fully covers (none for a small partial refund), so
     * modules reading refunded quantities do not treat $1 off a $100 item as a full refund.
     */
    public static function refundedUnits(int $quantity, float $share, float $total, int $decimals): int
    {
        if ($total <= 0 || round($share, $decimals) >= round($total, $decimals)) {
            return max(1, $quantity);
        }
        return max(0, min($quantity, (int) floor($quantity * $share / $total + 0.000001)));
    }
}
