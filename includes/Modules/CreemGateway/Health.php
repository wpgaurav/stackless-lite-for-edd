<?php

namespace StacklessEDD\Modules\CreemGateway;

use StacklessEDD\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * What Creem tells the store's health screens: payment alert routing, the merchant-of-record
 * list, and the health source (setup problems of the original and every copy, and recent failed
 * or stuck webhook events). No keys and no customer data leave this class.
 */
final class Health
{
    /**
     * Live and test are separate hosts, so only api.creem.io is watched.
     *
     * @param mixed $routes
     */
    public static function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['creem'] = [
            'label' => 'Creem',
            'ids' => [Gateway::ID],
            'hosts' => ['api.creem.io'],
            'class' => 'StacklessEDD\\Modules\\CreemGateway\\',
            'mode_meta' => Gateway::META_MODE,
        ];
        return $routes;
    }

    /**
     * Creem is the merchant of record: its orders never get one-click charges.
     *
     * @param mixed $ids
     * @return string[]
     */
    public static function merchantOfRecord($ids): array
    {
        $ids = is_array($ids) ? array_values(array_map('strval', $ids)) : [];
        return array_values(array_unique(array_merge($ids, Copies::ids())));
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
        $issues = [];
        $seen = get_option(Webhook::LAST_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        foreach (Gateway::all() as $gateway) {
            if (!$gateway->isEnabled()) {
                continue;
            }
            $name = $gateway->adminLabel();
            $url = SettingsPage::url('creem_gateway', $gateway->isOriginal() ? [] : ['gateway' => $gateway->id()]);
            $mode = $gateway->mode();
            $key = $gateway->apiKey($mode);
            if ($key === '') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s has no API key', 'stackless-lite-edd'), $name), 'detail' => __('Creem is turned on but cannot take payments until an API key is added for the selected mode.', 'stackless-lite-edd'), 'url' => $url];
            } elseif (EventMap::keyMode($key) !== '' && EventMap::keyMode($key) !== $mode) {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s key is for the other mode', 'stackless-lite-edd'), $name), 'detail' => __('The API key does not match the selected mode (test or live).', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($gateway->webhookSecret($mode) === '') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s webhooks are refused', 'stackless-lite-edd'), $name), 'detail' => __('Without the webhook secret, renewals, failed renewals, refunds and cancellations made in Creem never reach EDD.', 'stackless-lite-edd'), 'url' => $url];
            } elseif ($key !== '' && empty($seen[$gateway->id() . ':' . $mode])) {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'warning', 'title' => sprintf(__('No webhook received yet for %s', 'stackless-lite-edd'), $name), 'detail' => __('Check that the webhook in Creem points at this site and listens to the listed events.', 'stackless-lite-edd'), 'url' => $url];
            }
            if ($mode === 'test') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'info', 'title' => sprintf(__('%s is in test mode', 'stackless-lite-edd'), $name), 'detail' => __('Checkout takes test payments only. Turn test mode off to take real payments.', 'stackless-lite-edd'), 'url' => $url];
            }
        }
        return [
            'label' => 'Creem',
            'issues' => $issues,
            'events' => WebhookLog::problems(10),
        ];
    }
}
