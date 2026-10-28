<?php

namespace StacklessEDD\Modules\PolarGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * The two things Polar sync needs from a subscription beyond Contracts\SubscriptionData:
 * a note, and a status change that Polar (not the store) decided.
 *
 * Both go through the shared functions: cancellations through stackless_edd_cancel_subscription()
 * ($by = 'system'), provider-side on-hold, expiry and resumption through
 * stackless_edd_update_subscription_status(), notes through stackless_edd_add_subscription_note().
 * A status change that came from Polar never calls Polar back (see Gateway::fromProvider()).
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

    /** Apply a status Polar reported. True when the subscription now has it. */
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
}
