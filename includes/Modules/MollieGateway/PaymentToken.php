<?php

namespace StacklessEDD\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * A saved Mollie mandate (one row of {prefix}stackless_edd_mollie_tokens), the EDD counterpart
 * of the WooCommerce payment tokens the WooCommerce edition stored.
 *
 * $token is the mandate id (mdt_…), $mollie_customer the Mollie customer (cst_…) it belongs to,
 * $method the mandate method: creditcard (brand, last4, expiry), directdebit (last4 of the IBAN)
 * or paypal (a label with the PayPal account).
 */
final class PaymentToken
{
    /** @var int */
    public $id = 0;
    /** @var int WordPress user id, 0 for a guest purchase */
    public $user_id = 0;
    /** @var int EDD customer id */
    public $customer_id = 0;
    /** @var string Mollie mandate id */
    public $token = '';
    /** @var string Mollie customer id */
    public $mollie_customer = '';
    /** @var string test|live */
    public $mode = 'test';
    /** @var string creditcard|directdebit|paypal */
    public $method = 'creditcard';
    /** @var string */
    public $brand = '';
    /** @var string */
    public $last4 = '';
    /** @var string two digits */
    public $exp_month = '';
    /** @var string four digits */
    public $exp_year = '';
    /** @var string */
    public $label = '';
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
        $token->token = (string) ($row['mandate_id'] ?? '');
        $token->mollie_customer = (string) ($row['mollie_customer'] ?? '');
        $token->mode = ($row['mode'] ?? '') === 'live' ? 'live' : 'test';
        $token->method = (string) ($row['method'] ?? 'creditcard');
        $token->brand = (string) ($row['brand'] ?? '');
        $token->last4 = (string) ($row['last4'] ?? '');
        $token->exp_month = (string) ($row['exp_month'] ?? '');
        $token->exp_year = (string) ($row['exp_year'] ?? '');
        $token->label = (string) ($row['label'] ?? '');
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
            'mandate_id' => $this->token,
            'mollie_customer' => $this->mollie_customer,
            'mode' => $this->mode,
            'method' => $this->method,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'label' => $this->label,
            'is_default' => $this->is_default ? 1 : 0,
            'created_at' => $this->created_at,
        ];
    }

    public function isCard(): bool
    {
        return $this->method === 'creditcard' && $this->last4 !== '';
    }

    /** "Visa ending in 4242 (expires 12/31)", "SEPA Direct Debit ending in 0000", or the PayPal label. */
    public function displayName(): string
    {
        if ($this->isCard()) {
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
        if ($this->method === 'directdebit' && $this->last4 !== '') {
            /* translators: 1: payment method name, 2: last four characters of the IBAN */
            return sprintf(__('%1$s ending in %2$s', 'stackless-lite-edd'), Methods::label('directdebit'), $this->last4);
        }
        return $this->label !== '' ? $this->label : __('Saved payment method', 'stackless-lite-edd');
    }

    /** Expired cards stay listed but are flagged, and Subscriptions skips them. */
    public function isExpired(?int $now = null): bool
    {
        if (!$this->isCard() || $this->exp_year === '' || $this->exp_month === '') {
            return false;
        }
        $now = $now ?? time();
        $year = (int) gmdate('Y', $now);
        $month = (int) gmdate('n', $now);
        return (int) $this->exp_year < $year || ((int) $this->exp_year === $year && (int) $this->exp_month < $month);
    }

    public static function brandLabel(string $brand): string
    {
        $labels = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'maestro' => 'Maestro',
            'diners' => 'Diners Club',
            'discover' => 'Discover',
            'jcb' => 'JCB',
            'cartes_bancaires' => 'Cartes Bancaires',
            'card' => __('Card', 'stackless-lite-edd'),
        ];
        return $labels[$brand] ?? ucwords(str_replace(['-', '_'], ' ', $brand));
    }
}
