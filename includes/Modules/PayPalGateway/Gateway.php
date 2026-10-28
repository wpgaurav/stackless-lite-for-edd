<?php

namespace StacklessEDD\Modules\PayPalGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * PayPal (Orders v2 + Vault v3) for Easy Digital Downloads, with automatic subscription renewals.
 *
 * Settings (credentials, buttons, Pay Later) are this module's Stackless settings; the store
 * owner turns the gateway on in Downloads → Settings → Payments.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = 'stackless_edd_paypal';
    public const OPTION_PREFIX = 'stackless_edd_paypal_gateway_';

    public const META_ORDER_ID = '_stackless_edd_paypal_order_id';
    public const META_CAPTURE_ID = '_stackless_edd_paypal_capture_id';
    public const META_AUTH_ID = '_stackless_edd_paypal_authorization_id';
    public const META_VAULT_ID = '_stackless_edd_paypal_vault_id';
    public const META_SETUP_TOKEN = '_stackless_edd_paypal_setup_token';
    public const META_ENV = '_stackless_edd_paypal_environment';
    public const META_REFUND_IDS = '_stackless_edd_paypal_refund_ids';
    public const META_RENEWAL_ATTEMPTS = '_stackless_edd_paypal_renewal_attempts';
    public const META_PAYER_EMAIL = '_stackless_edd_paypal_payer_email';
    public const META_VOIDED = '_stackless_edd_paypal_voided';

    /** Hidden checkout fields the buttons fill in after PayPal approves. */
    public const FIELD_ORDER = 'stackless_edd_paypal_order_id';
    public const FIELD_SETUP = 'stackless_edd_paypal_setup_token';

    /** Features this gateway offers to the Subscriptions module. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    /** @var Gateway|null */
    private static $instance;

    /** @var Payments|null */
    private $payments;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('PayPal (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : __('PayPal', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->setting('description', __('Pay with your PayPal account, Pay Later, or a card through PayPal.', 'stackless-lite-edd'));
    }

    /** @return mixed */
    public function setting(string $key, $default = '')
    {
        $value = get_option(self::OPTION_PREFIX . $key, null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ---- Configuration ------------------------------------------------- */

    public function isSandbox(): bool
    {
        return $this->setting('sandbox', 'yes') === 'yes';
    }

    /** sandbox|live */
    public function environment(): string
    {
        return $this->isSandbox() ? 'sandbox' : 'live';
    }

    private function credential(string $environment, string $key): string
    {
        $constant = 'STACKLESS_EDD_PAYPAL_' . ($environment === 'sandbox' ? 'SANDBOX_' : '') . strtoupper($key);
        if (defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->setting(($environment === 'sandbox' ? 'sandbox_' : '') . $key, ''));
    }

    public function clientId(string $environment = ''): string
    {
        return $this->credential($environment ?: $this->environment(), 'client_id');
    }

    public function webhookId(string $environment = ''): string
    {
        return $this->credential($environment ?: $this->environment(), 'webhook_id');
    }

    public function apiFor(string $environment): Api
    {
        return new Api($environment === 'sandbox', $this->credential($environment, 'client_id'), $this->credential($environment, 'client_secret'));
    }

    public function api(): Api
    {
        return $this->apiFor($this->environment());
    }

    public function payments(): Payments
    {
        if (!$this->payments) {
            $this->payments = new Payments($this);
        }
        return $this->payments;
    }

    /** capture|authorize for orders that don't need a saved account. */
    public function intent(): string
    {
        return $this->setting('intent', 'capture') === 'authorize' ? 'authorize' : 'capture';
    }

    public function brandName(): string
    {
        $name = trim((string) $this->setting('brand_name', ''));
        if ($name === '') {
            $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        }
        return Amount::truncate($name, 127);
    }

    public function invoicePrefix(): string
    {
        return (string) $this->setting('invoice_prefix', 'EDD-');
    }

    public function buttonStyle(): array
    {
        $color = (string) $this->setting('button_color', 'gold');
        $shape = (string) $this->setting('button_shape', 'rect');
        $label = (string) $this->setting('button_label', 'paypal');
        $style = [
            'layout' => $this->setting('button_layout', 'vertical') === 'horizontal' ? 'horizontal' : 'vertical',
            'color' => in_array($color, ['gold', 'blue', 'silver', 'white', 'black'], true) ? $color : 'gold',
            'shape' => in_array($shape, ['rect', 'pill', 'sharp'], true) ? $shape : 'rect',
            'label' => in_array($label, ['paypal', 'checkout', 'buynow', 'pay'], true) ? $label : 'paypal',
        ];
        if ($style['layout'] === 'horizontal') {
            $style['tagline'] = false;
        }
        return (array) apply_filters('stackless_edd_paypal_gateway_button_style', $style);
    }

    public function payLaterMessaging(): bool
    {
        return $this->setting('paylater_messaging', 'yes') === 'yes';
    }

    /** Debug lines only when the store owner turned logging on; warnings and errors always. */
    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('paypal_gateway', $message, $level, $context);
    }

    public function transactionUrl(string $transactionId, string $environment = ''): string
    {
        $environment = $environment !== '' ? $environment : $this->environment();
        return sprintf($environment === 'sandbox' ? 'https://www.sandbox.paypal.com/activity/payment/%s' : 'https://www.paypal.com/activity/payment/%s', rawurlencode($transactionId));
    }

    /**
     * edd_payment_details_transaction_id-{gateway}: link the capture id to PayPal.
     *
     * @param mixed $transactionId
     * @param mixed $orderId
     * @return mixed
     */
    public function linkTransaction($transactionId, $orderId = 0)
    {
        if (!is_string($transactionId) || $transactionId === '') {
            return $transactionId;
        }
        $url = $this->transactionUrl($transactionId, Orders::meta((int) $orderId, self::META_ENV));
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transactionId) . '</a>';
    }

    /* ---- Availability ---------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->clientId() === '' || !$this->api()->hasCredentials()) {
            return false;
        }
        return Amount::supported(stackless_edd_current_currency());
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Amount::currencies();
    }

    /* ---- Checkout UI ----------------------------------------------------- */

    public function fields(): void
    {
        $requiresVault = stackless_edd_cart_requires_saved_payment_method();
        $total = (float) edd_get_cart_total();
        $currency = strtoupper(stackless_edd_current_currency());

        stackless_edd_get_template('paypal_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'context' => 'checkout',
            'mode' => ($requiresVault && $total <= 0) ? 'setup' : 'order',
            'requires_vault' => $requiresVault,
            'intent' => $requiresVault ? 'capture' : $this->intent(),
            'currency' => $currency,
            'total' => Amount::format($total, $currency),
        ]);
    }

    /* ---- Payments --------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $post = (array) ($purchase_data['post_data'] ?? []);
        $paypalOrderId = isset($post[self::FIELD_ORDER]) && is_scalar($post[self::FIELD_ORDER]) ? sanitize_text_field(wp_unslash((string) $post[self::FIELD_ORDER])) : '';
        $setupToken = isset($post[self::FIELD_SETUP]) && is_scalar($post[self::FIELD_SETUP]) ? sanitize_text_field(wp_unslash((string) $post[self::FIELD_SETUP])) : '';

        $currency = stackless_edd_current_currency();
        if (!Amount::supported($currency)) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('PayPal cannot take payments in %s. Choose another payment method.', 'stackless-lite-edd'), $currency));
            return;
        }

        $orderId = $this->createPendingOrder($purchase_data);
        if ($orderId < 1) {
            return;
        }
        $order = Orders::get($orderId);
        if (!$order) {
            $this->fail(__('Order not found.', 'stackless-lite-edd'));
            return;
        }

        try {
            $result = $this->payments()->process($order, $paypalOrderId, $setupToken);
        } catch (\Throwable $e) {
            $this->log('Checkout failed for order ' . $orderId . ': ' . $e->getMessage(), 'error');
            $result = ['result' => 'failure', 'message' => __('PayPal could not take this payment. Please try again or choose another payment method.', 'stackless-lite-edd')];
        }

        if (($result['result'] ?? '') !== 'success') {
            $this->resumeLater($orderId);
            $this->fail((string) ($result['message'] ?? __('PayPal could not take this payment.', 'stackless-lite-edd')));
            return;
        }
        if (!empty($result['redirect'])) {
            wp_redirect((string) $result['redirect']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- PayPal's approval page.
            exit;
        }
        edd_empty_cart();
        edd_send_to_success_page();
    }

    /** The next attempt at checkout reuses this pending order instead of creating another. */
    private function resumeLater(int $orderId): void
    {
        $order = Orders::get($orderId);
        if ($order && function_exists('EDD') && EDD()->session && !Orders::isPaid($order)) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    /* ---- Subscriptions contract ------------------------------------------ */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        try {
            return $this->payments()->chargeRenewal($renewal_order, $subscription);
        } catch (\Throwable $e) {
            $this->log('Renewal ' . $renewal_order->id . ' failed: ' . $e->getMessage(), 'error');
            // The attempt counter is left alone, so a retry reuses the same PayPal-Request-Id
            // and can never charge twice if PayPal did process this one.
            $message = __('PayPal renewal hit an unexpected error before it could be confirmed.', 'stackless-lite-edd');
            $fresh = Orders::get((int) $renewal_order->id);
            if ($fresh && !Orders::isPaid($fresh)) {
                Orders::setStatus((int) $fresh->id, 'failed', $message);
            }
            return ['result' => 'failed', 'message' => $message];
        }
    }

    /** The store runs the schedule, so there is nothing to cancel at PayPal. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }
}
