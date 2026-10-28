<?php

namespace StacklessEDD\Modules\ExternalProduct;

defined('ABSPATH') || exit;

/**
 * Pure link rules for external downloads.
 */
final class Link
{
    /** EDD joins the price and the label of its purchase button with this separator. */
    public const SEPARATOR = '&nbsp;&ndash;&nbsp;';

    /**
     * Download value '' inherits the store default; 'yes'/'no' override it.
     */
    public static function flag(string $downloadValue, bool $storeDefault): bool
    {
        if ($downloadValue === 'yes') {
            return true;
        }
        if ($downloadValue === 'no') {
            return false;
        }
        return $storeDefault;
    }

    /** '' inherits; '_self' or '_blank' override. */
    public static function target(string $downloadValue, string $storeDefault): string
    {
        if (in_array($downloadValue, ['_self', '_blank'], true)) {
            return $downloadValue;
        }
        return $storeDefault === '_self' ? '_self' : '_blank';
    }

    public static function rel(bool $nofollow, bool $sponsored, string $target): string
    {
        $rel = [];
        if ($nofollow) {
            $rel[] = 'nofollow';
        }
        if ($sponsored) {
            $rel[] = 'sponsored';
        }
        if ($target === '_blank') {
            $rel[] = 'noopener';
        }
        return implode(' ', $rel);
    }

    public static function validUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * The price part of EDD's purchase button text ("{price}&nbsp;&ndash;&nbsp;{label}"),
     * or '' when the button carries no price.
     */
    public static function pricePart(string $buttonText): string
    {
        $position = strrpos($buttonText, self::SEPARATOR);
        return $position === false ? '' : substr($buttonText, 0, $position);
    }
}
