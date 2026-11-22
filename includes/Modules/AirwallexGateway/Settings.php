<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the module's options (saved on Stackless → Settings → Airwallex as
 * stackless_edd_airwallex_gateway_{key}) plus the values derived from them.
 *
 * Each value can be pinned in wp-config.php with STACKLESS_EDD_AIRWALLEX_{LIVE|TEST}_{CLIENT_ID|
 * API_KEY|ACCOUNT_ID|WEBHOOK_SECRET}; pinned values win and their fields are hidden.
 */
final class Settings
{
    public const GATEWAY_ID = 'stackless_edd_airwallex';
    public const MODULE_ID = 'airwallex_gateway';
    public const PREFIX = 'stackless_edd_airwallex_gateway_';
    /** merchant_order_id prefix of this plugin's payments. */
    public const REFERENCE_PREFIX = 'sledd';

    public const CREDENTIALS = ['client_id', 'api_key', 'account_id', 'webhook_secret'];

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = '')
    {
        $value = get_option(self::PREFIX . $key, null);
        return $value === null || $value === false ? $default : $value;
    }

    /** test|live */
    public static function mode(): string
    {
        return self::get('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    public static function normalizeMode(string $mode): string
    {
        return $mode === 'live' || $mode === 'test' ? $mode : self::mode();
    }

    public static function constantName(string $mode, string $key): string
    {
        return 'STACKLESS_EDD_AIRWALLEX_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_' . strtoupper($key);
    }

    /** Whether a value is pinned in wp-config.php. */
    public static function isDefined(string $mode, string $key): bool
    {
        $constant = self::constantName($mode, $key);
        return defined($constant) && constant($constant);
    }

    public static function credential(string $key, string $mode = ''): string
    {
        $mode = self::normalizeMode($mode);
        if (self::isDefined($mode, $key)) {
            return trim((string) constant(self::constantName($mode, $key)));
        }
        return trim((string) self::get($mode . '_' . $key, ''));
    }

    public static function account(string $mode = ''): string
    {
        return self::credential('account_id', $mode);
    }

    public static function secret(string $mode): string
    {
        return self::credential('webhook_secret', $mode);
    }

    public static function configured(string $mode = ''): bool
    {
        return self::api($mode)->configured();
    }

    public static function api(string $mode = ''): Api
    {
        $mode = self::normalizeMode($mode);
        return new Api(
            $mode,
            self::credential('client_id', $mode),
            self::credential('api_key', $mode),
            self::credential('account_id', $mode),
            self::get($mode . '_login_as', 'no') === 'yes'
        );
    }

    /** Switched on in Downloads → Settings → Payments. */
    public static function enabled(): bool
    {
        return function_exists('edd_is_gateway_active') && edd_is_gateway_active(self::GATEWAY_ID);
    }

    public static function title(): string
    {
        $title = trim((string) self::get('title', ''));
        return $title !== '' ? $title : __('Card and more payment methods', 'stackless-lite-edd');
    }

    public static function description(): string
    {
        return (string) self::get('description', __('You\'ll finish paying on Airwallex\'s secure payment page.', 'stackless-lite-edd'));
    }

    public static function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . Webhook::ROUTE);
    }

    public static function settingsUrl(): string
    {
        return class_exists(SettingsPage::class) ? SettingsPage::url(self::MODULE_ID) : admin_url('admin.php');
    }

    public static function userAgent(): string
    {
        return 'Stackless-for-EDD/' . (defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : 'dev');
    }

    /**
     * Logs to the EDD debug log (Downloads → Tools → Debug Log, source stackless-edd-airwallex_gateway).
     * Warnings and errors always log; info and debug only with this module's debug log on.
     * Never pass secrets.
     */
    public static function log(string $message, string $level = 'info'): void
    {
        if (in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
            Logger::log(self::MODULE_ID, $message, $level);
            return;
        }
        if (self::get('debug', 'no') === 'yes' && function_exists('edd_debug_log')) {
            edd_debug_log(sprintf('[stackless-edd-%s] %s %s', self::MODULE_ID, strtoupper($level), $message), true);
        }
    }
}
