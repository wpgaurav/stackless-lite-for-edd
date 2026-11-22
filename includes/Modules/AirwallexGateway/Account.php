<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Airwallex's part of "Payment methods" on the Stackless account page (WooCommerce's My Account →
 * Payment methods and Add payment method). Customers see their saved cards, make one the default,
 * remove one (the consent is disabled at Airwallex), and add one through a SETUP session, which
 * charges nothing.
 *
 * The tab is shared: whichever gateway registers it first prints the
 * stackless_edd_account_payment_methods action, and each gateway prints its own section there.
 * Opening it with ?add=stackless_edd_airwallex&subscription={id} adds a card for that
 * subscription; Subscriptions attaches it when stackless_edd_payment_method_saved fires.
 */
final class Account
{
    public const TAB = 'payment-methods';
    public const TASK_FIELD = 'stackless_edd_airwallex_task';
    public const NONCE_FIELD = '_stackless_edd_airwallex_nonce';
    private const NONCE = 'stackless_edd_airwallex_account';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab'], 42);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render'], 22);
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

    /** Airwallex is connected and switched on in Downloads → Settings → Payments. */
    private static function available(): bool
    {
        return Settings::configured() && Settings::enabled();
    }

    public static function listUrl(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    public static function addUrl(int $subscriptionId = 0): string
    {
        $args = ['add' => Settings::GATEWAY_ID];
        if ($subscriptionId > 0) {
            $args['subscription'] = $subscriptionId;
        }
        return stackless_edd_account_url(self::TAB, $args);
    }

    /**
     * Where a customer adds a new card (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gatewayId
     * @param mixed $subscriptionId
     */
    public static function filterAddUrl($url, $gatewayId = '', $subscriptionId = 0): string
    {
        return (string) $gatewayId === Settings::GATEWAY_ID && self::available() ? self::addUrl((int) $subscriptionId) : (string) $url;
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
        $adding = ($query['add'] ?? '') === Settings::GATEWAY_ID;
        // Another gateway's add form is open: leave the page to it.
        if (!$adding && ($query['add'] ?? '') !== '') {
            return;
        }
        stackless_edd_get_template('airwallex_gateway/payment-methods.php', [
            'adding' => $adding,
            'subscription' => $adding ? self::ownedSubscription((int) ($query['subscription'] ?? 0), $userId) : null,
            'tokens' => $adding ? [] : Tokens::forUser($userId),
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'nonce_field' => self::NONCE_FIELD,
            'task_field' => self::TASK_FIELD,
            'test_mode' => Settings::mode() === 'test',
        ]);
    }

    /** Make default, remove, and add submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST[self::TASK_FIELD]) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, self::NONCE_FIELD);
        $task = sanitize_key(wp_unslash((string) $_POST[self::TASK_FIELD]));
        $userId = get_current_user_id();
        $tokenId = isset($_POST['token']) ? absint($_POST['token']) : 0;
        $subscriptionId = isset($_POST['subscription']) ? absint($_POST['subscription']) : 0;
        // phpcs:enable

        if ($task === 'delete' || $task === 'default') {
            $saved = Tokens::getOwned($tokenId, $userId);
            if (!$saved) {
                stackless_edd_add_notice(__('That payment method no longer exists.', 'stackless-lite-edd'), 'error');
            } elseif ($task === 'delete') {
                Tokens::delete($saved);
                stackless_edd_add_notice(__('Payment method deleted.', 'stackless-lite-edd'));
            } else {
                Tokens::setDefault($saved);
                stackless_edd_add_notice(__('This payment method was successfully set as your default.', 'stackless-lite-edd'));
            }
            wp_safe_redirect(self::listUrl());
            exit;
        }
        if ($task === 'add') {
            $subscription = self::ownedSubscription($subscriptionId, $userId);
            $forSubscription = $subscription ? $subscription->get_id() : 0;
            $url = self::available()
                ? (new Checkout())->startAdd($userId, $subscription ? $subscription->get_currency() : stackless_edd_current_currency(), $forSubscription)
                : new \WP_Error('stackless_edd_airwallex_unavailable', __('Airwallex is not available right now.', 'stackless-lite-edd'));
            if (is_wp_error($url)) {
                stackless_edd_add_notice($url->get_error_message(), 'error');
                wp_safe_redirect(self::addUrl($forSubscription));
                exit;
            }
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Airwallex's hosted page; Checkout checked the host.
            wp_redirect($url);
            exit;
        }
    }
}
