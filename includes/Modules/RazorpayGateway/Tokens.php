<?php

namespace StacklessEDD\Modules\RazorpayGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved mandates live in a custom table and change at checkout, on the account page and from webhooks, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Saved Razorpay recurring tokens: {$wpdb->prefix}stackless_edd_razorpay_tokens.
 *
 * EDD has no payment token store, so this table replaces the WooCommerce payment tokens the
 * WooCommerce edition used. One row per Razorpay token per WordPress user (0 = guest). The row
 * id is what SubscriptionData::META_TOKEN_ID holds on orders and subscriptions.
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
        return $wpdb->prefix . 'stackless_edd_razorpay_tokens';
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
  token varchar(191) NOT NULL DEFAULT '',
  rzp_customer varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  method varchar(20) NOT NULL DEFAULT 'card',
  brand varchar(50) NOT NULL DEFAULT '',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  label varchar(191) NOT NULL DEFAULT '',
  max_amount bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'confirmed',
  email varchar(191) NOT NULL DEFAULT '',
  contact varchar(20) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY customer_id (customer_id),
  KEY token (token)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /**
     * Save (or refresh) the row for a Razorpay token entity.
     *
     * @param array<string,mixed> $entity  Razorpay token (GET customers/{id}/tokens/{id}).
     * @param array<string,mixed> $payment The authorization payment (email and contact for later debits).
     */
    public static function saveFromEntity(array $entity, int $userId, string $rzpCustomer, string $mode, array $payment = [], int $eddCustomerId = 0): ?PaymentToken
    {
        global $wpdb;
        $tokenId = (string) ($entity['id'] ?? '');
        if (strpos($tokenId, 'token_') !== 0 || $rzpCustomer === '') {
            return null;
        }
        $userId = max(0, $userId);
        $existing = self::find($tokenId, $userId);
        if ($existing) {
            self::updateStatus($tokenId, self::statusOf($entity));
            return self::get($existing[0]->id);
        }

        $method = (string) ($entity['method'] ?? '');
        if ($method === 'nach') {
            $method = 'emandate';
        }
        $method = in_array($method, Mandate::METHODS, true) ? $method : 'upi';
        $token = new PaymentToken();
        if ($method === 'card' && is_array($entity['card'] ?? null)) {
            $card = $entity['card'];
            $token->brand = self::brand((string) ($card['network'] ?? ''));
            $token->last4 = self::last4((string) ($card['last4'] ?? ''));
            $month = (int) ($card['expiry_month'] ?? 12);
            $year = (int) ($card['expiry_year'] ?? ((int) gmdate('Y') + 5));
            $token->exp_month = str_pad((string) max(1, min(12, $month)), 2, '0', STR_PAD_LEFT);
            $token->exp_year = (string) ($year < 100 ? 2000 + $year : $year);
        }
        $token->label = self::label($method, $entity);
        $token->token = $tokenId;
        $token->user_id = $userId;
        $token->customer_id = $eddCustomerId > 0 ? $eddCustomerId : self::eddCustomerFor($userId);
        $token->rzp_customer = $rzpCustomer;
        $token->mode = $mode === 'live' ? 'live' : 'test';
        $token->method = $method;
        $token->max_amount = max(0, (int) ($entity['max_amount'] ?? 0));
        $token->status = self::statusOf($entity);
        $token->email = sanitize_email((string) ($payment['email'] ?? ''));
        $token->contact = substr((string) preg_replace('/[^0-9+]/', '', (string) ($payment['contact'] ?? '')), 0, 20);
        $token->is_default = $userId > 0 && !self::defaultFor($userId, $token->mode);
        $token->created_at = gmdate('Y-m-d H:i:s');

        if (!$wpdb->insert(self::table(), $token->toRow())) {
            Settings::log(sprintf('Could not save Razorpay token %s.', $tokenId), 'error');
            return null;
        }
        $token->id = (int) $wpdb->insert_id;
        self::$cache[$token->id] = $token;
        Settings::log(sprintf('Saved Razorpay token row %d for %s (user %d).', $token->id, $tokenId, $userId));

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it
         * to a subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Settings::GATEWAY_ID, (string) $token->id, $token->user_id);
        return $token;
    }

    /**
     * initiated|confirmed|paused|rejected|cancelled from a token entity.
     *
     * @param array<string,mixed> $entity
     */
    public static function statusOf(array $entity): string
    {
        $status = (string) ($entity['recurring_details']['status'] ?? '');
        if ($status === '' && isset($entity['recurring']) && $entity['recurring'] === true) {
            $status = 'confirmed';
        }
        return $status !== '' ? substr(sanitize_key($status), 0, 20) : 'initiated';
    }

    public static function get(int $id): ?PaymentToken
    {
        global $wpdb;
        if ($id < 1) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
            self::$cache[$id] = is_array($row) ? PaymentToken::fromRow($row) : null;
        }
        return self::$cache[$id];
    }

    /** Token row owned by $userId (guests own nothing that can be charged on its own), or null. */
    public static function getOwned(int $id, int $userId): ?PaymentToken
    {
        $token = self::get($id);
        return $token && $userId > 0 && $token->user_id === $userId ? $token : null;
    }

    /**
     * Every row for a Razorpay token id, optionally of one user.
     *
     * @return PaymentToken[]
     */
    public static function find(string $tokenId, int $userId = -1): array
    {
        global $wpdb;
        if ($tokenId === '') {
            return [];
        }
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE token = %s AND user_id = %d', self::table(), $tokenId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE token = %s', self::table(), $tokenId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = self::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /**
     * A user's rows in one mode (default: the current one), default first. Mandates made in the
     * other mode are left out: a test mandate cannot pay a live order.
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

    public static function updateStatus(string $tokenId, string $status): int
    {
        global $wpdb;
        $status = substr(sanitize_key($status), 0, 20);
        $count = 0;
        foreach (self::find($tokenId) as $token) {
            if ($token->status !== $status && $wpdb->update(self::table(), ['status' => $status], ['id' => $token->id], ['%s'], ['%d'])) {
                $count++;
            }
            unset(self::$cache[$token->id]);
        }
        return $count;
    }

    /**
     * Remove a saved method. A customer removing it from their account also cancels the mandate
     * at Razorpay, so it is never debited again (unless another row still uses it).
     */
    public static function delete(PaymentToken $token, bool $cancel = true): bool
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
        if ($cancel) {
            self::cancelRemote($token);
        }
        return true;
    }

    /** Delete every row of a Razorpay token without calling Razorpay (it is gone there). */
    public static function forget(string $tokenId): int
    {
        $count = 0;
        foreach (self::find($tokenId) as $token) {
            if (self::delete($token, false)) {
                $count++;
            }
        }
        return $count;
    }

    /** UPI Autopay has its own cancel call; card and eNACH tokens are deleted. */
    private static function cancelRemote(PaymentToken $token): void
    {
        if ($token->token === '' || $token->rzp_customer === '' || self::find($token->token)) {
            return; // Another row still uses it.
        }
        $api = Settings::api($token->mode);
        $path = 'customers/' . rawurlencode($token->rzp_customer) . '/tokens/' . rawurlencode($token->token);
        $result = $token->method === 'upi' ? $api->request('PUT', $path . '/cancel') : $api->request('DELETE', $path);
        if (is_wp_error($result) && Api::status($result) !== 404) {
            Settings::log(sprintf('Could not cancel token %s after row %d was deleted: %s', $token->token, $token->id, $result->get_error_message()), 'warning');
            return;
        }
        Settings::log(sprintf('Cancelled token %s after row %d was deleted.', $token->token, $token->id));
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
            if (in_array($token->status, ['rejected', 'cancelled'], true)) {
                continue;
            }
            $methods[] = [
                'id' => (string) $token->id,
                'gateway' => Settings::GATEWAY_ID,
                'label' => $token->displayNameWithStatus(),
                'type' => $token->method,
                'expired' => $token->isExpired(),
                'default' => $token->is_default,
                'is_default' => $token->is_default,
                'expires' => $token->isCard() && $token->exp_year !== '' ? $token->exp_year . '-' . $token->exp_month : '',
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

    /** @param array<string,mixed> $entity */
    public static function label(string $method, array $entity): string
    {
        if ($method === 'card') {
            return Mandate::label('card');
        }
        if ($method === 'upi') {
            $vpa = is_array($entity['vpa'] ?? null) ? $entity['vpa'] : [];
            $address = trim((string) ($vpa['username'] ?? '') . (isset($vpa['handle']) ? '@' . $vpa['handle'] : ''), '@');
            return Mandate::label('upi') . ($address !== '' ? ' (' . sanitize_text_field($address) . ')' : '');
        }
        $bank = sanitize_text_field((string) ($entity['bank'] ?? ''));
        $account = (string) ($entity['bank_details']['account_number'] ?? '');
        $tail = $account !== '' ? ' ••' . self::last4($account) : '';
        return Mandate::label('emandate') . ($bank !== '' || $tail !== '' ? ' (' . trim($bank . $tail) . ')' : '');
    }

    public static function brand(string $network): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $network));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'mastercard' => 'mastercard', 'visa' => 'visa', 'rupay' => 'rupay', 'unknown' => 'card'];
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
