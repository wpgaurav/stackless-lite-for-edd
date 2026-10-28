<?php

namespace StacklessEDD\Modules\HidePriceUntilLogin;

defined('ABSPATH') || exit;

/**
 * Hide Price Until Login: guests see "Log in to see prices" and cannot buy.
 *
 * Applies to every download, chosen categories, or downloads opted in one by one. Hidden
 * downloads cannot be purchased by guests (EDD's own purchase check), so purchase forms, AJAX,
 * /edd-add/ links, EDD's cart REST routes and checkout all refuse them, and EDD drops them
 * from a guest's cart. Prices are removed from price HTML, price ranges, the downloads block,
 * EDD's product API and structured data, and the purchase form becomes a login button.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META = '_stackless_edd_hide_price_until_login';
    private const HANDLE = 'stackless-edd-hide_price_until_login';

    /** @var array<int, bool> viewer result per user id, so a user switch mid-request (login, wp_set_current_user) is honoured */
    private $viewerSees = [];

    /** @var int[]|null */
    private $hiddenCategories;

    /** @var array<int, bool> */
    private $cache = [];

    /** @var bool */
    private $styled = false;

    /** @var int depth of edd/downloads blocks being rendered */
    private $blockDepth = 0;

    public function id(): string
    {
        return 'hide_price_until_login';
    }

    public function title(): string
    {
        return __('Hide Price Until Login', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Hide prices and buying from guests, store-wide, by category or per download, with a login link in place of the price.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_hide_price_until_login_';
        $categories = [];
        $terms = get_terms(['taxonomy' => 'download_category', 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term) {
                    $categories[(string) $term->term_id] = $term->name;
                }
            }
        }
        $roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Who and what', 'stackless-lite-edd'), 'desc' => __('Guests never see hidden prices and cannot add those downloads to the cart. Each download can also be set to always or never hide in its Settings section.', 'stackless-lite-edd')],
            ['type' => 'select', 'id' => $p . 'scope', 'title' => __('Hide prices on', 'stackless-lite-edd'), 'default' => Scope::ALL, 'options' => [
                Scope::ALL => __('All downloads', 'stackless-lite-edd'),
                Scope::CATEGORIES => __('Downloads in selected categories', 'stackless-lite-edd'),
                Scope::SELECTED => __('Only downloads set to hide', 'stackless-lite-edd'),
            ]],
            ['type' => 'multiselect', 'id' => $p . 'categories', 'title' => __('Categories', 'stackless-lite-edd'), 'default' => [], 'options' => $categories, 'desc' => __('Used when hiding by category. Subcategories are included.', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'multiselect', 'id' => $p . 'roles', 'title' => __('Roles that see prices', 'stackless-lite-edd'), 'default' => [], 'options' => $roles, 'desc' => __('Leave empty to show prices to every logged-in customer.', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'sectionend', 'id' => $p . 'section'],

            ['type' => 'title', 'id' => $p . 'display', 'title' => __('What guests see', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Price text', 'stackless-lite-edd'), 'default' => __('Log in to see prices', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'checkbox', 'id' => $p . 'link', 'title' => __('Login link', 'stackless-lite-edd'), 'desc' => __('Link the price text to the login page and return the shopper to the download afterwards', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p . 'login_page', 'title' => __('Login page', 'stackless-lite-edd'), 'default' => 'account', 'options' => [
                'account' => __('Stackless account page', 'stackless-lite-edd'),
                'wp-login' => __('WordPress login screen (or EDD\'s login page when one is set)', 'stackless-lite-edd'),
            ]],
            ['type' => 'text', 'id' => $p . 'button_text', 'title' => __('Button text', 'stackless-lite-edd'), 'default' => __('Log in to buy', 'stackless-lite-edd'), 'desc' => __('Replaces the purchase button on hidden downloads. Leave blank to remove the button.', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'sectionend', 'id' => $p . 'display'],
        ];
    }

    public function boot(): void
    {
        // Prices.
        add_filter('edd_download_price_after_html', [$this, 'filterPriceHtml'], 9999, 4);
        add_filter('edd_price_range', [$this, 'filterPriceRange'], 9999, 4);
        add_filter('edd_price_option_output', [$this, 'filterPriceOption'], 9999, 4);
        add_filter('edd_generate_download_structured_data', [$this, 'filterStructuredData'], 9999, 2);
        add_filter('edd_api_products_product', [$this, 'filterApiProduct'], 9999, 1);
        add_filter('render_block_data', [$this, 'enterDownloadsBlock'], 10, 1);
        add_filter('render_block_edd/downloads', [$this, 'leaveDownloadsBlock'], 10, 1);

        // Purchasing.
        add_filter('edd_can_purchase_download', [$this, 'filterCanPurchase'], 9999, 2);
        add_filter('edd_add_to_cart_item', [$this, 'filterAddToCartItem'], 5, 1);
        add_action('edd_add_to_cart', [$this, 'noticeOnAddToCart'], 1, 1);
        add_action('edd_checkout_error_checks', [$this, 'validateCheckout'], 10, 2);

        // Buttons: after External Downloads (100), before Wishlist (120).
        add_filter('edd_purchase_download_form', [$this, 'filterPurchaseForm'], 110, 2);

        // Login round trip.
        add_action('edd_login_fields_before', [$this, 'loginRedirectField']);
        add_shortcode('stackless_edd_login_to_see_price', [$this, 'shortcode']);

        // Download editor.
        add_action('edd_meta_box_settings_fields', [$this, 'renderDownloadField'], 40, 2);
        add_action('edd_save_download', [$this, 'saveDownloadField'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Rules
     * ------------------------------------------------------------------- */

    public function viewerSeesPrices(): bool
    {
        $userId = get_current_user_id();
        if (!isset($this->viewerSees[$userId])) {
            $user = wp_get_current_user();
            $sees = Scope::viewerSeesPrices(is_user_logged_in(), $user instanceof \WP_User ? (array) $user->roles : [], (array) $this->option('roles', []));
            /**
             * Filters whether the current visitor may see hidden prices.
             *
             * @param bool $sees
             */
            $this->viewerSees[$userId] = (bool) apply_filters('stackless_edd_hide_price_until_login_viewer_sees_prices', $sees);
        }
        return $this->viewerSees[$userId];
    }

    /** Whether the download is covered by the rule, regardless of the viewer. */
    public function productHidden(int $downloadId): bool
    {
        if ($downloadId <= 0) {
            return false;
        }
        if (!isset($this->cache[$downloadId])) {
            if (get_post_type($downloadId) !== 'download') {
                return $this->cache[$downloadId] = false;
            }
            $scope = Scope::normalizeScope((string) $this->option('scope', Scope::ALL));
            $categories = [];
            if ($scope === Scope::CATEGORIES) {
                $terms = wp_get_post_terms($downloadId, 'download_category', ['fields' => 'ids']);
                $categories = is_array($terms) ? array_map('intval', $terms) : [];
            }
            $this->cache[$downloadId] = Scope::productHidden(
                $scope,
                (string) get_post_meta($downloadId, self::META, true),
                $categories,
                $scope === Scope::CATEGORIES ? $this->hiddenCategories() : []
            );
        }
        return $this->cache[$downloadId];
    }

    /**
     * Hidden for this viewer right now.
     *
     * @param mixed $downloadId
     */
    public function hides($downloadId): bool
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        return $downloadId > 0 && !$this->viewerSeesPrices() && $this->productHidden($downloadId);
    }

    /** @return int[] */
    private function hiddenCategories(): array
    {
        if ($this->hiddenCategories === null) {
            $ids = array_filter(array_map('absint', (array) $this->option('categories', [])));
            $all = $ids;
            foreach ($ids as $id) {
                $children = get_term_children($id, 'download_category');
                if (is_array($children)) {
                    $all = array_merge($all, array_map('intval', $children));
                }
            }
            $this->hiddenCategories = array_values(array_unique($all));
        }
        return $this->hiddenCategories;
    }

    /* ---------------------------------------------------------------------
     * Login URL and markup
     * ------------------------------------------------------------------- */

    public function loginUrl(int $downloadId = 0): string
    {
        $return = $this->returnUrl($downloadId);
        if ($this->option('login_page', 'account') === 'account' && \StacklessEDD\Core\Account::pageId() > 0) {
            return add_query_arg('redirect_to', rawurlencode($return), stackless_edd_account_url());
        }
        // EDD points wp_login_url() at its own login page when one is set.
        return wp_login_url($return);
    }

    private function returnUrl(int $downloadId): string
    {
        $isRest = (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax();
        if ($downloadId > 0 && ($isRest || !is_singular() || is_singular('download'))) {
            $link = get_permalink($downloadId);
            if ($link) {
                return $link;
            }
        }
        if (!$isRest && isset($_SERVER['REQUEST_URI'])) {
            $uri = esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']));
            return wp_validate_redirect(home_url($uri), home_url('/'));
        }
        return home_url('/');
    }

    public function text(): string
    {
        $text = trim((string) $this->option('text', __('Log in to see prices', 'stackless-lite-edd')));
        return $text !== '' ? $text : __('Log in to see prices', 'stackless-lite-edd');
    }

    public function priceHtml(int $downloadId = 0): string
    {
        $this->style();
        $text = esc_html($this->text());
        if ($this->option('link', 'yes') === 'yes' && $this->isMainDownload($downloadId)) {
            $text = sprintf('<a href="%s" class="stackless-login-to-see-price__link">%s</a>', esc_url($this->loginUrl($downloadId)), $text);
        }
        return '<span class="stackless-login-to-see-price">' . $text . '</span>';
    }

    /**
     * Download cards wrap the price in the download link, and browsers cannot nest links, so the
     * sign-in link only goes on the download's own page. Cards, API responses and other
     * downloads' prices get plain text; their "Log in to buy" button carries the link.
     */
    private function isMainDownload(int $downloadId): bool
    {
        if ($downloadId <= 0 || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || !is_singular('download')) {
            return false;
        }
        if ($this->blockDepth > 0) {
            return false;
        }
        if (in_the_loop() && (int) get_the_ID() !== $downloadId) {
            return false;
        }
        return $downloadId === (int) get_queried_object_id();
    }

    /** @param array<string, mixed> $args purchase link arguments */
    private function buttonHtml(int $downloadId, array $args = []): string
    {
        $text = trim((string) $this->option('button_text', __('Log in to buy', 'stackless-lite-edd')));
        if ($text === '') {
            return '';
        }
        $this->style();
        $classes = array_filter([
            isset($args['style']) && is_string($args['style']) ? $args['style'] : 'button',
            isset($args['color']) && is_string($args['color']) ? $args['color'] : '',
            isset($args['class']) && is_string($args['class']) ? trim($args['class']) : 'edd-submit',
            'stackless-login-to-buy__button',
        ]);
        return sprintf(
            '<p class="stackless-login-to-buy"><a href="%s" class="%s">%s</a></p>',
            esc_url($this->loginUrl($downloadId)),
            esc_attr(implode(' ', $classes)),
            esc_html($text)
        );
    }

    private function style(): void
    {
        if ($this->styled || is_admin()) {
            return;
        }
        $this->styled = true;
        wp_register_style(self::HANDLE, false, [], STACKLESS_EDD_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.stackless-login-to-see-price{font-weight:600}.stackless-login-to-see-price__link{text-decoration:underline;text-underline-offset:.15em}.stackless-login-to-buy{margin:1em 0}');
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
        if (!$this->hides($downloadId)) {
            return $html;
        }
        return '<span class="edd_price" id="edd_price_' . esc_attr((string) (int) $downloadId) . '">' . $this->priceHtml((int) $downloadId) . '</span>';
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
        return $this->hides($downloadId) ? $this->priceHtml((int) $downloadId) : $range;
    }

    /**
     * A price option keeps its name and loses its amount.
     *
     * @param mixed $output
     * @param mixed $downloadId
     * @param mixed $key
     * @param mixed $price
     * @return mixed
     */
    public function filterPriceOption($output, $downloadId = 0, $key = 0, $price = [])
    {
        if (!$this->hides($downloadId)) {
            return $output;
        }
        $name = is_array($price) && isset($price['name']) ? (string) $price['name'] : '';
        return '<span class="edd_price_option_name">' . esc_html($name) . '</span>';
    }

    /**
     * @param mixed $data
     * @param mixed $download
     * @return mixed
     */
    public function filterStructuredData($data, $download = null)
    {
        $downloadId = $download instanceof \EDD_Download ? (int) $download->ID : 0;
        if (is_array($data) && $this->hides($downloadId)) {
            unset($data['offers']);
        }
        return $data;
    }

    /**
     * EDD's public product API (/edd-api/products) lists prices; leave them out for viewers who
     * may not see them. Requests signed with an API key and token are not public and keep them.
     *
     * @param mixed $product
     * @return mixed
     */
    public function filterApiProduct($product)
    {
        if (!is_array($product) || !isset($product['info']['id']) || (string) get_query_var('token') !== '') {
            return $product;
        }
        if ($this->hides((int) $product['info']['id'])) {
            unset($product['pricing']);
        }
        return $product;
    }

    /**
     * The downloads block prints "Free" itself for free downloads; while it renders, swap that
     * word for the login text on hidden downloads.
     *
     * @param mixed $parsedBlock
     * @return mixed
     */
    public function enterDownloadsBlock($parsedBlock)
    {
        if (is_array($parsedBlock) && ($parsedBlock['blockName'] ?? '') === 'edd/downloads' && !$this->viewerSeesPrices()) {
            if ($this->blockDepth++ === 0) {
                add_filter('gettext_easy-digital-downloads', [$this, 'filterFreeText'], 10, 2);
            }
        }
        return $parsedBlock;
    }

    /**
     * @param mixed $content
     * @return mixed
     */
    public function leaveDownloadsBlock($content)
    {
        if ($this->blockDepth > 0 && --$this->blockDepth === 0) {
            remove_filter('gettext_easy-digital-downloads', [$this, 'filterFreeText'], 10);
        }
        return $content;
    }

    /**
     * @param mixed $translation
     * @param mixed $text
     * @return mixed
     */
    public function filterFreeText($translation, $text = '')
    {
        if ($text !== 'Free' || get_post_type() !== 'download' || !$this->hides((int) get_the_ID())) {
            return $translation;
        }
        return $this->text();
    }

    /* ---------------------------------------------------------------------
     * Purchasing
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $canPurchase
     * @param mixed $download
     * @return mixed
     */
    public function filterCanPurchase($canPurchase, $download = null)
    {
        if (!$canPurchase || !$download instanceof \EDD_Download) {
            return $canPurchase;
        }
        // Admin order editing and cron-created orders are not shoppers.
        if (is_admin() && !wp_doing_ajax()) {
            return $canPurchase;
        }
        return $this->hides((int) $download->ID) ? false : $canPurchase;
    }

    /**
     * Belt and braces for plugins that flip EDD's purchase check back on.
     *
     * @param mixed $item
     * @return mixed
     */
    public function filterAddToCartItem($item)
    {
        // Locked lines (an invoice or renewal being paid) were sold already: never refuse them.
        if (!is_array($item) || (is_admin() && !wp_doing_ajax()) || stackless_edd_cart_item_is_locked((int) ($item['id'] ?? 0), (array) ($item['options'] ?? []))) {
            return $item;
        }
        return $this->hides($item['id'] ?? 0) ? false : $item;
    }

    /**
     * EDD skips downloads it may not sell without a word; tell the guest why.
     *
     * @param mixed $data
     */
    public function noticeOnAddToCart($data = []): void
    {
        $downloadId = is_array($data) && isset($data['download_id']) ? absint($data['download_id']) : 0;
        if ($this->hides($downloadId)) {
            stackless_edd_add_notice(esc_html__('Please log in to buy this download.', 'stackless-lite-edd'), 'error');
        }
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validateCheckout($validData = [], $post = []): void
    {
        foreach ((array) edd_get_cart_contents() as $item) {
            if (is_array($item) && $this->hides($item['id'] ?? 0)) {
                // EDD documents the error id as int; it is a string key.
                edd_set_error('stackless_edd_hide_price_until_login', esc_html__('Please log in to buy this download.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
                return;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Buttons
     * ------------------------------------------------------------------- */

    /**
     * The purchase form (price options, quantity, add to cart, buy now) becomes a login button.
     *
     * @param mixed $html
     * @param mixed $args
     * @return mixed
     */
    public function filterPurchaseForm($html, $args = [])
    {
        $args = is_array($args) ? $args : [];
        $downloadId = isset($args['download_id']) ? (int) $args['download_id'] : 0;
        if (!$this->hides($downloadId)) {
            return $html;
        }
        return $this->buttonHtml($downloadId, $args);
    }

    /* ---------------------------------------------------------------------
     * Login round trip
     * ------------------------------------------------------------------- */

    /**
     * Carry ?redirect_to= into EDD's [edd_login] form (the account page passes its own URL).
     * EDD redirects with wp_safe_redirect(), and the target is validated here as well.
     */
    public function loginRedirectField(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect target, validated below and again by EDD.
        $target = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash((string) $_GET['redirect_to'])) : '';
        if ($target === '') {
            return;
        }
        $target = wp_validate_redirect($target, '');
        if ($target !== '') {
            // shortcode-login.php prints this global into its edd_redirect field after this action.
            $GLOBALS['edd_login_redirect'] = $target;
        }
    }

    /**
     * [stackless_edd_login_to_see_price text="Log in for trade prices"]
     *
     * @param array|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['text' => $this->text()], is_array($atts) ? $atts : [], 'stackless_edd_login_to_see_price');
        if ($this->viewerSeesPrices()) {
            return '';
        }
        return sprintf('<a href="%s" class="stackless-login-to-see-price__link">%s</a>', esc_url($this->loginUrl()), esc_html((string) $atts['text']));
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
        $downloadId = (int) $downloadId;
        if (!current_user_can('edit_product', $downloadId)) {
            return;
        }
        $value = (string) get_post_meta($downloadId, self::META, true);
        $options = [
            '' => __('Follow the store setting', 'stackless-lite-edd'),
            'yes' => __('Always hide from guests', 'stackless-lite-edd'),
            'no' => __('Never hide', 'stackless-lite-edd'),
        ];
        echo '<div class="edd-form-group">';
        echo '<label for="' . esc_attr(self::META) . '" class="edd-form-group__label">' . esc_html__('Hide price until login', 'stackless-lite-edd') . '</label>';
        echo '<div class="edd-form-group__control"><select class="edd-form-group__input" id="' . esc_attr(self::META) . '" name="' . esc_attr(self::META) . '">';
        foreach ($options as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($label));
        }
        echo '</select></div>';
        echo '<p class="description edd-form-group__help">' . esc_html__('Guests see the login text instead of the price and cannot buy until they log in.', 'stackless-lite-edd') . '</p>';
        echo '</div>';
    }

    /**
     * EDD verified its download editor nonce before edd_save_download.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadField($postId, $post = null): void
    {
        $postId = (int) $postId;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD checked edd_download_meta_box_nonce.
        if ($postId <= 0 || !current_user_can('edit_product', $postId) || !isset($_POST[self::META])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = sanitize_key(wp_unslash((string) $_POST[self::META]));
        if (in_array($value, ['yes', 'no'], true)) {
            update_post_meta($postId, self::META, $value);
        } else {
            delete_post_meta($postId, self::META);
        }
        unset($this->cache[$postId]);
    }
}
