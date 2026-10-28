<?php

namespace StacklessEDD\Modules\ServiceProducts;

defined('ABSPATH') || exit;

/**
 * Service downloads: after-payment instructions (rich text) with an optional booking or intake
 * link on the purchase confirmation and receipt pages (which the account Orders tab links to)
 * and in the purchase receipt email.
 */
class Module extends \StacklessEDD\Core\Module
{
    public const META = '_stackless_edd_service';
    public const META_INSTRUCTIONS = '_stackless_edd_service_instructions';
    public const META_LINK = '_stackless_edd_service_link';
    public const META_LINK_LABEL = '_stackless_edd_service_link_label';
    public const ITEM_META = '_stackless_edd_service';
    private const ERRORS = 'stackless_edd_service_products_errors_';

    /** @var array<int, bool> orders already printed on this request */
    private $printed = [];

    public function id(): string
    {
        return 'service_products';
    }

    public function title(): string
    {
        return __('Service Products', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Mark downloads as services, with after-payment instructions and an optional booking or intake link on the purchase confirmation, the receipt email and the customer account.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'store';
    }

    public function settingsFields(): array
    {
        return [
            ['type' => 'title', 'id' => 'stackless_edd_service_products_options', 'title' => __('Service products', 'stackless-lite-edd'), 'desc' => __('Turn on "Service" in a download\'s Settings section, then add instructions in its Service box.', 'stackless-lite-edd')],
            [
                'id' => 'stackless_edd_service_products_heading',
                'type' => 'text',
                'title' => __('Heading', 'stackless-lite-edd'),
                'desc' => __('Shown above the instructions. %s is replaced with the download name.', 'stackless-lite-edd'),
                'desc_tip' => true,
                'default' => __('%s: what happens next', 'stackless-lite-edd'),
            ],
            [
                'id' => 'stackless_edd_service_products_emails',
                'type' => 'checkbox',
                'title' => __('Emails', 'stackless-lite-edd'),
                'desc' => __('Include instructions in the customer\'s purchase receipt email', 'stackless-lite-edd'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_edd_service_products_pending_note',
                'type' => 'text',
                'title' => __('Before payment', 'stackless-lite-edd'),
                'desc' => __('Shown instead of the instructions while an order is unpaid (for example a manual payment). Leave empty to show nothing.', 'stackless-lite-edd'),
                'desc_tip' => true,
                'default' => __('Instructions for your service appear here as soon as your payment is confirmed.', 'stackless-lite-edd'),
            ],
            ['type' => 'sectionend', 'id' => 'stackless_edd_service_products_options'],
        ];
    }

    public function boot(): void
    {
        // Download editor.
        add_action('edd_meta_box_settings_fields', [$this, 'toggle'], 15, 2);
        add_action('add_meta_boxes_download', [$this, 'metaBox']);
        add_action('edd_save_download', [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);
        add_action('admin_notices', [$this, 'notices']);

        // Orders.
        add_action('edd_built_order', [$this, 'markLines'], 20, 1);
        add_action('edd_order_receipt_after_table', [$this, 'orderDetails'], 5, 1);
        add_filter('render_block_edd/confirmation', [$this, 'confirmationBlock'], 20, 1);
        add_filter('edd_order_receipt', [$this, 'email'], 20, 2);
    }

    /* ------------------------------------------------------------------
     * Detection
     * ---------------------------------------------------------------- */

    /** @param int|string|null $downloadId */
    public static function isService($downloadId): bool
    {
        $downloadId = (int) $downloadId;
        return $downloadId > 0 && get_post_meta($downloadId, self::META, true) === 'yes';
    }

    /* ------------------------------------------------------------------
     * Download editor
     * ---------------------------------------------------------------- */

    /**
     * The "Service" switch in the download editor's Settings section.
     *
     * @param int|string $downloadId
     * @param mixed      $download
     */
    public function toggle($downloadId, $download = null): void
    {
        $downloadId = (int) $downloadId;
        if (!current_user_can('edit_product', $downloadId)) {
            return;
        }
        echo '<div class="edd-form-group stackless-service-toggle"><div class="edd-form-group__control">';
        if (class_exists(\EDD\HTML\CheckboxToggle::class)) {
            (new \EDD\HTML\CheckboxToggle([
                'name' => self::META,
                'id' => self::META,
                'current' => self::isService($downloadId),
                'class' => 'edd-form-group__input',
                'label' => __('Service', 'stackless-lite-edd'),
            ]))->output();
        } else {
            printf('<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s> %3$s</label>', esc_attr(self::META), checked(self::isService($downloadId), true, false), esc_html__('Service', 'stackless-lite-edd'));
        }
        echo '<input type="hidden" name="stackless_edd_service_present" value="1">';
        echo '</div><p class="description edd-form-group__help">' . esc_html__('Customers get the instructions from the Service box after paying.', 'stackless-lite-edd') . '</p></div>';
    }

    public function metaBox(): void
    {
        add_meta_box('stackless-edd-service', __('Service', 'stackless-lite-edd'), [$this, 'panel'], 'download', 'normal', 'default');
    }

    public function adminAssets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'download') {
            wp_enqueue_script('stackless-edd-service_products-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/service_products/admin.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        }
    }

    /** @param \WP_Post $post */
    public function panel($post): void
    {
        $downloadId = $post instanceof \WP_Post ? (int) $post->ID : 0;
        echo '<div class="stackless-service-panel">';
        echo '<p class="stackless-docs-field">' . \StacklessEDD\Admin\Documentation::link('service_products', __('How this works', 'stackless-lite-edd')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        echo '<p><label for="stackless_edd_service_instructions"><strong>' . esc_html__('After-payment instructions', 'stackless-lite-edd') . '</strong></label></p>';
        wp_editor((string) get_post_meta($downloadId, self::META_INSTRUCTIONS, true), 'stackless_edd_service_instructions', [
            'textarea_name' => 'stackless_edd_service_instructions',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
        ]);
        echo '<p class="description">' . esc_html__('How the customer books, sends a brief or receives the service. Shown on the purchase confirmation and receipt, in the purchase receipt email and in the customer account once the order is paid.', 'stackless-lite-edd') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        printf(
            '<tr><th scope="row"><label for="stackless_edd_service_link">%s</label></th><td><input type="url" class="regular-text" id="stackless_edd_service_link" name="stackless_edd_service_link" value="%s" placeholder="%s"><p class="description">%s</p></td></tr>',
            esc_html__('Booking or intake link', 'stackless-lite-edd'),
            esc_attr((string) get_post_meta($downloadId, self::META_LINK, true)),
            esc_attr('https://cal.com/you/session?email={email}'),
            esc_html__('Optional. Placeholders: {order_number}, {order_id}, {email}, {first_name}, {last_name}, {name}, {product}. Values are URL-encoded.', 'stackless-lite-edd')
        );
        printf(
            '<tr><th scope="row"><label for="stackless_edd_service_link_label">%s</label></th><td><input type="text" class="regular-text" id="stackless_edd_service_link_label" name="stackless_edd_service_link_label" value="%s" placeholder="%s"></td></tr>',
            esc_html__('Link button text', 'stackless-lite-edd'),
            esc_attr((string) get_post_meta($downloadId, self::META_LINK_LABEL, true)),
            esc_attr__('Book your session', 'stackless-lite-edd')
        );
        echo '</tbody></table></div>';
    }

    /**
     * Saves the switch and the Service box. EDD verified its download editor nonce before this action.
     *
     * @param int|string $postId
     * @param \WP_Post   $post
     */
    public function save($postId, $post = null): void
    {
        $postId = (int) $postId;
        if (!current_user_can('edit_product', $postId)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD verified edd_download_meta_box_nonce.
        if (isset($_POST['stackless_edd_service_present'])) {
            update_post_meta($postId, self::META, isset($_POST[self::META]) ? 'yes' : 'no');
        }
        if (isset($_POST['stackless_edd_service_instructions'])) {
            update_post_meta($postId, self::META_INSTRUCTIONS, wp_kses_post(wp_unslash((string) $_POST['stackless_edd_service_instructions'])));
        }
        if (isset($_POST['stackless_edd_service_link'])) {
            $raw = trim(sanitize_text_field(wp_unslash((string) $_POST['stackless_edd_service_link'])));
            // Keep placeholders intact; check the link with sample values.
            $valid = $raw === '' || Links::fill($raw, ['order_id' => '1', 'order_number' => '1', 'email' => 'a@b.c', 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B', 'product' => 'P']) !== '';
            update_post_meta($postId, self::META_LINK, $valid ? $raw : '');
            if (!$valid) {
                set_transient(self::ERRORS . get_current_user_id(), [__('The booking link must start with http:// or https://. It was not saved.', 'stackless-lite-edd')], 120);
            }
        }
        if (isset($_POST['stackless_edd_service_link_label'])) {
            update_post_meta($postId, self::META_LINK_LABEL, sanitize_text_field(wp_unslash((string) $_POST['stackless_edd_service_link_label'])));
        }
        // phpcs:enable
    }

    public function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'download') {
            return;
        }
        $key = self::ERRORS . get_current_user_id();
        $errors = get_transient($key);
        if (!is_array($errors) || !$errors) {
            return;
        }
        delete_transient($key);
        foreach ($errors as $error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
        }
    }

    /* ------------------------------------------------------------------
     * Orders
     * ---------------------------------------------------------------- */

    /**
     * Marks service lines on the order as it is built, so later edits to the download keep the order's state.
     *
     * @param int|string $orderId
     */
    public function markLines($orderId): void
    {
        $order = edd_get_order((int) $orderId);
        if (!$order) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if (self::isService((int) $item->product_id)) {
                edd_add_order_item_meta((int) $item->id, self::ITEM_META, 'yes', true);
            }
        }
    }

    private function isServiceLine(\EDD\Orders\Order_Item $item): bool
    {
        return edd_get_order_item_meta((int) $item->id, self::ITEM_META, true) === 'yes' || self::isService((int) $item->product_id);
    }

    /** "paid", "pending" or "closed" for an order. */
    public function state(\EDD\Orders\Order $order): string
    {
        return Links::orderState((string) $order->status, (array) edd_get_complete_order_statuses());
    }

    /**
     * Service blocks for an order: one per service download.
     *
     * @return array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}>
     */
    public function blocks(\EDD\Orders\Order $order): array
    {
        $lines = [];
        foreach ($order->get_items() as $item) {
            $lines[] = ['product_id' => (int) $item->product_id, 'is_service' => $this->isServiceLine($item)];
        }
        $blocks = [];
        $heading = (string) $this->option('heading', __('%s: what happens next', 'stackless-lite-edd'));
        [$first, $last] = $this->names($order);
        foreach (Links::serviceProducts($lines) as $id) {
            if (get_post_type($id) !== 'download') {
                continue;
            }
            $name = (string) get_the_title($id);
            $instructions = (string) get_post_meta($id, self::META_INSTRUCTIONS, true);
            $link = Links::fill((string) get_post_meta($id, self::META_LINK, true), [
                'order_id' => (string) $order->id,
                'order_number' => (string) $order->get_number(),
                'email' => (string) $order->email,
                'first_name' => $first,
                'last_name' => $last,
                'name' => trim($first . ' ' . $last),
                'product' => wp_specialchars_decode($name, ENT_QUOTES),
            ]);
            if (trim(wp_strip_all_tags($instructions)) === '' && $link === '') {
                continue;
            }
            $label = (string) get_post_meta($id, self::META_LINK_LABEL, true);
            $blocks[] = [
                'name' => $name,
                'heading' => str_replace('%s', $name, $heading),
                'instructions' => $instructions,
                'link' => $link,
                'link_label' => $label !== '' ? $label : __('Book your session', 'stackless-lite-edd'),
            ];
        }
        return $blocks;
    }

    /** @return array{0:string, 1:string} the customer's first and last name for an order */
    private function names(\EDD\Orders\Order $order): array
    {
        // get_address() returns an Order_Address, or a plain object without a name when there is none.
        $address = (object) $order->get_address();
        $read = static function (string $key) use ($address): string {
            return isset($address->{$key}) && is_scalar($address->{$key}) ? (string) $address->{$key} : '';
        };
        $first = $read('first_name');
        $last = $read('last_name');
        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }
        $name = $read('name');
        if ($name === '' && $order->customer_id) {
            $customer = edd_get_customer((int) $order->customer_id);
            $name = $customer ? (string) $customer->name : '';
        }
        return Links::splitName($name);
    }

    private function hasServices(\EDD\Orders\Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            if ($this->isServiceLine($item)) {
                return true;
            }
        }
        return false;
    }

    /** The instructions (or the before-payment note) for an order, once per request. */
    public function detailsHtml(\EDD\Orders\Order $order): string
    {
        if (isset($this->printed[(int) $order->id])) {
            return '';
        }
        $this->printed[(int) $order->id] = true;
        $state = $this->state($order);
        if ($state !== 'paid') {
            $note = (string) $this->option('pending_note', '');
            if ($note !== '' && $state === 'pending' && $this->hasServices($order)) {
                return '<p class="stackless-service-pending edd-alert edd-alert-info">' . esc_html($note) . '</p>';
            }
            return '';
        }
        $blocks = $this->blocks($order);
        if (!$blocks) {
            return '';
        }
        wp_enqueue_style('stackless-edd-service_products', STACKLESS_EDD_PLUGIN_URL . 'assets/service_products/service.css', [], STACKLESS_EDD_VERSION);
        return stackless_edd_get_template_html('service_products/instructions.php', ['blocks' => $blocks, 'order' => $order]);
    }

    /**
     * Receipt page ([edd_receipt] and the Receipt block), which the account Orders tab links to.
     *
     * @param \EDD\Orders\Order|int|mixed $order
     */
    public function orderDetails($order): void
    {
        $order = $order instanceof \EDD\Orders\Order ? $order : edd_get_order((int) $order);
        if (!$order instanceof \EDD\Orders\Order) {
            return;
        }
        echo $this->detailsHtml($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
    }

    /**
     * The Confirmation block on the success page has no receipt hook, so the instructions follow it.
     *
     * @param mixed $content
     * @return mixed
     */
    public function confirmationBlock($content)
    {
        if (!is_string($content) || is_admin() || !function_exists('edd_get_purchase_session')) {
            return $content;
        }
        $session = edd_get_purchase_session();
        $key = is_array($session) ? (string) ($session['purchase_key'] ?? '') : '';
        $order = $key !== '' ? edd_get_order_by('payment_key', $key) : false;
        if (!$order instanceof \EDD\Orders\Order) {
            return $content;
        }
        return $content . $this->detailsHtml($order);
    }

    /**
     * Adds the instructions to the customer's purchase receipt email (paid orders only).
     *
     * @param mixed                       $content
     * @param \EDD\Orders\Order|mixed     $order
     * @return mixed
     */
    public function email($content, $order = null)
    {
        if (!is_string($content) || !$order instanceof \EDD\Orders\Order || $this->option('emails', 'yes') !== 'yes' || $this->state($order) !== 'paid') {
            return $content;
        }
        $blocks = $this->blocks($order);
        if (!$blocks) {
            return $content;
        }
        $plain = (string) apply_filters('edd_email_template', edd_get_option('email_template', 'default')) === 'none';
        return $content . stackless_edd_get_template_html($plain ? 'service_products/email-plain.php' : 'service_products/email.php', ['blocks' => $blocks, 'order' => $order]);
    }
}
