<?php

namespace StacklessEDD\Modules\ExternalProduct;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- click counts live in a custom table and change on every outbound click, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Outbound click counter, one row per download. Increments are a single atomic upsert,
 * so busy downloads never lose clicks to read-modify-write races or trigger post saves.
 */
final class Clicks
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_external_clicks';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
  download_id bigint(20) unsigned NOT NULL,
  clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  last_click_gmt datetime NULL DEFAULT NULL,
  PRIMARY KEY  (download_id)
) {$collate};");
    }

    public static function increment(int $downloadId): void
    {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare('INSERT INTO %i (download_id, clicks, last_click_gmt) VALUES (%d, 1, %s) ON DUPLICATE KEY UPDATE clicks = clicks + 1, last_click_gmt = %s', $table, $downloadId, $now, $now));
    }

    /** @return array{clicks: int, last: string} */
    public static function get(int $downloadId): array
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare('SELECT clicks, last_click_gmt FROM %i WHERE download_id = %d', $table, $downloadId), ARRAY_A);
        return [
            'clicks' => is_array($row) ? (int) $row['clicks'] : 0,
            'last' => is_array($row) ? (string) $row['last_click_gmt'] : '',
        ];
    }

    /**
     * @param int[] $downloadIds
     * @return array<int, int>
     */
    public static function many(array $downloadIds): array
    {
        global $wpdb;
        $downloadIds = array_values(array_filter(array_map('absint', $downloadIds)));
        if (!$downloadIds) {
            return [];
        }
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($downloadIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT download_id, clicks FROM %i WHERE download_id IN ({$placeholders})", $table, ...$downloadIds), ARRAY_A);
        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row['download_id']] = (int) $row['clicks'];
        }
        return $map;
    }

    public static function reset(int $downloadId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['download_id' => $downloadId], ['%d']);
    }
}
