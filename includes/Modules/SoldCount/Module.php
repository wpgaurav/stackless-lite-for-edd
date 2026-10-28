<?php

namespace StacklessEDD\Modules\SoldCount;

defined('ABSPATH') || exit;

/**
 * Sold Count: "X sold" badge from EDD's own sales count for each download.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_HIDE = '_stackless_edd_sold_count_hide';
    private const FIELD_HIDE = 'stackless_edd_sold_count_hide';
    private const FIELD_PRESENT = 'stackless_edd_sold_count_fields';
    private const HANDLE = 'stackless-edd-sold_count';

    /** @var bool */
    private $styled = false;

    public function id(): string
    {
        return 'sold_count';
    }

    public function title(): string
    {
        return __('Sold Count', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Show an "X sold" badge on downloads once they pass a sales threshold. Uses EDD\'s own sales count.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_sold_count_';
        $single = [
            'before_content' => __('Above the description', 'stackless-lite-edd'),
            'before_button' => __('Before the purchase button', 'stackless-lite-edd'),
            'after_button' => __('After the purchase button', 'stackless-lite-edd'),
            '' => __('Do not show on download pages', 'stackless-lite-edd'),
        ];
        $archive = [
            'after_title' => __('After the title', 'stackless-lite-edd'),
            'after_price' => __('After the price', 'stackless-lite-edd'),
            'before_button' => __('Before the purchase button', 'stackless-lite-edd'),
            '' => __('Do not show in download grids', 'stackless-lite-edd'),
        ];
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Sold count badge', 'stackless-lite-edd'), 'desc' => __('Counts come from EDD\'s sales count for each download: completed orders, with refunds taken off. Price options add up on their download.', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Badge text', 'stackless-lite-edd'), 'default' => __('{count} sold', 'stackless-lite-edd'), 'desc' => __('{count} is replaced with the number sold.', 'stackless-lite-edd'), 'css' => 'min-width:260px'],
            ['type' => 'number', 'id' => $p . 'count_multiplier', 'title' => __('Count multiplier', 'stackless-lite-edd'), 'default' => '1', 'desc' => __('The badge shows the real count times this number, rounded up. For example, 12 sold × 6.32 = 75.84 shows as 76. Leave it at 1 to show the real count. A count that does not match real sales can mislead buyers and may break consumer-protection rules where you sell.', 'stackless-lite-edd'), 'css' => 'width:90px', 'custom_attributes' => ['min' => '0.01', 'max' => (string) Badge::MAX_MULTIPLIER, 'step' => '0.01']],
            ['type' => 'number', 'id' => $p . 'threshold', 'title' => __('Show from', 'stackless-lite-edd'), 'default' => '5', 'desc' => __('Hide the badge until it would show at least this many. Compared with the count the badge shows, after the multiplier.', 'stackless-lite-edd'), 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'select', 'id' => $p . 'rounding', 'title' => __('Number style', 'stackless-lite-edd'), 'default' => 'exact', 'options' => [
                'exact' => __('Exact (1,268 sold)', 'stackless-lite-edd'),
                'rounded' => __('Rounded down (1,200+ sold)', 'stackless-lite-edd'),
            ]],
            ['type' => 'select', 'id' => $p . 'single_position', 'title' => __('Download pages', 'stackless-lite-edd'), 'default' => 'before_button', 'options' => $single],
            ['type' => 'select', 'id' => $p . 'archive_position', 'title' => __('Download grids', 'stackless-lite-edd'), 'default' => 'after_price', 'options' => $archive, 'desc' => __('Applies to the [downloads] grid, the EDD downloads block and purchase buttons placed elsewhere.', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        (new Placement([$this, 'render'], (string) $this->option('single_position', 'before_button'), (string) $this->option('archive_position', 'after_price')))->register();

        add_filter('stackless_edd_settings_sanitize_option_stackless_edd_sold_count_count_multiplier', [$this, 'sanitizeMultiplier'], 10, 3);
        add_action('edd_meta_box_settings_fields', [$this, 'renderDownloadField'], 45, 2);
        add_action('edd_save_download', [$this, 'saveDownloadField'], 10, 2);
    }

    /**
     * Saved as a plain decimal string, clamped to a positive number up to Badge::MAX_MULTIPLIER.
     *
     * @param mixed $value
     * @param mixed $field
     * @param mixed $raw
     */
    public function sanitizeMultiplier($value, $field = [], $raw = null): string
    {
        $raw = is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        return (string) Badge::multiplier($raw === '' ? 1 : $raw);
    }

    public function count(int $downloadId): int
    {
        $sales = function_exists('edd_get_download_sales_stats') ? edd_get_download_sales_stats($downloadId) : 0;
        /**
         * Filters the sold count shown for a download.
         *
         * @param int $count
         * @param int $download_id
         */
        return (int) apply_filters('stackless_edd_sold_count_count', is_numeric($sales) ? (int) $sales : 0, $downloadId);
    }

    /** The number the badge shows: the real count times the count multiplier, rounded up. */
    public function shownCount(int $downloadId): int
    {
        return Badge::multiply($this->count($downloadId), Badge::multiplier($this->option('count_multiplier', 1)));
    }

    /** Badge HTML for a download, or '' when it should not show. */
    public function render(int $downloadId, string $context = 'single'): string
    {
        if ($downloadId < 1 || get_post_meta($downloadId, self::META_HIDE, true) === 'yes') {
            return '';
        }
        $count = $this->shownCount($downloadId);
        if (!Badge::visible($count, (int) $this->option('threshold', 5))) {
            return '';
        }
        $formatted = Badge::formatCount($count, (string) $this->option('rounding', 'exact'), static function (int $n): string {
            return (string) number_format_i18n($n);
        });
        $text = Badge::text((string) $this->option('text', __('{count} sold', 'stackless-lite-edd')), $formatted);
        $this->style();
        return sprintf(
            '<div class="stackless-sold-count stackless-sold-count--%1$s"><span class="stackless-sold-count__text">%2$s</span></div>',
            esc_attr($context === 'archive' ? 'archive' : 'single'),
            esc_html($text)
        );
    }

    private function style(): void
    {
        if ($this->styled) {
            return;
        }
        $this->styled = true;
        wp_register_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/sold_count/sold-count.css', [], STACKLESS_EDD_VERSION);
        wp_enqueue_style(self::HANDLE);
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $downloadId
     * @param mixed $download
     */
    public function renderDownloadField($downloadId = 0, $download = null): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $hidden = $downloadId > 0 && get_post_meta($downloadId, self::META_HIDE, true) === 'yes';
        ?>
        <div class="edd-form-group stackless-sold-count-field">
            <input type="hidden" name="<?php echo esc_attr(self::FIELD_PRESENT); ?>" value="1">
            <div class="edd-form-group__control">
                <input type="checkbox" class="edd-form-group__input" name="<?php echo esc_attr(self::FIELD_HIDE); ?>" id="<?php echo esc_attr(self::FIELD_HIDE); ?>" value="yes"<?php checked($hidden); ?>>
                <label for="<?php echo esc_attr(self::FIELD_HIDE); ?>"><?php esc_html_e('Hide sold count', 'stackless-lite-edd'); ?></label>
            </div>
            <p class="description edd-form-group__help"><?php esc_html_e('Do not show the "sold" badge for this download.', 'stackless-lite-edd'); ?></p>
        </div>
        <?php
    }

    /**
     * Runs after EDD verified the download editor nonce.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadField($postId = 0, $post = null): void
    {
        $postId = is_numeric($postId) ? (int) $postId : 0;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD checked the download editor nonce before edd_save_download.
        if ($postId < 1 || !current_user_can('edit_product', $postId) || empty($_POST[self::FIELD_PRESENT])) {
            return;
        }
        if (!empty($_POST[self::FIELD_HIDE])) {
            update_post_meta($postId, self::META_HIDE, 'yes');
        } else {
            delete_post_meta($postId, self::META_HIDE);
        }
        // phpcs:enable
    }
}
