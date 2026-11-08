<?php

namespace StacklessEDD\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_edd_health_sources, with a fix that
 * creates the webhook) and payment-failure alerts (stackless_edd_payment_alerts_gateways). Both
 * answer neutrally when the reading module is off, so nothing here depends on it.
 */
final class Health
{
    public const FIX_WEBHOOK = 'webhook';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_health_sources', [self::class, 'source']);
        add_filter('stackless_edd_health_fix', [self::class, 'fix'], 10, 3);
        add_filter('stackless_edd_payment_alerts_gateways', [self::class, 'alerts']);
    }

    /**
     * @param mixed $sources
     * @return array<string,mixed>
     */
    public static function source($sources): array
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Gateway::ID] = [self::class, 'report'];
        return $sources;
    }

    /**
     * Square has separate production and sandbox hosts, so the host alone marks a live call.
     *
     * @param mixed $routes
     * @return array<string,mixed>
     */
    public static function alerts($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['square'] = [
            'label' => 'Square',
            'ids' => [Gateway::ID],
            'hosts' => ['connect.squareup.com'],
            'class' => 'StacklessEDD\\Modules\\SquareGateway\\',
            'module' => Gateway::MODULE_ID,
            'mode_meta' => Gateway::META_MODE,
            'live_auth' => [],
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array<string,string>>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $gateway = Gateway::instance();
        $settings = Gateway::settingsUrl();
        $issues = [];
        if ($gateway->isEnabled()) {
            if ($gateway->appId() === '' || $gateway->accessToken() === '' || $gateway->locationId() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Square is missing its application ID, access token or location', 'stackless-lite-edd'),
                    'detail' => __('Checkout hides Square and renewals cannot be charged until all three are set for the selected mode.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
            if ($gateway->webhookKey() === '') {
                $issue = [
                    'severity' => 'warning',
                    'title' => __('Square webhook signature key is missing', 'stackless-lite-edd'),
                    'detail' => __('Refunds made in Square and card changes do not reach EDD without the webhook. Save the settings on a public HTTPS site to create it.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
                if ($gateway->accessToken() !== '') {
                    $issue['fix'] = self::FIX_WEBHOOK;
                    $issue['fix_label'] = __('Create the webhook', 'stackless-lite-edd');
                }
                $issues[] = $issue;
            }
            $currency = $gateway->currency();
            if ($currency !== '' && stackless_edd_base_currency() !== $currency) {
                $issues[] = [
                    'severity' => 'info',
                    /* translators: %s: currency code */
                    'title' => sprintf(__('Square only takes %s', 'stackless-lite-edd'), $currency),
                    'detail' => __('The store\'s base currency differs from the Square location\'s, so Square shows only for carts in the location\'s currency.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
            if ($gateway->mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Square is in sandbox mode', 'stackless-lite-edd'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Square webhook failed recently', '%d Square webhooks failed recently', count($events), 'stackless-lite-edd'), count($events)),
                'detail' => __('Square retries for 24 hours. Look in Downloads → Tools → Debug Log (stackless-edd-square_gateway) for the reason.', 'stackless-lite-edd'),
                'url' => admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log'),
            ];
        }
        return ['label' => 'Square', 'issues' => $issues, 'events' => $events];
    }

    /**
     * Billing Health asks when the owner clicks a fix.
     *
     * @param mixed $result
     * @param mixed $source_id
     * @param mixed $fix_key
     * @return mixed message, \WP_Error, or the incoming value when the fix is not ours
     */
    public static function fix($result, $source_id = '', $fix_key = '')
    {
        if ((string) $source_id !== Gateway::ID || (string) $fix_key !== self::FIX_WEBHOOK) {
            return $result;
        }
        if (!current_user_can('manage_shop_settings')) {
            return new \WP_Error('stackless_edd_square_forbidden', __('You are not allowed to change the Square settings.', 'stackless-lite-edd'));
        }
        $gateway = Gateway::instance();
        $message = (new Setup($gateway))->webhook($gateway->mode());
        if (is_wp_error($message)) {
            return $message;
        }
        if ($gateway->webhookKey() === '') {
            return new \WP_Error('stackless_edd_square_webhook', $message !== '' ? $message : __('Square did not return a signature key. Add the webhook by hand.', 'stackless-lite-edd'));
        }
        return $message !== '' ? $message : __('The Square webhook is already set up.', 'stackless-lite-edd');
    }
}
