<?php

namespace StacklessEDD\Modules\SquareGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Contracts\SavedMethodCharger;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Square for Easy Digital Downloads: the Web Payments SDK card form, Apple Pay and Google Pay in
 * EDD's purchase form, cards on file (Customers and Cards APIs) for saved cards and automatic
 * subscription renewals, refunds, and a signed webhook. Card data goes from the browser to
 * Square; this site only sees Square's one-time token. Square charges in the currency of the
 * business location only.
 *
 * EDD's own Square gateway (id "square") is a separate gateway with its own connection; both can
 * be enabled at the same time.
 */
class Gateway extends \StacklessEDD\Core\Gateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = 'stackless_edd_square';
    public const MODULE_ID = 'square_gateway';
    public const OPTION_PREFIX = 'stackless_edd_square_gateway_';

    public const META_PAYMENT_ID = '_stackless_edd_square_payment_id';
    public const META_MODE = '_stackless_edd_square_mode';
    public const META_REFUND_IDS = '_stackless_edd_square_refund_ids';
    public const META_CARD_ID = '_stackless_edd_square_card_id';
    public const USER_META_CUSTOMER = '_stackless_edd_square_customer_';

    /** One-time token from the card form or a wallet (cnon:... or a wallet token). */
    public const FIELD_TOKEN = 'stackless_edd_square_token';
    /** card, google_pay or apple_pay. */
    public const FIELD_METHOD = 'stackless_edd_square_method';
    /** Saved card row id, or "new". */
    public const FIELD_SAVED = 'stackless_edd_square_token_id';
    public const FIELD_SAVE = 'stackless_edd_square_save';
    /** Posted by the fields printed for a zero-total cart that must save a card (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_square_setup';

    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    /** @var Gateway|null */
    private static $instance;

    /** The registered gateway, or a standalone one when the module is off (staging scripts, CLI). */
    public static function instance(): self
    {
        $registered = class_exists(Gateways::class) ? Gateways::get(self::ID) : null;
        if ($registered instanceof self) {
            return $registered;
        }
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

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
        return __('Square (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) $this->option('title', ''));
        return $title !== '' ? $title : __('Card, Apple Pay or Google Pay', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) $this->option('description', __('Pay securely with your card through Square.', 'stackless-lite-edd'));
    }

    /* ---- Settings -------------------------------------------------------------- */

    /**
     * @param mixed $default
     * @return mixed
     */
    public function option(string $key, $default = '')
    {
        return get_option(self::OPTION_PREFIX . $key, $default);
    }

    /** Update a setting (the webhook key and location filled in on save). */
    public function saveSetting(string $key, string $value): void
    {
        update_option(self::OPTION_PREFIX . $key, $value);
    }

    /** @return array<int,array<string,mixed>> */
    public function settingsFields(): array
    {
        $p = self::OPTION_PREFIX;
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'id' => $p . 'keys_section',
                'type' => 'title',
                'title' => __('Square application', 'stackless-lite-edd'),
                'desc' => '<a class="button" href="https://developer.squareup.com/apps" target="_blank" rel="noopener">' . esc_html__('Open the Square Developer Console', 'stackless-lite-edd') . '</a>' . "\n\n"
                    . esc_html__('Open your application, then copy its Application ID and access token for Production and for Sandbox. The location is filled in with your main location when left empty, and the webhook is created for you on save (it needs a personal access token, which these are).', 'stackless-lite-edd')
                    . ' ' . sprintf(
                        /* translators: 1: link to the EDD payment gateways screen, 2: gateway name */
                        esc_html__('Then turn on %2$s in %1$s.', 'stackless-lite-edd'),
                        '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>',
                        '<strong>' . esc_html__('Square (Stackless)', 'stackless-lite-edd') . '</strong>'
                    ),
            ],
            [
                'id' => $p . 'testmode',
                'type' => 'checkbox',
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'desc' => __('Use the Square sandbox (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            $label = $mode === 'test' ? __('Sandbox', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd');
            $map = [
                'app_id' => [__('application ID', 'stackless-lite-edd'), 'text', $mode === 'test' ? 'sandbox-sq0idb-…' : 'sq0idp-…'],
                'access_token' => [__('access token', 'stackless-lite-edd'), 'password', 'EAAA…'],
                'location_id' => [__('location ID', 'stackless-lite-edd'), 'text', ''],
                'webhook_signature_key' => [__('webhook signature key', 'stackless-lite-edd'), 'password', ''],
            ];
            foreach ($map as $field => [$name, $type, $placeholder]) {
                if ($this->isDefined($mode, $field)) {
                    continue;
                }
                $fields[] = ['id' => $p . $mode . '_' . $field, 'title' => $label . ' ' . $name, 'type' => $type, 'placeholder' => $placeholder, 'default' => '', 'css' => 'min-width:420px'];
            }
        }
        $fields[] = ['id' => $p . 'keys_section', 'type' => 'sectionend'];

        $fields[] = ['id' => $p . 'checkout_section', 'type' => 'title', 'title' => __('Checkout', 'stackless-lite-edd'), 'desc' => $this->locationLine()];
        $fields[] = ['id' => $p . 'title', 'type' => 'text', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Card, Apple Pay or Google Pay', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite-edd')];
        $fields[] = ['id' => $p . 'description', 'type' => 'textarea', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('Pay securely with your card through Square.', 'stackless-lite-edd')];
        $fields[] = [
            'id' => $p . 'wallets',
            'type' => 'multiselect',
            'title' => __('Wallets', 'stackless-lite-edd'),
            'options' => ['google_pay' => __('Google Pay', 'stackless-lite-edd'), 'apple_pay' => __('Apple Pay', 'stackless-lite-edd')],
            'default' => ['google_pay', 'apple_pay'],
            'desc' => __('Shown above the card form for one-time orders. Wallet payments cannot be saved, so subscription checkouts show the card form only. Apple Pay also needs your domain registered in the Square Developer Console (Apple Pay tab).', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'id' => $p . 'saved_cards',
            'type' => 'checkbox',
            'title' => __('Saved cards', 'stackless-lite-edd'),
            'desc' => __('Let signed-in customers save cards with Square. Subscription orders always save the card for renewals.', 'stackless-lite-edd'),
            'default' => 'yes',
        ];
        $fields[] = [
            'id' => $p . 'debug',
            'type' => 'checkbox',
            'title' => __('Debug log', 'stackless-lite-edd'),
            'desc' => __('Log Square activity to Downloads → Tools → Debug Log (stackless-edd-square_gateway). Tokens and card data are never logged. Errors are always logged.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = ['id' => $p . 'checkout_section', 'type' => 'sectionend'];

        $fields[] = [
            'id' => $p . 'webhook_section',
            'type' => 'title',
            'title' => __('Webhook', 'stackless-lite-edd'),
            'desc' => $this->webhookLine() . ' ' . sprintf(
                /* translators: 1: webhook URL, 2: event names */
                __('Created automatically when the signature key is empty. To add it by hand, open Webhooks → Subscriptions in your application, add this URL: %1$s with these events, and paste its signature key above: %2$s', 'stackless-lite-edd'),
                '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
                '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code>'
            ),
        ];
        $fields[] = ['id' => $p . 'webhook_section', 'type' => 'sectionend'];
        return $fields;
    }

    private function locationLine(): string
    {
        $location = $this->location();
        if (empty($location['id'])) {
            return '';
        }
        return sprintf(
            /* translators: 1: location name, 2: currency code */
            __('Charging through %1$s in %2$s. Square takes payments in the location\'s currency only, so Square is hidden when a cart is in another currency.', 'stackless-lite-edd'),
            '<strong>' . esc_html((string) ($location['name'] ?? $location['id'])) . '</strong>',
            '<strong>' . esc_html((string) ($location['currency'] ?? '')) . '</strong>'
        );
    }

    private function webhookLine(): string
    {
        $mode = $this->mode();
        $label = $mode === 'test' ? __('Sandbox', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd');
        return $this->webhookKey($mode) !== ''
            /* translators: %s: Sandbox or Production */
            ? sprintf(__('%s webhook: signature key saved.', 'stackless-lite-edd'), $label)
            /* translators: %s: Sandbox or Production */
            : sprintf(__('%s webhook: not set up yet.', 'stackless-lite-edd'), $label);
    }

    /**
     * After the settings were saved (WooCommerce's process_admin_options): find each mode's
     * location and currency, create the webhook, and report what is missing.
     *
     * @return string[] errors for the settings screen
     */
    public function afterSave(): array
    {
        $errors = [];
        $setup = new Setup($this);
        foreach (['live', 'test'] as $mode) {
            if ($this->accessToken($mode) === '') {
                delete_option($this->locationOption($mode));
                continue;
            }
            $location = $setup->location($mode);
            if (is_wp_error($location)) {
                $errors[] = $location->get_error_message();
                continue;
            }
            $webhook = $setup->webhook($mode);
            if (is_wp_error($webhook)) {
                $errors[] = $webhook->get_error_message();
            } elseif ($webhook !== '') {
                $this->log($webhook);
            }
        }
        if ($this->isEnabled()) {
            if ($this->appId() === '' || $this->accessToken() === '') {
                $errors[] = __('Square is enabled but the selected mode needs both an application ID and an access token.', 'stackless-lite-edd');
            }
            if ($this->webhookKey() === '') {
                $errors[] = __('Add the Square webhook signature key so refunds and card changes made in Square reach EDD.', 'stackless-lite-edd');
            }
        }
        return $errors;
    }

    /** test (sandbox) | live (production) */
    public function mode(): string
    {
        return $this->option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** STACKLESS_EDD_SQUARE_{LIVE|SANDBOX}_{APP_ID|ACCESS_TOKEN|LOCATION_ID|WEBHOOK_SIGNATURE_KEY} */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = self::constant($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function constant(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_SQUARE_' . ($mode === 'test' ? 'SANDBOX' : 'LIVE') . '_' . strtoupper($field);
    }

    private function setting(string $mode, string $field): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant(self::constant($mode, $field)));
        }
        return trim((string) $this->option($mode . '_' . $field, ''));
    }

    public function appId(string $mode = ''): string
    {
        return $this->setting($mode, 'app_id');
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->setting($mode, 'access_token');
    }

    public function webhookKey(string $mode = ''): string
    {
        return $this->setting($mode, 'webhook_signature_key');
    }

    public function locationId(string $mode = ''): string
    {
        $id = $this->setting($mode, 'location_id');
        if ($id === '') {
            $id = (string) ($this->location($mode)['id'] ?? '');
        }
        return $id;
    }

    /** The location id typed in the settings (pins excluded), '' for "use the main location". */
    public function savedLocationId(string $mode): string
    {
        return trim((string) $this->option(($mode === 'test' ? 'test' : 'live') . '_location_id', ''));
    }

    public function locationOption(string $mode): string
    {
        return self::OPTION_PREFIX . 'location_' . ($mode === 'test' ? 'test' : 'live');
    }

    public function webhookUrlOption(string $mode): string
    {
        return self::OPTION_PREFIX . 'webhook_url_' . ($mode === 'test' ? 'test' : 'live');
    }

    /** @return array{id?:string, name?:string, currency?:string, country?:string} */
    public function location(string $mode = ''): array
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        $location = get_option($this->locationOption($mode), []);
        return is_array($location) ? $location : [];
    }

    public function currency(string $mode = ''): string
    {
        return strtoupper((string) ($this->location($mode)['currency'] ?? ''));
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    /** The mode an order was paid in, even after the store switched modes. */
    public function orderMode(\EDD\Orders\Order $order): string
    {
        $mode = (string) Orders::meta((int) $order->id, self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    public function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . Webhook::ROUTE);
    }

    /** @return string[] google_pay and/or apple_pay */
    public function wallets(): array
    {
        $wallets = $this->option('wallets', ['google_pay', 'apple_pay']);
        return is_array($wallets) ? array_values(array_intersect(['google_pay', 'apple_pay'], $wallets)) : [];
    }

    public function savedCardsEnabled(): bool
    {
        return $this->option('saved_cards', 'yes') === 'yes';
    }

    public function debug(): bool
    {
        return $this->option('debug', 'no') === 'yes';
    }

    /**
     * Downloads → Tools → Debug Log, source stackless-edd-square_gateway. Warnings and errors always
     * log; info only with this module's debug log on. Never pass tokens or card data.
     *
     * @param array<string,mixed> $context
     */
    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
            Logger::log(self::MODULE_ID, $message, $level, $context);
            return;
        }
        if (!$this->debug() || !function_exists('edd_debug_log')) {
            return;
        }
        $line = sprintf('[stackless-edd-%s] %s %s', self::MODULE_ID, strtoupper($level), $message);
        if ($context) {
            $line .= ' ' . wp_json_encode($context);
        }
        edd_debug_log($line, true);
    }

    public static function settingsUrl(): string
    {
        return SettingsPage::url(self::MODULE_ID);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        if ($this->appId() === '' || $this->accessToken() === '' || $this->locationId() === '') {
            return false;
        }
        $currency = strtoupper(stackless_edd_current_currency());
        return $this->currency() === '' || $currency === $this->currency();
    }

    /** @return string[] ISO codes: the location's currency only (read by Currency Gateways). */
    public function supported_currencies(): array
    {
        $currency = $this->currency();
        return $currency !== '' ? [$currency] : Money::CURRENCIES;
    }

    /**
     * What the browser needs to tokenize: the SDK, ids, amount, currency and intent.
     *
     * @return array<string,mixed>
     */
    public function scriptData(string $flow = 'checkout'): array
    {
        $mode = $this->mode();
        $currency = $this->currency($mode) !== '' ? $this->currency($mode) : strtoupper(stackless_edd_current_currency());
        if ($flow === 'add') {
            $total = 0.0;
            $requiresSave = true;
        } else {
            $total = function_exists('edd_get_cart_total') && did_action('wp_loaded') ? (float) edd_get_cart_total() : 0.0;
            $requiresSave = stackless_edd_cart_requires_saved_payment_method();
        }
        $location = $this->location($mode);
        $billing = [];
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $billing = [
                'givenName' => (string) $user->first_name,
                'familyName' => (string) $user->last_name,
                'email' => (string) $user->user_email,
            ];
        }
        return [
            'billing' => array_filter($billing),
            'gatewayId' => self::ID,
            'sdk' => $mode === 'test' ? 'https://sandbox.web.squarecdn.com/v1/square.js' : 'https://web.squarecdn.com/v1/square.js',
            'appId' => $this->appId($mode),
            'locationId' => $this->locationId($mode),
            'country' => (string) ($location['country'] ?? ''),
            'currency' => $currency,
            'amount' => Money::display($total, $currency),
            'flow' => $flow,
            'requiresSave' => $requiresSave,
            'canSave' => $this->savedCardsEnabled() && is_user_logged_in() && $flow !== 'add',
            'wallets' => $requiresSave || $flow === 'add' ? [] : $this->wallets(),
            'fields' => ['token' => self::FIELD_TOKEN, 'method' => self::FIELD_METHOD, 'saved' => self::FIELD_SAVED, 'save' => self::FIELD_SAVE],
            'storeName' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'i18n' => [
                'loading' => __('Loading the card form…', 'stackless-lite-edd'),
                'failed' => __('Square could not load the card form. Reload the page or choose another payment method.', 'stackless-lite-edd'),
                'invalid' => __('Check your card details and try again.', 'stackless-lite-edd'),
                'verify' => __('Your bank could not verify the card. Try again or use another card.', 'stackless-lite-edd'),
                'testmode' => __('Sandbox: use Square\'s test cards; no real payment will be taken.', 'stackless-lite-edd'),
                'total' => __('Total', 'stackless-lite-edd'),
            ],
        ];
    }

    /* ---- Checkout fields ------------------------------------------------------- */

    public function fields(): void
    {
        $data = $this->scriptData('checkout');
        $user_id = get_current_user_id();
        $tokens = ($user_id > 0 && ($this->savedCardsEnabled() || $data['requiresSave'])) ? Tokens::forUser($user_id, $this->mode()) : [];
        Assets::enqueue();
        stackless_edd_get_template('square_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'testmode' => $this->mode() === 'test',
            'data' => $data,
            'tokens' => $tokens,
            'show_save' => $data['canSave'] && !$data['requiresSave'],
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
        $request = self::requestFrom(wp_unslash($post));
        if ($request['token_id'] > 0 || $request['source'] !== '') {
            return;
        }
        edd_set_error('stackless_edd_square_details', __('Enter your card details to continue.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form; handle() checked the gateway nonce.
        $post = wp_unslash($_POST);
        $request = self::requestFrom(is_array($post) ? $post : []);
        $order_id = $this->createPendingOrder($purchase_data);
        if ($order_id < 1) {
            return;
        }
        $result = $this->payOrder($order_id, $request);
        if (($result['result'] ?? '') !== 'success') {
            // The order stays pending: EDD resumes it on the next attempt.
            $this->fail((string) ($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite-edd')));
            return;
        }
        $order = Orders::get($order_id);
        if ($order && Orders::isPaid($order)) {
            $this->complete($order_id);
            return;
        }
        // Square is still processing the payment; its webhook completes the order.
        edd_empty_cart();
        edd_send_to_success_page();
    }

    /**
     * Pay a pending EDD order with what the shopper submitted. Checkout and the staging scripts
     * share this path.
     *
     * @param array{token_id?:int, source?:string, method?:string, save?:bool} $request
     * @return array{result:string, message?:string} result success (paid or processing) or failure
     */
    public function payOrder(int $order_id, array $request): array
    {
        $order = Orders::get($order_id);
        if (!$order) {
            return self::failure(__('Order not found.', 'stackless-lite-edd'));
        }
        $payments = new Payments($this);
        $token_id = (int) ($request['token_id'] ?? 0);
        if ($token_id > 0) {
            $token = Tokens::getOwned($token_id, (int) $order->user_id);
            if (!$token || $token->mode !== $this->mode()) {
                return self::failure(__('That saved card is not available. Choose another one.', 'stackless-lite-edd'));
            }
            $result = $payments->chargeCard($order, $token, 'checkout');
        } else {
            $source = self::cleanSource((string) ($request['source'] ?? ''));
            if ($source === '') {
                return self::failure(__('Enter your card details to continue.', 'stackless-lite-edd'));
            }
            $method = self::cleanMethod((string) ($request['method'] ?? 'card'));
            $requiresSave = stackless_edd_order_requires_saved_payment_method($order_id);
            if ($requiresSave && $method !== 'card') {
                return self::failure(__('Wallet payments cannot be saved for renewals. Pay with a card for this subscription.', 'stackless-lite-edd'));
            }
            $save = $requiresSave || (!empty($request['save']) && $this->savedCardsEnabled() && (int) $order->user_id > 0 && $method === 'card');
            $result = $payments->chargeNew($order, $source, $save);
        }
        if (in_array($result['result'], ['success', 'pending'], true)) {
            return ['result' => 'success'];
        }
        return self::failure($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite-edd'));
    }

    /** @return array{result:string, message:string} */
    private static function failure(string $message): array
    {
        return ['result' => 'failure', 'message' => $message];
    }

    /**
     * The fields this gateway reads from a posted purchase form.
     *
     * @param mixed $post unslashed
     * @return array{token_id:int, source:string, method:string, save:bool}
     */
    public static function requestFrom($post): array
    {
        $post = is_array($post) ? $post : [];
        $read = static function (string $key) use ($post): string {
            return isset($post[$key]) && is_scalar($post[$key]) ? sanitize_text_field((string) $post[$key]) : '';
        };
        $saved = $read(self::FIELD_SAVED);
        $save = strtolower($read(self::FIELD_SAVE));
        return [
            'token_id' => ($saved !== '' && $saved !== 'new') ? absint($saved) : 0,
            'source' => self::cleanSource($read(self::FIELD_TOKEN)),
            'method' => self::cleanMethod($read(self::FIELD_METHOD) !== '' ? $read(self::FIELD_METHOD) : 'card'),
            'save' => in_array($save, ['1', 'true', 'yes', 'on'], true),
        ];
    }

    /** A Square one-time token (cnon:... for cards, other prefixes for wallets), or ''. */
    public static function cleanSource(string $value): string
    {
        return preg_match('/^[A-Za-z0-9:_\-]{8,400}$/', $value) ? $value : '';
    }

    /** card, google_pay or apple_pay: wallet tokens cannot be stored. */
    public static function cleanMethod(string $value): string
    {
        $value = sanitize_key($value);
        return in_array($value, ['card', 'google_pay', 'apple_pay'], true) ? $value : 'card';
    }

    /* ---- Zero-total carts that must save a card (all-trial subscriptions) -------- */

    /**
     * EDD hides gateways and switches to "manual" when the cart total is zero. A cart whose
     * subscription renews automatically still needs a saved card, so offer the gateways then.
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
     * card is saved before the order completes.
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

    /** @return string|\WP_Error Square customer id */
    public function customerFor(int $user_id, string $mode, ?\EDD\Orders\Order $order = null)
    {
        $meta_key = self::USER_META_CUSTOMER . $mode;
        if ($user_id > 0) {
            $stored = (string) get_user_meta($user_id, $meta_key, true);
            if ($stored !== '') {
                return $stored;
            }
        }
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        [$first, $last] = $order ? Orders::names($order) : ['', ''];
        $body = array_filter([
            'idempotency_key' => Api::key('cus', $mode . '-' . ($user_id > 0 ? 'u' . $user_id : 'o' . ($order ? (int) $order->id : wp_generate_password(8, false)))),
            'given_name' => $first !== '' ? $first : ($user ? (string) $user->first_name : ''),
            'family_name' => $last !== '' ? $last : ($user ? (string) $user->last_name : ''),
            'email_address' => $order && is_email((string) $order->email) ? (string) $order->email : ($user ? (string) $user->user_email : ''),
            'reference_id' => $user_id > 0 ? 'wp_user_' . $user_id : ($order ? 'edd_order_' . (int) $order->id : ''),
        ]);
        if (count($body) < 2) {
            $body['email_address'] = (string) get_option('admin_email');
        }
        $created = $this->api($mode)->request('POST', 'customers', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['customer']['id'] ?? '');
        if ($id === '') {
            return new \WP_Error('stackless_edd_square_customer', __('Square did not create a customer.', 'stackless-lite-edd'));
        }
        if ($user_id > 0) {
            update_user_meta($user_id, $meta_key, $id);
        }
        return $id;
    }

    /* ---- Contracts -------------------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(\EDD\Orders\Order $renewal_order, SubscriptionData $subscription): array
    {
        return (new Payments($this))->renew($renewal_order, $subscription);
    }

    /** Stackless runs the renewal schedule, not Square, so nothing to cancel remotely. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_edd_charge_saved_method(\EDD\Orders\Order $order, string $token_id, bool $on_session): array
    {
        return (new Payments($this))->chargeSaved($order, $token_id, $on_session);
    }

    /** Points an order at a saved card (renewals and Subscriptions read it from there). */
    public static function attachToken(int $order_id, PaymentToken $token): void
    {
        Orders::updateMeta($order_id, SubscriptionData::META_TOKEN_ID, (string) $token->id);
        Orders::updateMeta($order_id, SubscriptionData::META_GATEWAY_CUSTOMER, $token->square_customer);
        Orders::updateMeta($order_id, self::META_CARD_ID, $token->card_id);
    }

    /**
     * Points a subscription (and its unpaid renewals waiting in dunning) at a saved card. Only
     * subscriptions renewing through this gateway; moving one from another gateway is the
     * Subscriptions module's job (it listens to stackless_edd_payment_method_saved).
     */
    public function updateSubscriptionMethod(SubscriptionData $subscription, PaymentToken $token): bool
    {
        if ($subscription->get_gateway() !== $this->id()) {
            return false;
        }
        $subscription->update_meta(SubscriptionData::META_TOKEN_ID, (string) $token->id);
        $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $token->square_customer);
        foreach (stackless_edd_get_subscription_orders($subscription->get_id()) as $order) {
            if ($order instanceof \EDD\Orders\Order && (string) $order->gateway === $this->id() && Orders::needsPayment($order) && stackless_edd_is_renewal_order((int) $order->id)) {
                self::attachToken((int) $order->id, $token);
            }
        }
        $parent = $subscription->get_parent_order_id();
        if ($parent > 0) {
            /* translators: 1: subscription id, 2: card, e.g. "Visa ending in 1111" */
            Orders::note($parent, sprintf(__('Subscription #%1$d now renews with %2$s.', 'stackless-lite-edd'), $subscription->get_id(), $token->displayName()));
        }
        return true;
    }
}
