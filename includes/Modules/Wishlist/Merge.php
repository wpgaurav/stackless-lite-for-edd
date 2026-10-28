<?php

namespace StacklessEDD\Modules\Wishlist;

defined('ABSPATH') || exit;

/**
 * Pure list logic for wishlists: identity of an entry, normalising input and
 * merging a guest list into an account list.
 *
 * An entry is a download plus a variable price id; price id 0 means the download itself
 * (no option chosen, or a download without variable prices).
 */
final class Merge
{
    /** Stable identity for a download/price pair. */
    public static function key(int $download_id, int $price_id): string
    {
        return $download_id . ':' . $price_id;
    }

    /**
     * Normalise raw entries into unique [download_id, price_id] pairs, preserving order.
     *
     * @param array<int, mixed> $entries each an array with download_id / price_id
     * @return array<int, array{download_id:int, price_id:int}>
     */
    public static function normalise(array $entries): array
    {
        $out = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $download = isset($entry['download_id']) && is_numeric($entry['download_id']) ? (int) $entry['download_id'] : 0;
            $price = isset($entry['price_id']) && is_numeric($entry['price_id']) ? (int) $entry['price_id'] : 0;
            if ($download <= 0 || $price < 0) {
                continue;
            }
            $key = self::key($download, $price);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['download_id' => $download, 'price_id' => $price];
        }
        return $out;
    }

    /**
     * Entries from the guest list that should be added to the account list.
     *
     * Account entries keep their place; guest entries already in the account list are
     * dropped; the result never pushes the account list past $limit.
     *
     * @param array<int, mixed> $account
     * @param array<int, mixed> $guest
     * @return array<int, array{download_id:int, price_id:int}>
     */
    public static function additions(array $account, array $guest, int $limit): array
    {
        $account = self::normalise($account);
        $room = max(0, $limit - count($account));
        if ($room === 0) {
            return [];
        }
        $existing = [];
        foreach ($account as $entry) {
            $existing[self::key($entry['download_id'], $entry['price_id'])] = true;
        }
        $add = [];
        foreach (self::normalise($guest) as $entry) {
            if (isset($existing[self::key($entry['download_id'], $entry['price_id'])])) {
                continue;
            }
            $add[] = $entry;
            if (count($add) >= $room) {
                break;
            }
        }
        return $add;
    }

    /**
     * The full merged list (account first, then guest additions).
     *
     * @param array<int, mixed> $account
     * @param array<int, mixed> $guest
     * @return array<int, array{download_id:int, price_id:int}>
     */
    public static function merged(array $account, array $guest, int $limit): array
    {
        $account = array_slice(self::normalise($account), 0, max(0, $limit));
        return array_merge($account, self::additions($account, $guest, $limit));
    }
}
