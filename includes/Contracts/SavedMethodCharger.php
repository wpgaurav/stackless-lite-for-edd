<?php

namespace StacklessEDD\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a Core\Gateway that can charge a customer's saved payment method for a new
 * order outside the renewal schedule (2.0.0). Post-purchase Offers use it.
 *
 * The caller has already created $order (pending, totals final, gateway set to this gateway,
 * same customer) and owns the customer's consent: it only calls this after an explicit click.
 * $token_id is the value this gateway writes to SubscriptionData::META_TOKEN_ID (also the `id`
 * of its rows in stackless_edd_get_saved_payment_methods()). The gateway must check again that
 * the method belongs to this gateway and to the order's customer, and refuse otherwise.
 *
 * Must be idempotent per order and token (idempotency key stackless-edd-saved-{order}-{token}-{on|off})
 * and must refuse off-session charges unless \StacklessEDD\Core\LiveStore::isLive().
 *
 * On success the gateway completes the order itself with stackless_edd_complete_order().
 * Before failing on a decline it writes SubscriptionData::META_DECLINE / META_DECLINE_CODE on the order.
 */
interface SavedMethodCharger
{
    /**
     * @param bool $on_session true when the customer is on the page and can authenticate
     *                         (3-D Secure, payer approval); false for unattended charges.
     * @return array{result:string, message?:string, redirect?:string}
     *         result: success | pending (async, finished by webhook) | failed |
     *         action_required (redirect = a page where the customer completes authentication;
     *         the order stays pending until then).
     */
    public function stackless_edd_charge_saved_method(\EDD\Orders\Order $order, string $token_id, bool $on_session): array;
}
