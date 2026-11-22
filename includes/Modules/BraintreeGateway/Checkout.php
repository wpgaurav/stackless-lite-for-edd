<?php

namespace StacklessEDD\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * The card form's AJAX endpoint: a client token for the currency being paid, the total to verify
 * with 3-D Secure, and a nonce for the customer's own saved card. The amount charged is always
 * the order's; Braintree refuses the charge when the verified amount differs.
 *
 * Pay links open EDD's checkout with the order's items, so the cart total is the amount there too.
 */
final class Checkout
{
    public const SESSION_ACTION = 'stackless_edd_braintree_session';

    public static function registerHooks(): void
    {
        add_action('wp_ajax_' . self::SESSION_ACTION, [self::class, 'session']);
        add_action('wp_ajax_nopriv_' . self::SESSION_ACTION, [self::class, 'session']);
    }

    public static function session(): void
    {
        if (!check_ajax_referer(self::SESSION_ACTION, 'security', false)) {
            self::refuse(Browser::messages()['expired']);
        }
        $flow = isset($_POST['flow']) ? sanitize_key(wp_unslash((string) $_POST['flow'])) : 'checkout';
        $saved = isset($_POST['saved']) ? absint($_POST['saved']) : 0;
        $user_id = get_current_user_id();

        if ($saved > 0) {
            $card = Browser::savedCard($saved, $user_id);
            if (is_wp_error($card)) {
                self::refuse($card->get_error_message());
            }
            wp_send_json_success($card);
        }

        if ($flow === 'add') {
            if ($user_id <= 0) {
                self::refuse(__('Sign in to save a payment method.', 'stackless-lite-edd'));
            }
            $currency = Connection::cardCurrency(Config::mode(), stackless_edd_base_currency());
            $total = '0';
            $requires_save = true;
        } else {
            $currency = strtoupper(stackless_edd_current_currency());
            // EDD works out cart tax from the address fields in this request, which the form sends along.
            $total = stackless_edd_decimal(function_exists('edd_get_cart_total') ? (float) edd_get_cart_total() : 0.0, null, $currency);
            $requires_save = stackless_edd_cart_requires_saved_payment_method();
        }

        $minor = Money::minor($total, $currency);
        if ($minor === null) {
            self::refuse(Declines::message(['category' => '', 'code' => 'amount_precision']));
        }
        $token = Browser::clientToken(Config::mode(), $currency);
        if (is_wp_error($token)) {
            self::refuse($token->get_error_message());
        }
        wp_send_json_success([
            'token' => $token,
            'currency' => $currency,
            'amount' => Money::value((int) $minor, $currency),
            'requiresSave' => $requires_save,
        ]);
    }

    /** @return never */
    private static function refuse(string $message)
    {
        wp_send_json_error(['message' => $message]);
    }
}
