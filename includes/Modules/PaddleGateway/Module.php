<?php

namespace StacklessEDD\Modules\PaddleGateway;

use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'paddle_gateway';
    }

    public function title(): string
    {
        return __('Paddle', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Paddle Billing as a merchant of record: an overlay checkout with sales tax and VAT handled for you, one-time orders, pay links, and subscriptions (with signup fees, free trials and one-time items in the same cart) that Paddle renews. Payments, renewals, refunds and cancellations sync both ways through signed webhooks.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
    }

    public function settingsFields(): array
    {
        return Settings::fields();
    }

    public function boot(): void
    {
        foreach (Gateway::all() as $gateway) {
            Gateways::add($gateway);
            add_filter('edd_payment_details_transaction_id-' . $gateway->id(), [$this, 'linkTransaction'], 10, 2);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'route'], 0);
        add_action('wp_enqueue_scripts', [$this, 'checkoutScript']);
        add_filter('stackless_edd_subscriptions_customer_actions', [$this, 'customerActions'], 10, 2);
        // Payment Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_edd_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_edd_gateway_copy_removed', [Copies::class, 'removed']);
        // Refunds from EDD's refund form go to Paddle first; refunds made in Paddle come back by webhook.
        add_action('edd_after_submit_refund_table', [Refunds::class, 'checkbox']);
        add_action('wp_ajax_edd_process_refund_form', [Refunds::class, 'preflight'], 1);
        add_action('edd_refund_order', [Refunds::class, 'onRefund'], 10, 3);
        if (is_admin()) {
            add_filter('stackless_edd_settings_sanitize_option', [Settings::class, 'remember'], 10, 3);
            add_action('stackless_edd_settings_saved_' . $this->id(), [Settings::class, 'validate']);
            WebhookSetup::register();
        }
    }

    /**
     * One pay, return and update address serves Paddle and every copy: the order (or
     * subscription) in the request says which one. Checkout re-checks the token or nonce.
     */
    private static function requestGateway(): Gateway
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- lookup only; Checkout authenticates the request.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $subscriptionId = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        // phpcs:enable
        $gateway = null;
        if ($orderId) {
            $order = Orders::get($orderId);
            $gateway = $order ? Gateway::forOrder($order) : null;
        } elseif ($subscriptionId) {
            $subscription = stackless_edd_get_subscription($subscriptionId);
            $gateway = $subscription instanceof SubscriptionData ? Gateway::forId($subscription->get_gateway()) : null;
        }
        return $gateway ?: Gateway::instance();
    }

    public function route(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each handler authenticates its request.
        $action = isset($_GET[Checkout::ENDPOINT]) ? sanitize_key(wp_unslash($_GET[Checkout::ENDPOINT])) : '';
        if ($action === 'pay') {
            (new Checkout(self::requestGateway()))->renderPayPage();
        } elseif ($action === 'return') {
            (new Checkout(self::requestGateway()))->handleReturn();
        } elseif ($action === 'update') {
            (new Checkout(self::requestGateway()))->handleUpdatePayment();
        }
    }

    /** Opens Paddle's overlay over the EDD checkout instead of a separate pay page. */
    public function checkoutScript(): void
    {
        if (!function_exists('edd_is_checkout') || !edd_is_checkout()) {
            return;
        }
        $ids = [];
        foreach (Gateway::all() as $gateway) {
            if ($gateway->isEnabled() && $gateway->clientToken() !== '') {
                $ids[] = $gateway->id();
            }
        }
        if (!$ids) {
            return;
        }
        wp_enqueue_script('stackless-edd-paddle_gateway-checkout', STACKLESS_EDD_PLUGIN_URL . 'assets/paddle_gateway/checkout.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_localize_script('stackless-edd-paddle_gateway-checkout', 'stacklessEddPaddle', [
            'gateways' => $ids,
            'paddleJs' => 'https://cdn.paddle.com/paddle/v2/paddle.js',
            'flag' => Gateway::INLINE_FLAG,
        ]);
    }

    /**
     * The order's Paddle transaction id, linked to the Paddle dashboard on the EDD order screen.
     *
     * @param mixed $transactionId
     * @param mixed $orderId
     * @return mixed
     */
    public function linkTransaction($transactionId, $orderId)
    {
        $order = Orders::get(absint($orderId));
        $gateway = $order ? Gateway::forOrder($order) : null;
        $url = $order && $gateway ? $gateway->transactionUrl((int) $order->id) : '';
        if ($url === '' || !is_string($transactionId) || $transactionId === '') {
            return $transactionId;
        }
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transactionId) . '</a>';
    }

    /**
     * Paddle keeps the card for subscriptions it bills, so "update payment method"
     * opens Paddle's own update checkout instead of the store's form.
     *
     * @param mixed $actions
     * @param mixed $subscription
     * @return mixed
     */
    public function customerActions($actions, $subscription)
    {
        if (!is_array($actions) || !$subscription instanceof SubscriptionData || !Copies::belongs($subscription->get_gateway())) {
            return $actions;
        }
        if ((string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === '') {
            return $actions;
        }
        $open = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_ON_HOLD, SubscriptionData::STATUS_PENDING_CANCEL];
        if (!in_array($subscription->get_status(), $open, true)) {
            return $actions;
        }
        $actions['paddle_update_payment'] = [
            'label' => __('Update payment method', 'stackless-lite-edd'),
            'url' => Checkout::updatePaymentUrl($subscription),
            'class' => 'button',
        ];
        return $actions;
    }
}
