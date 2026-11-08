<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;

defined('ABSPATH') || exit;

/**
 * Razorpay gateway module: Razorpay Checkout for UPI, cards, netbanking and wallets, and
 * automatic subscription renewals through card e-mandates, UPI Autopay and eNACH
 * (Contracts\SubscriptionGateway, SavedMethodCharger and AdvanceCharge).
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
        return __('Razorpay', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take UPI, cards, netbanking and wallets with Razorpay Checkout, and renew subscriptions automatically through card e-mandates, UPI Autopay and eNACH. Renewal debits start early to cover the bank\'s pre-debit notice, and settle, refund and sync through Razorpay\'s signed webhook.', 'stackless-lite-edd');
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
        add_action('template_redirect', [Checkout::class, 'route'], 1);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);
        add_action('edd_checkout_error_checks', [$gateway, 'validateFields'], 10, 2);
        add_filter('edd_payment_details_transaction_id-' . Gateway::ID, [$gateway, 'linkTransaction'], 10, 2);

        // Zero-total carts whose subscription renews automatically still save a mandate here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        Tokens::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
        Health::registerHooks();

        add_action('stackless_edd_settings_saved_' . Settings::MODULE_ID, [$this, 'settingsSaved']);
        add_filter('stackless_edd_settings_sanitize_option_' . Settings::PREFIX . 'lead_days', [self::class, 'sanitizeLeadDays']);
        add_filter('stackless_edd_settings_sanitize_option_' . Settings::PREFIX . 'switch_allowance', [self::class, 'sanitizeAllowance']);
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

    /* ---- Settings ------------------------------------------------------------- */

    public function settingsFields(): array
    {
        $p = Settings::PREFIX;
        $fields = [
            [
                'type' => 'title',
                'id' => $p . 'keys_section',
                'title' => __('Razorpay account', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: %s: link to the Razorpay API keys page */
                    __('Generate keys in <a href="%s" target="_blank" rel="noopener">Razorpay → Account & Settings → API keys</a>, once in Test mode and once in Live mode. Keys are checked with Razorpay when you save. Turn the gateway on in Downloads → Settings → Payments.', 'stackless-lite-edd'),
                    'https://dashboard.razorpay.com/app/website-app-settings/api-keys'
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite-edd'), 'desc' => __('Use Razorpay test keys (no real money moves)', 'stackless-lite-edd'), 'default' => 'yes'],
        ];
        foreach (['test', 'live'] as $mode) {
            $label = $mode === 'test' ? __('Test', 'stackless-lite-edd') : __('Live', 'stackless-lite-edd');
            $names = ['key_id' => __('key id', 'stackless-lite-edd'), 'key_secret' => __('key secret', 'stackless-lite-edd'), 'webhook_secret' => __('webhook secret', 'stackless-lite-edd')];
            foreach (Settings::KEY_FIELDS as $field) {
                if (Settings::isDefined($mode, $field)) {
                    continue;
                }
                $fields[] = [
                    'type' => $field === 'key_id' ? 'text' : 'password',
                    'id' => $p . $mode . '_' . $field,
                    'title' => $label . ' ' . $names[$field],
                    'placeholder' => $field === 'key_id' ? 'rzp_' . $mode . '_…' : '',
                    'css' => 'min-width:420px',
                    'default' => '',
                ];
            }
        }
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'keys_section'];

        $fields[] = [
            'type' => 'title',
            'id' => $p . 'webhook_section',
            'title' => __('Webhook', 'stackless-lite-edd'),
            'desc' => self::webhookInstructions(),
        ];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'webhook_section'];

        $fields[] = ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')];
        $fields[] = ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('UPI, cards, netbanking and wallets', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite-edd')];
        $fields[] = ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('Pay securely with Razorpay.', 'stackless-lite-edd')];
        $fields[] = ['type' => 'color', 'id' => $p . 'theme_color', 'title' => __('Checkout color', 'stackless-lite-edd'), 'default' => '', 'css' => 'width:8em'];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'checkout_section'];

        $fields[] = [
            'type' => 'title',
            'id' => $p . 'recurring_section',
            'title' => __('Subscriptions', 'stackless-lite-edd'),
            'desc' => __('Automatic renewals use Razorpay recurring tokens, which Razorpay switches on for your account on request. Renewals are charged in rupees only.', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'type' => 'multiselect',
            'id' => $p . 'recurring_methods',
            'title' => __('Renewal methods', 'stackless-lite-edd'),
            'options' => Mandate::labels(),
            'default' => Mandate::METHODS,
            'desc' => __('Methods buyers can choose for subscriptions. Card mandates cover renewals up to ₹15,000, UPI Autopay up to ₹99,999 and eNACH up to ₹1 crore.', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'type' => 'number',
            'id' => $p . 'switch_allowance',
            'title' => __('Plan-switch allowance (%)', 'stackless-lite-edd'),
            'default' => (string) Settings::DEFAULT_ALLOWANCE,
            'custom_attributes' => ['min' => 0, 'max' => 500, 'step' => 1],
            'desc' => __('The mandate covers the largest renewal plus this much, so upgrades keep renewing without a new mandate.', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'type' => 'number',
            'id' => $p . 'lead_days',
            'title' => __('Start renewal charges early (days)', 'stackless-lite-edd'),
            'default' => (string) Settings::DEFAULT_LEAD_DAYS,
            'custom_attributes' => ['min' => 1, 'max' => 7, 'step' => 1],
            'desc' => __('Razorpay notifies the customer at least 24 hours before each debit, card debits take about 36 hours and eNACH two working days, so renewals start this many days before they are due.', 'stackless-lite-edd'),
        ];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'recurring_section'];

        $fields[] = ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite-edd')];
        $fields[] = ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite-edd'), 'desc' => __('Log Razorpay activity to Downloads → Tools → Debug Log (stackless-edd-razorpay_gateway). Keys are never logged. Errors are always logged.', 'stackless-lite-edd'), 'default' => 'no'];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'advanced_section'];
        return $fields;
    }

    private static function webhookInstructions(): string
    {
        $url = Settings::webhookUrl();
        $problem = class_exists(\StacklessEDD\Core\WebhookAddress::class) ? \StacklessEDD\Core\WebhookAddress::problem($url, 'Razorpay') : '';
        return sprintf(
            /* translators: 1: link to the Razorpay webhooks page, 2: webhook URL, 3: event names */
            __('Razorpay has no API for adding webhooks to your own account, so add it once per mode: in <a href="%1$s" target="_blank" rel="noopener">Account & Settings → Webhooks</a> choose Add New Webhook, paste this URL: %2$s pick a secret, tick these events, and enter the same secret above: %3$s Webhooks confirm payments when a buyer closes the tab, settle renewals and bring refunds made in Razorpay into EDD.', 'stackless-lite-edd'),
            'https://dashboard.razorpay.com/app/website-app-settings/webhooks',
            '<br><code>' . esc_html($url) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        ) . ($problem !== '' ? ' ' . esc_html($problem) : '');
    }

    /** After a save: check each mode's keys with Razorpay, and say what is missing once the gateway is on. */
    public function settingsSaved(): void
    {
        foreach (['live', 'test'] as $mode) {
            $keyId = Settings::keyId($mode);
            if ($keyId === '' && Settings::keySecret($mode) === '') {
                continue;
            }
            if (!Api::keyMatchesMode($keyId, $mode)) {
                Fields::addError($mode === 'test'
                    ? __('The Razorpay test key id must start with rzp_test_.', 'stackless-lite-edd')
                    : __('The Razorpay live key id must start with rzp_live_.', 'stackless-lite-edd'));
                continue;
            }
            $check = Settings::api($mode)->request('GET', 'payments', ['count' => 1]);
            if (is_wp_error($check)) {
                Fields::addError($check->get_error_message());
            }
        }
        if (self::gateway()->isEnabled()) {
            if (!Settings::hasKeys()) {
                Fields::addError(__('Razorpay is turned on but the selected mode has no key id and secret.', 'stackless-lite-edd'));
            }
            if (Settings::webhookSecret() === '') {
                Fields::addError(__('Add the Razorpay webhook secret. Renewals and refunds stay in sync through the webhook.', 'stackless-lite-edd'));
            }
        }
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitizeLeadDays($value)
    {
        $days = is_numeric($value) ? (int) $value : Settings::DEFAULT_LEAD_DAYS;
        return (string) max(1, min(7, $days > 0 ? $days : Settings::DEFAULT_LEAD_DAYS));
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitizeAllowance($value)
    {
        return (string) max(0, min(500, is_numeric($value) ? (int) $value : Settings::DEFAULT_ALLOWANCE));
    }
}
