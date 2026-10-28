<?php

namespace StacklessEDD\Modules\PaddleGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Refunds through Paddle adjustments, both ways.
 *
 * EDD refund amounts are in the order's own terms. When Paddle added tax on top,
 * the buyer paid more than the order total, so a refund is scaled by Paddle's
 * grand total over the order total and sent tax-inclusive. Partial refunds are
 * spread over the transaction's line items. Paddle may hold a refund for approval;
 * a later rejection is flagged on the order.
 *
 * In the EDD admin, "Refund transaction in Paddle" on the refund form sends the refund
 * to Paddle before EDD records it, so a refund Paddle refuses is never recorded.
 */
class Refunds
{
    /** Checkbox on EDD's refund form. */
    public const FIELD = 'stackless-edd-paddle-refund';

    /** True while an EDD refund is recorded for money Paddle already returned. */
    public static $mirroring = false;

    /** @var array<int,bool> orders refunded at Paddle earlier in this request (refund form pre-flight) */
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
        $transactionId = Orders::meta($orderId, Gateway::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return new \WP_Error('stackless_edd_paddle_refund', __('This payment has no Paddle transaction to refund.', 'stackless-lite-edd'));
        }
        $amount = $amount === null ? Orders::remainingRefund($order) : $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_paddle_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }

        $api = $this->gateway->api($this->gateway->orderMode($orderId));
        $transaction = $api->request('GET', 'transactions/' . rawurlencode($transactionId), ['include' => 'adjustments']);
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if (($transaction['status'] ?? '') !== 'completed') {
            return new \WP_Error('stackless_edd_paddle_refund', __('Paddle can refund this payment once it has finished processing. Try again in a few minutes.', 'stackless-lite-edd'));
        }

        $currency = strtoupper((string) $order->currency);
        $orderMinor = Money::toMinor($order->total, $currency);
        $paddleTotal = (int) ($transaction['details']['totals']['grand_total'] ?? $orderMinor);
        $storeMinor = Money::toMinor($amount, $currency);
        $refunded = self::refundedPerItem((array) ($transaction['adjustments'] ?? []));
        $lines = [];
        foreach ((array) ($transaction['details']['line_items'] ?? []) as $line) {
            if (!is_array($line) || empty($line['id'])) {
                continue;
            }
            $original = (int) ($line['totals']['total'] ?? 0);
            $lines[] = ['id' => (string) $line['id'], 'total' => max(0, $original - ($refunded[(string) $line['id']] ?? 0)), 'original' => $original];
        }

        $body = [
            'action' => 'refund',
            'transaction_id' => $transactionId,
            'reason' => EventMap::refundReason($reason),
        ];
        if (!$refunded && $storeMinor >= $orderMinor) {
            $body['type'] = 'full';
        } else {
            $items = self::split(self::paddleAmount($storeMinor, $orderMinor, $paddleTotal), $lines);
            if (!$items) {
                return new \WP_Error('stackless_edd_paddle_refund', __('This refund is more than Paddle has left to refund on the payment.', 'stackless-lite-edd'));
            }
            $body['type'] = 'partial';
            $body['tax_mode'] = 'internal';
            $body['items'] = $items;
        }

        // Held while the id is recorded, so an auto-approved refund's webhook can't mirror it a second time.
        $adjustment = Lock::run('refund-' . $orderId, static function () use ($api, $body, $orderId) {
            $created = $api->request('POST', 'adjustments', $body);
            if (!is_wp_error($created) && ($created['status'] ?? '') !== 'rejected') {
                self::recordId($orderId, (string) ($created['id'] ?? ''));
            }
            return $created;
        }, new \WP_Error('stackless_edd_paddle_refund', __('Another refund on this order is still being processed. Try again in a moment.', 'stackless-lite-edd')));
        if (is_wp_error($adjustment)) {
            $this->gateway->log('Refund on order ' . $orderId . ' failed: ' . $adjustment->get_error_message(), 'error');
            return $adjustment;
        }
        $status = (string) ($adjustment['status'] ?? '');
        if ($status === 'rejected') {
            return new \WP_Error('stackless_edd_paddle_refund', __('Paddle rejected this refund. Check the transaction in your Paddle dashboard.', 'stackless-lite-edd'));
        }
        stackless_edd_add_order_note($orderId, sprintf(
            /* translators: 1: amount, 2: Paddle adjustment id, 3: status */
            __('Refunded %1$s through Paddle (adjustment %2$s, %3$s).', 'stackless-lite-edd'),
            Money::label($amount, $currency),
            (string) ($adjustment['id'] ?? ''),
            $status === 'pending_approval' ? __('waiting for Paddle approval', 'stackless-lite-edd') : $status
        ));
        return true;
    }

    /** Amounts already refunded per transaction item. @return array<string,int> */
    public static function refundedPerItem(array $adjustments): array
    {
        $refunded = [];
        foreach ($adjustments as $adjustment) {
            if (!is_array($adjustment) || ($adjustment['action'] ?? '') !== 'refund' || in_array($adjustment['status'] ?? '', ['rejected', 'reversed'], true)) {
                continue;
            }
            foreach ((array) ($adjustment['items'] ?? []) as $item) {
                if (is_array($item) && !empty($item['item_id'])) {
                    $id = (string) $item['item_id'];
                    $refunded[$id] = ($refunded[$id] ?? 0) + (int) ($item['totals']['total'] ?? ($item['amount'] ?? 0));
                }
            }
        }
        return $refunded;
    }

    /** Store minor amount → what Paddle should refund (scaled by any tax Paddle added). */
    public static function paddleAmount(int $storeMinor, int $orderMinor, int $paddleTotal): int
    {
        if ($orderMinor <= 0 || $paddleTotal <= 0) {
            return $storeMinor;
        }
        return (int) min($paddleTotal, round($storeMinor * $paddleTotal / $orderMinor));
    }

    /** Paddle refund amount → store minor amount (the inverse of paddleAmount). */
    public static function storeAmount(int $paddleMinor, int $orderMinor, int $paddleTotal): int
    {
        if ($orderMinor <= 0 || $paddleTotal <= 0) {
            return $paddleMinor;
        }
        return (int) min($orderMinor, round($paddleMinor * $orderMinor / $paddleTotal));
    }

    /**
     * Spread a tax-inclusive refund over line items, first lines first.
     *
     * @param array<int,array{id:string,total:int,original?:int}> $lines Remaining refundable amount per item.
     * @return array<int,array{item_id:string,type:string,amount?:string}> Empty when the amount cannot be refunded.
     */
    public static function split(int $amount, array $lines): array
    {
        if ($amount <= 0) {
            return [];
        }
        $items = [];
        foreach ($lines as $line) {
            if ($amount <= 0) {
                break;
            }
            $available = (int) $line['total'];
            if ($available <= 0) {
                continue;
            }
            $take = min($amount, $available);
            $untouched = $available === (int) ($line['original'] ?? $available);
            $items[] = $take === $available && $untouched
                ? ['item_id' => (string) $line['id'], 'type' => 'full']
                : ['item_id' => (string) $line['id'], 'type' => 'partial', 'amount' => (string) $take];
            $amount -= $take;
        }
        return $amount > 0 ? [] : $items;
    }

    public static function recordId(int $orderId, string $id): void
    {
        if ($id === '') {
            return;
        }
        $ids = array_filter(explode(',', Orders::meta($orderId, Gateway::META_ADJUSTMENT_IDS)));
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            Orders::setMeta($orderId, Gateway::META_ADJUSTMENT_IDS, implode(',', $ids));
        }
    }

    public static function hasId(int $orderId, string $id): bool
    {
        return in_array($id, array_filter(explode(',', Orders::meta($orderId, Gateway::META_ADJUSTMENT_IDS))), true);
    }

    /**
     * Mirror a Paddle adjustment once: refunds made in the Paddle dashboard become
     * EDD refunds when approved; a rejected refund made here is flagged.
     */
    public function recordAdjustment(array $adjustment, string $mode): string
    {
        $action = (string) ($adjustment['action'] ?? '');
        $order = Sync::findOrderByMeta(Gateway::META_TRANSACTION_ID, (string) ($adjustment['transaction_id'] ?? ''));
        if (!$order || $order->gateway !== $this->gateway->id()) {
            return 'not ours';
        }
        $orderId = (int) $order->id;
        $id = (string) ($adjustment['id'] ?? '');
        $status = (string) ($adjustment['status'] ?? '');

        if (strpos($action, 'chargeback') === 0) {
            /* translators: 1: Paddle adjustment action, 2: adjustment id */
            stackless_edd_add_order_note($orderId, sprintf(__('Paddle reported a %1$s (adjustment %2$s). Review it in Paddle.', 'stackless-lite-edd'), str_replace('_', ' ', $action), $id));
            return 'chargeback noted';
        }
        if ($action !== 'refund') {
            return 'ignored';
        }

        return (string) Lock::run('refund-' . $orderId, function () use ($orderId, $adjustment, $id, $status) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'gone';
            }
            $known = self::hasId($orderId, $id);
            if ($status === 'rejected' || $status === 'reversed') {
                if ($known && Orders::meta($orderId, '_stackless_edd_paddle_flagged_' . $id) === '') {
                    Orders::setMeta($orderId, '_stackless_edd_paddle_flagged_' . $id, 'yes');
                    /* translators: 1: adjustment id, 2: status */
                    stackless_edd_add_order_note($orderId, sprintf(__('Paddle %2$s refund %1$s. The EDD refund recorded for it did not reach the customer; delete it or refund another way.', 'stackless-lite-edd'), $id, $status));
                    $this->gateway->log('Paddle ' . $status . ' refund ' . $id . ' on order ' . $orderId . '.', 'warning');
                    return 'flagged ' . $status;
                }
                return $status;
            }
            if ($known) {
                // The refund note said "waiting for Paddle approval"; close that loop once.
                if ($status === 'approved' && Orders::meta($orderId, '_stackless_edd_paddle_approved_' . $id) === '') {
                    Orders::setMeta($orderId, '_stackless_edd_paddle_approved_' . $id, 'yes');
                    /* translators: %s: Paddle adjustment id */
                    stackless_edd_add_order_note($orderId, sprintf(__('Paddle approved refund %s.', 'stackless-lite-edd'), $id));
                    return 'approved';
                }
                return 'duplicate';
            }
            if ($status !== 'approved') {
                return 'waiting for approval';
            }

            $currency = strtoupper((string) $order->currency);
            if (strtoupper((string) ($adjustment['currency_code'] ?? $currency)) !== $currency) {
                self::recordId($orderId, $id);
                stackless_edd_add_order_note($orderId, __('Paddle reported a refund in a different currency. Record it manually.', 'stackless-lite-edd'));
                return 'currency mismatch';
            }
            $orderMinor = Money::toMinor($order->total, $currency);
            $paddleTotal = Money::toMinor(Orders::meta($orderId, Gateway::META_TOTAL), $currency);
            $storeMinor = self::storeAmount((int) ($adjustment['totals']['total'] ?? 0), $orderMinor, $paddleTotal);
            $amount = min(Money::fromMinor($storeMinor, $currency), Orders::remainingRefund($order));
            if ($amount <= 0) {
                self::recordId($orderId, $id);
                return 'nothing left';
            }
            $created = Orders::recordRefund($order, $amount);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            self::recordId($orderId, $id);
            /* translators: 1: amount, 2: Paddle adjustment id */
            stackless_edd_add_order_note($orderId, sprintf(__('Refunded %1$s in Paddle (%2$s).', 'stackless-lite-edd'), Money::label($amount, $currency), $id));
            return 'refunded';
        }, 'busy');
    }

    /* ---- EDD refund form ---------------------------------------------------------- */

    /** "Refund transaction in Paddle" on EDD's refund form. */
    public static function checkbox($order): void
    {
        if (!$order instanceof Order || !Gateway::forOrder($order) || Orders::meta((int) $order->id, Gateway::META_TRANSACTION_ID) === '') {
            return;
        }
        ?>
        <div class="edd-form-group stackless-edd-paddle-refund">
            <div class="edd-form-group__control">
                <input type="checkbox" id="<?php echo esc_attr(self::FIELD); ?>" name="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__input" value="1">
                <label for="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__label"><?php esc_html_e('Refund transaction in Paddle', 'stackless-lite-edd'); ?></label>
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
     * Before EDD records a refund from its refund form: send it to Paddle first and stop the form
     * with Paddle's message when Paddle refuses it.
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
     * After EDD recorded a refund: send it to Paddle when asked and the pre-flight did not already.
     * Refunds recorded for money Paddle returned itself are never sent back.
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
         * Whether a refund recorded in EDD outside the refund form is sent to Paddle too.
         *
         * @param bool $asked    True when the refund form's "Refund transaction in Paddle" box was ticked.
         * @param int  $orderId
         * @param int  $refundId
         */
        $asked = (bool) apply_filters('stackless_edd_paddle_gateway_refund_at_provider', $asked, $orderId, absint($refundId));
        if (!$asked) {
            if ($form !== null) {
                stackless_edd_add_order_note($orderId, __('Transaction not refunded in Paddle, as the checkbox was not selected.', 'stackless-lite-edd'));
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
            stackless_edd_add_order_note($orderId, sprintf(__('Paddle did not refund #%1$d: %2$s', 'stackless-lite-edd'), absint($refundId), $result->get_error_message()));
        }
    }
}
