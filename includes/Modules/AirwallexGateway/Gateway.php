<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;

defined('ABSPATH') || exit;

/**
 * Airwallex for Easy Digital Downloads: the buyer pays on Airwallex's hosted Checkout Session
 * page and comes back to the EDD receipt. Nothing the browser or a webhook says settles an
 * order: Sync reads the session and the PaymentIntent from the Airwallex API first.
 *
 * Subscriptions save a card as a merchant-triggered PaymentConsent. Saved cards are not offered
 * for one-click payments: those are customer-initiated and need Airwallex's browser SDK for CVC
 * and 3-D Secure.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = Settings::GATEWAY_ID;
    public const MODULE_ID = Settings::MODULE_ID;

    /** Posted by the fields printed for a zero-total cart that must save a card (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_airwallex_setup';

    /** Card consents are unscheduled merchant-initiated agreements: any amount, on any date. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Airwallex Beta (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        return Settings::title();
    }

    public function isAvailable(): bool
    {
        return Settings::configured() && Money::supported(stackless_edd_current_currency());
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function fields(): void
    {
        stackless_edd_get_template('airwallex_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => Settings::description(),
            'testmode' => Settings::mode() === 'test',
            'requires_save' => stackless_edd_cart_requires_saved_payment_method(),
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $currency = strtoupper(stackless_edd_current_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Airwallex cannot take payments in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
            return;
        }
        if (!Settings::configured()) {
            $this->fail(__('Airwallex is not connected. Choose another payment method.', 'stackless-lite-edd'));
            return;
        }
        $orderId = $this->createPendingOrder($purchase_data);
        if ($orderId < 1) {
            return;
        }
        $result = $this->payOrder($orderId);
        if ($result['result'] !== 'success') {
            $this->resumeLater($orderId);
            // The order stays as it is: an earlier payment may still be settling.
            $this->fail($result['message']);
            return;
        }
        if ($result['empty_cart'] === 'yes') {
            edd_empty_cart();
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Airwallex's hosted page (Checkout checked the host) or this site's receipt.
        wp_redirect($result['redirect']);
        exit;
    }

    /**
     * Start the payment of an EDD order, for checkout, pay links and the staging scripts. A
     * saved card named by the browser is never charged (see the class note).
     *
     * @return array{result:string, redirect:string, message:string, empty_cart:string}
     */
    public function payOrder(int $orderId): array
    {
        try {
            $url = (new Checkout())->start($orderId);
        } catch (\Throwable $e) {
            Settings::log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_airwallex_checkout', __('Airwallex could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            stackless_edd_add_order_note($orderId, sprintf(__('Airwallex payment could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            return ['result' => 'failure', 'redirect' => '', 'message' => $url->get_error_message(), 'empty_cart' => 'no'];
        }
        // A receipt means there is nothing left to pay; the hosted page keeps the cart until the buyer returns.
        return ['result' => 'success', 'redirect' => $url, 'message' => '', 'empty_cart' => Checkout::hostedUrl($url) === '' ? 'yes' : 'no'];
    }

    /** The next attempt at checkout reuses this pending order (and its open session). */
    private function resumeLater(int $orderId): void
    {
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    /* ---- Zero-total carts that must save a card (all-trial subscriptions) ------- */

    /**
     * EDD hides gateways and switches to "manual" when the cart total is zero. A cart whose
     * subscription renews automatically still needs a saved card, so offer the gateways then.
     *
     * @param mixed $show
     */
    public function filterShowGateways($show): bool
    {
        if (!$show && $this->zeroTotalNeedsMethod() && count((array) edd_get_enabled_payment_gateways()) > 1) {
            return true;
        }
        return (bool) $show;
    }

    /**
     * @param mixed $gateway
     * @param mixed $chosen
     * @return mixed
     */
    public function filterChosenGateway($gateway, $chosen = false)
    {
        if ($gateway !== 'manual' || !$this->zeroTotalNeedsMethod()) {
            return $gateway;
        }
        $wanted = is_string($chosen) && $chosen !== '' ? $chosen : (function_exists('edd_get_default_gateway') ? edd_get_default_gateway() : '');
        return $wanted === $this->id() && edd_is_gateway_active($this->id()) ? $this->id() : $gateway;
    }

    /** Prints the fields for a zero-total cart (EDD skips the payment form when nothing is due). */
    public function zeroTotalFields(): void
    {
        if (!$this->zeroTotalNeedsMethod() || edd_get_chosen_gateway() !== $this->id()) {
            return;
        }
        echo '<input type="hidden" name="' . esc_attr(self::FIELD_SETUP) . '" value="1">';
        $this->fields();
    }

    /**
     * EDD sends a zero-total purchase to "manual". When our fields were used, take it over so the
     * card is saved (a SETUP session) before the order completes.
     *
     * @param mixed $purchase_data
     */
    public function interceptManual($purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form.
        if (empty($_POST[self::FIELD_SETUP]) || !stackless_edd_cart_requires_saved_payment_method() || !$this->isEnabled()) {
            return;
        }
        $purchase_data = is_array($purchase_data) ? $purchase_data : [];
        $purchase_data['gateway'] = $this->id();
        $this->handle($purchase_data);
        edd_die();
    }

    private function zeroTotalNeedsMethod(): bool
    {
        return function_exists('edd_get_cart_total') && (float) edd_get_cart_total() <= 0
            && (function_exists('edd_get_cart_contents') && edd_get_cart_contents())
            && stackless_edd_cart_requires_saved_payment_method();
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        $token = $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
        return (new Renewals())->charge((int) $renewal_order->id, is_scalar($token) ? (string) $token : '');
    }

    /** Stackless runs the schedule; nothing to cancel at Airwallex. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }
}
