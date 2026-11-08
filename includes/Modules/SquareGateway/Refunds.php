<?php

namespace StacklessEDD\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Refunds for orders paid with this gateway (WooCommerce's process_refund()).
 *
 * The admin refund modal (Orders → order → Refund) gets a "Refund the payment in Square" box. When
 * it is ticked, Square is called BEFORE EDD records the refund (wp_ajax_edd_process_refund_form at
 * priority 1, the pattern EDD's own PayPal gateway uses), so a refused refund stops the refund
 * instead of leaving an EDD refund that never reached the customer. Refunds created in code
 * (another module, the REST API) reach Square when the stackless_edd_refund_at_gateway filter
 * says so.
 *
 * Refunds made in the Square Dashboard come back through the refund.created webhook and are
 * recorded in EDD with recordExternal(); the refund ids this site made are remembered on the
 * order, so they are never counted twice.
 */
final class Refunds
{
    public const FIELD = 'stackless-edd-square-refund';

    /** @var array<int,array{id:string,status:string,amount:float}> Square refunds made by the preflight, by order id. */
    private static $preflight = [];

    /** @var bool True while a Dashboard refund is being written to EDD (never send it back to Square). */
    private static $syncing = false;

    public static function registerHooks(): void
    {
        add_action('edd_after_submit_refund_table', [self::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [self::class, 'preflight'], 1);
        add_action('edd_refund_order', [self::class, 'onRefund'], 10, 3);
    }

    /** @param mixed $order */
    public static function checkbox($order): void
    {
        if (!$order instanceof \EDD\Orders\Order || (string) $order->gateway !== Gateway::ID || (string) Orders::meta((int) $order->id, Gateway::META_PAYMENT_ID) === '') {
            return;
        }
        ?>
        <div class="edd-form-group stackless-edd-square-refund-transaction">
            <div class="edd-form-group__control">
                <input type="checkbox" id="<?php echo esc_attr(self::FIELD); ?>" name="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__input" value="1" checked>
                <label for="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__label"><?php esc_html_e('Refund the payment in Square', 'stackless-lite-edd'); ?></label>
            </div>
        </div>
        <?php
    }

    /** Calls Square before EDD records the refund, and stops the refund when Square refuses it. */
    public static function preflight(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the refund form's nonce is checked below with EDD's parser.
        if (!current_user_can('edit_shop_payments') || empty($_POST['data']) || empty($_POST['order_id'])) {
            return;
        }
        $order = Orders::get(absint($_POST['order_id']));
        if (!$order || (string) $order->gateway !== Gateway::ID) {
            return;
        }
        parse_str((string) wp_unslash($_POST['data']), $form_data); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, then sanitized on the next line.
        $form_data = map_deep($form_data, 'sanitize_text_field');
        // phpcs:enable
        if (!class_exists(\EDD\Orders\Refunds\FormParser::class) || !\EDD\Orders\Refunds\FormParser::verify_nonce($form_data) || empty($form_data[self::FIELD])) {
            return;
        }
        try {
            $validator = new \EDD\Orders\Refunds\Validator($order, \EDD\Orders\Refunds\FormParser::parse_order_items($form_data), \EDD\Orders\Refunds\FormParser::parse_adjustments($form_data));
            $validator->validate_and_calculate_totals();
        } catch (\Exception $e) {
            return; // EDD's own handler reports the same validation error.
        }
        $amount = abs((float) $validator->total);
        if ($amount <= 0) {
            return;
        }
        $result = self::refundAtSquare($order, $amount, '', count(edd_get_order_refunds((int) $order->id)) + 1);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$preflight[(int) $order->id] = $result;
    }

    /**
     * After EDD recorded the refund: note the Square result, or refund now for refunds made in code.
     *
     * @param mixed $order_id
     * @param mixed $refund_id
     * @param mixed $all_refunded
     */
    public static function onRefund($order_id, $refund_id, $all_refunded = false): void
    {
        $order_id = (int) $order_id;
        $order = Orders::get($order_id);
        if (!$order || (string) $order->gateway !== Gateway::ID || self::$syncing) {
            return;
        }
        $refund = Orders::get((int) $refund_id);
        $amount = $refund ? abs((float) $refund->total) : 0.0;

        if (isset(self::$preflight[$order_id])) {
            $done = self::$preflight[$order_id];
            unset(self::$preflight[$order_id]);
            if ($refund && $done['id'] !== '') {
                Orders::addTransaction($refund, $done['id'], 'complete');
            }
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; EDD verified the refund form.
        $from_modal = !empty($_POST['data']) && wp_doing_ajax();
        if ($from_modal) {
            Orders::note($order_id, __('Payment not refunded in Square, as the box was not ticked.', 'stackless-lite-edd'));
            return;
        }
        /**
         * Whether a refund created in code (not from the admin modal) is also sent to the gateway.
         *
         * @param bool $refund    Default false: EDD's refund records alone do not move money.
         * @param int  $order_id  Original order.
         * @param int  $refund_id EDD refund order.
         */
        if (!$amount || !apply_filters('stackless_edd_refund_at_gateway', false, $order_id, (int) $refund_id)) {
            return;
        }
        $result = self::refundAtSquare($order, $amount, '', (int) $refund_id);
        if (is_wp_error($result)) {
            /* translators: %s: error message */
            Orders::note($order_id, sprintf(__('The Square refund failed: %s', 'stackless-lite-edd'), $result->get_error_message()));
        } elseif ($refund && $result['id'] !== '') {
            Orders::addTransaction($refund, $result['id'], 'complete');
        }
    }

    /**
     * Refund an amount of the order's Square payment. Holds the order lock while it does, so the
     * refund webhook sees the refund id as this site's own.
     *
     * @return array{id:string,status:string,amount:float}|\WP_Error
     */
    public static function refundAtSquare(\EDD\Orders\Order $order, float $amount, string $reason = '', int $sequence = 0)
    {
        $order_id = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_square_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $payment_id = (string) Orders::meta($order_id, Gateway::META_PAYMENT_ID);
        if ($payment_id === '') {
            return new \WP_Error('stackless_edd_square_refund', __('This order has no Square payment to refund.', 'stackless-lite-edd'));
        }
        $gateway = Gateway::instance();
        $currency = strtoupper((string) $order->currency);
        $result = Lock::run('order-' . $order_id, static function () use ($gateway, $order, $order_id, $payment_id, $amount, $currency, $reason, $sequence) {
            $refund = $gateway->api($gateway->orderMode($order))->request('POST', 'refunds', array_filter([
                'idempotency_key' => Api::key('rf', $order_id . '-' . $sequence . '-' . Money::toMinor($amount, $currency)),
                'payment_id' => $payment_id,
                'amount_money' => Money::money($amount, $currency),
                'reason' => substr(wp_strip_all_tags($reason), 0, 192),
            ]));
            if (is_wp_error($refund)) {
                $gateway->log('Refund on order ' . $order_id . ' failed: ' . $refund->get_error_message(), 'error');
                return $refund;
            }
            $object = is_array($refund['refund'] ?? null) ? $refund['refund'] : [];
            $status = (string) ($object['status'] ?? '');
            if (in_array($status, ['REJECTED', 'FAILED'], true) || empty($object['id'])) {
                return new \WP_Error('stackless_edd_square_refund', __('Square could not process this refund. Check the payment in your Square Dashboard.', 'stackless-lite-edd'));
            }
            Payments::rememberRefund($order_id, (string) $object['id']);
            Orders::note($order_id, sprintf(
                /* translators: 1: amount, 2: Square refund id, 3: status */
                __('Refunded %1$s through Square (refund %2$s, %3$s).', 'stackless-lite-edd'),
                wp_strip_all_tags(stackless_edd_price($amount, $currency)),
                (string) $object['id'],
                strtolower($status)
            ));
            return ['id' => (string) $object['id'], 'status' => $status, 'amount' => $amount];
        }, null);
        if ($result === null) {
            return new \WP_Error('stackless_edd_square_refund', __('The order is being updated by another request. Try the refund again in a moment.', 'stackless-lite-edd'));
        }
        return $result;
    }

    /**
     * Record a refund that was made in the Square Dashboard (never sent back to Square). A full
     * refund refunds every item; a partial one is spread over the items in order.
     *
     * @return int|\WP_Error EDD refund order id
     */
    public static function recordExternal(\EDD\Orders\Order $order, float $amount)
    {
        $order_id = (int) $order->id;
        $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($order_id) : (float) $order->total;
        $decimals = Money::decimals((string) $order->currency);
        self::$syncing = true;
        add_filter('edd_is_order_refundable_by_override', '__return_true');
        try {
            if ($amount >= $remaining - 0.5 / (10 ** max(0, $decimals))) {
                return edd_refund_order($order_id);
            }
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
                return new \WP_Error('stackless_edd_square_refund', __('The refunded amount could not be matched to the order items.', 'stackless-lite-edd'));
            }
            return edd_refund_order($order_id, $allocation, []);
        } finally {
            remove_filter('edd_is_order_refundable_by_override', '__return_true');
            self::$syncing = false;
        }
    }

    /**
     * Spread a partial refund over order items, in order, never above an item's refundable total.
     * Tax is taken in proportion to each item's own tax share. An item refunded in full gives up
     * its units; a partly refunded one only the units the amount covers.
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
                'subtotal' => stackless_edd_decimal($subtotal, $decimals),
                'tax' => stackless_edd_decimal($tax, $decimals),
                'total' => stackless_edd_decimal($portion, $decimals),
            ];
            $remaining = round($remaining - $portion, $decimals);
        }
        return ($remaining > $epsilon || !$result) ? null : $result;
    }
}
