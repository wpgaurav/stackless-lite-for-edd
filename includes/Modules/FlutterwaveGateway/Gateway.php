<?php

namespace StacklessEDD\Modules\FlutterwaveGateway;

use EDD\Orders\Order;
use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\Gateway as BaseGateway;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Flutterwave for Easy Digital Downloads: one-time payments on Flutterwave's hosted checkout
 * (cards, bank transfer, mobile money, USSD and the account's other methods) through the v3 API.
 *
 * The order is marked paid only after the transaction is verified with Flutterwave and its
 * reference, currency and amount match the order. Refunds go through the API; refunds made
 * in the Flutterwave dashboard arrive through the webhook. Subscriptions use pay links:
 * this gateway does not renew automatically.
 */
class Gateway extends BaseGateway
{
    public const ID = 'stackless_edd_flutterwave';
    public const OPTION_PREFIX = 'stackless_edd_flutterwave_gateway_';

    public const META_MODE = '_stackless_edd_flutterwave_mode';
    public const META_TX_REF = '_stackless_edd_flutterwave_tx_ref';
    public const META_CHECKOUT_URL = '_stackless_edd_flutterwave_checkout_url';
    public const META_STARTED_AT = '_stackless_edd_flutterwave_started_at';
    public const META_AMOUNT = '_stackless_edd_flutterwave_amount';
    public const META_TRANSACTION_ID = '_stackless_edd_flutterwave_transaction_id';
    public const META_FLW_REF = '_stackless_edd_flutterwave_flw_ref';
    public const META_PAYMENT_TYPE = '_stackless_edd_flutterwave_payment_type';
    public const META_REFUND_IDS = '_stackless_edd_flutterwave_refund_ids';
    public const META_REFUND_FAILED = '_stackless_edd_flutterwave_refund_failed';

    /** Which keys were last checked with Flutterwave (hashes, never the keys). */
    public const CHECKED_OPTION = 'stackless_edd_flutterwave_gateway_checked_keys';

    /** An unpaid checkout link is offered again for this long (double submits, back button). */
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
        return __('Flutterwave (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->setting('title', ''));
        return $title !== '' ? $title : __('Card, bank transfer or mobile money (Flutterwave)', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->setting('description', __('You’ll finish paying on Flutterwave’s secure checkout.', 'stackless-lite-edd'));
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
        return SettingsPage::url('flutterwave_gateway');
    }

    /** @return array<int,array<string,mixed>> Fields on Stackless → Settings → Flutterwave. */
    public function settingsFields(): array
    {
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'id' => $this->optionName('section'),
                'type' => 'title',
                'title' => __('Flutterwave', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: gateway name, 2: link to the EDD payment gateways screen */
                    __('Turn on %1$s in %2$s once a secret key is saved below.', 'stackless-lite-edd'),
                    '<strong>' . esc_html($this->adminLabel()) . '</strong>',
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>'
                ),
            ],
            [
                'id' => $this->optionName('title'),
                'type' => 'text',
                'title' => __('Title', 'stackless-lite-edd'),
                'default' => __('Card, bank transfer or mobile money (Flutterwave)', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('description'),
                'type' => 'textarea',
                'title' => __('Description', 'stackless-lite-edd'),
                'default' => __('You’ll finish paying on Flutterwave’s secure checkout.', 'stackless-lite-edd'),
            ],
            [
                'id' => $this->optionName('testmode'),
                'type' => 'checkbox',
                'title' => __('Test mode', 'stackless-lite-edd'),
                'desc' => __('Use Flutterwave test mode (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => $this->optionName('debug'),
                'type' => 'checkbox',
                'title' => __('Debug log', 'stackless-lite-edd'),
                'desc' => __('Log Flutterwave activity to Downloads → Tools → Debug Log (stackless-edd-flutterwave_gateway) while EDD debug mode is on. Keys are never logged.', 'stackless-lite-edd'),
                'default' => 'no',
            ],
            ['id' => $this->optionName('section'), 'type' => 'sectionend'],
        ];

        foreach (['live', 'test'] as $mode) {
            $section = $this->optionName($mode . '_section');
            $fields[] = [
                'id' => $section,
                'type' => 'title',
                'title' => $mode === 'test' ? __('Test mode keys', 'stackless-lite-edd') : __('Live keys', 'stackless-lite-edd'),
                'desc' => $mode === 'test'
                    ? __('Test keys start with FLWSECK_TEST. Switch the Flutterwave dashboard to test mode to copy them and to set the test webhook.', 'stackless-lite-edd')
                    : self::keyButtons([
                        __('Get API keys', 'stackless-lite-edd') => 'https://app.flutterwave.com/dashboard/settings/apis/live',
                        __('Open webhook settings', 'stackless-lite-edd') => 'https://app.flutterwave.com/dashboard/settings/webhooks/live',
                    ]) . __('Use the v3 secret key. If your dashboard offers “Switch to v4 live API keys”, stay on v3.', 'stackless-lite-edd'),
            ];
            if (!$this->isDefined($mode, 'secret_key')) {
                $fields[] = [
                    'id' => $this->optionName($mode . '_secret_key'),
                    'type' => 'password',
                    'title' => $mode === 'test' ? __('Test secret key', 'stackless-lite-edd') : __('Live secret key', 'stackless-lite-edd'),
                    'placeholder' => $mode === 'test' ? 'FLWSECK_TEST-…' : 'FLWSECK-…',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'secret_hash')) {
                $fields[] = [
                    'id' => $this->optionName($mode . '_secret_hash'),
                    'type' => 'password',
                    'title' => $mode === 'test' ? __('Test webhook secret hash', 'stackless-lite-edd') : __('Live webhook secret hash', 'stackless-lite-edd'),
                    'desc' => __('Any long random text. Enter the same value as the Secret hash in Flutterwave’s webhook settings.', 'stackless-lite-edd'),
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
            'desc' => __('Flutterwave takes one-time payments. Subscription downloads can still be bought with Flutterwave when they renew by pay link: each renewal sends the customer an email with a link to pay. Which currencies and methods work depends on your Flutterwave account’s country and settings.', 'stackless-lite-edd'),
        ];
        $fields[] = ['id' => $this->optionName('limits_section'), 'type' => 'sectionend'];
        return $fields;
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
        return rest_url(Webhook::REST_NAMESPACE . '/flutterwave/webhook');
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: %s: webhook URL */
            __('In the Flutterwave dashboard, open Settings → Webhooks (once in test mode, once in live mode), choose the V3 webhook, paste this URL, enter your secret hash, and tick “Receive webhook response in JSON format”, “Enable webhook retries” and “Enable webhook for refunds”: %s Webhooks confirm payments when a buyer closes the tab before returning to your store, and bring refunds made in Flutterwave into EDD. Flutterwave does not let plugins add the webhook for you.', 'stackless-lite-edd'),
            '<br><code>' . esc_html(self::webhookUrl()) . '</code><br>'
        );
    }

    /**
     * After a settings save: refuse keys pasted into the other mode's field, check new keys
     * with Flutterwave once, and say what is missing once the gateway is on.
     *
     * @return string[] error messages
     */
    public function afterSave(): array
    {
        $errors = [];
        $checked = get_option(self::CHECKED_OPTION, []);
        $checked = is_array($checked) ? $checked : [];
        foreach (['live', 'test'] as $mode) {
            $key = $this->secretKey($mode);
            if ($key === '') {
                unset($checked[$mode]);
                continue;
            }
            $keyMode = EventMap::keyMode($key);
            if ($keyMode !== '' && $keyMode !== $mode) {
                $errors[] = $mode === 'test'
                    ? __('The Flutterwave test secret key field holds a live key. Paste the key that starts with FLWSECK_TEST.', 'stackless-lite-edd')
                    : __('The Flutterwave live secret key field holds a test key. Paste the live v3 secret key.', 'stackless-lite-edd');
                continue;
            }
            $fingerprint = self::fingerprint($key);
            if (($checked[$mode] ?? '') === $fingerprint) {
                continue;
            }
            $check = $this->api($mode)->request('GET', 'transactions', ['page' => 1]);
            if (is_wp_error($check)) {
                $errors[] = $check->get_error_message();
                continue;
            }
            $checked[$mode] = $fingerprint;
        }
        update_option(self::CHECKED_OPTION, $checked, false);

        if ($this->isEnabled()) {
            $mode = $this->mode();
            if ($this->secretKey($mode) === '') {
                $errors[] = __('Flutterwave is turned on but has no secret key for the selected mode.', 'stackless-lite-edd');
            }
            if ($this->secretHash($mode) === '') {
                $errors[] = __('Add the Flutterwave webhook secret hash. Payments and refunds stay in sync through the webhook.', 'stackless-lite-edd');
            }
        }
        return $errors;
    }

    private static function fingerprint(string $key): string
    {
        return substr(hash('sha256', 'stackless-edd-flutterwave|' . $key), 0, 16);
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** test|live */
    public function mode(): string
    {
        return $this->setting('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a value is pinned in wp-config.php, e.g. STACKLESS_EDD_FLUTTERWAVE_LIVE_SECRET_KEY. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_FLUTTERWAVE_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
    }

    private function secret(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        return trim((string) $this->setting($mode . '_' . $field, ''));
    }

    public function secretKey(string $mode = ''): string
    {
        return $this->secret($mode !== '' ? $mode : $this->mode(), 'secret_key');
    }

    public function secretHash(string $mode = ''): string
    {
        return $this->secret($mode !== '' ? $mode : $this->mode(), 'secret_hash');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->secretKey($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(int $orderId): string
    {
        $mode = Orders::meta($orderId, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /**
     * edd_payment_details_transaction_id-{gateway}: link the transaction to the Flutterwave dashboard.
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
        $url = 'https://app.flutterwave.com/dashboard/transactions/' . rawurlencode($transactionId);
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transactionId) . '</a>';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->setting('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('flutterwave_gateway', $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        return $this->secretKey() !== '' && Money::supported(stackless_edd_current_currency());
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function fields(): void
    {
        stackless_edd_get_template('flutterwave_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'testmode' => $this->mode() === 'test',
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        $currency = stackless_edd_current_currency();
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            $this->fail(sprintf(__('Flutterwave cannot charge in %s. Choose another payment method.', 'stackless-lite-edd'), strtoupper($currency)));
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
            $url = new \WP_Error('stackless_edd_flutterwave_checkout', __('Flutterwave could not start the payment. Please try again or choose another payment method.', 'stackless-lite-edd'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Flutterwave checkout could not start: %s', 'stackless-lite-edd'), $url->get_error_message()));
            $this->resumeLater($orderId);
            $this->fail($url->get_error_message());
            return;
        }
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Flutterwave's hosted checkout.
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
     * Refund through Flutterwave's v3 API (POST /transactions/{id}/refund).
     *
     * @return array{id:string,status:string}|\WP_Error
     */
    public function refund(Order $order, float $amount, string $reason = '')
    {
        $orderId = (int) $order->id;
        if ($amount <= 0) {
            return new \WP_Error('stackless_edd_flutterwave_refund', __('Enter a refund amount above zero.', 'stackless-lite-edd'));
        }
        $transaction = Orders::meta($orderId, self::META_TRANSACTION_ID);
        if ($transaction === '') {
            return new \WP_Error('stackless_edd_flutterwave_refund', __('This payment has no Flutterwave transaction to refund.', 'stackless-lite-edd'));
        }

        $currency = strtoupper((string) $order->currency);
        $body = ['amount' => Money::format($amount, $currency)];
        $reason = trim(wp_strip_all_tags($reason));
        if ($reason !== '') {
            $body['comments'] = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
        }
        $api = $this->api($this->orderMode($orderId));
        // Under the order's refund lock, so a refund webhook can't mirror this refund before its id is stored.
        $refund = Lock::run('refund-' . $orderId, static function () use ($api, $transaction, $body, $orderId) {
            $refund = $api->request('POST', 'transactions/' . rawurlencode($transaction) . '/refund', $body);
            if (is_wp_error($refund)) {
                return $refund;
            }
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '' || EventMap::refundState((string) ($refund['status'] ?? '')) === 'failed') {
                return new \WP_Error('stackless_edd_flutterwave_refund', __('Flutterwave could not process this refund. Check the transaction in your Flutterwave dashboard.', 'stackless-lite-edd'));
            }
            Sync::recordRefundId($orderId, $refundId);
            return $refund;
        }, null);
        if ($refund === null) {
            return new \WP_Error('stackless_edd_flutterwave_refund', __('This payment is being updated by another request. Try the refund again in a moment.', 'stackless-lite-edd'));
        }
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $orderId . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $refundId = (string) $refund['id'];
        $status = (string) ($refund['status'] ?? 'pending');
        Orders::note($orderId, sprintf(
            /* translators: 1: amount, 2: Flutterwave refund id, 3: status */
            __('Refunded %1$s through Flutterwave (refund %2$s, %3$s).', 'stackless-lite-edd'),
            Orders::money($amount, $currency),
            $refundId,
            $status
        ));
        return ['id' => $refundId, 'status' => $status];
    }
}
