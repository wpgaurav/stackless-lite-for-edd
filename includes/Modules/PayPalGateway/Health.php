<?php

namespace StacklessEDD\Modules\PayPalGateway;

use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Health source for PayPal (2.0.0), answered on stackless_edd_health_sources: configuration
 * problems and recent failed or stuck webhook events. Event ids, types and short notes only,
 * never secrets or customer data.
 */
final class Health
{
    public const SOURCE = 'paypal_gateway';

    /**
     * stackless_edd_health_sources
     *
     * @param mixed $sources
     * @return mixed
     */
    public static function register($sources)
    {
        if (!is_array($sources)) {
            return $sources;
        }
        $sources[self::SOURCE] = [self::class, 'source'];
        return $sources;
    }

    /** @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array{id:string,type:string,status:string,note:string,at:string}>} */
    public static function source(): array
    {
        return ['label' => __('PayPal', 'stackless-lite-edd'), 'issues' => self::issues(Gateway::instance()), 'events' => self::events()];
    }

    /** @return array<int,array{severity:string,title:string,detail:string,url:string}> */
    public static function issues(Gateway $gateway): array
    {
        if (!$gateway->isEnabled()) {
            return [];
        }
        $url = class_exists(\StacklessEDD\Admin\SettingsPage::class) ? \StacklessEDD\Admin\SettingsPage::url('paypal_gateway') : admin_url();
        $environment = $gateway->environment();
        $issues = [];
        if (!$gateway->api()->hasCredentials()) {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: sandbox or live */
                __('PayPal is turned on but the %s client ID or secret is missing', 'stackless-lite-edd'),
                $environment
            ), __('PayPal stays hidden at checkout until both are saved for the selected environment.', 'stackless-lite-edd'), $url);
        }
        if ($gateway->webhookId($environment) === '') {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: sandbox or live */
                __('The PayPal %s webhook ID is missing', 'stackless-lite-edd'),
                $environment
            ), __('Refunds made in PayPal, delayed payments and saved-account changes do not reach EDD without it. Add the webhook in your PayPal app and paste its ID.', 'stackless-lite-edd'), $url);
        }
        if ($environment === 'sandbox' && class_exists(LiveStore::class) && LiveStore::isLive()) {
            $issues[] = self::issue('warning', __('PayPal is in sandbox mode on the live store', 'stackless-lite-edd'), __('Customers cannot pay with real PayPal accounts until sandbox mode is turned off.', 'stackless-lite-edd'), $url);
        }
        if ($gateway->setting('fastlane', 'no') === 'yes' && !$gateway->cardFieldsEnabled()) {
            $issues[] = self::issue('info', __('Fastlane needs the PayPal card fields', 'stackless-lite-edd'), __('Turn on card fields too, or Fastlane stays hidden.', 'stackless-lite-edd'), $url);
        }
        return $issues;
    }

    /** @return array<int,array{id:string,type:string,status:string,note:string,at:string}> */
    public static function events(): array
    {
        $events = [];
        foreach (WebhookLog::problems(20) as $row) {
            $failed = (string) ($row['status'] ?? '') === 'failed';
            $note = $failed ? (string) ($row['message'] ?? '') : __('Claimed but never finished; PayPal retries it.', 'stackless-lite-edd');
            $events[] = [
                'id' => (string) ($row['event_id'] ?? ''),
                'type' => (string) ($row['event_type'] ?? ''),
                'status' => $failed ? 'failed' : 'stuck',
                'note' => (string) preg_replace('/[^\s@]+@[^\s@]+/', '[email]', $note),
                'at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $events;
    }

    /** @return array{severity:string,title:string,detail:string,url:string} */
    private static function issue(string $severity, string $title, string $detail, string $url): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'url' => $url];
    }
}
