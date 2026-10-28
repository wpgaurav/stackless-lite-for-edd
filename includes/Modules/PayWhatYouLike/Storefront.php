<?php

namespace StacklessEDD\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * What buyers see: the amount field on purchase forms (single-price downloads, a fixed variable
 * price button, and variable downloads whose options are radio buttons), the "pay what you like"
 * line under prices, and the "Name your price" box on the checkout (shortcode and block checkout).
 *
 * Purchase forms of downloads that take a chosen amount never skip the cart: "Buy Now" and the
 * Stripe payment request button would charge the catalog price.
 */
final class Storefront
{
    public const STYLE = 'stackless-edd-pay_what_you_like';
    public const SCRIPT = 'stackless-edd-pay_what_you_like-front';

    /** @var ProductSettings */
    private $settings;

    /** @var Cart */
    private $cart;

    /** @var array{heading: string, note: string, product_field: bool, product_note: bool} */
    private $options;

    /** @var bool */
    private $checkoutRendered = false;

    /** @param array{heading: string, note: string, product_field: bool, product_note: bool} $options */
    public function __construct(ProductSettings $settings, Cart $cart, array $options)
    {
        $this->settings = $settings;
        $this->cart = $cart;
        $this->options = $options;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('edd_purchase_link_args', [$this, 'purchaseLinkArgs'], 20, 2);
        add_filter('edds_prb_purchase_link_enabled', [$this, 'paymentRequestButton'], 20, 2);
        if ($this->options['product_field']) {
            add_action('edd_purchase_link_top', [$this, 'productField'], 15, 2);
        }
        if ($this->options['product_note']) {
            add_filter('edd_download_price_after_html', [$this, 'priceNote'], 60, 4);
        }
        add_filter('edd_cart_item_price_label', [$this, 'linePriceLabel'], 20, 3);
        add_action('edd_before_purchase_form', [$this, 'checkoutBox'], 10);
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------- */

    public function assets(): void
    {
        wp_register_style(self::STYLE, STACKLESS_EDD_PLUGIN_URL . 'assets/pay_what_you_like/front.css', [], STACKLESS_EDD_VERSION);
        wp_register_script(self::SCRIPT, STACKLESS_EDD_PLUGIN_URL . 'assets/pay_what_you_like/front.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_localize_script(self::SCRIPT, 'stacklessEddPayWhatYouLike', [
            'decimalSep' => self::separator(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => Cart::AJAX_ACTION,
            'nonce' => wp_create_nonce(Cart::NONCE_ACTION),
            'i18n' => [
                /* translators: %s: minimum price */
                'tooLow' => __('Enter at least %s.', 'stackless-lite-edd'),
                /* translators: %s: maximum price */
                'tooHigh' => __('Enter at most %s.', 'stackless-lite-edd'),
                'invalid' => __('Enter an amount such as 12.50, without a currency symbol.', 'stackless-lite-edd'),
                'failed' => __('Your price could not be updated. Please try again.', 'stackless-lite-edd'),
            ],
        ]);

        $needed = false;
        if (is_singular('download')) {
            $needed = $this->settings->settingsFor((int) get_queried_object_id()) !== null;
        } elseif (function_exists('edd_is_checkout') && edd_is_checkout() && !(function_exists('edd_is_success_page') && edd_is_success_page())) {
            $needed = (bool) $this->cart->lines();
        }
        if ($needed) {
            $this->enqueue();
        }
    }

    private function enqueue(): void
    {
        if (!wp_script_is(self::SCRIPT, 'registered')) {
            $this->assets();
        }
        wp_enqueue_style(self::STYLE);
        wp_enqueue_script(self::SCRIPT);
    }

    private static function separator(): string
    {
        $separator = function_exists('edd_get_option') ? (string) edd_get_option('decimal_separator', '.') : '.';
        return $separator !== '' ? $separator : '.';
    }

    /* ---------------------------------------------------------------------
     * Fields
     * ------------------------------------------------------------------- */

    /**
     * Template arguments for one amount field.
     *
     * @param array<string, mixed> $state amount (or default), min, max, presets, decimals, currency
     * @return array<string, mixed>
     */
    public static function fieldArgs(array $state, string $name, string $label, string $context): array
    {
        $d = (int) $state['decimals'];
        $currency = (string) ($state['currency'] ?? '');
        $sep = self::separator();
        $show = static function (int $minor) use ($d, $sep): string {
            return str_replace('.', $sep, Amounts::fromMinor($minor, $d));
        };
        $amount = (int) ($state['amount'] ?? $state['default'] ?? 0);
        $symbol = function_exists('edd_currency_symbol') ? (string) edd_currency_symbol($currency !== '' ? $currency : stackless_edd_current_currency()) : '';
        return [
            'id' => wp_unique_id('stackless-edd-pwyl-'),
            // Not "name": stackless_edd_get_template() keeps its own $name (the template path) over an argument of that name.
            'input_name' => $name,
            'label' => $label,
            'context' => $context,
            'value' => $show($amount),
            'min' => Amounts::fromMinor((int) $state['min'], $d),
            'max' => Amounts::fromMinor((int) $state['max'], $d),
            'decimals' => $d,
            'min_label' => Cart::label((int) $state['min'], $d, $currency),
            'max_label' => Cart::label((int) $state['max'], $d, $currency),
            'help' => Cart::helpText(['min' => (int) $state['min'], 'max' => (int) $state['max'], 'decimals' => $d]),
            'symbol' => html_entity_decode($symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'symbol_after' => function_exists('edd_get_option') && edd_get_option('currency_position', 'before') === 'after',
            'presets' => array_map(static function ($preset) use ($d, $show, $amount, $currency) {
                return ['value' => $show((int) $preset), 'label' => Cart::label((int) $preset, $d, $currency), 'pressed' => (int) $preset === $amount];
            }, (array) ($state['presets'] ?? [])),
        ];
    }

    /** @return array<string, mixed>|false script data for one variable price, or false for a fixed price */
    private function priceData(int $downloadId, int $priceId)
    {
        $bounds = $this->cart->bounds($downloadId, $priceId);
        if (!$bounds) {
            return false;
        }
        $args = self::fieldArgs($bounds + ['amount' => $bounds['default']], Cart::PRODUCT_FIELD, '', 'product');
        return [
            'value' => $args['value'],
            'min' => $args['min'],
            'max' => $args['max'],
            'decimals' => $args['decimals'],
            'minLabel' => $args['min_label'],
            'maxLabel' => $args['max_label'],
            'help' => $args['help'],
            'presets' => $args['presets'],
        ];
    }

    /**
     * Amount field at the top of a purchase form.
     *
     * @param mixed $downloadId
     * @param mixed $args
     */
    public function productField($downloadId = 0, $args = []): void
    {
        $downloadId = absint($downloadId);
        $args = is_array($args) ? $args : [];
        if (!$downloadId || $this->settings->settingsFor($downloadId) === null) {
            return;
        }
        $variable = function_exists('edd_has_variable_prices') && edd_has_variable_prices($downloadId);
        $fixedOption = isset($args['price_id']) && is_numeric($args['price_id']) ? (int) $args['price_id'] : null;
        $state = null;
        $prices = [];
        if (!$variable || $fixedOption !== null) {
            $bounds = $this->cart->bounds($downloadId, $fixedOption);
            if (!$bounds) {
                return;
            }
            $state = $bounds + ['amount' => $bounds['default']];
        } else {
            // Several options in one purchase (checkboxes) share no single amount: buyers choose at checkout.
            if (function_exists('edd_single_price_option_mode') && edd_single_price_option_mode($downloadId)) {
                return;
            }
            $options = function_exists('edd_get_variable_prices') ? (array) edd_get_variable_prices($downloadId) : [];
            foreach (array_keys($options) as $priceId) {
                if (is_numeric($priceId)) {
                    $prices[(string) $priceId] = $this->priceData($downloadId, (int) $priceId);
                }
            }
            if (!array_filter($prices)) {
                return;
            }
            $default = function_exists('edd_get_default_variable_price') ? edd_get_default_variable_price($downloadId) : null;
            $bounds = is_numeric($default) ? $this->cart->bounds($downloadId, (int) $default) : null;
            $state = $bounds ? $bounds + ['amount' => $bounds['default']] : null;
        }
        $this->enqueue();
        $base = $state ? self::fieldArgs($state, Cart::PRODUCT_FIELD, $this->options['heading'], 'product') : self::fieldArgs([
            'amount' => 0,
            'min' => 0,
            'max' => 0,
            'decimals' => stackless_edd_price_decimals(),
            'currency' => stackless_edd_current_currency(),
            'presets' => [],
        ], Cart::PRODUCT_FIELD, $this->options['heading'], 'product');
        stackless_edd_get_template('pay_what_you_like/amount-field.php', $base + [
            'note' => $this->options['note'],
            'product_id' => $downloadId,
            'variable' => $variable && $fixedOption === null,
            'prices' => $prices,
            // The default option has a fixed price: the field waits until an option that takes an amount is chosen.
            'hidden' => $state === null,
            'error' => '',
        ]);
    }

    /**
     * Downloads that take a chosen amount go through the cart, and their button shows no price:
     * the buyer sets it in the field above.
     *
     * @param mixed $args
     * @param mixed $download
     * @return mixed
     */
    public function purchaseLinkArgs($args, $download = null)
    {
        if (!is_array($args)) {
            return $args;
        }
        $downloadId = absint($args['download_id'] ?? 0);
        if (!$downloadId || $this->settings->settingsFor($downloadId) === null) {
            return $args;
        }
        $args['direct'] = false;
        $args['payment-request'] = false;
        $separator = '&nbsp;&ndash;&nbsp;';
        if (!empty($args['price']) && $args['price'] !== 'no' && is_string($args['text'] ?? null) && strpos($args['text'], $separator) !== false) {
            $args['text'] = substr($args['text'], strpos($args['text'], $separator) + strlen($separator));
        }
        return $args;
    }

    /**
     * @param mixed $enabled
     * @param mixed $downloadId
     * @return mixed
     */
    public function paymentRequestButton($enabled, $downloadId = 0)
    {
        return $this->settings->settingsFor(absint($downloadId)) !== null ? false : $enabled;
    }

    /**
     * "Pay what you like: this price or more" under prices.
     *
     * @param mixed $html
     * @param mixed $downloadId
     * @param mixed $price
     * @param mixed $priceId
     * @return mixed
     */
    public function priceNote($html, $downloadId = 0, $price = '', $priceId = false)
    {
        $downloadId = absint($downloadId);
        if (!$downloadId || !is_string($html) || $html === '' || (is_admin() && !wp_doing_ajax()) || strpos($html, 'stackless-price-unavailable') !== false) {
            return $html;
        }
        $option = is_numeric($priceId) ? (int) $priceId : null;
        if ($this->settings->settingsFor($downloadId, $option) === null) {
            return $html;
        }
        $variable = function_exists('edd_has_variable_prices') && edd_has_variable_prices($downloadId);
        $bounds = !$variable || $option !== null ? $this->cart->bounds($downloadId, $option) : null;
        if ($bounds && $bounds['custom_min']) {
            $text = $bounds['min'] > 0
                /* translators: %s: minimum price */
                ? sprintf(__('Pay what you like, from %s', 'stackless-lite-edd'), Cart::label($bounds['min'], $bounds['decimals'], $bounds['currency']))
                : __('Free, or pay what you like', 'stackless-lite-edd');
        } else {
            $amount = $bounds ? $bounds['price'] : null;
            if ($amount === null) {
                $lowest = function_exists('edd_get_lowest_price_option') && $variable ? edd_get_lowest_price_option($downloadId) : edd_get_download_price($downloadId);
                $amount = is_numeric($lowest) && (float) $lowest > 0 ? 1 : 0;
            }
            $text = $amount <= 0
                ? __('Free, or pay what you like', 'stackless-lite-edd')
                : __('Pay what you like: this price or more', 'stackless-lite-edd');
        }
        if (wp_style_is(self::STYLE, 'registered')) {
            wp_enqueue_style(self::STYLE);
        }
        return $html . ' <span class="stackless-pwyl-note">' . esc_html($text) . '</span>';
    }

    /**
     * Wraps the price of a chosen-amount cart line so the checkout script can update it in place.
     *
     * @param mixed $label
     * @param mixed $downloadId
     * @param mixed $options
     * @return mixed
     */
    public function linePriceLabel($label, $downloadId = 0, $options = [])
    {
        $options = is_array($options) ? $options : [];
        if (!is_string($label) || !$this->cart->lineState(['id' => absint($downloadId), 'options' => $options])) {
            return $label;
        }
        $line = absint($downloadId) . ':' . (Cart::optionPriceId($options) ?? '');
        return '<span class="stackless-pwyl-line-price" data-stackless-pwyl-line="' . esc_attr($line) . '">' . $label . '</span>';
    }

    /** "Name your price" box on the checkout, right above the purchase form. */
    public function checkoutBox(): void
    {
        if ($this->checkoutRendered) {
            return;
        }
        $fields = [];
        foreach ($this->cart->lines() as $key => $line) {
            $state = $line['state'];
            $fields[] = self::fieldArgs($state, Cart::KEY . '[' . $key . ']', $line['name'], 'checkout') + [
                'error' => $state['error'] !== '' ? Cart::boundsMessage($state['error'], $state, $line['name']) : '',
                'key' => (string) $key,
            ];
        }
        if (!$fields) {
            return;
        }
        $this->checkoutRendered = true;
        $this->enqueue();
        stackless_edd_get_template('pay_what_you_like/checkout-box.php', [
            'heading' => $this->options['heading'],
            'note' => $this->options['note'],
            'fields' => $fields,
            'nonce' => wp_create_nonce(Cart::NONCE_ACTION),
        ]);
    }
}
