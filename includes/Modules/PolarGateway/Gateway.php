<?php

namespace StacklessEDD\Modules\PolarGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Polar (merchant of record) for Easy Digital Downloads.
 *
 * Buyers pay on Polar's hosted checkout; Polar calculates and remits sales tax
 * and VAT, and runs subscription billing itself. Orders, renewals, refunds and
 * cancellations stay in step through the browser return and signed webhooks.
 *
 * One instance per Polar organization: the original ("stackless_edd_polar") and each
 * copy ("stackless_edd_polar_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = 'stackless_edd_polar';
    public const OPTION_PREFIX = 'stackless_edd_polar_gateway_';

    /** Settings stored per gateway: OPTION_PREFIX . key . suffix ('' or '_copyN'). */
    public const SETTING_KEYS = ['title', 'description', 'testmode', 'live_access_token', 'live_webhook_secret', 'sandbox_access_token', 'sandbox_webhook_secret', 'tax_behavior', 'debug'];

    public const META_CHECKOUT_ID = '_stackless_edd_polar_checkout_id';
    public const META_ORDER_ID = '_stackless_edd_polar_order_id';
    public const META_MODE = '_stackless_edd_polar_mode';
    public const META_AMOUNT = '_stackless_edd_polar_amount';
    public const META_REFUND_IDS = '_stackless_edd_polar_refund_ids';
    public const META_TAX = '_stackless_edd_polar_tax_amount';
    public const META_TOTAL = '_stackless_edd_polar_total_amount';
    public const META_AWAITING = '_stackless_edd_polar_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_edd_polar_current_period_end';
    public const META_CANCEL_AT_END = '_stackless_edd_polar_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['location', 'exclusive', 'inclusive'];

    /** @var array<string,Gateway> */
    private static $instances = [];

    /** @var bool True while Polar sync applies a change Polar itself made (nothing to send back). */
    private static $fromProvider = false;

    /** @var string */
    protected $gatewayId;

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

    /** The gateway (original or copy) an order was placed with. */
    public static function forOrder(Order $order): ?self
    {
        $id = (string) $order->gateway;
        return Copies::belongs($id) ? self::instance($id) : null;
    }

    public function __construct(string $id = self::ID)
    {
        $this->gatewayId = $id;
    }

    public function id(): string
    {
        return $this->gatewayId;
    }

    public function adminLabel(): string
    {
        $badge = Copies::badge($this->gatewayId);
        return __('Polar (Stackless)', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : '');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : __('Card, Apple Pay or Google Pay (Polar)', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->setting('description', __('You’ll finish paying on Polar’s secure checkout. Polar adds any sales tax or VAT for your location before you pay.', 'stackless-lite-edd'));
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

    public function optionName(string $key): string
    {
        return self::OPTION_PREFIX . $key . $this->suffix();
    }

    /** @return mixed */
    public function setting(string $key, $default = '')
    {
        $value = get_option($this->optionName($key), null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ---- Settings -------------------------------------------------------------- */

    /** This gateway's block on Stackless → Settings → Polar (the original, then each copy). */
    public function settingsFields(string $copiesPanel = ''): array
    {
        $scopes = 'products:read, products:write, checkouts:read, checkouts:write, orders:read, refunds:read, refunds:write, subscriptions:read, subscriptions:write, organizations:read';
        $badge = Copies::badge($this->gatewayId);
        $section = $this->optionName('section');
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');

        $fields = [
            [
                'id' => $section,
                'type' => 'title',
                /* translators: %s: copy badge, for example "Copy 2" */
                'title' => $badge !== '' ? sprintf(__('Polar · %s', 'stackless-lite-edd'), $badge) : __('Polar', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: gateway name, 2: link to the EDD payment gateways screen */
                    __('Turn on %1$s in %2$s once the access token and webhook secret below are saved.', 'stackless-lite-edd'),
                    '<strong>' . esc_html($this->adminLabel()) . '</strong>',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            [
                'id' => $this->optionName('title'),
                'type' => 'text',
                'title' => __('Title', 'stackless-lite-edd'),
                'default' => __('Card, Apple Pay or Google Pay (Polar)', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('description'),
                'type' => 'textarea',
                'title' => __('Description', 'stackless-lite-edd'),
                'default' => __('You’ll finish paying on Polar’s secure checkout. Polar adds any sales tax or VAT for your location before you pay.', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('testmode'),
                'type' => 'checkbox',
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'desc' => __('Use the Polar sandbox (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => $this->optionName('tax_behavior'),
                'type' => 'select',
                'title' => __('Prices and tax', 'stackless-lite-edd'),
                'default' => 'location',
                'options' => [
                    'location' => __('Follow the buyer’s country (Polar default)', 'stackless-lite-edd'),
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite-edd'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite-edd'),
                ],
                'desc' => __('Polar is the merchant of record and calculates sales tax and VAT itself. Turn off EDD taxes for downloads sold through Polar so buyers are not taxed twice.', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('debug'),
                'type' => 'checkbox',
                'title' => __('Debug log', 'stackless-lite-edd'),
                'desc' => __('Log Polar activity to Downloads → Tools → Debug Log (stackless-edd-polar_gateway) while EDD debug mode is on. Tokens are never logged.', 'stackless-lite-edd'),
                'default' => 'no',
            ],
            ['id' => $section, 'type' => 'sectionend'],

            [
                'id' => $this->optionName('live_section'),
                'type' => 'title',
                'title' => __('Live', 'stackless-lite-edd'),
                /* translators: %s: list of token scopes */
                'desc' => $this->connectionLine('live') . sprintf(__('Create an organization access token in Polar → Settings → General → Developers with these scopes: %s', 'stackless-lite-edd'), '<code>' . esc_html($scopes) . '</code>'),
            ],
            ['id' => $this->optionName('live_access_token'), 'type' => 'password', 'title' => __('Live access token', 'stackless-lite-edd'), 'placeholder' => 'polar_oat_…', 'default' => ''],
            ['id' => $this->optionName('live_webhook_secret'), 'type' => 'password', 'title' => __('Live webhook secret', 'stackless-lite-edd'), 'placeholder' => 'whsec_…', 'default' => ''],
            ['id' => $this->optionName('live_section'), 'type' => 'sectionend'],

            [
                'id' => $this->optionName('sandbox_section'),
                'type' => 'title',
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'desc' => $this->connectionLine('sandbox') . __('Same scopes, created at sandbox.polar.sh.', 'stackless-lite-edd'),
            ],
            ['id' => $this->optionName('sandbox_access_token'), 'type' => 'password', 'title' => __('Sandbox access token', 'stackless-lite-edd'), 'placeholder' => 'polar_oat_…', 'default' => ''],
            ['id' => $this->optionName('sandbox_webhook_secret'), 'type' => 'password', 'title' => __('Sandbox webhook secret', 'stackless-lite-edd'), 'placeholder' => 'whsec_…', 'default' => ''],
            ['id' => $this->optionName('sandbox_section'), 'type' => 'sectionend'],

            [
                'id' => $this->optionName('webhook_section'),
                'type' => 'title',
                'title' => __('Webhook', 'stackless-lite-edd'),
                'desc' => $this->webhookInstructions(),
            ],
            ['id' => $this->optionName('webhook_section'), 'type' => 'sectionend'],
        ];

        if ($copiesPanel !== '') {
            $fields[] = ['id' => $this->optionName('copies_section'), 'type' => 'title', 'title' => __('Copies', 'stackless-lite-edd')];
            $fields[] = ['type' => Module::COPIES_FIELD, 'html' => $copiesPanel];
            $fields[] = ['id' => $this->optionName('copies_section'), 'type' => 'sectionend'];
        }
        return $fields;
    }

    private function connectionLine(string $mode): string
    {
        $org = $this->organization($mode);
        if (empty($org['name'])) {
            return '';
        }
        /* translators: %s: Polar organization name */
        return sprintf(__('Connected to %s.', 'stackless-lite-edd'), '<strong>' . esc_html((string) $org['name']) . '</strong>') . ' ';
    }

    public function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . '/polar/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('In Polar (live and sandbox), open Settings → Webhooks, add an endpoint with this URL and the Raw format: %1$s Select these events, then copy the endpoint secret into the matching webhook secret field above: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Polar into EDD.', 'stackless-lite-edd'),
            '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        );
    }

    /**
     * After a settings save: look up the organization behind each new token, forget products
     * made for another organization, and say what is missing once the gateway is on.
     *
     * @return string[] error messages
     */
    public function afterSave(): array
    {
        $errors = [];
        foreach (['live', 'sandbox'] as $mode) {
            $token = $this->accessToken($mode);
            $org = $this->organization($mode);
            if ($token === '') {
                delete_option($this->orgOption($mode));
                continue;
            }
            if (!empty($org['id']) && ($org['token'] ?? '') === self::tokenFingerprint($token)) {
                continue;
            }
            $remote = $this->fetchOrganization($mode);
            if (is_wp_error($remote)) {
                $errors[] = $remote->get_error_message();
                continue;
            }
            // Products created for another organization cannot be sold by this one.
            if ((string) ($org['id'] ?? '') !== (string) $remote['id']) {
                Catalog::forgetMode($this, $mode);
            }
        }

        if ($this->isEnabled()) {
            $mode = $this->mode();
            $name = $this->adminLabel();
            if ($this->accessToken($mode) === '') {
                /* translators: %s: gateway name */
                $errors[] = sprintf(__('%s is turned on but has no access token for the selected environment.', 'stackless-lite-edd'), $name);
            }
            if ($this->webhookSecret($mode) === '') {
                /* translators: %s: gateway name */
                $errors[] = sprintf(__('Add the webhook secret for %s. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite-edd'), $name);
            }
        }
        return $errors;
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** sandbox|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'sandbox' : 'live';
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'sandbox' ? 'sandbox' : 'live';
        $constant = 'STACKLESS_EDD_POLAR_' . strtoupper($mode) . '_' . strtoupper($field);
        // Constants in wp-config.php belong to the original gateway; copies keep their own keys.
        if ($this->isOriginal() && defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->setting($mode . '_' . $field, ''));
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'access_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'sandbox' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = Orders::meta($orderId, self::META_MODE);
        return in_array($mode, ['live', 'sandbox'], true) ? $mode : $this->mode();
    }

    /** Mode a subscription runs in (falls back to its parent order, then the current setting). */
    public function subscriptionMode(SubscriptionData $subscription): string
    {
        $mode = $subscription->get_meta(self::META_MODE);
        if (is_string($mode) && in_array($mode, ['live', 'sandbox'], true)) {
            return $mode;
        }
        return $this->orderMode($subscription->get_parent_order_id());
    }

    public function taxBehavior(): string
    {
        $behavior = (string) $this->setting('tax_behavior', 'location');
        return in_array($behavior, self::TAX_BEHAVIORS, true) ? $behavior : 'location';
    }

    private function orgOption(string $mode): string
    {
        return self::OPTION_PREFIX . 'org_' . ($mode === 'sandbox' ? 'sandbox' : 'live') . $this->suffix();
    }

    /** @return array{id?:string,slug?:string,name?:string,currency?:string,token?:string} */
    public function organization(string $mode): array
    {
        $org = get_option($this->orgOption($mode), []);
        return is_array($org) ? $org : [];
    }

    /** Which token an organization was read with (a hash, never the token). */
    private static function tokenFingerprint(string $token): string
    {
        return substr(hash('sha256', 'stackless-edd-polar|' . $token), 0, 16);
    }

    /** @return array|\WP_Error */
    public function fetchOrganization(string $mode)
    {
        $response = $this->api($mode)->request('GET', 'organizations/', ['limit' => 1]);
        if (is_wp_error($response)) {
            return $response;
        }
        $remote = (array) ($response['items'][0] ?? []);
        if (empty($remote['id'])) {
            return new \WP_Error('stackless_edd_polar_org', __('Polar did not return an organization for this access token.', 'stackless-lite-edd'));
        }
        $org = [
            'id' => (string) $remote['id'],
            'slug' => (string) ($remote['slug'] ?? ''),
            'name' => (string) ($remote['name'] ?? ''),
            'currency' => strtolower((string) ($remote['default_presentment_currency'] ?? '')),
            'token' => self::tokenFingerprint($this->accessToken($mode)),
        ];
        update_option($this->orgOption($mode), $org, true);
        return $org;
    }

    /** The organization's default presentment currency (lowercase), looked up once when unknown. */
    public function defaultCurrency(string $mode): string
    {
        $org = $this->organization($mode);
        if (!empty($org['currency'])) {
            return (string) $org['currency'];
        }
        $remote = $this->fetchOrganization($mode);
        return is_wp_error($remote) ? '' : (string) $remote['currency'];
    }

    public function dashboardUrl(string $mode, string $path): string
    {
        $host = $mode === 'sandbox' ? 'https://sandbox.polar.sh/dashboard/' : 'https://polar.sh/dashboard/';
        $slug = (string) ($this->organization($mode)['slug'] ?? '');
        return $slug !== '' ? $host . rawurlencode($slug) . '/' . $path : $host;
    }

    /**
     * edd_payment_details_transaction_id-{gateway}: link the Polar order to the dashboard.
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
        $url = $this->dashboardUrl($this->orderMode((int) $orderId), 'sales/' . rawurlencode($transactionId));
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transactionId) . '</a>';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('polar_gateway', ($this->isOriginal() ? '' : '[' . $this->gatewayId . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->accessToken() === '' || !Money::supported(stackless_edd_current_currency())) {
            return false;
        }
        return Eligibility::cartReason() === Eligibility::OK;
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function fields(): void
    {
        stackless_edd_get_template('polar_gateway/payment-fields.php', [
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
        $currency = stackless_edd_current_currency();
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Polar cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), strtoupper($currency)));
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
        $reason = Eligibility::orderReason($order);
        if ($reason !== Eligibility::OK) {
            $this->fail(Eligibility::message($reason));
            return;
        }

        try {
            $url = (new Checkout($this))->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_polar_checkout', __('Polar could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Polar checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->resumeLater($orderId);
            $this->fail($url->get_error_message());
            return;
        }
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Polar's hosted checkout.
        exit;
    }

    /** The next attempt at checkout reuses this pending order instead of creating another. */
    private function resumeLater(int $orderId): void
    {
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set('edd_resume_payment', $orderId);
        }
    }

    /**
     * Refund part or all of a Polar order.
     *
     * @return array{id:string,status:string}|\WP_Error
     */
    public function refund(Order $order, float $amount, string $reason = '')
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_polar_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $polarOrder = Orders::meta($orderId, self::META_ORDER_ID);
        if ($polarOrder === '') {
            return new \WP_Error('stackless_edd_polar_refund', __('This payment has no Polar order to refund.', 'stackless-lite-edd'));
        }

        $polarReason = EventMap::refundReason($reason);
        $body = [
            'order_id' => $polarOrder,
            'reason' => $polarReason,
            'amount' => Money::toMinor($amount, (string) $order->currency),
            'metadata' => ['edd_order_id' => (string) $orderId],
        ];
        if ($reason !== '' && $polarReason === 'other') {
            $body['comment'] = function_exists('mb_substr') ? mb_substr($reason, 0, 500) : substr($reason, 0, 500);
        }

        $refund = $this->api($this->orderMode($orderId))->request('POST', 'refunds/', $body);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $status = (string) ($refund['status'] ?? '');
        if (!in_array($status, ['pending', 'succeeded'], true)) {
            return new \WP_Error('stackless_edd_polar_refund', __('Polar could not process this refund. Check the order in your Polar dashboard.', 'stackless-lite-edd'));
        }
        $refundId = (string) ($refund['id'] ?? '');
        Sync::recordRefundId($orderId, $refundId);
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Polar refund id, 3: status */
            __('Refunded %1$s through Polar (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, (string) $order->currency),
            $refundId,
            $status
        ));
        return ['id' => $refundId, 'status' => $status];
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Polar charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Polar's order.paid webhook arrives.
     */
    public function stackless_edd_charge_renewal(Order $renewal_order, SubscriptionData $subscription): array
    {
        $renewalId = (int) $renewal_order->id;
        if (Orders::isPaid($renewal_order)) {
            return ['result' => 'success'];
        }
        Orders::setGateway($renewal_order, $this->gatewayId);
        Orders::setMeta($renewalId, self::META_AWAITING, 'yes');
        $subscription->update_meta(self::META_AWAITING, (string) $renewalId);
        Orders::note($renewalId, __('Polar bills this subscription itself. This order is marked paid when Polar reports the renewal charge.', 'stackless-lite-edd'));
        return ['result' => 'pending', 'message' => __('Waiting for Polar to charge the renewal.', 'stackless-lite-edd')];
    }

    /** Mark (or clear) that the next subscription changes come from Polar's own webhook. */
    public static function fromProvider(bool $on): void
    {
        self::$fromProvider = $on;
    }

    /** Cancel at Polar: stop renewing at period end, or revoke immediately. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        if (self::$fromProvider) {
            return true; // Polar canceled it; the store is only catching up.
        }
        $polarId = $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        $polarId = is_scalar($polarId) ? (string) $polarId : '';
        if ($polarId === '') {
            return true; // Never reached Polar, nothing to stop there.
        }
        $api = $this->api($this->subscriptionMode($subscription));
        $path = 'subscriptions/' . rawurlencode($polarId);
        $remote = $api->request('GET', $path);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $polarId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (($remote['status'] ?? '') === 'canceled' || !empty($remote['ended_at'])) {
            return true;
        }
        if ($at_period_end && !empty($remote['cancel_at_period_end'])) {
            return true;
        }
        $result = $api->request('PATCH', $path, $at_period_end ? ['cancel_at_period_end' => true] : ['revoke' => true]);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $polarId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        Subscriptions::note($subscription, $at_period_end
            ? __('Polar subscription set to end at the close of the paid period.', 'stackless-lite-edd')
            : __('Polar subscription revoked immediately.', 'stackless-lite-edd'));
        return true;
    }
}
