<?php

namespace StacklessEDD\Modules\DodoGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Settings of the Dodo Payments gateway and each copy, on Stackless → Settings → Dodo Payments.
 *
 * The original stores stackless_edd_dodo_gateway_{key}; copy N stores the same keys with a
 * "_copyN" suffix. The owner switches each one on for checkout in Downloads → Settings → Payments.
 */
final class Settings
{
    public const PREFIX = 'stackless_edd_dodo_gateway_';

    /** Every per-gateway setting key (a copy's option is PREFIX . key . "_copyN"). */
    public const KEYS = [
        'title',
        'description',
        'testmode',
        'live_api_key',
        'live_webhook_secret',
        'test_api_key',
        'test_webhook_secret',
        'tax_behavior',
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

    /** Whether the Dodo Payments settings screen is being shown (panels that cost a query render only there). */
    public static function onScreen(): bool
    {
        if (!is_admin()) {
            return false;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $module = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : '';
        // phpcs:enable
        return $page === SettingsPage::SETTINGS && in_array($module, ['', 'dodo_gateway'], true);
    }

    /** @return array<int,array<string,mixed>> */
    private static function gatewayFields(Gateway $gateway): array
    {
        $id = $gateway->id();
        $badge = Copies::badge($id);
        $onScreen = self::onScreen();
        $section = self::id('section', $id);
        $paymentsUrl = function_exists('edd_get_admin_url') ? edd_get_admin_url(['page' => 'edd-settings', 'tab' => 'gateways']) : admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');

        $fields = [
            [
                'id' => $section,
                'type' => 'title',
                'title' => __('Dodo Payments', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : ''),
                'desc' => sprintf(
                    /* translators: 1: link to the EDD payment gateway settings, 2: gateway name as listed there, 3: webhook URL */
                    __('Switch %2$s on for checkout in %1$s. Webhook URL for Dodo Payments: %3$s', 'stackless-lite-edd'),
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
                'title' => __('Test mode', 'stackless-lite-edd'),
                'type' => 'checkbox',
                'desc' => __('Use Dodo Payments test mode (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            ['id' => $section, 'type' => 'sectionend'],
        ];

        foreach (['live', 'test'] as $mode) {
            $modeSection = self::id($mode . '_section', $id);
            $fields[] = [
                'id' => $modeSection,
                'title' => $mode === 'test' ? __('Test mode', 'stackless-lite-edd') : __('Live', 'stackless-lite-edd'),
                'type' => 'title',
                'desc' => ($onScreen ? $gateway->connectionLine($mode) : '') . ($mode === 'test'
                    ? __('Test and live keys are separate: switch the Dodo dashboard to Test Mode to create the test key and the test webhook.', 'stackless-lite-edd')
                    : sprintf(
                        /* translators: %s: link to the Dodo Payments dashboard */
                        __('Create an API key in %s → Settings → API Keys with Write access.', 'stackless-lite-edd'),
                        '<a href="https://app.dodopayments.com/" target="_blank" rel="noopener">Dodo Payments</a>'
                    )),
            ];
            if (!$gateway->isDefined($mode, 'api_key')) {
                $fields[] = [
                    'id' => self::id($mode . '_api_key', $id),
                    'title' => $mode === 'test' ? __('Test API key', 'stackless-lite-edd') : __('Live API key', 'stackless-lite-edd'),
                    'type' => 'password',
                    'default' => '',
                ];
            }
            if (!$gateway->isDefined($mode, 'webhook_secret')) {
                $fields[] = [
                    'id' => self::id($mode . '_webhook_secret', $id),
                    'title' => $mode === 'test' ? __('Test webhook secret', 'stackless-lite-edd') : __('Live webhook secret', 'stackless-lite-edd'),
                    'type' => 'password',
                    'placeholder' => 'whsec_…',
                    'default' => '',
                ];
            }
            $fields[] = ['id' => $modeSection, 'type' => 'sectionend'];
        }

        $fields[] = [
            'id' => self::id('webhook_section', $id),
            'title' => __('Webhook', 'stackless-lite-edd'),
            'type' => 'title',
            'desc' => $gateway->webhookInstructions(),
        ];
        $fields[] = [
            'id' => self::id('tax_behavior', $id),
            'title' => __('Prices and tax', 'stackless-lite-edd'),
            'type' => 'select',
            'default' => 'exclusive',
            'options' => [
                'exclusive' => __('Add tax on top of my prices (Dodo default)', 'stackless-lite-edd'),
                'inclusive' => __('My prices already include tax', 'stackless-lite-edd'),
            ],
            'desc' => __('Dodo Payments is the merchant of record and calculates sales tax, VAT and GST itself. Turn off EDD taxes for downloads sold through Dodo so buyers are not taxed twice.', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'id' => self::id('tax_category', $id),
            'title' => __('Tax category', 'stackless-lite-edd'),
            'type' => 'select',
            'default' => 'digital_products',
            'options' => [
                'digital_products' => __('Digital products', 'stackless-lite-edd'),
                'saas' => __('Software as a service', 'stackless-lite-edd'),
                'e_book' => __('E-books', 'stackless-lite-edd'),
                'edtech' => __('Education and courses', 'stackless-lite-edd'),
            ],
            'desc' => __('Dodo uses this category to pick the tax rate for each buyer’s country.', 'stackless-lite-edd'),
        ];
        $fields[] = ['id' => self::id('webhook_section', $id), 'type' => 'sectionend'];
        $fields[] = [
            'id' => self::id('limits_section', $id),
            'title' => __('Good to know', 'stackless-lite-edd'),
            'type' => 'title',
            'desc' => __('Dodo bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Dodo. Free trials work. A subscription must be the only item in the cart. One-time carts of any size work and show in Dodo as one store order. Products are created in your Dodo catalog automatically: one per download for one-time sales, one per plan and price for subscriptions. Dodo runs the renewal schedule; renewals it charges become paid EDD renewal orders.', 'stackless-lite-edd'),
        ];
        $fields[] = ['id' => self::id('limits_section', $id), 'type' => 'sectionend'];

        // Payment Gateway Tools fills this in on the settings screen: duplicate, rename, remove.
        $copies = $onScreen ? (string) apply_filters('stackless_edd_gateway_copies_panel', '', $id) : '';
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
            'desc' => __('Log Dodo Payments activity to Downloads → Tools → Debug Log (stackless-edd-dodo_gateway) while EDD debug mode is on. Keys are never logged.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = ['id' => self::id('debug_section', $id), 'type' => 'sectionend'];
        return $fields;
    }

    /**
     * Remembers credential and tax values before a save, so validate() only calls Dodo for changes.
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

    /** After a save: look up the business behind new keys, start a fresh catalog when it or the tax setup changed. */
    public static function validate(): void
    {
        foreach (Gateway::all() as $gateway) {
            $gid = $gateway->id();
            $prefix = Copies::badge($gid) !== '' ? Copies::badge($gid) . ': ' : '';
            $taxBefore = array_key_exists(self::id('tax_behavior', $gid), self::$previous) && array_key_exists(self::id('tax_category', $gid), self::$previous)
                ? (self::$previous[self::id('tax_behavior', $gid)] ?: 'exclusive') . '|' . (self::$previous[self::id('tax_category', $gid)] ?: 'digital_products')
                : '';
            // Products carry the tax settings, so changing them starts fresh products.
            $taxChanged = $taxBefore !== '' && $taxBefore !== $gateway->taxBehavior() . '|' . $gateway->taxCategory();

            foreach (['live', 'test'] as $mode) {
                $key = $gateway->apiKey($mode);
                $business = $gateway->business($mode);
                if ($key === '') {
                    delete_option($gateway->businessOption($mode));
                    continue;
                }
                $option = self::id($mode . '_api_key', $gid);
                $changed = array_key_exists($option, self::$previous) && self::$previous[$option] !== $key;
                if ($changed || empty($business['id'])) {
                    $remote = $gateway->fetchBusiness($mode);
                    if (is_wp_error($remote)) {
                        Fields::addError($prefix . $remote->get_error_message());
                        continue;
                    }
                    // Products created for another business cannot be sold by this one.
                    if ((string) ($business['id'] ?? '') !== (string) $remote['id']) {
                        $gateway->catalog()->forgetMode($mode);
                    }
                }
                if ($taxChanged) {
                    $gateway->catalog()->forgetMode($mode);
                }
            }

            if ($gateway->isEnabled()) {
                $mode = $gateway->mode();
                if ($gateway->apiKey($mode) === '') {
                    Fields::addError($prefix . __('Dodo Payments is enabled but has no API key for the selected mode.', 'stackless-lite-edd'));
                }
                if ($gateway->webhookSecret($mode) === '') {
                    Fields::addError($prefix . __('Add the Dodo Payments webhook secret. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite-edd'));
                }
            }
        }
        self::$previous = [];
        // SettingsPage stored this save's errors before firing the saved hook: store them again with ours.
        Fields::persistErrors();
    }
}
