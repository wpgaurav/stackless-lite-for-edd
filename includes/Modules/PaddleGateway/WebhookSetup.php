<?php

namespace StacklessEDD\Modules\PaddleGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\LiveStore;
use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/**
 * One-click webhook setup: creates (or repairs) the Paddle notification destination for this
 * store's webhook URL and saves its secret key, so nobody copies URLs, events or secrets by hand.
 *
 * Uses the saved API key of the chosen environment, which needs notification_setting.write. An
 * existing destination with the same URL is reused: missing events are added and it is switched
 * back on. Run from a link on Stackless → Settings → Paddle (a link, because the settings are one
 * form and forms cannot nest).
 */
final class WebhookSetup
{
    public const ACTION = 'stackless_edd_paddle_webhook';
    public const PERMISSION = 'notification_setting.write';
    private const RESULT = 'stackless_edd_paddle_webhook_result_';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * Finds the destination for $url or creates it, and makes sure it is on and has every event.
     *
     * @return array{status: string, id: string, secret: string}|\WP_Error status: created, updated or ready
     */
    public static function connect(Api $api, string $url)
    {
        $existing = self::find($api, $url);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if ($existing === null) {
            $created = $api->request('POST', 'notification-settings', [
                /* translators: %s: store domain */
                'description' => sprintf(__('Stackless for EDD: %s', 'stackless-lite-edd'), (string) wp_parse_url($url, PHP_URL_HOST)),
                'type' => 'url',
                'destination' => $url,
                'subscribed_events' => EventMap::EVENTS,
            ]);
            if (is_wp_error($created)) {
                return $created;
            }
            return self::result('created', $created);
        }

        $events = self::eventNames($existing);
        $missing = array_values(array_diff(EventMap::EVENTS, $events));
        if (!$missing && !empty($existing['active'])) {
            return self::result('ready', $existing);
        }
        $updated = $api->request('PATCH', 'notification-settings/' . rawurlencode((string) $existing['id']), [
            'active' => true,
            'subscribed_events' => array_values(array_unique(array_merge($events, EventMap::EVENTS))),
        ]);
        if (is_wp_error($updated)) {
            return $updated;
        }
        // An update may not repeat the secret; the destination keeps the one it had.
        if (empty($updated['endpoint_secret_key'])) {
            $updated['endpoint_secret_key'] = $existing['endpoint_secret_key'] ?? '';
        }
        return self::result('updated', $updated);
    }

    /**
     * The URL destination pointing at $url, null when there is none.
     *
     * @return array<string,mixed>|null|\WP_Error
     */
    private static function find(Api $api, string $url)
    {
        $after = '';
        // 200 per page; ten pages is far beyond any real account.
        for ($page = 0; $page < 10; $page++) {
            $query = ['per_page' => 200];
            if ($after !== '') {
                $query['after'] = $after;
            }
            $list = $api->request('GET', 'notification-settings', $query);
            if (is_wp_error($list)) {
                return $list;
            }
            $meta = $list['_meta'] ?? [];
            unset($list['_meta']);
            foreach ($list as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'url' && self::sameUrl((string) ($item['destination'] ?? ''), $url)) {
                    return $item;
                }
                $after = is_array($item) ? (string) ($item['id'] ?? $after) : $after;
            }
            if (empty($meta['pagination']['has_more']) || $after === '') {
                return null;
            }
        }
        return null;
    }

    public static function sameUrl(string $a, string $b): bool
    {
        return WebhookAddress::same($a, $b);
    }

    /** @return string[] */
    private static function eventNames(array $setting): array
    {
        $names = [];
        foreach ((array) ($setting['subscribed_events'] ?? []) as $event) {
            $name = is_array($event) ? (string) ($event['name'] ?? '') : (string) $event;
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** @return array{status: string, id: string, secret: string}|\WP_Error */
    private static function result(string $status, array $setting)
    {
        $secret = (string) ($setting['endpoint_secret_key'] ?? '');
        if ($secret === '') {
            return new \WP_Error('stackless_edd_paddle_webhook_secret', __('Paddle set up the webhook but did not return its secret key. Copy it from Paddle → Notifications into the webhook secret field.', 'stackless-lite-edd'));
        }
        return ['status' => $status, 'id' => (string) ($setting['id'] ?? ''), 'secret' => $secret];
    }

    /** '' when Paddle can deliver to $url, else why not. */
    public static function urlProblem(string $url): string
    {
        return WebhookAddress::problem($url, 'Paddle');
    }

    /**
     * Sets up the destination for one gateway and environment and saves the secret.
     *
     * @return array{status: string, id: string, secret: string}|\WP_Error
     */
    public static function run(Gateway $gateway, string $mode)
    {
        $mode = $mode === 'sandbox' ? 'sandbox' : 'live';
        if ($gateway->apiKey($mode) === '') {
            return new \WP_Error('stackless_edd_paddle_webhook_key', $mode === 'sandbox'
                ? __('Save a sandbox API key first, then set up the webhook.', 'stackless-lite-edd')
                : __('Save a live API key first, then set up the webhook.', 'stackless-lite-edd'));
        }
        if ($mode === 'live' && !LiveStore::isLive()) {
            return LiveStore::copyError(__('This site is a copy of the live store, so it cannot take over the live Paddle webhook. Set it up from the live store, or use the sandbox here.', 'stackless-lite-edd'));
        }
        $url = $gateway->webhookUrl();
        $problem = self::urlProblem($url);
        if ($problem !== '') {
            return new \WP_Error('stackless_edd_paddle_webhook_url', $problem);
        }

        $result = self::connect($gateway->api($mode), $url);
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            if ((int) ($data['status'] ?? 0) === 403) {
                return new \WP_Error('stackless_edd_paddle_webhook_permission', sprintf(
                    /* translators: %s: Paddle permission name */
                    __('The API key cannot manage webhooks. In Paddle → Authentication, edit the key, add the %s permission, save it, and try again.', 'stackless-lite-edd'),
                    self::PERMISSION
                ));
            }
            return $result;
        }

        $option = Settings::id($mode . '_webhook_secret', $gateway->id());
        if ($gateway->webhookSecret($mode) !== $result['secret']) {
            update_option($option, $result['secret']);
            // A constant in wp-config.php wins over the saved value.
            if ($gateway->webhookSecret($mode) !== $result['secret']) {
                return new \WP_Error('stackless_edd_paddle_webhook_constant', sprintf(
                    /* translators: %s: constant name */
                    __('The webhook is set up in Paddle, but %s in wp-config.php holds a different secret key. Update the constant with the key from Paddle → Notifications, or remove it.', 'stackless-lite-edd'),
                    'STACKLESS_EDD_PADDLE_' . strtoupper($mode) . '_WEBHOOK_SECRET'
                ));
            }
        }
        $gateway->log(sprintf('Webhook setup (%s): %s %s', $mode, $result['status'], $result['id']));
        return $result;
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite-edd'), '', ['response' => 403]);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- both name the nonce action checked next.
        $gatewayId = isset($_GET['gateway']) ? sanitize_key(wp_unslash($_GET['gateway'])) : '';
        $mode = isset($_GET['mode']) && sanitize_key(wp_unslash($_GET['mode'])) === 'sandbox' ? 'sandbox' : 'live';
        // phpcs:enable
        check_admin_referer(self::ACTION . '_' . $gatewayId . '_' . $mode);
        $gateway = Gateway::forId($gatewayId);
        if (!$gateway) {
            wp_die(esc_html__('That Paddle gateway no longer exists.', 'stackless-lite-edd'), '', ['response' => 404]);
        }

        $result = self::run($gateway, $mode);
        set_transient(self::RESULT . get_current_user_id() . '_' . $gatewayId . '_' . $mode, is_wp_error($result)
            ? ['ok' => false, 'message' => $result->get_error_message()]
            : ['ok' => true, 'status' => $result['status']], 120);
        wp_safe_redirect(SettingsPage::url('paddle_gateway') . '#' . self::anchor($gatewayId, $mode));
        exit;
    }

    private static function anchor(string $gatewayId, string $mode): string
    {
        return 'stackless-paddle-webhook-' . $gatewayId . '-' . $mode;
    }

    /** The webhook line of an environment's settings: status, then the button (or what to do first). */
    public static function panel(Gateway $gateway, string $mode): string
    {
        $gatewayId = $gateway->id();
        $html = '<span class="stackless-paddle-webhook" id="' . esc_attr(self::anchor($gatewayId, $mode)) . '">';
        $key = self::RESULT . get_current_user_id() . '_' . $gatewayId . '_' . $mode;
        $result = get_transient($key);
        if (is_array($result)) {
            delete_transient($key);
            if (!empty($result['ok'])) {
                $messages = [
                    'created' => __('Webhook created in Paddle and its secret key saved below.', 'stackless-lite-edd'),
                    'updated' => __('The existing Paddle webhook was switched on with every event, and its secret key saved below.', 'stackless-lite-edd'),
                    'ready' => __('The Paddle webhook is already set up; its secret key is saved below.', 'stackless-lite-edd'),
                ];
                $html .= '<span class="notice notice-success inline" style="display:block;margin:8px 0"><span style="display:block;margin:.5em 0">' . esc_html($messages[(string) ($result['status'] ?? '')] ?? $messages['ready']) . '</span></span>';
            } else {
                $html .= '<span class="notice notice-error inline" style="display:block;margin:8px 0"><span style="display:block;margin:.5em 0">' . esc_html((string) ($result['message'] ?? '')) . '</span></span>';
            }
        }

        if ($gateway->apiKey($mode) === '') {
            $html .= esc_html__('Webhook: save an API key above first, then set up the webhook here in one click.', 'stackless-lite-edd');
            return $html . '</span>';
        }
        $url = wp_nonce_url(add_query_arg([
            'action' => self::ACTION,
            'gateway' => $gatewayId,
            'mode' => $mode,
        ], admin_url('admin-post.php')), self::ACTION . '_' . $gatewayId . '_' . $mode);
        $connected = $gateway->webhookSecret($mode) !== '';
        $html .= '<a class="button' . ($connected ? '' : ' button-primary') . '" href="' . esc_url($url) . '">'
            . esc_html($connected ? __('Check the webhook in Paddle', 'stackless-lite-edd') : __('Set up the webhook in Paddle', 'stackless-lite-edd'))
            . '</a> ';
        $html .= esc_html($connected
            ? __('A secret key is saved. Checking adds any missing events and switches the destination back on.', 'stackless-lite-edd')
            /* translators: %s: Paddle permission name */
            : sprintf(__('Creates the notification destination with every event and saves its secret key. The API key needs the %s permission.', 'stackless-lite-edd'), self::PERMISSION));
        return $html . '</span>';
    }
}
