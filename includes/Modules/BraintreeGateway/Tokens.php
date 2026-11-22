<?php

namespace StacklessEDD\Modules\BraintreeGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved cards live in a custom table and change at checkout and on the account page, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Vaulted cards: {$wpdb->prefix}stackless_edd_braintree_tokens.
 *
 * One row per Braintree payment method per WordPress user (0 = guest), bound to a mode, a
 * Braintree merchant and a Braintree customer. The row id is what SubscriptionData::META_TOKEN_ID
 * holds on orders and subscriptions.
 */
final class Tokens
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_braintree_tokens';
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
  remote_id varchar(191) NOT NULL DEFAULT '',
  legacy_id varchar(64) NOT NULL DEFAULT '',
  braintree_customer varchar(191) NOT NULL DEFAULT '',
  account varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  brand varchar(50) NOT NULL DEFAULT 'card',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY remote_id (remote_id)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): Card
    {
        $card = new Card();
        $card->id = (int) ($row['id'] ?? 0);
        $card->user = (int) ($row['user_id'] ?? 0);
        $card->remote = (string) ($row['remote_id'] ?? '');
        $card->legacy = (string) ($row['legacy_id'] ?? '');
        $card->customer = (string) ($row['braintree_customer'] ?? '');
        $card->account = (string) ($row['account'] ?? '');
        $card->mode = ($row['mode'] ?? '') === 'live' ? 'live' : 'test';
        $card->brand = (string) ($row['brand'] ?? 'card');
        $card->last4 = (string) ($row['last4'] ?? '');
        $card->month = (string) ($row['exp_month'] ?? '');
        $card->year = (string) ($row['exp_year'] ?? '');
        $card->default = !empty($row['is_default']);
        return $card;
    }

    public static function save(Card $card): ?Card
    {
        global $wpdb;
        if ($card->remote === '') {
            return null;
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE remote_id = %s AND user_id = %d', self::table(), $card->remote, $card->user), ARRAY_A);
        foreach ($rows as $row) {
            if (is_array($row) && (string) $row['remote_id'] === $card->remote) {
                return self::fromRow($row);
            }
        }
        $card->default = $card->user > 0 && !self::defaultFor($card->user);
        $inserted = $wpdb->insert(self::table(), [
            'user_id' => $card->user,
            'remote_id' => $card->remote,
            'legacy_id' => $card->legacy,
            'braintree_customer' => $card->customer,
            'account' => $card->account,
            'mode' => $card->mode,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'exp_month' => $card->month,
            'exp_year' => $card->year,
            'is_default' => $card->default ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$inserted) {
            Config::log('Could not store a Braintree card.', 'error');
            return null;
        }
        $card->id = (int) $wpdb->insert_id;

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it to a
         * subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Gateway::ID, (string) $card->id, $card->user);
        return $card;
    }

    public static function get(int $token_id): ?Card
    {
        global $wpdb;
        if ($token_id < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $token_id), ARRAY_A);
        return is_array($row) ? self::fromRow($row) : null;
    }

    /** Token owned by a signed-in user, or null. */
    public static function getOwned(int $token_id, int $user_id): ?Card
    {
        $card = $user_id > 0 ? self::get($token_id) : null;
        return $card && $card->user === $user_id ? $card : null;
    }

    public static function inUse(string $remote): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE remote_id = %s', self::table(), $remote));
    }

    /**
     * A user's cards that can be charged now: this mode, this Braintree merchant. Default first.
     *
     * @return Card[]
     */
    public static function forUser(int $user_id): array
    {
        global $wpdb;
        if ($user_id < 1) {
            return [];
        }
        $mode = Config::mode();
        $connection = Connection::cached($mode);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY is_default DESC, id DESC', self::table(), $user_id, $mode), ARRAY_A);
        $cards = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && (!$connection || (string) ($row['account'] ?? '') === $connection['account'])) {
                $cards[] = self::fromRow($row);
            }
        }
        return $cards;
    }

    public static function defaultFor(int $user_id): ?Card
    {
        foreach (self::forUser($user_id) as $card) {
            if ($card->default) {
                return $card;
            }
        }
        return null;
    }

    public static function setDefault(Card $card): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_default' => 0], ['user_id' => $card->user, 'mode' => $card->mode], ['%d'], ['%d', '%s']);
        $wpdb->update(self::table(), ['is_default' => 1], ['id' => $card->id], ['%d'], ['%d']);
    }

    /** Remove a saved card, and delete it from the vault so it is never charged again. */
    public static function delete(Card $card): bool
    {
        global $wpdb;
        if (!$wpdb->delete(self::table(), ['id' => $card->id], ['%d'])) {
            return false;
        }
        if ($card->default && $card->user > 0) {
            $next = self::forUser($card->user);
            if ($next) {
                self::setDefault($next[0]);
            }
        }
        Vault::deleteRemote($card);
        return true;
    }

    /** Remove every card of a user, whatever its mode. Returns how many. */
    public static function deleteAll(int $user_id): int
    {
        global $wpdb;
        if ($user_id < 1) {
            return 0;
        }
        $count = 0;
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d', self::table(), $user_id), ARRAY_A) as $row) {
            if (is_array($row) && self::delete(self::fromRow($row))) {
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
        foreach (self::forUser((int) $user_id) as $card) {
            $methods[] = [
                'id' => (string) $card->id,
                'gateway' => Gateway::ID,
                'label' => $card->label(),
                'type' => 'card',
                'expired' => self::expired($card),
                'expires' => $card->month !== '' && $card->year !== '' ? $card->year . '-' . $card->month : '',
                'default' => $card->default,
                'is_default' => $card->default,
                'mode' => $card->mode,
            ];
        }
        return $methods;
    }

    public static function expired(Card $card): bool
    {
        return $card->month !== '' && $card->year !== '' && strcmp($card->year . $card->month, gmdate('Ym')) < 0;
    }

    /** @param mixed $user_id */
    public static function onUserDeleted($user_id): void
    {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id > 0) {
            $wpdb->delete(self::table(), ['user_id' => $user_id], ['%d']);
        }
    }
}
