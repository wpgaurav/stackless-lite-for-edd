<?php

namespace StacklessEDD\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Download meta storage and the rows the storefront shows.
 *
 * Download: _stackless_edd_product_enhancements = {v, preset, show, values{key: value}, hidden[], custom[{label, value}]}
 * Variable prices: _stackless_edd_product_enhancements_prices = {price_id: {key: value}}
 */
final class Repository
{
    public const META = '_stackless_edd_product_enhancements';
    public const META_PRICES = '_stackless_edd_product_enhancements_prices';
    public const MAX_CUSTOM = 20;

    /** The preset a download starts with. EDD sells digital goods, so downloads start as "Digital download". */
    public static function suggestedPreset(int $downloadId): string
    {
        return 'digital';
    }

    /**
     * @return array{v: int, preset: string, show: bool, values: array<string, string>, hidden: string[], custom: array<int, array{label: string, value: string}>, saved: bool}
     */
    public static function get(int $downloadId): array
    {
        $stored = $downloadId > 0 ? get_post_meta($downloadId, self::META, true) : [];
        return self::normalize(is_array($stored) ? $stored : [], $downloadId);
    }

    /**
     * @param array<string, mixed> $stored
     * @return array{v: int, preset: string, show: bool, values: array<string, string>, hidden: string[], custom: array<int, array{label: string, value: string}>, saved: bool}
     */
    public static function normalize(array $stored, int $downloadId = 0): array
    {
        $presets = Fields::presets();
        $preset = isset($stored['preset'], $presets[(string) $stored['preset']]) ? (string) $stored['preset'] : self::suggestedPreset($downloadId);
        $values = [];
        foreach ((array) ($stored['values'] ?? []) as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $key] = (string) $value;
            }
        }
        $custom = [];
        foreach ((array) ($stored['custom'] ?? []) as $row) {
            if (is_array($row) && isset($row['label'], $row['value'])) {
                $custom[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
            }
        }
        return [
            'v' => 1,
            'preset' => $preset,
            'show' => !empty($stored) && (!array_key_exists('show', $stored) || !empty($stored['show'])),
            'values' => $values,
            'hidden' => array_values(array_map('strval', (array) ($stored['hidden'] ?? []))),
            'custom' => $custom,
            'saved' => !empty($stored),
        ];
    }

    /** @return array<string, string> one variable price's own values */
    public static function priceValues(int $downloadId, int $priceId): array
    {
        $stored = get_post_meta($downloadId, self::META_PRICES, true);
        $stored = is_array($stored) ? ($stored[$priceId] ?? []) : [];
        $values = [];
        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $key] = (string) $value;
            }
        }
        return $values;
    }

    /**
     * Validate the download editor input. Invalid values are dropped and reported.
     *
     * @param array<string, mixed> $input preset, show, values{}, hidden[], custom (textarea "Label | Value" lines)
     * @return array{0: array<string, mixed>, 1: string[]} [data, errors]
     */
    public static function validate(int $downloadId, array $input): array
    {
        $presets = Fields::presets();
        $preset = isset($input['preset'], $presets[(string) $input['preset']]) ? (string) $input['preset'] : self::suggestedPreset($downloadId);
        $keys = $presets[$preset]['fields'];
        $errors = [];
        $values = [];
        $rawValues = is_array($input['values'] ?? null) ? $input['values'] : [];
        foreach ($keys as $key) {
            try {
                $value = Fields::clean($key, $rawValues[$key] ?? '');
                if ($value !== '') {
                    $values[$key] = $value;
                }
            } catch (\InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }
        $hidden = array_values(array_intersect($keys, array_map('strval', (array) ($input['hidden'] ?? []))));
        [$custom, $customErrors] = self::parseCustom((string) ($input['custom'] ?? ''));
        return [
            ['v' => 1, 'preset' => $preset, 'show' => !empty($input['show']), 'values' => $values, 'hidden' => $hidden, 'custom' => $custom],
            array_merge($errors, $customErrors),
        ];
    }

    /**
     * "Label | Value" per line (a colon works too when there is no pipe).
     *
     * @return array{0: array<int, array{label: string, value: string}>, 1: string[]}
     */
    public static function parseCustom(string $text): array
    {
        $rows = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $separator = strpos($line, '|') !== false ? '|' : (strpos($line, ':') !== false ? ':' : '');
            if ($separator === '') {
                $errors[] = sprintf(
                    /* translators: %s: the line the merchant typed */
                    __('Extra detail "%s" needs a name and a value separated by |.', 'stackless-lite-edd'),
                    mb_substr($line, 0, 40)
                );
                continue;
            }
            [$label, $value] = array_map('trim', explode($separator, $line, 2));
            $label = mb_substr(sanitize_text_field($label), 0, 80);
            $value = mb_substr(sanitize_text_field($value), 0, 300);
            if ($label === '' || $value === '') {
                $errors[] = __('Each extra detail needs a name and a value.', 'stackless-lite-edd');
                continue;
            }
            if (count($rows) >= self::MAX_CUSTOM) {
                /* translators: %d: maximum number of extra details */
                $errors[] = sprintf(__('Only the first %d extra details are kept.', 'stackless-lite-edd'), self::MAX_CUSTOM);
                break;
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }
        return [$rows, $errors];
    }

    /** @param array<int, array{label: string, value: string}> $rows */
    public static function customText(array $rows): string
    {
        return implode("\n", array_map(static function ($row) {
            return $row['label'] . ' | ' . $row['value'];
        }, $rows));
    }

    /**
     * Validate one variable price's values against the download's preset.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, string>, 1: string[]}
     */
    public static function validatePriceValues(string $preset, array $input): array
    {
        $presets = Fields::presets();
        $keys = $presets[$preset]['fields'] ?? [];
        $values = [];
        $errors = [];
        foreach ($keys as $key) {
            try {
                $value = Fields::clean($key, $input[$key] ?? '');
                if ($value !== '') {
                    $values[$key] = $value;
                }
            } catch (\InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }
        return [$values, $errors];
    }

    /** @return int[] variable price ids of a download (empty when it has a single price) */
    public static function priceIds(int $downloadId): array
    {
        if (!function_exists('edd_has_variable_prices') || !edd_has_variable_prices($downloadId)) {
            return [];
        }
        return array_map('intval', array_keys((array) edd_get_variable_prices($downloadId)));
    }

    /**
     * What the storefront shows: preset fields in order (entered value, then EDD's value, then
     * the suggested default), then custom rows. Rows that differ by variable price carry one value
     * per price id under "variations".
     *
     * @return array<int, array{key: string, label: string, value: string, type: string, variations: array<int, string>}>
     */
    public static function rows(int $downloadId): array
    {
        $data = self::get($downloadId);
        // A download shows details only once the merchant turns the table on, so enabling the module changes no page by itself.
        if (!$data['show']) {
            return (array) apply_filters('stackless_edd_product_enhancements_rows', [], $downloadId);
        }
        $fields = Fields::all();
        $presets = Fields::presets();
        $priceIds = self::priceIds($downloadId);
        $rows = [];
        foreach ($presets[$data['preset']]['fields'] ?? [] as $key) {
            if (in_array($key, $data['hidden'], true) || !isset($fields[$key])) {
                continue;
            }
            $field = $fields[$key];
            $value = self::value($downloadId, null, $key, $field, [], $data['values']);
            $perPrice = [];
            foreach ($priceIds as $priceId) {
                $priceValue = self::value($downloadId, $priceId, $key, $field, self::priceValues($downloadId, $priceId), $data['values']);
                if ($priceValue !== '' && $priceValue !== $value) {
                    $perPrice[$priceId] = $priceValue;
                }
            }
            if ($value === '' && !$perPrice) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => (string) $field['label'], 'value' => $value, 'type' => (string) $field['type'], 'variations' => $perPrice];
        }
        foreach ($data['custom'] as $index => $row) {
            $rows[] = ['key' => 'custom_' . $index, 'label' => $row['label'], 'value' => $row['value'], 'type' => 'text', 'variations' => []];
        }
        /**
         * Filters the rows of the details table.
         *
         * @param array $rows
         * @param int   $downloadId
         */
        return (array) apply_filters('stackless_edd_product_enhancements_rows', $rows, $downloadId);
    }

    /**
     * @param array<string, mixed>  $field
     * @param array<string, string> $ownValues     Price-level values (empty for the download itself)
     * @param array<string, string> $productValues Download-level values
     */
    private static function value(int $downloadId, ?int $priceId, string $key, array $field, array $ownValues, array $productValues): string
    {
        if (isset($ownValues[$key]) && $ownValues[$key] !== '') {
            return $ownValues[$key];
        }
        if (isset($productValues[$key]) && $productValues[$key] !== '') {
            return $productValues[$key];
        }
        // Each ISBN can be worked out from the other.
        $isbnSource = $ownValues['isbn13'] ?? $ownValues['isbn10'] ?? $productValues['isbn13'] ?? $productValues['isbn10'] ?? '';
        if ($key === 'isbn13' && $isbnSource !== '') {
            return Fields::isbn13($isbnSource);
        }
        if ($key === 'isbn10' && $isbnSource !== '') {
            return Fields::isbn10(Fields::isbn13($isbnSource));
        }
        if (!empty($field['auto'])) {
            $auto = AutoFill::values($downloadId, $priceId)[(string) $field['auto']] ?? '';
            if ($auto !== '') {
                return $auto;
            }
        }
        return (string) ($field['default'] ?? '');
    }
}
