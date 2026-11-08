<?php

namespace StacklessEDD\Modules\PayPalGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Contracts\SavedMethodCharger for PayPal (2.0.0): charges a vaulted PayPal account or card
 * for a new order outside the renewal schedule (post-purchase offers).
 *
 * The saved method must belong to the order's customer (a signed-in account; guests are never
 * charged), to this gateway and to the current PayPal environment; a copy of the live store
 * never charges. The PayPal-Request-Id is keyed by the order and token, so a double click or a
 * retry replays PayPal's first answer.
 */
final class SavedCharges
{
    /** Order statuses a saved-method charge may pay. */
    private const PAYABLE = ['pending', 'failed', 'abandoned'];

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function charge(Order $order, string $tokenId, bool $onSession): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite-edd')];
        }
        $token = Tokens::get(absint($tokenId));
        $refusal = $this->refusal($order, $token);
        if ($refusal !== '') {
            $this->gateway->log('Saved-method charge for order ' . $order->id . ' refused: ' . $refusal, 'warning');
            return ['result' => 'failed', 'message' => $refusal];
        }
        if (Orders::isPaid($order) || !is_array($token)) {
            return ['result' => 'success'];
        }
        $type = Tokens::type($token);
        $vaultId = (string) $token['vault_id'];
        $rowId = (int) $token['id'];
        $orderId = (int) $order->id;
        $payments = $this->gateway->payments();

        $done = Lock::run('order-' . $orderId, function () use ($orderId, $rowId, $type, $vaultId, $onSession, $payments) {
            $order = Orders::get($orderId);
            if (!$order) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite-edd')];
            }
            if (Orders::isPaid($order)) {
                return ['result' => 'success'];
            }
            Orders::setMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $rowId);
            Orders::setMeta($orderId, Gateway::META_VAULT_ID, $vaultId);
            Orders::setMeta($orderId, Gateway::META_VAULT_TYPE, $type);
            Orders::setMeta($orderId, Gateway::META_ENV, $this->gateway->environment());

            $api = $this->gateway->api();
            $requestId = self::requestId($orderId, $rowId, $onSession);
            $body = $payments->builder()->savedCharge($order, $vaultId, $type, $onSession, $payments->returnUrl($order, 'order'));
            $response = $api->createOrder($body, $requestId);
            if (is_wp_error($response) && Payments::isTransient($response)) {
                $response = $api->createOrder($body, $requestId);
            }
            if (is_wp_error($response)) {
                return $this->failed($order, $response);
            }
            if (!empty($response['id'])) {
                Orders::setMeta($orderId, Gateway::META_ORDER_ID, (string) $response['id']);
            }
            $status = (string) ($response['status'] ?? '');
            if ($status === 'PAYER_ACTION_REQUIRED') {
                $link = Payments::link($response, ['payer-action', 'approve']);
                return [
                    'result' => 'action_required',
                    'message' => __('Confirm the payment with PayPal to finish.', 'stackless-lite-edd'),
                    'redirect' => $onSession && $link !== '' ? $link : Orders::payUrl($order),
                ];
            }
            if (in_array($status, ['CREATED', 'APPROVED', 'SAVED'], true) && !empty($response['id'])) {
                $response = $api->captureOrder((string) $response['id'], $requestId . '-capture');
                if (is_wp_error($response)) {
                    return $this->failed($order, $response);
                }
            }
            $result = $payments->applyResult($order, $response, 'saved_method');
            return $result['result'] === 'failed'
                ? ['result' => 'failed', 'message' => (string) ($result['message'] ?? __('PayPal declined the payment.', 'stackless-lite-edd'))]
                : ['result' => $result['result']];
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'pending', 'message' => __('This order is already being processed.', 'stackless-lite-edd')];
    }

    /**
     * Why the saved method may not pay this order, or ''.
     *
     * @param array<string,mixed>|null $token a Tokens row
     */
    public function refusal(Order $order, ?array $token): string
    {
        if ((string) $order->gateway !== Gateway::ID) {
            return __('The order is not set to pay with PayPal.', 'stackless-lite-edd');
        }
        $customer = (int) $order->customer_id;
        $user = (int) $order->user_id;
        if (!$token || $customer <= 0 || $user <= 0 || (int) ($token['customer_id'] ?? 0) !== $customer
            || ((int) ($token['user_id'] ?? 0) !== 0 && (int) $token['user_id'] !== $user)) {
            return __('That saved payment method does not belong to this customer.', 'stackless-lite-edd');
        }
        if ((string) ($token['vault_id'] ?? '') === '') {
            return __('That saved payment method cannot be charged by PayPal.', 'stackless-lite-edd');
        }
        $environment = (string) ($token['environment'] ?? '');
        if ($environment !== '' && $environment !== $this->gateway->environment()) {
            return __('That saved payment method belongs to the other PayPal environment (sandbox or live).', 'stackless-lite-edd');
        }
        if (!Orders::isPaid($order) && !in_array((string) $order->status, self::PAYABLE, true)) {
            return __('The order cannot be paid in its current status.', 'stackless-lite-edd');
        }
        if ((float) $order->total <= 0 || !Amount::supported((string) $order->currency)) {
            /* translators: %s: currency code */
            return sprintf(__('PayPal cannot take this payment in %s.', 'stackless-lite-edd'), (string) $order->currency);
        }
        return '';
    }

    /** stackless-edd-saved-{order}-{token}-{on|off} */
    public static function requestId(int $orderId, int $tokenId, bool $onSession): string
    {
        return 'stackless-edd-saved-' . $orderId . '-' . $tokenId . ($onSession ? '-on' : '-off');
    }

    /** @return array{result:string, message:string, redirect?:string} */
    private function failed(Order $order, \WP_Error $error): array
    {
        $orderId = (int) $order->id;
        [$category, $code] = Declines::fromError($error);
        Declines::write($orderId, $category, $code);
        /* translators: %s: PayPal error */
        Orders::note($orderId, sprintf(__('Charging the saved PayPal method failed: %s', 'stackless-lite-edd'), $error->get_error_message()));
        $this->gateway->log('Saved-method charge for order ' . $orderId . ' failed: ' . $error->get_error_message() . ' ' . wp_json_encode($error->get_error_data()), 'warning');
        if ($category === SubscriptionData::DECLINE_AUTH) {
            return ['result' => 'action_required', 'message' => __('Confirm the payment with PayPal to finish.', 'stackless-lite-edd'), 'redirect' => Orders::payUrl($order)];
        }
        return ['result' => 'failed', 'message' => $error->get_error_message()];
    }
}
