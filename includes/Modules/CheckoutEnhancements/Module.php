<?php

namespace StacklessEDD\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Checkout Enhancements: consent checkboxes with stored proof, a shorter checkout for free
 * orders, a free-order button, renamed checkout fields and text, and a trust block under payment.
 *
 * EDD renders one checkout in PHP for both the [download_checkout] shortcode and the EDD Checkout
 * block, and re-renders the purchase form over AJAX when the gateway changes, so everything here
 * hooks the purchase form actions and the checkout script re-binds on EDD's jQuery events.
 * Consent answers are checked in edd_checkout_error_checks, kept in the session right before the
 * gateway runs (so on-site, hosted and AJAX gateways all record them) and stored on the order when
 * EDD builds it.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_CONSENTS = '_stackless_edd_checkout_enhancements_consents';
    public const META_NOTED = '_stackless_edd_checkout_enhancements_noted';
    public const SESSION_ANSWERS = 'stackless_edd_checkout_enhancements_answers';
    public const HANDLE = 'stackless-edd-checkout_enhancements';

    /** EDD AJAX actions that re-render parts of the checkout. */
    private const CHECKOUT_AJAX = ['edd_load_gateway', 'edd_recalculate_taxes', 'edd_get_shop_states', 'edd_apply_discount', 'edd_remove_discount', 'edd_update_quantity', 'edd_blocks_swap_personal_info', 'edd_process_checkout'];

    /** @var array<int, bool> orders whose consent table already printed on this request */
    private $printed = [];

    /** @var bool */
    private $translating = false;

    public function id(): string
    {
        return 'checkout_enhancements';
    }

    public function title(): string
    {
        return __('Checkout Enhancements', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Consent checkboxes with stored proof, a shorter checkout for free orders, a "Get it for free" button, your own checkout wording, and a trust block under the payment details.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'checkout';
    }

    public function settingsFields(): array
    {
        return (new Admin())->fields();
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }

        // Consents.
        add_action('edd_purchase_form_before_submit', [$this, 'renderConsents'], 20);
        add_action('edd_checkout_error_checks', [$this, 'validateConsents'], 20, 2);
        add_action('edd_checkout_before_gateway', [$this, 'keepAnswers'], 20, 3);
        add_action('edd_built_order', [$this, 'persistConsents'], 20, 2);

        // Where the stored proof is shown.
        add_action('edd_order_receipt_after_table', [$this, 'renderCustomerConsents'], 30, 2);
        add_filter('edd_order_receipt', [$this, 'emailBody'], 20, 2);
        add_filter('edd_admin_order_notification', [$this, 'emailBody'], 20, 2);

        // Shorter checkout for free orders and checkout wording.
        add_filter('edd_get_checkout_button_purchase_label', [$this, 'buttonText'], 20, 2);
        add_filter('body_class', [$this, 'bodyClass'], 20);
        add_action('wp', [$this, 'maybeTranslateCheckout'], 20);
        add_action('init', [$this, 'maybeTranslateAjax'], 20);

        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
        add_action('edd_purchase_form_after_cc_form', [$this, 'renderTrust'], 1000);
    }

    /* ---------------------------------------------------------------------
     * Consents.
     * ------------------------------------------------------------------ */

    public function renderConsents(): void
    {
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromCart());
        if (!$consents) {
            return;
        }
        stackless_edd_get_template('checkout_enhancements/consents.php', ['consents' => $consents, 'allowed' => Settings::allowedLabelHtml()]);
    }

    /**
     * @param mixed $post
     * @return array<string, bool>
     */
    private function answers($post): array
    {
        $posted = is_array($post) && isset($post['stackless_consent']) && is_array($post['stackless_consent']) ? wp_unslash($post['stackless_consent']) : [];
        $answers = [];
        foreach ((array) $posted as $id => $value) {
            $answers[sanitize_key((string) $id)] = ConsentRules::isTicked(is_scalar($value) ? (string) $value : '');
        }
        return $answers;
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validateConsents($validData, $post): void
    {
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromCart());
        $byId = [];
        foreach ($consents as $consent) {
            $byId[$consent['id']] = $consent;
        }
        foreach (ConsentRules::missing($consents, $this->answers($post)) as $id) {
            /* translators: %s: the consent wording the customer must accept */
            edd_set_error('stackless_consent_' . $id, sprintf(__('Please confirm: %s', 'stackless-lite-edd'), wp_strip_all_tags((string) $byId[$id]['label']))); // @phpstan-ignore argument.type
        }
    }

    /**
     * Right before EDD hands over to the gateway: keep the answers in the session, so the order the
     * gateway builds (now, or in a later AJAX call) gets them.
     *
     * @param mixed $post
     * @param mixed $userInfo
     * @param mixed $validData
     */
    public function keepAnswers($post, $userInfo, $validData): void
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return;
        }
        EDD()->session->set(self::SESSION_ANSWERS, ['at' => time(), 'answers' => $this->answers($post)]);
    }

    /**
     * @param mixed $orderId
     * @param mixed $orderData
     */
    public function persistConsents($orderId, $orderData = []): void
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD verified the checkout nonce; this only reads the answers.
        $post = $_POST;
        $kept = function_exists('EDD') && EDD()->session ? EDD()->session->get(self::SESSION_ANSWERS) : null;
        if (isset($post['stackless_consent']) || isset($post['edd-process-checkout-nonce'])) {
            $answers = $this->answers($post);
        } elseif (is_array($kept) && isset($kept['answers'])) {
            $answers = array_map('boolval', (array) $kept['answers']);
        } else {
            // Not a storefront checkout (a renewal, an invoice or an order built in the admin).
            return;
        }
        if (function_exists('EDD') && EDD()->session) {
            EDD()->session->set(self::SESSION_ANSWERS, null);
        }
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromOrder($orderId));
        if (!$consents) {
            edd_delete_order_meta($orderId, self::META_CONSENTS);
            return;
        }
        $records = ConsentRules::records($consents, $answers, gmdate('Y-m-d H:i:s'));
        edd_update_order_meta($orderId, self::META_CONSENTS, $records);
        /**
         * Fires when consent answers are recorded on an order.
         *
         * @param int     $order_id
         * @param array[] $records id, type, label, version, required, accepted, at (UTC)
         */
        do_action('stackless_edd_checkout_enhancements_consents_recorded', $orderId, $records);
        $this->noteConsents($orderId, $records);
    }

    /**
     * One order note per distinct answer set, so a retried payment does not repeat it.
     *
     * @param array[] $records
     */
    private function noteConsents(int $orderId, array $records): void
    {
        $hash = md5((string) wp_json_encode(array_map(static function ($record) {
            unset($record['at']);
            return $record;
        }, $records)));
        if (edd_get_order_meta($orderId, self::META_NOTED, true) === $hash) {
            return;
        }
        $lines = array_map(static function ($record) {
            /* translators: 1: Accepted or Not accepted, 2: consent wording, 3: wording version */
            return sprintf(__('%1$s: %2$s (wording v%3$d)', 'stackless-lite-edd'), $record['accepted'] ? __('Accepted', 'stackless-lite-edd') : __('Not accepted', 'stackless-lite-edd'), wp_strip_all_tags((string) $record['label']), (int) $record['version']);
        }, $records);
        stackless_edd_add_order_note($orderId, __('Checkout consents', 'stackless-lite-edd') . "\n" . implode("\n", $lines));
        edd_update_order_meta($orderId, self::META_NOTED, $hash);
    }

    /** @return array[] */
    public static function records(int $orderId): array
    {
        $records = edd_get_order_meta($orderId, self::META_CONSENTS, true);
        return is_array($records) ? array_values(array_filter($records, static function ($record) {
            return is_array($record) && !empty($record['label']);
        })) : [];
    }

    /**
     * Receipt (success page and the order in purchase history), shortcode and block receipts alike.
     *
     * @param mixed $order
     * @param mixed $args
     */
    public function renderCustomerConsents($order, $args = []): void
    {
        if (!$order instanceof \EDD\Orders\Order || !Settings::flag('show_account', true) || isset($this->printed[(int) $order->id])) {
            return;
        }
        $records = self::records((int) $order->id);
        if (!$records) {
            return;
        }
        $this->printed[(int) $order->id] = true;
        stackless_edd_get_template('checkout_enhancements/order-consents.php', ['order' => $order, 'records' => $records, 'heading' => __('Checkout consents', 'stackless-lite-edd')]);
    }

    /**
     * Purchase receipt and new sale notification: the answers below the message.
     *
     * @param mixed $body
     * @param mixed $order
     * @return mixed
     */
    public function emailBody($body, $order)
    {
        if (!is_string($body) || !$order instanceof \EDD\Orders\Order || !Settings::flag('show_emails', true)) {
            return $body;
        }
        $records = self::records((int) $order->id);
        if (!$records) {
            return $body;
        }
        $html = '<p><strong>' . esc_html__('Checkout consents', 'stackless-lite-edd') . '</strong></p><ul>';
        foreach ($records as $record) {
            $html .= '<li>' . esc_html(wp_strip_all_tags((string) $record['label'])) . ': ' . esc_html($this->answerText($record)) . '</li>';
        }
        return $body . $html . '</ul>';
    }

    public function answerText(array $record): string
    {
        $time = strtotime((string) $record['at'] . ' UTC');
        $when = $time ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '';
        $answer = !empty($record['accepted']) ? __('Accepted', 'stackless-lite-edd') : __('Not accepted', 'stackless-lite-edd');
        /* translators: 1: Accepted or Not accepted, 2: date and time, 3: wording version */
        return sprintf(__('%1$s on %2$s (wording v%3$d)', 'stackless-lite-edd'), $answer, (string) $when, (int) $record['version']);
    }

    /* ---------------------------------------------------------------------
     * Shorter checkout for free orders, and checkout wording.
     * ------------------------------------------------------------------ */

    /** Cart facts plus what the cart is worth. Read fresh: AJAX requests change the cart mid-request. */
    private function valueFacts(): array
    {
        return array_merge(CartFacts::fromCart(), CartFacts::valueFromCart());
    }

    private function cartReady(): bool
    {
        return function_exists('EDD') && EDD()->cart && did_action('init');
    }

    private function onCheckoutPage(): bool
    {
        return function_exists('edd_is_checkout') && did_action('wp') && edd_is_checkout() && !(function_exists('edd_is_success_page') && edd_is_success_page());
    }

    /**
     * The free-order wording when there is nothing to pay, otherwise the merchant's own label, if any.
     *
     * @param mixed $label
     * @param mixed $option
     * @return mixed
     */
    public function buttonText($label, $option = '')
    {
        if (!is_string($label)) {
            return $label;
        }
        $free = Settings::freeButtonText();
        $zero = $free !== '' && $this->cartReady() && LeanRules::zeroTotal($this->valueFacts());
        return LeanRules::buttonText($label, Settings::buttonText(), $free, $zero);
    }

    /**
     * Body class the checkout stylesheet uses: the currency selector hides on free carts.
     *
     * @param mixed $classes
     * @return mixed
     */
    public function bodyClass($classes)
    {
        if (!is_array($classes) || !$this->onCheckoutPage() || !$this->cartReady()) {
            return $classes;
        }
        if (LeanRules::freeDigital($this->valueFacts(), Settings::lean()['free'])) {
            $classes[] = 'stackless-ce-free-digital';
        }
        return $classes;
    }

    /** Checkout field labels and headings are EDD strings, renamed on the checkout page only. */
    public function maybeTranslateCheckout(): void
    {
        if ($this->onCheckoutPage()) {
            $this->translate();
        }
    }

    /** The same wording when EDD re-renders parts of the checkout over AJAX. */
    public function maybeTranslateAjax(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing check.
        $action = wp_doing_ajax() && isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== '' && in_array($action, self::CHECKOUT_AJAX, true)) {
            $this->translate();
        }
    }

    private function translate(): void
    {
        if ($this->translating) {
            return;
        }
        $map = Settings::textMap();
        if (!$map) {
            return;
        }
        $this->translating = true;
        add_filter('gettext_easy-digital-downloads', static function ($translation, $text) use ($map) {
            return $map[$text] ?? $translation;
        }, 20, 2);
    }

    /* ---------------------------------------------------------------------
     * Assets and the trust block.
     * ------------------------------------------------------------------ */

    public function assets(): void
    {
        if (!$this->onCheckoutPage()) {
            return;
        }
        $url = STACKLESS_EDD_PLUGIN_URL . 'assets/checkout_enhancements/';
        wp_enqueue_style(self::HANDLE, $url . 'checkout.css', [], STACKLESS_EDD_VERSION);
        wp_enqueue_script(self::HANDLE, $url . 'checkout.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        wp_localize_script(self::HANDLE, 'stacklessEddCheckoutEnhancements', [
            'required' => __('Please tick this box to continue.', 'stackless-lite-edd'),
        ]);
    }

    private function trustHtml(): string
    {
        $trust = Settings::trust();
        if (!$trust['enabled'] || ($trust['heading'] === '' && !$trust['lines'] && !$trust['image_id'] && !$trust['policy_page'])) {
            return '';
        }
        return stackless_edd_get_template_html('checkout_enhancements/trust.php', ['trust' => $trust]);
    }

    public function renderTrust(): void
    {
        echo $this->trustHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
    }
}
