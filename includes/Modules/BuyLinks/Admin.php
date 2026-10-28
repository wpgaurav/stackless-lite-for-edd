<?php

namespace StacklessEDD\Modules\BuyLinks;

defined('ABSPATH') || exit;

/** "Buy link" box on the download screen: link generator and the per-download Buy now override. */
final class Admin
{
    public const AJAX = 'stackless_edd_buy_links_generate';

    public function register(): void
    {
        add_action('add_meta_boxes_download', [$this, 'metaBox'], 30);
        add_action('save_post_download', [$this, 'saveDownload'], 20, 2);
        add_action('wp_ajax_' . self::AJAX, [$this, 'generate']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function metaBox(): void
    {
        add_meta_box('stackless-edd-buy-link', __('Buy link', 'stackless-lite-edd'), [$this, 'render'], 'download', 'side', 'default');
    }

    public function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_post_type() !== 'download') {
            return;
        }
        $url = STACKLESS_EDD_PLUGIN_URL . 'assets/buy_links/';
        wp_enqueue_style('stackless-edd-buy_links-admin', $url . 'admin.css', [], STACKLESS_EDD_VERSION);
        wp_enqueue_script('stackless-edd-buy_links-admin', $url . 'admin.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_localize_script('stackless-edd-buy_links-admin', 'stacklessEddBuyLinks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::AJAX),
            'copied' => __('Link copied.', 'stackless-lite-edd'),
            'copyFailed' => __('Copy failed. Select the link and copy it yourself.', 'stackless-lite-edd'),
            'error' => __('The link could not be made. Save the download and try again.', 'stackless-lite-edd'),
        ]);
    }

    /** Other downloads (and their price options) for "Also add", capped. */
    private static function extraOptions(int $exclude): string
    {
        $html = '';
        $posts = get_posts(['post_type' => 'download', 'post_status' => ['publish', 'private'], 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false]);
        foreach ($posts as $post) {
            if ((int) $post->ID === (int) $exclude) {
                continue;
            }
            $title = wp_strip_all_tags(get_the_title($post));
            $prices = edd_has_variable_prices($post->ID) ? (array) edd_get_variable_prices($post->ID) : [];
            if (!$prices) {
                $html .= '<option value="' . (int) $post->ID . '">' . esc_html($title . ' · #' . $post->ID) . '</option>';
                continue;
            }
            foreach ($prices as $key => $price) {
                $html .= '<option value="' . (int) $post->ID . ':' . (int) $key . '">' . esc_html($title . ' – ' . wp_strip_all_tags((string) ($price['name'] ?? '')) . ' · #' . $post->ID) . '</option>';
            }
        }
        return $html;
    }

    /** @param \WP_Post $post */
    public function render($post): void
    {
        $download = edd_get_download((int) $post->ID);
        if (!$download instanceof \EDD_Download) {
            return;
        }
        $required = Links::signingRequired();
        $override = (string) get_post_meta($post->ID, Module::META_BUY_NOW, true);
        wp_nonce_field('stackless_edd_buy_links_download', 'stackless_edd_buy_links_nonce');
        echo '<div class="stackless-bl" data-stackless-bl data-product="' . (int) $post->ID . '">';
        if ($post->post_status !== 'publish') {
            echo '<p class="description">' . esc_html__('Publish the download before sharing its link. Links to drafts only work for store managers.', 'stackless-lite-edd') . '</p>';
        }
        if ($download->has_variable_prices()) {
            echo '<p><label for="stackless-bl-variation">' . esc_html__('Price option', 'stackless-lite-edd') . '</label><select id="stackless-bl-variation" class="widefat" data-bl-variation><option value="">' . esc_html__('Choose a price option', 'stackless-lite-edd') . '</option>';
            foreach ((array) $download->get_prices() as $key => $price) {
                echo '<option value="' . (int) $key . '">' . esc_html(wp_strip_all_tags((string) ($price['name'] ?? '')) ?: '#' . (int) $key) . '</option>';
            }
            echo '</select></p>';
        }
        echo '<p><label for="stackless-bl-qty">' . esc_html__('Quantity', 'stackless-lite-edd') . '</label><input type="number" id="stackless-bl-qty" class="small-text" min="1" max="' . (int) LinkParser::MAX_QTY . '" value="1" data-bl-qty></p>';
        echo '<p><label for="stackless-bl-extra">' . esc_html__('Also add', 'stackless-lite-edd') . '</label><select id="stackless-bl-extra" multiple size="4" style="width:100%" data-bl-extra>' . self::extraOptions((int) $post->ID) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in extraOptions().
        echo '<p><label for="stackless-bl-coupon">' . esc_html__('Discount code', 'stackless-lite-edd') . '</label><input type="text" id="stackless-bl-coupon" class="widefat" maxlength="200" data-bl-coupon placeholder="' . esc_attr__('Optional', 'stackless-lite-edd') . '"></p>';
        echo '<p><label for="stackless-bl-clear">' . esc_html__('Existing cart', 'stackless-lite-edd') . '</label><select id="stackless-bl-clear" class="widefat" data-bl-clear><option value="">' . esc_html__('Store default', 'stackless-lite-edd') . '</option><option value="1">' . esc_html__('Empty it first', 'stackless-lite-edd') . '</option><option value="0">' . esc_html__('Keep it', 'stackless-lite-edd') . '</option></select></p>';
        echo '<p><label><input type="checkbox" data-bl-sign' . checked(true, true, false) . disabled($required, true, false) . '> ' . esc_html__('Sign the link so it cannot be edited', 'stackless-lite-edd') . '</label></p>';
        echo '<p data-bl-exp-row><label for="stackless-bl-exp">' . esc_html__('Expires (optional)', 'stackless-lite-edd') . '</label><input type="date" id="stackless-bl-exp" class="widefat" data-bl-exp min="' . esc_attr(wp_date('Y-m-d')) . '"></p>';
        echo '<p><button type="button" class="button" data-bl-generate>' . esc_html__('Make link', 'stackless-lite-edd') . '</button></p>';
        echo '<div class="stackless-bl-result" data-bl-result hidden><label for="stackless-bl-url" class="screen-reader-text">' . esc_html__('Buy link', 'stackless-lite-edd') . '</label><input type="text" id="stackless-bl-url" class="widefat code" readonly data-bl-url><button type="button" class="button button-small" data-bl-copy>' . esc_html__('Copy', 'stackless-lite-edd') . '</button></div>';
        echo '<p class="stackless-bl-status" role="status" aria-live="polite" data-bl-status></p>';
        echo '<hr><p><label for="stackless-bl-buy-now">' . esc_html__('Buy now button', 'stackless-lite-edd') . '</label><select id="stackless-bl-buy-now" name="stackless_edd_buy_links_buy_now" class="widefat">';
        foreach (['' => __('Store default', 'stackless-lite-edd'), 'yes' => __('Show on this download', 'stackless-lite-edd'), 'no' => __('Hide on this download', 'stackless-lite-edd')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($override, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p></div>';
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownload($postId, $post = null): void
    {
        $postId = (int) $postId;
        if (!isset($_POST['stackless_edd_buy_links_nonce']) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId)) {
            return;
        }
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['stackless_edd_buy_links_nonce'])), 'stackless_edd_buy_links_download') || !current_user_can('edit_product', $postId)) {
            return;
        }
        $value = isset($_POST['stackless_edd_buy_links_buy_now']) ? sanitize_key(wp_unslash($_POST['stackless_edd_buy_links_buy_now'])) : '';
        if ($value === 'yes' || $value === 'no') {
            update_post_meta($postId, Module::META_BUY_NOW, $value);
        } else {
            delete_post_meta($postId, Module::META_BUY_NOW);
        }
    }

    public function generate(): void
    {
        check_ajax_referer(self::AJAX, 'nonce');
        $downloadId = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        if (!$downloadId || !current_user_can('edit_product', $downloadId)) {
            wp_send_json_error(['message' => __('You cannot make links for this download.', 'stackless-lite-edd')], 403);
        }
        $download = edd_get_download($downloadId);
        if (!$download instanceof \EDD_Download || empty($download->ID)) {
            wp_send_json_error(['message' => __('Download not found.', 'stackless-lite-edd')], 404);
        }
        $qty = isset($_POST['qty']) ? max(1, min(LinkParser::MAX_QTY, absint(wp_unslash($_POST['qty'])))) : 1;
        $items = [];
        if ($download->has_variable_prices()) {
            $priceId = isset($_POST['variation_id']) && $_POST['variation_id'] !== '' ? absint(wp_unslash($_POST['variation_id'])) : -1;
            if ($priceId < 0 || !array_key_exists($priceId, (array) $download->get_prices())) {
                wp_send_json_error(['message' => __('Choose a price option first.', 'stackless-lite-edd')], 400);
            }
            $items[] = ['product' => $downloadId, 'variation' => $priceId, 'qty' => $qty];
        } else {
            $items[] = ['product' => $downloadId, 'variation' => 0, 'qty' => $qty];
        }
        $skipped = 0;
        $extra = isset($_POST['extra']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['extra'])) : [];
        foreach (array_slice(array_filter($extra), 0, LinkParser::MAX_ITEMS - 1) as $value) {
            if (!preg_match('/^(\d+)(?::(\d+))?$/', (string) $value, $m)) {
                $skipped++;
                continue;
            }
            $other = edd_get_download((int) $m[1]);
            if (!$other instanceof \EDD_Download || empty($other->ID)) {
                $skipped++;
                continue;
            }
            if ($other->has_variable_prices() && (!isset($m[2]) || !array_key_exists((int) $m[2], (array) $other->get_prices()))) {
                $skipped++;
                continue;
            }
            $items[] = ['product' => (int) $other->ID, 'variation' => $other->has_variable_prices() ? (int) $m[2] : 0, 'qty' => 1];
        }
        $sign = Links::signingRequired() || (isset($_POST['sign']) && sanitize_key(wp_unslash($_POST['sign'])) === '1');
        $exp = 0;
        $date = isset($_POST['exp']) ? sanitize_text_field(wp_unslash($_POST['exp'])) : '';
        if ($sign && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $end = date_create_immutable($date . ' 23:59:59', wp_timezone());
            $exp = $end ? $end->getTimestamp() : 0;
        }
        $url = Links::url($items, [
            'coupon' => isset($_POST['coupon']) ? sanitize_text_field(wp_unslash($_POST['coupon'])) : '',
            'clear' => isset($_POST['clear']) ? sanitize_key(wp_unslash($_POST['clear'])) : '',
            'sign' => $sign,
            'exp' => $exp,
        ]);
        $message = $skipped
            /* translators: %d: number of downloads left out */
            ? sprintf(_n('%d download was left out: a download with variable prices needs a specific price option.', '%d downloads were left out: a download with variable prices needs a specific price option.', $skipped, 'stackless-lite-edd'), $skipped)
            : __('Link ready.', 'stackless-lite-edd');
        wp_send_json_success(['url' => $url, 'message' => $message]);
    }
}
