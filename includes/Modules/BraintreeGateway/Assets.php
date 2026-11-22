<?php

namespace StacklessEDD\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Scripts for EDD's checkout (shortcode and block) and the account page's Add card form. The
 * Braintree SDK is loaded by common.js, so nothing loads from Braintree until the form is shown.
 */
final class Assets
{
    public const COMMON = 'stackless-edd-braintree_gateway-common';
    public const CHECKOUT = 'stackless-edd-braintree_gateway-checkout';
    public const STYLE = 'stackless-edd-braintree_gateway-style';

    /** @var bool */
    private static $registered = false;

    public static function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontend'], 20);
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        $base = STACKLESS_EDD_PLUGIN_URL . 'assets/braintree_gateway/';
        $version = defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : '0';
        wp_register_script(self::COMMON, $base . 'common.js', [], $version, true);
        wp_register_script(self::CHECKOUT, $base . 'checkout.js', ['jquery', self::COMMON], $version, true);
        wp_register_style(self::STYLE, $base . 'braintree.css', [], $version);
    }

    /** EDD's checkout: the purchase form can switch to this gateway over AJAX at any time. */
    public static function enqueueFrontend(): void
    {
        if (!function_exists('edd_is_checkout') || !edd_is_checkout()) {
            return;
        }
        if (!Config::hasKeys(Config::mode()) || !Gateway::instance()->isEnabled()) {
            return;
        }
        self::enqueue();
    }

    public static function enqueue(): void
    {
        self::register();
        wp_enqueue_script(self::CHECKOUT);
        wp_enqueue_style(self::STYLE);
    }
}
