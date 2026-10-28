<?php

namespace StacklessEDD\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Settings-screen extras: key validation on save (a real API call per mode), connection and
 * webhook status, the recent webhook log, a pointer from EDD's payment settings, and Stripe
 * Dashboard links on order transaction ids.
 */
final class Admin
{
    private const NOTICE_TRANSIENT = 'stackless_edd_stripe_gateway_notices_';

    public static function registerHooks(): void
    {
        add_action('stackless_edd_settings_saved_' . Settings::MODULE_ID, [self::class, 'validateKeys']);
        add_action('stackless_edd_settings_after_' . Settings::MODULE_ID, [self::class, 'renderStatus']);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('stackless_edd_settings_sanitize_option_' . Settings::PREFIX . 'statement_descriptor_suffix', [self::class, 'sanitizeDescriptor'], 10, 1);
        foreach (['test_secret_key', 'live_secret_key', 'test_publishable_key', 'live_publishable_key', 'test_webhook_secret', 'live_webhook_secret'] as $key) {
            add_filter('stackless_edd_settings_sanitize_option_' . Settings::PREFIX . $key, [self::class, 'sanitizeKey'], 10, 1);
        }
        WebhookSetup::register();
        add_filter('edd_settings_sections_gateways', [self::class, 'gatewaySection']);
        add_filter('edd_settings_gateways', [self::class, 'gatewaySettings']);
        add_filter('edd_payment_details_transaction_id-' . Settings::GATEWAY_ID, [self::class, 'linkTransaction'], 10, 2);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitizeKey($value)
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', is_scalar($value) ? (string) $value : '');
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitizeDescriptor($value)
    {
        return Settings::sanitizeDescriptorSuffix($value);
    }

    public static function validateKeys(): void
    {
        if (!current_user_can('manage_shop_settings')) {
            return;
        }
        $notices = [];
        foreach (['test', 'live'] as $mode) {
            $secret = Settings::secretKey($mode);
            $publishable = Settings::publishableKey($mode);
            $webhook = Settings::webhookSecret($mode);
            $label = $mode === 'live' ? __('Live', 'stackless-lite-edd') : __('Test', 'stackless-lite-edd');

            if ($secret === '' && $publishable === '') {
                delete_option(Settings::PREFIX . 'account_' . $mode);
                if (Settings::mode() === $mode) {
                    /* translators: %s: Test or Live */
                    $notices[] = ['error', sprintf(__('%s mode is selected but no API keys are entered. Stripe stays hidden at checkout until you add them.', 'stackless-lite-edd'), $label)];
                }
                continue;
            }
            if ($secret === '' || $publishable === '') {
                /* translators: %s: Test or Live */
                $notices[] = ['error', sprintf(__('%s mode needs both the publishable key and the secret key.', 'stackless-lite-edd'), $label)];
                continue;
            }
            if (strpos($secret, 'sk_' . $mode . '_') !== 0 && strpos($secret, 'rk_' . $mode . '_') !== 0) {
                /* translators: 1: Test or Live, 2: expected prefix */
                $notices[] = ['error', sprintf(__('The %1$s secret key should start with %2$s.', 'stackless-lite-edd'), $label, 'sk_' . $mode . '_')];
                continue;
            }
            if (strpos($publishable, 'pk_' . $mode . '_') !== 0) {
                /* translators: 1: Test or Live, 2: expected prefix */
                $notices[] = ['error', sprintf(__('The %1$s publishable key should start with %2$s.', 'stackless-lite-edd'), $label, 'pk_' . $mode . '_')];
                continue;
            }
            if ($webhook !== '' && strpos($webhook, 'whsec_') !== 0) {
                /* translators: %s: Test or Live */
                $notices[] = ['error', sprintf(__('The %s webhook signing secret should start with whsec_.', 'stackless-lite-edd'), $label)];
            } elseif ($webhook === '') {
                /* translators: %s: Test or Live */
                $notices[] = ['warning', sprintf(__('Add the %s webhook signing secret. Without webhooks, bank debits, Dashboard refunds, disputes and removed cards will not sync.', 'stackless-lite-edd'), $label)];
            }

            $client = new StripeClient($secret, $mode);
            try {
                $account = $client->get('account');
                update_option(Settings::PREFIX . 'account_' . $mode, [
                    'id' => (string) ($account['id'] ?? ''),
                    'name' => (string) ($account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? ''),
                    'country' => (string) ($account['country'] ?? ''),
                    'currency' => strtoupper((string) ($account['default_currency'] ?? '')),
                    'charges_enabled' => !empty($account['charges_enabled']),
                    'checked' => time(),
                ], false);
                if (empty($account['charges_enabled']) && $mode === 'live') {
                    $notices[] = ['warning', __('Stripe accepted the live keys, but the account cannot take charges yet. Finish activation in the Stripe Dashboard.', 'stackless-lite-edd')];
                } else {
                    /* translators: 1: Test or Live, 2: Stripe account id */
                    $notices[] = ['success', sprintf(__('%1$s keys verified with Stripe (account %2$s).', 'stackless-lite-edd'), $label, (string) ($account['id'] ?? ''))];
                }
            } catch (StripeApiException $e) {
                if ($e->getHttpStatus() === 403 || $e->getStripeCode() === 'secret_key_required' || ($e->getStripeType() === 'invalid_request_error' && strpos($secret, 'rk_') === 0)) {
                    // Restricted keys may not read the account object; a balance read proves the key works.
                    try {
                        $client->get('balance');
                        /* translators: %s: Test or Live */
                        $notices[] = ['success', sprintf(__('%s restricted key verified with Stripe.', 'stackless-lite-edd'), $label)];
                        continue;
                    } catch (StripeApiException $inner) {
                        $e = $inner;
                    }
                }
                delete_option(Settings::PREFIX . 'account_' . $mode);
                /* translators: 1: Test or Live, 2: Stripe error message */
                $notices[] = ['error', sprintf(__('Stripe rejected the %1$s secret key: %2$s', 'stackless-lite-edd'), $label, $e->getMessage())];
            }
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $notices, 120);
    }

    public static function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'stackless') === false || !current_user_can('manage_shop_settings')) {
            return;
        }
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notices = get_transient($key);
        if (!is_array($notices)) {
            return;
        }
        delete_transient($key);
        foreach ($notices as $notice) {
            printf('<div class="notice notice-%1$s stackless-notice is-dismissible"><p>%2$s</p></div>', esc_attr((string) $notice[0]), esc_html((string) $notice[1]));
        }
    }

    public static function renderStatus(): void
    {
        $enabled = Module::gateway()->isEnabled();
        $payments_url = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $last = get_option(Webhooks::OPTION_LAST);

        echo '<h2>' . esc_html__('Status', 'stackless-lite-edd') . '</h2>';
        echo '<table class="form-table stackless-stripe-status"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Checkout', 'stackless-lite-edd') . '</th><td>';
        echo $enabled
            ? esc_html__('Enabled at checkout.', 'stackless-lite-edd') . ' '
            : '<strong>' . esc_html__('Disabled.', 'stackless-lite-edd') . '</strong> ';
        printf('<a href="%s">%s</a>', esc_url($payments_url), esc_html__('Turn on or off in Downloads → Settings → Payments', 'stackless-lite-edd'));
        echo '</td></tr>';

        foreach (['test', 'live'] as $mode) {
            $account = get_option(Settings::PREFIX . 'account_' . $mode);
            echo '<tr><th scope="row">' . esc_html($mode === 'live' ? __('Live account', 'stackless-lite-edd') : __('Test account', 'stackless-lite-edd')) . '</th><td>';
            if (is_array($account) && !empty($account['id'])) {
                printf(
                    '%s <code>%s</code> %s',
                    esc_html((string) ($account['name'] ?: __('Connected', 'stackless-lite-edd'))),
                    esc_html((string) $account['id']),
                    esc_html(trim(($account['country'] ?? '') . ' ' . ($account['currency'] ?? '')))
                );
            } else {
                esc_html_e('Not verified. Save valid keys to check the connection.', 'stackless-lite-edd');
            }
            echo '</td></tr>';
        }

        echo '<tr id="stackless-stripe-webhook"><th scope="row">' . esc_html__('Webhook endpoint', 'stackless-lite-edd') . '</th><td>';
        echo WebhookSetup::panel('test') . WebhookSetup::panel('live'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in panel().
        printf('<input type="text" class="large-text code" readonly value="%s" onclick="this.select()">', esc_attr(Settings::webhookUrl()));
        echo '<p class="description">' . esc_html__('To do it by hand instead: in Stripe → Developers → Webhooks, add an endpoint with this URL for test mode and again for live mode, select these events, then paste each signing secret above:', 'stackless-lite-edd') . '</p>';
        echo '<p><code>' . implode('</code> <code>', array_map('esc_html', Settings::webhookEvents())) . '</code></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Last webhook', 'stackless-lite-edd') . '</th><td>';
        if (is_array($last) && !empty($last['time'])) {
            /* translators: 1: event type, 2: mode, 3: time ago */
            printf(esc_html__('%1$s (%2$s) %3$s ago', 'stackless-lite-edd'), esc_html((string) $last['type']), esc_html((string) $last['mode']), esc_html(human_time_diff((int) $last['time'])));
        } else {
            esc_html_e('No signed webhook received yet.', 'stackless-lite-edd');
        }
        $recent = EventStore::recent(8);
        if ($recent) {
            echo '<table class="widefat striped stackless-table" style="margin-top:8px;max-width:900px"><thead><tr><th>' . esc_html__('Event', 'stackless-lite-edd') . '</th><th>' . esc_html__('Type', 'stackless-lite-edd') . '</th><th>' . esc_html__('Result', 'stackless-lite-edd') . '</th><th>' . esc_html__('Received (UTC)', 'stackless-lite-edd') . '</th></tr></thead><tbody>';
            foreach ($recent as $row) {
                printf(
                    '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html((string) $row['event_id']),
                    esc_html((string) $row['event_type']),
                    esc_html(trim((string) $row['status'] . ' ' . (string) $row['note'])),
                    esc_html((string) $row['created_at'])
                );
            }
            echo '</tbody></table>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    /**
     * A "Stripe (Stackless)" section under Downloads → Settings → Payments that points to the
     * module settings, where keys, webhooks, appearance and express buttons live.
     *
     * @param mixed $sections
     * @return array<string,string>
     */
    public static function gatewaySection($sections): array
    {
        $sections = is_array($sections) ? $sections : [];
        $sections[Settings::GATEWAY_ID] = __('Stripe (Stackless)', 'stackless-lite-edd');
        return $sections;
    }

    /**
     * @param mixed $settings
     * @return array<string,mixed>
     */
    public static function gatewaySettings($settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $settings[Settings::GATEWAY_ID] = [
            'stackless_edd_stripe_settings_link' => [
                'id' => 'stackless_edd_stripe_settings_link',
                'name' => __('Stripe (Stackless)', 'stackless-lite-edd'),
                'type' => 'descriptive_text',
                'desc' => sprintf(
                    '%s <a class="button" href="%s">%s</a>',
                    esc_html__('API keys, webhooks, checkout appearance and express buttons live in the Stackless settings.', 'stackless-lite-edd'),
                    esc_url(Gateway::settingsUrl()),
                    esc_html__('Open Stripe settings', 'stackless-lite-edd')
                ),
            ],
        ];
        return $settings;
    }

    /**
     * Link the order's transaction id to the Stripe Dashboard in the mode it was paid in.
     *
     * @param mixed $transaction_id
     * @param mixed $order_id
     * @return mixed
     */
    public static function linkTransaction($transaction_id, $order_id = 0)
    {
        $id = (string) $transaction_id;
        if (strpos($id, 'pi_') !== 0 && strpos($id, 'ch_') !== 0) {
            return $transaction_id;
        }
        return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(Gateway::transactionUrl((int) $order_id, $id)), esc_html($id));
    }
}
