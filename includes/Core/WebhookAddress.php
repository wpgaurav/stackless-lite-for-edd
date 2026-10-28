<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/** Whether a payment provider can deliver webhooks to this site, for the one-click webhook setups. */
final class WebhookAddress
{
    /** '' when $provider can reach $url, else why not. */
    public static function problem(string $url, string $provider): string
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $local = $host === '' || $host === 'localhost' || preg_match('/\.(test|local|localhost|invalid|example)$/', $host) || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
        if ($local) {
            /* translators: %s: payment provider name */
            return sprintf(__('%s cannot reach this site: it runs on a local or private address. Set up the webhook on a public copy of the store.', 'stackless-lite-edd'), $provider);
        }
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            /* translators: %s: payment provider name */
            return sprintf(__('%s only sends webhooks to https addresses. Turn on SSL for this site first.', 'stackless-lite-edd'), $provider);
        }
        return '';
    }

    /** Same endpoint, ignoring scheme, letter case and a trailing slash. */
    public static function same(string $a, string $b): bool
    {
        $normalize = static function (string $url): string {
            return strtolower(rtrim((string) preg_replace('#^https?://#i', '', trim($url)), '/'));
        };
        return $a !== '' && $normalize($a) === $normalize($b);
    }
}
