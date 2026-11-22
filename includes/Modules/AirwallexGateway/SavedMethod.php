<?php

namespace StacklessEDD\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * A saved card (one row of {prefix}stackless_edd_airwallex_tokens): a merchant-triggered
 * PaymentConsent, bound to the WordPress user, the Airwallex customer, the mode and the
 * Airwallex account it was saved in.
 */
final class SavedMethod
{
    /** @var int */
    public $id = 0;
    /** @var int WordPress user id, 0 for a guest purchase */
    public $user_id = 0;
    /** @var string PaymentConsent id */
    public $consent = '';
    /** @var string Airwallex customer id */
    public $customer = '';
    /** @var string PaymentMethod id */
    public $method = '';
    /** @var string test|live */
    public $mode = 'test';
    /** @var string Airwallex account id */
    public $account = '';
    /** @var string */
    public $brand = '';
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
        $saved = new self();
        $saved->id = (int) ($row['id'] ?? 0);
        $saved->user_id = (int) ($row['user_id'] ?? 0);
        $saved->consent = (string) ($row['consent_id'] ?? '');
        $saved->customer = (string) ($row['airwallex_customer'] ?? '');
        $saved->method = (string) ($row['payment_method_id'] ?? '');
        $saved->mode = ($row['mode'] ?? '') === 'live' ? 'live' : 'test';
        $saved->account = (string) ($row['account_id'] ?? '');
        $saved->brand = (string) ($row['brand'] ?? '');
        $saved->last4 = (string) ($row['last4'] ?? '');
        $saved->exp_month = (string) ($row['exp_month'] ?? '');
        $saved->exp_year = (string) ($row['exp_year'] ?? '');
        $saved->is_default = !empty($row['is_default']);
        $saved->created_at = (string) ($row['created_at'] ?? '');
        return $saved;
    }

    /** @return array<string,mixed> */
    public function toRow(): array
    {
        return [
            'user_id' => $this->user_id,
            'consent_id' => $this->consent,
            'airwallex_customer' => $this->customer,
            'payment_method_id' => $this->method,
            'mode' => $this->mode,
            'account_id' => $this->account,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'is_default' => $this->is_default ? 1 : 0,
            'created_at' => $this->created_at,
        ];
    }

    public function usable(): bool
    {
        return $this->consent !== '' && $this->customer !== '' && $this->method !== '' && $this->account !== '';
    }

    /** "Visa ending in 4242 (expires 12/31)" */
    public function displayName(): string
    {
        if ($this->last4 === '') {
            return __('Saved card', 'stackless-lite-edd');
        }
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

    /** Expired cards stay listed but are flagged, and Subscriptions skips them. */
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

    public static function brandLabel(string $brand): string
    {
        $labels = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'american express' => 'American Express',
            'unionpay' => 'UnionPay',
            'jcb' => 'JCB',
            '' => __('Card', 'stackless-lite-edd'),
        ];
        return $labels[$brand] ?? ucwords(str_replace(['-', '_'], ' ', $brand));
    }
}
