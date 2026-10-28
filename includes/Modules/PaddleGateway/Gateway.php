<?php

namespace StacklessEDD\Modules\PaddleGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Paddle Billing (merchant of record) for Easy Digital Downloads.
 *
 * Buyers pay in Paddle's overlay checkout, opened over the EDD checkout (or, without
 * JavaScript and for pay links, on a page of this store); Paddle calculates and remits
 * sales tax and VAT, and runs subscription billing itself. Orders, renewals, refunds and
 * cancellations stay in step through the browser return and signed webhooks.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = 'stackless_edd_paddle';

    public const META_TRANSACTION_ID = '_stackless_edd_paddle_transaction_id';
    public const META_MODE = '_stackless_edd_paddle_mode';
    public const META_FINGERPRINT = '_stackless_edd_paddle_fingerprint';
    public const META_ADJUSTMENT_IDS = '_stackless_edd_paddle_adjustment_ids';
    public const META_TAX = '_stackless_edd_paddle_tax_amount';
    public const META_TOTAL = '_stackless_edd_paddle_total_amount';
    public const META_AWAITING = '_stackless_edd_paddle_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_edd_paddle_current_period_end';
    public const META_CANCEL_AT_END = '_stackless_edd_paddle_cancel_at_period_end';
    public const META_SYNCED_AT = '_stackless_edd_paddle_synced_at';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_MODES = ['account_setting', 'external', 'internal'];

    /** Paddle tax categories. Everything but "standard" needs approval in the Paddle account first. */
    public const TAX_CATEGORIES = ['standard', 'digital-goods', 'ebooks', 'implementation-services', 'professional-services', 'saas', 'software-programming-services', 'training-services', 'website-hosting'];

    /** POST flag the checkout script adds when it opens the overlay over the checkout page. */
    public const INLINE_FLAG = 'stackless_edd_paddle_inline';

    /** @var array<string,Gateway> */
    private static $instances = [];

    /** @var string */
    private $gatewayId;

    public static function instance(string $id = self::ID): self
    {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = new self($id);
        }
        return self::$instances[$id];
    }

    /** The original gateway and every copy. @return Gateway[] */
    public static function all(): array
    {
        return array_map([self::class, 'instance'], Copies::ids());
    }

    /** The gateway (original or copy) with this EDD gateway id, or null. */
    public static function forId(string $id): ?self
    {
        return Copies::belongs($id) ? self::instance($id) : null;
    }

    /** The gateway (original or copy) an order was placed with. */
    public static function forOrder(Order $order): ?self
    {
        return self::forId((string) $order->gateway);
    }

    public function __construct(string $id = self::ID)
    {
        $this->gatewayId = $id;
    }

    public function id(): string
    {
        return $this->gatewayId;
    }

    public function isOriginal(): bool
    {
        return $this->gatewayId === self::ID;
    }

    public function adminLabel(): string
    {
        $badge = Copies::badge($this->gatewayId);
        return __('Paddle (Stackless)', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : '');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : self::defaultTitle();
    }

    public static function defaultTitle(): string
    {
        return __('Card, PayPal, Apple Pay or Google Pay (Paddle)', 'stackless-lite-edd');
    }

    public static function defaultDescription(): string
    {
        return __('You’ll finish paying in Paddle’s secure checkout. Paddle adds any sales tax or VAT for your location before you pay.', 'stackless-lite-edd');
    }

    public function description(): string
    {
        $stored = get_option(Settings::id('description', $this->gatewayId), null);
        return $stored === null || $stored === false ? self::defaultDescription() : (string) $stored;
    }

    /* ---- Settings -------------------------------------------------------------- */

    /** @param mixed $default @return mixed */
    public function setting(string $key, $default = '')
    {
        $value = get_option(Settings::id($key, $this->gatewayId), null);
        return $value === null || $value === false ? $default : $value;
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless-edd/v1/paddle/webhook' . ($this->isOriginal() ? '' : '/' . ltrim(Copies::suffix($this->gatewayId), '_')));
    }

    public function setupInstructions(): string
    {
        return sprintf(
            /* translators: 1: store domain, 2: store URL, 3: webhook URL, 4: event names */
            __('1. In Paddle → Settings → Website approval, add %1$s and wait for approval. 2. In Paddle → Checkout → Checkout configuration, set the default payment link to %2$s (Stackless sends its own checkout page with every payment; Paddle only needs an approved default). 3. Use Set up the webhook in Paddle above (live and sandbox each have one). To do it by hand instead, open Paddle → Notifications, add a destination with this URL: %3$s Select these events, then copy the secret key into the matching webhook secret field above: %4$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Paddle into EDD.', 'stackless-lite-edd'),
            '<code>' . esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '</code>',
            '<code>' . esc_html(home_url('/')) . '</code>',
            '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        );
    }

    /** sandbox|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'sandbox' : 'live';
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'sandbox' ? 'sandbox' : 'live';
        $constant = 'STACKLESS_EDD_PADDLE_' . strtoupper($mode) . '_' . strtoupper($field);
        // Constants in wp-config.php belong to the original gateway; copies keep their own keys.
        if ($this->isOriginal() && defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->setting($mode . '_' . $field, ''));
    }

    public function apiKey(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'api_key');
    }

    public function clientToken(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'client_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'sandbox' ? $mode : $this->mode();
        return new Api($mode, $this->apiKey($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = $orderId > 0 ? Orders::meta($orderId, self::META_MODE) : '';
        return in_array($mode, ['live', 'sandbox'], true) ? $mode : $this->mode();
    }

    /** Mode a subscription bills in: its own record, else its parent order's. */
    public function subscriptionMode(SubscriptionData $subscription): string
    {
        $mode = (string) $subscription->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'sandbox'], true) ? $mode : $this->orderMode($subscription->get_parent_order_id());
    }

    public function taxMode(): string
    {
        $mode = (string) $this->setting('tax_mode', 'account_setting');
        return in_array($mode, self::TAX_MODES, true) ? $mode : 'account_setting';
    }

    public function taxCategory(): string
    {
        $category = (string) $this->setting('tax_category', 'standard');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'standard';
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function dashboardUrl(string $mode, string $path): string
    {
        return ($mode === 'sandbox' ? 'https://sandbox-vendors.paddle.com/' : 'https://vendors.paddle.com/') . ltrim($path, '/');
    }

    public function transactionUrl(int $orderId): string
    {
        $transaction = Orders::meta($orderId, self::META_TRANSACTION_ID);
        return $transaction !== '' ? $this->dashboardUrl($this->orderMode($orderId), 'transactions-v2/' . rawurlencode($transaction)) : '';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('paddle_gateway', ($this->isOriginal() ? '' : '[' . $this->gatewayId . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->apiKey() === '' || $this->clientToken() === '') {
            return false;
        }
        if (!Money::supported(stackless_edd_current_currency())) {
            return false;
        }
        return Eligibility::cartReason() === Eligibility::OK;
    }

    public function fields(): void
    {
        stackless_edd_get_template('paddle_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'sandbox' => $this->mode() === 'sandbox',
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $reason = Eligibility::cartReason();
        if ($reason !== Eligibility::OK) {
            $this->fail(Eligibility::message($reason));
            return;
        }
        $orderId = $this->createPendingOrder($purchase_data);
        $order = $orderId ? Orders::get($orderId) : null;
        if (!$order) {
            return;
        }

        $reason = Eligibility::orderReason($order);
        if ($reason !== Eligibility::OK) {
            $this->fail(Eligibility::message($reason), $orderId);
            return;
        }

        $checkout = new Checkout($this);
        try {
            $url = $checkout->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_paddle_checkout', __('Paddle could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            stackless_edd_add_order_note($orderId, sprintf(__('Paddle checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->fail($url->get_error_message(), $orderId);
            return;
        }

        if (self::inline()) {
            $order = Orders::get($orderId) ?: $order;
            $config = (array) apply_filters('stackless_edd_paddle_gateway_checkout_settings', $checkout->overlayConfig($order), $order);
            wp_send_json_success(['payUrl' => $url] + $config);
        }
        wp_safe_redirect($url);
        exit;
    }

    /** True when the checkout script asked for the overlay config instead of a redirect. */
    public static function inline(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form nonce.
        return !empty($_POST[self::INLINE_FLAG]) && !empty($_POST['edd_action']) && $_POST['edd_action'] === 'purchase';
    }

    /** fail() ends here too: the checkout script reloads checkout, where EDD prints the errors kept in the session. */
    protected function back(): void
    {
        if (!self::inline()) {
            parent::back();
            return;
        }
        wp_send_json_error(['redirect' => add_query_arg(['payment-mode' => $this->gatewayId], edd_get_checkout_uri())]);
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Paddle charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Paddle's transaction webhook arrives.
     */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        if (Orders::isPaid($renewal_order)) {
            return ['result' => 'success'];
        }
        $orderId = (int) $renewal_order->id;
        Orders::setGateway($renewal_order, $this->gatewayId);
        Orders::setMeta($orderId, self::META_AWAITING, 'yes');
        $subscription->update_meta(self::META_AWAITING, (string) $orderId);
        stackless_edd_add_order_note($orderId, __('Paddle bills this subscription itself. This order is marked paid when Paddle reports the renewal charge.', 'stackless-lite-edd'));
        return ['result' => 'pending', 'message' => __('Waiting for Paddle to charge the renewal.', 'stackless-lite-edd')];
    }

    /** Cancel at Paddle: at the end of the billing period, or immediately. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        if (Subscriptions::$applying) {
            return true; // Paddle reported this change itself; nothing to send back.
        }
        $paddleId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($paddleId === '') {
            return true; // Never reached Paddle, nothing to stop there.
        }
        $api = $this->api($this->subscriptionMode($subscription));
        $remote = $api->request('GET', 'subscriptions/' . rawurlencode($paddleId));
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $paddleId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (($remote['status'] ?? '') === 'canceled') {
            return true;
        }
        if ($at_period_end && EventMap::cancelScheduled($remote)) {
            return true;
        }
        // Paddle cannot schedule a cancellation for a paused or past-due subscription, only end it now.
        $deferred = $at_period_end && in_array($remote['status'] ?? '', ['active', 'trialing'], true);
        $result = $api->request('POST', 'subscriptions/' . rawurlencode($paddleId) . '/cancel', [
            'effective_from' => $deferred ? 'next_billing_period' : 'immediately',
        ]);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $paddleId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta(self::META_CANCEL_AT_END, $deferred ? 'yes' : 'no');
        Subscriptions::note($subscription, $deferred
            ? __('Paddle subscription set to end at the close of the paid period.', 'stackless-lite-edd')
            : __('Paddle subscription canceled immediately.', 'stackless-lite-edd'));
        return true;
    }
}
