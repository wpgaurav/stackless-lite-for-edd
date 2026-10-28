<?php

namespace StacklessEDD\Modules\QuantityLimits;

defined('ABSPATH') || exit;

/**
 * Pure HTML edits on EDD's quantity input markup. EDD prints the input as a fixed string
 * (min="1" step="1" value="1"), so the limits are written into the first quantity input.
 */
final class Markup
{
    /**
     * @param array{min: int, max: int, step: int} $limits
     */
    public static function quantityInput(string $html, array $limits, ?int $value = null): string
    {
        $value = $value ?? $limits['min'];
        return (string) preg_replace_callback('/<input\b[^>]*\bedd-item-quantity\b[^>]*>/', static function (array $match) use ($limits, $value): string {
            $tag = $match[0];
            $tag = self::setAttribute($tag, 'min', (string) $limits['min']);
            $tag = $limits['max'] > 0 ? self::setAttribute($tag, 'max', (string) $limits['max']) : self::removeAttribute($tag, 'max');
            $tag = self::setAttribute($tag, 'step', (string) $limits['step']);
            return self::setAttribute($tag, 'value', (string) $value);
        }, $html, 1);
    }

    /** Add an attribute to the first quantity wrapper div. The value must already be escaped. */
    public static function wrapperAttribute(string $html, string $name, string $escapedValue): string
    {
        return (string) preg_replace('/<div\b([^>]*\bedd_download_quantity_wrapper\b[^>]*)>/', '<div$1 ' . $name . '="' . str_replace(['\\', '$'], ['\\\\', '\\$'], $escapedValue) . '">', $html, 1);
    }

    /** Insert markup just before the last closing div (inside EDD's quantity wrapper). */
    public static function appendInside(string $html, string $markup): string
    {
        $position = strrpos($html, '</div>');
        if ($position === false) {
            return $html . $markup;
        }
        return substr($html, 0, $position) . $markup . substr($html, $position);
    }

    public static function setAttribute(string $tag, string $name, string $value): string
    {
        $pattern = '/\s' . preg_quote($name, '/') . '="[^"]*"/';
        $attribute = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        if (preg_match($pattern, $tag)) {
            return (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $attribute), $tag, 1);
        }
        return (string) preg_replace('/\s*\/?>$/', $attribute . '$0', $tag, 1);
    }

    public static function removeAttribute(string $tag, string $name): string
    {
        return (string) preg_replace('/\s' . preg_quote($name, '/') . '="[^"]*"/', '', $tag);
    }
}
