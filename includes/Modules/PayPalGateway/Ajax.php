<?php

namespace StacklessEDD\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * admin-ajax endpoints the PayPal buttons call. They run with the EDD session loaded,
 * so the cart is there for guests and customers alike.
 */
final class Ajax
{
    public const NONCE = 'stackless-edd-paypal-gateway';
    public const CREATE_ORDER = 'stackless_edd_paypal_create_order';
    public const CREATE_SETUP_TOKEN = 'stackless_edd_paypal_create_setup_token';

    public static function register(): void
    {
        foreach ([self::CREATE_ORDER => 'createOrder', self::CREATE_SETUP_TOKEN => 'createSetupToken'] as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, $method]);
        }
    }

    public static function urls(): array
    {
        return [
            'createOrder' => add_query_arg('action', self::CREATE_ORDER, admin_url('admin-ajax.php')),
            'createSetupToken' => add_query_arg('action', self::CREATE_SETUP_TOKEN, admin_url('admin-ajax.php')),
        ];
    }

    public static function createOrder(): void
    {
        self::verify();
        self::respond(Gateway::instance()->payments()->createForCart(self::funding()));
    }

    public static function createSetupToken(): void
    {
        self::verify();
        $payments = Gateway::instance()->payments();
        if (self::context() === 'add-payment-method') {
            if (!is_user_logged_in()) {
                self::respond(new \WP_Error('stackless_edd_paypal_login', __('Log in to save a PayPal account.', 'stackless-lite-edd')));
            }
            $url = Account::url();
            $result = $payments->createSetupToken($url, $url);
            if (is_array($result)) {
                unset($result['approve']);
            }
            self::respond($result);
        }
        if (!edd_get_cart_contents() && !edd_cart_has_fees()) {
            self::respond(new \WP_Error('stackless_edd_paypal_cart', __('Your cart is empty.', 'stackless-lite-edd')));
        }
        if ((float) edd_get_cart_total() > 0 || !stackless_edd_cart_requires_saved_payment_method()) {
            self::respond(new \WP_Error('stackless_edd_paypal_setup', __('This cart needs a payment, not just a saved account.', 'stackless-lite-edd')));
        }
        $url = edd_get_checkout_uri();
        $result = $payments->createSetupToken($url, $url);
        if (is_array($result)) {
            unset($result['approve']);
        }
        self::respond($result);
    }

    private static function verify(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Refresh the page and try again.', 'stackless-lite-edd')], 403);
        }
        $gateway = Gateway::instance();
        if (!$gateway->isEnabled() || !$gateway->api()->hasCredentials()) {
            wp_send_json_error(['message' => __('PayPal is not available.', 'stackless-lite-edd')], 400);
        }
    }

    private static function context(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().
        $context = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : 'checkout';
        return in_array($context, ['checkout', 'add-payment-method'], true) ? $context : 'checkout';
    }

    private static function funding(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().
        $funding = isset($_POST['funding']) ? sanitize_key(wp_unslash($_POST['funding'])) : 'paypal';
        return preg_match('/^[a-z0-9_-]{0,30}$/', $funding) ? $funding : 'paypal';
    }

    /** @param array|\WP_Error $result */
    private static function respond($result): void
    {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success($result);
    }
}
