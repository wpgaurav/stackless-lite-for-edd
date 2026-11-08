<?php

namespace StacklessEDD\Modules\MercadoPagoGateway;

use EDD\Orders\Order;
use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Mercado Pago for Easy Digital Downloads: one-time payments on Checkout Pro (cards, Pix,
 * boleto, OXXO, Mercado Pago balance and the rest of the country's methods).
 *
 * The order is marked paid only after the payment is read back from Mercado Pago and its
 * reference, currency and amount match the order; cash and bank methods that settle later
 * hold the order until Mercado Pago approves them. Refunds go through the API; refunds and
 * chargebacks made in Mercado Pago arrive through the signed webhook. Subscriptions use pay
 * links: this gateway does not renew automatically.
 */
class Gateway extends BaseGateway
{
    public const ID = 'stackless_edd_mercadopago';
    public const OPTION_PREFIX = 'stackless_edd_mercadopago_gateway_';

    public const META_MODE = '_stackless_edd_mercadopago_mode';
    public const META_PREFERENCE_ID = '_stackless_edd_mercadopago_preference_id';
    public const META_CHECKOUT_URL = '_stackless_edd_mercadopago_checkout_url';
    public const META_STARTED_AT = '_stackless_edd_mercadopago_started_at';
    public const META_AMOUNT = '_stackless_edd_mercadopago_amount';
    public const META_PAYMENT_ID = '_stackless_edd_mercadopago_payment_id';
    public const META_PAYMENT_TYPE = '_stackless_edd_mercadopago_payment_type';
    public const META_AWAITING = '_stackless_edd_mercadopago_awaiting_payment';
    public const META_REFUND_IDS = '_stackless_edd_mercadopago_refund_ids';

    /** The account each token belongs to: {site_id, test_user, token} (token = a hash, never the token). */
    public const ACCOUNT_OPTION = 'stackless_edd_mercadopago_gateway_account_';

    /** An unpaid Checkout Pro link is offered again for this long (double submits, back button). */
    public const REUSE_FOR = 1800;

    /** @var Gateway|null */
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Forget the shared instance (staging scripts swap settings). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Mercado Pago (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : __('Mercado Pago', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->setting('description', __('You’ll finish paying on Mercado Pago with a card, your Mercado Pago balance or a local payment method.', 'stackless-lite-edd'));
    }

    public function optionName(string $key): string
    {
        return self::OPTION_PREFIX . $key;
    }

    /** @return mixed */
    public function setting(string $key, $default = '')
    {
        $value = get_option($this->optionName($key), null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ---- Settings -------------------------------------------------------------- */

    public static function settingsUrl(): string
    {
        return SettingsPage::url('mercadopago_gateway');
    }

    /** @return array<int,array<string,mixed>> Fields on Stackless → Settings → Mercado Pago. */
    public function settingsFields(): array
    {
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'id' => $this->optionName('section'),
                'type' => 'title',
                'title' => __('Mercado Pago', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: gateway name, 2: link to the EDD payment gateways screen */
                    __('Turn on %1$s in %2$s once an access token is saved below.', 'stackless-lite-edd'),
                    '<strong>' . esc_html($this->adminLabel()) . '</strong>',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            [
                'id' => $this->optionName('title'),
                'type' => 'text',
                'title' => __('Title', 'stackless-lite-edd'),
                'default' => __('Mercado Pago', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('description'),
                'type' => 'textarea',
                'title' => __('Description', 'stackless-lite-edd'),
                'default' => __('You’ll finish paying on Mercado Pago with a card, your Mercado Pago balance or a local payment method.', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('testmode'),
                'type' => 'checkbox',
                'title' => __('Test mode', 'stackless-lite-edd'),
                'desc' => __('Use Mercado Pago test credentials (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => $this->optionName('debug'),
                'type' => 'checkbox',
                'title' => __('Debug log', 'stackless-lite-edd'),
                'desc' => __('Log Mercado Pago activity to Downloads → Tools → Debug Log (stackless-edd-mercadopago_gateway) while EDD debug mode is on. Tokens are never logged.', 'stackless-lite-edd'),
                'default' => 'no',
            ],
            ['id' => $this->optionName('section'), 'type' => 'sectionend'],
        ];

        foreach (['live', 'test'] as $mode) {
            $section = $this->optionName($mode . '_section');
            $fields[] = [
                'id' => $section,
                'type' => 'title',
                'title' => $mode === 'test' ? __('Test mode', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd'),
                'desc' => $mode === 'test'
                    ? $this->connectionLine('test') . __('Use the access token from Tests → Test credentials of the same application. Pay with a test buyer account and Mercado Pago’s test cards.', 'stackless-lite-edd')
                    : $this->connectionLine('live') . self::keyButtons([
                        __('Open Your integrations', 'stackless-lite-edd') => 'https://www.mercadopago.com/developers/panel/app',
                    ]) . __('Open your application, then Production → Production credentials, and copy the access token.', 'stackless-lite-edd'),
            ];
            if (!$this->isDefined($mode, 'access_token')) {
                $fields[] = [
                    'id' => $this->optionName($mode . '_access_token'),
                    'type' => 'password',
                    'title' => $mode === 'test' ? __('Test access token', 'stackless-lite-edd') : __('Production access token', 'stackless-lite-edd'),
                    'placeholder' => 'APP_USR-…',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'webhook_secret')) {
                $fields[] = [
                    'id' => $this->optionName($mode . '_webhook_secret'),
                    'type' => 'password',
                    'title' => $mode === 'test' ? __('Test webhook secret', 'stackless-lite-edd') : __('Production webhook secret', 'stackless-lite-edd'),
                    'default' => '',
                ];
            }
            $fields[] = ['id' => $section, 'type' => 'sectionend'];
        }

        $fields[] = [
            'id' => $this->optionName('webhook_section'),
            'type' => 'title',
            'title' => __('Webhook', 'stackless-lite-edd'),
            'desc' => $this->webhookInstructions(),
        ];
        $fields[] = ['id' => $this->optionName('webhook_section'), 'type' => 'sectionend'];
        $fields[] = [
            'id' => $this->optionName('limits_section'),
            'type' => 'title',
            'title' => __('Good to know', 'stackless-lite-edd'),
            'desc' => __('Mercado Pago takes payments in the currency of the country your account belongs to, so it shows at checkout only when the store or the buyer’s selected currency matches. It takes one-time payments: subscription downloads can still be bought with it when they renew by pay link. Boleto, OXXO and other cash methods keep the order on hold until Mercado Pago approves the payment.', 'stackless-lite-edd'),
        ];
        $fields[] = ['id' => $this->optionName('limits_section'), 'type' => 'sectionend'];
        return $fields;
    }

    private function connectionLine(string $mode): string
    {
        $site = $this->siteId($mode);
        if ($site === '' || !isset(Money::SITES[$site])) {
            return '';
        }
        /* translators: 1: Mercado Pago site id such as MLB, 2: currency code */
        return sprintf(__('Connected to a Mercado Pago account in %1$s (charges in %2$s).', 'stackless-lite-edd'), '<strong>' . esc_html($site) . '</strong>', esc_html(Money::SITES[$site])) . ' ';
    }

    /**
     * Buttons that open the provider's key screens.
     *
     * @param array<string,string> $links label => URL
     */
    public static function keyButtons(array $links): string
    {
        $html = [];
        foreach ($links as $label => $url) {
            $html[] = sprintf(
                '<a class="button button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span aria-hidden="true">↗</span><span class="screen-reader-text"> %3$s</span></a>',
                esc_url($url),
                esc_html($label),
                esc_html__('(opens in a new tab)', 'stackless-lite-edd')
            );
        }
        return $html ? '<span class="stackless-key-buttons" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">' . implode('', $html) . '</span>' : '';
    }

    public static function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . '/mercadopago/webhook');
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: %s: webhook URL */
            __('In Mercado Pago, open Your integrations → your application → Webhooks → Configure notifications. Paste this URL for both test mode and production mode, select the Payments event and save: %s Then copy the secret signature Mercado Pago shows into the webhook secret field for each mode. Webhooks confirm payments that settle later (boleto, OXXO, Pix) and payments whose buyer never came back, and bring refunds and chargebacks into EDD. Mercado Pago does not let plugins set this up for you.', 'stackless-lite-edd'),
            '<br><code>' . esc_html(self::webhookUrl()) . '</code><br>'
        );
    }

    /**
     * After a settings save: read the account of each new token from Mercado Pago once (its
     * country decides the currency), refuse a test account's token in the production field
     * and a real seller's token in the test field, and say what is missing once the gateway is on.
     *
     * @return string[] error messages
     */
    public function afterSave(): array
    {
        $errors = [];
        foreach (['live', 'test'] as $mode) {
            $token = $this->accessToken($mode);
            if ($token === '') {
                delete_option(self::ACCOUNT_OPTION . $mode);
                continue;
            }
            $account = $this->account($mode);
            if (($account['token'] ?? '') !== self::fingerprint($token) || empty($account['site_id'])) {
                $account = $this->fetchAccount($mode);
                if (is_wp_error($account)) {
                    $errors[] = $account->get_error_message();
                    continue;
                }
            }
            $isTest = EventMap::isTestToken($token, $account);
            if ($mode === 'live' && $isTest) {
                $errors[] = __('The Mercado Pago production access token belongs to a test account. Paste the token from Production → Production credentials.', 'stackless-lite-edd');
            } elseif ($mode === 'test' && !$isTest) {
                $errors[] = __('The Mercado Pago test access token belongs to a real seller account. Paste the token from Tests → Test credentials, so test orders never move real money.', 'stackless-lite-edd');
            }
        }

        if ($this->isEnabled()) {
            $mode = $this->mode();
            if ($this->accessToken($mode) === '') {
                $errors[] = __('Mercado Pago is turned on but has no access token for the selected mode.', 'stackless-lite-edd');
            }
            if ($this->webhookSecret($mode) === '') {
                $errors[] = __('Add the Mercado Pago webhook secret. Payments that settle later, refunds and chargebacks stay in sync through the webhook.', 'stackless-lite-edd');
            }
        }
        return $errors;
    }

    private static function fingerprint(string $token): string
    {
        return substr(hash('sha256', 'stackless-edd-mercadopago|' . $token), 0, 16);
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** test|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a value is pinned in wp-config.php, e.g. STACKLESS_EDD_MERCADOPAGO_LIVE_ACCESS_TOKEN. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_MERCADOPAGO_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
    }

    private function secret(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        return trim((string) $this->setting($mode . '_' . $field, ''));
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->secret($mode !== '' ? $mode : $this->mode(), 'access_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secret($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    /** Mode an order was started in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = Orders::meta($orderId, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /** @return array{site_id?:string,test_user?:bool,token?:string} The account the token belongs to (no personal data). */
    public function account(string $mode): array
    {
        $account = get_option(self::ACCOUNT_OPTION . ($mode === 'test' ? 'test' : 'live'), []);
        return is_array($account) ? $account : [];
    }

    /** @return array{site_id:string,test_user:bool,token:string}|\WP_Error */
    public function fetchAccount(string $mode)
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        $me = $this->api($mode)->request('GET', 'users/me');
        if (is_wp_error($me)) {
            return $me;
        }
        $site = strtoupper((string) ($me['site_id'] ?? ''));
        if ($site === '') {
            return new \WP_Error('stackless_edd_mercadopago_account', __('Mercado Pago did not say which country this account belongs to.', 'stackless-lite-edd'));
        }
        $account = [
            'site_id' => $site,
            'test_user' => in_array('test_user', (array) ($me['tags'] ?? []), true),
            'token' => self::fingerprint($this->accessToken($mode)),
        ];
        update_option(self::ACCOUNT_OPTION . $mode, $account, false);
        return $account;
    }

    /** The country site of the account in a mode, '' until its token was saved. */
    public function siteId(string $mode = ''): string
    {
        return strtoupper((string) ($this->account($mode !== '' ? $mode : $this->mode())['site_id'] ?? ''));
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('mercadopago_gateway', $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        return $this->accessToken() !== '' && Money::supported(stackless_edd_current_currency(), $this->siteId());
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies($this->siteId());
    }

    public function fields(): void
    {
        stackless_edd_get_template('mercadopago_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'testmode' => $this->mode() === 'test',
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $currency = stackless_edd_current_currency();
        if (!Money::supported($currency, $this->siteId())) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Mercado Pago cannot charge in %s for this store. Choose another payment method.', 'stackless-lite-edd'), strtoupper($currency)));
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
            $url = (new Checkout($this))->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $orderId . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_edd_mercadopago_checkout', __('Mercado Pago could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Mercado Pago checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->resumeLater($orderId);
            $this->fail($url->get_error_message());
            return;
        }
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mercado Pago's hosted checkout.
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
     * Refund through Mercado Pago. The X-Idempotency-Key comes from the order and its refund
     * count, so a retry after a timeout cannot pay the customer back twice; a different amount
     * gets a new key.
     *
     * @return array{id:string,status:string}|\WP_Error
     */
    public function refund(Order $order, float $amount, string $reason = '')
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_mercadopago_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $paymentId = Orders::meta($orderId, self::META_PAYMENT_ID);
        if ($paymentId === '') {
            return new \WP_Error('stackless_edd_mercadopago_refund', __('This payment has no Mercado Pago payment to refund.', 'stackless-lite-edd'));
        }
        $currency = strtoupper((string) $order->currency);
        $refundAmount = Money::amount($amount, $currency);
        $key = EventMap::refundKey($orderId, Refunds::count($orderId) + 1, Money::toMinor($refundAmount, $currency), Sync::siteHost());
        // A full refund is sent without an amount, as Mercado Pago asks.
        $body = Money::equals($amount, (float) $order->total, $currency) ? [] : ['amount' => $refundAmount];
        $api = $this->api($this->orderMode($orderId));
        // Under the order's refund lock: Mercado Pago approves at once and notifies, and that
        // notification must not mirror this refund before its id is stored.
        $refund = Lock::run('refund-' . $orderId, static function () use ($api, $paymentId, $body, $key, $orderId) {
            $refund = $api->request('POST', 'v1/payments/' . rawurlencode($paymentId) . '/refunds', $body, $key);
            if (is_wp_error($refund)) {
                return $refund;
            }
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '' || in_array((string) ($refund['status'] ?? ''), ['rejected', 'cancelled'], true)) {
                return new \WP_Error('stackless_edd_mercadopago_refund', __('Mercado Pago could not process this refund. Check that your account balance covers it and that the payment is under 180 days old.', 'stackless-lite-edd'));
            }
            Sync::recordRefundId($orderId, $refundId);
            return $refund;
        }, null);
        if ($refund === null) {
            return new \WP_Error('stackless_edd_mercadopago_refund', __('This payment is being updated by another request. Try the refund again in a moment.', 'stackless-lite-edd'));
        }
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $refundId = (string) $refund['id'];
        $status = (string) ($refund['status'] ?? 'approved');
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Mercado Pago refund id, 3: status */
            __('Refunded %1$s through Mercado Pago (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, $currency),
            $refundId,
            $status
        ));
        return ['id' => $refundId, 'status' => $status];
    }
}
