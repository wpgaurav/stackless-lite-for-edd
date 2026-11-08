<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Razorpay's part of the shared "Payment methods" tab on the Stackless account page (WooCommerce's
 * My Account → Payment methods and Add payment method). Customers see their saved mandates, make
 * one the default, remove one (the mandate is cancelled at Razorpay), and save a new one through
 * an authorization in Razorpay Checkout.
 *
 * The tab is shared: whichever gateway registers it first prints the
 * stackless_edd_account_payment_methods action, and each gateway adds its own section there.
 * Subscriptions attaches a method added from a subscription's "new payment method" link (it
 * listens to stackless_edd_payment_method_saved).
 */
final class Account
{
    public const TAB = 'payment-methods';
    private const NONCE = 'stackless_edd_razorpay_account';

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
        if (!isset($tabs[self::TAB]) && Settings::hasKeys()) {
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

    public static function addUrl(int $subscriptionId = 0): string
    {
        $args = ['add' => Gateway::ID];
        if ($subscriptionId > 0) {
            $args['subscription'] = $subscriptionId;
        }
        return stackless_edd_account_url(self::TAB, $args);
    }

    /**
     * Where a customer adds a new Razorpay method (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gatewayId
     * @param mixed $subscriptionId
     */
    public static function filterAddUrl($url, $gatewayId = '', $subscriptionId = 0): string
    {
        if ((string) $gatewayId !== Gateway::ID) {
            return (string) $url;
        }
        return Settings::hasKeys() && Settings::recurringMethods() && Module::gateway()->isEnabled() ? self::addUrl((int) $subscriptionId) : (string) $url;
    }

    /** @param array<string,string> $query */
    public static function render(array $query = []): void
    {
        $userId = get_current_user_id();
        if ($userId < 1 || !Settings::hasKeys()) {
            return;
        }
        $adding = ($query['add'] ?? '') === Gateway::ID;
        $subscriptionId = (int) ($query['subscription'] ?? 0);
        $subscription = $subscriptionId > 0 ? stackless_edd_get_subscription($subscriptionId) : null;
        $owned = $subscription instanceof SubscriptionData && $subscription->get_user_id() === $userId;
        $tokens = Tokens::forUser($userId);
        if (!$adding && !$tokens && !Module::gateway()->isEnabled()) {
            return;
        }
        stackless_edd_get_template('razorpay_gateway/payment-methods.php', [
            'adding' => $adding,
            'subscription_id' => $owned ? $subscriptionId : 0,
            'tokens' => $adding ? [] : $tokens,
            'methods' => array_intersect_key(Mandate::labels(), array_flip(Settings::recurringMethods())),
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'testmode' => Settings::mode() === 'test',
            'can_add' => Module::gateway()->isEnabled() && Settings::recurringMethods() !== [],
        ]);
    }

    /** Make default, remove, and add submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST['stackless_edd_razorpay_task']) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, '_stackless_edd_razorpay_nonce');
        $task = sanitize_key(wp_unslash((string) $_POST['stackless_edd_razorpay_task']));
        $userId = get_current_user_id();
        $tokenId = isset($_POST['token']) ? absint($_POST['token']) : 0;
        $method = isset($_POST[Gateway::FIELD_METHOD]) && is_scalar($_POST[Gateway::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[Gateway::FIELD_METHOD])) : '';
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
            $subscription = $subscriptionId > 0 ? stackless_edd_get_subscription($subscriptionId) : null;
            if (!$subscription instanceof SubscriptionData || $subscription->get_user_id() !== $userId) {
                $subscriptionId = 0;
            }
            $url = Module::gateway()->isEnabled()
                ? Checkout::prepareAdd($userId, $method, $subscriptionId)
                : new \WP_Error('stackless_edd_razorpay_off', __('Razorpay is not available right now.', 'stackless-lite-edd'));
            if (is_wp_error($url)) {
                stackless_edd_add_notice($url->get_error_message(), 'error');
                wp_safe_redirect(self::addUrl($subscriptionId));
                exit;
            }
            wp_safe_redirect($url);
            exit;
        }
    }
}
