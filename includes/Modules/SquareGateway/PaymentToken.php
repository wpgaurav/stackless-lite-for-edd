<?php

namespace StacklessEDD\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * A card saved on file with Square (one row of {prefix}stackless_edd_square_tokens), the EDD
 * counterpart of the WooCommerce card tokens the WooCommerce edition stored.
 *
 * $card_id is the Square card id (ccof:...), $square_customer the Square customer it belongs to.
 */
final class PaymentToken
{
    /** @var int */
    public $id = 0;
    /** @var int WordPress user id, 0 for a guest purchase */
    public $user_id = 0;
    /** @var int EDD customer id */
    public $customer_id = 0;
    /** @var string Square card id */
    public $card_id = '';
    /** @var string Square customer id */
    public $square_customer = '';
    /** @var string test|live */
    public $mode = 'test';
    /** @var string visa, mastercard, amex... */
    public $brand = 'card';
    /** @var string */
    public $last4 = '';
    /** @var string two digits */
    public $exp_month = '';
    /** @var string four digits */
    public $exp_year = '';
    /** @var bool */
    public $is_default = false;
    /** @var string UTC Y-m-d H:i:s */
    public $created_at = '';

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $token = new self();
        $token->id = (int) ($row['id'] ?? 0);
        $token->user_id = (int) ($row['user_id'] ?? 0);
        $token->customer_id = (int) ($row['customer_id'] ?? 0);
        $token->card_id = (string) ($row['card_id'] ?? '');
        $token->square_customer = (string) ($row['square_customer'] ?? '');
        $token->mode = ($row['mode'] ?? '') === 'live' ? 'live' : 'test';
        $token->brand = (string) ($row['brand'] ?? 'card');
        $token->last4 = (string) ($row['last4'] ?? '');
        $token->exp_month = (string) ($row['exp_month'] ?? '');
        $token->exp_year = (string) ($row['exp_year'] ?? '');
        $token->is_default = !empty($row['is_default']);
        $token->created_at = (string) ($row['created_at'] ?? '');
        return $token;
    }

    /** @return array<string,mixed> */
    public function toRow(): array
    {
        return [
            'user_id' => $this->user_id,
            'customer_id' => $this->customer_id,
            'card_id' => $this->card_id,
            'square_customer' => $this->square_customer,
            'mode' => $this->mode,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'is_default' => $this->is_default ? 1 : 0,
            'created_at' => $this->created_at,
        ];
    }

    /** "Visa ending in 1111 (expires 12/31)" */
    public function displayName(): string
    {
        $name = sprintf(
            /* translators: 1: card brand, 2: last four digits */
            __('%1$s ending in %2$s', 'stackless-lite-edd'),
            self::brandLabel($this->brand),
            $this->last4
        );
        if ($this->exp_month !== '' && $this->exp_year !== '') {
            /* translators: %s: expiry date, MM/YY */
            $name .= ' ' . sprintf(__('(expires %s)', 'stackless-lite-edd'), $this->exp_month . '/' . substr($this->exp_year, -2));
        }
        return $name;
    }

    /** Expired cards stay listed (Square's account updater may still renew them) but are flagged. */
    public function isExpired(?int $now = null): bool
    {
        if ($this->exp_year === '' || $this->exp_month === '') {
            return false;
        }
        $now = $now ?? time();
        $year = (int) gmdate('Y', $now);
        $month = (int) gmdate('n', $now);
        return (int) $this->exp_year < $year || ((int) $this->exp_year === $year && (int) $this->exp_month < $month);
    }

    /** "2031-12" or '' */
    public function expires(): string
    {
        return $this->exp_month !== '' && $this->exp_year !== '' ? $this->exp_year . '-' . $this->exp_month : '';
    }

    public static function brandLabel(string $brand): string
    {
        $labels = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            'interac' => 'Interac',
            'eftpos' => 'eftpos',
            'felica' => 'FeliCa',
            'card' => __('Card', 'stackless-lite-edd'),
        ];
        return $labels[$brand] ?? ucwords(str_replace(['-', '_'], ' ', $brand));
    }
}
