<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps EDD purchases onto Creem products.
 *
 * A Creem checkout sells one product from the Creem catalog. One-time checkouts pass the
 * order amount as a custom price, so one product per download or variable price (plus one
 * shared store product for multi-item carts) and currency covers every price. Creem cannot set
 * a subscription's price or trial per checkout, so subscription products are created per
 * download price, billing schedule, recurring amount and trial length. Products are created on
 * first use and remembered per environment, separately for each gateway copy.
 */
final class Catalog
{
    public const OPTION = 'stackless_edd_creem_gateway_products';

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

    /**
     * Map key: "{mode}|{once|<schedule>:<amount>:t<trial days>}|{currency}|{v<download>-<price>|d<download>|cart|t<hash>}".
     *
     * @param array<string,mixed> $item
     * @param array<string,string|int>|null $billing
     */
    public static function key(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0): string
    {
        $schedule = $billing ? EventMap::intervalKey($billing) . ':' . $minor . ':t' . max(0, $trialDays) : 'once';
        if (!empty($item['download_id']) && isset($item['price_id'])) {
            $target = 'v' . (int) $item['download_id'] . '-' . (int) $item['price_id'];
        } elseif (!empty($item['download_id'])) {
            $target = 'd' . (int) $item['download_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $schedule . '|' . strtolower($currency) . '|' . $target;
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

    /** @return array<string,string> */
    public function map(): array
    {
        $map = get_option($this->option(), []);
        return is_array($map) ? $map : [];
    }

    /**
     * Resolve (or create) the Creem product for a checkout.
     *
     * @param array<string,mixed> $item
     * @param array<string,string|int>|null $billing Creem billing fields for subscriptions, null for one-time.
     * @param int $minor Recurring amount for subscriptions; the first price of a one-time product.
     * @return string|\WP_Error Creem product id.
     */
    public function productId(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0, bool $refresh = false)
    {
        $key = self::key($item, $billing, $currency, $minor, $mode, $trialDays);
        $map = $this->map();
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }
        $product = $this->create($item, $billing, $currency, $minor, $mode, $trialDays);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['id'];
        update_option($this->option(), $map, false);
        return (string) $product['id'];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string|int>|null $billing
     */
    public function forget(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0): void
    {
        $map = $this->map();
        unset($map[self::key($item, $billing, $currency, $minor, $mode, $trialDays)]);
        update_option($this->option(), $map, false);
    }

    /** Drop remembered products for a mode, e.g. after the key moved to another store or the tax settings changed. */
    public function forgetMode(string $mode): void
    {
        $map = array_filter($this->map(), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option($this->option(), $map, false);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string|int>|null $billing
     * @return array|\WP_Error
     */
    public function create(array $item, ?array $billing, string $currency, int $minor, string $mode, int $trialDays = 0)
    {
        $site = self::siteHost();
        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            /* translators: %s: site address */
            'description' => sprintf(__('Sold on %s', 'stackless-lite-edd'), $site),
            'price' => max(Money::MINIMUM, $minor),
            'currency' => strtoupper($currency),
            'billing_type' => $billing ? 'recurring' : 'onetime',
            'tax_mode' => $this->gateway->taxInclusive() ? 'inclusive' : 'exclusive',
            'tax_category' => $this->gateway->taxCategory(),
        ];
        if ($billing) {
            $body += $billing;
            if ($trialDays > 0) {
                $body['trial_period_days'] = $trialDays;
            }
        } else {
            $body['billing_period'] = 'once';
        }
        $body = (array) apply_filters('stackless_edd_creem_gateway_product_args', $body, $item, $billing, $mode, $this->gateway);
        $product = $this->gateway->api($mode)->request('POST', 'v1/products', $body);
        if (!is_wp_error($product) && empty($product['id'])) {
            return new \WP_Error('stackless_edd_creem_product', __('Creem did not return a product for this checkout.', 'stackless-lite-edd'));
        }
        if (!is_wp_error($product)) {
            $this->gateway->log('Created Creem product ' . $product['id'] . ' (' . self::key($item, $billing, $currency, $minor, $mode, $trialDays) . ').');
        }
        return $product;
    }

    /** Creem product names, trimmed to 100 characters. */
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
