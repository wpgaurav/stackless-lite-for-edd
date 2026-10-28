<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * PaymentIntent / SetupIntent engine shared by checkout, express checkout, renewals, the
 * verify endpoint, redirect returns and webhooks.
 *
 * Every state change goes through applyPaymentIntent()/applySetupIntent(), which re-read
 * the order under a lock, so the browser confirmation and the webhook can race safely.
 */
final class Payments
{
    public const META_INTENT = '_stackless_edd_stripe_intent_id';
    public const META_INTENTS = '_stackless_edd_stripe_intent_ids';
    public const META_SETUP_INTENT = '_stackless_edd_stripe_setup_intent_id';
    public const META_PM = '_stackless_edd_stripe_payment_method';
    public const META_PM_TYPE = '_stackless_edd_stripe_payment_method_type';
    public const META_MODE = '_stackless_edd_stripe_mode';
    public const META_CHARGE = '_stackless_edd_stripe_charge_id';
    public const META_CAPTURED = '_stackless_edd_stripe_captured';
    public const META_SAVE = '_stackless_edd_stripe_save_method';
    public const META_OFF_SESSION = '_stackless_edd_stripe_off_session';
    public const META_REFUNDS = '_stackless_edd_stripe_refund_ids';
    public const META_DISPUTE = '_stackless_edd_stripe_dispute_id';
    public const META_FLOW = '_stackless_edd_stripe_flow';
    public const META_AMOUNT_MISMATCH = '_stackless_edd_stripe_amount_mismatch';

    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_AUTHORIZED = 'authorized';
    public const OUTCOME_PROCESSING = 'processing';
    public const OUTCOME_SAVED = 'saved';
    public const OUTCOME_ACTION = 'requires_action';
    public const OUTCOME_FAILED = 'failed';
    /** Another request held the order lock the whole time; nothing was applied. */
    public const OUTCOME_BUSY = 'busy';
    /** The intent's amount or currency does not match the order; the order was put on hold. */
    public const OUTCOME_HELD = 'held';

    /**
     * Creates and confirms a PaymentIntent for an order.
     *
     * @param array{confirmation_token?:string, payment_method?:string, payment_method_type?:string, customer?:string} $source
     * @param array{save?:bool, off_session?:bool, capture?:bool, flow?:string, idempotency_key?:string, mode?:string, metadata?:array<string,string>} $options
     * @return array<string,mixed> PaymentIntent
     * @throws StripeApiException
     */
    public static function createPaymentIntent(\EDD\Orders\Order $order, array $source, array $options = []): array
    {
        $client = Settings::client($options['mode'] ?? null);
        $order_id = (int) $order->id;
        $save = !empty($options['save']);
        $off_session = !empty($options['off_session']);
        $capture = array_key_exists('capture', $options) ? (bool) $options['capture'] : true;
        $flow = (string) ($options['flow'] ?? 'checkout');
        $currency = strtoupper((string) $order->currency);

        if (!$off_session) {
            $previous = self::settlePreviousIntent($order, $client);
            if ($previous !== null) {
                return $previous;
            }
        }

        $params = [
            'amount' => Amount::toMinor((string) $order->total, $currency),
            'currency' => strtolower($currency),
            'confirm' => true,
            'capture_method' => $capture ? 'automatic' : 'manual',
            'description' => self::description($order),
            'metadata' => array_merge(self::metadata($order), $options['metadata'] ?? []),
            'expand' => ['payment_method', 'latest_charge'],
        ];
        $suffix = Settings::statementDescriptorSuffix();
        if ($suffix !== '') {
            $params['statement_descriptor_suffix'] = $suffix;
        }

        $customer = (string) ($source['customer'] ?? '');
        if ($customer === '' && ($save || (int) $order->user_id > 0)) {
            $customer = Customers::forOrder($order, $client, true);
        }
        if ($customer !== '') {
            $params['customer'] = $customer;
        }

        if (!empty($source['confirmation_token'])) {
            $params['confirmation_token'] = $source['confirmation_token'];
            $params['automatic_payment_methods'] = ['enabled' => true];
            $params['return_url'] = self::returnUrl($order, $flow);
        } elseif (!empty($source['payment_method'])) {
            $params['payment_method'] = $source['payment_method'];
            $params['payment_method_types'] = [$source['payment_method_type'] ?? 'card'];
            if (!$off_session) {
                $params['return_url'] = self::returnUrl($order, $flow);
            }
        } else {
            throw new StripeApiException(esc_html__('No payment method was provided.', 'stackless-lite-edd'), 400, ['type' => 'invalid_request_error', 'code' => 'payment_method_missing']);
        }

        if ($off_session) {
            $params['off_session'] = true;
        } elseif ($save) {
            $params['setup_future_usage'] = 'off_session';
        }

        Orders::updateMeta($order_id, self::META_MODE, $client->mode());
        Orders::updateMeta($order_id, self::META_SAVE, $save ? 'yes' : 'no');
        Orders::updateMeta($order_id, self::META_FLOW, $flow);
        if ($off_session) {
            Orders::updateMeta($order_id, self::META_OFF_SESSION, 'yes');
        } else {
            // A failed renewal paid by the customer from the pay link is an on-session payment now:
            // its new method is saved and becomes the subscription's (see Gateway::syncRenewalToken()).
            Orders::deleteMeta($order_id, self::META_OFF_SESSION);
        }
        if ($customer !== '') {
            Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }

        $key = $options['idempotency_key'] ?? IdempotencyKey::order($order_id, self::sourceFingerprint($source) . '|' . $params['amount'] . '|' . $params['currency']);

        try {
            $intent = $client->post('payment_intents', $params, $key);
        } catch (StripeApiException $e) {
            if ($e->isResourceMissing() && $e->getParam() === 'customer' && empty($source['customer'])) {
                // Customer deleted in the Stripe Dashboard: forget it and try once with a new one.
                Customers::forget((int) $order->user_id, $client->mode(), $customer);
                Orders::deleteMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER);
                $params['customer'] = Customers::forOrder($order, $client, true);
                Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $params['customer']);
                $intent = $client->post('payment_intents', $params, $key . '-c');
            } else {
                $failed = $e->getPaymentIntent();
                if ($failed) {
                    self::rememberIntent($order_id, $failed, $client->mode());
                }
                throw $e;
            }
        }

        self::rememberIntent($order_id, $intent, $client->mode());
        return $intent;
    }

    /**
     * Before a new attempt on the same order (EDD resumes the pending order on a retry): if an
     * earlier PaymentIntent already took the money (or is still processing it), return it instead
     * of charging again; if it is still waiting for the customer, cancel it so two attempts can
     * never both succeed.
     *
     * @return array<string,mixed>|null The earlier intent when it must be used instead.
     */
    private static function settlePreviousIntent(\EDD\Orders\Order $order, StripeClient $client): ?array
    {
        $order_id = (int) $order->id;
        $previous = (string) Orders::meta($order_id, self::META_INTENT);
        if ($previous === '' || (string) Orders::meta($order_id, self::META_MODE) !== $client->mode()) {
            return null;
        }
        try {
            $intent = $client->get('payment_intents/' . rawurlencode($previous), ['expand' => ['payment_method', 'latest_charge']]);
        } catch (StripeApiException $e) {
            return null;
        }
        $status = (string) ($intent['status'] ?? '');
        if (in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
            Settings::log(sprintf('Order %d already has %s in status %s; not charging again.', $order_id, $previous, $status), 'warning');
            return $intent;
        }
        if (in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            try {
                $client->post('payment_intents/' . rawurlencode($previous) . '/cancel', ['cancellation_reason' => 'abandoned'], 'stackless-edd-cancel-' . $previous);
            } catch (StripeApiException $e) {
                // Cancelling fails when the customer just completed it elsewhere: use that result.
                try {
                    $intent = $client->get('payment_intents/' . rawurlencode($previous), ['expand' => ['payment_method', 'latest_charge']]);
                    if (in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true)) {
                        return $intent;
                    }
                } catch (StripeApiException $ignored) {
                    // Fall through to a fresh attempt.
                }
                Settings::log('Could not cancel superseded intent ' . $previous . ': ' . $e->logSummary(), 'warning');
            }
        }
        return null;
    }

    /**
     * Creates and confirms a SetupIntent that saves a method for later off-session use.
     *
     * @param array{confirmation_token:string} $source
     * @param array{customer:string, return_url:string, metadata?:array<string,string>, idempotency_key?:string, mode?:string} $options
     * @return array<string,mixed> SetupIntent
     * @throws StripeApiException
     */
    public static function createSetupIntent(array $source, array $options): array
    {
        $client = Settings::client($options['mode'] ?? null);
        $params = [
            'confirm' => true,
            'confirmation_token' => $source['confirmation_token'],
            'customer' => $options['customer'],
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
            'return_url' => $options['return_url'],
            'metadata' => array_merge(['stackless_site' => Settings::siteMarker()], $options['metadata'] ?? []),
            'expand' => ['payment_method'],
        ];
        $key = $options['idempotency_key'] ?? IdempotencyKey::setup('si', $source['confirmation_token']);
        return $client->post('setup_intents', $params, $key);
    }

    /**
     * Records the intent on the order (current id, history, mode) without changing status.
     *
     * @param array<string,mixed> $intent
     */
    public static function rememberIntent(int $order_id, array $intent, string $mode): void
    {
        $id = (string) ($intent['id'] ?? '');
        if ($id === '') {
            return;
        }
        if (strpos($id, 'seti_') === 0) {
            Orders::updateMeta($order_id, self::META_SETUP_INTENT, $id);
        } else {
            Orders::updateMeta($order_id, self::META_INTENT, $id);
            $history = Orders::meta($order_id, self::META_INTENTS);
            $history = is_array($history) ? $history : [];
            if (!in_array($id, $history, true)) {
                $history[] = $id;
                Orders::updateMeta($order_id, self::META_INTENTS, array_slice($history, -20));
            }
        }
        Orders::updateMeta($order_id, self::META_MODE, $mode);
    }

    /** True when the intent id was created for this order. */
    public static function orderOwnsIntent(int $order_id, string $intent_id): bool
    {
        if ($intent_id === '') {
            return false;
        }
        if (Orders::meta($order_id, self::META_INTENT) === $intent_id || Orders::meta($order_id, self::META_SETUP_INTENT) === $intent_id) {
            return true;
        }
        $history = Orders::meta($order_id, self::META_INTENTS);
        return is_array($history) && in_array($intent_id, $history, true);
    }

    /**
     * Applies a PaymentIntent's current state to its order. Safe to call repeatedly.
     *
     * @param array<string,mixed> $intent
     */
    public static function applyPaymentIntent(\EDD\Orders\Order $order, array $intent, string $via): string
    {
        $order_id = (int) $order->id;
        $lock = self::lock($order_id);
        if ($lock === null) {
            Settings::log(sprintf('Order %d is locked by another request; %s via %s not applied.', $order_id, (string) ($intent['id'] ?? ''), $via), 'warning');
            return self::OUTCOME_BUSY;
        }
        try {
            $order = Orders::get($order_id) ?? $order;
            $intent_id = (string) ($intent['id'] ?? '');
            $status = (string) ($intent['status'] ?? '');
            $mode = !empty($intent['livemode']) ? 'live' : 'test';

            switch ($status) {
                case 'succeeded':
                    if (!Orders::awaitingPayment($order)) {
                        return self::OUTCOME_PAID;
                    }
                    if (!self::intentMatchesOrder($order, $intent)) {
                        return self::holdForMismatch($order, $intent, $via);
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    self::recordCharge($order_id, $intent);
                    Orders::updateMeta($order_id, self::META_CAPTURED, 'yes');
                    stackless_edd_complete_order(
                        $order_id,
                        $intent_id,
                        /* translators: 1: PaymentIntent id, 2: how the result arrived (checkout, webhook, renewal...) */
                        sprintf(__('Stripe payment succeeded (%1$s, via %2$s).', 'stackless-lite-edd'), $intent_id, $via)
                    );
                    Settings::log(sprintf('Order %d paid by %s via %s.', $order_id, $intent_id, $via), 'info');
                    return self::OUTCOME_PAID;

                case 'requires_capture':
                    if (Orders::meta($order_id, self::META_CAPTURED) === 'no' && Orders::hasStatus($order, 'on_hold')) {
                        return self::OUTCOME_AUTHORIZED;
                    }
                    if (!Orders::needsPayment($order)) {
                        return self::OUTCOME_AUTHORIZED;
                    }
                    if (!self::intentMatchesOrder($order, $intent)) {
                        return self::holdForMismatch($order, $intent, $via);
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    self::recordCharge($order_id, $intent);
                    Orders::addTransaction($order, $intent_id, 'pending');
                    Orders::updateMeta($order_id, self::META_CAPTURED, 'no');
                    Orders::setStatus($order_id, 'on_hold', sprintf(
                        /* translators: %s: PaymentIntent id */
                        __('Stripe authorized the payment (%s). Mark the order Completed to capture it; authorizations expire after 7 days.', 'stackless-lite-edd'),
                        $intent_id
                    ));
                    return self::OUTCOME_AUTHORIZED;

                case 'processing':
                    if (!Orders::needsPayment($order)) {
                        return Orders::hasStatus($order, ['on_hold', 'processing']) ? self::OUTCOME_PROCESSING : self::OUTCOME_PAID;
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    Orders::addTransaction($order, $intent_id, 'pending');
                    if (Orders::meta($order_id, self::META_OFF_SESSION) === 'yes') {
                        // Renewals stay pending so the Subscriptions module keeps its own state; the webhook completes them.
                        /* translators: %s: PaymentIntent id */
                        Orders::note($order_id, sprintf(__('Stripe is processing the renewal payment (%s). The order updates when the bank confirms.', 'stackless-lite-edd'), $intent_id));
                    } else {
                        /* translators: %s: PaymentIntent id */
                        Orders::setStatus($order_id, 'processing', sprintf(__('Stripe is processing the payment (%s). The order updates when the bank confirms it.', 'stackless-lite-edd'), $intent_id));
                    }
                    return self::OUTCOME_PROCESSING;

                case 'requires_action':
                case 'requires_confirmation':
                    return self::OUTCOME_ACTION;

                case 'canceled':
                    if (Orders::needsPayment($order) || Orders::hasStatus($order, ['on_hold', 'processing'])) {
                        /* translators: %s: PaymentIntent id */
                        Orders::note($order_id, sprintf(__('Stripe PaymentIntent %s was canceled.', 'stackless-lite-edd'), $intent_id));
                    }
                    return self::OUTCOME_FAILED;

                case 'requires_payment_method':
                default:
                    $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
                    if ($error && Orders::awaitingPayment($order)) {
                        $message = ErrorMapper::fromIntentError($error);
                        /* translators: 1: PaymentIntent id, 2: customer-facing reason */
                        $note = sprintf(__('Stripe payment failed (%1$s): %2$s', 'stackless-lite-edd'), $intent_id, $message);
                        if (Orders::hasStatus($order, ['on_hold', 'processing']) || Orders::meta($order_id, self::META_OFF_SESSION) === 'yes') {
                            Orders::setStatus($order_id, 'failed', $note);
                        } else {
                            // Checkout attempts stay pending so EDD resumes the same order on the next try.
                            Orders::note($order_id, $note);
                        }
                    }
                    return self::OUTCOME_FAILED;
            }
        } finally {
            self::unlock($order_id, $lock);
        }
    }

    /**
     * True when the intent charges the order's current total in the order's currency. The order
     * can change after the intent was created (an admin edit or a cart change while the customer
     * is in 3D Secure), and the intent then pays for the old amount.
     *
     * @param array<string,mixed> $intent
     */
    public static function intentMatchesOrder(\EDD\Orders\Order $order, array $intent): bool
    {
        $currency = strtoupper((string) $order->currency);
        if (strtolower((string) ($intent['currency'] ?? '')) !== strtolower($currency)) {
            return false;
        }
        try {
            return (int) ($intent['amount'] ?? -1) === Amount::toMinor((string) $order->total, $currency);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Leaves the order unpaid and on hold for a person to check, noting it once per intent.
     *
     * @param array<string,mixed> $intent
     */
    private static function holdForMismatch(\EDD\Orders\Order $order, array $intent, string $via): string
    {
        $order_id = (int) $order->id;
        $intent_id = (string) ($intent['id'] ?? '');
        if ((string) Orders::meta($order_id, self::META_AMOUNT_MISMATCH) === $intent_id) {
            return self::OUTCOME_HELD;
        }
        $intent_currency = strtoupper((string) ($intent['currency'] ?? ''));
        $charged = $intent_currency !== ''
            ? Amount::fromMinor((int) ($intent['amount'] ?? 0), $intent_currency) . ' ' . $intent_currency
            : '?';
        Orders::updateMeta($order_id, self::META_AMOUNT_MISMATCH, $intent_id);
        Orders::addTransaction($order, $intent_id, 'pending');
        Orders::setStatus($order_id, 'on_hold', sprintf(
            /* translators: 1: PaymentIntent id, 2: amount on the intent, 3: order total */
            __('Stripe PaymentIntent %1$s is for %2$s but the order total is now %3$s. The order was not marked paid; check the payment in Stripe before fulfilling.', 'stackless-lite-edd'),
            $intent_id,
            $charged,
            (string) $order->total . ' ' . strtoupper((string) $order->currency)
        ));
        Settings::log(sprintf('Order %d: %s amount or currency does not match the order (via %s); put on hold.', $order_id, $intent_id, $via), 'warning');
        return self::OUTCOME_HELD;
    }

    /**
     * Applies a SetupIntent to a zero-total order: saves the method, then completes the order.
     *
     * @param array<string,mixed> $setup_intent
     */
    public static function applySetupIntent(\EDD\Orders\Order $order, array $setup_intent, string $via): string
    {
        $order_id = (int) $order->id;
        $lock = self::lock($order_id);
        if ($lock === null) {
            Settings::log(sprintf('Order %d is locked by another request; %s via %s not applied.', $order_id, (string) ($setup_intent['id'] ?? ''), $via), 'warning');
            return self::OUTCOME_BUSY;
        }
        try {
            $order = Orders::get($order_id) ?? $order;
            $status = (string) ($setup_intent['status'] ?? '');
            $id = (string) ($setup_intent['id'] ?? '');
            $mode = !empty($setup_intent['livemode']) ? 'live' : 'test';

            if ($status === 'succeeded' || $status === 'processing') {
                if (!Orders::awaitingPayment($order)) {
                    return self::OUTCOME_SAVED;
                }
                $token = self::saveMethodForOrder($order, $setup_intent['payment_method'] ?? '', (string) ($setup_intent['customer'] ?? ''), $mode);
                if (!$token && !Orders::meta($order_id, self::META_PM)) {
                    Orders::note($order_id, __('Stripe confirmed the setup but the payment method could not be saved.', 'stackless-lite-edd'));
                    return self::OUTCOME_FAILED;
                }
                if ($status === 'processing') {
                    if (!Orders::hasStatus($order, 'processing')) {
                        /* translators: 1: SetupIntent id, 2: how the result arrived */
                        Orders::note($order_id, sprintf(__('Payment method saved for future payments (%1$s, via %2$s).', 'stackless-lite-edd'), $id, $via));
                        Orders::setStatus($order_id, 'processing', __('Stripe is verifying the saved payment method.', 'stackless-lite-edd'));
                    }
                    return self::OUTCOME_PROCESSING;
                }
                /* translators: 1: SetupIntent id, 2: how the result arrived */
                stackless_edd_complete_order($order_id, '', sprintf(__('Payment method saved for future payments (%1$s, via %2$s).', 'stackless-lite-edd'), $id, $via));
                return self::OUTCOME_SAVED;
            }
            if ($status === 'requires_action' || $status === 'requires_confirmation') {
                return self::OUTCOME_ACTION;
            }
            $error = isset($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error']) ? $setup_intent['last_setup_error'] : [];
            if ($error) {
                /* translators: 1: SetupIntent id, 2: reason */
                Orders::note($order_id, sprintf(__('Saving the payment method failed (%1$s): %2$s', 'stackless-lite-edd'), $id, ErrorMapper::fromIntentError($error)));
            }
            return self::OUTCOME_FAILED;
        } finally {
            self::unlock($order_id, $lock);
        }
    }

    /**
     * Saves the intent's payment method when the order asked for it (subscription or the
     * customer ticked "save"), and writes the token + customer meta the Subscriptions module reads.
     *
     * @param array<string,mixed> $intent
     */
    public static function maybeSaveMethod(\EDD\Orders\Order $order, array $intent, string $mode): ?PaymentToken
    {
        $order_id = (int) $order->id;
        $wanted = Orders::meta($order_id, self::META_SAVE) === 'yes' || stackless_edd_order_requires_saved_payment_method($order_id);
        if (!$wanted || Orders::meta($order_id, self::META_OFF_SESSION) === 'yes') {
            return null;
        }
        $pm = $intent['payment_method'] ?? '';
        $pm_id = is_array($pm) ? (string) ($pm['id'] ?? '') : (string) $pm;
        $existing = Tokens::get((int) Orders::meta($order_id, SubscriptionData::META_TOKEN_ID));
        if ($existing && ($pm_id === '' || $existing->token === $pm_id)) {
            return $existing;
        }
        return self::saveMethodForOrder($order, $pm, (string) ($intent['customer'] ?? ''), $mode);
    }

    /**
     * @param array<string,mixed>|string $payment_method Expanded PaymentMethod or its id.
     */
    public static function saveMethodForOrder(\EDD\Orders\Order $order, $payment_method, string $customer, string $mode): ?PaymentToken
    {
        $order_id = (int) $order->id;
        $client = Settings::client($mode);
        if (is_string($payment_method)) {
            if ($payment_method === '') {
                return null;
            }
            try {
                $payment_method = $client->get('payment_methods/' . rawurlencode($payment_method));
            } catch (StripeApiException $e) {
                Settings::log('Could not load payment method to save it: ' . $e->logSummary(), 'warning');
                return null;
            }
        }
        if (!is_array($payment_method) || empty($payment_method['id'])) {
            return null;
        }
        if ($customer === '' && !empty($payment_method['customer'])) {
            $customer = is_array($payment_method['customer']) ? (string) ($payment_method['customer']['id'] ?? '') : (string) $payment_method['customer'];
        }

        Orders::updateMeta($order_id, self::META_PM, (string) $payment_method['id']);
        if ($customer !== '') {
            Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $token = Tokens::saveFromPaymentMethod($payment_method, (int) $order->user_id, $customer, $mode, (int) $order->customer_id);
        if ($token) {
            Orders::updateMeta($order_id, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        }
        return $token;
    }

    /**
     * Writes the charge id and method type when the intent carries an expanded latest_charge.
     *
     * @param array<string,mixed> $intent
     */
    private static function recordCharge(int $order_id, array $intent): void
    {
        $charge = $intent['latest_charge'] ?? '';
        $charge_id = is_array($charge) ? (string) ($charge['id'] ?? '') : (string) $charge;
        if ($charge_id !== '') {
            Orders::updateMeta($order_id, self::META_CHARGE, $charge_id);
        }
        $pm = $intent['payment_method'] ?? null;
        if (is_array($pm) && !empty($pm['id'])) {
            Orders::updateMeta($order_id, self::META_PM, (string) $pm['id']);
            $type = (string) ($pm['type'] ?? '');
            if ($type === 'card' && !empty($pm['card']['wallet']['type'])) {
                $type = (string) $pm['card']['wallet']['type'];
            }
            if ($type !== '') {
                Orders::updateMeta($order_id, self::META_PM_TYPE, $type);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Capture / void (authorize-only mode)
     * ------------------------------------------------------------------- */

    public static function capture(\EDD\Orders\Order $order): bool
    {
        $order_id = (int) $order->id;
        if ((string) $order->gateway !== Settings::GATEWAY_ID || Orders::meta($order_id, self::META_CAPTURED) !== 'no') {
            return false;
        }
        $intent_id = (string) Orders::meta($order_id, self::META_INTENT);
        if ($intent_id === '') {
            return false;
        }
        $client = Settings::clientForOrder($order_id);
        $currency = strtoupper((string) $order->currency);
        try {
            $intent = $client->get('payment_intents/' . rawurlencode($intent_id));
            if (($intent['status'] ?? '') !== 'requires_capture') {
                Orders::updateMeta($order_id, self::META_CAPTURED, ($intent['status'] ?? '') === 'succeeded' ? 'yes' : 'no');
                return ($intent['status'] ?? '') === 'succeeded';
            }
            $capturable = (int) ($intent['amount_capturable'] ?? 0);
            $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($order_id) : (float) $order->total;
            $amount = min($capturable, Amount::toMinor(max(0, $remaining), $currency));
            $params = $amount > 0 && $amount < $capturable ? ['amount_to_capture' => $amount] : [];
            $client->post('payment_intents/' . rawurlencode($intent_id) . '/capture', $params, 'stackless-edd-capture-' . $order_id . '-' . $intent_id);
            Orders::updateMeta($order_id, self::META_CAPTURED, 'yes');
            Orders::addTransaction($order, $intent_id, 'complete');
            /* translators: %s: formatted amount */
            Orders::note($order_id, sprintf(__('Stripe charge captured: %s.', 'stackless-lite-edd'), wp_strip_all_tags(stackless_edd_price(Amount::fromMinor($amount > 0 ? $amount : $capturable, $currency), $currency))));
            return true;
        } catch (StripeApiException $e) {
            /* translators: %s: error message */
            Orders::note($order_id, sprintf(__('Stripe capture failed: %s', 'stackless-lite-edd'), $e->getMessage()));
            Settings::log('Capture failed for order ' . $order_id . ': ' . $e->logSummary(), 'error');
            return false;
        }
    }

    public static function voidAuthorization(\EDD\Orders\Order $order): bool
    {
        $order_id = (int) $order->id;
        if ((string) $order->gateway !== Settings::GATEWAY_ID || Orders::meta($order_id, self::META_CAPTURED) !== 'no') {
            return false;
        }
        $intent_id = (string) Orders::meta($order_id, self::META_INTENT);
        if ($intent_id === '') {
            return false;
        }
        try {
            Settings::clientForOrder($order_id)->post('payment_intents/' . rawurlencode($intent_id) . '/cancel', ['cancellation_reason' => 'requested_by_customer'], 'stackless-edd-void-' . $intent_id);
            Orders::updateMeta($order_id, self::META_CAPTURED, 'void');
            Orders::note($order_id, __('Stripe authorization released.', 'stackless-lite-edd'));
            return true;
        } catch (StripeApiException $e) {
            /* translators: %s: error message */
            Orders::note($order_id, sprintf(__('Releasing the Stripe authorization failed: %s', 'stackless-lite-edd'), $e->getMessage()));
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /** Stripe's return_url after a full-page redirect (bank redirects, some wallets). */
    public static function returnUrl(\EDD\Orders\Order $order, string $flow): string
    {
        return add_query_arg([
            Endpoints::RETURN_ARG => '1',
            'order_id' => (int) $order->id,
            'key' => (string) $order->payment_key,
            'flow' => sanitize_key($flow),
        ], home_url('/'));
    }

    public static function description(\EDD\Orders\Order $order): string
    {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: 1: site name, 2: order number */
        return substr(sprintf(__('%1$s - Order %2$s', 'stackless-lite-edd'), $site, Orders::number($order)), 0, 1000);
    }

    /** @return array<string,string> */
    public static function metadata(\EDD\Orders\Order $order): array
    {
        return [
            'order_id' => (string) $order->id,
            'order_number' => Orders::number($order),
            'stackless_site' => Settings::siteMarker(),
            'site_url' => home_url('/'),
        ];
    }

    /** @param array<string,mixed> $source */
    private static function sourceFingerprint(array $source): string
    {
        return (string) ($source['confirmation_token'] ?? $source['payment_method'] ?? '');
    }

    /**
     * Per-order lock in the options table: an INSERT IGNORE on the unique option_name, so exactly
     * one request wins (add_option() is not atomic: it upserts). Waits up to ~5 seconds for a
     * concurrent holder and takes over locks older than 60 seconds.
     *
     * @return string|null Owner token for unlock(), or null when another request still holds it.
     */
    public static function lock(int $order_id): ?string
    {
        global $wpdb;
        $name = 'stackless_edd_stripe_lock_' . $order_id;
        $value = time() . ':' . bin2hex(random_bytes(10));
        for ($i = 0; $i < 20; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value))) {
                return $value;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $since = (int) $current;
            if ($since > 0 && time() - $since > 60) {
                // Abandoned by a request that died; take it over only if nobody else did first.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ($wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current))) {
                    return $value;
                }
            }
            usleep(250000);
        }
        return null;
    }

    /** Releases the order lock, but only while $token still owns it. */
    public static function unlock(int $order_id, string $token): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => 'stackless_edd_stripe_lock_' . $order_id, 'option_value' => $token]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases this request's lock only while it still holds the token.
    }
}
