<?php

namespace StacklessEDD\Modules\MollieGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Starts Mollie payments for checkout and the account page, and handles the buyer's return.
 *
 * A checkout that must save a method (a subscription, or the buyer ticked "save") becomes a
 * first payment on a Mollie customer, limited to methods that leave a mandate. A zero-total
 * order (all trial) still needs one: 0.00 for cards and PayPal, otherwise one cent that is
 * refunded once Mollie confirms the mandate.
 */
final class Checkout
{
    public const RETURN_ARG = 'stackless_edd_mollie_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Create (or reuse) the Mollie payment for an order.
     *
     * @return string|\WP_Error Mollie checkout URL.
     */
    public function start(Order $order, bool $save, string $method = '')
    {
        $orderId = (int) $order->id;
        $mode = $this->gateway->mode();
        $api = $this->gateway->api($mode);
        $currency = strtoupper((string) $order->currency);
        if (!Money::supported($currency) || ($mode === 'test' && $currency !== 'EUR')) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_mollie_currency', sprintf(__('Mollie does not take payments in %s.', 'stackless-lite-edd'), $currency));
        }
        $total = (float) $order->total;
        $verification = $total <= 0;

        $methods = $this->methodsFor($api, $currency, $verification ? Money::smallest($currency) : Money::value($total, $currency), $save, $method);
        if ($save && !$methods) {
            return new \WP_Error('stackless_edd_mollie_no_method', __('None of the Mollie payment methods for this order can be saved for later payments. Choose another payment method.', 'stackless-lite-edd'));
        }
        $amount = $verification
            ? (count($methods) === 1 ? Methods::verificationAmount($methods[0], $currency) : Money::smallest($currency))
            : Money::value($total, $currency);

        // Placing the order again (back button, double click) reuses a still-open payment.
        $previous = Orders::metaString($orderId, Gateway::META_PAYMENT_ID);
        $previousUrl = Orders::metaString($orderId, Gateway::META_CHECKOUT_URL);
        if ($previous !== '' && $previousUrl !== '' && $this->gateway->orderMode($orderId) === $mode) {
            $existing = $api->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && ($existing['status'] ?? '') === 'open'
                && Money::matches($existing['amount'] ?? null, $amount, $currency)
                && (string) ($existing['sequenceType'] ?? 'oneoff') === ($save ? 'first' : 'oneoff')
                && !empty($existing['_links']['checkout']['href'])) {
                return (string) $existing['_links']['checkout']['href'];
            }
        }

        $body = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            /* translators: 1: order number, 2: site name */
            'description' => substr(sprintf(__('Order %1$s from %2$s', 'stackless-lite-edd'), Orders::number($order), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'redirectUrl' => self::returnUrl(['order_id' => $orderId, 'key' => Orders::returnKey($order, 'mollie-return')]),
            'cancelUrl' => Orders::payUrl($order),
            'webhookUrl' => $this->gateway->webhookUrl(),
            'locale' => self::locale(),
            'metadata' => Sync::metadata($order, $verification ? 'verify' : 'checkout'),
            'billingAddress' => self::address($order),
        ];
        if ($methods) {
            $body['method'] = count($methods) === 1 ? $methods[0] : $methods;
        }
        if ($save) {
            $customer = $this->gateway->customerFor($order, $mode);
            if (is_wp_error($customer)) {
                return $customer;
            }
            $body['sequenceType'] = 'first';
            $body['customerId'] = $customer;
            Orders::updateMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }

        $payment = $api->request('POST', 'payments', array_filter($body), 'stackless-edd-order-' . $orderId . '-' . md5((string) wp_json_encode($body)));
        if (is_wp_error($payment)) {
            return $payment;
        }
        $url = (string) ($payment['_links']['checkout']['href'] ?? '');
        if (empty($payment['id']) || $url === '') {
            return new \WP_Error('stackless_edd_mollie_checkout', __('Mollie did not return a checkout page for this payment.', 'stackless-lite-edd'));
        }
        Orders::updateMeta($orderId, Gateway::META_PAYMENT_ID, (string) $payment['id']);
        Orders::updateMeta($orderId, Gateway::META_CHECKOUT_URL, $url);
        Orders::updateMeta($orderId, Gateway::META_MODE, $mode);
        Orders::updateMeta($orderId, Gateway::META_SEQUENCE, $save ? 'first' : 'oneoff');
        Orders::updateMeta($orderId, Gateway::META_VERIFICATION, $verification ? 'yes' : 'no');
        $this->gateway->log(sprintf('Payment %s created for order %d (%s %s, %s).', (string) $payment['id'], $orderId, $amount, $currency, $save ? 'first' : 'oneoff'));
        return $url;
    }

    /**
     * Account page → Add payment method: a first payment that only saves the method.
     *
     * @return string|\WP_Error
     */
    public function startAdd(int $userId, string $method = '', int $subscriptionId = 0)
    {
        $mode = $this->gateway->mode();
        $api = $this->gateway->api($mode);
        $currency = Gateway::addCurrency($mode);
        $methods = $this->methodsFor($api, $currency, Money::smallest($currency), true, $method);
        if (!$methods) {
            return new \WP_Error('stackless_edd_mollie_no_method', __('No Mollie payment method that can be saved is switched on.', 'stackless-lite-edd'));
        }
        $customer = $this->gateway->customerForUser($userId, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $nonce = wp_generate_password(20, false);
        $amount = count($methods) === 1 ? Methods::verificationAmount($methods[0], $currency) : Money::smallest($currency);
        $body = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            /* translators: %s: site name */
            'description' => substr(sprintf(__('Save a payment method for %s', 'stackless-lite-edd'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'redirectUrl' => self::returnUrl(['flow' => 'add', 'ref' => $nonce]),
            'webhookUrl' => $this->gateway->webhookUrl(),
            'locale' => self::locale(),
            'sequenceType' => 'first',
            'customerId' => $customer,
            'method' => count($methods) === 1 ? $methods[0] : $methods,
            'metadata' => [
                'flow' => 'add',
                'wp_user_id' => (string) $userId,
                'edd_site' => Sync::site(),
                'edd_gateway' => Gateway::ID,
            ],
        ];
        $payment = $api->request('POST', 'payments', $body, 'stackless-edd-add-' . $userId . '-' . $nonce);
        if (is_wp_error($payment)) {
            return $payment;
        }
        $url = (string) ($payment['_links']['checkout']['href'] ?? '');
        if (empty($payment['id']) || $url === '') {
            return new \WP_Error('stackless_edd_mollie_checkout', __('Mollie did not return a checkout page for this payment.', 'stackless-lite-edd'));
        }
        update_user_meta($userId, Gateway::USER_META_PENDING_ADD, ['payment' => (string) $payment['id'], 'ref' => $nonce, 'mode' => $mode, 'at' => time(), 'subscription' => $subscriptionId]);
        return $url;
    }

    /**
     * Methods to send with a payment: the buyer's pick when it fits, else the store's list
     * narrowed to what Mollie offers here (only mandate methods when saving).
     *
     * @return string[]
     */
    private function methodsFor(Api $api, string $currency, string $amount, bool $first, string $picked): array
    {
        $available = Methods::enabled($api, $currency, $amount, $first);
        $ids = Methods::pick(Settings::configuredMethods(), $available, $first);
        if ($first) {
            $ids = array_values(array_filter($ids, static function ($id) use ($currency) {
                return Methods::mandateTakes(Methods::MANDATE_OF[$id] ?? '', $currency);
            }));
        }
        if ($picked !== '' && in_array($picked, $ids, true)) {
            return [$picked];
        }
        return $ids;
    }

    /** @param array<string,string|int> $args */
    public static function returnUrl(array $args): string
    {
        return add_query_arg(array_merge([self::RETURN_ARG => '1'], $args), home_url('/'));
    }

    /** Mollie locale from the site language (Mollie falls back to the browser for others). */
    public static function locale(): string
    {
        $supported = ['en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE', 'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES', 'pt_PT', 'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS', 'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT'];
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (in_array($locale, $supported, true)) {
            return $locale;
        }
        foreach ($supported as $candidate) {
            if (strpos($candidate, substr($locale, 0, 2) . '_') === 0) {
                return $candidate;
            }
        }
        return 'en_US';
    }

    /** @return array<string,string> Mollie billing address (empty values left out). */
    public static function address(Order $order): array
    {
        $address = $order->get_address();
        $street = trim(Orders::field($address, 'address') . ' ' . Orders::field($address, 'address2'));
        $fields = [
            'givenName' => Orders::field($address, 'first_name'),
            'familyName' => Orders::field($address, 'last_name'),
            'email' => (string) $order->email,
            'streetAndNumber' => $street,
            'postalCode' => Orders::field($address, 'postal_code'),
            'city' => Orders::field($address, 'city'),
            'country' => strtoupper(Orders::field($address, 'country')),
        ];
        // Mollie rejects partial addresses: send the street block only when it is complete.
        if ($street === '' || $fields['city'] === '' || !preg_match('/^[A-Z]{2}$/', $fields['country'])) {
            unset($fields['streetAndNumber'], $fields['postalCode'], $fields['city'], $fields['country']);
        }
        if (!is_email($fields['email'])) {
            unset($fields['email']);
        }
        return array_filter($fields, static function ($value) {
            return (string) $value !== '';
        });
    }

    /* ---- Return from Mollie ---------------------------------------------------------- */

    /** template_redirect: the buyer came back from Mollie's hosted page. */
    public function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Mollie sends the buyer back; the signed key or the stored reference authenticates the request.
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : '';
        if ($flow === 'add') {
            $ref = isset($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : '';
            $this->finishAdd(get_current_user_id(), $ref);
            return;
        }
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        // phpcs:enable
        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(Orders::returnKey($order, 'mollie-return'), $key) || (string) $order->gateway !== Gateway::ID) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        $outcome = (new Sync($this->gateway))->refreshOrder($order);
        $order = Orders::get($orderId);
        if ($order && (Orders::isPaid($order) || Orders::hasStatus($order, ['on_hold', 'processing']) || in_array($outcome, ['paid', 'pending', 'busy'], true))) {
            edd_empty_cart();
            wp_safe_redirect(Gateway::successUrl());
            exit;
        }
        // EDD documents the error id as int; every caller (EDD included) passes a string code.
        edd_set_error('stackless_edd_mollie_return', $outcome === 'failed' // @phpstan-ignore argument.type
            ? __('Your payment was declined. Please try again or choose another payment method.', 'stackless-lite-edd')
            : __('The payment was not completed. You can try again below.', 'stackless-lite-edd'));
        wp_safe_redirect($order ? Orders::payUrl($order) : edd_get_checkout_uri());
        exit;
    }

    private function finishAdd(int $userId, string $ref): void
    {
        $target = Account::listUrl();
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect($target);
            exit;
        }
        $subscriptionId = (int) ($pending['subscription'] ?? 0);
        $payment = $this->gateway->api((string) ($pending['mode'] ?? ''))->request('GET', 'payments/' . rawurlencode((string) ($pending['payment'] ?? '')));
        $token = null;
        if (is_array($payment)) {
            $token = (new Sync($this->gateway))->applyAddPayment($payment, (string) ($pending['mode'] ?? ''));
        }
        if ($token instanceof PaymentToken) {
            delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
            stackless_edd_add_notice(__('Payment method successfully added.', 'stackless-lite-edd'));
            if ($subscriptionId > 0) {
                $target = stackless_edd_account_url('subscriptions', ['id' => $subscriptionId]);
            }
        } elseif (is_array($payment) && in_array($payment['status'] ?? '', ['open', 'pending'], true)) {
            stackless_edd_add_notice(__('Mollie is still confirming your payment method. It appears here once confirmed.', 'stackless-lite-edd'), 'info');
        } else {
            delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
            stackless_edd_add_notice(__('Unable to add payment method to your account.', 'stackless-lite-edd'), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }
}
