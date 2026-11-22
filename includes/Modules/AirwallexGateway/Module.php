<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;
use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/**
 * Airwallex gateway module: Airwallex's hosted Checkout Session page, saved card consents with
 * automatic renewals through Contracts\SubscriptionGateway, refunds both ways, disputes, and a
 * signed webhook.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return Settings::MODULE_ID;
    }

    public function title(): string
    {
        return __('Airwallex (beta)', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards, wallets and local payment methods on Airwallex\'s hosted payment page, save cards, and renew subscriptions automatically. Refunds, disputes and payment updates sync from Airwallex.', 'stackless-lite-edd');
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
        add_action('template_redirect', [Returns::class, 'handle'], 1);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);

        // Zero-total carts whose subscription renews automatically still save a card here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        add_action('stackless_edd_settings_saved_' . Settings::MODULE_ID, [$this, 'settingsSaved']);

        Tokens::registerHooks();
        Account::registerHooks();
        AdminRefunds::registerHooks();
        Health::registerHooks();
        Privacy::registerHooks();
    }

    /* ---- Settings ------------------------------------------------------------- */

    public function settingsFields(): array
    {
        $p = Settings::PREFIX;
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'type' => 'title',
                'id' => $p . 'mode_section',
                'title' => __('Airwallex (beta)', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: %s: link to the EDD payment gateways screen */
                    __('Connect an account below, then turn on Airwallex Beta (Stackless) in %s.', 'stackless-lite-edd'),
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite-edd'), 'desc' => __('Use the Airwallex sandbox (no real money moves)', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'mode_section'],
        ];

        $sections = [
            'live' => [__('Live account', 'stackless-lite-edd'), 'https://www.airwallex.com/app/'],
            'test' => [__('Sandbox account', 'stackless-lite-edd'), 'https://www.sandbox.airwallex.com/app/'],
        ];
        foreach ($sections as $mode => [$heading, $url]) {
            $fields[] = [
                'type' => 'title',
                'id' => $p . $mode . '_section',
                'title' => $heading,
                'desc' => sprintf(
                    /* translators: %s: Airwallex web app URL */
                    __('In <a href="%s" target="_blank" rel="noopener">Airwallex</a> → Developer → API keys create a scoped key with Payment Acceptance (write), Checkout Sessions (write) and Payment Disputes (read). The account ID is under Settings → Account details.', 'stackless-lite-edd'),
                    esc_url($url)
                ),
            ];
            $labels = [
                'client_id' => [__('Client ID', 'stackless-lite-edd'), 'text'],
                'api_key' => [__('API key', 'stackless-lite-edd'), 'password'],
                'account_id' => [__('Account ID', 'stackless-lite-edd'), 'text'],
                'webhook_secret' => [__('Webhook secret', 'stackless-lite-edd'), 'password'],
            ];
            foreach ($labels as $key => [$label, $type]) {
                if (!Settings::isDefined($mode, $key)) {
                    $fields[] = ['type' => $type, 'id' => $p . $mode . '_' . $key, 'title' => $label, 'css' => 'min-width:420px', 'default' => ''];
                }
            }
            $fields[] = ['type' => 'checkbox', 'id' => $p . $mode . '_login_as', 'title' => __('Several accounts', 'stackless-lite-edd'), 'desc' => __('This API key covers more than one account (sign in as the account ID above)', 'stackless-lite-edd'), 'default' => 'no'];
            $fields[] = ['type' => 'sectionend', 'id' => $p . $mode . '_section'];
        }

        // The REST URL needs the rewrite rules, which do not exist before init.
        $webhook = did_action('init') ? Settings::webhookUrl() : Webhook::REST_NAMESPACE . Webhook::ROUTE;
        return array_merge($fields, [
            [
                'type' => 'title',
                'id' => $p . 'webhook_section',
                'title' => __('Webhook', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: webhook URL, 2: API version, 3: event names */
                    __('In Developer → Webhooks, in the sandbox and in the live account, add %1$s with API version %2$s and these events: %3$s. Each webhook has its own secret. Test events sent from Airwallex use another secret, so this site refuses them.', 'stackless-lite-edd'),
                    '<code>' . esc_html($webhook) . '</code>',
                    '<code>' . esc_html(Api::VERSION) . '</code>',
                    '<code>' . implode('</code>, <code>', array_map('esc_html', Webhook::EVENTS)) . '</code>'
                ),
            ],
            ['type' => 'sectionend', 'id' => $p . 'webhook_section'],

            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Card and more payment methods', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name buyers see at checkout.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('You\'ll finish paying on Airwallex\'s secure payment page.', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite-edd'), 'desc' => __('Log Airwallex activity to Downloads → Tools → Debug Log (stackless-edd-airwallex_gateway). Keys are never logged. Errors are always logged.', 'stackless-lite-edd'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p . 'advanced_section'],

            [
                'type' => 'title',
                'id' => $p . 'limits_section',
                'title' => __('Good to know', 'stackless-lite-edd'),
                'desc' => __('Subscriptions offer cards only, and a free trial saves the card without a charge. Turn Automatic Currency Conversion off in Airwallex → Payments → Settings: a payment in another currency cannot match its order and is held for review. Airwallex labels the Checkout Session API as beta.', 'stackless-lite-edd'),
            ],
            ['type' => 'sectionend', 'id' => $p . 'limits_section'],
        ]);
    }

    /** After a save: check each connected account with Airwallex. */
    public function settingsSaved(): void
    {
        $enabled = Settings::enabled();
        foreach (Connection::problems($enabled) as $problem) {
            Fields::addError($problem);
        }
        if ($enabled) {
            $unreachable = WebhookAddress::problem(Settings::webhookUrl(), 'Airwallex');
            if ($unreachable !== '') {
                Fields::addError($unreachable);
            }
        }
    }
}
