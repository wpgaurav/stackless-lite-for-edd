<?php

namespace StacklessEDD\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * While a Braintree charge of an order has no known result, paying that order another way would
 * charge the customer twice. Braintree itself stays offered: paying with it settles the open
 * charge first and only charges when that one is unpaid or absent.
 */
final class Guard
{
    public static function registerHooks(): void
    {
        add_action('edd_checkout_error_checks', [self::class, 'guardCheckout'], 5, 2);
        add_filter('stackless_edd_order_pay_link_allowed', [self::class, 'payLink'], 10, 2);
    }

    public static function waiting(int $order_id): bool
    {
        if ($order_id < 1 || !Attempt::current($order_id)) {
            return false;
        }
        $order = Orders::get($order_id);
        return $order && !Orders::isPaid($order);
    }

    /**
     * A pay link resumes its order through the session (edd_resume_payment).
     *
     * @param mixed $valid_data
     * @param mixed $post
     */
    public static function guardCheckout($valid_data, $post = []): void
    {
        $gateway = is_array($valid_data) ? (string) ($valid_data['gateway'] ?? '') : '';
        if ($gateway === Gateway::ID || !function_exists('EDD') || !EDD()->session) {
            return;
        }
        if (is_array($post) && !empty($post[Gateway::FIELD_SETUP])) {
            return;
        }
        if (self::waiting(absint(EDD()->session->get('edd_resume_payment')))) {
            edd_set_error('stackless_edd_braintree_confirming', __('An earlier card payment for this order is still being confirmed. Wait a little before paying another way, so you are not charged twice.', 'stackless-lite-edd')); // @phpstan-ignore argument.type
        }
    }

    /**
     * @param mixed $allowed
     * @param mixed $order_id
     */
    public static function payLink($allowed, $order_id = 0): bool
    {
        return (bool) $allowed && !self::waiting((int) $order_id);
    }
}
