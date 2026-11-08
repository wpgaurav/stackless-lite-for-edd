<?php

namespace StacklessEDD\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Scripts for EDD's checkout (shortcode and block) and the account page's Add card form.
 * Square's Web Payments SDK is loaded by common.js on demand (sandbox or production URL from
 * the form's settings), so nothing loads from Square until the Square form is on the page.
 */
final class Assets
{
    public const COMMON = 'stackless-edd-square_gateway-common';
    public const CHECKOUT = 'stackless-edd-square_gateway-checkout';
    public const STYLE = 'stackless-edd-square_gateway-style';

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
        $base = STACKLESS_EDD_PLUGIN_URL . 'assets/square_gateway/';
        $version = defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : '0';
        wp_register_script(self::COMMON, $base . 'common.js', [], $version, true);
        wp_register_script(self::CHECKOUT, $base . 'checkout.js', ['jquery', self::COMMON], $version, true);
        wp_register_style(self::STYLE, $base . 'square.css', [], $version);
    }

    /** EDD's checkout: the purchase form can switch to this gateway over AJAX at any time. */
    public static function enqueueFrontend(): void
    {
        if (!function_exists('edd_is_checkout') || !edd_is_checkout()) {
            return;
        }
        $gateway = Gateway::instance();
        if (!$gateway->isEnabled() || $gateway->appId() === '') {
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
