<?php

namespace StacklessEDD\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Browser-facing endpoints that finish a payment after Stripe asked for customer action.
 *
 * - admin-ajax.php?action=stackless_edd_stripe_verify: called by the scripts after
 *   stripe.handleNextAction() (3D Secure in a modal). Returns JSON with a redirect or an error.
 * - ?stackless_edd_stripe_return=1: Stripe's return_url after a full-page redirect (bank
 *   redirects, some wallets). Redirects the shopper onward.
 *
 * Neither trusts an intent id from the browser: the order (checked by its payment key), the
 * signed-in user's pending setup, or the subscription the user owns is the source of truth, and
 * the intent is re-read from Stripe.
 */
final class Endpoints
{
    public const VERIFY_ACTION = 'stackless_edd_stripe_verify';
    public const RETURN_ARG = 'stackless_edd_stripe_return';

    public static function registerHooks(): void
    {
        add_action('wp_ajax_' . self::VERIFY_ACTION, [self::class, 'verify']);
        add_action('wp_ajax_nopriv_' . self::VERIFY_ACTION, [self::class, 'verify']);
        add_action('template_redirect', [self::class, 'maybeHandleReturn'], 5);
    }

    public static function verify(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- authorised by the order key / signed-in user; the call only re-reads state from Stripe.
        $flow = isset($_POST['flow']) ? sanitize_key(wp_unslash((string) $_POST['flow'])) : '';
        $type = isset($_POST['type']) && $_POST['type'] === 'si' ? 'si' : 'pi';
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash((string) $_POST['order_key'])) : '';
        // phpcs:enable

        nocache_headers();
        wp_send_json(self::finish($flow, $type, $order_id, $key));
    }

    public static function maybeHandleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorised by the order key / signed-in user.
        if (empty($_GET[self::RETURN_ARG])) {
            return;
        }
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : 'checkout';
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        $type = isset($_GET['setup_intent']) ? 'si' : 'pi';
        // phpcs:enable

        if ($flow !== 'add' && $flow !== 'change' && $order_id > 0 && Orders::meta($order_id, Payments::META_SETUP_INTENT) && !Orders::meta($order_id, Payments::META_INTENT)) {
            $type = 'si';
        }

        $result = self::finish($flow, $type, $order_id, $key);
        if ($result['result'] !== 'success') {
            if ($flow === 'add' || $flow === 'change') {
                stackless_edd_add_notice($result['message'] ?? ErrorMapper::generic(), 'error');
            } else {
                edd_set_error('stackless_edd_stripe_return', $result['message'] ?? ErrorMapper::generic()); // @phpstan-ignore argument.type
            }
        }
        wp_safe_redirect($result['redirect'] ?? edd_get_checkout_uri());
        exit;
    }

    /**
     * @return array{result:string, redirect?:string, message?:string}
     */
    public static function finish(string $flow, string $type, int $order_id, string $key): array
    {
        if ($flow === 'add') {
            return self::finishAddPaymentMethod();
        }
        if ($flow === 'change') {
            return self::finishSubscriptionChange($order_id);
        }

        $order = Orders::get($order_id);
        if (!$order || $key === '' || !hash_equals((string) $order->payment_key, $key) || (string) $order->gateway !== Settings::GATEWAY_ID) {
            return ['result' => 'failure', 'message' => __('We could not find that order.', 'stackless-lite-edd'), 'redirect' => edd_get_checkout_uri()];
        }

        $gateway = Module::gateway();
        $failure_redirect = edd_get_checkout_uri();
        $client = Settings::clientForOrder($order_id);

        try {
            if ($type === 'si') {
                $setup_intent_id = (string) Orders::meta($order_id, Payments::META_SETUP_INTENT);
                if ($setup_intent_id === '') {
                    return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $failure_redirect];
                }
                $setup_intent = $client->get('setup_intents/' . rawurlencode($setup_intent_id), ['expand' => ['payment_method']]);
                $status = (string) ($setup_intent['status'] ?? '');
                $outcome = Payments::applySetupIntent($order, $setup_intent, 'verify');
                if ($outcome === Payments::OUTCOME_SAVED || $outcome === Payments::OUTCOME_PROCESSING
                    || ($outcome === Payments::OUTCOME_BUSY && ($status === 'succeeded' || $status === 'processing'))) {
                    // Busy: the webhook is applying it right now.
                    return self::paid();
                }
                return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $failure_redirect];
            }

            $intent_id = (string) Orders::meta($order_id, Payments::META_INTENT);
            if ($intent_id === '') {
                return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $failure_redirect];
            }
            $intent = $client->get('payment_intents/' . rawurlencode($intent_id), ['expand' => ['payment_method', 'latest_charge']]);
            $outcome = Payments::applyPaymentIntent($order, $intent, 'verify');
            if (in_array($outcome, [Payments::OUTCOME_PAID, Payments::OUTCOME_AUTHORIZED, Payments::OUTCOME_PROCESSING], true)) {
                $gateway->syncRenewalToken($order_id);
                return self::paid();
            }
            $settled = in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true);
            if ($outcome === Payments::OUTCOME_HELD || ($outcome === Payments::OUTCOME_BUSY && $settled)) {
                // Money taken: held for the merchant, or the webhook is applying it right now.
                return self::paid();
            }
            return ['result' => 'failure', 'message' => self::intentErrorMessage($intent, 'last_payment_error'), 'redirect' => $failure_redirect];
        } catch (StripeApiException $e) {
            Settings::log('Verification failed for order ' . $order_id . ': ' . $e->logSummary(), 'error');
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $failure_redirect];
        }
    }

    /** The order is paid (or on its way): empty the cart and show EDD's receipt. */
    private static function paid(): array
    {
        if (function_exists('edd_empty_cart')) {
            edd_empty_cart();
        }
        return ['result' => 'success', 'redirect' => Gateway::successUrl()];
    }

    /**
     * @return array{result:string, redirect?:string, message?:string}
     */
    private static function finishAddPaymentMethod(): array
    {
        $add_url = Account::addUrl();
        $user_id = get_current_user_id();
        $pending = $user_id ? (string) get_user_meta($user_id, Gateway::USER_META_PENDING_SETUP, true) : '';
        if ($pending === '') {
            return ['result' => 'failure', 'message' => __('Your session expired. Add the payment method again.', 'stackless-lite-edd'), 'redirect' => $add_url];
        }
        $parts = explode('|', $pending);
        $setup_intent_id = $parts[0];
        $subscription_id = (int) ($parts[1] ?? 0);
        $client = Settings::client();
        try {
            $setup_intent = $client->get('setup_intents/' . rawurlencode($setup_intent_id), ['expand' => ['payment_method']]);
            $customer = Customers::forUser($user_id, $client, false);
            if ($customer === '' || (string) ($setup_intent['customer'] ?? '') !== $customer) {
                return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $add_url];
            }
            $status = (string) ($setup_intent['status'] ?? '');
            if (($status === 'succeeded' || $status === 'processing') && is_array($setup_intent['payment_method'] ?? null)) {
                delete_user_meta($user_id, Gateway::USER_META_PENDING_SETUP);
                $token = Tokens::saveFromPaymentMethod($setup_intent['payment_method'], $user_id, $customer, $client->mode());
                if ($token) {
                    $subscription = $subscription_id > 0 ? Account::ownedSubscription($subscription_id, $user_id) : null;
                    if ($subscription) {
                        Module::gateway()->updateSubscriptionMethod($subscription, $token);
                        stackless_edd_add_notice(__('Payment method updated.', 'stackless-lite-edd'));
                        return ['result' => 'success', 'redirect' => Account::subscriptionUrl($subscription_id)];
                    }
                    stackless_edd_add_notice(__('Payment method successfully added.', 'stackless-lite-edd'));
                    return ['result' => 'success', 'redirect' => Account::listUrl()];
                }
            }
            return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $add_url];
        } catch (StripeApiException $e) {
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $add_url];
        }
    }

    /**
     * A subscription's method changed through a SetupIntent that needed 3D Secure.
     *
     * @return array{result:string, redirect?:string, message?:string}
     */
    private static function finishSubscriptionChange(int $subscription_id): array
    {
        $user_id = get_current_user_id();
        $subscription = Account::ownedSubscription($subscription_id, $user_id);
        if (!$subscription) {
            return ['result' => 'failure', 'message' => __('We could not find that subscription.', 'stackless-lite-edd'), 'redirect' => Account::listUrl()];
        }
        $redirect = Account::subscriptionUrl($subscription_id);
        $setup_intent_id = (string) $subscription->get_meta(Payments::META_SETUP_INTENT);
        if ($setup_intent_id === '') {
            return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $redirect];
        }
        try {
            $mode = (string) $subscription->get_meta(Payments::META_MODE);
            $setup_intent = Settings::client($mode !== '' ? $mode : null)->get('setup_intents/' . rawurlencode($setup_intent_id), ['expand' => ['payment_method']]);
            $status = (string) ($setup_intent['status'] ?? '');
            if (($status === 'succeeded' || $status === 'processing') && Module::gateway()->completeSubscriptionSetup($subscription, $setup_intent)) {
                stackless_edd_add_notice(__('Payment method updated.', 'stackless-lite-edd'));
                return ['result' => 'success', 'redirect' => (string) apply_filters('stackless_edd_stripe_gateway_change_payment_method_redirect', $redirect, $subscription)];
            }
            return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $redirect];
        } catch (StripeApiException $e) {
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $redirect];
        }
    }

    /** @param array<string,mixed> $intent */
    private static function intentErrorMessage(array $intent, string $field): string
    {
        $error = isset($intent[$field]) && is_array($intent[$field]) ? $intent[$field] : [];
        if ($error) {
            return ErrorMapper::fromIntentError($error);
        }
        if (($intent['status'] ?? '') === 'requires_action' || ($intent['status'] ?? '') === 'requires_payment_method') {
            return ErrorMapper::message('payment_intent_authentication_failure');
        }
        return ErrorMapper::generic();
    }
}
