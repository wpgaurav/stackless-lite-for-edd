<?php

namespace StacklessEDD\Modules\BuyLinks;

defined('ABSPATH') || exit;

/**
 * Buy Links: shareable direct-checkout URLs (?stackless-edd-buy=…), an optional "Buy now" button,
 * signed links that cannot be edited, and a link generator on the download screen.
 * Links add normal cart items, so subscription downloads and every checkout type just work.
 *
 * EDD's own "Buy Now" purchase button (Download Details → Button Behavior) goes straight to a
 * gateway and only works for some shops; this Buy now button sits beside the Purchase button and
 * opens the regular checkout, and stays out of the way on downloads already set to EDD's Buy Now.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_BUY_NOW = '_stackless_edd_buy_links_buy_now';
    public const BUY_NOW_FIELD = 'stackless_edd_buy_now';
    public const HANDLE = 'stackless-edd-buy_links';

    public function id(): string
    {
        return 'buy_links';
    }

    public function title(): string
    {
        return __('Buy Links', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Shareable links that fill the cart and open checkout, with discount codes, multiple downloads and tamper-proof signatures, plus an optional Buy now button.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'sales';
    }

    public function install(): void
    {
        Links::secret();
    }

    public function settingsFields(): array
    {
        $example = home_url('/?' . Links::PARAM . '=123');
        return [
            [
                'title' => __('Buy links', 'stackless-lite-edd'),
                'type' => 'title',
                'id' => 'stackless_edd_buy_links_section',
                'desc' => sprintf(
                    /* translators: 1: example URL, 2: example parameters */
                    __('Any page URL with %1$s adds that download and opens checkout. Use download:price:quantity for more control, commas for several downloads, and %2$s to apply a discount code. Generate ready links from the Buy link box on each download.', 'stackless-lite-edd'),
                    '<code>' . esc_html($example) . '</code>',
                    '<code>&amp;coupon=CODE</code>'
                ),
            ],
            ['title' => __('Empty the cart first', 'stackless-lite-edd'), 'desc' => __('Replace the cart with the link\'s downloads (a link can override this with clear=0 or clear=1)', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => 'stackless_edd_buy_links_clear', 'default' => 'yes'],
            ['title' => __('Signed links', 'stackless-lite-edd'), 'type' => 'select', 'id' => 'stackless_edd_buy_links_signing', 'default' => 'off', 'options' => [
                'off' => __('Optional: unsigned links work, signed links are checked', 'stackless-lite-edd'),
                'required' => __('Required: only signed links work, so nobody can change the quantity or discount', 'stackless-lite-edd'),
            ], 'desc_tip' => __('A signature covers the downloads, price options, quantities, discount codes, cart option and expiry date.', 'stackless-lite-edd')],
            ['title' => __('Reset signing key', 'stackless-lite-edd'), 'desc' => __('Make a new key when you save. Every signed link made so far stops working.', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => 'stackless_edd_buy_links_rotate', 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'stackless_edd_buy_links_section'],

            ['title' => __('Buy now button', 'stackless-lite-edd'), 'type' => 'title', 'id' => 'stackless_edd_buy_links_buy_now_section', 'desc' => __('A second button beside Purchase that goes straight to checkout. Each download can override this in its Buy link box. Downloads set to EDD\'s own Buy Now button behavior keep EDD\'s button.', 'stackless-lite-edd')],
            ['title' => __('Show Buy now', 'stackless-lite-edd'), 'desc' => __('On download pages', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => 'stackless_edd_buy_links_buy_now', 'default' => 'no'],
            ['title' => __('In download grids', 'stackless-lite-edd'), 'desc' => __('Also in [downloads] grids, download blocks and anywhere else a Purchase button shows', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => 'stackless_edd_buy_links_buy_now_loops', 'default' => 'no'],
            ['title' => __('Button text', 'stackless-lite-edd'), 'type' => 'text', 'id' => 'stackless_edd_buy_links_buy_now_text', 'default' => __('Buy now', 'stackless-lite-edd'), 'custom_attributes' => ['maxlength' => 40]],
            ['title' => __('Buy only this download', 'stackless-lite-edd'), 'desc' => __('Buy now checks out just this download and leaves nothing else in the cart', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => 'stackless_edd_buy_links_buy_now_clear', 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'stackless_edd_buy_links_buy_now_section'],
        ];
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
            add_action('stackless_edd_settings_saved_buy_links', [$this, 'maybeRotate']);
        }
        add_action('wp_loaded', [$this, 'handleLink'], 30);
        add_action('edd_purchase_link_end', [$this, 'button'], 20, 2);
        add_action('edd_post_add_to_cart', [$this, 'buyNowOnly'], 20, 3);
        add_filter('edd_straight_to_checkout', [$this, 'buyNowRedirect'], 20);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function maybeRotate(): void
    {
        if (get_option('stackless_edd_buy_links_rotate') === 'yes') {
            Links::rotate();
            update_option('stackless_edd_buy_links_rotate', 'no');
            $this->log('Signing key reset from settings by user #' . get_current_user_id() . '.');
        }
    }

    /* ---------------------------------------------------------------------
     * Link handler.
     * ------------------------------------------------------------------ */

    public function handleLink(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public links; tampering is stopped by the optional HMAC signature.
        if (!isset($_GET[Links::PARAM]) || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!function_exists('EDD') || !EDD()->cart || !EDD()->session) {
            return;
        }
        $raw = sanitize_text_field(wp_unslash((string) $_GET[Links::PARAM]));
        $couponRaw = isset($_GET['coupon']) ? sanitize_text_field(wp_unslash((string) $_GET['coupon'])) : '';
        $clearParam = isset($_GET['clear']) ? sanitize_key(wp_unslash((string) $_GET['clear'])) : '';
        $to = isset($_GET['to']) ? sanitize_key(wp_unslash((string) $_GET['to'])) : '';
        $exp = isset($_GET['exp']) ? absint(wp_unslash($_GET['exp'])) : 0;
        $sig = isset($_GET['sig']) ? strtolower(sanitize_key(wp_unslash((string) $_GET['sig']))) : '';
        $utm = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && strpos($key, 'utm_') === 0 && is_scalar($value)) {
                $utm[sanitize_key($key)] = rawurlencode(sanitize_text_field(wp_unslash((string) $value)));
            }
        }
        // phpcs:enable

        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared signal page-cache plugins read.
        }

        $params = ['items' => $raw, 'coupon' => $couponRaw, 'clear' => in_array($clearParam, ['0', '1'], true) ? $clearParam : '', 'to' => in_array($to, ['checkout', 'cart'], true) ? $to : '', 'exp' => $exp];
        if ($sig !== '' || Links::signingRequired()) {
            if (!LinkParser::verify($params, $sig, Links::secret())) {
                $this->fail(__('This buy link is not valid. Please ask the store for a new one.', 'stackless-lite-edd'), 'signature');
                return;
            }
            if (LinkParser::expired($exp, time())) {
                $this->fail(__('This buy link has expired. Please ask the store for a new one.', 'stackless-lite-edd'), 'expired');
                return;
            }
        }

        $parsed = LinkParser::parse($raw);
        if (!$parsed['items']) {
            $this->fail(__('This buy link does not include any products.', 'stackless-lite-edd'), 'empty');
            return;
        }

        $clear = $params['clear'] !== '' ? $params['clear'] === '1' : get_option('stackless_edd_buy_links_clear', 'yes') === 'yes';
        if ($clear) {
            edd_empty_cart();
        }

        $added = 0;
        foreach ($parsed['items'] as $item) {
            $resolved = LinkParser::resolve($item, static function ($id) {
                return function_exists('edd_has_variable_prices') && edd_has_variable_prices((int) $id);
            });
            if ($this->addItem($resolved)) {
                $added++;
            }
        }
        if ($parsed['errors']) {
            stackless_edd_add_notice(__('Some products in this link could not be read and were skipped.', 'stackless-lite-edd'), 'info');
        }

        if ($added) {
            foreach (LinkParser::coupons($couponRaw) as $code) {
                $applied = array_map('strtolower', (array) edd_get_cart_discounts());
                if (!in_array($code, $applied, true) && edd_is_discount_valid($code, '', true)) {
                    edd_set_cart_discount($code);
                }
            }
        }

        /**
         * Fires after a buy link filled the cart, before the redirect.
         *
         * @param int   $added  Number of downloads added.
         * @param array $params items, coupon, clear, to, exp
         */
        do_action('stackless_edd_buy_links_processed', $added, $params);

        $url = (string) edd_get_checkout_uri();
        if ($utm) {
            $url = add_query_arg($utm, $url);
        }
        wp_safe_redirect((string) apply_filters('stackless_edd_buy_links_redirect', $url, $added, $params));
        exit;
    }

    private function fail(string $message, string $reason): void
    {
        stackless_edd_add_notice($message, 'error');
        $this->log('Buy link rejected: ' . $reason . '.', 'notice');
        wp_safe_redirect((string) edd_get_checkout_uri());
        exit;
    }

    /** @param array{product: int, variation: int, qty: int} $item */
    private function addItem(array $item): bool
    {
        $download = edd_get_download($item['product']);
        $status = $download instanceof \EDD_Download && !empty($download->ID) ? (string) get_post_status($download->ID) : '';
        if ($status === '' || $status === 'trash' || !$download->can_purchase()) {
            /* translators: %d: download ID */
            stackless_edd_add_notice(sprintf(__('Product #%d from this link is not available.', 'stackless-lite-edd'), $item['product']), 'error');
            return false;
        }
        $options = [];
        if ($download->has_variable_prices()) {
            $prices = (array) $download->get_prices();
            $priceId = $item['variation'] ?: (int) edd_get_default_variable_price($download->ID);
            if (!isset($prices[$priceId])) {
                /* translators: %d: download ID */
                stackless_edd_add_notice(sprintf(__('Product #%d from this link is not available.', 'stackless-lite-edd'), $item['product']), 'error');
                return false;
            }
            $options['price_id'] = $priceId;
        }
        $quantities = edd_item_quantities_enabled() && !edd_download_quantities_disabled($download->ID);
        $options['quantity'] = $quantities ? $item['qty'] : 1;
        edd_add_to_cart($download->ID, $options);
        unset($options['quantity']);
        return edd_item_in_cart($download->ID, $options);
    }

    /* ---------------------------------------------------------------------
     * Buy now button.
     * ------------------------------------------------------------------ */

    private function buyNowEnabled(int $downloadId): bool
    {
        $override = get_post_meta($downloadId, self::META_BUY_NOW, true);
        if ($override === 'yes' || $override === 'no') {
            return $override === 'yes';
        }
        return get_option('stackless_edd_buy_links_buy_now', 'no') === 'yes';
    }

    private function buttonText(): string
    {
        $text = get_option('stackless_edd_buy_links_buy_now_text', '');
        return is_string($text) && trim($text) !== '' ? sanitize_text_field($text) : __('Buy now', 'stackless-lite-edd');
    }

    /** Whether the purchase form being printed is the main one of a download page. */
    private function onDownloadPage(int $downloadId): bool
    {
        return is_singular('download') && (int) get_queried_object_id() === $downloadId;
    }

    /**
     * A Buy now button inside EDD's purchase form: it submits the same form (price option and
     * quantity included) and goes straight to checkout.
     *
     * @param mixed $downloadId
     * @param mixed $args
     */
    public function button($downloadId, $args = []): void
    {
        $downloadId = (int) $downloadId;
        $args = is_array($args) ? $args : [];
        if (!$downloadId || !empty($args['direct']) || !$this->buyNowEnabled($downloadId)) {
            return;
        }
        // On a download page the main switch decides; elsewhere the grids switch must be on too.
        if (!$this->onDownloadPage($downloadId) && get_option('stackless_edd_buy_links_buy_now_loops', 'no') !== 'yes') {
            return;
        }
        $download = edd_get_download($downloadId);
        if (!$download instanceof \EDD_Download || !$download->can_purchase()) {
            return;
        }
        $classes = ['stackless-buy-now', 'edd-submit', (string) ($args['style'] ?? edd_get_option('button_style', 'button')), (string) ($args['color'] ?? '')];
        if (function_exists('wp_theme_get_element_class_name') && wp_theme_get_element_class_name('button')) {
            $classes[] = wp_theme_get_element_class_name('button');
        }
        printf(
            '<button type="submit" name="%1$s" value="1" class="%2$s">%3$s</button>',
            esc_attr(self::BUY_NOW_FIELD),
            esc_attr(implode(' ', array_filter($classes))),
            esc_html($this->buttonText())
        );
    }

    private function isBuyNowRequest(): bool
    {
        return !empty($_POST[self::BUY_NOW_FIELD]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same trust level as EDD's own purchase form.
    }

    /**
     * Buy now checks out exactly what was chosen.
     *
     * @param mixed $downloadId
     * @param mixed $options
     * @param mixed $items
     */
    public function buyNowOnly($downloadId, $options, $items = []): void
    {
        static $running = false;
        if ($running || !$this->isBuyNowRequest() || !function_exists('EDD') || !EDD()->cart) {
            return;
        }
        $running = true;
        $downloadId = (int) $downloadId;
        $chosen = [];
        foreach ((array) $items as $item) {
            if (is_array($item)) {
                $chosen[] = isset($item['options']['price_id']) && is_numeric($item['options']['price_id']) ? (int) $item['options']['price_id'] : null;
            }
        }
        if (get_option('stackless_edd_buy_links_buy_now_clear', 'yes') === 'yes') {
            $remove = [];
            foreach ((array) edd_get_cart_contents() as $key => $line) {
                $price = isset($line['options']['price_id']) && is_numeric($line['options']['price_id']) ? (int) $line['options']['price_id'] : null;
                if ((int) ($line['id'] ?? 0) !== $downloadId || !in_array($price, $chosen, true)) {
                    $remove[] = (int) $key;
                }
            }
            rsort($remove, SORT_NUMERIC);
            foreach ($remove as $key) {
                edd_remove_from_cart($key);
            }
        }
        // A repeat Buy now must not stack quantities: keep what this form asked for.
        if (edd_item_quantities_enabled() && !edd_download_quantities_disabled($downloadId)) {
            $wanted = isset($_POST['edd_download_quantity']) ? max(1, absint(wp_unslash($_POST['edd_download_quantity']))) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            foreach ($chosen as $price) {
                $lineOptions = $price === null ? [] : ['price_id' => $price];
                if ((int) edd_get_cart_item_quantity($downloadId, $lineOptions) !== $wanted) {
                    EDD()->cart->set_item_quantity($downloadId, $wanted, $lineOptions);
                }
            }
        }
        $running = false;
    }

    /**
     * @param mixed $straight
     * @return mixed
     */
    public function buyNowRedirect($straight)
    {
        return $this->isBuyNowRequest() ? true : $straight;
    }

    public function assets(): void
    {
        if (get_option('stackless_edd_buy_links_buy_now_loops', 'no') === 'yes' || is_singular('download')) {
            wp_enqueue_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/buy_links/buy-now.css', [], STACKLESS_EDD_VERSION);
        }
    }
}
