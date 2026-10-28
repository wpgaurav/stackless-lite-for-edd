<?php

namespace StacklessEDD\Modules\PaddleGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\Assets;

defined('ABSPATH') || exit;

/**
 * Creates Paddle transactions for EDD orders, serves the page that opens Paddle's
 * overlay checkout for them, and handles the buyer's return.
 *
 * Paddle checkout runs in Paddle.js on a page of an approved domain. The checkout script
 * opens the overlay right over the EDD checkout; without JavaScript, and for pay links,
 * the buyer is sent to this store's own pay page (an order-token protected address),
 * which opens the overlay for the order's transaction.
 */
class Checkout
{
    /** Query argument of the pay, return and update-payment addresses. */
    public const ENDPOINT = 'stackless-edd-paddle';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Paddle items for an order.
     *
     * @return array{items:array,subscription:?SubscriptionData}|\WP_Error
     */
    public function plan(Order $order)
    {
        $orderId = (int) $order->id;
        $currency = strtoupper((string) $order->currency);
        $context = [
            'currency' => $currency,
            /* translators: %s: order number */
            'label' => sprintf(__('Order #%s', 'stackless-lite-edd'), (string) $order->get_number()),
            'tax_mode' => $this->gateway->taxMode(),
            'tax_category' => $this->gateway->taxCategory(),
        ];
        $total = Money::toMinor($order->total, $currency);
        $recurring = null;
        $subscription = null;

        if (!stackless_edd_is_renewal_order($orderId) && stackless_edd_order_contains_subscription($orderId)) {
            $subscriptions = array_values(stackless_edd_get_order_subscriptions($orderId));
            if (count($subscriptions) !== 1) {
                return new \WP_Error('stackless_edd_paddle_subscriptions', count($subscriptions) === 0
                    ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite-edd')
                    : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
            }
            $candidate = $subscriptions[0];
            // Store-billed subscriptions are paid one order at a time.
            if ($candidate instanceof SubscriptionData && $candidate->get_renewal_mode() !== 'manual') {
                $cycle = EventMap::cycle($candidate->get_billing_period(), $candidate->get_billing_interval());
                if (!$cycle) {
                    return new \WP_Error('stackless_edd_paddle_interval', __('Paddle cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite-edd'));
                }
                $subscription = $candidate;
                $recurring = [
                    'name' => self::subscriptionName($candidate),
                    'amount' => Money::toMinor($candidate->get_recurring_total(), $currency),
                    'cycle' => $cycle,
                    'trial_days' => self::trialDays($candidate, time()),
                ];
            }
        }

        $items = Items::build($total, $recurring === null ? self::orderLines($order) : [], $recurring, $context);
        if (is_string($items)) {
            return new \WP_Error('stackless_edd_paddle_' . $items, self::itemsMessage($items));
        }
        return ['items' => $items, 'subscription' => $subscription];
    }

    public static function itemsMessage(string $code): string
    {
        switch ($code) {
            case Items::ERROR_FIRST_PAYMENT:
                return __('Paddle charges at least one full billing cycle on the first payment, so it cannot take a first-payment discount. Remove the discount or choose another payment method.', 'stackless-lite-edd');
            case Items::ERROR_TRIAL_CHARGE:
                return __('Paddle cannot charge a signup fee or other items during a free trial. Buy them separately or choose another payment method.', 'stackless-lite-edd');
            default:
                return __('Paddle needs an amount greater than zero to start a checkout.', 'stackless-lite-edd');
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
                'amount' => Money::toMinor((float) $item->subtotal - (float) $item->discount, $currency),
            ];
        }
        foreach ($order->get_fees() as $fee) {
            $lines[] = ['name' => (string) $fee->description, 'amount' => Money::toMinor((float) $fee->subtotal, $currency)];
        }
        // EDD tax is left out on purpose: when it is charged, the lines no longer add up
        // and the order goes to Paddle as one line instead of itemizing tax as a product.
        return $lines;
    }

    private static function subscriptionName(SubscriptionData $subscription): string
    {
        $names = [];
        foreach ($subscription->get_items() as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $names[] = (string) ($item['name'] ?? '') . ($quantity > 1 ? ' × ' . $quantity : '');
        }
        $names = array_filter($names, static function (string $name): bool {
            return trim($name) !== '';
        });
        return $names ? implode(', ', $names) : __('Subscription', 'stackless-lite-edd');
    }

    /** Free-trial days still to run: the subscription's trial end, else the download's subscription terms. */
    public static function trialDays(SubscriptionData $subscription, int $now): int
    {
        $days = Items::trialDays($subscription->get_trial_end_date(), $now);
        if ($days > 0 || $subscription->get_trial_end_date() !== null) {
            return $days;
        }
        foreach ($subscription->get_items() as $item) {
            $terms = apply_filters('stackless_edd_subscription_product_terms', null, (int) ($item['download_id'] ?? 0), isset($item['price_id']) ? (int) $item['price_id'] : null);
            if (is_array($terms) && !empty($terms['trial_length'])) {
                return Items::trialDaysFromTerms((int) $terms['trial_length'], (string) ($terms['trial_period'] ?? 'day'), $now);
            }
        }
        return 0;
    }

    /**
     * Create (or reuse) the Paddle transaction for an order.
     *
     * @return string|\WP_Error URL of the store's Paddle pay page.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_paddle_currency', sprintf(__('Paddle cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }

        $body = $this->body($order, $plan['items'], $plan['subscription']);
        $fingerprint = $mode . ':' . md5((string) wp_json_encode($body));
        if (!$this->reusable($order, $fingerprint)) {
            $transaction = $this->gateway->api($mode)->request('POST', 'transactions', $body);
            if (is_wp_error($transaction)) {
                $this->gateway->log('Transaction for order ' . $orderId . ' failed: ' . $transaction->get_error_message(), 'error');
                return $transaction;
            }
            if (empty($transaction['id'])) {
                return new \WP_Error('stackless_edd_paddle_checkout', __('Paddle did not return a transaction.', 'stackless-lite-edd'));
            }
            Orders::setMeta($orderId, Gateway::META_TRANSACTION_ID, (string) $transaction['id']);
            Orders::setMeta($orderId, Gateway::META_FINGERPRINT, $fingerprint);
            $this->gateway->log('Created Paddle transaction ' . $transaction['id'] . ' for order ' . $orderId . '.');
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        return self::payUrl($order);
    }

    private function body(Order $order, array $items, ?SubscriptionData $subscription): array
    {
        $body = [
            'items' => $items,
            'currency_code' => strtoupper((string) $order->currency),
            'custom_data' => array_filter([
                'edd_order_id' => (string) $order->id,
                'edd_subscription_id' => $subscription ? (string) $subscription->get_id() : '',
                'edd_site' => self::siteHost(),
            ]),
            'checkout' => ['url' => self::payUrl($order)],
        ];
        return (array) apply_filters('stackless_edd_paddle_gateway_transaction_args', $body, $order, $subscription);
    }

    /** Reuse the order's open transaction when nothing about it changed (double submits, back button). */
    private function reusable(Order $order, string $fingerprint): bool
    {
        $orderId = (int) $order->id;
        $transactionId = Orders::meta($orderId, Gateway::META_TRANSACTION_ID);
        if ($transactionId === '' || Orders::meta($orderId, Gateway::META_FINGERPRINT) !== $fingerprint) {
            return false;
        }
        $mode = (string) strtok($fingerprint, ':');
        $transaction = $this->gateway->api($mode)->request('GET', 'transactions/' . rawurlencode($transactionId));
        return !is_wp_error($transaction) && in_array($transaction['status'] ?? '', ['draft', 'ready'], true);
    }

    public static function siteHost(): string
    {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * Token that authorizes the pay and return addresses of one order. Derived from the order's
     * payment key, which is never put in a URL itself (it also opens the receipt and downloads).
     */
    public static function token(Order $order): string
    {
        return substr(hash_hmac('sha256', 'stackless-edd-paddle|' . (int) $order->id . '|' . (string) $order->payment_key, wp_salt('nonce')), 0, 32);
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
        return self::endpoint('return', ['order_id' => (int) $order->id, 'key' => self::token($order)]);
    }

    public static function updatePaymentUrl(SubscriptionData $subscription): string
    {
        return wp_nonce_url(self::endpoint('update', ['subscription_id' => $subscription->get_id()]), 'stackless_edd_paddle_update_payment_' . $subscription->get_id());
    }

    /** Where a buyer who closes the checkout goes: back to paying this order. */
    public static function cancelUrl(Order $order): string
    {
        $url = stackless_edd_order_pay_url((int) $order->id);
        return $url !== '' ? $url : edd_get_checkout_uri();
    }

    /* ---- Pay page ---------------------------------------------------------------- */

    /** The order a pay or return request names, when its token checks out. */
    private function requestedOrder(): ?Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order token.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable
        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(self::token($order), $key) || $order->gateway !== $this->gateway->id()) {
            return null;
        }
        return $order;
    }

    /** Overlay settings for an order's transaction (pay page and the checkout script). @return array<string,mixed> */
    public function overlayConfig(Order $order): array
    {
        $mode = $this->gateway->orderMode((int) $order->id);
        return [
            'transactionId' => Orders::meta((int) $order->id, Gateway::META_TRANSACTION_ID),
            'successUrl' => self::returnUrl($order),
            'cancelUrl' => self::cancelUrl($order),
            'email' => (string) $order->email,
            'sandbox' => $mode === 'sandbox',
            'token' => $this->gateway->clientToken($mode),
            'locale' => substr((string) get_locale(), 0, 2),
        ];
    }

    public function renderPayPage(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        if (Orders::isPaid($order)) {
            wp_safe_redirect(edd_get_receipt_page_uri((int) $order->id));
            exit;
        }
        $config = $this->overlayConfig($order);
        if ($config['transactionId'] === '') {
            wp_safe_redirect((string) $config['cancelUrl']);
            exit;
        }
        $this->output($config, $order);
    }

    /** Paddle's own checkout for replacing the card on a subscription it bills. */
    public function handleUpdatePayment(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
        $id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        $subscription = $id ? stackless_edd_get_subscription($id) : null;
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $back = stackless_edd_account_url('subscriptions', $id ? ['id' => $id] : []);
        if (!$subscription instanceof SubscriptionData || !is_user_logged_in() || !wp_verify_nonce($nonce, 'stackless_edd_paddle_update_payment_' . $id)
            || ($subscription->get_user_id() !== get_current_user_id() && !current_user_can('edit_shop_payments'))
            || $subscription->get_gateway() !== $this->gateway->id()) {
            wp_safe_redirect($back);
            exit;
        }
        $paddleId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        $mode = $this->gateway->subscriptionMode($subscription);
        $transaction = $paddleId !== '' ? $this->gateway->api($mode)->request('GET', 'subscriptions/' . rawurlencode($paddleId) . '/update-payment-method-transaction') : new \WP_Error('stackless_edd_paddle_none', '');
        if (is_wp_error($transaction) || empty($transaction['id'])) {
            $this->gateway->log('Update payment method for subscription ' . $id . ' failed: ' . (is_wp_error($transaction) ? $transaction->get_error_message() : 'no transaction'), 'error');
            stackless_edd_add_notice(__('Paddle could not open the payment method update. Please try again later.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $this->output([
            'transactionId' => (string) $transaction['id'],
            'successUrl' => $back,
            'cancelUrl' => $back,
            'email' => $subscription->get_email(),
            'sandbox' => $mode === 'sandbox',
            'token' => $this->gateway->clientToken($mode),
            'locale' => substr((string) get_locale(), 0, 2),
        ], $subscription);
    }

    /**
     * @param array<string,mixed>    $config
     * @param Order|SubscriptionData $context
     */
    private function output(array $config, $context): void
    {
        if ((string) $config['token'] === '') {
            stackless_edd_add_notice(__('Paddle is missing its client-side token. Please choose another payment method.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect((string) $config['cancelUrl']);
            exit;
        }
        $config['i18n'] = [
            'failed' => __('Paddle checkout could not load. Check your connection and try again.', 'stackless-lite-edd'),
        ];
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex, nofollow');
        stackless_edd_get_template('paddle_gateway/pay-page.php', [
            'order' => $context,
            'config' => (array) apply_filters('stackless_edd_paddle_gateway_checkout_settings', $config, $context),
            'script_url' => self::scriptUrl(),
        ]);
        exit;
    }

    /** The pay page script, minified unless SCRIPT_DEBUG (the page is printed without wp_enqueue_script). */
    private static function scriptUrl(): string
    {
        return Assets::url('paddle_gateway/pay.js') . '?ver=' . rawurlencode(STACKLESS_EDD_VERSION);
    }

    /* ---- Buyer returns from Paddle ------------------------------------------------- */

    public function handleReturn(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $transactionId = Orders::meta((int) $order->id, Gateway::META_TRANSACTION_ID);
        if (!Orders::isPaid($order) && $transactionId !== '') {
            $result = (new Sync($this->gateway))->confirmById($order, $transactionId);
            if (is_wp_error($result)) {
                $this->gateway->log('Return for order ' . $order->id . ': ' . $result->get_error_message());
            }
        }

        edd_empty_cart();
        // Paid, still settling, or abandoned: the receipt shows the real state.
        wp_safe_redirect(edd_get_receipt_page_uri((int) $order->id));
        exit;
    }
}
