<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Keeps a logged-in buyer's name filled in at checkout.
 *
 * EDD fills the checkout's first and last name from the WordPress profile and caches them in the
 * visitor's session the first time checkout loads. It never writes the typed name back, so a buyer
 * whose profile has no name types it again on every purchase. After a paid order this fills an empty
 * profile name from the order's customer, and on the next page load it fills the empty names in the
 * cached session.
 */
final class BuyerName
{
    public static function boot(): void
    {
        add_action('edd_complete_purchase', [self::class, 'remember'], 30);
        add_action('template_redirect', [self::class, 'fillSession'], 5);
    }

    /** @param int|string $orderId */
    public static function remember($orderId): void
    {
        if (!function_exists('edd_get_order') || !function_exists('edd_get_customer')) {
            return;
        }
        $order = edd_get_order((int) $orderId);
        if (!$order || (int) $order->user_id <= 0 || (int) $order->customer_id <= 0) {
            return;
        }
        $customer = edd_get_customer((int) $order->customer_id);
        if (!$customer) {
            return;
        }
        $name = self::split((string) $customer->name);
        foreach (['first_name' => $name[0], 'last_name' => $name[1]] as $key => $value) {
            if ($value !== '' && (string) get_user_meta((int) $order->user_id, $key, true) === '') {
                update_user_meta((int) $order->user_id, $key, $value);
            }
        }
    }

    public static function fillSession(): void
    {
        if (!is_user_logged_in() || !function_exists('EDD')) {
            return;
        }
        $session = EDD()->session->get('customer');
        // No cached customer yet: EDD reads the profile and customer record itself.
        if (!is_array($session) || (!empty($session['first_name']) && !empty($session['last_name']))) {
            return;
        }
        $userId = get_current_user_id();
        $name = [(string) get_user_meta($userId, 'first_name', true), (string) get_user_meta($userId, 'last_name', true)];
        if ($name[0] === '' && function_exists('edd_get_customer_by')) {
            $customer = edd_get_customer_by('user_id', (string) $userId);
            $name = $customer ? self::split((string) $customer->name) : $name;
        }
        $changed = false;
        foreach (['first_name' => $name[0], 'last_name' => $name[1]] as $key => $value) {
            if (empty($session[$key]) && $value !== '') {
                $session[$key] = $value;
                $changed = true;
            }
        }
        if ($changed) {
            EDD()->session->set('customer', $session);
        }
    }

    /** "Ada King Lovelace" → ['Ada', 'King Lovelace'], the way EDD splits a customer name. @return array{0:string,1:string} */
    public static function split(string $name): array
    {
        $parts = explode(' ', trim(preg_replace('/\s+/', ' ', $name) ?? ''), 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
