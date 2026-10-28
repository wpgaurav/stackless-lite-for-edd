<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Dodo Payments checkout sessions for EDD orders and handles the
 * buyer's return from them.
 */
class Checkout
{
    /** Query argument of the return address. */
    public const ENDPOINT = 'stackless-edd-dodo';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * What Dodo should bill for an order.
     *
     * @return array{recurring:?array,amount:float,trial_days:int,subscription:?SubscriptionData}|\WP_Error
     */
    public function plan(Order $order)
    {
        $orderId = (int) $order->id;
        $oneTime = ['recurring' => null, 'amount' => (float) $order->total, 'trial_days' => 0, 'subscription' => null];

        if (stackless_edd_is_renewal_order($orderId) || !stackless_edd_order_contains_subscription($orderId)) {
            return $oneTime;
        }

        $subscriptions = array_values(stackless_edd_get_order_subscriptions($orderId));
        if (count($subscriptions) !== 1) {
            return new \WP_Error('stackless_edd_dodo_subscriptions', count($subscriptions) === 0
                ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite-edd')
                : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
        }
        $subscription = $subscriptions[0];

        // Store-billed subscriptions are paid one order at a time.
        if (!$subscription instanceof SubscriptionData || $subscription->get_renewal_mode() === 'manual') {
            return $oneTime;
        }

        $recurring = EventMap::interval($subscription->get_billing_period(), $subscription->get_billing_interval());
        if (!$recurring) {
            return new \WP_Error('stackless_edd_dodo_interval', __('Dodo Payments cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite-edd'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_dodo_zero', __('Dodo Payments needs a recurring amount above zero.', 'stackless-lite-edd'));
        }

        $trialDays = self::subscriptionTrialDays($subscription, time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        // A Dodo subscription product bills one price every cycle, so a first payment
        // that differs from the renewal cannot be represented.
        if (!Money::equals($order->total, $expectedFirst, (string) $order->currency)) {
            return new \WP_Error('stackless_edd_dodo_first_payment', __('Dodo Payments charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite-edd'));
        }

        return ['recurring' => $recurring, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /** Whole days left in a trial (0 when none). Dodo trials run up to 10,000 days. @param mixed $trialEnd */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(10000, (int) ceil($seconds / 86400)) : 0;
    }

    /** Whole trial days from subscription terms (trial_length + trial_period) when the trial end is not dated yet. */
    public static function trialDaysFromTerms(int $length, string $period, int $now): int
    {
        $period = strtolower($period);
        if ($length < 1 || !in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return 0;
        }
        $end = (new \DateTimeImmutable('@' . $now))->modify('+' . $length . ' ' . $period);
        return $end ? self::trialDays($end, $now) : 0;
    }

    /** Free-trial days still to run: the subscription's trial end, else the download's subscription terms. */
    public static function subscriptionTrialDays(SubscriptionData $subscription, int $now): int
    {
        $trialEnd = $subscription->get_trial_end_date();
        if ($trialEnd !== null) {
            return self::trialDays($trialEnd, $now);
        }
        foreach ($subscription->get_items() as $item) {
            $terms = apply_filters('stackless_edd_subscription_product_terms', null, (int) ($item['download_id'] ?? 0), isset($item['price_id']) ? (int) $item['price_id'] : null);
            if (is_array($terms) && !empty($terms['trial_length'])) {
                return self::trialDaysFromTerms((int) $terms['trial_length'], (string) ($terms['trial_period'] ?? 'day'), $now);
            }
        }
        return 0;
    }

    /**
     * Start (or reuse) a Dodo checkout session for an order.
     *
     * @return string|\WP_Error Checkout URL.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_dodo_currency', sprintf(__('Dodo Payments cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($plan['amount'] <= 0) {
            return new \WP_Error('stackless_edd_dodo_zero', __('Dodo Payments needs an amount greater than zero to start a checkout.', 'stackless-lite-edd'));
        }

        $minor = Money::toMinor($plan['amount'], $currency);
        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $catalog = $this->gateway->catalog();
        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $productId = $catalog->productId($item, $plan['recurring'], $currency, $minor, $mode);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $session = $api->request('POST', 'checkouts', $this->body($order, $productId, $minor, $currency, $plan));

        // A product archived or deleted in Dodo is recreated once.
        if (is_wp_error($session) && Api::isProductError($session)) {
            $catalog->forget($item, $plan['recurring'], $currency, $minor, $mode);
            $productId = $catalog->productId($item, $plan['recurring'], $currency, $minor, $mode, true);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $session = $api->request('POST', 'checkouts', $this->body($order, $productId, $minor, $currency, $plan));
        }
        if (is_wp_error($session)) {
            $this->gateway->log('Checkout for order ' . $orderId . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        if (empty($session['checkout_url']) || empty($session['session_id']) || !self::isCheckoutUrl((string) $session['checkout_url'])) {
            return new \WP_Error('stackless_edd_dodo_checkout', __('Dodo Payments did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_SESSION_ID, (string) $session['session_id']);
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_URL, (string) $session['checkout_url']);
        Orders::setMeta($orderId, Gateway::META_SESSION_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $minor);
        Orders::setMeta($orderId, Gateway::META_PRODUCT_ID, $productId);
        Orders::setMeta($orderId, Gateway::META_TAX_INCLUSIVE, $this->gateway->taxInclusive() ? 'yes' : 'no');
        $this->gateway->log('Started Dodo checkout ' . $session['session_id'] . ' for order ' . $orderId . '.');
        return (string) $session['checkout_url'];
    }

    /** Only Dodo's own https checkout pages are followed (the gateway redirects off-site to them). */
    public static function isCheckoutUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        $allowed = (array) apply_filters('stackless_edd_dodo_gateway_checkout_hosts', ['dodopayments.com']);
        foreach ($allowed as $domain) {
            $domain = strtolower((string) $domain);
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }
        return false;
    }

    /**
     * Token that authorizes the return address of one order. Derived from the order's payment key,
     * which is never put in a URL itself (it also opens the receipt and downloads).
     */
    public static function token(Order $order): string
    {
        return substr(hash_hmac('sha256', 'stackless-edd-dodo|' . (int) $order->id . '|' . (string) $order->payment_key, wp_salt('nonce')), 0, 32);
    }

    public static function returnUrl(Order $order): string
    {
        // Dodo appends payment_id or subscription_id and status to this address.
        return add_query_arg([
            self::ENDPOINT => 'return',
            'order_id' => (int) $order->id,
            'key' => self::token($order),
        ], home_url('/'));
    }

    /** Where the buyer goes when they leave Dodo's checkout: back to paying this order. */
    public static function cancelUrl(Order $order): string
    {
        // Dodo refuses a session whose cancel URL is not a full address.
        foreach ([stackless_edd_order_pay_url((int) $order->id), edd_get_checkout_uri(), home_url('/')] as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }
        return home_url('/');
    }

    public function body(Order $order, string $productId, int $minor, string $currency, array $plan): array
    {
        $recurring = $plan['recurring'] !== null;
        $line = ['product_id' => $productId, 'quantity' => 1];
        if (!$recurring) {
            $line['amount'] = $minor;
        }
        $subscription = $plan['subscription'];

        $body = [
            'product_cart' => [$line],
            // Pinned, so adaptive pricing cannot move the buyer to another currency.
            'billing_currency' => strtoupper($currency),
            'return_url' => self::returnUrl($order),
            'cancel_url' => self::cancelUrl($order),
            'feature_flags' => [
                'allow_discount_code' => false,
                'allow_currency_selection' => false,
                'redirect_immediately' => true,
            ],
            'metadata' => array_filter([
                'edd_order_id' => (string) $order->id,
                'edd_subscription_id' => $subscription instanceof SubscriptionData ? (string) $subscription->get_id() : '',
                'edd_gateway' => $this->gateway->id(),
                'edd_site' => Catalog::siteHost(),
            ]),
        ];
        if ($recurring) {
            $body['subscription_data'] = ['trial_period_days' => max(0, min(10000, (int) $plan['trial_days']))];
        }

        $address = $order->get_address();
        $email = (string) $order->email;
        if ($email !== '' && is_email($email)) {
            $body['customer'] = ['email' => $email];
            $name = trim((string) ($address->first_name ?? '') . ' ' . (string) ($address->last_name ?? ''));
            if ($name === '' && function_exists('edd_get_customer')) {
                $customer = edd_get_customer((int) $order->customer_id);
                $name = $customer ? trim((string) $customer->name) : '';
            }
            if ($name !== '') {
                $body['customer']['name'] = function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200);
            }
        }
        $country = strtoupper((string) ($address->country ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $body['billing_address'] = array_filter([
                'country' => $country,
                'state' => (string) ($address->region ?? ''),
                'city' => (string) ($address->city ?? ''),
                'street' => trim((string) ($address->address ?? '') . ' ' . (string) ($address->address2 ?? '')),
                'zipcode' => (string) ($address->postal_code ?? ''),
            ], static function (string $value): bool {
                return $value !== '';
            });
        }

        return (array) apply_filters('stackless_edd_dodo_gateway_checkout_args', $body, $order, $subscription, $this->gateway);
    }

    /** Reuse an unpaid session for the same order and amount (double submits, back button). */
    private function reusable(Order $order, string $mode, int $minor): string
    {
        $orderId = (int) $order->id;
        $sessionId = Orders::meta($orderId, Gateway::META_SESSION_ID);
        $url = Orders::meta($orderId, Gateway::META_CHECKOUT_URL);
        if ($sessionId === '' || $url === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || (int) Orders::meta($orderId, Gateway::META_AMOUNT) !== $minor) {
            return '';
        }
        if ((int) Orders::meta($orderId, Gateway::META_SESSION_AT) < time() - Gateway::SESSION_TTL) {
            return '';
        }
        $session = $this->gateway->api($mode)->request('GET', 'checkouts/' . rawurlencode($sessionId));
        if (is_wp_error($session) || !empty($session['payment_status']) || !empty($session['payment_id'])) {
            return '';
        }
        return $url;
    }

    /* ---- Buyer returns from Dodo ------------------------------------------------- */

    /** One return address serves Dodo and every copy; the order says which one it used. */
    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order token.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $subscriptionHint = isset($_GET['subscription_id']) ? sanitize_text_field(wp_unslash($_GET['subscription_id'])) : '';
        // A hint only; the payment is looked up at Dodo.
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        $gateway = $order ? Gateway::forOrder($order) : null;
        if (!$order || !$gateway || $key === '' || !hash_equals(self::token($order), $key)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $target = self::returnTarget($gateway, $order, $subscriptionHint, $status);
        if ($target['empty_cart']) {
            edd_empty_cart();
        }
        if ($target['notice'] !== '') {
            stackless_edd_add_notice($target['notice'], 'error');
        }
        wp_safe_redirect($target['url']);
        exit;
    }

    /**
     * Where a buyer coming back from Dodo goes, and whether their cart is done with.
     *
     * Only the session this order started is trusted; the query string ($status from Dodo) is a
     * hint. A failed or cancelled payment sends the buyer back to pay with their cart kept.
     * Paid orders, and payments still settling (confirmed later by the webhook), go to the
     * receipt, which shows the real state.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, Order $order, string $subscriptionHint = '', string $status = ''): array
    {
        $failed = false;
        $orderId = (int) $order->id;
        if (!Orders::isPaid($order) && Orders::meta($orderId, Gateway::META_SESSION_ID) !== '') {
            $result = (new Sync($gateway))->confirmCheckout($order, $subscriptionHint);
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $orderId . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_edd_dodo_failed';
            }
            $order = Orders::get($orderId) ?: $order;
        }
        if (!Orders::isPaid($order) && ($failed || in_array($status, ['failed', 'cancelled'], true))) {
            return [
                'url' => self::cancelUrl($order),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'),
            ];
        }
        return ['url' => edd_get_receipt_page_uri($orderId), 'empty_cart' => true, 'notice' => ''];
    }
}
