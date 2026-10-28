<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Coupon links: any store link with ?coupon=CODE remembers the discount code for a day and
 * applies it, with EDD's own discount rules, as soon as the visitor has a cart (a Purchase
 * button, AJAX add to cart, or a cart that is already there). EDD's own ?discount= links keep
 * working as before.
 *
 * Buy Links and On-page Checkout carry their own coupon parameter; their links are left to them so
 * a code is never applied twice. Inside an embedded checkout (On-page or Modal Checkout) the cart
 * is the frame's own, so a remembered code waits for the store cart.
 */
final class CouponLinks
{
    public const COOKIE = 'stackless_edd_coupon';
    public const PARAM = 'coupon';

    public function register(): void
    {
        add_action('init', [$this, 'capture'], 20);
        add_action('template_redirect', [$this, 'apply'], 5);
        add_action('edd_post_add_to_cart', [$this, 'apply'], 20, 0);
    }

    public static function sanitize($code): string
    {
        $code = is_string($code) ? trim(sanitize_text_field(wp_unslash($code))) : '';
        return preg_match('/^[\p{L}\p{N}_.@\-]{1,50}$/u', $code) ? $code : '';
    }

    /** Links that apply their own coupon. */
    private static function handledElsewhere(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
        return isset($_GET['stackless-edd-buy']) || isset($_GET['stackless-edd-checkout-frame']);
    }

    public function capture(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public link parameter, applied with EDD's discount checks.
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || empty($_GET[self::PARAM]) || self::handledElsewhere()) {
            return;
        }
        $code = self::sanitize($_GET[self::PARAM]);
        // phpcs:enable
        if ($code === '') {
            return;
        }
        $_COOKIE[self::COOKIE] = $code;
        self::cookie($code, time() + DAY_IN_SECONDS);
    }

    private static function cookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && constant('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function forget(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::cookie('', time() - HOUR_IN_SECONDS);
    }

    /** A request of an embedded checkout, whose cart is not the visitor's store cart. */
    public static function inFrame(): bool
    {
        return false; // Embedded checkouts are not part of Stackless Lite.
    }

    public static function pending(): string
    {
        return self::sanitize($_COOKIE[self::COOKIE] ?? '');
    }

    public function apply(): void
    {
        $code = self::pending();
        if ($code === '' || !function_exists('EDD') || !EDD()->cart || (is_admin() && !wp_doing_ajax()) || self::inFrame()) {
            return;
        }
        // No cart yet: keep the code until the visitor adds something.
        if (!edd_get_cart_contents()) {
            return;
        }
        $applied = array_map('strtolower', (array) edd_get_cart_discounts());
        // EDD checks the discount's own rules and shows its message at checkout; a refused code is dropped, not retried.
        if (!in_array(strtolower($code), $applied, true) && edd_is_discount_valid($code, '', true)) {
            edd_set_cart_discount($code);
        }
        self::forget();
    }
}
