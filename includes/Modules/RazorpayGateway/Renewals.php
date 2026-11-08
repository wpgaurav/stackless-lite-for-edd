<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Recurring debits on a saved Razorpay token: subscription renewals, charges of a saved method
 * for a new order, and the first eNACH debit of an order.
 *
 * Each debit is a Razorpay order plus POST payments/create/recurring. Razorpay sends the
 * customer the pre-debit notice itself and debits a day or more later, so every debit returns
 * "pending" and settles through the payment.captured or payment.failed webhook. A debit is
 * created at most once per attempt: a repeat call finds the stored Razorpay order or payment
 * and reports its state instead of debiting again.
 */
final class Renewals
{
    /** Order flows that are debits without the customer present. */
    public const FLOWS = ['renewal', 'saved', 'first'];

    public const RETRY_HOOK = 'stackless_edd_razorpay_gateway_retry_charge';
    public const GROUP = 'stackless-edd-razorpay_gateway';
    public const META_ATTEMPT = '_stackless_edd_razorpay_attempt';
    public const META_TRANSIENT = '_stackless_edd_razorpay_transient_retries';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @return array{result:string, message?:string} */
    public static function charge(Order $renewal, ?SubscriptionData $subscription = null): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => self::copyMessage()];
        }
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            return self::fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'no_mandate'], __('No saved Razorpay payment method is attached to this subscription.', 'stackless-lite-edd'));
        }
        return self::chargeToken($renewal, $token, 'renewal');
    }

    /**
     * Contracts\SavedMethodCharger: charge a saved method for a new order (Post-purchase Offers).
     * Refused without an API call on a copy of the live store, for another gateway's order, for
     * a method that isn't the order customer's (guests included) and on a mode mismatch.
     *
     * @return array{result:string, message?:string}
     */
    public static function chargeSaved(Order $order, string $tokenId): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => self::copyMessage()];
        }
        if ((string) $order->gateway !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Razorpay.', 'stackless-lite-edd')];
        }
        $token = ctype_digit($tokenId) ? Tokens::getOwned((int) $tokenId, (int) $order->user_id) : null;
        if (!$token || (int) $order->user_id <= 0) {
            return ['result' => 'failed', 'message' => __('That saved payment method does not belong to this customer.', 'stackless-lite-edd')];
        }
        if ($token->mode !== Settings::mode()) {
            return ['result' => 'failed', 'message' => __('That saved payment method was saved in the other Razorpay mode.', 'stackless-lite-edd')];
        }
        return self::chargeToken($order, $token, 'saved');
    }

    /** @return array{result:string, message?:string} */
    public static function chargeToken(Order $order, PaymentToken $token, string $flow): array
    {
        if ($flow !== 'first' && !LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => self::copyMessage()];
        }
        $orderId = (int) $order->id;
        $result = Lock::run('charge-' . $orderId, static function () use ($orderId, $token, $flow) {
            $order = Orders::get($orderId);
            return $order ? self::debit($order, $token, $flow) : ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite-edd')];
        });
        return is_array($result) ? $result : ['result' => 'pending'];
    }

    public static function copyMessage(): string
    {
        return __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd');
    }

    /** @return array{result:string, message?:string} */
    private static function debit(Order $order, PaymentToken $token, string $flow): array
    {
        $orderId = (int) $order->id;
        if (Orders::isPaid($order)) {
            return ['result' => 'success'];
        }
        if (!Orders::needsPayment($order) && !($flow === 'first' && Orders::hasStatus($order, 'on_hold'))) {
            return Orders::hasStatus($order, ['on_hold', 'processing']) ? ['result' => 'pending'] : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite-edd')];
        }
        $currency = strtoupper((string) $order->currency);
        $minor = Money::toMinor($order->total, $currency);
        if ($minor <= 0) {
            Orders::complete($orderId);
            return ['result' => 'success'];
        }
        if (!Money::recurringSupported($currency)) {
            /* translators: %s: currency code */
            return self::fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'currency'], sprintf(__('Razorpay recurring payments are in Indian rupees only, not %s.', 'stackless-lite-edd'), $currency));
        }
        if (in_array($token->status, ['rejected', 'cancelled'], true)) {
            return self::fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'mandate_' . $token->status], __('The customer\'s Razorpay mandate is no longer active.', 'stackless-lite-edd'));
        }
        if ($token->status === 'paused') {
            return self::fail($order, ['category' => SubscriptionData::DECLINE_AUTH, 'code' => 'mandate_paused'], __('The customer paused their Razorpay mandate.', 'stackless-lite-edd'));
        }
        if ($token->status === 'initiated') {
            return self::fail($order, ['category' => SubscriptionData::DECLINE_SOFT, 'code' => 'mandate_not_confirmed'], __('The bank has not confirmed the customer\'s mandate yet.', 'stackless-lite-edd'));
        }
        if ($token->max_amount > 0 && $minor > $token->max_amount) {
            return self::fail($order, ['category' => SubscriptionData::DECLINE_AUTH, 'code' => 'amount_exceeds_mandate'], __('The amount is above what the customer\'s mandate allows. The customer needs to pay this one by hand or set up a new mandate.', 'stackless-lite-edd'));
        }

        $api = Settings::api($token->mode);

        // A debit already created for this order is reported, never repeated.
        $existing = self::existingDebit($order, $api);
        if ($existing !== null) {
            return $existing;
        }

        $attempt = (int) Orders::metaString($orderId, self::META_ATTEMPT);
        $notes = Checkout::notes($order, $flow);
        $created = $api->request('POST', 'orders', [
            'amount' => $minor,
            'currency' => Money::RECURRING,
            'payment_capture' => true,
            'receipt' => substr('edd_' . $flow[0] . '_' . $orderId . '_' . $attempt . '_' . base_convert((string) time(), 10, 36), 0, 40),
            'notes' => $notes,
        ]);
        if (is_wp_error($created)) {
            return self::apiError($order, $created);
        }
        Orders::setMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $token->rzp_customer);
        Orders::setMeta($orderId, Gateway::META_ORDER_ID, (string) ($created['id'] ?? ''));
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $minor);
        Orders::setMeta($orderId, Gateway::META_FLOW, $flow);
        Orders::setMeta($orderId, Gateway::META_MODE, $token->mode);
        Orders::setMeta($orderId, Gateway::META_ORDER_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_VERIFICATION, 'no');
        Orders::deleteMeta($orderId, Gateway::META_PAYMENT_ID);

        $payment = $api->request('POST', 'payments/create/recurring', [
            'email' => is_email($token->email) ? $token->email : (string) $order->email,
            'contact' => $token->contact,
            'amount' => $minor,
            'currency' => Money::RECURRING,
            'order_id' => (string) ($created['id'] ?? ''),
            'customer_id' => $token->rzp_customer,
            'token' => $token->token,
            'recurring' => true,
            /* translators: %s: order number */
            'description' => substr(sprintf(__('Order %s', 'stackless-lite-edd'), Orders::number($order)), 0, 50),
            'notes' => $notes,
        ]);
        if (is_wp_error($payment)) {
            return self::apiError($order, $payment);
        }
        $paymentId = (string) ($payment['razorpay_payment_id'] ?? ($payment['id'] ?? ''));
        Orders::setMeta($orderId, Gateway::META_PAYMENT_ID, $paymentId);
        Orders::deleteMeta($orderId, self::META_TRANSIENT);
        Orders::note($orderId, sprintf(
            /* translators: 1: method, 2: Razorpay payment id */
            __('Razorpay debit requested on the customer\'s %1$s (%2$s). Razorpay notifies the customer first and debits a day or two later; the order updates when Razorpay confirms it.', 'stackless-lite-edd'),
            wp_strip_all_tags($token->displayName()),
            $paymentId
        ));
        return ['result' => 'pending'];
    }

    /**
     * The state of a debit already created for this order, or null when there is none.
     *
     * @return array{result:string, message?:string}|null
     */
    private static function existingDebit(Order $order, Api $api): ?array
    {
        $orderId = (int) $order->id;
        if (!in_array(Orders::metaString($orderId, Gateway::META_FLOW), self::FLOWS, true)) {
            return null;
        }
        $rzpOrder = Orders::metaString($orderId, Gateway::META_ORDER_ID);
        if ($rzpOrder === '') {
            return null;
        }
        $payments = $api->request('GET', 'orders/' . rawurlencode($rzpOrder) . '/payments');
        if (is_wp_error($payments)) {
            // Unknown: don't risk a second debit; report pending and let the webhook settle it.
            return Api::isTransient($payments) ? ['result' => 'pending'] : null;
        }
        foreach ((array) ($payments['items'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'captured' || $status === 'authorized') {
                $outcome = Sync::applyPayment($order, $payment, $api->mode());
                return $outcome === 'paid' ? ['result' => 'success'] : ['result' => 'pending'];
            }
            if ($status === 'created' || $status === 'pending') {
                return ['result' => 'pending'];
            }
        }
        return null; // Only failed attempts: a new debit may be created.
    }

    /** @return array{result:string, message?:string} */
    private static function apiError(Order $order, \WP_Error $error): array
    {
        $orderId = (int) $order->id;
        if (Api::isTransient($error)) {
            $retries = (int) Orders::metaString($orderId, self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                Orders::setMeta($orderId, self::META_TRANSIENT, (string) ($retries + 1));
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $orderId], self::GROUP);
                /* translators: %s: error */
                Orders::note($orderId, sprintf(__('Razorpay could not be reached for this debit (%s). Retrying automatically.', 'stackless-lite-edd'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        return self::fail($order, Declines::fromError($error), $error->get_error_message());
    }

    /**
     * Write the decline category and code, THEN fail the order: Dunning reacts to the status change.
     *
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private static function fail(Order $order, array $decline, string $message): array
    {
        $orderId = (int) $order->id;
        Orders::setMeta($orderId, self::META_ATTEMPT, (string) ((int) Orders::metaString($orderId, self::META_ATTEMPT) + 1));
        Orders::deleteMeta($orderId, self::META_TRANSIENT);
        Declines::record($orderId, $decline);
        /* translators: 1: reason, 2: reason code */
        $note = sprintf(__('Razorpay debit failed: %1$s (%2$s)', 'stackless-lite-edd'), $message, $decline['code']);
        $fresh = Orders::get($orderId) ?? $order;
        if (Orders::hasStatus($fresh, ['pending', 'on_hold', 'abandoned'])) {
            Orders::setStatus($orderId, 'failed', $note);
        } else {
            Orders::note($orderId, $note);
        }
        Settings::log(sprintf('Debit for order %d failed: %s', $orderId, $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /**
     * Action Scheduler callback: retry a debit that could not reach Razorpay.
     *
     * @param mixed $order_id
     */
    public static function retry($order_id): void
    {
        $order_id = (int) $order_id;
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = Orders::get($order_id);
        if (!$order || (string) $order->gateway !== Gateway::ID || Orders::isPaid($order)) {
            return;
        }
        $subscriptions = stackless_edd_get_order_subscriptions($order_id);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof SubscriptionData ? $subscription : null);
        if ($token) {
            $flow = Orders::metaString($order_id, Gateway::META_FLOW);
            self::chargeToken($order, $token, in_array($flow, self::FLOWS, true) ? $flow : 'renewal');
        }
    }

    /** The saved Razorpay token an order (or its subscription) points at. */
    public static function resolveToken(Order $order, ?SubscriptionData $subscription = null): ?PaymentToken
    {
        $ids = [Orders::metaString((int) $order->id, SubscriptionData::META_TOKEN_ID)];
        if ($subscription) {
            $value = $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
            $ids[] = is_scalar($value) ? (string) $value : '';
        }
        foreach ($ids as $id) {
            $token = ctype_digit($id) ? Tokens::get((int) $id) : null;
            if ($token && $token->rzp_customer !== '') {
                return $token;
            }
        }
        return null;
    }
}
