<?php

namespace StacklessEDD\Modules\MollieGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved mandates live in a custom table and change at checkout and on the account page, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Saved Mollie mandates: {$wpdb->prefix}stackless_edd_mollie_tokens.
 *
 * EDD has no payment token store, so this table replaces the WooCommerce payment tokens the
 * WooCommerce edition used. One row per mandate per WordPress user (0 = guest), recording the
 * Mollie mode, the Mollie customer the mandate belongs to, the mandate method and the display
 * details. The row id is what SubscriptionData::META_TOKEN_ID holds on orders and subscriptions.
 *
 * Other modules list a user's methods with the stackless_edd_saved_payment_methods filter and
 * learn about new ones from the stackless_edd_payment_method_saved action.
 */
final class Tokens
{
    /** @var array<int,PaymentToken|null> */
    private static $cache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_mollie_tokens';
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
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  mandate_id varchar(191) NOT NULL DEFAULT '',
  mollie_customer varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  method varchar(50) NOT NULL DEFAULT 'creditcard',
  brand varchar(50) NOT NULL DEFAULT '',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  label varchar(191) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY mandate_id (mandate_id)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /**
     * Saves (or finds) the token for a Mollie mandate object.
     *
     * @param array<string,mixed> $mandate Mollie mandate (GET customers/{id}/mandates/{id}).
     */
    public static function saveFromMandate(array $mandate, int $userId, string $customerId, string $mode, int $eddCustomerId = 0): ?PaymentToken
    {
        global $wpdb;
        $mandateId = (string) ($mandate['id'] ?? '');
        if (strpos($mandateId, 'mdt_') !== 0 || $customerId === '') {
            return null;
        }
        $userId = max(0, $userId);
        $existing = self::find($mandateId, $userId);
        if ($existing) {
            return $existing[0];
        }

        $method = (string) ($mandate['method'] ?? '');
        $details = is_array($mandate['details'] ?? null) ? $mandate['details'] : [];
        $token = new PaymentToken();
        if ($method === 'creditcard') {
            $token->brand = self::brand((string) ($details['cardLabel'] ?? ''));
            $token->last4 = self::last4((string) ($details['cardNumber'] ?? ''));
            $expiry = (string) ($details['cardExpiryDate'] ?? '');
            $time = $expiry !== '' ? strtotime($expiry) : false;
            $token->exp_month = $time ? gmdate('m', $time) : '12';
            $token->exp_year = $time ? gmdate('Y', $time) : (string) ((int) gmdate('Y') + 5);
        } elseif ($method === 'directdebit') {
            $token->last4 = self::last4((string) ($details['consumerAccount'] ?? ''));
        } else {
            $method = $method !== '' ? $method : 'paypal';
            $account = sanitize_text_field((string) ($details['consumerAccount'] ?? ''));
            $token->label = Methods::label($method) . ($account !== '' ? ' (' . $account . ')' : '');
        }
        $token->token = $mandateId;
        $token->method = $method;
        $token->user_id = $userId;
        $token->customer_id = $eddCustomerId > 0 ? $eddCustomerId : self::eddCustomerFor($userId);
        $token->mollie_customer = $customerId;
        $token->mode = $mode === 'live' ? 'live' : 'test';
        $token->is_default = $userId > 0 && !self::defaultFor($userId, $token->mode);
        $token->created_at = gmdate('Y-m-d H:i:s');

        if (!$wpdb->insert(self::table(), $token->toRow())) {
            Settings::log(sprintf('Could not save a payment token for mandate %s.', $mandateId), 'error');
            return null;
        }
        $token->id = (int) $wpdb->insert_id;
        self::$cache[$token->id] = $token;
        Settings::log(sprintf('Saved payment token %d for mandate %s (user %d).', $token->id, $mandateId, $userId));

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it to
         * a subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Settings::GATEWAY_ID, (string) $token->id, $token->user_id);
        return $token;
    }

    public static function get(int $tokenId): ?PaymentToken
    {
        global $wpdb;
        if ($tokenId < 1) {
            return null;
        }
        if (!array_key_exists($tokenId, self::$cache)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $tokenId), ARRAY_A);
            self::$cache[$tokenId] = is_array($row) ? PaymentToken::fromRow($row) : null;
        }
        return self::$cache[$tokenId];
    }

    /** @return PaymentToken[] rows for a mandate, of one user (>= 0) or of everyone (-1) */
    public static function find(string $mandateId, int $userId = -1): array
    {
        global $wpdb;
        if ($mandateId === '') {
            return [];
        }
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE mandate_id = %s AND user_id = %d', self::table(), $mandateId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE mandate_id = %s', self::table(), $mandateId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = self::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /** Token owned by $userId (0 = guest-owned), or null. */
    public static function getOwned(int $tokenId, int $userId): ?PaymentToken
    {
        $token = self::get($tokenId);
        return $token && $token->user_id === $userId ? $token : null;
    }

    /**
     * A user's tokens, default first. Mandates of the other Mollie mode are left out: a test
     * mandate cannot pay a live order.
     *
     * @return PaymentToken[]
     */
    public static function forUser(int $userId, ?string $mode = null): array
    {
        global $wpdb;
        if ($userId < 1) {
            return [];
        }
        $mode = $mode ?? Settings::mode();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY is_default DESC, id DESC', self::table(), $userId, $mode), ARRAY_A);
        $tokens = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $token = PaymentToken::fromRow($row);
                self::$cache[$token->id] = $token;
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    public static function defaultFor(int $userId, ?string $mode = null): ?PaymentToken
    {
        foreach (self::forUser($userId, $mode) as $token) {
            if ($token->is_default) {
                return $token;
            }
        }
        return null;
    }

    public static function setDefault(PaymentToken $token): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_default' => 0], ['user_id' => $token->user_id, 'mode' => $token->mode], ['%d'], ['%d', '%s']);
        $wpdb->update(self::table(), ['is_default' => 1], ['id' => $token->id], ['%d'], ['%d']);
        self::$cache = [];
    }

    /**
     * Remove a saved method. A customer removing it from their account also revokes the mandate
     * at Mollie, so it can never be charged again (unless another row still uses it).
     */
    public static function delete(PaymentToken $token, bool $revoke = true): bool
    {
        global $wpdb;
        $deleted = (bool) $wpdb->delete(self::table(), ['id' => $token->id], ['%d']);
        unset(self::$cache[$token->id]);
        if (!$deleted) {
            return false;
        }
        if ($token->is_default && $token->user_id > 0) {
            $next = self::forUser($token->user_id, $token->mode);
            if ($next) {
                self::setDefault($next[0]);
            }
        }
        if ($revoke) {
            self::revoke($token);
        }
        return true;
    }

    private static function revoke(PaymentToken $token): void
    {
        if ($token->token === '' || $token->mollie_customer === '' || self::find($token->token)) {
            return; // Another token row still uses this mandate.
        }
        $result = Settings::api($token->mode)->request('DELETE', 'customers/' . rawurlencode($token->mollie_customer) . '/mandates/' . rawurlencode($token->token));
        if (is_wp_error($result) && Api::status($result) !== 404) {
            Settings::log(sprintf('Could not revoke mandate %s after token %d was deleted: %s', $token->token, $token->id, $result->get_error_message()), 'warning');
            return;
        }
        Settings::log(sprintf('Revoked mandate %s after token %d was deleted.', $token->token, $token->id));
    }

    /** Delete every token of a mandate without calling Mollie (it is already gone there). */
    public static function forget(string $mandateId): int
    {
        $count = 0;
        foreach (self::find($mandateId) as $token) {
            if (self::delete($token, false)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cross-module listing (filter stackless_edd_saved_payment_methods).
     *
     * @param mixed $methods
     * @param mixed $userId
     * @param mixed $gatewayId '' for every gateway
     * @return array<int,array<string,mixed>>
     */
    public static function filterSavedMethods($methods, $userId = 0, $gatewayId = '')
    {
        $methods = is_array($methods) ? $methods : [];
        if ((string) $gatewayId !== '' && (string) $gatewayId !== Settings::GATEWAY_ID) {
            return $methods;
        }
        foreach (self::forUser((int) $userId) as $token) {
            $methods[] = [
                'id' => (string) $token->id,
                'gateway' => Settings::GATEWAY_ID,
                'label' => $token->displayName(),
                'type' => $token->method,
                'expired' => $token->isExpired(),
                'expires' => $token->exp_year !== '' && $token->exp_month !== '' ? $token->exp_year . '-' . $token->exp_month : '',
                'default' => $token->is_default,
                'is_default' => $token->is_default,
                'mode' => $token->mode,
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
            self::$cache = [];
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function brand(string $label): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $label));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'carte-bancaire' => 'cartes_bancaires', 'cartes-bancaires' => 'cartes_bancaires'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    public static function last4(string $value): string
    {
        $digits = preg_replace('/[^0-9A-Za-z]/', '', $value);
        return str_pad(substr((string) $digits, -4), 4, '0', STR_PAD_LEFT);
    }

    private static function eddCustomerFor(int $userId): int
    {
        if ($userId < 1 || !function_exists('edd_get_customer_by')) {
            return 0;
        }
        $customer = edd_get_customer_by('user_id', (string) $userId);
        return $customer ? (int) $customer->id : 0;
    }
}
