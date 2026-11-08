<?php

namespace StacklessEDD\Admin;

defined('ABSPATH') || exit;

/**
 * Links each module and screen to its page in the Stackless for EDD documentation.
 * Unmapped keys fall back to the documentation home.
 */
final class Documentation
{
    public const DOCS = 'https://gauravtiwari.org/docs/stackless-for-edd';
    public const CHANGELOG = 'https://gauravtiwari.org/changelogs/stackless-for-edd/';

    /** Module id (or screen key) => section/page. */
    private const PAGES = [
        'dashboard' => 'getting-started/dashboard-and-modules',
        'stripe_gateway' => 'payments/stripe',
        'paypal_gateway' => 'payments/paypal',
        'polar_gateway' => 'payments/polar',
        'paddle_gateway' => 'payments/paddle',
        'dodo_gateway' => 'payments/dodo-payments',
        'stripe_managed' => 'payments/stripe-managed-payments',
        'razorpay_gateway' => 'payments/razorpay',
        'mollie_gateway' => 'payments/mollie',
        'square_gateway' => 'payments/square',
        'paystack_gateway' => 'payments/paystack',
        'flutterwave_gateway' => 'payments/flutterwave',
        'mercadopago_gateway' => 'payments/mercado-pago',
        'creem_gateway' => 'payments/creem',
        'gateway_tools' => 'payments/payment-gateway-tools',
        'checkout_enhancements' => 'checkout-and-sales/checkout-enhancements',
        'extra_enhancements' => 'checkout-and-sales/extra-enhancements',
        'buy_links' => 'checkout-and-sales/buy-links',
        'pay_what_you_like' => 'checkout-and-sales/pay-what-you-like',
        'free_price_text' => 'catalog/free-price-text',
        'sold_count' => 'catalog/sold-count',
        'quantity_limits' => 'catalog/quantity-limits',
        'hide_price_until_login' => 'catalog/hide-price-until-login',
        'custom_cart_text' => 'catalog/custom-add-to-cart-text',
        'external_product' => 'catalog/external-products',
        'product_enhancements' => 'catalog/product-details',
        'wishlist' => 'store-tools/wishlist',
        'saved_carts' => 'store-tools/saved-carts',
        'service_products' => 'store-tools/service-products',
        'edd_optimizer' => 'store-tools/edd-optimizer',
        'emails' => 'getting-started/emails',
        'account' => 'getting-started/customer-account',
        'troubleshooting' => 'developer-reference/troubleshooting',
    ];

    /**
     * Module id => its step-by-step guide, linked from the download edit screen. The EDD docs
     * have no guides yet; add entries here as they are published.
     *
     * @var array<string,string>
     */
    private const GUIDES = [
    ];

    public static function url(string $key = ''): string
    {
        $page = self::PAGES[$key] ?? '';
        $url = self::DOCS . '/' . ($page === '' ? '' : $page . '/');
        return (string) apply_filters('stackless_edd_documentation_url', $url, $key);
    }

    /**
     * An escaped link that opens the page in a new tab.
     *
     * @param string $class Extra CSS classes.
     */
    public static function link(string $key, string $label = '', string $class = ''): string
    {
        return self::anchor(self::url($key), $label !== '' ? $label : __('Documentation', 'stackless-lite-edd'), $class);
    }

    /**
     * Help for a feature set on the download edit screen: its docs page, any further pages, and
     * the step-by-step guide when the docs have one. Escaped HTML.
     *
     * @param array<string,string> $more Further docs pages: key => link text.
     */
    public static function helpLinks(string $key, string $label = '', array $more = []): string
    {
        $links = [self::link($key, $label !== '' ? $label : __('How this works', 'stackless-lite-edd'))];
        foreach ($more as $moreKey => $moreLabel) {
            $links[] = self::link((string) $moreKey, $moreLabel);
        }
        /**
         * Step-by-step guides by module id, linked after the docs page.
         *
         * @param array<string,string> $guides module id => docs section/page
         */
        $guides = (array) apply_filters('stackless_edd_documentation_guides', self::GUIDES);
        if (isset($guides[$key]) && is_string($guides[$key]) && $guides[$key] !== '') {
            $url = (string) apply_filters('stackless_edd_documentation_url', self::DOCS . '/' . $guides[$key] . '/', $key . '_guide');
            $links[] = self::anchor($url, __('Step-by-step guide', 'stackless-lite-edd'));
        }
        return implode('<span class="stackless-docs-sep" aria-hidden="true">·</span>', $links);
    }

    /**
     * helpLinks() as the last row of a Stackless box on the download edit screen. Add
     * data-stackless-show-if="<checkbox id>" yourself to show it only while that option is ticked.
     *
     * @param array<string,string> $more Further docs pages: key => link text.
     */
    public static function helpField(string $key, string $label = '', array $more = []): string
    {
        return '<p class="stackless-docs-field">' . self::helpLinks($key, $label, $more) . '</p>';
    }

    /**
     * Module id => its meta box on the download edit screen. register() ends each box with the
     * module's help row, so the modules don't print it themselves. Lite drops the Pro rows.
     */
    private const BOXES = [
        'buy_links' => 'stackless-edd-buy-link',
        'product_enhancements' => 'stackless-edd-product-details',
        'extra_enhancements' => 'stackless-edd-checkout-options',
        'pay_what_you_like' => 'stackless-edd-pay-what-you-like',
        'service_products' => 'stackless-edd-service',
        'polar_gateway' => 'stackless-edd-polar-products',
    ];

    public static function register(): void
    {
        add_action('add_meta_boxes_download', [self::class, 'wrapBoxes'], 999);
        add_action('admin_print_styles-post.php', [self::class, 'downloadScreen']);
        add_action('admin_print_styles-post-new.php', [self::class, 'downloadScreen']);
    }

    /**
     * On the download edit screen: styles the help rows, and shows a row marked
     * data-stackless-show-if="<checkbox id>" only while that download option is ticked.
     */
    public static function downloadScreen(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'download') {
            return;
        }
        echo '<style id="stackless-edd-docs-field">.stackless-docs-field .stackless-docs-link{text-decoration:none;white-space:nowrap}.stackless-docs-field .stackless-docs-link:is(:hover,:focus){text-decoration:underline}.stackless-docs-field .stackless-docs-link::after{content:"\\f504";font:normal 14px/1 dashicons;vertical-align:-2px;margin-left:2px}.stackless-docs-field .stackless-docs-sep{color:#8c8f94;margin:0 8px}</style>';
        wp_add_inline_script('jquery-core', 'jQuery(function($){$("[data-stackless-show-if]").each(function(){var row=$(this),box=$("#"+row.attr("data-stackless-show-if")),sync=function(){row.toggle(box.is(":checked"));};box.on("change",sync);sync();});});');
    }

    /** Ends each known Stackless meta box on the download screen with its module's help row. */
    public static function wrapBoxes(): void
    {
        global $wp_meta_boxes;
        if (!is_array($wp_meta_boxes) || empty($wp_meta_boxes['download']) || !is_array($wp_meta_boxes['download'])) {
            return;
        }
        $modules = array_flip(self::BOXES);
        foreach ($wp_meta_boxes['download'] as $context => $priorities) {
            foreach ((array) $priorities as $priority => $boxes) {
                foreach ((array) $boxes as $box_id => $box) {
                    if (!is_array($box) || !isset($modules[$box_id]) || !is_callable($box['callback'] ?? null)) {
                        continue;
                    }
                    $callback = $box['callback'];
                    $module = (string) $modules[$box_id];
                    $wp_meta_boxes['download'][$context][$priority][$box_id]['callback'] = static function (...$args) use ($callback, $module): void {
                        call_user_func_array($callback, $args);
                        echo self::helpField($module); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helpLinks().
                    };
                }
            }
        }
    }

        private static function anchor(string $url, string $label, string $class = ''): string
    {
        return sprintf(
            '<a href="%s" class="stackless-docs-link%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
            esc_url($url),
            $class !== '' ? ' ' . esc_attr($class) : '',
            esc_html($label),
            esc_html__('(opens in a new tab)', 'stackless-lite-edd')
        );
    }
}
