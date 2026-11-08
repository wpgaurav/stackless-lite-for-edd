<?php

namespace StacklessEDD\Modules\StripeGateway\Managed;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\Assets;
use StacklessEDD\Modules\StripeGateway\Amount;
use StacklessEDD\Modules\StripeGateway\Customers;
use StacklessEDD\Modules\StripeGateway\IdempotencyKey;
use StacklessEDD\Modules\StripeGateway\Orders;
use StacklessEDD\Modules\StripeGateway\Payments;
use StacklessEDD\Modules\StripeGateway\Settings;
use StacklessEDD\Modules\StripeGateway\StripeApiException;

defined('ABSPATH') || exit;

/**
 * Creates the Managed Payments Checkout Session for an EDD order, serves the store's pay page
 * that embeds it, handles the buyer's return, and opens the Stripe customer portal for card
 * updates.
 *
 * process() creates (or reuses) the session and sends the buyer to an order-token protected
 * page of this store, which mounts embedded Checkout with the session's client secret. Stripe
 * returns the buyer to the return URL, where the session is read back from Stripe before
 * anything is marked paid.
 */
class Checkout
{
    /** Query argument of the pay, return and portal addresses. */
    public const ENDPOINT = 'stackless-edd-stripe-managed';

    /** Stackless Blue. */
    private const ACCENT = '#066fd1';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Session parameters (without return_url and metadata) for an order.
     *
     * @return array{mode:string, line_items:array<int,array>, trial_days:int, subscription:?SubscriptionData}|\WP_Error
     */
    public function plan(Order $order)
    {
        $order_id = (int) $order->id;
        $currency = strtoupper((string) $order->currency);
        $context = [
            'currency' => $currency,
            /* translators: %s: order number */
            'label' => sprintf(__('Order #%s', 'stackless-lite-edd'), Orders::number($order)),
            'tax_code' => $this->gateway->taxCode($order),
            'tax_behavior' => $this->gateway->taxBehavior(),
        ];
        $total = Amount::toMinor($order->total, $currency);
        $recurring = null;
        $subscription = null;

        if (!stackless_edd_is_renewal_order($order_id) && stackless_edd_order_contains_subscription($order_id)) {
            $subscriptions = array_values(stackless_edd_get_order_subscriptions($order_id));
            if (count($subscriptions) !== 1) {
                return new \WP_Error('stackless_edd_stripe_managed_subscriptions', count($subscriptions) === 0
                    ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite-edd')
                    : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
            }
            $candidate = $subscriptions[0];
            // Store-billed (manual) subscriptions are paid one order at a time.
            if ($candidate instanceof SubscriptionData && $candidate->get_renewal_mode() !== 'manual') {
                $subscription = $candidate;
                $recurring = [
                    'name' => self::subscriptionName($candidate),
                    'amount' => Amount::toMinor($candidate->get_recurring_total(), $currency),
                    'period' => $candidate->get_billing_period(),
                    'interval' => $candidate->get_billing_interval(),
                    'trial_days' => self::trialDays($candidate, time()),
                ];
            }
        }

        $built = Lines::build($total, $recurring === null ? self::orderLines($order) : [], $recurring, $context);
        if (is_string($built)) {
            return new \WP_Error('stackless_edd_stripe_managed_' . $built, self::linesMessage($built));
        }
        return [
            'mode' => $recurring === null ? 'payment' : 'subscription',
            'line_items' => $built['line_items'],
            'trial_days' => $built['trial_days'],
            'subscription' => $subscription,
        ];
    }

    public static function linesMessage(string $code): string
    {
        switch ($code) {
            case Lines::ERROR_FIRST_PAYMENT:
                return __('Stripe charges at least one full billing cycle on the first payment, so it cannot take a first-payment discount. Remove the discount or choose another payment method.', 'stackless-lite-edd');
            case Lines::ERROR_INTERVAL:
                return __('Stripe cannot bill this subscription schedule (the longest is three years). Choose another payment method.', 'stackless-lite-edd');
            default:
                return __('Stripe needs an amount greater than zero to start a checkout.', 'stackless-lite-edd');
        }
    }

    /** Order lines and fees in the lowest denomination. @return array<int,array{name:string,amount:int}> */
    public static function orderLines(Order $order): array
    {
        $currency = (string) $order->currency;
        $lines = [];
        foreach ($order->get_items() as $item) {
            $quantity = (int) $item->quantity;
            $lines[] = [
                'name' => (string) $item->product_name . ($quantity > 1 ? ' × ' . $quantity : ''),
                'amount' => Amount::toMinor((float) $item->subtotal - (float) $item->discount, $currency),
            ];
        }
        foreach ($order->get_fees() as $fee) {
            $lines[] = ['name' => (string) $fee->description, 'amount' => Amount::toMinor((float) $fee->subtotal, $currency)];
        }
        // EDD tax is left out on purpose: when it is charged, the lines no longer add up and the
        // order goes to Stripe as one line instead of itemizing tax as a product.
        return $lines;
    }

    private static function subscriptionName(SubscriptionData $subscription): string
    {
        $names = [];
        foreach ($subscription->get_items() as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name . ($quantity > 1 ? ' × ' . $quantity : '');
            }
        }
        return $names ? implode(', ', $names) : __('Subscription', 'stackless-lite-edd');
    }

    /** Free-trial days still to run: the subscription's trial end, else the download's subscription terms. */
    public static function trialDays(SubscriptionData $subscription, int $now): int
    {
        $end = $subscription->get_trial_end_date();
        if ($end !== null) {
            return Lines::trialDays($end, $now);
        }
        foreach ($subscription->get_items() as $item) {
            $terms = apply_filters('stackless_edd_subscription_product_terms', null, (int) ($item['download_id'] ?? 0), isset($item['price_id']) ? (int) $item['price_id'] : null);
            if (is_array($terms) && !empty($terms['trial_length'])) {
                return Lines::trialDaysFromTerms((int) $terms['trial_length'], (string) ($terms['trial_period'] ?? 'day'), $now);
            }
        }
        return 0;
    }

    /**
     * Checkout Session create body for an order.
     *
     * @param array{mode:string, line_items:array<int,array>, trial_days:int, subscription:?SubscriptionData} $plan
     */
    public function body(Order $order, array $plan, string $customer): array
    {
        $metadata = [
            'order_id' => (string) $order->id,
            'order_number' => Orders::number($order),
            'stackless_site' => Settings::siteMarker(),
            'stackless_managed' => '1',
        ];
        $body = [
            'mode' => $plan['mode'],
            'ui_mode' => EventMap::uiMode(Gateway::apiVersion()),
            'managed_payments' => ['enabled' => 'true'],
            'line_items' => $plan['line_items'],
            'return_url' => self::returnUrl($order),
            'client_reference_id' => (string) $order->id,
            'metadata' => $metadata,
        ];
        if ($customer !== '') {
            $body['customer'] = $customer;
        } elseif (is_email((string) $order->email)) {
            $body['customer_email'] = (string) $order->email;
        }
        $locale = strtolower(str_replace('_', '-', (string) get_locale()));
        $body['locale'] = in_array(substr($locale, 0, 2), ['en', 'de', 'fr', 'es', 'it', 'ja', 'nl', 'pt', 'sv', 'da', 'fi', 'nb', 'pl'], true) ? substr($locale, 0, 2) : 'auto';
        if ($plan['mode'] === 'subscription') {
            $subscription = $plan['subscription'];
            $body['subscription_data'] = ['metadata' => array_merge($metadata, ['subscription_id' => $subscription instanceof SubscriptionData ? (string) $subscription->get_id() : ''])];
            if ($plan['trial_days'] > 0) {
                $body['subscription_data']['trial_period_days'] = $plan['trial_days'];
            }
        } else {
            $body['payment_intent_data'] = ['metadata' => $metadata];
        }
        return (array) apply_filters('stackless_edd_stripe_managed_session_args', $body, $order, $plan['subscription']);
    }

    /**
     * Create (or reuse) the Checkout Session for an order.
     *
     * @return string|\WP_Error URL of the store's pay page.
     * @throws StripeApiException
     */
    public function start(Order $order)
    {
        $order_id = (int) $order->id;
        $currency = strtoupper((string) $order->currency);
        if (!Amount::isSupported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_stripe_managed_currency', sprintf(__('Stripe cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }
        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $client = Gateway::client();
        $customer = (int) $order->user_id > 0 ? Customers::forOrder($order, $client, true) : '';
        $body = $this->body($order, $plan, $customer);
        $fingerprint = $client->mode() . ':' . md5((string) wp_json_encode($body));

        if ($this->openSession($order, $fingerprint) === null) {
            // The previous session id is part of the key, so a session that expired is replaced, not replayed.
            $previous = (string) Orders::meta($order_id, Gateway::META_SESSION);
            $session = $client->post('checkout/sessions', $body, IdempotencyKey::order($order_id, 'managed|' . $fingerprint . '|' . $previous));
            if (empty($session['id'])) {
                return new \WP_Error('stackless_edd_stripe_managed', __('Stripe did not return a checkout session.', 'stackless-lite-edd'));
            }
            Orders::updateMeta($order_id, Gateway::META_SESSION, (string) $session['id']);
            Orders::updateMeta($order_id, Gateway::META_FINGERPRINT, $fingerprint);
            Settings::log('Created Managed Payments session ' . $session['id'] . ' for order ' . $order_id . '.', 'info');
        }
        Gateway::setGateway($order);
        Orders::updateMeta($order_id, Payments::META_MODE, $client->mode());
        if ($customer !== '') {
            Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        return self::payUrl($order);
    }

    /**
     * The order's session when it is still open and was built from the same order contents
     * (double submits, the back button), else null.
     *
     * @return array<string,mixed>|null
     */
    public function openSession(Order $order, string $fingerprint = ''): ?array
    {
        $order_id = (int) $order->id;
        $id = (string) Orders::meta($order_id, Gateway::META_SESSION);
        if ($id === '' || ($fingerprint !== '' && (string) Orders::meta($order_id, Gateway::META_FINGERPRINT) !== $fingerprint)) {
            return null;
        }
        try {
            $session = Gateway::clientForOrder($order_id)->get('checkout/sessions/' . rawurlencode($id));
        } catch (StripeApiException $e) {
            return null;
        }
        return ($session['status'] ?? '') === 'open' && !empty($session['client_secret']) ? $session : null;
    }

    /**
     * Token that authorizes the pay and return addresses of one order. Derived from the order's
     * payment key, which is never put in a URL itself (it also opens the receipt and downloads).
     */
    public static function token(Order $order): string
    {
        return substr(hash_hmac('sha256', 'stackless-edd-stripe-managed|' . (int) $order->id . '|' . (string) $order->payment_key, wp_salt('nonce')), 0, 32);
    }

    /** @param array<string,scalar> $args */
    public static function endpoint(string $action, array $args): string
    {
        return add_query_arg(array_merge([self::ENDPOINT => $action], $args), home_url('/'));
    }

    public static function payUrl(Order $order): string
    {
        return self::endpoint('pay', ['order_id' => (int) $order->id, 'key' => self::token($order)]);
    }

    public static function returnUrl(Order $order): string
    {
        // Stripe replaces {CHECKOUT_SESSION_ID}; add_query_arg would encode the braces.
        return self::endpoint('return', ['order_id' => (int) $order->id, 'key' => self::token($order)]) . '&session_id={CHECKOUT_SESSION_ID}';
    }

    public static function portalUrl(SubscriptionData $subscription): string
    {
        return wp_nonce_url(self::endpoint('portal', ['subscription_id' => $subscription->get_id()]), 'stackless_edd_stripe_managed_portal_' . $subscription->get_id());
    }

    /** Where a buyer who leaves the pay page goes: back to checkout with another method. */
    public static function cancelUrl(): string
    {
        return add_query_arg(['payment-mode' => Gateway::ID], edd_get_checkout_uri());
    }

    /* ---- Pay page ---------------------------------------------------------------- */

    /** The order a pay or return request names, when its token checks out. */
    private function requestedOrder(): ?Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order token.
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable
        $order = Orders::get($order_id);
        if (!$order || $key === '' || !hash_equals(self::token($order), $key) || (string) $order->gateway !== Gateway::ID) {
            return null;
        }
        return $order;
    }

    public function renderPayPage(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $order_id = (int) $order->id;
        if (Orders::isPaid($order) || Orders::hasStatus($order, ['on_hold', 'processing'])) {
            wp_safe_redirect(edd_get_receipt_page_uri($order_id));
            exit;
        }
        $session = $this->openSession($order);
        if ($session === null) {
            // Expired or never created: start a fresh one for the same order.
            $started = $this->gateway->startCheckout($order);
            $session = is_wp_error($started) ? null : $this->openSession($order);
            if ($session === null) {
                stackless_edd_add_notice(is_wp_error($started) ? $started->get_error_message() : __('Stripe could not start the checkout. Please try again.', 'stackless-lite-edd'), 'error');
                wp_safe_redirect(self::cancelUrl());
                exit;
            }
        }
        $mode = Gateway::orderMode($order_id);
        $config = [
            'publishableKey' => Settings::publishableKey($mode !== '' ? $mode : null),
            'clientSecret' => (string) $session['client_secret'],
            'cancelUrl' => self::cancelUrl(),
            'testMode' => $mode !== 'live',
            'accent' => self::ACCENT,
            'i18n' => [
                'failed' => __('Stripe checkout could not load. Check your connection and try again.', 'stackless-lite-edd'),
            ],
        ];
        $config = (array) apply_filters('stackless_edd_stripe_managed_pay_page_config', $config, $order);
        $config['accent'] = sanitize_hex_color((string) ($config['accent'] ?? '')) ?: self::ACCENT;
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex, nofollow');
        stackless_edd_get_template('stripe_gateway/managed-pay-page.php', [
            'order' => $order,
            'config' => $config,
            'summary' => $this->summary($order),
            'script_url' => Assets::url('stripe_gateway/managed-pay.js') . '?ver=' . rawurlencode(STACKLESS_EDD_VERSION),
            'style_url' => Assets::url('stripe_gateway/managed-pay.css') . '?ver=' . rawurlencode(STACKLESS_EDD_VERSION),
        ]);
        exit;
    }

    /**
     * What the pay page shows beside Stripe Checkout: the store, the order's lines and totals,
     * what renews and when, and who sells it. Amounts are plain text in the order's currency.
     *
     * @return array{store:array{name:string,logo:string,logo_type:string,url:string}, number:string, items:array<int,array{name:string,quantity:int,total:string,image:string}>, rows:array<int,array{label:string,value:string}>, total:string, recurring:string, trial:string, tax_note:string, seller_note:string}
     */
    public function summary(Order $order): array
    {
        $currency = strtoupper((string) $order->currency);
        $money = static function ($amount) use ($currency): string {
            return html_entity_decode(wp_strip_all_tags(stackless_edd_price((float) $amount, $currency)), ENT_QUOTES, 'UTF-8');
        };
        $items = [];
        $subtotal = 0.0;
        $discount = 0.0;
        foreach ($order->get_items() as $item) {
            $thumb = has_post_thumbnail((int) $item->product_id) ? get_the_post_thumbnail_url((int) $item->product_id, 'thumbnail') : '';
            $items[] = [
                'name' => (string) $item->product_name,
                'quantity' => (int) $item->quantity,
                'total' => $money((float) $item->subtotal),
                'image' => is_string($thumb) ? $thumb : '',
            ];
            $subtotal += (float) $item->subtotal;
            $discount += (float) $item->discount;
        }
        $rows = [['label' => __('Subtotal', 'stackless-lite-edd'), 'value' => $money($subtotal)]];
        if ($discount > 0) {
            $rows[] = ['label' => __('Discount', 'stackless-lite-edd'), 'value' => '−' . $money($discount)];
        }
        foreach ($order->get_fees() as $fee) {
            $rows[] = ['label' => (string) $fee->description, 'value' => $money((float) $fee->subtotal)];
        }
        if ((float) $order->tax > 0) {
            $rows[] = ['label' => __('Tax', 'stackless-lite-edd'), 'value' => $money((float) $order->tax)];
        }

        $recurring = '';
        $trial = '';
        $order_id = (int) $order->id;
        if (!stackless_edd_is_renewal_order($order_id) && stackless_edd_order_contains_subscription($order_id)) {
            $subscriptions = array_values(stackless_edd_get_order_subscriptions($order_id));
            $subscription = $subscriptions[0] ?? null;
            if ($subscription instanceof SubscriptionData && $subscription->get_renewal_mode() !== 'manual') {
                $recurring = sprintf(
                    /* translators: 1: recurring amount, 2: billing schedule such as "every month" */
                    __('Then %1$s %2$s', 'stackless-lite-edd'),
                    $money($subscription->get_recurring_total()),
                    self::every($subscription->get_billing_period(), $subscription->get_billing_interval())
                );
                $days = self::trialDays($subscription, time());
                if ($days > 0) {
                    /* translators: %s: date the free trial ends */
                    $trial = sprintf(__('Free trial until %s', 'stackless-lite-edd'), wp_date((string) get_option('date_format'), time() + $days * DAY_IN_SECONDS));
                }
            }
        }

        // A theme logo usually carries the store name already; a site icon doesn't.
        $logo = '';
        $logo_type = '';
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id > 0) {
            $logo = (string) wp_get_attachment_image_url($logo_id, 'medium');
            $logo_type = $logo !== '' ? 'logo' : '';
        }
        if ($logo === '' && function_exists('get_site_icon_url')) {
            $logo = (string) get_site_icon_url(96);
            $logo_type = $logo !== '' ? 'icon' : '';
        }
        return [
            'store' => ['name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), 'logo' => $logo, 'logo_type' => $logo_type, 'url' => home_url('/')],
            'number' => Orders::number($order),
            'items' => $items,
            'rows' => $rows,
            'total' => $money((float) $order->total),
            'recurring' => $recurring,
            'trial' => $trial,
            'tax_note' => $this->gateway->taxBehavior() === 'inclusive'
                ? __('Prices include tax. Stripe works out the tax for your location.', 'stackless-lite-edd')
                : __('Stripe adds sales tax or VAT for your location before you pay.', 'stackless-lite-edd'),
            'seller_note' => __('Stripe is the merchant of record for this purchase and emails your receipt.', 'stackless-lite-edd'),
        ];
    }

    /** "every month", "every 3 weeks". */
    public static function every(string $period, int $interval): string
    {
        $interval = max(1, $interval);
        switch ($period) {
            case 'day':
                /* translators: %d: number of days */
                return sprintf(_n('every day', 'every %d days', $interval, 'stackless-lite-edd'), $interval);
            case 'week':
                /* translators: %d: number of weeks */
                return sprintf(_n('every week', 'every %d weeks', $interval, 'stackless-lite-edd'), $interval);
            case 'year':
                /* translators: %d: number of years */
                return sprintf(_n('every year', 'every %d years', $interval, 'stackless-lite-edd'), $interval);
            default:
                /* translators: %d: number of months */
                return sprintf(_n('every month', 'every %d months', $interval, 'stackless-lite-edd'), $interval);
        }
    }

    /* ---- Buyer returns from Stripe ------------------------------------------------- */

    public function handleReturn(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $order_id = (int) $order->id;
        $stored = (string) Orders::meta($order_id, Gateway::META_SESSION);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with the stored id, never trusted on its own.
        $returned = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
        if (!Orders::isPaid($order) && $stored !== '' && ($returned === '' || hash_equals($stored, $returned))) {
            $result = (new Sync())->confirmById($order, $stored);
            if (is_wp_error($result)) {
                Settings::log('Managed Payments return for order ' . $order_id . ': ' . $result->get_error_message(), 'info');
                if ($result->get_error_code() === 'stackless_edd_stripe_managed_open') {
                    wp_safe_redirect(self::payUrl($order));
                    exit;
                }
            }
        }
        if (function_exists('edd_empty_cart')) {
            edd_empty_cart();
        }
        // Paid, still settling, or failed: the receipt shows the real state.
        wp_safe_redirect(edd_get_receipt_page_uri($order_id));
        exit;
    }

    /* ---- Customer portal (card updates) ------------------------------------------- */

    /**
     * Opens the Stripe customer portal so the customer can replace the card on a Managed
     * Payments subscription; Stripe does not let the API change its default_payment_method.
     */
    public function handlePortal(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
        $id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $subscription = $id ? stackless_edd_get_subscription($id) : null;
        $view = stackless_edd_account_url('subscriptions', $id ? ['id' => $id] : []);
        if (!$subscription instanceof SubscriptionData || !is_user_logged_in() || !wp_verify_nonce($nonce, 'stackless_edd_stripe_managed_portal_' . $id)
            || $subscription->get_user_id() !== get_current_user_id()
            || $subscription->get_gateway() !== Gateway::ID) {
            wp_safe_redirect($view);
            exit;
        }
        $customer = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
        if ($customer === '') {
            stackless_edd_add_notice(__('Stripe has no customer record for this subscription yet. Please try again later.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($view);
            exit;
        }
        try {
            $portal = Gateway::clientForSubscription($subscription)->post('billing_portal/sessions', [
                'customer' => $customer,
                'return_url' => $view,
                'flow_data' => ['type' => 'payment_method_update'],
            ]);
        } catch (StripeApiException $e) {
            Settings::log('Customer portal for subscription ' . $id . ' failed: ' . $e->logSummary(), 'error');
            stackless_edd_add_notice(__('Stripe could not open the payment method update. Please try again later.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($view);
            exit;
        }
        $url = (string) ($portal['url'] ?? '');
        if (strpos($url, 'https://') !== 0) {
            wp_safe_redirect($view);
            exit;
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the portal URL Stripe returned (billing.stripe.com or the account's own billing domain).
        wp_redirect($url);
        exit;
    }
}
