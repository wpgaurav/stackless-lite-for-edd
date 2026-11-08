<?php

namespace StacklessEDD\Modules\StripeGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Contracts\SavedMethodCharger for Stripe (2.0.0): charges a customer's saved payment method
 * for a new order outside the renewal schedule (post-purchase offers).
 *
 * The caller created the order and owns the customer's consent. This re-checks that the token
 * belongs to the order's customer and to this gateway, refuses on a copy of the live store,
 * and charges with one PaymentIntent keyed by the order. When the bank wants the customer to
 * authenticate, the order stays pending and the result points at the order's pay link, where
 * the customer confirms (checkout runs 3-D Secure in place from the confirm fragment).
 */
final class SavedCharges
{
    /**
     * @return array{result:string, message?:string, redirect?:string}
     */
    public static function charge(Order $order, string $token_id, bool $on_session): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        $order_id = (int) $order->id;
        $order = Orders::get($order_id) ?? $order;
        $token = ctype_digit($token_id) ? Tokens::get((int) $token_id) : null;
        $refusal = self::refusal($order, $token);
        if ($refusal !== '' || !$token) {
            Settings::log(sprintf('Saved-method charge for order %d refused: %s', $order_id, $refusal), 'warning');
            return ['result' => 'failed', 'message' => $refusal];
        }
        if (Orders::isPaid($order)) {
            return ['result' => 'success'];
        }

        Orders::updateMeta($order_id, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $token->stripe_customer);
        Orders::updateMeta($order_id, Payments::META_PM, $token->token);

        try {
            $intent = Payments::createPaymentIntent($order, [
                'payment_method' => $token->token,
                'payment_method_type' => $token->type,
                'customer' => $token->stripe_customer,
            ], [
                'off_session' => !$on_session,
                'capture' => true,
                'flow' => 'checkout',
                'mode' => $token->mode,
                'metadata' => ['stackless_saved_charge' => '1'],
                'idempotency_key' => self::idempotencyKey($order_id, $token->id, $on_session),
            ]);
        } catch (StripeApiException $e) {
            return self::fromException($order, $e);
        }

        $outcome = Payments::applyPaymentIntent($order, $intent, 'saved_method');
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
                return ['result' => 'success'];
            case Payments::OUTCOME_PROCESSING:
            case Payments::OUTCOME_HELD:
            case Payments::OUTCOME_BUSY:
            case Payments::OUTCOME_AUTHORIZED:
                return ['result' => 'pending'];
            case Payments::OUTCOME_ACTION:
                return self::actionRequired($order, $on_session ? (string) ($intent['client_secret'] ?? '') : '');
        }
        $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
        if ($error) {
            Declines::fromIntentError($order_id, $error);
        }
        return ['result' => 'failed', 'message' => $error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic()];
    }

    /** Why the token may not pay this order, or '' when it may. */
    public static function refusal(Order $order, ?PaymentToken $token): string
    {
        $user = (int) $order->user_id;
        if ((string) $order->gateway !== Settings::GATEWAY_ID) {
            return __('The order is not set to pay with Stripe.', 'stackless-lite-edd');
        }
        if (!$token || $user <= 0 || $token->user_id !== $user) {
            return __('That saved payment method does not belong to this customer.', 'stackless-lite-edd');
        }
        if ($token->stripe_customer === '' || $token->token === '') {
            return __('That saved payment method is incomplete. Ask the customer to pay with a new method.', 'stackless-lite-edd');
        }
        if ($token->mode !== Settings::mode()) {
            return __('That saved payment method belongs to the other Stripe mode (test or live).', 'stackless-lite-edd');
        }
        if (!Orders::isPaid($order) && !Orders::needsPayment($order)) {
            return __('The order cannot be paid in its current status.', 'stackless-lite-edd');
        }
        $currency = strtoupper((string) $order->currency);
        if ((float) $order->total <= 0 || !Amount::isSupported($currency) || !Amount::meetsMinimum((string) $order->total, $currency)) {
            return ErrorMapper::message('amount_too_small');
        }
        return '';
    }

    /** One key per order, token and session type, so a double click never charges twice. */
    public static function idempotencyKey(int $order_id, int $token_id, bool $on_session): string
    {
        return 'stackless-edd-saved-' . $order_id . '-' . $token_id . ($on_session ? '-on' : '-off');
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    private static function fromException(Order $order, StripeApiException $e): array
    {
        $order_id = (int) $order->id;
        $intent = $e->getPaymentIntent();
        $status = $intent ? (string) ($intent['status'] ?? '') : '';
        Declines::fromException($order_id, $e);
        /* translators: %s: error message */
        Orders::note($order_id, sprintf(__('Charging the saved Stripe payment method failed: %s', 'stackless-lite-edd'), ErrorMapper::fromException($e) . ($e->getDeclineCode() ? ' (' . $e->getDeclineCode() . ')' : '')));
        Settings::log('Saved-method charge for order ' . $order_id . ' failed: ' . $e->logSummary(), $e->getStripeType() === 'card_error' ? 'info' : 'error');
        if (ErrorMapper::isAuthenticationRequired($e->getStripeCode(), $e->getDeclineCode(), $status)) {
            // Off-session attempt the bank wants confirmed: the customer pays the order from its pay link.
            return ['result' => 'action_required', 'message' => ErrorMapper::message('authentication_required'), 'redirect' => stackless_edd_order_pay_url($order_id)];
        }
        return ['result' => 'failed', 'message' => ErrorMapper::fromException($e)];
    }

    /** @return array{result:string, message:string, redirect:string} */
    private static function actionRequired(Order $order, string $client_secret): array
    {
        $order_id = (int) $order->id;
        // The pay link loads the order into checkout; with a client secret the fragment survives
        // the redirect and the Stripe checkout script runs stripe.handleNextAction() in place.
        $redirect = stackless_edd_order_pay_url($order_id);
        if ($client_secret !== '') {
            $redirect .= Gateway::confirmFragment('checkout', 'pi', $order_id, (string) $order->payment_key, $client_secret);
        }
        return ['result' => 'action_required', 'message' => ErrorMapper::message('authentication_required'), 'redirect' => $redirect];
    }
}
