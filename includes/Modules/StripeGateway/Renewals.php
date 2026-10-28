<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Off-session renewal charges for the Subscriptions module (Contracts\SubscriptionGateway).
 *
 * Outcomes follow the contract: success => stackless_edd_complete_order() already called; pending =>
 * Stripe is still processing (bank debit) or the call hit a transient error and a retry is
 * scheduled with the same idempotency key; failed => the renewal order is marked failed and
 * the Subscriptions module runs dunning. Authentication-required failures leave a pay link
 * in the order notes so the customer can confirm the payment on-session.
 */
final class Renewals
{
    public const RETRY_HOOK = 'stackless_edd_stripe_gateway_retry_renewal';
    public const GROUP = 'stackless-edd-stripe_gateway';
    public const META_ATTEMPT = '_stackless_edd_stripe_renewal_attempt';
    public const META_TRANSIENT_RETRIES = '_stackless_edd_stripe_transient_retries';
    public const META_NEEDS_AUTH = '_stackless_edd_stripe_requires_action';

    private const MAX_TRANSIENT_RETRIES = 3;

    /**
     * @return array{result:string, message?:string}
     */
    public static function charge(\EDD\Orders\Order $renewal, ?SubscriptionData $subscription = null): array
    {
        if (!LiveStore::isLive()) {
            // A copy of the live store never uses a saved card, whatever mode the token is in (audit F04).
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        $order_id = (int) $renewal->id;
        $renewal = Orders::get($order_id) ?? $renewal;

        if (!Orders::needsPayment($renewal)) {
            if (Orders::isPaid($renewal)) {
                return ['result' => 'success'];
            }
            return Orders::hasStatus($renewal, ['on_hold', 'processing'])
                ? ['result' => 'pending']
                : ['result' => 'failed', 'message' => __('The renewal order cannot be paid in its current status.', 'stackless-lite-edd')];
        }

        $currency = strtoupper((string) $renewal->currency);
        $total = (float) $renewal->total;
        if ($total <= 0) {
            stackless_edd_complete_order($order_id);
            return ['result' => 'success'];
        }
        if (!Amount::isSupported($currency)) {
            /* translators: %s: currency code */
            return self::fail($renewal, sprintf(__('Stripe does not support %s.', 'stackless-lite-edd'), $currency));
        }
        if (!Amount::meetsMinimum((string) $renewal->total, $currency)) {
            return self::fail($renewal, ErrorMapper::message('amount_too_small'));
        }

        $source = self::resolveSource($renewal, $subscription);
        if (!$source) {
            return self::fail($renewal, __('No saved Stripe payment method is attached to this subscription.', 'stackless-lite-edd'));
        }

        // A previous attempt for this renewal may already have succeeded or still be processing.
        $previous = (string) Orders::meta($order_id, Payments::META_INTENT);
        if ($previous !== '') {
            try {
                $intent = Settings::client($source['mode'])->get('payment_intents/' . rawurlencode($previous), ['expand' => ['latest_charge']]);
                $status = (string) ($intent['status'] ?? '');
                if ($status === 'succeeded') {
                    $outcome = Payments::applyPaymentIntent($renewal, $intent, 'renewal');
                    return ['result' => $outcome === Payments::OUTCOME_PAID ? 'success' : 'pending'];
                }
                if ($status === 'processing') {
                    return ['result' => 'pending'];
                }
            } catch (StripeApiException $e) {
                Settings::log('Could not re-check previous renewal intent: ' . $e->logSummary(), 'warning');
            }
        }

        // Carry the token onto the renewal order so later webhooks and refunds find it.
        Orders::updateMeta($order_id, SubscriptionData::META_TOKEN_ID, (string) $source['token_id']);
        Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $source['customer']);
        Orders::updateMeta($order_id, Payments::META_PM, $source['payment_method']);

        $attempt = (int) Orders::meta($order_id, self::META_ATTEMPT);
        $metadata = ['stackless_renewal' => '1'];
        if ($subscription) {
            $metadata['subscription_id'] = (string) $subscription->get_id();
        }

        try {
            $intent = Payments::createPaymentIntent($renewal, [
                'payment_method' => $source['payment_method'],
                'payment_method_type' => $source['type'],
                'customer' => $source['customer'],
            ], [
                'off_session' => true,
                'capture' => true,
                'flow' => 'renewal',
                'mode' => $source['mode'],
                'metadata' => $metadata,
                'idempotency_key' => IdempotencyKey::renewal($order_id, $attempt),
            ]);
        } catch (StripeApiException $e) {
            return self::handleException($renewal, $e);
        }

        Orders::deleteMeta($order_id, self::META_TRANSIENT_RETRIES);

        $outcome = Payments::applyPaymentIntent($renewal, $intent, 'renewal');
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
                return ['result' => 'success'];
            case Payments::OUTCOME_PROCESSING:
            case Payments::OUTCOME_HELD:
            case Payments::OUTCOME_BUSY:
                // Busy: the webhook is applying this intent. Held: charged, but on hold for the merchant.
                return ['result' => 'pending'];
            case Payments::OUTCOME_ACTION:
                return self::failNeedsAuthentication($renewal);
            default:
                $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
                return self::fail($renewal, $error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
        }
    }

    /**
     * Action Scheduler callback for transient-error retries.
     *
     * @param mixed $order_id
     */
    public static function retry($order_id): void
    {
        $order_id = (int) $order_id;
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $renewal = Orders::get($order_id);
        if (!$renewal || (string) $renewal->gateway !== Settings::GATEWAY_ID || !Orders::needsPayment($renewal)) {
            return;
        }
        $subscriptions = stackless_edd_get_order_subscriptions($order_id);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        self::charge($renewal, $subscription instanceof SubscriptionData ? $subscription : null);
    }

    /**
     * @return array{token_id:int, payment_method:string, customer:string, type:string, mode:string}|null
     */
    public static function resolveSource(\EDD\Orders\Order $renewal, ?SubscriptionData $subscription = null): ?array
    {
        $order_id = (int) $renewal->id;
        $read = static function (string $key) use ($order_id, $subscription) {
            $value = Orders::meta($order_id, $key);
            if (($value === '' || $value === null || $value === false) && $subscription) {
                $value = $subscription->get_meta($key);
            }
            return $value;
        };

        $token = Tokens::get((int) $read(SubscriptionData::META_TOKEN_ID));

        $payment_method = $token ? $token->token : (string) $read(Payments::META_PM);
        $customer = (string) $read(SubscriptionData::META_GATEWAY_CUSTOMER);
        if ($token && $token->stripe_customer !== '') {
            $customer = $token->stripe_customer;
        }
        if ($payment_method === '' || $customer === '') {
            return null;
        }
        $mode = $token ? $token->mode : (string) $read(Payments::META_MODE);
        return [
            'token_id' => $token ? $token->id : 0,
            'payment_method' => $payment_method,
            'customer' => $customer,
            'type' => $token ? $token->type : 'card',
            'mode' => $mode === 'live' ? 'live' : ($mode === 'test' ? 'test' : Settings::mode()),
        ];
    }

    /** @return array{result:string, message?:string} */
    private static function handleException(\EDD\Orders\Order $renewal, StripeApiException $e): array
    {
        $order_id = (int) $renewal->id;
        $intent = $e->getPaymentIntent();

        if ($e->isRetryable() && !$intent) {
            $retries = (int) Orders::meta($order_id, self::META_TRANSIENT_RETRIES);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                Orders::updateMeta($order_id, self::META_TRANSIENT_RETRIES, (string) ($retries + 1));
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP);
                /* translators: %s: error summary */
                Orders::note($order_id, sprintf(__('Stripe could not be reached for the renewal charge (%s). Retrying automatically with the same idempotency key.', 'stackless-lite-edd'), $e->getMessage()));
                return ['result' => 'pending'];
            }
        }

        $status = $intent ? (string) ($intent['status'] ?? '') : '';
        if (ErrorMapper::isAuthenticationRequired($e->getStripeCode(), $e->getDeclineCode(), $status)) {
            return self::failNeedsAuthentication($renewal);
        }
        return self::fail($renewal, ErrorMapper::fromException($e), $e->getDeclineCode() ?: $e->getStripeCode());
    }

    /** @return array{result:string, message:string} */
    private static function failNeedsAuthentication(\EDD\Orders\Order $renewal): array
    {
        $order_id = (int) $renewal->id;
        Orders::updateMeta($order_id, self::META_NEEDS_AUTH, 'yes');
        $message = ErrorMapper::message('authentication_required');
        $note = sprintf(
            /* translators: %s: order payment URL */
            __('The bank requires the customer to authenticate this renewal (3D Secure). The customer can confirm and pay at: %s', 'stackless-lite-edd'),
            stackless_edd_order_pay_url($order_id)
        );
        return self::fail($renewal, $message, 'authentication_required', $note);
    }

    /** @return array{result:string, message:string} */
    private static function fail(\EDD\Orders\Order $renewal, string $message, string $code = '', string $note = ''): array
    {
        $order_id = (int) $renewal->id;
        Orders::updateMeta($order_id, self::META_ATTEMPT, (string) ((int) Orders::meta($order_id, self::META_ATTEMPT) + 1));
        Orders::deleteMeta($order_id, self::META_TRANSIENT_RETRIES);
        if ($note === '') {
            /* translators: %s: reason */
            $note = sprintf(__('Stripe renewal charge failed: %s', 'stackless-lite-edd'), $message);
            if ($code !== '') {
                $note .= ' (' . $code . ')';
            }
        }
        Orders::setStatus($order_id, 'failed', $note);
        Settings::log(sprintf('Renewal %d failed: %s', $order_id, $code ?: $message), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }
}
