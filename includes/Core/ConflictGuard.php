<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

final class ConflictGuard
{
    /** Shared conflict lists, reused by modules. */
    public const SUBSCRIPTIONS = [
        'edd-recurring/edd-recurring.php' => 'EDD Recurring Payments',
    ];

    public const MULTI_CURRENCY = [
        'edd-multi-currency/edd-multi-currency.php' => 'EDD Multi Currency',
        'easy-digital-downloads-multi-currency/edd-multi-currency.php' => 'EDD Multi Currency',
    ];

    public const LICENSING = [
        'edd-software-licensing/edd-software-licenses.php' => 'EDD Software Licensing',
    ];

    public const CONTENT_RESTRICTION = [
        'edd-content-restriction/content-restriction.php' => 'EDD Content Restriction',
    ];

    public const INVOICES = [
        'edd-invoices/edd-invoices.php' => 'EDD Invoices',
    ];

    public const WISH_LISTS = [
        'edd-wish-lists/edd-wish-lists.php' => 'EDD Wish Lists',
    ];

    /** @param array<string,string> $plugins basename => name */
    public static function firstActive(array $plugins): ?string
    {
        if (!$plugins) {
            return null;
        }
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        foreach ($plugins as $basename => $name) {
            if (in_array($basename, $active, true)) {
                return $name;
            }
        }
        return null;
    }
}
