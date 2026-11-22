<?php

namespace StacklessEDD\Modules\RevolutGateway;

use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/** The EDD screens, links and notices the payment flows use. */
final class Platform
{
    /** @param array<string,string|int> $args */
    public static function pageUrl(array $args): string
    {
        // EDD has no order-pay page or API endpoint for this: the pages answer on the home URL.
        return add_query_arg(array_merge([Pages::ACTION => '1'], $args), home_url('/'));
    }

    public static function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    public static function checkoutUrl(): string
    {
        return (string) edd_get_checkout_uri();
    }

    public static function methodsUrl(int $subscriptionId = 0): string
    {
        return $subscriptionId > 0 ? stackless_edd_account_url('subscriptions', ['id' => $subscriptionId]) : Account::listUrl();
    }

    /** @param array<string,mixed> $args */
    public static function render(array $args): void
    {
        status_header(200);
        stackless_edd_get_template('revolut_gateway/pay-page.php', $args + [
            'script' => \StacklessEDD\Core\Assets::url('revolut_gateway/checkout.js') . '?ver=' . rawurlencode(defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : '1'),
        ]);
        exit;
    }

    public static function emptyCart(): void
    {
        edd_empty_cart();
    }

    public static function checkoutError(string $message): void
    {
        // EDD documents the error id as int; every caller (EDD included) passes a string code.
        edd_set_error('stackless_edd_revolut_return', $message); // @phpstan-ignore argument.type
    }

    /** @param string $type success|error|info */
    public static function notice(string $message, string $type): void
    {
        stackless_edd_add_notice($message, $type);
    }

    public static function currency(): string
    {
        return strtoupper(stackless_edd_current_currency());
    }

    public static function gatewayEnabled(): bool
    {
        return function_exists('edd_is_gateway_active') && edd_is_gateway_active(Keys::GATEWAY_ID);
    }

    public static function reachable(string $url): bool
    {
        return WebhookAddress::problem($url, 'Revolut') === '';
    }

    public static function ordersUrl(): string
    {
        return admin_url('edit.php?post_type=download&page=edd-payment-history');
    }

    public static function logsUrl(): string
    {
        return admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log');
    }
}
