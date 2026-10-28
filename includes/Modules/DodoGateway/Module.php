<?php

namespace StacklessEDD\Modules\DodoGateway;

use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'dodo_gateway';
    }

    public function title(): string
    {
        return __('Dodo Payments', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Dodo Payments as a merchant of record: hosted checkout with sales tax, VAT and GST handled for you, cards, UPI, wallets and local methods, one-time orders in any currency Dodo supports, pay links, and single-download subscriptions with free trials that Dodo renews. Payments, renewals, refunds and cancellations sync both ways through signed webhooks.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
    }

    public function settingsFields(): array
    {
        return Settings::fields();
    }

    public function boot(): void
    {
        foreach (Gateway::all() as $gateway) {
            Gateways::add($gateway);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'route'], 0);
        // Payment Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_edd_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_edd_gateway_copy_removed', [Copies::class, 'removed']);
        // Refunds from EDD's refund form go to Dodo first; refunds made in Dodo come back by webhook.
        add_action('edd_after_submit_refund_table', [Refunds::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [Refunds::class, 'preflight'], 1);
        add_action('edd_refund_order', [Refunds::class, 'onRefund'], 10, 3);
        if (is_admin()) {
            add_filter('stackless_edd_settings_sanitize_option', [Settings::class, 'remember'], 10, 3);
            add_action('stackless_edd_settings_saved_' . $this->id(), [Settings::class, 'validate']);
        }
    }

    public function route(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the handler authenticates the request.
        $action = isset($_GET[Checkout::ENDPOINT]) ? sanitize_key(wp_unslash($_GET[Checkout::ENDPOINT])) : '';
        if ($action === 'return') {
            Checkout::handleReturn();
        }
    }
}
