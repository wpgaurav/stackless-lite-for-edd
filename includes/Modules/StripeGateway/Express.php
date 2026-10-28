<?php

namespace StacklessEDD\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Express Checkout Element (Apple Pay, Google Pay, Link) on download pages and above the checkout.
 *
 * The wallet sheet collects the shopper's name, email and billing address; these admin-ajax
 * endpoints keep the EDD cart in step (billing address → taxes → totals) and finally run EDD's own
 * purchase processing (edd_process_purchase_form) with the wallet details, so discounts, taxes,
 * fees, required checkout fields, receipts and other plugins' checkout hooks behave exactly as on
 * a normal checkout. The gateway answers that request with JSON instead of a redirect.
 *
 * On a download page the wallet buys only that download: the shopper's existing cart is put
 * aside in the session and restored when the sheet is cancelled or after the purchase.
 */
final class Express
{
    public const NONCE = 'stackless_edd_stripe_gateway_express';
    public const ACTION_UPDATE = 'stackless_edd_stripe_express_update';
    public const ACTION_CHECKOUT = 'stackless_edd_stripe_express_checkout';
    public const ACTION_CANCEL = 'stackless_edd_stripe_express_cancel';
    private const SESSION_SAVED_CART = 'stackless_edd_stripe_express_saved_cart';
    private const SESSION_PRODUCT_MODE = 'stackless_edd_stripe_express_product';

    /** @var bool */
    private static $rendered = false;

    public static function registerHooks(): void
    {
        if (Settings::expressEnabledOn('product')) {
            add_action('edd_purchase_link_end', [self::class, 'renderProduct'], 20, 2);
        }
        if (Settings::expressEnabledOn('checkout')) {
            add_action('edd_before_purchase_form', [self::class, 'renderCheckout'], 15);
        }
        foreach ([self::ACTION_UPDATE => 'ajaxUpdate', self::ACTION_CHECKOUT => 'ajaxCheckout', self::ACTION_CANCEL => 'ajaxCancel'] as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, $method]);
        }
        add_action('template_redirect', [self::class, 'maybeRestoreAfterOrder'], 99);
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    /**
     * Under the purchase button of the download being viewed (not in download lists).
     *
     * @param mixed $download_id
     * @param mixed $args
     */
    public static function renderProduct($download_id, $args = []): void
    {
        $download_id = (int) $download_id;
        if (!is_singular('download') || $download_id !== (int) get_queried_object_id() || !self::canShow()) {
            return;
        }
        $download = edd_get_download($download_id);
        if (!$download || $download->post_status !== 'publish' || (!$download->has_variable_prices() && $download->is_free())) {
            return;
        }
        $currency = stackless_edd_current_currency();
        $variable = $download->has_variable_prices();
        $price_id = $variable && function_exists('edd_get_default_variable_price') ? edd_get_default_variable_price($download_id) : null;
        $base = $variable && $price_id !== null && $price_id !== false ? (float) edd_get_price_option_amount($download_id, (int) $price_id) : (float) $download->get_price();
        $price = (float) stackless_edd_convert_amount($base, $currency);
        if (!$variable && ($price <= 0 || !Amount::meetsMinimum($price, $currency))) {
            return;
        }
        self::output('product', [
            'productId' => $download_id,
            'productType' => $variable ? ($download->is_single_price_mode() ? 'multi' : 'variable') : 'simple',
            'amount' => $price > 0 ? Amount::toMinor($price, $currency) : 0,
            'currency' => strtolower($currency),
            'requiresSave' => stackless_edd_is_subscription_product($download_id, $price_id !== null && $price_id !== false ? (int) $price_id : null),
            'label' => wp_strip_all_tags(get_the_title($download_id)),
        ]);
    }

    public static function renderCheckout(): void
    {
        if (!self::canShow()) {
            return;
        }
        $data = self::cartData('checkout');
        if ($data) {
            self::output('checkout', $data);
        }
    }

    /** @return array<string,mixed>|null */
    public static function cartData(string $context): ?array
    {
        if (!edd_get_cart_contents()) {
            return null;
        }
        $total = (float) edd_get_cart_total();
        $currency = stackless_edd_current_currency();
        if ($total <= 0 || !Amount::meetsMinimum($total, $currency)) {
            return null;
        }
        return [
            'amount' => Amount::toMinor($total, $currency),
            'currency' => strtolower($currency),
            'requiresSave' => stackless_edd_cart_requires_saved_payment_method(),
            'context' => $context,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function output(string $context, array $data): void
    {
        if (self::$rendered) {
            return;
        }
        self::$rendered = true;
        Assets::enqueueExpress();
        $terms = function_exists('edd_get_option') && (string) edd_get_option('show_agree_to_terms', false) === '1';
        stackless_edd_get_template('stripe_gateway/express-buttons.php', [
            'context' => $context,
            'config' => array_merge(['context' => $context, 'capture' => Settings::captureNow() ? 'automatic' : 'manual'], $data),
            'terms_text' => $terms ? (string) edd_get_option('agree_label', __('Agree to Terms?', 'stackless-lite-edd')) : '',
        ]);
    }

    /** Gateway on, keys present, and the shopper can finish a checkout without filling a form. */
    public static function canShow(): bool
    {
        if (!Module::gateway()->isEnabled() || !Settings::isConfigured() || !Settings::expressLocations()) {
            return false;
        }
        if (!Amount::isSupported(stackless_edd_current_currency())) {
            return false;
        }
        return self::accountRequirementsMet();
    }

    /** Guests can only pay with a wallet when EDD allows guest checkout (wallets cannot create accounts). */
    public static function accountRequirementsMet(): bool
    {
        return is_user_logged_in() || !(function_exists('edd_no_guest_checkout') && edd_no_guest_checkout());
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------- */

    public static function ajaxUpdate(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $context = isset($_POST['context']) ? sanitize_key(wp_unslash((string) $_POST['context'])) : 'checkout';
        if ($context === 'product' && !empty($_POST['prepare'])) {
            $error = self::prepareProductCart();
            if ($error !== '') {
                wp_send_json_error(['message' => $error], 400);
            }
        }
        if (isset($_POST['address']) && is_array($_POST['address'])) {
            self::applyAddress(self::cleanAddress(wp_unslash($_POST['address']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field.
        }
        if (!edd_get_cart_contents()) {
            wp_send_json_error(['message' => __('Your cart is empty.', 'stackless-lite-edd')], 400);
        }
        wp_send_json_success(self::totals());
    }

    public static function ajaxCheckout(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $context = isset($_POST['context']) ? sanitize_key(wp_unslash((string) $_POST['context'])) : 'checkout';
        if ($context === 'product' && !empty($_POST['prepare'])) {
            $error = self::prepareProductCart();
            if ($error !== '') {
                wp_send_json(['result' => 'failure', 'message' => $error]);
            }
        }
        if (!self::accountRequirementsMet()) {
            wp_send_json(['result' => 'failure', 'message' => __('Sign in to check out.', 'stackless-lite-edd')]);
        }

        $billing = isset($_POST['billing']) && is_array($_POST['billing']) ? wp_unslash($_POST['billing']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
        $token = isset($_POST['confirmation_token']) ? Gateway::cleanConfirmationToken(sanitize_text_field(wp_unslash($_POST['confirmation_token']))) : '';
        $address = self::cleanAddress(isset($billing['address']) && is_array($billing['address']) ? $billing['address'] : []);
        list($first, $last) = self::splitName(sanitize_text_field((string) ($billing['name'] ?? '')));
        $email = sanitize_email((string) ($billing['email'] ?? ''));
        $user = wp_get_current_user();
        if ($user->ID > 0) {
            // The account's own email: EDD refuses a signed-in purchase with another customer's address.
            $email = (string) $user->user_email;
            $first = $first !== '' ? $first : (string) $user->first_name;
            $last = $last !== '' ? $last : (string) $user->last_name;
        }

        $posted = [
            'edd_email' => $email,
            'edd_first' => $first,
            'edd_last' => $last,
            'edd-gateway' => Settings::GATEWAY_ID,
            'payment-mode' => Settings::GATEWAY_ID,
            'edd_action' => 'purchase',
            'edd-process-checkout-nonce' => wp_create_nonce('edd-process-checkout'),
            'billing_country' => $address['country'],
            'card_address' => $address['line1'],
            'card_address_2' => $address['line2'],
            'card_city' => $address['city'],
            'card_state' => $address['state'],
            'card_zip' => $address['postal_code'],
            Gateway::FIELD_EXPRESS => '1',
            Gateway::FIELD_CONFIRMATION_TOKEN => $token,
        ];
        if ($user->ID > 0) {
            $posted['edd-user-id'] = (string) $user->ID;
        }
        if ((string) edd_get_option('show_agree_to_terms', false) === '1') {
            // The express buttons show the terms next to them; paying with a wallet accepts them.
            $posted['edd_agree_to_terms'] = '1';
        }
        if ((string) edd_get_option('show_agree_to_privacy_policy', false) === '1') {
            $posted['edd_agree_to_privacy_policy'] = '1';
        }
        foreach ($posted as $key => $value) {
            $_POST[$key] = $value;
            $_REQUEST[$key] = $value;
        }
        if (function_exists('EDD') && EDD()->cart) {
            EDD()->cart->set_tax_rate(null);
        }

        // Reaches the gateway, which answers with JSON and exits; returns only when EDD refused the form.
        edd_process_purchase_form();
        $errors = (array) edd_get_errors();
        edd_clear_errors();
        wp_send_json(['result' => 'failure', 'message' => $errors ? wp_strip_all_tags(implode(' ', array_map('strval', $errors))) : ErrorMapper::generic()]);
    }

    public static function ajaxCancel(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        self::restoreSavedCart();
        wp_send_json_success();
    }

    /** After an express download-page purchase, put the shopper's earlier cart back. */
    public static function maybeRestoreAfterOrder(): void
    {
        if (!function_exists('edd_is_success_page') || !edd_is_success_page() || !function_exists('EDD') || !EDD()->session || !EDD()->session->get(self::SESSION_PRODUCT_MODE)) {
            return;
        }
        self::restoreSavedCart();
    }

    /* ---------------------------------------------------------------------
     * Cart helpers
     * ------------------------------------------------------------------- */

    /** Replace the cart with the download being bought. Returns an error message or ''. */
    private static function prepareProductCart(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verified the express nonce.
        $download_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $price_ids = isset($_POST['price_ids']) && is_array($_POST['price_ids']) ? array_values(array_unique(array_map('absint', wp_unslash($_POST['price_ids'])))) : [];
        // phpcs:enable

        $download = $download_id ? edd_get_download($download_id) : null;
        if (!$download || $download->post_status !== 'publish' || get_post_type($download_id) !== 'download') {
            return __('This download cannot be purchased.', 'stackless-lite-edd');
        }
        $variable = $download->has_variable_prices();
        if ($variable) {
            $prices = (array) $download->get_prices();
            $price_ids = array_values(array_filter($price_ids, static function ($id) use ($prices) {
                return isset($prices[$id]);
            }));
            if (!$price_ids) {
                return __('Choose a price option before paying.', 'stackless-lite-edd');
            }
        }
        if (!edd_item_quantities_enabled()) {
            $quantity = 1;
        }

        if (!EDD()->session->get(self::SESSION_PRODUCT_MODE) && edd_get_cart_contents()) {
            EDD()->session->set(self::SESSION_SAVED_CART, [
                'cart' => (array) edd_get_cart_contents(),
                'discounts' => (array) edd_get_cart_discounts(),
            ]);
        }
        EDD()->session->set(self::SESSION_PRODUCT_MODE, true);
        edd_empty_cart();

        if ($variable) {
            foreach ($price_ids as $price_id) {
                edd_add_to_cart($download_id, ['price_id' => $price_id, 'quantity' => $quantity]);
            }
        } else {
            edd_add_to_cart($download_id, ['quantity' => $quantity]);
        }
        if (!edd_get_cart_contents()) {
            self::restoreSavedCart();
            return __('This download could not be added to the cart.', 'stackless-lite-edd');
        }
        return '';
    }

    private static function restoreSavedCart(): void
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return;
        }
        $saved = EDD()->session->get(self::SESSION_SAVED_CART);
        $was_product_mode = (bool) EDD()->session->get(self::SESSION_PRODUCT_MODE);
        EDD()->session->set(self::SESSION_SAVED_CART, null);
        EDD()->session->set(self::SESSION_PRODUCT_MODE, null);
        if (!$was_product_mode) {
            return;
        }
        edd_empty_cart();
        if (!is_array($saved) || empty($saved['cart']) || !is_array($saved['cart'])) {
            return;
        }
        foreach ($saved['cart'] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
            $options['quantity'] = max(1, (int) ($item['quantity'] ?? ($options['quantity'] ?? 1)));
            edd_add_to_cart((int) $item['id'], $options);
        }
        foreach ((array) ($saved['discounts'] ?? []) as $code) {
            if (is_string($code) && $code !== '') {
                edd_set_cart_discount($code);
            }
        }
    }

    /** @param array{country:string, state:string, postal_code:string, city:string, line1:string, line2:string} $address */
    private static function applyAddress(array $address): void
    {
        // EDD's tax calculation reads the billing country and state from the request.
        $_POST['billing_country'] = $address['country'];
        $_POST['card_state'] = $address['state'];
        $_POST['card_zip'] = $address['postal_code'];
        if (function_exists('EDD') && EDD()->cart) {
            EDD()->cart->set_tax_rate(null);
        }
    }

    /**
     * @param array<string,mixed> $address
     * @return array{country:string, state:string, postal_code:string, city:string, line1:string, line2:string}
     */
    private static function cleanAddress(array $address): array
    {
        $country = strtoupper(sanitize_text_field((string) ($address['country'] ?? '')));
        $state = sanitize_text_field((string) ($address['state'] ?? ''));
        return [
            'country' => $country,
            'state' => self::normalizeState($country, $state),
            'postal_code' => sanitize_text_field((string) ($address['postal_code'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city'] ?? '')),
            'line1' => sanitize_text_field((string) ($address['line1'] ?? '')),
            'line2' => sanitize_text_field((string) ($address['line2'] ?? '')),
        ];
    }

    /** Wallets send state names ("California") or codes ("CA"); EDD wants its own codes. */
    public static function normalizeState(string $country, string $state): string
    {
        if ($country === '' || $state === '' || !function_exists('edd_get_shop_states')) {
            return $state;
        }
        $states = edd_get_shop_states($country);
        if (!is_array($states) || !$states) {
            return $state;
        }
        $upper = strtoupper($state);
        if (isset($states[$upper])) {
            return $upper;
        }
        foreach ($states as $code => $name) {
            if (strcasecmp(html_entity_decode((string) $name, ENT_QUOTES), $state) === 0 || strcasecmp(remove_accents(html_entity_decode((string) $name, ENT_QUOTES)), remove_accents($state)) === 0) {
                return (string) $code;
            }
        }
        // Some wallets prefix the country, e.g. "ES-M".
        if (strpos($upper, $country . '-') === 0 && isset($states[substr($upper, strlen($country) + 1)])) {
            return substr($upper, strlen($country) + 1);
        }
        return $state;
    }

    /** @return array{0:string, 1:string} */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['', ''];
        }
        $position = strrpos($name, ' ');
        if ($position === false) {
            return [$name, $name];
        }
        return [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * Totals for the wallet sheet, in Stripe minor units.
     *
     * @return array<string,mixed>
     */
    public static function totals(): array
    {
        $currency = stackless_edd_current_currency();
        $total = (float) edd_get_cart_total();
        $amount = $total > 0 ? Amount::toMinor($total, $currency) : 0;

        $line_items = [];
        foreach ((array) edd_get_cart_content_details() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 1);
            $line_items[] = [
                'name' => wp_strip_all_tags((string) ($item['name'] ?? '')) . ($quantity > 1 ? ' × ' . $quantity : ''),
                'amount' => Amount::toMinor(max(0, (float) ($item['price'] ?? 0)), $currency),
            ];
        }
        foreach ((array) edd_get_cart_fees() as $fee) {
            $fee_total = is_array($fee) ? (float) ($fee['amount'] ?? 0) : 0.0;
            if ($fee_total < 0 || !empty($fee['download_id'])) {
                // Negative fees and item fees are already inside the item prices or cannot be shown.
                $line_items = [];
                break;
            }
            $line_items[] = ['name' => wp_strip_all_tags((string) ($fee['label'] ?? '')), 'amount' => Amount::toMinor($fee_total, $currency)];
        }
        // Wallets reject line items that don't add up to the total, so only send exact sets.
        $sum = array_sum(array_column($line_items, 'amount'));
        if ($sum !== $amount || count($line_items) > 20) {
            $line_items = [];
        }

        return [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'lineItems' => $line_items,
            'requiresSave' => stackless_edd_cart_requires_saved_payment_method(),
        ];
    }
}
