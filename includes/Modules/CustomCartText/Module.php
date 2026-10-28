<?php

namespace StacklessEDD\Modules\CustomCartText;

defined('ABSPATH') || exit;

/**
 * Custom Add to Cart Text: purchase button labels per download type, for free and subscription
 * downloads, and per download or price option, on download grids and download pages.
 *
 * EDD builds the button text as "{price}&nbsp;&ndash;&nbsp;{label}" before edd_purchase_link_args;
 * this module swaps the label and keeps the price part. Every EDD purchase button (download pages,
 * the [downloads] grid, [purchase_link], the downloads and buy button blocks) goes through it.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_LOOP = '_stackless_edd_custom_cart_text_loop';
    public const META_SINGLE = '_stackless_edd_custom_cart_text_single';
    /** price id => label on the download page. */
    public const META_PRICES = '_stackless_edd_custom_cart_text_prices';
    private const FIELD_LOOP = 'stackless_edd_custom_cart_text_loop';
    private const FIELD_SINGLE = 'stackless_edd_custom_cart_text_single';
    private const FIELD_PRICES = 'stackless_edd_custom_cart_text_prices';
    private const FIELD_PRESENT = 'stackless_edd_custom_cart_text_fields';
    private const HANDLE = 'stackless-edd-custom_cart_text';

    /** @var bool */
    private $scriptQueued = false;

    public function id(): string
    {
        return 'custom_cart_text';
    }

    public function title(): string
    {
        return __('Custom Add to Cart Text', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Rename the purchase button per download type, for free and subscription downloads, and per download or price option.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return [
            'simple' => __('Single-price downloads', 'stackless-lite-edd'),
            'variable' => __('Variable-price downloads', 'stackless-lite-edd'),
            'bundle' => __('Bundles', 'stackless-lite-edd'),
            'subscription' => __('Subscription downloads', 'stackless-lite-edd'),
            'free' => __('Free downloads', 'stackless-lite-edd'),
        ];
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_custom_cart_text_';
        $placeholder = $this->eddLabel();
        $fields = [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Button text', 'stackless-lite-edd'), 'desc' => __('Leave a box blank to keep EDD\'s text (Downloads → Settings → Misc → Purchase Buttons). Text set on a download (Download Details → Settings) wins over these. Free and subscription win over the download type. Buy Now buttons keep EDD\'s Buy Now text unless the download sets its own.', 'stackless-lite-edd')],
        ];
        foreach ($this->types() as $key => $label) {
            $fields[] = ['type' => 'text', 'id' => $p . $key . '_loop', 'title' => $label, 'desc' => __('Download grids and blocks', 'stackless-lite-edd'), 'placeholder' => $placeholder, 'css' => 'min-width:260px'];
            $fields[] = ['type' => 'text', 'id' => $p . $key . '_single', 'title' => '', 'desc' => __('Download page', 'stackless-lite-edd'), 'placeholder' => $placeholder, 'css' => 'min-width:260px'];
        }
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'section'];
        return $fields;
    }

    /** EDD's own add to cart label, used as the placeholder. */
    private function eddLabel(): string
    {
        $label = function_exists('edd_get_option') ? (string) edd_get_option('add_to_cart_text', '') : '';
        return $label !== '' ? $label : __('Purchase', 'stackless-lite-edd');
    }

    public function boot(): void
    {
        add_filter('edd_purchase_link_args', [$this, 'filterPurchaseLinkArgs'], 60, 2);
        add_action('edd_purchase_link_end', [$this, 'printPriceLabels'], 20, 2);

        add_action('edd_meta_box_settings_fields', [$this, 'renderDownloadFields'], 35, 2);
        add_action('edd_download_price_option_row', [$this, 'renderPriceField'], 30, 3);
        add_action('edd_save_download', [$this, 'saveDownloadFields'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Resolution
     * ------------------------------------------------------------------- */

    /**
     * Resolved label for a download in "loop" or "single" context; $default is EDD's own.
     *
     * @param int|null $priceId A specific price option, or null for the download as a whole.
     * @param bool     $direct  A Buy Now button: only text set on the download applies.
     */
    public function text(int $downloadId, ?int $priceId, string $context, string $default, bool $direct = false): string
    {
        $context = $context === 'single' ? 'single' : 'loop';
        $candidates = [];
        if ($context === 'single' && $priceId !== null) {
            $candidates[] = $this->priceText($downloadId, $priceId);
        }
        $candidates[] = (string) get_post_meta($downloadId, $context === 'single' ? self::META_SINGLE : self::META_LOOP, true);

        if (!$direct) {
            $variable = edd_has_variable_prices($downloadId);
            $free = $variable ? ($priceId !== null && edd_is_free_download($downloadId, $priceId)) : edd_is_free_download($downloadId);
            $download = edd_get_download($downloadId);
            $type = $download && $download->get_type() === 'bundle' ? 'bundle' : ($variable ? 'variable' : 'simple');
            foreach (TextResolver::keys($type, $free, stackless_edd_is_subscription_product($downloadId, $priceId)) as $key) {
                $candidates[] = (string) $this->option($key . '_' . $context, '');
            }
        }
        $text = TextResolver::resolve($candidates, $default);
        /**
         * Filters the purchase button label.
         *
         * @param string   $text
         * @param int      $download_id
         * @param string   $context loop|single
         * @param int|null $price_id
         */
        return (string) apply_filters('stackless_edd_custom_cart_text_text', $text, $downloadId, $context, $priceId);
    }

    private function priceText(int $downloadId, int $priceId): string
    {
        $labels = get_post_meta($downloadId, self::META_PRICES, true);
        return is_array($labels) && isset($labels[$priceId]) && is_string($labels[$priceId]) ? $labels[$priceId] : '';
    }

    /** "single" for the purchase button of the download page being viewed, "loop" everywhere else. */
    private function context(int $downloadId): string
    {
        return is_singular('download') && $downloadId === (int) get_queried_object_id() ? 'single' : 'loop';
    }

    /**
     * Whether EDD put a price on this button (mirrors edd_get_purchase_link()).
     *
     * @param array<string,mixed> $args
     */
    private function buttonHasPrice(int $downloadId, array $args): bool
    {
        if (empty($args['price']) || $args['price'] === 'no') {
            return false;
        }
        if (!edd_has_variable_prices($downloadId)) {
            return true;
        }
        $priceId = $args['price_id'] ?? false;
        if ($priceId === false || $priceId === '') {
            return false;
        }
        $prices = edd_get_variable_prices($downloadId);
        return is_array($prices) && isset($prices[$priceId]);
    }

    /**
     * @param array<string,mixed> $args
     */
    private function argsPriceId(array $args): ?int
    {
        $priceId = $args['price_id'] ?? false;
        return is_numeric($priceId) ? (int) $priceId : null;
    }

    /* ---------------------------------------------------------------------
     * Purchase buttons
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $args
     * @param mixed $download
     * @return mixed
     */
    public function filterPurchaseLinkArgs($args, $download = null)
    {
        if (!is_array($args) || !isset($args['text']) || !is_string($args['text'])) {
            return $args;
        }
        $downloadId = isset($args['download_id']) && is_numeric($args['download_id']) ? (int) $args['download_id'] : 0;
        if ($downloadId < 1) {
            return $args;
        }
        $hasPrice = $this->buttonHasPrice($downloadId, $args);
        [$price, $label] = TextResolver::split($args['text'], $hasPrice);
        // EDD's own label, for the per-option labels printed at edd_purchase_link_end.
        $args['stackless_edd_cart_label'] = $label;
        $text = $this->text($downloadId, $this->argsPriceId($args), $this->context($downloadId), $label, !empty($args['direct']));
        if ($text !== $label) {
            $args['text'] = TextResolver::join($price, esc_html($text));
        }
        return $args;
    }

    /**
     * On a download page with a price option list, hand the per-option labels to the script that
     * swaps the button text when the shopper picks another option.
     *
     * @param mixed $downloadId
     * @param mixed $args
     */
    public function printPriceLabels($downloadId = 0, $args = []): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $args = is_array($args) ? $args : [];
        if ($downloadId < 1 || !empty($args['direct']) || $this->context($downloadId) !== 'single'
            || !edd_has_variable_prices($downloadId) || edd_single_price_option_mode($downloadId) || $this->argsPriceId($args) !== null) {
            return;
        }
        $original = isset($args['stackless_edd_cart_label']) && is_string($args['stackless_edd_cart_label']) ? $args['stackless_edd_cart_label'] : $this->eddLabel();
        $original = html_entity_decode($original, ENT_QUOTES, 'UTF-8');
        $default = $this->text($downloadId, null, 'single', $original);
        $prices = edd_get_variable_prices($downloadId);
        $labels = [];
        $differs = false;
        foreach (is_array($prices) ? $prices : [] as $priceId => $price) {
            if (!is_numeric($priceId)) {
                continue;
            }
            $label = $this->text($downloadId, (int) $priceId, 'single', $original);
            $labels[(string) $priceId] = $label;
            $differs = $differs || $label !== $default;
        }
        if (!$differs) {
            return;
        }
        printf(
            '<span class="stackless-cart-text-data" hidden data-download="%1$s" data-labels="%2$s" data-default="%3$s"></span>',
            esc_attr((string) $downloadId),
            esc_attr((string) wp_json_encode($labels)),
            esc_attr($default)
        );
        $this->queueScript();
    }

    private function queueScript(): void
    {
        if ($this->scriptQueued) {
            return;
        }
        $this->scriptQueued = true;
        wp_enqueue_script(self::HANDLE . '-prices', STACKLESS_EDD_PLUGIN_URL . 'assets/custom_cart_text/prices.js', ['jquery'], STACKLESS_EDD_VERSION, true);
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $downloadId
     * @param mixed $download
     */
    public function renderDownloadFields($downloadId = 0, $download = null): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $fields = [
            self::FIELD_LOOP => [
                'meta' => self::META_LOOP,
                'label' => __('Button text in grids', 'stackless-lite-edd'),
                'help' => __('Purchase button text in download grids and blocks. Leave blank to use the store setting.', 'stackless-lite-edd'),
            ],
            self::FIELD_SINGLE => [
                'meta' => self::META_SINGLE,
                'label' => __('Button text on download page', 'stackless-lite-edd'),
                'help' => __('Purchase button text on this download\'s page. Price options can override it.', 'stackless-lite-edd'),
            ],
        ];
        echo '<input type="hidden" name="' . esc_attr(self::FIELD_PRESENT) . '" value="1">';
        foreach ($fields as $name => $field) {
            $value = $downloadId > 0 ? (string) get_post_meta($downloadId, $field['meta'], true) : '';
            ?>
            <div class="edd-form-group stackless-custom-cart-text-field">
                <label for="<?php echo esc_attr($name); ?>" class="edd-form-group__label"><?php echo esc_html($field['label']); ?></label>
                <div class="edd-form-group__control">
                    <input type="text" class="edd-form-group__input regular-text" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" maxlength="60" placeholder="<?php esc_attr_e('Store setting', 'stackless-lite-edd'); ?>">
                </div>
                <p class="description edd-form-group__help"><?php echo esc_html($field['help']); ?></p>
            </div>
            <?php
        }
    }

    /**
     * @param mixed $downloadId
     * @param mixed $priceId
     * @param mixed $args
     */
    public function renderPriceField($downloadId = 0, $priceId = 0, $args = []): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $priceId = is_numeric($priceId) ? (int) $priceId : 0;
        $value = $downloadId > 0 ? $this->priceText($downloadId, $priceId) : '';
        $id = self::FIELD_PRICES . '-' . $priceId;
        ?>
        <div class="edd-form-group stackless-custom-cart-text-price">
            <label for="<?php echo esc_attr($id); ?>" class="edd-form-group__label"><?php esc_html_e('Button text', 'stackless-lite-edd'); ?></label>
            <div class="edd-form-group__control">
                <input type="text" class="edd-form-group__input regular-text" name="<?php echo esc_attr(self::FIELD_PRICES . '[' . $priceId . ']'); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" maxlength="60" placeholder="<?php esc_attr_e('Download setting', 'stackless-lite-edd'); ?>">
            </div>
            <p class="description edd-form-group__help"><?php esc_html_e('Shown on the download page when this price option is selected.', 'stackless-lite-edd'); ?></p>
        </div>
        <?php
    }

    /**
     * Runs after EDD verified the download editor nonce.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadFields($postId = 0, $post = null): void
    {
        $postId = is_numeric($postId) ? (int) $postId : 0;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD checked the download editor nonce before edd_save_download.
        if ($postId < 1 || !current_user_can('edit_product', $postId) || empty($_POST[self::FIELD_PRESENT])) {
            return;
        }
        foreach ([self::FIELD_LOOP => self::META_LOOP, self::FIELD_SINGLE => self::META_SINGLE] as $field => $meta) {
            $value = isset($_POST[$field]) && is_string($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
            if ($value === '') {
                delete_post_meta($postId, $meta);
            } else {
                update_post_meta($postId, $meta, mb_substr($value, 0, 60));
            }
        }

        $labels = [];
        $posted = isset($_POST[self::FIELD_PRICES]) && is_array($_POST[self::FIELD_PRICES]) ? wp_unslash($_POST[self::FIELD_PRICES]) : [];
        // phpcs:enable
        foreach ((array) $posted as $priceId => $value) {
            if (!is_numeric($priceId) || !is_string($value)) {
                continue;
            }
            $value = sanitize_text_field($value);
            if ($value !== '') {
                $labels[(int) $priceId] = mb_substr($value, 0, 60);
            }
        }
        if ($labels) {
            update_post_meta($postId, self::META_PRICES, $labels);
        } else {
            delete_post_meta($postId, self::META_PRICES);
        }
    }
}
