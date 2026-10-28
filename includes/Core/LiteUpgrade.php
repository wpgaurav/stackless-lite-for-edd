<?php

namespace StacklessEDD\Core;

use StacklessEDD\Admin\Icons;
use StacklessEDD\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Stackless Lite for EDD only: shows what Stackless for EDD adds, without any of its code.
 *
 * - Dashboard: after the Lite modules, a locked card per Pro module (title, description, group, Pro badge,
 *   Details, Upgrade) grouped like the modules, and a Pro filter.
 * - Settings: a "Pro" sidebar group and a read-only details view (what it does, key features, Upgrade button).
 * - Header: an Upgrade link. Plugins screen: an Upgrade action link.
 *
 * Everything comes from the static file lite-pro-modules.php. No notices, no tracking, no remote calls.
 */
final class LiteUpgrade
{
    public const URL = 'https://gauravtiwari.org/product/stackless-for-edd/';
    private const QUERY = 'feature';

    /** @var array<string,array>|null */
    private static $modules;

    public static function boot(): void
    {
        add_filter('stackless_edd_admin_header_links', [self::class, 'headerLinks']);
        add_filter('stackless_edd_dashboard_filters', [self::class, 'filters']);
        add_action('stackless_edd_dashboard_after_groups', [self::class, 'section']);
        add_action('stackless_edd_settings_sidebar', [self::class, 'sidebar']);
        add_filter('stackless_edd_settings_panel', [self::class, 'panel']);
        add_action('admin_enqueue_scripts', [self::class, 'assets'], 20);
        add_filter('plugin_action_links_' . plugin_basename(STACKLESS_EDD_PLUGIN_FILE), [self::class, 'actionLinks']);
    }

    /** @return array<string,array{title:string,description:string,group:string,icon:string,features:string[]}> */
    public static function modules(): array
    {
        if (self::$modules === null) {
            $data = include __DIR__ . '/lite-pro-modules.php';
            self::$modules = is_array($data) ? $data : [];
        }
        return self::$modules;
    }

    public static function detailsUrl(string $slug): string
    {
        return add_query_arg(['page' => SettingsPage::SETTINGS, self::QUERY => $slug], admin_url('admin.php'));
    }

    /** The requested Pro module slug on the Settings screen, or ''. */
    public static function requested(): string
    {
        $slug = isset($_GET[self::QUERY]) ? sanitize_key(wp_unslash($_GET[self::QUERY])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return isset(self::modules()[$slug]) ? $slug : '';
    }

    /** @param array<string,array> $links */
    public static function headerLinks($links): array
    {
        $links = is_array($links) ? $links : [];
        $links['stackless-edd-upgrade'] = ['sparkles', __('Upgrade', 'stackless-lite-edd'), self::URL, true, 'stackless-header__link--upgrade'];
        return $links;
    }

    /** @param array<string,string> $filters */
    public static function filters($filters): array
    {
        $filters = is_array($filters) ? $filters : [];
        $filters['pro'] = __('Pro', 'stackless-lite-edd');
        return $filters;
    }

    /**
     * The Pro modules after the Lite ones, one section per dashboard group.
     *
     * @param array<string,string> $groups group id => label
     */
    public static function section($groups = []): void
    {
        $groups = is_array($groups) ? $groups : [];
        $byGroup = [];
        foreach (self::sorted() as $slug => $module) {
            $byGroup[$module['group']][$slug] = $module;
        }
        echo '<div class="stackless-pro-intro" data-pro-intro><h2>' . esc_html__('More in Stackless for EDD', 'stackless-lite-edd') . '</h2>';
        echo '<p>' . esc_html__('These modules come with Stackless for EDD. It replaces Lite and keeps your Lite settings and data.', 'stackless-lite-edd') . ' ';
        printf('<a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p></div>', esc_url(self::URL), esc_html__('See plans', 'stackless-lite-edd'), esc_html__('(opens in a new tab)', 'stackless-lite-edd'));
        foreach (array_keys($groups + $byGroup) as $group) {
            if (empty($byGroup[$group])) {
                continue;
            }
            $label = (string) ($groups[$group] ?? ucfirst((string) $group));
            echo '<section class="stackless-group stackless-group--pro" data-group="' . esc_attr('pro-' . $group) . '">';
            echo '<h2 class="stackless-group__title">' . Icons::svg(Icons::GROUPS[$group] ?? 'sparkles', 18) . esc_html($label) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
            echo '<div class="stackless-grid">';
            foreach ($byGroup[$group] as $slug => $module) {
                self::card($slug, $module, $label);
            }
            echo '</div></section>';
        }
    }

    /** One locked card: no switch, no settings. */
    private static function card(string $slug, array $module, string $groupLabel): void
    {
        printf(
            '<article class="stackless-card stackless-card--locked" data-module="%s" data-state="pro" data-search="%s">',
            esc_attr($slug),
            esc_attr(strtolower($module['title'] . ' ' . $module['description'] . ' ' . $groupLabel . ' pro'))
        );
        echo '<div class="stackless-card__head">';
        echo '<span class="stackless-card__icon">' . Icons::svg($module['icon'], 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        echo '<h3 class="stackless-card__title">' . esc_html($module['title']) . '</h3>';
        echo '<span class="stackless-badge stackless-badge--pro">' . esc_html__('Pro', 'stackless-lite-edd') . '</span>';
        echo '</div>';
        echo '<p class="stackless-card__desc">' . esc_html($module['description']) . '</p>';
        echo '<div class="stackless-card__foot">';
        printf(
            '<a class="stackless-card__docs" href="%s">%s<span class="screen-reader-text"> %s</span></a>',
            esc_url(self::detailsUrl($slug)),
            esc_html__('Details', 'stackless-lite-edd'),
            esc_html($module['title'])
        );
        printf(
            '<a class="stackless-card__link" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s %s</span>%s</a>',
            esc_url(self::URL),
            esc_html__('Upgrade', 'stackless-lite-edd'),
            esc_html($module['title']),
            esc_html__('(opens in a new tab)', 'stackless-lite-edd'),
            Icons::svg('chevron-right', 16) // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        );
        echo '</div></article>';
    }

    /** The "Pro" group in the Settings sidebar. */
    public static function sidebar(): void
    {
        $current = self::requested();
        echo '<p class="stackless-sidebar__group">' . esc_html__('Pro', 'stackless-lite-edd') . '</p><ul>';
        foreach (self::sorted() as $slug => $module) {
            $active = $slug === $current;
            printf(
                '<li><a href="%s" class="stackless-sidebar__link stackless-sidebar__link--pro%s"%s>%s<span>%s</span></a></li>',
                esc_url(self::detailsUrl($slug)),
                $active ? ' is-current' : '',
                $active ? ' aria-current="page"' : '',
                Icons::svg($module['icon'], 18), // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
                esc_html($module['title'])
            );
        }
        echo '</ul>';
    }

    /** Read-only details of a Pro module on the Settings screen. */
    public static function panel($html): string
    {
        $slug = self::requested();
        if ($slug === '') {
            return is_string($html) ? $html : '';
        }
        $module = self::modules()[$slug];
        $out = '<div class="stackless-panel stackless-pro-details">';
        $out .= '<div class="stackless-panel__head"><span class="stackless-card__icon">' . Icons::svg($module['icon'], 22) . '</span>';
        $out .= '<div><h2>' . esc_html($module['title']) . ' <span class="stackless-badge stackless-badge--pro">' . esc_html__('Pro', 'stackless-lite-edd') . '</span></h2>';
        $out .= '<p>' . esc_html($module['description']) . '</p></div></div>';
        $out .= '<h3 class="stackless-pro-details__title">' . esc_html__('What it does', 'stackless-lite-edd') . '</h3><ul class="stackless-pro-details__features">';
        foreach ($module['features'] as $feature) {
            $out .= '<li>' . Icons::svg('check', 16) . '<span>' . esc_html($feature) . '</span></li>';
        }
        $out .= '</ul>';
        $out .= '<p class="stackless-pro-details__note">' . esc_html__('Part of Stackless for EDD. When you upgrade, it replaces Lite and keeps your Lite settings and data.', 'stackless-lite-edd') . '</p>';
        $out .= sprintf(
            '<p class="stackless-pro-details__actions"><a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></p>',
            esc_url(self::URL),
            esc_html__('Upgrade to Stackless for EDD', 'stackless-lite-edd'),
            esc_html__('(opens in a new tab)', 'stackless-lite-edd')
        );
        return $out . '</div>';
    }

    public static function assets(): void
    {
        if (wp_style_is('stackless-edd-admin', 'enqueued')) {
            wp_enqueue_style('stackless-edd-lite', STACKLESS_EDD_PLUGIN_URL . 'assets/css/lite.css', ['stackless-edd-admin'], STACKLESS_EDD_VERSION);
            // Hide the "More in Stackless for EDD" heading when search or filters leave none of its cards.
            wp_add_inline_script('stackless-edd-admin', "(function(){var r=document.querySelector('.stackless-dashboard'),i=r&&r.querySelector('[data-pro-intro]');if(!i){return;}function s(){i.hidden=!r.querySelector('.stackless-group--pro:not([hidden])');}r.addEventListener('click',function(){setTimeout(s,0);});r.addEventListener('input',function(){setTimeout(s,0);});})();");
        }
    }

    /** @param string[] $links */
    public static function actionLinks($links): array
    {
        $links = is_array($links) ? $links : [];
        $links[] = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url(self::URL), esc_html__('Upgrade', 'stackless-lite-edd'));
        return $links;
    }

    /** @return array<string,array> Modules sorted by title. */
    private static function sorted(): array
    {
        $modules = self::modules();
        uasort($modules, static function (array $a, array $b): int {
            return strcasecmp($a['title'], $b['title']);
        });
        return $modules;
    }
}
