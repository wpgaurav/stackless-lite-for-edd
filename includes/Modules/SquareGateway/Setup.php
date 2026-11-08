<?php

namespace StacklessEDD\Modules\SquareGateway;

use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/**
 * One-click setup on save: the business location (and so the currency) a mode charges
 * through, and the webhook subscription with its signature key.
 */
final class Setup
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Read the configured location (the main one when none is set) and remember its name,
     * country and currency.
     *
     * @return array{id:string, name:string, currency:string, country:string}|\WP_Error
     */
    public function location(string $mode)
    {
        $pinned = $this->gateway->isDefined($mode, 'location_id');
        $id = $pinned ? $this->gateway->locationId($mode) : $this->gateway->savedLocationId($mode);
        $response = $this->gateway->api($mode)->request('GET', 'locations/' . rawurlencode($id !== '' ? $id : 'main'));
        if (is_wp_error($response)) {
            return $response;
        }
        $location = is_array($response['location'] ?? null) ? $response['location'] : [];
        if (empty($location['id'])) {
            return new \WP_Error('stackless_edd_square_location', __('Square did not return the location.', 'stackless-lite-edd'));
        }
        if (($location['status'] ?? 'ACTIVE') !== 'ACTIVE' || !in_array('CREDIT_CARD_PROCESSING', (array) ($location['capabilities'] ?? ['CREDIT_CARD_PROCESSING']), true)) {
            /* translators: %s: location name */
            return new \WP_Error('stackless_edd_square_location', sprintf(__('The Square location %s cannot take card payments. Choose an active location with card processing.', 'stackless-lite-edd'), (string) ($location['name'] ?? $location['id'])));
        }
        $saved = [
            'id' => (string) $location['id'],
            'name' => (string) ($location['name'] ?? ''),
            'currency' => strtoupper((string) ($location['currency'] ?? '')),
            'country' => strtoupper((string) ($location['country'] ?? '')),
        ];
        update_option($this->gateway->locationOption($mode), $saved, false);
        if ($id === '' && !$pinned) {
            $this->gateway->saveSetting($mode . '_location_id', $saved['id']);
        }
        return $saved;
    }

    /**
     * Create this site's webhook subscription when no signature key is saved, or find the one
     * already pointing here and give it a fresh key (Square shows a key only when it is made).
     * Needs a public HTTPS address and a personal access token.
     *
     * @return string|\WP_Error A message for the log ('' when nothing was done).
     */
    public function webhook(string $mode)
    {
        $url = $this->gateway->webhookUrl();
        if ($this->gateway->webhookKey($mode) !== '' || $this->gateway->isDefined($mode, 'webhook_signature_key')) {
            return '';
        }
        // A mode without an access token isn't set up yet: nothing to create, nothing to report.
        if ($this->gateway->accessToken($mode) === '') {
            return '';
        }
        $problem = WebhookAddress::problem($url, 'Square');
        if ($problem !== '') {
            // Not an error on a local or staging copy: the settings show the webhook is not set up.
            return $problem;
        }
        $api = $this->gateway->api($mode);
        $key = '';
        $list = $api->request('GET', 'webhooks/subscriptions', ['include_disabled' => 'false']);
        if (is_array($list)) {
            foreach ((array) ($list['subscriptions'] ?? []) as $subscription) {
                if (is_array($subscription) && (string) ($subscription['notification_url'] ?? '') === $url && !empty($subscription['id'])) {
                    $rotated = $api->request('POST', 'webhooks/subscriptions/' . rawurlencode((string) $subscription['id']) . '/signature-key', [
                        'idempotency_key' => Api::key('whk', $mode . '-' . substr(md5((string) $subscription['id'] . time()), 0, 12)),
                    ]);
                    if (is_array($rotated)) {
                        $key = (string) ($rotated['signature_key'] ?? '');
                    }
                    break;
                }
            }
        }
        if ($key === '') {
            $created = $api->request('POST', 'webhooks/subscriptions', [
                'idempotency_key' => Api::key('wh', $mode . '-' . substr(md5($url), 0, 16)),
                'subscription' => [
                    'name' => 'Stackless for EDD',
                    'event_types' => EventMap::EVENTS,
                    'notification_url' => $url,
                    'api_version' => Api::VERSION,
                ],
            ]);
            if (is_wp_error($created)) {
                /* translators: 1: Sandbox or Production, 2: error */
                return new \WP_Error('stackless_edd_square_webhook', sprintf(__('Creating the %1$s Square webhook failed: %2$s Add it by hand (see Webhook below).', 'stackless-lite-edd'), $mode === 'test' ? __('Sandbox', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd'), $created->get_error_message()));
            }
            $key = (string) ($created['subscription']['signature_key'] ?? '');
        }
        if ($key === '') {
            return '';
        }
        $this->gateway->saveSetting($mode . '_webhook_signature_key', $key);
        update_option($this->gateway->webhookUrlOption($mode), $url, false);
        /* translators: %s: Sandbox or Production */
        return sprintf(__('Square %s webhook connected and its signature key saved.', 'stackless-lite-edd'), $mode === 'test' ? __('Sandbox', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd'));
    }

    /** The exact URL Square signs with: the one the subscription was created for. */
    public static function signedUrl(string $mode, string $fallback): string
    {
        $stored = (string) get_option(Gateway::OPTION_PREFIX . 'webhook_url_' . ($mode === 'test' ? 'test' : 'live'), '');
        return $stored !== '' ? $stored : $fallback;
    }
}
