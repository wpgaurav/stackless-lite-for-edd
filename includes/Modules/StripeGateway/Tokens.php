<?php

namespace StacklessEDD\Modules\StripeGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- saved cards live in a custom table and change at checkout and in My Account, so they are read fresh.

defined('ABSPATH') || exit;

/**
 * Saved payment methods: {$wpdb->prefix}stackless_edd_stripe_tokens.
 *
 * EDD has no payment token store, so this table replaces the WooCommerce payment tokens the
 * WooCommerce edition used. One row per Stripe PaymentMethod per WordPress user (0 = guest),
 * recording the Stripe mode, the Stripe customer it is attached to and the display details.
 * The row id is what SubscriptionData::META_TOKEN_ID holds on orders and subscriptions.
 *
 * Other modules list a user's methods with the stackless_edd_saved_payment_methods filter and
 * learn about new ones from the stackless_edd_payment_method_saved action.
 */
final class Tokens
{
    /** Bank debit types that expose last4. */
    private const BANK_TYPES = ['sepa_debit', 'us_bank_account', 'bacs_debit', 'au_becs_debit', 'acss_debit', 'nz_bank_account'];

    /** @var array<int,PaymentToken|null> */
    private static $cache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_edd_stripe_tokens';
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
  payment_method varchar(191) NOT NULL DEFAULT '',
  stripe_customer varchar(191) NOT NULL DEFAULT '',
  mode varchar(10) NOT NULL DEFAULT 'test',
  type varchar(50) NOT NULL DEFAULT 'card',
  brand varchar(50) NOT NULL DEFAULT '',
  last4 varchar(4) NOT NULL DEFAULT '',
  exp_month varchar(2) NOT NULL DEFAULT '',
  exp_year varchar(4) NOT NULL DEFAULT '',
  label varchar(191) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY customer_id (customer_id),
  KEY payment_method (payment_method)
) {$charset};");
    }

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_saved_payment_methods', [self::class, 'filterSavedMethods'], 10, 3);
        add_action('delete_user', [self::class, 'onUserDeleted']);
    }

    /**
     * Saves (or finds) the token for a Stripe PaymentMethod object.
     *
     * @param array<string,mixed> $pm Stripe PaymentMethod object.
     */
    public static function saveFromPaymentMethod(array $pm, int $user_id, string $customer_id, string $mode, int $edd_customer_id = 0): ?PaymentToken
    {
        global $wpdb;
        $pm_id = (string) ($pm['id'] ?? '');
        if ($pm_id === '' || (strpos($pm_id, 'pm_') !== 0 && strpos($pm_id, 'card_') !== 0 && strpos($pm_id, 'src_') !== 0)) {
            return null;
        }

        $existing = self::findByPaymentMethod($pm_id, max(0, $user_id));
        if ($existing) {
            return $existing[0];
        }

        $token = new PaymentToken();
        $type = (string) ($pm['type'] ?? 'card');
        if ($type === 'card' && isset($pm['card']) && is_array($pm['card'])) {
            $card = $pm['card'];
            $token->brand = self::brand((string) ($card['display_brand'] ?? $card['brand'] ?? 'card'));
            $token->last4 = self::last4((string) ($card['last4'] ?? ''));
            $token->exp_month = str_pad((string) ((int) ($card['exp_month'] ?? 1)), 2, '0', STR_PAD_LEFT);
            $token->exp_year = (string) ((int) ($card['exp_year'] ?? gmdate('Y')));
        } elseif (in_array($type, self::BANK_TYPES, true) && isset($pm[$type]['last4'])) {
            $token->last4 = self::last4((string) $pm[$type]['last4']);
        }
        $token->label = self::label($pm);
        $token->token = $pm_id;
        $token->type = $type;
        $token->user_id = max(0, $user_id);
        $token->customer_id = $edd_customer_id > 0 ? $edd_customer_id : self::eddCustomerFor($token->user_id);
        $token->stripe_customer = $customer_id;
        $token->mode = $mode === 'live' ? 'live' : 'test';
        $token->is_default = $user_id > 0 && !self::defaultFor($user_id, $token->mode);
        $token->created_at = gmdate('Y-m-d H:i:s');

        if (!$wpdb->insert(self::table(), $token->toRow())) {
            Settings::log(sprintf('Could not save a payment token for %s.', $pm_id), 'error');
            return null;
        }
        $token->id = (int) $wpdb->insert_id;
        self::$cache[$token->id] = $token;
        Settings::log(sprintf('Saved payment token %d for %s (user %d).', $token->id, $pm_id, $user_id), 'info');

        /**
         * A Stackless gateway saved a reusable payment method (WooCommerce's woocommerce_new_payment_token).
         * Subscriptions listens to attach it to a subscription whose method the customer is changing.
         *
         * @param string $gateway_id EDD gateway id.
         * @param string $token_id   Value for SubscriptionData::META_TOKEN_ID.
         * @param int    $user_id    Owner, 0 for a guest.
         */
        do_action('stackless_edd_payment_method_saved', Settings::GATEWAY_ID, (string) $token->id, $token->user_id);
        return $token;
    }

    public static function get(int $token_id): ?PaymentToken
    {
        global $wpdb;
        if ($token_id < 1) {
            return null;
        }
        if (!array_key_exists($token_id, self::$cache)) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $token_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::$cache[$token_id] = is_array($row) ? PaymentToken::fromRow($row) : null;
        }
        return self::$cache[$token_id];
    }

    /**
     * @return PaymentToken[]
     */
    public static function findByPaymentMethod(string $pm_id, int $user_id = -1): array
    {
        global $wpdb;
        if ($pm_id === '') {
            return [];
        }
        $table = self::table();
        if ($user_id >= 0) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE payment_method = %s AND user_id = %d", $table, $pm_id, $user_id));
        } else {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE payment_method = %s", $table, $pm_id));
        }
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
        if (!$token || $token->user_id !== $user_id) {
            return null;
        }
        return $token;
    }

    /**
     * A user's tokens, default first. Tokens made in the other Stripe mode are left out: a test card
     * cannot pay a live order.
     *
     * @return PaymentToken[]
     */
    public static function forUser(int $user_id, ?string $mode = null): array
    {
        global $wpdb;
        if ($user_id < 1) {
            return [];
        }
        $mode = $mode ?? Settings::mode();
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY is_default DESC, id DESC", $table, $user_id, $mode), ARRAY_A);
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
     * Remove a saved method. A customer removing it from their account also detaches it at Stripe
     * so it can never be charged again (unless another row still uses it).
     */
    public static function delete(PaymentToken $token, bool $detach = true): bool
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
        if ($detach) {
            self::detach($token);
        }
        return true;
    }

    private static function detach(PaymentToken $token): void
    {
        $pm_id = $token->token;
        if ($pm_id === '' || self::findByPaymentMethod($pm_id)) {
            return; // Another token row still uses this method.
        }
        try {
            Settings::client($token->mode)->post('payment_methods/' . rawurlencode($pm_id) . '/detach');
            Settings::log(sprintf('Detached %s after token %d was deleted.', $pm_id, $token->id), 'info');
        } catch (StripeApiException $e) {
            Settings::log(sprintf('Could not detach %s: %s', $pm_id, $e->logSummary()), 'warning');
        }
    }

    /** Delete tokens for a PaymentMethod that Stripe says is gone, without calling Stripe again. */
    public static function deleteForPaymentMethod(string $pm_id): int
    {
        $count = 0;
        foreach (self::findByPaymentMethod($pm_id) as $token) {
            if (self::delete($token, false)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Refresh card details after the network updated them (new expiry, reissued card).
     *
     * @param array<string,mixed> $pm
     */
    public static function updateFromPaymentMethod(array $pm): int
    {
        global $wpdb;
        $count = 0;
        $card = isset($pm['card']) && is_array($pm['card']) ? $pm['card'] : null;
        foreach (self::findByPaymentMethod((string) ($pm['id'] ?? '')) as $token) {
            $data = [];
            if ($card && $token->type === 'card') {
                if (!empty($card['exp_month'])) {
                    $data['exp_month'] = str_pad((string) ((int) $card['exp_month']), 2, '0', STR_PAD_LEFT);
                }
                if (!empty($card['exp_year'])) {
                    $data['exp_year'] = (string) ((int) $card['exp_year']);
                }
                if (!empty($card['last4'])) {
                    $data['last4'] = self::last4((string) $card['last4']);
                }
                if (!empty($card['brand'])) {
                    $data['brand'] = self::brand((string) ($card['display_brand'] ?? $card['brand']));
                }
            } else {
                $data['label'] = self::label($pm);
            }
            if ($data && $wpdb->update(self::table(), $data, ['id' => $token->id]) !== false) {
                unset(self::$cache[$token->id]);
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
        if ((string) $gateway_id !== '' && (string) $gateway_id !== Settings::GATEWAY_ID) {
            return $methods;
        }
        foreach (self::forUser((int) $user_id) as $token) {
            $methods[] = [
                'id' => (string) $token->id,
                'gateway' => Settings::GATEWAY_ID,
                'label' => $token->displayName(),
                'type' => $token->type,
                'expired' => $token->isExpired(),
                'default' => $token->is_default,
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

    /* ---------------------------------------------------------------------
     * Labels
     * ------------------------------------------------------------------- */

    /** @param array<string,mixed> $pm */
    public static function label(array $pm): string
    {
        $type = (string) ($pm['type'] ?? '');
        $name = self::typeLabel($type);
        $details = isset($pm[$type]) && is_array($pm[$type]) ? $pm[$type] : [];
        if (!empty($details['email'])) {
            return $name . ' (' . sanitize_text_field((string) $details['email']) . ')';
        }
        if (!empty($details['last4'])) {
            /* translators: 1: payment method name, 2: last four digits */
            return sprintf(__('%1$s ending in %2$s', 'stackless-lite-edd'), $name, self::last4((string) $details['last4']));
        }
        return $name;
    }

    public static function typeLabel(string $type): string
    {
        $labels = [
            'card' => __('Card', 'stackless-lite-edd'),
            'link' => __('Link', 'stackless-lite-edd'),
            'sepa_debit' => __('SEPA Direct Debit', 'stackless-lite-edd'),
            'us_bank_account' => __('US bank account', 'stackless-lite-edd'),
            'bacs_debit' => __('Bacs Direct Debit', 'stackless-lite-edd'),
            'au_becs_debit' => __('BECS Direct Debit', 'stackless-lite-edd'),
            'acss_debit' => __('Pre-authorized debit', 'stackless-lite-edd'),
            'paypal' => __('PayPal', 'stackless-lite-edd'),
            'cashapp' => __('Cash App Pay', 'stackless-lite-edd'),
            'revolut_pay' => __('Revolut Pay', 'stackless-lite-edd'),
            'amazon_pay' => __('Amazon Pay', 'stackless-lite-edd'),
        ];
        return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function brand(string $brand): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $brand));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'cartes-bancaires' => 'cartes_bancaires', 'unknown' => 'card'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    public static function last4(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        return str_pad(substr((string) $digits, -4), 4, '0', STR_PAD_LEFT);
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
