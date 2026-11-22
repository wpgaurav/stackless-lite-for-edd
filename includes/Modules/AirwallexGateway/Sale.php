<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use EDD\Orders\Order;
use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * An EDD order as the payment flows see it. Checkout, Sync, Renewals and Refunds only reach
 * orders through this class, and always load a fresh one inside the order's lock.
 *
 * Status map from the WooCommerce edition: "pending or failed" = pending, failed or abandoned;
 * "on hold while an asynchronous payment finishes" = processing; "on hold for a person" =
 * on_hold; "processing or completed" = complete.
 */
final class Sale
{
    /** @var Order */
    private $order;

    private function __construct(Order $order)
    {
        $this->order = $order;
    }

    /** The order as it is in the database now. */
    public static function get(int $orderId): ?self
    {
        $order = self::load($orderId);
        return $order ? new self($order) : null;
    }

    private static function load(int $orderId): ?Order
    {
        if ($orderId < 1 || !function_exists('edd_get_order')) {
            return null;
        }
        // EDD answers from the object cache, which still holds the order from before a lock was taken.
        wp_cache_delete($orderId, 'edd-orders');
        wp_cache_delete($orderId, 'edd_order_meta');
        $order = edd_get_order($orderId);
        return $order instanceof Order && (string) $order->type !== 'refund' ? $order : null;
    }

    private function reload(): void
    {
        $this->order = self::load($this->id()) ?? $this->order;
    }

    /** The order a merchant_order_id belongs to: both the id inside it and the order's own copy must agree. */
    public static function fromReference(string $reference): ?self
    {
        if (!preg_match('/^' . Settings::REFERENCE_PREFIX . '-([1-9]\d*)-[a-f0-9]{16}$/D', $reference, $match)) {
            return null;
        }
        $sale = self::get((int) $match[1]);
        return $sale && hash_equals($sale->reference(), $reference) ? $sale : null;
    }

    public function id(): int
    {
        return (int) $this->order->id;
    }

    /** '' until a payment was started, and for a copy of another order's meta. */
    public function reference(): string
    {
        $reference = $this->text(Meta::REFERENCE);
        return strpos($reference, Settings::REFERENCE_PREFIX . '-' . $this->id() . '-') === 0 ? $reference : '';
    }

    public function newReference(): string
    {
        return Settings::REFERENCE_PREFIX . '-' . $this->id() . '-' . bin2hex(random_bytes(8));
    }

    public function number(): string
    {
        $number = (string) $this->order->get_number();
        return $number !== '' ? $number : (string) $this->id();
    }

    public function currency(): string
    {
        return strtoupper((string) $this->order->currency);
    }

    public function total(): string
    {
        return (string) $this->order->total;
    }

    public function isZero(): bool
    {
        return Money::toMinor($this->total(), $this->currency()) <= 0;
    }

    public function userId(): int
    {
        return (int) $this->order->user_id;
    }

    public function email(): string
    {
        return (string) $this->order->email;
    }

    public function firstName(): string
    {
        $first = $this->address('first_name');
        if ($first === '' && $this->address('last_name') === '' && (int) $this->order->customer_id > 0 && function_exists('edd_get_customer')) {
            $customer = edd_get_customer((int) $this->order->customer_id);
            // EDD keeps the customer's whole name in one field.
            $first = $customer ? trim((string) $customer->name) : '';
        }
        return $first;
    }

    public function lastName(): string
    {
        return $this->address('last_name');
    }

    public function country(): string
    {
        return strtoupper($this->address('country'));
    }

    private function address(string $key): string
    {
        // An Order_Address, or a plain object when none is stored.
        $address = $this->order->get_address();
        return is_object($address) && isset($address->{$key}) && is_scalar($address->{$key}) ? (string) $address->{$key} : '';
    }

    /**
     * @phpstan-impure
     * @return mixed '' when the key is not set
     */
    public function meta(string $key)
    {
        $value = edd_get_order_meta($this->id(), $key, true);
        return ($value === false || $value === null) ? '' : $value;
    }

    /** @phpstan-impure */
    public function text(string $key): string
    {
        $value = $this->meta($key);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @phpstan-impure
     * @return array<mixed>
     */
    public function data(string $key): array
    {
        $value = $this->meta($key);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $values meta key => value, null deletes it */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                edd_delete_order_meta($this->id(), $key);
            } else {
                edd_update_order_meta($this->id(), $key, $value);
            }
        }
    }

    public function isOurs(): bool
    {
        return (string) $this->order->gateway === Settings::GATEWAY_ID;
    }

    /**
     * Paid at least once (refunds and a later hold for a dispute included).
     *
     * @phpstan-impure
     */
    public function paid(): bool
    {
        $complete = function_exists('edd_get_complete_order_statuses') ? (array) edd_get_complete_order_statuses() : ['complete', 'partially_refunded', 'refunded', 'revoked'];
        $completed = isset($this->order->date_completed) ? (string) $this->order->date_completed : '';
        return in_array((string) $this->order->status, $complete, true) || ($completed !== '' && $completed !== '0000-00-00 00:00:00');
    }

    /**
     * Unpaid, and a payment may be started.
     *
     * @phpstan-impure
     */
    public function payable(): bool
    {
        return !$this->paid() && $this->hasStatus(['pending', 'failed', 'abandoned']);
    }

    /**
     * Parked by this gateway while an asynchronous payment finishes.
     *
     * @phpstan-impure
     */
    public function waiting(): bool
    {
        return !$this->paid() && $this->hasStatus(['processing']) && $this->text(Meta::WAITING) === 'yes';
    }

    /**
     * Whether a verified payment may complete the order.
     *
     * @phpstan-impure
     */
    public function canSettle(): bool
    {
        return $this->payable() || $this->waiting();
    }

    /** @param string[] $statuses */
    private function hasStatus(array $statuses): bool
    {
        return in_array((string) $this->order->status, $statuses, true);
    }

    public function requiresSave(): bool
    {
        return stackless_edd_order_requires_saved_payment_method($this->id());
    }

    public function isRenewal(): bool
    {
        return stackless_edd_is_renewal_order($this->id());
    }

    /** @return string[] The saved methods the order, then its subscriptions, point at. */
    public function tokenIds(): array
    {
        $ids = [$this->text(SubscriptionData::META_TOKEN_ID)];
        foreach (stackless_edd_get_order_subscriptions($this->id()) as $subscription) {
            if ($subscription instanceof SubscriptionData) {
                $value = $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
                $ids[] = is_scalar($value) ? (string) $value : '';
            }
        }
        return array_values(array_filter($ids));
    }

    public function note(string $note): void
    {
        stackless_edd_add_order_note($this->id(), $note);
    }

    private function setStatus(string $status, string $note = ''): void
    {
        if ((string) $this->order->status !== $status) {
            edd_update_order_status($this->id(), $status);
            $this->reload();
        }
        if ($note !== '') {
            $this->note($note);
        }
    }

    /** Waiting for an asynchronous payment: not recoverable, so not payable again. */
    public function wait(string $note): void
    {
        $this->put([Meta::WAITING => 'yes']);
        $this->setStatus('processing', $note);
    }

    /** A person has to look at it. */
    public function hold(string $note): void
    {
        $this->setStatus('on_hold', $note);
    }

    /** EDD's own dispute record: On hold, with file access paused. */
    public function dispute(string $disputeId, string $note): void
    {
        if ($this->hasStatus(['complete', 'partially_refunded'])) {
            if (function_exists('edd_record_order_dispute')) {
                edd_record_order_dispute($this->id(), $disputeId, 'chargeback');
            } else {
                edd_update_order_status($this->id(), 'on_hold');
            }
            $this->reload();
        }
        $this->note($note);
    }

    public function fail(string $note): void
    {
        if ($this->hasStatus(['failed']) || !$this->canSettle()) {
            $this->note($note);
            return;
        }
        $this->setStatus('failed', $note);
    }

    public function complete(string $transactionId = ''): bool
    {
        stackless_edd_complete_order($this->id(), $transactionId);
        $this->reload();
        return $this->paid();
    }

    public function payUrl(): string
    {
        $url = stackless_edd_order_pay_url($this->id());
        return $url !== '' ? $url : (string) edd_get_checkout_uri();
    }

    public function receiptUrl(): string
    {
        return Returns::successUrl();
    }

    /** @param float|int|string $amount Plain text, for notes. */
    public function money($amount, string $currency = ''): string
    {
        $currency = strtoupper($currency !== '' ? $currency : $this->currency());
        return wp_strip_all_tags(html_entity_decode(stackless_edd_price($amount, $currency), ENT_QUOTES, 'UTF-8'));
    }

    public function remainingRefund(): float
    {
        return function_exists('edd_get_order_total') ? (float) edd_get_order_total($this->id()) : (float) $this->order->total;
    }

    /** A refund made in Airwallex. It never calls the gateway back. */
    public function recordRefund(float $amount, string $refundId, string $reason): bool
    {
        try {
            AdminRefunds::recordExternal($this->order, $amount, $refundId, $reason);
        } catch (\Throwable $e) {
            Settings::log('Could not record Airwallex refund ' . $refundId . ' on order ' . $this->id() . ': ' . $e->getMessage(), 'error');
            return false;
        }
        $this->reload();
        return true;
    }
}
