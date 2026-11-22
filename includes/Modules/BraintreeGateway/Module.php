<?php

namespace StacklessEDD\Modules\BraintreeGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Braintree gateway module: Hosted Fields with 3-D Secure in EDD's purchase form, vaulted cards
 * for saved cards and automatic renewals through Contracts\SubscriptionGateway and
 * Contracts\SavedMethodCharger, refunds and voids both ways, and signed webhooks for disputes.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return Gateway::MODULE_ID;
    }

    public function title(): string
    {
        return __('Braintree', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Take cards with Braintree\'s Hosted Fields and 3-D Secure at checkout and on pay links, save cards, and renew subscriptions automatically. Charges each currency through its own merchant account; refunds, voids and disputes sync from Braintree.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
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
        add_action(Payments::CHECK_HOOK, [Payments::class, 'check']);
        add_action(Sync::SWEEP_HOOK, [Sync::class, 'sweep']);
        add_action('admin_init', [Sync::class, 'adminInit']);
        add_action('edd_checkout_error_checks', [$gateway, 'validateFields'], 10, 2);

        // Zero-total carts whose subscription renews automatically still save a card here.
        add_filter('edd_show_gateways', [$gateway, 'filterShowGateways']);
        add_filter('edd_chosen_gateway', [$gateway, 'filterChosenGateway'], 10, 2);
        add_action('edd_purchase_form_after_cc_form', [$gateway, 'zeroTotalFields'], 5);
        add_action('edd_gateway_manual', [$gateway, 'interceptManual'], 1);

        add_action('stackless_edd_settings_saved_' . $this->id(), [$this, 'settingsSaved']);

        Checkout::registerHooks();
        Assets::registerHooks();
        Tokens::registerHooks();
        Account::registerHooks();
        Refunds::registerHooks();
        Guard::registerHooks();
        Health::registerHooks();
        Privacy::registerHooks();
    }

    public function settingsFields(): array
    {
        return Gateway::instance()->settingsFields();
    }

    /** Check the connection and report what is missing. */
    public function settingsSaved(): void
    {
        foreach (array_unique(Gateway::instance()->afterSave()) as $error) {
            Fields::addError($error);
        }
    }
}
