<?php

namespace StacklessEDD\Modules\CreemGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\LiveStore;
use StacklessEDD\Core\Logger;
use StacklessEDD\Core\WebhookAddress;

defined('ABSPATH') || exit;

/**
 * Creem (merchant of record) for Easy Digital Downloads.
 *
 * Buyers pay on Creem's hosted checkout; Creem calculates and remits sales tax and VAT, and
 * bills subscriptions itself. Orders, renewals, refunds and cancellations stay in step
 * through the browser return and signed webhooks.
 *
 * One instance per Creem store: the original ("stackless_edd_creem") and each copy
 * ("stackless_edd_creem_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends BaseGateway implements SubscriptionGateway
{
    public const ID = 'stackless_edd_creem';
    public const OPTION_PREFIX = 'stackless_edd_creem_gateway_';

    /** Settings stored per gateway: OPTION_PREFIX . key . suffix ('' or '_copyN'). */
    public const SETTING_KEYS = ['title', 'description', 'testmode', 'live_api_key', 'live_webhook_secret', 'test_api_key', 'test_webhook_secret', 'tax_behavior', 'tax_category', 'debug'];

    public const META_CHECKOUT_ID = '_stackless_edd_creem_checkout_id';
    public const META_CHECKOUT_URL = '_stackless_edd_creem_checkout_url';
    public const META_SESSION_AT = '_stackless_edd_creem_session_at';
    public const META_MODE = '_stackless_edd_creem_mode';
    public const META_AMOUNT = '_stackless_edd_creem_amount';
    public const META_PRODUCT_ID = '_stackless_edd_creem_product_id';
    public const META_TRANSACTION_ID = '_stackless_edd_creem_transaction_id';
    public const META_ORDER_ID = '_stackless_edd_creem_order_id';
    public const META_TAX = '_stackless_edd_creem_tax_amount';
    public const META_TOTAL = '_stackless_edd_creem_total_minor';
    public const META_REFUND_IDS = '_stackless_edd_creem_refund_ids';
    public const META_AWAITING = '_stackless_edd_creem_awaiting_renewal';
    public const META_NEXT_BILLING = '_stackless_edd_creem_next_billing_date';
    public const META_CANCEL_AT_END = '_stackless_edd_creem_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['exclusive', 'inclusive'];

    /** Tax categories Creem accepts on a product. */
    public const TAX_CATEGORIES = ['saas', 'digital-goods-service', 'ebooks'];

    /** An open checkout session is offered again for this long. */
    public const SESSION_TTL = 82800;

    /** Where Creem hands out API keys and webhooks. */
    public const DASHBOARD_URL = 'https://www.creem.io/dashboard/developers';

    /** @var array<string,Gateway> */
    private static $instances = [];

    /** @var bool True while Creem sync applies a change Creem itself made (nothing to send back). */
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

    /** Forget every instance (staging scripts swap settings). */
    public static function reset(): void
    {
        self::$instances = [];
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
        return __('Creem (Stackless)', 'stackless-lite-edd') . ($badge !== '' ? ' · ' . $badge : '');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : __('Card, Apple Pay or Google Pay (Creem)', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->setting('description', self::defaultDescription());
    }

    private static function defaultDescription(): string
    {
        return __("You'll finish paying on Creem's secure checkout. Creem adds any sales tax or VAT for your location before you pay.", 'stackless-lite-edd');
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

    /**
     * @param mixed $default
     * @return mixed
     */
    public function setting(string $key, $default = '')
    {
        $value = get_option($this->optionName($key), null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ---- Settings -------------------------------------------------------------- */

    /** This gateway's block on Stackless → Settings → Creem (the original, then each copy). */
    public function settingsFields(string $copiesPanel = ''): array
    {
        $badge = Copies::badge($this->gatewayId);
        $section = $this->optionName('section');
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');

        $fields = [
            [
                'id' => $section,
                'type' => 'title',
                /* translators: %s: copy badge, for example "Copy 2" */
                'title' => $badge !== '' ? sprintf(__('Creem · %s', 'stackless-lite-edd'), $badge) : __('Creem', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: gateway name, 2: link to the EDD payment gateways screen */
                    __('Turn on %1$s in %2$s once the API key below is saved.', 'stackless-lite-edd'),
                    '<strong>' . esc_html($this->adminLabel()) . '</strong>',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            [
                'id' => $this->optionName('title'),
                'type' => 'text',
                'title' => __('Title', 'stackless-lite-edd'),
                'default' => __('Card, Apple Pay or Google Pay (Creem)', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('description'),
                'type' => 'textarea',
                'title' => __('Description', 'stackless-lite-edd'),
                'default' => self::defaultDescription(),
            ],
            [
                'id' => $this->optionName('testmode'),
                'type' => 'checkbox',
                'title' => __('Test mode', 'stackless-lite-edd'),
                'desc' => __('Use Creem test mode (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => $this->optionName('tax_behavior'),
                'type' => 'select',
                'title' => __('Prices and tax', 'stackless-lite-edd'),
                'default' => 'exclusive',
                'options' => [
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite-edd'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite-edd'),
                ],
                'desc' => __('Creem is the merchant of record and calculates sales tax and VAT itself. Turn off EDD taxes for downloads sold through Creem so buyers are not taxed twice.', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('tax_category'),
                'type' => 'select',
                'title' => __('Tax category', 'stackless-lite-edd'),
                'default' => 'saas',
                'options' => [
                    'saas' => __('Software as a service', 'stackless-lite-edd'),
                    'digital-goods-service' => __('Digital goods or services', 'stackless-lite-edd'),
                    'ebooks' => __('E-books', 'stackless-lite-edd'),
                ],
                'desc' => __("Creem uses this category to pick the tax rate for each buyer's country.", 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('debug'),
                'type' => 'checkbox',
                'title' => __('Debug log', 'stackless-lite-edd'),
                'desc' => __('Log Creem activity to Downloads → Tools → Debug Log (stackless-edd-creem_gateway) while EDD debug mode is on. Keys are never logged.', 'stackless-lite-edd'),
                'default' => 'no',
            ],
            ['id' => $section, 'type' => 'sectionend'],
        ];

        foreach (['live', 'test'] as $mode) {
            $fields[] = [
                'id' => $this->optionName($mode . '_section'),
                'type' => 'title',
                'title' => $mode === 'test' ? __('Test mode', 'stackless-lite-edd') : __('Live', 'stackless-lite-edd'),
                'desc' => $mode === 'test'
                    ? __('Test and live keys are separate: switch the Creem dashboard to test mode (bottom of the sidebar) to copy the key that starts with creem_test.', 'stackless-lite-edd')
                    : self::keyButton() . ' ' . __('In Developers → API keys, copy the live key and paste it below. On a public HTTPS site the webhook and its secret are set up for you when you save.', 'stackless-lite-edd'),
            ];
            if (!$this->isDefined($mode, 'api_key')) {
                $fields[] = ['id' => $this->optionName($mode . '_api_key'), 'type' => 'password', 'title' => $mode === 'test' ? __('Test API key', 'stackless-lite-edd') : __('Live API key', 'stackless-lite-edd'), 'default' => ''];
            }
            if (!$this->isDefined($mode, 'webhook_secret')) {
                $fields[] = ['id' => $this->optionName($mode . '_webhook_secret'), 'type' => 'password', 'title' => $mode === 'test' ? __('Test webhook secret', 'stackless-lite-edd') : __('Live webhook secret', 'stackless-lite-edd'), 'placeholder' => 'whsec_…', 'default' => ''];
            }
            $fields[] = ['id' => $this->optionName($mode . '_section'), 'type' => 'sectionend'];
        }

        $fields[] = [
            'id' => $this->optionName('webhook_section'),
            'type' => 'title',
            'title' => __('Webhook', 'stackless-lite-edd'),
            'desc' => $this->webhookInstructions(),
        ];
        $fields[] = ['id' => $this->optionName('webhook_section'), 'type' => 'sectionend'];

        if ($copiesPanel !== '') {
            $fields[] = ['id' => $this->optionName('copies_section'), 'type' => 'title', 'title' => __('Copies', 'stackless-lite-edd')];
            $fields[] = ['type' => Module::COPIES_FIELD, 'html' => $copiesPanel];
            $fields[] = ['id' => $this->optionName('copies_section'), 'type' => 'sectionend'];
        }
        return $fields;
    }

    /** The "Get API keys" button that opens Creem's developer settings in a new tab. */
    private static function keyButton(): string
    {
        return '<a class="button" href="' . esc_url(self::DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Get API keys', 'stackless-lite-edd') . '</a>';
    }

    public function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . '/creem/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    private function webhookInstructions(): string
    {
        $status = '';
        $problem = WebhookAddress::problem($this->webhookUrl(), 'Creem');
        if ($problem !== '') {
            $status .= '<strong>' . esc_html($problem) . '</strong> ';
        }
        foreach (['live', 'test'] as $mode) {
            if ($this->webhookSecret($mode) === '') {
                continue;
            }
            $status .= esc_html(get_option($this->webhookOption($mode), '') !== ''
                ? ($mode === 'test' ? __('Test webhook connected: Stackless set it up in Creem and saved its secret.', 'stackless-lite-edd') : __('Live webhook connected: Stackless set it up in Creem and saved its secret.', 'stackless-lite-edd'))
                : ($mode === 'test' ? __('Test webhook secret saved.', 'stackless-lite-edd') : __('Live webhook secret saved.', 'stackless-lite-edd'))) . ' ';
        }
        return $status . sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('On a public HTTPS site, saving a key creates this webhook in Creem and stores its secret. To add it by hand, open Developers → Webhooks in Creem (switch to test mode first for the test endpoint), add this URL: %1$s Select these events, then paste the webhook secret into the field for the same mode: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals and failed renewals, and bring refunds and cancellations made in Creem into EDD.', 'stackless-lite-edd'),
            '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        );
    }

    /**
     * After a settings save: check each new key with Creem, forget products made with another
     * store's key or with other tax settings, connect the webhook, and say what is missing once
     * the gateway is on.
     *
     * @return string[] error messages
     */
    public function afterSave(): array
    {
        $errors = [];
        $tax = $this->taxBehavior() . '|' . $this->taxCategory();
        foreach (['live', 'test'] as $mode) {
            $key = $this->apiKey($mode);
            if ($key === '') {
                delete_option($this->stateOption($mode));
                continue;
            }
            $keyMode = EventMap::keyMode($key);
            if ($keyMode !== '' && $keyMode !== $mode) {
                $errors[] = $mode === 'test'
                    ? __('The Creem test API key field holds a live key. Paste the key that starts with creem_test.', 'stackless-lite-edd')
                    : __('The Creem live API key field holds a test key. Paste a live key from the dashboard with test mode off.', 'stackless-lite-edd');
                continue;
            }
            $state = get_option($this->stateOption($mode), []);
            $state = is_array($state) ? $state : [];
            $fingerprint = self::keyFingerprint($key);
            if ((string) ($state['key'] ?? '') !== $fingerprint) {
                $check = $this->api($mode)->request('GET', 'v1/products/search', ['page_number' => 1, 'page_size' => 1]);
                if (is_wp_error($check)) {
                    $errors[] = $check->get_error_message();
                    continue;
                }
                // Products created with another store's key cannot be sold by this one.
                if (!empty($state['key'])) {
                    $this->catalog()->forgetMode($mode);
                }
            }
            // Products carry the tax settings, so changing them starts fresh products.
            if (isset($state['tax']) && (string) $state['tax'] !== $tax) {
                $this->catalog()->forgetMode($mode);
            }
            update_option($this->stateOption($mode), ['key' => $fingerprint, 'tax' => $tax], false);
            $error = $this->ensureWebhook($mode);
            if ($error !== '') {
                $errors[] = $error;
            }
        }

        if ($this->isEnabled()) {
            $mode = $this->mode();
            $name = $this->adminLabel();
            if ($this->apiKey($mode) === '') {
                /* translators: %s: gateway name */
                $errors[] = sprintf(__('%s is turned on but has no API key for the selected mode.', 'stackless-lite-edd'), $name);
            }
            if ($this->webhookSecret($mode) === '') {
                /* translators: %s: gateway name */
                $errors[] = sprintf(__('Add the webhook secret for %s. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite-edd'), $name);
            }
        }
        return $errors;
    }

    /** Which key a mode's saved state belongs to (a hash, never the key). */
    private static function keyFingerprint(string $key): string
    {
        return substr(hash('sha256', 'stackless-edd-creem|' . $key), 0, 16);
    }

    private function stateOption(string $mode): string
    {
        return self::OPTION_PREFIX . 'state_' . ($mode === 'test' ? 'test' : 'live') . $this->suffix();
    }

    private function webhookOption(string $mode): string
    {
        return self::OPTION_PREFIX . 'webhook_' . ($mode === 'test' ? 'test' : 'live') . $this->suffix();
    }

    /**
     * Find or create this site's webhook endpoint in Creem and save its secret. An endpoint
     * already pointing here is reused; Creem hands out its secret on request.
     *
     * @return string An error to show, or ''.
     */
    public function ensureWebhook(string $mode): string
    {
        $url = $this->webhookUrl();
        if ($this->webhookSecret($mode) !== '' || $this->isDefined($mode, 'webhook_secret')) {
            return '';
        }
        // A local or plain-http site can't receive webhooks; the Webhook section says so.
        if (WebhookAddress::problem($url, 'Creem') !== '') {
            return '';
        }
        // A copy of the live store does not add itself to the live Creem store's webhooks.
        if ($mode === 'live' && !LiveStore::isLive()) {
            return '';
        }
        $label = $mode === 'test' ? __('test', 'stackless-lite-edd') : __('live', 'stackless-lite-edd');
        $api = $this->api($mode);
        $webhookId = '';
        $secret = '';
        $list = $api->request('GET', 'v1/webhooks', ['page_number' => 1, 'page_size' => 100]);
        foreach (is_array($list) ? (array) ($list['items'] ?? []) : [] as $webhook) {
            if (is_array($webhook) && WebhookAddress::same((string) ($webhook['url'] ?? ''), $url)) {
                $webhookId = (string) ($webhook['id'] ?? '');
                $secret = (string) ($webhook['secret'] ?? '');
                break;
            }
        }
        if ($webhookId === '') {
            $created = $api->request('POST', 'v1/webhooks', [
                'url' => $url,
                'name' => 'Stackless for EDD',
                'events' => EventMap::EVENTS,
                'delivery_mode' => 'http',
            ]);
            if (is_wp_error($created)) {
                /* translators: 1: test or live, 2: Creem error message */
                return sprintf(__('Creating the %1$s Creem webhook failed: %2$s Add it by hand (see Webhook below), or give the API key the webhook permissions.', 'stackless-lite-edd'), $label, $created->get_error_message());
            }
            $webhookId = (string) ($created['id'] ?? '');
            $secret = (string) ($created['secret'] ?? '');
        }
        if ($secret === '' && $webhookId !== '') {
            $fetched = $api->request('GET', 'v1/webhooks/' . rawurlencode($webhookId) . '/secret');
            $secret = is_wp_error($fetched) ? '' : (string) ($fetched['secret'] ?? '');
        }
        if ($secret === '') {
            /* translators: %s: test or live */
            return sprintf(__('The %s Creem webhook exists, but Creem did not return its secret. Copy it from Developers → Webhooks into the webhook secret field.', 'stackless-lite-edd'), $label);
        }
        update_option($this->optionName($mode . '_webhook_secret'), $secret);
        update_option($this->webhookOption($mode), $webhookId, false);
        $this->log('Webhook connected (' . $mode . '): ' . $webhookId);
        return '';
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** test|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    private static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_CREEM_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
    }

    /** Whether a key or secret is pinned in wp-config.php (original gateway only; copies keep their own keys). */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return $this->isOriginal() && defined($constant) && constant($constant);
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
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
        $mode = Orders::meta($orderId, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /** Mode a subscription runs in (falls back to its parent order, then the current setting). */
    public function subscriptionMode(SubscriptionData $subscription): string
    {
        $mode = Subscriptions::metaString($subscription, self::META_MODE);
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
        $category = (string) $this->setting('tax_category', 'saas');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'saas';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('creem_gateway', ($this->isOriginal() ? '' : '[' . $this->gatewayId . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->apiKey() === '' || !Money::supported(stackless_edd_current_currency())) {
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
        stackless_edd_get_template('creem_gateway/payment-fields.php', [
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
        $currency = stackless_edd_current_currency();
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Creem cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), strtoupper($currency)));
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
            $url = new \WP_Error('stackless_edd_creem_checkout', __('Creem could not start the checkout. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Creem checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->resumeLater($orderId);
            $this->fail($url->get_error_message());
            return;
        }
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Creem's hosted checkout, or this site's receipt.
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
     * Refund a Creem payment. Creem's API refunds a whole payment, so only the full order total
     * can be refunded from EDD; partial refunds are made in the Creem dashboard and mirrored in.
     *
     * @return array{id:string,status:string}|\WP_Error Creem returns no refund id; refund.created brings it.
     */
    public function refund(Order $order, float $amount, string $reason = '')
    {
        $orderId = (int) $order->id;
        $transactionId = Orders::meta($orderId, self::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return new \WP_Error('stackless_edd_creem_refund', __('This order has no Creem payment to refund.', 'stackless-lite-edd'));
        }
        if (!Money::equals($amount, (float) $order->total) || !Money::equals((float) edd_get_order_total($orderId), (float) $order->total)) {
            return new \WP_Error('stackless_edd_creem_refund', __('Creem refunds the whole payment from EDD. Refund the full order total here, or make a partial refund in the Creem dashboard; it appears on this order automatically.', 'stackless-lite-edd'));
        }

        $refund = $this->api($this->orderMode($orderId))->request('POST', 'v1/refunds', ['transaction_id' => $transactionId]);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $status = (string) ($refund['status'] ?? '');
        if (in_array($status, ['failed', 'canceled'], true)) {
            return new \WP_Error('stackless_edd_creem_refund', __('Creem could not process this refund. Check the payment in your Creem dashboard.', 'stackless-lite-edd'));
        }
        $refundId = EventMap::id($refund['id'] ?? '');
        Sync::recordRefundId($orderId, $refundId);
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Creem transaction id, 3: status */
            __('Refunded %1$s through Creem (transaction %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, (string) $order->currency),
            $transactionId,
            $status !== '' ? $status : 'pending'
        ));
        return ['id' => $refundId, 'status' => $status !== '' ? $status : 'pending'];
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Creem charges renewals on its own schedule, so nothing is charged here. The renewal
     * order is parked and completed when Creem's subscription.paid webhook arrives.
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
        Orders::note($renewalId, __('Creem bills this subscription itself. This order is marked paid when Creem reports the renewal charge.', 'stackless-lite-edd'));
        return ['result' => 'pending', 'message' => __('Waiting for Creem to charge the renewal.', 'stackless-lite-edd')];
    }

    /** Mark (or clear) that the next subscription changes come from Creem's own webhook. */
    public static function fromProvider(bool $on): void
    {
        self::$fromProvider = $on;
    }

    /** Cancel at Creem: at the end of the paid period, or now. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        if (self::$fromProvider) {
            return true; // Creem canceled it; the store is only catching up.
        }
        $creemId = Subscriptions::metaString($subscription, SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($creemId === '') {
            return true; // Never reached Creem, nothing to stop there.
        }
        $api = $this->api($this->subscriptionMode($subscription));
        $remote = $api->request('GET', 'v1/subscriptions', ['subscription_id' => $creemId]);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $creemId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        $status = (string) ($remote['status'] ?? '');
        if ($status === 'canceled' || ($at_period_end && $status === 'scheduled_cancel')) {
            return true;
        }
        $result = $api->request('POST', 'v1/subscriptions/' . rawurlencode($creemId) . '/cancel', $at_period_end
            ? ['mode' => 'scheduled', 'onExecute' => 'cancel']
            : ['mode' => 'immediate']);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $creemId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        Subscriptions::note($subscription, $at_period_end
            ? __('Creem subscription set to stop at the end of the paid period.', 'stackless-lite-edd')
            : __('Creem subscription canceled immediately.', 'stackless-lite-edd'));
        return true;
    }
}
