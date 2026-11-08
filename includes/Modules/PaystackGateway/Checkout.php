<?php

namespace StacklessEDD\Modules\PaystackGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Starts Paystack transactions for EDD orders and handles the buyer's return.
 */
class Checkout
{
    public const RETURN_ARG = 'stackless_edd_paystack_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Start (or reuse) a Paystack transaction for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Paystack's checkout, or the
     *                          receipt when the order turns out to be paid.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_paystack_currency', sprintf(__('Paystack cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }
        $total = (float) $order->total;
        if ($total <= 0) {
            return new \WP_Error('stackless_edd_paystack_zero', __('Paystack needs an amount greater than zero.', 'stackless-lite-edd'));
        }
        $minimum = Money::minimum($currency);
        if ($minimum > 0 && $total < $minimum) {
            return new \WP_Error('stackless_edd_paystack_minimum', sprintf(
                /* translators: %s: smallest amount Paystack accepts */
                __('Paystack accepts payments of %s or more. Choose another payment method for this order.', 'stackless-lite-edd'),
                Orders::money($minimum, $currency)
            ));
        }
        $email = (string) $order->email;
        if ($email === '' || !is_email($email)) {
            return new \WP_Error('stackless_edd_paystack_email', __('Paystack needs a valid email address to take a payment.', 'stackless-lite-edd'));
        }

        $minor = Money::toMinor($total, $currency);
        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $reference = self::reference($orderId);
        $session = $this->gateway->api($mode)->request('POST', 'transaction/initialize', $this->body($order, $reference, $minor, $currency));
        if (is_wp_error($session)) {
            $this->gateway->log('Initialize for order ' . $orderId . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        $url = (string) ($session['authorization_url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('stackless_edd_paystack_checkout', __('Paystack did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_REFERENCE, (string) ($session['reference'] ?? $reference));
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_URL, $url);
        Orders::setMeta($orderId, Gateway::META_STARTED_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $minor);
        $this->gateway->log('Started Paystack transaction ' . $reference . ' for order ' . $orderId . '.');
        return $url;
    }

    /** A fresh transaction reference: "edd{order id}-{random}", unique per attempt. */
    public static function reference(int $orderId): string
    {
        return 'edd' . $orderId . '-' . strtolower(wp_generate_password(10, false, false));
    }

    /** Whether a reference was made by this store for this order. */
    public static function referenceFor(string $reference, int $orderId): bool
    {
        return $orderId > 0 && strpos($reference, 'edd' . $orderId . '-') === 0;
    }

    public function body(Order $order, string $reference, int $minor, string $currency): array
    {
        $orderId = (int) $order->id;
        $body = [
            'email' => (string) $order->email,
            'amount' => (string) $minor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => self::returnUrl($order),
            'metadata' => [
                'edd_order_id' => (string) $orderId,
                'edd_site' => Sync::siteHost(),
                'cancel_action' => self::cancelUrl($order),
                'custom_fields' => [[
                    'display_name' => __('Order', 'stackless-lite-edd'),
                    'variable_name' => 'edd_order_number',
                    'value' => (string) $order->get_number(),
                ]],
            ],
        ];
        $name = self::buyerName($order);
        if ($name !== '') {
            $body['metadata']['customer_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
        }
        return (array) apply_filters('stackless_edd_paystack_gateway_checkout_args', $body, $order, $this->gateway);
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
        // Paystack appends ?trxref=…&reference=… to this address.
        return add_query_arg([
            self::RETURN_ARG => '1',
            'order_id' => $order->id,
            'key' => Orders::returnKey($order, 'paystack-return'),
        ], home_url('/'));
    }

    /** Where Paystack sends a buyer who cancels: back to paying this order. */
    public static function cancelUrl(Order $order): string
    {
        foreach ([Orders::payUrl($order), edd_get_checkout_uri(), home_url('/')] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return (string) $url;
            }
        }
        return home_url('/');
    }

    /** Reuse an unpaid transaction for the same order, amount and mode (double submits, back button). */
    private function reusable(Order $order, string $mode, int $minor): string
    {
        $orderId = (int) $order->id;
        $reference = Orders::meta($orderId, Gateway::META_REFERENCE);
        $url = Orders::meta($orderId, Gateway::META_CHECKOUT_URL);
        if ($reference === '' || $url === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || (int) Orders::meta($orderId, Gateway::META_AMOUNT) !== $minor
            || (int) Orders::meta($orderId, Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $transaction = $this->gateway->api($mode)->request('GET', 'transaction/verify/' . rawurlencode($reference));
        if (is_wp_error($transaction)) {
            return '';
        }
        $status = strtolower((string) ($transaction['status'] ?? ''));
        if ($status === 'success') {
            // Paid in another tab: settle it and show the receipt.
            (new Sync($this->gateway))->settle($order, $transaction, $mode);
            return edd_get_success_page_uri();
        }
        return in_array($status, ['abandoned', 'ongoing', 'pending'], true) ? $url : '';
    }

    /* ---- Buyer returns from Paystack ------------------------------------------------ */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key; the transaction is verified with Paystack.
        if (!isset($_GET[self::RETURN_ARG])) {
            return;
        }
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $reference = isset($_GET['reference']) ? sanitize_text_field(wp_unslash($_GET['reference'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        $gateway = Gateway::instance();
        if (!$order || (string) $order->gateway !== $gateway->id() || $key === '' || !hash_equals(Orders::returnKey($order, 'paystack-return'), $key)) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $target = self::returnTarget($gateway, $order, $reference);
        if ($target['empty_cart']) {
            edd_empty_cart();
        }
        if ($target['notice'] !== '') {
            edd_set_error('stackless_edd_paystack_failed', $target['notice']); // @phpstan-ignore argument.type
        }
        wp_safe_redirect($target['url']);
        exit;
    }

    /**
     * Where a buyer coming back from Paystack goes. The transaction is verified with
     * Paystack; the reference in the address only says which of this order's attempts to check.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, Order $order, string $reference = ''): array
    {
        $orderId = (int) $order->id;
        $failed = false;
        if (!Orders::isPaid($order)) {
            if (!self::referenceFor($reference, $orderId)) {
                $reference = Orders::meta($orderId, Gateway::META_REFERENCE);
            }
            $result = $reference !== '' ? (new Sync($gateway))->confirm($order, $reference) : new \WP_Error('stackless_edd_paystack_pending', '');
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $orderId . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_edd_paystack_failed';
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
        // Paid, still settling, or abandoned: the receipt shows the real state.
        return ['url' => edd_get_success_page_uri(), 'empty_cart' => true, 'notice' => ''];
    }
}
