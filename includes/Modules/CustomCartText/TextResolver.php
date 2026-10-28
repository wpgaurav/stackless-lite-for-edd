<?php

namespace StacklessEDD\Modules\CustomCartText;

defined('ABSPATH') || exit;

/**
 * Pure precedence rules for button text, and the EDD button text format
 * ("{price}&nbsp;&ndash;&nbsp;{label}", or just "{label}" when the button shows no price).
 */
final class TextResolver
{
    public const SEPARATOR = '&nbsp;&ndash;&nbsp;';

    /**
     * First non-empty candidate wins; EDD's own label is the fallback.
     *
     * @param array<int, mixed> $candidates Most specific first.
     */
    public static function resolve(array $candidates, string $default): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return $default;
    }

    /**
     * The state keys that apply to a download, most specific first.
     *
     * @param string $type simple|variable|bundle
     * @return string[] e.g. ['free', 'simple']
     */
    public static function keys(string $type, bool $free, bool $subscription): array
    {
        $keys = [];
        if ($free) {
            $keys[] = 'free';
        }
        if ($subscription) {
            $keys[] = 'subscription';
        }
        if (in_array($type, ['simple', 'variable', 'bundle'], true)) {
            $keys[] = $type;
        }
        return $keys;
    }

    /**
     * Split a purchase button text into its price part and its label.
     *
     * @param bool $hasPrice Whether EDD put a price on this button.
     * @return array{0:string,1:string} [price, label]
     */
    public static function split(string $text, bool $hasPrice): array
    {
        if (!$hasPrice) {
            return ['', $text];
        }
        $position = strpos($text, self::SEPARATOR);
        if ($position === false) {
            return [$text, ''];
        }
        return [substr($text, 0, $position), substr($text, $position + strlen(self::SEPARATOR))];
    }

    public static function join(string $price, string $label): string
    {
        if ($price === '') {
            return $label;
        }
        return $label === '' ? $price : $price . self::SEPARATOR . $label;
    }
}
