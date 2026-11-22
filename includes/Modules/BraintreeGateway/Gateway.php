<?php

namespace StacklessEDD\Modules\BraintreeGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Contracts\SavedMethodCharger;
use StacklessEDD\Contracts\SubscriptionData;
use StacklessEDD\Contracts\SubscriptionGateway;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Braintree for Easy Digital Downloads: cards in Braintree's Hosted Fields with 3-D Secure in
 * EDD's purchase form and on Stackless pay links (which open that form), vaulted cards for
 * saved cards and automatic renewals, refunds and voids, and signed webhooks for disputes. Card
 * data goes from Braintree's iframes to Braintree; this site only sees a one-time nonce.
 */
class Gateway extends \StacklessEDD\Core\Gateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = 'stackless_edd_braintree';
    public const MODULE_ID = 'braintree_gateway';
    public const OPTION_PREFIX = 'stackless_edd_braintree_gateway_';

    public const FIELD_NONCE = 'stackless_edd_braintree_nonce';
    public const FIELD_DEVICE = 'stackless_edd_braintree_device_data';
    /** Saved card row id, or "new". */
    public const FIELD_SAVED = 'stackless_edd_braintree_token_id';
    public const FIELD_SAVE = 'stackless_edd_braintree_save';
    /** Posted by the fields printed for a zero-total cart that must save a card (EDD hands those to "manual"). */
    public const FIELD_SETUP = 'stackless_edd_braintree_setup';

    /**
     * A vaulted card takes any amount on any date as a merchant-initiated charge
     * (paymentInitiator RECURRING or UNSCHEDULED), and Stackless runs the schedule.
     */
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

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function setting(string $key, $default = '')
    {
        return get_option(self::OPTION_PREFIX . $key, $default);
    }

    /** Downloads → Tools → Debug Log, source stackless-edd-braintree_gateway. */
    public static function writeLog(string $message, string $level): void
    {
        if (in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
            Logger::log(self::MODULE_ID, $message, $level);
            return;
        }
        if (function_exists('edd_debug_log')) {
            edd_debug_log(sprintf('[stackless-edd-%s] %s %s', self::MODULE_ID, strtoupper($level), $message), true);
        }
    }

    public function id(): string
    {
        return self::ID;
    }

    public function adminLabel(): string
    {
        return __('Braintree (Stackless)', 'stackless-lite-edd');
    }

    public function checkoutLabel(): string
    {
        $title = trim((string) self::setting('title', ''));
        return $title !== '' ? $title : __('Credit or debit card', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return (string) self::setting('description', __('Pay securely with your card through Braintree.', 'stackless-lite-edd'));
    }

    public static function settingsUrl(): string
    {
        return SettingsPage::url(self::MODULE_ID);
    }

    /* ---- Settings -------------------------------------------------------------- */

    /** @return array<int,array<string,mixed>> */
    public function settingsFields(): array
    {
        $p = self::OPTION_PREFIX;
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        $fields = [
            [
                'id' => $p . 'keys_section',
                'type' => 'title',
                'title' => __('Braintree account', 'stackless-lite-edd'),
                'desc' => '<a class="button" href="https://www.braintreegateway.com/login" target="_blank" rel="noopener">' . esc_html__('Open the production Control Panel', 'stackless-lite-edd') . '</a> '
                    . '<a class="button" href="https://sandbox.braintreegateway.com/login" target="_blank" rel="noopener">' . esc_html__('Open the sandbox Control Panel', 'stackless-lite-edd') . '</a>' . "\n\n"
                    . esc_html__('In the Control Panel open the gear icon, then API, and copy the Merchant ID, public key and private key. Saving checks the connection and reads your merchant accounts.', 'stackless-lite-edd')
                    . ' ' . sprintf(
                        /* translators: 1: link to the EDD payment gateways screen, 2: gateway name */
                        esc_html__('Then turn on %2$s in %1$s.', 'stackless-lite-edd'),
                        '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>',
                        '<strong>' . esc_html__('Braintree (Stackless)', 'stackless-lite-edd') . '</strong>'
                    ),
            ],
            [
                'id' => $p . 'testmode',
                'type' => 'checkbox',
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'desc' => __('Use the Braintree sandbox (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
        ];
        foreach (Config::MODES as $mode) {
            $label = $mode === 'test' ? __('Sandbox', 'stackless-lite-edd') : __('Production', 'stackless-lite-edd');
            $map = [
                'merchant_id' => [__('Merchant ID', 'stackless-lite-edd'), 'text', ''],
                'public_key' => [__('public key', 'stackless-lite-edd'), 'text', ''],
                'private_key' => [__('private key', 'stackless-lite-edd'), 'password', ''],
                'merchant_accounts' => [__('merchant accounts', 'stackless-lite-edd'), 'text', 'EUR:store_eur, GBP:store_gbp'],
            ];
            foreach ($map as $field => [$name, $type, $placeholder]) {
                if (Config::isDefined($mode, $field)) {
                    continue;
                }
                $row = ['id' => $p . $mode . '_' . $field, 'title' => $label . ' ' . $name, 'type' => $type, 'placeholder' => $placeholder, 'default' => '', 'css' => 'min-width:420px'];
                if ($field === 'merchant_accounts') {
                    $row['desc'] = __('Optional. A merchant account takes one currency. Name one per currency only where you have several; otherwise the account in the order\'s currency is used.', 'stackless-lite-edd');
                }
                $fields[] = $row;
            }
        }
        $fields[] = ['id' => $p . 'keys_section', 'type' => 'sectionend'];

        $fields[] = ['id' => $p . 'checkout_section', 'type' => 'title', 'title' => __('Checkout', 'stackless-lite-edd'), 'desc' => $this->currencyLine()];
        $fields[] = ['id' => $p . 'title', 'type' => 'text', 'title' => __('Title', 'stackless-lite-edd'), 'default' => __('Credit or debit card', 'stackless-lite-edd'), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite-edd')];
        $fields[] = ['id' => $p . 'description', 'type' => 'textarea', 'title' => __('Description', 'stackless-lite-edd'), 'default' => __('Pay securely with your card through Braintree.', 'stackless-lite-edd')];
        $fields[] = [
            'id' => $p . 'three_d_secure',
            'type' => 'select',
            'title' => __('3-D Secure', 'stackless-lite-edd'),
            'default' => ThreeDSecure::REQUIRED,
            'options' => [
                ThreeDSecure::REQUIRED => __('Required (recommended): refuse failed or skipped bank checks', 'stackless-lite-edd'),
                ThreeDSecure::STRICT => __('Strict: accept only payments with a liability shift', 'stackless-lite-edd'),
                ThreeDSecure::OFF => __('Off', 'stackless-lite-edd'),
            ],
            'desc' => __('Checked on the server with Braintree, so it cannot be skipped from the browser. Braintree must switch on 3-D Secure for your account. European and UK cards need it.', 'stackless-lite-edd'),
        ];
        $fields[] = [
            'id' => $p . 'postal_code',
            'type' => 'checkbox',
            'title' => __('Postal code', 'stackless-lite-edd'),
            'desc' => __('Ask for the billing postal code in the card form. Turn this on if your Braintree account has address verification (AVS) rules.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = [
            'id' => $p . 'saved_cards',
            'type' => 'checkbox',
            'title' => __('Saved cards', 'stackless-lite-edd'),
            'desc' => __('Let signed-in customers save cards with Braintree. Subscription orders always save the card for renewals.', 'stackless-lite-edd'),
            'default' => 'yes',
        ];
        $fields[] = [
            'id' => $p . 'device_data',
            'type' => 'checkbox',
            'title' => __('Fraud tools', 'stackless-lite-edd'),
            'desc' => __('Send device data for Braintree\'s fraud tools. Turn this on if your account uses Advanced Fraud Tools or Fraud Protection; it loads PayPal\'s device script at checkout.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = [
            'id' => $p . 'debug',
            'type' => 'checkbox',
            'title' => __('Debug log', 'stackless-lite-edd'),
            'desc' => __('Log Braintree activity to Downloads → Tools → Debug Log (stackless-edd-braintree_gateway). Keys, tokens and card data are never logged. Errors are always logged.', 'stackless-lite-edd'),
            'default' => 'no',
        ];
        $fields[] = ['id' => $p . 'checkout_section', 'type' => 'sectionend'];

        $fields[] = [
            'id' => $p . 'webhook_section',
            'type' => 'title',
            'title' => __('Webhook', 'stackless-lite-edd'),
            'desc' => sprintf(
                /* translators: 1: webhook URL, 2: event names */
                __('In the Control Panel open API, then Webhooks, and create one with this URL: %1$s and these notifications: %2$s Card payments, refunds and voids send no webhook, so Stackless also asks Braintree for those every six hours. A payment that has not settled (usually within a day or two) can only be refunded whole, which voids it; a part can be refunded once it settles.', 'stackless-lite-edd'),
                '<br><code>' . esc_html(Config::webhookUrl()) . '</code><br>',
                '<br><code>' . implode('</code> <code>', array_map('esc_html', Webhook::EVENTS)) . '</code><br>'
            ),
        ];
        $fields[] = ['id' => $p . 'webhook_section', 'type' => 'sectionend'];
        return $fields;
    }

    private function currencyLine(): string
    {
        $currencies = Connection::currencies(Config::mode());
        if (!$currencies) {
            return '';
        }
        /* translators: %s: currency codes */
        return sprintf(__('Braintree shows for carts in %s, the currencies of your active merchant accounts, and hides for the rest.', 'stackless-lite-edd'), '<strong>' . esc_html(implode(', ', $currencies)) . '</strong>');
    }

    /**
     * After the settings were saved: check each connection and report what is missing.
     *
     * @return string[] errors for the settings screen
     */
    public function afterSave(): array
    {
        $errors = [];
        foreach (Config::MODES as $mode) {
            if (!Config::hasKeys($mode)) {
                delete_option(Connection::option($mode));
                continue;
            }
            $errors = array_merge($errors, Connection::check($mode));
        }
        if (Config::hasKeys(Config::mode())) {
            Sync::ensureScheduled();
        } elseif ($this->isEnabled()) {
            $errors[] = __('Braintree is enabled but the selected mode needs a Merchant ID, a public key and a private key.', 'stackless-lite-edd');
        }
        return $errors;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function isAvailable(): bool
    {
        $mode = Config::mode();
        if (!Config::hasKeys($mode)) {
            return false;
        }
        $connection = Connection::ensure($mode);
        return $connection && Connection::merchantAccount($mode, stackless_edd_current_currency(), $connection);
    }

    /** @return string[] ISO codes with a merchant account (read by Currency Gateways). */
    public function supported_currencies(): array
    {
        return Connection::currencies(Config::mode());
    }

    /**
     * What the browser needs. No key, token or amount to charge is taken from it.
     *
     * @return array<string,mixed>
     */
    public function scriptData(string $flow = 'checkout'): array
    {
        $billing = [];
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $billing = ['first' => (string) $user->first_name, 'last' => (string) $user->last_name, 'email' => (string) $user->user_email];
        }
        return [
            'gatewayId' => self::ID,
            'version' => Browser::SDK_VERSION,
            'scripts' => Browser::scripts(),
            'secure' => Config::threeDSecure() !== ThreeDSecure::OFF,
            'deviceData' => Config::deviceData(),
            'endpoint' => admin_url('admin-ajax.php'),
            'action' => Checkout::SESSION_ACTION,
            'security' => wp_create_nonce(Checkout::SESSION_ACTION),
            'flow' => $flow,
            'requiresSave' => $flow === 'add' || stackless_edd_cart_requires_saved_payment_method(),
            'canSave' => Config::savedCards() && is_user_logged_in() && $flow !== 'add',
            'billing' => array_filter($billing),
            'fields' => ['nonce' => self::FIELD_NONCE, 'device' => self::FIELD_DEVICE, 'saved' => self::FIELD_SAVED, 'save' => self::FIELD_SAVE],
            'i18n' => Browser::messages() + [
                'number' => __('Card number', 'stackless-lite-edd'),
                'expiry' => __('Expiry date', 'stackless-lite-edd'),
                'cvv' => __('Security code', 'stackless-lite-edd'),
                'postal' => __('Billing postal code', 'stackless-lite-edd'),
                'testmode' => __('Sandbox: use Braintree\'s test cards; no real payment will be taken.', 'stackless-lite-edd'),
            ],
        ];
    }

    /* ---- Checkout fields ------------------------------------------------------- */

    public function fields(): void
    {
        $data = $this->scriptData('checkout');
        $user_id = get_current_user_id();
        $tokens = ($user_id > 0 && (Config::savedCards() || $data['requiresSave'])) ? Tokens::forUser($user_id) : [];
        Assets::enqueue();
        stackless_edd_get_template('braintree_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->description(),
            'testmode' => Config::mode() === 'test',
            'postal' => self::setting('postal_code', 'no') === 'yes',
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
        if ($request['token_id'] > 0 || $request['nonce'] !== '') {
            return;
        }
        edd_set_error('stackless_edd_braintree_details', __('Enter your card details to continue.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
    }

    /* ---- Payments --------------------------------------------------------------- */

    protected function process(array $purchase_data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the purchase form; handle() checked the gateway nonce.
        $post = wp_unslash($_POST);
        $request = self::requestFrom(is_array($post) ? $post : []);
        $price = isset($purchase_data['price']) ? (float) $purchase_data['price'] : 0.0;
        // EDD's gateway list is not filtered on admin-ajax, so the currency is checked again here.
        if ($price > 0 && !$this->isAvailable()) {
            $this->fail(__('Braintree does not take payments in this currency here. Choose another payment method.', 'stackless-lite-edd'));
            return;
        }
        $order_id = $this->createPendingOrder($purchase_data);
        if ($order_id < 1) {
            return;
        }
        $result = $this->payOrder($order_id, $request);
        if ($result['result'] === 'failure') {
            // The order stays pending: EDD resumes it on the next attempt.
            $this->fail($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite-edd'));
            return;
        }
        if ($result['result'] === 'success') {
            $this->complete($order_id);
            return;
        }
        // Braintree has not confirmed yet; the scheduled check completes or fails the order.
        edd_empty_cart();
        edd_send_to_success_page();
    }

    /**
     * Pay a pending EDD order with what the shopper submitted. Checkout and the staging scripts
     * share this path.
     *
     * @param array{token_id?:int, nonce?:string, device_data?:string, save?:bool} $request
     * @return array{result:string, message?:string} success (paid), pending (being confirmed) or failure
     */
    public function payOrder(int $order_id, array $request): array
    {
        $result = (new Payments())->checkout($order_id, $request);
        if ($result['result'] === 'success') {
            return ['result' => 'success'];
        }
        if ($result['result'] === 'pending' && empty($result['busy'])) {
            return ['result' => 'pending'];
        }
        return ['result' => 'failure', 'message' => (string) ($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite-edd'))];
    }

    /**
     * The fields this gateway reads from a posted purchase form.
     *
     * @param mixed $post unslashed
     * @return array{token_id:int, nonce:string, device_data:string, save:bool}
     */
    public static function requestFrom($post): array
    {
        $post = is_array($post) ? $post : [];
        $read = static function (string $key) use ($post): string {
            return isset($post[$key]) && is_scalar($post[$key]) ? sanitize_text_field((string) $post[$key]) : '';
        };
        $saved = $read(self::FIELD_SAVED);
        return [
            'token_id' => ($saved !== '' && $saved !== 'new') ? absint($saved) : 0,
            'nonce' => Payments::cleanNonce($read(self::FIELD_NONCE)),
            'device_data' => Payments::cleanDeviceData($read(self::FIELD_DEVICE)),
            'save' => in_array(strtolower($read(self::FIELD_SAVE)), ['1', 'true', 'yes', 'on'], true),
        ];
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

    /* ---- Contracts -------------------------------------------------------------- */

    public function stackless_edd_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /** @return array{result:string, message?:string} */
    public function stackless_edd_charge_renewal(\EDD\Orders\Order $renewal_order, SubscriptionData $subscription): array
    {
        return self::contractResult((new Payments())->renew(
            (int) $renewal_order->id,
            (int) $subscription->get_meta(SubscriptionData::META_TOKEN_ID),
            (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER)
        ));
    }

    /** Stackless runs the renewal schedule, not Braintree, so nothing to cancel remotely. */
    public function stackless_edd_cancel_subscription(SubscriptionData $subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_edd_charge_saved_method(\EDD\Orders\Order $order, string $token_id, bool $on_session): array
    {
        return self::contractResult((new Payments())->chargeSaved((int) $order->id, ctype_digit($token_id) ? (int) $token_id : 0, $on_session));
    }

    /**
     * @param array<string,mixed> $result
     * @return array{result:string, message?:string, redirect?:string}
     */
    private static function contractResult(array $result): array
    {
        $out = ['result' => (string) $result['result']];
        foreach (['message', 'redirect'] as $key) {
            if (!empty($result[$key])) {
                $out[$key] = (string) $result[$key];
            }
        }
        return $out;
    }

    /**
     * Points a subscription (and its unpaid renewals waiting in dunning) at a saved card. Only
     * subscriptions renewing through this gateway; moving one from another gateway is the
     * Subscriptions module's job (it listens to stackless_edd_payment_method_saved).
     */
    public function updateSubscriptionMethod(SubscriptionData $subscription, Card $card): bool
    {
        if ($subscription->get_gateway() !== $this->id()) {
            return false;
        }
        $subscription->update_meta(SubscriptionData::META_TOKEN_ID, (string) $card->id);
        $subscription->update_meta(SubscriptionData::META_GATEWAY_CUSTOMER, $card->customer);
        foreach (stackless_edd_get_subscription_orders($subscription->get_id()) as $order) {
            if (!$order instanceof \EDD\Orders\Order || (string) $order->gateway !== $this->id() || !stackless_edd_is_renewal_order((int) $order->id)) {
                continue;
            }
            $summary = Orders::summary((int) $order->id);
            if ($summary && $summary['payable']) {
                Orders::setMeta((int) $order->id, [SubscriptionData::META_TOKEN_ID => (string) $card->id, SubscriptionData::META_GATEWAY_CUSTOMER => $card->customer]);
            }
        }
        $parent = $subscription->get_parent_order_id();
        if ($parent > 0) {
            /* translators: 1: subscription id, 2: card, e.g. "Visa ending in 1111" */
            Orders::note($parent, sprintf(__('Subscription #%1$d now renews with %2$s.', 'stackless-lite-edd'), $subscription->get_id(), $card->label()));
        }
        return true;
    }
}
