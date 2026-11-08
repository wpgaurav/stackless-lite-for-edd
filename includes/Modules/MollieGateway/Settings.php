<?php

namespace StacklessEDD\Modules\MollieGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the module's options (saved on Stackless → Settings → Mollie as
 * stackless_edd_mollie_gateway_{key}) plus the values derived from them.
 *
 * Keys can be pinned in wp-config.php with STACKLESS_EDD_MOLLIE_LIVE_API_KEY and
 * STACKLESS_EDD_MOLLIE_TEST_API_KEY; pinned keys win and their fields are hidden.
 */
final class Settings
{
    public const MODULE_ID = 'mollie_gateway';
    public const GATEWAY_ID = 'stackless_edd_mollie';
    public const PREFIX = 'stackless_edd_mollie_gateway_';

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

    public static function constantName(string $mode): string
    {
        return 'STACKLESS_EDD_MOLLIE_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_API_KEY';
    }

    /** Whether the key of a mode is pinned in wp-config.php. */
    public static function isDefined(string $mode): bool
    {
        $constant = self::constantName($mode);
        return defined($constant) && constant($constant);
    }

    public static function apiKey(string $mode = ''): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : self::mode();
        if (self::isDefined($mode)) {
            return trim((string) constant(self::constantName($mode)));
        }
        return trim((string) self::get($mode . '_api_key', ''));
    }

    public static function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : self::mode();
        return new Api($mode, self::apiKey($mode));
    }

    public static function title(): string
    {
        $title = trim((string) self::get('title', ''));
        return $title !== '' ? $title : __('Card, iDEAL, Bancontact or PayPal', 'stackless-lite-edd');
    }

    public static function description(): string
    {
        return (string) self::get('description', __('You\'ll finish paying on Mollie\'s secure checkout.', 'stackless-lite-edd'));
    }

    /** @return string[] */
    public static function configuredMethods(): array
    {
        $methods = self::get('methods', []);
        if (!is_array($methods)) {
            $methods = $methods === '' ? [] : [(string) $methods];
        }
        return array_values(array_filter(array_map('strval', $methods)));
    }

    public static function pickerAtCheckout(): bool
    {
        return self::get('method_picker', 'checkout') === 'checkout';
    }

    public static function savedMethodsEnabled(): bool
    {
        return self::get('saved_methods', 'yes') === 'yes';
    }

    public static function debug(): bool
    {
        return self::get('debug', 'no') === 'yes';
    }

    public static function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . Webhook::ROUTE);
    }

    public static function settingsUrl(): string
    {
        return class_exists(SettingsPage::class) ? SettingsPage::url(self::MODULE_ID) : admin_url('admin.php');
    }

    /**
     * Logs to the EDD debug log (Downloads → Tools → Debug Log, source stackless-edd-mollie_gateway).
     * Warnings and errors always log; info and debug only with this module's debug log on.
     * Callers never pass API keys.
     */
    public static function log(string $message, string $level = 'info', array $context = []): void
    {
        if (in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
            Logger::log(self::MODULE_ID, $message, $level, $context);
            return;
        }
        if (!self::debug() || !function_exists('edd_debug_log')) {
            return;
        }
        $line = sprintf('[stackless-edd-%s] %s %s', self::MODULE_ID, strtoupper($level), $message);
        if ($context) {
            $line .= ' ' . wp_json_encode($context);
        }
        edd_debug_log($line, true);
    }
}
