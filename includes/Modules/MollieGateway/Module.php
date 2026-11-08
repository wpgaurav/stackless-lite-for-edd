<?php

namespace StacklessEDD\Modules\MollieGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Mollie gateway module: Mollie's hosted checkout with every method on the Mollie website
 * profile, saved card, PayPal and SEPA Direct Debit mandates, automatic renewals through
 * Contracts\SubscriptionGateway, one-click charges through Contracts\SavedMethodCharger, and
 * refunds both ways. Mollie reports every payment to this site by itself (the webhook URL travels
 * with each payment), so there is no webhook to set up.
 */
final class Module extends BaseModule
{
    /** @var Gateway|null */
    private static $fallbackGateway;

    public function id(): string
    {
        return Settings::MODULE_ID;
    }

    public function title(): string
    {
        return __('Mollie', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards, iDEAL, Bancontact, PayPal, Apple Pay and every other method switched on in Mollie on Mollie\'s hosted checkout, save cards, PayPal and SEPA Direct Debit mandates, and renew subscriptions automatically. Refunds and payment updates sync from Mollie.', 'stackless-lite-edd');
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
        $gateway = new Gateway();
        Gateways::add($gateway);

        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'maybeHandleReturn'], 1);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);

        // Zero-total carts whose subscription renews automatically still save a mandate here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        add_action('stackless_edd_settings_saved_' . Settings::MODULE_ID, [$this, 'settingsSaved']);

        Tokens::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
        Health::registerHooks();
    }

    /** The registered gateway object, or a standalone one when the module is off (staging scripts, CLI). */
    public static function gateway(): Gateway
    {
        $registered = Gateways::get(Gateway::ID);
        if ($registered instanceof Gateway) {
            return $registered;
        }
        if (!self::$fallbackGateway) {
            self::$fallbackGateway = new Gateway();
        }
        return self::$fallbackGateway;
    }

    /** Buyers coming back from Mollie's hosted page. */
    public function maybeHandleReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed key is checked in Checkout::handleReturn().
        if (!isset($_GET[Checkout::RETURN_ARG])) {
            return;
        }
        (new Checkout(self::gateway()))->handleReturn();
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
                'title' => __('Mollie account', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: link to the Mollie API keys page, 2: link to the EDD payment gateways screen */
                    __('Copy the live key (live_…) and the test key (test_…) of your website profile from <a href="%1$s" target="_blank" rel="noopener">Mollie → Developers → API keys</a>, then turn on Mollie (Stackless) in %2$s. Mollie calls this site for every payment by itself, so there is no webhook to set up.', 'stackless-lite-edd'),
                    'https://my.mollie.com/dashboard/developers/api-keys',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite-edd'), 'desc' => __('Use the Mollie test API key (no real money moves; test payments take euros only)', 'stackless-lite-edd'), 'default' => 'yes'],
        ];
        foreach (['test', 'live'] as $mode) {
            if (Settings::isDefined($mode)) {
                continue;
            }
            $fields[] = [
                'type' => 'password',
                'id' => $p . $mode . '_api_key',
                'title' => $mode === 'test' ? __('Test API key', 'stackless-lite-edd') : __('Live API key', 'stackless-lite-edd'),
                'placeholder' => $mode . '_…',
                'css' => 'min-width:420px',
                'default' => '',
            ];
        }
        return array_merge($fields, [
            ['type' => 'sectionend', 'id' => $p . 'keys_section'],

            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Card, iDEAL, Bancontact or PayPal', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name buyers see at checkout.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('You\'ll finish paying on Mollie\'s secure checkout.', 'stackless-lite-edd')],
            [
                'type' => 'multiselect',
                'id' => $p . 'methods',
                'title' => __('Methods to offer', 'stackless-lite-edd'),
                'options' => Methods::labels(),
                'default' => [],
                'desc' => __('Leave empty to offer every method switched on in Mollie for the order\'s currency and amount. Subscriptions only see methods that can save a mandate.', 'stackless-lite-edd'),
            ],
            [
                'type' => 'select',
                'id' => $p . 'method_picker',
                'title' => __('Method choice', 'stackless-lite-edd'),
                'default' => 'checkout',
                'options' => [
                    'checkout' => __('Let buyers pick the method at checkout, then go straight to it', 'stackless-lite-edd'),
                    'mollie' => __('Let buyers pick on Mollie\'s page', 'stackless-lite-edd'),
                ],
            ],
            ['type' => 'checkbox', 'id' => $p . 'saved_methods', 'title' => __('Saved payment methods', 'stackless-lite-edd'), 'desc' => __('Let signed-in customers save cards, PayPal and bank mandates for later. Subscription orders always save the method for renewals.', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite-edd'), 'desc' => __('Log Mollie activity to Downloads → Tools → Debug Log (stackless-edd-mollie_gateway). Keys are never logged. Errors are always logged.', 'stackless-lite-edd'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p . 'advanced_section'],

            [
                'type' => 'title',
                'id' => $p . 'limits_section',
                'title' => __('Good to know', 'stackless-lite-edd'),
                'desc' => __('Saving a method for a free trial uses a 0.00 card or PayPal payment, or a one-cent payment for iDEAL and the other bank methods, which is refunded once Mollie confirms the mandate. Bank mandates renew by SEPA Direct Debit in euros and settle a few days after the charge, so those renewals stay pending until Mollie confirms them. Pay-later methods such as Klarna leave the order on hold until you capture the payment in Mollie. Test mode takes euros only.', 'stackless-lite-edd'),
            ],
            ['type' => 'sectionend', 'id' => $p . 'limits_section'],
        ]);
    }

    /** After a save: check each key against its mode and with Mollie, as the WooCommerce edition did. */
    public function settingsSaved(): void
    {
        foreach (self::keyErrors() as $error) {
            Fields::addError($error);
        }
    }

    /** @return string[] */
    public static function keyErrors(): array
    {
        $errors = [];
        foreach (['live', 'test'] as $mode) {
            $key = Settings::apiKey($mode);
            if ($key === '') {
                continue;
            }
            if (!Api::keyMatchesMode($key, $mode)) {
                $errors[] = $mode === 'test'
                    ? __('The Mollie test API key must start with test_.', 'stackless-lite-edd')
                    : __('The Mollie live API key must start with live_.', 'stackless-lite-edd');
                continue;
            }
            $check = Settings::api($mode)->request('GET', 'methods', ['amount' => ['value' => '10.00', 'currency' => 'EUR']]);
            if (is_wp_error($check)) {
                $errors[] = $check->get_error_message();
            }
        }
        if (function_exists('edd_is_gateway_active') && edd_is_gateway_active(Gateway::ID) && Settings::apiKey() === '') {
            $errors[] = __('Mollie is enabled but has no API key for the selected mode.', 'stackless-lite-edd');
        }
        return $errors;
    }
}
