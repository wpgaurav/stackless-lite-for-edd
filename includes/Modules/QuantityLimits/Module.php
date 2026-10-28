<?php

namespace StacklessEDD\Modules\QuantityLimits;

defined('ABSPATH') || exit;

/**
 * Min/Max Quantity: minimum, maximum and step per price option, per download and store-wide.
 *
 * Needs EDD's cart item quantities (Downloads → Settings → Payments → Cart). Limits are written
 * into EDD's quantity inputs on purchase forms and the checkout cart, and enforced server-side
 * when adding to the cart (forms, AJAX and /edd-add/ links), when quantities change in the
 * checkout cart, and again when the order is placed.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_MIN = '_stackless_edd_quantity_limits_min';
    public const META_MAX = '_stackless_edd_quantity_limits_max';
    public const META_STEP = '_stackless_edd_quantity_limits_step';
    /** Price option values: [price_id => ['min' => '', 'max' => '', 'step' => '']]. */
    public const META_PRICES = '_stackless_edd_quantity_limits_prices';
    public const FIELD_PRICES = 'stackless_edd_quantity_limits_prices';
    private const HANDLE = 'stackless-edd-quantity_limits';

    /** @var array<string, array{min: int, max: int, step: int}> */
    private $cache = [];

    /** @var string[] add-to-cart refusals during an AJAX request, returned with EDD's response */
    private $ajaxErrors = [];

    /** @var array{key: int, quantity: int, message: string}|null last quantity correction in this request */
    private $adjustment;

    /** @var bool */
    private $adjusting = false;

    /** @var bool */
    private $assetsQueued = false;

    public function id(): string
    {
        return 'quantity_limits';
    }

    public function title(): string
    {
        return __('Min/Max Quantity', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Set minimum, maximum and step quantities store-wide, per download and per price option. Enforced on purchase forms, the checkout cart and at checkout.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_quantity_limits_';
        $notice = '';
        if (function_exists('edd_item_quantities_enabled') && !edd_item_quantities_enabled()) {
            $url = function_exists('edd_get_admin_url') ? edd_get_admin_url(['page' => 'edd-settings', 'tab' => 'gateways', 'section' => 'cart']) : admin_url('edit.php?post_type=download&page=edd-settings');
            $notice = '<div class="notice notice-warning inline"><p>' . sprintf(
                /* translators: %s: link to the EDD cart settings */
                esc_html__('Cart item quantities are off, so shoppers always buy one of each download and these limits do nothing. Turn on %s first.', 'stackless-lite-edd'),
                '<a href="' . esc_url($url) . '">' . esc_html__('Downloads → Settings → Payments → Cart → Cart Item Quantities', 'stackless-lite-edd') . '</a>'
            ) . '</p></div>';
        }
        return [
            ['type' => 'title', 'id' => $p . 'defaults', 'title' => __('Store defaults', 'stackless-lite-edd'), 'desc' => $notice . '<p>' . esc_html__('Downloads and price options can override each value. Price option values win over download values, which win over these.', 'stackless-lite-edd') . '</p>'],
            ['type' => 'number', 'id' => $p . 'default_min', 'title' => __('Minimum quantity', 'stackless-lite-edd'), 'default' => '1', 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'number', 'id' => $p . 'default_max', 'title' => __('Maximum quantity', 'stackless-lite-edd'), 'default' => '0', 'desc' => __('0 means no limit.', 'stackless-lite-edd'), 'custom_attributes' => ['min' => 0, 'step' => 1]],
            ['type' => 'number', 'id' => $p . 'default_step', 'title' => __('Sold in multiples of', 'stackless-lite-edd'), 'default' => '1', 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'sectionend', 'id' => $p . 'defaults'],

            ['type' => 'title', 'id' => $p . 'display', 'title' => __('Shopper messages', 'stackless-lite-edd'), 'desc' => __('Placeholders: {product}, {min}, {max}, {step}, {quantity}.', 'stackless-lite-edd')],
            ['type' => 'checkbox', 'id' => $p . 'show_note', 'title' => __('Limits note', 'stackless-lite-edd'), 'desc' => __('Show the limits next to the quantity box on download pages', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'min_note', 'title' => __('Minimum note', 'stackless-lite-edd'), 'default' => __('Minimum {min}', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'max_note', 'title' => __('Maximum note', 'stackless-lite-edd'), 'default' => __('Maximum {max}', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'step_note', 'title' => __('Step note', 'stackless-lite-edd'), 'default' => __('Sold in packs of {step}', 'stackless-lite-edd'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'min_error', 'title' => __('Below minimum error', 'stackless-lite-edd'), 'default' => __('You need at least {min} of "{product}" in your cart.', 'stackless-lite-edd'), 'css' => 'min-width:400px'],
            ['type' => 'text', 'id' => $p . 'max_error', 'title' => __('Above maximum error', 'stackless-lite-edd'), 'default' => __('You can buy at most {max} of "{product}".', 'stackless-lite-edd'), 'css' => 'min-width:400px'],
            ['type' => 'text', 'id' => $p . 'step_error', 'title' => __('Wrong multiple error', 'stackless-lite-edd'), 'default' => __('"{product}" is sold in multiples of {step}.', 'stackless-lite-edd'), 'css' => 'min-width:400px'],
            ['type' => 'sectionend', 'id' => $p . 'display'],
        ];
    }

    public function boot(): void
    {
        // Purchase forms (single downloads, [downloads] grids, EDD blocks).
        add_filter('edd_purchase_form_quantity_input', [$this, 'filterQuantityInput'], 20, 3);
        add_filter('edd_purchase_form_variation_quantity_input', [$this, 'filterVariationQuantityInput'], 20, 4);

        // Server-side enforcement.
        add_filter('edd_add_to_cart_item', [$this, 'filterAddToCartItem'], 20, 1);
        add_filter('edd_ajax_add_to_cart_response', [$this, 'filterAjaxAddResponse'], 20, 1);
        add_action('edd_after_set_cart_item_quantity', [$this, 'afterSetQuantity'], 20, 4);
        add_filter('edd_ajax_cart_item_quantity_response', [$this, 'filterAjaxQuantityResponse'], 20, 1);
        add_action('edd_checkout_error_checks', [$this, 'validateCheckout'], 20, 2);

        // Checkout cart.
        add_action('edd_checkout_cart_item_title_after', [$this, 'renderCartItemLimits'], 20, 2);

        // Download editor.
        add_action('edd_meta_box_settings_fields', [$this, 'renderDownloadFields'], 35, 2);
        add_action('edd_download_price_option_row', [$this, 'renderPriceFields'], 20, 3);
        add_action('edd_save_download', [$this, 'saveDownloadFields'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Limits
     * ------------------------------------------------------------------- */

    /** Limits only apply while EDD lets shoppers choose a quantity for the download. */
    public function applies(int $downloadId): bool
    {
        if ($downloadId <= 0 || !function_exists('edd_item_quantities_enabled') || !edd_item_quantities_enabled()) {
            return false;
        }
        return get_post_type($downloadId) === 'download' && !edd_download_quantities_disabled($downloadId);
    }

    /**
     * @param mixed $priceId
     * @return array{min: int, max: int, step: int} max 0 = no limit
     */
    public function limits(int $downloadId, $priceId = null): array
    {
        $priceId = is_numeric($priceId) && edd_has_variable_prices($downloadId) ? (int) $priceId : null;
        $key = Rules::lineKey($downloadId, $priceId);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $levels = [];
        if ($priceId !== null) {
            $levels[] = $this->priceLevel($downloadId, $priceId);
        }
        $levels[] = [
            'min' => Rules::parse(get_post_meta($downloadId, self::META_MIN, true)),
            'max' => Rules::parse(get_post_meta($downloadId, self::META_MAX, true)),
            'step' => Rules::parse(get_post_meta($downloadId, self::META_STEP, true)),
        ];
        $levels[] = [
            'min' => Rules::parse($this->option('default_min', 1)),
            'max' => Rules::parse($this->option('default_max', 0)),
            'step' => Rules::parse($this->option('default_step', 1)),
        ];
        $limits = Rules::resolve($levels);
        /**
         * Filters the resolved quantity limits for a download or one of its price options.
         *
         * @param array{min: int, max: int, step: int} $limits      max 0 = no limit
         * @param int                                  $downloadId
         * @param int|null                             $priceId
         */
        $limits = (array) apply_filters('stackless_edd_quantity_limits_limits', $limits, $downloadId, $priceId);
        return $this->cache[$key] = Rules::normalize((int) ($limits['min'] ?? 1), (int) ($limits['max'] ?? 0), (int) ($limits['step'] ?? 1));
    }

    /** @return array{min: int|null, max: int|null, step: int|null} */
    private function priceLevel(int $downloadId, int $priceId): array
    {
        $prices = get_post_meta($downloadId, self::META_PRICES, true);
        $row = is_array($prices) && isset($prices[$priceId]) && is_array($prices[$priceId]) ? $prices[$priceId] : [];
        return [
            'min' => Rules::parse($row['min'] ?? null),
            'max' => Rules::parse($row['max'] ?? null),
            'step' => Rules::parse($row['step'] ?? null),
        ];
    }

    /**
     * Error message for a total quantity, or '' when it is valid.
     *
     * @param mixed $priceId
     */
    public function error(int $downloadId, $priceId, int $quantity): string
    {
        if (!$this->applies($downloadId)) {
            return '';
        }
        $limits = $this->limits($downloadId, $priceId);
        $rule = Rules::check($quantity, $limits);
        if ($rule === '') {
            return '';
        }
        $defaults = [
            'min' => __('You need at least {min} of "{product}" in your cart.', 'stackless-lite-edd'),
            'max' => __('You can buy at most {max} of "{product}".', 'stackless-lite-edd'),
            'step' => __('"{product}" is sold in multiples of {step}.', 'stackless-lite-edd'),
        ];
        $template = (string) $this->option($rule . '_error', $defaults[$rule]);
        if (trim($template) === '') {
            $template = $defaults[$rule];
        }
        return Rules::message($template, [
            'product' => wp_strip_all_tags((string) edd_get_download_name($downloadId, is_numeric($priceId) ? (int) $priceId : null)),
            'min' => $limits['min'],
            'max' => $limits['max'],
            'step' => $limits['step'],
            'quantity' => $quantity,
        ]);
    }

    /**
     * Quantity of a download (and price option) across cart lines, optionally skipping one line.
     *
     * @param array<int|string, mixed> $contents
     * @param mixed                    $priceId
     * @param int|string|null          $skipKey
     */
    private function quantityInCart(array $contents, int $downloadId, $priceId, $skipKey = null): int
    {
        $group = Rules::lineKey($downloadId, edd_has_variable_prices($downloadId) ? $priceId : null);
        $total = 0;
        foreach ($contents as $key => $item) {
            if (!is_array($item) || ($skipKey !== null && (string) $key === (string) $skipKey)) {
                continue;
            }
            $itemId = (int) ($item['id'] ?? 0);
            $itemPrice = edd_has_variable_prices($itemId) ? ($item['options']['price_id'] ?? null) : null;
            if ($itemId === $downloadId && Rules::lineKey($itemId, $itemPrice) === $group) {
                $total += max(1, (int) ($item['quantity'] ?? 1));
            }
        }
        return $total;
    }

    /** @return array<int|string, mixed> */
    private function cartContents(): array
    {
        if (!function_exists('EDD') || !EDD()->cart) {
            return [];
        }
        return is_array(EDD()->cart->contents) ? EDD()->cart->contents : [];
    }

    /**
     * Distinct download/price groups in the cart with their total quantity.
     *
     * @return array<string, array{download_id: int, price_id: int|null, quantity: int}>
     */
    private function cartGroups(): array
    {
        $groups = [];
        foreach ((array) edd_get_cart_contents() as $item) {
            // Invoice and renewal lines keep the quantity of the order they pay.
            if (!is_array($item) || stackless_edd_cart_item_is_locked((int) ($item['id'] ?? 0), (array) ($item['options'] ?? []))) {
                continue;
            }
            $downloadId = (int) ($item['id'] ?? 0);
            $priceId = edd_has_variable_prices($downloadId) && isset($item['options']['price_id']) && is_numeric($item['options']['price_id']) ? (int) $item['options']['price_id'] : null;
            $key = Rules::lineKey($downloadId, $priceId);
            if (!isset($groups[$key])) {
                $groups[$key] = ['download_id' => $downloadId, 'price_id' => $priceId, 'quantity' => 0];
            }
            $groups[$key]['quantity'] += max(1, (int) ($item['quantity'] ?? 1));
        }
        return $groups;
    }

    private function refuse(string $message): void
    {
        if (wp_doing_ajax()) {
            $this->ajaxErrors[] = $message;
            return;
        }
        stackless_edd_add_notice(esc_html($message), 'error');
    }

    /* ---------------------------------------------------------------------
     * Add to cart
     * ------------------------------------------------------------------- */

    /**
     * New lines below the minimum (links without a quantity, /edd-add/ URLs) are raised to the
     * minimum; anything that would break the limits is refused.
     *
     * @param mixed $item
     * @return mixed
     */
    public function filterAddToCartItem($item)
    {
        if (!is_array($item) || empty($item['id']) || (is_admin() && !wp_doing_ajax())) {
            return $item;
        }
        $downloadId = (int) $item['id'];
        if (!$this->applies($downloadId)) {
            return $item;
        }
        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $priceId = $options['price_id'] ?? null;
        $limits = $this->limits($downloadId, $priceId);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $inCart = $this->quantityInCart($this->cartContents(), $downloadId, $priceId);

        if ($inCart === 0) {
            if ($limits['max'] > 0 && $quantity > $limits['max']) {
                $this->refuse($this->error($downloadId, $priceId, $quantity));
                return false;
            }
            $item['quantity'] = Rules::clamp($quantity, $limits);
            return $item;
        }

        $message = $this->error($downloadId, $priceId, $quantity + $inCart);
        if ($message !== '') {
            $this->refuse($message);
            return false;
        }
        return $item;
    }

    /**
     * @param mixed $response
     * @return mixed
     */
    public function filterAjaxAddResponse($response)
    {
        if (!is_array($response) || !$this->ajaxErrors) {
            return $response;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the add-to-cart token before this filter.
        $downloadId = isset($_POST['download_id']) ? absint(wp_unslash($_POST['download_id'])) : 0;
        $response['stackless_quantity_limits'] = [
            'download_id' => $downloadId,
            'error' => implode(' ', array_unique($this->ajaxErrors)),
            'in_cart' => $downloadId > 0 && edd_item_in_cart($downloadId),
        ];
        $this->ajaxErrors = [];
        return $response;
    }

    /* ---------------------------------------------------------------------
     * Checkout cart quantity changes
     * ------------------------------------------------------------------- */

    /**
     * EDD has no validation hook for quantity updates, so a quantity that breaks the limits is
     * moved to the nearest valid one right after EDD stores it, and the shopper is told why.
     *
     * @param mixed $downloadId
     * @param mixed $quantity
     * @param mixed $options
     * @param mixed $contents
     */
    public function afterSetQuantity($downloadId, $quantity, $options = [], $contents = []): void
    {
        $downloadId = (int) $downloadId;
        if ($this->adjusting || !$this->applies($downloadId) || !function_exists('EDD') || !EDD()->cart) {
            return;
        }
        $options = is_array($options) ? $options : [];
        $priceId = $options['price_id'] ?? null;
        $key = EDD()->cart->get_item_position($downloadId, $options);
        $cart = EDD()->cart->contents;
        if ($key === false || !is_array($cart) || !isset($cart[$key]['quantity'])) {
            return;
        }
        $line = max(1, (int) $cart[$key]['quantity']);
        $others = $this->quantityInCart($cart, $downloadId, $priceId, $key);
        $message = $this->error($downloadId, $priceId, $line + $others);
        if ($message === '') {
            return;
        }
        $target = max(1, Rules::clamp($line + $others, $this->limits($downloadId, $priceId)) - $others);
        if ($target !== $line) {
            $this->adjusting = true;
            EDD()->cart->contents[$key]['quantity'] = $target;
            EDD()->cart->update_cart();
            $this->adjusting = false;
        }
        $this->adjustment = ['key' => (int) $key, 'quantity' => $target, 'message' => $message];
        if (!wp_doing_ajax()) {
            stackless_edd_add_notice(esc_html($message), 'error');
        }
    }

    /**
     * @param mixed $response
     * @return mixed
     */
    public function filterAjaxQuantityResponse($response)
    {
        if (!is_array($response) || $this->adjustment === null) {
            return $response;
        }
        $response['stackless_quantity_limits'] = $this->adjustment;
        $this->adjustment = null;
        return $response;
    }

    /**
     * Refuse the order while any download breaks its limits.
     *
     * @param mixed $validData
     * @param mixed $post
     */
    public function validateCheckout($validData = [], $post = []): void
    {
        foreach ($this->cartGroups() as $key => $group) {
            $message = $this->error($group['download_id'], $group['price_id'], $group['quantity']);
            if ($message !== '') {
                // EDD documents the error id as int; it is a string key.
                edd_set_error('stackless_edd_quantity_limits_' . md5($key), esc_html($message)); // @phpstan-ignore argument.type
            }
        }
    }

    /**
     * Checkout cart row: the limits for the quantity box (applied by quantity.js) and an inline
     * error while the row breaks them.
     *
     * @param mixed      $item
     * @param int|string $key
     */
    public function renderCartItemLimits($item, $key = 0): void
    {
        if (!is_array($item)) {
            return;
        }
        $downloadId = (int) ($item['id'] ?? 0);
        if (!$this->applies($downloadId)) {
            return;
        }
        $priceId = $item['options']['price_id'] ?? null;
        $limits = $this->limits($downloadId, $priceId);
        $contents = edd_get_cart_contents();
        $others = $this->quantityInCart(is_array($contents) ? $contents : [], $downloadId, $priceId, $key);
        $this->queueAssets();
        printf(
            '<span class="stackless-qty-limits-data" hidden data-key="%1$s" data-min="%2$d" data-max="%3$d" data-step="%4$d" data-others="%5$d"></span>',
            esc_attr((string) $key),
            (int) $limits['min'],
            (int) $limits['max'],
            (int) $limits['step'],
            (int) $others
        );
        $message = $this->error($downloadId, $priceId, max(1, (int) ($item['quantity'] ?? 1)) + $others);
        if ($message !== '') {
            echo '<p class="stackless-qty-limits-error" role="alert">' . esc_html($message) . '</p>';
        }
    }

    /* ---------------------------------------------------------------------
     * Purchase forms
     * ------------------------------------------------------------------- */

    /** @param array{min: int, max: int, step: int} $limits */
    private function noteText(array $limits): string
    {
        if ($this->option('show_note', 'yes') !== 'yes') {
            return '';
        }
        $parts = [];
        $vars = ['min' => $limits['min'], 'max' => $limits['max'], 'step' => $limits['step']];
        if ($limits['min'] > 1) {
            $parts[] = Rules::message((string) $this->option('min_note', __('Minimum {min}', 'stackless-lite-edd')), $vars);
        }
        if ($limits['max'] > 0) {
            $parts[] = Rules::message((string) $this->option('max_note', __('Maximum {max}', 'stackless-lite-edd')), $vars);
        }
        if ($limits['step'] > 1) {
            $parts[] = Rules::message((string) $this->option('step_note', __('Sold in packs of {step}', 'stackless-lite-edd')), $vars);
        }
        return implode(' · ', array_filter(array_map('trim', $parts)));
    }

    /** Like WooCommerce's note under the quantity box: only on the download's own page. */
    private function showsNote(int $downloadId): bool
    {
        return !wp_doing_ajax() && is_singular('download') && (int) get_queried_object_id() === $downloadId;
    }

    /**
     * The one quantity box of a purchase form (single-price downloads, or variable downloads in
     * single price mode, where it follows the chosen option through quantity.js).
     *
     * @param mixed $html
     * @param mixed $downloadId
     * @param mixed $args
     * @return mixed
     */
    public function filterQuantityInput($html, $downloadId = 0, $args = [])
    {
        $downloadId = (int) $downloadId;
        if (!is_string($html) || $html === '' || !$this->applies($downloadId)) {
            return $html;
        }
        $args = is_array($args) ? $args : [];
        $variable = edd_has_variable_prices($downloadId);
        $priceId = null;
        if ($variable) {
            $priceId = isset($args['price_id']) && $args['price_id'] !== false && is_numeric($args['price_id'])
                ? (int) $args['price_id']
                : (int) edd_get_default_variable_price($downloadId);
        }
        $limits = $this->limits($downloadId, $priceId);
        $html = Markup::quantityInput($html, $limits);
        $note = $this->showsNote($downloadId) ? $this->noteText($limits) : '';

        if ($variable && (!isset($args['price_id']) || $args['price_id'] === false)) {
            $map = [];
            foreach (array_keys((array) edd_get_variable_prices($downloadId)) as $id) {
                $optionLimits = $this->limits($downloadId, (int) $id);
                $map[(string) $id] = $optionLimits + ['note' => $this->showsNote($downloadId) ? $this->noteText($optionLimits) : ''];
            }
            $html = Markup::wrapperAttribute($html, 'data-stackless-qty-limits', esc_attr((string) wp_json_encode($map)));
        }
        if ($this->showsNote($downloadId) && $this->option('show_note', 'yes') === 'yes') {
            $html = Markup::appendInside($html, sprintf('<p class="stackless-qty-limits"%s>%s</p>', $note === '' ? ' hidden' : '', esc_html($note)));
        }
        $this->queueAssets();
        return $html;
    }

    /**
     * Per-option quantity boxes (variable downloads that allow buying several options at once).
     *
     * @param mixed $html
     * @param mixed $downloadId
     * @param mixed $priceId
     * @param mixed $price
     * @return mixed
     */
    public function filterVariationQuantityInput($html, $downloadId = 0, $priceId = 0, $price = [])
    {
        $downloadId = (int) $downloadId;
        if (!is_string($html) || $html === '' || !$this->applies($downloadId)) {
            return $html;
        }
        $this->queueAssets();
        return Markup::quantityInput($html, $this->limits($downloadId, (int) $priceId));
    }

    private function queueAssets(): void
    {
        if ($this->assetsQueued || is_admin()) {
            return;
        }
        $this->assetsQueued = true;
        wp_enqueue_script(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/quantity_limits/quantity.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_register_style(self::HANDLE, false, [], STACKLESS_EDD_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.stackless-qty-limits{flex-basis:100%;margin:.4em 0 .8em;font-size:.875em;opacity:.8}.stackless-qty-limits-error{margin:.4em 0 0;font-size:.875em;color:#d63939}');
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $downloadId
     * @param mixed $download
     */
    public function renderDownloadFields($downloadId = 0, $download = null): void
    {
        $downloadId = (int) $downloadId;
        if (!current_user_can('edit_product', $downloadId)) {
            return;
        }
        $fields = [
            self::META_MIN => [__('Minimum quantity', 'stackless-lite-edd'), (string) $this->option('default_min', 1), 1],
            self::META_MAX => [__('Maximum quantity', 'stackless-lite-edd'), (string) $this->option('default_max', 0), 0],
            self::META_STEP => [__('Sold in multiples of', 'stackless-lite-edd'), (string) $this->option('default_step', 1), 1],
        ];
        echo '<div class="edd-form-group stackless-edd-quantity-limits">';
        echo '<span class="edd-form-group__label">' . esc_html__('Quantity limits', 'stackless-lite-edd') . '</span>';
        echo '<div class="edd-form-group__control" style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($fields as $key => $field) {
            printf(
                '<label for="%1$s" style="display:flex;flex-direction:column;gap:4px">%2$s<input type="number" class="edd-form-group__input small-text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" min="%5$d" step="1"></label>',
                esc_attr($key),
                esc_html($field[0]),
                esc_attr((string) get_post_meta($downloadId, $key, true)),
                esc_attr($field[1]),
                (int) $field[2]
            );
        }
        echo '</div><p class="description edd-form-group__help">';
        esc_html_e('Leave blank to use the store default. 0 maximum means no limit. Price options can override these.', 'stackless-lite-edd');
        if (!edd_item_quantities_enabled()) {
            echo ' ' . esc_html__('Cart item quantities are off in Downloads → Settings → Payments → Cart, so these limits do nothing yet.', 'stackless-lite-edd');
        } elseif ($downloadId > 0 && edd_download_quantities_disabled($downloadId)) {
            echo ' ' . esc_html__('The quantity input is disabled for this download, so these limits do nothing.', 'stackless-lite-edd');
        }
        echo '</p></div>';
    }

    /**
     * @param mixed $downloadId
     * @param mixed $priceId
     * @param mixed $args
     */
    public function renderPriceFields($downloadId = 0, $priceId = 0, $args = []): void
    {
        $downloadId = (int) $downloadId;
        $priceId = (int) $priceId;
        $prices = $downloadId > 0 ? get_post_meta($downloadId, self::META_PRICES, true) : [];
        $row = is_array($prices) && isset($prices[$priceId]) && is_array($prices[$priceId]) ? $prices[$priceId] : [];
        $labels = [
            'min' => [__('Minimum quantity', 'stackless-lite-edd'), 1],
            'max' => [__('Maximum quantity', 'stackless-lite-edd'), 0],
            'step' => [__('Sold in multiples of', 'stackless-lite-edd'), 1],
        ];
        echo '<div class="edd-form-group stackless-edd-variation-qty-limits">';
        echo '<span class="edd-form-group__label">' . esc_html__('Quantity limits', 'stackless-lite-edd') . '</span>';
        echo '<div class="edd-form-group__control" style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($labels as $key => $label) {
            $id = self::FIELD_PRICES . '_' . $priceId . '_' . $key;
            printf(
                '<label for="%1$s" style="display:flex;flex-direction:column;gap:4px">%2$s<input type="number" class="edd-form-group__input small-text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s" min="%6$d" step="1"></label>',
                esc_attr($id),
                esc_html($label[0]),
                esc_attr(self::FIELD_PRICES . '[' . $priceId . '][' . $key . ']'),
                esc_attr(isset($row[$key]) ? (string) $row[$key] : ''),
                esc_attr__('Download default', 'stackless-lite-edd'),
                (int) $label[1]
            );
        }
        echo '</div></div>';
    }

    /**
     * EDD verified its download editor nonce before edd_save_download.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadFields($postId, $post = null): void
    {
        $postId = (int) $postId;
        if ($postId <= 0 || !current_user_can('edit_product', $postId)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD checked edd_download_meta_box_nonce.
        foreach ([self::META_MIN, self::META_MAX, self::META_STEP] as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = self::storedValue($key === self::META_MAX ? 'max' : 'min', sanitize_text_field(wp_unslash((string) $_POST[$key])));
            if ($value === null) {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, $value);
            }
        }

        if (isset($_POST[self::FIELD_PRICES]) && is_array($_POST[self::FIELD_PRICES])) {
            $posted = wp_unslash($_POST[self::FIELD_PRICES]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value below.
            $existing = edd_has_variable_prices($postId) ? array_map('intval', array_keys((array) edd_get_variable_prices($postId))) : [];
            $stored = [];
            foreach ((array) $posted as $priceId => $values) {
                if (!is_numeric($priceId) || !in_array((int) $priceId, $existing, true) || !is_array($values)) {
                    continue;
                }
                $row = [];
                foreach (['min', 'max', 'step'] as $key) {
                    $value = self::storedValue($key, isset($values[$key]) && is_scalar($values[$key]) ? sanitize_text_field((string) $values[$key]) : '');
                    if ($value !== null) {
                        $row[$key] = $value;
                    }
                }
                if ($row) {
                    $stored[(int) $priceId] = $row;
                }
            }
            if ($stored) {
                update_post_meta($postId, self::META_PRICES, $stored);
            } else {
                delete_post_meta($postId, self::META_PRICES);
            }
        }
        // phpcs:enable
        $this->cache = [];
    }

    /** '' = inherit (null); min and step are at least 1, max at least 0. */
    private static function storedValue(string $kind, string $raw): ?string
    {
        $value = Rules::parse($raw);
        if ($value === null) {
            return null;
        }
        return (string) ($kind === 'max' ? $value : max(1, $value));
    }
}
