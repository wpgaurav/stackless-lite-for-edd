<?php

namespace StacklessEDD\Modules\RevolutGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Revolut gateway module: Revolut's hosted page for one-time payments, Revolut's checkout
 * widget where a card must be saved for merchant use, automatic renewals through
 * Contracts\SubscriptionGateway, refunds both ways and disputes.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return Keys::MODULE_ID;
    }

    public function title(): string
    {
        return __('Revolut', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards, Revolut Pay and the other methods switched on in your Revolut merchant account on Revolut\'s hosted page, save cards for subscriptions and renew them automatically. Refunds, payment updates and disputes sync from Revolut.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
        Tokens::install();
    }

    public function boot(): void
    {
        Gateways::add(new Gateway());

        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'maybeRoute'], 1);
        add_action(Jobs::HOOK, [Jobs::class, 'run']);
        add_action('stackless_edd_settings_saved_' . Keys::MODULE_ID, [$this, 'settingsSaved']);
        add_filter('stackless_edd_health_sources', [$this, 'healthSource']);
        add_filter('stackless_edd_payment_alerts_gateways', [$this, 'alertRoute']);
        add_action('admin_init', [$this, 'privacyPolicy']);

        Tokens::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
    }

    /** The gateway's own pages: the checkout widget and the return from Revolut. */
    public function maybeRoute(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signature is checked in Pages::parse().
        if (isset($_GET[Pages::ACTION])) {
            Pages::route();
        }
    }

    /**
     * @param mixed $sources
     * @return array<string,mixed>
     */
    public function healthSource($sources): array
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Keys::GATEWAY_ID] = [Health::class, 'report'];
        return $sources;
    }

    /**
     * @param mixed $routes
     * @return array<string,mixed>
     */
    public function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['revolut'] = Health::route() + ['module' => Keys::MODULE_ID];
        return $routes;
    }

    /** Suggested text for the store's privacy policy. */
    public function privacyPolicy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Stackless: Revolut', 'stackless-lite-edd'),
            wp_kses_post('<p>' . __('When you pay with Revolut, we send Revolut your name, email address, and the items and amount of the order. Card details are entered on Revolut\'s page or checkout window and never reach our servers. For subscriptions, Revolut saves the card so we can charge renewals; we keep its brand, last four digits and expiry date.', 'stackless-lite-edd') . '</p>')
        );
    }

    /* ---- Settings ------------------------------------------------------------- */

    public function settingsFields(): array
    {
        $p = Settings::PREFIX;
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'type' => 'title',
                'id' => $p . 'keys_section',
                'title' => __('Revolut account', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: Revolut Business link, 2: Revolut sandbox link, 3: link to the EDD payment gateways screen */
                    __('Create a Merchant API secret key in <a href="%1$s" target="_blank" rel="noopener">Revolut Business</a> (or in the <a href="%2$s" target="_blank" rel="noopener">Revolut sandbox</a> for test mode) without request signing, with read and write access to orders, payments, customers and webhooks, and read access to disputes. Saving checks the key and sets the webhook up. Then turn on Revolut (Stackless) in %3$s.', 'stackless-lite-edd'),
                    'https://business.revolut.com/',
                    'https://sandbox-business.revolut.com/',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite-edd'), 'desc' => __('Use the Revolut sandbox (a separate sandbox account and key; no real money moves)', 'stackless-lite-edd'), 'default' => 'yes'],
        ];
        foreach (['test', 'live'] as $mode) {
            if (!Settings::isDefined($mode, 'secret_key')) {
                $fields[] = [
                    'type' => 'password',
                    'id' => $p . $mode . '_secret_key',
                    'title' => $mode === 'test' ? __('Sandbox secret key', 'stackless-lite-edd') : __('Live secret key', 'stackless-lite-edd'),
                    'placeholder' => 'sk_…',
                    'css' => 'min-width:420px',
                    'default' => '',
                ];
            }
            if (!Settings::isDefined($mode, 'webhook_secret')) {
                $fields[] = [
                    'type' => 'password',
                    'id' => $p . $mode . '_webhook_secret',
                    'title' => $mode === 'test' ? __('Sandbox webhook signing secret', 'stackless-lite-edd') : __('Live webhook signing secret', 'stackless-lite-edd'),
                    'placeholder' => 'wsk_…',
                    'css' => 'min-width:420px',
                    'default' => '',
                    'desc' => sprintf(
                        /* translators: %s: webhook URL */
                        __('Filled in when you save. For a webhook made by hand, use the URL %s and paste its signing secret.', 'stackless-lite-edd'),
                        // Not before init: rest_url() needs $wp_rewrite, and settings are also built while plugins load.
                        '<code>' . esc_html(did_action('init') ? Settings::webhookUrl($mode) : 'stackless-edd/v1' . Webhook::ROUTE . $mode) . '</code>'
                    ),
                ];
            }
        }
        return array_merge($fields, [
            ['type' => 'sectionend', 'id' => $p . 'keys_section'],

            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Card or Revolut Pay', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name buyers see at checkout.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('You\'ll finish paying on Revolut\'s secure checkout.', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite-edd'), 'desc' => __('Log Revolut activity to Downloads → Tools → Debug Log (stackless-edd-revolut_gateway). Keys are never logged. Errors are always logged.', 'stackless-lite-edd'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p . 'advanced_section'],

            [
                'type' => 'title',
                'id' => $p . 'limits_section',
                'title' => __('Good to know', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: %s: currency codes */
                    __('One-time payments use Revolut\'s hosted page. A subscription or free trial takes a card in Revolut\'s checkout window and saves it for renewals; a free trial checks the card for 0.00. Only cards can renew. Currencies: %s.', 'stackless-lite-edd'),
                    implode(', ', Money::CURRENCIES)
                ),
            ],
            ['type' => 'sectionend', 'id' => $p . 'limits_section'],
        ]);
    }

    /** After a save: check the keys with Revolut and set the webhooks up. */
    public function settingsSaved(): void
    {
        foreach (Connection::check() as $problem) {
            Fields::addError($problem);
        }
    }
}
