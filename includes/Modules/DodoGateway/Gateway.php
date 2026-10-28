<?php

namespace StacklessEDD\Modules\DodoGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Dodo Payments (merchant of record) for Easy Digital Downloads.
 *
 * Buyers pay on Dodo's hosted checkout; Dodo calculates and remits sales tax,
 * VAT and GST, and bills subscriptions itself. Orders, renewals, refunds and
 * cancellations stay in step through the browser return and signed webhooks.
 *
 * One instance per Dodo business: the original ("stackless_edd_dodo") and each copy
 * ("stackless_edd_dodo_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = 'stackless_edd_dodo';

    public const META_SESSION_ID = '_stackless_edd_dodo_session_id';
    public const META_CHECKOUT_URL = '_stackless_edd_dodo_checkout_url';
    public const META_SESSION_AT = '_stackless_edd_dodo_session_at';
    public const META_MODE = '_stackless_edd_dodo_mode';
    public const META_AMOUNT = '_stackless_edd_dodo_amount';
    public const META_PRODUCT_ID = '_stackless_edd_dodo_product_id';
    public const META_TAX_INCLUSIVE = '_stackless_edd_dodo_tax_inclusive';
    public const META_PAYMENT_ID = '_stackless_edd_dodo_payment_id';
    public const META_TAX = '_stackless_edd_dodo_tax_amount';
    public const META_TOTAL = '_stackless_edd_dodo_total_minor';
    public const META_REFUND_IDS = '_stackless_edd_dodo_refund_ids';
    public const META_AWAITING = '_stackless_edd_dodo_awaiting_renewal';
    public const META_NEXT_BILLING = '_stackless_edd_dodo_next_billing_date';
    public const META_CANCEL_AT_END = '_stackless_edd_dodo_cancel_at_next_billing';

    /** Business (brand) a key belongs to: BUSINESS_OPTION . mode . copy suffix. */
    public const BUSINESS_OPTION = 'stackless_edd_dodo_gateway_business_';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['exclusive', 'inclusive'];

    /** Tax categories Dodo accepts on a product. */
    public const TAX_CATEGORIES = ['digital_products', 'saas', 'e_book', 'edtech'];

    /** Dodo checkout sessions last 24 hours; an older one is not offered again. */
    public const SESSION_TTL = 82800;

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

    /** '' for the original, '_copy2' for copy 2: suffix of per-copy options. */
    public function suffix(): string
    {
        return Copies::suffix($this->gatewayId);
    }

    public function adminLabel(): string
    {
        $badge = Copies::badge($this->gatewayId);
        return __('Dodo Payments (Stackless)', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : '');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : self::defaultTitle();
    }

    public static function defaultTitle(): string
    {
        return __('Card, UPI, Apple Pay or Google Pay (Dodo Payments)', 'stackless-lite-edd');
    }

    public static function defaultDescription(): string
    {
        return __('You’ll finish paying on Dodo Payments’ secure checkout. Dodo adds any sales tax, VAT or GST for your location before you pay.', 'stackless-lite-edd');
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

    public function connectionLine(string $mode): string
    {
        $business = $this->business($mode);
        if (empty($business['name'])) {
            return '';
        }
        /* translators: %s: Dodo Payments business name */
        return sprintf(__('Connected to %s.', 'stackless-lite-edd'), '<strong>' . esc_html((string) $business['name']) . '</strong>') . ' ';
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless-edd/v1/dodo/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    public function webhookInstructions(): string
    {
        return sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('In Dodo Payments, open Developer → Webhooks, choose Add endpoint and paste this URL (switch to Test Mode first to add the test endpoint): %1$s Select these events, then copy the signing secret from the endpoint’s Overview tab into the webhook secret field for the same mode: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Dodo into EDD.', 'stackless-lite-edd'),
            '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        );
    }

    /** test|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a key or secret is pinned in wp-config.php (original gateway only). */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_EDD_DODO_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return $this->isOriginal() && defined($constant) && constant($constant);
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_EDD_DODO_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->setting($mode . '_' . $field, ''));
    }

    public function apiKey(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'api_key');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->apiKey($mode));
    }

    public function catalog(): Catalog
    {
        return new Catalog($this);
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = $orderId > 0 ? Orders::meta($orderId, self::META_MODE) : '';
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /** Mode a subscription bills in: its own record, else its parent order's. */
    public function subscriptionMode(SubscriptionData $subscription): string
    {
        $mode = (string) $subscription->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->orderMode($subscription->get_parent_order_id());
    }

    public function taxBehavior(): string
    {
        $behavior = (string) $this->setting('tax_behavior', 'exclusive');
        return in_array($behavior, self::TAX_BEHAVIORS, true) ? $behavior : 'exclusive';
    }

    public function taxInclusive(): bool
    {
        return $this->taxBehavior() === 'inclusive';
    }

    public function taxCategory(): string
    {
        $category = (string) $this->setting('tax_category', 'digital_products');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'digital_products';
    }

    public function businessOption(string $mode): string
    {
        return self::BUSINESS_OPTION . ($mode === 'test' ? 'test' : 'live') . $this->suffix();
    }

    /** @return array{id?:string,name?:string} The business (brand) the key belongs to. */
    public function business(string $mode): array
    {
        $business = get_option($this->businessOption($mode), []);
        return is_array($business) ? $business : [];
    }

    /** @return array{id:string,name:string}|\WP_Error */
    public function fetchBusiness(string $mode)
    {
        $response = $this->api($mode)->request('GET', 'brands');
        if (is_wp_error($response)) {
            return $response;
        }
        $remote = (array) ($response['items'][0] ?? ($response[0] ?? []));
        if (empty($remote['business_id'])) {
            return new \WP_Error('stackless_edd_dodo_business', __('Dodo Payments did not return a business for this API key.', 'stackless-lite-edd'));
        }
        $business = [
            'id' => (string) $remote['business_id'],
            'name' => (string) (($remote['name'] ?? '') ?: ($remote['statement_descriptor'] ?? '')),
        ];
        update_option($this->businessOption($mode), $business, false);
        return $business;
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('dodo_gateway', ($this->isOriginal() ? '' : '[' . $this->gatewayId . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->apiKey() === '') {
            return false;
        }
        if (!Money::supported(stackless_edd_current_currency())) {
            return false;
        }
        return Eligibility::cartReason() === Eligibility::OK;
    }

    public function fields(): void
    {
        stackless_edd_get_template('dodo_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'testmode' => $this->mode() === 'test',
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

        try {
            $url = (new Checkout($this))->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_dodo_checkout', __('Dodo Payments could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            stackless_edd_add_order_note($orderId, sprintf(__('Dodo Payments checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->fail($url->get_error_message(), $orderId);
            return;
        }
        // Dodo's hosted checkout is on its own domain, so wp_safe_redirect() would refuse it.
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checked by Checkout::isCheckoutUrl().
        exit;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Dodo charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Dodo's payment webhook arrives.
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
        stackless_edd_add_order_note($orderId, __('Dodo Payments bills this subscription itself. This order is marked paid when Dodo reports the renewal charge.', 'stackless-lite-edd'));
        return ['result' => 'pending', 'message' => __('Waiting for Dodo Payments to charge the renewal.', 'stackless-lite-edd')];
    }

    /** Cancel at Dodo: stop renewing at the next billing date, or cancel now. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        if (Subscriptions::$applying) {
            return true; // Dodo reported this change itself; nothing to send back.
        }
        $dodoId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($dodoId === '') {
            return true; // Never reached Dodo, nothing to stop there.
        }
        $api = $this->api($this->subscriptionMode($subscription));
        $path = 'subscriptions/' . rawurlencode($dodoId);
        $remote = $api->request('GET', $path);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $dodoId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (in_array($remote['status'] ?? '', ['cancelled', 'expired', 'failed'], true)) {
            return true;
        }
        if ($at_period_end && !empty($remote['cancel_at_next_billing_date'])) {
            return true;
        }
        $result = $api->request('PATCH', $path, $at_period_end ? ['cancel_at_next_billing_date' => true] : ['status' => 'cancelled']);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $dodoId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        Subscriptions::note($subscription, $at_period_end
            ? __('Dodo Payments subscription set to stop at the next billing date.', 'stackless-lite-edd')
            : __('Dodo Payments subscription cancelled immediately.', 'stackless-lite-edd'));
        return true;
    }
}
