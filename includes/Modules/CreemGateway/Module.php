<?php

namespace StacklessEDD\Modules\CreemGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    /** Custom settings field type that prints Gateway Tools' copies panel. */
    public const COPIES_FIELD = 'stackless_edd_creem_copies';

    public function id(): string
    {
        return 'creem_gateway';
    }

    public function title(): string
    {
        return __('Creem', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Creem as a merchant of record for software and digital products: hosted checkout with sales tax and VAT handled for you, one-time orders in US dollars or euros, and single-download subscriptions with free trials that Creem renews. Payments, renewals, failed renewals, refunds and cancellations sync both ways through signed webhooks, which are set up for you.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
    }

    public function boot(): void
    {
        foreach (Gateway::all() as $gateway) {
            Gateways::add($gateway);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'maybeHandleReturn'], 1);
        Refunds::register();
        // Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_edd_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_edd_gateway_copy_removed', [Copies::class, 'removed'], 10, 2);
        add_action('stackless_edd_settings_saved_creem_gateway', [$this, 'settingsSaved']);
        add_action('stackless_edd_admin_field_' . self::COPIES_FIELD, [$this, 'copiesField']);
        // What the store's health screens, payment alerts and one-click offers read.
        add_filter('stackless_edd_payment_alerts_gateways', [Health::class, 'alertRoute']);
        add_filter('stackless_edd_health_sources', [Health::class, 'register']);
        add_filter('stackless_edd_merchant_of_record_gateways', [Health::class, 'merchantOfRecord']);
    }

    /* ---- Settings ------------------------------------------------------------- */

    /**
     * The original gateway's settings, the limits, then one block per copy. Gateway Tools links a
     * copy as ?gateway=<copy id>; that view (and its save) holds only that copy's block.
     */
    public function settingsFields(): array
    {
        $fields = [];
        $gateways = Gateway::all();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which block to show.
        $only = isset($_GET['gateway']) ? sanitize_key(wp_unslash($_GET['gateway'])) : '';
        if ($only !== '' && $only !== Gateway::ID && Copies::belongs($only)) {
            $gateways = [Gateway::instance($only)];
        }
        foreach ($gateways as $gateway) {
            // Gateway Tools fills this in on the settings screen: duplicate, rename, remove.
            $panel = is_admin() ? (string) apply_filters('stackless_edd_gateway_copies_panel', '', $gateway->id()) : '';
            $fields = array_merge($fields, $gateway->settingsFields($panel));
            if ($gateway->isOriginal()) {
                $fields[] = [
                    'id' => Gateway::OPTION_PREFIX . 'limits_section',
                    'type' => 'title',
                    'title' => __('Good to know', 'stackless-lite-edd'),
                    'desc' => __('Creem charges in US dollars and euros, from 1.00. It bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Creem; free trials work. A subscription must be the only item in the cart, and it must renew automatically: Creem bills every subscription it takes. One-time carts of any size work and show in Creem as one store order. Products are created in your Creem catalog automatically. Creem runs the renewal schedule; renewals it charges become paid EDD renewal orders, and a failed renewal is retried by Creem. Refunds from EDD refund the whole payment; make partial refunds in the Creem dashboard and they appear here.', 'stackless-lite-edd'),
                ];
                $fields[] = ['id' => Gateway::OPTION_PREFIX . 'limits_section', 'type' => 'sectionend'];
            }
        }
        return $fields;
    }

    /** @param array<string,mixed> $field */
    public function copiesField(array $field): void
    {
        $html = (string) ($field['html'] ?? '');
        if ($html === '') {
            return;
        }
        // Markup from the Gateway Tools module, which escapes its own output.
        echo '<tr><td colspan="2" class="stackless-creem-copies">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function settingsSaved(): void
    {
        $errors = [];
        foreach (Gateway::all() as $gateway) {
            $errors = array_merge($errors, $gateway->afterSave());
        }
        if (!$errors) {
            return;
        }
        foreach (array_unique($errors) as $error) {
            Fields::addError($error);
        }
        // The settings screen stored its errors before this action ran.
        Fields::persistErrors();
    }

    /* ---- Requests ------------------------------------------------------------- */

    /** One return route serves Creem and every copy; the order says which one it used. */
    public function maybeHandleReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed key is checked in Checkout::handleReturn().
        if (!isset($_GET[Checkout::RETURN_ARG])) {
            return;
        }
        Checkout::handleReturn();
    }
}
