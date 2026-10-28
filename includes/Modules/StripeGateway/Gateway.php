<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;

defined('ABSPATH') || exit;

/**
 * Stripe payment gateway for Easy Digital Downloads: Payment Element, saved methods, SCA,
 * refunds, authorize-and-capture and off-session subscription renewals.
 *
 * Card data never reaches the server. The browser turns the Payment Element into a
 * ConfirmationToken (ctoken_...) when the shopper clicks Purchase, puts it in a hidden field and
 * lets EDD submit its purchase form; the server creates and confirms the PaymentIntent or
 * SetupIntent with it ("finalize payments on the server"). When the bank asks for 3D Secure or a
 * redirect, the shopper is sent back to checkout with a #stackless-edd-stripe-confirm/... fragment
 * that the checkout script hands to stripe.handleNextAction() and then verifies server-side.
 *
 * EDD's own Stripe gateway (id "stripe") is a separate gateway with its own keys, customers and
 * webhooks; both can be enabled at the same time.
 */
class Gateway extends \StacklessEDD\Core\Gateway implements SubscriptionGateway
{
    /** Contract features this gateway supports (everything except gateway_managed). */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public const FIELD_CONFIRMATION_TOKEN = 'stackless_edd_stripe_confirmation_token';
    public const FIELD_TOKEN = 'stackless_edd_stripe_payment_token';
    public const FIELD_SAVE = 'stackless_edd_stripe_save_method';
    public const FIELD_EXPRESS = 'stackless_edd_stripe_express';
    /** Posted by the fields printed for a zero-total cart that must save a method (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_stripe_setup';
    public const USER_META_PENDING_SETUP = '_stackless_edd_stripe_pending_setup_intent';
    public const CONFIRM_PREFIX = '#stackless-edd-stripe-confirm/';

    /** @var bool True while an express (wallet) checkout is being processed: answers are JSON. */
    private $express = false;

    public function id(): string
    {
        return Settings::GATEWAY_ID;
    }

    public function adminLabel(): string
    {
        return __('Stripe (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        return Settings::title();
    }

    public function isAvailable(): bool
    {
        if (!Settings::isConfigured()) {
            return false;
        }
        $context = $this->amountContext();
        if (!Amount::isSupported($context['currency'])) {
            return false;
        }
        if ($context['total'] > 0 && !Amount::meetsMinimum($context['total'], $context['currency'])) {
            return false;
        }
        return true;
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Amount::SUPPORTED;
    }

    /** Stripe Dashboard link for a PaymentIntent, in the mode the order was paid in. */
    public static function transactionUrl(int $order_id, string $intent_id): string
    {
        $mode = (string) Orders::meta($order_id, Payments::META_MODE);
        $live = $mode === 'live' || ($mode === '' && !Settings::isTestMode());
        return ($live ? 'https://dashboard.stripe.com/payments/' : 'https://dashboard.stripe.com/test/payments/') . rawurlencode($intent_id);
    }

    /* ---------------------------------------------------------------------
     * Checkout fields
     * ------------------------------------------------------------------- */

    public function fields(): void
    {
        $context = $this->fieldContext();
        $user_id = get_current_user_id();
        $tokens = ($user_id > 0 && (Settings::savedCardsEnabled() || $context['requires_save'])) ? Tokens::forUser($user_id) : [];
        Assets::enqueueCheckout();
        stackless_edd_get_template('stripe_gateway/payment-fields.php', [
            'gateway' => $this,
            'context' => $context,
            'description' => Settings::description(),
            'test_mode' => Settings::isTestMode(),
            'tokens' => $tokens,
            'show_save' => !$context['requires_save'] && Settings::savedCardsEnabled() && $user_id > 0,
        ]);
    }

    /**
     * What the Payment Element needs on this page: intent mode, amount and currency.
     *
     * @return array{flow:string, mode:string, amount:int, currency:string, requires_save:bool, capture:string}
     */
    public function fieldContext(): array
    {
        $currency = stackless_edd_current_currency();
        $total = function_exists('edd_get_cart_total') ? (float) edd_get_cart_total() : 0.0;
        $requires_save = stackless_edd_cart_requires_saved_payment_method();
        return [
            'flow' => 'checkout',
            'mode' => $total > 0 ? 'payment' : 'setup',
            'amount' => $total > 0 && Amount::isSupported($currency) ? Amount::toMinor($total, $currency) : 0,
            'currency' => $currency,
            'requires_save' => $requires_save,
            'capture' => ($requires_save || Settings::captureNow()) ? 'automatic' : 'manual',
        ];
    }

    /**
     * EDD checks this before sending the purchase to the gateway (edd_checkout_error_checks).
     *
     * @param mixed $valid_data
     * @param mixed $post
     */
    public function validateFields($valid_data, $post): void
    {
        $post = is_array($post) ? $post : [];
        $gateway = is_array($valid_data) ? (string) ($valid_data['gateway'] ?? '') : '';
        if ($gateway !== $this->id() && empty($post[self::FIELD_SETUP])) {
            return;
        }
        $request = self::requestFrom($post);
        if ($request['token_id'] > 0 || $request['confirmation_token'] !== '') {
            return;
        }
        edd_set_error('stackless_edd_stripe_details', __('Enter your payment details to continue.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
    }

    /* ---------------------------------------------------------------------
     * Payments
     * ------------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form; handle() checked the gateway nonce.
        $post = wp_unslash($_POST);
        $request = self::requestFrom(is_array($post) ? $post : []);
        $this->express = $request['flow'] === 'express';
        $order_id = $this->createPendingOrder($purchase_data);
        if ($order_id < 1) {
            return;
        }
        $this->respond($this->payOrder($order_id, $request));
    }

    /**
     * Pay an EDD order with what the shopper submitted. Returns the WooCommerce-style result array
     * (result success|failure, redirect, message) so checkout, express checkout and the staging
     * scripts share one code path.
     *
     * @param array{token_id?:int, confirmation_token?:string, save?:bool, flow?:string} $request
     * @return array<string,string>
     */
    public function payOrder(int $order_id, array $request): array
    {
        $order = Orders::get($order_id);
        if (!$order) {
            return $this->failure(__('Order not found.', 'stackless-lite-edd'));
        }

        $flow = (string) ($request['flow'] ?? 'checkout');
        $requires_save = stackless_edd_order_requires_saved_payment_method($order_id);
        $save = $requires_save || (!empty($request['save']) && Settings::savedCardsEnabled() && (int) $order->user_id > 0);
        $token_id = (int) ($request['token_id'] ?? 0);
        $confirmation_token = self::cleanConfirmationToken((string) ($request['confirmation_token'] ?? ''));
        $total = (float) $order->total;
        $capture = $requires_save || Settings::captureNow();

        try {
            if ($token_id > 0) {
                $token = Tokens::getOwned($token_id, (int) $order->user_id);
                if (!$token || $token->mode !== Settings::mode()) {
                    return $this->failure(__('That saved payment method is not available. Choose another one.', 'stackless-lite-edd'));
                }
                Orders::updateMeta($order_id, SubscriptionData::META_TOKEN_ID, (string) $token->id);
                Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $token->stripe_customer);
                Orders::updateMeta($order_id, Payments::META_PM, $token->token);
                Orders::updateMeta($order_id, Payments::META_MODE, $token->mode);

                if ($total <= 0) {
                    stackless_edd_complete_order($order_id, '', __('Saved Stripe payment method attached for future payments.', 'stackless-lite-edd'));
                    return $this->success($order);
                }

                $intent = Payments::createPaymentIntent($order, [
                    'payment_method' => $token->token,
                    'payment_method_type' => $token->type,
                    'customer' => $token->stripe_customer,
                ], ['save' => false, 'capture' => $capture, 'flow' => $flow, 'mode' => $token->mode]);
                return $this->handlePaymentIntent($order, $intent, $flow);
            }

            if ($total <= 0 && !$requires_save && !$save) {
                stackless_edd_complete_order($order_id);
                return $this->success($order);
            }

            if ($confirmation_token === '') {
                return $this->failure(__('Enter your payment details to continue.', 'stackless-lite-edd'));
            }

            if ($total <= 0) {
                $client = Settings::client();
                $customer = Customers::forOrder($order, $client, true);
                Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
                Orders::updateMeta($order_id, Payments::META_MODE, $client->mode());
                Orders::updateMeta($order_id, Payments::META_SAVE, 'yes');
                Orders::updateMeta($order_id, Payments::META_FLOW, $flow);
                $setup_intent = Payments::createSetupIntent(['confirmation_token' => $confirmation_token], [
                    'customer' => $customer,
                    'return_url' => Payments::returnUrl($order, $flow),
                    'metadata' => array_merge(Payments::metadata($order), ['stackless_flow' => 'order']),
                    'idempotency_key' => IdempotencyKey::setup('order-' . $order_id, $confirmation_token),
                ]);
                Payments::rememberIntent($order_id, $setup_intent, $client->mode());
                return $this->handleSetupIntent($order, $setup_intent, $flow);
            }

            if (!Amount::meetsMinimum((string) $order->total, (string) $order->currency)) {
                return $this->failure(ErrorMapper::message('amount_too_small'));
            }

            $intent = Payments::createPaymentIntent($order, ['confirmation_token' => $confirmation_token], [
                'save' => $save,
                'capture' => $capture,
                'flow' => $flow,
            ]);
            return $this->handlePaymentIntent($order, $intent, $flow);
        } catch (StripeApiException $e) {
            $message = ErrorMapper::fromException($e);
            /* translators: %s: error message */
            Orders::note($order_id, sprintf(__('Stripe payment attempt failed: %s', 'stackless-lite-edd'), $message . ($e->getDeclineCode() ? ' (' . $e->getDeclineCode() . ')' : '')));
            Settings::log('Checkout payment failed for order ' . $order_id . ': ' . $e->logSummary(), $e->getStripeType() === 'card_error' ? 'info' : 'error');
            return $this->failure($message);
        } catch (\InvalidArgumentException $e) {
            return $this->failure(ErrorMapper::generic());
        }
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<string,string>
     */
    private function handlePaymentIntent(\EDD\Orders\Order $order, array $intent, string $flow): array
    {
        $outcome = Payments::applyPaymentIntent($order, $intent, $flow);
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
            case Payments::OUTCOME_AUTHORIZED:
            case Payments::OUTCOME_PROCESSING:
                $this->syncRenewalToken((int) $order->id);
                return $this->success($order);
            case Payments::OUTCOME_HELD:
                // Stripe took the money; the order waits on hold for the merchant.
                return $this->success($order);
            case Payments::OUTCOME_ACTION:
                return $this->actionResult($order, 'pi', (string) ($intent['client_secret'] ?? ''), $flow);
            case Payments::OUTCOME_BUSY:
                // The webhook is applying this intent right now; a settled one lands the customer on the receipt.
                if (in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true)) {
                    return $this->success($order);
                }
                break;
        }
        $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
        return $this->failure($error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
    }

    /**
     * @param array<string,mixed> $setup_intent
     * @return array<string,string>
     */
    private function handleSetupIntent(\EDD\Orders\Order $order, array $setup_intent, string $flow): array
    {
        $outcome = Payments::applySetupIntent($order, $setup_intent, $flow);
        if ($outcome === Payments::OUTCOME_SAVED || $outcome === Payments::OUTCOME_PROCESSING) {
            return $this->success($order);
        }
        if ($outcome === Payments::OUTCOME_BUSY && in_array((string) ($setup_intent['status'] ?? ''), ['succeeded', 'processing'], true)) {
            return $this->success($order);
        }
        if ($outcome === Payments::OUTCOME_ACTION) {
            return $this->actionResult($order, 'si', (string) ($setup_intent['client_secret'] ?? ''), $flow);
        }
        $error = isset($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error']) ? $setup_intent['last_setup_error'] : [];
        return $this->failure($error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
    }

    /**
     * Finish the request for a payOrder() result: receipt, 3D Secure fragment, or back to checkout.
     * Express (wallet) purchases answer with JSON instead.
     *
     * @param array<string,string> $result
     */
    private function respond(array $result): void
    {
        $success = ($result['result'] ?? '') === 'success';
        if ($success && empty($result['stackless_edd_stripe_action'])) {
            // Paid (or on its way): the cart is done. A 3D Secure step keeps it until verified.
            edd_empty_cart();
        }
        if ($this->express) {
            wp_send_json($result);
        }
        if ($success) {
            edd_redirect((string) $result['redirect']);
            edd_die();
        }
        $this->fail((string) ($result['message'] ?? ErrorMapper::generic()));
    }

    /**
     * Show an error and go back. The order stays pending (EDD resumes it on the next attempt), so the
     * superseded PaymentIntent is found and settled instead of charging twice.
     */
    protected function fail(string $message, int $order_id = 0): void
    {
        if ($this->express) {
            wp_send_json(['result' => 'failure', 'message' => $message]);
        }
        parent::fail($message, $order_id);
    }

    protected function back(): void
    {
        if ($this->express) {
            $errors = function_exists('edd_get_errors') ? (array) edd_get_errors() : [];
            edd_clear_errors();
            wp_send_json(['result' => 'failure', 'message' => $errors ? wp_strip_all_tags(implode(' ', array_map('strval', $errors))) : ErrorMapper::generic()]);
        }
        parent::back();
    }

    /* ---------------------------------------------------------------------
     * Zero-total carts that must save a method (all-trial subscriptions)
     * ------------------------------------------------------------------- */

    /**
     * EDD hides gateways and switches to "manual" when the cart total is zero. A cart whose
     * subscription renews automatically still needs a saved method, so offer the gateways then.
     *
     * @param mixed $show
     */
    public function filterShowGateways($show): bool
    {
        if (!$show && $this->zeroTotalNeedsMethod() && count((array) edd_get_enabled_payment_gateways()) > 1) {
            return true;
        }
        return (bool) $show;
    }

    /**
     * @param mixed $gateway
     * @param mixed $chosen
     * @return mixed
     */
    public function filterChosenGateway($gateway, $chosen = false)
    {
        if ($gateway !== 'manual' || !$this->zeroTotalNeedsMethod()) {
            return $gateway;
        }
        $wanted = is_string($chosen) && $chosen !== '' ? $chosen : (function_exists('edd_get_default_gateway') ? edd_get_default_gateway() : '');
        return $wanted === $this->id() && edd_is_gateway_active($this->id()) ? $this->id() : $gateway;
    }

    /** Prints the fields for a zero-total cart (EDD skips the payment form when nothing is due). */
    public function zeroTotalFields(): void
    {
        if (!$this->zeroTotalNeedsMethod() || edd_get_chosen_gateway() !== $this->id()) {
            return;
        }
        echo '<input type="hidden" name="' . esc_attr(self::FIELD_SETUP) . '" value="1">';
        $this->fields();
    }

    /**
     * EDD sends a zero-total purchase to "manual". When our fields were used, take it over so the
     * method is saved before the order completes.
     *
     * @param mixed $purchase_data
     */
    public function interceptManual($purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form.
        if (empty($_POST[self::FIELD_SETUP]) || !stackless_edd_cart_requires_saved_payment_method() || !$this->isEnabled()) {
            return;
        }
        $purchase_data = is_array($purchase_data) ? $purchase_data : [];
        $purchase_data['gateway'] = $this->id();
        $this->handle($purchase_data);
        edd_die();
    }

    private function zeroTotalNeedsMethod(): bool
    {
        return function_exists('edd_get_cart_total') && (float) edd_get_cart_total() <= 0
            && (function_exists('edd_get_cart_contents') && edd_get_cart_contents())
            && stackless_edd_cart_requires_saved_payment_method();
    }

    /* ---------------------------------------------------------------------
     * Saved methods: account page additions and subscription method changes
     * ------------------------------------------------------------------- */

    /**
     * Saves the SetupIntent's method and points the subscription at it.
     *
     * @param array<string,mixed> $setup_intent
     */
    public function completeSubscriptionSetup(SubscriptionData $subscription, array $setup_intent): bool
    {
        $mode = !empty($setup_intent['livemode']) ? 'live' : 'test';
        $pm = $setup_intent['payment_method'] ?? '';
        if (is_string($pm) && $pm !== '') {
            $pm = Settings::client($mode)->get('payment_methods/' . rawurlencode($pm));
        }
        if (!is_array($pm)) {
            return false;
        }
        $token = Tokens::saveFromPaymentMethod($pm, $subscription->get_user_id(), (string) ($setup_intent['customer'] ?? ''), $mode, $subscription->get_customer_id());
        if (!$token) {
            return false;
        }
        $this->updateSubscriptionMethod($subscription, $token);
        return true;
    }

    /**
     * Points a subscription (and its unpaid renewals waiting in dunning) at a saved token.
     * Only subscriptions renewing through this gateway; moving one from another gateway is the
     * Subscriptions module's job (it listens to stackless_edd_payment_method_saved).
     */
    public function updateSubscriptionMethod(SubscriptionData $subscription, PaymentToken $token): bool
    {
        if ($subscription->get_gateway() !== $this->id()) {
            return false;
        }
        $subscription->update_meta(SubscriptionData::META_TOKEN_ID, (string) $token->id);
        $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $token->stripe_customer);
        $subscription->update_meta(Payments::META_PM, $token->token);
        $subscription->update_meta(Payments::META_MODE, $token->mode);
        foreach (stackless_edd_get_subscription_orders($subscription->get_id()) as $order) {
            if ($order instanceof \EDD\Orders\Order && (string) $order->gateway === $this->id() && Orders::needsPayment($order) && stackless_edd_is_renewal_order((int) $order->id)) {
                Orders::updateMeta((int) $order->id, SubscriptionData::META_TOKEN_ID, (string) $token->id);
                Orders::updateMeta((int) $order->id, SubscriptionData::META_GATEWAY_CUSTOMER, $token->stripe_customer);
                Orders::updateMeta((int) $order->id, Payments::META_PM, $token->token);
            }
        }
        $parent = $subscription->get_parent_order_id();
        if ($parent > 0) {
            /* translators: 1: subscription id, 2: payment method name, e.g. "Visa ending in 4242" */
            Orders::note($parent, sprintf(__('Subscription #%1$d now renews with %2$s.', 'stackless-lite-edd'), $subscription->get_id(), $token->displayName()));
        }
        /**
         * The payment method a subscription renews with changed.
         *
         * @param SubscriptionData $subscription
         * @param int              $token_id
         */
        do_action('stackless_edd_stripe_gateway_subscription_payment_method_updated', $subscription, $token->id);
        return true;
    }

    /**
     * A customer who pays a failed renewal from the pay link with a different saved or new
     * method expects future renewals to use it: point the renewed subscriptions at that token.
     */
    public function syncRenewalToken(int $order_id): void
    {
        if (!stackless_edd_is_renewal_order($order_id)) {
            return;
        }
        $token = Tokens::get((int) Orders::meta($order_id, SubscriptionData::META_TOKEN_ID));
        if (!$token) {
            return;
        }
        foreach (stackless_edd_get_order_subscriptions($order_id) as $subscription) {
            if ($subscription instanceof SubscriptionData && (int) $subscription->get_meta(SubscriptionData::META_TOKEN_ID) !== $token->id) {
                $this->updateSubscriptionMethod($subscription, $token);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Authorize-and-capture follows the order status
     * ------------------------------------------------------------------- */

    /**
     * On hold → Completed captures an authorization; On hold → Failed/Revoked/Abandoned releases it.
     *
     * @param mixed $old_status
     * @param mixed $new_status
     * @param mixed $order_id
     */
    public function onStatusChange($old_status, $new_status, $order_id): void
    {
        if ((string) $old_status !== 'on_hold') {
            return;
        }
        $order = Orders::get((int) $order_id);
        if (!$order || (string) $order->gateway !== $this->id() || Orders::meta((int) $order->id, Payments::META_CAPTURED) !== 'no') {
            return;
        }
        if ((string) $new_status === 'complete') {
            Payments::capture($order);
        } elseif (in_array((string) $new_status, ['failed', 'revoked', 'abandoned'], true)) {
            Payments::voidAuthorization($order);
        }
    }

    /* ---------------------------------------------------------------------
     * Contracts\SubscriptionGateway
     * ------------------------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * @return array{result:string, message?:string}
     */
    public function stackless_edd_charge_renewal(\EDD\Orders\Order $renewal_order, SubscriptionData $subscription): array
    {
        return Renewals::charge($renewal_order, $subscription);
    }

    /** Stripe does not run the schedule (the Subscriptions module does), so nothing to cancel remotely. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }

    /* ---------------------------------------------------------------------
     * Request helpers
     * ------------------------------------------------------------------- */

    /** Fragment the front-end scripts turn into stripe.handleNextAction() + server verification. */
    public static function confirmFragment(string $flow, string $type, int $order_id, string $key, string $client_secret): string
    {
        return self::CONFIRM_PREFIX . rawurlencode($flow) . '/' . rawurlencode($type) . '/' . $order_id . '/' . rawurlencode($key) . '/' . rawurlencode($client_secret);
    }

    /** @return array<string,string> */
    private function actionResult(\EDD\Orders\Order $order, string $type, string $client_secret, string $flow): array
    {
        if ($client_secret === '') {
            return $this->failure(ErrorMapper::generic());
        }
        $fragment = self::confirmFragment($flow, $type, (int) $order->id, (string) $order->payment_key, $client_secret);
        return [
            'result' => 'success',
            'redirect' => edd_get_checkout_uri() . $fragment,
            'stackless_edd_stripe_action' => 'confirm',
            'stackless_edd_stripe_flow' => $flow,
            'stackless_edd_stripe_intent_type' => $type,
            'stackless_edd_stripe_client_secret' => $client_secret,
            'stackless_edd_stripe_order_id' => (string) $order->id,
            'stackless_edd_stripe_order_key' => (string) $order->payment_key,
        ];
    }

    /** @return array<string,string> */
    private function success(\EDD\Orders\Order $order): array
    {
        return ['result' => 'success', 'redirect' => self::successUrl(), 'order_id' => (string) $order->id];
    }

    /** EDD's receipt page, through the same filter edd_send_to_success_page() applies (custom thank-you pages). */
    public static function successUrl(): string
    {
        return (string) apply_filters('edd_success_page_redirect', edd_get_success_page_uri(), Settings::GATEWAY_ID, null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter, applied the way EDD's gateways do.
    }

    /** @return array<string,string> */
    private function failure(string $message): array
    {
        return ['result' => 'failure', 'message' => $message];
    }

    /**
     * The fields this gateway reads from a posted purchase form.
     *
     * @param array<string,mixed> $post unslashed
     * @return array{token_id:int, confirmation_token:string, save:bool, flow:string}
     */
    public static function requestFrom(array $post): array
    {
        $token = isset($post[self::FIELD_TOKEN]) && is_scalar($post[self::FIELD_TOKEN]) ? sanitize_text_field((string) $post[self::FIELD_TOKEN]) : '';
        $save = isset($post[self::FIELD_SAVE]) && is_scalar($post[self::FIELD_SAVE]) ? strtolower(sanitize_text_field((string) $post[self::FIELD_SAVE])) : '';
        return [
            'token_id' => ($token !== '' && $token !== 'new') ? absint($token) : 0,
            'confirmation_token' => self::cleanConfirmationToken(isset($post[self::FIELD_CONFIRMATION_TOKEN]) && is_scalar($post[self::FIELD_CONFIRMATION_TOKEN]) ? (string) $post[self::FIELD_CONFIRMATION_TOKEN] : ''),
            'save' => in_array($save, ['1', 'true', 'yes', 'on'], true),
            'flow' => !empty($post[self::FIELD_EXPRESS]) ? 'express' : 'checkout',
        ];
    }

    public static function cleanConfirmationToken(string $value): string
    {
        $value = sanitize_text_field($value);
        return preg_match('/^ctoken_[A-Za-z0-9]+$/', $value) ? $value : '';
    }

    /** @return array{currency:string, total:float} */
    private function amountContext(): array
    {
        $total = 0.0;
        if (function_exists('edd_get_cart_total') && did_action('wp_loaded')) {
            $total = (float) edd_get_cart_total();
        }
        return ['currency' => stackless_edd_current_currency(), 'total' => $total];
    }

    /** Settings screen link shown in the EDD payment settings. */
    public static function settingsUrl(): string
    {
        return SettingsPage::url(Settings::MODULE_ID);
    }
}
