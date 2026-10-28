<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\LiveStore;
use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/**
 * One-click webhook setup: creates (or repairs) the Stripe webhook endpoint for this store and
 * saves its signing secret, in test or live mode, with the mode's saved secret key.
 *
 * Stripe shows an endpoint's signing secret only when the endpoint is created. So an endpoint for
 * this URL is reused only while its secret is saved here; without one it is deleted and created
 * again, which is the only way to get a secret back. Run from a link on Stackless → Settings →
 * Stripe (a link, because the settings are one form and forms cannot nest).
 */
final class WebhookSetup
{
    public const ACTION = 'stackless_edd_stripe_webhook';
    private const RESULT = 'stackless_edd_stripe_webhook_result_';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * Finds the endpoint for $url or creates it, and makes sure it is on with every event.
     *
     * @param string $savedSecret the signing secret stored for this mode ('' when none)
     * @return array{status: string, id: string, secret: string}|\WP_Error status: created, replaced, updated or ready
     */
    public static function connect(StripeClient $client, string $url, string $savedSecret)
    {
        try {
            $matches = self::find($client, $url);
            if ($matches && $savedSecret !== '') {
                $endpoint = $matches[0];
                $events = array_map('strval', (array) ($endpoint['enabled_events'] ?? []));
                $missing = in_array('*', $events, true) ? [] : array_diff(Settings::webhookEvents(), $events);
                if (!$missing && ($endpoint['status'] ?? '') === 'enabled') {
                    return ['status' => 'ready', 'id' => (string) $endpoint['id'], 'secret' => $savedSecret];
                }
                $params = ['disabled' => false];
                if ($missing) {
                    $params['enabled_events'] = array_values(array_unique(array_merge($events, Settings::webhookEvents())));
                }
                $client->post('webhook_endpoints/' . rawurlencode((string) $endpoint['id']), $params);
                return ['status' => 'updated', 'id' => (string) $endpoint['id'], 'secret' => $savedSecret];
            }

            // An endpoint whose secret is lost cannot be verified: replace it.
            foreach ($matches as $endpoint) {
                $client->delete('webhook_endpoints/' . rawurlencode((string) $endpoint['id']));
            }
            $created = $client->post('webhook_endpoints', [
                'url' => $url,
                'enabled_events' => Settings::webhookEvents(),
                'api_version' => StripeClient::API_VERSION,
                /* translators: %s: store domain */
                'description' => sprintf(__('Stackless for EDD: %s', 'stackless-lite-edd'), (string) wp_parse_url($url, PHP_URL_HOST)),
                'metadata' => ['stackless_site' => Settings::siteMarker()],
            ]);
        } catch (StripeApiException $e) {
            return new \WP_Error('stackless_edd_stripe_webhook', $e->getMessage(), ['status' => $e->getHttpStatus(), 'type' => $e->getStripeType()]);
        }

        $secret = (string) ($created['secret'] ?? '');
        if ($secret === '') {
            return new \WP_Error('stackless_edd_stripe_webhook_secret', __('Stripe created the webhook but did not return its signing secret. Copy it from Stripe → Developers → Webhooks into the signing secret field.', 'stackless-lite-edd'));
        }
        return ['status' => $matches ? 'replaced' : 'created', 'id' => (string) ($created['id'] ?? ''), 'secret' => $secret];
    }

    /**
     * Every endpoint of this mode pointing at $url, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function find(StripeClient $client, string $url): array
    {
        $found = [];
        $after = '';
        // 100 per page; ten pages is far beyond Stripe's limit of 16 endpoints per account.
        for ($page = 0; $page < 10; $page++) {
            $query = ['limit' => 100];
            if ($after !== '') {
                $query['starting_after'] = $after;
            }
            $list = $client->get('webhook_endpoints', $query);
            foreach ((array) ($list['data'] ?? []) as $endpoint) {
                if (!is_array($endpoint)) {
                    continue;
                }
                $after = (string) ($endpoint['id'] ?? $after);
                if (WebhookAddress::same((string) ($endpoint['url'] ?? ''), $url)) {
                    $found[] = $endpoint;
                }
            }
            if (empty($list['has_more']) || $after === '') {
                break;
            }
        }
        return $found;
    }

    /**
     * Sets up the endpoint for one mode and saves the signing secret.
     *
     * @return array{status: string, id: string, secret: string}|\WP_Error
     */
    public static function run(string $mode)
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        if (Settings::secretKey($mode) === '') {
            return new \WP_Error('stackless_edd_stripe_webhook_key', $mode === 'live'
                ? __('Save a live secret key first, then set up the webhook.', 'stackless-lite-edd')
                : __('Save a test secret key first, then set up the webhook.', 'stackless-lite-edd'));
        }
        if ($mode === 'live' && !LiveStore::isLive()) {
            return LiveStore::copyError(__('This site is a copy of the live store, so it cannot take over the live Stripe webhook. Set it up from the live store, or use test mode here.', 'stackless-lite-edd'));
        }
        $url = Settings::webhookUrl();
        $problem = WebhookAddress::problem($url, 'Stripe');
        if ($problem !== '') {
            return new \WP_Error('stackless_edd_stripe_webhook_url', $problem);
        }

        $result = self::connect(Settings::client($mode), $url, Settings::webhookSecret($mode));
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            if ((int) ($data['status'] ?? 0) === 403 || ($data['type'] ?? '') === 'permission_error') {
                return new \WP_Error('stackless_edd_stripe_webhook_permission', __('The secret key cannot manage webhooks. A restricted key (rk_…) needs Webhook Endpoints set to Write: edit it in Stripe → Developers → API keys and try again.', 'stackless-lite-edd'));
            }
            return $result;
        }
        update_option(Settings::PREFIX . $mode . '_webhook_secret', $result['secret']);
        Settings::log(sprintf('Webhook setup (%s): %s %s', $mode, $result['status'], $result['id']));
        return $result;
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite-edd'), '', ['response' => 403]);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the nonce action checked next.
        $mode = isset($_GET['mode']) && sanitize_key(wp_unslash($_GET['mode'])) === 'live' ? 'live' : 'test';
        check_admin_referer(self::ACTION . '_' . $mode);

        $result = self::run($mode);
        set_transient(self::RESULT . get_current_user_id() . '_' . $mode, is_wp_error($result)
            ? ['ok' => false, 'message' => $result->get_error_message()]
            : ['ok' => true, 'status' => $result['status']], 120);
        wp_safe_redirect(SettingsPage::url(Settings::MODULE_ID) . '#stackless-stripe-webhook');
        exit;
    }

    /** One mode's line in the Status table: the last result, then the button (or what to do first). */
    public static function panel(string $mode): string
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        $label = $mode === 'live' ? __('Live mode', 'stackless-lite-edd') : __('Test mode', 'stackless-lite-edd');
        $html = '<p><strong>' . esc_html($label) . ':</strong> ';
        $key = self::RESULT . get_current_user_id() . '_' . $mode;
        $result = get_transient($key);
        $notice = '';
        if (is_array($result)) {
            delete_transient($key);
            $messages = [
                'created' => __('Webhook created in Stripe and its signing secret saved.', 'stackless-lite-edd'),
                'replaced' => __('The old Stripe webhook for this site had no saved signing secret, so it was replaced with a new one and its secret saved.', 'stackless-lite-edd'),
                'updated' => __('The Stripe webhook was switched on with every event.', 'stackless-lite-edd'),
                'ready' => __('The Stripe webhook is already set up.', 'stackless-lite-edd'),
            ];
            $notice = !empty($result['ok'])
                ? '<div class="notice notice-success inline"><p>' . esc_html($messages[(string) ($result['status'] ?? '')] ?? $messages['ready']) . '</p></div>'
                : '<div class="notice notice-error inline"><p>' . esc_html((string) ($result['message'] ?? '')) . '</p></div>';
        }

        if (Settings::secretKey($mode) === '') {
            return $notice . $html . esc_html__('save a secret key above first, then set up the webhook here in one click.', 'stackless-lite-edd') . '</p>';
        }
        $url = wp_nonce_url(add_query_arg(['action' => self::ACTION, 'mode' => $mode], admin_url('admin-post.php')), self::ACTION . '_' . $mode);
        $connected = Settings::webhookSecret($mode) !== '';
        $html .= '<a class="button' . ($connected ? '' : ' button-primary') . '" href="' . esc_url($url) . '">'
            . esc_html($connected ? __('Check the webhook in Stripe', 'stackless-lite-edd') : __('Set up the webhook in Stripe', 'stackless-lite-edd'))
            . '</a> ';
        $html .= esc_html($connected
            ? __('A signing secret is saved. Checking adds any missing events and switches the endpoint back on.', 'stackless-lite-edd')
            : __('Creates the endpoint with every event and saves its signing secret.', 'stackless-lite-edd'));
        return $notice . $html . '</p>';
    }
}
