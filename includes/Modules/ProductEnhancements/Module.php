<?php

namespace StacklessEDD\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Product Details: a details table (specifications) for downloads with presets for physical
 * products, downloads, books (ISBN-10/13), software and services. Values auto-fill from
 * EDD data (SKU barcodes, files, last update), can differ per variable price, show above or
 * below the description or below the purchase button, and feed EDD's Product structured data.
 */
final class Module extends \StacklessEDD\Core\Module
{
    private const HANDLE = 'stackless-edd-product_enhancements';
    public const SHORTCODE = 'stackless_edd_product_details';

    /** @var array<int, bool> */
    private $rendered = [];

    /** @var array<int, array> */
    private $rowsCache = [];

    public function id(): string
    {
        return 'product_enhancements';
    }

    public function title(): string
    {
        return __('Product Details Table', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Add a specifications table to downloads with presets for physical goods, downloads, books, software and services. Values fill in from EDD data and feed product schema.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_edd_product_enhancements_';
        return [
            /* translators: %s: shortcode */
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Product details table', 'stackless-lite-edd'), 'desc' => sprintf(__('Fill in details per download in the Details table box on the download editor. The shortcode %s places the table anywhere; add id="123" outside download pages.', 'stackless-lite-edd'), '[' . self::SHORTCODE . ']')],
            ['type' => 'select', 'id' => $p . 'placement', 'title' => __('Placement', 'stackless-lite-edd'), 'default' => 'below_button', 'options' => [
                'below_button' => __('Below the purchase button', 'stackless-lite-edd'),
                'below_description' => __('Below the description, above the purchase button', 'stackless-lite-edd'),
                'above_description' => __('Above the description', 'stackless-lite-edd'),
                'none' => __('Only where I place the shortcode', 'stackless-lite-edd'),
            ]],
            ['type' => 'text', 'id' => $p . 'heading', 'title' => __('Table heading', 'stackless-lite-edd'), 'default' => __('Specifications', 'stackless-lite-edd'), 'desc' => __('Leave blank for no heading.', 'stackless-lite-edd'), 'css' => 'min-width:260px'],
            ['type' => 'checkbox', 'id' => $p . 'schema', 'title' => __('Structured data', 'stackless-lite-edd'), 'desc' => __('Add details to the product schema (brand, GTIN, MPN, condition, weight, dimensions, other properties)', 'stackless-lite-edd'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }

        $placement = (string) $this->option('placement', 'below_button');
        if ($placement === 'below_button') {
            // EDD appends the purchase button at priority 10.
            add_action('edd_after_download_content', [$this, 'renderInContent'], 20);
        } elseif ($placement === 'below_description') {
            add_action('edd_after_download_content', [$this, 'renderInContent'], 5);
        } elseif ($placement === 'above_description') {
            add_action('edd_before_download_content', [$this, 'renderInContent'], 20);
        }

        (new Agent())->register();
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        if ($this->option('schema', 'yes') === 'yes') {
            add_filter('edd_generate_download_structured_data', [$this, 'filterStructuredData'], 30, 2);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(int $downloadId): array
    {
        if (!isset($this->rowsCache[$downloadId])) {
            $this->rowsCache[$downloadId] = Repository::rows($downloadId);
        }
        return $this->rowsCache[$downloadId];
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    public function render(int $downloadId, ?string $heading = null): string
    {
        if ($downloadId < 1 || get_post_type($downloadId) !== 'download') {
            return '';
        }
        if (get_post_status($downloadId) !== 'publish' && !current_user_can('read_product', $downloadId)) {
            return '';
        }
        $rows = $this->rows($downloadId);
        if (!$rows) {
            return '';
        }
        $this->rendered[$downloadId] = true;
        wp_enqueue_style(self::HANDLE, STACKLESS_EDD_PLUGIN_URL . 'assets/product_enhancements/details.css', [], STACKLESS_EDD_VERSION);

        $heading = $heading ?? (string) $this->option('heading', __('Specifications', 'stackless-lite-edd'));
        $prices = $this->priceData($downloadId);
        $html = '<section class="stackless-product-details" data-stackless-product-details="' . esc_attr((string) $downloadId) . '"'
            . ($prices ? ' data-stackless-prices="' . esc_attr((string) wp_json_encode($prices)) . '"' : '') . '>';
        if (trim($heading) !== '') {
            $html .= '<h2 class="stackless-product-details__title">' . esc_html($heading) . '</h2>';
        }
        $html .= '<table class="stackless-product-details__table"><tbody>';
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            $varies = !empty($row['variations']);
            $html .= sprintf(
                '<tr class="stackless-product-details__row"%s%s><th scope="row">%s</th><td%s>%s</td></tr>',
                $varies ? ' data-stackless-detail-row="' . esc_attr((string) $row['key']) . '"' : '',
                $value === '' ? ' hidden' : '',
                esc_html((string) $row['label']),
                $varies ? ' data-stackless-detail="' . esc_attr((string) $row['key']) . '" data-stackless-default="' . esc_attr($this->plain($row, $value)) . '"' : '',
                $this->formatValue((string) $row['type'], $value)
            );
        }
        $html .= '</tbody></table></section>';
        if ($prices) {
            wp_enqueue_script(self::HANDLE . '-variations', STACKLESS_EDD_PLUGIN_URL . 'assets/product_enhancements/variations.js', ['jquery'], STACKLESS_EDD_VERSION, true);
        }
        return $html;
    }

    /**
     * Per-price values for the switcher script: price id => [row key => plain text]. Only rows
     * whose value differs for that price are listed.
     *
     * @return array<int, array<string, string>>
     */
    public function priceData(int $downloadId): array
    {
        $data = [];
        foreach ($this->rows($downloadId) as $row) {
            foreach ((array) ($row['variations'] ?? []) as $priceId => $value) {
                $data[(int) $priceId][(string) $row['key']] = $this->plain($row, (string) $value);
            }
        }
        return $data;
    }

    /** Escaped HTML for one value. */
    private function formatValue(string $type, string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($type === 'url') {
            $label = (string) preg_replace('#^https?://(www\.)?#', '', untrailingslashit($value));
            return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
        }
        if ($type === 'date') {
            $time = strtotime($value);
            return esc_html($time ? (string) wp_date((string) get_option('date_format'), $time) : $value);
        }
        return esc_html($value);
    }

    /**
     * Plain text for the per-price switcher (it sets textContent, never HTML).
     *
     * @param array<string, mixed> $row
     */
    private function plain(array $row, string $value): string
    {
        if ($value !== '' && ($row['type'] ?? '') === 'date') {
            $time = strtotime($value);
            return $time ? (string) wp_date((string) get_option('date_format'), $time) : $value;
        }
        return $value;
    }

    /**
     * Prints the table on single download pages (edd_before/after_download_content run inside the_content).
     *
     * @param int|string $downloadId
     */
    public function renderInContent($downloadId): void
    {
        echo $this->render((int) $downloadId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * [stackless_edd_product_details id="123" title="Specs"]
     *
     * @param array|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['id' => 0, 'title' => null], is_array($atts) ? $atts : [], self::SHORTCODE);
        $id = absint($atts['id']) ?: (int) get_the_ID();
        if (!$id || !empty($this->rendered[$id])) {
            return '';
        }
        return $this->render($id, $atts['title'] === null ? null : sanitize_text_field((string) $atts['title']));
    }

    /**
     * Adds the details to the Product JSON-LD EDD prints on download pages.
     *
     * @param mixed                     $data
     * @param \EDD_Download|mixed       $download
     * @return mixed
     */
    public function filterStructuredData($data, $download)
    {
        if (!is_array($data) || !$download instanceof \EDD_Download) {
            return $data;
        }
        $rows = $this->rows((int) $download->ID);
        if (!$rows) {
            return $data;
        }
        $data = Schema::dropDefaultBrand($data, $rows, (string) get_bloginfo('name'));
        return Schema::apply($data, $rows, Fields::all());
    }
}
