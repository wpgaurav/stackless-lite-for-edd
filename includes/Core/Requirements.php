<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

final class Requirements
{
    public static function met(): bool
    {
        return defined('EDD_VERSION') && version_compare(EDD_VERSION, STACKLESS_EDD_MIN_EDD, '>=') && function_exists('EDD');
    }

    public static function notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $message = defined('EDD_VERSION')
            /* translators: 1: required Easy Digital Downloads version, 2: installed version */
            ? sprintf(__('Stackless Lite for EDD needs Easy Digital Downloads %1$s or newer. You have %2$s.', 'stackless-lite-edd'), STACKLESS_EDD_MIN_EDD, EDD_VERSION)
            : __('Stackless Lite for EDD needs Easy Digital Downloads installed and active.', 'stackless-lite-edd');
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }
}
