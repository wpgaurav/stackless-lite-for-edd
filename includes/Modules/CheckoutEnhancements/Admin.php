<?php

namespace StacklessEDD\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/** Settings fields, the consent editor, the media picker and the order screen box. */
final class Admin
{
    public const CONSENT_TYPE = 'stackless_edd_ce_consents';
    public const MEDIA_TYPE = 'stackless_edd_ce_media';

    /** @var string|null */
    private static $downloadOptions;

    public function register(): void
    {
        add_action('stackless_edd_admin_field_' . self::CONSENT_TYPE, [$this, 'renderConsents']);
        add_action('stackless_edd_admin_field_' . self::MEDIA_TYPE, [$this, 'renderMedia']);
        add_filter('stackless_edd_settings_sanitize_option_' . Settings::CONSENTS, [$this, 'sanitizeConsents'], 10, 3);
        add_filter('stackless_edd_settings_sanitize_option_' . Settings::TRUST_IMAGE, [$this, 'sanitizeMedia'], 10, 3);
        add_action('stackless_edd_settings_saved_checkout_enhancements', [Settings::class, 'flush']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('edd_view_order_details_sidebar_after', [$this, 'orderBox'], 30);
    }

    public function fields(): array
    {
        $p = Settings::PREFIX;
        $fields = [
            ['title' => __('Consent checkboxes', 'stackless-lite-edd'), 'type' => 'title', 'id' => $p . 'consents_section', 'desc' => __('Shown above the purchase button on the checkout, whether it uses the [download_checkout] shortcode or the EDD Checkout block. Each order stores the exact wording, its version, the answer and the time.', 'stackless-lite-edd')],
            ['title' => __('Checkboxes', 'stackless-lite-edd'), 'type' => self::CONSENT_TYPE, 'id' => Settings::CONSENTS, 'default' => []],
            ['title' => __('Show answers to customers', 'stackless-lite-edd'), 'desc' => __('On the purchase receipt and the order in the customer\'s purchase history', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => $p . 'show_account', 'default' => 'yes'],
            ['title' => __('Show answers in emails', 'stackless-lite-edd'), 'desc' => __('In the purchase receipt and the store\'s new sale notification', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => $p . 'show_emails', 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'consents_section'],

            ['title' => __('Shorter checkout for free orders', 'stackless-lite-edd'), 'type' => 'title', 'id' => $p . 'lean_section', 'desc' => __('EDD already skips the billing address on free orders, and on paid orders asks for it only when tax or your Downloads → Settings → Payments → Checkout address fields need it. It also hides the discount field when there is nothing to pay. These switches cover the rest.', 'stackless-lite-edd')],
            ['title' => __('Free downloads', 'stackless-lite-edd'), 'desc' => __('Hide the currency selector when nothing is charged', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => $p . 'lean_free', 'default' => 'yes', 'desc_tip' => __('When nothing is charged: no item price, no fees and no subscription to bill later.', 'stackless-lite-edd')],
            ['title' => __('Free order button', 'stackless-lite-edd'), 'desc' => __('Relabel the purchase button when there is nothing to pay', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => $p . 'lean_free_button', 'default' => 'yes', 'desc_tip' => __('Applies when the order total is zero, after discounts, and nothing is billed later. It takes the place of EDD\'s Free Download label and your own purchase button label on those orders.', 'stackless-lite-edd')],
            ['title' => __('Free order button text', 'stackless-lite-edd'), 'type' => 'text', 'id' => $p . 'lean_free_button_text', 'default' => __('Get it for free', 'stackless-lite-edd'), 'placeholder' => __('Get it for free', 'stackless-lite-edd'), 'custom_attributes' => ['maxlength' => 60]],
            ['type' => 'sectionend', 'id' => $p . 'lean_section'],

            ['title' => __('Checkout wording', 'stackless-lite-edd'), 'type' => 'title', 'id' => $p . 'wording_section', 'desc' => __('Leave a box empty to keep EDD\'s text. Address fields only show when EDD asks for an address. A custom purchase button label replaces payment-method specific button text too.', 'stackless-lite-edd')],
        ];
        foreach (Settings::fieldLabels() as $key => $row) {
            /* translators: %s: checkout field name */
            $fields[] = ['title' => sprintf(__('%s field', 'stackless-lite-edd'), $row[0]), 'type' => 'text', 'id' => $p . 'label_' . $key, 'default' => '', 'placeholder' => $row[1][0], 'custom_attributes' => ['maxlength' => 80]];
        }
        foreach (Settings::textLabels() as $slug => $row) {
            $fields[] = ['title' => $row[0], 'type' => 'text', 'id' => $p . 'text_' . $slug, 'default' => '', 'placeholder' => $row[1][0], 'custom_attributes' => ['maxlength' => 120]];
        }
        $fields[] = ['title' => __('Purchase button', 'stackless-lite-edd'), 'type' => 'text', 'id' => $p . 'button', 'default' => '', 'placeholder' => __('Purchase', 'stackless-lite-edd'), 'custom_attributes' => ['maxlength' => 60]];
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'wording_section'];

        return array_merge($fields, [
            ['title' => __('Trust block under payment', 'stackless-lite-edd'), 'type' => 'title', 'id' => $p . 'trust_section', 'desc' => __('A short reassurance under the payment details: what protects the payment, an optional payment methods image, and a link to your refund policy.', 'stackless-lite-edd')],
            ['title' => __('Show trust block', 'stackless-lite-edd'), 'desc' => __('Show the trust block at checkout', 'stackless-lite-edd'), 'type' => 'checkbox', 'id' => $p . 'trust_enabled', 'default' => 'no'],
            ['title' => __('Heading', 'stackless-lite-edd'), 'type' => 'text', 'id' => $p . 'trust_heading', 'default' => __('Secure checkout', 'stackless-lite-edd'), 'custom_attributes' => ['maxlength' => 80]],
            ['title' => __('Reassurance lines', 'stackless-lite-edd'), 'type' => 'textarea', 'id' => $p . 'trust_lines', 'default' => __('Payments are encrypted and processed by our payment provider.', 'stackless-lite-edd'), 'desc' => __('One per line, up to 6. Keep them true for your store, for example your refund window or support hours.', 'stackless-lite-edd'), 'css' => 'min-height:96px'],
            ['title' => __('Payment methods image', 'stackless-lite-edd'), 'type' => self::MEDIA_TYPE, 'id' => Settings::TRUST_IMAGE, 'default' => 0, 'desc' => __('Use an image of the payment brands your gateways really accept. Its alt text from the media library is used at checkout.', 'stackless-lite-edd')],
            ['title' => __('Refund policy page', 'stackless-lite-edd'), 'type' => 'single_select_page', 'id' => $p . 'trust_policy_page', 'default' => ''],
            ['title' => __('Link text', 'stackless-lite-edd'), 'type' => 'text', 'id' => $p . 'trust_policy_label', 'default' => __('Refund policy', 'stackless-lite-edd'), 'custom_attributes' => ['maxlength' => 60]],
            ['type' => 'sectionend', 'id' => $p . 'trust_section'],
        ]);
    }

    private function onScreen(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $module = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : '';
        // phpcs:enable
        return $page === \StacklessEDD\Admin\SettingsPage::SETTINGS && $module === 'checkout_enhancements';
    }

    public function assets(): void
    {
        if (!$this->onScreen()) {
            return;
        }
        wp_enqueue_media();
        $url = STACKLESS_EDD_PLUGIN_URL . 'assets/checkout_enhancements/';
        wp_enqueue_style('stackless-edd-checkout_enhancements-admin', $url . 'admin.css', [], STACKLESS_EDD_VERSION);
        wp_enqueue_script('stackless-edd-checkout_enhancements-admin', $url . 'admin.js', ['media-views'], STACKLESS_EDD_VERSION, true);
        wp_localize_script('stackless-edd-checkout_enhancements-admin', 'stacklessEddCeAdmin', [
            'presets' => Settings::consentPresets(),
            'types' => Settings::consentTypes(),
            'max' => ConsentRules::MAX_CONSENTS,
            'confirmRemove' => __('Remove this consent? Orders already placed keep their record.', 'stackless-lite-edd'),
            'mediaTitle' => __('Choose a payment methods image', 'stackless-lite-edd'),
            'mediaButton' => __('Use this image', 'stackless-lite-edd'),
        ]);
    }

    /**
     * @param mixed $value
     * @param mixed $field
     * @param mixed $raw
     * @return array[]
     */
    public function sanitizeConsents($value, $field, $raw): array
    {
        $previous = get_option(Settings::CONSENTS, []);
        return Settings::sanitizeConsents(is_array($raw) ? $raw : [], is_array($previous) ? $previous : []);
    }

    /**
     * @param mixed $value
     * @param mixed $field
     * @param mixed $raw
     */
    public function sanitizeMedia($value, $field, $raw): int
    {
        $id = absint(is_scalar($raw) ? $raw : 0);
        return $id && wp_attachment_is_image($id) ? $id : 0;
    }

    /** @param array<string, mixed> $field */
    public function renderConsents(array $field): void
    {
        $consents = Settings::consents();
        $name = (string) $field['id'];
        echo '<tr><th scope="row" class="titledesc">' . esc_html((string) ($field['title'] ?? '')) . '</th><td class="forminp stackless-ce-forminp">';
        echo '<div class="stackless-ce-consents" data-ce-consents data-name="' . esc_attr($name) . '">';
        foreach ($consents as $index => $consent) {
            $this->consentRow($name, (string) $index, $consent);
        }
        echo '</div>';
        echo '<p class="stackless-ce-empty" data-ce-empty' . ($consents ? ' hidden' : '') . '>' . esc_html__('No consent checkboxes yet. Add one with the buttons below.', 'stackless-lite-edd') . '</p>';
        echo '<div class="stackless-ce-add" role="group" aria-label="' . esc_attr__('Add a consent checkbox', 'stackless-lite-edd') . '">';
        foreach (Settings::consentTypes() as $type => $label) {
            echo '<button type="button" class="button" data-ce-add="' . esc_attr($type) . '">+ ' . esc_html($label) . '</button>';
        }
        echo '</div>';
        /* translators: %d: maximum number of consent checkboxes */
        echo '<p class="description">' . esc_html(sprintf(__('Up to %d checkboxes. The suggested wording is a starting point; check it against your own policies and local law, especially the EU digital content waiver.', 'stackless-lite-edd'), ConsentRules::MAX_CONSENTS)) . '</p>';
        echo '<template id="stackless-ce-consent-template">';
        $this->consentRow($name, '__index__', ['id' => '', 'type' => 'custom', 'label' => '', 'required' => false, 'scope' => 'all', 'product_id' => 0, 'version' => 1]);
        echo '</template></td></tr>';
    }

    /** Published downloads as <option>s (capped), with the selected one kept in the list. */
    private static function downloadOptions(int $selected): string
    {
        if (self::$downloadOptions === null) {
            $posts = get_posts(['post_type' => 'download', 'post_status' => ['publish', 'private'], 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false]);
            $html = '';
            foreach ($posts as $post) {
                $html .= '<option value="' . (int) $post->ID . '">' . esc_html(wp_strip_all_tags(get_the_title($post)) . ' · #' . $post->ID) . '</option>';
            }
            self::$downloadOptions = $html;
        }
        $html = '<option value="">' . esc_html__('Choose a download', 'stackless-lite-edd') . '</option>' . self::$downloadOptions;
        if ($selected > 0) {
            $needle = '<option value="' . $selected . '">';
            $html = strpos($html, $needle) !== false
                ? str_replace($needle, '<option value="' . $selected . '" selected>', $html)
                : $html . '<option value="' . $selected . '" selected>' . esc_html(wp_strip_all_tags(get_the_title($selected)) . ' · #' . $selected) . '</option>';
        }
        return $html;
    }

    private function consentRow(string $name, string $index, array $consent): void
    {
        $prefix = $name . '[' . $index . ']';
        $id = 'stackless-ce-consent-' . $index;
        $types = Settings::consentTypes();
        $scopes = Settings::consentScopes();
        $isNew = $index === '__index__';
        $scope = isset($scopes[$consent['scope']]) ? (string) $consent['scope'] : 'all';
        echo '<details class="stackless-ce-consent" data-ce-consent' . ($isNew ? ' open' : '') . '><summary><span data-ce-consent-title>' . esc_html($types[$consent['type']] ?? $types['custom']) . '</span>';
        echo '<span class="stackless-ce-badges"><span class="stackless-ce-badge" data-ce-required' . (empty($consent['required']) ? ' hidden' : '') . '>' . esc_html__('Required', 'stackless-lite-edd') . '</span>';
        if (!$isNew) {
            /* translators: %d: wording version number */
            echo '<span class="stackless-ce-badge">' . esc_html(sprintf(__('Wording v%d', 'stackless-lite-edd'), (int) $consent['version'])) . '</span>';
        }
        echo '</span></summary><div class="stackless-ce-consent-body">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[id]" value="' . esc_attr((string) $consent['id']) . '">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[version]" value="' . (int) $consent['version'] . '">';
        echo '<input type="hidden" name="' . esc_attr($prefix) . '[delete]" value="0" data-ce-delete>';
        echo '<div class="stackless-ce-grid"><p><label for="' . esc_attr($id) . '-type">' . esc_html__('Type', 'stackless-lite-edd') . '</label><select id="' . esc_attr($id) . '-type" name="' . esc_attr($prefix) . '[type]" data-ce-type>';
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($consent['type'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p><p><label for="' . esc_attr($id) . '-scope">' . esc_html__('Show for', 'stackless-lite-edd') . '</label><select id="' . esc_attr($id) . '-scope" name="' . esc_attr($prefix) . '[scope]" data-ce-scope>';
        foreach ($scopes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($scope, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p><p data-ce-product' . ($scope === 'product' ? '' : ' hidden') . '><label for="' . esc_attr($id) . '-product">' . esc_html__('Download', 'stackless-lite-edd') . '</label>';
        echo '<select id="' . esc_attr($id) . '-product" name="' . esc_attr($prefix) . '[product_id]">' . self::downloadOptions((int) $consent['product_id']) . '</select></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in downloadOptions().
        echo '<p class="stackless-ce-wording"><label for="' . esc_attr($id) . '-label">' . esc_html__('Checkbox text', 'stackless-lite-edd') . '</label><textarea id="' . esc_attr($id) . '-label" name="' . esc_attr($prefix) . '[label]" rows="2" maxlength="600" data-ce-label>' . esc_textarea((string) $consent['label']) . '</textarea>';
        echo '<small>' . esc_html__('Links, bold and italic are allowed, for example <a href="/privacy-policy/">privacy policy</a>. Changing the text starts a new wording version.', 'stackless-lite-edd') . '</small></p>';
        echo '<div class="stackless-ce-consent-footer"><label><input type="checkbox" name="' . esc_attr($prefix) . '[required]" value="1" data-ce-required-toggle' . checked(!empty($consent['required']), true, false) . '> ' . esc_html__('Customer must tick this to place the order', 'stackless-lite-edd') . '</label>';
        echo '<button type="button" class="button-link button-link-delete" data-ce-remove>' . esc_html__('Remove', 'stackless-lite-edd') . '</button></div></div></details>';
    }

    /** @param array<string, mixed> $field */
    public function renderMedia(array $field): void
    {
        $id = absint(get_option((string) $field['id'], 0));
        $url = $id ? (string) wp_get_attachment_image_url($id, 'medium') : '';
        echo '<tr><th scope="row" class="titledesc">' . esc_html((string) ($field['title'] ?? '')) . '</th><td class="forminp"><div class="stackless-ce-media" data-ce-media>';
        echo '<img data-ce-media-preview src="' . esc_url($url) . '" alt=""' . ($url ? '' : ' hidden') . '>';
        echo '<input type="hidden" name="' . esc_attr((string) $field['id']) . '" value="' . (int) $id . '" data-ce-media-id>';
        echo '<button type="button" class="button" data-ce-media-choose>' . esc_html__('Choose image', 'stackless-lite-edd') . '</button> ';
        echo '<button type="button" class="button-link" data-ce-media-clear' . ($url ? '' : ' hidden') . '>' . esc_html__('Remove image', 'stackless-lite-edd') . '</button></div>';
        if (!empty($field['desc'])) {
            echo '<p class="description">' . esc_html((string) $field['desc']) . '</p>';
        }
        echo '</td></tr>';
    }

    /** "Checkout consents" box in the order details sidebar. @param mixed $orderId */
    public function orderBox($orderId): void
    {
        if (!current_user_can('edit_shop_payments')) {
            return;
        }
        $records = edd_get_order_meta((int) $orderId, Module::META_CONSENTS, true);
        echo '<div id="stackless-edd-checkout-consents" class="postbox stackless-ce-order-box"><h2 class="hndle"><span>' . esc_html__('Checkout consents', 'stackless-lite-edd') . '</span></h2><div class="inside">';
        if (!is_array($records) || !$records) {
            echo '<p>' . esc_html__('No consent checkboxes were shown for this order.', 'stackless-lite-edd') . '</p></div></div>';
            return;
        }
        echo '<ul class="stackless-ce-records">';
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['label'])) {
                continue;
            }
            $time = strtotime((string) ($record['at'] ?? '') . ' UTC');
            echo '<li><strong>' . esc_html(!empty($record['accepted']) ? __('Accepted', 'stackless-lite-edd') : __('Not accepted', 'stackless-lite-edd')) . '</strong>';
            echo !empty($record['required']) ? ' <span class="description">(' . esc_html__('required', 'stackless-lite-edd') . ')</span>' : '';
            echo '<br>' . esc_html(wp_strip_all_tags((string) $record['label']));
            /* translators: 1: wording version, 2: date and time */
            echo '<br><small>' . esc_html(sprintf(__('Wording v%1$d · %2$s', 'stackless-lite-edd'), (int) ($record['version'] ?? 1), $time ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '')) . '</small></li>';
        }
        echo '</ul></div></div>';
    }
}
