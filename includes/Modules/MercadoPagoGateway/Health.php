<?php

namespace StacklessEDD\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * What Mercado Pago tells the store's health screens: payment alert routing and the Billing
 * Health source. No keys and no customer data leave this class.
 */
final class Health
{
    /** @param mixed $routes */
    public static function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['mercadopago'] = [
            'label' => 'Mercado Pago',
            'ids' => [Gateway::ID],
            'hosts' => ['api.mercadopago.com'],
            // Test and live share one host: only calls sent through LiveTransport are live.
            'class' => LiveTransport::class,
            'mode_meta' => Gateway::META_MODE,
            'module' => 'mercadopago_gateway',
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
            $key = $gateway->accessToken($mode);
            if ($key === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Mercado Pago has no access token', 'stackless-lite-edd'), 'detail' => __('Mercado Pago is turned on but cannot take payments until an access token is added for the selected mode.', 'stackless-lite-edd'), 'url' => $url];
            } elseif ($gateway->account($mode) && EventMap::isTestToken($key, $gateway->account($mode)) !== ($mode === 'test')) {
                $issues[] = ['severity' => 'error', 'title' => __('Mercado Pago token is for the other mode', 'stackless-lite-edd'), 'detail' => __('The access token belongs to a test account in production mode, or to a real account in test mode. Save the settings again after pasting the right token.', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($gateway->webhookSecret($mode) === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Mercado Pago webhooks are refused', 'stackless-lite-edd'), 'detail' => __('Without the webhook secret, Mercado Pago notifications cannot be checked, so cash and bank payments that settle later are never confirmed.', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($mode === 'test') {
                $issues[] = ['severity' => 'info', 'title' => __('Mercado Pago is in test mode', 'stackless-lite-edd'), 'detail' => __('Checkout takes test payments only. Turn test mode off to take real payments.', 'stackless-lite-edd'), 'url' => $url];
            }
            $seen = get_option(Webhook::LAST_SEEN, []);
            if ($key !== '' && $gateway->webhookSecret($mode) !== '' && empty(is_array($seen) ? ($seen[$mode] ?? 0) : 0)) {
                $issues[] = ['severity' => 'warning', 'title' => __('No Mercado Pago webhook received yet', 'stackless-lite-edd'), 'detail' => __('Configure notifications in Your integrations → Webhooks so payments that settle later are confirmed.', 'stackless-lite-edd'), 'url' => $url];
            }
        }
        return [
            'label' => 'Mercado Pago',
            'issues' => $issues,
            'events' => WebhookLog::problems(10),
        ];
    }
}
