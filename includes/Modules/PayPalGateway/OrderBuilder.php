<?php

namespace StacklessEDD\Modules\PayPalGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Builds Orders v2 and Vault v3 request bodies from the EDD cart and EDD orders.
 *
 * Totals always come from EDD's own calculation on the server; nothing the browser
 * sends affects an amount. Downloads are digital, so PayPal never asks for a
 * shipping address.
 */
class OrderBuilder
{
    public const REFERENCE = 'default';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** Order body for the current cart (checkout page, before the EDD order exists). */
    public function fromCart(bool $vault, string $funding): array
    {
        $currency = strtoupper(stackless_edd_current_currency());
        $total = (float) edd_get_cart_total();

        $amounts = [];
        $fees = EDD()->fees ? EDD()->fees->get_fees('all') : [];
        foreach ((array) $fees as $fee) {
            $amounts[] = is_array($fee) ? (float) ($fee['amount'] ?? 0) : 0.0;
        }
        $fees = self::splitFees($amounts);

        $unit = [
            'reference_id' => self::REFERENCE,
            'description' => $this->description(),
            'amount' => $this->amount($total, $currency, [
                'item_total' => (float) edd_get_cart_subtotal() + $fees['positive'],
                'tax_total' => (float) edd_get_cart_tax(),
                'discount' => (float) edd_get_cart_discounted_amount() + $fees['negative'],
            ]),
        ];

        $checkoutUrl = edd_get_checkout_uri();
        return $this->finish([
            'intent' => $vault ? 'CAPTURE' : strtoupper($this->gateway->intent()),
            'purchase_units' => [$unit],
        ], $funding, $vault, $checkoutUrl, $checkoutUrl, ['context' => 'cart']);
    }

    /**
     * Order body for an existing EDD order (the no-JavaScript redirect fallback).
     *
     * @param string $returnUrl Where PayPal sends the buyer after approval (redirect flow only).
     */
    public function fromOrder(Order $order, bool $vault, string $funding, string $returnUrl = ''): array
    {
        $unit = $this->purchaseUnit($order);
        $cancel = Orders::payUrl($order);
        return $this->finish([
            'intent' => $vault ? 'CAPTURE' : strtoupper($this->gateway->intent()),
            'purchase_units' => [$unit],
        ], $funding, $vault, $returnUrl !== '' ? $returnUrl : $cancel, $cancel, ['context' => 'order', 'order' => $order]);
    }

    /**
     * Merchant-initiated renewal charge against a vaulted PayPal account or card.
     *
     * @param string $type paypal|card
     */
    public function renewal(Order $renewal, string $vaultId, string $type = 'paypal'): array
    {
        $unit = $this->purchaseUnit($renewal);
        /* translators: %s: site name */
        $unit['description'] = Amount::truncate(sprintf(__('Subscription renewal – %s', 'stackless-lite-edd'), $this->gateway->brandName()), 127);

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => self::vaultSource($vaultId, $type, 'MERCHANT', 'RECURRING'),
        ];
        return (array) apply_filters('stackless_edd_paypal_gateway_renewal_args', $body, $renewal);
    }

    /**
     * Charge outside the renewal schedule against a saved PayPal account or card
     * (Contracts\SavedMethodCharger, e.g. a post-purchase offer).
     *
     * @param string $type      paypal|card
     * @param bool   $onSession The buyer is on the page and may approve or authenticate.
     */
    public function savedCharge(Order $order, string $vaultId, string $type, bool $onSession, string $returnUrl): array
    {
        $unit = $this->purchaseUnit($order);
        $source = self::vaultSource($vaultId, $type, $onSession ? 'CUSTOMER' : 'MERCHANT', 'UNSCHEDULED');
        if ($onSession) {
            $context = ['return_url' => $returnUrl, 'cancel_url' => Orders::payUrl($order)];
            if ($type === 'card') {
                $source['card']['attributes'] = ['verification' => ['method' => $this->gateway->threeDSecureMethod()]];
                $source['card']['experience_context'] = $context;
            } else {
                $source['paypal']['experience_context'] = array_merge(['brand_name' => $this->gateway->brandName(), 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW'], $context);
            }
        }
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => $source,
        ];
        return (array) apply_filters('stackless_edd_paypal_gateway_saved_charge_args', $body, $order, $onSession);
    }

    /**
     * payment_source for a vault id. Cards carry the card-network stored-credential fields;
     * PayPal wallets take payment_initiator and usage only.
     */
    public static function vaultSource(string $vaultId, string $type, string $initiator, string $paymentType): array
    {
        if ($type === 'card') {
            return ['card' => [
                'vault_id' => $vaultId,
                'stored_credential' => [
                    'payment_initiator' => $initiator,
                    'payment_type' => $paymentType,
                    'usage' => 'SUBSEQUENT',
                ],
            ]];
        }
        return ['paypal' => [
            'vault_id' => $vaultId,
            'stored_credential' => [
                'payment_initiator' => $initiator,
                'usage' => 'SUBSEQUENT',
            ],
        ]];
    }

    /**
     * Order paid with a Fastlane single-use card token: PayPal charges it on create, so the
     * order is captured in one call (or asks for 3-D Secure).
     */
    public function fromSingleUseToken(Order $order, string $token, bool $vault, string $returnUrl): array
    {
        $unit = $this->purchaseUnit($order);
        $card = [
            'single_use_token' => $token,
            'attributes' => ['verification' => ['method' => $this->gateway->threeDSecureMethod()]],
            'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => Orders::payUrl($order)],
        ];
        if ($vault) {
            $card['attributes']['vault'] = ['store_in_vault' => 'ON_SUCCESS'];
        }
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['card' => $card],
        ];
        return (array) apply_filters('stackless_edd_paypal_gateway_order_args', $body, ['context' => 'fastlane', 'order' => $order]);
    }

    /** Vault v3 setup token: save a PayPal account without charging it. */
    public function setupToken(string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'payment_source' => [
                'paypal' => [
                    'description' => Amount::truncate(sprintf(
                        /* translators: %s: store name */
                        __('Future payments to %s', 'stackless-lite-edd'),
                        $this->gateway->brandName()
                    ), 127),
                    'usage_type' => 'MERCHANT',
                    'customer_type' => 'CONSUMER',
                    'permit_multiple_payment_tokens' => true,
                    'experience_context' => [
                        'brand_name' => $this->gateway->brandName(),
                        'shipping_preference' => 'NO_SHIPPING',
                        'vault_instruction' => 'ON_PAYER_APPROVAL',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];
        return (array) apply_filters('stackless_edd_paypal_gateway_setup_token_args', $body);
    }

    /**
     * PATCH operations that make an approved PayPal order match the EDD order
     * (cart totals can move between approval and Purchase).
     */
    public function patchOperations(Order $order, array $paypalOrder): array
    {
        $unit = (array) ($paypalOrder['purchase_units'][0] ?? []);
        $reference = (string) ($unit['reference_id'] ?? self::REFERENCE);
        $path = "/purchase_units/@reference_id=='" . $reference . "'";
        $wanted = $this->purchaseUnit($order);

        $ops = [];
        $current = (array) ($unit['amount'] ?? []);
        $currentBreakdown = isset($current['breakdown']) ? (array) $current['breakdown'] : [];
        $wantedBreakdown = isset($wanted['amount']['breakdown']) ? (array) $wanted['amount']['breakdown'] : [];
        if (!Amount::matches($current, (float) $order->total, (string) $order->currency) || self::normalize($currentBreakdown) !== self::normalize($wantedBreakdown)) {
            $ops[] = ['op' => 'replace', 'path' => $path . '/amount', 'value' => $wanted['amount']];
        }
        if ((string) ($unit['custom_id'] ?? '') !== $wanted['custom_id']) {
            $ops[] = ['op' => 'add', 'path' => $path . '/custom_id', 'value' => $wanted['custom_id']];
        }
        if ((string) ($unit['invoice_id'] ?? '') !== $wanted['invoice_id']) {
            $ops[] = ['op' => 'add', 'path' => $path . '/invoice_id', 'value' => $wanted['invoice_id']];
        }
        return $ops;
    }

    /** One purchase unit for an EDD order. */
    public function purchaseUnit(Order $order): array
    {
        $currency = (string) $order->currency;
        $amounts = [];
        foreach ((array) $order->get_fees() as $fee) {
            $amounts[] = is_object($fee) ? (float) $fee->subtotal : 0.0;
        }
        $fees = self::splitFees($amounts);

        return [
            'reference_id' => self::REFERENCE,
            'custom_id' => (string) $order->id,
            'invoice_id' => Amount::truncate($this->gateway->invoicePrefix() . $order->get_number(), 127),
            'description' => $this->description(),
            'amount' => $this->amount((float) $order->total, $currency, [
                'item_total' => (float) $order->subtotal + $fees['positive'],
                'tax_total' => (float) $order->tax,
                'discount' => (float) $order->discount + $fees['negative'],
            ]),
        ];
    }

    private function amount(float $total, string $currency, array $parts): array
    {
        $amount = Amount::money($total, $currency);
        $breakdown = Amount::breakdown($parts, $total, $currency);
        if ($breakdown) {
            $amount['breakdown'] = $breakdown;
        }
        return $amount;
    }

    /**
     * @param float[] $fees
     * @return array{positive:float,negative:float}
     */
    public static function splitFees(array $fees): array
    {
        $positive = 0.0;
        $negative = 0.0;
        foreach ($fees as $fee) {
            if ($fee >= 0) {
                $positive += $fee;
            } else {
                $negative += abs($fee);
            }
        }
        return ['positive' => $positive, 'negative' => $negative];
    }

    public static function normalize(array $breakdown): array
    {
        $out = [];
        foreach ($breakdown as $key => $money) {
            $out[$key] = is_array($money) ? strtoupper((string) ($money['currency_code'] ?? '')) . ':' . (string) ($money['value'] ?? '') : '';
        }
        ksort($out);
        return $out;
    }

    private function description(): string
    {
        /* translators: %s: store name */
        return Amount::truncate(sprintf(__('Order from %s', 'stackless-lite-edd'), $this->gateway->brandName()), 127);
    }

    /** Payment source, experience context and vault attributes. */
    private function finish(array $body, string $funding, bool $vault, string $returnUrl, string $cancelUrl, array $context): array
    {
        $experience = [
            'brand_name' => $this->gateway->brandName(),
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ];
        $locale = str_replace('_', '-', (string) get_locale());
        if (preg_match('/^[a-z]{2}-[A-Z]{2}$/', $locale)) {
            $experience['locale'] = $locale;
        }

        if ($funding === 'card') {
            // Card fields: PayPal runs 3-D Secure in the card fields when it applies.
            $card = [
                'attributes' => ['verification' => ['method' => $this->gateway->threeDSecureMethod()]],
                'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl],
            ];
            if ($vault) {
                $card['attributes']['vault'] = ['store_in_vault' => 'ON_SUCCESS'];
            }
            $body['payment_source'] = ['card' => $card];
            return (array) apply_filters('stackless_edd_paypal_gateway_order_args', $body, $context);
        }

        if ($vault || in_array($funding, ['', 'paypal', 'paylater'], true)) {
            $source = ['experience_context' => $experience];
            if ($vault) {
                $source['attributes'] = [
                    'vault' => [
                        'store_in_vault' => 'ON_SUCCESS',
                        'usage_type' => 'MERCHANT',
                        'customer_type' => 'CONSUMER',
                        'permit_multiple_payment_tokens' => true,
                    ],
                ];
            }
            $body['payment_source'] = ['paypal' => $source];
        } elseif ($funding === 'venmo') {
            $body['payment_source'] = ['venmo' => ['experience_context' => [
                'brand_name' => $experience['brand_name'],
                'shipping_preference' => 'NO_SHIPPING',
            ]]];
        }
        // Other funding sources (guest_card: the buttons' own card form, Apple Pay, Google Pay,
        // local methods) carry no payment source: the JS SDK collects them itself.

        return (array) apply_filters('stackless_edd_paypal_gateway_order_args', $body, $context);
    }
}
