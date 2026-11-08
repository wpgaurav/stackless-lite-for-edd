<?php

namespace StacklessEDD\Modules\SquareGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Square cards on the account page's shared "Payment methods" tab: the counterpart of
 * WooCommerce's My Account → Payment methods and Add payment method pages. Customers see their
 * saved Square cards, make one the default, remove one (it is disabled at Square so it is never
 * charged again), and add a new one with Square's card form.
 *
 * The tab prints the stackless_edd_account_payment_methods action, so every gateway lists its
 * own methods there; whichever registers first adds the tab. Opening it with ?add=stackless_edd_square
 * &subscription={id} adds a card for that subscription and switches its renewals to it
 * (subscriptions renewing through Square; others are moved by the Subscriptions module, which
 * listens to stackless_edd_payment_method_saved).
 */
final class Account
{
    public const TAB = 'payment-methods';
    public const FIELD = 'stackless_edd_square_task';
    private const NONCE = 'stackless_edd_square_account';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab'], 40);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render'], 30);
        add_action('template_redirect', [self::class, 'handlePost'], 20);
        add_filter('stackless_edd_add_payment_method_url', [self::class, 'filterAddUrl'], 10, 3);
    }

    private static function ready(): bool
    {
        $gateway = Gateway::instance();
        return $gateway->isEnabled() && $gateway->appId() !== '' && $gateway->accessToken() !== '' && $gateway->locationId() !== '';
    }

    /**
     * @param mixed $tabs
     * @return array<string,mixed>
     */
    public static function tab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        if (!is_user_logged_in() || isset($tabs[self::TAB])) {
            return $tabs;
        }
        // The tab stays for customers who already saved a card, even if Square was switched off.
        if (!self::ready() && !Tokens::forUser(get_current_user_id())) {
            return $tabs;
        }
        $tabs[self::TAB] = [
            'title' => __('Payment methods', 'stackless-lite-edd'),
            'icon' => 'credit-card',
            'priority' => 40,
            'callback' => static function (array $query = []): void {
                do_action('stackless_edd_account_payment_methods', $query);
            },
        ];
        return $tabs;
    }

    public static function listUrl(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    public static function addUrl(int $subscription_id = 0): string
    {
        $args = ['add' => Gateway::ID];
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
     * Where a customer adds a new card (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gateway_id
     * @param mixed $subscription_id
     */
    public static function filterAddUrl($url, $gateway_id = '', $subscription_id = 0): string
    {
        if ((string) $gateway_id !== Gateway::ID || !self::ready()) {
            return (string) $url;
        }
        return self::addUrl((int) $subscription_id);
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

    /** @param mixed $query */
    public static function render($query = []): void
    {
        $query = is_array($query) ? $query : [];
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }
        $tokens = Tokens::forUser($user_id);
        if (!self::ready() && !$tokens) {
            return;
        }
        $adding = self::ready() && ($query['add'] ?? '') === Gateway::ID;
        if (!$adding && ($query['add'] ?? '') !== '') {
            return; // Another gateway's add form owns the tab right now.
        }
        $subscription = $adding ? self::ownedSubscription((int) ($query['subscription'] ?? 0), $user_id) : null;
        if ($adding) {
            Assets::enqueue();
        }
        $gateway = Gateway::instance();
        stackless_edd_get_template('square_gateway/payment-methods.php', [
            'adding' => $adding,
            'can_add' => self::ready(),
            'subscription' => $subscription,
            'tokens' => $adding ? [] : $tokens,
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'field' => self::FIELD,
            'testmode' => $gateway->mode() === 'test',
            'data' => $adding ? $gateway->scriptData('add') : [],
        ]);
    }

    /** Make default, remove, and add submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST[self::FIELD]) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, '_stackless_edd_square_nonce');
        $task = sanitize_key(wp_unslash((string) $_POST[self::FIELD]));
        $user_id = get_current_user_id();
        $token_id = isset($_POST['token']) ? absint($_POST['token']) : 0;
        // phpcs:enable

        if ($task === 'delete' || $task === 'default') {
            $token = Tokens::getOwned($token_id, $user_id);
            if (!$token) {
                stackless_edd_add_notice(__('That card no longer exists.', 'stackless-lite-edd'), 'error');
            } elseif ($task === 'delete') {
                Tokens::delete($token);
                stackless_edd_add_notice(__('Card removed. Subscriptions that used it will ask for another payment method at renewal.', 'stackless-lite-edd'));
            } else {
                Tokens::setDefault($token);
                stackless_edd_add_notice(__('This card is now your default.', 'stackless-lite-edd'));
            }
            wp_safe_redirect(self::listUrl());
            exit;
        }
        if ($task === 'add') {
            $result = self::add($user_id);
            stackless_edd_add_notice($result['message'], $result['result'] === 'success' ? 'success' : 'error');
            wp_safe_redirect($result['redirect']);
            exit;
        }
    }

    /**
     * Store a new card (WooCommerce's add_payment_method()).
     *
     * @return array{result:string, redirect:string, message:string}
     */
    private static function add(int $user_id): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- handlePost() verified the nonce.
        $source = Gateway::cleanSource(isset($_POST[Gateway::FIELD_TOKEN]) ? sanitize_text_field(wp_unslash((string) $_POST[Gateway::FIELD_TOKEN])) : '');
        $subscription_id = isset($_POST['subscription']) ? absint($_POST['subscription']) : 0;
        // phpcs:enable
        $subscription = self::ownedSubscription($subscription_id, $user_id);
        $back = self::addUrl($subscription ? $subscription_id : 0);
        if ($source === '' || !self::ready()) {
            return ['result' => 'failure', 'redirect' => $back, 'message' => __('Enter your card details to continue.', 'stackless-lite-edd')];
        }
        $gateway = Gateway::instance();
        $token = (new Payments($gateway))->storeCard($source, $user_id, $gateway->mode());
        if (is_wp_error($token)) {
            $code = Api::code($token);
            return ['result' => 'failure', 'redirect' => $back, 'message' => $code !== '' ? Declines::message($code) : $token->get_error_message()];
        }
        if ($subscription && $gateway->updateSubscriptionMethod($subscription, $token)) {
            return ['result' => 'success', 'redirect' => self::subscriptionUrl($subscription->get_id()), 'message' => __('Payment method updated.', 'stackless-lite-edd')];
        }
        return ['result' => 'success', 'redirect' => $subscription ? self::subscriptionUrl($subscription->get_id()) : self::listUrl(), 'message' => __('Card saved.', 'stackless-lite-edd')];
    }
}
