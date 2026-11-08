<?php

namespace StacklessEDD\Modules\MercadoPagoGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Creates Checkout Pro preferences for EDD orders and handles the buyer's return.
 *
 * A preference is one line for the order total, so discounts, fees and taxes always add up
 * to what EDD charges. The external reference "edd{order}-{site}" ties every payment made
 * against it back to the order.
 */
class Checkout
{
    public const RETURN_ARG = 'stackless_edd_mercadopago_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Start (or reuse) a Checkout Pro preference for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Checkout Pro, or the receipt when the
     *                          order turns out to be paid or waiting for a cash payment.
     */
    public function start(Order $order)
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency, $this->gateway->siteId($mode))) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_mercadopago_currency', sprintf(__('Mercado Pago cannot charge in %s for this store. Choose another payment method.', 'stackless-lite-edd'), $currency));
        }
        if ((float) $order->total <= 0) {
            return new \WP_Error('stackless_edd_mercadopago_zero', __('Mercado Pago needs an amount greater than zero.', 'stackless-lite-edd'));
        }

        $amount = Money::amount($order->total, $currency);
        $reused = $this->reusable($order, $mode, $amount);
        if ($reused !== '') {
            return $reused;
        }

        $preference = $this->gateway->api($mode)->request('POST', 'checkout/preferences', $this->body($order, $amount, $currency));
        if (is_wp_error($preference)) {
            $this->gateway->log('Preference for order ' . $orderId . ' failed: ' . $preference->get_error_message(), 'error');
            return $preference;
        }
        $url = (string) ($preference['init_point'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || empty($preference['id'])) {
            return new \WP_Error('stackless_edd_mercadopago_checkout', __('Mercado Pago did not return a checkout page.', 'stackless-lite-edd'));
        }

        Orders::setGateway($order, $this->gateway->id());
        Orders::setMeta($orderId, Gateway::META_PREFERENCE_ID, (string) $preference['id']);
        Orders::setMeta($orderId, Gateway::META_CHECKOUT_URL, $url);
        Orders::setMeta($orderId, Gateway::META_STARTED_AT, (string) time());
        Orders::setMeta($orderId, Gateway::META_MODE, $mode);
        Orders::setMeta($orderId, Gateway::META_AMOUNT, (string) $amount);
        $this->gateway->log('Created Mercado Pago preference ' . $preference['id'] . ' for order ' . $orderId . '.');
        return $url;
    }

    public static function externalReference(Order $order): string
    {
        return EventMap::externalReference((int) $order->id, Sync::siteHost());
    }

    public function body(Order $order, float $amount, string $currency): array
    {
        $orderId = (int) $order->id;
        $store = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: 1: order number, 2: store name */
        $title = sprintf(__('Order %1$s at %2$s', 'stackless-lite-edd'), $order->get_number(), $store);
        $return = self::returnUrl($order);
        [$first, $last] = self::buyerName($order);
        $body = [
            'items' => [[
                'id' => 'edd-' . $orderId,
                'title' => function_exists('mb_substr') ? mb_substr($title, 0, 250) : substr($title, 0, 250),
                'quantity' => 1,
                'unit_price' => $amount,
                'currency_id' => $currency,
            ]],
            'payer' => array_filter([
                'email' => (string) $order->email,
                'name' => $first,
                'surname' => $last,
            ]),
            'back_urls' => ['success' => $return, 'pending' => $return, 'failure' => $return],
            'external_reference' => self::externalReference($order),
            'metadata' => ['edd_order_id' => (string) $orderId, 'edd_site' => Sync::siteHost()],
        ];
        // Mercado Pago only returns buyers automatically to HTTPS addresses.
        if (strpos($return, 'https://') === 0) {
            $body['auto_return'] = 'approved';
        }
        return (array) apply_filters('stackless_edd_mercadopago_gateway_checkout_args', $body, $order, $this->gateway);
    }

    /**
     * The buyer's first and last name from the order address, else the EDD customer's name.
     *
     * @return array{0:string,1:string}
     */
    public static function buyerName(Order $order): array
    {
        $address = $order->get_address();
        $first = is_object($address) ? trim((string) $address->first_name) : '';
        $last = is_object($address) ? trim((string) $address->last_name) : '';
        if ($first === '' && $last === '' && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $order->customer_id);
            $first = $customer ? trim((string) $customer->name) : '';
        }
        return [$first, $last];
    }

    public static function returnUrl(Order $order): string
    {
        // Mercado Pago appends payment_id, status, external_reference and preference_id.
        return add_query_arg([
            self::RETURN_ARG => '1',
            'order_id' => $order->id,
            'key' => Orders::returnKey($order, 'mercadopago-return'),
        ], home_url('/'));
    }

    /** Reuse the order's preference for the same amount and mode, unless a payment already went through. */
    private function reusable(Order $order, string $mode, float $amount): string
    {
        $orderId = (int) $order->id;
        $url = Orders::meta($orderId, Gateway::META_CHECKOUT_URL);
        if ($url === '' || Orders::meta($orderId, Gateway::META_MODE) !== $mode || Orders::meta($orderId, Gateway::META_AMOUNT) !== (string) $amount
            || (int) Orders::meta($orderId, Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $sync = new Sync($this->gateway);
        $payment = $sync->latestPayment($order, $mode);
        if (is_wp_error($payment)) {
            return '';
        }
        if ($payment && EventMap::paymentState((string) ($payment['status'] ?? '')) !== 'failed') {
            // Paid, or a cash payment started, in another tab: apply it and show the receipt.
            $sync->apply($order, $payment, $mode);
            return edd_get_success_page_uri();
        }
        return $url;
    }

    /* ---- Buyer returns from Mercado Pago ------------------------------------------- */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key; the payment is read back from Mercado Pago.
        if (!isset($_GET[self::RETURN_ARG])) {
            return;
        }
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $paymentId = isset($_GET['payment_id']) ? sanitize_text_field(wp_unslash($_GET['payment_id'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        $gateway = Gateway::instance();
        if (!$order || (string) $order->gateway !== $gateway->id() || $key === '' || !hash_equals(Orders::returnKey($order, 'mercadopago-return'), $key)) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $target = self::returnTarget($gateway, $order, $paymentId, $status);
        if ($target['empty_cart']) {
            edd_empty_cart();
        }
        if ($target['notice'] !== '') {
            edd_set_error('stackless_edd_mercadopago_failed', $target['notice']); // @phpstan-ignore argument.type
        }
        wp_safe_redirect($target['url']);
        exit;
    }

    /**
     * Where a buyer coming back from Mercado Pago goes. The payment is read back from Mercado
     * Pago; the address only says which payment to read.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, Order $order, string $paymentId = '', string $status = ''): array
    {
        $orderId = (int) $order->id;
        $state = '';
        if (!Orders::isPaid($order)) {
            $sync = new Sync($gateway);
            $mode = $gateway->orderMode($orderId);
            $payment = $paymentId !== '' && ctype_digit($paymentId) ? $sync->payment($paymentId, $mode) : $sync->latestPayment($order, $mode);
            if (is_array($payment) && $payment && $sync->belongsTo($payment, $order)) {
                $state = EventMap::paymentState((string) ($payment['status'] ?? ''));
                $sync->apply($order, $payment, $mode);
            } elseif (is_wp_error($payment)) {
                $gateway->log('Return for order ' . $orderId . ': ' . $payment->get_error_message());
            }
            $order = Orders::get($orderId) ?: $order;
        }
        $failed = $state === 'failed' || ($state === '' && in_array($status, ['rejected', 'failure', 'null'], true));
        if (!Orders::isPaid($order) && (string) $order->status !== 'on_hold' && $failed) {
            return [
                'url' => Orders::payUrl($order),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite-edd'),
            ];
        }
        // Paid, waiting for a cash payment, or still open: the receipt shows the real state.
        return ['url' => edd_get_success_page_uri(), 'empty_cart' => true, 'notice' => ''];
    }
}
