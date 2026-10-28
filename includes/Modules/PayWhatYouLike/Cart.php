<?php

namespace StacklessEDD\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Chosen amounts in the cart.
 *
 * A cart line carries the option stackless_edd_pwyl = {amount, currency}: the amount the buyer
 * chose, as a decimal string in the currency it was chosen in. EDD saves line options as order
 * item meta, so the order keeps it. The line's price is worked out on the server from that value
 * and the download's current limits on every price read (edd_cart_item_price), never from a
 * client-sent price. Without a chosen amount the line costs the default (suggested amount or the
 * download's price).
 *
 * Multi-currency: limits are worked out in the buyer's currency (the download's own displayed
 * price as the minimum, the merchant's base-currency amounts converted). edd_cart_item_price works
 * in base-currency amounts, so the line's price becomes its stored price plus a base-currency
 * surcharge (the chosen amount minus the displayed price, turned back into the base currency at
 * the same effective rate, unrounded), declared through stackless_edd_runtime_price_surcharge.
 * Multi-Currency Checkout keeps the line's displayed price and adds the converted surcharge, which lands
 * exactly on the chosen amount. Switching currency puts the line back on the new currency's default.
 */
final class Cart
{
    /** Cart line option key, and the request field for amounts keyed by cart line. */
    public const KEY = 'stackless_edd_pwyl';
    /** Amount field on purchase forms. */
    public const PRODUCT_FIELD = 'stackless_edd_pwyl_amount';
    /** Which download the posted purchase-form amount belongs to. */
    public const PRODUCT_MARKER = 'stackless_edd_pwyl_download';
    public const ORDER_META = '_stackless_edd_pay_what_you_like';
    public const NOTED_META = '_stackless_edd_pay_what_you_like_noted';
    /** ?stackless_edd_price=25 on a checkout or buy link sets the amount once, inside the limits. */
    public const LINK_PARAM = 'stackless_edd_price';
    public const AJAX_ACTION = 'stackless_edd_pwyl_set_amount';
    public const NONCE_ACTION = 'stackless-edd-pwyl';
    /** Order bump lines are priced by the bump; they never take a chosen amount. */
    private const BUMP_KEY = '_stackless_edd_bump_id'; // the Order Bumps cart line option (ARCHITECTURE.md)

    /** @var ProductSettings */
    private $settings;

    /** @var array<string, array<string, mixed>|null> */
    private $boundsMemo = [];

    /** @var bool */
    private $linkApplied = false;

    /** @var array<string, array{amount: string, currency: string}> amounts accepted on this request, by "download|price" */
    private $pending = [];

    /** @var string */
    private $refused = '';

    public function __construct(ProductSettings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Adding to the cart: purchase forms (AJAX and plain), buy links, recovered orders, code.
        add_filter('edd_add_to_cart_item', [$this, 'addToCartItem'], 20, 1);
        add_action('edd_post_add_to_cart', [$this, 'afterAddToCart'], 20, 3);
        add_filter('edd_ajax_add_to_cart_response', [$this, 'ajaxAddResponse'], 5, 1);

        // Prices: base-currency runtime price plus the declared surcharge.
        add_filter('edd_cart_item_price', [$this, 'applyPrice'], 10, 3);
        add_filter('stackless_edd_runtime_price_surcharge', [$this, 'declareSurcharge'], 10, 3);
        add_action('edd_checkout_error_checks', [$this, 'validateCheckout'], 20, 2);

        // Changing amounts at checkout.
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxSetAmount']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'ajaxSetAmount']);
        add_action('template_redirect', [$this, 'formSetAmount'], 5);

        // Orders.
        add_action('edd_built_order', [$this, 'recordOrder'], 20, 2);

        // Links.
        add_action('stackless_edd_buy_links_processed', [$this, 'buyLinkProcessed'], 20, 2);
        add_action('template_redirect', [$this, 'linkAmount'], 20);
    }

    /* ---------------------------------------------------------------------
     * Limits
     * ------------------------------------------------------------------- */

    /**
     * Limits for a download (or one of its variable prices) in the buyer's currency, or null when
     * it has a fixed price.
     *
     * @return array{min: int, max: int, default: int, presets: int[], decimals: int, currency: string, custom_min: bool, price: int}|null
     */
    public function bounds(int $downloadId, ?int $priceId = null): ?array
    {
        $priceId = $this->priceIdFor($downloadId, $priceId);
        $settings = $this->settings->settingsFor($downloadId, $priceId);
        if (!$settings) {
            return null;
        }
        $currency = stackless_edd_current_currency();
        $memo = $downloadId . '|' . ($priceId === null ? '-' : $priceId) . '|' . $currency;
        if (array_key_exists($memo, $this->boundsMemo)) {
            return $this->boundsMemo[$memo];
        }
        $decimals = max(0, stackless_edd_price_decimals($currency));
        // The catalog price in the buyer's currency (price book or conversion), never a runtime change.
        $price = $priceId !== null ? edd_get_price_option_amount($downloadId, $priceId) : edd_get_download_price($downloadId);
        $priceMinor = is_numeric($price) && (float) $price >= 0 ? Amounts::toMinor(number_format((float) $price, 8, '.', ''), $decimals) : null;
        if ($priceMinor === null) {
            return $this->boundsMemo[$memo] = null;
        }
        $presets = [];
        foreach ($settings['presets'] as $preset) {
            $minor = $this->converted($preset, $decimals, $currency);
            if ($minor !== null) {
                $presets[] = $minor;
            }
        }
        $bounds = Amounts::bounds(
            $priceMinor,
            $settings['min'] !== '' ? $this->converted($settings['min'], $decimals, $currency) : null,
            $settings['suggested'] !== '' ? $this->converted($settings['suggested'], $decimals, $currency) : null,
            $settings['max'] !== '' ? $this->converted($settings['max'], $decimals, $currency) : null,
            $presets,
            Amounts::hardMax($decimals)
        );
        return $this->boundsMemo[$memo] = $bounds + ['decimals' => $decimals, 'currency' => $currency, 'custom_min' => $settings['min'] !== '', 'price' => $priceMinor];
    }

    /** A variable download is priced by one of its prices; a single-price download ignores price ids. */
    private function priceIdFor(int $downloadId, ?int $priceId): ?int
    {
        if (!function_exists('edd_has_variable_prices') || !edd_has_variable_prices($downloadId)) {
            return null;
        }
        if ($priceId === null && function_exists('edd_get_default_variable_price')) {
            $default = edd_get_default_variable_price($downloadId);
            return is_numeric($default) ? (int) $default : null;
        }
        return $priceId;
    }

    /** A base-currency setting in minor units of the buyer's currency. */
    private function converted(string $base, int $decimals, string $currency): ?int
    {
        $value = stackless_edd_convert_amount($base, $currency);
        return Amounts::toMinor(is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 8, '.', '') : $base, $decimals);
    }

    public function flush(): void
    {
        $this->boundsMemo = [];
        $this->settings->flush();
    }

    /**
     * State of one cart line, or null when it does not take a chosen amount.
     *
     * amount: the chosen amount (or the default when none was chosen), priced: the amount used for
     * the price (the chosen amount kept inside the current limits), chosen: whether the buyer chose,
     * error: "too_low"/"too_high" when a chosen amount no longer fits the limits.
     *
     * @param array<string, mixed> $item cart line: id, options (or a cart details row with item_number)
     * @return array{amount: int, priced: int, chosen: bool, error: string, min: int, max: int, default: int, presets: int[], decimals: int, currency: string, custom_min: bool, price: int}|null
     */
    public function lineState(array $item): ?array
    {
        if (isset($item['item_number']) && is_array($item['item_number'])) {
            $item = $item['item_number'];
        }
        $downloadId = absint($item['id'] ?? 0);
        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        if (!$downloadId || !empty($options[self::BUMP_KEY])) {
            return null;
        }
        $bounds = $this->bounds($downloadId, self::optionPriceId($options));
        if (!$bounds) {
            return null;
        }
        $stored = $options[self::KEY] ?? null;
        $chosen = null;
        if (is_array($stored) && ($stored['currency'] ?? '') === $bounds['currency'] && isset($stored['amount']) && is_scalar($stored['amount'])) {
            $chosen = Amounts::toMinor((string) $stored['amount'], $bounds['decimals']);
        }
        $amount = $chosen === null ? $bounds['default'] : $chosen;
        return [
            'amount' => $amount,
            'priced' => Amounts::clamp($amount, $bounds),
            'chosen' => $chosen !== null,
            'error' => $chosen === null ? '' : Amounts::check($chosen, $bounds),
        ] + $bounds;
    }

    /** @param array<string, mixed> $options */
    public static function optionPriceId(array $options): ?int
    {
        return isset($options['price_id']) && is_numeric($options['price_id']) ? (int) $options['price_id'] : null;
    }

    /** Plain-text price ("€12.50") for labels, notices and responses. */
    public static function label(int $minor, int $decimals, string $currency = ''): string
    {
        return html_entity_decode(wp_strip_all_tags(stackless_edd_price(Amounts::fromMinor($minor, $decimals), $currency)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array{min: int, max: int, decimals: int} $state
     */
    public static function boundsMessage(string $code, array $state, string $name): string
    {
        if ($code === 'too_low') {
            /* translators: 1: minimum price, 2: download name */
            return sprintf(__('Enter at least %1$s for %2$s.', 'stackless-lite-edd'), self::label($state['min'], $state['decimals']), $name);
        }
        /* translators: 1: maximum price, 2: download name */
        return sprintf(__('Enter at most %1$s for %2$s.', 'stackless-lite-edd'), self::label($state['max'], $state['decimals']), $name);
    }

    public static function invalidMessage(string $name): string
    {
        /* translators: %s: download name */
        return sprintf(__('Enter an amount such as 12.50 for %s, without a currency symbol.', 'stackless-lite-edd'), $name);
    }

    /**
     * Help text under an amount field.
     *
     * @param array{min: int, max: int, decimals: int} $state
     */
    public static function helpText(array $state): string
    {
        $hasMax = $state['max'] < Amounts::hardMax($state['decimals']);
        if ($state['min'] <= 0) {
            return $hasMax
                /* translators: %s: maximum price */
                ? sprintf(__('Enter 0 to get it free, or any amount up to %s.', 'stackless-lite-edd'), self::label($state['max'], $state['decimals']))
                : __('Enter 0 to get it free, or any amount you like.', 'stackless-lite-edd');
        }
        return $hasMax
            /* translators: 1: minimum price, 2: maximum price */
            ? sprintf(__('Minimum %1$s, maximum %2$s', 'stackless-lite-edd'), self::label($state['min'], $state['decimals']), self::label($state['max'], $state['decimals']))
            /* translators: %s: minimum price */
            : sprintf(__('Minimum %s', 'stackless-lite-edd'), self::label($state['min'], $state['decimals']));
    }

    public static function itemName(int $downloadId, ?int $priceId = null): string
    {
        $name = function_exists('edd_get_download_name') ? (string) edd_get_download_name($downloadId, $priceId) : '';
        return wp_strip_all_tags($name !== '' ? $name : get_the_title($downloadId));
    }

    /** Effective rate from the base currency to $currency (1 for the base currency). */
    private function rate(string $currency): float
    {
        if ($currency === stackless_edd_base_currency()) {
            return 1.0;
        }
        return (float) stackless_edd_currency_rate($currency);
    }

    /* ---------------------------------------------------------------------
     * Adding to the cart
     * ------------------------------------------------------------------- */

    /**
     * The purchase form that was posted: EDD's AJAX add to cart sends it serialized in post_data,
     * the no-JavaScript form posts it directly.
     *
     * @return array<string, mixed>
     */
    private function postedForm(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD verified the add-to-cart nonce or token; values are validated.
        if (wp_doing_ajax() && isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            $fields = [];
            // The serialized form is parsed first; every field is then sanitized below.
            parse_str(wp_unslash($_POST['post_data']), $fields); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return map_deep($fields, 'sanitize_text_field');
        }
        return map_deep(wp_unslash($_POST), 'sanitize_text_field');
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * Amount requested for a download being added: the line options first (buy links, recovered
     * orders, code), then the purchase form when it belongs to this download. Null when none.
     *
     * @param array<string, mixed> $options
     */
    private function requestedAmount(int $downloadId, array $options): ?string
    {
        if (array_key_exists(self::KEY, $options)) {
            $value = is_array($options[self::KEY]) ? ($options[self::KEY]['amount'] ?? null) : $options[self::KEY];
            return is_scalar($value) && trim((string) $value) !== '' ? sanitize_text_field((string) $value) : null;
        }
        $form = $this->postedForm();
        if (!isset($form[self::PRODUCT_MARKER], $form[self::PRODUCT_FIELD]) || absint($form[self::PRODUCT_MARKER]) !== $downloadId || !is_scalar($form[self::PRODUCT_FIELD])) {
            return null;
        }
        $value = sanitize_text_field((string) $form[self::PRODUCT_FIELD]);
        return $value;
    }

    /** True when the posted purchase form belongs to this download (its amount field was on screen). */
    private function formPosted(int $downloadId): bool
    {
        $form = $this->postedForm();
        return isset($form[self::PRODUCT_MARKER]) && absint($form[self::PRODUCT_MARKER]) === $downloadId && array_key_exists(self::PRODUCT_FIELD, $form);
    }

    /**
     * Validates an amount typed on a purchase form (refusing the add when it does not fit) and
     * stamps the line with the buyer's currency. Amounts that arrive in the line options (buy
     * links, recovered orders, code) are kept and priced inside the limits; checkout stops until
     * an out-of-range one is corrected.
     *
     * @param mixed $item
     * @return mixed
     */
    public function addToCartItem($item)
    {
        if (!is_array($item) || empty($item['id'])) {
            return $item;
        }
        $downloadId = absint($item['id']);
        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $bounds = $this->bounds($downloadId, self::optionPriceId($options));
        if (!$bounds || !empty($options[self::BUMP_KEY])) {
            unset($options[self::KEY]);
            $item['options'] = $options;
            return $item;
        }
        $fromOptions = array_key_exists(self::KEY, $options);
        $raw = $this->requestedAmount($downloadId, $options);
        unset($options[self::KEY]);
        $name = self::itemName($downloadId, self::optionPriceId($options));
        if ($raw === null) {
            // A purchase form whose amount field was left empty.
            if (!$fromOptions && $this->formPosted($downloadId)) {
                return $this->refuse(self::invalidMessage($name));
            }
            $item['options'] = $options;
            return $item;
        }
        $separator = $fromOptions ? '.' : self::separator();
        $parsed = Amounts::parse($raw, $bounds['decimals'], $separator);
        if ($parsed === null) {
            if ($fromOptions) {
                $item['options'] = $options;
                return $item;
            }
            return $this->refuse(self::invalidMessage($name));
        }
        if (!$fromOptions) {
            $error = Amounts::check((int) Amounts::toMinor($parsed, $bounds['decimals']), $bounds);
            if ($error !== '') {
                return $this->refuse(self::boundsMessage($error, $bounds, $name));
            }
        }
        $chosen = ['amount' => $parsed, 'currency' => $bounds['currency']];
        $options[self::KEY] = $chosen;
        $item['options'] = $options;
        $this->pending[$downloadId . '|' . (self::optionPriceId($options) ?? '-')] = $chosen;
        return $item;
    }

    /** @return false */
    private function refuse(string $message): bool
    {
        $this->refused = $message;
        if (!wp_doing_ajax()) {
            stackless_edd_add_notice($message, 'error');
        }
        return false;
    }

    /**
     * EDD adds the quantity of an item that is already in the cart to its line and keeps that
     * line's options: the amount just chosen replaces the line's old one.
     *
     * @param mixed $downloadId
     * @param mixed $options
     * @param mixed $items
     */
    public function afterAddToCart($downloadId = 0, $options = [], $items = []): void
    {
        if (!$this->pending || !function_exists('EDD') || !EDD()->cart) {
            return;
        }
        $cart = EDD()->cart;
        $changed = false;
        foreach ((array) $cart->contents as $key => $line) {
            if (!is_array($line) || empty($line['id'])) {
                continue;
            }
            $lineOptions = isset($line['options']) && is_array($line['options']) ? $line['options'] : [];
            $pendingKey = absint($line['id']) . '|' . (self::optionPriceId($lineOptions) ?? '-');
            if (isset($this->pending[$pendingKey]) && ($lineOptions[self::KEY] ?? null) !== $this->pending[$pendingKey]) {
                $cart->contents[$key]['options'][self::KEY] = $this->pending[$pendingKey];
                $changed = true;
            }
        }
        $this->pending = [];
        if ($changed) {
            $cart->update_cart();
        }
    }

    /**
     * A refused AJAX add answers with an error the purchase form shows next to the amount.
     *
     * @param mixed $response
     * @return mixed
     */
    public function ajaxAddResponse($response)
    {
        if ($this->refused !== '') {
            wp_send_json(['stackless_edd_pwyl_error' => $this->refused], 400);
        }
        return $response;
    }

    private static function separator(): string
    {
        $separator = function_exists('edd_get_option') ? (string) edd_get_option('decimal_separator', '.') : '.';
        return $separator !== '' ? $separator : '.';
    }

    /* ---------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------- */

    /**
     * The base-currency surcharge that turns the line's catalog price into the amount it is priced
     * at, or null for a line without a chosen-amount price.
     *
     * @param array<string, mixed> $options
     */
    public function surcharge(int $downloadId, array $options): ?float
    {
        $state = $this->lineState(['id' => $downloadId, 'options' => $options]);
        if (!$state) {
            return null;
        }
        $difference = number_format(($state['priced'] - $state['price']) / pow(10, $state['decimals']), $state['decimals'], '.', '');
        return (float) Amounts::toBase($difference, $this->rate($state['currency']));
    }

    /**
     * @param mixed $price
     * @param mixed $downloadId
     * @param mixed $options
     * @return mixed
     */
    public function applyPrice($price, $downloadId = 0, $options = [])
    {
        if (!is_numeric($price) || (is_array($options) && stackless_edd_cart_item_is_locked(absint($downloadId), $options))) {
            return $price;
        }
        $surcharge = $this->surcharge(absint($downloadId), is_array($options) ? $options : []);
        if ($surcharge === null) {
            return $price;
        }
        $value = max(0.0, (float) $price + $surcharge);
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param mixed $surcharge
     * @param mixed $downloadId
     * @param mixed $options
     * @return mixed
     */
    public function declareSurcharge($surcharge, $downloadId = 0, $options = [])
    {
        $mine = $this->surcharge(absint($downloadId), is_array($options) ? $options : []);
        if ($mine === null) {
            return $surcharge;
        }
        return (is_numeric($surcharge) ? (float) $surcharge : 0.0) + $mine;
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validateCheckout($validData = [], $post = []): void
    {
        foreach ($this->lines() as $key => $line) {
            $state = $line['state'];
            if ($state['error'] !== '') {
                // EDD documents the error id as int; every caller (EDD included) passes a string code.
                edd_set_error('stackless_edd_pwyl_' . $key, self::boundsMessage($state['error'], $state, $line['name'])); // @phpstan-ignore argument.type
            }
        }
    }

    /**
     * Cart lines that take a chosen amount, by cart key.
     *
     * @return array<int|string, array{id: int, options: array<string, mixed>, name: string, state: array<string, mixed>}>
     */
    public function lines(): array
    {
        if (!function_exists('edd_get_cart_contents')) {
            return [];
        }
        $lines = [];
        foreach ((array) edd_get_cart_contents() as $key => $item) {
            if (!is_array($item) || stackless_edd_cart_item_is_locked((int) ($item['id'] ?? 0), (array) ($item['options'] ?? []))) {
                continue;
            }
            $state = $this->lineState($item);
            if (!$state) {
                continue;
            }
            $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
            $id = absint($item['id'] ?? 0);
            $lines[$key] = ['id' => $id, 'options' => $options, 'name' => self::itemName($id, self::optionPriceId($options)), 'state' => $state];
        }
        return $lines;
    }

    /* ---------------------------------------------------------------------
     * Changing amounts
     * ------------------------------------------------------------------- */

    /**
     * Set the chosen amount of one cart line from typed text. Returns '' on success or a message
     * for the buyer.
     *
     * @param int|string $key cart key
     * @param mixed $raw
     */
    public function setAmount($key, $raw, bool $clampToLimits = false): string
    {
        $cart = function_exists('EDD') ? EDD()->cart : null;
        $contents = $cart ? (array) $cart->get_contents() : [];
        if (!$cart || !isset($contents[$key]) || !is_array($contents[$key])) {
            return __('That cart item no longer exists.', 'stackless-lite-edd');
        }
        $item = $contents[$key];
        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $name = self::itemName(absint($item['id'] ?? 0), self::optionPriceId($options));
        $state = $this->lineState($item);
        if (!$state) {
            /* translators: %s: download name */
            return sprintf(__('%s has a fixed price.', 'stackless-lite-edd'), $name);
        }
        $parsed = is_scalar($raw) ? Amounts::parse((string) $raw, $state['decimals'], $clampToLimits ? '.' : self::separator()) : null;
        if ($parsed === null) {
            return self::invalidMessage($name);
        }
        $minor = (int) Amounts::toMinor($parsed, $state['decimals']);
        if ($clampToLimits) {
            $minor = Amounts::clamp($minor, $state);
        }
        $error = Amounts::check($minor, $state);
        if ($error !== '') {
            return self::boundsMessage($error, $state, $name);
        }
        if ($minor === $state['amount'] && $state['error'] === '') {
            return '';
        }
        $cart->contents[$key]['options'][self::KEY] = ['amount' => Amounts::fromMinor($minor, $state['decimals']), 'currency' => $state['currency']];
        $cart->update_cart();
        $cart->invalidate_cache();
        return '';
    }

    /**
     * Apply amounts posted as stackless_edd_pwyl[cart key] = "12.50". Returns the messages for
     * amounts that could not be used.
     *
     * @param mixed $posted
     * @return string[]
     */
    public function applyPosted($posted): array
    {
        if (!is_array($posted)) {
            return [];
        }
        $messages = [];
        foreach ($posted as $key => $raw) {
            if (!is_numeric($key) || !is_scalar($raw)) {
                continue;
            }
            $message = $this->setAmount((int) $key, sanitize_text_field((string) $raw));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    /** Checkout "Name your price" box via AJAX: one line at a time, answered with the new totals. */
    public function ajaxSetAmount(): void
    {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page and try again.', 'stackless-lite-edd')], 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $key = isset($_POST['key']) && is_numeric($_POST['key']) ? (int) $_POST['key'] : -1;
        $amount = isset($_POST['amount']) && is_scalar($_POST['amount']) ? sanitize_text_field(wp_unslash((string) $_POST['amount'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $message = $this->setAmount($key, $amount);
        if ($message !== '') {
            wp_send_json_error(['message' => $message], 400);
        }
        $this->flush();
        $cart = EDD()->cart;
        $item = $cart->get_contents()[$key] ?? [];
        $options = is_array($item) && isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $subtotal = $cart->get_subtotal();
        $taxes = $cart->get_tax();
        $total = $cart->get_total();
        $state = is_array($item) ? $this->lineState($item) : null;
        wp_send_json_success([
            'key' => $key,
            'line' => absint($item['id'] ?? 0) . ':' . (self::optionPriceId($options) ?? ''),
            'line_price' => is_array($item) && isset($item['id']) ? wp_kses_post(edd_cart_item_price($item['id'], $options)) : '',
            'amount' => $state ? Amounts::fromMinor($state['amount'], $state['decimals']) : '',
            'subtotal_raw' => $subtotal,
            'taxes_raw' => $taxes,
            'total_raw' => $total,
            'subtotal' => html_entity_decode(edd_currency_filter(edd_format_amount($subtotal)), ENT_COMPAT, 'UTF-8'),
            'taxes' => html_entity_decode(edd_currency_filter(edd_format_amount($taxes)), ENT_COMPAT, 'UTF-8'),
            'total' => html_entity_decode(edd_currency_filter(edd_format_amount($total)), ENT_COMPAT, 'UTF-8'),
            'discounts' => function_exists('edd_get_cart_discounts_html') ? edd_get_cart_discounts_html() : '',
            'complete_purchase' => function_exists('edd_get_checkout_button_purchase_label') ? edd_get_checkout_button_purchase_label() : '',
        ]);
    }

    /** No-JavaScript fallback: the "Name your price" box posts its own form. */
    public function formSetAmount(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
        if (!isset($_POST[self::KEY], $_POST['stackless_edd_pwyl_nonce']) || is_admin()) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['stackless_edd_pwyl_nonce']));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        $messages = $this->applyPosted(wp_unslash($_POST[self::KEY])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per value in applyPosted().
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        foreach ($messages as $message) {
            stackless_edd_add_notice($message, 'error');
        }
        wp_safe_redirect(function_exists('edd_get_checkout_uri') ? edd_get_checkout_uri() : home_url('/'));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $added
     * @param mixed $params
     */
    public function buyLinkProcessed($added, $params = []): void
    {
        if ((int) $added > 0) {
            $this->applyLinkAmount();
        }
    }

    public function linkAmount(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link; the amount is kept inside the download's limits.
        if (!isset($_GET[self::LINK_PARAM]) || is_admin() || !function_exists('edd_is_checkout') || !edd_is_checkout()) {
            return;
        }
        $this->applyLinkAmount();
        wp_safe_redirect(remove_query_arg(self::LINK_PARAM));
        exit;
    }

    /** One amount fits one download: it goes to the first line that takes a chosen amount. */
    private function applyLinkAmount(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link; the amount is kept inside the download's limits.
        $raw = isset($_GET[self::LINK_PARAM]) && is_scalar($_GET[self::LINK_PARAM]) ? sanitize_text_field(wp_unslash((string) $_GET[self::LINK_PARAM])) : '';
        if ($this->linkApplied || $raw === '') {
            return;
        }
        $this->linkApplied = true;
        foreach (array_keys($this->lines()) as $key) {
            $this->setAmount($key, $raw, true);
            return;
        }
    }

    /* ---------------------------------------------------------------------
     * Orders
     * ------------------------------------------------------------------- */

    /**
     * Records each chosen amount and its minimum on the order item, and adds one order note.
     *
     * @param mixed $orderId
     * @param mixed $orderData
     */
    public function recordOrder($orderId, $orderData = []): void
    {
        $orderId = absint($orderId);
        $orderData = is_array($orderData) ? $orderData : [];
        $details = isset($orderData['cart_details']) && is_array($orderData['cart_details']) ? $orderData['cart_details'] : [];
        if (!$orderId || !$details || !function_exists('edd_get_order_items')) {
            return;
        }
        $order = edd_get_order($orderId);
        if (!$order) {
            return;
        }
        $items = [];
        foreach ((array) edd_get_order_items(['order_id' => $orderId, 'number' => 999]) as $orderItem) {
            $items[(int) $orderItem->cart_index] = $orderItem;
        }
        $lines = [];
        foreach ($details as $index => $detail) {
            if (!is_array($detail) || !isset($items[(int) $index])) {
                continue;
            }
            $state = $this->lineState($detail);
            if (!$state) {
                continue;
            }
            $currency = $state['currency'];
            $amount = Amounts::fromMinor($state['priced'], $state['decimals']);
            $minimum = Amounts::fromMinor($state['min'], $state['decimals']);
            edd_update_order_item_meta((int) $items[(int) $index]->id, self::ORDER_META, [
                'amount' => $amount,
                'minimum' => $minimum,
                'currency' => $currency,
                'chosen' => $state['chosen'] ? 'yes' : 'no',
            ]);
            $lines[] = sprintf(
                /* translators: 1: download name, 2: amount paid per item, 3: minimum price */
                __('%1$s: %2$s (minimum %3$s)', 'stackless-lite-edd'),
                (string) $items[(int) $index]->product_name,
                html_entity_decode(wp_strip_all_tags(stackless_edd_price($amount, $currency)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                html_entity_decode(wp_strip_all_tags(stackless_edd_price($minimum, $currency)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }
        if (!$lines || edd_get_order_meta($orderId, self::NOTED_META, true)) {
            return;
        }
        stackless_edd_add_order_note($orderId, __('Pay what you like', 'stackless-lite-edd') . "\n" . implode("\n", $lines));
        edd_update_order_meta($orderId, self::NOTED_META, '1');
    }
}
