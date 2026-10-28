<?php
/**
 * Cross-module API.
 *
 * Every function here is safe to call whether or not the owning module is enabled:
 * each returns a neutral default through a filter, and the owning module hooks that
 * filter to provide the real answer. Modules talk to each other only through these
 * functions, the actions documented in ARCHITECTURE.md, and the Contracts\ interfaces.
 *
 * The helpers at the end replace the WooCommerce functions Stackless for WooCommerce used
 * (wc_price, wc_format_decimal, wc_add_notice, wc_get_template, order-pay links).
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;

/** True when a module is enabled and booted on this request. */
function stackless_edd_module_active(string $id): bool
{
    return class_exists(\StacklessEDD\Core\Plugin::class) && \StacklessEDD\Core\Plugin::instance()->modules()->isActive($id);
}

/* ---------------------------------------------------------------------------
 * Subscriptions (answered by the Subscriptions module)
 * ------------------------------------------------------------------------- */

/** @return \StacklessEDD\Contracts\SubscriptionData|null */
function stackless_edd_get_subscription($subscription_id)
{
    return apply_filters('stackless_edd_get_subscription', null, (int) $subscription_id);
}

/**
 * @param array $args status => string[] (SubscriptionData::STATUS_*), download_id => int
 * @return \StacklessEDD\Contracts\SubscriptionData[]
 */
function stackless_edd_get_customer_subscriptions(int $user_id, array $args = []): array
{
    return (array) apply_filters('stackless_edd_get_customer_subscriptions', [], $user_id, $args);
}

/** Active, trialing or pending-cancel (still inside a paid period). $download_id 0 = any download. */
function stackless_edd_customer_has_active_subscription(int $user_id, int $download_id = 0): bool
{
    return (bool) apply_filters('stackless_edd_customer_has_active_subscription', false, $user_id, $download_id);
}

/** A download (or one of its variable prices) sold as a subscription. */
function stackless_edd_is_subscription_product(int $download_id, ?int $price_id = null): bool
{
    return (bool) apply_filters('stackless_edd_is_subscription_product', false, $download_id, $price_id);
}

/** Subscriptions created by, or renewed by, this order. @return \StacklessEDD\Contracts\SubscriptionData[] */
function stackless_edd_get_order_subscriptions(int $order_id): array
{
    return (array) apply_filters('stackless_edd_get_order_subscriptions', [], $order_id);
}

function stackless_edd_order_contains_subscription(int $order_id): bool
{
    return (bool) apply_filters('stackless_edd_order_contains_subscription', false, $order_id);
}

function stackless_edd_cart_contains_subscription(): bool
{
    return (bool) apply_filters('stackless_edd_cart_contains_subscription', false);
}

/** Number of distinct subscriptions the current cart will create (one per billing schedule). */
function stackless_edd_cart_subscription_count(): int
{
    return (int) apply_filters('stackless_edd_cart_subscription_count', 0);
}

function stackless_edd_is_renewal_order(int $order_id): bool
{
    return (bool) apply_filters('stackless_edd_is_renewal_order', false, $order_id);
}

/**
 * True when the gateway must save a reusable payment method for this order
 * (it contains a subscription set to renew automatically). Gateways read this.
 */
function stackless_edd_order_requires_saved_payment_method(int $order_id): bool
{
    return (bool) apply_filters('stackless_edd_order_requires_saved_payment_method', false, $order_id);
}

/** Cart-time version of the above, for showing "save card" UI and SetupIntent flows. */
function stackless_edd_cart_requires_saved_payment_method(): bool
{
    return (bool) apply_filters('stackless_edd_cart_requires_saved_payment_method', false);
}

/**
 * Create a pending renewal order for a subscription (copies items, customer, currency,
 * gateway). Used by gateway-managed billing (Polar, Paddle, Dodo) when the provider reports a charge.
 *
 * @param \StacklessEDD\Contracts\SubscriptionData $subscription
 * @return int|\WP_Error renewal order id
 */
function stackless_edd_create_renewal_order($subscription)
{
    return apply_filters('stackless_edd_create_renewal_order', new \WP_Error('stackless_edd_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite-edd')), $subscription);
}

/**
 * Page through subscriptions for the REST API and other admin tooling.
 *
 * @param array $args status => string[], customer_id => int, user_id => int, download_id => int,
 *                    page => int (1-based), per_page => int (1-100)
 * @return array{items: \StacklessEDD\Contracts\SubscriptionData[], total: int}
 */
function stackless_edd_query_subscriptions(array $args = []): array
{
    $result = apply_filters('stackless_edd_query_subscriptions', null, $args);
    return is_array($result) ? $result + ['items' => [], 'total' => 0] : ['items' => [], 'total' => 0];
}

/**
 * The parent order plus the renewal and switch orders of a subscription, newest first.
 *
 * @return \EDD\Orders\Order[]
 */
function stackless_edd_get_subscription_orders(int $subscription_id): array
{
    return (array) apply_filters('stackless_edd_get_subscription_orders', [], $subscription_id);
}

/**
 * Cancel a subscription now, or at the end of the paid period.
 *
 * @param string $by admin|customer|system (shown in the subscription notes)
 * @return true|\WP_Error
 */
function stackless_edd_cancel_subscription(int $subscription_id, bool $at_period_end = true, string $by = 'admin')
{
    return apply_filters('stackless_edd_cancel_subscription', new \WP_Error('stackless_edd_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite-edd')), $subscription_id, $at_period_end, $by);
}

/**
 * Withdraw a pending cancellation, or resume an on-hold subscription.
 *
 * @return true|\WP_Error
 */
function stackless_edd_reactivate_subscription(int $subscription_id, string $by = 'admin')
{
    return apply_filters('stackless_edd_reactivate_subscription', new \WP_Error('stackless_edd_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite-edd')), $subscription_id, $by);
}

/**
 * Move a subscription to a status (SubscriptionData::STATUS_*) with an optional note. Gateway-managed
 * billing uses this for provider-side on-hold, expiry and resumption; cancellation and reactivation
 * go through stackless_edd_cancel_subscription() / stackless_edd_reactivate_subscription().
 *
 * @return true|\WP_Error
 */
function stackless_edd_update_subscription_status(int $subscription_id, string $status, string $note = '')
{
    return apply_filters('stackless_edd_update_subscription_status', new \WP_Error('stackless_edd_subscriptions_inactive', __('Subscriptions module is not active.', 'stackless-lite-edd')), $subscription_id, $status, $note);
}

/** Add a note to a subscription's history (shown on its admin screen). */
function stackless_edd_add_subscription_note(int $subscription_id, string $note): void
{
    do_action('stackless_edd_add_subscription_note', $subscription_id, $note);
}

/* ---------------------------------------------------------------------------
 * Software licensing (answered by the Software Licensing module)
 * ------------------------------------------------------------------------- */

/**
 * One license as an array: id, license_key, download_id, price_id, order_id, subscription_id,
 * user_id, customer_id, customer_email, status (stored), effective_status (expiry applied),
 * activation_limit, activations (count), expires_at, created_at, updated_at (UTC, Y-m-d H:i:s).
 *
 * @return array<string,mixed>|null
 */
function stackless_edd_get_license(int $license_id): ?array
{
    $license = apply_filters('stackless_edd_get_license', null, $license_id);
    return is_array($license) ? $license : null;
}

/**
 * Licenses of an account, including guest purchases made with the same email, newest first.
 *
 * @return array<int,array<string,mixed>>
 */
function stackless_edd_get_customer_licenses(int $user_id, string $email = ''): array
{
    return (array) apply_filters('stackless_edd_get_customer_licenses', [], $user_id, $email);
}

/*
 * Licensing filters other plugins may call (answered by Software Licensing while it is active):
 *
 * apply_filters('stackless_edd_license_admin_url', '', int $license_id): string
 *     The license's admin screen, '' when the module is off.
 * apply_filters('stackless_edd_license_product_terms', null, int $download_id, ?int $price_id): ?array
 *     A licensed download's terms for one price option (null = the download's own settings):
 *     activation_limit (int, 0 = unlimited sites), validity (lifetime|subscription|days),
 *     validity_days (int, only for "days", else null), version, requires_wp, tested_wp,
 *     requires_php (strings, '' when unset) and software_type (plugin|theme). Null when the
 *     download is not licensed or the module is off.
 */

/* ---------------------------------------------------------------------------
 * Invoices and quotes (answered by the Custom Invoices and Request a Quote modules)
 * ------------------------------------------------------------------------- */

/**
 * Issued invoices (never drafts) of an account, plus guest invoices sent to its email addresses
 * or to $email, newest first. Each item: id, number, title, status (sent|overdue|paid|cancelled|
 * refunded), status_label, open (awaiting payment or overdue), total (decimal string), currency,
 * due_date (Y-m-d, '' when due on receipt), issued_at (UTC Y-m-d H:i:s or ''), admin_url,
 * customer_url (the signed invoice page) and pay_url ('' unless it can be paid now).
 * Empty when Custom Invoices is off.
 *
 * @param array $args open => bool (only open invoices), number => int (1-100, default 20)
 * @return array<int,array<string,mixed>>
 */
function stackless_edd_get_customer_invoices(int $user_id, string $email = '', array $args = []): array
{
    return (array) apply_filters('stackless_edd_get_customer_invoices', [], $user_id, $email, $args);
}

/**
 * Quote requests made with an account's email addresses or with $email, newest first. Each item:
 * id, number, title (download and option, or the start of the message), status (requested|
 * proposal|offered|expired|accepted|declined|withdrawn), status_label, open (waiting on the store
 * or the customer), total (decimal string, '' before a proposal), currency, due_date (the
 * proposal's valid-through date, Y-m-d or ''), admin_url and customer_url (the proposal page once
 * it was sent, else ''). Empty when Request a Quote is off.
 *
 * @param array $args open => bool (only open quotes), number => int (1-100, default 20)
 * @return array<int,array<string,mixed>>
 */
function stackless_edd_get_customer_quotes(int $user_id, string $email = '', array $args = []): array
{
    return (array) apply_filters('stackless_edd_get_customer_quotes', [], $user_id, $email, $args);
}

/* An invoice's admin screen: apply_filters('stackless_edd_invoice_admin_url', '', int $invoice_id) (answered by Custom Invoices). */

/**
 * Output of the stackless_edd_account_item_actions action for one item on the account page:
 * buttons other plugins add beside a subscription or a license (a help desk's "Get help").
 * Templates print it inside their own actions area, so nothing renders when no one hooks in.
 *
 * @param string $type subscription|license
 * @param mixed  $data subscription: the \StacklessEDD\Contracts\SubscriptionData object;
 *                     license: the license array in the stackless_edd_get_license() shape
 */
function stackless_edd_account_item_actions_html(string $type, int $id, $data): string
{
    ob_start();
    do_action('stackless_edd_account_item_actions', $type, $id, $data);
    return trim((string) ob_get_clean());
}

/* ---------------------------------------------------------------------------
 * Currency (answered by the Multi-Currency Checkout module)
 * ------------------------------------------------------------------------- */

/** The settlement currency for the current visitor's cart. */
function stackless_edd_current_currency(): string
{
    return (string) apply_filters('stackless_edd_current_currency', stackless_edd_base_currency());
}

/** Store base currency (Downloads → Settings → General → Currency), unaffected by the visitor's choice. */
function stackless_edd_base_currency(): string
{
    $currency = function_exists('edd_get_option') ? edd_get_option('currency', 'USD') : 'USD';
    return strtoupper((string) ($currency ?: 'USD'));
}

/** Decimals of a currency (EDD's rules: 0 for zero-decimal currencies such as JPY). '' = visitor's currency. */
function stackless_edd_price_decimals(string $currency = ''): int
{
    $currency = $currency !== '' ? $currency : stackless_edd_current_currency();
    return function_exists('edd_currency_decimal_filter') ? (int) edd_currency_decimal_filter(2, $currency) : 2;
}

/** Decimals of the store's base currency (runtime prices, add-on charges and bump amounts are base amounts). */
function stackless_edd_base_price_decimals(): int
{
    return stackless_edd_price_decimals(stackless_edd_base_currency());
}

/** @return string[] ISO codes enabled for checkout (base currency only when the module is off). */
function stackless_edd_enabled_currencies(): array
{
    return (array) apply_filters('stackless_edd_enabled_currencies', [stackless_edd_base_currency()]);
}

/**
 * Convert an amount between currencies at the configured rate (no charm rounding).
 * $to defaults to the visitor's currency, $from to the base currency. Returns the input unchanged when the module is off.
 *
 * @param float|string $amount
 * @return float|string
 */
function stackless_edd_convert_amount($amount, string $to = '', string $from = '')
{
    return apply_filters('stackless_edd_convert_amount', $amount, $to, $from);
}

/** Effective rate (markup included) from base to $currency; 0 when unknown, 1 when the module is off. */
function stackless_edd_currency_rate(string $currency = ''): float
{
    return (float) apply_filters('stackless_edd_currency_rate', 1.0, $currency);
}

/**
 * Switch the visitor's cart currency. Result is a WP_Error or true; false when the module is off.
 *
 * @return true|false|\WP_Error
 */
function stackless_edd_switch_currency(string $currency, string $source = 'api')
{
    return apply_filters('stackless_edd_switch_currency', false, $currency, $source);
}

/** A subscription signup fee in $currency (price book first, then conversion). */
function stackless_edd_currency_signup_fee(float $fee, int $download_id, ?int $price_id = null, string $currency = ''): float
{
    return (float) apply_filters('stackless_edd_currency_signup_fee', $fee, $download_id, $price_id, $currency);
}

/* ---------------------------------------------------------------------------
 * AI agents (dispatcher: Core\Agent; families come from each module)
 * ------------------------------------------------------------------------- */

/**
 * Run an agent command as the signed-in user, e.g. stackless_edd_agent('invoice.list') or
 * stackless_edd_agent('subscription.get', ['id' => 42]). "commands" lists what this site supports.
 * Returns ['ok' => bool, ...]; never throws.
 */
function stackless_edd_agent(string $command, array $input = []): array
{
    return \StacklessEDD\Core\Agent::call($command, $input);
}

/* ---------------------------------------------------------------------------
 * Gateways (Core\Gateway, Core\Gateways)
 * ------------------------------------------------------------------------- */

/** The Stackless gateway object for an EDD gateway id; null for EDD's own and third-party gateways. */
function stackless_edd_get_gateway(string $gateway_id): ?\StacklessEDD\Core\Gateway
{
    return \StacklessEDD\Core\Gateways::get($gateway_id);
}

/**
 * Mark an order paid: records the gateway transaction and moves it to "complete" (EDD then
 * delivers files, sends the receipt and fires edd_complete_purchase). Safe to call twice.
 */
function stackless_edd_complete_order(int $order_id, string $transaction_id = '', string $note = ''): bool
{
    $order = edd_get_order($order_id);
    if (!$order) {
        return false;
    }
    if ($transaction_id !== '' && function_exists('edd_add_order_transaction')) {
        $existing = edd_get_order_transactions(['object_id' => $order_id, 'object_type' => 'order', 'transaction_id' => $transaction_id, 'number' => 1]);
        if (!$existing) {
            edd_add_order_transaction([
                'object_id' => $order_id,
                'object_type' => 'order',
                'transaction_id' => $transaction_id,
                'gateway' => $order->gateway,
                'status' => 'complete',
                'total' => $order->total,
            ]);
        }
    }
    if ($note !== '') {
        stackless_edd_add_order_note($order_id, $note);
    }
    if (in_array($order->status, ['complete', 'partially_refunded', 'refunded', 'revoked'], true)) {
        return true;
    }
    return (bool) edd_update_order_status($order_id, 'complete');
}

/** A note on an order's Notes panel. */
function stackless_edd_add_order_note(int $order_id, string $note): void
{
    if ($order_id > 0 && $note !== '' && function_exists('edd_add_note')) {
        edd_add_note([
            'object_id' => $order_id,
            'object_type' => 'order',
            'content' => $note,
            'user_id' => is_admin() ? get_current_user_id() : 0,
        ]);
    }
}

/**
 * Where a customer pays a pending order (renewals, invoices, quotes): EDD's order recovery link,
 * which loads the order into the cart and reuses it at checkout.
 */
function stackless_edd_order_pay_url(int $order_id): string
{
    $order = edd_get_order($order_id);
    $url = $order ? (string) $order->get_recovery_url() : '';
    return (string) apply_filters('stackless_edd_order_pay_url', $url, $order_id);
}

/**
 * Saved payment methods of a user (WC_Payment_Tokens counterpart), from every Stackless gateway
 * that stores them, or one gateway. Each item: id, gateway, label, is_default, expires (Y-m or '').
 *
 * @return array<int,array<string,mixed>>
 */
function stackless_edd_get_saved_payment_methods(int $user_id, string $gateway_id = ''): array
{
    return (array) apply_filters('stackless_edd_saved_payment_methods', [], $user_id, $gateway_id);
}

/** Where a customer adds a payment method for a gateway, optionally to switch a subscription onto it. '' when unsupported. */
function stackless_edd_add_payment_method_url(string $gateway_id, int $subscription_id = 0): string
{
    return (string) apply_filters('stackless_edd_add_payment_method_url', '', $gateway_id, $subscription_id);
}

/**
 * True for a cart line whose price, quantity and fees are fixed by the order it pays (an invoice
 * line, a subscription renewal or switch payment). Modules that change cart prices, quantities,
 * fees or offers (currency conversion, add-ons, pay what you like, bumps, limits, signup fees)
 * leave these lines alone. Invoices and Subscriptions answer the filter for their own lines.
 *
 * @param array<string,mixed> $options cart item options
 */
function stackless_edd_cart_item_is_locked(int $download_id, array $options): bool
{
    return (bool) apply_filters('stackless_edd_cart_item_is_locked', false, $download_id, $options);
}

/** True when any line in the cart is locked (see stackless_edd_cart_item_is_locked()). */
function stackless_edd_cart_has_locked_items(): bool
{
    foreach ((array) (function_exists('edd_get_cart_contents') ? edd_get_cart_contents() : []) as $item) {
        if (is_array($item) && stackless_edd_cart_item_is_locked((int) ($item['id'] ?? 0), (array) ($item['options'] ?? []))) {
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------------------------
 * Money and notices (replace wc_price, wc_format_decimal, wc_add_notice)
 * ------------------------------------------------------------------------- */

/**
 * A formatted price with the currency symbol, like wc_price(). Escaped HTML-safe text.
 *
 * @param float|string $amount
 */
function stackless_edd_price($amount, string $currency = ''): string
{
    $currency = $currency !== '' ? $currency : stackless_edd_current_currency();
    // EDD documents $decimals as string; it takes a bool.
    $formatted = edd_format_amount((float) $amount, true, $currency, 'display'); // @phpstan-ignore argument.type
    return '<span class="stackless-price">' . edd_currency_filter($formatted, $currency) . '</span>';
}

/**
 * A decimal string rounded to $decimals (default: the currency's decimals), like wc_format_decimal().
 * Accepts "1,234.50" and localized input through edd_sanitize_amount().
 *
 * @param float|int|string $amount
 */
function stackless_edd_decimal($amount, ?int $decimals = null, string $currency = ''): string
{
    $decimals = $decimals ?? stackless_edd_price_decimals($currency);
    $value = is_string($amount) ? edd_sanitize_amount($amount) : $amount;
    return number_format((float) $value, max(0, $decimals), '.', '');
}

/**
 * Show a message to the shopper on the next page view (cart, checkout, account, product pages).
 * Checkout validation errors should use edd_set_error() inside edd_checkout_error_checks instead.
 *
 * @param string $type success|error|info
 */
function stackless_edd_add_notice(string $message, string $type = 'success'): void
{
    \StacklessEDD\Core\Notices::add($message, $type);
}

/* ---------------------------------------------------------------------------
 * Customer account (Core\Account)
 * ------------------------------------------------------------------------- */

/** URL of a tab on the Stackless account page, e.g. stackless_edd_account_url('subscriptions', ['id' => 4]). */
function stackless_edd_account_url(string $tab = '', array $args = []): string
{
    return \StacklessEDD\Core\Account::url($tab, $args);
}

/* ---------------------------------------------------------------------------
 * Templates
 * ------------------------------------------------------------------------- */

/** Load a template, overridable from the theme at /stackless-edd/{name}. */
function stackless_edd_get_template(string $name, array $args = []): void
{
    $__stackless_edd_template = ltrim(str_replace('..', '', $name), '/');
    $__stackless_edd_file = locate_template(['stackless-edd/' . $__stackless_edd_template]);
    if (!$__stackless_edd_file) {
        $__stackless_edd_file = STACKLESS_EDD_PLUGIN_PATH . 'templates/' . $__stackless_edd_template;
    }
    $__stackless_edd_file = (string) apply_filters('stackless_edd_template', $__stackless_edd_file, $__stackless_edd_template, $args);
    if (!is_readable($__stackless_edd_file)) {
        return;
    }
    // Locals must not shadow template variables: EXTR_SKIP would silently drop an argument named "name" or "args".
    $__stackless_edd_args = $args;
    unset($name, $args);
    // phpcs:ignore WordPress.PHP.DontExtract -- template variables, same as wc_get_template().
    extract($__stackless_edd_args, EXTR_SKIP);
    include $__stackless_edd_file;
}

/** Same as stackless_edd_get_template() but returns the HTML. */
function stackless_edd_get_template_html(string $name, array $args = []): string
{
    ob_start();
    stackless_edd_get_template($name, $args);
    return (string) ob_get_clean();
}
