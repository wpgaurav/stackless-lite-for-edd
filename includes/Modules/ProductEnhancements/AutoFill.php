<?php

namespace StacklessEDD\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Values EDD already stores about a download. The merchant's own entry always wins over these.
 *
 * - gtin / isbn13 / isbn10: the download's SKU when it is a valid barcode number.
 * - file_format, file_size, file_count, book_format: the download's files (per price option when
 *   files are limited to one).
 * - updated: the download's last modified date, when it has files.
 * - language: the site language.
 */
final class AutoFill
{
    /** @var array<string, array<string, string>> */
    private static $cache = [];

    /**
     * @param int|null $priceId Variable price id, or null for the whole download.
     * @return array<string, string> auto key => display value
     */
    public static function values(int $downloadId, ?int $priceId = null): array
    {
        $cacheKey = $downloadId . ':' . ($priceId === null ? '' : (string) $priceId);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        $values = [];
        $download = function_exists('edd_get_download') ? edd_get_download($downloadId) : null;
        if (!$download instanceof \EDD_Download) {
            return self::$cache[$cacheKey] = [];
        }

        $sku = (string) preg_replace('/[\s-]/', '', (string) $download->get_sku());
        if ($sku !== '' && Fields::validGtin($sku)) {
            $values['gtin'] = $sku;
            $isbn = Fields::isbn13($sku);
            if ($isbn !== '') {
                $values['isbn13'] = $isbn;
                $isbn10 = Fields::isbn10($isbn);
                if ($isbn10 !== '') {
                    $values['isbn10'] = $isbn10;
                }
            }
        }

        // A price option gets the files it is entitled to (EDD 3.7.1+ counts unrestricted files too).
        $files = $priceId !== null && method_exists($download, 'get_files_for_price_id') // @phpstan-ignore function.alreadyNarrowedType (absent before EDD 3.7.1)
            ? (array) $download->get_files_for_price_id($priceId)
            : (array) $download->get_files($priceId);
        if ($files) {
            $formats = [];
            $size = 0;
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $url = (string) ($file['file'] ?? '');
                $path = (string) wp_parse_url($url, PHP_URL_PATH);
                $extension = strtoupper((string) pathinfo($path, PATHINFO_EXTENSION));
                if ($extension !== '' && strlen($extension) <= 6) {
                    $formats[$extension] = true;
                }
                $size += self::fileSize($url, (int) ($file['attachment_id'] ?? 0));
            }
            if ($formats) {
                $values['file_format'] = implode(', ', array_keys($formats));
            }
            if ($size > 0) {
                $values['file_size'] = (string) size_format($size, $size >= 1048576 ? 1 : 0);
            }
            $values['file_count'] = (string) count($files);
            $values['book_format'] = __('Ebook', 'stackless-lite-edd');
            $modified = get_post_modified_time('Y-m-d', false, $downloadId);
            if (is_string($modified) && $modified !== '') {
                $values['updated'] = $modified;
            }
        }

        $language = get_bloginfo('language');
        if ($language && function_exists('locale_get_display_language')) {
            $name = locale_get_display_language(str_replace('-', '_', $language), get_locale());
            if ($name) {
                $values['language'] = $name;
            }
        }

        /**
         * Filters the values auto-filled from EDD data.
         *
         * @param array<string, string> $values
         * @param int                   $downloadId
         * @param int|null              $priceId
         */
        return self::$cache[$cacheKey] = (array) apply_filters('stackless_edd_product_enhancements_auto_values', $values, $downloadId, $priceId);
    }

    public static function flush(int $downloadId): void
    {
        foreach (array_keys(self::$cache) as $key) {
            if (strpos($key, $downloadId . ':') === 0) {
                unset(self::$cache[$key]);
            }
        }
    }

    /** Size of a file stored in the media library or under uploads; 0 when unknown (remote files). */
    private static function fileSize(string $file, int $attachmentId): int
    {
        $path = $attachmentId > 0 ? (string) get_attached_file($attachmentId) : '';
        if ($path === '' && $file !== '') {
            $attachment = attachment_url_to_postid($file);
            $path = $attachment ? (string) get_attached_file($attachment) : '';
        }
        if ($path === '' && $file !== '') {
            $uploads = wp_get_upload_dir();
            if (!empty($uploads['baseurl']) && strpos($file, (string) $uploads['baseurl']) === 0) {
                $path = (string) $uploads['basedir'] . substr($file, strlen((string) $uploads['baseurl']));
            }
        }
        if ($path !== '' && strpos(wp_normalize_path($path), '..') === false && is_readable($path)) {
            $size = filesize($path);
            return $size === false ? 0 : (int) $size;
        }
        return 0;
    }

    /** @param mixed $value */
    public static function number($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
