<?php

namespace StacklessEDD\Modules\MercadoPagoGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Applies Mercado Pago payments, refunds and chargebacks to EDD orders.
 *
 * Nothing is taken from a notification or a return address except which payment to read:
 * each payment is fetched from the API in the mode its order was started in, then applied
 * under a lock. Approved payments pay the order once, when the reference, currency, amount
 * and test/live mode all match; refunds are keyed by Mercado Pago refund id.
 */
class Sync
{
    public const META_CHARGEBACK = '_stackless_edd_mercadopago_chargeback';

    /** Statuses a cash or bank payment may hold (an unpaid order waiting for its buyer). */
    private const HOLDABLE = ['pending', 'failed', 'abandoned'];

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** @return array|\WP_Error */
    public function payment(string $paymentId, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'v1/payments/' . rawurlencode($paymentId));
    }

    /**
     * The order's most relevant payment: an approved one, else the newest. Empty when the
     * buyer has not paid anything yet.
     *
     * @return array|\WP_Error
     */
    public function latestPayment(Order $order, string $mode)
    {
        $found = $this->gateway->api($mode)->request('GET', 'v1/payments/search', [
            'external_reference' => Checkout::externalReference($order),
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 20,
        ]);
        if (is_wp_error($found)) {
            return $found;
        }
        $results = array_values(array_filter((array) ($found['results'] ?? []), 'is_array'));
        foreach ($results as $payment) {
            if (EventMap::paymentState((string) ($payment['status'] ?? '')) === 'paid') {
                return $payment;
            }
        }
        return $results[0] ?? [];
    }

    /** A payment is this order's when its external reference (or metadata) names the order on this site. */
    public function belongsTo(array $payment, Order $order): bool
    {
        $reference = (string) ($payment['external_reference'] ?? '');
        if ($reference !== '') {
            return $reference === Checkout::externalReference($order);
        }
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $site = (string) ($metadata['edd_site'] ?? '');
        return (string) ($metadata['edd_order_id'] ?? '') === (string) $order->id && ($site === '' || $site === self::siteHost());
    }

    /** The order a payment was made for, when it is one of this gateway's on this site. */
    public function orderFor(array $payment): ?Order
    {
        $orderId = EventMap::orderIdFrom((string) ($payment['external_reference'] ?? ''));
        if (!$orderId) {
            $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
            $orderId = absint($metadata['edd_order_id'] ?? 0);
        }
        $order = Orders::get($orderId);
        if (!$order || (string) $order->gateway !== $this->gateway->id() || !$this->belongsTo($payment, $order)) {
            return null;
        }
        return $order;
    }

    /** A payment notification: read the payment in the order's own mode and apply it. */
    public function confirmNotification(string $paymentId, string $notifiedMode): string
    {
        if ($paymentId === '' || !ctype_digit($paymentId)) {
            return 'no payment id';
        }
        $payment = $this->payment($paymentId, $notifiedMode);
        if (is_wp_error($payment)) {
            $other = $notifiedMode === 'live' ? 'test' : 'live';
            $retry = $this->gateway->accessToken($other) !== '' ? $this->payment($paymentId, $other) : $payment;
            if (is_wp_error($retry)) {
                // Thrown so the webhook answers 500 and Mercado Pago retries.
                throw new \RuntimeException(esc_html($payment->get_error_message()));
            }
            $payment = $retry;
            $notifiedMode = $other;
        }
        $order = $this->orderFor($payment);
        if (!$order) {
            return 'not ours';
        }
        $mode = $this->gateway->orderMode((int) $order->id);
        if ($mode !== $notifiedMode) {
            $payment = $this->payment($paymentId, $mode);
            if (is_wp_error($payment)) {
                throw new \RuntimeException(esc_html($payment->get_error_message()));
            }
        }
        return $this->apply($order, $payment, $mode);
    }

    /** Bring the order in line with a payment read from the API. */
    public function apply(Order $order, array $payment, string $mode): string
    {
        if (!$this->belongsTo($payment, $order)) {
            return 'not this order';
        }
        $state = EventMap::paymentState((string) ($payment['status'] ?? ''));
        switch ($state) {
            case 'paid':
                $outcome = $this->settle($order, $payment, $mode);
                $refunds = $this->reconcileRefunds($order, $payment);
                return $refunds !== '' ? $outcome . '; ' . $refunds : $outcome;
            case 'pending':
                return $this->awaiting($order, $payment);
            case 'refunded':
                $outcome = $this->reconcileRefunds($order, $payment);
                if ((string) ($payment['status'] ?? '') === 'charged_back') {
                    $outcome = trim($outcome . ' ' . $this->noteChargeback($order, $payment));
                }
                return $outcome !== '' ? $outcome : 'refunded';
            default:
                return $this->failed($order, $payment);
        }
    }

    /** Mark the order paid from an approved payment, once. */
    public function settle(Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $payment, $mode) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return 'gone';
            }
            $orderId = (int) $order->id;
            $paymentId = (string) ($payment['id'] ?? '');
            if (Orders::isPaid($order)) {
                if (Orders::meta($orderId, Gateway::META_PAYMENT_ID) === '' && $paymentId !== '') {
                    Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
                }
                return 'already paid';
            }
            if (EventMap::paymentState((string) ($payment['status'] ?? '')) !== 'paid') {
                return 'not paid';
            }

            $currency = strtoupper((string) $order->currency);
            Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            if (!empty($payment['payment_type_id'])) {
                Orders::setMeta($orderId, Gateway::META_PAYMENT_TYPE, sanitize_key((string) $payment['payment_type_id']));
            }
            Orders::deleteMeta($orderId, Gateway::META_AWAITING);
            // Checkout Pro test credentials look like production ones, so the payment's own
            // live_mode decides: a test payment never pays a live order.
            if ($mode === 'live' && isset($payment['live_mode']) && $payment['live_mode'] === false) {
                Orders::setStatus($orderId, 'on_hold', __('Mercado Pago approved a test payment for this live order. No real money was taken; do not deliver it.', 'stackless-lite-edd'));
                return 'test payment on live order';
            }
            if (!Money::paidMatches($payment, (float) $order->total, $currency)) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: amount Mercado Pago charged, 2: order total */
                    __('Mercado Pago charged %1$s but the order total is %2$s. Check the payment in Mercado Pago before delivering the order.', 'stackless-lite-edd'),
                    Orders::money((float) ($payment['transaction_amount'] ?? 0), strtoupper((string) ($payment['currency_id'] ?? $currency))),
                    Orders::money((float) $order->total, $currency)
                ));
                return strtoupper((string) ($payment['currency_id'] ?? '')) !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            Orders::complete($orderId, $paymentId, sprintf(
                /* translators: 1: Mercado Pago payment id, 2: payment method */
                __('Mercado Pago payment %1$s approved (%2$s).', 'stackless-lite-edd'),
                $paymentId,
                sanitize_text_field((string) ($payment['payment_method_id'] ?? ($payment['payment_type_id'] ?? __('unknown method', 'stackless-lite-edd'))))
            ));
            return 'paid';
        }, 'busy');
    }

    /** A cash, bank or in-review payment: hold the order until Mercado Pago decides. */
    private function awaiting(Order $order, array $payment): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $payment) {
            $order = Orders::get((int) $order->id);
            if (!$order || Orders::isPaid($order) || !in_array((string) $order->status, self::HOLDABLE, true)) {
                return 'no change';
            }
            $orderId = (int) $order->id;
            Orders::setMeta($orderId, Gateway::META_AWAITING, (string) ($payment['id'] ?? ''));
            Orders::setStatus($orderId, 'on_hold', sprintf(
                /* translators: 1: Mercado Pago payment id, 2: status detail */
                __('Mercado Pago payment %1$s is waiting to be completed (%2$s). The order is paid automatically once Mercado Pago approves it.', 'stackless-lite-edd'),
                (string) ($payment['id'] ?? ''),
                sanitize_text_field((string) ($payment['status_detail'] ?? ($payment['status'] ?? '')))
            ));
            return 'awaiting';
        }, 'busy');
    }

    /** A rejected or cancelled payment: an order held for it becomes failed so it can be paid again. */
    private function failed(Order $order, array $payment): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $payment) {
            $order = Orders::get((int) $order->id);
            if (!$order || Orders::isPaid($order)) {
                return 'no change';
            }
            $orderId = (int) $order->id;
            $paymentId = (string) ($payment['id'] ?? '');
            if ((string) $order->status === 'on_hold' && Orders::meta($orderId, Gateway::META_AWAITING) === $paymentId) {
                Orders::deleteMeta($orderId, Gateway::META_AWAITING);
                Orders::setStatus($orderId, 'failed', sprintf(
                    /* translators: 1: Mercado Pago payment id, 2: status detail */
                    __('Mercado Pago payment %1$s was not completed (%2$s).', 'stackless-lite-edd'),
                    $paymentId,
                    sanitize_text_field((string) ($payment['status_detail'] ?? ($payment['status'] ?? '')))
                ));
                return 'failed';
            }
            return 'rejected';
        }, 'busy');
    }

    /* ---- Refunds and chargebacks ----------------------------------------------------- */

    /** @return string[] */
    private static function refundIds(int $orderId): array
    {
        return array_values(array_filter(explode(',', Orders::meta($orderId, Gateway::META_REFUND_IDS))));
    }

    public static function recordRefundId(int $orderId, string $refundId): void
    {
        $ids = self::refundIds($orderId);
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::setMeta($orderId, Gateway::META_REFUND_IDS, implode(',', $ids));
        }
    }

    public static function hasRefundId(int $orderId, string $refundId): bool
    {
        return in_array($refundId, self::refundIds($orderId), true);
    }

    /** Mirror approved refunds on a paid order that EDD does not know about yet, once each. */
    public function reconcileRefunds(Order $order, array $payment): string
    {
        $refunds = array_values(array_filter((array) ($payment['refunds'] ?? []), 'is_array'));
        if (!$refunds) {
            return '';
        }
        $orderId = (int) $order->id;
        return (string) Lock::run('refund-' . $orderId, function () use ($orderId, $refunds, $payment) {
            $order = Orders::get($orderId);
            if (!$order || !Orders::isPaid($order)) {
                return '';
            }
            if (Orders::meta($orderId, Gateway::META_PAYMENT_ID) !== (string) ($payment['id'] ?? '')) {
                return '';
            }
            $currency = strtoupper((string) $order->currency);
            $recorded = 0;
            foreach ($refunds as $refund) {
                $refundId = (string) ($refund['id'] ?? '');
                if ($refundId === '' || self::hasRefundId($orderId, $refundId) || !in_array((string) ($refund['status'] ?? 'approved'), ['approved', 'authorized'], true)) {
                    continue;
                }
                $amount = min(Money::amount((float) ($refund['amount'] ?? 0), $currency), (float) edd_get_order_total($orderId));
                if ($amount <= 0) {
                    self::recordRefundId($orderId, $refundId);
                    continue;
                }
                // Throws when EDD refuses a full refund; nothing is recorded then, so Mercado Pago's retry tries again.
                Refunds::recordExternal($order, $amount, $refundId, sprintf(
                    /* translators: 1: amount, 2: Mercado Pago refund id */
                    __('Refunded %1$s in Mercado Pago (%2$s).', 'stackless-lite-edd'),
                    Orders::money($amount, $currency),
                    $refundId
                ));
                self::recordRefundId($orderId, $refundId);
                $recorded++;
                $order = Orders::get($orderId) ?: $order;
            }
            return $recorded ? 'refunded ' . $recorded : '';
        }, 'busy');
    }

    private function noteChargeback(Order $order, array $payment): string
    {
        $orderId = (int) $order->id;
        $paymentId = (string) ($payment['id'] ?? '');
        if (Orders::meta($orderId, self::META_CHARGEBACK) === $paymentId) {
            return '';
        }
        Orders::setMeta($orderId, self::META_CHARGEBACK, $paymentId);
        /* translators: %s: Mercado Pago payment id */
        Orders::note($orderId, sprintf(__('Mercado Pago reports a chargeback on payment %s. Review it in your Mercado Pago account.', 'stackless-lite-edd'), $paymentId));
        return 'chargeback noted';
    }
}
