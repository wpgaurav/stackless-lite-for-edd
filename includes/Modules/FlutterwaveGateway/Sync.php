<?php

namespace StacklessEDD\Modules\FlutterwaveGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Applies verified Flutterwave payments and refunds to EDD orders.
 *
 * Every entry point verifies the transaction with Flutterwave's API first (as Flutterwave
 * asks: reference, status, currency and amount) and settles the order under a lock, only
 * while it is unpaid. Refunds are keyed by Flutterwave refund id. Repeating any of this is
 * harmless.
 */
class Sync
{
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

    /** An order of this gateway with a meta value. */
    public function findOrder(string $key, string $value): ?Order
    {
        return Orders::findByMeta($key, $value, $this->gateway->id());
    }

    /** The order a tx_ref was made for, when it is one of this gateway's. */
    public function orderForReference(string $txRef): ?Order
    {
        $order = Orders::get(Checkout::orderIdFrom($txRef));
        return $order && (string) $order->gateway === $this->gateway->id() ? $order : null;
    }

    /**
     * @param string $transactionId Flutterwave's id when known (faster and exact), else the tx_ref is looked up.
     * @return array|\WP_Error The verified transaction.
     */
    public function verify(string $txRef, string $transactionId, string $mode)
    {
        $api = $this->gateway->api($mode);
        if ($transactionId !== '' && ctype_digit($transactionId)) {
            return $api->request('GET', 'transactions/' . rawurlencode($transactionId) . '/verify');
        }
        return $api->request('GET', 'transactions/verify_by_reference', ['tx_ref' => $txRef]);
    }

    /**
     * Verify the order's payment with Flutterwave and settle the order.
     *
     * @return true|\WP_Error stackless_edd_flutterwave_failed when the payment did not go through,
     *                        stackless_edd_flutterwave_pending when nothing was paid yet,
     *                        stackless_edd_flutterwave_pending_payment while a payment is processing.
     */
    public function confirm(Order $order, string $txRef, string $transactionId = '')
    {
        $mode = $this->gateway->orderMode((int) $order->id);
        $transaction = $this->verify($txRef, $transactionId, $mode);
        if (is_wp_error($transaction)) {
            return Api::isNotFound($transaction)
                ? new \WP_Error('stackless_edd_flutterwave_pending', __('Flutterwave has no payment for this order yet.', 'stackless-lite-edd'))
                : $transaction;
        }
        if (!$this->belongsTo($transaction, $order)) {
            return new \WP_Error('stackless_edd_flutterwave_mismatch', __('This Flutterwave transaction belongs to a different order.', 'stackless-lite-edd'));
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'failed') {
            return new \WP_Error('stackless_edd_flutterwave_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if ($state !== 'paid') {
            return new \WP_Error('stackless_edd_flutterwave_pending_payment', __('Flutterwave has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite-edd'));
        }
        $outcome = $this->settle($order, $transaction, $mode);
        return in_array($outcome, ['paid', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_edd_flutterwave_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** A verified transaction is this order's when its tx_ref was made for it, on this site. */
    public function belongsTo(array $transaction, Order $order): bool
    {
        $orderId = (int) $order->id;
        $txRef = (string) ($transaction['tx_ref'] ?? '');
        if ($txRef === '') {
            return false;
        }
        if ($txRef === Orders::meta($orderId, Gateway::META_TX_REF) || Checkout::referenceFor($txRef, $orderId)) {
            $meta = is_array($transaction['meta'] ?? null) ? $transaction['meta'] : [];
            $site = (string) ($meta['edd_site'] ?? '');
            return $site === '' || $site === self::siteHost();
        }
        return false;
    }

    /** charge.completed: verify with the API (in the mode the order was started in), then settle. */
    public function confirmPayment(array $data, string $signedMode): string
    {
        $txRef = (string) ($data['tx_ref'] ?? '');
        $order = $this->orderForReference($txRef);
        if (!$order) {
            return 'not ours';
        }
        $orderId = (int) $order->id;
        $mode = Orders::meta($orderId, Gateway::META_MODE) !== '' ? $this->gateway->orderMode($orderId) : $signedMode;
        $transaction = $this->verify($txRef, (string) ($data['id'] ?? ''), $mode);
        if (is_wp_error($transaction)) {
            // Thrown so the webhook answers 500 and Flutterwave retries.
            throw new \RuntimeException(esc_html($transaction->get_error_message()));
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state !== 'paid') {
            if ($state === 'failed' && !Orders::isPaid($order) && $this->belongsTo($transaction, $order)) {
                Orders::note($orderId, sprintf(
                    /* translators: 1: Flutterwave reference, 2: processor response */
                    __('Flutterwave payment %1$s failed: %2$s', 'stackless-lite-edd'),
                    $txRef,
                    sanitize_text_field((string) ($transaction['processor_response'] ?? __('no reason given', 'stackless-lite-edd')))
                ));
                return 'failed noted';
            }
            return 'not paid';
        }
        return $this->settle($order, $transaction, $mode);
    }

    /** Mark the order paid from a verified, successful transaction, once. */
    public function settle(Order $order, array $transaction, string $mode): string
    {
        return (string) Lock::run('order-' . $order->id, function () use ($order, $transaction, $mode) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return 'gone';
            }
            $orderId = (int) $order->id;
            $transactionId = (string) ($transaction['id'] ?? '');
            // A test-mode payment never settles a live order (or the other way round).
            $orderMode = Orders::meta($orderId, Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Flutterwave payment for ' . $orderMode . '-mode order ' . $orderId . '.', 'warning');
                return 'other mode';
            }
            if (Orders::isPaid($order)) {
                if (Orders::meta($orderId, Gateway::META_TRANSACTION_ID) === '' && $transactionId !== '' && $this->belongsTo($transaction, $order)) {
                    Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
                }
                return 'already paid';
            }
            if (EventMap::transactionState((string) ($transaction['status'] ?? '')) !== 'paid' || !$this->belongsTo($transaction, $order)) {
                return 'not paid';
            }

            $currency = strtoupper((string) $order->currency);
            $charged = strtoupper((string) ($transaction['currency'] ?? ''));
            $txRef = (string) $transaction['tx_ref'];
            Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
            Orders::setMeta($orderId, Gateway::META_TX_REF, $txRef);
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            if (!empty($transaction['flw_ref'])) {
                Orders::setMeta($orderId, Gateway::META_FLW_REF, sanitize_text_field((string) $transaction['flw_ref']));
            }
            if (!empty($transaction['payment_type'])) {
                Orders::setMeta($orderId, Gateway::META_PAYMENT_TYPE, sanitize_key((string) $transaction['payment_type']));
            }
            if (!Money::paidMatches($transaction, (float) $order->total, $currency)) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: amount Flutterwave charged, 2: order total */
                    __('Flutterwave charged %1$s but the order total is %2$s. Check the transaction in Flutterwave before delivering the order.', 'stackless-lite-edd'),
                    Orders::money((float) ($transaction['amount'] ?? 0), $charged !== '' ? $charged : $currency),
                    Orders::money((float) $order->total, $currency)
                ));
                return $charged !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            Orders::complete($orderId, $transactionId, sprintf(
                /* translators: 1: Flutterwave reference, 2: payment type */
                __('Flutterwave payment %1$s verified (%2$s).', 'stackless-lite-edd'),
                $txRef,
                sanitize_text_field((string) ($transaction['payment_type'] ?? __('unknown method', 'stackless-lite-edd')))
            ));
            return 'paid';
        }, 'busy');
    }

    /* ---- Refunds ------------------------------------------------------------------ */

    /** @return string[] */
    private static function ids(int $orderId, string $key): array
    {
        return array_values(array_filter(explode(',', Orders::meta($orderId, $key))));
    }

    public static function recordRefundId(int $orderId, string $refundId, string $key = Gateway::META_REFUND_IDS): void
    {
        $ids = self::ids($orderId, $key);
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::setMeta($orderId, $key, implode(',', $ids));
        }
    }

    public static function hasRefundId(int $orderId, string $refundId, string $key = Gateway::META_REFUND_IDS): bool
    {
        return in_array($refundId, self::ids($orderId, $key), true);
    }

    /**
     * A refund webhook (v3 sends a flat refund object). Refunds made here are already in EDD;
     * one made in the Flutterwave dashboard is recorded once, after its status is read back
     * from the API and only when it belongs to the order's transaction.
     */
    public function recordRefund(array $data): string
    {
        $transactionId = (string) ($data['TransactionId'] ?? ($data['tx_id'] ?? ($data['transaction_id'] ?? '')));
        $order = $this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId);
        if (!$order) {
            return 'not ours';
        }
        $orderId = (int) $order->id;
        $refundId = (string) ($data['id'] ?? '');
        if ($refundId === '') {
            return 'no refund id';
        }
        $remote = $this->gateway->api($this->gateway->orderMode($orderId))->request('GET', 'refunds/' . rawurlencode($refundId));
        if (is_wp_error($remote)) {
            throw new \RuntimeException(esc_html($remote->get_error_message()));
        }
        $remoteTransaction = (string) ($remote['tx_id'] ?? ($remote['transaction_id'] ?? ''));
        if ($remoteTransaction !== '' && $remoteTransaction !== Orders::meta($orderId, Gateway::META_TRANSACTION_ID)) {
            return 'other transaction';
        }
        $status = (string) ($remote['status'] ?? ($data['status'] ?? ''));
        $amount = (float) ($remote['amount_refunded'] ?? ($data['AmountRefunded'] ?? ($data['amount_refunded'] ?? 0)));
        $state = EventMap::refundState($status);

        return (string) Lock::run('refund-' . $orderId, function () use ($orderId, $refundId, $amount, $state) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'gone';
            }
            if ($state === 'failed') {
                if (self::hasRefundId($orderId, $refundId) && !self::hasRefundId($orderId, $refundId, Gateway::META_REFUND_FAILED)) {
                    self::recordRefundId($orderId, $refundId, Gateway::META_REFUND_FAILED);
                    Orders::note($orderId, sprintf(
                        /* translators: %s: Flutterwave refund id */
                        __('Flutterwave could not complete refund %s. The EDD refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite-edd'),
                        $refundId
                    ));
                    return 'failed noted';
                }
                return 'failed';
            }
            if ($state !== 'done') {
                return 'not done';
            }
            if (self::hasRefundId($orderId, $refundId)) {
                return 'duplicate';
            }
            $currency = strtoupper((string) $order->currency);
            $amount = min($amount, (float) edd_get_order_total($orderId));
            if ($amount <= 0) {
                self::recordRefundId($orderId, $refundId);
                return 'nothing left';
            }
            // Throws when EDD refuses a full refund; nothing is recorded then, so Flutterwave's retry tries again.
            Refunds::recordExternal($order, $amount, $refundId, sprintf(
                /* translators: 1: amount, 2: Flutterwave refund id */
                __('Refunded %1$s in Flutterwave (%2$s).', 'stackless-lite-edd'),
                Orders::money($amount, $currency),
                $refundId
            ));
            self::recordRefundId($orderId, $refundId);
            return 'refunded';
        }, 'busy');
    }
}
