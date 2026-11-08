<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\AdvanceCharge;
use StacklessEDD\Contracts\SavedMethodCharger;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;

defined('ABSPATH') || exit;

/**
 * Razorpay for Easy Digital Downloads: Standard Checkout (UPI, cards, netbanking, wallets) for
 * one-time orders, and recurring tokens for subscriptions: card e-mandates, UPI Autopay and eNACH.
 *
 * Purchase creates the pending EDD order and a Razorpay order, then sends the buyer to a small
 * page of this gateway's own that opens Razorpay Checkout (EDD has no order-pay page). The signed
 * result comes back to this site and is checked against the Razorpay API.
 *
 * Renewals are recurring debits on the saved token. Razorpay tells the customer before every
 * debit and takes a day or more, so renewals return "pending", are started early
 * (Contracts\AdvanceCharge) and settle through the signed webhook.
 */
class Gateway extends \StacklessEDD\Core\Gateway implements SubscriptionGateway, SavedMethodCharger, AdvanceCharge
{
    public const ID = Settings::GATEWAY_ID;
    public const MODULE_ID = Settings::MODULE_ID;

    public const META_ORDER_ID = '_stackless_edd_razorpay_order_id';
    public const META_PAYMENT_ID = '_stackless_edd_razorpay_payment_id';
    public const META_MODE = '_stackless_edd_razorpay_mode';
    public const META_FLOW = '_stackless_edd_razorpay_flow';
    public const META_METHOD = '_stackless_edd_razorpay_method';
    public const META_AMOUNT = '_stackless_edd_razorpay_amount';
    public const META_ORDER_AT = '_stackless_edd_razorpay_order_at';
    public const META_VERIFICATION = '_stackless_edd_razorpay_verification';
    public const META_FIRST_DEBIT = '_stackless_edd_razorpay_first_debit_token';
    public const META_REFUND_IDS = '_stackless_edd_razorpay_refund_ids';
    public const USER_META_CUSTOMER = '_stackless_edd_razorpay_customer_';
    public const USER_META_PENDING_ADD = '_stackless_edd_razorpay_pending_add';

    /** Renewal method the buyer picked at checkout (card, upi, emandate). */
    public const FIELD_METHOD = 'stackless_edd_razorpay_recurring_method';
    /** Posted by the fields printed for a zero-total cart that must save a method (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_razorpay_setup';

    /**
     * Razorpay debits any amount up to the mandate ceiling on any date, so amount and date
     * changes work (amounts above the ceiling need a new mandate). The mandate is ₹0 for eNACH,
     * so a trial checkout can save a method; cards and UPI take a ₹1 authorization that is
     * refunded.
     */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Razorpay (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        return Settings::title();
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if (!Settings::hasKeys()) {
            return false;
        }
        $currency = stackless_edd_current_currency();
        if (!Money::supported($currency)) {
            return false;
        }
        $requiresSave = did_action('wp_loaded') && stackless_edd_cart_requires_saved_payment_method();
        if ($requiresSave && (!Money::recurringSupported($currency) || !$this->eligibleMethods())) {
            return false;
        }
        return true;
    }

    /** @return string[] ISO codes, read by Currency Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    /**
     * Largest single renewal the saved method must cover, in rupees: the order's biggest
     * subscription renewal, or for the cart, each subscription line at its regular price (what
     * renews) in the visitor's currency.
     */
    public function largestRenewal(?Order $order = null): float
    {
        $largest = 0.0;
        if ($order) {
            foreach (stackless_edd_get_order_subscriptions((int) $order->id) as $subscription) {
                if ($subscription instanceof SubscriptionData) {
                    $largest = max($largest, (float) $subscription->get_recurring_total());
                }
            }
            return $largest > 0 ? $largest : (float) $order->total;
        }
        if (!function_exists('edd_get_cart_contents') || !did_action('wp_loaded')) {
            return 0.0;
        }
        foreach ((array) edd_get_cart_contents() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $downloadId = (int) ($item['id'] ?? 0);
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $priceId = isset($options['price_id']) && is_numeric($options['price_id']) ? (int) $options['price_id'] : null;
            if ($downloadId < 1 || apply_filters('stackless_edd_subscription_product_terms', null, $downloadId, $priceId) === null) {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? ($options['quantity'] ?? 1)));
            $largest = max($largest, self::renewalPrice($downloadId, $priceId, $options, $quantity));
        }
        return $largest > 0 ? $largest : (float) edd_get_cart_total();
    }

    /**
     * A subscription line's regular price (what renews, before signup fees), with tax when prices
     * are entered without it, in the visitor's currency. The cart's own item price already went
     * through every price filter (currency conversion included); a free-trial line is priced at
     * zero there, so its catalog price is used, which the storefront shows in the visitor's
     * currency too.
     *
     * @param array<string,mixed> $options cart item options
     */
    public static function renewalPrice(int $downloadId, ?int $priceId, array $options = [], int $quantity = 1): float
    {
        $unit = function_exists('EDD') && EDD()->cart ? (float) EDD()->cart->get_item_price($downloadId, $options) : 0.0;
        if ($unit <= 0) {
            $unit = $priceId !== null && edd_has_variable_prices($downloadId)
                ? (float) edd_get_price_option_amount($downloadId, $priceId)
                : (float) edd_get_download_price($downloadId);
        }
        $amount = $unit * max(1, $quantity);
        if (function_exists('edd_use_taxes') && edd_use_taxes() && !edd_prices_include_tax()) {
            $amount += (float) edd_calculate_tax($amount);
        }
        return $amount;
    }

    /** @return string[] recurring methods that can carry this order's (or cart's) renewals */
    public function eligibleMethods(?Order $order = null): array
    {
        return Mandate::eligible(Settings::recurringMethods(), $this->largestRenewal($order));
    }

    /* ---- Checkout fields ------------------------------------------------------- */

    public function fields(): void
    {
        $requiresSave = stackless_edd_cart_requires_saved_payment_method();
        stackless_edd_get_template('razorpay_gateway/payment-fields.php', [
            'label' => $this->checkoutLabel(),
            'description' => Settings::description(),
            'testmode' => Settings::mode() === 'test',
            'methods' => $requiresSave ? array_intersect_key(Mandate::labels(), array_flip($this->eligibleMethods())) : [],
            'field' => self::FIELD_METHOD,
        ]);
    }

    /**
     * EDD checks this before sending the purchase to the gateway (edd_checkout_error_checks).
     *
     * @param mixed $valid_data
     * @param mixed $post
     */
    public function validateFields($valid_data, $post): void
    {
        $post = is_array($post) ? $post : [];
        $gateway = is_array($valid_data) ? (string) ($valid_data['gateway'] ?? '') : '';
        if ($gateway !== $this->id() && empty($post[self::FIELD_SETUP])) {
            return;
        }
        if (!stackless_edd_cart_requires_saved_payment_method()) {
            return;
        }
        if (!Money::recurringSupported(stackless_edd_current_currency())) {
            edd_set_error('stackless_edd_razorpay_currency', __('Razorpay saves payment methods for subscriptions in Indian rupees only.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
            return;
        }
        if (!$this->eligibleMethods()) {
            edd_set_error('stackless_edd_razorpay_method', __('None of the Razorpay renewal methods can cover this subscription\'s renewal amount. Choose another payment method.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
        }
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $method = self::postedMethod();
        $orderId = $this->createPendingOrder($purchase_data);
        if ($orderId < 1) {
            return;
        }
        $this->resumeLater($orderId);
        $order = Orders::get($orderId);
        if (!$order) {
            $this->fail(__('Order not found.', 'stackless-lite-edd'));
            return;
        }
        if ((float) $order->total <= 0 && !stackless_edd_order_requires_saved_payment_method($orderId)) {
            $this->complete($orderId);
            return;
        }
        $url = $this->startPayment($orderId, $method);
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Razorpay payment could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->fail($url->get_error_message());
            return;
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Prepare the Razorpay order for an EDD order and return the page that opens Razorpay
     * Checkout for it.
     *
     * @return string|\WP_Error
     */
    public function startPayment(int $orderId, string $method = '')
    {
        $order = Orders::get($orderId);
        if (!$order) {
            return new \WP_Error('stackless_edd_razorpay_order', __('Order not found.', 'stackless-lite-edd'));
        }
        try {
            $prepared = Checkout::prepare($order, stackless_edd_order_requires_saved_payment_method($orderId), $method);
        } catch (\Throwable $e) {
            Settings::log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $prepared = new \WP_Error('stackless_edd_razorpay_checkout', __('Razorpay could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        return Checkout::payUrl($order);
    }

    /** The next attempt at checkout reuses this pending order instead of creating another. */
    private function resumeLater(int $orderId): void
    {
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    public static function postedMethod(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form; handle() checked the gateway nonce.
        $value = isset($_POST[self::FIELD_METHOD]) && is_scalar($_POST[self::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[self::FIELD_METHOD])) : '';
        return in_array($value, Mandate::METHODS, true) ? $value : '';
    }

    /** EDD's receipt page, through the same filter edd_send_to_success_page() applies (custom thank-you pages). */
    public static function successUrl(): string
    {
        return (string) apply_filters('edd_success_page_redirect', edd_get_success_page_uri(), self::ID, null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter, applied the way EDD's gateways do.
    }

    /* ---- Zero-total carts that must save a method (all-trial subscriptions) ----- */

    /**
     * EDD hides gateways and switches to "manual" when the cart total is zero. A cart whose
     * subscription renews automatically still needs a saved method, so offer the gateways then.
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
     * mandate is saved (₹1 refunded, or ₹0 for eNACH) before the order completes.
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

    /* ---- Customers -------------------------------------------------------------- */

    /** @return string|\WP_Error cust_… id */
    public function customerFor(int $userId, string $mode, string $name, string $email, string $contact = '')
    {
        $metaKey = self::USER_META_CUSTOMER . $mode;
        if ($userId > 0) {
            $stored = (string) get_user_meta($userId, $metaKey, true);
            if (strpos($stored, 'cust_') === 0) {
                return $stored;
            }
        }
        $name = trim($name);
        $body = array_filter([
            'name' => strlen($name) >= 3 ? substr($name, 0, 50) : null,
            'email' => is_email($email) ? substr($email, 0, 64) : null,
            'contact' => self::contact($contact) !== '' ? self::contact($contact) : null,
            // Return the existing customer for the same email and phone instead of an error.
            'fail_existing' => '0',
            'notes' => ['wp_user_id' => (string) $userId, 'edd_site' => Settings::site()],
        ]);
        $created = Settings::api($mode)->request('POST', 'customers', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        if (strpos($id, 'cust_') !== 0) {
            return new \WP_Error('stackless_edd_razorpay_customer', __('Razorpay did not create a customer.', 'stackless-lite-edd'));
        }
        if ($userId > 0) {
            update_user_meta($userId, $metaKey, $id);
        }
        return $id;
    }

    /** Phone number as Razorpay takes it: digits with an optional leading +, 8 to 15 digits. */
    public static function contact(string $phone): string
    {
        $phone = (string) preg_replace('/[^0-9+]/', '', $phone);
        $digits = (string) preg_replace('/\D/', '', $phone);
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $phone : '';
    }

    /** The mode an order was paid in, even after the store switched modes. */
    public static function orderMode(int $orderId): string
    {
        $mode = Orders::metaString($orderId, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : Settings::mode();
    }

    /**
     * edd_payment_details_transaction_id-{gateway}: link the payment to the Razorpay dashboard.
     *
     * @param mixed $transactionId
     * @param mixed $orderId
     * @return mixed
     */
    public function linkTransaction($transactionId, $orderId = 0)
    {
        if (!is_string($transactionId) || strpos($transactionId, 'pay_') !== 0) {
            return $transactionId;
        }
        $url = 'https://dashboard.razorpay.com/app/payments/' . rawurlencode($transactionId);
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transactionId) . '</a>';
    }

    /* ---- Contracts -------------------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        return Renewals::charge($renewal_order, $subscription);
    }

    /** Stackless runs the schedule; the mandate stays until the customer removes it. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_edd_charge_saved_method(Order $order, string $token_id, bool $on_session): array
    {
        return Renewals::chargeSaved($order, $token_id);
    }

    public function stackless_edd_renewal_lead_seconds(): int
    {
        return Settings::leadDays() * DAY_IN_SECONDS;
    }
}
