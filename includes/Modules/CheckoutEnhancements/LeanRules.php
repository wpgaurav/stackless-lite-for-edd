<?php

namespace StacklessEDD\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Pure decisions for the shorter checkout on free orders and the free-order button.
 *
 * Facts come from CartFacts::fromCart() merged with CartFacts::valueFromCart():
 * product_ids, needs_shipping, items_total, fees_total, total, subscription.
 *
 * - Zero value: nothing to pay for the items themselves (no item price, no fees) and no
 *   subscription to bill later. Discounts do not make a cart zero value.
 * - Zero total: nothing to pay at checkout after discounts, and no subscription to bill later.
 * - Free digital: nothing ships and the cart is zero value (every EDD cart is digital).
 *
 * EDD itself already leaves the billing address off free orders and asks for it on paid orders
 * only when tax or the store's checkout address fields need it, so the country rules of the
 * WooCommerce edition have nothing left to do here.
 */
final class LeanRules
{
    public static function hasItems(array $facts): bool
    {
        return !empty($facts['product_ids']);
    }

    public static function digital(array $facts): bool
    {
        return self::hasItems($facts) && empty($facts['needs_shipping']);
    }

    public static function zeroValue(array $facts): bool
    {
        if (!self::hasItems($facts) || !empty($facts['subscription'])) {
            return false;
        }
        return self::nothing($facts['items_total'] ?? 0) && self::nothing($facts['fees_total'] ?? 0);
    }

    public static function zeroTotal(array $facts): bool
    {
        if (!self::hasItems($facts) || !empty($facts['subscription'])) {
            return false;
        }
        return self::nothing($facts['total'] ?? 0);
    }

    public static function freeDigital(array $facts, bool $freeOn): bool
    {
        return $freeOn && self::digital($facts) && self::zeroValue($facts);
    }

    /**
     * Purchase button label: the free-order text when there is nothing to pay, else the merchant's
     * own label, else what EDD or the payment method would show.
     *
     * @param string $free '' when the free-order button is off.
     */
    public static function buttonText(string $current, string $custom, string $free, bool $zeroTotal): string
    {
        if ($free !== '' && $zeroTotal) {
            return $free;
        }
        return $custom !== '' ? $custom : $current;
    }

    /** @param mixed $amount */
    private static function nothing($amount): bool
    {
        return round(is_numeric($amount) ? (float) $amount : 0.0, 6) <= 0.0;
    }
}
