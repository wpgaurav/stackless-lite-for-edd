<?php

namespace StacklessEDD\Modules\MollieGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies a Mollie payment, as read from the Mollie API, to its EDD order: payment, saved
 * mandate, verification refund, failures (with decline details), refunds made in the Mollie
 * dashboard and chargebacks. The browser return, the webhook and renewals all come through
 * here, under one lock per order, so an order is settled once.
 */
final class Sync
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function site(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** @return array<string,string> Metadata sent with every payment for an order. */
    public static function metadata(Order $order, string $flow): array
    {
        return [
            'edd_order_id' => (string) $order->id,
            'edd_site' => self::site(),
            'edd_gateway' => Gateway::ID,
            'flow' => $flow,
        ];
    }

    public static function rememberRefund(int $orderId, string $refundId): void
    {
        if ($refundId === '') {
            return;
        }
        $ids = Orders::meta($orderId, Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::updateMeta($orderId, Gateway::META_REFUND_IDS, array_values($ids));
        }
    }

    /** @return string[] */
    public static function knownRefunds(int $orderId): array
    {
        $ids = Orders::meta($orderId, Gateway::META_REFUND_IDS);
        return is_array($ids) ? array_map('strval', $ids) : [];
    }

    /**
     * Read the order's payment from Mollie and apply it (browser return).
     *
     * @return string paid|pending|open|failed|canceled|expired|mismatch|busy|none
     */
    public function refreshOrder(Order $order): string
    {
        $orderId = (int) $order->id;
        $paymentId = Orders::metaString($orderId, Gateway::META_PAYMENT_ID);
        if ($paymentId === '') {
            return 'none';
        }
        $mode = $this->gateway->orderMode($orderId);
        $payment = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
        if (is_wp_error($payment)) {
            $this->gateway->log('Could not read payment ' . $paymentId . ': ' . $payment->get_error_message(), 'warning');
            return 'open';
        }
        return $this->applyToOrder($order, $payment, $mode);
    }

    /**
     * A webhook: the payment as Mollie returned it. Finds the order (or the account-page flow)
     * it belongs to and applies it.
     */
    public function applyPayment(array $payment, string $mode): string
    {
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        if (($metadata['edd_gateway'] ?? Gateway::ID) !== Gateway::ID || (($metadata['edd_site'] ?? '') !== '' && $metadata['edd_site'] !== self::site())) {
            return 'ignored: another site or gateway';
        }
        if (($metadata['flow'] ?? '') === 'add') {
            $token = $this->applyAddPayment($payment, $mode);
            return $token ? 'method saved (token ' . $token->id . ')' : 'add flow: ' . (string) ($payment['status'] ?? '');
        }
        $order = $this->findOrder($payment);
        if (!$order) {
            return 'ignored: no matching order';
        }
        return $this->applyToOrder($order, $payment, $mode);
    }

    private function findOrder(array $payment): ?Order
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $orderId = absint($payment['metadata']['edd_order_id'] ?? 0);
        $order = $orderId ? Orders::get($orderId) : null;
        if ($order && (string) $order->gateway === Gateway::ID) {
            return $order;
        }
        $order = Orders::findByMeta(Gateway::META_PAYMENT_ID, $paymentId);
        return $order && (string) $order->gateway === Gateway::ID ? $order : null;
    }

    /** Apply a payment to an order, under the order lock. */
    public function applyToOrder(Order $order, array $payment, string $mode): string
    {
        $orderId = (int) $order->id;
        $paymentId = (string) ($payment['id'] ?? '');
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $payment, $paymentId, $mode) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'none';
            }
            // Only the payment this order is waiting for may change it (an older, replaced one may not).
            $current = Orders::metaString($orderId, Gateway::META_PAYMENT_ID);
            if ($current !== '' && $current !== $paymentId) {
                return 'ignored: superseded payment';
            }
            $status = (string) ($payment['status'] ?? '');
            switch ($status) {
                case 'paid':
                    $outcome = $this->paid($order, $payment, $mode);
                    $order = Orders::get($orderId);
                    if ($order) {
                        $this->syncRefunds($order, $payment, $mode);
                    }
                    return $outcome;
                case 'authorized':
                    // Pay-later methods (Klarna, Billie, Riverty): the money is reserved, not taken.
                    if (Orders::needsPayment($order)) {
                        /* translators: %s: Mollie payment id */
                        Orders::setStatus($orderId, 'on_hold', sprintf(__('Mollie authorized payment %s. Capture it in Mollie to collect the money.', 'stackless-lite-edd'), $paymentId));
                    }
                    return 'pending';
                case 'pending':
                    if (Orders::needsPayment($order) && !self::isOffSession($orderId, $payment)) {
                        /* translators: %s: Mollie payment id */
                        Orders::setStatus($orderId, 'processing', sprintf(__('Mollie is processing payment %s. The order updates when Mollie confirms it.', 'stackless-lite-edd'), $paymentId));
                    }
                    return 'pending';
                case 'failed':
                case 'canceled':
                case 'expired':
                    return $this->unpaid($order, $payment, $status);
                case 'open':
                default:
                    return 'open';
            }
        }, 'busy');
    }

    /** Whether a payment was charged without the buyer present (renewal or saved-method charge). */
    private static function isOffSession(int $orderId, array $payment): bool
    {
        return (string) ($payment['sequenceType'] ?? '') === 'recurring' || stackless_edd_is_renewal_order($orderId);
    }

    private function paid(Order $order, array $payment, string $mode): string
    {
        $orderId = (int) $order->id;
        $paymentId = (string) $payment['id'];
        $currency = strtoupper((string) $order->currency);
        // Already applied (a replay, or the order was put on hold later, e.g. for a chargeback).
        if (Orders::isPaid($order) || ($paymentId !== '' && (string) $order->get_transaction_id() === $paymentId)) {
            return 'paid';
        }
        $verification = Orders::metaString($orderId, Gateway::META_VERIFICATION) === 'yes';
        $amountOk = $verification
            ? Money::fromAmount($payment['amount'] ?? null) <= (float) Money::smallest($currency) + 0.0001
            : Money::matches($payment['amount'] ?? null, (float) $order->total, $currency);
        if (!$amountOk) {
            if (!Orders::hasStatus($order, 'on_hold')) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: 1: Mollie payment id, 2: amount */
                    __('Mollie payment %1$s was for %2$s, which does not match the order total. Check the payment before delivering the order.', 'stackless-lite-edd'),
                    $paymentId,
                    Orders::money(Money::fromAmount($payment['amount'] ?? null), (string) ($payment['amount']['currency'] ?? $currency))
                ));
            }
            return 'mismatch';
        }

        if ((string) ($payment['sequenceType'] ?? '') === 'first') {
            $token = $this->saveMandate($payment, (int) $order->user_id, $mode, (int) $order->customer_id);
            if ($token) {
                // Before the order completes: Subscriptions copies these at that moment.
                Orders::updateMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $token->id);
                Orders::updateMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $token->mollie_customer);
                /* translators: %s: saved method, e.g. "Visa ending in 4242" */
                Orders::note($orderId, sprintf(__('Saved %s for future payments.', 'stackless-lite-edd'), $token->displayName()));
            } else {
                Orders::note($orderId, __('Mollie did not return a usable mandate for this payment, so renewals will be invoiced.', 'stackless-lite-edd'));
            }
        }
        Orders::updateMeta($orderId, Gateway::META_METHOD, (string) ($payment['method'] ?? ''));
        if ($verification && Money::fromAmount($payment['amount'] ?? null) > 0) {
            $this->refundVerification($orderId, $payment, $mode);
        }
        stackless_edd_complete_order($orderId, $paymentId);
        return 'paid';
    }

    /** Refund the one-cent payment that only verified a bank method. */
    private function refundVerification(int $orderId, array $payment, string $mode): void
    {
        $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', [
            'amount' => $payment['amount'],
            'description' => __('Verification refund', 'stackless-lite-edd'),
            'metadata' => ['edd_order_id' => (string) $orderId, 'verification' => '1'],
        ], 'stackless-edd-verify-refund-' . (string) $payment['id']);
        if (is_wp_error($refund)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('The verification payment could not be refunded automatically: %s Refund it in Mollie.', 'stackless-lite-edd'), $refund->get_error_message()));
            return;
        }
        self::rememberRefund($orderId, (string) ($refund['id'] ?? ''));
        Orders::note($orderId, __('The verification payment was refunded through Mollie.', 'stackless-lite-edd'));
    }

    private function unpaid(Order $order, array $payment, string $status): string
    {
        $orderId = (int) $order->id;
        if (!Orders::awaitingPayment($order)) {
            return 'ignored: order already settled';
        }
        $paymentId = (string) $payment['id'];
        $decline = Declines::fromPayment($payment);
        $offSession = self::isOffSession($orderId, $payment);
        if ($offSession || $status === 'failed' || !Orders::needsPayment($order)) {
            // Decline details first: Dunning reads them when the order turns failed.
            Declines::record($orderId, $decline);
            $note = sprintf(
                /* translators: 1: Mollie payment id, 2: status, 3: reason code */
                __('Mollie payment %1$s %2$s (%3$s).', 'stackless-lite-edd'),
                $paymentId,
                $status,
                $decline['code']
            );
            Orders::setStatus($orderId, 'failed', $note);
            return 'failed';
        }
        // The buyer left the hosted page or let it expire: the order stays payable.
        /* translators: 1: Mollie payment id, 2: status */
        Orders::note($orderId, sprintf(__('Mollie payment %1$s was %2$s. The customer can try again from the order\'s payment link.', 'stackless-lite-edd'), $paymentId, $status));
        return $status;
    }

    /** Save the mandate a first payment created, for the order's customer (0 = guest). */
    private function saveMandate(array $payment, int $userId, string $mode, int $eddCustomerId = 0): ?PaymentToken
    {
        $customerId = (string) ($payment['customerId'] ?? '');
        $mandateId = (string) ($payment['mandateId'] ?? '');
        if ($customerId === '' || $mandateId === '') {
            return null;
        }
        $mandate = $this->gateway->api($mode)->request('GET', 'customers/' . rawurlencode($customerId) . '/mandates/' . rawurlencode($mandateId));
        if (is_wp_error($mandate) || ($mandate['status'] ?? '') === 'invalid') {
            return null;
        }
        return Tokens::saveFromMandate($mandate, $userId, $customerId, $mode, $eddCustomerId);
    }

    /**
     * Account page → Add payment method: save the mandate once the first payment is paid, and
     * refund the verification amount.
     */
    public function applyAddPayment(array $payment, string $mode): ?PaymentToken
    {
        $userId = absint($payment['metadata']['wp_user_id'] ?? 0);
        if ($userId <= 0 || ($payment['status'] ?? '') !== 'paid' || (string) ($payment['sequenceType'] ?? '') !== 'first') {
            return null;
        }
        $paymentId = (string) $payment['id'];
        $token = Lock::run('add-' . $paymentId, function () use ($payment, $userId, $mode, $paymentId) {
            $token = $this->saveMandate($payment, $userId, $mode);
            if ($token && Money::fromAmount($payment['amount'] ?? null) > 0 && (float) ($payment['amountRefunded']['value'] ?? 0) <= 0) {
                $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode($paymentId) . '/refunds', [
                    'amount' => $payment['amount'],
                    'description' => __('Verification refund', 'stackless-lite-edd'),
                ], 'stackless-edd-verify-refund-' . $paymentId);
                if (is_wp_error($refund)) {
                    $this->gateway->log('Verification refund for ' . $paymentId . ' failed: ' . $refund->get_error_message(), 'error');
                }
            }
            return $token;
        });
        return $token instanceof PaymentToken ? $token : null;
    }

    /**
     * Bring refunds and chargebacks made in Mollie into EDD: each Mollie refund this store did
     * not start becomes an EDD refund (without calling Mollie again).
     */
    private function syncRefunds(Order $order, array $payment, string $mode): void
    {
        $orderId = (int) $order->id;
        $currency = strtoupper((string) $order->currency);
        $refunded = Money::fromAmount($payment['amountRefunded'] ?? null);
        if ($refunded > 0 && Orders::metaString($orderId, Gateway::META_VERIFICATION) !== 'yes') {
            $list = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', ['limit' => 50]);
            if (is_array($list)) {
                foreach ((array) ($list['_embedded']['refunds'] ?? []) as $refund) {
                    $this->syncRefund($orderId, is_array($refund) ? $refund : []);
                }
            }
        }

        $chargedBack = Money::fromAmount($payment['amountChargedBack'] ?? null);
        if ($chargedBack > 0 && Orders::metaString($orderId, Gateway::META_CHARGEBACK) !== Money::value($chargedBack, $currency)) {
            Orders::updateMeta($orderId, Gateway::META_CHARGEBACK, Money::value($chargedBack, $currency));
            $order = Orders::get($orderId) ?? $order;
            if (Orders::hasStatus($order, ['complete', 'partially_refunded']) && apply_filters('stackless_edd_mollie_gateway_chargeback_sets_on_hold', true, $order)) {
                if (function_exists('edd_record_order_dispute')) {
                    // EDD's own dispute record: On hold (file access paused), with the payment as the dispute id.
                    edd_record_order_dispute($orderId, (string) $payment['id'], 'chargeback');
                } else {
                    Orders::setStatus($orderId, 'on_hold');
                }
            }
            /* translators: %s: amount */
            Orders::note($orderId, sprintf(__('Mollie reports a chargeback of %s on this payment.', 'stackless-lite-edd'), Orders::money($chargedBack, $currency)));
        }
    }

    /** @param array<string,mixed> $refund */
    private function syncRefund(int $orderId, array $refund): void
    {
        $id = (string) ($refund['id'] ?? '');
        $state = (string) ($refund['status'] ?? '');
        $known = self::knownRefunds($orderId);
        if ($id === '' || in_array($id, $known, true)) {
            if ($id !== '' && $state === 'failed' && !in_array($id . ':failed', $known, true)) {
                /* translators: %s: Mollie refund id */
                Orders::note($orderId, sprintf(__('Mollie could not complete refund %s. Check it in the Mollie dashboard.', 'stackless-lite-edd'), $id));
                self::rememberRefund($orderId, $id . ':failed');
            }
            return;
        }
        if (in_array($state, ['failed', 'canceled'], true)) {
            return;
        }
        $order = Orders::get($orderId);
        if (!$order) {
            return;
        }
        $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($orderId) : (float) $order->total;
        $amount = min(Money::fromAmount($refund['amount'] ?? null), $remaining);
        if ($amount <= 0) {
            self::rememberRefund($orderId, $id);
            return;
        }
        // Remember first, so the edd_refund_order hook below never sends this refund back to Mollie.
        self::rememberRefund($orderId, $id);
        /* translators: %s: Mollie refund id */
        $note = sprintf(__('Refunded in Mollie (%s).', 'stackless-lite-edd'), $id);
        try {
            Refunds::recordExternal($order, $amount, $id, $note);
        } catch (\Throwable $e) {
            $ids = array_values(array_diff(self::knownRefunds($orderId), [$id]));
            Orders::updateMeta($orderId, Gateway::META_REFUND_IDS, $ids);
            $this->gateway->log('Could not record Mollie refund ' . $id . ' on order ' . $orderId . ': ' . $e->getMessage(), 'error');
        }
    }
}
