<?php

namespace StacklessEDD\Modules\PaystackGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Applies verified Paystack payments, refunds and disputes to EDD orders.
 *
 * Every entry point (browser return, webhook, a repeated checkout) verifies the transaction
 * with Paystack's API first and settles the order under a lock, only while it is unpaid
 * and only when the currency and amount match. Refunds are reconciled against Paystack's
 * refund list by refund id, so repeating any of this is harmless.
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

    public static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['edd_site'] ?? '');
        return $site === '' || $site === self::siteHost();
    }

    /** An order of this gateway with a meta value. */
    public function findOrder(string $key, string $value): ?Order
    {
        return Orders::findByMeta($key, $value, $this->gateway->id());
    }

    /** @return array|\WP_Error The verified transaction. */
    public function verify(string $reference, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'transaction/verify/' . rawurlencode($reference));
    }

    /**
     * Verify one of the order's transactions with Paystack and settle the order.
     *
     * @return true|\WP_Error stackless_edd_paystack_failed when the payment did not go through,
     *                        stackless_edd_paystack_pending while it is still open.
     */
    public function confirm(Order $order, string $reference)
    {
        $mode = $this->gateway->orderMode((int) $order->id);
        $transaction = $this->verify($reference, $mode);
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if (!$this->belongsTo($transaction, $order)) {
            return new \WP_Error('stackless_edd_paystack_mismatch', __('This Paystack transaction belongs to a different order.', 'stackless-lite-edd'));
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'failed') {
            return new \WP_Error('stackless_edd_paystack_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if ($state !== 'paid') {
            return new \WP_Error('stackless_edd_paystack_pending', __('Paystack has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite-edd'));
        }
        $outcome = $this->settle($order, $transaction, $mode);
        return in_array($outcome, ['paid', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_edd_paystack_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** A verified transaction is this order's when its metadata or its reference says so. */
    public function belongsTo(array $transaction, Order $order): bool
    {
        $orderId = (int) $order->id;
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        $claimed = (string) ($metadata['edd_order_id'] ?? '');
        if ($claimed !== '') {
            return $claimed === (string) $orderId && self::forThisSite($metadata);
        }
        $reference = (string) ($transaction['reference'] ?? '');
        return $reference !== '' && ($reference === Orders::meta($orderId, Gateway::META_REFERENCE) || Checkout::referenceFor($reference, $orderId));
    }

    /** The order a verified transaction was started for. */
    public function orderFor(array $transaction): ?Order
    {
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        if (!empty($metadata['edd_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            $order = Orders::get(absint($metadata['edd_order_id']));
            return $order && (string) $order->gateway === $this->gateway->id() ? $order : null;
        }
        return $this->findOrder(Gateway::META_REFERENCE, (string) ($transaction['reference'] ?? ''));
    }

    /** charge.success: verify the transaction again with the API, then settle its order. */
    public function confirmPayment(array $data, string $mode): string
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return 'no reference';
        }
        $transaction = $this->verify($reference, $mode);
        if (is_wp_error($transaction)) {
            // Thrown so the webhook answers 500 and Paystack retries.
            throw new \RuntimeException(esc_html($transaction->get_error_message()));
        }
        if (EventMap::transactionState((string) ($transaction['status'] ?? '')) !== 'paid') {
            return 'not paid';
        }
        $order = $this->orderFor($transaction);
        if (!$order) {
            return 'not ours';
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
            // A test-mode payment never settles a live order (or the other way round): anyone can
            // start a test transaction with the public test key and name a live order in it.
            $orderMode = Orders::meta($orderId, Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Paystack payment for ' . $orderMode . '-mode order ' . $orderId . '.', 'warning');
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
            Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, $transactionId);
            $reference = (string) ($transaction['reference'] ?? '');
            if ($reference !== '') {
                Orders::setMeta($orderId, Gateway::META_REFERENCE, $reference);
            }
            Orders::setMeta($orderId, Gateway::META_MODE, $mode);
            if (!empty($transaction['channel'])) {
                Orders::setMeta($orderId, Gateway::META_CHANNEL, sanitize_key((string) $transaction['channel']));
            }
            $expected = Money::toMinor((float) $order->total, $currency);
            if (!Money::paidMatches($transaction, $expected, $currency)) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: amount Paystack charged, 2: order total */
                    __('Paystack charged %1$s but the order total is %2$s. Check the transaction in Paystack before delivering the order.', 'stackless-lite-edd'),
                    Orders::money(Money::fromMinor((int) ($transaction['amount'] ?? 0)), $charged !== '' ? $charged : $currency),
                    Orders::money((float) $order->total, $currency)
                ));
                return $charged !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            Orders::complete($orderId, $transactionId, sprintf(
                /* translators: 1: Paystack reference, 2: payment channel */
                __('Paystack payment %1$s verified (%2$s).', 'stackless-lite-edd'),
                $reference,
                (string) ($transaction['channel'] ?? __('unknown channel', 'stackless-lite-edd'))
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
     * A refund event: Paystack's refund events carry no refund id, so read the transaction's
     * refunds from the API and mirror each processed one (made in the Paystack dashboard)
     * once; refunds made here are already in EDD. A failed refund made here is noted.
     */
    public function reconcileRefunds(array $data, string $mode): string
    {
        $order = $this->findOrder(Gateway::META_REFERENCE, (string) ($data['transaction_reference'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $orderId = (int) $order->id;
        $transactionId = Orders::meta($orderId, Gateway::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return 'no transaction';
        }
        $refunds = $this->gateway->api($this->gateway->orderMode($orderId))->request('GET', 'refund', ['transaction' => $transactionId, 'perPage' => 100]);
        if (is_wp_error($refunds)) {
            throw new \RuntimeException(esc_html($refunds->get_error_message()));
        }
        return (string) Lock::run('refund-' . $orderId, function () use ($orderId, $refunds) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'gone';
            }
            $done = [];
            foreach ($refunds as $refund) {
                if (is_array($refund)) {
                    $done[] = $this->applyRefund($order, $refund);
                    $order = Orders::get($orderId) ?: $order;
                }
            }
            $done = array_filter($done);
            return $done ? implode(', ', array_unique($done)) : 'nothing new';
        }, 'busy');
    }

    /** Mirror one refund from Paystack's list. Returns what happened, '' when nothing did. */
    public function applyRefund(Order $order, array $refund): string
    {
        $orderId = (int) $order->id;
        $refundId = (string) ($refund['id'] ?? '');
        if ($refundId === '') {
            return '';
        }
        $state = EventMap::refundState((string) ($refund['status'] ?? ''));
        if ($state === 'failed') {
            if (self::hasRefundId($orderId, $refundId) && !self::hasRefundId($orderId, $refundId, Gateway::META_REFUND_FAILED)) {
                self::recordRefundId($orderId, $refundId, Gateway::META_REFUND_FAILED);
                Orders::note($orderId, sprintf(
                    /* translators: %s: Paystack refund id */
                    __('Paystack could not complete refund %s. The EDD refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite-edd'),
                    $refundId
                ));
                return 'failed noted';
            }
            return '';
        }
        if ($state !== 'done' || self::hasRefundId($orderId, $refundId)) {
            return '';
        }
        $currency = strtoupper((string) $order->currency);
        if (strtoupper((string) ($refund['currency'] ?? $currency)) !== $currency) {
            self::recordRefundId($orderId, $refundId);
            Orders::note($orderId, __('Paystack reported a refund in a different currency. Record it manually.', 'stackless-lite-edd'));
            return 'currency mismatch';
        }
        $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0)), (float) edd_get_order_total($orderId));
        if ($amount <= 0) {
            self::recordRefundId($orderId, $refundId);
            return 'nothing left';
        }
        // Throws when EDD refuses a full refund; nothing is recorded then, so Paystack's retry tries again.
        Refunds::recordExternal($order, $amount, $refundId, sprintf(
            /* translators: 1: amount, 2: Paystack refund id */
            __('Refunded %1$s in Paystack (%2$s).', 'stackless-lite-edd'),
            Orders::money($amount, $currency),
            $refundId
        ));
        self::recordRefundId($orderId, $refundId);
        return 'refunded';
    }

    /* ---- Disputes ------------------------------------------------------------------ */

    public function noteDispute(string $event, array $dispute): string
    {
        $transaction = is_array($dispute['transaction'] ?? null) ? $dispute['transaction'] : [];
        $order = $this->findOrder(Gateway::META_REFERENCE, (string) ($transaction['reference'] ?? ''))
            ?: $this->findOrder(Gateway::META_TRANSACTION_ID, (string) ($transaction['id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        Orders::note((int) $order->id, sprintf(
            /* translators: 1: Paystack event, 2: dispute status */
            __('Paystack reported %1$s (status: %2$s). Respond to it in the Paystack dashboard.', 'stackless-lite-edd'),
            $event,
            (string) ($dispute['status'] ?? '')
        ));
        return 'noted';
    }
}
