<?php

namespace StacklessEDD\Modules\RevolutGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved cards live in a custom table and change at checkout and on the account page, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Cards saved at Revolut for merchant use: {$wpdb->prefix}stackless_edd_revolut_tokens.
 *
 * EDD has no payment token store. One row per card per WordPress user (0 = guest), recording
 * the mode and the Revolut customer the card belongs to. The row id is what
 * SubscriptionData::META_TOKEN_ID holds on orders and subscriptions. Cards saved for the
 * customer only are never stored.
 */
final class Tokens
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_revolut_tokens';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  method_id varchar(64) NOT NULL DEFAULT '',
  revolut_customer varchar(64) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  brand varchar(50) NOT NULL DEFAULT '',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY method_id (method_id)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /** @return array{id:int, user:int, method:string, customer:string, mode:string}|null */
    public static function get(int $tokenId): ?array
    {
        $row = self::row($tokenId);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'user' => (int) $row['user_id'],
            'method' => (string) $row['method_id'],
            'customer' => (string) $row['revolut_customer'],
            'mode' => ($row['mode'] ?? '') === 'live' ? 'live' : 'test',
        ];
    }

    /** @return array<string,mixed>|null */
    public static function row(int $tokenId): ?array
    {
        global $wpdb;
        if ($tokenId < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $tokenId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Saves (or finds) the row of a saved card.
     *
     * @param array<string,mixed> $method A Revolut payment method.
     * @return int row id, 0 when it could not be saved
     */
    public static function save(array $method, int $userId, string $customer, string $mode): int
    {
        global $wpdb;
        $id = (string) ($method['id'] ?? '');
        if ($id === '' || $customer === '' || ($method['saved_for'] ?? '') !== 'merchant') {
            return 0;
        }
        $mode = $mode === 'live' ? 'live' : 'test';
        $userId = max(0, $userId);
        $tokenId = 0;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE method_id = %s AND user_id = %d', self::table(), $id, $userId), ARRAY_A);
        foreach ((array) $rows as $row) {
            if (is_array($row) && $row['mode'] === $mode && $row['revolut_customer'] === $customer) {
                $tokenId = (int) $row['id'];
            }
        }
        if ($tokenId === 0) {
            $month = (int) ($method['expiry_month'] ?? 0);
            $year = (int) ($method['expiry_year'] ?? 0);
            $brand = strtolower((string) ($method['brand'] ?? ''));
            $saved = $wpdb->insert(self::table(), [
                'user_id' => $userId,
                'method_id' => $id,
                'revolut_customer' => $customer,
                'mode' => $mode,
                'brand' => substr((string) preg_replace('/[^a-z_]/', '', $brand), 0, 50),
                'last4' => substr((string) preg_replace('/\D/', '', (string) ($method['last_four'] ?? '')), -4),
                'exp_month' => $month >= 1 && $month <= 12 ? sprintf('%02d', $month) : '',
                'exp_year' => $year >= 2000 && $year <= 9999 ? (string) $year : '',
                'is_default' => $userId > 0 && !self::forUser($userId, $mode) ? 1 : 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$saved) {
                Settings::log('Could not save a Revolut card.', 'error');
                return 0;
            }
            $tokenId = (int) $wpdb->insert_id;
        }

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it
         * to a subscription whose method the customer is changing. Also fired when the customer
         * entered a card that was already saved, which is a choice of it just the same.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Keys::GATEWAY_ID, (string) $tokenId, $userId);
        return $tokenId;
    }

    /**
     * A row when $userId owns it.
     *
     * @return array<string,mixed>|null
     */
    public static function owned(int $tokenId, int $userId): ?array
    {
        $row = self::row($tokenId);
        return $row && $userId > 0 && (int) $row['user_id'] === $userId ? $row : null;
    }

    /**
     * A user's cards, default first. Cards of the other mode are left out: a sandbox card
     * cannot pay a live order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId, ?string $mode = null): array
    {
        global $wpdb;
        if ($userId < 1) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY is_default DESC, id DESC', self::table(), $userId, $mode ?? Settings::mode()), ARRAY_A);
        return array_values(array_filter((array) $rows, 'is_array'));
    }

    /** @param array<string,mixed> $row */
    public static function setDefault(array $row): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_default' => 0], ['user_id' => (int) $row['user_id'], 'mode' => (string) $row['mode']], ['%d'], ['%d', '%s']);
        $wpdb->update(self::table(), ['is_default' => 1], ['id' => (int) $row['id']], ['%d'], ['%d']);
    }

    /**
     * The customer removed a card: delete it at Revolut too, so it can never be charged again
     * (unless another row still uses it). A copy of the live store leaves Revolut alone.
     *
     * @param array<string,mixed> $row
     */
    public static function delete(array $row): bool
    {
        global $wpdb;
        if (!$wpdb->delete(self::table(), ['id' => (int) $row['id']], ['%d'])) {
            return false;
        }
        if (!empty($row['is_default'])) {
            $next = self::forUser((int) $row['user_id'], (string) $row['mode']);
            if ($next) {
                self::setDefault($next[0]);
            }
        }
        $method = (string) $row['method_id'];
        $customer = (string) $row['revolut_customer'];
        if ($method === '' || $customer === '' || !Settings::liveStore()
            || $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE method_id = %s LIMIT 1', self::table(), $method))) {
            return true;
        }
        $result = Settings::api((string) $row['mode'])->request('DELETE', 'customers/' . rawurlencode($customer) . '/payment-methods/' . rawurlencode($method));
        if (is_wp_error($result) && Api::status($result) !== 404) {
            Settings::log(sprintf('Could not delete the card of token %d at Revolut: %s', (int) $row['id'], $result->get_error_message()), 'warning');
        } else {
            Settings::log(sprintf('Deleted the card of token %d at Revolut.', (int) $row['id']));
        }
        return true;
    }

    public static function label(int $tokenId): string
    {
        $row = self::row($tokenId);
        return $row ? self::displayName($row) : __('a card', 'stackless-lite-edd');
    }

    /** @param array<string,mixed> $row */
    public static function displayName(array $row): string
    {
        $brands = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'american_express' => 'American Express'];
        $brand = (string) ($row['brand'] ?? '');
        $label = $brands[$brand] ?? ($brand !== '' ? ucwords(str_replace('_', ' ', $brand)) : __('Card', 'stackless-lite-edd'));
        $last4 = (string) ($row['last4'] ?? '');
        if ($last4 !== '') {
            /* translators: 1: card brand, 2: last four digits */
            $label = sprintf(__('%1$s ending in %2$s', 'stackless-lite-edd'), $label, $last4);
        }
        $expires = self::expires($row);
        if ($expires !== '') {
            /* translators: %s: expiry date, MM/YY */
            $label .= ' ' . sprintf(__('(expires %s)', 'stackless-lite-edd'), substr($expires, 5) . '/' . substr($expires, 2, 2));
        }
        return $label;
    }

    /**
     * Y-m, or ''.
     *
     * @param array<string,mixed> $row
     */
    public static function expires(array $row): string
    {
        $year = (string) ($row['exp_year'] ?? '');
        $month = (string) ($row['exp_month'] ?? '');
        return strlen($year) === 4 && strlen($month) === 2 ? $year . '-' . $month : '';
    }

    /**
     * Expired cards stay listed but are flagged, and Subscriptions skips them.
     *
     * @param array<string,mixed> $row
     */
    public static function expired(array $row): bool
    {
        $expires = self::expires($row);
        return $expires !== '' && $expires < gmdate('Y-m');
    }

    /**
     * Cross-module listing (filter stackless_edd_saved_payment_methods).
     *
     * @param mixed $methods
     * @param mixed $userId
     * @param mixed $gatewayId '' for every gateway
     * @return array<int,array<string,mixed>>
     */
    public static function filterSavedMethods($methods, $userId = 0, $gatewayId = ''): array
    {
        $methods = is_array($methods) ? $methods : [];
        if ((string) $gatewayId !== '' && (string) $gatewayId !== Keys::GATEWAY_ID) {
            return $methods;
        }
        foreach (self::forUser((int) $userId) as $row) {
            $methods[] = [
                'id' => (string) $row['id'],
                'gateway' => Keys::GATEWAY_ID,
                'label' => self::displayName($row),
                'type' => 'card',
                'expired' => self::expired($row),
                'expires' => self::expires($row),
                'default' => !empty($row['is_default']),
                'is_default' => !empty($row['is_default']),
                'mode' => (string) $row['mode'],
            ];
        }
        return $methods;
    }

    /** @param mixed $userId */
    public static function onUserDeleted($userId): void
    {
        global $wpdb;
        $userId = (int) $userId;
        if ($userId > 0) {
            $wpdb->delete(self::table(), ['user_id' => $userId], ['%d']);
        }
    }
}
