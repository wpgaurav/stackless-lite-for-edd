<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Base for every payment gateway Stackless adds to Easy Digital Downloads (the EDD counterpart of
 * WC_Payment_Gateway). A gateway module creates its instance in boot() and passes it to
 * Gateways::add(); the store owner then enables it in Downloads → Settings → Payments like any
 * EDD gateway. Gateway settings (keys, modes) live in the module's Stackless settings.
 *
 * Purchase flow: EDD validates the purchase form, then calls handle() with $purchase_data.
 * process() builds a pending order with createPendingOrder(), charges or redirects, and ends with
 * complete() (on-site payment) or a redirect to the provider; webhooks finish async payments with
 * stackless_edd_complete_order().
 *
 * Gateways that renew subscriptions also implement Contracts\SubscriptionGateway.
 */
abstract class Gateway
{
    /** EDD gateway id, prefixed: stackless_edd_{name}. Copies (Gateway Tools) append _copyN. */
    abstract public function id(): string;

    /** Shown in Downloads → Settings → Payments. Keep "(Stackless)" in it. */
    abstract public function adminLabel(): string;

    abstract public function checkoutLabel(): string;

    /** Handle the purchase: create the order, charge or redirect. Must end the request. */
    abstract protected function process(array $purchase_data): void;

    /** EDD supports flags, e.g. ['buy_now']. */
    public function supports(): array
    {
        return [];
    }

    /** Payment icon keys or URLs shown at checkout. */
    public function icons(): array
    {
        return [];
    }

    /** Hide the gateway for the current cart (wrong currency, no credentials, subscription limits). */
    public function isAvailable(): bool
    {
        return true;
    }

    /** Payment fields printed in place of EDD's default card form. Leave empty for redirect gateways. */
    public function fields(): void
    {
    }

    public function register(): void
    {
        add_filter('edd_payment_gateways', [$this, 'addGateway']);
        add_filter('edd_enabled_payment_gateways', [$this, 'filterEnabled'], 20);
        add_action('edd_gateway_' . $this->id(), [$this, 'handle']);
        add_action('edd_' . $this->id() . '_cc_form', [$this, 'fields']);
    }

    /** @param array<string,array<string,mixed>> $gateways */
    public function addGateway($gateways): array
    {
        $gateways = is_array($gateways) ? $gateways : [];
        $gateways[$this->id()] = [
            'admin_label' => $this->adminLabel(),
            'checkout_label' => $this->checkoutLabel(),
            'supports' => $this->supports(),
            'icons' => $this->icons(),
        ];
        return $gateways;
    }

    /** @param array<string,mixed> $gateways */
    public function filterEnabled($gateways): array
    {
        $gateways = is_array($gateways) ? $gateways : [];
        if (isset($gateways[$this->id()]) && !is_admin() && !$this->isAvailable()) {
            unset($gateways[$this->id()]);
        }
        return $gateways;
    }

    public function handle($purchase_data): void
    {
        $purchase_data = is_array($purchase_data) ? $purchase_data : [];
        if (empty($purchase_data['gateway_nonce']) || !wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
            wp_die(esc_html__('Nonce verification has failed', 'stackless-lite-edd'), esc_html__('Error', 'stackless-lite-edd'), ['response' => 403]);
        }
        try {
            $this->process($purchase_data);
        } catch (\Throwable $e) {
            Logger::log($this->id(), 'Checkout failed: ' . $e->getMessage(), 'error');
            $this->fail(__('Payment could not be processed. Please try again.', 'stackless-lite-edd'));
        }
    }

    public function isEnabled(): bool
    {
        return function_exists('edd_is_gateway_active') && edd_is_gateway_active($this->id());
    }

    public function testMode(): bool
    {
        return function_exists('edd_is_test_mode') && edd_is_test_mode();
    }

    /**
     * Record the pending order for this purchase. Sends the shopper back to checkout and returns 0
     * when EDD refuses the order (for example an already-claimed one-use discount).
     */
    protected function createPendingOrder(array $purchase_data): int
    {
        $payment_data = [
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'] ?? '',
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => function_exists('stackless_edd_current_currency') ? stackless_edd_current_currency() : edd_get_currency(),
            'downloads' => $purchase_data['downloads'],
            'user_info' => $purchase_data['user_info'],
            'cart_details' => $purchase_data['cart_details'],
            'gateway' => $this->id(),
            'status' => 'pending',
        ];
        $order_id = (int) edd_insert_payment($payment_data);
        if ($order_id < 1) {
            edd_record_gateway_error(__('Payment Error', 'stackless-lite-edd'), sprintf('%s could not create the order.', $this->id()));
            $this->fail(__('Your order could not be created. Please try again.', 'stackless-lite-edd'));
            return 0;
        }
        if (class_exists(\EDD\Discounts\OrderValidation::class) && \EDD\Discounts\OrderValidation::refuse_unclaimed($order_id)) {
            $this->back();
            return 0;
        }
        return $order_id;
    }

    /** On-site payment succeeded: mark the order paid, empty the cart and show the receipt. */
    protected function complete(int $order_id, string $transaction_id = ''): void
    {
        stackless_edd_complete_order($order_id, $transaction_id);
        edd_empty_cart();
        edd_send_to_success_page();
    }

    /** Show an error at checkout and send the shopper back. Marks $order_id failed when given. */
    protected function fail(string $message, int $order_id = 0): void
    {
        // EDD documents the error id as int; every caller (EDD included) passes a string code.
        edd_set_error('stackless_edd_' . $this->id(), $message); // @phpstan-ignore argument.type
        if ($order_id > 0) {
            edd_update_order_status($order_id, 'failed');
        }
        $this->back();
    }

    protected function back(): void
    {
        edd_send_back_to_checkout(['payment-mode' => $this->id()]);
    }
}
