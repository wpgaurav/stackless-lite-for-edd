<?php

namespace StacklessEDD\Modules\SquareGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved cards live in a custom table and change at checkout and on the account page, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Square cards on file: {$wpdb->prefix}stackless_edd_square_tokens.
 *
 * EDD has no payment token store, so this table replaces the WooCommerce card tokens the
 * WooCommerce edition used. One row per Square card per WordPress user (0 = guest), recording the
 * Square mode, the Square customer the card belongs to and the display details. The row id is
 * what SubscriptionData::META_TOKEN_ID holds on orders and subscriptions.
 *
 * Other modules list a user's cards with the stackless_edd_saved_payment_methods filter and
 * learn about new ones from the stackless_edd_payment_method_saved action.
 */
final class Tokens
{
    /** @var array<int,PaymentToken|null> */
    private static $cache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_square_tokens';
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
  card_id varchar(191) NOT NULL DEFAULT '',
  square_customer varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  brand varchar(50) NOT NULL DEFAULT 'card',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY card_id (card_id)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /**
     * Save (or find) the token for a Square card object.
     *
     * @param array<string,mixed> $card
     */
    public static function saveFromCard(array $card, int $user_id, string $mode, int $edd_customer_id = 0): ?PaymentToken
    {
        global $wpdb;
        $card_id = (string) ($card['id'] ?? '');
        $customer = (string) ($card['customer_id'] ?? '');
        if ($card_id === '' || $customer === '' || (isset($card['enabled']) && $card['enabled'] === false)) {
            return null;
        }
        $user_id = max(0, $user_id);
        $existing = self::find($card_id, $user_id);
        if ($existing) {
            return $existing[0];
        }
        $token = new PaymentToken();
        $token->brand = self::brand((string) ($card['card_brand'] ?? ''));
        $token->last4 = self::last4((string) ($card['last_4'] ?? ''));
        $token->exp_month = str_pad((string) max(1, min(12, (int) ($card['exp_month'] ?? 12))), 2, '0', STR_PAD_LEFT);
        $token->exp_year = (string) ((int) ($card['exp_year'] ?? ((int) gmdate('Y') + 5)));
        $token->card_id = $card_id;
        $token->square_customer = $customer;
        $token->user_id = $user_id;
        $token->customer_id = $edd_customer_id > 0 ? $edd_customer_id : self::eddCustomerFor($user_id);
        $token->mode = $mode === 'live' ? 'live' : 'test';
        $token->is_default = $user_id > 0 && !self::defaultFor($user_id, $token->mode);
        $token->created_at = gmdate('Y-m-d H:i:s');

        if (!$wpdb->insert(self::table(), $token->toRow())) {
            Gateway::instance()->log('Could not store a Square card.', 'error');
            return null;
        }
        $token->id = (int) $wpdb->insert_id;
        self::$cache[$token->id] = $token;

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it to a
         * subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Gateway::ID, (string) $token->id, $token->user_id);
        return $token;
    }

    /**
     * Refresh expiry and last four after Square's account updater changed a card.
     *
     * @param array<string,mixed> $card
     */
    public static function updateFromCard(array $card): int
    {
        global $wpdb;
        $count = 0;
        foreach (self::find((string) ($card['id'] ?? '')) as $token) {
            $data = [];
            if (!empty($card['exp_month'])) {
                $data['exp_month'] = str_pad((string) (int) $card['exp_month'], 2, '0', STR_PAD_LEFT);
            }
            if (!empty($card['exp_year'])) {
                $data['exp_year'] = (string) (int) $card['exp_year'];
            }
            if (!empty($card['last_4'])) {
                $data['last4'] = self::last4((string) $card['last_4']);
            }
            if (!empty($card['card_brand'])) {
                $data['brand'] = self::brand((string) $card['card_brand']);
            }
            if ($data && $wpdb->update(self::table(), $data, ['id' => $token->id]) !== false) {
                unset(self::$cache[$token->id]);
                $count++;
            }
        }
        return $count;
    }

    public static function get(int $token_id): ?PaymentToken
    {
        global $wpdb;
        if ($token_id < 1) {
            return null;
        }
        if (!array_key_exists($token_id, self::$cache)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $token_id), ARRAY_A);
            self::$cache[$token_id] = is_array($row) ? PaymentToken::fromRow($row) : null;
        }
        return self::$cache[$token_id];
    }

    /** @return PaymentToken[] rows for a Square card, of one user (0 = guest) or of everyone (-1) */
    public static function find(string $card_id, int $user_id = -1): array
    {
        global $wpdb;
        if ($card_id === '') {
            return [];
        }
        $ids = $user_id >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE card_id = %s AND user_id = %d', self::table(), $card_id, $user_id))
            : $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE card_id = %s', self::table(), $card_id));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = self::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /** Token owned by $user_id (0 = guest-owned), or null. */
    public static function getOwned(int $token_id, int $user_id): ?PaymentToken
    {
        $token = self::get($token_id);
        return $token && $token->user_id === $user_id ? $token : null;
    }

    /**
     * A user's cards in one Square mode, default first. Cards from the other mode are left out: a
     * sandbox card cannot pay a production order.
     *
     * @return PaymentToken[]
     */
    public static function forUser(int $user_id, ?string $mode = null): array
    {
        global $wpdb;
        if ($user_id < 1) {
            return [];
        }
        $mode = $mode ?? Gateway::instance()->mode();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY is_default DESC, id DESC', self::table(), $user_id, $mode), ARRAY_A);
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

    public static function defaultFor(int $user_id, ?string $mode = null): ?PaymentToken
    {
        foreach (self::forUser($user_id, $mode) as $token) {
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
     * Remove a saved card. A customer removing it from their account also disables it at Square so
     * it is never charged again (unless another row still uses it).
     */
    public static function delete(PaymentToken $token, bool $disable = true): bool
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
        if ($disable) {
            self::disable($token);
        }
        return true;
    }

    private static function disable(PaymentToken $token): void
    {
        if ($token->card_id === '' || self::find($token->card_id)) {
            return; // Another row still uses this card.
        }
        $gateway = Gateway::instance();
        $result = $gateway->api($token->mode)->request('POST', 'cards/' . rawurlencode($token->card_id) . '/disable');
        if (is_wp_error($result) && Api::status($result) !== 404) {
            $gateway->log(sprintf('Could not disable card %s after token %d was deleted: %s', $token->card_id, $token->id, $result->get_error_message()), 'warning');
        }
    }

    /** Square disabled or forgot a card: delete its rows without calling Square back. */
    public static function forget(string $card_id): int
    {
        $count = 0;
        foreach (self::find($card_id) as $token) {
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
     * @param mixed $user_id
     * @param mixed $gateway_id '' for every gateway
     * @return array<int,array<string,mixed>>
     */
    public static function filterSavedMethods($methods, $user_id = 0, $gateway_id = '')
    {
        $methods = is_array($methods) ? $methods : [];
        if ((string) $gateway_id !== '' && (string) $gateway_id !== Gateway::ID) {
            return $methods;
        }
        foreach (self::forUser((int) $user_id) as $token) {
            $methods[] = [
                'id' => (string) $token->id,
                'gateway' => Gateway::ID,
                'label' => $token->displayName(),
                'type' => 'card',
                'expired' => $token->isExpired(),
                'expires' => $token->expires(),
                'default' => $token->is_default,
                'is_default' => $token->is_default,
                'mode' => $token->mode,
            ];
        }
        return $methods;
    }

    /** @param mixed $user_id */
    public static function onUserDeleted($user_id): void
    {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id > 0) {
            $wpdb->delete(self::table(), ['user_id' => $user_id], ['%d']);
            self::$cache = [];
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function brand(string $brand): string
    {
        $map = [
            'VISA' => 'visa',
            'MASTERCARD' => 'mastercard',
            'AMERICAN_EXPRESS' => 'amex',
            'DISCOVER' => 'discover',
            'DISCOVER_DINERS' => 'diners',
            'JCB' => 'jcb',
            'CHINA_UNIONPAY' => 'unionpay',
            'INTERAC' => 'interac',
            'EFTPOS' => 'eftpos',
            'FELICA' => 'felica',
        ];
        return $map[strtoupper($brand)] ?? 'card';
    }

    public static function last4(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);
    }

    private static function eddCustomerFor(int $user_id): int
    {
        if ($user_id < 1 || !function_exists('edd_get_customer_by')) {
            return 0;
        }
        $customer = edd_get_customer_by('user_id', (string) $user_id);
        return $customer ? (int) $customer->id : 0;
    }
}
