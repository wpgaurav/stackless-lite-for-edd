<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Refunds both ways.
 *
 * Out: EDD's Refund dialog gets a "Refund the payment in Razorpay" box. Razorpay is called before
 * EDD records the refund (wp_ajax_edd_process_refund_form at priority 1), so a refused refund
 * leaves nothing behind in EDD. Refunds created in code (another module, the REST API) reach
 * Razorpay when the stackless_edd_refund_at_gateway filter says so.
 *
 * In: refunds made in the Razorpay dashboard arrive by webhook (or are found when a payment is
 * read back) and become EDD refunds, once per Razorpay refund id; refunds made here are
 * remembered by id, so their webhooks are not counted again.
 */
final class Refunds
{
    public const FIELD = 'stackless-edd-razorpay-refund';

    /** @var array<int,array{id:string,status:string,amount:float}> Razorpay refunds made by the preflight, by order id. */
    private static $preflight = [];

    /** @var bool True while a dashboard refund is being written to EDD (never send it back to Razorpay). */
    private static $syncing = false;

    public static function registerHooks(): void
    {
        add_action('edd_after_submit_refund_table', [self::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [self::class, 'preflight'], 1);
        add_action('edd_refund_order', [self::class, 'onRefund'], 10, 3);
    }

    /** Whether the order holds a Razorpay payment this store can refund (not a ₹1 authorization). */
    public static function canRefund(Order $order): bool
    {
        $orderId = (int) $order->id;
        return (string) $order->gateway === Gateway::ID
            && Orders::metaString($orderId, Gateway::META_PAYMENT_ID) !== ''
            && Orders::metaString($orderId, Gateway::META_VERIFICATION) !== 'yes';
    }

    /** @param mixed $order */
    public static function checkbox($order): void
    {
        if (!$order instanceof Order || !self::canRefund($order)) {
            return;
        }
        echo '<div class="edd-form-group stackless-razorpay-refund"><div class="edd-form-group__control">';
        echo '<input type="checkbox" id="' . esc_attr(self::FIELD) . '" name="' . esc_attr(self::FIELD) . '" class="edd-form-group__input" value="1" checked>';
        echo ' <label for="' . esc_attr(self::FIELD) . '" class="edd-form-group__label">' . esc_html__('Refund the payment in Razorpay', 'stackless-lite-edd') . '</label>';
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

    /** Before EDD records the refund: refund in Razorpay, and stop EDD when Razorpay refuses. */
    public static function preflight(): void
    {
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below through EDD's refund form nonce.
        $order = Orders::get(isset($_POST['order_id']) ? absint($_POST['order_id']) : 0);
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
        $amount = abs((float) $validator->total);
        if ($amount <= 0) {
            return;
        }
        $result = self::refundAtRazorpay($order, $amount, '', count(edd_get_order_refunds((int) $order->id)) + 1);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$preflight[(int) $order->id] = $result;
    }

    /**
     * After EDD recorded the refund: note the Razorpay result, or refund now for refunds made in code.
     *
     * @param mixed $orderId
     * @param mixed $refundId
     * @param mixed $allRefunded
     */
    public static function onRefund($orderId, $refundId, $allRefunded = false): void
    {
        $orderId = (int) $orderId;
        $order = Orders::get($orderId);
        if (!$order || (string) $order->gateway !== Gateway::ID || self::$syncing) {
            return;
        }
        $refund = Orders::get((int) $refundId);
        $amount = $refund ? abs((float) $refund->total) : 0.0;

        if (isset(self::$preflight[$orderId])) {
            $done = self::$preflight[$orderId];
            unset(self::$preflight[$orderId]);
            self::recordTransaction((int) $refundId, $done['id']);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; EDD verified the refund form.
        $fromModal = !empty($_POST['data']) && wp_doing_ajax();
        if ($fromModal) {
            Orders::note($orderId, __('Payment not refunded in Razorpay, as the box was not ticked.', 'stackless-lite-edd'));
            return;
        }
        /**
         * Whether a refund created in code (not from the admin dialog) is also sent to the gateway.
         *
         * @param bool $refund    Default false: EDD's refund records alone do not move money.
         * @param int  $order_id  Original order.
         * @param int  $refund_id EDD refund order.
         */
        if ($amount <= 0 || !self::canRefund($order) || !apply_filters('stackless_edd_refund_at_gateway', false, $orderId, (int) $refundId)) {
            return;
        }
        $result = self::refundAtRazorpay($order, $amount, '', (int) $refundId);
        if (is_wp_error($result)) {
            /* translators: %s: error message */
            Orders::note($orderId, sprintf(__('The Razorpay refund failed: %s', 'stackless-lite-edd'), $result->get_error_message()));
            return;
        }
        self::recordTransaction((int) $refundId, $result['id']);
    }

    /**
     * Refund an amount of the order's Razorpay payment.
     *
     * @return array{id:string,status:string,amount:float}|\WP_Error
     */
    public static function refundAtRazorpay(Order $order, float $amount, string $reason = '', int $sequence = 0)
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_razorpay_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $paymentId = Orders::metaString($orderId, Gateway::META_PAYMENT_ID);
        if ($paymentId === '' || Orders::metaString($orderId, Gateway::META_VERIFICATION) === 'yes') {
            return new \WP_Error('stackless_edd_razorpay_refund', __('This order has no Razorpay payment to refund.', 'stackless-lite-edd'));
        }
        $currency = strtoupper((string) $order->currency);
        $minor = Money::toMinor($amount, $currency);
        $refund = Settings::api(Gateway::orderMode($orderId))->request('POST', 'payments/' . rawurlencode($paymentId) . '/refund', [
            'amount' => $minor,
            'speed' => 'normal',
            'receipt' => substr('edd_' . $orderId . '_' . $sequence, 0, 40),
            'notes' => array_filter([
                'edd_order_id' => (string) $orderId,
                'edd_refund' => (string) $sequence,
                'reason' => substr(wp_strip_all_tags($reason), 0, 250),
            ]),
        ], ['X-Refund-Idempotency' => 'stackless-edd-refund-' . $orderId . '-' . $sequence . '-' . $minor]);
        if (is_wp_error($refund)) {
            Settings::log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        if (($refund['status'] ?? '') === 'failed' || empty($refund['id'])) {
            return new \WP_Error('stackless_edd_razorpay_refund', __('Razorpay could not process this refund. Check the payment in your Razorpay dashboard.', 'stackless-lite-edd'));
        }
        $refundId = (string) $refund['id'];
        $status = (string) ($refund['status'] ?? 'pending');
        Sync::rememberRefund($orderId, $refundId);
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Razorpay refund id, 3: status */
            __('Refunded %1$s through Razorpay (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, $currency),
            $refundId,
            $status
        ));
        return ['id' => $refundId, 'status' => $status, 'amount' => $amount];
    }

    private static function recordTransaction(int $refundId, string $transactionId): void
    {
        $refund = $refundId > 0 ? Orders::get($refundId) : null;
        Orders::addTransaction($refundId, $transactionId, $refund ? (float) $refund->total : 0.0);
    }

    /**
     * Record a refund that was made in the Razorpay dashboard (never sent back to Razorpay). A full
     * refund refunds every item; a partial one is spread over the items in order.
     *
     * @return int|\WP_Error EDD refund order id
     */
    public static function recordExternal(Order $order, float $amount, string $transactionId = '')
    {
        $orderId = (int) $order->id;
        $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($orderId) : (float) $order->total;
        $decimals = stackless_edd_price_decimals((string) $order->currency);
        self::$syncing = true;
        add_filter('edd_is_order_refundable_by_override', '__return_true');
        try {
            if ($amount >= $remaining - 0.5 / (10 ** max(0, $decimals))) {
                $result = edd_refund_order($orderId);
            } else {
                $items = [];
                foreach ($order->get_items() as $item) {
                    if ((string) $item->status === 'refunded' || !method_exists($item, 'get_refundable_amounts')) {
                        continue;
                    }
                    $amounts = (array) $item->get_refundable_amounts();
                    $items[] = [
                        'id' => (int) $item->id,
                        'quantity' => (int) ($amounts['quantity'] ?? $item->quantity),
                        'subtotal' => (float) ($amounts['subtotal'] ?? 0),
                        'tax' => (float) ($amounts['tax'] ?? 0),
                        'total' => (float) ($amounts['total'] ?? 0),
                    ];
                }
                $allocation = self::allocate($items, $amount, $decimals);
                if ($allocation === null) {
                    return new \WP_Error('stackless_edd_razorpay_refund', __('The refunded amount could not be matched to the order items.', 'stackless-lite-edd'));
                }
                $result = edd_refund_order($orderId, $allocation, []);
            }
        } finally {
            remove_filter('edd_is_order_refundable_by_override', '__return_true');
            self::$syncing = false;
        }
        if (is_wp_error($result)) {
            return $result;
        }
        if ((int) $result > 0) {
            self::recordTransaction((int) $result, $transactionId);
            /* translators: 1: amount, 2: Razorpay refund id */
            Orders::note($orderId, sprintf(__('Refund of %1$s made in Razorpay (%2$s) recorded.', 'stackless-lite-edd'), Orders::money($amount, (string) $order->currency), $transactionId));
        }
        return (int) $result;
    }

    /**
     * Spread a partial refund over order items, in order, never above an item's refundable total.
     * Tax is taken in proportion to each item's own tax share. An item refunded in full gives up
     * its units; a partly refunded one only the units the amount covers, so access does not end
     * for a small refund.
     *
     * @param array<int,array{id:int,quantity?:int,subtotal:float,tax:float,total:float}> $items refundable amounts
     * @return array<int,array{order_item_id:int,quantity:int,subtotal:string,tax:string,total:string}>|null null when the items cannot cover the amount
     */
    public static function allocate(array $items, float $amount, int $decimals = 2): ?array
    {
        $decimals = max(0, $decimals);
        $epsilon = 0.5 / (10 ** $decimals);
        $remaining = round($amount, $decimals);
        $result = [];
        foreach ($items as $item) {
            if ($remaining <= $epsilon) {
                break;
            }
            $total = round((float) $item['total'], $decimals);
            if ($total <= 0) {
                continue;
            }
            $portion = min($remaining, $total);
            $tax = $item['tax'] > 0 ? round((float) $item['tax'] * $portion / $total, $decimals) : 0.0;
            $subtotal = round($portion - $tax, $decimals);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $result[] = [
                'order_item_id' => (int) $item['id'],
                'quantity' => $portion >= $total ? $quantity : max(0, min($quantity, (int) floor($quantity * $portion / $total + 0.000001))),
                'subtotal' => number_format($subtotal, $decimals, '.', ''),
                'tax' => number_format($tax, $decimals, '.', ''),
                'total' => number_format($portion, $decimals, '.', ''),
            ];
            $remaining = round($remaining - $portion, $decimals);
        }
        return ($remaining > $epsilon || !$result) ? null : $result;
    }
}
