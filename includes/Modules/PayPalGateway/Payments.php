<?php

namespace StacklessEDD\Modules\PayPalGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Payment flows: checkout capture/authorize, vault-with-purchase, vault setup
 * tokens, the redirect fallback, renewals, refunds and authorization capture.
 *
 * Every path that settles an order ends in applyResult(), which checks the
 * PayPal amount and currency against the EDD order before marking it paid,
 * and is safe to run more than once for the same order.
 */
class Payments
{
    private const SESSION_KEY = 'stackless_edd_paypal_gateway_ids';
    public const RETURN_ARG = 'stackless_edd_paypal_return';

    /** @var Gateway */
    private $gateway;

    /** @var OrderBuilder */
    private $builder;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
        $this->builder = new OrderBuilder($gateway);
    }

    public function builder(): OrderBuilder
    {
        return $this->builder;
    }

    /* ---- Session binding --------------------------------------------------- */

    /** Remember a PayPal order or setup token created for this browser session. */
    public function remember(string $id): void
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return;
        }
        $ids = (array) EDD()->session->get(self::SESSION_KEY);
        $ids[$id] = time();
        $ids = array_filter($ids, static function ($time) {
            return (int) $time > time() - 3 * HOUR_IN_SECONDS;
        });
        arsort($ids);
        EDD()->session->set(self::SESSION_KEY, array_slice($ids, 0, 10, true));
    }

    public function isRemembered(string $id): bool
    {
        if ($id === '' || !function_exists('EDD') || !EDD()->session) {
            return false;
        }
        $ids = (array) EDD()->session->get(self::SESSION_KEY);
        return isset($ids[$id]) && (int) $ids[$id] > time() - 3 * HOUR_IN_SECONDS;
    }

    public function returnUrl(Order $order, string $type): string
    {
        return add_query_arg([
            self::RETURN_ARG => $type === 'setup' ? 'setup' : 'order',
            'order_id' => $order->id,
            'key' => Orders::returnKey($order, 'paypal-return'),
        ], home_url('/'));
    }

    /* ---- Creating PayPal objects from the browser ------------------------- */

    /**
     * PayPal order for the current cart (before the EDD order exists).
     *
     * @return array|\WP_Error ['id' => ...]
     */
    public function createForCart(string $funding)
    {
        if (!edd_get_cart_contents() && !edd_cart_has_fees()) {
            return new \WP_Error('stackless_edd_paypal_cart', __('Your cart is empty.', 'stackless-lite-edd'));
        }
        $currency = stackless_edd_current_currency();
        if (!Amount::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_edd_paypal_currency', sprintf(__('PayPal cannot take payments in %s.', 'stackless-lite-edd'), $currency));
        }

        $vault = stackless_edd_cart_requires_saved_payment_method();
        if ((float) edd_get_cart_total() <= 0) {
            return new \WP_Error('stackless_edd_paypal_zero', __('Nothing to pay today. Use the PayPal button again to save your account for future payments.', 'stackless-lite-edd'));
        }

        $response = $this->gateway->api()->createOrder($this->builder->fromCart($vault, $funding));
        if (is_wp_error($response)) {
            $this->gateway->log('Create order (cart) failed: ' . $response->get_error_message() . ' ' . wp_json_encode($response->get_error_data()), 'error');
            return $response;
        }
        if (empty($response['id'])) {
            return new \WP_Error('stackless_edd_paypal_create', __('PayPal did not return an order.', 'stackless-lite-edd'));
        }

        $this->remember((string) $response['id']);
        $this->gateway->log('Created PayPal order ' . $response['id'] . ' for the cart.');
        return ['id' => (string) $response['id']];
    }

    /**
     * Vault setup token for a zero-total checkout.
     *
     * @return array|\WP_Error
     */
    public function createSetupToken(string $returnUrl, string $cancelUrl, ?Order $order = null)
    {
        $response = $this->gateway->api()->createSetupToken($this->builder->setupToken($returnUrl, $cancelUrl));
        if (is_wp_error($response)) {
            $this->gateway->log('Create setup token failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        if (empty($response['id'])) {
            return new \WP_Error('stackless_edd_paypal_setup', __('PayPal did not return a setup token.', 'stackless-lite-edd'));
        }
        if ($order) {
            Orders::setMeta((int) $order->id, Gateway::META_SETUP_TOKEN, (string) $response['id']);
            Orders::setMeta((int) $order->id, Gateway::META_ENV, $this->gateway->environment());
        }
        $this->remember((string) $response['id']);
        return ['id' => (string) $response['id'], 'approve' => self::link($response, ['approve', 'payer-action'])];
    }

    /* ---- Purchase ----------------------------------------------------------- */

    /**
     * Settle the pending EDD order EDD just created for this purchase.
     *
     * @return array{result:string,redirect?:string,message?:string} result success|failure; a
     *         redirect means "send the buyer there to approve", none means "show the receipt".
     */
    public function process(Order $order, string $paypalOrderId, string $setupToken): array
    {
        $currency = (string) $order->currency;
        if (!Amount::supported($currency)) {
            /* translators: %s: currency code */
            return ['result' => 'failure', 'message' => sprintf(__('PayPal cannot take payments in %s. Choose another payment method.', 'stackless-lite-edd'), $currency)];
        }

        $orderId = (int) $order->id;
        $requiresVault = stackless_edd_order_requires_saved_payment_method($orderId);

        if ((float) $order->total <= 0) {
            if (!$requiresVault) {
                stackless_edd_complete_order($orderId);
                return ['result' => 'success'];
            }
            if ($setupToken !== '') {
                if (!$this->isRemembered($setupToken) && $setupToken !== Orders::meta($orderId, Gateway::META_SETUP_TOKEN)) {
                    return ['result' => 'failure', 'message' => __('Your PayPal session expired. Please use the PayPal button again.', 'stackless-lite-edd')];
                }
                $saved = $this->completeSetupToken($order, $setupToken);
                return is_wp_error($saved)
                    ? ['result' => 'failure', 'message' => $saved->get_error_message()]
                    : ['result' => 'success'];
            }
            // No buttons (no JS, or Purchase clicked): send the buyer to PayPal to approve.
            $token = $this->createSetupToken($this->returnUrl($order, 'setup'), Orders::payUrl($order), $order);
            if (is_wp_error($token) || empty($token['approve'])) {
                return ['result' => 'failure', 'message' => is_wp_error($token) ? $token->get_error_message() : __('PayPal did not return an approval link.', 'stackless-lite-edd')];
            }
            return ['result' => 'success', 'redirect' => (string) $token['approve']];
        }

        if ($paypalOrderId !== '') {
            if (!$this->isRemembered($paypalOrderId) && $paypalOrderId !== Orders::meta($orderId, Gateway::META_ORDER_ID)) {
                return ['result' => 'failure', 'message' => __('Your PayPal session expired. Please use the PayPal button again.', 'stackless-lite-edd')];
            }
            $result = $this->completeCheckout($order, $paypalOrderId);
            if ($result['result'] === 'failed') {
                return ['result' => 'failure', 'message' => (string) ($result['message'] ?? __('PayPal could not take this payment.', 'stackless-lite-edd'))];
            }
            return ['result' => 'success'];
        }

        // Redirect fallback: create the PayPal order for this EDD order and send the buyer to approve it.
        $response = $this->gateway->api()->createOrder($this->builder->fromOrder($order, $requiresVault, 'paypal', $this->returnUrl($order, 'order')));
        if (is_wp_error($response)) {
            $this->gateway->log('Create order (redirect) for #' . $orderId . ' failed: ' . $response->get_error_message(), 'error');
            return ['result' => 'failure', 'message' => $response->get_error_message()];
        }
        $approve = self::link($response, ['payer-action', 'approve']);
        if (empty($response['id']) || $approve === '') {
            return ['result' => 'failure', 'message' => __('PayPal did not return an approval link.', 'stackless-lite-edd')];
        }
        Orders::setMeta($orderId, Gateway::META_ORDER_ID, (string) $response['id']);
        Orders::setMeta($orderId, Gateway::META_ENV, $this->gateway->environment());
        return ['result' => 'success', 'redirect' => $approve];
    }

    /**
     * Settle an approved PayPal order against an EDD order.
     *
     * @return array{result:string,message?:string}
     */
    public function completeCheckout(Order $order, string $paypalOrderId): array
    {
        $done = Lock::run('order-' . $order->id, function () use ($order, $paypalOrderId) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite-edd')];
            }
            $orderId = (int) $order->id;
            if (Orders::isPaid($order) && Orders::meta($orderId, Gateway::META_ORDER_ID) === $paypalOrderId) {
                return ['result' => 'success'];
            }

            $api = $this->gateway->api();
            $paypal = $api->getOrder($paypalOrderId);
            if (is_wp_error($paypal)) {
                return ['result' => 'failed', 'message' => $paypal->get_error_message()];
            }

            $unit = (array) ($paypal['purchase_units'][0] ?? []);
            $custom = (string) ($unit['custom_id'] ?? '');
            if ($custom !== '' && $custom !== (string) $orderId) {
                $this->gateway->log('PayPal order ' . $paypalOrderId . ' belongs to order ' . $custom . ', not ' . $orderId, 'warning');
                return ['result' => 'failed', 'message' => __('This PayPal payment belongs to a different order. Please use the PayPal button again.', 'stackless-lite-edd')];
            }

            $status = (string) ($paypal['status'] ?? '');
            if ($status === 'COMPLETED') {
                return $this->applyResult($order, $paypal, 'checkout');
            }
            if ($status !== 'APPROVED') {
                return ['result' => 'failed', 'message' => __('PayPal has not approved this payment yet. Please finish in the PayPal window.', 'stackless-lite-edd')];
            }

            if (strtoupper((string) ($unit['amount']['currency_code'] ?? '')) !== strtoupper((string) $order->currency)) {
                return ['result' => 'failed', 'message' => __('The order currency changed after PayPal approved the payment. Please use the PayPal button again.', 'stackless-lite-edd')];
            }

            $ops = $this->builder->patchOperations($order, $paypal);
            if ($ops) {
                $patched = $api->patchOrder($paypalOrderId, $ops);
                if (is_wp_error($patched)) {
                    $this->gateway->log('Patch of ' . $paypalOrderId . ' failed: ' . $patched->get_error_message(), 'error');
                    return ['result' => 'failed', 'message' => __('Your order total changed after PayPal approved the payment. Please use the PayPal button again.', 'stackless-lite-edd')];
                }
            }

            Orders::setMeta($orderId, Gateway::META_ORDER_ID, $paypalOrderId);
            Orders::setMeta($orderId, Gateway::META_ENV, $this->gateway->environment());

            $intent = strtoupper((string) ($paypal['intent'] ?? 'CAPTURE'));
            $requestId = 'stackless-edd-' . strtolower($intent) . '-' . $orderId . '-' . $paypalOrderId;
            $settled = $intent === 'AUTHORIZE' ? $api->authorizeOrder($paypalOrderId, $requestId) : $api->captureOrder($paypalOrderId, $requestId);

            if (is_wp_error($settled)) {
                $data = (array) $settled->get_error_data();
                $issue = (string) ($data['issue'] ?? '');
                if (in_array($issue, ['ORDER_ALREADY_CAPTURED', 'ORDER_ALREADY_AUTHORIZED'], true)) {
                    $settled = $api->getOrder($paypalOrderId);
                }
                if (is_wp_error($settled)) {
                    $this->gateway->log(ucfirst(strtolower($intent)) . ' of ' . $paypalOrderId . ' failed: ' . $settled->get_error_message(), 'error');
                    $message = $issue === 'INSTRUMENT_DECLINED' || $issue === 'PAYER_ACTION_REQUIRED'
                        ? __('PayPal declined that funding source. Please use the PayPal button again and pick another way to pay.', 'stackless-lite-edd')
                        : $settled->get_error_message();
                    Orders::note($orderId, sprintf(
                        /* translators: %s: PayPal error */
                        __('PayPal payment failed: %s', 'stackless-lite-edd'),
                        $settled->get_error_message()
                    ));
                    return ['result' => 'failed', 'message' => $message];
                }
            }

            return $this->applyResult($order, $settled, 'checkout');
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'failed', 'message' => __('This order is already being processed. Refresh the page in a moment.', 'stackless-lite-edd')];
    }

    /**
     * Apply a PayPal order (after capture/authorize, or as fetched) to an EDD order.
     *
     * @return array{result:string,message?:string} success|pending|failed
     */
    public function applyResult(Order $order, array $paypal, string $context): array
    {
        $orderId = (int) $order->id;
        $unit = (array) ($paypal['purchase_units'][0] ?? []);
        $custom = (string) ($unit['custom_id'] ?? '');
        if ($custom !== '' && $custom !== (string) $orderId) {
            return ['result' => 'failed', 'message' => __('PayPal returned a payment for a different order.', 'stackless-lite-edd')];
        }

        $currency = (string) $order->currency;
        $expected = (float) $order->total;
        $capture = (array) ($unit['payments']['captures'][0] ?? []);
        $authorization = (array) ($unit['payments']['authorizations'][0] ?? []);
        $paypalOrderId = (string) ($paypal['id'] ?? '');

        if ($paypalOrderId !== '') {
            Orders::setMeta($orderId, Gateway::META_ORDER_ID, $paypalOrderId);
        }
        $email = (string) ($paypal['payment_source']['paypal']['email_address'] ?? ($paypal['payer']['email_address'] ?? ''));
        if ($email !== '') {
            Orders::setMeta($orderId, Gateway::META_PAYER_EMAIL, sanitize_email($email));
        }

        if ($capture) {
            $captureId = (string) ($capture['id'] ?? '');
            $status = (string) ($capture['status'] ?? '');
            Orders::setMeta($orderId, Gateway::META_CAPTURE_ID, $captureId);

            if (in_array($status, ['DECLINED', 'FAILED'], true)) {
                $note = sprintf(/* translators: %s: PayPal capture id */ __('PayPal declined the payment (capture %s).', 'stackless-lite-edd'), $captureId);
                if (!Orders::isPaid($order)) {
                    Orders::setStatus($orderId, 'failed', $note);
                }
                return ['result' => 'failed', 'message' => __('PayPal declined the payment. Please try another way to pay.', 'stackless-lite-edd')];
            }

            if (!Amount::matches((array) ($capture['amount'] ?? []), $expected, $currency)) {
                $note = sprintf(
                    /* translators: 1: amount PayPal captured, 2: order total */
                    __('PayPal captured %1$s but the order total is %2$s. Check the payment in PayPal before delivering the order.', 'stackless-lite-edd'),
                    (string) ($capture['amount']['value'] ?? '?') . ' ' . (string) ($capture['amount']['currency_code'] ?? ''),
                    Amount::format($expected, $currency) . ' ' . $currency
                );
                if (!Orders::isPaid($order)) {
                    Orders::setStatus($orderId, 'on_hold', $note);
                } else {
                    Orders::note($orderId, $note);
                }
                $this->gateway->log('Amount mismatch on order ' . $orderId . ': ' . $note, 'warning');
                return ['result' => $context === 'checkout' ? 'success' : 'pending', 'message' => $note];
            }

            $this->maybeSaveVault($order, $paypal);

            if ($status === 'PENDING') {
                $reason = (string) ($capture['status_details']['reason'] ?? '');
                if (!Orders::isPaid($order) && (string) $order->status !== 'on_hold') {
                    Orders::setStatus($orderId, 'on_hold', sprintf(
                        /* translators: 1: capture id, 2: PayPal reason code */
                        __('PayPal payment %1$s is pending (%2$s). The order updates when PayPal confirms it.', 'stackless-lite-edd'),
                        $captureId,
                        $reason !== '' ? $reason : __('no reason given', 'stackless-lite-edd')
                    ));
                }
                return ['result' => 'pending'];
            }

            if (in_array($status, ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
                if (!Orders::isPaid($order)) {
                    stackless_edd_complete_order($orderId, $captureId, sprintf(/* translators: %s: capture id */ __('PayPal payment captured (%s).', 'stackless-lite-edd'), $captureId));
                }
                return ['result' => 'success'];
            }

            return ['result' => 'pending'];
        }

        if ($authorization) {
            $authId = (string) ($authorization['id'] ?? '');
            $status = (string) ($authorization['status'] ?? '');
            Orders::setMeta($orderId, Gateway::META_AUTH_ID, $authId);

            if (in_array($status, ['DENIED', 'VOIDED', 'EXPIRED'], true)) {
                Orders::setMeta($orderId, Gateway::META_VOIDED, 'yes');
                if (!Orders::isPaid($order)) {
                    Orders::setStatus($orderId, 'failed', sprintf(/* translators: 1: authorization id, 2: status */ __('PayPal authorization %1$s is %2$s.', 'stackless-lite-edd'), $authId, strtolower($status)));
                }
                return ['result' => 'failed', 'message' => __('PayPal declined the payment. Please try another way to pay.', 'stackless-lite-edd')];
            }
            if (!Amount::matches((array) ($authorization['amount'] ?? []), $expected, $currency)) {
                Orders::setStatus($orderId, 'on_hold', __('The PayPal authorization amount does not match the order total. Check it in PayPal before capturing.', 'stackless-lite-edd'));
                return ['result' => 'success'];
            }
            $this->maybeSaveVault($order, $paypal);
            $fresh = Orders::get($orderId);
            if ($fresh && (string) $fresh->status !== 'on_hold' && !Orders::isPaid($fresh)) {
                Orders::setStatus($orderId, 'on_hold', sprintf(
                    /* translators: %s: authorization id */
                    __('PayPal payment authorized (%s). Change the order status to Completed to capture it.', 'stackless-lite-edd'),
                    $authId
                ));
            }
            return ['result' => 'success'];
        }

        return ['result' => 'failed', 'message' => __('PayPal did not report a payment for this order.', 'stackless-lite-edd')];
    }

    /* ---- Vault --------------------------------------------------------------- */

    /** Save the vault id PayPal returned with a purchase (vault-with-purchase). */
    private function maybeSaveVault(Order $order, array $paypal): void
    {
        $orderId = (int) $order->id;
        $source = (array) ($paypal['payment_source']['paypal'] ?? []);
        $vault = (array) ($source['attributes']['vault'] ?? []);
        $vaultId = (string) ($vault['id'] ?? '');

        if ($vaultId === '' || ($vault['status'] ?? 'VAULTED') !== 'VAULTED') {
            if (stackless_edd_order_requires_saved_payment_method($orderId) && Orders::meta($orderId, SubscriptionData::META_TOKEN_ID) === '') {
                Orders::note($orderId, __('PayPal is still saving the buyer’s account for renewals; it will be attached when PayPal confirms it.', 'stackless-lite-edd'));
            }
            return;
        }

        $customerId = (string) ($vault['customer']['id'] ?? '');
        $tokenId = $this->saveToken($order, $vaultId, (string) ($source['email_address'] ?? ''), (string) ($source['account_id'] ?? ''), $customerId);
        $this->attachToken($order, $tokenId, $vaultId, $customerId);
    }

    /** Store (or reuse) a saved PayPal account for the order's customer. */
    public function saveToken(Order $order, string $vaultId, string $email, string $payerId, string $paypalCustomerId, string $environment = ''): int
    {
        $environment = $environment !== '' ? $environment : $this->gateway->environment();
        return Tokens::save((int) $order->customer_id, (int) $order->user_id, $vaultId, $email, $payerId, $paypalCustomerId, $environment);
    }

    /** Write the token on the parent order (and any subscriptions already created from it). */
    public function attachToken(Order $order, int $tokenId, string $vaultId, string $customerId): void
    {
        $orderId = (int) $order->id;
        $environment = $this->gateway->environment();
        Orders::setMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $tokenId);
        Orders::setMeta($orderId, Gateway::META_VAULT_ID, $vaultId);
        Orders::setMeta($orderId, Gateway::META_ENV, $environment);
        if ($customerId !== '') {
            Orders::setMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $customerId);
        }

        foreach (stackless_edd_get_order_subscriptions($orderId) as $subscription) {
            if (!$subscription instanceof SubscriptionData || !in_array($subscription->get_gateway(), ['', Gateway::ID], true)) {
                continue;
            }
            $subscription->update_meta(SubscriptionData::META_TOKEN_ID, (string) $tokenId);
            $subscription->update_meta(Gateway::META_VAULT_ID, $vaultId);
            $subscription->update_meta(Gateway::META_ENV, $environment);
            if ($customerId !== '') {
                $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $customerId);
            }
        }
        Orders::note($orderId, __('PayPal account saved for automatic renewals.', 'stackless-lite-edd'));
    }

    /**
     * Turn an approved setup token into a vaulted payment token for an order.
     *
     * @return true|\WP_Error
     */
    public function completeSetupToken(Order $order, string $setupToken)
    {
        $done = Lock::run('order-' . $order->id, function () use ($order, $setupToken) {
            $order = Orders::get((int) $order->id);
            if (!$order) {
                return new \WP_Error('stackless_edd_paypal_order', __('Order not found.', 'stackless-lite-edd'));
            }
            $orderId = (int) $order->id;
            if (Orders::isPaid($order) && Orders::meta($orderId, SubscriptionData::META_TOKEN_ID) !== '') {
                return true;
            }
            $saved = $this->vaultSetupToken($setupToken, $order);
            if (is_wp_error($saved)) {
                Orders::note($orderId, sprintf(/* translators: %s: error */ __('Saving the PayPal account failed: %s', 'stackless-lite-edd'), $saved->get_error_message()));
                return $saved;
            }
            Orders::setMeta($orderId, Gateway::META_SETUP_TOKEN, $setupToken);
            $this->attachToken($order, $saved['token_id'], $saved['vault_id'], $saved['customer_id']);
            stackless_edd_complete_order($orderId);
            return true;
        }, null, 20);

        return $done === null ? new \WP_Error('stackless_edd_paypal_busy', __('This order is already being processed. Refresh the page in a moment.', 'stackless-lite-edd')) : $done;
    }

    /**
     * Vault an approved setup token for an order's customer, or (no order) for an EDD
     * customer and WordPress user.
     *
     * @return array{token_id:int,vault_id:string,customer_id:string}|\WP_Error customer_id is PayPal's
     */
    private function vaultSetupToken(string $setupToken, ?Order $order, int $eddCustomerId = 0, int $userId = 0)
    {
        $api = $this->gateway->api();
        $setup = $api->getSetupToken($setupToken);
        if (is_wp_error($setup)) {
            return $setup;
        }
        $status = (string) ($setup['status'] ?? '');
        if (!in_array($status, ['APPROVED', 'VAULTED', 'TOKENIZED'], true)) {
            return new \WP_Error('stackless_edd_paypal_setup_status', __('PayPal has not approved saving this account yet. Please finish in the PayPal window.', 'stackless-lite-edd'));
        }

        $payment = $api->createPaymentToken($setupToken, 'stackless-edd-vault-' . $setupToken);
        if (is_wp_error($payment)) {
            return $payment;
        }
        $vaultId = (string) ($payment['id'] ?? '');
        if ($vaultId === '') {
            return new \WP_Error('stackless_edd_paypal_vault', __('PayPal did not return a saved payment token.', 'stackless-lite-edd'));
        }
        $source = (array) ($payment['payment_source']['paypal'] ?? []);
        $email = (string) ($source['email_address'] ?? '');
        $payerId = (string) ($source['account_id'] ?? '');
        $paypalCustomerId = (string) ($payment['customer']['id'] ?? '');
        $tokenId = $order
            ? $this->saveToken($order, $vaultId, $email, $payerId, $paypalCustomerId)
            : Tokens::save($eddCustomerId, $userId, $vaultId, $email, $payerId, $paypalCustomerId, $this->gateway->environment());
        return ['token_id' => $tokenId, 'vault_id' => $vaultId, 'customer_id' => $paypalCustomerId];
    }

    /**
     * Account page → PayPal: save an account without a purchase, for subscriptions that
     * switch to it (stackless_edd_payment_method_saved tells Subscriptions).
     *
     * @return int|\WP_Error the saved token id
     */
    public function addPaymentMethod(int $userId, string $setupToken)
    {
        if ($userId <= 0) {
            return new \WP_Error('stackless_edd_paypal_login', __('Log in to save a PayPal account.', 'stackless-lite-edd'));
        }
        if ($setupToken === '' || !$this->isRemembered($setupToken)) {
            return new \WP_Error('stackless_edd_paypal_setup', __('Use the PayPal button to connect your account first.', 'stackless-lite-edd'));
        }
        $customer = function_exists('edd_get_customer_by') ? edd_get_customer_by('user_id', (string) $userId) : false;
        $saved = $this->vaultSetupToken($setupToken, null, $customer ? (int) $customer->id : 0, $userId);
        if (is_wp_error($saved)) {
            return $saved;
        }
        do_action('stackless_edd_payment_method_saved', Gateway::ID, (string) $saved['token_id'], $userId);
        do_action('stackless_edd_paypal_gateway_payment_method_added', $saved['token_id'], $userId);
        return $saved['token_id'];
    }

    /* ---- Browser return from PayPal (redirect flow) ---------------------------- */

    public function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the signed order key.
        $type = isset($_GET[self::RETURN_ARG]) && $_GET[self::RETURN_ARG] === 'setup' ? 'setup' : 'order';
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $returned = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $approval = isset($_GET['approval_token_id']) ? sanitize_text_field(wp_unslash($_GET['approval_token_id'])) : '';
        // phpcs:enable

        $order = Orders::get($orderId);
        if (!$order || $key === '' || !hash_equals(Orders::returnKey($order, 'paypal-return'), $key) || (string) $order->gateway !== Gateway::ID) {
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }

        if ($type === 'setup') {
            $stored = Orders::meta($orderId, Gateway::META_SETUP_TOKEN);
            if ($stored === '' || ($approval !== '' && $approval !== $stored)) {
                $result = new \WP_Error('stackless_edd_paypal_return', __('This PayPal approval does not match the order.', 'stackless-lite-edd'));
            } else {
                $result = $this->completeSetupToken($order, $stored);
            }
            $ok = !is_wp_error($result);
            $message = is_wp_error($result) ? $result->get_error_message() : '';
        } else {
            $stored = Orders::meta($orderId, Gateway::META_ORDER_ID);
            if ($stored === '' || ($returned !== '' && $returned !== $stored)) {
                $ok = false;
                $message = __('This PayPal approval does not match the order.', 'stackless-lite-edd');
            } else {
                $result = $this->completeCheckout($order, $stored);
                $ok = $result['result'] !== 'failed';
                $message = (string) ($result['message'] ?? '');
            }
        }

        if ($ok) {
            edd_empty_cart();
            wp_safe_redirect(edd_get_success_page_uri());
            exit;
        }

        // EDD documents the error id as int; every caller (EDD included) passes a string code.
        edd_set_error('stackless_edd_paypal', $message !== '' ? $message : __('PayPal could not take this payment.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
        wp_safe_redirect(Orders::payUrl($order));
        exit;
    }

    /* ---- Renewals ------------------------------------------------------------- */

    /**
     * Merchant-initiated renewal against the vaulted PayPal account.
     *
     * @return array{result:string,message?:string}
     */
    public function chargeRenewal(Order $renewal, SubscriptionData $subscription): array
    {
        $renewalId = (int) $renewal->id;
        if (Orders::isPaid($renewal)) {
            return ['result' => 'success'];
        }
        if ((float) $renewal->total <= 0) {
            stackless_edd_complete_order($renewalId);
            return ['result' => 'success'];
        }
        if (!Amount::supported((string) $renewal->currency)) {
            /* translators: %s: currency */
            return $this->failRenewal($renewalId, sprintf(__('PayPal cannot charge in %s.', 'stackless-lite-edd'), (string) $renewal->currency), false);
        }

        $source = $this->renewalSource($renewal, $subscription);
        if (is_wp_error($source)) {
            return $this->failRenewal($renewalId, $source->get_error_message(), false);
        }
        [$vaultId, $environment] = $source;
        if ($environment !== $this->gateway->environment()) {
            return $this->failRenewal($renewalId, __('The saved PayPal account belongs to the other PayPal environment (sandbox vs live). Ask the customer to update their payment method.', 'stackless-lite-edd'), false);
        }

        $done = Lock::run('order-' . $renewalId, function () use ($renewalId, $vaultId) {
            $renewal = Orders::get($renewalId);
            if (!$renewal) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite-edd')];
            }
            if (Orders::isPaid($renewal)) {
                return ['result' => 'success'];
            }

            $attempts = (int) Orders::meta($renewalId, Gateway::META_RENEWAL_ATTEMPTS);
            $requestId = self::renewalRequestId($renewalId, $attempts);
            Orders::setGateway($renewal, Gateway::ID);
            Orders::setMeta($renewalId, Gateway::META_VAULT_ID, $vaultId);

            $api = $this->gateway->api();
            $body = $this->builder->renewal($renewal, $vaultId);
            $response = $api->createOrder($body, $requestId);
            if (is_wp_error($response) && self::isTransient($response)) {
                // Same PayPal-Request-Id: if the first call went through, PayPal replays its result.
                $response = $api->createOrder($body, $requestId);
            }
            if (is_wp_error($response)) {
                $this->gateway->log('Renewal ' . $renewalId . ' create failed: ' . $response->get_error_message() . ' ' . wp_json_encode($response->get_error_data()), 'error');
                return $this->failRenewal($renewalId, $response->get_error_message(), !self::isTransient($response));
            }

            $status = (string) ($response['status'] ?? '');
            if (!empty($response['id'])) {
                Orders::setMeta($renewalId, Gateway::META_ORDER_ID, (string) $response['id']);
            }
            if (in_array($status, ['CREATED', 'APPROVED', 'SAVED'], true) && !empty($response['id'])) {
                $response = $api->captureOrder((string) $response['id'], $requestId . '-capture');
                if (is_wp_error($response)) {
                    return $this->failRenewal($renewalId, $response->get_error_message(), !self::isTransient($response));
                }
            } elseif ($status === 'PAYER_ACTION_REQUIRED') {
                return $this->failRenewal($renewalId, __('PayPal needs the customer to approve this payment, so it cannot be charged automatically.', 'stackless-lite-edd'), true);
            }

            $result = $this->applyResult($renewal, $response, 'renewal');
            if ($result['result'] === 'failed') {
                Orders::setMeta($renewalId, Gateway::META_RENEWAL_ATTEMPTS, (string) ($attempts + 1));
            }
            return $result;
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'pending', 'message' => __('Another process is already charging this renewal.', 'stackless-lite-edd')];
    }

    /** "stackless-edd-renewal-{id}" for the first attempt, "-2", "-3"… after a definitive decline. */
    public static function renewalRequestId(int $orderId, int $attempts): string
    {
        return 'stackless-edd-renewal-' . $orderId . ($attempts > 0 ? '-' . ($attempts + 1) : '');
    }

    /**
     * Vault id and environment for a renewal: the renewal's token meta, else the subscription's.
     *
     * @return array{0:string,1:string}|\WP_Error
     */
    private function renewalSource(Order $renewal, SubscriptionData $subscription)
    {
        $renewalId = (int) $renewal->id;
        $sources = [
            [
                'token' => absint(Orders::meta($renewalId, SubscriptionData::META_TOKEN_ID)),
                'vault' => Orders::meta($renewalId, Gateway::META_VAULT_ID),
                'env' => Orders::meta($renewalId, Gateway::META_ENV),
            ],
            [
                'token' => absint(self::scalar($subscription->get_meta(SubscriptionData::META_TOKEN_ID))),
                'vault' => self::scalar($subscription->get_meta(Gateway::META_VAULT_ID)),
                'env' => self::scalar($subscription->get_meta(Gateway::META_ENV)),
            ],
        ];
        $customerId = (int) $renewal->customer_id;
        foreach ($sources as $source) {
            if ($source['token'] > 0) {
                $token = Tokens::get($source['token']);
                if ($token) {
                    $owner = (int) ($token['customer_id'] ?? 0);
                    if ($owner !== 0 && $customerId !== 0 && $owner !== $customerId) {
                        return new \WP_Error('stackless_edd_paypal_token_owner', __('The saved PayPal account belongs to a different customer.', 'stackless-lite-edd'));
                    }
                    return [(string) $token['vault_id'], (string) ($token['environment'] ?: ($source['env'] ?: $this->gateway->environment()))];
                }
                // The customer removed this saved account, so it must not be charged any more.
                return new \WP_Error('stackless_edd_paypal_token_removed', __('The saved PayPal account for this subscription was removed. Ask the customer to choose another payment method.', 'stackless-lite-edd'));
            }
            if ($source['vault'] !== '') {
                return [$source['vault'], $source['env'] !== '' ? $source['env'] : $this->gateway->environment()];
            }
        }
        return new \WP_Error('stackless_edd_paypal_no_token', __('No saved PayPal account for this subscription.', 'stackless-lite-edd'));
    }

    /** @return array{result:string,message:string} */
    private function failRenewal(int $renewalId, string $message, bool $countAttempt): array
    {
        if ($countAttempt) {
            Orders::setMeta($renewalId, Gateway::META_RENEWAL_ATTEMPTS, (string) ((int) Orders::meta($renewalId, Gateway::META_RENEWAL_ATTEMPTS) + 1));
        }
        $renewal = Orders::get($renewalId);
        if ($renewal && !Orders::isPaid($renewal)) {
            /* translators: %s: error message */
            Orders::setStatus($renewalId, 'failed', sprintf(__('PayPal renewal payment failed: %s', 'stackless-lite-edd'), $message));
        }
        return ['result' => 'failed', 'message' => $message];
    }

    /* ---- Refunds and authorizations -------------------------------------------- */

    /**
     * Refund part or all of a captured payment in PayPal.
     *
     * @return array{id:string,status:string}|\WP_Error
     */
    public function refund(Order $order, float $amount, string $reason = '')
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_paypal_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $captureId = Orders::meta($orderId, Gateway::META_CAPTURE_ID);
        if ($captureId === '') {
            if (Orders::meta($orderId, Gateway::META_AUTH_ID) !== '') {
                return new \WP_Error('stackless_edd_paypal_refund', __('This payment was only authorized, not captured. Revoke the order to void the authorization instead.', 'stackless-lite-edd'));
            }
            $captureId = (string) $order->get_transaction_id();
        }
        if ($captureId === '') {
            return new \WP_Error('stackless_edd_paypal_refund', __('This order has no PayPal payment to refund.', 'stackless-lite-edd'));
        }

        $previous = edd_get_orders(['parent' => $orderId, 'type' => 'refund', 'number' => 100, 'fields' => 'ids']);
        $requestId = 'stackless-edd-refund-' . $orderId . '-' . md5(Amount::format($amount, (string) $order->currency) . '|' . count((array) $previous));

        $body = ['amount' => Amount::money($amount, (string) $order->currency)];
        if ($reason !== '') {
            $body['note_to_payer'] = Amount::truncate($reason, 255);
        }
        $environment = Orders::meta($orderId, Gateway::META_ENV);
        $api = $this->gateway->apiFor($environment !== '' ? $environment : $this->gateway->environment());
        $response = $api->refundCapture($captureId, $body, $requestId);
        if (is_wp_error($response)) {
            $this->gateway->log('Refund on order ' . $orderId . ' failed: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $status = (string) ($response['status'] ?? '');
        if (!in_array($status, ['COMPLETED', 'PENDING'], true)) {
            /* translators: %s: refund status */
            return new \WP_Error('stackless_edd_paypal_refund', sprintf(__('PayPal did not complete the refund (status: %s).', 'stackless-lite-edd'), $status !== '' ? $status : 'unknown'));
        }
        $refundId = (string) ($response['id'] ?? '');
        $this->recordRefundId($orderId, $refundId);
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: refund id, 3: status */
            __('Refunded %1$s through PayPal (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, (string) $order->currency),
            $refundId,
            strtolower($status)
        ));
        return ['id' => $refundId, 'status' => $status];
    }

    public function recordRefundId(int $orderId, string $refundId): void
    {
        if ($refundId === '') {
            return;
        }
        $ids = array_filter(explode(',', Orders::meta($orderId, Gateway::META_REFUND_IDS)));
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            Orders::setMeta($orderId, Gateway::META_REFUND_IDS, implode(',', $ids));
        }
    }

    public function hasRefundId(int $orderId, string $refundId): bool
    {
        return in_array($refundId, array_filter(explode(',', Orders::meta($orderId, Gateway::META_REFUND_IDS))), true);
    }

    /** Capture an authorized payment when the store owner marks the order Completed. */
    public function captureAuthorization(Order $order): void
    {
        $orderId = (int) $order->id;
        $authId = Orders::meta($orderId, Gateway::META_AUTH_ID);
        if ($authId === '' || Orders::meta($orderId, Gateway::META_CAPTURE_ID) !== '' || (string) $order->gateway !== Gateway::ID) {
            return;
        }
        $environment = Orders::meta($orderId, Gateway::META_ENV);
        $api = $this->gateway->apiFor($environment !== '' ? $environment : $this->gateway->environment());
        $response = $api->captureAuthorization($authId, [
            'amount' => Amount::money((float) $order->total, (string) $order->currency),
            'final_capture' => true,
            'invoice_id' => Amount::truncate($this->gateway->invoicePrefix() . $order->get_number(), 127),
        ], 'stackless-edd-auth-capture-' . $authId);

        if (is_wp_error($response)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Capturing the PayPal authorization failed: %s', 'stackless-lite-edd'), $response->get_error_message()));
            $this->gateway->log('Authorization capture on ' . $orderId . ' failed: ' . $response->get_error_message(), 'error');
            return;
        }

        $captureId = (string) ($response['id'] ?? '');
        Orders::setMeta($orderId, Gateway::META_CAPTURE_ID, $captureId);
        if ($captureId !== '' && function_exists('edd_add_order_transaction') && !edd_get_order_transaction_by('transaction_id', $captureId)) {
            edd_add_order_transaction([
                'object_id' => $orderId,
                'object_type' => 'order',
                'transaction_id' => $captureId,
                'gateway' => Gateway::ID,
                'status' => 'complete',
                'total' => (float) $order->total,
            ]);
        }
        Orders::note($orderId, sprintf(
            /* translators: 1: capture id, 2: status */
            __('PayPal authorization captured (%1$s, %2$s).', 'stackless-lite-edd'),
            $captureId,
            strtolower((string) ($response['status'] ?? ''))
        ));
    }

    public function voidAuthorization(Order $order): void
    {
        $orderId = (int) $order->id;
        $authId = Orders::meta($orderId, Gateway::META_AUTH_ID);
        if ($authId === '' || Orders::meta($orderId, Gateway::META_CAPTURE_ID) !== '' || (string) $order->gateway !== Gateway::ID || Orders::meta($orderId, Gateway::META_VOIDED) !== '') {
            return;
        }
        $environment = Orders::meta($orderId, Gateway::META_ENV);
        $api = $this->gateway->apiFor($environment !== '' ? $environment : $this->gateway->environment());
        $response = $api->voidAuthorization($authId);
        if (is_wp_error($response)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Voiding the PayPal authorization failed: %s', 'stackless-lite-edd'), $response->get_error_message()));
            return;
        }
        Orders::setMeta($orderId, Gateway::META_VOIDED, 'yes');
        Orders::note($orderId, __('PayPal authorization voided.', 'stackless-lite-edd'));
    }

    /* ---- Helpers ------------------------------------------------------------------ */

    /** First HATEOAS link with one of these rels. */
    public static function link(array $response, array $rels): string
    {
        foreach ($rels as $rel) {
            foreach ((array) ($response['links'] ?? []) as $link) {
                if (is_array($link) && ($link['rel'] ?? '') === $rel && !empty($link['href'])) {
                    return (string) $link['href'];
                }
            }
        }
        return '';
    }

    /** Network failure or a PayPal 5xx: the outcome is unknown, so don't count it as a decline. */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = (int) (((array) $error->get_error_data())['status'] ?? 0);
        return $status === 0 || $status >= 500 || $status === 429;
    }

    /** @param mixed $value */
    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
