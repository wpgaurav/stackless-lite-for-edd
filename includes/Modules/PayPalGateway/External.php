<?php

namespace StacklessEDD\Modules\PayPalGateway;

use StacklessEDD\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * stackless_edd_cancel_external_subscription for PayPal (2.0.0): cancels a PayPal Subscriptions
 * API subscription (I-…) another plugin created on this store's PayPal app, effective now and
 * without a refund, for the EDD Recurring migrator. Only on the live store and in the PayPal
 * environment the store is set to. Answers only for this gateway's id and keeps an earlier answer.
 */
final class External
{
    /**
     * @param mixed $value
     * @param mixed $gateway_id
     * @param mixed $args profile_id (I-…), mode (live|test), source
     * @return mixed true|\WP_Error
     */
    public static function cancel($value, $gateway_id = '', $args = [])
    {
        if ($value !== null || (string) $gateway_id !== Gateway::ID) {
            return $value;
        }
        $args = is_array($args) ? $args : [];
        $profile = trim((string) ($args['profile_id'] ?? ''));
        if (!preg_match('/^I-[A-Z0-9]+$/', $profile)) {
            return new \WP_Error('stackless_edd_paypal_cancel_invalid', __('A PayPal subscription id (I-…) is required.', 'stackless-lite-edd'));
        }
        $gateway = Gateway::instance();
        $refusal = self::refusal((string) ($args['mode'] ?? ''), $gateway->environment(), $gateway->api()->hasCredentials());
        if ($refusal) {
            return $refusal;
        }
        $api = $gateway->api();
        $path = 'v1/billing/subscriptions/' . rawurlencode($profile);
        $remote = $api->request('GET', $path);
        if (is_wp_error($remote)) {
            $gateway->log('Cancel lookup for PayPal subscription ' . $profile . ' failed: ' . $remote->get_error_message(), 'error');
            return $remote;
        }
        if (self::isClosed((string) ($remote['status'] ?? ''))) {
            return true;
        }
        $result = $api->request('POST', $path . '/cancel', [
            'reason' => __('Moved to Stackless subscriptions on this store.', 'stackless-lite-edd'),
        ]);
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            // Closed between the lookup and the cancel.
            if ((int) ($data['status'] ?? 0) === 422 && (string) ($data['issue'] ?? '') === 'SUBSCRIPTION_STATUS_INVALID') {
                return true;
            }
            $gateway->log('Cancel of PayPal subscription ' . $profile . ' failed: ' . $result->get_error_message(), 'error');
            return $result;
        }
        $gateway->log(sprintf('Cancelled PayPal subscription %s for %s.', $profile, sanitize_text_field((string) ($args['source'] ?? ''))), 'info');
        return true;
    }

    public static function isClosed(string $status): bool
    {
        return in_array(strtoupper($status), ['CANCELLED', 'EXPIRED'], true);
    }

    /** Why the store may not cancel at PayPal, or null. $mode is live|test, $environment live|sandbox. */
    public static function refusal(string $mode, string $environment, bool $hasCredentials): ?\WP_Error
    {
        if ($mode !== 'live' && $mode !== 'test') {
            return new \WP_Error('stackless_edd_paypal_cancel_invalid', __('The PayPal mode (live or test) is required.', 'stackless-lite-edd'));
        }
        if (!LiveStore::isLive()) {
            return LiveStore::copyError(__('This site is a copy of the live store, so it does not cancel subscriptions at PayPal.', 'stackless-lite-edd'));
        }
        if (($mode === 'test' ? 'sandbox' : 'live') !== $environment || !$hasCredentials) {
            return new \WP_Error('stackless_edd_paypal_cancel_mode', $mode === 'test'
                ? __('The subscription is in PayPal sandbox mode, but the PayPal settings are not set up for the sandbox.', 'stackless-lite-edd')
                : __('The subscription is in PayPal live mode, but the PayPal settings are not set up for live payments.', 'stackless-lite-edd'));
        }
        return null;
    }
}
