<?php

namespace StacklessEDD\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * A saved Razorpay recurring token (one row of {prefix}stackless_edd_razorpay_tokens), the EDD
 * counterpart of the WooCommerce payment tokens the WooCommerce edition stored.
 *
 * $token is the Razorpay token id (token_…). Each row records the mode, the Razorpay customer
 * (cust_…), the method (card, upi, emandate), the mandate ceiling in paise, the mandate status
 * (initiated, confirmed, paused, rejected, cancelled) and the email and phone Razorpay needs for
 * every recurring debit. Card mandates keep brand, last4 and expiry; UPI and eNACH keep a label.
 */
final class PaymentToken
{
    /** @var int */
    public $id = 0;
    /** @var int WordPress user id, 0 for a guest purchase */
    public $user_id = 0;
    /** @var int EDD customer id */
    public $customer_id = 0;
    /** @var string Razorpay token id */
    public $token = '';
    /** @var string Razorpay customer id */
    public $rzp_customer = '';
    /** @var string test|live */
    public $mode = 'test';
    /** @var string card|upi|emandate */
    public $method = 'card';
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
    /** @var int mandate ceiling in paise, 0 = unknown */
    public $max_amount = 0;
    /** @var string initiated|confirmed|paused|rejected|cancelled */
    public $status = 'confirmed';
    /** @var string */
    public $email = '';
    /** @var string */
    public $contact = '';
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
        $token->token = (string) ($row['token'] ?? '');
        $token->rzp_customer = (string) ($row['rzp_customer'] ?? '');
        $token->mode = ($row['mode'] ?? '') === 'live' ? 'live' : 'test';
        $token->method = (string) ($row['method'] ?? 'card');
        $token->brand = (string) ($row['brand'] ?? '');
        $token->last4 = (string) ($row['last4'] ?? '');
        $token->exp_month = (string) ($row['exp_month'] ?? '');
        $token->exp_year = (string) ($row['exp_year'] ?? '');
        $token->label = (string) ($row['label'] ?? '');
        $token->max_amount = (int) ($row['max_amount'] ?? 0);
        $token->status = (string) ($row['status'] ?? '') !== '' ? (string) $row['status'] : 'confirmed';
        $token->email = (string) ($row['email'] ?? '');
        $token->contact = (string) ($row['contact'] ?? '');
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
            'token' => $this->token,
            'rzp_customer' => $this->rzp_customer,
            'mode' => $this->mode,
            'method' => $this->method,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'label' => $this->label,
            'max_amount' => $this->max_amount,
            'status' => $this->status,
            'email' => $this->email,
            'contact' => $this->contact,
            'is_default' => $this->is_default ? 1 : 0,
            'created_at' => $this->created_at,
        ];
    }

    public function isCard(): bool
    {
        return $this->method === 'card' && $this->last4 !== '';
    }

    /** "Visa ending in 1111 (expires 12/31)", "UPI Autopay (name@bank)" or "Bank account (eNACH) (HDFC ••5678)". */
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
        return $this->label !== '' ? $this->label : __('Saved payment method', 'stackless-lite-edd');
    }

    /** The name plus "(paused)" or "(awaiting bank confirmation)" while the mandate cannot be debited yet. */
    public function displayNameWithStatus(): string
    {
        $name = $this->displayName();
        if ($this->status === 'paused') {
            return $name . ' ' . __('(paused)', 'stackless-lite-edd');
        }
        if ($this->status === 'initiated') {
            return $name . ' ' . __('(awaiting bank confirmation)', 'stackless-lite-edd');
        }
        return $name;
    }

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
            'diners' => 'Diners Club',
            'rupay' => 'RuPay',
            'maestro' => 'Maestro',
            'jcb' => 'JCB',
            'card' => __('Card', 'stackless-lite-edd'),
        ];
        return $labels[$brand] ?? ucwords(str_replace(['-', '_'], ' ', $brand));
    }
}
