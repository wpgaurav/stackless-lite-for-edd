<?php

namespace StacklessEDD\Modules\MollieGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SavedMethodCharger;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;

defined('ABSPATH') || exit;

/**
 * Mollie for Easy Digital Downloads: the buyer pays on Mollie's hosted checkout (cards, iDEAL,
 * Bancontact, PayPal, Apple Pay and every other method switched on in Mollie), and comes back to
 * the EDD receipt. Mollie's webhook carries only a payment id, so every status is read from the
 * Mollie API before anything changes.
 *
 * Saving a method for subscriptions uses a first payment (sequenceType=first) on a Mollie
 * customer, which leaves a mandate. Renewals are recurring payments on that mandate; cards
 * usually settle at once, SEPA Direct Debit days later through the webhook.
 */
class Gateway extends BaseGateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = Settings::GATEWAY_ID;
    public const MODULE_ID = Settings::MODULE_ID;

    public const META_PAYMENT_ID = '_stackless_edd_mollie_payment_id';
    public const META_CHECKOUT_URL = '_stackless_edd_mollie_checkout_url';
    public const META_MODE = '_stackless_edd_mollie_mode';
    public const META_SEQUENCE = '_stackless_edd_mollie_sequence';
    public const META_VERIFICATION = '_stackless_edd_mollie_verification';
    public const META_METHOD = '_stackless_edd_mollie_method';
    public const META_REFUND_IDS = '_stackless_edd_mollie_refund_ids';
    public const META_CHARGEBACK = '_stackless_edd_mollie_charged_back';
    public const USER_META_CUSTOMER = '_stackless_edd_mollie_customer_';
    public const USER_META_PENDING_ADD = '_stackless_edd_mollie_pending_add';

    public const FIELD_METHOD = 'stackless_edd_mollie_method';
    public const FIELD_TOKEN = 'stackless_edd_mollie_payment_token';
    public const FIELD_SAVE = 'stackless_edd_mollie_save_method';
    /** Posted by the fields printed for a zero-total cart that must save a method (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_mollie_setup';

    /** auto_renewal and the rest; Mollie charges every mandate for any amount on any date. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Mollie (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        return Settings::title();
    }

    public function mode(): string
    {
        return Settings::mode();
    }

    public function apiKey(string $mode = ''): string
    {
        return Settings::apiKey($mode);
    }

    public function api(string $mode = ''): Api
    {
        return Settings::api($mode);
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = Orders::metaString($orderId, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    public function webhookUrl(): string
    {
        return Settings::webhookUrl();
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        Settings::log($message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->apiKey() === '') {
            return false;
        }
        $currency = strtoupper(stackless_edd_current_currency());
        return Money::supported($currency) && ($this->mode() !== 'test' || $currency === 'EUR');
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    /**
     * Methods for the picker on this page: what Mollie offers for the cart total (first-payment
     * methods when a method must be saved), or for adding a method on the account page.
     *
     * @return array<string,string> id => label
     */
    public function pickerMethods(bool $adding = false): array
    {
        if (!Settings::pickerAtCheckout() || $this->apiKey() === '') {
            return [];
        }
        if ($adding) {
            $currency = self::addCurrency($this->mode());
            $total = 1.0;
            $first = true;
        } else {
            $currency = strtoupper(stackless_edd_current_currency());
            $total = function_exists('edd_get_cart_total') && did_action('wp_loaded') ? (float) edd_get_cart_total() : 0.0;
            $first = stackless_edd_cart_requires_saved_payment_method();
        }
        $available = Methods::enabled($this->api(), $currency, Money::value(max($total, 0.01), $currency), $first);
        $ids = Methods::pick(Settings::configuredMethods(), $available, $first);
        $methods = [];
        foreach ($ids as $id) {
            if ($first && !Methods::mandateTakes(Methods::MANDATE_OF[$id] ?? '', $currency)) {
                continue;
            }
            $methods[$id] = $available[$id] ?? Methods::label($id);
        }
        return $methods;
    }

    /** Currency of an account-page first payment: the store's, or euros when Mollie cannot use it here. */
    public static function addCurrency(string $mode): string
    {
        $currency = strtoupper(stackless_edd_current_currency());
        return (!Money::supported($currency) || ($mode === 'test' && $currency !== 'EUR')) ? 'EUR' : $currency;
    }

    public function fields(): void
    {
        $requiresSave = stackless_edd_cart_requires_saved_payment_method();
        $userId = get_current_user_id();
        $tokens = ($userId > 0 && (Settings::savedMethodsEnabled() || $requiresSave)) ? Tokens::forUser($userId) : [];
        stackless_edd_get_template('mollie_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => Settings::description(),
            'testmode' => $this->mode() === 'test',
            'methods' => $this->pickerMethods(),
            'field' => self::FIELD_METHOD,
            'token_field' => self::FIELD_TOKEN,
            'save_field' => self::FIELD_SAVE,
            'tokens' => $tokens,
            'requires_save' => $requiresSave,
            'show_save' => !$requiresSave && Settings::savedMethodsEnabled() && $userId > 0,
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form; handle() checked the gateway nonce.
        $post = wp_unslash($_POST);
        $request = self::requestFrom(is_array($post) ? $post : []);
        $currency = strtoupper(stackless_edd_current_currency());
        if (!Money::supported($currency) || ($this->mode() === 'test' && $currency !== 'EUR')) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Mollie cannot take payments in %s here. Choose another payment method.', 'stackless-lite-edd'), $currency));
            return;
        }
        $orderId = $this->createPendingOrder($purchase_data);
        if ($orderId < 1) {
            return;
        }
        $result = $this->payOrder($orderId, $request);
        if (($result['result'] ?? '') !== 'success') {
            $this->resumeLater($orderId);
            $this->fail((string) ($result['message'] ?? __('Mollie could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd')));
            return;
        }
        if (($result['empty_cart'] ?? '') === 'yes') {
            edd_empty_cart();
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mollie's hosted checkout or this site's receipt.
        wp_redirect((string) $result['redirect']);
        exit;
    }

    /**
     * Pay an EDD order with what the buyer submitted. Returns result success|failure, redirect
     * (Mollie's checkout or the receipt), message and empty_cart, so checkout and the staging
     * scripts share one code path.
     *
     * @param array{token_id?:int, save?:bool, method?:string} $request
     * @return array<string,string>
     */
    public function payOrder(int $orderId, array $request): array
    {
        $order = Orders::get($orderId);
        if (!$order) {
            return $this->failure(__('Order not found.', 'stackless-lite-edd'));
        }

        $tokenId = (int) ($request['token_id'] ?? 0);
        if ($tokenId > 0) {
            return $this->payWithToken($order, $tokenId);
        }

        $requiresSave = stackless_edd_order_requires_saved_payment_method($orderId);
        $save = $requiresSave || (!empty($request['save']) && Settings::savedMethodsEnabled() && (int) $order->user_id > 0);
        if ((float) $order->total <= 0 && !$save) {
            stackless_edd_complete_order($orderId);
            return $this->success(true);
        }

        try {
            $url = (new Checkout($this))->start($order, $save, (string) ($request['method'] ?? ''));
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_mollie_checkout', __('Mollie could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Mollie payment could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url, 'empty_cart' => 'no', 'order_id' => (string) $orderId];
    }

    /**
     * Pay a checkout order with a saved mandate: a recurring payment, charged without sending
     * the buyer away.
     *
     * @return array<string,string>
     */
    private function payWithToken(Order $order, int $tokenId): array
    {
        $orderId = (int) $order->id;
        $token = Tokens::getOwned($tokenId, (int) $order->user_id);
        if (!$token || (int) $order->user_id <= 0 || $token->mode !== $this->mode()) {
            return $this->failure(__('That saved payment method is not available. Choose another one.', 'stackless-lite-edd'));
        }
        Orders::updateMeta($orderId, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        Orders::updateMeta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER, $token->mollie_customer);
        Orders::updateMeta($orderId, self::META_MODE, $token->mode);
        if ((float) $order->total <= 0) {
            stackless_edd_complete_order($orderId, '', __('Saved Mollie payment method attached for future payments.', 'stackless-lite-edd'));
            return $this->success(true);
        }
        $result = (new Renewals($this))->chargeToken($order, $token, 'checkout');
        if ($result['result'] === 'failed') {
            return $this->failure($result['message'] ?? __('Mollie declined the payment.', 'stackless-lite-edd'));
        }
        return $this->success(true);
    }

    /** The next attempt at checkout reuses this pending order (and its open Mollie payment). */
    private function resumeLater(int $orderId): void
    {
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    /** @return array<string,string> */
    private function success(bool $emptyCart): array
    {
        return ['result' => 'success', 'redirect' => self::successUrl(), 'empty_cart' => $emptyCart ? 'yes' : 'no'];
    }

    /** @return array<string,string> */
    private function failure(string $message): array
    {
        return ['result' => 'failure', 'message' => $message];
    }

    /** EDD's receipt page, through the same filter edd_send_to_success_page() applies (custom thank-you pages). */
    public static function successUrl(): string
    {
        return (string) apply_filters('edd_success_page_redirect', edd_get_success_page_uri(), self::ID, null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- EDD's own filter, applied the way EDD's gateways do.
    }

    /**
     * The fields this gateway reads from a posted purchase form.
     *
     * @param array<string,mixed> $post unslashed
     * @return array{token_id:int, save:bool, method:string}
     */
    public static function requestFrom(array $post): array
    {
        $token = isset($post[self::FIELD_TOKEN]) && is_scalar($post[self::FIELD_TOKEN]) ? sanitize_text_field((string) $post[self::FIELD_TOKEN]) : '';
        $save = isset($post[self::FIELD_SAVE]) && is_scalar($post[self::FIELD_SAVE]) ? strtolower(sanitize_text_field((string) $post[self::FIELD_SAVE])) : '';
        $method = isset($post[self::FIELD_METHOD]) && is_scalar($post[self::FIELD_METHOD]) ? sanitize_key((string) $post[self::FIELD_METHOD]) : '';
        return [
            'token_id' => ($token !== '' && $token !== 'new') ? absint($token) : 0,
            'save' => in_array($save, ['1', 'true', 'yes', 'on'], true),
            'method' => isset(Methods::labels()[$method]) ? $method : '',
        ];
    }

    /* ---- Zero-total carts that must save a method (all-trial subscriptions) ---- */

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
     * mandate is saved (a 0.00 or one-cent first payment) before the order completes.
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

    /**
     * The Mollie customer for an order: the account's customer in this mode, created once,
     * or a customer of its own for a guest order.
     *
     * @return string|\WP_Error cst_… id
     */
    public function customerFor(Order $order, string $mode)
    {
        $orderId = (int) $order->id;
        $existing = Orders::metaString($orderId, SubscriptionData::META_GATEWAY_CUSTOMER);
        if (strpos($existing, 'cst_') === 0 && $this->orderMode($orderId) === $mode) {
            return $existing;
        }
        return $this->customerForUser((int) $order->user_id, $mode, Orders::customerName($order), (string) $order->email);
    }

    /** @return string|\WP_Error */
    public function customerForUser(int $userId, string $mode, string $name = '', string $email = '')
    {
        $metaKey = self::USER_META_CUSTOMER . $mode;
        if ($userId > 0) {
            $stored = (string) get_user_meta($userId, $metaKey, true);
            if (strpos($stored, 'cst_') === 0) {
                return $stored;
            }
            $user = get_userdata($userId);
            if ($user) {
                $name = $name !== '' ? $name : trim($user->first_name . ' ' . $user->last_name);
                $email = $email !== '' ? $email : (string) $user->user_email;
            }
        }
        $body = array_filter([
            'name' => $name !== '' ? $name : null,
            'email' => is_email($email) ? $email : null,
            'metadata' => ['wp_user_id' => (string) $userId, 'edd_site' => Sync::site()],
        ]);
        $created = $this->api($mode)->request('POST', 'customers', $body, 'stackless-edd-customer-' . md5($mode . '|' . $userId . '|' . $email . '|' . ($userId > 0 ? '' : wp_generate_password(8, false))));
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        if (strpos($id, 'cst_') !== 0) {
            return new \WP_Error('stackless_edd_mollie_customer', __('Mollie did not create a customer.', 'stackless-lite-edd'));
        }
        if ($userId > 0) {
            update_user_meta($userId, $metaKey, $id);
        }
        return $id;
    }

    /* ---- Refunds ---------------------------------------------------------------- */

    /** Whether the order has a Mollie payment a refund can go to (verification payments do not). */
    public function canRefund(Order $order): bool
    {
        $orderId = (int) $order->id;
        return (string) $order->gateway === self::ID && Orders::metaString($orderId, self::META_PAYMENT_ID) !== '' && Orders::metaString($orderId, self::META_VERIFICATION) !== 'yes';
    }

    /**
     * Refund part or all of the order's Mollie payment.
     *
     * @return array{id:string,status:string}|\WP_Error
     */
    public function refund(Order $order, float $amount, string $reason = '', int $sequence = 0)
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_mollie_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $paymentId = Orders::metaString($orderId, self::META_PAYMENT_ID);
        if (!$this->canRefund($order)) {
            return new \WP_Error('stackless_edd_mollie_refund', __('This order has no Mollie payment to refund.', 'stackless-lite-edd'));
        }
        $currency = strtoupper((string) $order->currency);
        $body = [
            'amount' => Money::amount($amount, $currency),
            /* translators: %s: order number */
            'description' => substr(trim($reason) !== '' ? wp_strip_all_tags($reason) : sprintf(__('Refund for order %s', 'stackless-lite-edd'), Orders::number($order)), 0, 255),
            'metadata' => ['edd_order_id' => (string) $orderId, 'edd_refund' => (string) $sequence],
        ];
        $refund = $this->api($this->orderMode($orderId))->request('POST', 'payments/' . rawurlencode($paymentId) . '/refunds', $body, 'stackless-edd-refund-' . $orderId . '-' . $sequence . '-' . Money::toMinor($amount, $currency));
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $status = (string) ($refund['status'] ?? '');
        if ($status === 'failed' || $status === 'canceled' || empty($refund['id'])) {
            return new \WP_Error('stackless_edd_mollie_refund', __('Mollie could not process this refund. Check the payment in your Mollie dashboard.', 'stackless-lite-edd'));
        }
        Sync::rememberRefund($orderId, (string) $refund['id']);
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Mollie refund id, 3: status */
            __('Refunded %1$s through Mollie (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, $currency),
            (string) $refund['id'],
            $status
        ));
        return ['id' => (string) $refund['id'], 'status' => $status];
    }

    /* ---- Subscriptions and saved-method contracts ------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        return (new Renewals($this))->charge($renewal_order, $subscription);
    }

    /** Stackless runs the schedule; nothing to cancel at Mollie. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_edd_charge_saved_method(Order $order, string $token_id, bool $on_session): array
    {
        return (new Renewals($this))->chargeSaved($order, $token_id, $on_session);
    }
}
