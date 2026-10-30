<?php
/**
 * Plugin Name: Stackless Lite for EDD
 * Plugin URI: https://gauravtiwari.org/product/stackless-lite-for-edd/
 * Description: Free store tools for Easy Digital Downloads: wishlists, saved carts, pay what you like, buy links, quantity limits, checkout consents and more. Switch on only what you use.
 * Version: 1.3.0
 * Author: Gaurav Tiwari
 * Author URI: https://gauravtiwari.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stackless-lite-edd
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Update URI: https://gauravtiwari.org/product/stackless-lite-for-edd/
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;

/*
 * Stackless for EDD (the full plugin) contains everything in Lite and uses the same classes, so the two
 * never run together. This check runs before Lite defines anything: when the full plugin is loaded or
 * active, Lite stays idle. Settings and data are shared, so nothing is lost either way.
 */
if (defined('STACKLESS_EDD_VERSION')
    || (in_array('stackless-edd/stackless-edd.php', (array) get_option('active_plugins', []), true) && file_exists(WP_PLUGIN_DIR . '/stackless-edd/stackless-edd.php'))
    || (is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', []))['stackless-edd/stackless-edd.php']))) {
    // Activating Lite next to the full plugin would do nothing, so refuse it with a clear message.
    register_activation_hook(__FILE__, static function () {
        wp_die(
            esc_html__('Stackless for EDD is active and already includes everything in Stackless Lite for EDD, so Lite was not activated. Your settings and data are shared between them.', 'stackless-lite-edd'),
            esc_html__('Stackless Lite for EDD', 'stackless-lite-edd'),
            ['back_link' => true]
        );
    });
    add_action('admin_notices', static function () {
        $lite = plugin_basename(__FILE__);
        $active = in_array($lite, (array) get_option('active_plugins', []), true)
            || (is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', []))[$lite]));
        if (!$active || !current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html__('Stackless Lite for EDD is paused because Stackless for EDD is active and includes all of its features. You can deactivate and delete Lite; your settings and data stay.', 'stackless-lite-edd')
        );
    });
    return;
}

define('STACKLESS_EDD_VERSION', '1.3.0');
define('STACKLESS_EDD_LITE', true);
define('STACKLESS_EDD_PLUGIN_FILE', __FILE__);
define('STACKLESS_EDD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('STACKLESS_EDD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STACKLESS_EDD_MIN_EDD', '3.5');

/** Map StacklessEDD\* onto includes/*.php. */
spl_autoload_register(function ($class) {
    $prefix = 'StacklessEDD\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }
    $path = STACKLESS_EDD_PLUGIN_PATH . 'includes/' . $relative . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

require_once STACKLESS_EDD_PLUGIN_PATH . 'includes/functions.php';

add_action('plugins_loaded', function () {
    // Updates keep working even while Easy Digital Downloads is missing.
    \StacklessEDD\Core\LiteUpdater::boot();
    if (!\StacklessEDD\Core\Requirements::met()) {
        add_action('admin_notices', [\StacklessEDD\Core\Requirements::class, 'notice']);
        return;
    }
    \StacklessEDD\Core\Plugin::instance()->boot();
    if (is_admin()) {
        \StacklessEDD\Core\LiteUpgrade::boot();
    }
}, 20);

register_activation_hook(__FILE__, [\StacklessEDD\Core\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\StacklessEDD\Core\Installer::class, 'deactivate']);
