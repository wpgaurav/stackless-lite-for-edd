<?php

namespace StacklessEDD\Modules\PolarGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    /** Custom settings field type that prints Gateway Tools' copies panel. */
    public const COPIES_FIELD = 'stackless_edd_polar_copies';

    public function id(): string
    {
        return 'polar_gateway';
    }

    public function title(): string
    {
        return __('Polar', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Polar as a merchant of record: hosted checkout with sales tax and VAT handled for you, one-time orders, pay links, and single-download subscriptions with free trials that Polar renews. Payments, renewals, refunds and cancellations sync both ways through signed webhooks.', 'stackless-lite-edd');
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
            add_filter('edd_payment_details_transaction_id-' . $gateway->id(), [$gateway, 'linkTransaction'], 10, 2);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'maybeHandleReturn'], 1);
        if (is_admin()) {
            Catalog::registerAdmin();
        }
        Refunds::register();
        // Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_edd_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_edd_gateway_copy_removed', [Copies::class, 'removed'], 10, 2);
        add_action('stackless_edd_settings_saved_polar_gateway', [$this, 'settingsSaved']);
        add_action('stackless_edd_admin_field_' . self::COPIES_FIELD, [$this, 'copiesField']);
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
                    'desc' => __('Polar sells one product per checkout. One-time carts of any size work and show in Polar as one store order. A subscription must be the only item in the cart, and Polar bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Polar. Free trials work. Polar runs the renewal schedule; renewals it charges become paid EDD renewal orders.', 'stackless-lite-edd'),
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
        echo '<tr><td colspan="2" class="stackless-polar-copies">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

    /** One return route serves Polar and every copy; the order says which one it used. */
    public function maybeHandleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed key is checked in Checkout::handleReturn().
        if (!isset($_GET[Checkout::RETURN_ARG])) {
            return;
        }
        $order = isset($_GET['order_id']) ? Orders::get(absint($_GET['order_id'])) : null;
        // phpcs:enable
        $gateway = $order ? Gateway::forOrder($order) : null;
        (new Checkout($gateway ?: Gateway::instance()))->handleReturn();
    }
}
