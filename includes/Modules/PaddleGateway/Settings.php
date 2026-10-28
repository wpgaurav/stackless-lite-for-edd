<?php

namespace StacklessEDD\Modules\PaddleGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Settings of the Paddle gateway and each copy, on Stackless → Settings → Paddle.
 *
 * The original stores stackless_edd_paddle_gateway_{key}; copy N stores the same keys with a
 * "_copyN" suffix. The owner switches each one on for checkout in Downloads → Settings → Payments.
 */
final class Settings
{
    public const PREFIX = 'stackless_edd_paddle_gateway_';

    /** Every per-gateway setting key (a copy's option is PREFIX . key . "_copyN"). */
    public const KEYS = [
        'title',
        'description',
        'testmode',
        'live_api_key',
        'live_client_token',
        'live_webhook_secret',
        'sandbox_api_key',
        'sandbox_client_token',
        'sandbox_webhook_secret',
        'tax_mode',
        'tax_category',
        'debug',
    ];

    /** @var array<string,string> option name => value before the current save */
    private static $previous = [];

    public static function id(string $key, string $gatewayId): string
    {
        return self::PREFIX . $key . Copies::suffix($gatewayId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function fields(): array
    {
        $fields = [];
        foreach (Gateway::all() as $gateway) {
            $fields = array_merge($fields, self::gatewayFields($gateway));
        }
        return $fields;
    }

    /** Whether the Paddle settings screen is being shown (panels that cost a query render only there). */
    public static function onScreen(): bool
    {
        if (!is_admin()) {
            return false;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $module = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : '';
        // phpcs:enable
        return $page === SettingsPage::SETTINGS && in_array($module, ['', 'paddle_gateway'], true);
    }

    /** @return array<int,array<string,mixed>> */
    private static function gatewayFields(Gateway $gateway): array
    {
        $id = $gateway->id();
        // Write includes read in Paddle, so these cover every call Stackless makes; the last one only
        // the one-click webhook setup.
        $permissions = 'transaction.write, subscription.write, adjustment.write, ' . WebhookSetup::PERMISSION;
        $badge = Copies::badge($id);
        $onScreen = self::onScreen();
        // Payment Gateway Tools fills this in on the settings screen: duplicate, rename, remove.
        $copies = $onScreen ? (string) apply_filters('stackless_edd_gateway_copies_panel', '', $id) : '';
        $section = self::id('section', $id);
        $paymentsUrl = function_exists('edd_get_admin_url') ? edd_get_admin_url(['page' => 'edd-settings', 'tab' => 'gateways']) : admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');

        $fields = [
            [
                'id' => $section,
                'type' => 'title',
                'title' => __('Paddle', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : ''),
                'desc' => sprintf(
                    /* translators: 1: link to the EDD payment gateway settings, 2: gateway name as listed there, 3: webhook URL */
                    __('Switch %2$s on for checkout in %1$s. Webhook URL for Paddle: %3$s', 'stackless-lite-edd'),
                    '<a href="' . esc_url($paymentsUrl) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>',
                    '<strong>' . esc_html($gateway->adminLabel()) . '</strong>',
                    '<code>' . esc_html($gateway->webhookUrl()) . '</code>'
                ),
            ],
            [
                'id' => self::id('title', $id),
                'title' => __('Title', 'stackless-lite-edd'),
                'type' => 'text',
                'default' => Gateway::defaultTitle(),
            ],
            [
                'id' => self::id('description', $id),
                'title' => __('Description', 'stackless-lite-edd'),
                'type' => 'textarea',
                'default' => Gateway::defaultDescription(),
            ],
            [
                'id' => self::id('testmode', $id),
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'type' => 'checkbox',
                'desc' => __('Use the Paddle sandbox (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            ['id' => $section, 'type' => 'sectionend'],
            [
                'id' => self::id('live_section', $id),
                'title' => __('Live', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => self::keyHelp('https://vendors.paddle.com/authentication-v2', $permissions) . ($onScreen ? '<br><br>' . WebhookSetup::panel($gateway, 'live') : ''),
            ],
            [
                'id' => self::id('live_api_key', $id),
                'title' => __('Live API key', 'stackless-lite-edd'),
                'type' => 'password',
                'placeholder' => 'pdl_live_apikey_…',
                'default' => '',
            ],
            [
                'id' => self::id('live_client_token', $id),
                'title' => __('Live client-side token', 'stackless-lite-edd'),
                'type' => 'text',
                'placeholder' => 'live_…',
                'default' => '',
            ],
            [
                'id' => self::id('live_webhook_secret', $id),
                'title' => __('Live webhook secret key', 'stackless-lite-edd'),
                'type' => 'password',
                'placeholder' => 'pdl_ntfset_…',
                'default' => '',
            ],
            ['id' => self::id('live_section', $id), 'type' => 'sectionend'],
            [
                'id' => self::id('sandbox_section', $id),
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => self::keyHelp('https://sandbox-vendors.paddle.com/authentication-v2', $permissions) . ($onScreen ? '<br><br>' . WebhookSetup::panel($gateway, 'sandbox') : ''),
            ],
            [
                'id' => self::id('sandbox_api_key', $id),
                'title' => __('Sandbox API key', 'stackless-lite-edd'),
                'type' => 'password',
                'placeholder' => 'pdl_sdbx_apikey_…',
                'default' => '',
            ],
            [
                'id' => self::id('sandbox_client_token', $id),
                'title' => __('Sandbox client-side token', 'stackless-lite-edd'),
                'type' => 'text',
                'placeholder' => 'test_…',
                'default' => '',
            ],
            [
                'id' => self::id('sandbox_webhook_secret', $id),
                'title' => __('Sandbox webhook secret key', 'stackless-lite-edd'),
                'type' => 'password',
                'placeholder' => 'pdl_ntfset_…',
                'default' => '',
            ],
            ['id' => self::id('sandbox_section', $id), 'type' => 'sectionend'],
            [
                'id' => self::id('checkout_section', $id),
                'title' => __('Checkout page and webhook', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => $gateway->setupInstructions(),
            ],
            [
                'id' => self::id('tax_mode', $id),
                'title' => __('Prices and tax', 'stackless-lite-edd'),
                'type' => 'select',
                'default' => 'account_setting',
                'options' => [
                    'account_setting' => __('Use my Paddle account setting', 'stackless-lite-edd'),
                    'external' => __('Add tax on top of my prices', 'stackless-lite-edd'),
                    'internal' => __('My prices already include tax', 'stackless-lite-edd'),
                ],
                'desc' => __('Paddle is the merchant of record and calculates sales tax and VAT itself. Turn off EDD taxes for downloads sold through Paddle so buyers are not taxed twice.', 'stackless-lite-edd'),
            ],
            [
                'id' => self::id('tax_category', $id),
                'title' => __('Tax category', 'stackless-lite-edd'),
                'type' => 'select',
                'default' => 'standard',
                'options' => array_combine(Gateway::TAX_CATEGORIES, [
                    __('Standard digital goods (approved for every account)', 'stackless-lite-edd'),
                    __('Digital goods', 'stackless-lite-edd'),
                    __('Ebooks', 'stackless-lite-edd'),
                    __('Implementation services', 'stackless-lite-edd'),
                    __('Professional services', 'stackless-lite-edd'),
                    __('Software as a service', 'stackless-lite-edd'),
                    __('Software programming services', 'stackless-lite-edd'),
                    __('Training services', 'stackless-lite-edd'),
                    __('Website hosting', 'stackless-lite-edd'),
                ]),
                'desc' => __('Paddle uses the tax category to pick tax rates. Any category other than Standard must be approved for your Paddle account first, or checkouts fail.', 'stackless-lite-edd'),
            ],
            ['id' => self::id('checkout_section', $id), 'type' => 'sectionend'],
            [
                'id' => self::id('limits_section', $id),
                'title' => __('Good to know', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => __('Paddle bills one subscription schedule per checkout. One-time items or a signup fee can share the checkout with it; Paddle charges them once. Free trials work when the first payment is zero. A first payment smaller than a full cycle (a first-payment discount) cannot use Paddle. Paddle runs the renewal schedule; renewals it charges become paid EDD renewal orders.', 'stackless-lite-edd'),
            ],
            ['id' => self::id('limits_section', $id), 'type' => 'sectionend'],
        ];
        if ($copies !== '') {
            $fields[] = [
                'id' => self::id('copies_section', $id),
                'title' => __('Copies', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => $copies,
            ];
            $fields[] = ['id' => self::id('copies_section', $id), 'type' => 'sectionend'];
        }
        $fields[] = ['id' => self::id('debug_section', $id), 'type' => 'title', 'title' => ''];
        $fields[] = [
            'id' => self::id('debug', $id),
            'title' => __('Debug log', 'stackless-lite-edd'),
            'type' => 'checkbox',
            'desc' => __('Log Paddle activity to Downloads → Tools → Debug Log (stackless-edd-paddle_gateway) while EDD debug mode is on. Keys are never logged.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = ['id' => self::id('debug_section', $id), 'type' => 'sectionend'];
        return $fields;
    }

    /** Where to create the keys, with a direct link to that Paddle screen. */
    private static function keyHelp(string $url, string $permissions): string
    {
        return sprintf(
            /* translators: 1: link to Paddle's Authentication screen, 2: API key permissions */
            __('Open %1$s. On the API keys tab choose New API key and give it Write access to Transactions, Subscriptions and Adjustments (Write includes Read): %2$s. Add Notification settings: Write too if you want Stackless to set up the webhook for you. Set the expiry as far out as Paddle allows and note the date: an expired key stops payments. Then, on the Client-side tokens tab, create a token. Paste both below.', 'stackless-lite-edd'),
            '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Paddle → Authentication', 'stackless-lite-edd') . '</a>',
            '<code>' . esc_html($permissions) . '</code>'
        );
    }

    /**
     * Remembers credential values before a save, so validate() only calls Paddle for changed keys.
     *
     * @param mixed $value
     * @param mixed $field
     * @return mixed
     */
    public static function remember($value, $field, $raw = null)
    {
        $id = is_array($field) ? (string) ($field['id'] ?? '') : '';
        if ($id !== '' && strpos($id, self::PREFIX) === 0 && !array_key_exists($id, self::$previous)) {
            $stored = get_option($id, '');
            self::$previous[$id] = is_scalar($stored) ? (string) $stored : '';
        }
        return $value;
    }

    /** After a save: catch keys pasted into the wrong environment, rejected keys and missing secrets. */
    public static function validate(): void
    {
        foreach (Gateway::all() as $gateway) {
            $prefix = Copies::badge($gateway->id()) !== '' ? Copies::badge($gateway->id()) . ': ' : '';
            foreach (['live', 'sandbox'] as $mode) {
                $key = $gateway->apiKey($mode);
                if ($key === '') {
                    continue;
                }
                $problem = Api::keyProblem($mode, $key, $gateway->clientToken($mode));
                if ($problem !== '') {
                    Fields::addError($prefix . $problem);
                    continue;
                }
                $option = self::id($mode . '_api_key', $gateway->id());
                if (array_key_exists($option, self::$previous) && self::$previous[$option] !== $key) {
                    $check = $gateway->api($mode)->request('GET', 'transactions', ['per_page' => 1]);
                    if (is_wp_error($check)) {
                        Fields::addError($prefix . $check->get_error_message());
                    }
                }
            }

            if ($gateway->isEnabled()) {
                $mode = $gateway->mode();
                if ($gateway->apiKey($mode) === '' || $gateway->clientToken($mode) === '') {
                    Fields::addError($prefix . __('Paddle is enabled but the selected environment needs both an API key and a client-side token.', 'stackless-lite-edd'));
                }
                if ($gateway->webhookSecret($mode) === '') {
                    Fields::addError($prefix . __('Add the Paddle webhook secret key, or use Set up the webhook in Paddle. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite-edd'));
                }
            }
        }
        self::$previous = [];
        // SettingsPage stored this save's errors before firing the saved hook: store them again with ours.
        Fields::persistErrors();
    }
}
