<?php

namespace StacklessEDD\Modules\MollieGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Mollie's part of "Payment methods" on the Stackless account page (WooCommerce's My Account →
 * Payment methods and Add payment method). Customers see their saved Mollie mandates, make one
 * the default, remove one (the mandate is revoked at Mollie), and add a new one through a first
 * payment on Mollie's hosted page (0.00 for cards and PayPal, one refunded cent for bank methods).
 *
 * The tab is shared: whichever gateway registers it first prints the
 * stackless_edd_account_payment_methods action, and each gateway prints its own section there.
 * Opening it with ?add=stackless_edd_mollie&subscription={id} adds a method for that
 * subscription; the Subscriptions module attaches it when stackless_edd_payment_method_saved fires.
 */
final class Account
{
    public const TAB = 'payment-methods';
    private const NONCE = 'stackless_edd_mollie_account';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab'], 41);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render'], 20);
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
        if (!isset($tabs[self::TAB]) && self::available()) {
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

    /** Mollie has a key and is switched on in Downloads → Settings → Payments. */
    private static function available(): bool
    {
        return Settings::apiKey() !== '' && function_exists('edd_is_gateway_active') && edd_is_gateway_active(Gateway::ID);
    }

    public static function listUrl(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    public static function addUrl(int $subscriptionId = 0): string
    {
        $args = ['add' => Gateway::ID];
        if ($subscriptionId > 0) {
            $args['subscription'] = $subscriptionId;
        }
        return stackless_edd_account_url(self::TAB, $args);
    }

    /**
     * Where a customer adds a new Mollie method (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gatewayId
     * @param mixed $subscriptionId
     */
    public static function filterAddUrl($url, $gatewayId = '', $subscriptionId = 0): string
    {
        return (string) $gatewayId === Gateway::ID && self::available() ? self::addUrl((int) $subscriptionId) : (string) $url;
    }

    /** The subscription when the signed-in user owns it. */
    public static function ownedSubscription(int $subscriptionId, int $userId): ?SubscriptionData
    {
        if ($subscriptionId < 1 || $userId < 1) {
            return null;
        }
        $subscription = stackless_edd_get_subscription($subscriptionId);
        return $subscription instanceof SubscriptionData && $subscription->get_user_id() === $userId ? $subscription : null;
    }

    /** @param array<string,string> $query */
    public static function render(array $query = []): void
    {
        $userId = get_current_user_id();
        if ($userId < 1 || !self::available()) {
            return;
        }
        $adding = ($query['add'] ?? '') === Gateway::ID;
        // Another gateway's add form is open: leave the page to it.
        if (!$adding && ($query['add'] ?? '') !== '') {
            return;
        }
        $subscription = $adding ? self::ownedSubscription((int) ($query['subscription'] ?? 0), $userId) : null;
        stackless_edd_get_template('mollie_gateway/payment-methods.php', [
            'adding' => $adding,
            'subscription' => $subscription,
            'tokens' => $adding ? [] : Tokens::forUser($userId),
            'methods' => $adding ? Module::gateway()->pickerMethods(true) : [],
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'test_mode' => Settings::mode() === 'test',
        ]);
    }

    /** Make default, remove, and add submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST['stackless_edd_mollie_task']) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, '_stackless_edd_mollie_nonce');
        $task = sanitize_key(wp_unslash((string) $_POST['stackless_edd_mollie_task']));
        $userId = get_current_user_id();
        $tokenId = isset($_POST['token']) ? absint($_POST['token']) : 0;
        $method = isset($_POST[Gateway::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[Gateway::FIELD_METHOD])) : '';
        $subscriptionId = isset($_POST['subscription']) ? absint($_POST['subscription']) : 0;
        // phpcs:enable

        if ($task === 'delete' || $task === 'default') {
            $token = Tokens::getOwned($tokenId, $userId);
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
            $subscription = self::ownedSubscription($subscriptionId, $userId);
            $url = self::available()
                ? (new Checkout(Module::gateway()))->startAdd($userId, isset(Methods::labels()[$method]) ? $method : '', $subscription ? $subscription->get_id() : 0)
                : new \WP_Error('stackless_edd_mollie_unavailable', __('Mollie is not available right now.', 'stackless-lite-edd'));
            if (is_wp_error($url)) {
                stackless_edd_add_notice($url->get_error_message(), 'error');
                wp_safe_redirect(self::addUrl($subscription ? $subscription->get_id() : 0));
                exit;
            }
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mollie's hosted checkout.
            wp_redirect($url);
            exit;
        }
    }
}
