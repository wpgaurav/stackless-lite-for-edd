<?php

namespace StacklessEDD\Modules\PolarGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Polar hosted checkout sessions for EDD orders and handles the
 * buyer's return from them.
 */
class Checkout
{
    public const RETURN_ARG = 'stackless_edd_polar_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * What Polar should bill for an order.
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

        $subscriptions = array_values(array_filter(stackless_edd_get_order_subscriptions($orderId), static function ($subscription) {
            return $subscription instanceof SubscriptionData;
        }));
        if (count($subscriptions) !== 1) {
            return new \WP_Error('stackless_edd_polar_subscriptions', count($subscriptions) === 0
                ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite-edd')
                : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
        }
        /** @var SubscriptionData $subscription */
        $subscription = $subscriptions[0];

        // Store-billed subscriptions are paid one order at a time.
        if ($subscription->get_renewal_mode() === 'manual') {
            return $oneTime;
        }

        $recurring = EventMap::interval($subscription->get_billing_period(), $subscription->get_billing_interval());
        if (!$recurring) {
            return new \WP_Error('stackless_edd_polar_interval', __('Polar cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite-edd'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_polar_zero', __('Polar needs a recurring amount above zero.', 'stackless-lite-edd'));
        }

        $trialDays = self::trialDays($subscription->get_trial_end_date(), time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        if (!Money::equals((float) $order->total, $expectedFirst, (string) $order->currency)) {
            return new \WP_Error('stackless_edd_polar_first_payment', __('Polar charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite-edd'));
        }

        return ['recurring' => $recurring, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /**
     * Whole days left in a trial (0 when none). Polar trials run 1–1000 days.
     *
     * @param mixed $trialEnd
     */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(1000, (int) ceil($seconds / 86400)) : 0;
    }

    /**
     * Start (or reuse) a Polar checkout for an order.
     *
     * @return string|\WP_Error Checkout URL.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtolower((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_polar_currency', sprintf(__('Polar cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), strtoupper($currency)));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($plan['amount'] <= 0) {
            return new \WP_Error('stackless_edd_polar_zero', __('Polar needs an amount greater than zero to start a checkout.', 'stackless-lite-edd'));
        }

        $minor = Money::toMinor($plan['amount'], $currency);
        $reused = $this->reusable($orderId, $mode, $minor, $currency);
        if ($reused !== '') {
            return $reused;
        }

        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $defaultPrice = $this->defaultPrice($order, $plan['amount'], $currency, $mode);
        $productId = Catalog::productId($this->gateway, $item, $plan['recurring'], $currency, $minor, $mode, false, $defaultPrice);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $checkout = $api->request('POST', 'checkouts/', $this->body($order, $productId, $minor, $currency, $plan, $defaultPrice));

        // A product archived or deleted in Polar is recreated once.
        if (is_wp_error($checkout) && Api::isProductError($checkout)) {
            Catalog::forget($this->gateway, $item, $plan['recurring'], $mode);
            $productId = Catalog::productId($this->gateway, $item, $plan['recurring'], $currency, $minor, $mode, true, $defaultPrice);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $checkout = $api->request('POST', 'checkouts/', $this->body($order, $productId, $minor, $currency, $plan, $defaultPrice));
        }
        if (is_wp_error($checkout)) {
            $this->gateway->log('Checkout for order ' . $orderId . ' failed: ' . $checkout->get_error_message(), 'error');
            return $checkout;
        }
        if (empty($checkout['url']) || empty($checkout['id'])) {
            return new \WP_Error('stackless_edd_polar_checkout', __('Polar did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_ID, (string) $checkout['id']);
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $minor);
        $this->gateway->log('Started Polar checkout ' . $checkout['id'] . ' for order ' . $orderId . '.');
        return (string) $checkout['url'];
    }

    public static function returnUrl(Order $order): string
    {
        $url = add_query_arg([
            self::RETURN_ARG => '1',
            'order_id' => $order->id,
            'key' => Orders::returnKey($order, 'polar-return'),
        ], home_url('/'));
        // {CHECKOUT_ID} is replaced by Polar, so it must not be URL-encoded.
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'checkout_id={CHECKOUT_ID}';
    }

    private function body(Order $order, string $productId, int $minor, string $currency, array $plan, ?array $defaultPrice): array
    {
        $orderId = (int) $order->id;
        $price = ['amount_type' => 'fixed', 'price_amount' => $minor, 'price_currency' => $currency];
        if ($this->gateway->taxBehavior() !== 'location') {
            $price['tax_behavior'] = $this->gateway->taxBehavior();
        }
        $prices = [$price];
        if ($defaultPrice) {
            $prices[] = array_merge($price, ['price_amount' => $defaultPrice['amount'], 'price_currency' => $defaultPrice['currency']]);
        }

        $subscription = $plan['subscription'];
        $body = [
            'products' => [$productId],
            'prices' => [$productId => $prices],
            'currency' => $currency,
            'success_url' => self::returnUrl($order),
            'return_url' => Orders::payUrl($order),
            'allow_discount_codes' => false,
            'allow_trial' => $plan['recurring'] !== null && $plan['trial_days'] > 0,
            'metadata' => array_filter([
                'edd_order_id' => (string) $orderId,
                'edd_subscription_id' => $subscription instanceof SubscriptionData ? (string) $subscription->get_id() : '',
                'edd_site' => Catalog::siteHost(),
            ]),
        ];
        if ($plan['recurring'] !== null && $plan['trial_days'] > 0) {
            $body['trial_interval'] = 'day';
            $body['trial_interval_count'] = $plan['trial_days'];
        }

        $email = (string) $order->email;
        if ($email !== '' && is_email($email)) {
            $body['customer_email'] = $email;
        }
        $address = $order->get_address();
        $name = is_object($address) ? trim((string) $address->first_name . ' ' . (string) $address->last_name) : '';
        if ($name === '' && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        if ($name !== '') {
            $body['customer_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 256) : substr($name, 0, 256);
        }
        $country = is_object($address) ? strtoupper((string) $address->country) : '';
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $body['customer_billing_address'] = ['country' => $country];
        }
        $locale = str_replace('_', '-', (string) get_locale());
        if ($locale !== '') {
            $body['locale'] = $locale;
        }

        return (array) apply_filters('stackless_edd_polar_gateway_checkout_args', $body, $order, $subscription);
    }

    /** Reuse an open checkout for the same order and amount (double submits, back button). */
    private function reusable(int $orderId, string $mode, int $minor, string $currency): string
    {
        $checkoutId = Orders::meta($orderId, Gateway::META_CHECKOUT_ID);
        if ($checkoutId === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || (int) Orders::meta($orderId, Gateway::META_AMOUNT) !== $minor) {
            return '';
        }
        $checkout = $this->gateway->api($mode)->request('GET', 'checkouts/' . rawurlencode($checkoutId));
        if (is_wp_error($checkout) || ($checkout['status'] ?? '') !== 'open' || strtolower((string) ($checkout['currency'] ?? $currency)) !== $currency) {
            return '';
        }
        $expires = strtotime((string) ($checkout['expires_at'] ?? ''));
        if ($expires && $expires < time() + 5 * MINUTE_IN_SECONDS) {
            return '';
        }
        return (string) ($checkout['url'] ?? '');
    }

    /**
     * Polar refuses ad-hoc prices without one in the organization's default currency.
     * That price is never charged (the checkout is pinned to $currency); it only has
     * to be present and plausible.
     *
     * @return array{currency:string,amount:int}|null
     */
    private function defaultPrice(Order $order, float $amount, string $currency, string $mode): ?array
    {
        $default = $this->gateway->defaultCurrency($mode);
        if ($default === '' || $default === $currency) {
            return null;
        }
        return ['currency' => $default, 'amount' => Money::toMinor(self::convert($amount, $currency, $default, $order), $default)];
    }

    /**
     * Convert an amount for the default-currency price.
     *
     * Omitting the price is not an option: Polar rejects the product and the checkout
     * without it. The order's own price is what the buyer pays, so this one is only
     * cosmetic, but it still appears on the Polar product and its receipts, and a
     * ¥5,000 order copied over as $5,000 looks like a bug there. So the amount goes
     * through Multi-Currency's rate (stackless_edd_convert_amount(), which hands the
     * input back unchanged when the module is off or has no rate for the pair);
     * stackless_edd_polar_gateway_convert_amount lets a site supply its own number first.
     */
    public static function convert(float $amount, string $from, string $to, ?Order $order): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $converted = apply_filters('stackless_edd_polar_gateway_convert_amount', null, $amount, $from, $to, $order);
        if (is_numeric($converted) && (float) $converted > 0) {
            return (float) $converted;
        }
        $converted = stackless_edd_convert_amount($amount, $to, $from);
        return is_numeric($converted) && (float) $converted > 0 ? (float) $converted : $amount;
    }

    /* ---- Buyer returns from Polar ------------------------------------------------- */

    public function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $checkoutId = isset($_GET['checkout_id']) ? sanitize_text_field(wp_unslash($_GET['checkout_id'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(Orders::returnKey($order, 'polar-return'), $key) || (string) $order->gateway !== $this->gateway->id()) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }

        $stored = Orders::meta($orderId, Gateway::META_CHECKOUT_ID);
        // Only the checkout this order started may confirm it.
        if (!Orders::isPaid($order) && $stored !== '' && ($checkoutId === '' || $checkoutId === $stored)) {
            $result = (new Sync($this->gateway))->confirmCheckout($order, $stored);
            if (is_wp_error($result)) {
                $this->gateway->log('Return for order ' . $orderId . ': ' . $result->get_error_message());
            }
        }

        edd_empty_cart();
        // Paid, still settling, or abandoned: the receipt shows the real state.
        wp_safe_redirect(edd_get_success_page_uri());
        exit;
    }
}
