<?php

namespace StacklessEDD\Modules\EddOptimizer;

defined('ABSPATH') || exit;

/**
 * EDD Optimizer: loads EDD's scripts and styles only where EDD renders something (purchase
 * buttons, cart, checkout, account, receipts and EDD blocks), defers what can wait, trims EDD's
 * admin extras and keeps Action Scheduler's logs lean.
 */
final class Module extends \StacklessEDD\Core\Module
{
    /** @var bool Set once an EDD or Stackless block renders in this request. */
    private $renderedStoreBlock = false;

    /** @var bool|null */
    private $storePage;

    /** @var bool EDD's assets were taken off this page. */
    private $trimmed = false;

    public function id(): string
    {
        return 'edd_optimizer';
    }

    public function title(): string
    {
        return __('EDD Optimizer', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Load EDD scripts and styles only where they are used, defer store scripts, quiet EDD\'s admin extras and keep scheduled action logs lean.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'store';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_edd_optimizer_';
        return [
            ['type' => 'title', 'id' => $p . 'frontend', 'title' => __('Storefront', 'stackless-lite-edd'), 'desc' => __('Stackless already serves its own files minified and deferred. These settings tune what EDD itself loads. Clear your page cache after changing them.', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'trim_assets', 'title' => __('Store assets', 'stackless-lite-edd'), 'default' => 'no', 'desc' => __('Load EDD scripts and styles only on store pages', 'stackless-lite-edd'), 'desc_tip' => __('Store pages are downloads, download categories and tags, checkout, the receipt and failed-payment pages, purchase history, the account page, and any page with an EDD or Stackless block, shortcode or purchase button. A purchase button that appears later on a page still brings its scripts with it.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'keep_pages', 'title' => __('Always treat as store pages', 'stackless-lite-edd'), 'default' => '', 'desc' => __('Page IDs or URL paths, one per line (for example 42 or /landing/). Use for pages whose store widgets come from a page builder.', 'stackless-lite-edd'), 'css' => 'min-width:300px;height:70px'],
            ['type' => 'checkbox', 'id' => $p . 'defer_scripts', 'title' => __('Defer EDD scripts', 'stackless-lite-edd'), 'default' => 'yes', 'desc' => __('Load EDD scripts with defer outside checkout', 'stackless-lite-edd'), 'desc_tip' => __('WordPress keeps a script blocking when another plugin needs it that way, so this never breaks load order.', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'generator_tag', 'title' => __('Generator tag', 'stackless-lite-edd'), 'default' => 'yes', 'desc' => __('Remove the Easy Digital Downloads version meta tag from page source', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'frontend'],

            ['type' => 'title', 'id' => $p . 'admin', 'title' => __('Admin', 'stackless-lite-edd'), 'desc' => ''],
            ['type' => 'checkbox', 'id' => $p . 'notifications', 'title' => __('Remote notifications', 'stackless-lite-edd'), 'default' => 'no', 'desc' => __('Stop fetching EDD\'s remote tips and announcements into the notifications panel', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'help_menu', 'title' => __('Help menu and footer', 'stackless-lite-edd'), 'default' => 'no', 'desc' => __('Hide EDD\'s floating help menu, review request and footer links on EDD screens', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'dashboard_widgets', 'title' => __('Dashboard widget', 'stackless-lite-edd'), 'default' => 'no', 'desc' => __('Remove the EDD sales summary widget from the WordPress dashboard', 'stackless-lite-edd'), 'desc_tip' => __('The widget runs its own report queries every time the dashboard loads.', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'admin'],

            ['type' => 'title', 'id' => $p . 'housekeeping', 'title' => __('Housekeeping', 'stackless-lite-edd'), 'desc' => __('Smaller tables mean faster queries for orders and scheduled actions.', 'stackless-lite-edd')],
            ['type' => 'number', 'id' => $p . 'as_retention', 'title' => __('Scheduled action logs', 'stackless-lite-edd'), 'default' => '31', 'desc' => __('Days to keep completed and cancelled scheduled actions. Action Scheduler keeps 31; 7 is plenty for most stores.', 'stackless-lite-edd'), 'custom_attributes' => ['min' => 1, 'max' => 365, 'step' => 1]],
            ['type' => 'sectionend', 'id' => $p . 'housekeeping'],
        ];
    }

    public function boot(): void
    {
        if ($this->enabled('generator_tag', 'yes')) {
            add_action('init', [$this, 'removeGeneratorTag']);
        }

        if (!is_admin()) {
            add_filter('render_block', [$this, 'trackBlock'], 10, 2);
            add_action('wp_enqueue_scripts', [$this, 'trimAssets'], 999);
            add_action('edd_purchase_link_top', [$this, 'restoreAssets']);
            if ($this->enabled('defer_scripts', 'yes')) {
                add_action('wp_print_scripts', [$this, 'deferScripts'], 2);
                add_action('wp_print_footer_scripts', [$this, 'deferScripts'], 2);
            }
        }

        if ($this->enabled('notifications')) {
            // EDD skips its daily fetch while this "next request" time is in the future.
            add_filter('pre_option_edd_notification_req_timeout', [self::class, 'never']);
        }
        if ($this->enabled('help_menu')) {
            add_filter('edd_admin_flyoutmenu', '__return_false');
            add_filter('edd_admin_page_footer_exclusions', [$this, 'footerExclusions']);
        }
        if ($this->enabled('dashboard_widgets')) {
            add_action('wp_dashboard_setup', [$this, 'removeDashboardWidgets'], 99);
        }

        $retention = Rules::retentionSeconds($this->option('as_retention', 31), DAY_IN_SECONDS);
        if ($retention !== null) {
            add_filter('action_scheduler_retention_period', static function () use ($retention) {
                return $retention;
            });
        }
    }

    /* ---------------------------------------------------------------- storefront */

    public function removeGeneratorTag(): void
    {
        remove_action('wp_head', 'edd_version_in_header');
    }

    /**
     * @param string $content
     * @param array  $block
     * @return string
     */
    public function trackBlock($content, $block)
    {
        if (!$this->renderedStoreBlock && is_array($block) && Rules::isStoreBlock((string) ($block['blockName'] ?? ''))) {
            $this->renderedStoreBlock = true;
            $this->restoreAssets();
        }
        return $content;
    }

    public function trimAssets(): void
    {
        if (!$this->enabled('trim_assets') || $this->isStorePage()) {
            return;
        }
        foreach (Rules::STORE_STYLES as $handle) {
            wp_dequeue_style($handle);
        }
        foreach (Rules::STORE_SCRIPTS as $handle) {
            wp_dequeue_script($handle);
        }
        $this->trimmed = true;
    }

    /**
     * Something on this page needs EDD after all (a purchase button or an EDD block rendered after
     * the head): put the assets back. Scripts print in the footer; the stylesheet prints there too.
     */
    public function restoreAssets(): void
    {
        $this->storePage = true;
        if (!$this->trimmed) {
            return;
        }
        $this->trimmed = false;
        foreach (Rules::STORE_STYLES as $handle) {
            if (wp_style_is($handle, 'registered')) {
                wp_enqueue_style($handle);
            }
        }
        foreach (Rules::STORE_SCRIPTS as $handle) {
            if (wp_script_is($handle, 'registered') && !(function_exists('edd_is_ajax_disabled') && edd_is_ajax_disabled())) {
                wp_enqueue_script($handle);
            }
        }
    }

    public function deferScripts(): void
    {
        if (function_exists('edd_is_checkout') && edd_is_checkout()) {
            return;
        }
        $scripts = wp_scripts();
        /**
         * Filters the EDD script handles the optimizer defers.
         *
         * @param string[] $handles
         */
        $handles = (array) apply_filters('stackless_edd_edd_optimizer_defer_handles', Rules::DEFERRABLE_SCRIPTS);
        foreach ($handles as $handle) {
            if (isset($scripts->registered[$handle]) && !$scripts->get_data($handle, 'strategy')) {
                $scripts->add_data($handle, 'strategy', 'defer');
            }
        }
    }

    /** Downloads, checkout, receipt, account, or any page that renders store blocks or shortcodes. */
    public function isStorePage(): bool
    {
        // Only a positive answer is final: a store block can still render later in the request.
        if ($this->storePage === true) {
            return true;
        }
        $store = $this->renderedStoreBlock
            || is_singular('download')
            || is_post_type_archive('download')
            || is_tax(['download_category', 'download_tag'])
            || (function_exists('edd_is_checkout') && edd_is_checkout())
            || (function_exists('edd_is_success_page') && edd_is_success_page())
            || (function_exists('edd_is_failed_transaction_page') && edd_is_failed_transaction_page())
            || (function_exists('edd_is_purchase_history_page') && edd_is_purchase_history_page())
            || (is_singular() && (int) get_queried_object_id() === \StacklessEDD\Core\Account::pageId())
            || is_active_widget(false, false, 'edd_cart_widget')
            || $this->isKeptPage();

        if (!$store && is_singular()) {
            $post = get_queried_object();
            $store = $post instanceof \WP_Post && Rules::contentHasStoreMarkupDeep((string) $post->post_content, [$this, 'syncedPatternContent']);
        }

        /**
         * Filters whether the current request is a store page that needs EDD assets.
         *
         * @param bool $store
         */
        $store = (bool) apply_filters('stackless_edd_edd_optimizer_is_store_page', $store);
        if ($store) {
            $this->storePage = true;
        }
        return $store;
    }

    /** Content of a published synced pattern (wp_block post), or '' for anything else. */
    public function syncedPatternContent(int $id): string
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'wp_block' || $post->post_status !== 'publish') {
            return '';
        }
        return (string) $post->post_content;
    }

    private function isKeptPage(): bool
    {
        $lines = preg_split('/[\r\n]+/', (string) $this->option('keep_pages', '')) ?: [];
        $path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '', PHP_URL_PATH);
        return Rules::isKeptPage($lines, (int) get_queried_object_id(), $path);
    }

    /* ---------------------------------------------------------------- admin */

    /** @return int a time EDD's notification fetch never reaches */
    public static function never(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * Footer extras EDD should leave out on the current screen.
     *
     * @param mixed $exclusions
     * @return mixed
     */
    public function footerExclusions($exclusions)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!is_array($exclusions) || !$screen instanceof \WP_Screen) {
            return $exclusions;
        }
        $exclusions[(string) $screen->id] = ['flyout', 'review', 'links'];
        return $exclusions;
    }

    public function removeDashboardWidgets(): void
    {
        remove_meta_box('edd_dashboard_sales', 'dashboard', 'normal');
    }

    private function enabled(string $key, string $default = 'no'): bool
    {
        return $this->option($key, $default) === 'yes';
    }
}
