<?php

namespace StacklessEDD\Modules\FlutterwaveGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'flutterwave_gateway';
    }

    public function title(): string
    {
        return __('Flutterwave', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Flutterwave for stores selling across Africa and beyond: cards, bank transfer, mobile money and USSD on Flutterwave’s hosted checkout, in naira, cedis, shillings, rand, CFA francs, dollars, euros and pounds. Each payment is verified with Flutterwave before the order is marked paid, refunds go back through Flutterwave, and webhooks bring in payments and refunds made in Flutterwave.', 'stackless-lite-edd');
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
        return Gateway::instance()->settingsFields();
    }

    public function boot(): void
    {
        $gateway = Gateway::instance();
        Gateways::add($gateway);
        add_filter('edd_payment_details_transaction_id-' . $gateway->id(), [$gateway, 'linkTransaction'], 10, 2);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [Checkout::class, 'handleReturn'], 1);
        Refunds::register();
        add_action('stackless_edd_settings_saved_flutterwave_gateway', [$this, 'settingsSaved']);
        add_filter('stackless_edd_payment_alerts_gateways', [Health::class, 'alertRoute']);
        add_filter('stackless_edd_health_sources', [Health::class, 'register']);
    }

    public function settingsSaved(): void
    {
        $errors = Gateway::instance()->afterSave();
        if (!$errors) {
            return;
        }
        foreach (array_unique($errors) as $error) {
            Fields::addError($error);
        }
        // The settings screen stored its errors before this action ran.
        Fields::persistErrors();
    }
}
