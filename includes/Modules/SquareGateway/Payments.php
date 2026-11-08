<?php

namespace StacklessEDD\Modules\SquareGateway;

use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Square payments: a new card or wallet token at checkout (saved on file when asked), a saved
 * card at checkout, renewals and saved-card charges without the buyer (merchant-initiated), and
 * webhook updates. Square answers card payments at once, so outcomes are success or failed,
 * except a transient error on a renewal or saved-card charge, which is retried with the same
 * idempotency key.
 */
final class Payments
{
    public const RETRY_HOOK = 'stackless_edd_square_gateway_retry_charge';
    public const GROUP = 'stackless-edd-square_gateway';
    public const META_ATTEMPT = '_stackless_edd_square_attempt';
    public const META_TRANSIENT = '_stackless_edd_square_transient_retries';
    /** The idempotency key of a charge waiting for a transient-error retry. */
    public const META_RETRY_KEY = '_stackless_edd_square_retry_key';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function rememberRefund(int $order_id, string $refund_id): void
    {
        $ids = Orders::meta($order_id, Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if ($refund_id !== '' && !in_array($refund_id, $ids, true)) {
            $ids[] = $refund_id;
            Orders::updateMeta($order_id, Gateway::META_REFUND_IDS, array_values($ids));
        }
    }

    /** @return string[] */
    public static function knownRefunds(int $order_id): array
    {
        $ids = Orders::meta($order_id, Gateway::META_REFUND_IDS);
        return is_array($ids) ? array_map('strval', $ids) : [];
    }

    /**
     * Pay an order with a token from the card form or a wallet; save the card on file when asked.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeNew(\EDD\Orders\Order $order, string $source, bool $save): array
    {
        $mode = $this->gateway->mode();
        $order_id = (int) $order->id;
        $currency = strtoupper((string) $order->currency);
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return ['result' => 'failed', 'message' => sprintf(__('Square takes payments in %s only.', 'stackless-lite-edd'), $this->gateway->currency($mode))];
        }
        $user_id = (int) $order->user_id;
        $customer = '';
        if ($save) {
            $customer = $this->gateway->customerFor($user_id, $mode, $order);
            if (is_wp_error($customer)) {
                return ['result' => 'failed', 'message' => $customer->get_error_message()];
            }
        }
        Orders::updateMeta($order_id, Gateway::META_MODE, $mode);

        if ((float) $order->total <= 0) {
            if (!$save) {
                stackless_edd_complete_order($order_id);
                return ['result' => 'success'];
            }
            $token = $this->createCard($source, $customer, $user_id, $mode, $order);
            if (is_wp_error($token)) {
                Orders::note($order_id, sprintf(
                    /* translators: %s: error */
                    __('Square could not save the card: %s', 'stackless-lite-edd'),
                    $token->get_error_message()
                ));
                return ['result' => 'failed', 'message' => Declines::message(Api::code($token))];
            }
            // Before completing: Subscriptions copies the token from the parent order then.
            Gateway::attachToken($order_id, $token);
            /* translators: %s: card, e.g. "Visa ending in 1111" */
            Orders::note($order_id, sprintf(__('Saved %s with Square for future payments.', 'stackless-lite-edd'), $token->displayName()));
            stackless_edd_complete_order($order_id);
            return ['result' => 'success'];
        }

        $payment = $this->createPayment($order, $mode, [
            'source_id' => $source,
            'customer_id' => $customer !== '' ? $customer : null,
        ], Api::key('o' . $order_id, substr(hash('sha256', $source), 0, 16)));
        if (is_wp_error($payment)) {
            return $this->checkoutError($order, $payment);
        }
        // Save the card before the order is marked paid: Subscriptions copies the token from the
        // parent order when payment completes (Contracts\SubscriptionGateway).
        if ($save && in_array($payment['status'] ?? '', ['COMPLETED', 'APPROVED'], true)) {
            $token = $this->createCard((string) $payment['id'], $customer, $user_id, $mode, $order);
            if ($token instanceof PaymentToken) {
                Gateway::attachToken($order_id, $token);
                /* translators: %s: card, e.g. "Visa ending in 1111" */
                Orders::note($order_id, sprintf(__('Saved %s with Square for future payments.', 'stackless-lite-edd'), $token->displayName()));
            } else {
                /* translators: %s: error */
                Orders::note($order_id, sprintf(__('The payment went through, but Square could not save the card for renewals: %s Renewals will be invoiced.', 'stackless-lite-edd'), $token->get_error_message()));
            }
        }
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'busy' && ($payment['status'] ?? '') === 'COMPLETED') {
            $outcome = 'paid'; // The webhook is recording this same payment right now.
        }
        if ($outcome !== 'paid' && $outcome !== 'pending') {
            return ['result' => 'failed', 'message' => Declines::message((string) Declines::fromPayment($payment)['code'])];
        }
        return ['result' => $outcome === 'paid' ? 'success' : 'pending'];
    }

    /**
     * Pay with a saved card: at checkout (buyer present), for a renewal, or for a saved-card
     * charge (Contracts\SavedMethodCharger).
     *
     * @param string $flow checkout | renewal | saved
     * @return array{result:string, message?:string}
     */
    public function chargeCard(\EDD\Orders\Order $order, PaymentToken $token, string $flow, bool $onSession = true): array
    {
        $order_id = (int) $order->id;
        if (Orders::isPaid($order)) {
            return ['result' => 'success'];
        }
        $mode = $token->mode;
        $currency = strtoupper((string) $order->currency);
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'CURRENCY_MISMATCH'], sprintf(__('Square takes payments in %s only.', 'stackless-lite-edd'), $this->gateway->currency($mode)), $flow);
        }
        Gateway::attachToken($order_id, $token);
        Orders::updateMeta($order_id, Gateway::META_MODE, $mode);
        if ((float) $order->total <= 0) {
            stackless_edd_complete_order($order_id);
            return ['result' => 'success'];
        }

        // A charge that already went through for this order is reported, not repeated.
        $previous = (string) Orders::meta($order_id, Gateway::META_PAYMENT_ID);
        if ($previous !== '') {
            $existing = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && is_array($existing['payment'] ?? null) && in_array($existing['payment']['status'] ?? '', ['COMPLETED', 'APPROVED', 'PENDING'], true)) {
                return $this->applyPayment($order, $existing['payment'], $mode) === 'paid' ? ['result' => 'success'] : ['result' => 'pending'];
            }
        }

        $key = $this->idempotencyKey($order, $token, $flow, $onSession);
        $payment = $this->createPayment($order, $mode, [
            'source_id' => $token->card_id,
            'customer_id' => $token->square_customer,
            // Without the buyer present, Square treats the charge as merchant-initiated (no 3-D Secure).
            'customer_details' => ['customer_initiated' => $onSession, 'seller_keyed_in' => false],
        ], $key);
        if (is_wp_error($payment)) {
            if ($flow === 'checkout') {
                return $this->checkoutError($order, $payment);
            }
            return $this->offSessionError($order, $payment, $flow, $key);
        }
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'paid') {
            Orders::deleteMeta($order_id, self::META_TRANSIENT);
            Orders::deleteMeta($order_id, self::META_RETRY_KEY);
            return ['result' => 'success'];
        }
        if ($outcome === 'pending') {
            return ['result' => 'pending'];
        }
        $decline = Declines::fromPayment($payment);
        return $flow === 'checkout'
            ? ['result' => 'failed', 'message' => Declines::message($decline['code'])]
            : $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * One key per charge attempt, reused while Square cannot be reached. Saved-card charges use the
     * SavedMethodCharger key; each try after a decline gets its own key.
     */
    public function idempotencyKey(\EDD\Orders\Order $order, PaymentToken $token, string $flow, bool $onSession): string
    {
        $order_id = (int) $order->id;
        $pending = (string) Orders::meta($order_id, self::META_RETRY_KEY);
        if ($pending !== '') {
            return $pending;
        }
        $attempt = (int) Orders::meta($order_id, self::META_ATTEMPT);
        if ($flow === 'saved') {
            $key = 'stackless-edd-saved-' . $order_id . '-' . $token->id . '-' . ($onSession ? 'on' : 'off');
            return Api::fit($attempt > 0 ? $key . '-' . $attempt : $key);
        }
        return Api::key(substr($flow, 0, 1) . $order_id, (string) $attempt);
    }

    /**
     * Renewal (Contracts\SubscriptionGateway).
     *
     * @return array{result:string, message?:string}
     */
    public function renew(\EDD\Orders\Order $renewal, ?SubscriptionData $subscription): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        $renewal = Orders::get((int) $renewal->id) ?? $renewal;
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'NO_CARD_ON_FILE'], __('No saved Square card is attached to this subscription.', 'stackless-lite-edd'), 'renewal');
        }
        if (!Orders::needsPayment($renewal)) {
            if (Orders::isPaid($renewal)) {
                return ['result' => 'success'];
            }
            return Orders::hasStatus($renewal, ['on_hold', 'processing'])
                ? ['result' => 'pending']
                : ['result' => 'failed', 'message' => __('The renewal order cannot be paid in its current status.', 'stackless-lite-edd')];
        }
        return $this->chargeCard($renewal, $token, 'renewal', false);
    }

    /**
     * Charge a saved card for a new order (Contracts\SavedMethodCharger). Every refusal comes
     * before any call to Square.
     *
     * @return array{result:string, message?:string, redirect?:string}
     */
    public function chargeSaved(\EDD\Orders\Order $order, string $token_id, bool $onSession): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        if ((string) $order->gateway !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not waiting for a Square payment.', 'stackless-lite-edd')];
        }
        $token = ctype_digit($token_id) ? Tokens::get((int) $token_id) : null;
        if (!$token || (int) $order->user_id <= 0 || $token->user_id !== (int) $order->user_id) {
            return ['result' => 'failed', 'message' => __('That saved card does not belong to this customer.', 'stackless-lite-edd')];
        }
        if ($token->mode !== $this->gateway->mode()) {
            return ['result' => 'failed', 'message' => __('That saved card was saved in the other Square mode.', 'stackless-lite-edd')];
        }
        $order = Orders::get((int) $order->id) ?? $order;
        if (!Orders::needsPayment($order)) {
            return ['result' => 'failed', 'message' => __('The order is not waiting for a Square payment.', 'stackless-lite-edd')];
        }
        $result = $this->chargeCard($order, $token, 'saved', $onSession);
        if ($result['result'] === 'failed' && $onSession && (string) Orders::meta((int) $order->id, SubscriptionData::META_DECLINE) === SubscriptionData::DECLINE_AUTH) {
            // The bank wants the buyer to verify: let them enter the card at checkout for this order.
            return ['result' => 'action_required', 'message' => $result['message'] ?? '', 'redirect' => stackless_edd_order_pay_url((int) $order->id)];
        }
        return $result;
    }

    /**
     * Account page → Add payment method: store a card from a STORE-intent token.
     *
     * @return PaymentToken|\WP_Error
     */
    public function storeCard(string $source, int $user_id, string $mode)
    {
        $customer = $this->gateway->customerFor($user_id, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        return $this->createCard($source, $customer, $user_id, $mode);
    }

    /**
     * POST /v2/cards from a token (STORE intent) or a payment id (charged within 24 hours).
     *
     * @return PaymentToken|\WP_Error
     */
    private function createCard(string $source, string $customer, int $user_id, string $mode, ?\EDD\Orders\Order $order = null)
    {
        $name = $order ? trim(implode(' ', Orders::names($order))) : '';
        $card = array_filter([
            'customer_id' => $customer,
            'cardholder_name' => $name,
            'reference_id' => $order ? 'edd_order_' . (int) $order->id : 'wp_user_' . $user_id,
            'billing_address' => $order ? (Orders::squareAddress($order) ?: null) : null,
        ]);
        $response = $this->gateway->api($mode)->request('POST', 'cards', [
            'idempotency_key' => Api::key('card', substr(hash('sha256', $source . '|' . $customer), 0, 20)),
            'source_id' => $source,
            'card' => $card,
        ]);
        if (is_wp_error($response)) {
            $this->gateway->log('Saving a card failed: ' . Api::code($response) . ' ' . $response->get_error_message(), 'warning');
            return $response;
        }
        $token = Tokens::saveFromCard(is_array($response['card'] ?? null) ? $response['card'] : [], $user_id, $mode, $order ? (int) $order->customer_id : 0);
        return $token ?: new \WP_Error('stackless_edd_square_card', __('Square saved the card, but it could not be stored here.', 'stackless-lite-edd'));
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>|\WP_Error The payment object.
     */
    private function createPayment(\EDD\Orders\Order $order, string $mode, array $source, string $idempotencyKey)
    {
        $order_id = (int) $order->id;
        $body = array_filter(array_merge($source, [
            'idempotency_key' => $idempotencyKey,
            'amount_money' => Money::money((string) $order->total, (string) $order->currency),
            'location_id' => $this->gateway->locationId($mode),
            'autocomplete' => true,
            'reference_id' => substr((string) $order_id, 0, 40),
            /* translators: 1: order number, 2: site name */
            'note' => substr(sprintf(__('Order %1$s, %2$s', 'stackless-lite-edd'), Orders::number($order), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), 0, 500),
            'buyer_email_address' => is_email((string) $order->email) ? (string) $order->email : null,
            'billing_address' => Orders::squareAddress($order) ?: null,
        ]), static function ($value) {
            return $value !== null && $value !== '';
        });
        $response = $this->gateway->api($mode)->request('POST', 'payments', $body);
        if (is_wp_error($response)) {
            return $response;
        }
        $payment = is_array($response['payment'] ?? null) ? $response['payment'] : [];
        if (empty($payment['id'])) {
            return new \WP_Error('stackless_edd_square_payment', __('Square did not return a payment.', 'stackless-lite-edd'), ['status' => 502]);
        }
        Orders::updateMeta($order_id, Gateway::META_PAYMENT_ID, (string) $payment['id']);
        return $payment;
    }

    /**
     * Apply a Square payment object to its order (checkout, renewal or webhook).
     *
     * @param array<string,mixed> $payment
     * @return string paid|pending|failed|mismatch|busy|ignored…
     */
    public function applyPayment(\EDD\Orders\Order $order, array $payment, string $mode): string
    {
        $order_id = (int) $order->id;
        return (string) Lock::run('order-' . $order_id, static function () use ($order_id, $payment) {
            $order = Orders::get($order_id);
            if (!$order) {
                return 'none';
            }
            $payment_id = (string) ($payment['id'] ?? '');
            $current = (string) Orders::meta($order_id, Gateway::META_PAYMENT_ID);
            if ($current !== '' && $current !== $payment_id) {
                return 'ignored: another payment';
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'COMPLETED' || $status === 'APPROVED') {
                if (Orders::isPaid($order)) {
                    return 'paid';
                }
                if (!Money::matches($payment['amount_money'] ?? null, (string) $order->total, (string) $order->currency)) {
                    if (!Orders::hasStatus($order, 'on_hold')) {
                        /* translators: %s: Square payment id */
                        Orders::setStatus($order_id, 'on_hold', sprintf(__('Square payment %s does not match the order total. Check it before delivering.', 'stackless-lite-edd'), $payment_id));
                    }
                    return 'mismatch';
                }
                stackless_edd_complete_order($order_id, $payment_id);
                return 'paid';
            }
            if ($status === 'PENDING') {
                if (Orders::needsPayment($order) && !stackless_edd_is_renewal_order($order_id)) {
                    Orders::addTransaction($order, $payment_id);
                    /* translators: %s: Square payment id */
                    Orders::setStatus($order_id, 'processing', sprintf(__('Square is processing payment %s.', 'stackless-lite-edd'), $payment_id));
                }
                return 'pending';
            }
            if ($status === 'FAILED' || $status === 'CANCELED') {
                if (Orders::needsPayment($order) && stackless_edd_is_renewal_order($order_id)) {
                    $decline = Declines::fromPayment($payment);
                    Declines::record($order_id, $decline);
                    if (!Orders::hasStatus($order, 'failed')) {
                        /* translators: 1: Square payment id, 2: error code */
                        Orders::setStatus($order_id, 'failed', sprintf(__('Square payment %1$s failed (%2$s).', 'stackless-lite-edd'), $payment_id, $decline['code']));
                    }
                }
                return 'failed';
            }
            return 'pending';
        }, 'busy');
    }

    /** @return array{result:string, message:string} */
    private function checkoutError(\EDD\Orders\Order $order, \WP_Error $error): array
    {
        $code = Api::code($error);
        /* translators: 1: message, 2: error code */
        Orders::note((int) $order->id, sprintf(__('Square payment attempt failed: %1$s (%2$s)', 'stackless-lite-edd'), $error->get_error_message(), $code !== '' ? $code : 'HTTP ' . Api::status($error)));
        $this->gateway->log('Checkout payment for order ' . (int) $order->id . ' failed: ' . $code, Api::isTransient($error) ? 'error' : 'info');
        return ['result' => 'failed', 'message' => $code !== '' ? Declines::message($code) : $error->get_error_message()];
    }

    /** @return array{result:string, message?:string} */
    private function offSessionError(\EDD\Orders\Order $order, \WP_Error $error, string $flow, string $key): array
    {
        $order_id = (int) $order->id;
        if (Api::isTransient($error)) {
            $retries = (int) Orders::meta($order_id, self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                Orders::updateMeta($order_id, self::META_TRANSIENT, (string) ($retries + 1));
                Orders::updateMeta($order_id, self::META_RETRY_KEY, $key);
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP);
                /* translators: %s: error */
                Orders::note($order_id, sprintf(__('Square could not be reached for this charge (%s). Retrying automatically with the same idempotency key.', 'stackless-lite-edd'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        $decline = Declines::fromError($error);
        return $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(\EDD\Orders\Order $order, array $decline, string $message, string $flow): array
    {
        if ($flow === 'checkout') {
            return ['result' => 'failed', 'message' => $message];
        }
        $order_id = (int) $order->id;
        Orders::updateMeta($order_id, self::META_ATTEMPT, (string) ((int) Orders::meta($order_id, self::META_ATTEMPT) + 1));
        Orders::deleteMeta($order_id, self::META_TRANSIENT);
        Orders::deleteMeta($order_id, self::META_RETRY_KEY);
        Orders::deleteMeta($order_id, Gateway::META_PAYMENT_ID);
        // Before the status change: Dunning reads the category when the order turns failed.
        Declines::record($order_id, $decline);
        /* translators: 1: reason, 2: error code */
        $note = sprintf(__('Square charge failed: %1$s (%2$s)', 'stackless-lite-edd'), $message, $decline['code']);
        $current = Orders::get($order_id);
        if ($current && Orders::hasStatus($current, 'pending')) {
            Orders::setStatus($order_id, 'failed', $note);
        } else {
            Orders::note($order_id, $note);
        }
        $this->gateway->log(sprintf('Charge for order %d failed: %s', $order_id, $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /**
     * Action Scheduler callback: retry a charge that could not reach Square.
     *
     * @param mixed $order_id
     */
    public static function retry($order_id): void
    {
        $order_id = (int) $order_id;
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = Orders::get($order_id);
        if (!$order || (string) $order->gateway !== Gateway::ID || !Orders::needsPayment($order)) {
            return;
        }
        $subscriptions = stackless_edd_get_order_subscriptions($order_id);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof SubscriptionData ? $subscription : null);
        if ($token) {
            (new self(Gateway::instance()))->chargeCard($order, $token, stackless_edd_is_renewal_order($order_id) ? 'renewal' : 'saved', false);
        }
    }

    public static function resolveToken(\EDD\Orders\Order $order, ?SubscriptionData $subscription = null): ?PaymentToken
    {
        $ids = [(int) Orders::meta((int) $order->id, SubscriptionData::META_TOKEN_ID)];
        if ($subscription) {
            $ids[] = (int) $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
        }
        foreach ($ids as $token_id) {
            $token = $token_id > 0 ? Tokens::get($token_id) : null;
            if ($token && $token->square_customer !== '' && $token->card_id !== '') {
                return $token;
            }
        }
        return null;
    }

    /* ---- Webhook ----------------------------------------------------------------------- */

    /**
     * payment.created / payment.updated
     *
     * @param array<string,mixed> $payment
     */
    public function webhookPayment(array $payment, string $mode): string
    {
        $order = self::findOrder((string) ($payment['id'] ?? ''));
        if (!$order) {
            return 'ignored: no matching order';
        }
        return $this->applyPayment($order, $payment, $mode);
    }

    /**
     * refund.created / refund.updated: refunds made in Square, and failures of ours.
     *
     * @param array<string,mixed> $refund
     */
    public function webhookRefund(array $refund): string
    {
        $order = self::findOrder((string) ($refund['payment_id'] ?? ''));
        if (!$order) {
            return 'ignored: no matching order';
        }
        $order_id = (int) $order->id;
        return (string) Lock::run('order-' . $order_id, static function () use ($order_id, $refund) {
            $order = Orders::get($order_id);
            if (!$order) {
                return 'none';
            }
            $refund_id = (string) ($refund['id'] ?? '');
            $status = (string) ($refund['status'] ?? '');
            $known = self::knownRefunds($order_id);
            if (in_array($status, ['REJECTED', 'FAILED'], true)) {
                if (in_array($refund_id, $known, true) && !in_array($refund_id . ':failed', $known, true)) {
                    /* translators: %s: Square refund id */
                    Orders::note($order_id, sprintf(__('Square could not complete refund %s. Check it in the Square Dashboard.', 'stackless-lite-edd'), $refund_id));
                    self::rememberRefund($order_id, $refund_id . ':failed');
                }
                return 'refund failed noted';
            }
            if ($refund_id === '' || in_array($refund_id, $known, true)) {
                return 'refund already recorded';
            }
            $currency = (string) $order->currency;
            $remaining = function_exists('edd_get_order_total') ? (float) edd_get_order_total($order_id) : (float) $order->total;
            $amount = min(Money::fromMinor((int) ($refund['amount_money']['amount'] ?? 0), $currency), $remaining);
            self::rememberRefund($order_id, $refund_id);
            if ($amount <= 0) {
                return 'nothing left to refund';
            }
            $created = Refunds::recordExternal($order, $amount);
            if (is_wp_error($created) || !$created) {
                return 'refund not recorded: ' . (is_wp_error($created) ? $created->get_error_message() : 'EDD refused it');
            }
            $refund_order = Orders::get((int) $created);
            if ($refund_order) {
                Orders::addTransaction($refund_order, $refund_id, 'complete');
            }
            /* translators: 1: amount, 2: Square refund id */
            Orders::note($order_id, sprintf(__('Refunded %1$s in Square (%2$s).', 'stackless-lite-edd'), wp_strip_all_tags(stackless_edd_price($amount, $currency)), $refund_id));
            return 'refund recorded';
        }, 'busy');
    }

    /**
     * The order a Square payment belongs to, by the payment id stored when it was created.
     * reference_id alone is not trusted: another store on the same Square account can use the
     * same order numbers.
     */
    public static function findOrder(string $payment_id): ?\EDD\Orders\Order
    {
        return Orders::findByMeta(Gateway::META_PAYMENT_ID, $payment_id);
    }
}
