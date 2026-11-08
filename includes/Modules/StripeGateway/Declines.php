<?php

namespace StacklessEDD\Modules\StripeGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Writes the decline category and the provider's own code on an order (2.0.0).
 *
 * Every renewal or switch charge that fails writes both BEFORE the order turns failed,
 * because Dunning reacts to that status change and reads them then.
 */
final class Declines
{
    public static function write(int $order_id, string $category, string $code): void
    {
        if (!in_array($category, SubscriptionData::DECLINES, true)) {
            $category = SubscriptionData::DECLINE_SOFT;
        }
        Orders::updateMeta($order_id, SubscriptionData::META_DECLINE, $category);
        // sanitize_key() without the filter: lowercase letters, digits, dashes and underscores.
        Orders::updateMeta($order_id, SubscriptionData::META_DECLINE_CODE, substr(strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $code)), 0, 64));
    }

    /** @param array<string,mixed> $error A PaymentIntent's last_payment_error. */
    public static function fromIntentError(int $order_id, array $error): void
    {
        $code = (string) ($error['decline_code'] ?? '') ?: (string) ($error['code'] ?? '');
        self::write($order_id, ErrorMapper::declineCategoryFromIntentError($error), $code !== '' ? $code : 'declined');
    }

    public static function fromException(int $order_id, StripeApiException $e): void
    {
        $code = $e->getDeclineCode() ?: $e->getStripeCode();
        if ($code === '') {
            $code = $e->getHttpStatus() === 0 ? 'api_connection_error' : 'http_' . $e->getHttpStatus();
        }
        self::write($order_id, ErrorMapper::declineCategoryFromException($e), $code);
    }
}
