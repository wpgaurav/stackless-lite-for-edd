<?php

namespace StacklessEDD\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * The buyer coming back from Airwallex. The link only says which order to look at; whether it
 * is paid comes from the Airwallex API.
 */
final class Returns
{
    public const ARG = 'stackless_edd_airwallex_return';

    /** @param array<string,string|int> $args */
    public static function url(array $args): string
    {
        // A last argument nobody reads takes whatever a provider appends with "?" or "&".
        return add_query_arg([self::ARG => 1] + $args + ['awx' => 1], home_url('/'));
    }

    /** EDD's receipt page, through the same filter edd_send_to_success_page() applies (custom thank-you pages). */
    public static function successUrl(): string
    {
        return (string) apply_filters('edd_success_page_redirect', edd_get_success_page_uri(), Settings::GATEWAY_ID, null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter, applied the way EDD's gateways do.
    }

    /** template_redirect: the buyer came back from Airwallex's hosted page. */
    public static function handle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Airwallex sends the buyer back; the signed key or the stored reference authenticates the request.
        if (!isset($_GET[self::ARG])) {
            return;
        }
        $value = static function (string $name): string {
            return isset($_GET[$name]) && is_string($_GET[$name]) ? Checkout::param(sanitize_text_field(wp_unslash($_GET[$name]))) : '';
        };
        // phpcs:enable
        $back = $value('back') !== '';
        if ($value('flow') === 'add') {
            self::finishAdd(get_current_user_id(), $value('ref'), $back);
            return;
        }
        $sale = Checkout::returningOrder(absint($value('order_id')), $value('key'));
        if (!$sale) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $orderId = $sale->id();
        $outcome = (new Sync())->refresh($orderId);
        $sale = Sale::get($orderId);
        if ($sale && self::showsReceipt($sale, $outcome, $back)) {
            edd_empty_cart();
            wp_safe_redirect($sale->receiptUrl());
            exit;
        }
        // EDD documents the error id as int; every caller (EDD included) passes a string code.
        edd_set_error('stackless_edd_airwallex_return', !$back || $outcome === 'failed' // @phpstan-ignore argument.type
            ? __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd')
            : __('The payment was not completed. You can try again below.', 'stackless-lite-edd'));
        wp_safe_redirect($sale && $sale->payable() ? $sale->payUrl() : edd_get_checkout_uri());
        exit;
    }

    /** Paid, or possibly paid: never send those buyers back to pay again. */
    public static function showsReceipt(Sale $sale, string $outcome, bool $back): bool
    {
        if ($sale->paid() || $sale->waiting() || $outcome === 'pending' || $outcome === 'held') {
            return true;
        }
        return !$back && ($outcome === 'error' || $outcome === 'busy');
    }

    private static function finishAdd(int $userId, string $ref, bool $back): void
    {
        $result = (new Checkout())->finishAdd($userId, $ref);
        $status = $result['status'];
        $target = Account::listUrl();
        if ($status === 'saved') {
            stackless_edd_add_notice(__('Payment method successfully added.', 'stackless-lite-edd'));
            if ($result['subscription'] > 0) {
                $target = stackless_edd_account_url('subscriptions', ['id' => $result['subscription']]);
            }
        } elseif (($status === 'pending' && !$back) || $status === 'busy') {
            stackless_edd_add_notice(__('Airwallex is still confirming your card. It appears here once confirmed.', 'stackless-lite-edd'), 'info');
        } elseif ($status !== 'none') {
            stackless_edd_add_notice(__('Unable to add payment method to your account.', 'stackless-lite-edd'), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }
}
