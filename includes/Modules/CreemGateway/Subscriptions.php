<?php

namespace StacklessEDD\Modules\CreemGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * The two things Creem sync needs from a subscription beyond Contracts\SubscriptionData:
 * a note, and a status change that Creem (not the store) decided.
 *
 * Both go through the shared functions: cancellations through stackless_edd_cancel_subscription()
 * ($by = 'system'), provider-side on-hold, expiry and resumption through
 * stackless_edd_update_subscription_status(), notes through stackless_edd_add_subscription_note().
 * A status change that came from Creem never calls Creem back (see Gateway::fromProvider()).
 */
final class Subscriptions
{
    public static function note(SubscriptionData $subscription, string $note): void
    {
        if ($note === '') {
            return;
        }
        if (function_exists('stackless_edd_add_subscription_note')) {
            stackless_edd_add_subscription_note($subscription->get_id(), $note);
            return;
        }
        /* translators: 1: subscription id, 2: note */
        stackless_edd_add_order_note($subscription->get_parent_order_id(), sprintf(__('Subscription #%1$d: %2$s', 'stackless-lite-edd'), $subscription->get_id(), $note));
    }

    /** Apply a status Creem reported. True when the subscription now has it. */
    public static function setStatus(SubscriptionData $subscription, string $status, string $note): bool
    {
        $id = $subscription->get_id();
        if (in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_PENDING_CANCEL], true)) {
            Gateway::fromProvider(true);
            try {
                $result = stackless_edd_cancel_subscription($id, $status === SubscriptionData::STATUS_PENDING_CANCEL, 'system');
            } finally {
                Gateway::fromProvider(false);
            }
            if ($result !== true) {
                return false;
            }
            self::note($subscription, $note);
            return true;
        }
        return stackless_edd_update_subscription_status($id, $status, $note) === true;
    }

    /** A fresh copy of a subscription (after other code changed it), or null when it is gone. */
    public static function fresh(int $id): ?SubscriptionData
    {
        $subscription = $id > 0 ? stackless_edd_get_subscription($id) : null;
        return $subscription instanceof SubscriptionData ? $subscription : null;
    }

    public static function metaString(SubscriptionData $subscription, string $key): string
    {
        $value = $subscription->get_meta($key);
        return is_scalar($value) ? (string) $value : '';
    }
}
