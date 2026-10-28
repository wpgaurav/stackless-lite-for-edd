<?php

namespace StacklessEDD\Modules\Wishlist;

use StacklessEDD\Core\Account;
use StacklessEDD\Core\ConflictGuard;

defined('ABSPATH') || exit;

/**
 * Wishlists for guests and customers.
 *
 * Guests get a cookie-backed list that merges into their account list when they sign in.
 * Buttons next to the purchase button on download pages, the [downloads] shortcode and the
 * EDD download blocks (all built by edd_get_purchase_link()); a [stackless_edd_wishlist]
 * shortcode and stackless-edd/wishlist block; an account page "Wishlist" tab; a read-only
 * share link; and "Move to cart".
 *
 * An entry is a download plus a variable price id; price id 0 is the download itself.
 */
class Module extends \StacklessEDD\Core\Module
{
    public const COOKIE = 'stackless_edd_wishlist';
    public const TAB = 'wishlist';
    public const NONCE = 'stackless_edd_wishlist';
    private const HANDLE = 'stackless-edd-wishlist';

    /** @var Repository|null */
    private $repo;

    /** @var array<int, bool> downloads whose single-page button was printed */
    private $singleRendered = [];

    /** @var string|null token created during this request (cookie not yet readable) */
    private $freshToken;

    /** @var string[]|null memoised keys of the visitor's list for this request */
    private $keys;

    public function id(): string
    {
        return 'wishlist';
    }

    public function title(): string
    {
        return __('Wishlist', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Guest and account wishlists that merge on sign-in, with buttons on download pages and shop grids, a wishlist page, an account page tab, share links and move to cart.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'store';
    }

    public function conflicts(): array
    {
        return ConflictGuard::WISH_LISTS;
    }

    public function install(): void
    {
        Repository::install();
    }

    public function settingsFields(): array
    {
        return [
            ['type' => 'title', 'id' => 'stackless_edd_wishlist_options', 'title' => __('Wishlist', 'stackless-lite-edd')],
            [
                'id' => 'stackless_edd_wishlist_page_id',
                'type' => 'single_select_page',
                'title' => __('Wishlist page', 'stackless-lite-edd'),
                'desc' => __('A page containing the [stackless_edd_wishlist] shortcode or the Wishlist block. Needed for share links.', 'stackless-lite-edd'),
                'desc_tip' => true,
                'css' => 'min-width:300px;',
                'default' => '',
            ],
            [
                'id' => 'stackless_edd_wishlist_account_tab',
                'type' => 'checkbox',
                'title' => __('Account page', 'stackless-lite-edd'),
                'desc' => __('Show a Wishlist tab on the account page', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_wishlist_show_loop',
                'type' => 'checkbox',
                'title' => __('Shop grids', 'stackless-lite-edd'),
                'desc' => __('Show the wishlist button in download grids ([downloads] shortcode and the Downloads block)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_wishlist_show_single',
                'type' => 'checkbox',
                'title' => __('Download pages', 'stackless-lite-edd'),
                'desc' => __('Show the wishlist button below the purchase button', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_wishlist_guests',
                'type' => 'checkbox',
                'title' => __('Guest wishlists', 'stackless-lite-edd'),
                'desc' => __('Let visitors save downloads without an account (kept in a cookie, merged when they sign in)', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_wishlist_guest_days',
                'type' => 'number',
                'title' => __('Keep guest lists for', 'stackless-lite-edd'),
                'desc' => __('days since the last change', 'stackless-lite-edd'),
                'default' => '30',
                'custom_attributes' => ['min' => 1, 'max' => 365, 'step' => 1],
            ],
            [
                'id' => 'stackless_edd_wishlist_limit',
                'type' => 'number',
                'title' => __('Maximum downloads per list', 'stackless-lite-edd'),
                'default' => '100',
                'custom_attributes' => ['min' => 1, 'max' => 500, 'step' => 1],
            ],
            [
                'id' => 'stackless_edd_wishlist_remove_on_cart',
                'type' => 'checkbox',
                'title' => __('Move to cart', 'stackless-lite-edd'),
                'desc' => __('Remove a download from the wishlist after it is moved to the cart', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            ['type' => 'sectionend', 'id' => 'stackless_edd_wishlist_options'],
        ];
    }

    public function boot(): void
    {
        add_action('wp_loaded', [$this, 'handlePost'], 20);
        add_action('wp_loaded', [$this, 'maybeMergeGuest'], 5);
        add_action('wp_login', [$this, 'mergeOnLogin'], 10, 2);
        add_action('edd_insert_user', [$this, 'mergeOnRegister'], 10, 1);
        add_action('wp_ajax_stackless_edd_wishlist', [$this, 'ajax']);
        add_action('wp_ajax_nopriv_stackless_edd_wishlist', [$this, 'ajax']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('init', [$this, 'registerBlock']);
        add_shortcode('stackless_edd_wishlist', [$this, 'shortcode']);

        // Every purchase button (download pages, [downloads], [purchase_link], EDD blocks) is built
        // by edd_get_purchase_link(). Priority 120: after modules that replace the form (100+).
        add_filter('edd_purchase_download_form', [$this, 'filterPurchaseForm'], 120, 2);
        // Download pages whose purchase button is hidden still get the button.
        add_action('edd_after_download_content', [$this, 'afterDownloadContent'], 30);

        // Account page tab.
        add_filter('stackless_edd_account_tabs', [$this, 'accountTab']);

        // Housekeeping and privacy.
        add_action('edd_daily_scheduled_events', [$this, 'purgeGuests']);
        add_action('delete_user', [$this, 'deleteUser']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    private function repo(): Repository
    {
        if (!$this->repo) {
            $this->repo = new Repository();
        }
        return $this->repo;
    }

    private function limit(): int
    {
        return max(1, min(500, (int) $this->option('limit', 100)));
    }

    private function guestsAllowed(): bool
    {
        return $this->option('guests', 'yes') === 'yes';
    }

    /* ------------------------------------------------------------------
     * Current visitor's list
     * ---------------------------------------------------------------- */

    private function cookieToken(): string
    {
        if ($this->freshToken) {
            return $this->freshToken;
        }
        $raw = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return preg_match('/^[a-f0-9]{32}$/', $raw) ? $raw : '';
    }

    private function setCookie(string $token, int $days): void
    {
        $this->freshToken = $token === '' ? null : $token;
        if ($token === '') {
            unset($_COOKIE[self::COOKIE]);
        }
        if (headers_sent()) {
            return;
        }
        $expires = $token === '' ? time() - YEAR_IN_SECONDS : time() + $days * DAY_IN_SECONDS;
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && constant('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function currentList(bool $create)
    {
        if (is_user_logged_in()) {
            return $this->repo()->forUser(get_current_user_id(), $create);
        }
        if (!$this->guestsAllowed()) {
            return null;
        }
        $token = $this->cookieToken();
        if ($token === '' && $create) {
            $token = bin2hex(random_bytes(16));
            $this->setCookie($token, (int) $this->option('guest_days', 30));
        }
        return $token === '' ? null : $this->repo()->forGuest($token, $create);
    }

    /** @return array<int, array{download_id:int, price_id:int}> */
    private function currentItems(): array
    {
        $list = $this->currentList(false);
        return $list ? $this->repo()->items((int) $list['id']) : [];
    }

    /** @return string[] "download:price" keys */
    private function currentKeys(): array
    {
        if ($this->keys === null) {
            $this->keys = array_map(static function (array $e): string {
                return Merge::key($e['download_id'], $e['price_id']);
            }, $this->currentItems());
        }
        return $this->keys;
    }

    public function maybeMergeGuest(): void
    {
        if (!is_user_logged_in() || $this->cookieToken() === '') {
            return;
        }
        $this->mergeFor(get_current_user_id());
    }

    /**
     * @param string        $login
     * @param \WP_User|mixed $user
     */
    public function mergeOnLogin($login, $user): void
    {
        if ($user instanceof \WP_User && $this->cookieToken() !== '') {
            $this->mergeFor((int) $user->ID);
        }
    }

    /** Accounts created at checkout (EDD signs the new customer in, but merge right away too). @param int|mixed $user_id */
    public function mergeOnRegister($user_id): void
    {
        if (is_numeric($user_id) && (int) $user_id > 0 && $this->cookieToken() !== '') {
            $this->mergeFor((int) $user_id);
        }
    }

    private function mergeFor(int $user_id): void
    {
        $this->keys = null;
        $guest = $this->repo()->forGuest($this->cookieToken(), false);
        $this->setCookie('', 0);
        if (!$guest || !$this->repo()->count((int) $guest['id'])) {
            if ($guest) {
                $this->repo()->deleteList((int) $guest['id']);
            }
            return;
        }
        $account = $this->repo()->forUser($user_id, true);
        if (!$account) {
            return;
        }
        $added = $this->repo()->merge((int) $guest['id'], (int) $account['id'], $this->limit());
        if ($added > 0) {
            $this->log(sprintf('Merged %d guest wishlist item(s) into user #%d.', $added, $user_id));
        }
    }

    /* ------------------------------------------------------------------
     * Mutations
     * ---------------------------------------------------------------- */

    /**
     * Resolve and validate a download/price pair from request input. A price id on a
     * download without variable prices collapses to 0.
     *
     * @return array{0:int,1:int}|null
     */
    private function resolveTarget(int $download_id, int $price_id)
    {
        if ($download_id <= 0 || get_post_type($download_id) !== 'download' || get_post_status($download_id) !== 'publish') {
            return null;
        }
        $download = edd_get_download($download_id);
        if (!$download instanceof \EDD_Download) {
            return null;
        }
        if ($price_id > 0) {
            if (!$download->has_variable_prices()) {
                $price_id = 0;
            } else {
                $prices = (array) $download->get_prices();
                if (!isset($prices[$price_id])) {
                    return null;
                }
            }
        }
        return [$download_id, max(0, $price_id)];
    }

    /** @return array{ok:bool, in:bool, message:string} */
    private function mutate(string $op, int $download_id, int $price_id): array
    {
        $this->keys = null;
        $target = $this->resolveTarget($download_id, $price_id);
        if (!$target) {
            return ['ok' => false, 'in' => false, 'message' => __('This download is not available.', 'stackless-lite-edd')];
        }
        [$download_id, $price_id] = $target;
        if (!is_user_logged_in() && !$this->guestsAllowed()) {
            return ['ok' => false, 'in' => false, 'message' => __('Sign in to save downloads to your wishlist.', 'stackless-lite-edd')];
        }
        $list = $this->currentList($op !== 'remove');
        if (!$list) {
            return ['ok' => $op === 'remove', 'in' => false, 'message' => __('Removed from your wishlist.', 'stackless-lite-edd')];
        }
        $list_id = (int) $list['id'];
        $has = $this->repo()->has($list_id, $download_id, $price_id);
        if ($op === 'toggle') {
            $op = $has ? 'remove' : 'add';
        }
        if ($op === 'remove') {
            $this->repo()->remove($list_id, $download_id, $price_id);
            return ['ok' => true, 'in' => false, 'message' => __('Removed from your wishlist.', 'stackless-lite-edd')];
        }
        if (!$has && $this->repo()->count($list_id) >= $this->limit()) {
            return ['ok' => false, 'in' => false, 'message' => __('Your wishlist is full. Remove a download before adding another.', 'stackless-lite-edd')];
        }
        $in = $this->repo()->add($list_id, $download_id, $price_id);
        return ['ok' => $in, 'in' => $in, 'message' => $in ? __('Saved to your wishlist.', 'stackless-lite-edd') : __('Could not save this download. Please try again.', 'stackless-lite-edd')];
    }

    /**
     * Move one entry to the cart. Downloads with variable prices saved without a chosen
     * option go to their download page instead.
     *
     * @return array{ok:bool, message:string, redirect:string}
     */
    private function moveToCart(int $download_id, int $price_id): array
    {
        $target = $this->resolveTarget($download_id, $price_id);
        $fail = ['ok' => false, 'message' => __('This download is not available.', 'stackless-lite-edd'), 'redirect' => ''];
        if (!$target || !function_exists('EDD') || !EDD()->cart) {
            return $fail;
        }
        [$download_id, $price_id] = $target;
        $download = edd_get_download($download_id);
        if (!$download instanceof \EDD_Download) {
            return $fail;
        }
        $link = (string) get_permalink($download_id);
        if (!$download->can_purchase()) {
            return ['ok' => false, 'message' => __('This download cannot be bought right now.', 'stackless-lite-edd'), 'redirect' => ''];
        }
        $options = [];
        if ($download->has_variable_prices()) {
            $prices = (array) $download->get_prices();
            if ($price_id <= 0 || !isset($prices[$price_id])) {
                return ['ok' => false, 'message' => __('Choose the download options first.', 'stackless-lite-edd'), 'redirect' => $link];
            }
            $options['price_id'] = $price_id;
        }
        if (!self::addToCart($download_id, $options)) {
            return ['ok' => false, 'message' => __('This download cannot be added to the cart.', 'stackless-lite-edd'), 'redirect' => $link];
        }
        if ($this->option('remove_on_cart', 'yes') === 'yes') {
            $this->mutate('remove', $download_id, $price_id);
        }
        return ['ok' => true, 'message' => __('Moved to your cart.', 'stackless-lite-edd'), 'redirect' => edd_get_checkout_uri()];
    }

    /**
     * Add to the EDD cart and report whether the cart changed. edd_add_to_cart() gives no
     * failure signal when a filter on edd_add_to_cart_item drops the item.
     *
     * @param array<string, mixed> $options
     */
    private static function addToCart(int $download_id, array $options): bool
    {
        $before = md5((string) wp_json_encode(EDD()->cart->get_contents()));
        edd_add_to_cart($download_id, $options);
        return md5((string) wp_json_encode(EDD()->cart->get_contents())) !== $before;
    }

    /** No-JS form posts. */
    public function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
        if (empty($_POST['stackless_edd_wishlist_op']) || empty($_POST['_stackless_edd_wishlist_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_stackless_edd_wishlist_nonce'])), self::NONCE)) {
            stackless_edd_add_notice(__('Your session expired. Please try again.', 'stackless-lite-edd'), 'error');
            return;
        }
        $op = sanitize_key(wp_unslash($_POST['stackless_edd_wishlist_op']));
        $download_id = isset($_POST['download_id']) ? absint($_POST['download_id']) : 0;
        $price_id = isset($_POST['price_id']) ? absint($_POST['price_id']) : 0;
        // phpcs:enable
        $back = wp_get_referer() ?: home_url('/');

        if ($op === 'move') {
            $result = $this->moveToCart($download_id, $price_id);
            stackless_edd_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($result['redirect'] ?: $back);
            exit;
        }
        if ($op === 'reshare' && is_user_logged_in()) {
            $list = $this->currentList(false);
            if ($list) {
                $this->repo()->newShareKey((int) $list['id']);
                stackless_edd_add_notice(__('A new share link was created. The old link no longer works.', 'stackless-lite-edd'), 'success');
            }
            wp_safe_redirect($back);
            exit;
        }
        if (in_array($op, ['add', 'remove', 'toggle'], true)) {
            $result = $this->mutate($op, $download_id, $price_id);
            stackless_edd_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($back);
            exit;
        }
    }

    public function ajax(): void
    {
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'state'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only op, writes check below.
        if ($op === 'state') {
            // Read-only, and returns the nonce cached pages need for later writes.
            wp_send_json_success(['keys' => $this->currentKeys(), 'nonce' => wp_create_nonce(self::NONCE)]);
        }
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page and try again.', 'stackless-lite-edd'), 'nonce' => wp_create_nonce(self::NONCE)], 403);
        }
        $download_id = isset($_POST['download_id']) ? absint($_POST['download_id']) : 0;
        $price_id = isset($_POST['price_id']) ? absint($_POST['price_id']) : 0;
        if ($op === 'move') {
            $result = $this->moveToCart($download_id, $price_id);
            if ($result['ok']) {
                wp_send_json_success($result + ['keys' => $this->currentKeys()]);
            }
            wp_send_json_error($result, 400);
        }
        if (!in_array($op, ['add', 'remove', 'toggle'], true)) {
            wp_send_json_error(['message' => __('Unknown wishlist action.', 'stackless-lite-edd')], 400);
        }
        $result = $this->mutate($op, $download_id, $price_id);
        $payload = $result + ['keys' => $this->currentKeys()];
        if ($result['ok']) {
            wp_send_json_success($payload);
        }
        wp_send_json_error($payload, 400);
    }

    /* ------------------------------------------------------------------
     * Assets and buttons
     * ---------------------------------------------------------------- */

    public function registerAssets(): void
    {
        wp_register_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/wishlist/wishlist.css', [], STACKLESS_EDD_VERSION);
        wp_register_script(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/wishlist/wishlist.js', [], STACKLESS_EDD_VERSION, true);
        wp_localize_script(self::HANDLE, 'stacklessEddWishlist', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n' => [
                'add' => __('Save to wishlist', 'stackless-lite-edd'),
                'remove' => __('Saved to wishlist', 'stackless-lite-edd'),
                'error' => __('Something went wrong. Please try again.', 'stackless-lite-edd'),
                'copied' => __('Link copied.', 'stackless-lite-edd'),
            ],
        ]);
        // The account tab and the wishlist page render inside the content, after wp_head:
        // load their assets in the head.
        if (is_singular() && $this->onOwnScreen((int) get_queried_object_id())) {
            $this->enqueue();
        }
    }

    private function onOwnScreen(int $page_id): bool
    {
        if ($page_id <= 0) {
            return false;
        }
        if ($page_id === (int) $this->option('page_id', 0)) {
            return true;
        }
        $tab = isset($_GET[Account::QUERY_VAR]) ? sanitize_key(wp_unslash($_GET[Account::QUERY_VAR])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        return $page_id === Account::pageId() && $tab === self::TAB && $this->option('account_tab', 'yes') === 'yes';
    }

    private function enqueue(): void
    {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            $this->registerAssets();
        }
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * @param int|false $price_id fixed price of the purchase link, false when the shopper picks one
     */
    private function button(int $download_id, string $context, $price_id = false): string
    {
        if ($download_id <= 0 || get_post_type($download_id) !== 'download') {
            return '';
        }
        if (!is_user_logged_in() && !$this->guestsAllowed()) {
            return '';
        }
        $variable = edd_has_variable_prices($download_id);
        $fixed = is_numeric($price_id) && $variable ? (int) $price_id : 0;
        $this->enqueue();
        $in = in_array(Merge::key($download_id, $fixed), $this->currentKeys(), true);
        return stackless_edd_get_template_html('wishlist/button.php', [
            'download_id' => $download_id,
            'price_id' => $fixed,
            'context' => $context,
            'in_list' => $in,
            // The single-page button follows the chosen price option (radio buttons only).
            'variable' => $context === 'single' && $variable && !is_numeric($price_id) && !edd_single_price_option_mode($download_id),
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
    }

    /**
     * @param string               $html
     * @param array<string, mixed> $args edd_get_purchase_link() arguments
     * @return string
     */
    public function filterPurchaseForm($html, $args = [])
    {
        $args = is_array($args) ? $args : [];
        $id = isset($args['download_id']) ? absint($args['download_id']) : 0;
        if (!$id || !is_string($html) || (is_admin() && !wp_doing_ajax())) {
            return $html;
        }
        $price_id = isset($args['price_id']) && is_numeric($args['price_id']) ? (int) $args['price_id'] : false;
        if (is_singular('download') && $id === (int) get_queried_object_id()) {
            // The main download's first purchase form is the single-page button; skip repeats.
            if (isset($this->singleRendered[$id]) || $this->option('show_single', 'yes') !== 'yes') {
                return $html;
            }
            $this->singleRendered[$id] = true;
            return $html . $this->button($id, 'single', $price_id);
        }
        if ($this->option('show_loop', 'yes') !== 'yes') {
            return $html;
        }
        return $html . $this->button($id, 'loop', $price_id);
    }

    /** @param int|mixed $download_id */
    public function afterDownloadContent($download_id): void
    {
        $id = absint($download_id);
        if (!$id || isset($this->singleRendered[$id]) || $this->option('show_single', 'yes') !== 'yes') {
            return;
        }
        $this->singleRendered[$id] = true;
        echo $this->button($id, 'single'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /* ------------------------------------------------------------------
     * Wishlist page, block, account tab, share view
     * ---------------------------------------------------------------- */

    public function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script(self::HANDLE . '-block', STACKLESS_EDD_PLUGIN_URL . 'assets/wishlist/wishlist-block.js', ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor'], STACKLESS_EDD_VERSION, true);
        register_block_type('stackless-edd/wishlist', [
            'api_version' => '3',
            'title' => __('Wishlist', 'stackless-lite-edd'),
            'category' => 'easy-digital-downloads',
            'icon' => 'heart',
            'editor_script' => self::HANDLE . '-block',
            'render_callback' => function () {
                return $this->shortcode();
            },
            'supports' => ['html' => false, 'align' => ['wide', 'full']],
        ]);
    }

    /** @param array<string, mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        $this->enqueue();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        $share = isset($_GET['wishlist']) ? sanitize_text_field(wp_unslash($_GET['wishlist'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
        if ($share !== '') {
            return $this->renderShared($share);
        }
        return $this->renderOwn();
    }

    private function renderOwn(): string
    {
        $list = $this->currentList(false);
        $items = $list ? $this->repo()->items((int) $list['id']) : [];
        $share_url = '';
        if ($list && $items && is_user_logged_in()) {
            $page = (int) $this->option('page_id', 0);
            if ($page && get_post_status($page) === 'publish') {
                $share_url = add_query_arg('wishlist', rawurlencode((string) $list['share_key']), (string) get_permalink($page));
            }
        }
        return stackless_edd_get_template_html('wishlist/list.php', [
            'rows' => $this->rows($items),
            'owner' => true,
            'guest' => !is_user_logged_in(),
            'share_url' => $share_url,
            'nonce' => wp_create_nonce(self::NONCE),
            'login_url' => stackless_edd_account_url(),
            'shop_url' => self::shopUrl(),
        ]);
    }

    private function renderShared(string $key): string
    {
        $list = $this->repo()->byShareKey($key);
        if (!$list || !(int) $list['user_id']) {
            return '<div class="stackless-store-notice stackless-store-notice--info edd-alert edd-alert-info">' . esc_html__('This wishlist link is no longer available.', 'stackless-lite-edd') . '</div>';
        }
        add_filter('wp_robots', 'wp_robots_no_robots');
        $user = get_userdata((int) $list['user_id']);
        return stackless_edd_get_template_html('wishlist/list.php', [
            'rows' => $this->rows($this->repo()->items((int) $list['id'])),
            'owner' => is_user_logged_in() && get_current_user_id() === (int) $list['user_id'],
            'guest' => false,
            'share_url' => '',
            'nonce' => wp_create_nonce(self::NONCE),
            'login_url' => '',
            'shop_url' => self::shopUrl(),
            'shared_by' => $user ? $user->display_name : '',
            'shared' => true,
        ]);
    }

    /** Download archive, or the home page when the store disables it. */
    public static function shopUrl(): string
    {
        $url = get_post_type_archive_link('download');
        return $url ? (string) $url : home_url('/');
    }

    /**
     * @param array<int, array{download_id:int, price_id:int}> $items
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $entry) {
            $id = $entry['download_id'];
            $price_id = $entry['price_id'];
            if (get_post_type($id) !== 'download' || get_post_status($id) !== 'publish') {
                continue;
            }
            $download = edd_get_download($id);
            if (!$download instanceof \EDD_Download) {
                continue;
            }
            $variable = $download->has_variable_prices();
            $prices = $variable ? (array) $download->get_prices() : [];
            if ($price_id > 0 && (!$variable || !isset($prices[$price_id]))) {
                continue; // The saved option no longer exists.
            }
            if ($price_id > 0) {
                $price_html = edd_price($id, false, $price_id);
            } elseif ($variable) {
                $price_html = (string) edd_price_range($id);
            } else {
                $price_html = edd_price($id, false);
            }
            $rows[] = [
                'download_id' => $id,
                'price_id' => $price_id,
                'name' => (string) edd_get_download_name($id, $price_id > 0 ? $price_id : null),
                'url' => (string) get_permalink($id),
                'image' => get_the_post_thumbnail($id, 'thumbnail'),
                'price_html' => (string) $price_html,
                'can_move' => $download->can_purchase() && (!$variable || ($price_id > 0 && isset($prices[$price_id]))),
            ];
        }
        return $rows;
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
            'title' => __('Wishlist', 'stackless-lite-edd'),
            'icon' => 'heart',
            'priority' => 80,
            'callback' => function (): void {
                $this->enqueue();
                echo $this->renderOwn(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
            },
        ];
        return $tabs;
    }

    /* ------------------------------------------------------------------
     * Housekeeping and privacy
     * ---------------------------------------------------------------- */

    public function purgeGuests(): void
    {
        $removed = $this->repo()->purgeGuests(max(1, (int) $this->option('guest_days', 30)));
        if ($removed) {
            $this->log(sprintf('Removed %d expired guest wishlist(s).', $removed));
        }
    }

    /** @param int|mixed $user_id */
    public function deleteUser($user_id): void
    {
        $this->repo()->deleteForUser((int) $user_id);
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function registerExporter($exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['stackless-edd-wishlist'] = [
            'exporter_friendly_name' => __('Wishlist', 'stackless-lite-edd'),
            'callback' => [$this, 'export'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function registerEraser($erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['stackless-edd-wishlist'] = [
            'eraser_friendly_name' => __('Wishlist', 'stackless-lite-edd'),
            'callback' => [$this, 'erase'],
        ];
        return $erasers;
    }

    /** @return array{data: array<int, mixed>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $data = [];
        if ($user) {
            $list = $this->repo()->forUser((int) $user->ID, false);
            foreach ($list ? $this->repo()->items((int) $list['id']) : [] as $entry) {
                $name = edd_get_download_name($entry['download_id'], $entry['price_id'] > 0 ? $entry['price_id'] : null);
                $data[] = [
                    'group_id' => 'stackless-edd-wishlist',
                    'group_label' => __('Wishlist', 'stackless-lite-edd'),
                    'item_id' => 'stackless-edd-wishlist-' . Merge::key($entry['download_id'], $entry['price_id']),
                    'data' => [
                        ['name' => __('Download', 'stackless-lite-edd'), 'value' => $name ? (string) $name : '#' . $entry['download_id']],
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
        $removed = false;
        if ($user && $this->repo()->forUser((int) $user->ID, false)) {
            $this->repo()->deleteForUser((int) $user->ID);
            $removed = true;
        }
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
