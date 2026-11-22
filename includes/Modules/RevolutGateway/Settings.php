<?php

namespace StacklessEDD\Modules\RevolutGateway;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\LiveStore;
use StacklessEDD\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the module's options (saved on Stackless → Settings → Revolut as
 * stackless_edd_revolut_gateway_{key}) plus the values derived from them.
 *
 * Secrets can be pinned in wp-config.php with STACKLESS_EDD_REVOLUT_{LIVE|TEST}_SECRET_KEY and
 * STACKLESS_EDD_REVOLUT_{LIVE|TEST}_WEBHOOK_SECRET; pinned values win and their fields are hidden.
 */
final class Settings
{
    public const PREFIX = 'stackless_edd_' . Keys::MODULE_ID . '_';
    public const SECRETS = ['secret_key', 'webhook_secret'];

    public static function get(string $key, string $default = ''): string
    {
        $value = get_option(self::PREFIX . $key, null);
        return $value === null || $value === false || !is_scalar($value) ? $default : (string) $value;
    }

    /** test|live */
    public static function mode(): string
    {
        return self::get('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    public static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_EDD_REVOLUT_' . ($mode === 'live' ? 'LIVE' : 'TEST') . '_' . strtoupper($field);
    }

    /** Whether a secret is pinned in wp-config.php. */
    public static function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function secret(string $mode, string $field): string
    {
        if (self::isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        return trim(self::get($mode . '_' . $field));
    }

    public static function secretKey(string $mode = ''): string
    {
        return self::secret($mode === 'live' || $mode === 'test' ? $mode : self::mode(), 'secret_key');
    }

    public static function webhookSecret(string $mode): string
    {
        return self::secret($mode === 'live' ? 'live' : 'test', 'webhook_secret');
    }

    public static function saveWebhookSecret(string $mode, string $secret): void
    {
        update_option(self::PREFIX . ($mode === 'live' ? 'live' : 'test') . '_webhook_secret', $secret);
    }

    public static function api(string $mode): Api
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        return new Api($mode, self::secretKey($mode));
    }

    /** False on a copy of the live store. */
    public static function liveStore(): bool
    {
        return LiveStore::isLive();
    }

    /**
     * Saving a card only serves modules that charge it later, so it is offered only while one
     * answers stackless_edd_order_requires_saved_payment_method.
     */
    public static function savesMethods(): bool
    {
        return (bool) has_filter('stackless_edd_order_requires_saved_payment_method');
    }

    public static function title(): string
    {
        $title = trim(self::get('title'));
        return $title !== '' ? $title : __('Card or Revolut Pay', 'stackless-lite-edd');
    }

    public static function description(): string
    {
        return self::get('description', __('You\'ll finish paying on Revolut\'s secure checkout.', 'stackless-lite-edd'));
    }

    public static function webhookUrl(string $mode): string
    {
        return rest_url('stackless-edd/v1' . Webhook::ROUTE . ($mode === 'live' ? 'live' : 'test'));
    }

    public static function settingsUrl(): string
    {
        return class_exists(SettingsPage::class) ? SettingsPage::url(Keys::MODULE_ID) : admin_url('admin.php');
    }

    /** Warnings and errors always log; the rest only with the debug log on. Callers never pass secrets. */
    public static function log(string $message, string $level = 'info'): void
    {
        if ($level !== 'info' && $level !== 'debug') {
            Logger::log(Keys::MODULE_ID, $message, $level);
            return;
        }
        if (self::get('debug', 'no') !== 'yes' || !function_exists('edd_debug_log')) {
            return;
        }
        edd_debug_log(sprintf('[stackless-edd-%s] %s %s', Keys::MODULE_ID, strtoupper($level), $message), true);
    }
}
