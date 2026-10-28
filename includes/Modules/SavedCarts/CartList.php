<?php

namespace StacklessEDD\Modules\SavedCarts;

defined('ABSPATH') || exit;

/**
 * Pure rules for saved carts: what is kept from an EDD cart line, how lists are cleaned,
 * and the limits on lists and lines.
 *
 * A stored line is ['download_id' => int, 'price_id' => int|null, 'quantity' => int, 'options' => array]:
 * the download, its variable price (null for downloads without variable prices), and the
 * Stackless cart item options (add-on choices and the like) to put back on restore.
 */
final class CartList
{
    public const MAX_CARTS = 10;
    public const MAX_ITEMS = 50;
    public const MAX_QTY = 10000;
    public const MAX_NAME = 100;

    /** Cart item option keys this plugin owns; other plugins' options are never restored. */
    public const OPTION_PREFIX = 'stackless_edd_';

    /** EDD stores cart item options as order item meta under this prefix. */
    public const ORDER_META_PREFIX = '_option_';

    /**
     * Cart item options worth keeping: only this plugin's own keys (stackless_edd_*), with scalar
     * or nested-scalar values, so restoring never resurrects objects or another plugin's state.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function keepData(array $options): array
    {
        $out = [];
        foreach ($options as $key => $value) {
            if (!is_string($key) || strpos($key, self::OPTION_PREFIX) !== 0 || !self::plain($value)) {
                continue;
            }
            $out[$key] = $value;
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($out) : json_encode($out);
        return is_string($json) && strlen($json) <= 8000 ? $out : [];
    }

    /** @param mixed $value */
    private static function plain($value, int $depth = 0): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value) || $depth > 3) {
            return false;
        }
        foreach ($value as $v) {
            if (!self::plain($v, $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function priceId($value): ?int
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value) || (int) $value < 0) {
            return null;
        }
        return (int) $value;
    }

    /** @param mixed $value */
    private static function qty($value): int
    {
        return is_numeric($value) ? (int) min(self::MAX_QTY, max(0, (int) floor((float) $value))) : 0;
    }

    /**
     * Turn EDD cart contents into storable lines.
     *
     * @param array<int|string, mixed> $contents edd_get_cart_contents()
     * @return array<int, array{download_id:int, price_id:int|null, quantity:int, options:array<string, mixed>}>
     */
    public static function fromCart(array $contents): array
    {
        $lines = [];
        foreach ($contents as $item) {
            if (!is_array($item)) {
                continue;
            }
            $download_id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
            $quantity = self::qty($item['quantity'] ?? 1);
            if ($download_id <= 0 || $quantity <= 0) {
                continue;
            }
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $lines[] = [
                'download_id' => $download_id,
                'price_id' => self::priceId($options['price_id'] ?? null),
                'quantity' => $quantity,
                'options' => self::keepData($options),
            ];
            if (count($lines) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $lines;
    }

    /**
     * Cart item options from an order item's meta (edd_get_order_item_meta($id) without a key:
     * key => list of values). EDD saved each option as "_option_{key}".
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function optionsFromOrderMeta(array $meta): array
    {
        $options = [];
        foreach ($meta as $key => $values) {
            if (!is_string($key) || strpos($key, self::ORDER_META_PREFIX) !== 0) {
                continue;
            }
            $value = is_array($values) && array_key_exists(0, $values) && count($values) === 1 ? $values[0] : $values;
            if (is_string($value) && preg_match('/^(a|s|i|d|b):\d|^N;$|^b:[01];$/', $value)) {
                // Raw meta is serialized; never revive objects.
                $decoded = @unserialize($value, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions
                if ($decoded !== false || $value === 'b:0;') {
                    $value = $decoded;
                }
            }
            $options[substr($key, strlen(self::ORDER_META_PREFIX))] = $value;
        }
        return self::keepData($options);
    }

    /**
     * Clean a stored list (user meta can be anything).
     *
     * @param mixed $raw
     * @return array<string, array{name:string, created:int, items: array<int, array{download_id:int, price_id:int|null, quantity:int, options:array<string, mixed>}>}>
     */
    public static function normalise($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $entry) {
            if (!is_string($key) || !preg_match('/^[a-f0-9\-]{8,40}$/', $key) || !is_array($entry)) {
                continue;
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            $items = [];
            foreach ((array) ($entry['items'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $download = isset($line['download_id']) && is_numeric($line['download_id']) ? (int) $line['download_id'] : 0;
                $qty = self::qty($line['quantity'] ?? 0);
                if ($download <= 0 || $qty <= 0) {
                    continue;
                }
                $items[] = [
                    'download_id' => $download,
                    'price_id' => self::priceId($line['price_id'] ?? null),
                    'quantity' => $qty,
                    'options' => is_array($line['options'] ?? null) ? self::keepData($line['options']) : [],
                ];
                if (count($items) >= self::MAX_ITEMS) {
                    break;
                }
            }
            if ($name === '' || !$items) {
                continue;
            }
            $out[$key] = [
                'name' => mb_substr($name, 0, self::MAX_NAME),
                'created' => max(0, (int) ($entry['created'] ?? 0)),
                'items' => $items,
            ];
            if (count($out) >= self::MAX_CARTS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Add a cart to the list.
     *
     * @param array<string, array<string, mixed>> $list normalised
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     * @throws \LengthException when the list is full
     * @throws \InvalidArgumentException when name or items are empty
     */
    public static function add(array $list, string $key, string $name, array $items, int $now): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || !$items) {
            throw new \InvalidArgumentException('empty');
        }
        if (count($list) >= self::MAX_CARTS) {
            throw new \LengthException('full');
        }
        $list[$key] = ['name' => mb_substr($name, 0, self::MAX_NAME), 'created' => $now, 'items' => array_slice($items, 0, self::MAX_ITEMS)];
        return $list;
    }

    /** Total quantity of a saved cart. @param array<string, mixed> $cart */
    public static function quantity(array $cart): int
    {
        return (int) array_sum(array_map(static function ($line): int {
            return is_array($line) ? (int) ($line['quantity'] ?? 0) : 0;
        }, (array) ($cart['items'] ?? [])));
    }
}
