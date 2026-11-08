<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Razorpay Standard Checkout: creates the Razorpay order, opens Razorpay Checkout on a small
 * page of this gateway's own (EDD has no order-pay page; the account page uses the same page to
 * save a method), and takes the signed result back (Sync::handleReturn()).
 *
 * A subscription checkout is an authorization transaction: a Razorpay order for one recurring
 * method (card, UPI Autopay or eNACH) with a customer and a token block whose max_amount
 * covers the largest renewal plus the plan-switch allowance. Cards and UPI take the order total
 * (at least ₹1, refunded when the order itself is free); eNACH authorizes ₹0 and the first
 * payment follows as its own debit once the bank confirms the mandate.
 *
 * Pages are reached with ?stackless_edd_razorpay={pay|authorize|return}: "pay" and "return"
 * carry the EDD order id and a key signed with the order's payment key, "authorize" a one-time
 * reference stored for the signed-in user.
 */
final class Checkout
{
    public const ARG = 'stackless_edd_razorpay';
    public const SDK = 'https://checkout.razorpay.com/v1/checkout.js';

    /** Razorpay orders are reused for this long (it caps authorization orders at the same day). */
    private const ORDER_TTL = 6 * HOUR_IN_SECONDS;

    /** template_redirect: this gateway's own pages. */
    public static function route(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each page checks its own signed key or reference.
        $page = isset($_GET[self::ARG]) && is_scalar($_GET[self::ARG]) ? sanitize_key(wp_unslash((string) $_GET[self::ARG])) : '';
        if ($page === 'pay') {
            self::payPage();
        } elseif ($page === 'authorize') {
            self::authorizePage();
        } elseif ($page === 'return') {
            Sync::handleReturn();
        }
    }

    /* ---- URLs -------------------------------------------------------------------- */

    public static function payUrl(Order $order): string
    {
        return add_query_arg([self::ARG => 'pay', 'order_id' => (int) $order->id, 'key' => Orders::returnKey($order, 'razorpay-pay')], home_url('/'));
    }

    public static function returnUrl(Order $order): string
    {
        return add_query_arg([self::ARG => 'return', 'order_id' => (int) $order->id, 'key' => Orders::returnKey($order, 'razorpay-return')], home_url('/'));
    }

    public static function authorizeUrl(string $ref): string
    {
        return add_query_arg([self::ARG => 'authorize', 'ref' => $ref], home_url('/'));
    }

    public static function addReturnUrl(string $ref): string
    {
        return add_query_arg([self::ARG => 'return', 'flow' => 'add', 'ref' => $ref], home_url('/'));
    }

    /** @return array<string,string> Notes sent with every Razorpay order for an EDD order. */
    public static function notes(Order $order, string $flow): array
    {
        return [
            'edd_order_id' => (string) $order->id,
            'edd_site' => Settings::site(),
            'edd_gateway' => Gateway::ID,
            'flow' => $flow,
        ];
    }

    /* ---- Razorpay orders ------------------------------------------------------------- */

    /**
     * Create (or reuse) the Razorpay order an EDD order is paid against.
     *
     * @return true|\WP_Error
     */
    public static function prepare(Order $order, bool $save, string $method = '')
    {
        $gateway = Module::gateway();
        $orderId = (int) $order->id;
        $mode = Settings::mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_razorpay_currency', sprintf(__('Razorpay does not take payments in %s.', 'stackless-lite-edd'), $currency));
        }
        $orderMinor = Money::toMinor($order->total, $currency);
        $body = [
            'currency' => $currency,
            'receipt' => substr('edd_' . $orderId . '_' . wp_generate_password(6, false), 0, 40),
            'notes' => self::notes($order, $save ? 'auth' : 'checkout'),
        ];
        $flow = 'checkout';
        if ($save) {
            if (!Money::recurringSupported($currency)) {
                return new \WP_Error('stackless_edd_razorpay_currency', __('Razorpay saves payment methods for subscriptions in Indian rupees only.', 'stackless-lite-edd'));
            }
            $largest = $gateway->largestRenewal($order);
            $eligible = Mandate::eligible(Settings::recurringMethods(), $largest);
            $method = in_array($method, $eligible, true) ? $method : ($eligible[0] ?? '');
            $max = $method !== '' ? Mandate::maxAmount($method, $largest, Settings::switchAllowance()) : null;
            if ($method === '' || $max === null) {
                return new \WP_Error('stackless_edd_razorpay_method', __('None of the Razorpay renewal methods can cover this subscription\'s renewal amount. Choose another payment method.', 'stackless-lite-edd'));
            }
            $customer = $gateway->customerFor((int) $order->user_id, $mode, Orders::customerName($order), (string) $order->email);
            if (is_wp_error($customer)) {
                return $customer;
            }
            $amount = Mandate::authorizationMinor($method, $orderMinor);
            $body += [
                'amount' => $amount,
                'customer_id' => $customer,
                'method' => $method,
                'payment_capture' => true,
                'token' => Mandate::tokenBlock($method, $max),
            ];
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            Orders::setMeta($orderId, Gateway::META_METHOD, $method);
            Orders::setMeta($orderId, Gateway::META_VERIFICATION, $orderMinor <= 0 && $amount > 0 ? 'yes' : 'no');
            $flow = 'auth';
        } else {
            if ($orderMinor < Money::MINIMUM_MINOR && $currency === 'INR') {
                return new \WP_Error('stackless_edd_razorpay_amount', __('Razorpay takes payments of ₹1 or more.', 'stackless-lite-edd'));
            }
            $body['amount'] = $orderMinor;
            Orders::setMeta($orderId, Gateway::META_VERIFICATION, 'no');
        }

        // Reuse an unpaid Razorpay order for the same amount and flow (back button, retries).
        $previous = Orders::metaString($orderId, Gateway::META_ORDER_ID);
        if ($previous !== '' && Gateway::orderMode($orderId) === $mode
            && (int) Orders::metaString($orderId, Gateway::META_AMOUNT) === (int) $body['amount']
            && Orders::metaString($orderId, Gateway::META_FLOW) === $flow
            && ($flow !== 'auth' || Orders::metaString($orderId, Gateway::META_METHOD) === $method)
            && (int) Orders::metaString($orderId, Gateway::META_ORDER_AT) > time() - self::ORDER_TTL) {
            $existing = Settings::api($mode)->request('GET', 'orders/' . rawurlencode($previous));
            if (is_array($existing) && ($existing['status'] ?? '') !== 'paid') {
                return true;
            }
        }

        $created = Settings::api($mode)->request('POST', 'orders', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        if (empty($created['id'])) {
            return new \WP_Error('stackless_edd_razorpay_order', __('Razorpay did not create an order.', 'stackless-lite-edd'));
        }
        Orders::setMeta($orderId, Gateway::META_ORDER_ID, (string) $created['id']);
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_FLOW, $flow);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) (int) $body['amount']);
        Orders::setMeta($orderId, Gateway::META_ORDER_AT, (string) time());
        Settings::log(sprintf('Razorpay order %s created for order %d (%d %s, %s%s).', (string) $created['id'], $orderId, (int) $body['amount'], $currency, $flow, $flow === 'auth' ? ' ' . $method : ''));
        return true;
    }

    /**
     * Account page → Add payment method: an authorization order for the chosen method.
     *
     * @return string|\WP_Error URL of the page that opens Razorpay Checkout.
     */
    public static function prepareAdd(int $userId, string $method, int $subscriptionId = 0)
    {
        $mode = Settings::mode();
        $methods = Settings::recurringMethods();
        $method = in_array($method, $methods, true) ? $method : ($methods[0] ?? '');
        if ($method === '') {
            return new \WP_Error('stackless_edd_razorpay_method', __('No Razorpay renewal method is switched on.', 'stackless-lite-edd'));
        }
        $user = get_userdata($userId);
        $customer = Module::gateway()->customerFor($userId, $mode, $user ? trim($user->first_name . ' ' . $user->last_name) : '', $user ? (string) $user->user_email : '');
        if (is_wp_error($customer)) {
            return $customer;
        }
        $caps = Mandate::caps();
        $max = Mandate::maxAmount($method, (float) min($caps[$method], 10000), Settings::switchAllowance());
        $ref = wp_generate_password(20, false);
        $created = Settings::api($mode)->request('POST', 'orders', [
            'amount' => Mandate::VERIFY_MINOR[$method],
            'currency' => Money::RECURRING,
            'customer_id' => $customer,
            'method' => $method,
            'payment_capture' => true,
            'receipt' => substr('edd_add_' . $userId . '_' . substr($ref, 0, 8), 0, 40),
            'token' => Mandate::tokenBlock($method, (int) $max),
            'notes' => ['flow' => 'add', 'wp_user_id' => (string) $userId, 'edd_site' => Settings::site(), 'edd_gateway' => Gateway::ID],
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        if (empty($created['id'])) {
            return new \WP_Error('stackless_edd_razorpay_order', __('Razorpay did not create an order.', 'stackless-lite-edd'));
        }
        update_user_meta($userId, Gateway::USER_META_PENDING_ADD, [
            'order' => (string) $created['id'],
            'customer' => $customer,
            'method' => $method,
            'mode' => $mode,
            'amount' => Mandate::VERIFY_MINOR[$method],
            'ref' => $ref,
            'subscription' => max(0, $subscriptionId),
            'at' => time(),
        ]);
        return self::authorizeUrl($ref);
    }

    /* ---- Razorpay Checkout options ------------------------------------------------------ */

    /**
     * Options for Razorpay Checkout (checkout.js) for an order.
     *
     * @return array<string,mixed>
     */
    public static function orderConfig(Order $order): array
    {
        $orderId = (int) $order->id;
        $flow = Orders::metaString($orderId, Gateway::META_FLOW);
        $options = [
            'key' => Settings::keyId(Gateway::orderMode($orderId)),
            'order_id' => Orders::metaString($orderId, Gateway::META_ORDER_ID),
            'amount' => (int) Orders::metaString($orderId, Gateway::META_AMOUNT),
            'currency' => strtoupper((string) $order->currency),
            'name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            /* translators: %s: order number */
            'description' => sprintf(__('Order %s', 'stackless-lite-edd'), Orders::number($order)),
            'prefill' => array_filter([
                'name' => Orders::customerName($order),
                'email' => (string) $order->email,
            ]),
            'notes' => ['edd_order_id' => (string) $orderId],
        ];
        if ($flow === 'auth') {
            $options['customer_id'] = Orders::metaString($orderId, SubscriptionData::META_GATEWAY_CUSTOMER);
            $options['recurring'] = '1';
        }
        return self::withTheme($options, self::returnUrl($order), edd_get_checkout_uri());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function withTheme(array $options, string $returnUrl, string $cancelUrl): array
    {
        if (Settings::themeColor() !== '') {
            $options['theme'] = ['color' => Settings::themeColor()];
        }
        // UPI Collect is closed for new mandates; Checkout shows Intent or QR instead.
        $options['config'] = ['display' => ['hide' => [['method' => 'upi', 'flows' => ['collect']]]]];
        return [
            'options' => $options,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'i18n' => [
                'pay' => __('Pay now', 'stackless-lite-edd'),
                'failed' => __('The payment did not go through. You can try again.', 'stackless-lite-edd'),
                'closed' => __('Payment window closed. Click Pay now to try again.', 'stackless-lite-edd'),
                'unavailable' => __('Razorpay Checkout could not load. Check your connection and reload the page.', 'stackless-lite-edd'),
            ],
        ];
    }

    /* ---- Pages ---------------------------------------------------------------------- */

    /** ?stackless_edd_razorpay=pay: open Razorpay Checkout for a pending order. */
    public static function payPage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) && is_scalar($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        $failed = !empty($_GET['failed']);
        // phpcs:enable
        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(Orders::returnKey($order, 'razorpay-pay'), $key) || (string) $order->gateway !== Gateway::ID) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        if (Orders::isPaid($order) || Orders::hasStatus($order, 'on_hold')) {
            wp_safe_redirect(Gateway::successUrl());
            exit;
        }
        if (!Orders::needsPayment($order)) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $error = '';
        if (Orders::metaString($orderId, Gateway::META_ORDER_ID) === '') {
            $prepared = self::prepare($order, stackless_edd_order_requires_saved_payment_method($orderId), Orders::metaString($orderId, Gateway::META_METHOD));
            if (is_wp_error($prepared)) {
                $error = $prepared->get_error_message();
            }
        }
        $lines = [__('Complete your payment in the Razorpay window. If it did not open, use the button below.', 'stackless-lite-edd')];
        if (Mandate::needsFirstDebit(Orders::metaString($orderId, Gateway::META_METHOD), Money::toMinor($order->total, (string) $order->currency)) && Orders::metaString($orderId, Gateway::META_FLOW) === 'auth') {
            $lines[] = __('You are setting up an eNACH mandate. Your first payment is debited once your bank confirms it, usually within two working days.', 'stackless-lite-edd');
        }
        self::render([
            /* translators: %s: order number */
            'heading' => sprintf(__('Pay for order %s', 'stackless-lite-edd'), Orders::number($order)),
            'lines' => $lines,
            'error' => $error !== '' ? $error : ($failed ? __('The payment could not be confirmed. Please try again or choose another payment method.', 'stackless-lite-edd') : ''),
            'button' => __('Pay now', 'stackless-lite-edd'),
            'cancel' => edd_get_checkout_uri(),
            'cancel_label' => __('Choose another payment method', 'stackless-lite-edd'),
            'testmode' => Gateway::orderMode($orderId) === 'test',
            'config' => $error === '' ? self::orderConfig($order) : [],
        ]);
    }

    /** ?stackless_edd_razorpay=authorize: Razorpay Checkout for the account page's Add payment method. */
    public static function authorizePage(): void
    {
        $userId = get_current_user_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the stored reference authenticates the request.
        $ref = isset($_GET['ref']) && is_scalar($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : '';
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect(Account::listUrl());
            exit;
        }
        $user = wp_get_current_user();
        $options = [
            'key' => Settings::keyId((string) $pending['mode']),
            'order_id' => (string) $pending['order'],
            'customer_id' => (string) $pending['customer'],
            'recurring' => '1',
            'amount' => (int) $pending['amount'],
            'currency' => Money::RECURRING,
            'name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'description' => __('Save a payment method', 'stackless-lite-edd'),
            'prefill' => array_filter(['name' => trim($user->first_name . ' ' . $user->last_name), 'email' => (string) $user->user_email]),
        ];
        $lines = [sprintf(
            /* translators: %s: method, e.g. UPI Autopay */
            __('Approve a %s mandate in the Razorpay window to use it for your subscriptions.', 'stackless-lite-edd'),
            Mandate::label((string) $pending['method'])
        )];
        if ((int) $pending['amount'] > 0) {
            $lines[] = __('Razorpay charges ₹1 to verify it, which is refunded straight away.', 'stackless-lite-edd');
        }
        self::render([
            'heading' => __('Save a payment method', 'stackless-lite-edd'),
            'lines' => $lines,
            'error' => '',
            'button' => __('Open Razorpay', 'stackless-lite-edd'),
            'cancel' => Account::listUrl(),
            'cancel_label' => __('Cancel', 'stackless-lite-edd'),
            'testmode' => (string) $pending['mode'] === 'test',
            'config' => self::withTheme($options, self::addReturnUrl($ref), Account::listUrl()),
        ]);
    }

    /** @param array<string,mixed> $args */
    private static function render(array $args): void
    {
        nocache_headers();
        stackless_edd_get_template('razorpay_gateway/pay-page.php', $args + [
            'sdk' => self::SDK,
            'script' => \StacklessEDD\Core\Assets::url('razorpay_gateway/checkout.js'),
        ]);
        exit;
    }
}
