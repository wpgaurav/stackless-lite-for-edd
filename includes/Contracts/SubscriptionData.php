<?php

namespace StacklessEDD\Contracts;

defined('ABSPATH') || exit;

/**
 * What every other module may rely on from a subscription object.
 *
 * The Subscriptions module stores subscriptions in its own tables ({prefix}stackless_edd_subscriptions
 * and _subscription_items) and implements this interface on its Subscription class. Renewal and
 * switch orders are ordinary EDD orders linked through order meta.
 *
 * Dates are UTC DateTimeImmutable objects (null when not set).
 */
interface SubscriptionData
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIAL = 'trialling';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_PENDING_CANCEL = 'pending_cancel';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /** Order meta a gateway writes on the PARENT order before completing it; copied to each subscription. */
    public const META_TOKEN_ID = '_stackless_edd_payment_token_id';
    public const META_GATEWAY_CUSTOMER = '_stackless_edd_gateway_customer_id';
    public const META_GATEWAY_SUBSCRIPTION = '_stackless_edd_gateway_subscription_id';

    /** Order meta on renewal and switch orders. */
    public const META_RENEWAL_OF = '_stackless_edd_renewal_of';
    public const META_SWITCH_OF = '_stackless_edd_switch_subscription_id';

    public function get_id(): int;

    /** One of the STATUS_* constants. */
    public function get_status(): string;

    /** EDD customer id. */
    public function get_customer_id(): int;

    /** WordPress user id, 0 for a guest purchase. */
    public function get_user_id(): int;

    public function get_email(): string;

    /** Locked at signup; renewals never change it. */
    public function get_currency(): string;

    /** EDD gateway id the subscription renews through. */
    public function get_gateway(): string;

    public function get_parent_order_id(): int;

    /**
     * @return array<int, array{download_id:int, price_id:int|null, quantity:int, amount:string, name:string}>
     *         amount is the recurring amount per cycle for the line, a decimal string in the subscription currency.
     */
    public function get_items(): array;

    /** @return mixed */
    public function get_meta(string $key, bool $single = true);

    /** @param mixed $value */
    public function update_meta(string $key, $value): void;

    /** Active, trialing or pending-cancel. */
    public function is_active(): bool;

    /** day|week|month|year */
    public function get_billing_period(): string;

    public function get_billing_interval(): int;

    public function get_next_payment_date(): ?\DateTimeImmutable;

    public function get_trial_end_date(): ?\DateTimeImmutable;

    /** Null = runs until cancelled. */
    public function get_end_date(): ?\DateTimeImmutable;

    /** Gateway-managed billing (Polar, Paddle, Dodo) syncs the provider's date through this. */
    public function set_next_payment_date(?\DateTimeImmutable $date): void;

    public function has_product(int $download_id): bool;

    /** "auto" or "manual" */
    public function get_renewal_mode(): string;

    /** Recurring total per cycle in the subscription's own currency. */
    public function get_recurring_total(): float;
}
