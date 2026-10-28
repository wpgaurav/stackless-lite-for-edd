<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Customer account page (the WooCommerce My Account counterpart): [stackless_edd_account] renders
 * a tabbed area with EDD's own order history, downloads and profile plus the tabs modules add
 * (Subscriptions, Licenses, Invoices, My pages, Wishlist…).
 *
 * Modules add tabs through the stackless_edd_account_tabs filter:
 *   $tabs['subscriptions'] = ['title' => 'Subscriptions', 'icon' => 'repeat', 'priority' => 20,
 *                             'callback' => function (array $query) { … print the tab … }];
 * $query holds the tab's query args (for example ['id' => 42] for a detail view).
 * Tab URLs come from stackless_edd_account_url($tab, $args).
 */
final class Account
{
    public const PAGE_OPTION = 'stackless_edd_account_page_id';
    public const SHORTCODE = 'stackless_edd_account';
    public const QUERY_VAR = 'account-tab';

    public static function boot(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_filter('display_post_states', [self::class, 'postState'], 10, 2);
    }

    /** Create the account page when none is set. Runs from Installer on activation and upgrades. */
    public static function install(): void
    {
        $id = (int) get_option(self::PAGE_OPTION);
        if ($id > 0 && get_post_status($id) && get_post_status($id) !== 'trash') {
            return;
        }
        $page = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Account', 'stackless-lite-edd'),
            'post_name' => 'account',
            'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
            'comment_status' => 'closed',
        ]);
        if (!is_wp_error($page) && $page) {
            update_option(self::PAGE_OPTION, (int) $page);
        }
    }

    public static function pageId(): int
    {
        return (int) apply_filters('stackless_edd_account_page_id', (int) get_option(self::PAGE_OPTION));
    }

    public static function url(string $tab = '', array $args = []): string
    {
        $id = self::pageId();
        $base = $id > 0 ? (string) get_permalink($id) : home_url('/');
        if ($tab !== '') {
            $args = [self::QUERY_VAR => sanitize_key($tab)] + $args;
        }
        return $args ? add_query_arg(array_map('rawurlencode', array_map('strval', $args)), $base) : $base;
    }

    /** @return array<string,array{title:string,icon:string,priority:int,callback:callable}> */
    public static function tabs(): array
    {
        $tabs = [
            'orders' => [
                'title' => __('Orders', 'stackless-lite-edd'),
                'icon' => 'receipt',
                'priority' => 10,
                'callback' => static function (): void {
                    echo do_shortcode('[purchase_history]'); // phpcs:ignore WordPress.Security.EscapeOutput -- EDD markup.
                },
            ],
            'downloads' => [
                'title' => __('Downloads', 'stackless-lite-edd'),
                'icon' => 'file-download',
                'priority' => 15,
                'callback' => static function (): void {
                    echo do_shortcode('[download_history]'); // phpcs:ignore WordPress.Security.EscapeOutput -- EDD markup.
                },
            ],
            'profile' => [
                'title' => __('Profile', 'stackless-lite-edd'),
                'icon' => 'user-circle',
                'priority' => 90,
                'callback' => static function (): void {
                    echo do_shortcode('[edd_profile_editor]'); // phpcs:ignore WordPress.Security.EscapeOutput -- EDD markup.
                },
            ],
        ];
        $tabs = (array) apply_filters('stackless_edd_account_tabs', $tabs);
        $clean = [];
        foreach ($tabs as $id => $tab) {
            if (!is_array($tab) || empty($tab['callback']) || !is_callable($tab['callback'])) {
                continue;
            }
            $clean[sanitize_key((string) $id)] = [
                'title' => (string) ($tab['title'] ?? $id),
                'icon' => (string) ($tab['icon'] ?? 'sparkles'),
                'priority' => (int) ($tab['priority'] ?? 50),
                'callback' => $tab['callback'],
            ];
        }
        uasort($clean, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'];
        });
        return $clean;
    }

    public static function current(): string
    {
        $tabs = self::tabs();
        $requested = isset($_GET[self::QUERY_VAR]) ? sanitize_key(wp_unslash($_GET[self::QUERY_VAR])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($requested !== '' && isset($tabs[$requested])) {
            return $requested;
        }
        return (string) (array_key_first($tabs) ?? '');
    }

    /** @param mixed $atts */
    public static function shortcode($atts = []): string
    {
        ob_start();
        echo Notices::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
        if (!is_user_logged_in()) {
            echo '<div class="stackless-account stackless-account--guest">';
            echo do_shortcode('[edd_login redirect="' . esc_url(self::url()) . '"]'); // phpcs:ignore WordPress.Security.EscapeOutput -- EDD markup.
            echo '</div>';
            return (string) ob_get_clean();
        }
        stackless_edd_get_template('account/account.php', [
            'tabs' => self::tabs(),
            'current' => self::current(),
            'query' => array_map('sanitize_text_field', wp_unslash(array_diff_key($_GET, [self::QUERY_VAR => 1]))), // phpcs:ignore WordPress.Security.NonceVerification
        ]);
        return (string) ob_get_clean();
    }

    public static function assets(): void
    {
        if (!is_singular() || (int) get_queried_object_id() !== self::pageId()) {
            return;
        }
        wp_enqueue_style('stackless-edd-account', STACKLESS_EDD_PLUGIN_URL . 'assets/css/account.css', [], STACKLESS_EDD_VERSION);
    }

    /**
     * @param string[] $states
     * @param \WP_Post $post
     * @return string[]
     */
    public static function postState($states, $post): array
    {
        $states = is_array($states) ? $states : [];
        if ($post instanceof \WP_Post && (int) $post->ID === self::pageId()) {
            $states['stackless_edd_account'] = __('Stackless Account Page', 'stackless-lite-edd');
        }
        return $states;
    }
}
