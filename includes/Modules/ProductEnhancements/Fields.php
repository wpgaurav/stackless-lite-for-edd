<?php

namespace StacklessEDD\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Detail fields and the presets that group them by download type.
 *
 * Field types: text, number, date, url, list (comma separated), select, gtin, isbn13, isbn10.
 * "auto" names a value EDD already stores (see AutoFill). "default" is a suggested
 * value shown until the merchant types their own. "schema" maps the field to schema.org.
 */
final class Fields
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $fields = [
            'brand' => ['label' => __('Brand', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'brand', 'schema' => 'brand'],
            'mpn' => ['label' => __('Model number', 'stackless-lite-edd'), 'type' => 'text', 'schema' => 'mpn'],
            'gtin' => ['label' => __('GTIN / UPC / EAN', 'stackless-lite-edd'), 'type' => 'gtin', 'auto' => 'gtin', 'schema' => 'gtin', 'help' => __('8, 12, 13 or 14 digits from the barcode.', 'stackless-lite-edd')],
            'condition' => ['label' => __('Condition', 'stackless-lite-edd'), 'type' => 'select', 'options' => [__('New', 'stackless-lite-edd'), __('Used', 'stackless-lite-edd'), __('Refurbished', 'stackless-lite-edd')], 'schema' => 'itemCondition'],
            'color' => ['label' => __('Colors', 'stackless-lite-edd'), 'type' => 'list', 'schema' => 'color'],
            'size' => ['label' => __('Size', 'stackless-lite-edd'), 'type' => 'list', 'schema' => 'size'],
            'material' => ['label' => __('Material', 'stackless-lite-edd'), 'type' => 'list', 'schema' => 'material'],
            'dimensions' => ['label' => __('Dimensions', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'dimensions', 'schema' => 'dimensions', 'help' => __('Length × width × height, for example 20 × 15 × 3 cm.', 'stackless-lite-edd')],
            'weight' => ['label' => __('Weight', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'weight', 'schema' => 'weight'],
            'in_box' => ['label' => __('In the box', 'stackless-lite-edd'), 'type' => 'list'],
            'origin' => ['label' => __('Country of origin', 'stackless-lite-edd'), 'type' => 'text', 'schema' => 'countryOfOrigin'],
            'warranty' => ['label' => __('Warranty', 'stackless-lite-edd'), 'type' => 'text'],
            'file_format' => ['label' => __('File type', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'file_format'],
            'file_size' => ['label' => __('File size', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'file_size'],
            'file_count' => ['label' => __('Files included', 'stackless-lite-edd'), 'type' => 'number', 'auto' => 'file_count'],
            'pages' => ['label' => __('Pages', 'stackless-lite-edd'), 'type' => 'number', 'schema' => 'numberOfPages'],
            'version' => ['label' => __('Version', 'stackless-lite-edd'), 'type' => 'text'],
            'updated' => ['label' => __('Last updated', 'stackless-lite-edd'), 'type' => 'date', 'auto' => 'updated'],
            'requirements' => ['label' => __('Requirements', 'stackless-lite-edd'), 'type' => 'list'],
            'license' => ['label' => __('License', 'stackless-lite-edd'), 'type' => 'text'],
            'delivery' => ['label' => __('Delivery', 'stackless-lite-edd'), 'type' => 'text', 'default' => __('Instant download after payment', 'stackless-lite-edd')],
            'author' => ['label' => __('Author', 'stackless-lite-edd'), 'type' => 'list', 'schema' => 'author'],
            'publisher' => ['label' => __('Publisher', 'stackless-lite-edd'), 'type' => 'text', 'schema' => 'publisher'],
            'published' => ['label' => __('Publication date', 'stackless-lite-edd'), 'type' => 'date', 'schema' => 'datePublished'],
            'edition' => ['label' => __('Edition', 'stackless-lite-edd'), 'type' => 'text'],
            'language' => ['label' => __('Language', 'stackless-lite-edd'), 'type' => 'text', 'auto' => 'language', 'schema' => 'inLanguage'],
            'book_format' => ['label' => __('Format', 'stackless-lite-edd'), 'type' => 'select', 'options' => [__('Ebook', 'stackless-lite-edd'), __('Paperback', 'stackless-lite-edd'), __('Hardcover', 'stackless-lite-edd'), __('Audiobook', 'stackless-lite-edd')], 'auto' => 'book_format'],
            'isbn13' => ['label' => __('ISBN-13', 'stackless-lite-edd'), 'type' => 'isbn13', 'auto' => 'isbn13', 'schema' => 'isbn', 'help' => __('Enter either ISBN. The other is worked out for you when it exists.', 'stackless-lite-edd')],
            'isbn10' => ['label' => __('ISBN-10', 'stackless-lite-edd'), 'type' => 'isbn10', 'auto' => 'isbn10'],
            'platform' => ['label' => __('Works with', 'stackless-lite-edd'), 'type' => 'list'],
            'activations' => ['label' => __('Sites or devices', 'stackless-lite-edd'), 'type' => 'text'],
            'support' => ['label' => __('Support', 'stackless-lite-edd'), 'type' => 'text'],
            'updates' => ['label' => __('Updates', 'stackless-lite-edd'), 'type' => 'text'],
            'docs_url' => ['label' => __('Documentation', 'stackless-lite-edd'), 'type' => 'url'],
            'demo_url' => ['label' => __('Live demo', 'stackless-lite-edd'), 'type' => 'url'],
            'duration' => ['label' => __('Duration', 'stackless-lite-edd'), 'type' => 'text'],
            'format' => ['label' => __('Format', 'stackless-lite-edd'), 'type' => 'select', 'options' => [__('Self-paced', 'stackless-lite-edd'), __('Live online', 'stackless-lite-edd'), __('In person', 'stackless-lite-edd'), __('Hybrid', 'stackless-lite-edd')]],
            'level' => ['label' => __('Level', 'stackless-lite-edd'), 'type' => 'select', 'options' => [__('Beginner', 'stackless-lite-edd'), __('Intermediate', 'stackless-lite-edd'), __('Advanced', 'stackless-lite-edd'), __('All levels', 'stackless-lite-edd')]],
            'includes' => ['label' => __('Includes', 'stackless-lite-edd'), 'type' => 'list'],
            'access' => ['label' => __('Access', 'stackless-lite-edd'), 'type' => 'text'],
            'turnaround' => ['label' => __('Delivery time', 'stackless-lite-edd'), 'type' => 'text'],
            'location' => ['label' => __('Location', 'stackless-lite-edd'), 'type' => 'text'],
        ];
        /**
         * Filters the available detail fields.
         *
         * @param array<string, array<string, mixed>> $fields
         */
        return (array) apply_filters('stackless_edd_product_enhancements_fields', $fields);
    }

    /** @return array<string, array{label: string, fields: string[]}> */
    public static function presets(): array
    {
        $presets = [
            'physical' => ['label' => __('Physical product', 'stackless-lite-edd'), 'fields' => ['brand', 'mpn', 'gtin', 'condition', 'color', 'size', 'material', 'dimensions', 'weight', 'in_box', 'origin', 'warranty']],
            'digital' => ['label' => __('Digital download', 'stackless-lite-edd'), 'fields' => ['file_format', 'file_size', 'file_count', 'pages', 'version', 'updated', 'requirements', 'license', 'delivery']],
            'book' => ['label' => __('Book or ebook', 'stackless-lite-edd'), 'fields' => ['author', 'publisher', 'published', 'edition', 'language', 'pages', 'book_format', 'isbn13', 'isbn10', 'file_format', 'file_size', 'dimensions', 'weight']],
            'software' => ['label' => __('Software or plugin', 'stackless-lite-edd'), 'fields' => ['version', 'updated', 'requirements', 'platform', 'file_format', 'file_size', 'license', 'activations', 'support', 'updates', 'docs_url', 'demo_url']],
            'service' => ['label' => __('Course or service', 'stackless-lite-edd'), 'fields' => ['duration', 'format', 'level', 'language', 'includes', 'access', 'turnaround', 'location']],
            'custom' => ['label' => __('Custom details only', 'stackless-lite-edd'), 'fields' => []],
        ];
        /**
         * Filters the presets. Unknown field keys are dropped.
         *
         * @param array<string, array{label: string, fields: string[]}> $presets
         */
        $presets = (array) apply_filters('stackless_edd_product_enhancements_presets', $presets);
        $known = self::all();
        foreach ($presets as $key => $preset) {
            $presets[$key]['fields'] = array_values(array_filter((array) ($preset['fields'] ?? []), static function ($field) use ($known) {
                return isset($known[$field]);
            }));
        }
        return $presets;
    }

    /**
     * Validate and normalize one entered value.
     *
     * @param mixed $value
     * @throws \InvalidArgumentException With a reader-facing message.
     */
    public static function clean(string $key, $value): string
    {
        $field = self::all()[$key] ?? ['type' => 'text', 'label' => $key];
        $label = (string) $field['label'];
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '') {
            return '';
        }
        $value = mb_substr($value, 0, 300);
        switch ($field['type']) {
            case 'number':
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(esc_html(sprintf(__('%s must be a whole number.', 'stackless-lite-edd'), $label)));
                }
                return (string) (int) $value;
            case 'date':
                $time = strtotime($value);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $time === false || gmdate('Y-m-d', $time) !== $value) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(esc_html(sprintf(__('%s must be a valid date (YYYY-MM-DD).', 'stackless-lite-edd'), $label)));
                }
                return $value;
            case 'url':
                $url = esc_url_raw($value, ['http', 'https']);
                if ($url === '') {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(esc_html(sprintf(__('%s must be a web address starting with https://.', 'stackless-lite-edd'), $label)));
                }
                return $url;
            case 'select':
                if (!in_array($value, (array) ($field['options'] ?? []), true)) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(esc_html(sprintf(__('Choose one of the options for %s.', 'stackless-lite-edd'), $label)));
                }
                return $value;
            case 'list':
                return implode(', ', array_slice(array_values(array_filter(array_map('trim', explode(',', $value)), static function (string $item): bool {
                    return $item !== '';
                })), 0, 30));
            case 'gtin':
                $digits = (string) preg_replace('/[\s-]/', '', $value);
                if (!self::validGtin($digits)) {
                    throw new \InvalidArgumentException(esc_html(__('The GTIN must be 8, 12, 13 or 14 digits with a valid check digit.', 'stackless-lite-edd')));
                }
                return $digits;
            case 'isbn13':
            case 'isbn10':
                $isbn = self::isbn13($value);
                if ($isbn === '') {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(esc_html(sprintf(__('%s is not a valid ISBN. Check the digits, including the last one.', 'stackless-lite-edd'), $label)));
                }
                if ($field['type'] === 'isbn13') {
                    return $isbn;
                }
                $isbn10 = self::isbn10($isbn);
                if ($isbn10 === '') {
                    throw new \InvalidArgumentException(esc_html(__('ISBNs starting with 979 have no ISBN-10. Enter it as the ISBN-13 instead.', 'stackless-lite-edd')));
                }
                return $isbn10;
        }
        return $value;
    }

    public static function validGtin(string $digits): bool
    {
        if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $digits)) {
            return false;
        }
        $sum = 0;
        $body = substr($digits, 0, -1);
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /** A valid ISBN-13 for an ISBN-10 or ISBN-13 entry, or '' when it is not valid. */
    public static function isbn13(string $value): string
    {
        $raw = strtoupper((string) preg_replace('/[\s-]/', '', $value));
        if (preg_match('/^\d{13}$/', $raw)) {
            return (strpos($raw, '978') === 0 || strpos($raw, '979') === 0) && self::validGtin($raw) ? $raw : '';
        }
        if (!preg_match('/^\d{9}[\dX]$/', $raw)) {
            return '';
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($raw[$i] === 'X' ? 10 : (int) $raw[$i]) * (10 - $i);
        }
        if ($sum % 11 !== 0) {
            return '';
        }
        $body = '978' . substr($raw, 0, 9);
        $total = 0;
        for ($i = 0; $i < 12; $i++) {
            $total += (int) $body[$i] * ($i % 2 ? 3 : 1);
        }
        return $body . ((10 - $total % 10) % 10);
    }

    /** ISBN-10 exists only for 978-prefixed ISBN-13s. */
    public static function isbn10(string $isbn13): string
    {
        if (!preg_match('/^978(\d{9})\d$/', $isbn13, $match)) {
            return '';
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $match[1][$i] * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;
        return $match[1] . ($check === 10 ? 'X' : (string) $check);
    }
}
