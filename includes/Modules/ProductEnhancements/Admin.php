<?php

namespace StacklessEDD\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Download editor: a "Details table" box and per-variable-price values in each price row.
 */
final class Admin
{
    public const FIELD = 'stackless_edd_product_enhancements';
    public const FIELD_PRICES = 'stackless_edd_product_enhancements_prices';
    private const NONCE = 'stackless_edd_product_enhancements_nonce';
    private const ERRORS = 'stackless_edd_product_enhancements_errors_';
    private const HANDLE = 'stackless-edd-product_enhancements-admin';

    public function register(): void
    {
        add_action('add_meta_boxes_download', [$this, 'metaBox']);
        add_action('save_post_download', [$this, 'save'], 10, 2);
        add_action('edd_download_price_option_row', [$this, 'priceFields'], 30, 3);
        add_action('edd_save_download', [$this, 'savePrices'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public function metaBox(): void
    {
        add_meta_box('stackless-edd-product-details', __('Details table', 'stackless-lite-edd'), [$this, 'panel'], 'download', 'normal', 'default');
    }

    public function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'download') {
            return;
        }
        wp_enqueue_script(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/product_enhancements/admin.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_enqueue_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/product_enhancements/admin.css', [], STACKLESS_EDD_VERSION);
    }

    /** @param \WP_Post $post */
    public function panel($post): void
    {
        $downloadId = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $data = Repository::get($downloadId);
        $fields = Fields::all();
        $presets = Fields::presets();
        $auto = $downloadId > 0 ? AutoFill::values($downloadId) : [];

        $membership = [];
        foreach ($presets as $presetKey => $preset) {
            foreach ($preset['fields'] as $fieldKey) {
                $membership[$fieldKey][] = $presetKey;
            }
        }

        wp_nonce_field('stackless_edd_product_enhancements_save', self::NONCE);
        echo '<div class="stackless-pe-panel">';
        echo '<p class="stackless-docs-field">' . \StacklessEDD\Admin\Documentation::link('product_enhancements', __('How this works', 'stackless-lite-edd')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().

        echo '<div class="stackless-pe-group">';
        echo '<p class="stackless-pe-row"><label for="stackless_pe_preset">' . esc_html__('Download type', 'stackless-lite-edd') . '</label>';
        printf('<select id="stackless_pe_preset" name="%s[preset]">', esc_attr(self::FIELD));
        foreach ($presets as $key => $preset) {
            printf('<option value="%s" %s>%s</option>', esc_attr((string) $key), selected($data['preset'], (string) $key, false), esc_html((string) $preset['label']));
        }
        echo '</select>';
        $this->tip(__('Picks which details to show. Grey values come from EDD data and fill in automatically when you leave a box empty.', 'stackless-lite-edd'));
        echo '</p>';
        printf(
            '<p class="stackless-pe-row"><span class="stackless-pe-label">%s</span><label class="stackless-pe-check"><input type="checkbox" id="stackless_pe_show" name="%s[show]" value="yes" %s> %s</label></p>',
            esc_html__('Details table', 'stackless-lite-edd'),
            esc_attr(self::FIELD),
            checked($data['show'], true, false),
            esc_html__('Show the details table on the download page', 'stackless-lite-edd')
        );
        echo '</div>';

        echo '<div class="stackless-pe-group stackless-pe-fields">';
        foreach ($membership as $key => $inPresets) {
            if (!isset($fields[$key])) {
                continue;
            }
            $field = $fields[$key];
            $id = 'stackless_pe_' . $key;
            $placeholder = (string) ($auto[(string) ($field['auto'] ?? '')] ?? ($field['default'] ?? ''));
            $value = $data['values'][$key] ?? '';
            printf('<p class="stackless-pe-row stackless-pe-field" data-presets="%s">', esc_attr(implode(' ', $inPresets)));
            printf('<label for="%s">%s</label>', esc_attr($id), esc_html((string) $field['label']));
            self::input($id, self::FIELD . '[values][' . $key . ']', $field, $value, $placeholder);
            printf(
                '<label class="stackless-pe-hide"><input type="checkbox" name="%s[hidden][]" value="%s" %s> %s</label>',
                esc_attr(self::FIELD),
                esc_attr($key),
                checked(in_array($key, $data['hidden'], true), true, false),
                esc_html__('Hide', 'stackless-lite-edd')
            );
            if (!empty($field['help'])) {
                $this->tip((string) $field['help']);
            }
            echo '</p>';
        }
        echo '</div>';

        echo '<div class="stackless-pe-group">';
        echo '<p class="stackless-pe-row"><label for="stackless_pe_custom">' . esc_html__('Extra details', 'stackless-lite-edd') . '</label>';
        printf(
            '<textarea id="stackless_pe_custom" name="%s[custom]" rows="4" placeholder="%s">%s</textarea>',
            esc_attr(self::FIELD),
            esc_attr__("Care | Machine wash cold\nCertified | OEKO-TEX", 'stackless-lite-edd'),
            esc_textarea(Repository::customText($data['custom']))
        );
        /* translators: %d: maximum number of rows */
        $this->tip(sprintf(__('One per line as "Name | Value". Up to %d rows, shown after the fields above.', 'stackless-lite-edd'), Repository::MAX_CUSTOM));
        echo '</p>';
        echo '<input type="hidden" name="' . esc_attr(self::FIELD) . '[present]" value="1">';
        echo '</div>';
        echo '</div>';
    }

    /** @param array<string, mixed> $field */
    private static function input(string $id, string $name, array $field, string $value, string $placeholder): void
    {
        $type = (string) $field['type'];
        if ($type === 'select') {
            printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
            printf('<option value="">%s</option>', esc_html($placeholder !== '' ? $placeholder : __('Not set', 'stackless-lite-edd')));
            foreach ((array) ($field['options'] ?? []) as $option) {
                printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr((string) $option), selected($value, (string) $option, false));
            }
            echo '</select>';
            return;
        }
        $html = ['number' => 'number', 'date' => 'date', 'url' => 'url'][$type] ?? 'text';
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text"%s>',
            esc_attr($html),
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            esc_attr($placeholder),
            $html === 'number' ? ' min="0" step="1"' : ''
        );
    }

    /** An EDD-style help tip. */
    private function tip(string $text): void
    {
        printf('<span class="edd-help-tip dashicons dashicons-editor-help" title="%s"></span>', esc_attr(wp_strip_all_tags($text)));
    }

    /**
     * @param int|string $postId
     * @param \WP_Post   $post
     */
    public function save($postId, $post = null): void
    {
        $postId = (int) $postId;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), 'stackless_edd_product_enhancements_save')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_product', $postId)) {
            return;
        }
        $raw = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in Repository::validate().
        if (!is_array($raw) || empty($raw['present'])) {
            return;
        }
        [$data, $errors] = Repository::validate($postId, $raw);
        $existing = Repository::get($postId);
        if (!$existing['saved'] && !$data['show'] && !$data['values'] && !$data['custom'] && !$data['hidden']) {
            return;
        }
        update_post_meta($postId, Repository::META, $data);
        AutoFill::flush($postId);
        foreach (array_unique($errors) as $error) {
            /* translators: %s: validation message */
            $this->addError(sprintf(__('Details table: %s', 'stackless-lite-edd'), $error));
        }
    }

    /**
     * Per-price detail fields inside each variable price row.
     *
     * @param int|string $downloadId
     * @param int|string $priceId
     * @param array      $args
     */
    public function priceFields($downloadId, $priceId, $args = []): void
    {
        $downloadId = (int) $downloadId;
        $data = Repository::get($downloadId);
        $presets = Fields::presets();
        $keys = $presets[$data['preset']]['fields'] ?? [];
        if (!$keys) {
            return;
        }
        $fields = Fields::all();
        $values = $downloadId > 0 ? Repository::priceValues($downloadId, (int) $priceId) : [];
        $auto = $downloadId > 0 ? AutoFill::values($downloadId, (int) $priceId) : [];
        echo '<div class="stackless-pe-variation"><p class="stackless-pe-variation__title"><strong>' . esc_html__('Details table for this price', 'stackless-lite-edd') . '</strong> ';
        $this->tip(__('Leave blank to use the download\'s value. The field list follows the download type set in the Details table box; update the download after changing it.', 'stackless-lite-edd'));
        echo '</p><div class="stackless-pe-variation__fields">';
        foreach ($keys as $key) {
            if (!isset($fields[$key]) || in_array($key, $data['hidden'], true)) {
                continue;
            }
            $field = $fields[$key];
            $id = 'stackless_pe_p' . (int) $priceId . '_' . $key;
            $placeholder = (string) ($data['values'][$key] ?? ($auto[(string) ($field['auto'] ?? '')] ?? ''));
            printf('<p class="edd-form-group"><label class="edd-form-group__label" for="%s">%s</label>', esc_attr($id), esc_html((string) $field['label']));
            self::input($id, self::FIELD_PRICES . '[' . (int) $priceId . '][' . $key . ']', $field, $values[$key] ?? '', $placeholder);
            echo '</p>';
        }
        echo '</div></div>';
    }

    /**
     * Saves per-price values. EDD verified its download editor nonce before this action.
     *
     * @param int|string $postId
     * @param \WP_Post   $post
     */
    public function savePrices($postId, $post = null): void
    {
        $postId = (int) $postId;
        if (!current_user_can('edit_product', $postId)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified edd_download_meta_box_nonce.
        if (!isset($_POST[self::FIELD_PRICES]) || !is_array($_POST[self::FIELD_PRICES])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Fields::clean().
        $input = (array) wp_unslash($_POST[self::FIELD_PRICES]);
        $preset = Repository::get($postId)['preset'];
        $valid = array_flip(Repository::priceIds($postId));
        $stored = [];
        $errors = [];
        foreach ($input as $priceId => $values) {
            if (!is_numeric($priceId) || !is_array($values) || ($valid && !isset($valid[(int) $priceId]))) {
                continue;
            }
            [$clean, $priceErrors] = Repository::validatePriceValues($preset, $values);
            if ($clean) {
                $stored[(int) $priceId] = $clean;
            }
            foreach ($priceErrors as $error) {
                /* translators: 1: variable price ID, 2: validation message */
                $errors[] = sprintf(__('Price option #%1$d details: %2$s', 'stackless-lite-edd'), (int) $priceId, $error);
            }
        }
        if ($stored) {
            update_post_meta($postId, Repository::META_PRICES, $stored);
        } else {
            delete_post_meta($postId, Repository::META_PRICES);
        }
        AutoFill::flush($postId);
        foreach (array_unique($errors) as $error) {
            $this->addError($error);
        }
    }

    private function addError(string $message): void
    {
        $key = self::ERRORS . get_current_user_id();
        $errors = get_transient($key);
        $errors = is_array($errors) ? $errors : [];
        $errors[] = $message;
        set_transient($key, array_values(array_unique($errors)), 120);
    }

    /** Validation messages from the last save, shown once on the download editor. */
    public function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'download') {
            return;
        }
        $key = self::ERRORS . get_current_user_id();
        $errors = get_transient($key);
        if (!is_array($errors) || !$errors) {
            return;
        }
        delete_transient($key);
        foreach ($errors as $error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
        }
    }
}
