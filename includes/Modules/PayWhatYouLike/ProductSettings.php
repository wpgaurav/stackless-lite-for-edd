<?php

namespace StacklessEDD\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Per-download settings: a "Pay what you like" box in the download editor and optional overrides
 * on each variable price. Amounts are stored in the store's base currency as decimal strings;
 * Multi-Currency Checkout converts them for other currencies at display time.
 *
 * Subscription downloads (or subscription variable prices) keep their set price, so they never
 * take a chosen amount.
 */
final class ProductSettings
{
    public const META = '_stackless_edd_pay_what_you_like';
    public const META_PRICES = '_stackless_edd_pay_what_you_like_prices';
    private const FIELD = 'stackless_edd_pwyl';
    private const NONCE = 'stackless_edd_pwyl_settings';
    private const NOTICE = 'stackless_edd_pwyl_notice_';

    /** @var array<string, array<string, mixed>|null> */
    private $cache = [];

    public function register(): void
    {
        add_action('add_meta_boxes_download', [$this, 'addMetaBox']);
        add_action('edd_download_price_option_row', [$this, 'variationFields'], 25, 3);
        add_action('edd_save_download', [$this, 'save'], 20, 2);
        add_action('admin_notices', [$this, 'notices']);
    }

    private static function decimals(): int
    {
        return stackless_edd_base_price_decimals();
    }

    private static function separator(): string
    {
        $separator = function_exists('edd_get_option') ? (string) edd_get_option('decimal_separator', '.') : '.';
        return $separator !== '' ? $separator : '.';
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Effective settings for a download or one of its variable prices, or null when it does not
     * take a chosen amount (switched off, or a subscription).
     *
     * A variable download asked without a price id answers with the download's own settings.
     *
     * @return array{enabled: bool, min: string, suggested: string, max: string, presets: string[]}|null
     */
    public function settingsFor(int $downloadId, ?int $priceId = null): ?array
    {
        if ($downloadId < 1) {
            return null;
        }
        $key = $downloadId . '|' . ($priceId === null ? '-' : $priceId);
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->compute($downloadId, $priceId);
        }
        $settings = $this->cache[$key];
        /**
         * Filters the effective pay-what-you-like settings of a download (null = fixed price).
         *
         * @param array|null $settings    enabled, min, suggested, max, presets (base currency)
         * @param int        $download_id
         * @param int|null   $price_id
         */
        $settings = apply_filters('stackless_edd_pay_what_you_like_settings', $settings, $downloadId, $priceId);
        return is_array($settings) && !empty($settings['enabled']) ? Amounts::normalizeProduct($settings) : null;
    }

    /** @return array<string, mixed>|null */
    private function compute(int $downloadId, ?int $priceId): ?array
    {
        if (get_post_type($downloadId) !== 'download') {
            return null;
        }
        if (stackless_edd_is_subscription_product($downloadId, $priceId)) {
            return null;
        }
        $product = Amounts::normalizeProduct(get_post_meta($downloadId, self::META, true));
        if ($priceId !== null && function_exists('edd_has_variable_prices') && edd_has_variable_prices($downloadId)) {
            $overrides = get_post_meta($downloadId, self::META_PRICES, true);
            $variation = is_array($overrides) && isset($overrides[$priceId]) ? $overrides[$priceId] : [];
            $settings = Amounts::resolve($product, Amounts::normalizeVariation($variation));
        } else {
            $settings = $product;
        }
        return $settings['enabled'] ? $settings : null;
    }

    public function flush(int $downloadId = 0): void
    {
        if ($downloadId) {
            foreach (array_keys($this->cache) as $key) {
                if (strpos($key, $downloadId . '|') === 0) {
                    unset($this->cache[$key]);
                }
            }
        } else {
            $this->cache = [];
        }
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    public function addMetaBox(): void
    {
        add_meta_box('stackless-edd-pay-what-you-like', __('Pay what you like', 'stackless-lite-edd'), [$this, 'panel'], 'download', 'normal', 'default');
    }

    /** @param mixed $post */
    public function panel($post = null): void
    {
        $downloadId = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $settings = Amounts::normalizeProduct($downloadId ? get_post_meta($downloadId, self::META, true) : []);
        $symbol = self::symbol();

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');
        echo '<input type="hidden" name="' . esc_attr(self::FIELD . '_present') . '" value="1">';
        echo '<div class="stackless-pwyl-admin">';
        echo '<p>' . \StacklessEDD\Admin\Documentation::link('pay_what_you_like', __('How this works', 'stackless-lite-edd')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        printf(
            '<p class="edd-form-group"><label><input type="checkbox" name="%1$s" value="yes"%2$s> %3$s</label></p>',
            esc_attr(self::FIELD . '_enabled'),
            checked($settings['enabled'], true, false),
            esc_html__('Let buyers choose what to pay', 'stackless-lite-edd')
        );
        echo '<p class="description">' . esc_html__('The download\'s price is the minimum unless you set one below, so a price of 0 makes it "free, or pay what you like". Subscription downloads keep their set price.', 'stackless-lite-edd') . '</p>';

        $fields = [
            'min' => [__('Minimum', 'stackless-lite-edd'), __('Leave empty to use the download\'s price as the minimum.', 'stackless-lite-edd')],
            'suggested' => [__('Suggested amount', 'stackless-lite-edd'), __('Filled in for the buyer. Leave empty to start at the download\'s price.', 'stackless-lite-edd')],
            'max' => [__('Maximum', 'stackless-lite-edd'), __('Leave empty to allow up to 1,000,000.', 'stackless-lite-edd')],
        ];
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $key => $labels) {
            $id = self::FIELD . '_' . $key;
            printf(
                '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" inputmode="decimal" class="regular-text" id="%1$s" name="%1$s" value="%3$s"><p class="description">%4$s</p></td></tr>',
                esc_attr($id),
                /* translators: 1: field label, 2: currency symbol */
                esc_html(sprintf(__('%1$s (%2$s)', 'stackless-lite-edd'), $labels[0], $symbol)),
                esc_attr(self::localized($settings[$key])),
                esc_html($labels[1])
            );
        }
        echo '<tr><th scope="row">' . esc_html__('Quick amounts', 'stackless-lite-edd') . '</th><td>';
        for ($i = 0; $i < Amounts::MAX_PRESETS; $i++) {
            printf(
                '<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" inputmode="decimal" class="small-text" id="%1$s" name="%3$s" value="%4$s"> ',
                esc_attr(self::FIELD . '_preset_' . $i),
                /* translators: 1: quick amount number (1-4), 2: currency symbol */
                esc_html(sprintf(__('Quick amount %1$d (%2$s)', 'stackless-lite-edd'), $i + 1, $symbol)),
                esc_attr(self::FIELD . '_presets[' . $i . ']'),
                esc_attr(isset($settings['presets'][$i]) ? self::localized($settings['presets'][$i]) : '')
            );
        }
        echo '<p class="description">' . esc_html__('One-tap amounts shown with the amount field, for example 0, 5 and 10. Amounts below a download\'s minimum are left out, so 0 only shows when the minimum is 0.', 'stackless-lite-edd') . '</p></td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: store currency code */
            __('Amounts are in %s. Other currencies are converted at checkout. Variable prices can override the minimum, suggested amount and maximum, or opt out.', 'stackless-lite-edd'),
            stackless_edd_base_currency()
        )) . '</p>';
        echo '</div>';
    }

    /**
     * Overrides inside each variable price row. The price id is the first number in every name and
     * id, so EDD's row cloning renumbers it.
     *
     * @param mixed $downloadId
     * @param mixed $priceId
     * @param mixed $args
     */
    public function variationFields($downloadId = 0, $priceId = 0, $args = []): void
    {
        if (!is_numeric($priceId)) {
            return;
        }
        $priceId = (int) $priceId;
        $overrides = get_post_meta(absint($downloadId), self::META_PRICES, true);
        $settings = Amounts::normalizeVariation(is_array($overrides) && isset($overrides[$priceId]) ? $overrides[$priceId] : []);
        $symbol = self::symbol();

        echo '<div class="stackless-pwyl-variation">';
        echo '<input type="hidden" name="' . esc_attr(self::FIELD . '_var_present[' . $priceId . ']') . '" value="1">';
        printf(
            '<p class="edd-form-group__label">%1$s</p><div class="edd-form-group__control edd-toggle"><input type="checkbox" id="%2$s" name="%3$s" value="yes"%4$s><label for="%2$s">%5$s</label></div>',
            esc_html__('Pay what you like', 'stackless-lite-edd'),
            esc_attr('stackless-edd-pwyl-' . $priceId . '-off'),
            esc_attr(self::FIELD . '_var[' . $priceId . '][off]'),
            checked($settings['mode'], 'off', false),
            esc_html__('Fixed price: no pay what you like for this price (when the download has it on)', 'stackless-lite-edd')
        );
        $fields = [
            'min' => __('Pay what you like minimum', 'stackless-lite-edd'),
            'suggested' => __('Suggested amount', 'stackless-lite-edd'),
            'max' => __('Maximum', 'stackless-lite-edd'),
        ];
        foreach ($fields as $key => $label) {
            printf(
                '<div class="edd-form-group__control"><label class="edd-form-group__label" for="%1$s">%2$s</label><input type="text" inputmode="decimal" class="edd-form-group__input" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s"></div>',
                esc_attr('stackless-edd-pwyl-' . $priceId . '-' . $key),
                /* translators: 1: field label, 2: currency symbol */
                esc_html(sprintf(__('%1$s (%2$s)', 'stackless-lite-edd'), $label, $symbol)),
                esc_attr(self::FIELD . '_var[' . $priceId . '][' . $key . ']'),
                esc_attr(self::localized($settings[$key])),
                esc_attr__('Download setting', 'stackless-lite-edd')
            );
        }
        echo '</div>';
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     */
    public function save($postId, $post = null): void
    {
        $postId = absint($postId);
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below; EDD verified its own editor nonce first.
        if (!$postId || empty($_POST[self::FIELD . '_present']) || !current_user_can('edit_product', $postId)) {
            return;
        }
        $nonce = isset($_POST[self::NONCE . '_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE . '_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }
        $text = static function (string $key): string {
            return isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
        };
        $presets = [];
        if (isset($_POST[self::FIELD . '_presets']) && is_array($_POST[self::FIELD . '_presets'])) {
            foreach (map_deep(wp_unslash($_POST[self::FIELD . '_presets']), 'sanitize_text_field') as $value) {
                if (is_scalar($value)) {
                    $presets[] = sanitize_text_field((string) $value);
                }
            }
        }
        $input = [
            'enabled' => $text(self::FIELD . '_enabled'),
            'min' => $text(self::FIELD . '_min'),
            'suggested' => $text(self::FIELD . '_suggested'),
            'max' => $text(self::FIELD . '_max'),
            'presets' => $presets,
        ];
        $rows = isset($_POST[self::FIELD . '_var']) && is_array($_POST[self::FIELD . '_var']) ? map_deep(wp_unslash($_POST[self::FIELD . '_var']), 'sanitize_text_field') : [];
        $present = isset($_POST[self::FIELD . '_var_present']) && is_array($_POST[self::FIELD . '_var_present']) ? array_keys(map_deep(wp_unslash($_POST[self::FIELD . '_var_present']), 'sanitize_text_field')) : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $messages = [];
        $result = Amounts::sanitizeProduct($input, self::decimals(), self::separator());
        foreach ($result['errors'] as $code) {
            $messages[] = self::errorMessage($code);
        }
        if ($result['settings'] === Amounts::productDefaults()) {
            delete_post_meta($postId, self::META);
        } else {
            update_post_meta($postId, self::META, $result['settings']);
        }

        $stored = get_post_meta($postId, self::META_PRICES, true);
        $stored = is_array($stored) ? $stored : [];
        foreach ($present as $priceId) {
            if (!is_numeric($priceId)) {
                continue;
            }
            $row = is_array($rows) && isset($rows[$priceId]) && is_array($rows[$priceId]) ? $rows[$priceId] : [];
            $clean = [];
            foreach (['off', 'min', 'suggested', 'max'] as $key) {
                $clean[$key] = isset($row[$key]) && is_scalar($row[$key]) ? sanitize_text_field((string) $row[$key]) : '';
            }
            $variation = Amounts::sanitizeVariation($clean, self::decimals(), self::separator());
            foreach ($variation['errors'] as $code) {
                $messages[] = sprintf(
                    /* translators: 1: variable price ID, 2: error message */
                    __('Price #%1$d: %2$s', 'stackless-lite-edd'),
                    (int) $priceId,
                    self::errorMessage($code)
                );
            }
            if ($variation['settings'] === Amounts::variationDefaults()) {
                unset($stored[(int) $priceId]);
            } else {
                $stored[(int) $priceId] = $variation['settings'];
            }
        }
        // Drop overrides of variable prices that were removed from the download.
        $existing = array_map('intval', array_keys(array_filter((array) get_post_meta($postId, 'edd_variable_prices', true))));
        $stored = array_intersect_key($stored, array_flip($existing));
        if ($stored) {
            update_post_meta($postId, self::META_PRICES, $stored);
        } else {
            delete_post_meta($postId, self::META_PRICES);
        }
        if ($messages) {
            set_transient(self::NOTICE . get_current_user_id(), $messages, 120);
        }
        $this->flush();
    }

    public function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'download') {
            return;
        }
        $key = self::NOTICE . get_current_user_id();
        $messages = get_transient($key);
        if (!is_array($messages) || !$messages) {
            return;
        }
        delete_transient($key);
        foreach ($messages as $message) {
            echo '<div class="notice notice-error stackless-notice"><p>' . esc_html((string) $message) . '</p></div>';
        }
    }

    private static function symbol(): string
    {
        return function_exists('edd_currency_symbol') ? html_entity_decode((string) edd_currency_symbol(stackless_edd_base_currency()), ENT_QUOTES, 'UTF-8') : '';
    }

    private static function localized(string $value): string
    {
        return $value === '' ? '' : str_replace('.', self::separator(), $value);
    }

    public static function errorMessage(string $code): string
    {
        switch ($code) {
            case 'invalid_min':
                return __('Pay what you like: the minimum is not a valid amount and was not saved.', 'stackless-lite-edd');
            case 'invalid_suggested':
                return __('Pay what you like: the suggested amount is not a valid amount and was not saved.', 'stackless-lite-edd');
            case 'invalid_max':
                return __('Pay what you like: the maximum is not a valid amount and was not saved.', 'stackless-lite-edd');
            case 'invalid_preset':
                return __('Pay what you like: a quick amount is not a valid amount and was left out.', 'stackless-lite-edd');
            case 'too_many_presets':
                /* translators: %d: the number of quick amounts allowed */
                return sprintf(__('Pay what you like: add up to %d quick amounts.', 'stackless-lite-edd'), Amounts::MAX_PRESETS);
            case 'min_above_max':
                return __('Pay what you like: the minimum is above the maximum, so the maximum was not saved.', 'stackless-lite-edd');
            case 'suggested_above_max':
                return __('Pay what you like: the suggested amount can\'t be above the maximum and was not saved.', 'stackless-lite-edd');
            case 'preset_above_max':
                return __('Pay what you like: quick amounts above the maximum were left out.', 'stackless-lite-edd');
        }
        return __('Pay what you like: some settings could not be saved.', 'stackless-lite-edd');
    }
}
