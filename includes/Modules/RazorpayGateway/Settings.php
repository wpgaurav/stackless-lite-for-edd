<?php

namespace StacklessEDD\Modules\RazorpayGateway;

use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the module's options (stored by the Stackless settings screen as
 * stackless_edd_razorpay_gateway_{key}) plus derived values used across the module.
 *
 * Keys can be pinned in wp-config.php instead, e.g. STACKLESS_EDD_RAZORPAY_LIVE_KEY_SECRET;
 * pinned fields are left out of the settings screen.
 */
final class Settings
{
    public const MODULE_ID = 'razorpay_gateway';
    public const GATEWAY_ID = 'stackless_edd_razorpay';
    public const PREFIX = 'stackless_edd_razorpay_gateway_';
    public const REST_NAMESPACE = 'stackless-edd/v1';

    /** Renewal charges start this many days early by default (pre-debit notice plus settlement). */
    public const DEFAULT_LEAD_DAYS = 3;
    public const DEFAULT_ALLOWANCE = 50;

    /** Key settings per mode, in the order the settings screen shows them. */
    public const KEY_FIELDS = ['key_id', 'key_secret', 'webhook_secret'];

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

    public static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_RAZORPAY_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_' . strtoupper($field);
    }

    /** Whether a setting is pinned in wp-config.php, e.g. STACKLESS_EDD_RAZORPAY_LIVE_KEY_SECRET. */
    public static function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function secret(string $mode, string $field): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : self::mode();
        if (self::isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        $value = self::get($mode . '_' . $field, '');
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function keyId(string $mode = ''): string
    {
        return self::secret($mode, 'key_id');
    }

    public static function keySecret(string $mode = ''): string
    {
        return self::secret($mode, 'key_secret');
    }

    public static function webhookSecret(string $mode = ''): string
    {
        return self::secret($mode, 'webhook_secret');
    }

    public static function hasKeys(string $mode = ''): bool
    {
        return self::keyId($mode) !== '' && self::keySecret($mode) !== '';
    }

    public static function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : self::mode();
        return new Api($mode, self::keyId($mode), self::keySecret($mode));
    }

    /** @return string[] */
    public static function recurringMethods(): array
    {
        $methods = self::get('recurring_methods', Mandate::METHODS);
        return is_array($methods) ? array_values(array_intersect(Mandate::METHODS, array_map('strval', $methods))) : Mandate::METHODS;
    }

    public static function switchAllowance(): int
    {
        $value = self::get('switch_allowance', (string) self::DEFAULT_ALLOWANCE);
        return max(0, min(500, is_numeric($value) ? (int) $value : self::DEFAULT_ALLOWANCE));
    }

    /** 1 to 7 days, default 3. */
    public static function leadDays(): int
    {
        $value = self::get('lead_days', (string) self::DEFAULT_LEAD_DAYS);
        $days = is_numeric($value) ? (int) $value : 0;
        return max(1, min(7, $days > 0 ? $days : self::DEFAULT_LEAD_DAYS));
    }

    public static function themeColor(): string
    {
        $color = (string) self::get('theme_color', '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '';
    }

    public static function title(): string
    {
        $title = trim((string) self::get('title', ''));
        return $title !== '' ? $title : __('UPI, cards, netbanking and wallets', 'stackless-lite-edd');
    }

    public static function description(): string
    {
        return (string) self::get('description', __('Pay securely with Razorpay.', 'stackless-lite-edd'));
    }

    public static function debug(): bool
    {
        return self::get('debug', 'no') === 'yes';
    }

    public static function webhookUrl(): string
    {
        return rest_url(self::REST_NAMESPACE . Webhook::ROUTE);
    }

    public static function settingsUrl(): string
    {
        return \StacklessEDD\Admin\SettingsPage::url(self::MODULE_ID);
    }

    /** The store's host, written to Razorpay notes so events for other sites on the same account are ignored. */
    public static function site(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Logs to the EDD debug log (Downloads → Tools → Debug Log, source stackless-edd-razorpay_gateway).
     * Warnings and errors always log; info and debug only with this module's debug log on.
     * Callers never pass keys or secrets.
     *
     * @param array<string,mixed> $context
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
