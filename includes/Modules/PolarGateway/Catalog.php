<?php

namespace StacklessEDD\Modules\PolarGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps EDD purchases onto Polar products.
 *
 * A Polar checkout sells exactly one product and takes its price per checkout
 * (an ad-hoc price), so EDD stays the source of truth for amounts. Each download
 * or variable price can be linked to a Polar product by hand (or with "Create in
 * Polar"); otherwise one private Polar product per download/price and billing
 * schedule is created on first use and remembered per environment, plus one
 * shared product for multi-item carts.
 */
final class Catalog
{
    public const OPTION = 'stackless_edd_polar_gateway_products';
    public const META_PREFIX = '_stackless_edd_polar_product_';
    public const NONCE = 'stackless-edd-polar-gateway-catalog';
    public const SAVE_NONCE = 'stackless-edd-polar-gateway-download';
    public const AJAX = 'stackless_edd_polar_create_product';

    /** Billing choices offered by the "Create in Polar" helper. */
    public const BILLING_CHOICES = ['once', 'day:1', 'week:1', 'month:1', 'month:3', 'month:6', 'year:1'];

    /**
     * Map key: "{mode}|{once|interval:count}|{v<download>-<price>|d<download>|cart|t<hash>}".
     *
     * @param array{title?:string,download_id?:int,price_id?:int|null,cart?:bool} $item
     */
    public static function key(array $item, ?array $recurring, string $mode): string
    {
        $billing = $recurring ? $recurring[0] . ':' . (int) $recurring[1] : 'once';
        if (!empty($item['download_id']) && isset($item['price_id'])) {
            $target = 'v' . (int) $item['download_id'] . '-' . (int) $item['price_id'];
        } elseif (!empty($item['download_id'])) {
            $target = 'd' . (int) $item['download_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $billing . '|' . $target;
    }

    /**
     * One line becomes its own product; several share a store product.
     *
     * @return array{title:string,download_id:int,price_id:int|null,cart?:bool}
     */
    public static function itemForOrder(Order $order, ?SubscriptionData $subscription = null): array
    {
        $lines = [];
        if ($subscription) {
            foreach ($subscription->get_items() as $line) {
                $lines[] = ['title' => (string) $line['name'], 'download_id' => (int) $line['download_id'], 'price_id' => $line['price_id'] ?? null];
            }
        } else {
            foreach ((array) $order->get_items() as $line) {
                if (!is_object($line)) {
                    continue;
                }
                $downloadId = (int) $line->product_id;
                $priceId = is_numeric($line->price_id) && edd_has_variable_prices($downloadId) ? (int) $line->price_id : null;
                $lines[] = ['title' => (string) $line->product_name, 'download_id' => $downloadId, 'price_id' => $priceId];
            }
        }
        if (count($lines) === 1) {
            return $lines[0];
        }
        $store = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return [
            /* translators: %s: store name */
            'title' => sprintf(__('%s order', 'stackless-lite-edd'), $store),
            'download_id' => 0,
            'price_id' => null,
            'cart' => true,
        ];
    }

    /** Product map of one gateway: each copy is its own Polar organization. */
    public static function option(Gateway $gateway): string
    {
        return self::OPTION . $gateway->suffix();
    }

    private static function map(Gateway $gateway): array
    {
        $map = get_option(self::option($gateway), []);
        return is_array($map) ? $map : [];
    }

    public static function forget(Gateway $gateway, array $item, ?array $recurring, string $mode): void
    {
        $map = self::map($gateway);
        unset($map[self::key($item, $recurring, $mode)]);
        update_option(self::option($gateway), $map, false);
    }

    public static function forgetMode(Gateway $gateway, string $mode): void
    {
        $map = array_filter(self::map($gateway), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option(self::option($gateway), $map, false);
    }

    /**
     * Polar product for a checkout: the linked one when it fits, else the auto-created one.
     *
     * @param array|null $defaultPrice ['currency' => lowercase, 'amount' => minor] when Polar also needs a default-currency price.
     * @return string|\WP_Error
     */
    public static function productId(Gateway $gateway, array $item, ?array $recurring, string $currency, int $minor, string $mode, bool $refresh = false, ?array $defaultPrice = null)
    {
        // Products linked by hand in the download editor belong to the original gateway's organization.
        if (!$refresh && $gateway->isOriginal()) {
            $linked = self::linkedProduct($item, $mode);
            if ($linked !== '' && self::linkedFits($gateway, $linked, $recurring, $mode)) {
                return $linked;
            }
        }

        $key = self::key($item, $recurring, $mode);
        $map = self::map($gateway);
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }

        $product = self::create($gateway, $item, $recurring, $currency, $minor, $mode, $defaultPrice);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['id'];
        update_option(self::option($gateway), $map, false);
        return (string) $product['id'];
    }

    /** Meta key holding the linked Polar product of a download (null price) or one of its variable prices. */
    public static function metaKey(string $mode, ?int $priceId): string
    {
        return self::META_PREFIX . ($mode === 'sandbox' ? 'sandbox' : 'live') . ($priceId !== null ? '_' . $priceId : '');
    }

    /** Polar product id set by hand on the variable price, else on its download. */
    public static function linkedProduct(array $item, string $mode): string
    {
        $downloadId = (int) ($item['download_id'] ?? 0);
        if (!$downloadId) {
            return '';
        }
        $keys = isset($item['price_id']) ? [self::metaKey($mode, (int) $item['price_id'])] : [];
        $keys[] = self::metaKey($mode, null);
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($downloadId, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** A linked product must exist, be active, and bill on the same schedule. Cached for an hour. */
    private static function linkedFits(Gateway $gateway, string $productId, ?array $recurring, string $mode): bool
    {
        $cacheKey = 'stackless_edd_polar_gateway_pv_' . md5($mode . '|' . $productId);
        $cached = get_transient($cacheKey);
        if (!is_array($cached)) {
            $remote = $gateway->api($mode)->request('GET', 'products/' . rawurlencode($productId));
            if (is_wp_error($remote)) {
                $gateway->log('Linked Polar product ' . $productId . ' could not be read: ' . $remote->get_error_message(), 'warning');
                return false;
            }
            $cached = [
                'archived' => !empty($remote['is_archived']),
                'interval' => (string) ($remote['recurring_interval'] ?? ''),
                'count' => max(1, (int) ($remote['recurring_interval_count'] ?? 1)),
            ];
            set_transient($cacheKey, $cached, HOUR_IN_SECONDS);
        }
        if ($cached['archived']) {
            return false;
        }
        if (!$recurring) {
            return $cached['interval'] === '';
        }
        $fits = $cached['interval'] === $recurring[0] && (int) $cached['count'] === (int) $recurring[1];
        if (!$fits) {
            $gateway->log('Linked Polar product ' . $productId . ' bills every ' . $cached['count'] . ' ' . $cached['interval'] . ', not ' . $recurring[1] . ' ' . $recurring[0] . '; using an automatic product.', 'warning');
        }
        return $fits;
    }

    /** @return array|\WP_Error */
    public static function create(Gateway $gateway, array $item, ?array $recurring, string $currency, int $minor, string $mode, ?array $defaultPrice = null)
    {
        $currency = strtolower($currency);
        $prices = [[
            'amount_type' => 'fixed',
            'price_amount' => max(0, $minor),
            'price_currency' => $currency,
        ]];
        // Polar requires a price in the organization's default currency. It is never
        // charged: every checkout supplies its own prices.
        if ($defaultPrice && $defaultPrice['currency'] !== $currency) {
            $prices[] = [
                'amount_type' => 'fixed',
                'price_amount' => max(0, (int) $defaultPrice['amount']),
                'price_currency' => $defaultPrice['currency'],
            ];
        }

        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            'visibility' => 'private',
            'prices' => $prices,
            'metadata' => array_filter([
                'source' => 'stackless-edd',
                'edd_download_id' => (int) ($item['download_id'] ?? 0),
                'edd_price_id' => isset($item['price_id']) ? (string) $item['price_id'] : '',
                'site' => self::siteHost(),
            ], static function ($value) {
                return $value !== '' && $value !== 0;
            }),
        ];
        if ($recurring) {
            $body['recurring_interval'] = $recurring[0];
            $body['recurring_interval_count'] = (int) $recurring[1];
        }

        $body = (array) apply_filters('stackless_edd_polar_gateway_product_args', $body, $item, $recurring, $mode);
        $product = $gateway->api($mode)->request('POST', 'products/', $body);
        if (!is_wp_error($product) && empty($product['id'])) {
            return new \WP_Error('stackless_edd_polar_product', __('Polar did not return a product.', 'stackless-lite-edd'));
        }
        if (!is_wp_error($product)) {
            $gateway->log('Created Polar product ' . $product['id'] . ' (' . self::key($item, $recurring, $mode) . ').');
        }
        return $product;
    }

    /** Polar product names must be 3 to 64 characters. */
    public static function name(string $title): string
    {
        $title = trim(wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')));
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($length < 3) {
            $title = trim($title . ' ' . __('purchase', 'stackless-lite-edd'));
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($title) > 64 ? rtrim(mb_substr($title, 0, 63)) . '…' : $title;
        }
        return strlen($title) > 64 ? rtrim(substr($title, 0, 61)) . '...' : $title;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** "month:3" → ['month', 3]; "once" → null. */
    public static function parseBilling(string $choice): ?array
    {
        if ($choice === 'once' || strpos($choice, ':') === false) {
            return null;
        }
        [$period, $count] = explode(':', $choice, 2);
        return EventMap::interval($period, (int) $count);
    }

    /* ---- Download editor ------------------------------------------------------------ */

    public static function registerAdmin(): void
    {
        add_action('add_meta_boxes_download', [self::class, 'addMetaBox']);
        add_action('save_post_download', [self::class, 'saveDownload'], 10, 1);
        add_action('wp_ajax_' . self::AJAX, [self::class, 'ajaxCreate']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function addMetaBox(): void
    {
        add_meta_box('stackless-edd-polar-products', __('Polar products', 'stackless-lite-edd'), [self::class, 'renderMetaBox'], 'download', 'normal', 'low');
    }

    /** @param mixed $hook */
    public static function enqueue($hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$screen || $screen->post_type !== 'download') {
            return;
        }
        wp_enqueue_script('stackless-edd-polar_gateway-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/polar_gateway/admin.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_localize_script('stackless-edd-polar_gateway-admin', 'stacklessEddPolarAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::NONCE),
            'i18n' => [
                'creating' => __('Creating in Polar…', 'stackless-lite-edd'),
                'created' => __('Created. Update the download to keep the link.', 'stackless-lite-edd'),
                'failed' => __('Polar could not create the product.', 'stackless-lite-edd'),
                'saveFirst' => __('Save the download first so it has a price.', 'stackless-lite-edd'),
            ],
        ]);
    }

    private static function billingOptions(): array
    {
        return [
            'once' => __('One-time', 'stackless-lite-edd'),
            'day:1' => __('Daily', 'stackless-lite-edd'),
            'week:1' => __('Weekly', 'stackless-lite-edd'),
            'month:1' => __('Monthly', 'stackless-lite-edd'),
            'month:3' => __('Every 3 months', 'stackless-lite-edd'),
            'month:6' => __('Every 6 months', 'stackless-lite-edd'),
            'year:1' => __('Yearly', 'stackless-lite-edd'),
        ];
    }

    private static function rowHtml(int $downloadId, ?int $priceId, string $label): void
    {
        $default = $downloadId && stackless_edd_is_subscription_product($downloadId, $priceId) ? 'month:1' : 'once';
        $key = $priceId === null ? 'base' : (string) $priceId;
        echo '<div class="stackless-polar-product" data-product-id="' . esc_attr((string) $downloadId) . '" data-price-id="' . esc_attr($priceId === null ? '' : (string) $priceId) . '">';
        if ($label !== '') {
            echo '<p><strong>' . esc_html($label) . '</strong></p>';
        }
        foreach (['live' => __('Polar product (live)', 'stackless-lite-edd'), 'sandbox' => __('Polar product (sandbox)', 'stackless-lite-edd')] as $mode => $text) {
            $fieldId = 'stackless_edd_polar_product_' . $mode . '_' . $downloadId . '_' . $key;
            echo '<p>';
            echo '<label for="' . esc_attr($fieldId) . '">' . esc_html($text) . '</label><br>';
            echo '<input type="text" class="regular-text stackless-polar-product__id" data-mode="' . esc_attr($mode) . '" id="' . esc_attr($fieldId) . '" name="stackless_edd_polar_product[' . esc_attr($mode) . '][' . esc_attr($key) . ']" value="' . esc_attr((string) get_post_meta($downloadId, self::metaKey($mode, $priceId), true)) . '" placeholder="' . esc_attr__('Automatic', 'stackless-lite-edd') . '" pattern="[0-9a-fA-F-]{36}" /> ';
            echo '<button type="button" class="button stackless-polar-product__create" data-mode="' . esc_attr($mode) . '">' . esc_html__('Create in Polar', 'stackless-lite-edd') . '</button>';
            echo '</p>';
        }
        echo '<p><label>' . esc_html__('Polar billing', 'stackless-lite-edd') . ' <select class="stackless-polar-product__billing">';
        foreach (self::billingOptions() as $value => $text) {
            echo '<option value="' . esc_attr($value) . '"' . selected($value, $default, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label> <span class="description stackless-polar-product__status"></span></p>';
        echo '</div>';
    }

    /** @param mixed $post */
    public static function renderMetaBox($post): void
    {
        $downloadId = $post instanceof \WP_Post ? (int) $post->ID : 0;
        wp_nonce_field(self::SAVE_NONCE, 'stackless_edd_polar_product_nonce');
        echo '<div class="stackless-polar-products">';
        if ($downloadId && edd_has_variable_prices($downloadId)) {
            foreach ((array) edd_get_variable_prices($downloadId) as $priceId => $price) {
                $name = is_array($price) ? (string) ($price['name'] ?? '') : '';
                /* translators: %d: variable price id */
                self::rowHtml($downloadId, (int) $priceId, $name !== '' ? $name : sprintf(__('Price option %d', 'stackless-lite-edd'), (int) $priceId));
            }
        } else {
            self::rowHtml($downloadId, null, '');
        }
        echo '<p class="description">' . esc_html__('Leave empty and Polar products are created automatically on first sale. A linked product must use the same billing schedule as this download; prices always come from EDD.', 'stackless-lite-edd') . '</p>';
        echo '</div>';
    }

    /** @param mixed $value */
    private static function clean($value): string
    {
        $value = trim(sanitize_text_field((string) $value));
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) ? strtolower($value) : '';
    }

    /** @param mixed $postId */
    public static function saveDownload($postId): void
    {
        $postId = (int) $postId;
        if (!isset($_POST['stackless_edd_polar_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['stackless_edd_polar_product_nonce'])), self::SAVE_NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is cleaned below.
        $raw = isset($_POST['stackless_edd_polar_product']) && is_array($_POST['stackless_edd_polar_product']) ? wp_unslash($_POST['stackless_edd_polar_product']) : [];
        foreach (['live', 'sandbox'] as $mode) {
            foreach ((array) ($raw[$mode] ?? []) as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $metaKey = self::metaKey($mode, $key === 'base' ? null : absint($key));
                $clean = self::clean($value);
                if ($clean === '') {
                    delete_post_meta($postId, $metaKey);
                } else {
                    update_post_meta($postId, $metaKey, $clean);
                }
            }
        }
    }

    /** "Create in Polar": a private Polar product at the current EDD price. */
    public static function ajaxCreate(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $downloadId = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $rawPrice = isset($_POST['price_id']) ? sanitize_text_field(wp_unslash($_POST['price_id'])) : '';
        $priceId = $rawPrice === '' ? null : absint($rawPrice);
        $mode = isset($_POST['mode']) && $_POST['mode'] === 'sandbox' ? 'sandbox' : 'live';
        $billing = isset($_POST['billing']) ? sanitize_text_field(wp_unslash($_POST['billing'])) : 'once';
        if (!in_array($billing, self::BILLING_CHOICES, true)) {
            $billing = 'once';
        }

        if (!$downloadId || get_post_type($downloadId) !== 'download' || !current_user_can('edit_post', $downloadId)) {
            wp_send_json_error(['message' => __('Download not found.', 'stackless-lite-edd')], 404);
        }
        $price = $priceId !== null ? (float) edd_get_price_option_amount($downloadId, $priceId) : (float) edd_get_download_price($downloadId);
        if ($price <= 0) {
            wp_send_json_error(['message' => __('Give the download a price above zero first.', 'stackless-lite-edd')], 400);
        }

        $gateway = Gateway::instance();
        if ($gateway->accessToken($mode) === '') {
            wp_send_json_error(['message' => __('Add the Polar access token for this environment first.', 'stackless-lite-edd')], 400);
        }
        $currency = strtolower(stackless_edd_base_currency());
        if (!Money::supported($currency)) {
            wp_send_json_error(['message' => __('Polar does not support the store currency.', 'stackless-lite-edd')], 400);
        }
        $default = $gateway->defaultCurrency($mode);
        $defaultPrice = $default !== '' && $default !== $currency
            ? ['currency' => $default, 'amount' => Money::toMinor(Checkout::convert($price, $currency, $default, null), $default)]
            : null;

        $title = (string) get_the_title($downloadId);
        if ($priceId !== null) {
            $option = (string) edd_get_price_option_name($downloadId, $priceId);
            $title = $option !== '' ? $title . ' – ' . $option : $title;
        }
        $item = ['title' => $title, 'download_id' => $downloadId, 'price_id' => $priceId];
        $created = self::create($gateway, $item, self::parseBilling($billing), $currency, Money::toMinor($price, $currency), $mode, $defaultPrice);
        if (is_wp_error($created)) {
            wp_send_json_error(['message' => $created->get_error_message()], 400);
        }
        update_post_meta($downloadId, self::metaKey($mode, $priceId), (string) $created['id']);
        wp_send_json_success(['id' => (string) $created['id']]);
    }
}
