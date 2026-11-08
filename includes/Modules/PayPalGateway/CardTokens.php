<?php

namespace StacklessEDD\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Cards vaulted at PayPal (card fields, Fastlane), stored as type "card" rows of Tokens (2.0.0).
 *
 * vault_id = PayPal vault id; the row records the PayPal environment, the PayPal customer id,
 * the brand, last four digits and expiry, so renewals send payment_source.card.vault_id and
 * the account page can name the card. Card numbers never reach the store.
 */
final class CardTokens
{
    public static function isCard(?array $token): bool
    {
        return is_array($token) && Tokens::type($token) === 'card';
    }

    /**
     * Saves (or finds) the row for a vaulted card.
     *
     * @param array<string,mixed> $card payment_source.card: brand, last_digits, expiry (YYYY-MM)
     */
    public static function save(int $customerId, int $userId, string $vaultId, array $card, string $paypalCustomerId, string $environment): int
    {
        if ($vaultId === '') {
            return 0;
        }
        [$year, $month] = self::expiry((string) ($card['expiry'] ?? ''));
        return Tokens::save($customerId, $userId, $vaultId, '', '', $paypalCustomerId, $environment, [
            'type' => 'card',
            'card_brand' => self::brand((string) ($card['brand'] ?? '')),
            'card_last4' => self::last4((string) ($card['last_digits'] ?? '')),
            'card_expiry' => $year !== '' ? $year . '-' . $month : '',
        ]);
    }

    /** PayPal card brand (VISA, MASTERCARD, AMEX…) as a lowercase key. */
    public static function brand(string $brand): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', trim($brand)));
        $map = ['mastercard' => 'mastercard', 'master-card' => 'mastercard', 'american-express' => 'amex', 'amex' => 'amex', 'diners' => 'diners', 'diners-club' => 'diners', 'unknown' => 'card'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    public static function last4(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return $digits === '' ? '' : str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);
    }

    /** "2028-07" → ["2028", "07"]; anything else → ["", ""]. @return array{0:string,1:string} */
    public static function expiry(string $value): array
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($value), $m)) {
            return [$m[1], str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT)];
        }
        return ['', ''];
    }

    /** "Visa ending in 1111 (expires 12/2030)" */
    public static function displayName(array $token): string
    {
        $names = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'diners' => 'Diners Club', 'jcb' => 'JCB', 'maestro' => 'Maestro', 'unionpay' => 'UnionPay', 'elo' => 'Elo', 'hiper' => 'Hiper'];
        $brand = (string) ($token['card_brand'] ?? '');
        $name = $names[$brand] ?? __('Card', 'stackless-lite-edd');
        $last4 = (string) ($token['card_last4'] ?? '');
        $label = $last4 !== ''
            /* translators: 1: card brand, 2: last four digits */
            ? sprintf(__('%1$s ending in %2$s', 'stackless-lite-edd'), $name, $last4)
            : $name;
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) ($token['card_expiry'] ?? ''), $m)) {
            /* translators: 1: card name and last digits, 2: expiry month/year */
            $label = sprintf(__('%1$s (expires %2$s)', 'stackless-lite-edd'), $label, $m[2] . '/' . $m[1]);
        }
        return $label;
    }
}
