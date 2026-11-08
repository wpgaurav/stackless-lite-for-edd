<?php

namespace StacklessEDD\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_edd_health_sources) and
 * payment-failure alerts (stackless_edd_payment_alerts_gateways). Both answer neutrally when the
 * reading module is off, so nothing here depends on it.
 */
final class Health
{
    /** Charges still pending after this long are reported (SEPA settles within about 9 business days). */
    public const STUCK_AFTER = 14 * DAY_IN_SECONDS;

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_health_sources', [self::class, 'source']);
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
     * @param mixed $routes
     * @return array<string,mixed>
     */
    public static function alerts($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['mollie'] = [
            'label' => 'Mollie',
            'ids' => [Gateway::ID],
            'hosts' => ['api.mollie.com'],
            'class' => 'StacklessEDD\\Modules\\MollieGateway\\',
            'module' => Gateway::MODULE_ID,
            'mode_meta' => Gateway::META_MODE,
            // Test and live calls share this host: only live credentials alert.
            'live_auth' => ['live_', 'access_'],
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $settings = Settings::settingsUrl();
        $issues = [];
        $enabled = function_exists('edd_is_gateway_active') && edd_is_gateway_active(Gateway::ID);
        if ($enabled) {
            if (Settings::apiKey() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Mollie has no API key for the selected mode', 'stackless-lite-edd'),
                    'detail' => __('Checkout hides Mollie and renewals cannot be charged until a key is added.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
            if (Settings::mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Mollie is in test mode', 'stackless-lite-edd'),
                    'detail' => __('Payments and renewals use test money only, and Mollie shows only for carts in euros.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
        }
        $stuck = self::stuckCharges();
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Mollie charge has been pending for over two weeks', '%d Mollie charges have been pending for over two weeks', count($stuck), 'stackless-lite-edd'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these orders in Mollie: %s', 'stackless-lite-edd'), implode(', ', $stuck)),
                'url' => admin_url('edit.php?post_type=download&page=edd-payment-history&status=pending'),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Mollie webhook failed recently', '%d Mollie webhooks failed recently', count($events), 'stackless-lite-edd'), count($events)),
                'detail' => __('Mollie retries for about a day. Look in Downloads → Tools → Debug Log (stackless-edd-mollie_gateway) for the reason.', 'stackless-lite-edd'),
                'url' => admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log'),
            ];
        }
        return ['label' => 'Mollie', 'issues' => $issues, 'events' => $events];
    }

    /** @return string[] order numbers of recurring charges still waiting on Mollie */
    private static function stuckCharges(): array
    {
        if (!function_exists('edd_get_orders')) {
            return [];
        }
        $orders = edd_get_orders([
            'number' => 20,
            'status' => 'pending',
            'gateway' => Gateway::ID,
            'date_created_query' => ['before' => gmdate('Y-m-d H:i:s', time() - self::STUCK_AFTER)],
            'meta_query' => [['key' => Gateway::META_SEQUENCE, 'value' => 'recurring']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Billing Health only, at most 20 rows.
        ]);
        $numbers = [];
        foreach ((array) $orders as $order) {
            if ($order instanceof \EDD\Orders\Order && Orders::metaString((int) $order->id, Gateway::META_PAYMENT_ID) !== '') {
                $numbers[] = '#' . Orders::number($order);
            }
        }
        return $numbers;
    }
}
