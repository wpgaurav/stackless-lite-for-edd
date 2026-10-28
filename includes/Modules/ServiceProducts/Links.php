<?php

namespace StacklessEDD\Modules\ServiceProducts;

defined('ABSPATH') || exit;

/**
 * Pure helpers for service downloads: booking-link placeholders, de-duplication and order state.
 */
final class Links
{
    public const PLACEHOLDERS = ['{order_id}', '{order_number}', '{email}', '{first_name}', '{last_name}', '{name}', '{product}'];

    /** Order statuses after which nothing is shown: no instructions and no "before payment" note. */
    public const CLOSED_STATUSES = ['refunded', 'revoked', 'failed', 'abandoned', 'cancelled', 'trash'];

    /**
     * Fill placeholders in a booking/intake URL. Values are URL-encoded; the result must
     * still be an http(s) URL or '' is returned.
     *
     * @param array<string, string> $vars placeholder (without braces) => raw value
     */
    public static function fill(string $template, array $vars): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = rawurlencode((string) $value);
        }
        // Unknown placeholders are removed rather than left in the link.
        $url = strtr($template, $replace);
        $url = preg_replace('/\{[a-z_]+\}/', '', $url) ?? '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            return '';
        }
        return $url;
    }

    /**
     * Unique service download ids from order lines, keeping order.
     *
     * @param array<int, array{product_id:int, is_service:bool}> $lines
     * @return int[]
     */
    public static function serviceProducts(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            if ($id > 0 && !empty($line['is_service']) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Where an EDD order stands for service instructions.
     *
     * @param string[] $completeStatuses edd_get_complete_order_statuses()
     * @return string "paid" (show instructions), "pending" (show the before-payment note) or "closed" (show nothing)
     */
    public static function orderState(string $status, array $completeStatuses): string
    {
        if (in_array($status, self::CLOSED_STATUSES, true)) {
            return 'closed';
        }
        return in_array($status, $completeStatuses, true) ? 'paid' : 'pending';
    }

    /**
     * First and last name from a full name ("Sam O'Neil Jr" → Sam, O'Neil Jr).
     *
     * @return array{0:string, 1:string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
