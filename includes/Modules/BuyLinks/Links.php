<?php

namespace StacklessEDD\Modules\BuyLinks;

defined('ABSPATH') || exit;

/** Building and signing buy links with the store's secret. */
final class Links
{
    public const PARAM = 'stackless-edd-buy';
    public const SECRET_OPTION = 'stackless_edd_buy_links_secret';

    public static function secret(): string
    {
        $secret = get_option(self::SECRET_OPTION, '');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = self::rotate();
        }
        return $secret;
    }

    public static function rotate(): string
    {
        $secret = wp_generate_password(64, true, true);
        update_option(self::SECRET_OPTION, $secret, false);
        return $secret;
    }

    public static function signingRequired(): bool
    {
        return get_option('stackless_edd_buy_links_signing', 'off') === 'required';
    }

    /**
     * @param array[] $items product (download id), variation (price option id), qty
     * @param array   $args  coupon (string), clear ('', '0', '1'), exp (int), sign (bool)
     */
    public static function url(array $items, array $args = []): string
    {
        $query = [self::PARAM => LinkParser::itemsString($items)];
        $coupons = LinkParser::coupons((string) ($args['coupon'] ?? ''));
        if ($coupons) {
            $query['coupon'] = rawurlencode(implode(',', $coupons));
        }
        $clear = isset($args['clear']) ? (string) $args['clear'] : '';
        if ($clear === '0' || $clear === '1') {
            $query['clear'] = $clear;
        }
        // EDD has no separate cart page: links always open checkout. Old "to" values are still signed as given.
        $to = in_array($args['to'] ?? '', ['checkout', 'cart'], true) ? (string) $args['to'] : '';
        if ($to !== '') {
            $query['to'] = $to;
        }
        $sign = !empty($args['sign']) || self::signingRequired();
        $exp = $sign ? max(0, (int) ($args['exp'] ?? 0)) : 0;
        if ($exp) {
            $query['exp'] = (string) $exp;
        }
        if ($sign) {
            $query['sig'] = LinkParser::sign(['items' => $items, 'coupon' => $coupons, 'clear' => $clear, 'to' => $to, 'exp' => $exp], self::secret());
        }
        // Items, flags and the signature are digits, letters, colons and commas; only discount codes need encoding.
        return add_query_arg($query, home_url('/'));
    }
}
