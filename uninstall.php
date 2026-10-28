<?php
/**
 * Uninstall Stackless Lite for EDD.
 *
 * Settings and store data are kept by default, so they are there again if you reinstall Lite or move to
 * Stackless for EDD. To remove Stackless tables, options and scheduled actions on uninstall, define
 * STACKLESS_EDD_REMOVE_ALL_DATA as true in wp-config.php before deleting the plugin. EDD orders are never
 * deleted. Nothing is removed while Stackless for EDD is installed: it uses the same data.
 *
 * @package StacklessEDD
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_site_transient('stackless_edd_lite_update');

if (!defined('STACKLESS_EDD_REMOVE_ALL_DATA') || STACKLESS_EDD_REMOVE_ALL_DATA !== true) {
    return;
}
if (file_exists(WP_PLUGIN_DIR . '/stackless-edd/stackless-edd.php')) {
    return;
}

global $wpdb;

// The opt-in cleanup runs once, against the live schema, so these queries are direct and uncached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Custom tables.
$stackless_edd_tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'stackless_edd_') . '%'));
foreach ($stackless_edd_tables as $stackless_edd_table) {
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $stackless_edd_table));
}

// Options and transients.
$wpdb->query($wpdb->prepare(
    'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
    $wpdb->options,
    $wpdb->esc_like('stackless_edd_') . '%',
    $wpdb->esc_like('_transient_stackless_edd_') . '%',
    $wpdb->esc_like('_transient_timeout_stackless_edd_') . '%'
));

// Scheduled actions.
if (function_exists('as_unschedule_all_actions')) {
    foreach ($wpdb->get_col($wpdb->prepare('SELECT DISTINCT hook FROM %i WHERE hook LIKE %s', $wpdb->prefix . 'actionscheduler_actions', $wpdb->esc_like('stackless_edd') . '%')) as $stackless_edd_hook) {
        as_unschedule_all_actions($stackless_edd_hook);
    }
}
// phpcs:enable
