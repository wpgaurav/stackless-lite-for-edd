<?php

namespace StacklessEDD\Modules\ExternalProduct;

defined('ABSPATH') || exit;

/**
 * External Downloads: a download sold on another website. Its purchase form becomes a real link
 * to the external buy URL, with rel (nofollow, sponsored, noopener) and target controls, optional
 * price hiding and outbound click counts. A hard server-side guard keeps external downloads out
 * of the cart: EDD's purchase check, purchase forms, AJAX, /edd-add/ links, EDD's cart REST
 * routes and checkout all refuse them.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_ENABLED = '_stackless_edd_external_product';
    public const META_URL = '_stackless_edd_external_product_url';
    public const META_BUTTON = '_stackless_edd_external_product_button_text';
    public const META_NOFOLLOW = '_stackless_edd_external_product_nofollow';
    public const META_SPONSORED = '_stackless_edd_external_product_sponsored';
    public const META_TARGET = '_stackless_edd_external_product_target';
    public const META_HIDE_PRICE = '_stackless_edd_external_product_hide_price';
    public const FIELD_RESET = 'stackless_edd_external_product_reset_clicks';
    /** Set by the editor fields, so a save from anywhere else never clears the settings. */
    public const FIELD_PRESENT = 'stackless_edd_external_product_fields';
    private const HANDLE = 'stackless-edd-external_product';
    private const COLUMN = 'stackless_edd_external_clicks';

    /** @var bool */
    private $scriptQueued = false;

    public function id(): string
    {
        return 'external_product';
    }

    public function title(): string
    {
        return __('External Downloads', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Sell a download on another website: an external buy link replaces the purchase button, with rel and target controls, optional price hiding and outbound click counts. External downloads can never be added to the cart.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function install(): void
    {
        Clicks::install();
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_external_product_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('External download links', 'stackless-lite-edd'), 'desc' => __('Store defaults for every external download. Turn a download external, and override these, in its Settings section.', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'button_text', 'title' => __('Button text', 'stackless-lite-edd'), 'default' => __('Buy product', 'stackless-lite-edd'), 'desc' => __('Used when a download has no button text of its own.', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'checkbox', 'id' => $p . 'nofollow', 'title' => __('Link attributes', 'stackless-lite-edd'), 'desc' => __('Add rel="nofollow"', 'stackless-lite-edd'), 'default' => 'yes', 'checkboxgroup' => 'start'],
            ['type' => 'checkbox', 'id' => $p . 'sponsored', 'desc' => __('Add rel="sponsored" (recommended for paid and affiliate links)', 'stackless-lite-edd'), 'default' => 'yes', 'checkboxgroup' => 'end'],
            ['type' => 'select', 'id' => $p . 'target', 'title' => __('Open links', 'stackless-lite-edd'), 'default' => '_blank', 'options' => [
                '_blank' => __('In a new tab (adds rel="noopener")', 'stackless-lite-edd'),
                '_self' => __('In the same tab', 'stackless-lite-edd'),
            ]],
            ['type' => 'checkbox', 'id' => $p . 'hide_price', 'title' => __('Prices', 'stackless-lite-edd'), 'desc' => __('Hide prices on external downloads (useful when the seller\'s price changes often)', 'stackless-lite-edd'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => $p . 'tracking', 'title' => __('Click tracking', 'stackless-lite-edd'), 'desc' => __('Count clicks on external download buttons and show them in the downloads list', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        // Cart guard.
        add_filter('edd_can_purchase_download', [$this, 'filterCanPurchase'], PHP_INT_MAX, 2);
        add_filter('edd_add_to_cart_item', [$this, 'filterAddToCartItem'], 1, 1);
        add_action('edd_add_to_cart', [$this, 'noticeOnAddToCart'], 1, 1);
        add_action('edd_checkout_error_checks', [$this, 'validateCheckout'], 1, 2);

        // Links: the purchase form becomes the external button (Hide Price Until Login runs at 110, Wishlist appends at 120).
        add_filter('edd_purchase_download_form', [$this, 'filterPurchaseForm'], 100, 2);

        // Prices.
        add_filter('edd_download_price_after_html', [$this, 'filterPriceHtml'], 100, 4);
        add_filter('edd_price_range', [$this, 'filterPriceRange'], 100, 4);
        add_filter('edd_generate_download_structured_data', [$this, 'filterStructuredData'], 100, 2);

        // Tracking.
        if ($this->trackingEnabled()) {
            add_action('rest_api_init', [$this, 'registerRoutes']);
            add_filter('manage_edit-download_columns', [$this, 'addColumn'], 20);
            add_action('manage_download_posts_custom_column', [$this, 'renderColumn'], 10, 2);
            add_action('admin_head-edit.php', [$this, 'columnStyle']);
        }

        // Download editor.
        add_action('edd_meta_box_settings_fields', [$this, 'renderDownloadFields'], 5, 2);
        add_action('edd_save_download', [$this, 'saveDownloadFields'], 10, 2);
    }

    private function trackingEnabled(): bool
    {
        return $this->option('tracking', 'yes') === 'yes';
    }

    /** @param mixed $downloadId */
    public function isExternal($downloadId): bool
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        return $downloadId > 0 && get_post_meta($downloadId, self::META_ENABLED, true) === 'yes';
    }

    /* ---------------------------------------------------------------------
     * Resolved per-download settings
     * ------------------------------------------------------------------- */

    public function url(int $downloadId): string
    {
        return (string) get_post_meta($downloadId, self::META_URL, true);
    }

    public function buttonText(int $downloadId): string
    {
        $text = trim((string) get_post_meta($downloadId, self::META_BUTTON, true));
        if ($text === '') {
            $text = trim((string) $this->option('button_text', __('Buy product', 'stackless-lite-edd')));
        }
        return $text !== '' ? $text : __('Buy product', 'stackless-lite-edd');
    }

    public function target(int $downloadId): string
    {
        return Link::target((string) get_post_meta($downloadId, self::META_TARGET, true), (string) $this->option('target', '_blank'));
    }

    public function rel(int $downloadId): string
    {
        return Link::rel(
            Link::flag((string) get_post_meta($downloadId, self::META_NOFOLLOW, true), $this->option('nofollow', 'yes') === 'yes'),
            Link::flag((string) get_post_meta($downloadId, self::META_SPONSORED, true), $this->option('sponsored', 'yes') === 'yes'),
            $this->target($downloadId)
        );
    }

    public function hidesPrice(int $downloadId): bool
    {
        return Link::flag((string) get_post_meta($downloadId, self::META_HIDE_PRICE, true), $this->option('hide_price', 'no') === 'yes');
    }

    /* ---------------------------------------------------------------------
     * Cart guard
     * ------------------------------------------------------------------- */

    /**
     * EDD drops downloads that fail this check from carts, REST cart routes and add to cart.
     *
     * @param mixed $canPurchase
     * @param mixed $download
     * @return mixed
     */
    public function filterCanPurchase($canPurchase, $download = null)
    {
        return $download instanceof \EDD_Download && $this->isExternal((int) $download->ID) ? false : $canPurchase;
    }

    /**
     * Keeps external downloads out even if another plugin flips EDD's purchase check back on.
     *
     * @param mixed $item
     * @return mixed
     */
    public function filterAddToCartItem($item)
    {
        return is_array($item) && $this->isExternal($item['id'] ?? 0) ? false : $item;
    }

    /**
     * EDD skips downloads it may not sell without a word; tell the shopper why.
     *
     * @param mixed $data
     */
    public function noticeOnAddToCart($data = []): void
    {
        $downloadId = is_array($data) && isset($data['download_id']) ? absint($data['download_id']) : 0;
        if ($this->isExternal($downloadId)) {
            stackless_edd_add_notice(esc_html__('This download is sold on another website and cannot be added to the cart.', 'stackless-lite-edd'), 'error');
        }
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validateCheckout($validData = [], $post = []): void
    {
        $contents = function_exists('EDD') && EDD()->cart && is_array(EDD()->cart->contents) ? EDD()->cart->contents : [];
        foreach ($contents as $item) {
            if (is_array($item) && $this->isExternal($item['id'] ?? 0)) {
                // EDD documents the error id as int; it is a string key.
                edd_set_error('stackless_edd_external_product', esc_html__('An external download is in your cart. It can only be bought on the seller\'s website; remove it to continue.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
                return;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------- */

    /**
     * Every purchase form EDD prints (download pages, [downloads] grids, the downloads and buy
     * button blocks) goes through edd_get_purchase_link(), so this one filter covers them all.
     *
     * @param mixed $html
     * @param mixed $args
     * @return mixed
     */
    public function filterPurchaseForm($html, $args = [])
    {
        $args = is_array($args) ? $args : [];
        $downloadId = isset($args['download_id']) ? (int) $args['download_id'] : 0;
        if (!$this->isExternal($downloadId)) {
            return $html;
        }
        $url = $this->url($downloadId);
        if (!Link::validUrl($url)) {
            return '';
        }
        $text = esc_html($this->buttonText($downloadId));
        $showPrice = !empty($args['price']) && $args['price'] !== 'no' && !$this->hidesPrice($downloadId);
        $price = $showPrice ? Link::pricePart(isset($args['text']) && is_string($args['text']) ? $args['text'] : '') : '';
        if ($price !== '') {
            $text = wp_kses_post($price) . Link::SEPARATOR . $text;
        }
        $classes = array_filter([
            isset($args['style']) && is_string($args['style']) ? $args['style'] : 'button',
            isset($args['color']) && is_string($args['color']) ? $args['color'] : '',
            isset($args['class']) && is_string($args['class']) ? trim($args['class']) : 'edd-submit',
            'stackless-external-product__button',
        ]);
        $rel = $this->rel($downloadId);
        $tracking = $this->trackingEnabled();
        if ($tracking) {
            $this->queueScript();
        }
        return sprintf(
            '<div class="edd_download_purchase_form stackless-external-product"><div class="edd_purchase_submit_wrapper"><a href="%1$s" class="%2$s" target="%3$s"%4$s%5$s>%6$s</a></div></div>',
            esc_url($url),
            esc_attr(implode(' ', $classes)),
            esc_attr($this->target($downloadId)),
            $rel !== '' ? ' rel="' . esc_attr($rel) . '"' : '',
            $tracking ? ' data-stackless-external="' . esc_attr((string) $downloadId) . '"' : '',
            $text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        );
    }

    private function queueScript(): void
    {
        if ($this->scriptQueued) {
            return;
        }
        $this->scriptQueued = true;
        wp_enqueue_script(self::HANDLE . '-tracking', STACKLESS_EDD_PLUGIN_URL . 'assets/external_product/tracking.js', [], STACKLESS_EDD_VERSION, true);
        wp_add_inline_script(self::HANDLE . '-tracking', 'window.stacklessEddExternalProduct=' . wp_json_encode(['endpoint' => esc_url_raw(rest_url('stackless-edd/v1/external-click/'))]) . ';', 'before');
    }

    /* ---------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $html
     * @param mixed $downloadId
     * @param mixed $price
     * @param mixed $priceId
     * @return mixed
     */
    public function filterPriceHtml($html, $downloadId = 0, $price = '', $priceId = false)
    {
        return $this->isExternal($downloadId) && $this->hidesPrice((int) $downloadId) ? '' : $html;
    }

    /**
     * @param mixed $range
     * @param mixed $downloadId
     * @param mixed $low
     * @param mixed $high
     * @return mixed
     */
    public function filterPriceRange($range, $downloadId = 0, $low = 0, $high = 0)
    {
        return $this->isExternal($downloadId) && $this->hidesPrice((int) $downloadId) ? '' : $range;
    }

    /**
     * @param mixed $data
     * @param mixed $download
     * @return mixed
     */
    public function filterStructuredData($data, $download = null)
    {
        $downloadId = $download instanceof \EDD_Download ? (int) $download->ID : 0;
        if (is_array($data) && $this->isExternal($downloadId) && $this->hidesPrice($downloadId)) {
            unset($data['offers']);
        }
        return $data;
    }

    /* ---------------------------------------------------------------------
     * Click tracking
     * ------------------------------------------------------------------- */

    public function registerRoutes(): void
    {
        register_rest_route('stackless-edd/v1', '/external-click/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'restClick'],
            'permission_callback' => [$this, 'restClickPermission'],
            'args' => [
                'id' => ['required' => true, 'validate_callback' => static function ($value) {
                    return is_numeric($value) && (int) $value > 0;
                }],
            ],
        ]);
    }

    /**
     * Only published external downloads can be counted.
     *
     * @return bool|\WP_Error
     */
    public function restClickPermission(\WP_REST_Request $request)
    {
        $downloadId = (int) $request->get_param('id');
        if (get_post_type($downloadId) !== 'download' || get_post_status($downloadId) !== 'publish' || !$this->isExternal($downloadId)) {
            return new \WP_Error('stackless_edd_external_product_not_found', __('Download not found.', 'stackless-lite-edd'), ['status' => 404]);
        }
        return true;
    }

    public function restClick(\WP_REST_Request $request): \WP_REST_Response
    {
        $downloadId = (int) $request->get_param('id');
        // One click per visitor per download every 10 minutes keeps refreshes and bots from inflating counts.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        $key = 'stackless_edd_xclk_' . substr(hash_hmac('sha256', $ip . '|' . $downloadId, wp_salt('nonce')), 0, 32);
        if (get_transient($key)) {
            return new \WP_REST_Response(['counted' => false], 200);
        }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        Clicks::increment($downloadId);
        return new \WP_REST_Response(['counted' => true], 200);
    }

    /**
     * @param mixed $columns
     * @return mixed
     */
    public function addColumn($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'price') {
                $out[self::COLUMN] = __('Outbound clicks', 'stackless-lite-edd');
            }
        }
        if (!isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('Outbound clicks', 'stackless-lite-edd');
        }
        return $out;
    }

    /** Give the column a width so it does not collapse next to EDD's stats columns. */
    public function columnStyle(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'edit-download') {
            echo '<style>.wp-list-table .column-' . esc_attr(self::COLUMN) . '{width:7em}</style>';
        }
    }

    /**
     * @param mixed $column
     * @param mixed $postId
     */
    public function renderColumn($column, $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        static $counts = null;
        if ($counts === null) {
            global $wp_query;
            $ids = [];
            if ($wp_query instanceof \WP_Query) {
                foreach ((array) $wp_query->posts as $post) {
                    $ids[] = is_object($post) ? (int) $post->ID : (int) $post;
                }
            }
            $counts = Clicks::many($ids);
        }
        if (!$this->isExternal($postId)) {
            echo '<span aria-hidden="true">&ndash;</span>';
            return;
        }
        echo esc_html(number_format_i18n($counts[(int) $postId] ?? 0));
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    /**
     * @param string               $name
     * @param string               $value
     * @param array<string,string> $options
     */
    private function selectField(string $name, string $label, string $value, array $options): void
    {
        echo '<div class="edd-form-group" data-edd-requires-stackless-external-product="true">';
        echo '<label for="' . esc_attr($name) . '" class="edd-form-group__label">' . esc_html($label) . '</label>';
        echo '<div class="edd-form-group__control"><select class="edd-form-group__input" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $key => $optionLabel) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($optionLabel));
        }
        echo '</select></div></div>';
    }

    /**
     * @param mixed $downloadId
     * @param mixed $download
     */
    public function renderDownloadFields($downloadId = 0, $download = null): void
    {
        $downloadId = (int) $downloadId;
        if (!current_user_can('edit_product', $downloadId)) {
            return;
        }
        $enabled = $this->isExternal($downloadId);
        $inherit = __('Store default', 'stackless-lite-edd');
        $hidden = $enabled ? '' : ' edd-hidden';

        echo '<div class="edd-form-group">';
        echo '<input type="hidden" name="' . esc_attr(self::FIELD_PRESENT) . '" value="1">';
        echo '<div class="edd-form-group__control edd-toggle">';
        printf(
            '<input type="checkbox" class="edd-form-group__input edd-requirement" name="%1$s" id="%1$s" value="1" data-edd-requirement="stackless-external-product"%2$s>',
            esc_attr(self::META_ENABLED),
            checked($enabled, true, false)
        );
        echo '<label for="' . esc_attr(self::META_ENABLED) . '">' . esc_html__('Sold on another website (external download)', 'stackless-lite-edd') . '</label>';
        echo '</div>';
        echo '<p class="description edd-form-group__help">' . esc_html__('The purchase button links to the URL below and the download can never be added to the cart.', 'stackless-lite-edd') . '</p>';
        echo '</div>';

        echo '<div class="edd-form-group' . esc_attr($hidden) . '" data-edd-requires-stackless-external-product="true">';
        echo '<label for="' . esc_attr(self::META_URL) . '" class="edd-form-group__label">' . esc_html__('External buy URL', 'stackless-lite-edd') . '</label>';
        printf('<div class="edd-form-group__control"><input type="url" class="edd-form-group__input regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="https://"></div>', esc_attr(self::META_URL), esc_attr($this->url($downloadId)));
        echo '</div>';

        echo '<div class="edd-form-group' . esc_attr($hidden) . '" data-edd-requires-stackless-external-product="true">';
        echo '<label for="' . esc_attr(self::META_BUTTON) . '" class="edd-form-group__label">' . esc_html__('Button text', 'stackless-lite-edd') . '</label>';
        printf(
            '<div class="edd-form-group__control"><input type="text" class="edd-form-group__input regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s"></div>',
            esc_attr(self::META_BUTTON),
            esc_attr((string) get_post_meta($downloadId, self::META_BUTTON, true)),
            esc_attr((string) $this->option('button_text', __('Buy product', 'stackless-lite-edd')))
        );
        echo '</div>';

        ob_start();
        $this->selectField(self::META_NOFOLLOW, __('rel="nofollow"', 'stackless-lite-edd'), (string) get_post_meta($downloadId, self::META_NOFOLLOW, true), ['' => $inherit, 'yes' => __('Add', 'stackless-lite-edd'), 'no' => __('Do not add', 'stackless-lite-edd')]);
        $this->selectField(self::META_SPONSORED, __('rel="sponsored"', 'stackless-lite-edd'), (string) get_post_meta($downloadId, self::META_SPONSORED, true), ['' => $inherit, 'yes' => __('Add', 'stackless-lite-edd'), 'no' => __('Do not add', 'stackless-lite-edd')]);
        $this->selectField(self::META_TARGET, __('Open link', 'stackless-lite-edd'), (string) get_post_meta($downloadId, self::META_TARGET, true), ['' => $inherit, '_blank' => __('In a new tab', 'stackless-lite-edd'), '_self' => __('In the same tab', 'stackless-lite-edd')]);
        $this->selectField(self::META_HIDE_PRICE, __('Price display', 'stackless-lite-edd'), (string) get_post_meta($downloadId, self::META_HIDE_PRICE, true), ['' => $inherit, 'yes' => __('Hide price', 'stackless-lite-edd'), 'no' => __('Show price', 'stackless-lite-edd')]);
        $selects = (string) ob_get_clean();
        // Hidden until the toggle is on (EDD's editor script keeps them in sync afterwards).
        echo $enabled ? $selects : str_replace('<div class="edd-form-group" data-edd-requires', '<div class="edd-form-group edd-hidden" data-edd-requires', $selects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in selectField().

        if ($this->trackingEnabled() && $downloadId > 0 && $enabled) {
            $stats = Clicks::get($downloadId);
            $last = $stats['last'] !== '' ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($stats['last'] . ' UTC')) : '';
            echo '<div class="edd-form-group" data-edd-requires-stackless-external-product="true">';
            echo '<span class="edd-form-group__label">' . esc_html__('Outbound clicks', 'stackless-lite-edd') . '</span>';
            echo '<div class="edd-form-group__control"><strong>' . esc_html(number_format_i18n($stats['clicks'])) . '</strong>';
            if ($last) {
                /* translators: %s: date and time of the last click */
                echo ' <span class="description">' . esc_html(sprintf(__('Last click %s', 'stackless-lite-edd'), $last)) . '</span>';
            }
            echo '</div>';
            if ($stats['clicks'] > 0) {
                printf(
                    '<div class="edd-form-group__control"><label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="yes"> %2$s</label></div>',
                    esc_attr(self::FIELD_RESET),
                    esc_html__('Set the click count back to zero when you update the download.', 'stackless-lite-edd')
                );
            }
            echo '</div>';
        }
    }

    /**
     * EDD verified its download editor nonce before edd_save_download.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadFields($postId, $post = null): void
    {
        $postId = (int) $postId;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD checked edd_download_meta_box_nonce.
        if ($postId <= 0 || !current_user_can('edit_product', $postId) || empty($_POST[self::FIELD_PRESENT])) {
            return;
        }
        if (!empty($_POST[self::META_ENABLED])) {
            update_post_meta($postId, self::META_ENABLED, 'yes');
        } else {
            delete_post_meta($postId, self::META_ENABLED);
        }

        $url = isset($_POST[self::META_URL]) ? esc_url_raw(trim(wp_unslash((string) $_POST[self::META_URL])), ['http', 'https']) : '';
        if ($url !== '' && Link::validUrl($url)) {
            update_post_meta($postId, self::META_URL, $url);
        } else {
            delete_post_meta($postId, self::META_URL);
        }

        $button = isset($_POST[self::META_BUTTON]) ? sanitize_text_field(wp_unslash((string) $_POST[self::META_BUTTON])) : '';
        if ($button !== '') {
            update_post_meta($postId, self::META_BUTTON, mb_substr($button, 0, 80));
        } else {
            delete_post_meta($postId, self::META_BUTTON);
        }

        $allowed = [
            self::META_NOFOLLOW => ['yes', 'no'],
            self::META_SPONSORED => ['yes', 'no'],
            self::META_TARGET => ['_blank', '_self'],
            self::META_HIDE_PRICE => ['yes', 'no'],
        ];
        foreach ($allowed as $key => $values) {
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
            if (in_array($value, $values, true)) {
                update_post_meta($postId, $key, $value);
            } else {
                delete_post_meta($postId, $key);
            }
        }
        if (!empty($_POST[self::FIELD_RESET])) {
            Clicks::reset($postId);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }
}
