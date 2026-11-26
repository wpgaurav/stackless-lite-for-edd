<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * First payments that differ from the renewal price, for providers that bill one price per cycle.
 *
 * Dodo Payments and Polar charge a subscription product's price on every cycle. A discount code
 * that only lowers the first payment is sent to them as a one-cycle discount for the difference,
 * so the buyer pays what EDD billed and renewals return to the full price. A first payment above
 * the renewal price (a signup fee) cannot be expressed that way.
 */
final class FirstPayment
{
    /**
     * What to take off the first charge, in minor units.
     *
     * @return int|null 0 when the first payment equals the renewal; null when a discount cannot
     *                  produce it (a higher first payment, or nothing due).
     */
    public static function discount(int $firstMinor, int $recurringMinor): ?int
    {
        if ($firstMinor === $recurringMinor) {
            return 0;
        }
        if ($firstMinor > 0 && $firstMinor < $recurringMinor) {
            return $recurringMinor - $firstMinor;
        }
        return null;
    }

    /** The same share of another price, e.g. the discount in the provider's default currency. Never below 1 or above that price. */
    public static function scale(int $discountMinor, int $recurringMinor, int $otherMinor): int
    {
        if ($recurringMinor <= 0 || $otherMinor <= 0) {
            return 1;
        }
        return max(1, min($otherMinor, (int) round($otherMinor * $discountMinor / $recurringMinor)));
    }

    /** A short provider-side name that ties the discount to its EDD order. */
    public static function label(int $orderId): string
    {
        /* translators: %d: EDD order ID. */
        return sprintf(__('First payment, order #%d', 'stackless-lite-edd'), $orderId);
    }
}
