<?php

namespace StacklessEDD\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * What Flutterwave tells the store's health screens: payment alert routing and the Billing
 * Health source (setup problems and recent failed or stuck webhook events). No keys and no
 * customer data leave this class.
 */
final class Health
{
    /** Payment alerts watch failed live orders and failed live API calls. @param mixed $routes */
    public static function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['flutterwave'] = [
            'label' => 'Flutterwave',
            'ids' => [Gateway::ID],
            'hosts' => ['api.flutterwave.com'],
            // Test and live share one host: only calls sent through LiveTransport are live.
            'class' => LiveTransport::class,
            'mode_meta' => Gateway::META_MODE,
            'module' => 'flutterwave_gateway',
        ];
        return $routes;
    }

    /** @param mixed $sources */
    public static function register($sources): array
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Gateway::ID] = [self::class, 'report'];
        return $sources;
    }

    /** @return array{label:string,issues:array,events:array} */
    public static function report(): array
    {
        $gateway = Gateway::instance();
        $url = Gateway::settingsUrl();
        $issues = [];
        if ($gateway->isEnabled()) {
            $mode = $gateway->mode();
            $key = $gateway->secretKey($mode);
            $hash = $gateway->secretHash($mode);
            if ($key === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave has no secret key', 'stackless-lite-edd'), 'detail' => __('Flutterwave is turned on but cannot take payments until a secret key is added for the selected mode.', 'stackless-lite-edd'), 'url' => $url];
            } elseif (EventMap::keyMode($key) !== '' && EventMap::keyMode($key) !== $mode) {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave key is for the other mode', 'stackless-lite-edd'), 'detail' => __('The secret key does not match the selected mode (test or live).', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($hash === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave webhooks are refused', 'stackless-lite-edd'), 'detail' => __('Without a webhook secret hash, Flutterwave webhooks cannot be checked, so payments are confirmed only when buyers return to your store.', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($mode === 'test') {
                $issues[] = ['severity' => 'info', 'title' => __('Flutterwave is in test mode', 'stackless-lite-edd'), 'detail' => __('Checkout takes test payments only. Turn test mode off to take real payments.', 'stackless-lite-edd'), 'url' => $url];
            }
            $seen = get_option(Webhook::LAST_SEEN, []);
            if ($key !== '' && $hash !== '' && empty(is_array($seen) ? ($seen[$mode] ?? 0) : 0)) {
                $issues[] = ['severity' => 'warning', 'title' => __('No Flutterwave webhook received yet', 'stackless-lite-edd'), 'detail' => __('Add the webhook URL and secret hash in Flutterwave’s webhook settings so payments are confirmed even when a buyer does not return to your store.', 'stackless-lite-edd'), 'url' => $url];
            }
        }
        return [
            'label' => 'Flutterwave',
            'issues' => $issues,
            'events' => WebhookLog::problems(10),
        ];
    }
}
