<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Creem checkout sessions for EDD orders and handles the buyer's return.
 */
class Checkout
{
    /** Query flag of the return address (home URL + this flag, the EDD order and a signed key). */
    public const RETURN_ARG = 'stackless_edd_creem_return';

    /**
     * Query name of the EDD order id on the return address. Creem appends its own order_id
     * (ord_...) to the success URL, so the EDD order travels under another name.
     */
    public const ORDER_ARG = 'edd_order';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * What Creem should bill for an order.
     *
     * @return array{billing:?array,amount:float,trial_days:int,subscription:?SubscriptionData}|\WP_Error
     */
    public function plan(Order $order)
    {
        $orderId = (int) $order->id;
        $oneTime = ['billing' => null, 'amount' => (float) $order->total, 'trial_days' => 0, 'subscription' => null];

        if (stackless_edd_is_renewal_order($orderId) || !stackless_edd_order_contains_subscription($orderId)) {
            return $oneTime;
        }

        $subscriptions = array_values(array_filter(stackless_edd_get_order_subscriptions($orderId), static function ($subscription) {
            return $subscription instanceof SubscriptionData;
        }));
        if (count($subscriptions) !== 1) {
            return new \WP_Error('stackless_edd_creem_subscriptions', count($subscriptions) === 0
                ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite-edd')
                : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
        }
        /** @var SubscriptionData $subscription */
        $subscription = $subscriptions[0];

        // Creem bills every subscription it takes, so it can't take one the store bills by
        // invoice: the store would never send the next invoice for a Creem subscription.
        if ($subscription->get_renewal_mode() === 'manual') {
            return new \WP_Error('stackless_edd_creem_manual', __('Creem bills subscriptions itself, so this subscription must renew automatically. Choose automatic renewal or another payment method.', 'stackless-lite-edd'));
        }

        $billing = EventMap::interval($subscription->get_billing_period(), $subscription->get_billing_interval());
        if (!$billing) {
            return new \WP_Error('stackless_edd_creem_interval', __('Creem cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite-edd'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if (Money::toMinor($amount) < Money::MINIMUM) {
            return new \WP_Error('stackless_edd_creem_zero', __('Creem needs a recurring amount of at least 1.00.', 'stackless-lite-edd'));
        }

        $trialDays = self::trialDays($subscription->get_trial_end_date(), time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        // A Creem subscription product bills one price every cycle, so a first payment
        // that differs from the renewal cannot be represented.
        if (!Money::equals((float) $order->total, $expectedFirst)) {
            return new \WP_Error('stackless_edd_creem_first_payment', __('Creem charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite-edd'));
        }

        return ['billing' => $billing, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /**
     * Whole days left in a trial (0 when none).
     *
     * @param mixed $trialEnd
     */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(3650, (int) ceil($seconds / 86400)) : 0;
    }

    /**
     * Start (or reuse) a Creem checkout session for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Creem's checkout, or the receipt when the
     *                          order turns out to be paid.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_creem_currency', sprintf(__('Creem cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $minor = Money::toMinor($plan['amount']);
        if ($minor < Money::MINIMUM) {
            return new \WP_Error('stackless_edd_creem_minimum', __('Creem takes payments of 1.00 or more. Choose another payment method for this order.', 'stackless-lite-edd'));
        }

        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $catalog = $this->gateway->catalog();
        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $productId = $catalog->productId($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days']);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $session = $api->request('POST', 'v1/checkouts', $this->body($order, $productId, $minor, $plan));
        // A product archived or deleted in Creem is recreated once.
        if (is_wp_error($session) && Api::isProductError($session)) {
            $catalog->forget($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days']);
            $productId = $catalog->productId($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days'], true);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $session = $api->request('POST', 'v1/checkouts', $this->body($order, $productId, $minor, $plan));
        }
        if (is_wp_error($session)) {
            $this->gateway->log('Checkout for order ' . $orderId . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        if (empty($session['checkout_url']) || empty($session['id'])) {
            return new \WP_Error('stackless_edd_creem_checkout', __('Creem did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_ID, (string) $session['id']);
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_URL, (string) $session['checkout_url']);
        Orders::setMeta($orderId, Gateway::META_SESSION_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $minor);
        Orders::setMeta($orderId, Gateway::META_PRODUCT_ID, $productId);
        $this->gateway->log('Started Creem checkout ' . $session['id'] . ' for order ' . $orderId . '.');
        return (string) $session['checkout_url'];
    }

    public static function returnUrl(Order $order): string
    {
        // Creem appends checkout_id, order_id, customer_id, subscription_id and a signature.
        return add_query_arg([
            self::RETURN_ARG => '1',
            self::ORDER_ARG => $order->id,
            'key' => Orders::returnKey($order, 'creem-return'),
        ], home_url('/'));
    }

    /** request_id Creem echoes back: the order on this site. */
    public static function requestId(Order $order): string
    {
        return 'edd' . $order->id . '-' . substr(md5(Catalog::siteHost()), 0, 8);
    }

    /** @param array{billing:?array,amount:float,trial_days:int,subscription:?SubscriptionData} $plan */
    public function body(Order $order, string $productId, int $minor, array $plan): array
    {
        $subscription = $plan['subscription'];
        $body = [
            'product_id' => $productId,
            'request_id' => self::requestId($order),
            'units' => 1,
            'success_url' => self::returnUrl($order),
            'metadata' => array_filter([
                'edd_order_id' => (string) $order->id,
                'edd_subscription_id' => $subscription instanceof SubscriptionData ? (string) $subscription->get_id() : '',
                'edd_gateway' => $this->gateway->id(),
                'edd_site' => Catalog::siteHost(),
            ]),
        ];
        if ($plan['billing'] === null) {
            // One-time products take the order amount as their price for this checkout.
            $body['custom_price'] = $minor;
        }
        $email = (string) $order->email;
        if ($email !== '' && is_email($email)) {
            $body['customer'] = ['email' => $email];
        }
        return (array) apply_filters('stackless_edd_creem_gateway_checkout_args', $body, $order, $subscription, $this->gateway);
    }

    /** Reuse an open session for the same order and amount (double submits, back button). */
    private function reusable(Order $order, string $mode, int $minor): string
    {
        $orderId = (int) $order->id;
        $checkoutId = Orders::meta($orderId, Gateway::META_CHECKOUT_ID);
        $url = Orders::meta($orderId, Gateway::META_CHECKOUT_URL);
        if ($checkoutId === '' || $url === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || (int) Orders::meta($orderId, Gateway::META_AMOUNT) !== $minor
            || (int) Orders::meta($orderId, Gateway::META_SESSION_AT) < time() - Gateway::SESSION_TTL) {
            return '';
        }
        $session = $this->gateway->api($mode)->request('GET', 'v1/checkouts', ['checkout_id' => $checkoutId]);
        if (is_wp_error($session)) {
            return '';
        }
        $status = (string) ($session['status'] ?? '');
        if ($status === 'completed') {
            (new Sync($this->gateway))->applyCheckout($session, $mode, $order);
            return Orders::receiptUrl($order);
        }
        return $status === 'pending' ? $url : '';
    }

    /* ---- Buyer returns from Creem ---------------------------------------------------- */

    /** One return route serves Creem and every copy; the order says which one it used. */
    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key; the checkout is read back from Creem.
        $orderId = isset($_GET[self::ORDER_ARG]) ? absint($_GET[self::ORDER_ARG]) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        $gateway = $order ? Gateway::forOrder($order) : null;
        if (!$order || !$gateway || $key === '' || !hash_equals(Orders::returnKey($order, 'creem-return'), $key)) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $target = self::returnTarget($gateway, $order);
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
     * Where a buyer coming back from Creem goes. Only the session this order started is
     * trusted: it is read back from Creem. Creem sends buyers back only after a completed
     * checkout, so anything not confirmed yet is left to the webhook and the buyer sees the
     * receipt; an expired session sends them back to pay.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, Order $order): array
    {
        $orderId = (int) $order->id;
        $expired = false;
        if (!Orders::isPaid($order) && Orders::meta($orderId, Gateway::META_CHECKOUT_ID) !== '') {
            $result = (new Sync($gateway))->confirmCheckout($order);
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $orderId . ': ' . $result->get_error_message());
                $expired = $result->get_error_code() === 'stackless_edd_creem_expired';
            }
            $order = Orders::get($orderId) ?: $order;
        }
        if (!Orders::isPaid($order) && $expired) {
            return [
                'url' => Orders::payUrl($order),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'),
            ];
        }
        return ['url' => Orders::receiptUrl($order), 'empty_cart' => true, 'notice' => ''];
    }
}
