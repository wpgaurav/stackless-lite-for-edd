<?php

namespace StacklessEDD\Modules\PayPalGateway;

use StacklessEDD\Admin\Fields;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public const SCRIPT = 'stackless-edd-paypal_gateway-checkout';
    public const SDK = 'stackless-edd-paypal_gateway-sdk';
    public const STYLE = 'stackless-edd-paypal_gateway';

    public function id(): string
    {
        return 'paypal_gateway';
    }

    public function title(): string
    {
        return __('PayPal', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('PayPal Smart Buttons and Pay Later at checkout, refunds, and saved PayPal accounts that renew subscriptions automatically. Uses PayPal’s REST APIs directly.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
        Tokens::install();
    }

    public function boot(): void
    {
        $gateway = Gateway::instance();
        Gateways::add($gateway);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('template_redirect', [$this, 'maybeHandleReturn'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('edd_transition_order_status', [$this, 'onStatusChange'], 10, 3);
        add_filter('edd_payment_details_transaction_id-' . Gateway::ID, [$gateway, 'linkTransaction'], 10, 2);
        add_action('stackless_edd_settings_saved_paypal_gateway', [$this, 'settingsSaved']);
        Ajax::register();
        Refunds::register();
        Account::register();
    }

    /* ---- Settings ------------------------------------------------------------- */

    public function settingsFields(): array
    {
        $p = Gateway::OPTION_PREFIX;
        $payments = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        return [
            [
                'id' => $p . 'about',
                'type' => 'title',
                'title' => __('PayPal', 'stackless-lite-edd'),
                'desc' => sprintf(
                    /* translators: 1: link to the EDD payment gateways screen, 2: gateway name */
                    __('Turn on %2$s in %1$s once the credentials below are saved.', 'stackless-lite-edd'),
                    '<a href="' . esc_url($payments) . '">' . esc_html__('Downloads → Settings → Payments', 'stackless-lite-edd') . '</a>',
                    '<strong>' . esc_html__('PayPal (Stackless)', 'stackless-lite-edd') . '</strong>'
                ),
            ],
            [
                'id' => $p . 'title',
                'type' => 'text',
                'title' => __('Title', 'stackless-lite-edd'),
                'default' => __('PayPal', 'stackless-lite-edd'),
                'desc_tip' => __('Shown to buyers at checkout.', 'stackless-lite-edd'),
            ],
            [
                'id' => $p . 'description',
                'type' => 'textarea',
                'title' => __('Description', 'stackless-lite-edd'),
                'default' => __('Pay with your PayPal account, Pay Later, or a card through PayPal.', 'stackless-lite-edd'),
            ],
            [
                'id' => $p . 'sandbox',
                'type' => 'checkbox',
                'title' => __('Sandbox', 'stackless-lite-edd'),
                'desc' => __('Use the PayPal sandbox (no real money moves)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            ['id' => $p . 'about', 'type' => 'sectionend'],

            [
                'id' => $p . 'live_section',
                'type' => 'title',
                'title' => __('Live credentials', 'stackless-lite-edd'),
                'desc' => __('From developer.paypal.com → Apps & Credentials (Live). The app needs the Vault feature turned on for subscriptions.', 'stackless-lite-edd'),
            ],
            ['id' => $p . 'client_id', 'type' => 'text', 'title' => __('Client ID', 'stackless-lite-edd'), 'default' => ''],
            ['id' => $p . 'client_secret', 'type' => 'password', 'title' => __('Secret', 'stackless-lite-edd'), 'default' => ''],
            [
                'id' => $p . 'webhook_id',
                'type' => 'text',
                'title' => __('Webhook ID', 'stackless-lite-edd'),
                'default' => '',
                'desc' => __('The ID PayPal shows after you add the webhook below to the live app.', 'stackless-lite-edd'),
            ],
            ['id' => $p . 'live_section', 'type' => 'sectionend'],

            [
                'id' => $p . 'sandbox_section',
                'type' => 'title',
                'title' => __('Sandbox credentials', 'stackless-lite-edd'),
                'desc' => __('From developer.paypal.com → Apps & Credentials (Sandbox).', 'stackless-lite-edd'),
            ],
            ['id' => $p . 'sandbox_client_id', 'type' => 'text', 'title' => __('Sandbox client ID', 'stackless-lite-edd'), 'default' => ''],
            ['id' => $p . 'sandbox_client_secret', 'type' => 'password', 'title' => __('Sandbox secret', 'stackless-lite-edd'), 'default' => ''],
            ['id' => $p . 'sandbox_webhook_id', 'type' => 'text', 'title' => __('Sandbox webhook ID', 'stackless-lite-edd'), 'default' => ''],
            ['id' => $p . 'sandbox_section', 'type' => 'sectionend'],

            [
                'id' => $p . 'webhook_section',
                'type' => 'title',
                'title' => __('Webhook', 'stackless-lite-edd'),
                'desc' => self::webhookInstructions(),
            ],
            ['id' => $p . 'webhook_section', 'type' => 'sectionend'],

            ['id' => $p . 'payment_section', 'type' => 'title', 'title' => __('Payments', 'stackless-lite-edd')],
            [
                'id' => $p . 'intent',
                'type' => 'select',
                'title' => __('Payment action', 'stackless-lite-edd'),
                'default' => 'capture',
                'options' => [
                    'capture' => __('Capture immediately', 'stackless-lite-edd'),
                    'authorize' => __('Authorize only (capture when the order status changes to Completed)', 'stackless-lite-edd'),
                ],
                'desc' => __('Subscription orders are always captured immediately. Revoking an authorized order voids the authorization.', 'stackless-lite-edd'),
            ],
            [
                'id' => $p . 'brand_name',
                'type' => 'text',
                'title' => __('Brand name', 'stackless-lite-edd'),
                'default' => '',
                'desc' => __('Shown on PayPal. Defaults to the site name.', 'stackless-lite-edd'),
            ],
            [
                'id' => $p . 'invoice_prefix',
                'type' => 'text',
                'title' => __('Invoice prefix', 'stackless-lite-edd'),
                'default' => 'EDD-',
                'desc' => __('PayPal rejects repeated invoice numbers, so use a unique prefix per store on the same PayPal account.', 'stackless-lite-edd'),
            ],
            ['id' => $p . 'payment_section', 'type' => 'sectionend'],

            ['id' => $p . 'buttons_section', 'type' => 'title', 'title' => __('Buttons', 'stackless-lite-edd')],
            [
                'id' => $p . 'button_layout',
                'type' => 'select',
                'title' => __('Layout', 'stackless-lite-edd'),
                'default' => 'vertical',
                'options' => ['vertical' => __('Vertical', 'stackless-lite-edd'), 'horizontal' => __('Horizontal', 'stackless-lite-edd')],
            ],
            [
                'id' => $p . 'button_color',
                'type' => 'select',
                'title' => __('Color', 'stackless-lite-edd'),
                'default' => 'gold',
                'options' => [
                    'gold' => __('Gold', 'stackless-lite-edd'),
                    'blue' => __('Blue', 'stackless-lite-edd'),
                    'silver' => __('Silver', 'stackless-lite-edd'),
                    'white' => __('White', 'stackless-lite-edd'),
                    'black' => __('Black', 'stackless-lite-edd'),
                ],
            ],
            [
                'id' => $p . 'button_shape',
                'type' => 'select',
                'title' => __('Shape', 'stackless-lite-edd'),
                'default' => 'rect',
                'options' => ['rect' => __('Rectangle', 'stackless-lite-edd'), 'pill' => __('Pill', 'stackless-lite-edd'), 'sharp' => __('Sharp', 'stackless-lite-edd')],
            ],
            [
                'id' => $p . 'button_label',
                'type' => 'select',
                'title' => __('Label', 'stackless-lite-edd'),
                'default' => 'paypal',
                'options' => [
                    'paypal' => 'PayPal',
                    'checkout' => __('PayPal Checkout', 'stackless-lite-edd'),
                    'buynow' => __('Buy Now', 'stackless-lite-edd'),
                    'pay' => __('Pay with PayPal', 'stackless-lite-edd'),
                ],
            ],
            [
                'id' => $p . 'paylater_messaging',
                'type' => 'checkbox',
                'title' => __('Pay Later messaging', 'stackless-lite-edd'),
                'desc' => __('Show Pay Later offers under the buttons where PayPal supports them', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => $p . 'debug',
                'type' => 'checkbox',
                'title' => __('Debug log', 'stackless-lite-edd'),
                'desc' => __('Log API activity to Downloads → Tools → Debug Log (stackless-edd-paypal_gateway) while EDD debug mode is on. Secrets are never logged.', 'stackless-lite-edd'),
                'default' => 'no',
            ],
            ['id' => $p . 'buttons_section', 'type' => 'sectionend'],
        ];
    }

    private static function webhookInstructions(): string
    {
        $events = '<code>' . implode('</code> <code>', array_map('esc_html', Webhook::EVENTS)) . '</code>';
        return sprintf(
            /* translators: 1: webhook URL, 2: list of event names */
            __('Add this URL as a webhook in each PayPal app (live and sandbox): %1$s Subscribe to: %2$s Then paste the webhook ID above.', 'stackless-lite-edd'),
            '<br><code>' . esc_html(Webhook::url()) . '</code><br>',
            '<br>' . $events . '<br>'
        );
    }

    /** Check the credentials of the environment in use once the gateway is on. */
    public function settingsSaved(): void
    {
        $gateway = Gateway::instance();
        foreach (['live', 'sandbox'] as $environment) {
            $gateway->apiFor($environment)->forgetToken();
        }
        if (!$gateway->isEnabled()) {
            return;
        }
        $api = $gateway->api();
        if (!$api->hasCredentials()) {
            Fields::addError(__('PayPal is turned on but the client ID or secret for the selected environment is empty.', 'stackless-lite-edd'));
        } else {
            $token = $api->accessToken(true);
            if (is_wp_error($token)) {
                Fields::addError($token->get_error_message());
            }
        }
        if ($gateway->webhookId() === '') {
            Fields::addError(__('Add the PayPal webhook ID. Without it, refunds made in PayPal, delayed payments and saved-account changes will not reach EDD.', 'stackless-lite-edd'));
        }
        // The settings screen stored its errors before this action ran.
        Fields::persistErrors();
    }

    /* ---- Requests ------------------------------------------------------------- */

    /** The buyer comes back from PayPal's approval page (redirect fallback). */
    public function maybeHandleReturn(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authenticated in Payments::handleReturn().
        if (!isset($_GET[Payments::RETURN_ARG])) {
            return;
        }
        Gateway::instance()->payments()->handleReturn();
    }

    /**
     * Capture an authorized payment when the store owner completes the order; void it when the
     * order is revoked or failed instead.
     *
     * @param mixed $old
     * @param mixed $new
     * @param mixed $orderId
     */
    public function onStatusChange($old, $new, $orderId): void
    {
        $order = Orders::get((int) $orderId);
        if (!$order || (string) $order->gateway !== Gateway::ID || $old === $new) {
            return;
        }
        if ($new === 'complete') {
            Gateway::instance()->payments()->captureAuthorization($order);
        } elseif (in_array($new, ['revoked', 'failed', 'abandoned'], true)) {
            Gateway::instance()->payments()->voidAuthorization($order);
        }
    }

    /* ---- Assets ---------------------------------------------------------------- */

    public static function registerScripts(): void
    {
        if (wp_script_is(self::SDK, 'registered')) {
            return;
        }
        $base = STACKLESS_EDD_PLUGIN_URL . 'assets/paypal_gateway/';
        wp_register_style(self::STYLE, $base . 'paypal.css', [], STACKLESS_EDD_VERSION);
        wp_register_script(self::SDK, $base . 'sdk.js', [], STACKLESS_EDD_VERSION, true);
        wp_register_script(self::SCRIPT, $base . 'checkout.js', ['jquery', self::SDK], STACKLESS_EDD_VERSION, true);
    }

    /** Settings the browser needs (no secrets: the client id is public by design). */
    public static function scriptData(Gateway $gateway): array
    {
        return [
            'gatewayId' => Gateway::ID,
            'clientId' => $gateway->clientId(),
            'sdkBase' => 'https://www.paypal.com/sdk/js',
            'sdkParams' => (array) apply_filters('stackless_edd_paypal_gateway_sdk_params', [
                'components' => $gateway->payLaterMessaging() ? 'buttons,messages' : 'buttons',
                'enable-funding' => $gateway->payLaterMessaging() ? 'paylater' : '',
                'commit' => 'true',
            ]),
            // Extra SDK parameters used only when saving an account without a purchase.
            'sdkSetupParams' => (array) apply_filters('stackless_edd_paypal_gateway_sdk_setup_params', []),
            'intent' => $gateway->intent(),
            'currencies' => Amount::currencies(),
            'style' => $gateway->buttonStyle(),
            'payLater' => $gateway->payLaterMessaging(),
            'ajax' => Ajax::urls(),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'fields' => ['order' => Gateway::FIELD_ORDER, 'setup' => Gateway::FIELD_SETUP],
            'i18n' => [
                'error' => __('PayPal could not start. Please try again or choose another payment method.', 'stackless-lite-edd'),
                'approved' => __('PayPal approved. Finishing your purchase…', 'stackless-lite-edd'),
                'saving' => __('Your PayPal account will be saved for future payments.', 'stackless-lite-edd'),
                'unavailable' => __('PayPal is not available for this currency.', 'stackless-lite-edd'),
                'cancelled' => __('You closed PayPal before finishing. Use the button again when you are ready.', 'stackless-lite-edd'),
                'savingAccount' => __('PayPal approved. Saving your account…', 'stackless-lite-edd'),
            ],
        ];
    }

    /**
     * Checkout page and the account page (saved PayPal accounts). EDD swaps the payment fields
     * in over AJAX when the buyer picks a gateway, so the script is loaded whenever the gateway
     * is on, and renders on edd_gateway_loaded.
     */
    public function enqueue(): void
    {
        $checkout = function_exists('edd_is_checkout') && edd_is_checkout();
        $account = is_user_logged_in() && is_singular() && (int) get_queried_object_id() === \StacklessEDD\Core\Account::pageId();
        if (!$checkout && !$account) {
            return;
        }
        $gateway = Gateway::instance();
        if (!$gateway->isEnabled() || !$gateway->api()->hasCredentials()) {
            return;
        }
        self::registerScripts();
        wp_enqueue_style(self::STYLE);
        wp_enqueue_script(self::SCRIPT);
        wp_localize_script(self::SCRIPT, 'stacklessEddPayPalData', self::scriptData($gateway));
    }
}
