<?php

namespace StacklessEDD\Modules\DodoGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Stackless subscriptions as Dodo sees them: lookups by Dodo subscription id, status
 * changes Dodo reports, and notes. Everything goes through the stackless_edd_* functions.
 */
final class Subscriptions
{
    /** True while a status change Dodo already made is applied; the gateway's cancel hook then skips the API. */
    public static $applying = false;

    /** A fresh copy of a subscription (statuses and meta change underneath during a webhook). */
    public static function fresh(SubscriptionData $subscription): SubscriptionData
    {
        $fresh = stackless_edd_get_subscription($subscription->get_id());
        return $fresh instanceof SubscriptionData ? $fresh : $subscription;
    }

    /** A note on the subscription (falls back to the parent order's notes). */
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

    /**
     * Move a subscription to the status Dodo reports. Uses stackless_edd_update_subscription_status()
     * when the Subscriptions module provides it; otherwise cancellations and reactivations go through
     * the documented cancel and reactivate functions, flagged so the gateway does not call Dodo back.
     */
    public static function setStatus(SubscriptionData $subscription, string $status, string $note): bool
    {
        $id = $subscription->get_id();
        if (function_exists('stackless_edd_update_subscription_status')) {
            $result = stackless_edd_update_subscription_status($id, $status, $note);
            return $result === true;
        }

        $current = $subscription->get_status();
        $result = null;
        self::$applying = true;
        try {
            if ($status === SubscriptionData::STATUS_PENDING_CANCEL) {
                $result = stackless_edd_cancel_subscription($id, true, 'system');
            } elseif ($status === SubscriptionData::STATUS_CANCELLED) {
                $result = stackless_edd_cancel_subscription($id, false, 'system');
            } elseif (in_array($status, [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL], true)
                && in_array($current, [SubscriptionData::STATUS_PENDING_CANCEL, SubscriptionData::STATUS_ON_HOLD], true)) {
                $result = stackless_edd_reactivate_subscription($id, 'system');
            }
        } finally {
            self::$applying = false;
        }
        if ($result === true) {
            self::note($subscription, $note);
            return true;
        }
        // No way to apply this one here (on hold, expired): keep the provider's word on record.
        self::note($subscription, $note);
        return false;
    }

    /** ISO 8601 date from Dodo → UTC DateTimeImmutable, or null. */
    public static function date(string $iso): ?\DateTimeImmutable
    {
        if ($iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
