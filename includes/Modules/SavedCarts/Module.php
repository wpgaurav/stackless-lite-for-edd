<?php

namespace StacklessEDD\Modules\SavedCarts;

use StacklessEDD\Core\Account;

defined('ABSPATH') || exit;

/**
 * Saved carts and order again.
 *
 * Signed-in customers name and save their cart, restore it later and reorder from any
 * past order, not only completed ones. Everything lives under the account page's Saved carts
 * tab, with a [stackless_edd_saved_carts] shortcode for a standalone page and a form below the
 * checkout cart. EDD's own single "Save cart" button (Downloads → Settings → Misc) is untouched.
 */
class Module extends \StacklessEDD\Core\Module
{
    public const META = '_stackless_edd_saved_carts';
    public const TAB = 'saved-carts';
    public const NONCE = 'stackless_edd_saved_carts';
    public const NONCE_ORDER_AGAIN = 'stackless_edd_order_again';
    public const ORDER_AGAIN_ARG = 'stackless_edd_order_again';
    private const HANDLE = 'stackless-edd-saved_carts';
    private const DEFAULT_STATUSES = ['processing', 'complete', 'on_hold', 'refunded', 'partially_refunded', 'revoked', 'failed'];

    /** @var bool */
    private $formPrinted = false;

    public function id(): string
    {
        return 'saved_carts';
    }

    public function title(): string
    {
        return __('Saved Carts & Order Again', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Customers save their cart under a name, restore it later, and reorder from any past order. Adds a Saved carts tab to the account page.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'store';
    }

    public function settingsFields(): array
    {
        $statuses = function_exists('edd_get_payment_statuses') ? (array) edd_get_payment_statuses() : [];
        return [
            ['type' => 'title', 'id' => 'stackless_edd_saved_carts_options', 'title' => __('Saved carts & order again', 'stackless-lite-edd')],
            [
                'id' => 'stackless_edd_saved_carts_account_tab',
                'type' => 'checkbox',
                'title' => __('Account page', 'stackless-lite-edd'),
                'desc' => __('Show a Saved carts tab on the account page (the shortcode still works without it)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_saved_carts_cart_link',
                'type' => 'checkbox',
                'title' => __('Checkout page', 'stackless-lite-edd'),
                'desc' => __('Show a "Save this cart" form below the cart for signed-in customers', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_saved_carts_reorder_statuses',
                'type' => 'multiselect',
                'title' => __('Order again for', 'stackless-lite-edd'),
                'desc' => __('Order statuses that show an Order again button. Completed orders always do.', 'stackless-lite-edd'),
                'desc_tip' => true,
                'options' => $statuses,
                'default' => self::DEFAULT_STATUSES,
            ],
            ['type' => 'sectionend', 'id' => 'stackless_edd_saved_carts_options'],
        ];
    }

    public function boot(): void
    {
        add_action('wp_loaded', [$this, 'handlePost'], 20);
        add_action('wp_loaded', [$this, 'handleOrderAgain'], 20);
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue']);
        add_shortcode('stackless_edd_saved_carts', [$this, 'shortcode']);
        add_filter('stackless_edd_account_tabs', [$this, 'accountTab']);

        if ($this->option('cart_link', 'yes') === 'yes') {
            // Below the cart, outside every form: [download_checkout] fires edd_after_checkout_cart
            // between the cart and the purchase form; the Checkout block only fires
            // edd_after_purchase_form (EDD 3.7+). Printed once per page.
            add_action('edd_after_checkout_cart', [$this, 'cartForm']);
            add_action('edd_after_purchase_form', [$this, 'cartForm']);
        }

        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /* ------------------------------------------------------------------
     * Storage
     * ---------------------------------------------------------------- */

    /** @return array<string, array<string, mixed>> */
    public function saved(int $user_id): array
    {
        return CartList::normalise(get_user_meta($user_id, self::META, true));
    }

    /** @param array<string, array<string, mixed>> $list */
    private function store(int $user_id, array $list): void
    {
        if ($list) {
            update_user_meta($user_id, self::META, $list);
        } else {
            delete_user_meta($user_id, self::META);
        }
    }

    /** @return array<int|string, mixed> */
    private static function cartContents(): array
    {
        $contents = function_exists('edd_get_cart_contents') ? edd_get_cart_contents() : [];
        return is_array($contents) ? $contents : [];
    }

    /**
     * Save the current cart for a user.
     *
     * @return array{ok:bool, message:string}
     */
    public function saveCurrent(int $user_id, string $name): array
    {
        $items = CartList::fromCart(self::cartContents());
        if (!$items) {
            return ['ok' => false, 'message' => __('Your cart is empty. Add downloads before saving it.', 'stackless-lite-edd')];
        }
        try {
            $list = CartList::add($this->saved($user_id), wp_generate_uuid4(), sanitize_text_field($name), $items, time());
        } catch (\LengthException $e) {
            /* translators: %d: maximum number of saved carts */
            return ['ok' => false, 'message' => sprintf(__('You can keep %d saved carts. Remove one before saving another.', 'stackless-lite-edd'), CartList::MAX_CARTS)];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => __('Give this cart a name.', 'stackless-lite-edd')];
        }
        $this->store($user_id, $list);
        return ['ok' => true, 'message' => __('Cart saved. Find it any time under Saved carts.', 'stackless-lite-edd')];
    }

    /**
     * Put a saved cart's lines into the live cart. Current prices apply.
     *
     * @return array{ok:bool, added:int, skipped:array<int, string>}
     */
    public function restore(int $user_id, string $key, bool $replace): array
    {
        $list = $this->saved($user_id);
        if (!isset($list[$key]) || !function_exists('EDD') || !EDD()->cart) {
            return ['ok' => false, 'added' => 0, 'skipped' => []];
        }
        if ($replace) {
            edd_empty_cart();
        }
        $added = 0;
        $skipped = [];
        foreach ($list[$key]['items'] as $line) {
            $name = $this->addLine($line);
            if ($name === '') {
                $added++;
            } else {
                $skipped[] = $name;
            }
        }
        return ['ok' => $added > 0, 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * Add one stored line to the EDD cart.
     *
     * @param array<string, mixed> $line download_id, price_id, quantity, options
     * @return string '' when added, otherwise the name to list as skipped
     */
    private function addLine(array $line): string
    {
        $id = (int) ($line['download_id'] ?? 0);
        $price_id = isset($line['price_id']) && is_numeric($line['price_id']) ? (int) $line['price_id'] : null;
        $download = $id > 0 && get_post_type($id) === 'download' ? edd_get_download($id) : null;
        if (!$download instanceof \EDD_Download) {
            return __('A download that is no longer available', 'stackless-lite-edd');
        }
        $name = (string) edd_get_download_name($id, $price_id);
        if (get_post_status($id) !== 'publish' || !$download->can_purchase()) {
            return $name;
        }
        $options = is_array($line['options'] ?? null) ? CartList::keepData($line['options']) : [];
        if ($download->has_variable_prices()) {
            $prices = (array) $download->get_prices();
            if ($price_id === null || !isset($prices[$price_id])) {
                return $name;
            }
            $options['price_id'] = $price_id;
        }
        if (edd_item_quantities_enabled() && !edd_download_quantities_disabled($id)) {
            $options['quantity'] = max(1, (int) ($line['quantity'] ?? 1));
        }
        $before = md5((string) wp_json_encode(EDD()->cart->get_contents()));
        edd_add_to_cart($id, $options);
        return md5((string) wp_json_encode(EDD()->cart->get_contents())) !== $before ? '' : $name;
    }

    public function delete(int $user_id, string $key): bool
    {
        $list = $this->saved($user_id);
        if (!isset($list[$key])) {
            return false;
        }
        unset($list[$key]);
        $this->store($user_id, $list);
        return true;
    }

    /* ------------------------------------------------------------------
     * Requests
     * ---------------------------------------------------------------- */

    public function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
        if (empty($_POST['stackless_edd_saved_carts_op']) || empty($_POST['_stackless_edd_saved_carts_nonce'])) {
            return;
        }
        $back = wp_get_referer() ?: stackless_edd_account_url();
        if (!is_user_logged_in()) {
            stackless_edd_add_notice(__('Sign in to manage saved carts.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_stackless_edd_saved_carts_nonce'])), self::NONCE)) {
            stackless_edd_add_notice(__('Your session expired. Please try again.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $user_id = get_current_user_id();
        $op = sanitize_key(wp_unslash($_POST['stackless_edd_saved_carts_op']));
        $key = isset($_POST['cart_key']) ? sanitize_text_field(wp_unslash($_POST['cart_key'])) : '';

        if ($op === 'save') {
            $name = isset($_POST['cart_name']) ? sanitize_text_field(wp_unslash($_POST['cart_name'])) : '';
            $result = $this->saveCurrent($user_id, $name);
            stackless_edd_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($result['ok'] && $this->accountUrl() ? $this->accountUrl() : $back);
            exit;
        }
        if ($op === 'delete') {
            $ok = $this->delete($user_id, $key);
            stackless_edd_add_notice($ok ? __('Saved cart removed.', 'stackless-lite-edd') : __('That saved cart was not found.', 'stackless-lite-edd'), $ok ? 'success' : 'error');
            wp_safe_redirect($back);
            exit;
        }
        if ($op === 'restore') {
            $result = $this->restore($user_id, $key, !empty($_POST['replace']));
            if (!$result['ok']) {
                stackless_edd_add_notice(__('None of these downloads can be bought right now.', 'stackless-lite-edd'), 'error');
                wp_safe_redirect($back);
                exit;
            }
            stackless_edd_add_notice(__('Your saved cart is back. Current prices apply; check any download options before paying.', 'stackless-lite-edd'), 'success');
            if ($result['skipped']) {
                /* translators: %s: download names */
                stackless_edd_add_notice(sprintf(__('Not added: %s', 'stackless-lite-edd'), implode(', ', array_unique($result['skipped']))), 'info');
            }
            wp_safe_redirect(edd_get_checkout_uri());
            exit;
        }
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Order again
     * ---------------------------------------------------------------- */

    /**
     * Statuses that may be ordered again: the chosen ones plus completed orders.
     *
     * @return string[]
     */
    public function reorderStatuses(): array
    {
        $chosen = $this->option('reorder_statuses', self::DEFAULT_STATUSES);
        $chosen = is_array($chosen) ? $chosen : [];
        $clean = ['complete'];
        foreach ($chosen as $status) {
            $status = sanitize_key((string) $status);
            if ($status !== '') {
                $clean[] = $status;
            }
        }
        return array_values(array_unique($clean));
    }

    private function ownsOrder(\EDD\Orders\Order $order, int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        if ((int) $order->user_id === $user_id) {
            return true;
        }
        $customer = edd_get_customer_by('user_id', (string) $user_id);
        return $customer && (int) $customer->id > 0 && (int) $order->customer_id === (int) $customer->id;
    }

    /** @return \EDD\Orders\Order[] recent orders this customer can reorder */
    private function reorderable(int $user_id): array
    {
        $args = [
            'type' => 'sale',
            'status__in' => $this->reorderStatuses(),
            'number' => 20,
            'orderby' => 'date_created',
            'order' => 'DESC',
        ];
        $customer = edd_get_customer_by('user_id', (string) $user_id);
        if ($customer && (int) $customer->id > 0) {
            $args['customer_id'] = (int) $customer->id;
        } else {
            $args['user_id'] = $user_id;
        }
        $orders = edd_get_orders($args);
        return array_values(array_filter(is_array($orders) ? $orders : [], static function ($order): bool {
            return $order instanceof \EDD\Orders\Order && count($order->get_items()) > 0;
        }));
    }

    public function orderAgainUrl(int $order_id): string
    {
        return add_query_arg([
            self::ORDER_AGAIN_ARG => $order_id,
            '_wpnonce' => wp_create_nonce(self::NONCE_ORDER_AGAIN),
        ], edd_get_checkout_uri());
    }

    /**
     * Fill the cart from a past order (owner only, allowed statuses only).
     *
     * @return array{ok:bool, added:int, skipped:array<int, string>, message:string}
     */
    public function orderAgain(int $user_id, int $order_id): array
    {
        $order = $order_id > 0 ? edd_get_order($order_id) : false;
        if (!$order instanceof \EDD\Orders\Order || $order->type !== 'sale' || !$this->ownsOrder($order, $user_id)
            || !in_array($order->status, $this->reorderStatuses(), true) || !function_exists('EDD') || !EDD()->cart) {
            return ['ok' => false, 'added' => 0, 'skipped' => [], 'message' => __('This order cannot be ordered again.', 'stackless-lite-edd')];
        }
        /**
         * Whether ordering again empties the cart first (WooCommerce does).
         *
         * @param bool $empty
         * @param int  $order_id
         */
        if (apply_filters('stackless_edd_saved_carts_empty_cart_when_order_again', true, $order_id)) {
            edd_empty_cart();
        }
        $added = 0;
        $skipped = [];
        foreach ($order->get_items() as $item) {
            $meta = edd_get_order_item_meta((int) $item->id);
            $name = $this->addLine([
                'download_id' => (int) $item->product_id,
                'price_id' => is_numeric($item->price_id) ? (int) $item->price_id : null,
                'quantity' => max(1, (int) $item->quantity),
                'options' => CartList::optionsFromOrderMeta(is_array($meta) ? $meta : []),
            ]);
            if ($name === '') {
                $added++;
            } else {
                $skipped[] = $name;
            }
        }
        if (!$added) {
            return ['ok' => false, 'added' => 0, 'skipped' => $skipped, 'message' => __('None of these downloads can be bought right now.', 'stackless-lite-edd')];
        }
        return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'message' => __('The cart has been filled with the items from your previous order.', 'stackless-lite-edd')];
    }

    public function handleOrderAgain(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
        if (empty($_GET[self::ORDER_AGAIN_ARG])) {
            return;
        }
        $order_id = absint($_GET[self::ORDER_AGAIN_ARG]);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        // phpcs:enable
        $back = $this->accountUrl() ?: stackless_edd_account_url();
        if (!is_user_logged_in()) {
            stackless_edd_add_notice(__('Sign in to order again.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect(stackless_edd_account_url());
            exit;
        }
        if (!wp_verify_nonce($nonce, self::NONCE_ORDER_AGAIN)) {
            stackless_edd_add_notice(__('Your session expired. Please try again.', 'stackless-lite-edd'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $result = $this->orderAgain(get_current_user_id(), $order_id);
        stackless_edd_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
        if ($result['ok'] && $result['skipped']) {
            /* translators: %s: download names */
            stackless_edd_add_notice(sprintf(__('Not added: %s', 'stackless-lite-edd'), implode(', ', array_unique($result['skipped']))), 'info');
        }
        wp_safe_redirect($result['ok'] ? edd_get_checkout_uri() : $back);
        exit;
    }

    /* ------------------------------------------------------------------
     * Output
     * ---------------------------------------------------------------- */

    private function accountUrl(): string
    {
        return $this->option('account_tab', 'yes') === 'yes' ? stackless_edd_account_url(self::TAB) : '';
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function accountTab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        if ($this->option('account_tab', 'yes') !== 'yes') {
            return $tabs;
        }
        $tabs[self::TAB] = [
            'title' => __('Saved carts', 'stackless-lite-edd'),
            'icon' => 'device-floppy',
            'priority' => 12,
            'callback' => function (): void {
                echo $this->render(true); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
            },
        ];
        return $tabs;
    }

    /** The account tab renders inside the content, after wp_head: load its stylesheet in the head. */
    public function maybeEnqueue(): void
    {
        if (!is_singular() || (int) get_queried_object_id() !== Account::pageId() || $this->option('account_tab', 'yes') !== 'yes') {
            return;
        }
        $tab = isset($_GET[Account::QUERY_VAR]) ? sanitize_key(wp_unslash($_GET[Account::QUERY_VAR])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        if ($tab === self::TAB) {
            $this->enqueueStyle();
        }
    }

    private function enqueueStyle(): void
    {
        wp_enqueue_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/saved_carts/saved-carts.css', [], STACKLESS_EDD_VERSION);
    }

    /** @param array<string, mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared signal page-cache plugins read.
        }
        return $this->render(false);
    }

    private function render(bool $in_account): string
    {
        $this->enqueueStyle();
        if (!is_user_logged_in()) {
            return stackless_edd_get_template_html('saved_carts/signed-out.php', ['login_url' => stackless_edd_account_url()]);
        }
        $user_id = get_current_user_id();
        $carts = [];
        foreach ($this->saved($user_id) as $key => $cart) {
            $previews = [];
            foreach (array_slice($cart['items'], 0, 4) as $line) {
                $id = (int) $line['download_id'];
                if (get_post_type($id) === 'download') {
                    $previews[] = [
                        'name' => (string) edd_get_download_name($id, $line['price_id']),
                        'image' => get_the_post_thumbnail($id, 'thumbnail'),
                    ];
                }
            }
            $carts[] = [
                'key' => $key,
                'name' => $cart['name'],
                'created' => $cart['created'],
                'lines' => count($cart['items']),
                'quantity' => CartList::quantity($cart),
                'previews' => $previews,
            ];
        }
        $orders = [];
        foreach ($this->reorderable($user_id) as $order) {
            $created = strtotime($order->date_created . ' UTC');
            $orders[] = [
                'id' => (int) $order->id,
                'number' => (string) $order->get_number(),
                'date' => $created ? wp_date((string) get_option('date_format'), $created) : '',
                'status' => edd_get_status_label($order->status),
                'total_html' => stackless_edd_price($order->total, (string) $order->currency),
                'again_url' => $this->orderAgainUrl((int) $order->id),
                'view_url' => edd_get_receipt_page_uri((int) $order->id),
            ];
        }
        return stackless_edd_get_template_html('saved_carts/account.php', [
            'carts' => $carts,
            'orders' => $orders,
            'can_save' => (bool) self::cartContents(),
            'cart_count' => function_exists('edd_get_cart_quantity') ? (int) edd_get_cart_quantity() : 0,
            'limit' => CartList::MAX_CARTS,
            'nonce' => wp_create_nonce(self::NONCE),
            'in_account' => $in_account,
            'shop_url' => self::shopUrl(),
        ]);
    }

    /** Download archive, or the home page when the store disables it. */
    private static function shopUrl(): string
    {
        $url = get_post_type_archive_link('download');
        return $url ? (string) $url : home_url('/');
    }

    private function saveForm(): string
    {
        if (!is_user_logged_in() || !self::cartContents()) {
            return '';
        }
        $this->enqueueStyle();
        return stackless_edd_get_template_html('saved_carts/cart-form.php', [
            'nonce' => wp_create_nonce(self::NONCE),
            'account_url' => $this->accountUrl(),
        ]);
    }

    public function cartForm(): void
    {
        if ($this->formPrinted) {
            return;
        }
        $this->formPrinted = true;
        echo $this->saveForm(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /* ------------------------------------------------------------------
     * Privacy
     * ---------------------------------------------------------------- */

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function registerExporter($exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['stackless-edd-saved-carts'] = ['exporter_friendly_name' => __('Saved carts', 'stackless-lite-edd'), 'callback' => [$this, 'export']];
        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function registerEraser($erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['stackless-edd-saved-carts'] = ['eraser_friendly_name' => __('Saved carts', 'stackless-lite-edd'), 'callback' => [$this, 'erase']];
        return $erasers;
    }

    /** @return array{data: array<int, mixed>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $data = [];
        if ($user) {
            foreach ($this->saved((int) $user->ID) as $key => $cart) {
                $names = [];
                foreach ($cart['items'] as $line) {
                    $name = edd_get_download_name((int) $line['download_id'], $line['price_id']);
                    $names[] = ($name ? (string) $name : '#' . $line['download_id']) . ' × ' . (int) $line['quantity'];
                }
                $data[] = [
                    'group_id' => 'stackless-edd-saved-carts',
                    'group_label' => __('Saved carts', 'stackless-lite-edd'),
                    'item_id' => 'stackless-edd-saved-cart-' . $key,
                    'data' => [
                        ['name' => __('Name', 'stackless-lite-edd'), 'value' => $cart['name']],
                        ['name' => __('Downloads', 'stackless-lite-edd'), 'value' => implode(', ', $names)],
                    ],
                ];
            }
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $removed = $user && $this->saved((int) $user->ID);
        if ($user) {
            delete_user_meta((int) $user->ID, self::META);
        }
        return ['items_removed' => (bool) $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
