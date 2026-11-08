<?php

namespace StacklessEDD\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: the store health report (stackless_edd_health_sources) and
 * payment-failure alerts (stackless_edd_payment_alerts_gateways).
 */
final class Health
{
    /** Debits still pending after this long are reported (they normally settle within three days). */
    public const STUCK_AFTER = 5 * DAY_IN_SECONDS;

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
        $routes['razorpay'] = self::route();
        return $routes;
    }

    /** @return array{label:string, ids:string[], hosts:string[], class:string, module:string, mode_meta:string, live_auth:string[]} */
    public static function route(): array
    {
        return [
            'label' => 'Razorpay',
            'ids' => [Gateway::ID],
            'hosts' => ['api.razorpay.com'],
            'class' => 'StacklessEDD\\Modules\\RazorpayGateway\\',
            'module' => Settings::MODULE_ID,
            'mode_meta' => Gateway::META_MODE,
            // Test and live calls share this host: only live credentials alert.
            'live_auth' => ['rzp_live_'],
        ];
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $settings = Settings::settingsUrl();
        $issues = [];
        if (Module::gateway()->isEnabled()) {
            if (!Settings::hasKeys()) {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Razorpay has no API keys for the selected mode', 'stackless-lite-edd'),
                    'detail' => __('Checkout hides Razorpay and renewals cannot be debited until the key id and secret are added.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
            if (Settings::webhookSecret() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Razorpay webhook secret is missing', 'stackless-lite-edd'),
                    'detail' => __('Renewal debits settle only through the webhook, so renewals stay pending without it.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
            if (Settings::mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Razorpay is in test mode', 'stackless-lite-edd'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
        }
        $stuck = self::stuckDebits();
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Razorpay debit has been pending for over five days', '%d Razorpay debits have been pending for over five days', count($stuck), 'stackless-lite-edd'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these orders in the Razorpay dashboard and make sure the webhook is active: %s', 'stackless-lite-edd'), implode(', ', $stuck)),
                'url' => admin_url('edit.php?post_type=download&page=edd-payment-history&status=pending'),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Razorpay webhook failed recently', '%d Razorpay webhooks failed recently', count($events), 'stackless-lite-edd'), count($events)),
                'detail' => __('Razorpay retries for 24 hours and then disables the webhook. Look in Downloads → Tools → Debug Log (stackless-edd-razorpay_gateway) for the reason.', 'stackless-lite-edd'),
                'url' => admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log'),
            ];
        }
        return ['label' => 'Razorpay', 'issues' => $issues, 'events' => $events];
    }

    /** @return string[] order numbers of debits still waiting on Razorpay */
    private static function stuckDebits(): array
    {
        if (!function_exists('edd_get_orders')) {
            return [];
        }
        $orders = edd_get_orders([
            'number' => 50,
            'type' => 'sale',
            'gateway' => Gateway::ID,
            'status__in' => ['pending', 'on_hold'],
            'orderby' => 'id',
            'order' => 'ASC',
            'meta_query' => [['key' => Gateway::META_FLOW, 'value' => Renewals::FLOWS, 'compare' => 'IN']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the health report, opened by an admin.
        ]);
        $cutoff = time() - self::STUCK_AFTER;
        $numbers = [];
        foreach ((array) $orders as $order) {
            if (!$order instanceof \EDD\Orders\Order) {
                continue;
            }
            $orderId = (int) $order->id;
            $since = (int) Orders::metaString($orderId, Gateway::META_ORDER_AT);
            if ($since > 0 && $since < $cutoff && Orders::metaString($orderId, Gateway::META_PAYMENT_ID) !== '' && in_array(Orders::metaString($orderId, Gateway::META_FLOW), Renewals::FLOWS, true)) {
                $numbers[] = '#' . Orders::number($order);
            }
            if (count($numbers) >= 20) {
                break;
            }
        }
        return $numbers;
    }
}
