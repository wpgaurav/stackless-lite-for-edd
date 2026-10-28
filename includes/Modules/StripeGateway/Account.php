<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * "Payment methods" on the Stackless account page: the counterpart of WooCommerce's My Account →
 * Payment methods and Add payment method pages. Customers see their saved Stripe methods, make
 * one the default, remove one (it is detached at Stripe), and add a new one through a SetupIntent.
 *
 * The tab is shared: it prints the stackless_edd_account_payment_methods action, so another
 * gateway can list its own methods in the same tab. Opening it with ?subscription={id} adds a
 * method for that subscription and switches its renewals to it (subscriptions renewing through
 * this gateway; other subscriptions are moved by the Subscriptions module, which listens to
 * stackless_edd_payment_method_saved).
 */
final class Account
{
    public const TAB = 'payment-methods';
    private const NONCE = 'stackless_edd_stripe_account';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab'], 40);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handlePost'], 20);
        add_filter('stackless_edd_add_payment_method_url', [self::class, 'filterAddUrl'], 10, 3);
    }

    /**
     * @param mixed $tabs
     * @return array<string,mixed>
     */
    public static function tab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        if (!isset($tabs[self::TAB]) && Settings::isConfigured()) {
            $tabs[self::TAB] = [
                'title' => __('Payment methods', 'stackless-lite-edd'),
                'icon' => 'credit-card',
                'priority' => 40,
                'callback' => static function (array $query = []): void {
                    do_action('stackless_edd_account_payment_methods', $query);
                },
            ];
        }
        return $tabs;
    }

    public static function listUrl(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    public static function addUrl(int $subscription_id = 0): string
    {
        $args = ['add' => Settings::GATEWAY_ID];
        if ($subscription_id > 0) {
            $args['subscription'] = $subscription_id;
        }
        return stackless_edd_account_url(self::TAB, $args);
    }

    public static function subscriptionUrl(int $subscription_id): string
    {
        return stackless_edd_account_url('subscriptions', ['id' => $subscription_id]);
    }

    /**
     * Where a customer adds a new method for a gateway (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gateway_id
     * @param mixed $subscription_id
     */
    public static function filterAddUrl($url, $gateway_id = '', $subscription_id = 0): string
    {
        return (string) $gateway_id === Settings::GATEWAY_ID ? self::addUrl((int) $subscription_id) : (string) $url;
    }

    /** The subscription when the signed-in user owns it. */
    public static function ownedSubscription(int $subscription_id, int $user_id): ?SubscriptionData
    {
        if ($subscription_id < 1 || $user_id < 1) {
            return null;
        }
        $subscription = stackless_edd_get_subscription($subscription_id);
        return $subscription instanceof SubscriptionData && $subscription->get_user_id() === $user_id ? $subscription : null;
    }

    /** @param array<string,string> $query */
    public static function render(array $query = []): void
    {
        $user_id = get_current_user_id();
        if ($user_id < 1 || !Settings::isConfigured()) {
            return;
        }
        $adding = ($query['add'] ?? '') === Settings::GATEWAY_ID;
        $subscription = $adding ? self::ownedSubscription((int) ($query['subscription'] ?? 0), $user_id) : null;
        if ($adding) {
            Assets::enqueueCheckout();
        }
        stackless_edd_get_template('stripe_gateway/payment-methods.php', [
            'adding' => $adding,
            'subscription' => $subscription,
            'tokens' => $adding ? [] : Tokens::forUser($user_id),
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'test_mode' => Settings::isTestMode(),
            'currency' => stackless_edd_current_currency(),
        ]);
    }

    /** Make default, remove, and add (SetupIntent) submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST['stackless_edd_stripe_task']) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, '_stackless_edd_stripe_nonce');
        $task = sanitize_key(wp_unslash((string) $_POST['stackless_edd_stripe_task']));
        $user_id = get_current_user_id();
        $token_id = isset($_POST['token']) ? absint($_POST['token']) : 0;
        // phpcs:enable

        if ($task === 'delete' || $task === 'default') {
            $token = Tokens::getOwned($token_id, $user_id);
            if (!$token) {
                stackless_edd_add_notice(__('That payment method no longer exists.', 'stackless-lite-edd'), 'error');
            } elseif ($task === 'delete') {
                Tokens::delete($token);
                stackless_edd_add_notice(__('Payment method deleted.', 'stackless-lite-edd'));
            } else {
                Tokens::setDefault($token);
                stackless_edd_add_notice(__('This payment method was successfully set as your default.', 'stackless-lite-edd'));
            }
            wp_safe_redirect(self::listUrl());
            exit;
        }
        if ($task === 'add') {
            $result = self::add($user_id);
            if ($result['result'] !== 'success') {
                stackless_edd_add_notice($result['message'] ?? ErrorMapper::generic(), 'error');
            } elseif (!empty($result['notice'])) {
                stackless_edd_add_notice($result['notice']);
            }
            wp_safe_redirect($result['redirect'] ?? self::addUrl());
            exit;
        }
    }

    /**
     * Save a new method through a SetupIntent (WooCommerce's add_payment_method()).
     *
     * @return array{result:string, redirect?:string, message?:string, notice?:string}
     */
    private static function add(int $user_id): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- handlePost() verified the nonce.
        $confirmation_token = Gateway::cleanConfirmationToken(isset($_POST[Gateway::FIELD_CONFIRMATION_TOKEN]) ? sanitize_text_field(wp_unslash($_POST[Gateway::FIELD_CONFIRMATION_TOKEN])) : '');
        $subscription_id = isset($_POST['subscription']) ? absint($_POST['subscription']) : 0;
        // phpcs:enable
        $subscription = self::ownedSubscription($subscription_id, $user_id);
        $back = self::addUrl($subscription ? $subscription_id : 0);
        if ($confirmation_token === '') {
            return ['result' => 'failure', 'message' => __('Enter your payment details to continue.', 'stackless-lite-edd'), 'redirect' => $back];
        }
        $change = $subscription && $subscription->get_gateway() === Settings::GATEWAY_ID;
        $flow = $change ? 'change' : 'add';
        try {
            $client = Settings::client();
            $customer = Customers::forUser($user_id, $client, true);
            $metadata = ['stackless_flow' => $flow, 'wp_user_id' => (string) $user_id];
            if ($change) {
                $metadata['subscription_id'] = (string) $subscription_id;
            }
            $setup_intent = Payments::createSetupIntent(['confirmation_token' => $confirmation_token], [
                'customer' => $customer,
                'return_url' => add_query_arg([Endpoints::RETURN_ARG => '1', 'flow' => $flow, 'order_id' => $change ? $subscription_id : 0], home_url('/')),
                'metadata' => $metadata,
                'idempotency_key' => IdempotencyKey::setup(($change ? 'sub-' . $subscription_id : 'user-' . $user_id), $confirmation_token),
            ]);
            if ($change && $subscription) {
                $subscription->update_meta(Payments::META_SETUP_INTENT, (string) ($setup_intent['id'] ?? ''));
                $subscription->update_meta(Payments::META_MODE, $client->mode());
            }
            $status = (string) ($setup_intent['status'] ?? '');
            if ($status === 'succeeded' || $status === 'processing') {
                if ($change && $subscription) {
                    if (Module::gateway()->completeSubscriptionSetup($subscription, $setup_intent)) {
                        return ['result' => 'success', 'redirect' => (string) apply_filters('stackless_edd_stripe_gateway_change_payment_method_redirect', self::subscriptionUrl($subscription_id), $subscription), 'notice' => __('Payment method updated.', 'stackless-lite-edd')];
                    }
                    return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $back];
                }
                $pm = is_array($setup_intent['payment_method'] ?? null) ? $setup_intent['payment_method'] : $client->get('payment_methods/' . rawurlencode((string) ($setup_intent['payment_method'] ?? '')));
                $token = Tokens::saveFromPaymentMethod($pm, $user_id, $customer, $client->mode());
                if ($token) {
                    $redirect = $subscription ? self::subscriptionUrl($subscription_id) : self::listUrl();
                    return ['result' => 'success', 'redirect' => $redirect, 'notice' => __('Payment method successfully added.', 'stackless-lite-edd')];
                }
            } elseif ($status === 'requires_action') {
                if (!$change) {
                    update_user_meta($user_id, Gateway::USER_META_PENDING_SETUP, (string) ($setup_intent['id'] ?? ''));
                }
                $fragment = Gateway::confirmFragment($flow, 'si', $change ? $subscription_id : 0, $flow, (string) ($setup_intent['client_secret'] ?? ''));
                return ['result' => 'success', 'redirect' => $back . $fragment];
            } elseif (!empty($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error'])) {
                return ['result' => 'failure', 'message' => ErrorMapper::fromIntentError($setup_intent['last_setup_error']), 'redirect' => $back];
            }
        } catch (StripeApiException $e) {
            Settings::log('Add payment method failed: ' . $e->logSummary(), 'warning');
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $back];
        }
        return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $back];
    }
}
