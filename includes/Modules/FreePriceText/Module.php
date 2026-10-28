<?php

namespace StacklessEDD\Modules\FreePriceText;

defined('ABSPATH') || exit;

/**
 * Free Price Text: shows custom text ("Free") wherever a zero price would print.
 *
 * Covers edd_price() (download pages, the [downloads] grid, [edd_price], themes), variable price
 * ranges and the price option list, the purchase button ("Free – Purchase"), the EDD downloads
 * block (which prints "Free" itself), the checkout cart and the cart widget.
 */
final class Module extends \StacklessEDD\Core\Module
{
    public const META_LABEL = '_stackless_edd_free_price_text_label';
    private const FIELD = 'stackless_edd_free_price_text_label';

    /** @var int Nesting depth of edd/downloads blocks being rendered. */
    private $blockDepth = 0;

    public function id(): string
    {
        return 'free_price_text';
    }

    public function title(): string
    {
        return __('Free Price Text', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Show custom text such as "Free" instead of a zero price on download pages, download grids and the checkout.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_free_price_text_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Free price text', 'stackless-lite-edd'), 'desc' => __('Downloads that cost nothing show this text instead of a zero price. EDD sells a download with no price entered as free, so it gets the text too.', 'stackless-lite-edd')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Text', 'stackless-lite-edd'), 'default' => __('Free', 'stackless-lite-edd'), 'desc_tip' => __('Shown in place of the zero price. Each download can override it under Download Details, below the price.', 'stackless-lite-edd'), 'css' => 'min-width:260px'],
            ['type' => 'checkbox', 'id' => $p . 'ranges', 'title' => __('Price ranges', 'stackless-lite-edd'), 'desc' => __('Use the text at the low end of a range, for example "Free – $19"', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'cart', 'title' => __('Checkout', 'stackless-lite-edd'), 'desc' => __('Also show the text for free items in the checkout cart and the cart widget', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        add_filter('edd_download_price_after_html', [$this, 'filterPriceHtml'], 50, 4);
        add_filter('edd_price_range', [$this, 'filterPriceRange'], 50, 4);
        add_filter('edd_price_option_output', [$this, 'filterPriceOption'], 50, 4);
        add_filter('edd_purchase_link_args', [$this, 'filterPurchaseLinkArgs'], 50, 2);

        // The downloads block prints EDD's own "Free" for free downloads.
        add_filter('render_block_data', [$this, 'enterDownloadsBlock'], 10, 1);
        add_filter('render_block_edd/downloads', [$this, 'leaveDownloadsBlock'], 10, 1);

        if ($this->option('cart', 'yes') === 'yes') {
            add_filter('edd_cart_item_price_label', [$this, 'filterCartItemPriceLabel'], 50, 3);
            add_filter('edd_cart_item', [$this, 'filterCartWidgetItem'], 50, 2);
        }

        add_action('edd_meta_box_price_fields', [$this, 'renderDownloadField'], 30, 2);
        add_action('edd_save_download', [$this, 'saveDownloadField'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Text
     * ------------------------------------------------------------------- */

    public function text(int $downloadId = 0): string
    {
        $text = $downloadId > 0 ? trim((string) get_post_meta($downloadId, self::META_LABEL, true)) : '';
        if ($text === '') {
            $text = trim((string) $this->option('text', __('Free', 'stackless-lite-edd')));
        }
        if ($text === '') {
            $text = __('Free', 'stackless-lite-edd');
        }
        /**
         * Filters the text shown for a zero price.
         *
         * @param string $text
         * @param int    $download_id 0 outside a download context.
         */
        return (string) apply_filters('stackless_edd_free_price_text_text', $text, $downloadId);
    }

    public function html(int $downloadId = 0): string
    {
        return '<span class="stackless-free-price">' . esc_html($this->text($downloadId)) . '</span>';
    }

    private function isSubscription(int $downloadId, ?int $priceId = null): bool
    {
        return stackless_edd_is_subscription_product($downloadId, $priceId);
    }

    /**
     * Whether the free text may replace a zero price of this download. Modules that show their own
     * label instead (Request a Quote's "Price on request" for quote-only downloads) answer false.
     */
    private function applies(int $downloadId): bool
    {
        /**
         * Filters whether the free price text applies to a download.
         *
         * @param bool $applies
         * @param int  $download_id
         */
        return $downloadId < 1 || (bool) apply_filters('stackless_edd_free_price_text_applies', true, $downloadId);
    }

    /* ---------------------------------------------------------------------
     * Download prices
     * ------------------------------------------------------------------- */

    /**
     * edd_price(): the amount it shows, mirrored so the raw value can be tested for zero.
     *
     * @param mixed $priceId
     * @return array{0:string,1:?int} amount, price id shown (null for single-price downloads)
     */
    private function displayedAmount(int $downloadId, $priceId): array
    {
        if (!edd_has_variable_prices($downloadId)) {
            return [(string) edd_get_download_price($downloadId), null];
        }
        $prices = edd_get_variable_prices($downloadId);
        if (is_numeric($priceId) && is_array($prices) && isset($prices[$priceId])) {
            return [(string) edd_get_price_option_amount($downloadId, (int) $priceId), (int) $priceId];
        }
        $default = edd_get_default_variable_price($downloadId);
        if ($default) {
            return [(string) edd_get_price_option_amount($downloadId, (int) $default), (int) $default];
        }
        $lowest = edd_get_lowest_price_id($downloadId);
        return [(string) edd_get_lowest_price_option($downloadId), is_numeric($lowest) ? (int) $lowest : null];
    }

    /**
     * @param mixed $html
     * @param mixed $downloadId
     * @param mixed $price
     * @param mixed $priceId
     * @return mixed
     */
    public function filterPriceHtml($html, $downloadId = 0, $price = '', $priceId = false)
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        if ($downloadId < 1 || get_post_type($downloadId) !== 'download') {
            return $html;
        }
        [$amount, $shownPriceId] = $this->displayedAmount($downloadId, $priceId);
        if (!Price::isZero($amount) || $this->isSubscription($downloadId, $shownPriceId) || !$this->applies($downloadId)) {
            return $html;
        }
        return '<span class="edd_price" id="edd_price_' . esc_attr((string) $downloadId) . '">' . $this->html($downloadId) . '</span>';
    }

    /**
     * @param mixed $range
     * @param mixed $downloadId
     * @param mixed $low
     * @param mixed $high
     * @return mixed
     */
    public function filterPriceRange($range, $downloadId = 0, $low = '', $high = '')
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        if ($downloadId < 1 || !is_string($range)) {
            return $range;
        }
        $state = Price::rangeState([$low, $high]);
        if ($state === 'none' || $this->isSubscription($downloadId) || !$this->applies($downloadId)) {
            return $range;
        }
        $free = $this->html($downloadId);
        if ($state === 'all') {
            return '<span class="edd_price" id="edd_price_' . esc_attr((string) $downloadId) . '">' . $free . '</span>';
        }
        if ($this->option('ranges', 'yes') !== 'yes') {
            return $range;
        }
        $count = 0;
        $replaced = preg_replace_callback(
            '/(<span[^>]*\bedd_price_range_low\b[^>]*>).*?(<\/span>)/s',
            static function (array $m) use ($free): string {
                return $m[1] . $free . $m[2];
            },
            $range,
            1,
            $count
        );
        if (is_string($replaced) && $count > 0) {
            return $replaced;
        }
        /* translators: 1: free text (e.g. "Free"), 2: highest price */
        return sprintf(_x('%1$s &ndash; %2$s', 'Free price range', 'stackless-lite-edd'), $free, edd_currency_filter(edd_format_amount(Price::max([$low, $high]))));
    }

    /**
     * The price option list on a variable download ("Small – $0.00").
     *
     * @param mixed $output
     * @param mixed $downloadId
     * @param mixed $key
     * @param mixed $price
     * @return mixed
     */
    public function filterPriceOption($output, $downloadId = 0, $key = 0, $price = [])
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        if ($downloadId < 1 || !is_string($output) || !is_array($price)) {
            return $output;
        }
        $amount = isset($price['amount']) && $price['amount'] !== '' ? $price['amount'] : '0';
        if (!Price::isZero($amount) || $this->isSubscription($downloadId, is_numeric($key) ? (int) $key : null) || !$this->applies($downloadId)) {
            return $output;
        }
        $free = $this->html($downloadId);
        $replaced = preg_replace_callback(
            '/(<span class="edd_price_option_price">).*?(<\/span>)/s',
            static function (array $m) use ($free): string {
                return $m[1] . $free . $m[2];
            },
            $output,
            1
        );
        return is_string($replaced) ? $replaced : $output;
    }

    /**
     * EDD builds the button text as "{price}&nbsp;&ndash;&nbsp;{label}" before this filter, with its own
     * "Free" for zero prices. Swap that price part for the free text and keep the label.
     *
     * @param mixed $args
     * @param mixed $download
     * @return mixed
     */
    public function filterPurchaseLinkArgs($args, $download = null)
    {
        if (!is_array($args) || !isset($args['text']) || !is_string($args['text'])) {
            return $args;
        }
        $downloadId = isset($args['download_id']) && is_numeric($args['download_id']) ? (int) $args['download_id'] : 0;
        if ($downloadId < 1) {
            return $args;
        }
        $priceId = $args['price_id'] ?? false;
        $amount = $this->buttonAmount($downloadId, $args);
        if ($amount === null || !Price::isZero($amount) || $this->isSubscription($downloadId, is_numeric($priceId) ? (int) $priceId : null) || !$this->applies($downloadId)) {
            return $args;
        }
        $args['text'] = Price::replaceButtonPrice($args['text'], esc_html($this->text($downloadId)));
        return $args;
    }

    /**
     * The amount EDD put on the purchase button, or null when the button carries no price
     * (mirrors edd_get_purchase_link()).
     *
     * @param array<string,mixed> $args
     */
    private function buttonAmount(int $downloadId, array $args): ?string
    {
        if (empty($args['price']) || $args['price'] === 'no') {
            return null;
        }
        if (!edd_has_variable_prices($downloadId)) {
            return (string) edd_get_download_price($downloadId);
        }
        $priceId = $args['price_id'] ?? false;
        if ($priceId === false || $priceId === '') {
            return null;
        }
        $prices = edd_get_variable_prices($downloadId);
        if (!is_array($prices) || !isset($prices[$priceId]) || !is_array($prices[$priceId])) {
            return null;
        }
        $amount = $prices[$priceId]['amount'] ?? '';
        return $amount === '' ? '0' : (string) $amount;
    }

    /* ---------------------------------------------------------------------
     * EDD downloads block
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $parsedBlock
     * @return mixed
     */
    public function enterDownloadsBlock($parsedBlock)
    {
        if (is_array($parsedBlock) && ($parsedBlock['blockName'] ?? '') === 'edd/downloads' && $this->blockDepth++ === 0) {
            add_filter('gettext_easy-digital-downloads', [$this, 'filterFreeText'], 5, 2);
        }
        return $parsedBlock;
    }

    /**
     * @param mixed $content
     * @return mixed
     */
    public function leaveDownloadsBlock($content)
    {
        if ($this->blockDepth > 0 && --$this->blockDepth === 0) {
            remove_filter('gettext_easy-digital-downloads', [$this, 'filterFreeText'], 5);
        }
        return $content;
    }

    /**
     * @param mixed $translation
     * @param mixed $text
     * @return mixed
     */
    public function filterFreeText($translation, $text = '')
    {
        if ($text !== 'Free' || get_post_type() !== 'download') {
            return $translation;
        }
        $downloadId = (int) get_the_ID();
        return $this->isSubscription($downloadId) || !$this->applies($downloadId) ? $translation : $this->text($downloadId);
    }

    /* ---------------------------------------------------------------------
     * Checkout cart and cart widget
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $label
     * @param mixed $downloadId
     * @param mixed $options
     * @return mixed
     */
    public function filterCartItemPriceLabel($label, $downloadId = 0, $options = [])
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $options = is_array($options) ? $options : [];
        if ($downloadId < 1) {
            return $label;
        }
        $priceId = isset($options['price_id']) && is_numeric($options['price_id']) ? (int) $options['price_id'] : null;
        if ($this->isSubscription($downloadId, $priceId)) {
            return $label;
        }
        return Price::isZero(edd_get_cart_item_price($downloadId, $options)) ? $this->html($downloadId) : $label;
    }

    /**
     * The cart widget only hands over the item markup, so the formatted amount is checked.
     *
     * @param mixed $html
     * @param mixed $downloadId
     * @return mixed
     */
    public function filterCartWidgetItem($html, $downloadId = 0)
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        if (!is_string($html) || $downloadId < 1 || strpos($html, 'edd-cart-item-price') === false || $this->isSubscription($downloadId)) {
            return $html;
        }
        $free = $this->html($downloadId);
        $replaced = preg_replace_callback(
            '/(<span class="edd-cart-item-price">)(.*?)(<\/span>)/s',
            static function (array $m) use ($free): string {
                return Price::isZeroFormatted($m[2]) ? $m[1] . $free . $m[3] : $m[0];
            },
            $html,
            1
        );
        return is_string($replaced) ? $replaced : $html;
    }

    /* ---------------------------------------------------------------------
     * Download editor
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $downloadId
     * @param mixed $download
     */
    public function renderDownloadField($downloadId = 0, $download = null): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        $value = $downloadId > 0 ? (string) get_post_meta($downloadId, self::META_LABEL, true) : '';
        ?>
        <div class="edd-form-group stackless-free-price-text-field">
            <label for="<?php echo esc_attr(self::FIELD); ?>" class="edd-form-group__label"><?php esc_html_e('Free price text', 'stackless-lite-edd'); ?></label>
            <div class="edd-form-group__control">
                <input type="text" class="edd-form-group__input regular-text" name="<?php echo esc_attr(self::FIELD); ?>" id="<?php echo esc_attr(self::FIELD); ?>" value="<?php echo esc_attr($value); ?>" maxlength="60" placeholder="<?php echo esc_attr((string) $this->option('text', __('Free', 'stackless-lite-edd'))); ?>">
            </div>
            <p class="description edd-form-group__help"><?php esc_html_e('Shown instead of the price when this download costs nothing. Leave blank to use the store setting.', 'stackless-lite-edd'); ?></p>
        </div>
        <?php
    }

    /**
     * Runs after EDD verified the download editor nonce.
     *
     * @param mixed $postId
     * @param mixed $post
     */
    public function saveDownloadField($postId = 0, $post = null): void
    {
        $postId = is_numeric($postId) ? (int) $postId : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- EDD checked the download editor nonce before edd_save_download.
        if ($postId < 1 || !current_user_can('edit_product', $postId) || !isset($_POST[self::FIELD]) || !is_string($_POST[self::FIELD])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = sanitize_text_field(wp_unslash($_POST[self::FIELD]));
        if ($value === '') {
            delete_post_meta($postId, self::META_LABEL);
        } else {
            update_post_meta($postId, self::META_LABEL, mb_substr($value, 0, 60));
        }
    }
}
