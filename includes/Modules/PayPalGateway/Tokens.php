<?php

namespace StacklessEDD\Modules\PayPalGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved PayPal accounts live in a custom table and change at checkout and in My Account, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Saved PayPal accounts and cards (Vault v3 payment tokens) for merchant-initiated renewals:
 * the counterpart of the WooCommerce payment-token rows the Woo gateway used.
 *
 * One row per EDD customer and vault id. The row id is what goes into
 * SubscriptionData::META_TOKEN_ID on orders and subscriptions. A vault id only
 * works in the PayPal environment (live or sandbox) that created it. Since 2.0.0 a row
 * is either a PayPal account (type paypal) or a card vaulted at PayPal (type card, see
 * CardTokens), which renews with payment_source.card.vault_id.
 */
final class Tokens
{
    public const TABLE = 'stackless_edd_paypal_gateway_tokens';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            vault_id varchar(191) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            payer_id varchar(64) NOT NULL DEFAULT '',
            paypal_customer_id varchar(64) NOT NULL DEFAULT '',
            environment varchar(10) NOT NULL DEFAULT 'live',
            type varchar(10) NOT NULL DEFAULT 'paypal',
            card_brand varchar(32) NOT NULL DEFAULT '',
            card_last4 varchar(4) NOT NULL DEFAULT '',
            card_expiry varchar(7) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_vault (customer_id,vault_id),
            KEY vault_id (vault_id),
            KEY user_id (user_id)
        ) {$charset};");
    }

    /**
     * Store (or reuse) a saved account. Returns the token row id.
     *
     * @param array{type?:string,card_brand?:string,card_last4?:string,card_expiry?:string} $card card columns (CardTokens::save())
     */
    public static function save(int $customerId, int $userId, string $vaultId, string $email, string $payerId, string $paypalCustomerId, string $environment, array $card = []): int
    {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE customer_id = %d AND vault_id = %s", $table, $customerId, $vaultId));
        if ($existing > 0) {
            return $existing;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert($table, [
            'customer_id' => max(0, $customerId),
            'user_id' => max(0, $userId),
            'vault_id' => substr($vaultId, 0, 191),
            'email' => substr(sanitize_email($email), 0, 191),
            'payer_id' => substr(sanitize_text_field($payerId), 0, 64),
            'paypal_customer_id' => substr(sanitize_text_field($paypalCustomerId), 0, 64),
            'environment' => $environment === 'sandbox' ? 'sandbox' : 'live',
            'type' => ($card['type'] ?? '') === 'card' ? 'card' : 'paypal',
            'card_brand' => substr(sanitize_key((string) ($card['card_brand'] ?? '')), 0, 32),
            'card_last4' => substr((string) preg_replace('/\D/', '', (string) ($card['card_last4'] ?? '')), 0, 4),
            'card_expiry' => substr((string) preg_replace('/[^0-9-]/', '', (string) ($card['card_expiry'] ?? '')), 0, 7),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id > 0) {
            do_action('stackless_edd_paypal_gateway_token_saved', $id, $customerId, $userId);
        }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function forCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC", $table, $customerId), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Saved accounts of a WordPress user, newest first; '' environment = any.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId, string $environment = ''): array
    {
        if ($userId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC", $table, $userId), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        if ($environment === '') {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($row) use ($environment) {
            return (string) ($row['environment'] ?? '') === $environment;
        }));
    }

    /** Remove one saved account of a user. */
    public static function deleteForUser(int $id, int $userId): bool
    {
        if ($id <= 0 || $userId <= 0) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete(self::table(), ['id' => $id, 'user_id' => $userId]);
    }

    /** The buyer removed us in their PayPal account. @return int[] removed token ids */
    public static function deleteByVault(string $vaultId): array
    {
        if ($vaultId === '') {
            return [];
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE vault_id = %s", $table, $vaultId)));
        if ($ids) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, ['vault_id' => $vaultId]);
        }
        return $ids;
    }

    /** paypal|card: what a saved row pays with. Rows saved before 2.0 are PayPal accounts. */
    public static function type(array $token): string
    {
        return (string) ($token['type'] ?? '') === 'card' ? 'card' : 'paypal';
    }

    /** "PayPal (buyer@example.com)", or "Visa ending in 1111 (expires 12/2030)" for a card. */
    public static function displayName(array $token): string
    {
        if (self::type($token) === 'card') {
            return CardTokens::displayName($token);
        }
        $email = (string) ($token['email'] ?? '');
        /* translators: %s: PayPal account email */
        return $email !== '' ? sprintf(__('PayPal (%s)', 'stackless-lite-edd'), $email) : __('PayPal', 'stackless-lite-edd');
    }
}
