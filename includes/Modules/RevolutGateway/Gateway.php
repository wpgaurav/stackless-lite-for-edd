<?php

namespace StacklessEDD\Modules\RevolutGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;

defined('ABSPATH') || exit;

/**
 * Revolut Merchant for Easy Digital Downloads: one-time payments on Revolut's hosted page, and
 * payments that save a card (subscriptions, free trials) in Revolut's checkout widget, saved
 * for merchant use. Renewals are merchant-initiated charges of that card on Stackless's schedule.
 *
 * Saved cards are not offered at checkout: charging one with the customer present needs a 3-D
 * Secure challenge of our own, and the merchant-initiated flag would be untrue there.
 *
 * Zero-total carts that must save a card reach this gateway through Subscriptions' checkout,
 * which is also what asks for the card to be saved.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = Keys::GATEWAY_ID;
    public const MODULE_ID = Keys::MODULE_ID;

    /** Revolut charges a card saved for merchant use for any amount on any date. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Revolut (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        return Settings::title();
    }

    public function isAvailable(): bool
    {
        return Settings::secretKey() !== '' && Money::supported(Platform::currency());
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::CURRENCIES;
    }

    public function fields(): void
    {
        stackless_edd_get_template('revolut_gateway/payment-fields.php', [
            'label' => $this->checkoutLabel(),
            'description' => Settings::description(),
            'testmode' => Settings::mode() === 'test',
            'requires_save' => stackless_edd_cart_requires_saved_payment_method(),
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        if (!Money::supported(Platform::currency())) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Revolut cannot take payments in %s here. Choose another payment method.', 'stackless-lite-edd'), Platform::currency()));
            return;
        }
        $orderId = $this->createPendingOrder($purchase_data);
        if ($orderId < 1) {
            return;
        }
        $result = $this->payOrder($orderId);
        if ($result['result'] !== 'success') {
            $this->resumeLater($orderId);
            $this->fail($result['message'] ?? __('Revolut could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
            return;
        }
        if (($result['empty_cart'] ?? '') === 'yes') {
            edd_empty_cart();
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Revolut's hosted page, this gateway's page, or this site's receipt.
        wp_redirect($result['redirect'] ?? Orders::successUrl($orderId));
        exit;
    }

    /**
     * Pay an EDD order. Returns result success|failure, redirect, message and empty_cart, so
     * checkout and the staging scripts share one code path. No token id from the browser is read.
     *
     * @return array{result:string, redirect?:string, message?:string, empty_cart?:string}
     */
    public function payOrder(int $orderId): array
    {
        if (!Orders::exists($orderId)) {
            return ['result' => 'failure', 'message' => __('Order not found.', 'stackless-lite-edd')];
        }
        $requiresSave = stackless_edd_order_requires_saved_payment_method($orderId);
        if (Orders::total($orderId) <= 0 && !$requiresSave) {
            stackless_edd_complete_order($orderId);
            return ['result' => 'success', 'redirect' => Orders::successUrl($orderId), 'empty_cart' => 'yes'];
        }
        try {
            $url = (new Checkout())->start($orderId, $requiresSave);
        } catch (\Throwable $e) {
            Settings::log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_revolut_checkout', __('Revolut could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Revolut payment could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            return ['result' => 'failure', 'message' => $url->get_error_message()];
        }
        return ['result' => 'success', 'redirect' => $url, 'empty_cart' => 'no'];
    }

    /** The next attempt at checkout reuses this pending order, and with it the open Revolut order. */
    private function resumeLater(int $orderId): void
    {
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        return (new Charges())->charge((int) $renewal_order->id, $subscription);
    }

    /** Stackless runs the schedule; nothing to cancel at Revolut. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }
}
