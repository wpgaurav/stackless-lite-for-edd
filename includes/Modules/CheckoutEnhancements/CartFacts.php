<?php

namespace StacklessEDD\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * The few cart facts consents and the shorter checkout depend on, read the same way from a
 * live cart or a placed order so the stored record matches what the customer was shown.
 * Every EDD download is digital: nothing ships.
 */
final class CartFacts
{
    /** @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]} */
    public static function fromCart(): array
    {
        $ids = [];
        foreach ((array) (function_exists('edd_get_cart_contents') ? edd_get_cart_contents() : []) as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $ids[] = (int) $item['id'];
            }
        }
        return self::fromIds($ids);
    }

    /** @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]} */
    public static function fromOrder(int $orderId): array
    {
        $ids = [];
        $order = $orderId > 0 && function_exists('edd_get_order') ? edd_get_order($orderId) : null;
        if ($order instanceof \EDD\Orders\Order) {
            foreach ((array) $order->get_items() as $item) {
                if (is_object($item) && !empty($item->product_id)) {
                    $ids[] = (int) $item->product_id;
                }
            }
        }
        return self::fromIds($ids);
    }

    /**
     * @param int[] $ids
     * @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]}
     */
    public static function fromIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return ['needs_shipping' => false, 'has_digital' => (bool) $ids, 'product_ids' => $ids];
    }

    /**
     * What the cart is worth, for the shorter checkout and the free-order button.
     *
     * @return array{items_total: float, fees_total: float, total: float, subscription: bool}
     */
    public static function valueFromCart(): array
    {
        $value = ['items_total' => 0.0, 'fees_total' => 0.0, 'total' => 0.0, 'subscription' => false];
        if (!function_exists('edd_get_cart_contents')) {
            return $value;
        }
        foreach ((array) edd_get_cart_contents() as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
            $value['items_total'] += (float) edd_get_cart_item_price((int) $item['id'], $options) * max(0, (float) ($item['quantity'] ?? 1));
            $priceId = isset($options['price_id']) && is_numeric($options['price_id']) ? (int) $options['price_id'] : null;
            if (!$value['subscription'] && stackless_edd_is_subscription_product((int) $item['id'], $priceId)) {
                $value['subscription'] = true;
            }
        }
        foreach ((array) edd_get_cart_fees() as $fee) {
            if (is_array($fee) && isset($fee['amount'])) {
                $value['fees_total'] += max(0.0, (float) $fee['amount']);
            }
        }
        $value['total'] = (float) edd_get_cart_total();
        if (!$value['subscription'] && stackless_edd_cart_contains_subscription()) {
            $value['subscription'] = true;
        }
        return $value;
    }
}
