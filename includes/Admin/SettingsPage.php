<?php

namespace StacklessEDD\Admin;

use StacklessEDD\Core\Module;
use StacklessEDD\Core\ModuleRegistry;

defined('ABSPATH') || exit;

/**
 * Top-level "Stackless" admin menu.
 *
 *  Stackless → Dashboard   module cards with instant on/off switches, search and filters
 *            → (module screens: Subscriptions, Invoices, Licenses… register under PARENT)
 *            → Settings    grouped sidebar of enabled modules, same fields as Stackless for WooCommerce
 *            → Emails      every Stackless email: on/off, subject, heading
 *            → License     plugin license + updates
 */
final class SettingsPage
{
    /** Parent slug for every Stackless admin screen. Modules: add_submenu_page(SettingsPage::PARENT, …). */
    public const PARENT = 'stackless-edd';
    public const SLUG = self::PARENT;
    public const SETTINGS = 'stackless-edd-settings';
    public const LICENSE = 'stackless-edd-license';
    public const EMAILS = 'stackless-edd-emails';
    public const CAP = 'manage_shop_settings';
    private const LICENSE_MODULE = '';

    /** @var ModuleRegistry */
    private $modules;

    /** @var string[] */
    private $hooks = [];

    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }

    /** Dashboard URL, or a module's settings URL when $module is given. */
    public static function url(string $module = '', array $args = []): string
    {
        if ($module === '') {
            $base = ['page' => self::PARENT];
        } elseif ($module === self::LICENSE_MODULE) {
            $base = ['page' => self::LICENSE];
        } else {
            $base = ['page' => self::SETTINGS, 'module' => $module];
        }
        return add_query_arg(array_merge($base, $args), admin_url('admin.php'));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menuTop'], 9);
        add_action('admin_menu', [$this, 'menuBottom'], 99);
        add_action('admin_init', [$this, 'legacyRedirect'], 1);
        add_action('admin_init', [$this, 'save']);
        add_action('admin_init', [$this, 'saveEmail']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_stackless_edd_toggle_module', [$this, 'ajaxToggle']);
        add_filter('plugin_action_links_' . plugin_basename(STACKLESS_EDD_PLUGIN_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Modules', 'stackless-lite-edd') . '</a>');
            return $links;
        });
        add_filter('plugin_row_meta', function ($meta, $file) {
            if ($file === plugin_basename(STACKLESS_EDD_PLUGIN_FILE)) {
                $meta[] = Documentation::link('dashboard');
            }
            return $meta;
        }, 10, 2);
    }

    public function menuTop(): void
    {
        $this->hooks[] = (string) add_menu_page(
            __('Stackless', 'stackless-lite-edd'),
            __('Stackless', 'stackless-lite-edd'),
            self::CAP,
            self::PARENT,
            [$this, 'renderDashboard'],
            Icons::menuDataUri(),
            26.7
        );
        $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless modules', 'stackless-lite-edd'), __('Dashboard', 'stackless-lite-edd'), self::CAP, self::PARENT, [$this, 'renderDashboard']);
    }

    public function menuBottom(): void
    {
        $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless settings', 'stackless-lite-edd'), __('Settings', 'stackless-lite-edd'), self::CAP, self::SETTINGS, [$this, 'renderSettings']);
        $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless emails', 'stackless-lite-edd'), __('Emails', 'stackless-lite-edd'), self::CAP, self::EMAILS, [$this, 'renderEmails']);
        if ($this->modules->get(self::LICENSE_MODULE)) {
            $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless license', 'stackless-lite-edd'), __('License', 'stackless-lite-edd'), self::CAP, self::LICENSE, [$this, 'renderLicense']);
        }
    }

    /** Old WooCommerce → Stackless URLs (?page=stackless&tab=id) keep working. */
    public function legacyRedirect(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_GET['page'], $_GET['tab']) || $_GET['page'] !== self::PARENT || !current_user_can(self::CAP)) {
            return;
        }
        $tab = sanitize_key(wp_unslash($_GET['tab']));
        $extra = array_diff_key(wp_unslash($_GET), ['page' => 1, 'tab' => 1]);
        // phpcs:enable
        wp_safe_redirect(self::url($tab === 'modules' ? '' : $tab, array_map('sanitize_text_field', array_filter($extra, 'is_scalar'))));
        exit;
    }

    public function assets(string $hook): void
    {
        if (!in_array($hook, $this->hooks, true)) {
            return;
        }
        wp_enqueue_style('stackless-edd-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/css/admin.css', [], STACKLESS_EDD_VERSION);
        wp_enqueue_script('stackless-edd-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/js/admin.js', [], STACKLESS_EDD_VERSION, true);
        wp_localize_script('stackless-edd-admin', 'stacklessEddAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stackless_edd_toggle_module'),
            'i18n' => [
                'on' => __('On', 'stackless-lite-edd'),
                'off' => __('Off', 'stackless-lite-edd'),
                'failed' => __('Could not save. Reload and try again.', 'stackless-lite-edd'),
                /* translators: 1: enabled count, 2: total */
                'count' => __('%1$d of %2$d on', 'stackless-lite-edd'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */

    public function ajaxToggle(): void
    {
        check_ajax_referer('stackless_edd_toggle_module', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('You are not allowed to change modules.', 'stackless-lite-edd')], 403);
        }
        $id = isset($_POST['module']) ? sanitize_key(wp_unslash($_POST['module'])) : '';
        $module = $this->modules->get($id);
        if (!$module) {
            wp_send_json_error(['message' => __('Unknown module.', 'stackless-lite-edd')], 400);
        }
        $on = !empty($_POST['enabled']) && $_POST['enabled'] !== 'false';
        // Change only this module's entry, re-read right before the write (concurrent toggles).
        $ids = $this->modules->setModuleEnabled($id, $on);
        update_option('stackless_edd_flush_rewrite', 1);
        // Run install() for modules switched on for the first time (tables, schedules).
        if ($on) {
            try {
                $module->install();
            } catch (\Throwable $e) {
                \StacklessEDD\Core\Logger::log($id, 'Install failed: ' . $e->getMessage(), 'error');
            }
        }
        wp_send_json_success([
            'enabled' => $on,
            'settingsUrl' => ($on && $module->settingsFields()) ? self::url($id) : '',
            'count' => count($ids),
            'total' => count($this->modules->all()),
        ]);
    }

    public function save(): void
    {
        if (empty($_POST['stackless_edd_settings_nonce']) || !current_user_can(self::CAP)) {
            return;
        }
        check_admin_referer('stackless_edd_settings', 'stackless_edd_settings_nonce');
        $id = isset($_POST['stackless_edd_module']) ? sanitize_key(wp_unslash($_POST['stackless_edd_module'])) : '';
        $module = $this->modules->get($id);
        if (!$module || !$module->settingsFields()) {
            return;
        }
        Fields::save($module->settingsFields());
        do_action('stackless_edd_settings_saved_' . $module->id());
        // After the saved hook, so errors a module reports while validating the new values (gateway keys) show too.
        Fields::persistErrors();
        wp_safe_redirect(self::url($id, ['updated' => '1']));
        exit;
    }

    /* ------------------------------------------------------------------ */

    private function groups(): array
    {
        return [
            'subscriptions' => __('Subscriptions', 'stackless-lite-edd'),
            'payments' => __('Payments', 'stackless-lite-edd'),
            'checkout' => __('Checkout', 'stackless-lite-edd'),
            'sales' => __('Sales & invoices', 'stackless-lite-edd'),
            'currency' => __('Currency', 'stackless-lite-edd'),
            'access' => __('Access & licensing', 'stackless-lite-edd'),
            'catalog' => __('Catalog', 'stackless-lite-edd'),
            'store' => __('Store tools', 'stackless-lite-edd'),
            'admin' => __('Admin', 'stackless-lite-edd'),
        ];
    }

    /** @return array<string, Module[]> group => modules, in group order */
    private function byGroup(?callable $filter = null): array
    {
        $out = array_fill_keys(array_keys($this->groups()), []);
        foreach ($this->modules->all() as $id => $module) {
            if ($filter && !$filter($module)) {
                continue;
            }
            $out[$module->group()][$id] = $module;
        }
        foreach ($out as $group => $mods) {
            uasort($mods, function (Module $a, Module $b) {
                return strcasecmp($a->title(), $b->title());
            });
            $out[$group] = $mods;
        }
        return array_filter($out);
    }

    private function header(string $title, string $subtitle = ''): void
    {
        echo '<div class="stackless-header">';
        echo '<span class="stackless-header__logo">' . Icons::svg('stackless', 26) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        echo '<div><h1 class="stackless-header__title">' . esc_html($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="stackless-header__sub">' . esc_html($subtitle) . '</p>';
        }
        echo '</div>';
        echo '<nav class="stackless-header__nav">';
        $current = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $links = [
            self::PARENT => ['layout-dashboard', __('Dashboard', 'stackless-lite-edd'), self::url()],
            self::SETTINGS => ['settings', __('Settings', 'stackless-lite-edd'), admin_url('admin.php?page=' . self::SETTINGS)],
            self::EMAILS => ['mail', __('Emails', 'stackless-lite-edd'), admin_url('admin.php?page=' . self::EMAILS)],
        ];
        if ($this->modules->get(self::LICENSE_MODULE)) {
            $links[self::LICENSE] = ['license', __('License', 'stackless-lite-edd'), admin_url('admin.php?page=' . self::LICENSE)];
        }
        /**
         * Filters the header navigation. Each link: [icon, label, url, opens in a new tab (bool, optional),
         * extra CSS class (optional)], keyed by the admin page slug it marks as current.
         *
         * @param array<string,array> $links
         * @param string              $current Current admin page slug.
         */
        $links = (array) apply_filters('stackless_edd_admin_header_links', $links, $current);
        foreach ($links as $page => $link) {
            $external = !empty($link[3]);
            printf(
                '<a href="%s" class="stackless-header__link%s%s"%s%s>%s<span>%s</span>%s</a>',
                esc_url((string) $link[2]),
                $current === $page ? ' is-current' : '',
                !empty($link[4]) ? ' ' . esc_attr((string) $link[4]) : '',
                $current === $page ? ' aria-current="page"' : '',
                $external ? ' target="_blank" rel="noopener noreferrer"' : '',
                Icons::svg((string) $link[0], 18), // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html((string) $link[1]),
                $external ? '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'stackless-lite-edd') . '</span>' : ''
            );
        }
        $docsKey = $current === self::LICENSE ? self::LICENSE_MODULE : ($current === self::PARENT ? 'dashboard' : '');
        printf(
            '<a href="%s" class="stackless-header__link" target="_blank" rel="noopener noreferrer">%s<span>%s</span><span class="screen-reader-text"> %s</span></a>',
            esc_url(Documentation::url($docsKey)),
            Icons::svg('book', 18), // phpcs:ignore WordPress.Security.EscapeOutput
            esc_html__('Docs', 'stackless-lite-edd'),
            esc_html__('(opens in a new tab)', 'stackless-lite-edd')
        );
        echo '</nav></div>';
    }

    private function notices(): void
    {
        Fields::printErrors();
        if (!empty($_GET['updated'])) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible stackless-notice"><p>' . esc_html__('Settings saved.', 'stackless-lite-edd') . '</p></div>';
        }
    }

    private function maybeFlush(): void
    {
        if (get_option('stackless_edd_flush_rewrite')) {
            delete_option('stackless_edd_flush_rewrite');
            flush_rewrite_rules(false);
        }
    }

    /* ---------------------------- Dashboard --------------------------- */

    public function renderDashboard(): void
    {
        $this->maybeFlush();
        $all = $this->modules->all();
        $enabled = count($this->modules->enabledIds());
        echo '<div class="wrap stackless-admin stackless-dashboard">';
        $this->header(__('Stackless', 'stackless-lite-edd'), __('You don\'t need any other add-ons for Easy Digital Downloads. Switch on only what you use; switched-off modules load no code.', 'stackless-lite-edd'));
        echo '<hr class="wp-header-end">';
        $this->notices();

        echo '<div class="stackless-toolbar">';
        echo '<label class="stackless-search">' . Icons::svg('search', 18) . '<span class="screen-reader-text">' . esc_html__('Search modules', 'stackless-lite-edd') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<input type="search" id="stackless-module-search" placeholder="' . esc_attr__('Search modules', 'stackless-lite-edd') . '"></label>';
        echo '<div class="stackless-filters" role="group" aria-label="' . esc_attr__('Filter modules', 'stackless-lite-edd') . '">';
        /** Filters the dashboard filter buttons: card state (data-state) => label. */
        $filters = (array) apply_filters('stackless_edd_dashboard_filters', ['all' => __('All', 'stackless-lite-edd'), 'on' => __('On', 'stackless-lite-edd'), 'off' => __('Off', 'stackless-lite-edd')]);
        foreach ($filters as $key => $label) {
            printf('<button type="button" class="stackless-filter%s" data-filter="%s" aria-pressed="%s">%s</button>', $key === 'all' ? ' is-active' : '', esc_attr($key), $key === 'all' ? 'true' : 'false', esc_html($label));
        }
        echo '</div>';
        /* translators: 1: enabled count, 2: total */
        echo '<span class="stackless-count" id="stackless-module-count">' . esc_html(sprintf(__('%1$d of %2$d on', 'stackless-lite-edd'), $enabled, count($all))) . '</span>';
        echo '</div>';

        foreach ($this->byGroup() as $group => $mods) {
            $label = $this->groups()[$group] ?? ucfirst($group);
            echo '<section class="stackless-group" data-group="' . esc_attr($group) . '">';
            echo '<h2 class="stackless-group__title">' . Icons::svg(Icons::GROUPS[$group] ?? 'sparkles', 18) . esc_html($label) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<div class="stackless-grid">';
            foreach ($mods as $id => $module) {
                $this->card($id, $module);
            }
            echo '</div></section>';
        }
        /**
         * Prints extra dashboard sections after the module groups. Sections use .stackless-group and cards
         * .stackless-card with data-state and data-search, so the search box and filters cover them.
         *
         * @param array<string,string> $groups group id => label
         */
        do_action('stackless_edd_dashboard_after_groups', $this->groups());
        echo '<p class="stackless-empty" hidden>' . esc_html__('No modules match your search.', 'stackless-lite-edd') . '</p>';
        echo '</div>';
    }

    private function card(string $id, Module $module): void
    {
        $on = $this->modules->isEnabled($id);
        $blocked = $on ? $this->modules->blockedReason($id) : null;
        $settings = $on && $module->settingsFields() ? self::url($id) : '';
        $switchId = 'stackless-switch-' . $id;
        printf(
            '<article class="stackless-card%s" data-module="%s" data-state="%s" data-search="%s">',
            $on ? ' is-on' : '',
            esc_attr($id),
            $on ? 'on' : 'off',
            esc_attr(strtolower($module->title() . ' ' . $module->description() . ' ' . $module->group()))
        );
        echo '<div class="stackless-card__head">';
        echo '<span class="stackless-card__icon">' . Icons::svg(Icons::forModule($id, $module->group()), 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<h3 class="stackless-card__title"><label for="' . esc_attr($switchId) . '">' . esc_html($module->title()) . '</label></h3>';
        printf(
            '<button type="button" role="switch" id="%s" class="stackless-switch" aria-checked="%s" data-module="%s"><span class="stackless-switch__track" aria-hidden="true"><span class="stackless-switch__thumb"></span></span><span class="screen-reader-text">%s</span></button>',
            esc_attr($switchId),
            $on ? 'true' : 'false',
            esc_attr($id),
            /* translators: %s: module name */
            esc_html(sprintf(__('Enable %s', 'stackless-lite-edd'), $module->title()))
        );
        echo '</div>';
        echo '<p class="stackless-card__desc">' . esc_html($module->description()) . '</p>';
        echo '<div class="stackless-card__foot">';
        if ($blocked) {
            echo '<span class="stackless-card__blocked">' . Icons::svg('info-circle', 16) . esc_html($blocked) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo Documentation::link($id, __('Docs', 'stackless-lite-edd'), 'stackless-card__docs'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        printf(
            '<a class="stackless-card__link"%s href="%s">%s%s</a>',
            $settings === '' ? ' hidden' : '',
            esc_url($settings ?: self::url($id)),
            esc_html__('Settings', 'stackless-lite-edd'),
            Icons::svg('chevron-right', 16) // phpcs:ignore WordPress.Security.EscapeOutput
        );
        echo '</div></article>';
    }

    /* ----------------------------- Settings --------------------------- */

    public function renderSettings(): void
    {
        $this->maybeFlush();
        $configurable = $this->byGroup(function (Module $m) {
            return $m->id() !== self::LICENSE_MODULE && $this->modules->isEnabled($m->id()) && $m->settingsFields();
        });
        $requested = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $current = null;
        foreach ($configurable as $mods) {
            if ($requested !== '' && isset($mods[$requested])) {
                $current = $mods[$requested];
                break;
            }
            if ($requested === '' && !$current) {
                $current = reset($mods) ?: null;
            }
        }

        /**
         * Filters the main panel of the Settings screen. Markup returned here (escaped by the callback)
         * replaces the module form, and no module is marked current in the sidebar.
         */
        $panel = (string) apply_filters('stackless_edd_settings_panel', '');
        // Extra sidebar groups (stackless_edd_settings_sidebar prints them after the module groups).
        ob_start();
        do_action('stackless_edd_settings_sidebar');
        $sidebarExtra = (string) ob_get_clean();
        if ($panel !== '') {
            $current = null;
        }

        echo '<div class="wrap stackless-admin stackless-settings">';
        $this->header(__('Settings', 'stackless-lite-edd'), __('Configure the modules you have switched on.', 'stackless-lite-edd'));
        echo '<hr class="wp-header-end">';
        $this->notices();

        if (!$configurable && $panel === '' && $sidebarExtra === '') {
            echo '<div class="stackless-panel stackless-panel--empty"><p>' . esc_html__('Switch on a module from the Dashboard to configure it here.', 'stackless-lite-edd') . '</p>';
            echo '<a class="button button-primary" href="' . esc_url(self::url()) . '">' . esc_html__('Open Dashboard', 'stackless-lite-edd') . '</a></div></div>';
            return;
        }

        echo '<div class="stackless-layout">';
        echo '<nav class="stackless-sidebar" aria-label="' . esc_attr__('Modules', 'stackless-lite-edd') . '">';
        foreach ($configurable as $group => $mods) {
            echo '<p class="stackless-sidebar__group">' . esc_html($this->groups()[$group] ?? ucfirst($group)) . '</p><ul>';
            foreach ($mods as $id => $module) {
                $active = $current && $current->id() === $id;
                printf(
                    '<li><a href="%s" class="stackless-sidebar__link%s"%s>%s<span>%s</span></a></li>',
                    esc_url(self::url($id)),
                    $active ? ' is-current' : '',
                    $active ? ' aria-current="page"' : '',
                    Icons::svg(Icons::forModule($id, $module->group()), 18), // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html($module->title())
                );
            }
            echo '</ul>';
        }
        echo $sidebarExtra; // phpcs:ignore WordPress.Security.EscapeOutput -- printed and escaped by the hooked callbacks.
        echo '</nav>';

        echo '<div class="stackless-main">';
        if ($panel !== '') {
            echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the filter callback.
        } elseif (!$configurable) {
            echo '<div class="stackless-panel stackless-panel--empty"><p>' . esc_html__('Switch on a module from the Dashboard to configure it here.', 'stackless-lite-edd') . '</p></div>';
        } elseif (!$current) {
            echo '<div class="stackless-panel"><p>' . esc_html__('That module is switched off or has no settings.', 'stackless-lite-edd') . '</p></div>';
        } else {
            $this->moduleForm($current);
        }
        echo '</div></div></div>';
    }

    private function moduleForm(Module $module): void
    {
        echo '<div class="stackless-panel">';
        echo '<div class="stackless-panel__head"><span class="stackless-card__icon">' . Icons::svg(Icons::forModule($module->id(), $module->group()), 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<div><h2>' . esc_html($module->title()) . '</h2><p>' . esc_html($module->description()) . '</p></div>';
        echo '<p class="stackless-panel__docs">' . Icons::svg('book', 16) . Documentation::link($module->id(), sprintf(/* translators: %s: module name */ __('How to set up %s', 'stackless-lite-edd'), $module->title())) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG; link escaped in Documentation::link().
        echo '<form method="post" action="" class="stackless-form">';
        wp_nonce_field('stackless_edd_settings', 'stackless_edd_settings_nonce');
        echo '<input type="hidden" name="stackless_edd_module" value="' . esc_attr($module->id()) . '">';
        Fields::output($module->settingsFields());
        do_action('stackless_edd_settings_after_' . $module->id());
        echo '<div class="stackless-form__actions">';
        submit_button(__('Save changes', 'stackless-lite-edd'), 'primary', 'submit', false);
        echo '</div></form></div>';
    }

    /* ------------------------------ License --------------------------- */

    public function renderLicense(): void
    {
        $module = $this->modules->get(self::LICENSE_MODULE);
        echo '<div class="wrap stackless-admin stackless-license">';
        $this->header(__('License', 'stackless-lite-edd'), __('Activate your license for automatic updates and support. Every feature works without it.', 'stackless-lite-edd'));
        echo '<hr class="wp-header-end">';
        $this->notices();
        echo '<div class="stackless-narrow">';
        if ($module && $this->modules->isEnabled(self::LICENSE_MODULE) && $module->settingsFields()) {
            $this->moduleForm($module);
        } else {
            echo '<div class="stackless-panel"><p>' . esc_html__('Turn on the License & Updates module on the Dashboard to manage your license.', 'stackless-lite-edd') . '</p></div>';
        }
        echo '</div></div>';
    }

    /* ------------------------------ Emails ---------------------------- */

    /** @return array<int,array<string,mixed>> settings fields for one email */
    private function emailFields(\StacklessEDD\Core\Email $email): array
    {
        $option = $email->optionName();
        $defaults = $email->defaults();
        $fields = [
            ['type' => 'title', 'title' => $email->title(), 'desc' => $email->description()],
            ['id' => $option . '[enabled]', 'type' => 'checkbox', 'title' => __('Enable', 'stackless-lite-edd'), 'desc' => __('Send this email', 'stackless-lite-edd'), 'default' => $email->enabledByDefault() ? 'yes' : 'no'],
        ];
        if (!$email->isCustomerEmail()) {
            $fields[] = ['id' => $option . '[recipient]', 'type' => 'text', 'title' => __('Recipients', 'stackless-lite-edd'), 'desc' => __('Comma-separated. Empty uses the admin notification emails from Downloads → Settings → Emails.', 'stackless-lite-edd'), 'placeholder' => $email->adminRecipients()];
        }
        /* translators: %s: list of placeholders */
        $help = sprintf(__('Leave empty for the default. Placeholders: %s', 'stackless-lite-edd'), implode(' ', $email->placeholders()));
        $fields[] = ['id' => $option . '[subject]', 'type' => 'text', 'title' => __('Subject', 'stackless-lite-edd'), 'placeholder' => $defaults['subject'], 'desc' => $help];
        $fields[] = ['id' => $option . '[heading]', 'type' => 'text', 'title' => __('Heading', 'stackless-lite-edd'), 'placeholder' => $defaults['heading'], 'desc' => $help];
        $fields[] = ['type' => 'sectionend'];
        return $fields;
    }

    public function saveEmail(): void
    {
        if (empty($_POST['stackless_edd_email_nonce']) || !current_user_can(self::CAP)) {
            return;
        }
        check_admin_referer('stackless_edd_email', 'stackless_edd_email_nonce');
        $id = isset($_POST['stackless_edd_email']) ? sanitize_key(wp_unslash($_POST['stackless_edd_email'])) : '';
        $email = \StacklessEDD\Core\Emails::get($id);
        if (!$email) {
            return;
        }
        Fields::save($this->emailFields($email));
        wp_safe_redirect(add_query_arg(['page' => self::EMAILS, 'email' => $id, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function renderEmails(): void
    {
        $emails = \StacklessEDD\Core\Emails::all();
        $requested = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="wrap stackless-admin stackless-emails">';
        $this->header(__('Emails', 'stackless-lite-edd'), __('Every email the modules you switched on can send. They use your EDD email template.', 'stackless-lite-edd'));
        echo '<hr class="wp-header-end">';
        $this->notices();

        if ($requested !== '' && isset($emails[$requested])) {
            $email = $emails[$requested];
            echo '<div class="stackless-narrow"><p><a href="' . esc_url(admin_url('admin.php?page=' . self::EMAILS)) . '">&larr; ' . esc_html__('All emails', 'stackless-lite-edd') . '</a></p>';
            echo '<div class="stackless-panel"><form method="post" action="" class="stackless-form">';
            wp_nonce_field('stackless_edd_email', 'stackless_edd_email_nonce');
            echo '<input type="hidden" name="stackless_edd_email" value="' . esc_attr($email->id()) . '">';
            Fields::output($this->emailFields($email));
            echo '<div class="stackless-form__actions">';
            submit_button(__('Save changes', 'stackless-lite-edd'), 'primary', 'submit', false);
            echo '</div></form></div></div></div>';
            return;
        }

        if (!$emails) {
            echo '<div class="stackless-panel stackless-panel--empty"><p>' . esc_html__('Switch on a module that sends email (Subscriptions, Software Licensing, Invoices, Quotes) to manage its emails here.', 'stackless-lite-edd') . '</p></div></div>';
            return;
        }
        echo '<div class="stackless-panel"><table class="widefat striped stackless-table"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Email', 'stackless-lite-edd') . '</th><th scope="col">' . esc_html__('Module', 'stackless-lite-edd') . '</th><th scope="col">' . esc_html__('Recipient', 'stackless-lite-edd') . '</th><th scope="col">' . esc_html__('Status', 'stackless-lite-edd') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'stackless-lite-edd') . '</span></th>';
        echo '</tr></thead><tbody>';
        foreach ($emails as $id => $email) {
            $module = $this->modules->get($email->module());
            $url = add_query_arg(['page' => self::EMAILS, 'email' => $id], admin_url('admin.php'));
            echo '<tr>';
            echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($email->title()) . '</strong></a><br><span class="description">' . esc_html($email->description()) . '</span></td>';
            echo '<td>' . esc_html($module ? $module->title() : $email->module()) . '</td>';
            echo '<td>' . esc_html($email->isCustomerEmail() ? __('Customer', 'stackless-lite-edd') : $email->adminRecipients()) . '</td>';
            echo '<td>' . ($email->isEnabled() ? '<span class="stackless-badge stackless-badge--on">' . esc_html__('On', 'stackless-lite-edd') . '</span>' : '<span class="stackless-badge">' . esc_html__('Off', 'stackless-lite-edd') . '</span>') . '</td>';
            echo '<td><a class="button" href="' . esc_url($url) . '">' . esc_html__('Manage', 'stackless-lite-edd') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
