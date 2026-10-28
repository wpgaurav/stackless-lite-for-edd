<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Small store improvements that don't need a module of their own, each with its own switch:
 * payment failure alerts, per-download payment methods and after-purchase links, coupon links,
 * internal test emails and a checkout spam guard.
 *
 * This module owns the internal-email contract other modules rely on:
 *   apply_filters('stackless_edd_skip_integrations', false, string $email)  true for internal/test addresses
 *   order meta _stackless_edd_internal_email = yes                          on their orders
 *
 * EDD already hides the payment method list when only one payment method can take the order, so
 * the WooCommerce edition's "hosted-only payment" switch has nothing left to do here.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return 'extra_enhancements';
    }

    public function title(): string
    {
        return __('Extra Enhancements', 'stackless-lite-edd');
    }

    public function description(): string
    {
        if (!self::hasAlerts()) {
            return __('Small fixes with their own switches: payment methods and an after-purchase link per download, ?coupon= links that stick, internal test emails that skip accounts and integrations, and a checkout guard against throwaway emails and card testing.', 'stackless-lite-edd');
        }
        return __('Small fixes with their own switches: email alerts for failed live payments, payment methods and an after-purchase link per download, ?coupon= links that stick, internal test emails that skip accounts and integrations, and a checkout guard against throwaway emails and card testing.', 'stackless-lite-edd');
    }

    /** Payment failure alerts watch the Stackless gateways, so builds without those gateways (Stackless Lite) leave them out. */
    private static function hasAlerts(): bool
    {
        return class_exists(PaymentAlerts::class);
    }

    public function group(): string
    {
        return 'checkout';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_extra_enhancements_';
        $alerts = !class_exists(PaymentAlerts::class) ? [] : [
            ['type' => 'title', 'id' => $p . 'alerts_section', 'title' => __('Payment failure alerts', 'stackless-lite-edd'), 'desc' => __('Emails you when a live payment fails or a payment provider’s API refuses a live request (refunds, checkouts, renewals). Test and sandbox activity never alerts. The email has no keys, card or customer details. Repeats of the same failure are held back for 30 minutes.', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'payment_alerts', 'title' => __('Alerts', 'stackless-lite-edd'), 'desc' => __('Email me about failed live payments', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'alert_email', 'title' => __('Send alerts to', 'stackless-lite-edd'), 'default' => '', 'placeholder' => (string) get_option('admin_email'), 'desc' => __('Leave empty to use the site admin email.', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'multiselect', 'id' => $p . 'alert_gateways', 'title' => __('Watch these gateways', 'stackless-lite-edd'), 'default' => array_keys(PaymentAlerts::GATEWAYS), 'options' => array_map(static function ($gateway) {
                return $gateway['label'];
            }, PaymentAlerts::GATEWAYS), 'desc' => __('The Stackless gateways. Clear the list to watch none.', 'stackless-lite-edd')],
            ['type' => 'sectionend', 'id' => $p . 'alerts_section'],
        ];
        return array_merge($alerts, [
            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'product_checkout', 'title' => __('Per-download checkout', 'stackless-lite-edd'), 'desc' => __('Choose payment methods and an after-purchase link on each download (the Checkout box on the download screen)', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'coupon_links', 'title' => __('Coupon links', 'stackless-lite-edd'), 'desc' => __('Any store link with ?coupon=CODE keeps the discount code for a day and applies it once the visitor has a cart', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            ['type' => 'title', 'id' => $p . 'internal_section', 'title' => __('Internal and test emails', 'stackless-lite-edd'), 'desc' => __('Orders from these addresses or domains are recorded as usual but don’t get an account from EDD’s automatic account creation and don’t run integrations (such as the Stackless FluentCRM sync). Use it for your own test purchases.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'internal_emails', 'title' => __('Emails and domains', 'stackless-lite-edd'), 'default' => '', 'placeholder' => "you@example.com\n@yourcompany.com", 'desc' => __('One per line. @example.com or example.com means the whole domain.', 'stackless-lite-edd'), 'css' => 'min-width:300px;height:90px'],
            ['type' => 'checkbox', 'id' => $p . 'internal_skip_accounts', 'title' => __('Accounts', 'stackless-lite-edd'), 'desc' => __('Don’t create a customer account', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'internal_skip_integrations', 'title' => __('Integrations', 'stackless-lite-edd'), 'desc' => __('Don’t run integrations', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'internal_section'],

            ['type' => 'title', 'id' => $p . 'guard_section', 'title' => __('Checkout guard', 'stackless-lite-edd'), 'desc' => __('Refuses throwaway email addresses and slows down a connection that places too many orders in an hour (card testing). Store managers are never limited.', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'guard_disposable', 'title' => __('Throwaway emails', 'stackless-lite-edd'), 'desc' => __('Refuse well-known throwaway inboxes (Mailinator, Guerrilla Mail, YOPmail and others)', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'textarea', 'id' => $p . 'guard_domains', 'title' => __('Also refuse', 'stackless-lite-edd'), 'default' => '', 'placeholder' => 'example-spam.com', 'desc' => __('More domains, one per line.', 'stackless-lite-edd'), 'css' => 'min-width:300px;height:70px'],
            ['type' => 'number', 'id' => $p . 'guard_hourly_limit', 'title' => __('Orders per hour', 'stackless-lite-edd'), 'default' => '10', 'desc' => __('Most order attempts one connection may make in an hour. 0 turns the limit off.', 'stackless-lite-edd'), 'css' => 'width:90px', 'custom_attributes' => ['min' => '0', 'step' => '1']],
            ['type' => 'sectionend', 'id' => $p . 'guard_section'],
        ]);
    }

    private function on(string $key): bool
    {
        return $this->option($key, 'yes') === 'yes';
    }

    public function boot(): void
    {
        if (class_exists(PaymentAlerts::class) && $this->on('payment_alerts')) {
            PaymentAlerts::init((string) $this->option('alert_email', ''), PaymentAlerts::selectedRoutes($this->option('alert_gateways', null)));
        }
        if ($this->on('product_checkout')) {
            (new ProductCheckout())->register();
        }
        if ($this->on('coupon_links')) {
            (new CouponLinks())->register();
        }
        $internal = InternalEmails::parse((string) $this->option('internal_emails', ''));
        if ($internal) {
            (new InternalEmails($internal, $this->on('internal_skip_accounts'), $this->on('internal_skip_integrations')))->register();
        }
        $guard = new CheckoutGuard($this->on('guard_disposable'), CheckoutGuard::parseDomains((string) $this->option('guard_domains', '')), (int) $this->option('guard_hourly_limit', 10));
        $guard->register();
    }
}
