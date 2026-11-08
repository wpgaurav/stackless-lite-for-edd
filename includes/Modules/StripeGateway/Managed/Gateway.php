<?php

namespace StacklessEDD\Modules\StripeGateway\Managed;

use EDD\Orders\Order;
use StacklessEDD\Contracts\PausableGateway;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\LiveStore;
use StacklessEDD\Modules\StripeGateway\Amount;
use StacklessEDD\Modules\StripeGateway\Orders;
use StacklessEDD\Modules\StripeGateway\Payments;
use StacklessEDD\Modules\StripeGateway\Settings;
use StacklessEDD\Modules\StripeGateway\StripeApiException;
use StacklessEDD\Modules\StripeGateway\StripeClient;

defined('ABSPATH') || exit;

/**
 * Stripe Managed Payments (2.0.0): Stripe as merchant of record for digital downloads.
 *
 * Stripe calculates, collects and remits sales tax and VAT, handles disputes and fraud, and
 * bills subscriptions on Stripe Billing. After the order is placed the buyer pays in an
 * embedded Checkout Session on this store's own pay page; the browser return and signed
 * webhooks (the Stripe module's endpoint) keep orders, renewals, refunds, pauses and
 * cancellations in step. Reuses the Stripe module's keys, client, idempotency keys and event
 * log. One Stripe account per site, so it is not copyable.
 */
class Gateway extends BaseGateway implements SubscriptionGateway, PausableGateway
{
    /**
     * EDD gateway id. Short on purpose: EDD's order transactions table keeps the gateway in a
     * 20-character column and refuses longer ids, so a longer id would record no transactions.
     */
    public const ID = 'stackless_edd_smp';

    /** Managed Payments needs 2025-03-31.basil or later (docs.stripe.com/payments/managed-payments/set-up). */
    public const API_VERSION = '2025-03-31.basil';

    public const META_SESSION = '_stackless_edd_stripe_managed_session_id';
    public const META_FINGERPRINT = '_stackless_edd_stripe_managed_fingerprint';
    public const META_TAX = '_stackless_edd_stripe_managed_tax';
    public const META_TOTAL = '_stackless_edd_stripe_managed_total';
    public const META_INVOICE = '_stackless_edd_stripe_managed_invoice_id';
    public const META_AWAITING = '_stackless_edd_stripe_managed_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_edd_stripe_managed_period_end';
    public const META_CANCEL_AT_END = '_stackless_edd_stripe_managed_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    /** Default tax code: General - Electronically Supplied Services, eligible for Managed Payments. */
    public const DEFAULT_TAX_CODE = 'txcd_10000000';

    /** A few eligible tax codes (docs.stripe.com/payments/managed-payments/eligibility). */
    public const TAX_CODES = [
        'txcd_10000000', 'txcd_10103000', 'txcd_10103001', 'txcd_10103100', 'txcd_10103101',
        'txcd_10202000', 'txcd_10202003', 'txcd_10701100', 'txcd_20060058',
    ];

    /** Option prefix of the gateway's settings, inside the Stripe module's settings. */
    public const PREFIX = Settings::PREFIX . 'managed_';

    /** @var Gateway|null */
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            $registered = Gateways::get(self::ID);
            self::$instance = $registered instanceof self ? $registered : new self();
        }
        return self::$instance;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Stripe Managed Payments (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : self::defaultTitle();
    }

    public static function defaultTitle(): string
    {
        return __('Card, Apple Pay, Google Pay or Link', 'stackless-lite-edd');
    }

    public static function defaultDescription(): string
    {
        return __('You’ll pay securely with Stripe on the next step. Sales tax or VAT for your location is added before you pay.', 'stackless-lite-edd');
    }

    public function description(): string
    {
        $stored = get_option(self::PREFIX . 'description', null);
        return $stored === null || $stored === false ? self::defaultDescription() : (string) $stored;
    }

    /** @param mixed $default @return mixed */
    public function setting(string $key, $default = '')
    {
        $value = get_option(self::PREFIX . $key, null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ---- Settings -------------------------------------------------------------- */

    /** @return array<string,string> */
    public static function taxCodeOptions(): array
    {
        return [
            'txcd_10000000' => __('txcd_10000000 · Electronically supplied services (general)', 'stackless-lite-edd'),
            'txcd_10103000' => __('txcd_10103000 · SaaS, personal use', 'stackless-lite-edd'),
            'txcd_10103001' => __('txcd_10103001 · SaaS, business use', 'stackless-lite-edd'),
            'txcd_10103100' => __('txcd_10103100 · SaaS, electronic download, personal use', 'stackless-lite-edd'),
            'txcd_10103101' => __('txcd_10103101 · SaaS, electronic download, business use', 'stackless-lite-edd'),
            'txcd_10202000' => __('txcd_10202000 · Downloadable software, personal use', 'stackless-lite-edd'),
            'txcd_10202003' => __('txcd_10202003 · Downloadable software, business use', 'stackless-lite-edd'),
            'txcd_10701100' => __('txcd_10701100 · Website hosting', 'stackless-lite-edd'),
            'txcd_20060058' => __('txcd_20060058 · Training, self-study web-based', 'stackless-lite-edd'),
        ];
    }

    /** Fields for the Stripe module's settings screen. @return array<int,array<string,mixed>> */
    public static function settingsFields(): array
    {
        $p = self::PREFIX;
        return [
            [
                'type' => 'title',
                'id' => $p . 'section',
                'title' => __('Stripe Managed Payments', 'stackless-lite-edd'),
                'desc' => self::limitsText(),
            ],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite-edd'), 'default' => self::defaultTitle(), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite-edd')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite-edd'), 'default' => self::defaultDescription()],
            [
                'type' => 'select',
                'id' => $p . 'tax_code',
                'title' => __('Stripe tax code', 'stackless-lite-edd'),
                'default' => self::DEFAULT_TAX_CODE,
                'options' => self::taxCodeOptions(),
                'desc' => __('Managed Payments needs an eligible tax code on every download. Pick the one that describes what you sell; the stackless_edd_stripe_managed_tax_code filter can set one per order.', 'stackless-lite-edd'),
            ],
            [
                'type' => 'select',
                'id' => $p . 'tax_behavior',
                'title' => __('Prices and tax', 'stackless-lite-edd'),
                'default' => 'exclusive',
                'options' => [
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite-edd'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite-edd'),
                ],
                'desc' => __('Stripe is the merchant of record and calculates sales tax and VAT itself. Turn off EDD taxes (Downloads → Settings → Taxes) while you sell with Managed Payments, so buyers are not taxed twice.', 'stackless-lite-edd'),
            ],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public static function limitsText(): string
    {
        return implode(' ', [
            __('Stripe as merchant of record for digital downloads: Stripe calculates and remits sales tax and VAT, handles disputes, and bills subscriptions on Stripe Billing. Buyers pay in Stripe Checkout, embedded on your store. Enable it in Downloads → Settings → Payments.', 'stackless-lite-edd'),
            sprintf(
                /* translators: %s: link to Stripe Dashboard settings */
                __('Turn on Managed Payments in your Stripe Dashboard first (%s); Stripe reviews each account. It uses the API keys and webhook above.', 'stackless-lite-edd'),
                '<a href="https://dashboard.stripe.com/settings/managed-payments" target="_blank" rel="noopener">' . esc_html__('Settings → Managed Payments', 'stackless-lite-edd') . '</a>'
            ),
            __('Digital goods only: services and in-person events are not eligible, so the method hides when the cart holds a service download. One subscription schedule per checkout; one-time items and signup fees are added to the first invoice. Klarna, Afterpay and Bancontact take one-time payments only.', 'stackless-lite-edd'),
            __('Only new subscriptions are covered: subscriptions started with another method keep renewing there. Stripe bills renewals itself; renewal orders are marked paid when Stripe reports them. Customers update their card in the Stripe customer portal.', 'stackless-lite-edd'),
        ]);
    }

    public function taxCode(?Order $order = null): string
    {
        $code = (string) $this->setting('tax_code', self::DEFAULT_TAX_CODE);
        $code = preg_match('/^txcd_\d{8}$/', $code) ? $code : self::DEFAULT_TAX_CODE;
        $filtered = (string) apply_filters('stackless_edd_stripe_managed_tax_code', $code, $order);
        return preg_match('/^txcd_\d{8}$/', $filtered) ? $filtered : $code;
    }

    public function taxBehavior(): string
    {
        return $this->setting('tax_behavior', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    }

    public static function apiVersion(): string
    {
        $version = (string) apply_filters('stackless_edd_stripe_managed_api_version', self::API_VERSION);
        return preg_match('/^\d{4}-\d{2}-\d{2}(\.[a-z]+)?$/', $version) ? $version : self::API_VERSION;
    }

    /** Client in a mode, pinned to the Managed Payments API version. */
    public static function client(?string $mode = null): StripeClient
    {
        return Settings::versionedClient(self::apiVersion(), $mode);
    }

    /** Mode an order was paid in, or '' when it never reached Stripe. */
    public static function orderMode(int $order_id): string
    {
        $mode = (string) Orders::meta($order_id, Payments::META_MODE);
        return $mode === 'live' || $mode === 'test' ? $mode : '';
    }

    /** Client for the mode an order was paid in. */
    public static function clientForOrder(int $order_id): StripeClient
    {
        $mode = self::orderMode($order_id);
        return self::client($mode !== '' ? $mode : null);
    }

    /** Client for the mode a subscription bills in (its own record, else its parent order's). */
    public static function clientForSubscription(SubscriptionData $subscription): StripeClient
    {
        $mode = (string) $subscription->get_meta(Payments::META_MODE);
        if ($mode !== 'live' && $mode !== 'test') {
            $mode = self::orderMode($subscription->get_parent_order_id());
        }
        return self::client($mode !== '' ? $mode : null);
    }

    /** @return string[] */
    public function supported_currencies(): array
    {
        return Amount::SUPPORTED;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if (!Settings::isConfigured()) {
            return false;
        }
        // Stripe charges renewals itself; a renewal or switch order is never paid here a second time.
        if (self::resumedOrder() > 0) {
            return false;
        }
        if (!Amount::isSupported(stackless_edd_current_currency())) {
            return false;
        }
        if (!function_exists('edd_get_cart_contents')) {
            return true;
        }
        if (Eligibility::cartReason() !== Eligibility::OK) {
            return false;
        }
        if (function_exists('edd_get_cart_total') && (float) edd_get_cart_total() <= 0 && !stackless_edd_cart_contains_subscription()) {
            return false;
        }
        return true;
    }

    /** The renewal or switch order this checkout pays through its recovery link, or 0. */
    public static function resumedOrder(): int
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return 0;
        }
        $order_id = absint(EDD()->session->get('edd_resume_payment'));
        if ($order_id <= 0) {
            return 0;
        }
        return Orders::meta($order_id, SubscriptionData::META_RENEWAL_OF) || Orders::meta($order_id, SubscriptionData::META_SWITCH_OF) ? $order_id : 0;
    }

    public function fields(): void
    {
        $description = $this->description();
        echo '<div class="stackless-stripe-managed-fields">';
        if ($description !== '') {
            echo wp_kses_post(wpautop(wptexturize($description)));
        }
        if (Settings::isTestMode()) {
            echo '<p class="stackless-stripe-test-notice">' . esc_html__('Test mode: no real payment will be taken.', 'stackless-lite-edd') . '</p>';
        }
        echo '</div>';
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $reason = Eligibility::cartReason();
        if ($reason !== Eligibility::OK) {
            $this->fail(Eligibility::message($reason));
            return;
        }
        if (self::resumedOrder() > 0) {
            $this->fail(__('Stripe collects this renewal itself. Choose another payment method or wait for Stripe to retry the charge.', 'stackless-lite-edd'));
            return;
        }
        $order_id = $this->createPendingOrder($purchase_data);
        $order = $order_id ? Orders::get($order_id) : null;
        if (!$order) {
            return;
        }
        $reason = Eligibility::orderReason($order);
        if ($reason !== Eligibility::OK) {
            $this->fail(Eligibility::message($reason), $order_id);
            return;
        }
        $url = $this->startCheckout($order);
        if (is_wp_error($url)) {
            /* translators: %s: error */
            stackless_edd_add_order_note($order_id, sprintf(__('Stripe Managed Payments checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->fail($url->get_error_message());
            return;
        }
        wp_safe_redirect($url);
        exit;
    }

    /** @return string|\WP_Error */
    public function startCheckout(Order $order)
    {
        try {
            return (new Checkout($this))->start($order);
        } catch (StripeApiException $e) {
            Settings::log('Managed Payments checkout for order ' . $order->id . ' failed: ' . $e->logSummary(), 'error');
            return new \WP_Error('stackless_edd_stripe_managed', self::apiErrorMessage($e));
        } catch (\Throwable $e) {
            Settings::log('Managed Payments checkout for order ' . $order->id . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_Error('stackless_edd_stripe_managed', __('Stripe could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
    }

    public static function apiErrorMessage(StripeApiException $e): string
    {
        if ($e->getStripeType() === 'invalid_request_error' && stripos($e->getMessage(), 'managed') !== false) {
            return __('Stripe Managed Payments is not available on this Stripe account yet. Choose another payment method.', 'stackless-lite-edd');
        }
        return __('Stripe could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd');
    }

    public static function setGateway(Order $order): void
    {
        if ((string) $order->gateway !== self::ID) {
            edd_update_order((int) $order->id, ['gateway' => self::ID]);
        }
    }

    /* ---- Contracts\SubscriptionGateway ------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Stripe Billing charges renewals on its own schedule, so nothing is charged here. The
     * renewal order is parked and completed when Stripe's invoice.paid webhook arrives.
     */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        if (Orders::isPaid($renewal_order)) {
            return ['result' => 'success'];
        }
        $order_id = (int) $renewal_order->id;
        self::setGateway($renewal_order);
        Orders::updateMeta($order_id, self::META_AWAITING, 'yes');
        $subscription->update_meta(self::META_AWAITING, (string) $order_id);
        stackless_edd_add_order_note($order_id, __('Stripe bills this subscription itself. This order is marked paid when Stripe reports the renewal charge.', 'stackless-lite-edd'));
        return ['result' => 'pending', 'message' => __('Waiting for Stripe to charge the renewal.', 'stackless-lite-edd')];
    }

    /** Cancel at Stripe: at the end of the billing period, or immediately. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        if (Sync::$applying) {
            return true; // Stripe reported this change itself; nothing to send back.
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return true; // Never reached Stripe, nothing to stop there.
        }
        if (!LiveStore::isLive()) {
            return false;
        }
        $client = self::clientForSubscription($subscription);
        try {
            $remote = $client->get('subscriptions/' . rawurlencode($remote_id));
            $status = (string) ($remote['status'] ?? '');
            if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
                return true;
            }
            $deferred = $at_period_end && in_array($status, ['active', 'trialing'], true);
            if ($deferred) {
                if (empty($remote['cancel_at_period_end'])) {
                    $client->post('subscriptions/' . rawurlencode($remote_id), ['cancel_at_period_end' => 'true'], 'stackless-edd-managed-cancel-end-' . $remote_id);
                }
            } else {
                $client->delete('subscriptions/' . rawurlencode($remote_id));
            }
        } catch (StripeApiException $e) {
            Settings::log('Cancel of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        $subscription->update_meta(self::META_CANCEL_AT_END, $deferred ? 'yes' : 'no');
        Sync::note($subscription, $deferred
            ? __('Stripe subscription set to end at the close of the paid period.', 'stackless-lite-edd')
            : __('Stripe subscription canceled immediately.', 'stackless-lite-edd'));
        return true;
    }

    /* ---- Contracts\PausableGateway ----------------------------------------------- */

    /**
     * Pauses collection at Stripe: invoices Stripe would create while paused are voided, so the
     * customer is not charged, and collection resumes on $resume_at (or when resumed by hand).
     */
    public function stackless_edd_pause_subscription(SubscriptionData $subscription, ?int $resume_at): bool
    {
        if (!LiveStore::isLive()) {
            return false;
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return false;
        }
        $pause = ['behavior' => 'void'];
        if ($resume_at !== null && $resume_at > time()) {
            $pause['resumes_at'] = $resume_at;
        }
        try {
            self::clientForSubscription($subscription)->post('subscriptions/' . rawurlencode($remote_id), ['pause_collection' => $pause], 'stackless-edd-managed-pause-' . $remote_id . '-' . ($resume_at ?? 0) . '-' . gmdate('YmdH'));
        } catch (StripeApiException $e) {
            Settings::log('Pause of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        Sync::note($subscription, $resume_at
            /* translators: %s: date */
            ? sprintf(__('Stripe paused collection until %s.', 'stackless-lite-edd'), wp_date((string) get_option('date_format'), $resume_at))
            : __('Stripe paused collection until the subscription is resumed.', 'stackless-lite-edd'));
        return true;
    }

    public function stackless_edd_resume_subscription(SubscriptionData $subscription): bool
    {
        if (!LiveStore::isLive()) {
            return false;
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return false;
        }
        try {
            // An empty pause_collection clears it; Stripe bills again from the next period.
            self::clientForSubscription($subscription)->post('subscriptions/' . rawurlencode($remote_id), ['pause_collection' => ''], 'stackless-edd-managed-resume-' . $remote_id . '-' . gmdate('YmdH'));
        } catch (StripeApiException $e) {
            Settings::log('Resume of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        Sync::note($subscription, __('Stripe resumed collection.', 'stackless-lite-edd'));
        return true;
    }
}
