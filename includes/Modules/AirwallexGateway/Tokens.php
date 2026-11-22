<?php

namespace StacklessEDD\Modules\AirwallexGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved consents live in a custom table and change at checkout and on the account page, so they are read fresh.

use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Merchant-triggered card PaymentConsents: {$wpdb->prefix}stackless_edd_airwallex_tokens.
 *
 * EDD has no payment token store, so this table replaces the WooCommerce payment tokens of the
 * WooCommerce edition. One row per consent per WordPress user (0 = guest). The row id is what
 * SubscriptionData::META_TOKEN_ID holds on orders and subscriptions.
 *
 * Other modules list a user's methods with the stackless_edd_saved_payment_methods filter and
 * learn about new ones from the stackless_edd_payment_method_saved action.
 */
final class Tokens
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_airwallex_tokens';
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
  consent_id varchar(191) NOT NULL DEFAULT '',
  airwallex_customer varchar(191) NOT NULL DEFAULT '',
  payment_method_id varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  account_id varchar(191) NOT NULL DEFAULT '',
  brand varchar(50) NOT NULL DEFAULT '',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY consent_id (consent_id)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /**
     * Saves (or finds) the row for a consent that Sync read and checked.
     *
     * @param array $consent GET pa/payment_consents/{id}
     */
    public static function save(array $consent, int $userId, string $customerId, string $mode, string $account): ?SavedMethod
    {
        global $wpdb;
        $consentId = Api::id($consent['id'] ?? null);
        $method = is_array($consent['payment_method'] ?? null) ? $consent['payment_method'] : [];
        $methodId = Api::id($method['id'] ?? null);
        if ($consentId === '' || $methodId === '' || $customerId === '' || $account === '') {
            return null;
        }
        $userId = max(0, $userId);
        $existing = self::find($consentId, $userId);
        if ($existing) {
            return $existing[0];
        }

        $card = is_array($method['card'] ?? null) ? $method['card'] : [];
        $saved = new SavedMethod();
        $saved->user_id = $userId;
        $saved->consent = $consentId;
        $saved->customer = $customerId;
        $saved->method = $methodId;
        $saved->mode = $mode === 'live' ? 'live' : 'test';
        $saved->account = $account;
        $saved->brand = substr(strtolower(sanitize_text_field((string) ($card['brand'] ?? ''))), 0, 50);
        $saved->last4 = substr((string) preg_replace('/\D/', '', (string) ($card['last4'] ?? '')), -4);
        $month = (int) ($card['expiry_month'] ?? 0);
        $year = (int) ($card['expiry_year'] ?? 0);
        if ($month >= 1 && $month <= 12 && $year >= 2000 && $year <= 9999) {
            $saved->exp_month = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            $saved->exp_year = (string) $year;
        }
        $saved->is_default = $userId > 0 && !self::forUser($userId, $saved->mode, $account);
        $saved->created_at = gmdate('Y-m-d H:i:s');

        if (!$wpdb->insert(self::table(), $saved->toRow())) {
            Settings::log(sprintf('Could not save consent %s.', $consentId), 'error');
            return null;
        }
        $saved->id = (int) $wpdb->insert_id;
        Settings::log(sprintf('Saved card %d for consent %s (user %d).', $saved->id, $consentId, $userId));

        /**
         * A Stackless gateway saved a reusable payment method. Subscriptions listens to attach it to
         * a subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Settings::GATEWAY_ID, (string) $saved->id, $saved->user_id);
        return $saved;
    }

    public static function get(int $tokenId): ?SavedMethod
    {
        global $wpdb;
        if ($tokenId < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $tokenId), ARRAY_A);
        $saved = is_array($row) ? SavedMethod::fromRow($row) : null;
        return $saved && $saved->usable() ? $saved : null;
    }

    /** A card of this user, or null. */
    public static function getOwned(int $tokenId, int $userId): ?SavedMethod
    {
        $saved = self::get($tokenId);
        return $saved && $userId > 0 && $saved->user_id === $userId ? $saved : null;
    }

    /** @return SavedMethod[] rows of a consent, of one user (>= 0) or of everyone (-1) */
    public static function find(string $consentId, int $userId = -1): array
    {
        global $wpdb;
        if ($consentId === '') {
            return [];
        }
        $rows = $userId >= 0
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE consent_id = %s AND user_id = %d', self::table(), $consentId, $userId), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE consent_id = %s', self::table(), $consentId), ARRAY_A);
        return array_map([SavedMethod::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * A user's cards, default first. Cards of the other mode or another account are left out:
     * they cannot be charged there.
     *
     * @return SavedMethod[]
     */
    public static function forUser(int $userId, ?string $mode = null, ?string $account = null): array
    {
        global $wpdb;
        if ($userId < 1) {
            return [];
        }
        $mode = $mode ?? Settings::mode();
        $account = $account ?? Settings::account($mode);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND mode = %s AND account_id = %s ORDER BY is_default DESC, id DESC', self::table(), $userId, $mode, $account), ARRAY_A);
        return array_map([SavedMethod::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    public static function setDefault(SavedMethod $saved): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_default' => 0], ['user_id' => $saved->user_id, 'mode' => $saved->mode], ['%d'], ['%d', '%s']);
        $wpdb->update(self::table(), ['is_default' => 1], ['id' => $saved->id], ['%d'], ['%d']);
    }

    /**
     * Remove a saved card. Removing it also disables the consent at Airwallex, so it can never be
     * charged again (unless another row still uses it). A copy of the live store leaves the live
     * consent alone.
     */
    public static function delete(SavedMethod $saved, bool $revoke = true): bool
    {
        global $wpdb;
        if (!$wpdb->delete(self::table(), ['id' => $saved->id], ['%d'])) {
            return false;
        }
        if ($saved->is_default && $saved->user_id > 0) {
            $next = self::forUser($saved->user_id, $saved->mode, $saved->account);
            if ($next) {
                self::setDefault($next[0]);
            }
        }
        if ($revoke) {
            self::revoke($saved);
        }
        return true;
    }

    private static function revoke(SavedMethod $saved): void
    {
        if (!$saved->usable() || !LiveStore::isLive() || $saved->account !== Settings::account($saved->mode) || self::find($saved->consent)) {
            return; // Not chargeable from here, or another row still uses this consent.
        }
        $result = Settings::api($saved->mode)->request('POST', 'pa/payment_consents/' . rawurlencode($saved->consent) . '/disable', ['request_id' => Api::requestId()]);
        if (is_wp_error($result) && !Api::notFound($result)) {
            Settings::log(sprintf('Could not disable consent %s after card %d was deleted: %s', $saved->consent, $saved->id, $result->get_error_message()), 'warning');
            return;
        }
        Settings::log(sprintf('Disabled consent %s after card %d was deleted.', $saved->consent, $saved->id));
    }

    /** Delete every row of a consent without calling Airwallex (it is already disabled there). */
    public static function forget(string $consentId, string $mode): int
    {
        $count = 0;
        foreach (self::find($consentId) as $saved) {
            if ($saved->mode === $mode && self::delete($saved, false)) {
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
    public static function filterSavedMethods($methods, $userId = 0, $gatewayId = ''): array
    {
        $methods = is_array($methods) ? $methods : [];
        if ((string) $gatewayId !== '' && (string) $gatewayId !== Settings::GATEWAY_ID) {
            return $methods;
        }
        foreach (self::forUser((int) $userId) as $saved) {
            $methods[] = [
                'id' => (string) $saved->id,
                'gateway' => Settings::GATEWAY_ID,
                'label' => $saved->displayName(),
                'type' => 'card',
                'expired' => $saved->isExpired(),
                'expires' => $saved->exp_year !== '' && $saved->exp_month !== '' ? $saved->exp_year . '-' . $saved->exp_month : '',
                'default' => $saved->is_default,
                'is_default' => $saved->is_default,
                'mode' => $saved->mode,
            ];
        }
        return $methods;
    }

    /** Every card of a user, whatever its mode. */
    public static function deleteAll(int $userId): int
    {
        global $wpdb;
        if ($userId < 1) {
            return 0;
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d', self::table(), $userId), ARRAY_A);
        $count = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (self::delete(SavedMethod::fromRow($row))) {
                $count++;
            }
        }
        return $count;
    }

    /** @param mixed $userId */
    public static function onUserDeleted($userId): void
    {
        self::deleteAll((int) $userId);
    }
}
