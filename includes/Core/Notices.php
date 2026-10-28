<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Shopper-facing flash messages (the wc_add_notice() counterpart). Stored in the EDD session so
 * guests see them too, printed once at the top of the next EDD page, the account page, or wherever
 * a template calls Notices::render().
 */
final class Notices
{
    private const KEY = 'stackless_edd_notices';

    /** @var bool */
    private static $printed = false;

    public static function boot(): void
    {
        add_filter('the_content', [self::class, 'prependToContent'], 1);
        add_action('edd_before_checkout_cart', [self::class, 'printNotices'], 1);
    }

    public static function add(string $message, string $type = 'success'): void
    {
        if ($message === '' || !function_exists('EDD') || !EDD()->session) {
            return;
        }
        $type = in_array($type, ['success', 'error', 'info'], true) ? $type : 'info';
        $notices = self::all();
        $notices[] = ['type' => $type, 'message' => $message];
        EDD()->session->set(self::KEY, $notices);
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function all(): array
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return [];
        }
        $notices = EDD()->session->get(self::KEY);
        return is_array($notices) ? $notices : [];
    }

    public static function hasErrors(): bool
    {
        foreach (self::all() as $notice) {
            if ($notice['type'] === 'error') {
                return true;
            }
        }
        return false;
    }

    /** Markup for pending notices, cleared once rendered. */
    public static function render(): string
    {
        $notices = self::all();
        if (!$notices) {
            return '';
        }
        EDD()->session->set(self::KEY, null);
        self::$printed = true;
        $out = '';
        foreach ($notices as $notice) {
            $role = $notice['type'] === 'error' ? 'alert' : 'status';
            $out .= sprintf(
                '<div class="stackless-store-notice stackless-store-notice--%1$s edd-alert edd-alert-%2$s" role="%3$s">%4$s</div>',
                esc_attr($notice['type']),
                esc_attr($notice['type'] === 'error' ? 'error' : ($notice['type'] === 'success' ? 'success' : 'info')),
                esc_attr($role),
                wp_kses_post($notice['message'])
            );
        }
        return '<div class="stackless-store-notices">' . $out . '</div>';
    }

    public static function printNotices(): void
    {
        echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
    }

    /** @param mixed $content */
    public static function prependToContent($content)
    {
        if (self::$printed || is_admin() || !is_singular() || !in_the_loop() || !is_main_query() || !self::all()) {
            return $content;
        }
        return self::render() . $content;
    }
}
