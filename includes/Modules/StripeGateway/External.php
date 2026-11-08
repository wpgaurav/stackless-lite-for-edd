<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Subscriptions another plugin billed through this store's Stripe account (2.0.0), for the
 * EDD Recurring migrator:
 *
 *  - stackless_edd_adopt_external_subscription: saves the payment method a Stripe Billing
 *    subscription charges as a Stackless Stripe token, so Stackless can renew on the same card.
 *    Cancels nothing.
 *  - stackless_edd_cancel_external_subscription: cancels a Stripe Billing subscription now,
 *    without proration or a final invoice. Only on the live store and in the mode the store's
 *    keys are set to.
 *
 * Both answer only for this gateway's id and keep an earlier answer.
 */
final class External
{
    /**
     * @param mixed $value
     * @param mixed $gateway_id
     * @param mixed $args profile_id (sub_…), customer (cus_…), user_id, email, mode (live|test), source
     * @return mixed array{token_id:string, customer:string}|\WP_Error
     */
    public static function adopt($value, $gateway_id = '', $args = [])
    {
        if ($value !== null || (string) $gateway_id !== Settings::GATEWAY_ID) {
            return $value;
        }
        $args = is_array($args) ? $args : [];
        $profile = trim((string) ($args['profile_id'] ?? ''));
        $customer = trim((string) ($args['customer'] ?? ''));
        $mode = self::mode($args['mode'] ?? '');
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $profile)) {
            return new \WP_Error('stackless_edd_stripe_adopt_invalid', __('A Stripe subscription id (sub_…) is required.', 'stackless-lite-edd'));
        }
        if ($mode === '') {
            return new \WP_Error('stackless_edd_stripe_adopt_invalid', __('The Stripe mode (live or test) is required.', 'stackless-lite-edd'));
        }
        if (!Settings::isConfigured($mode)) {
            /* translators: %s: test or live */
            return new \WP_Error('stackless_edd_stripe_adopt_keys', sprintf(__('Add the Stripe %s keys before moving subscriptions.', 'stackless-lite-edd'), $mode));
        }

        $client = Settings::client($mode);
        try {
            $remote = $client->get('subscriptions/' . rawurlencode($profile));
            $owner = self::idOf($remote['customer'] ?? '');
            if ($customer !== '' && $owner !== '' && !hash_equals($owner, $customer)) {
                return new \WP_Error('stackless_edd_stripe_adopt_customer', __('The Stripe subscription belongs to another Stripe customer.', 'stackless-lite-edd'));
            }
            $customer = $customer !== '' ? $customer : $owner;
            $method = self::subscriptionMethod($remote);
            if ($method === '' && $customer !== '') {
                $method = self::customerMethod($client->get('customers/' . rawurlencode($customer)));
            }
        } catch (StripeApiException $e) {
            Settings::log('Adopting subscription ' . $profile . ' failed: ' . $e->logSummary(), 'warning');
            return new \WP_Error('stackless_edd_stripe_adopt_lookup', $e->isResourceMissing()
                ? __('Stripe has no such subscription or customer on this account.', 'stackless-lite-edd')
                : $e->getMessage());
        }
        if ($method === '') {
            return new \WP_Error('stackless_edd_stripe_adopt_method', __('The Stripe subscription has no default payment method to renew with.', 'stackless-lite-edd'));
        }

        $token = Adoption::adoptPaymentMethod(null, [
            'payment_method' => $method,
            'customer' => $customer,
            'user_id' => (int) ($args['user_id'] ?? 0),
            'mode' => $mode,
        ]);
        if (is_wp_error($token)) {
            return $token;
        }
        if (!is_int($token) || $token <= 0) {
            return new \WP_Error('stackless_edd_stripe_adopt_save', __('The payment method could not be saved.', 'stackless-lite-edd'));
        }
        return ['token_id' => (string) $token, 'customer' => $customer];
    }

    /**
     * @param mixed $value
     * @param mixed $gateway_id
     * @param mixed $args profile_id (sub_…), mode (live|test), source
     * @return mixed true|\WP_Error
     */
    public static function cancel($value, $gateway_id = '', $args = [])
    {
        if ($value !== null || (string) $gateway_id !== Settings::GATEWAY_ID) {
            return $value;
        }
        $args = is_array($args) ? $args : [];
        $profile = trim((string) ($args['profile_id'] ?? ''));
        $mode = self::mode($args['mode'] ?? '');
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $profile)) {
            return new \WP_Error('stackless_edd_stripe_cancel_invalid', __('A Stripe subscription id (sub_…) is required.', 'stackless-lite-edd'));
        }
        $refusal = self::refusal($mode);
        if ($refusal) {
            return $refusal;
        }
        $client = Settings::client($mode);
        try {
            $remote = $client->get('subscriptions/' . rawurlencode($profile));
            if (in_array((string) ($remote['status'] ?? ''), ['canceled', 'incomplete_expired'], true)) {
                return true;
            }
            $client->request('DELETE', 'subscriptions/' . rawurlencode($profile), ['prorate' => false, 'invoice_now' => false]);
        } catch (StripeApiException $e) {
            if ($e->isResourceMissing()) {
                return new \WP_Error('stackless_edd_stripe_cancel_lookup', __('Stripe has no such subscription on this account.', 'stackless-lite-edd'));
            }
            Settings::log('Cancel of external subscription ' . $profile . ' failed: ' . $e->logSummary(), 'error');
            return new \WP_Error('stackless_edd_stripe_cancel', $e->getMessage());
        }
        Settings::log(sprintf('Cancelled Stripe subscription %s (%s) for %s.', $profile, $mode, sanitize_text_field((string) ($args['source'] ?? ''))), 'info');
        return true;
    }

    /** Why the store may not cancel at Stripe in $mode, or null. */
    public static function refusal(string $mode): ?\WP_Error
    {
        if ($mode === '') {
            return new \WP_Error('stackless_edd_stripe_cancel_invalid', __('The Stripe mode (live or test) is required.', 'stackless-lite-edd'));
        }
        if (!LiveStore::isLive()) {
            return LiveStore::copyError(__('This site is a copy of the live store, so it does not cancel subscriptions at Stripe.', 'stackless-lite-edd'));
        }
        if ($mode !== Settings::mode() || !Settings::isConfigured($mode)) {
            /* translators: %s: test or live */
            return new \WP_Error('stackless_edd_stripe_cancel_mode', sprintf(__('The subscription is in Stripe %s mode, but the Stripe settings are not set up for that mode.', 'stackless-lite-edd'), $mode));
        }
        return null;
    }

    /** The method a subscription charges: its default_payment_method, else its legacy default_source. */
    public static function subscriptionMethod(array $remote): string
    {
        $method = self::idOf($remote['default_payment_method'] ?? '');
        return $method !== '' ? $method : self::idOf($remote['default_source'] ?? '');
    }

    /** A customer's invoice default (invoice_settings.default_payment_method), else its legacy default_source. */
    public static function customerMethod(array $customer): string
    {
        $method = self::idOf($customer['invoice_settings']['default_payment_method'] ?? '');
        return $method !== '' ? $method : self::idOf($customer['default_source'] ?? '');
    }

    /** @param mixed $mode */
    private static function mode($mode): string
    {
        return $mode === 'live' || $mode === 'test' ? $mode : '';
    }

    /** @param mixed $value Expandable field: id string or object. */
    private static function idOf($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return is_string($value) ? $value : '';
    }
}
