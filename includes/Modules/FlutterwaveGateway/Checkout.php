<?php

namespace StacklessEDD\Modules\FlutterwaveGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Creates Flutterwave hosted payment links for EDD orders and handles the buyer's return.
 */
class Checkout
{
    public const RETURN_ARG = 'stackless_edd_flutterwave_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Start (or reuse) a Flutterwave payment for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Flutterwave's checkout, or the
     *                          receipt when the order turns out to be paid.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_flutterwave_currency', sprintf(__('Flutterwave cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }
        if ((float) $order->total <= 0) {
            return new \WP_Error('stackless_edd_flutterwave_zero', __('Flutterwave needs an amount greater than zero.', 'stackless-lite-edd'));
        }
        $email = (string) $order->email;
        if ($email === '' || !is_email($email)) {
            return new \WP_Error('stackless_edd_flutterwave_email', __('Flutterwave needs a valid email address to take a payment.', 'stackless-lite-edd'));
        }

        $amount = Money::format($order->total, $currency);
        $reused = $this->reusable($order, $mode, $amount);
        if ($reused !== '') {
            return $reused;
        }

        $txRef = self::reference($orderId);
        $session = $this->gateway->api($mode)->request('POST', 'payments', $this->body($order, $txRef, $amount, $currency));
        if (is_wp_error($session)) {
            $this->gateway->log('Payment link for order ' . $orderId . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        $url = (string) ($session['link'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('stackless_edd_flutterwave_checkout', __('Flutterwave did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_TX_REF, $txRef);
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_URL, $url);
        Orders::setMeta($orderId, Gateway::META_STARTED_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, $amount);
        $this->gateway->log('Started Flutterwave payment ' . $txRef . ' for order ' . $orderId . '.');
        return $url;
    }

    /** A fresh tx_ref: "edd{order id}-{random}", unique per attempt. */
    public static function reference(int $orderId): string
    {
        return 'edd' . $orderId . '-' . strtolower(wp_generate_password(10, false, false));
    }

    /** Whether a tx_ref was made by this store for this order. */
    public static function referenceFor(string $txRef, int $orderId): bool
    {
        return $orderId > 0 && strpos($txRef, 'edd' . $orderId . '-') === 0;
    }

    /** The order id a tx_ref was made for, 0 when it is not one of ours. */
    public static function orderIdFrom(string $txRef): int
    {
        return preg_match('/^edd(\d+)-[a-z0-9]+$/', $txRef, $m) ? (int) $m[1] : 0;
    }

    public function body(Order $order, string $txRef, string $amount, string $currency): array
    {
        $customer = ['email' => (string) $order->email];
        $name = self::buyerName($order);
        if ($name !== '') {
            $customer['name'] = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
        }
        $body = [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => self::returnUrl($order),
            'customer' => $customer,
            'customizations' => [
                'title' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                /* translators: %s: order number */
                'description' => sprintf(__('Order %s', 'stackless-lite-edd'), (string) $order->get_number()),
            ],
            'meta' => [
                'edd_order_id' => (string) $order->id,
                'edd_site' => Sync::siteHost(),
            ],
        ];
        return (array) apply_filters('stackless_edd_flutterwave_gateway_checkout_args', $body, $order, $this->gateway);
    }

    /** The buyer's name from the order address, else the EDD customer. */
    public static function buyerName(Order $order): string
    {
        $address = $order->get_address();
        $name = is_object($address) ? trim((string) $address->first_name . ' ' . (string) $address->last_name) : '';
        if ($name === '' && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? trim((string) $customer->name) : '';
        }
        return $name;
    }

    public static function returnUrl(Order $order): string
    {
        // Flutterwave appends status, tx_ref and transaction_id to this address.
        return add_query_arg([
            self::RETURN_ARG => '1',
            'order_id' => $order->id,
            'key' => Orders::returnKey($order, 'flutterwave-return'),
        ], home_url('/'));
    }

    /** Reuse an unpaid payment link for the same order, amount and mode (double submits, back button). */
    private function reusable(Order $order, string $mode, string $amount): string
    {
        $orderId = (int) $order->id;
        $txRef = Orders::meta($orderId, Gateway::META_TX_REF);
        $url = Orders::meta($orderId, Gateway::META_CHECKOUT_URL);
        if ($txRef === '' || $url === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || Orders::meta($orderId, Gateway::META_AMOUNT) !== $amount
            || (int) Orders::meta($orderId, Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $transaction = $this->gateway->api($mode)->request('GET', 'transactions/verify_by_reference', ['tx_ref' => $txRef]);
        if (is_wp_error($transaction)) {
            // Nothing paid against this link yet.
            return Api::isNotFound($transaction) ? $url : '';
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'paid') {
            // Paid in another tab: settle it and show the receipt.
            (new Sync($this->gateway))->settle($order, $transaction, $mode);
            return edd_get_success_page_uri();
        }
        return $state === 'pending' ? $url : '';
    }

    /* ---- Buyer returns from Flutterwave --------------------------------------------- */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key; the transaction is verified with Flutterwave.
        if (!isset($_GET[self::RETURN_ARG])) {
            return;
        }
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $txRef = isset($_GET['tx_ref']) ? sanitize_text_field(wp_unslash($_GET['tx_ref'])) : '';
        $transactionId = isset($_GET['transaction_id']) ? sanitize_text_field(wp_unslash($_GET['transaction_id'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        $gateway = Gateway::instance();
        if (!$order || (string) $order->gateway !== $gateway->id() || $key === '' || !hash_equals(Orders::returnKey($order, 'flutterwave-return'), $key)) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $target = self::returnTarget($gateway, $order, $txRef, $transactionId, $status);
        if ($target['empty_cart']) {
            edd_empty_cart();
        }
        if ($target['notice'] !== '') {
            edd_set_error('stackless_edd_flutterwave_failed', $target['notice']); // @phpstan-ignore argument.type
        }
        wp_safe_redirect($target['url']);
        exit;
    }

    /**
     * Where a buyer coming back from Flutterwave goes. The query string is only a hint: the
     * transaction is verified with Flutterwave before anything changes.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, Order $order, string $txRef = '', string $transactionId = '', string $status = ''): array
    {
        $orderId = (int) $order->id;
        $failed = false;
        if (!Orders::isPaid($order)) {
            if (!self::referenceFor($txRef, $orderId)) {
                $txRef = Orders::meta($orderId, Gateway::META_TX_REF);
                $transactionId = '';
            }
            $result = $txRef !== '' ? (new Sync($gateway))->confirm($order, $txRef, $transactionId) : new \WP_Error('stackless_edd_flutterwave_pending', '');
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $orderId . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_edd_flutterwave_failed'
                    || (in_array($status, ['cancelled', 'failed'], true) && $result->get_error_code() !== 'stackless_edd_flutterwave_pending_payment');
            }
            $order = Orders::get($orderId) ?: $order;
        }
        if (!Orders::isPaid($order) && $failed) {
            return [
                'url' => Orders::payUrl($order),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'),
            ];
        }
        // Paid, still settling, or not started: the receipt shows the real state.
        return ['url' => edd_get_success_page_uri(), 'empty_cart' => true, 'notice' => ''];
    }
}
