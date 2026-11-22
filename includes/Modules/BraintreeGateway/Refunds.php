<?php

namespace StacklessEDD\Modules\BraintreeGateway;

use StacklessEDD\Core\ExternalRefunds;

defined('ABSPATH') || exit;

/**
 * EDD's refund screens for orders paid with this gateway (Reversals does the work).
 *
 * The refund modal gets a "Refund the payment in Braintree" box. When it is ticked Braintree is
 * called BEFORE EDD records the refund (wp_ajax_edd_process_refund_form at priority 1), so a
 * refused refund stops EDD's instead of leaving a refund that never reached the customer.
 * Refunds created in code reach Braintree when stackless_edd_refund_at_gateway says so.
 */
final class Refunds
{
    public const FIELD = 'stackless-edd-braintree-refund';

    /** @var array<int,array{id:string, voided:bool}> Braintree results of the preflight, by order id. */
    private static $preflight = [];

    /** @var bool True while a Control Panel refund is being written to EDD (never sent back). */
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
        if (!$order instanceof \EDD\Orders\Order || (string) $order->gateway !== Gateway::ID || (string) Orders::meta((int) $order->id, Meta::TRANSACTION) === '') {
            return;
        }
        ?>
        <div class="edd-form-group stackless-edd-braintree-refund-transaction">
            <div class="edd-form-group__control">
                <input type="checkbox" id="<?php echo esc_attr(self::FIELD); ?>" name="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__input" value="1" checked>
                <label for="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__label"><?php esc_html_e('Refund the payment in Braintree (a payment that has not settled can only be refunded whole)', 'stackless-lite-edd'); ?></label>
            </div>
        </div>
        <?php
    }

    /** Calls Braintree before EDD records the refund, and stops the refund when it is refused. */
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
        if (!\StacklessEDD\Core\RefundForm::supported() || !\EDD\Orders\Refunds\FormParser::verify_nonce($form_data) || empty($form_data[self::FIELD])) {
            return;
        }
        // The checks edd_refund_order() makes: no money moves for a refund EDD would then refuse.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter.
        if (edd_is_order_refundable((int) $order->id) === false || apply_filters('edd_should_process_order_refund', true, (int) $order->id) !== true) {
            return;
        }
        try {
            $validator = new \EDD\Orders\Refunds\Validator($order, \EDD\Orders\Refunds\FormParser::parse_order_items($form_data), \EDD\Orders\Refunds\FormParser::parse_adjustments($form_data));
            $validator->validate_and_calculate_totals();
        } catch (\Exception $e) {
            return; // EDD's own handler reports the same validation error.
        }
        $amount = self::rounded(abs((float) $validator->total), (string) $order->currency);
        if ($amount <= 0) {
            return;
        }
        $result = (new Reversals())->refund((int) $order->id, $amount, self::nextSequence((int) $order->id));
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$preflight[(int) $order->id] = $result;
    }

    /**
     * Names the refund about to be recorded: a retry of a submit EDD did not record keeps the
     * name, and the next refund gets a new one.
     */
    private static function nextSequence(int $order_id): string
    {
        $last = 0;
        foreach ((array) edd_get_order_refunds($order_id) as $refund) {
            if ($refund instanceof \EDD\Orders\Order) {
                $last = max($last, (int) $refund->id);
            }
        }
        return 'after-' . $last;
    }

    private static function rounded(float $amount, string $currency): float
    {
        return (float) stackless_edd_decimal($amount, null, $currency);
    }

    /**
     * After EDD recorded the refund: note the Braintree result, or refund now for refunds made in code.
     *
     * @param mixed $order_id
     * @param mixed $refund_id
     * @param mixed $all_refunded
     */
    public static function onRefund($order_id, $refund_id, $all_refunded = false): void
    {
        $order_id = (int) $order_id;
        if (self::$syncing) {
            return;
        }
        $order = Orders::get($order_id);
        if (!$order || (string) $order->gateway !== Gateway::ID) {
            return;
        }
        $refund = Orders::get((int) $refund_id);

        if (isset(self::$preflight[$order_id])) {
            $done = self::$preflight[$order_id];
            unset(self::$preflight[$order_id]);
            if ($refund) {
                Orders::addTransaction($refund, $done['id']);
            }
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; EDD verified the refund form.
        $from_modal = !empty($_POST['data']) && wp_doing_ajax();
        if ($from_modal) {
            Orders::note($order_id, __('Payment not refunded in Braintree, as the box was not ticked.', 'stackless-lite-edd'));
            return;
        }
        $amount = $refund ? self::rounded(abs((float) $refund->total), (string) $order->currency) : 0.0;
        /**
         * Whether a refund created in code (not from the admin modal) is also sent to the gateway.
         *
         * @param bool $refund    Default false: EDD's refund records alone do not move money.
         * @param int  $order_id  Original order.
         * @param int  $refund_id EDD refund order.
         */
        if ($amount <= 0 || !apply_filters('stackless_edd_refund_at_gateway', false, $order_id, (int) $refund_id)) {
            return;
        }
        $result = (new Reversals())->refund($order_id, $amount, 'refund-' . (int) $refund_id);
        if (is_wp_error($result)) {
            /* translators: %s: error message */
            Orders::note($order_id, sprintf(__('The Braintree refund failed: %s', 'stackless-lite-edd'), $result->get_error_message()));
        } elseif ($refund) {
            Orders::addTransaction($refund, $result['id']);
        }
    }

    /**
     * Record a refund that was made in the Control Panel (never sent back to Braintree). A full
     * refund refunds every item; a partial one is spread over the items in order.
     *
     * @return int|\WP_Error EDD refund order id
     */
    public static function recordExternal(\EDD\Orders\Order $order, float $amount)
    {
        $order_id = (int) $order->id;
        $remaining = Orders::refundable($order_id);
        $decimals = Money::decimals((string) $order->currency);
        self::$syncing = true;
        add_filter('edd_is_order_refundable_by_override', '__return_true');
        try {
            if ($amount >= $remaining - 0.5 / (10 ** $decimals)) {
                return ExternalRefunds::record($order_id);
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
                return new \WP_Error('stackless_edd_braintree_refund', __('The refunded amount could not be matched to the order items.', 'stackless-lite-edd'));
            }
            return ExternalRefunds::record($order_id, $allocation, []);
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
