<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Stackless Lite for EDD only: updates from gauravtiwari.org without a license key.
 *
 * GET {API} returns the latest release as JSON (schema 1: plugin, channel, name, version, url, package,
 * package_expires, sha256, requires, requires_php, tested, changelog), or 404 when there is none.
 * GET {API}/download redirects to a fresh signed package URL, so the update offer stores that address
 * and never holds a link that expires. The release is cached for 12 hours (30 minutes after a failed
 * check). Requests carry nothing beyond what wp_remote_get() sends, and the package is checked against
 * the release's SHA-256 before WordPress installs it.
 */
final class LiteUpdater
{
    public const API = 'https://gauravtiwari.org/wp-json/gt-plugin-updates/v1/plugins/stackless-lite-for-edd';
    public const DOWNLOAD = self::API . '/download';
    public const PRODUCT = 'https://gauravtiwari.org/product/stackless-lite-for-edd/';
    public const CACHE = 'stackless_edd_lite_update';
    public const TTL = 43200;
    public const RETRY_TTL = 1800;

    public static function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
        add_filter('plugins_api', [self::class, 'info'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 2);
    }

    public static function basename(): string
    {
        return plugin_basename(STACKLESS_EDD_PLUGIN_FILE);
    }

    public static function slug(): string
    {
        return dirname(self::basename());
    }

    /* ----- Pure parts (unit tested) ----- */

    /**
     * The release in a response, or null when there is none (404) or the response is not a valid release.
     *
     * @return array{version:string,name:string,url:string,requires:string,requires_php:string,tested:string,changelog:string,sha256:string}|null
     */
    public static function parse(int $code, string $body): ?array
    {
        if ($code !== 200) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || (int) ($data['schema'] ?? 0) !== 1) {
            return null;
        }
        if (isset($data['plugin']) && $data['plugin'] !== 'stackless-lite-for-edd') {
            return null;
        }
        $version = is_string($data['version'] ?? null) ? trim($data['version']) : '';
        if (!preg_match('/^\d+(\.\d+){0,3}([.+-][0-9A-Za-z.+-]+)?$/', $version)) {
            return null;
        }
        $text = static function (string $key) use ($data): string {
            return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        };
        $sha = strtolower($text('sha256'));
        return [
            'version' => $version,
            'name' => $text('name') !== '' ? $text('name') : 'Stackless Lite for EDD',
            'url' => $text('url'),
            'requires' => $text('requires'),
            'requires_php' => $text('requires_php'),
            'tested' => $text('tested'),
            'changelog' => $text('changelog'),
            'sha256' => preg_match('/^[0-9a-f]{64}$/', $sha) ? $sha : '',
        ];
    }

    /** How long to keep a check's result: a release or "no release yet" (404) for 12 hours, anything else 30 minutes. */
    public static function ttl(int $code, ?array $release): int
    {
        return $release !== null || $code === 404 ? self::TTL : self::RETRY_TTL;
    }

    public static function isNewer(string $installed, string $available): bool
    {
        return $available !== '' && version_compare($available, $installed, '>');
    }

    /** The update_plugins entry for a release. */
    public static function item(array $release, string $basename): object
    {
        return (object) [
            'id' => self::PRODUCT,
            'slug' => dirname($basename),
            'plugin' => $basename,
            'new_version' => $release['version'],
            'url' => $release['url'] !== '' ? $release['url'] : self::PRODUCT,
            'package' => self::DOWNLOAD,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'icons' => [],
            'banners' => [],
            'compatibility' => new \stdClass(),
        ];
    }

    /* ----- WordPress ----- */

    /** The latest release, from the cache unless $fresh. */
    public static function release(bool $fresh = false): ?array
    {
        $cached = $fresh ? false : get_site_transient(self::CACHE);
        if (is_array($cached) && array_key_exists('release', $cached)) {
            return is_array($cached['release']) ? $cached['release'] : null;
        }
        $response = wp_remote_get(self::API, ['timeout' => 10]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $release = $code === 0 ? null : self::parse($code, (string) wp_remote_retrieve_body($response));
        set_site_transient(self::CACHE, ['release' => $release], self::ttl($code, $release));
        return $release;
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $release = self::release();
        if ($release === null) {
            return $transient;
        }
        $basename = self::basename();
        $item = self::item($release, $basename);
        if (self::isNewer(STACKLESS_EDD_VERSION, $release['version'])) {
            $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
            $transient->response[$basename] = $item;
            unset($transient->no_update[$basename]);
        } else {
            // Listed as current, so the auto-update toggle shows on the Plugins screen.
            $item->new_version = STACKLESS_EDD_VERSION;
            $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
            $transient->no_update[$basename] = $item;
            unset($transient->response[$basename]);
        }
        return $transient;
    }

    /**
     * "View details" on the Plugins and Updates screens.
     *
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public static function info($result, string $action = '', $args = null)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::slug()) {
            return $result;
        }
        $release = self::release();
        if ($release === null) {
            return $result;
        }
        return (object) [
            'name' => $release['name'],
            'slug' => self::slug(),
            'version' => $release['version'],
            'author' => '<a href="https://gauravtiwari.org">Gaurav Tiwari</a>',
            'homepage' => $release['url'] !== '' ? $release['url'] : self::PRODUCT,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'download_link' => self::DOWNLOAD,
            'sections' => [
                'description' => esc_html__('Free store tools for Easy Digital Downloads: wishlists, saved carts, pay what you like, buy links, quantity limits, checkout consents and more.', 'stackless-lite-edd'),
                'changelog' => wp_kses_post($release['changelog']),
            ],
        ];
    }

    /**
     * Downloads the package and checks it against the release's SHA-256 before WordPress installs it.
     *
     * @param mixed $reply
     * @param mixed $package
     * @return mixed
     */
    public static function download($reply, $package)
    {
        if ($reply !== false || $package !== self::DOWNLOAD) {
            return $reply;
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // A fresh check: the cached release may be older than the package the link now serves.
        // If that check fails, fall back to the release this offer came from.
        $cached = get_site_transient(self::CACHE);
        $release = self::release(true) ?? (is_array($cached) && is_array($cached['release'] ?? null) ? $cached['release'] : null);
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }
        $expected = $release['sha256'] ?? '';
        if ($expected !== '' && !hash_equals($expected, (string) hash_file('sha256', $file))) {
            wp_delete_file($file);
            return new \WP_Error('stackless_edd_lite_checksum', __('The downloaded update did not match its checksum, so it was not installed. Try again in a few minutes.', 'stackless-lite-edd'));
        }
        return $file;
    }
}
