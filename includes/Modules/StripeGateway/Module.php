<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Core\Gateways;

defined('ABSPATH') || exit;

/**
 * Stripe gateway module: our own Stripe integration for Easy Digital Downloads (no Stripe
 * add-on, no SDK). Payment Element and Express Checkout Element at checkout and on download
 * pages, saved methods, SCA, refunds, webhooks, and automatic renewals through
 * Contracts\SubscriptionGateway. EDD's built-in Stripe gateway keeps working next to it.
 */
final class Module extends \StacklessEDD\Core\Module
{
    /** @var Gateway|null */
    private static $fallbackGateway;

    public function id(): string
    {
        return Settings::MODULE_ID;
    }

    public function title(): string
    {
        return __('Stripe', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards, Apple Pay, Google Pay, Link and local payment methods with Stripe, save cards, and renew subscriptions automatically.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function conflicts(): array
    {
        // EDD's own Stripe gateway can run alongside: different gateway id, customers, tokens and webhooks.
        return [];
    }

    public function install(): void
    {
        EventStore::install();
        Tokens::install();
    }

    public function boot(): void
    {
        $gateway = new Gateway();
        Gateways::add($gateway);

        add_action('rest_api_init', [Webhooks::class, 'register']);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);
        add_action('edd_transition_order_status', [$gateway, 'onStatusChange'], 10, 3);
        add_action('edd_checkout_error_checks', [$gateway, 'validateFields'], 10, 2);

        // Zero-total carts whose subscription renews automatically still save a method here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        Tokens::registerHooks();
        Endpoints::registerHooks();
        Assets::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
        add_action('init', [Express::class, 'registerHooks']);

        if (is_admin()) {
            Admin::registerHooks();
        }
    }

    /** The registered gateway object, or a standalone one when the module is off (staging scripts, CLI). */
    public static function gateway(): Gateway
    {
        $registered = Gateways::get(Settings::GATEWAY_ID);
        if ($registered instanceof Gateway) {
            return $registered;
        }
        if (!self::$fallbackGateway) {
            self::$fallbackGateway = new Gateway();
        }
        return self::$fallbackGateway;
    }

    public function settingsFields(): array
    {
        $p = Settings::PREFIX;
        $webhook = esc_url(Settings::webhookUrl());
        return [
            [
                'type' => 'title',
                'id' => $p . 'keys_section',
                'title' => __('Stripe account', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: link to Stripe API keys page, 2: webhook URL */
                    __('Copy your keys from <a href="%1$s" target="_blank" rel="noopener">Stripe → Developers → API keys</a>. Keys are checked with Stripe when you save. Leave the signing secrets empty and use Set up the webhook in Stripe under Status below, or add the endpoint yourself with this URL for both modes: <code>%2$s</code> A restricted key needs Webhook Endpoints set to Write for the one-click setup.', 'stackless-lite-edd'),
                    'https://dashboard.stripe.com/apikeys',
                    $webhook
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite-edd'), 'desc' => __('Use test keys. Only Stripe test cards work; no real money moves.', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'test_publishable_key', 'title' => __('Test publishable key', 'stackless-lite-edd'), 'placeholder' => 'pk_test_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'test_secret_key', 'title' => __('Test secret key', 'stackless-lite-edd'), 'placeholder' => 'sk_test_… / rk_test_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'test_webhook_secret', 'title' => __('Test webhook signing secret', 'stackless-lite-edd'), 'placeholder' => 'whsec_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'text', 'id' => $p . 'live_publishable_key', 'title' => __('Live publishable key', 'stackless-lite-edd'), 'placeholder' => 'pk_live_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'live_secret_key', 'title' => __('Live secret key', 'stackless-lite-edd'), 'placeholder' => 'sk_live_… / rk_live_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'live_webhook_secret', 'title' => __('Live webhook signing secret', 'stackless-lite-edd'), 'placeholder' => 'whsec_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'keys_section'],

            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Card and more', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('Pay securely with your card, Apple Pay, Google Pay, Link or a local payment method.', 'stackless-lite-edd')],
            [
                'type' => 'select',
                'id' => $p . 'capture',
                'title' => __('Capture', 'stackless-lite-edd'),
                'default' => 'now',
                'options' => [
                    'now' => __('Charge immediately', 'stackless-lite-edd'),
                    'authorize' => __('Authorize only, capture when the order is marked Completed', 'stackless-lite-edd'),
                ],
                'desc' => __('Orders with subscriptions always charge immediately. Authorized orders wait On hold; authorizations expire after 7 days.', 'stackless-lite-edd'),
            ],
            ['type' => 'checkbox', 'id' => $p . 'saved_cards', 'title' => __('Saved payment methods', 'stackless-lite-edd'), 'desc' => __('Let signed-in customers save and reuse payment methods. Subscription orders always save the method for renewals.', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'statement_descriptor_suffix', 'title' => __('Statement descriptor suffix', 'stackless-lite-edd'), 'default' => '', 'custom_attributes' => ['maxlength' => 22], 'desc_tip' => __('Added after your account\'s shortened descriptor on card statements, e.g. an order prefix. Letters required; no < > \\ \' " * characters.', 'stackless-lite-edd')],
            [
                'type' => 'select',
                'id' => $p . 'appearance',
                'title' => __('Payment form style', 'stackless-lite-edd'),
                'default' => 'site',
                'options' => [
                    'site' => __('Match my theme (colors, font, light or dark)', 'stackless-lite-edd'),
                    'stripe' => __('Stripe default', 'stackless-lite-edd'),
                    'flat' => __('Flat', 'stackless-lite-edd'),
                    'night' => __('Night (dark)', 'stackless-lite-edd'),
                ],
            ],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            [
                'type' => 'title',
                'id' => $p . 'express_section',
                'title' => __('Express checkout', 'stackless-lite-edd'),
                'desc' => __('Apple Pay, Google Pay and Link buttons. Apple Pay also needs your domain registered in Stripe → Settings → Payment method domains.', 'stackless-lite-edd'),
            ],
            [
                'type' => 'multiselect',
                'id' => $p . 'express_locations',
                'title' => __('Show buttons on', 'stackless-lite-edd'),
                'default' => ['product', 'checkout'],
                'options' => [
                    'product' => __('Download pages', 'stackless-lite-edd'),
                    'checkout' => __('Checkout', 'stackless-lite-edd'),
                ],
            ],
            [
                'type' => 'select',
                'id' => $p . 'express_button_type',
                'title' => __('Button label', 'stackless-lite-edd'),
                'default' => 'buy',
                'options' => [
                    'plain' => __('Logo only', 'stackless-lite-edd'),
                    'buy' => __('Buy with', 'stackless-lite-edd'),
                    'checkout' => __('Checkout with', 'stackless-lite-edd'),
                    'order' => __('Order with', 'stackless-lite-edd'),
                    'pay' => __('Pay with', 'stackless-lite-edd'),
                ],
            ],
            ['type' => 'sectionend', 'id' => $p . 'express_section'],

            ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite-edd'), 'desc' => __('Log API calls and webhook results to Downloads → Tools → Debug Log (stackless-edd-stripe_gateway). Keys and card data are never logged. Errors are always logged.', 'stackless-lite-edd'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p . 'advanced_section'],
        ];
    }
}
