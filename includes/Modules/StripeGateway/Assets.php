<?php

namespace StacklessEDD\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Script and style registration. Stripe.js always loads from js.stripe.com (required for PCI
 * SAQ-A); our scripts are plain ES2017 with no build step.
 */
final class Assets
{
    public const STRIPE_JS = 'stackless-edd-stripe_gateway-stripejs';
    public const COMMON = 'stackless-edd-stripe_gateway-common';
    public const CHECKOUT = 'stackless-edd-stripe_gateway-checkout';
    public const EXPRESS = 'stackless-edd-stripe_gateway-express';
    public const STYLE = 'stackless-edd-stripe_gateway-style';

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
        $base = STACKLESS_EDD_PLUGIN_URL . 'assets/stripe_gateway/';
        $version = defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : '0';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe.js must never be versioned or self-hosted.
        wp_register_script(self::STRIPE_JS, 'https://js.stripe.com/v3/', [], null, true);
        wp_register_script(self::COMMON, $base . 'common.js', [self::STRIPE_JS], $version, true);
        wp_localize_script(self::COMMON, 'stacklessEddStripeConfig', self::config());
        wp_register_script(self::CHECKOUT, $base . 'checkout.js', ['jquery', self::COMMON], $version, true);
        wp_register_script(self::EXPRESS, $base . 'express.js', [self::COMMON], $version, true);
        wp_register_style(self::STYLE, $base . 'stripe.css', [], $version);
    }

    /** EDD's checkout (shortcode or block): the purchase form can switch to this gateway over AJAX at any time. */
    public static function enqueueFrontend(): void
    {
        if (!self::gatewayReady() || !function_exists('edd_is_checkout') || !edd_is_checkout()) {
            return;
        }
        self::enqueueCheckout();
    }

    public static function enqueueCheckout(): void
    {
        self::register();
        wp_enqueue_script(self::CHECKOUT);
        wp_enqueue_style(self::STYLE);
    }

    public static function enqueueExpress(): void
    {
        self::register();
        wp_enqueue_script(self::EXPRESS);
        wp_enqueue_style(self::STYLE);
    }

    public static function gatewayReady(): bool
    {
        return Module::gateway()->isEnabled() && Settings::isConfigured();
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return [
            'gatewayId' => Settings::GATEWAY_ID,
            'publishableKey' => Settings::publishableKey(),
            'testMode' => Settings::isTestMode(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'verifyAction' => Endpoints::VERIFY_ACTION,
            'confirmPrefix' => Gateway::CONFIRM_PREFIX,
            'fields' => [
                'confirmationToken' => Gateway::FIELD_CONFIRMATION_TOKEN,
                'token' => Gateway::FIELD_TOKEN,
                'save' => Gateway::FIELD_SAVE,
            ],
            'appearance' => Settings::appearance(),
            'zeroDecimal' => Amount::ZERO_DECIMAL,
            'threeDecimal' => Amount::THREE_DECIMAL,
            'wholeUnits' => Amount::WHOLE_UNITS_TWO_DECIMAL,
            'expressNonce' => wp_create_nonce(Express::NONCE),
            'expressActions' => [
                'update' => Express::ACTION_UPDATE,
                'checkout' => Express::ACTION_CHECKOUT,
                'cancel' => Express::ACTION_CANCEL,
            ],
            'buttonType' => Settings::expressButtonType(),
            'requirePhone' => false,
            'storeName' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'i18n' => [
                'genericError' => ErrorMapper::generic(),
                'enterDetails' => __('Enter your payment details to continue.', 'stackless-lite-edd'),
                'chooseOptions' => __('Choose a price option before paying.', 'stackless-lite-edd'),
                'savedForRenewals' => __('Your payment details will be saved securely by Stripe and used for future renewals.', 'stackless-lite-edd'),
                'processing' => __('Processing payment…', 'stackless-lite-edd'),
                'testCard' => __('Test mode: use card 4242 4242 4242 4242 with any future expiry date and any CVC.', 'stackless-lite-edd'),
                'totalChanged' => __('The total changed after taxes were added. Check the new total and try again.', 'stackless-lite-edd'),
            ],
        ];
    }
}
