<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Razorpay payments, tokens and refunds to EDD, from the signed browser return, the
 * signed webhook and renewals alike, under one lock per order. Every payment is matched to the
 * Razorpay order this store created for it, and its amount and currency are checked before an
 * order is marked paid.
 */
final class Sync
{
    public static function rememberRefund(int $orderId, string $refundId): void
    {
        $ids = Orders::meta($orderId, Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::setMeta($orderId, Gateway::META_REFUND_IDS, array_values($ids));
        }
    }

    /** @return string[] */
    public static function knownRefunds(int $orderId): array
    {
        $ids = Orders::meta($orderId, Gateway::META_REFUND_IDS);
        return is_array($ids) ? array_map('strval', $ids) : [];
    }

    /* ---- Browser return --------------------------------------------------------------- */

    /** ?stackless_edd_razorpay=return: checkout.js posts the signed result here. */
    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- Razorpay's HMAC signature, keyed with the store's key secret, authenticates the post.
        $paymentId = isset($_POST['razorpay_payment_id']) && is_scalar($_POST['razorpay_payment_id']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_payment_id'])) : '';
        $rzpOrder = isset($_POST['razorpay_order_id']) && is_scalar($_POST['razorpay_order_id']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_order_id'])) : '';
        $signature = isset($_POST['razorpay_signature']) && is_scalar($_POST['razorpay_signature']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_signature'])) : '';
        $flow = isset($_GET['flow']) && is_scalar($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : '';
        if ($flow === 'add') {
            $ref = isset($_GET['ref']) && is_scalar($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : '';
            self::finishAdd(get_current_user_id(), $ref, $paymentId, $rzpOrder, $signature);
            return;
        }
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) && is_scalar($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        // phpcs:enable
        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(Orders::returnKey($order, 'razorpay-return'), $key) || (string) $order->gateway !== Gateway::ID) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $outcome = self::verifyReturn($order, $paymentId, $rzpOrder, $signature);
        $order = Orders::get($orderId);
        if ($order && (Orders::isPaid($order) || Orders::hasStatus($order, 'on_hold') || in_array($outcome, ['paid', 'awaiting_mandate', 'busy'], true))) {
            edd_empty_cart();
            if (function_exists('EDD') && EDD()->session) {
                EDD()->session->set('edd_resume_payment', null);
            }
            wp_safe_redirect(Gateway::successUrl());
            exit;
        }
        wp_safe_redirect($order ? add_query_arg('failed', '1', Checkout::payUrl($order)) : edd_get_checkout_uri());
        exit;
    }

    /**
     * Check a Razorpay Checkout result for an order and apply the payment it names.
     *
     * @return string an applyPayment() outcome, "invalid" or "unreadable"
     */
    public static function verifyReturn(Order $order, string $paymentId, string $rzpOrder, string $signature): string
    {
        $orderId = (int) $order->id;
        $mode = Gateway::orderMode($orderId);
        $expected = Orders::metaString($orderId, Gateway::META_ORDER_ID);
        // The Razorpay order id is ours, from the order; the browser only supplies the payment.
        if ($expected === '' || !hash_equals($expected, $rzpOrder) || !Signature::payment($expected, $paymentId, $signature, Settings::keySecret($mode))) {
            Settings::log('Rejected a Razorpay return for order ' . $orderId . ' with a missing or invalid signature.', 'warning');
            return 'invalid';
        }
        $payment = Settings::api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
        return is_array($payment) ? self::applyPayment($order, $payment, $mode) : 'unreadable';
    }

    private static function finishAdd(int $userId, string $ref, string $paymentId, string $rzpOrder, string $signature): void
    {
        $target = Account::listUrl();
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect($target);
            exit;
        }
        $mode = (string) $pending['mode'];
        $token = null;
        if (hash_equals((string) $pending['order'], $rzpOrder) && Signature::payment($rzpOrder, $paymentId, $signature, Settings::keySecret($mode))) {
            $payment = Settings::api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
            if (is_array($payment)) {
                $token = self::applyAddPayment($payment, $mode, $userId);
            }
        }
        delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
        $subscriptionId = (int) ($pending['subscription'] ?? 0);
        if ($token) {
            stackless_edd_add_notice($token->status === 'confirmed'
                ? __('Payment method successfully added.', 'stackless-lite-edd')
                : __('Payment method added. It can be used once your bank confirms the mandate.', 'stackless-lite-edd'));
            $subscription = $subscriptionId > 0 ? stackless_edd_get_subscription($subscriptionId) : null;
            if ($subscription instanceof SubscriptionData && $subscription->get_user_id() === $userId) {
                $target = stackless_edd_account_url('subscriptions', ['id' => $subscriptionId]);
            }
        } else {
            stackless_edd_add_notice(__('Unable to add payment method to your account.', 'stackless-lite-edd'), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }

    /* ---- Payments --------------------------------------------------------------------- */

    /**
     * Apply a Razorpay payment entity to an order (return, webhook or renewal).
     *
     * @param array<string,mixed> $payment
     * @return string paid|awaiting_mandate|pending|failed|mismatch|ignored…|busy
     */
    public static function applyPayment(Order $order, array $payment, string $mode): string
    {
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, static function () use ($orderId, $payment, $mode) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'none';
            }
            $rzpOrder = (string) ($payment['order_id'] ?? '');
            if ($rzpOrder === '' || $rzpOrder !== Orders::metaString($orderId, Gateway::META_ORDER_ID)) {
                return 'ignored: payment for another Razorpay order';
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'authorized' && (int) ($payment['amount'] ?? -1) === 0) {
                $status = 'captured'; // A ₹0 eNACH authorization has nothing to capture.
            } elseif ($status === 'authorized') {
                $payment = self::capture($order, $payment, $mode);
                $status = (string) ($payment['status'] ?? '');
            }
            if ($status === 'captured') {
                $outcome = self::paid($order, $payment, $mode);
                self::syncRefunds($order, $payment, $mode);
                return $outcome;
            }
            if ($status === 'failed') {
                return self::failed($order, $payment);
            }
            return 'pending';
        }, 'busy');
    }

    /**
     * Capture an authorized payment for the amount this store asked for.
     *
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    private static function capture(Order $order, array $payment, string $mode): array
    {
        $orderId = (int) $order->id;
        $expected = (int) Orders::metaString($orderId, Gateway::META_AMOUNT);
        $currency = strtoupper((string) $order->currency);
        if ((int) ($payment['amount'] ?? -1) !== $expected || strtoupper((string) ($payment['currency'] ?? '')) !== $currency) {
            return $payment;
        }
        $captured = Settings::api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/capture', [
            'amount' => $expected,
            'currency' => $currency,
        ]);
        if (is_wp_error($captured)) {
            Settings::log('Capture of ' . (string) $payment['id'] . ' failed: ' . $captured->get_error_message(), 'warning');
            return $payment;
        }
        return $captured;
    }

    /** @param array<string,mixed> $payment */
    private static function paid(Order $order, array $payment, string $mode): string
    {
        $orderId = (int) $order->id;
        $paymentId = (string) ($payment['id'] ?? '');
        if (Orders::isPaid($order)) {
            return 'paid';
        }
        $currency = strtoupper((string) $order->currency);
        $expected = (int) Orders::metaString($orderId, Gateway::META_AMOUNT);
        if ((int) ($payment['amount'] ?? -1) !== $expected || strtoupper((string) ($payment['currency'] ?? '')) !== $currency) {
            if (!Orders::hasStatus($order, 'on_hold')) {
                /* translators: %s: Razorpay payment id */
                Orders::setStatus($orderId, 'on_hold', sprintf(__('Razorpay payment %s does not match the amount this order asked for. Check it before delivering the order.', 'stackless-lite-edd'), $paymentId));
            }
            return 'mismatch';
        }

        $flow = Orders::metaString($orderId, Gateway::META_FLOW);
        if ($flow === 'auth') {
            // Already registered (eNACH waiting for the bank): nothing more to save.
            if (Orders::metaString($orderId, Gateway::META_FIRST_DEBIT) !== '' && Orders::hasStatus($order, 'on_hold')) {
                return 'awaiting_mandate';
            }
            $token = self::saveToken($payment, $order, $mode);
            if (!$token) {
                Orders::note($orderId, __('Razorpay did not return a recurring token for this payment, so renewals will be invoiced.', 'stackless-lite-edd'));
            } else {
                // Written before the order completes: Subscriptions copies them to the subscription then.
                Orders::setMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $token->id);
                Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $token->rzp_customer);
                /* translators: %s: saved method */
                Orders::note($orderId, sprintf(__('Saved %s for renewals.', 'stackless-lite-edd'), wp_strip_all_tags($token->displayName())));
                $orderMinor = Money::toMinor($order->total, $currency);
                if (Mandate::needsFirstDebit($token->method, $orderMinor)) {
                    // eNACH authorizes ₹0: the order's own amount is a separate debit.
                    Orders::setMeta($orderId, Gateway::META_FIRST_DEBIT, $token->token);
                    Orders::setStatus($orderId, 'on_hold', __('eNACH mandate registered. The first payment is debited once the bank confirms the mandate, usually within two working days.', 'stackless-lite-edd'));
                    if ($token->status === 'confirmed') {
                        $fresh = Orders::get($orderId);
                        if ($fresh) {
                            self::firstDebit($fresh, $token);
                        }
                    }
                    return 'awaiting_mandate';
                }
            }
        }
        Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
        Orders::setMeta($orderId, Gateway::META_METHOD, (string) ($payment['method'] ?? ''));
        if (Orders::metaString($orderId, Gateway::META_VERIFICATION) === 'yes') {
            self::refundVerification($orderId, $payment, $mode);
        }
        Orders::complete($orderId, $paymentId);
        return 'paid';
    }

    /** @param array<string,mixed> $payment */
    private static function failed(Order $order, array $payment): string
    {
        $orderId = (int) $order->id;
        if (!Orders::needsPayment($order) && !Orders::hasStatus($order, 'on_hold')) {
            return 'ignored: order already settled';
        }
        $decline = Declines::fromPayment($payment);
        $offSession = in_array(Orders::metaString($orderId, Gateway::META_FLOW), Renewals::FLOWS, true);
        if ($offSession || Orders::hasStatus($order, 'on_hold')) {
            Declines::record($orderId, $decline);
            /* translators: 1: Razorpay payment id, 2: reason */
            $note = sprintf(__('Razorpay debit %1$s failed (%2$s).', 'stackless-lite-edd'), (string) ($payment['id'] ?? ''), $decline['code']);
            Orders::setStatus($orderId, 'failed', $note);
            return 'failed';
        }
        // A checkout attempt failed in Razorpay Checkout; the buyer can try again on the same order.
        /* translators: 1: Razorpay payment id, 2: reason */
        Orders::note($orderId, sprintf(__('Razorpay payment attempt %1$s failed (%2$s).', 'stackless-lite-edd'), (string) ($payment['id'] ?? ''), $decline['code']));
        return 'failed';
    }

    /**
     * Refund the ₹1 authorization of a free (all-trial) order.
     *
     * @param array<string,mixed> $payment
     */
    private static function refundVerification(int $orderId, array $payment, string $mode): void
    {
        $refund = Settings::api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refund', [
            'amount' => (int) $payment['amount'],
            'notes' => ['edd_order_id' => (string) $orderId, 'verification' => '1'],
        ], ['X-Refund-Idempotency' => 'stackless-edd-verify-' . (string) $payment['id']]);
        if (is_wp_error($refund)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('The ₹1 authorization could not be refunded automatically: %s Refund it in Razorpay.', 'stackless-lite-edd'), $refund->get_error_message()));
            return;
        }
        self::rememberRefund($orderId, (string) ($refund['id'] ?? ''));
        Orders::note($orderId, __('The ₹1 authorization was refunded through Razorpay.', 'stackless-lite-edd'));
    }

    /**
     * Save the token an authorization payment created.
     *
     * @param array<string,mixed> $payment
     */
    private static function saveToken(array $payment, Order $order, string $mode): ?PaymentToken
    {
        return self::tokenFromPayment($payment, (int) $order->user_id, $mode, (int) $order->customer_id);
    }

    /** @param array<string,mixed> $payment */
    private static function tokenFromPayment(array $payment, int $userId, string $mode, int $eddCustomerId = 0): ?PaymentToken
    {
        $tokenId = (string) ($payment['token_id'] ?? '');
        $customerId = (string) ($payment['customer_id'] ?? '');
        if ($tokenId === '' || $customerId === '') {
            return null;
        }
        $entity = Settings::api($mode)->request('GET', 'customers/' . rawurlencode($customerId) . '/tokens/' . rawurlencode($tokenId));
        if (is_wp_error($entity)) {
            Settings::log('Could not read token ' . $tokenId . ': ' . $entity->get_error_message(), 'warning');
            return null;
        }
        if (in_array(Tokens::statusOf($entity), ['rejected', 'cancelled'], true)) {
            return null;
        }
        return Tokens::saveFromEntity($entity, $userId, $customerId, $mode, $payment, $eddCustomerId);
    }

    /**
     * Account page → Add payment method: save the token and refund the ₹1 authorization.
     *
     * @param array<string,mixed> $payment
     */
    public static function applyAddPayment(array $payment, string $mode, int $userId): ?PaymentToken
    {
        if ($userId <= 0 || !in_array($payment['status'] ?? '', ['captured', 'authorized'], true)) {
            return null;
        }
        $token = self::tokenFromPayment($payment, $userId, $mode);
        if ($token && (int) ($payment['amount'] ?? 0) > 0 && (int) ($payment['amount_refunded'] ?? 0) === 0) {
            $refund = Settings::api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refund', [
                'amount' => (int) $payment['amount'],
            ], ['X-Refund-Idempotency' => 'stackless-edd-verify-' . (string) $payment['id']]);
            if (is_wp_error($refund)) {
                Settings::log('Verification refund for ' . (string) $payment['id'] . ' failed: ' . $refund->get_error_message(), 'error');
            }
        }
        return $token;
    }

    /** eNACH: debit an order's first payment once its mandate is confirmed. */
    public static function firstDebit(Order $order, PaymentToken $token): void
    {
        if (Orders::metaString((int) $order->id, Gateway::META_FIRST_DEBIT) !== $token->token || Orders::isPaid($order)) {
            return;
        }
        $result = Renewals::chargeToken($order, $token, 'first');
        if ($result['result'] === 'failed') {
            Settings::log('First eNACH debit for order ' . (int) $order->id . ' failed: ' . ($result['message'] ?? ''), 'warning');
        }
    }

    /* ---- Webhook entities ------------------------------------------------------------ */

    /**
     * A payment.* or order.paid webhook.
     *
     * @param array<string,mixed> $payment
     */
    public static function webhookPayment(array $payment, string $mode): string
    {
        $notes = is_array($payment['notes'] ?? null) ? $payment['notes'] : [];
        if (($notes['edd_site'] ?? '') !== '' && $notes['edd_site'] !== Settings::site()) {
            return 'ignored: another site';
        }
        $order = self::findOrder($payment);
        if (!$order) {
            return 'ignored: no matching order';
        }
        return self::applyPayment($order, $payment, $mode);
    }

    /** @param array<string,mixed> $payment */
    private static function findOrder(array $payment): ?Order
    {
        $notes = is_array($payment['notes'] ?? null) ? $payment['notes'] : [];
        $order = Orders::get(absint($notes['edd_order_id'] ?? 0));
        if ($order && (string) $order->gateway === Gateway::ID) {
            return $order;
        }
        return Orders::findByMeta(Gateway::META_ORDER_ID, (string) ($payment['order_id'] ?? ''), Gateway::ID);
    }

    /**
     * A refund.* webhook: refunds made in the Razorpay dashboard, and failures of ours.
     *
     * @param array<string,mixed> $refund
     * @param array<string,mixed> $payment
     */
    public static function webhookRefund(string $event, array $refund, array $payment, string $mode): string
    {
        $paymentId = (string) ($refund['payment_id'] ?? ($payment['id'] ?? ''));
        if ($paymentId === '') {
            return 'ignored: no payment';
        }
        $order = Orders::findByMeta(Gateway::META_PAYMENT_ID, $paymentId, Gateway::ID);
        if (!$order) {
            return 'ignored: no matching order';
        }
        $orderId = (int) $order->id;
        return (string) Lock::run('order-' . $orderId, static function () use ($orderId, $event, $refund) {
            $order = Orders::get($orderId);
            if (!$order) {
                return 'none';
            }
            $refundId = (string) ($refund['id'] ?? '');
            $known = self::knownRefunds($orderId);
            if ($event === 'refund.failed') {
                if (in_array($refundId, $known, true) && !in_array($refundId . ':failed', $known, true)) {
                    /* translators: %s: Razorpay refund id */
                    Orders::note($orderId, sprintf(__('Razorpay could not complete refund %s. Check it in the Razorpay dashboard.', 'stackless-lite-edd'), $refundId));
                    self::rememberRefund($orderId, $refundId . ':failed');
                }
                return 'refund failed noted';
            }
            if ($refundId === '' || in_array($refundId, $known, true) || Orders::metaString($orderId, Gateway::META_VERIFICATION) === 'yes') {
                return 'refund already recorded';
            }
            if (!Orders::isPaid($order)) {
                return 'ignored: order not paid';
            }
            $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($orderId) : (float) $order->total;
            $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0), (string) $order->currency), $remaining);
            self::rememberRefund($orderId, $refundId);
            if ($amount <= 0) {
                return 'nothing left to refund';
            }
            $created = Refunds::recordExternal($order, $amount, $refundId);
            return is_wp_error($created) ? 'refund not recorded: ' . $created->get_error_message() : 'refund recorded';
        }, 'busy');
    }

    /**
     * Refunds already on a captured payment that this store has not recorded (dashboard refunds).
     *
     * @param array<string,mixed> $payment
     */
    private static function syncRefunds(Order $order, array $payment, string $mode): void
    {
        if ((int) ($payment['amount_refunded'] ?? 0) <= 0 || Orders::metaString((int) $order->id, Gateway::META_VERIFICATION) === 'yes') {
            return;
        }
        $list = Settings::api($mode)->request('GET', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', ['count' => 50]);
        if (!is_array($list)) {
            return;
        }
        foreach ((array) ($list['items'] ?? []) as $refund) {
            if (is_array($refund) && ($refund['status'] ?? '') !== 'failed') {
                // Runs inside this order's lock already, so record directly.
                self::recordRefundUnlocked($order, $refund);
            }
        }
    }

    /** @param array<string,mixed> $refund */
    private static function recordRefundUnlocked(Order $order, array $refund): void
    {
        $orderId = (int) $order->id;
        $refundId = (string) ($refund['id'] ?? '');
        $fresh = Orders::get($orderId);
        if ($refundId === '' || !$fresh || !Orders::isPaid($fresh) || in_array($refundId, self::knownRefunds($orderId), true)) {
            return;
        }
        $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($orderId) : (float) $fresh->total;
        $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0), (string) $fresh->currency), $remaining);
        self::rememberRefund($orderId, $refundId);
        if ($amount > 0) {
            Refunds::recordExternal($fresh, $amount, $refundId);
        }
    }

    /**
     * A token.* webhook: mandate confirmed, rejected, cancelled or paused.
     *
     * @param array<string,mixed> $entity
     */
    public static function webhookToken(string $event, array $entity, string $mode): string
    {
        $tokenId = (string) ($entity['id'] ?? '');
        if ($tokenId === '') {
            return 'ignored: no token';
        }
        $status = substr($event, strlen('token.'));
        $updated = Tokens::updateStatus($tokenId, $status);
        $waiting = edd_get_orders([
            'number' => 20,
            'type' => 'sale',
            'gateway' => Gateway::ID,
            'status__in' => ['on_hold', 'pending'],
            'meta_query' => [['key' => Gateway::META_FIRST_DEBIT, 'value' => $tokenId]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- orders waiting on this mandate, once per token webhook.
        ]);
        $waiting = is_array($waiting) ? $waiting : [];
        $tokens = Tokens::find($tokenId);
        if ($status === 'confirmed') {
            foreach ($waiting as $order) {
                if ($order instanceof Order && $tokens) {
                    self::firstDebit($order, $tokens[0]);
                }
            }
            return 'token confirmed (' . $updated . ' saved, ' . count($waiting) . ' first debits)';
        }
        if ($status === 'rejected' || $status === 'cancelled') {
            foreach ($waiting as $order) {
                if ($order instanceof Order && (Orders::needsPayment($order) || Orders::hasStatus($order, 'on_hold'))) {
                    Declines::record((int) $order->id, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'mandate_' . $status]);
                    Orders::setStatus((int) $order->id, 'failed', $status === 'rejected'
                        ? __('The bank rejected the eNACH mandate, so the first payment was not debited.', 'stackless-lite-edd')
                        : __('The bank cancelled the eNACH mandate, so the first payment was not debited.', 'stackless-lite-edd'));
                }
            }
            $forgotten = Tokens::forget($tokenId);
            return 'token ' . $status . ' (' . $forgotten . ' removed)';
        }
        return 'token ' . $status . ' (' . $updated . ' updated)';
    }
}
