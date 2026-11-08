<?php

namespace StacklessEDD\Modules\MercadoPagoGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'mercadopago_gateway';
    }

    public function title(): string
    {
        return __('Mercado Pago', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Mercado Pago Checkout Pro for stores in Argentina, Brazil, Chile, Colombia, Mexico, Peru and Uruguay: cards, Pix, boleto, OXXO, Mercado Pago balance and local methods, in the account’s own currency. Each payment is read back from Mercado Pago before the order is marked paid, cash and bank payments hold the order until they clear, refunds go back through Mercado Pago, and signed webhooks bring in refunds and chargebacks.', 'stackless-lite-edd');
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
        Gateways::add(Gateway::instance());
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [Checkout::class, 'handleReturn'], 1);
        Refunds::register();
        add_action('stackless_edd_settings_saved_mercadopago_gateway', [$this, 'settingsSaved']);
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
