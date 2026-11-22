<?php

namespace StacklessEDD\Modules\AirwallexGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/** The few order lookups Billing Health needs. Payments never look an order up by a query. */
final class OrderLists
{
    private const LIMIT = 20;

    /** @return string[] Numbers of orders with money a person must look at. */
    public static function needingReview(): array
    {
        return self::numbers([], Meta::REVIEW);
    }

    /** @return string[] Numbers of charges without a result since $before. */
    public static function stuck(int $before): array
    {
        return self::numbers([
            'status' => 'pending',
            'gateway' => Settings::GATEWAY_ID,
            'date_created_query' => ['before' => gmdate('Y-m-d H:i:s', $before)],
        ], Meta::OFF_SESSION);
    }

    /**
     * @param array<string,mixed> $args
     * @return string[]
     */
    private static function numbers(array $args, string $key): array
    {
        if (!function_exists('edd_get_orders')) {
            return [];
        }
        $args += [
            'number' => self::LIMIT,
            'meta_query' => [['key' => $key, 'value' => 'yes']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Billing Health only, at most 20 rows.
        ];
        $numbers = [];
        foreach ((array) edd_get_orders($args) as $order) {
            if ($order instanceof Order && (string) edd_get_order_meta((int) $order->id, $key, true) === 'yes') {
                $number = (string) $order->get_number();
                $numbers[] = '#' . ($number !== '' ? $number : $order->id);
            }
        }
        return $numbers;
    }

    public static function url(): string
    {
        return admin_url('edit.php?post_type=download&page=edd-payment-history');
    }

    public static function logsUrl(): string
    {
        return admin_url('edit.php?post_type=download&page=edd-tools&tab=debug_log');
    }
}
