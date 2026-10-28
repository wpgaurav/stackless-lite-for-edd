<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Per-download checkout options, set in the Checkout box on each download: which payment methods
 * may sell the download, and a link buyers go to after paying (any page on this site or another
 * website) instead of EDD's purchase confirmation page.
 *
 * A cart with several restricted downloads offers only the methods they all allow. Renewals
 * always keep the purchase confirmation page.
 */
final class ProductCheckout
{
    public const METHODS_META = '_stackless_edd_extra_enhancements_methods';
    public const THANK_YOU_META = '_stackless_edd_extra_enhancements_thank_you_url';
    public const REDIRECTED_META = '_stackless_edd_extra_enhancements_redirected';
    private const NOTICE = 'stackless_edd_ee_product_notice_';

    /** @var string[] Hosts of after-purchase links used in this request, for wp_safe_redirect(). */
    private static $hosts = [];

    public function register(): void
    {
        add_filter('edd_enabled_payment_gateways', [$this, 'filterAvailable'], 150);
        add_action('edd_checkout_error_checks', [$this, 'validate'], 30, 2);
        add_action('template_redirect', [$this, 'afterPurchase'], 5);
        add_filter('allowed_redirect_hosts', [$this, 'allowedHosts']);
        if (is_admin()) {
            add_action('add_meta_boxes_download', [$this, 'metaBox'], 40);
            add_action('save_post_download', [$this, 'save'], 20, 1);
            add_action('admin_notices', [$this, 'notice']);
        }
    }

    /* ---- Storage ----------------------------------------------------------------------- */

    /** @return string[] */
    public static function allowedMethods(int $downloadId): array
    {
        $saved = $downloadId > 0 ? get_post_meta($downloadId, self::METHODS_META, true) : [];
        return is_array($saved) ? array_values(array_filter(array_map('sanitize_key', $saved))) : [];
    }

    public static function thankYouUrl(int $downloadId): string
    {
        return $downloadId > 0 ? esc_url_raw((string) get_post_meta($downloadId, self::THANK_YOU_META, true)) : '';
    }

    /**
     * Methods every restricted download allows, or null when no download restricts them.
     *
     * @param int[] $downloadIds
     * @param callable(int):string[]|null $lookup
     * @return string[]|null
     */
    public static function intersect(array $downloadIds, ?callable $lookup = null): ?array
    {
        $lookup = $lookup ?: [self::class, 'allowedMethods'];
        $allowed = null;
        foreach (array_unique(array_map('intval', $downloadIds)) as $id) {
            $routes = $id ? (array) $lookup($id) : [];
            if (!$routes) {
                continue;
            }
            $allowed = $allowed === null ? array_values($routes) : array_values(array_intersect($allowed, $routes));
        }
        return $allowed;
    }

    /** @return int[] */
    private static function cartDownloadIds(): array
    {
        $ids = [];
        if (function_exists('edd_get_cart_contents')) {
            foreach ((array) edd_get_cart_contents() as $item) {
                $ids[] = (int) ($item['id'] ?? 0);
            }
        }
        return $ids;
    }

    /** @return int[] */
    private static function orderDownloadIds(\EDD\Orders\Order $order): array
    {
        $ids = [];
        foreach ((array) $order->get_items() as $item) {
            if (is_object($item)) {
                $ids[] = (int) $item->product_id;
            }
        }
        return $ids;
    }

    /* ---- Checkout ---------------------------------------------------------------------- */

    /**
     * @param mixed $gateways
     * @return mixed
     */
    public function filterAvailable($gateways)
    {
        if (!is_array($gateways) || !$gateways || (is_admin() && !wp_doing_ajax()) || !function_exists('EDD') || !EDD()->cart) {
            return $gateways;
        }
        $allowed = self::intersect(self::cartDownloadIds());
        if ($allowed === null) {
            return $gateways;
        }
        // An empty list makes EDD say no payment method can take the order.
        return array_intersect_key($gateways, array_flip($allowed));
    }

    private static function refusal(string $method, ?array $allowed): ?string
    {
        if ($allowed === null || $method === '' || in_array($method, $allowed, true)) {
            return null;
        }
        return __('The selected payment method is not available for a product in your cart. Choose another payment method.', 'stackless-lite-edd');
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validate($validData, $post): void
    {
        if (!function_exists('edd_get_cart_total') || (float) edd_get_cart_total() <= 0) {
            return;
        }
        $method = is_array($validData) && !empty($validData['gateway']) ? (string) $validData['gateway'] : (is_array($post) && isset($post['edd-gateway']) ? (string) $post['edd-gateway'] : '');
        $message = self::refusal(sanitize_key($method), self::intersect(self::cartDownloadIds()));
        if ($message !== null) {
            edd_set_error('stackless_edd_product_payment_method', $message); // @phpstan-ignore argument.type
        }
    }

    /**
     * On the purchase confirmation page right after checkout, send buyers to the first download's
     * own link. Only paid orders go there: a payment still open (a hosted checkout the buyer left,
     * a payment the provider has not confirmed yet) keeps EDD's page, which shows its state.
     * Renewals keep the receipt too, and each order redirects once, so the receipt stays reachable.
     */
    public function afterPurchase(): void
    {
        if (!function_exists('edd_is_success_page') || !edd_is_success_page()) {
            return;
        }
        $session = edd_get_purchase_session();
        $key = is_array($session) && !empty($session['purchase_key']) ? (string) $session['purchase_key'] : '';
        $order = $key !== '' ? edd_get_order_by('payment_key', $key) : null;
        if (!$order instanceof \EDD\Orders\Order || (string) $order->type !== 'sale' || !in_array((string) $order->status, ['complete', 'publish'], true)) {
            return;
        }
        if (stackless_edd_is_renewal_order((int) $order->id) || edd_get_order_meta((int) $order->id, self::REDIRECTED_META, true)) {
            return;
        }
        foreach (self::orderDownloadIds($order) as $id) {
            $link = self::thankYouUrl($id);
            if ($link === '') {
                continue;
            }
            $host = strtolower((string) wp_parse_url($link, PHP_URL_HOST));
            if ($host !== '') {
                self::$hosts[] = $host;
            }
            edd_update_order_meta((int) $order->id, self::REDIRECTED_META, (string) time());
            wp_safe_redirect($link);
            exit;
        }
    }

    /**
     * @param mixed $hosts
     * @return mixed
     */
    public function allowedHosts($hosts)
    {
        return array_values(array_unique(array_merge((array) $hosts, self::$hosts)));
    }

    /* ---- Download editor --------------------------------------------------------------- */

    /** Gateways to offer: switched on, or already chosen for this download. @return array<string,array{title:string,enabled:bool}> */
    private static function gateways(array $chosen): array
    {
        $list = [];
        if (!function_exists('edd_get_payment_gateways')) {
            return $list;
        }
        $enabled = (array) edd_get_option('gateways', []);
        foreach ((array) edd_get_payment_gateways() as $id => $gateway) {
            $id = (string) $id;
            $on = !empty($enabled[$id]);
            if ($on || in_array($id, $chosen, true)) {
                $label = is_array($gateway) ? (string) ($gateway['admin_label'] ?? ($gateway['checkout_label'] ?? $id)) : $id;
                $list[$id] = ['title' => wp_strip_all_tags($label !== '' ? $label : $id), 'enabled' => $on];
            }
        }
        return $list;
    }

    public function metaBox(): void
    {
        add_meta_box('stackless-edd-checkout-options', __('Checkout', 'stackless-lite-edd'), [$this, 'panel'], 'download', 'side', 'default');
    }

    /** @param \WP_Post $post */
    public function panel($post): void
    {
        $chosen = self::allowedMethods((int) $post->ID);
        echo '<div class="stackless-ee-checkout"><fieldset><legend><strong>' . esc_html__('Payment methods', 'stackless-lite-edd') . '</strong></legend><ul>';
        foreach (self::gateways($chosen) as $id => $gateway) {
            echo '<li><label><input type="checkbox" name="stackless_edd_checkout_methods[]" value="' . esc_attr($id) . '" ' . checked(in_array($id, $chosen, true), true, false) . '> ' . esc_html($gateway['title']) . ($gateway['enabled'] ? '' : ' <em>(' . esc_html__('off', 'stackless-lite-edd') . ')</em>') . '</label></li>';
        }
        echo '</ul></fieldset>';
        echo '<p class="description">' . esc_html__('Leave all unticked to allow every payment method. If a cart has downloads with different choices, buyers see only the methods they all allow.', 'stackless-lite-edd') . '</p>';
        echo '<p><label for="stackless_edd_checkout_thank_you_url"><strong>' . esc_html__('After payment, send buyers to', 'stackless-lite-edd') . '</strong></label><br><input type="text" class="widefat" id="stackless_edd_checkout_thank_you_url" name="stackless_edd_checkout_thank_you_url" value="' . esc_attr((string) get_post_meta($post->ID, self::THANK_YOU_META, true)) . '" placeholder="/welcome/ or https://…"></p>';
        echo '<p class="description">' . esc_html__('Any page on this site (/welcome/) or another website (https://…). Leave empty to show the purchase confirmation page. Renewals always show the purchase confirmation page.', 'stackless-lite-edd') . '</p>';
        wp_nonce_field('stackless_edd_checkout_options', 'stackless_edd_checkout_options_nonce');
        echo '</div>';
    }

    /** "/welcome/" → this site's address; anything that is not a web address → ''. */
    public static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = home_url($url);
        }
        $url = esc_url_raw($url, ['http', 'https']);
        return $url !== '' && wp_http_validate_url($url) ? $url : '';
    }

    /** @param mixed $postId */
    public function save($postId): void
    {
        $postId = (int) $postId;
        if (!$postId || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_product', $postId)
            || !isset($_POST['stackless_edd_checkout_options_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['stackless_edd_checkout_options_nonce'])), 'stackless_edd_checkout_options')) {
            return;
        }
        $known = array_keys(self::gateways(self::allowedMethods($postId)));
        $posted = isset($_POST['stackless_edd_checkout_methods']) && is_array($_POST['stackless_edd_checkout_methods']) ? array_map('sanitize_key', wp_unslash($_POST['stackless_edd_checkout_methods'])) : [];
        $methods = array_values(array_intersect($posted, $known));
        $methods ? update_post_meta($postId, self::METHODS_META, $methods) : delete_post_meta($postId, self::METHODS_META);

        $raw = isset($_POST['stackless_edd_checkout_thank_you_url']) ? sanitize_text_field(wp_unslash($_POST['stackless_edd_checkout_thank_you_url'])) : '';
        $url = self::cleanUrl($raw);
        if ($raw !== '' && $url === '') {
            set_transient(self::NOTICE . get_current_user_id(), __('After-payment link: enter a full web address starting with https://, or a path on this site starting with /.', 'stackless-lite-edd'), 120);
            return;
        }
        $url !== '' ? update_post_meta($postId, self::THANK_YOU_META, $url) : delete_post_meta($postId, self::THANK_YOU_META);
    }

    public function notice(): void
    {
        $message = get_transient(self::NOTICE . get_current_user_id());
        if (!is_string($message) || $message === '') {
            return;
        }
        delete_transient(self::NOTICE . get_current_user_id());
        echo '<div class="notice notice-error stackless-notice"><p>' . esc_html($message) . '</p></div>';
    }
}
