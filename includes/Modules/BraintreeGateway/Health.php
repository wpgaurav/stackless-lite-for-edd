<?php

namespace StacklessEDD\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_edd_health_sources) and
 * payment-failure alerts (stackless_edd_payment_alerts_gateways). Both answer neutrally when
 * the reading module is off, so nothing here depends on it.
 */
final class Health
{
    /** A charge without a known result is reported after this long. */
    public const STUCK_AFTER = HOUR_IN_SECONDS;

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
     * Sandbox and production have separate hosts, so the host alone marks a live call.
     *
     * @param mixed $routes
     * @return array<string,mixed>
     */
    public static function alerts($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['braintree'] = [
            'label' => 'Braintree',
            'ids' => [Gateway::ID],
            'hosts' => [Api::HOSTS['live'], Api::GATEWAY_HOSTS['live']],
            'class' => 'StacklessEDD\\Modules\\BraintreeGateway\\',
            'module' => Gateway::MODULE_ID,
            'mode_meta' => Meta::MODE,
            'live_auth' => [],
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array<string,string>>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $settings = Gateway::settingsUrl();
        $orders_url = admin_url('edit.php?post_type=download&page=edd-payment-history');
        $mode = Config::mode();
        $issues = [];
        if (Gateway::instance()->isEnabled()) {
            if (!Config::hasKeys($mode)) {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Braintree is missing its Merchant ID or keys', 'stackless-lite-edd'),
                    'detail' => __('Checkout hides Braintree and renewals cannot be charged until the Merchant ID, public key and private key are set for the selected mode.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            } else {
                $connection = Connection::cached($mode);
                $store = stackless_edd_base_currency();
                if (!$connection) {
                    $issues[] = [
                        'severity' => 'error',
                        'title' => __('Braintree has not confirmed these keys', 'stackless-lite-edd'),
                        'detail' => __('Save the Braintree settings to check the connection and read your merchant accounts.', 'stackless-lite-edd'),
                        'url' => $settings,
                    ];
                } else {
                    $row = Connection::merchantAccount($mode, $store, $connection);
                    if (!$row) {
                        $issues[] = [
                            'severity' => 'warning',
                            /* translators: %s: currency code */
                            'title' => sprintf(__('Braintree has no merchant account for %s', 'stackless-lite-edd'), $store),
                            'detail' => __('A merchant account takes one currency, so Braintree hides for carts in currencies without one. Ask Braintree for an account in this currency, or choose one in the settings when there are several.', 'stackless-lite-edd'),
                            'url' => $settings,
                        ];
                    } elseif (!$row['secure'] && Config::threeDSecure() !== ThreeDSecure::OFF) {
                        $issues[] = [
                            'severity' => 'warning',
                            'title' => __('3-D Secure is required but not switched on at Braintree', 'stackless-lite-edd'),
                            'detail' => __('Card payments fail until Braintree enables 3-D Secure for this merchant account.', 'stackless-lite-edd'),
                            'url' => $settings,
                        ];
                    }
                    if (stackless_edd_price_decimals($store) > Money::decimals($store)) {
                        $issues[] = [
                            'severity' => 'warning',
                            /* translators: %s: currency code */
                            'title' => sprintf(__('Braintree takes %s without decimals', 'stackless-lite-edd'), $store),
                            'detail' => __('EDD keeps decimals for this currency, and a total with a fraction cannot be charged exactly. Use whole prices and tax amounts, or set the decimals to 0 with the edd_currency_decimal_count filter.', 'stackless-lite-edd'),
                            'url' => $settings,
                        ];
                    }
                }
                if (!get_option(Webhook::LAST_OPTION . $mode)) {
                    $issues[] = [
                        'severity' => 'info',
                        'title' => __('No Braintree webhook has arrived yet', 'stackless-lite-edd'),
                        'detail' => __('Create the webhook in the Control Panel and use Check URL there. Without it, disputes reach EDD within six hours instead of at once.', 'stackless-lite-edd'),
                        'url' => $settings,
                    ];
                }
            }
            if ($mode === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Braintree is in sandbox mode', 'stackless-lite-edd'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite-edd'),
                    'url' => $settings,
                ];
            }
        }

        $waiting = [];
        foreach (Orders::withMeta(Meta::ATTEMPT, 90) as $id) {
            $attempt = Attempt::current($id);
            if ($attempt && time() - (int) $attempt['started'] >= self::STUCK_AFTER) {
                $waiting[] = '#' . $id;
            }
        }
        if ($waiting) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Braintree charge needs a look', '%d Braintree charges need a look', count($waiting), 'stackless-lite-edd'), count($waiting)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('These orders wait on a charge with no known result or a payment that does not match, so they are not charged again: %s', 'stackless-lite-edd'), implode(', ', $waiting)),
                'url' => $orders_url,
            ];
        }
        $flagged = array_map(static function ($id) {
            return '#' . $id;
        }, Orders::withMeta(Meta::FLAGGED, 30));
        if ($flagged) {
            $issues[] = [
                'severity' => 'error',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d order has an extra Braintree payment', '%d orders have extra Braintree payments', count($flagged), 'stackless-lite-edd'), count($flagged)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('The order notes name the transaction to refund in the Control Panel: %s', 'stackless-lite-edd'), implode(', ', $flagged)),
                'url' => $orders_url,
            ];
        }

        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Braintree webhook failed recently', '%d Braintree webhooks failed recently', count($events), 'stackless-lite-edd'), count($events)),
                'detail' => __('Braintree retries every hour for 24 hours. Look in Downloads → Tools → Debug Log (stackless-edd-braintree_gateway) for the reason.', 'stackless-lite-edd'),
                'url' => admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log'),
            ];
        }
        return ['label' => 'Braintree', 'issues' => $issues, 'events' => $events];
    }
}
