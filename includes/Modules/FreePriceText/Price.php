<?php

namespace StacklessEDD\Modules\FreePriceText;

defined('ABSPATH') || exit;

/**
 * Pure price checks used by the module. No WordPress calls, so they are unit tested directly.
 */
final class Price
{
    /** Separator EDD puts between the price and the label on a purchase button. */
    public const SEPARATOR = '&nbsp;&ndash;&nbsp;';

    /**
     * A price is free only when it is set and equals zero. An empty string means "no value",
     * which callers resolve through EDD first (EDD stores a blank price as 0).
     *
     * @param mixed $price
     */
    public static function isZero($price): bool
    {
        if ($price === null || $price === '' || is_bool($price) || is_array($price) || is_object($price)) {
            return false;
        }
        if (!is_numeric($price)) {
            return false;
        }
        return abs((float) $price) < 0.000001;
    }

    /**
     * Classify a list of display prices (variable prices).
     *
     * @param array<int|string, mixed> $prices
     * @return string "none" (nothing free or no prices), "all" (every price is zero), "from" (cheapest is zero, others are not)
     */
    public static function rangeState(array $prices): string
    {
        $numeric = [];
        foreach ($prices as $price) {
            if ($price !== '' && $price !== null && is_numeric($price)) {
                $numeric[] = (float) $price;
            }
        }
        if (!$numeric) {
            return 'none';
        }
        $min = min($numeric);
        $max = max($numeric);
        if (!self::isZero($min)) {
            return 'none';
        }
        return self::isZero($max) ? 'all' : 'from';
    }

    /**
     * Highest price in a list, for "Free – $X" ranges.
     *
     * @param array<int|string, mixed> $prices
     */
    public static function max(array $prices): float
    {
        $numeric = [];
        foreach ($prices as $price) {
            if ($price !== '' && $price !== null && is_numeric($price)) {
                $numeric[] = (float) $price;
            }
        }
        return $numeric ? max($numeric) : 0.0;
    }

    /**
     * Whether an already formatted amount ("$0.00", "0,00 €", "¥0") is zero: it has at least one
     * digit and none of them is 1-9. Used where EDD hands over formatted HTML only (cart widget).
     */
    public static function isZeroFormatted(string $formatted): bool
    {
        $text = html_entity_decode(wp_strip_all_tags($formatted), ENT_QUOTES, 'UTF-8');
        return preg_match('/[0-9]/', $text) === 1 && preg_match('/[1-9]/', $text) === 0;
    }

    /**
     * Swap the price part of an EDD purchase button text ("{price}&nbsp;&ndash;&nbsp;{label}", or just
     * "{price}" when the label is empty) and keep the label.
     */
    public static function replaceButtonPrice(string $buttonText, string $price): string
    {
        $position = strpos($buttonText, self::SEPARATOR);
        if ($position === false) {
            return $price;
        }
        return $price . substr($buttonText, $position);
    }
}
