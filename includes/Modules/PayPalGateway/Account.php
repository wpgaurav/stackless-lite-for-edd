<?php

namespace StacklessEDD\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Saved PayPal accounts for customers (the WooCommerce My Account → Payment methods
 * counterpart): a section of the shared "Payment methods" account tab that lists them, saves a
 * new one through PayPal's vault flow, and removes one. The tab prints the
 * stackless_edd_account_payment_methods action, so the Stripe gateway lists its cards in the same
 * tab; whichever gateway registers first adds the tab. Subscriptions reaches it through its
 * payment-method filters.
 */
final class Account
{
    public const TAB = 'payment-methods';
    /** POST field naming the action; the forms post back to the account page (a storefront request, so the EDD session is there). */
    public const FIELD = 'stackless_edd_paypal_account_action';
    public const ADD_ACTION = 'stackless_edd_paypal_add_method';
    public const REMOVE_ACTION = 'stackless_edd_paypal_remove_method';

    public static function register(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab']);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render'], 20);
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'savedMethods'], 10, 3);
        add_filter('stackless_edd_add_payment_method_url', [self::class, 'addMethodUrl'], 10, 3);
        add_action('template_redirect', [self::class, 'handlePost'], 5);
    }

    /** The account page's own forms: save or remove a PayPal account. */
    public static function handlePost(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler checks its own nonce.
        $action = isset($_POST[self::FIELD]) ? sanitize_key(wp_unslash($_POST[self::FIELD])) : '';
        if ($action === self::ADD_ACTION) {
            self::handleAdd();
        } elseif ($action === self::REMOVE_ACTION) {
            self::handleRemove();
        }
    }

    private static function ready(): bool
    {
        $gateway = Gateway::instance();
        return $gateway->isEnabled() && $gateway->api()->hasCredentials();
    }

    public static function url(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    /** @param mixed $tabs */
    public static function tab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        if (!is_user_logged_in()) {
            return $tabs;
        }
        // The tab stays for customers who already saved an account, even if PayPal was switched off.
        if (!self::ready() && !Tokens::forUser(get_current_user_id())) {
            return $tabs;
        }
        if (!isset($tabs[self::TAB])) {
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

    public static function render(): void
    {
        if (!is_user_logged_in() || (!self::ready() && !Tokens::forUser(get_current_user_id()))) {
            return;
        }
        $gateway = Gateway::instance();
        $currency = strtoupper(stackless_edd_base_currency());
        stackless_edd_get_template('paypal_gateway/account.php', [
            'gateway' => $gateway,
            'tokens' => Tokens::forUser(get_current_user_id()),
            'environment' => $gateway->environment(),
            'can_add' => self::ready(),
            'currency' => Amount::supported($currency) ? $currency : 'USD',
            'field' => self::FIELD,
            'add_action' => self::ADD_ACTION,
            'remove_action' => self::REMOVE_ACTION,
            'form_url' => self::url(),
        ]);
    }

    /**
     * stackless_edd_saved_payment_methods: [token id => label] for this gateway.
     *
     * @param mixed $methods
     * @param mixed $userId
     * @param mixed $gatewayId
     * @return mixed
     */
    public static function savedMethods($methods, $userId = 0, $gatewayId = '')
    {
        if ($gatewayId !== Gateway::ID) {
            return $methods;
        }
        $methods = is_array($methods) ? $methods : [];
        foreach (Tokens::forUser((int) $userId, Gateway::instance()->environment()) as $token) {
            $methods[(string) $token['id']] = Tokens::displayName($token);
        }
        return $methods;
    }

    /**
     * stackless_edd_add_payment_method_url: where a customer saves a new PayPal account.
     *
     * @param mixed $url
     * @param mixed $gatewayId
     * @return mixed
     */
    public static function addMethodUrl($url, $gatewayId = '', $userId = 0)
    {
        if ($gatewayId !== Gateway::ID || !self::ready()) {
            return $url;
        }
        return self::url();
    }

    private static function handleAdd(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wp_safe_redirect(self::url());
            exit;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::ADD_ACTION)) {
            stackless_edd_add_notice(__('Your session expired. Refresh the page and try again.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect(self::url());
            exit;
        }
        $setupToken = isset($_POST[Gateway::FIELD_SETUP]) ? sanitize_text_field(wp_unslash($_POST[Gateway::FIELD_SETUP])) : '';
        $saved = self::ready()
            ? Gateway::instance()->payments()->addPaymentMethod($userId, $setupToken)
            : new \WP_Error('stackless_edd_paypal_unavailable', __('PayPal is not available.', 'stackless-lite-edd'));
        if (is_wp_error($saved)) {
            stackless_edd_add_notice($saved->get_error_message(), 'error');
        } else {
            stackless_edd_add_notice(__('PayPal account saved.', 'stackless-lite-edd'), 'success');
        }
        wp_safe_redirect(self::url());
        exit;
    }

    private static function handleRemove(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wp_safe_redirect(self::url());
            exit;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::REMOVE_ACTION)) {
            stackless_edd_add_notice(__('Your session expired. Refresh the page and try again.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect(self::url());
            exit;
        }
        $tokenId = isset($_POST['token_id']) ? absint($_POST['token_id']) : 0;
        if (Tokens::deleteForUser($tokenId, $userId)) {
            stackless_edd_add_notice(__('PayPal account removed. Subscriptions that used it will ask for another payment method at renewal.', 'stackless-lite-edd'), 'success');
        } else {
            stackless_edd_add_notice(__('That PayPal account could not be removed.', 'stackless-lite-edd'), 'error');
        }
        wp_safe_redirect(self::url());
        exit;
    }
}
