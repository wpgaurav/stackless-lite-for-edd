<?php

namespace StacklessEDD\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Paddle amounts are strings of integers in the currency's lowest denomination
 * ("1999" is $19.99; "1500" is ¥1500). EDD amounts are decimals, so every
 * conversion goes through here.
 */
final class Money
{
    /** Currencies Paddle Billing can charge in. */
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK', 'ARS', 'BRL', 'CLP', 'CNY', 'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'KRW', 'MXN', 'NOK', 'NZD', 'PEN', 'PLN', 'RUB', 'THB', 'TRY', 'TWD', 'UAH', 'VND', 'ZAR'];

    /** Currencies whose lowest denomination is the whole unit. */
    public const ZERO_DECIMAL = ['CLP', 'JPY', 'KRW', 'VND'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount Decimal amount → Paddle integer. */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    /** @param float|int|string $amount Decimal amount → Paddle amount string. */
    public static function toPaddle($amount, string $currency): string
    {
        return (string) self::toMinor($amount, $currency);
    }

    /** @param int|string $amount Paddle integer → decimal float. */
    public static function fromMinor($amount, string $currency): float
    {
        $decimals = self::decimals($currency);
        return round(((int) $amount) / (10 ** $decimals), $decimals);
    }

    /**
     * @param float|int|string $a
     * @param float|int|string $b
     */
    public static function equals($a, $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_edd_paddle_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * Whether a Paddle transaction carries the amount we asked for. Depending on
     * the tax mode Paddle adds tax on top (subtotal is our price) or takes it out
     * of our price (total is our price).
     *
     * @param array $totals Transaction details.totals.
     */
    public static function paidMatches(array $totals, int $expectedMinor): bool
    {
        $candidates = [];
        foreach (['subtotal', 'total', 'grand_total'] as $key) {
            if (isset($totals[$key]) && is_numeric($totals[$key])) {
                $candidates[] = (int) $totals[$key];
            }
        }
        if (isset($totals['subtotal'], $totals['discount']) && is_numeric($totals['subtotal']) && is_numeric($totals['discount'])) {
            $candidates[] = (int) $totals['subtotal'] - (int) $totals['discount'];
        }
        return in_array($expectedMinor, $candidates, true);
    }

    /** A plain-text price for order notes ("$12.00"), entities decoded. @param float|int|string $amount */
    public static function label($amount, string $currency): string
    {
        return html_entity_decode(wp_strip_all_tags(stackless_edd_price($amount, strtoupper($currency))), ENT_QUOTES, 'UTF-8');
    }
}
