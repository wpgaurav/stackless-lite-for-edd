<?php

namespace StacklessEDD\Modules\SquareGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Square gateway module: Square's Web Payments SDK card form with Apple Pay and Google Pay in
 * EDD's purchase form, cards on file for saved cards and automatic renewals through
 * Contracts\SubscriptionGateway and Contracts\SavedMethodCharger, refunds both ways and a
 * signed webhook created on save. EDD's own Square gateway can run next to it.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return Gateway::MODULE_ID;
    }

    public function title(): string
    {
        return __('Square', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards, Apple Pay and Google Pay with Square\'s Web Payments SDK at checkout, save cards on file, and renew subscriptions automatically. Charges in your Square location\'s currency; refunds and card updates sync through a signed webhook created for you.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function conflicts(): array
    {
        // EDD's own Square gateway can run alongside: different gateway id, customers, cards and webhook.
        return [];
    }

    public function install(): void
    {
        WebhookLog::install();
        Tokens::install();
    }

    public function boot(): void
    {
        $gateway = Gateway::instance();
        Gateways::add($gateway);

        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action(Payments::RETRY_HOOK, [Payments::class, 'retry']);
        add_action('edd_checkout_error_checks', [$gateway, 'validateFields'], 10, 2);

        // Zero-total carts whose subscription renews automatically still save a card here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        add_action('stackless_edd_settings_saved_' . $this->id(), [$this, 'settingsSaved']);

        Assets::registerHooks();
        Tokens::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
        Health::registerHooks();
    }

    /** The registered gateway object, or a standalone one when the module is off (staging scripts, CLI). */
    public static function gateway(): Gateway
    {
        return Gateway::instance();
    }

    public function settingsFields(): array
    {
        return Gateway::instance()->settingsFields();
    }

    /** Fill in the location and currency, create the webhook, and report what is missing. */
    public function settingsSaved(): void
    {
        foreach (array_unique(Gateway::instance()->afterSave()) as $error) {
            Fields::addError($error);
        }
    }
}
