<?php

namespace StacklessEDD\Modules\MollieGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Recurring payments on a saved mandate: subscription renewals (Contracts\SubscriptionGateway)
 * and charges of a saved method for a new order (Contracts\SavedMethodCharger).
 *
 * Outcomes follow the contracts: success = stackless_edd_complete_order() already called;
 * pending = Mollie is still processing (SEPA Direct Debit settles days later) or Mollie could not
 * be reached and a retry is scheduled; failed = decline details written, then the order failed.
 */
final class Renewals
{
    public const RETRY_HOOK = 'stackless_edd_mollie_gateway_retry_charge';
    public const GROUP = 'stackless-edd-mollie_gateway';
    public const META_ATTEMPT = '_stackless_edd_mollie_attempt';
    public const META_TRANSIENT = '_stackless_edd_mollie_transient_retries';
    public const META_FLOW = '_stackless_edd_mollie_flow';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return array{result:string, message?:string} */
    public function charge(Order $renewal, ?SubscriptionData $subscription = null): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        $renewal = Orders::get((int) $renewal->id) ?? $renewal;
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            if (Orders::isPaid($renewal)) {
                return ['result' => 'success'];
            }
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'no_mandate'], __('No saved Mollie payment method is attached to this subscription.', 'stackless-lite-edd'));
        }
        return $this->chargeToken($renewal, $token, 'renewal', $subscription);
    }

    /**
     * Contracts\SavedMethodCharger: refuses (no API call) on a copy of the live store, for
     * another gateway's order, for a method that is not the order customer's, and on a mode
     * mismatch.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeSaved(Order $order, string $tokenId, bool $onSession = true): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        if ((string) $order->gateway !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Mollie.', 'stackless-lite-edd')];
        }
        $userId = (int) $order->user_id;
        $token = ctype_digit($tokenId) ? Tokens::get((int) $tokenId) : null;
        if (!$token || $userId <= 0 || $token->user_id !== $userId) {
            return ['result' => 'failed', 'message' => __('That saved payment method does not belong to this customer.', 'stackless-lite-edd')];
        }
        if ($token->mode !== $this->gateway->mode()) {
            return ['result' => 'failed', 'message' => __('That saved payment method was saved in the other Mollie mode.', 'stackless-lite-edd')];
        }
        Orders::updateMeta((int) $order->id, self::META_FLOW, $onSession ? 'on' : 'off');
        return $this->chargeToken($order, $token, 'saved');
    }

    /**
     * Create (once) and apply a recurring payment for an order on a saved mandate.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeToken(Order $order, PaymentToken $token, string $flow, ?SubscriptionData $subscription = null): array
    {
        $orderId = (int) $order->id;
        $order = Orders::get($orderId) ?? $order;
        if (Orders::isPaid($order)) {
            return ['result' => 'success'];
        }
        if (!Orders::needsPayment($order)) {
            return Orders::hasStatus($order, ['on_hold', 'processing'])
                ? ['result' => 'pending']
                : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite-edd')];
        }
        $currency = strtoupper((string) $order->currency);
        $total = (float) $order->total;
        if ($total <= 0) {
            stackless_edd_complete_order($orderId);
            return ['result' => 'success'];
        }
        if (!Money::supported($currency) || !Methods::mandateTakes($token->method, $currency)) {
            /* translators: 1: payment method, 2: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'currency'], sprintf(__('%1$s through Mollie cannot be charged in %2$s.', 'stackless-lite-edd'), Methods::label($token->method), $currency));
        }

        $api = $this->gateway->api($token->mode);
        // A previous attempt for this order may already be paid or still settling.
        $previous = Orders::metaString($orderId, Gateway::META_PAYMENT_ID);
        if ($previous !== '' && Orders::metaString($orderId, Gateway::META_SEQUENCE) === 'recurring') {
            $existing = $api->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && in_array($existing['status'] ?? '', ['paid', 'pending', 'open', 'authorized'], true)) {
                return $this->outcome($order, $existing, $token->mode);
            }
        }

        Orders::updateMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        Orders::updateMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $token->mollie_customer);
        Orders::updateMeta($orderId, Gateway::META_MODE, $token->mode);

        $metadata = Sync::metadata($order, $flow);
        if ($subscription) {
            $metadata['subscription_id'] = (string) $subscription->get_id();
        }
        $attempt = (int) Orders::meta($orderId, self::META_ATTEMPT);
        $key = $flow === 'saved'
            ? self::savedKey($orderId, $token->id, Orders::metaString($orderId, self::META_FLOW) !== 'off')
            : self::idempotencyKey($orderId, $attempt);
        $payment = $api->request('POST', 'payments', [
            'amount' => Money::amount($total, $currency),
            /* translators: 1: order number, 2: site name */
            'description' => substr(sprintf(__('Order %1$s from %2$s', 'stackless-lite-edd'), Orders::number($order), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'sequenceType' => 'recurring',
            'customerId' => $token->mollie_customer,
            'mandateId' => $token->token,
            'webhookUrl' => $this->gateway->webhookUrl(),
            'metadata' => $metadata,
        ], $key);

        if (is_wp_error($payment)) {
            return $this->apiError($order, $payment, $flow);
        }
        Orders::deleteMeta($orderId, self::META_TRANSIENT);
        Orders::updateMeta($orderId, Gateway::META_PAYMENT_ID, (string) ($payment['id'] ?? ''));
        Orders::updateMeta($orderId, Gateway::META_SEQUENCE, 'recurring');
        Orders::updateMeta($orderId, Gateway::META_METHOD, (string) ($payment['method'] ?? $token->method));
        return $this->outcome($order, $payment, $token->mode);
    }

    /** Idempotency key: one per order and attempt (Mollie keeps keys for an hour). */
    public static function idempotencyKey(int $orderId, int $attempt): string
    {
        return 'stackless-edd-mollie-charge-' . $orderId . ($attempt > 0 ? '-retry-' . $attempt : '');
    }

    /** Idempotency key of a SavedMethodCharger charge: one per order, token and session. */
    public static function savedKey(int $orderId, int $tokenId, bool $onSession): string
    {
        return 'stackless-edd-saved-' . $orderId . '-' . $tokenId . '-' . ($onSession ? 'on' : 'off');
    }

    /** @return array{result:string, message?:string} */
    private function outcome(Order $order, array $payment, string $mode): array
    {
        $orderId = (int) $order->id;
        $applied = (new Sync($this->gateway))->applyToOrder($order, $payment, $mode);
        if ($applied === 'paid') {
            return ['result' => 'success'];
        }
        if ($applied === 'failed') {
            $this->bumpAttempt($orderId);
            return ['result' => 'failed', 'message' => __('Mollie declined the payment.', 'stackless-lite-edd')];
        }
        if ($applied === 'pending' || $applied === 'open' || $applied === 'busy') {
            $method = (string) ($payment['method'] ?? '');
            if (in_array(Methods::MANDATE_OF[$method] ?? $method, Methods::SLOW_MANDATES, true)) {
                Orders::note($orderId, __('SEPA Direct Debit submitted through Mollie. It settles in a few days; the order updates when Mollie confirms it.', 'stackless-lite-edd'));
            }
            return ['result' => 'pending'];
        }
        return ['result' => 'failed', 'message' => __('Mollie could not charge the saved payment method.', 'stackless-lite-edd')];
    }

    /** @return array{result:string, message?:string} */
    private function apiError(Order $order, \WP_Error $error, string $flow): array
    {
        $orderId = (int) $order->id;
        if (Api::isTransient($error) && $flow !== 'checkout') {
            $retries = (int) Orders::meta($orderId, self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                Orders::updateMeta($orderId, self::META_TRANSIENT, (string) ($retries + 1));
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $orderId], self::GROUP);
                /* translators: %s: error */
                Orders::note($orderId, sprintf(__('Mollie could not be reached for this charge (%s). Retrying automatically.', 'stackless-lite-edd'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        $this->bumpAttempt($orderId);
        return $this->fail($order, Declines::fromError($error), $error->get_error_message());
    }

    private function bumpAttempt(int $orderId): void
    {
        Orders::updateMeta($orderId, self::META_ATTEMPT, (string) ((int) Orders::meta($orderId, self::META_ATTEMPT) + 1));
        Orders::deleteMeta($orderId, self::META_TRANSIENT);
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(Order $order, array $decline, string $message): array
    {
        $orderId = (int) $order->id;
        // Decline details first: Dunning reads them when the order turns failed.
        Declines::record($orderId, $decline);
        /* translators: 1: reason, 2: reason code */
        $note = sprintf(__('Mollie charge failed: %1$s (%2$s)', 'stackless-lite-edd'), $message, $decline['code']);
        $current = Orders::get($orderId) ?? $order;
        if (Orders::hasStatus($current, 'pending')) {
            Orders::setStatus($orderId, 'failed', $note);
        } else {
            Orders::note($orderId, $note);
        }
        $this->gateway->log(sprintf('Charge for order %d failed: %s', $orderId, $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /**
     * Action Scheduler callback: retry a charge that could not reach Mollie.
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
        if (!$order || (string) $order->gateway !== Gateway::ID || !Orders::needsPayment($order)) {
            return;
        }
        $subscriptions = stackless_edd_get_order_subscriptions($order_id);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $subscription = $subscription instanceof SubscriptionData ? $subscription : null;
        $token = self::resolveToken($order, $subscription);
        if ($token) {
            (new self(Module::gateway()))->chargeToken($order, $token, stackless_edd_is_renewal_order($order_id) ? 'renewal' : 'saved', $subscription);
        }
    }

    /** The saved Mollie token a renewal (or its subscription) points at. */
    public static function resolveToken(Order $order, ?SubscriptionData $subscription = null): ?PaymentToken
    {
        $candidates = [Orders::meta((int) $order->id, SubscriptionData::META_TOKEN_ID)];
        if ($subscription) {
            $candidates[] = $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
        }
        foreach ($candidates as $tokenId) {
            $token = is_scalar($tokenId) && (int) $tokenId > 0 ? Tokens::get((int) $tokenId) : null;
            if ($token && $token->mollie_customer !== '' && $token->token !== '') {
                return $token;
            }
        }
        return null;
    }
}
