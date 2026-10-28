<?php

namespace StacklessEDD\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Pay What You Like: buyers choose what to pay for a download, at or above a minimum (the
 * download's price unless it sets one). The chosen amount becomes the cart line's real price on
 * the server, so discounts, tax, order items and reports all see it.
 *
 * Buyers choose on purchase forms, in a "Name your price" box at checkout (shortcode and block
 * checkout), or through a link (?stackless_edd_price=25 on a checkout or buy link). Subscription
 * downloads keep their set price.
 */
final class Module extends \StacklessEDD\Core\Module
{
    /** @var ProductSettings|null */
    private $productSettings;

    /** @var Cart|null */
    private $cart;

    public function id(): string
    {
        return 'pay_what_you_like';
    }

    public function title(): string
    {
        return __('Pay What You Like', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Let buyers choose what to pay for a download, with a minimum, a suggested amount, quick amounts and an optional maximum. Works on purchase buttons, the checkout and buy links.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'sales';
    }

    public function conflicts(): array
    {
        return [
            'edd-custom-prices/edd-custom-prices.php' => 'EDD Custom Prices',
        ];
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_pay_what_you_like_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Pay what you like', 'stackless-lite-edd'), 'desc' => __('Turn it on per download in the "Pay what you like" box of the download editor. Each download\'s price is its minimum unless you set a lower one, so a price of 0 makes it "free, or pay what you like". Subscription downloads keep their set price.', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'heading', 'title' => __('Heading', 'stackless-lite-edd'), 'default' => __('Name your price', 'stackless-lite-edd'), 'desc_tip' => __('Label of the amount field on purchase forms and heading of the box at checkout.', 'stackless-lite-edd'), 'css' => 'min-width:260px'],
            ['type' => 'text', 'id' => $p . 'note', 'title' => __('Line under the heading', 'stackless-lite-edd'), 'default' => '', 'desc_tip' => __('Optional, for example "Every amount helps keep this project going."', 'stackless-lite-edd'), 'css' => 'min-width:360px'],
            ['type' => 'checkbox', 'id' => $p . 'product_field', 'title' => __('Purchase buttons', 'stackless-lite-edd'), 'desc' => __('Show the amount field above the purchase button. When off, buyers choose their amount at checkout.', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'product_note', 'title' => __('Price line', 'stackless-lite-edd'), 'desc' => __('Show "Pay what you like: this price or more" (or "Free, or pay what you like") under prices', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    /** @return array{heading: string, note: string, product_field: bool, product_note: bool} */
    public function options(): array
    {
        $heading = trim((string) $this->option('heading', ''));
        return [
            'heading' => $heading !== '' ? sanitize_text_field($heading) : __('Name your price', 'stackless-lite-edd'),
            'note' => sanitize_text_field(trim((string) $this->option('note', ''))),
            'product_field' => $this->option('product_field', 'yes') !== 'no',
            'product_note' => $this->option('product_note', 'yes') !== 'no',
        ];
    }

    public function boot(): void
    {
        $this->productSettings = new ProductSettings();
        $this->productSettings->register();
        $this->cart = new Cart($this->productSettings);
        $this->cart->register();
        // The default heading is translated, so the options are read once translations load (init).
        add_action('init', function () {
            if ($this->productSettings && $this->cart) {
                (new Storefront($this->productSettings, $this->cart, $this->options()))->register();
            }
        }, 5);
        // Prices change with the currency: forget limits worked out in the old one.
        add_action('stackless_edd_currency_switched', function () {
            if ($this->cart) {
                $this->cart->flush();
            }
        });
    }

    /** The cart service (staging scripts and integrations). */
    public function cart(): ?Cart
    {
        return $this->cart;
    }

    public function productSettings(): ?ProductSettings
    {
        return $this->productSettings;
    }
}
