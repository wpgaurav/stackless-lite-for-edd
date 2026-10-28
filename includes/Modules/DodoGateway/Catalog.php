<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps EDD purchases onto Dodo Payments products.
 *
 * A Dodo checkout sells products from the Dodo catalog. One-time payments use a
 * pay-what-you-want product with no minimum, and each checkout passes the order
 * amount, so one product per download or variable price (plus one shared store product
 * for multi-item carts) covers every price. Dodo cannot set a subscription price per
 * checkout, so subscription products are created per download price, billing schedule
 * and recurring amount. Products are created on first use and remembered per
 * environment, separately for each gateway copy (each copy is its own business).
 */
final class Catalog
{
    public const OPTION = 'stackless_edd_dodo_gateway_products';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function option(): string
    {
        return self::OPTION . $this->gateway->suffix();
    }

    /** Map key: "{mode}|{once|Interval:count:amount}|{currency}|{v<download>_<price>|p<download>|cart|t<hash>}". */
    public static function key(array $item, ?array $recurring, string $currency, int $minor, string $mode): string
    {
        $billing = $recurring ? $recurring[0] . ':' . (int) $recurring[1] . ':' . $minor : 'once';
        if (!empty($item['download_id']) && isset($item['price_id']) && is_numeric($item['price_id'])) {
            $target = 'v' . (int) $item['download_id'] . '_' . (int) $item['price_id'];
        } elseif (!empty($item['download_id'])) {
            $target = 'p' . (int) $item['download_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $billing . '|' . strtolower($currency) . '|' . $target;
    }

    /** A variable price id when the download sells variable prices; null for a single-price download. @param mixed $priceId */
    public static function priceId(int $downloadId, $priceId): ?int
    {
        if ($priceId === null || $priceId === '' || !is_numeric($priceId)) {
            return null;
        }
        return function_exists('edd_has_variable_prices') && edd_has_variable_prices($downloadId) ? (int) $priceId : null;
    }

    /** One order item becomes its own product; several share a store product. @return array<string,mixed> */
    public static function itemForOrder(Order $order, ?SubscriptionData $subscription = null): array
    {
        if ($subscription) {
            $lines = array_values($subscription->get_items());
            if (count($lines) === 1) {
                $line = $lines[0];
                $downloadId = (int) $line['download_id'];
                return [
                    'title' => (string) $line['name'],
                    'download_id' => $downloadId,
                    'price_id' => self::priceId($downloadId, $line['price_id'] ?? null),
                ];
            }
        } else {
            $lines = array_values($order->get_items());
            if (count($lines) === 1) {
                $line = $lines[0];
                $downloadId = (int) $line->product_id;
                return [
                    'title' => (string) $line->product_name,
                    'download_id' => $downloadId,
                    'price_id' => self::priceId($downloadId, $line->price_id),
                ];
            }
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

    /** @return array<string,string> */
    public function map(): array
    {
        $map = get_option($this->option(), []);
        return is_array($map) ? $map : [];
    }

    /**
     * Resolve (or create) the Dodo product for a checkout.
     *
     * @param int $minor Recurring amount for subscriptions; ignored for one-time products.
     * @return string|\WP_Error Dodo product id.
     */
    public function productId(array $item, ?array $recurring, string $currency, int $minor, string $mode, bool $refresh = false)
    {
        $key = self::key($item, $recurring, $currency, $minor, $mode);
        $map = $this->map();
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }
        $product = $this->create($item, $recurring, $currency, $minor, $mode);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['product_id'];
        update_option($this->option(), $map, false);
        return (string) $product['product_id'];
    }

    public function forget(array $item, ?array $recurring, string $currency, int $minor, string $mode): void
    {
        $map = $this->map();
        unset($map[self::key($item, $recurring, $currency, $minor, $mode)]);
        update_option($this->option(), $map, false);
    }

    /** Drop remembered products for a mode, e.g. after the key moved to another business or the tax settings changed. */
    public function forgetMode(string $mode): void
    {
        $map = array_filter($this->map(), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option($this->option(), $map, false);
    }

    /** @return array|\WP_Error */
    public function create(array $item, ?array $recurring, string $currency, int $minor, string $mode)
    {
        if ($recurring) {
            $price = [
                'type' => 'recurring_price',
                'currency' => strtoupper($currency),
                'price' => max(0, $minor),
                'discount_bps' => 0,
                'purchasing_power_parity' => false,
                'payment_frequency_interval' => $recurring[0],
                'payment_frequency_count' => (int) $recurring[1],
                // Renews until cancelled: Dodo's recommended (and longest) term.
                'subscription_period_interval' => 'Year',
                'subscription_period_count' => 20,
                'tax_inclusive' => $this->gateway->taxInclusive(),
            ];
        } else {
            // Pay what you want with no minimum: every checkout passes its own amount.
            $price = [
                'type' => 'one_time_price',
                'currency' => strtoupper($currency),
                'price' => 0,
                'pay_what_you_want' => true,
                'discount_bps' => 0,
                'purchasing_power_parity' => false,
                'tax_inclusive' => $this->gateway->taxInclusive(),
            ];
        }

        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            'tax_category' => $this->gateway->taxCategory(),
            'price' => $price,
            'metadata' => array_map('strval', array_filter([
                'source' => 'stackless-edd',
                'edd_download_id' => (int) ($item['download_id'] ?? 0),
                'edd_price_id' => isset($item['price_id']) && is_numeric($item['price_id']) ? (string) (int) $item['price_id'] : '',
                'site' => self::siteHost(),
            ], static function ($value): bool {
                return $value !== '' && $value !== 0;
            })),
        ];
        $body = (array) apply_filters('stackless_edd_dodo_gateway_product_args', $body, $item, $recurring, $mode, $this->gateway);
        $product = $this->gateway->api($mode)->request('POST', 'products', $body);
        if (!is_wp_error($product) && empty($product['product_id'])) {
            return new \WP_Error('stackless_edd_dodo_product', __('Dodo Payments did not return a product for this checkout.', 'stackless-lite-edd'));
        }
        if (!is_wp_error($product)) {
            $this->gateway->log('Created Dodo product ' . $product['product_id'] . ' (' . self::key($item, $recurring, $currency, $minor, $mode) . ').');
        }
        return $product;
    }

    /** Dodo product names, trimmed to 100 characters. */
    public static function name(string $title): string
    {
        $title = trim(wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')));
        if ($title === '') {
            $title = __('Purchase', 'stackless-lite-edd');
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($title) > 100 ? rtrim(mb_substr($title, 0, 99)) . '…' : $title;
        }
        return strlen($title) > 100 ? rtrim(substr($title, 0, 97)) . '...' : $title;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }
}
