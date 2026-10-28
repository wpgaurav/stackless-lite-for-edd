<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Refunds through Dodo Payments.
 *
 * A refund of the whole payment is sent as is; less than the whole payment refunds part
 * of the order's single Dodo product, in the order's own terms (Dodo adds the matching tax
 * when it was charged on top). Refunds made in the Dodo dashboard come back through
 * Sync::recordRefund().
 *
 * In the EDD admin, "Refund transaction in Dodo Payments" on the refund form sends the
 * refund to Dodo before EDD records it, so a refund Dodo refuses is never recorded.
 */
class Refunds
{
    /** Checkbox on EDD's refund form. */
    public const FIELD = 'stackless-edd-dodo-refund';

    /** True while an EDD refund is recorded for money Dodo already returned. */
    public static $mirroring = false;

    /** @var array<int,bool> orders refunded at Dodo earlier in this request (refund form pre-flight) */
    private static $sent = [];

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @return true|\WP_Error
     */
    public function refund(Order $order, ?float $amount, string $reason)
    {
        $orderId = (int) $order->id;
        $amount = $amount === null ? Orders::remainingRefund($order) : $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_dodo_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $paymentId = Orders::meta($orderId, Gateway::META_PAYMENT_ID);
        if ($paymentId === '') {
            return new \WP_Error('stackless_edd_dodo_refund', __('This order has no Dodo Payments payment to refund.', 'stackless-lite-edd'));
        }

        $currency = (string) $order->currency;
        $body = [
            'payment_id' => $paymentId,
            'metadata' => ['edd_order_id' => (string) $orderId],
        ];
        $reason = trim($reason);
        if ($reason !== '') {
            $body['reason'] = function_exists('mb_substr') ? mb_substr($reason, 0, 3000) : substr($reason, 0, 3000);
        }

        // Less than the whole payment: refund part of the single line, in the order's own
        // terms. Dodo adds the matching tax when it was charged on top.
        if (!Money::equals($amount, $order->total, $currency)) {
            $productId = Orders::meta($orderId, Gateway::META_PRODUCT_ID);
            if ($productId === '') {
                return new \WP_Error('stackless_edd_dodo_refund', __('Dodo Payments can only refund this order in full from EDD. Make the partial refund in the Dodo Payments dashboard.', 'stackless-lite-edd'));
            }
            $body['items'] = [[
                'item_id' => $productId,
                'amount' => Money::toMinor($amount, $currency),
                'tax_inclusive' => Orders::meta($orderId, Gateway::META_TAX_INCLUSIVE) === 'yes',
            ]];
        }

        $refund = $this->gateway->api($this->gateway->orderMode($orderId))->request('POST', 'refunds', $body);
        if (is_wp_error($refund)) {
            $this->gateway->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        if (($refund['status'] ?? '') === 'failed' || empty($refund['refund_id'])) {
            return new \WP_Error('stackless_edd_dodo_refund', __('Dodo Payments could not process this refund. Check the payment in your Dodo Payments dashboard.', 'stackless-lite-edd'));
        }
        Sync::recordRefundId($orderId, (string) $refund['refund_id']);
        stackless_edd_add_order_note($orderId, sprintf(
            /* translators: 1: amount, 2: Dodo refund id, 3: status */
            __('Refunded %1$s through Dodo Payments (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Money::label($amount, $currency),
            (string) $refund['refund_id'],
            (string) ($refund['status'] ?? 'pending')
        ));
        return true;
    }

    /* ---- EDD refund form ---------------------------------------------------------- */

    /** "Refund transaction in Dodo Payments" on EDD's refund form. */
    public static function checkbox($order): void
    {
        if (!$order instanceof Order || !Gateway::forOrder($order) || Orders::meta((int) $order->id, Gateway::META_PAYMENT_ID) === '') {
            return;
        }
        ?>
        <div class="edd-form-group stackless-edd-dodo-refund">
            <div class="edd-form-group__control">
                <input type="checkbox" id="<?php echo esc_attr(self::FIELD); ?>" name="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__input" value="1">
                <label for="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__label"><?php esc_html_e('Refund transaction in Dodo Payments', 'stackless-lite-edd'); ?></label>
            </div>
        </div>
        <?php
    }

    /** The refund form's fields, when this request is EDD's refund form submission. @return array<string,mixed>|null */
    private static function formData(): ?array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the callers through FormParser.
        if (!wp_doing_ajax() || empty($_POST['data']) || !is_string($_POST['data'])) {
            return null;
        }
        parse_str(wp_unslash($_POST['data']), $form); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, then sanitized below.
        // phpcs:enable
        return map_deep($form, 'sanitize_text_field');
    }

    /**
     * Before EDD records a refund from its refund form: send it to Dodo first and stop the form
     * with Dodo's message when Dodo refuses it.
     */
    public static function preflight(): void
    {
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the form nonce is checked below.
        $order = Orders::get(isset($_POST['order_id']) ? absint($_POST['order_id']) : 0);
        $gateway = $order ? Gateway::forOrder($order) : null;
        $form = self::formData();
        if (!$order || !$gateway || !$form || empty($form[self::FIELD])) {
            return;
        }
        if (!class_exists(\EDD\Orders\Refunds\FormParser::class) || !\EDD\Orders\Refunds\FormParser::verify_nonce($form)) {
            return; // EDD's own handler answers with the nonce error.
        }
        try {
            $validator = new \EDD\Orders\Refunds\Validator($order, \EDD\Orders\Refunds\FormParser::parse_order_items($form), \EDD\Orders\Refunds\FormParser::parse_adjustments($form));
            $validator->validate_and_calculate_totals();
        } catch (\Exception $e) {
            return; // EDD reports the same validation error.
        }
        $amount = abs((float) $validator->total);
        if ($amount <= 0) {
            return;
        }
        $result = (new self($gateway))->refund($order, $amount, '');
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 422);
        }
        self::$sent[(int) $order->id] = true;
    }

    /**
     * After EDD recorded a refund: send it to Dodo when asked and the pre-flight did not already.
     * Refunds recorded for money Dodo returned itself are never sent back.
     *
     * @param mixed $orderId
     * @param mixed $refundId
     */
    public static function onRefund($orderId, $refundId, $allRefunded = false): void
    {
        $orderId = absint($orderId);
        if (self::$mirroring || !empty(self::$sent[$orderId])) {
            return;
        }
        $order = Orders::get($orderId);
        $gateway = $order ? Gateway::forOrder($order) : null;
        if (!$order || !$gateway) {
            return;
        }
        $form = self::formData();
        $asked = $form !== null && !empty($form[self::FIELD]) && current_user_can('edit_shop_payments');
        /**
         * Whether a refund recorded in EDD outside the refund form is sent to Dodo Payments too.
         *
         * @param bool $asked    True when the refund form's "Refund transaction in Dodo Payments" box was ticked.
         * @param int  $orderId
         * @param int  $refundId
         */
        $asked = (bool) apply_filters('stackless_edd_dodo_gateway_refund_at_provider', $asked, $orderId, absint($refundId));
        if (!$asked) {
            if ($form !== null) {
                stackless_edd_add_order_note($orderId, __('Transaction not refunded in Dodo Payments, as the checkbox was not selected.', 'stackless-lite-edd'));
            }
            return;
        }
        $refund = Orders::get(absint($refundId));
        $amount = $refund ? abs((float) $refund->total) : 0.0;
        if ($amount <= 0) {
            return;
        }
        $result = (new self($gateway))->refund($order, $amount, '');
        if (is_wp_error($result)) {
            /* translators: 1: EDD refund id, 2: error message */
            stackless_edd_add_order_note($orderId, sprintf(__('Dodo Payments did not refund #%1$d: %2$s', 'stackless-lite-edd'), absint($refundId), $result->get_error_message()));
        }
    }
}
