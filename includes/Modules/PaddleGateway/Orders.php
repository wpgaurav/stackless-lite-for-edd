<?php

namespace StacklessEDD\Modules\PaddleGateway;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * EDD order helpers for the Paddle gateway: meta, lookups, holds, and recording a refund
 * made at Paddle as an EDD refund (EDD refunds are per order item, so an amount is spread
 * over the items and fees first).
 */
final class Orders
{
    /** Statuses that mean the order was paid at some point (complete, then possibly refunded or revoked). */
    public const PAID = ['complete', 'publish', 'completed', 'partially_refunded', 'refunded', 'revoked'];

    public static function get(int $id): ?Order
    {
        $order = $id > 0 ? edd_get_order($id) : false;
        return $order instanceof Order ? $order : null;
    }

    public static function isPaid(Order $order): bool
    {
        return in_array((string) $order->status, self::PAID, true);
    }

    public static function meta(int $id, string $key): string
    {
        $value = edd_get_order_meta($id, $key, true);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param mixed $value */
    public static function setMeta(int $id, string $key, $value): void
    {
        edd_update_order_meta($id, $key, $value);
    }

    public static function deleteMeta(int $id, string $key): void
    {
        edd_delete_order_meta($id, $key);
    }

    /** The newest sale order (any status) whose $key meta is exactly $value; re-checked on the order. */
    public static function findByMeta(string $key, string $value, string $gateway = ''): ?Order
    {
        foreach (self::findAllByMeta($key, $value, 5) as $order) {
            if ($gateway === '' || $order->gateway === $gateway) {
                return $order;
            }
        }
        return null;
    }

    /** @return Order[] newest first */
    public static function findAllByMeta(string $key, string $value, int $limit = 5): array
    {
        if ($key === '' || $value === '') {
            return [];
        }
        $orders = edd_get_orders([
            'type__not_in' => ['refund'],
            'meta_query' => [['key' => $key, 'value' => $value]], // phpcs:ignore WordPress.DB.SlowDBQuery
            'number' => $limit,
            'orderby' => 'id',
            'order' => 'DESC',
        ]);
        $found = [];
        foreach ((array) $orders as $order) {
            if ($order instanceof Order && self::meta((int) $order->id, $key) === $value) {
                $found[] = $order;
            }
        }
        return $found;
    }

    /** Put an order on hold with a note (charged amount or currency does not match). */
    public static function hold(Order $order, string $note): void
    {
        edd_update_order_status((int) $order->id, 'on_hold');
        stackless_edd_add_order_note((int) $order->id, $note);
    }

    public static function setGateway(Order $order, string $gateway): void
    {
        if ($order->gateway !== $gateway) {
            edd_update_order((int) $order->id, ['gateway' => $gateway]);
        }
    }

    /** What is left to refund on an order: its total minus earlier refunds. */
    public static function remainingRefund(Order $order): float
    {
        return max(0.0, (float) edd_get_order_total((int) $order->id));
    }

    /**
     * Refundable rows of an order for allocate(): items, then fees.
     *
     * @return array<int,array{kind:string,id:int,quantity:int,tax:float,total:float}>
     */
    public static function refundables(Order $order): array
    {
        $rows = [];
        foreach ($order->get_items() as $item) {
            if ((string) $item->status === 'refunded') {
                continue;
            }
            $max = (array) $item->get_refundable_amounts();
            $rows[] = ['kind' => 'item', 'id' => (int) $item->id, 'quantity' => max(1, (int) ($max['quantity'] ?? $item->quantity)), 'tax' => (float) ($max['tax'] ?? 0), 'total' => (float) ($max['total'] ?? 0)];
        }
        foreach ($order->get_fees() as $fee) {
            $max = (array) $fee->get_refundable_amounts();
            $rows[] = ['kind' => 'adjustment', 'id' => (int) $fee->id, 'quantity' => 1, 'tax' => (float) ($max['tax'] ?? 0), 'total' => (float) ($max['total'] ?? 0)];
        }
        return $rows;
    }

    /**
     * Spread a refund amount over refundable rows, first rows first, splitting each row's share
     * between subtotal and tax in the row's own proportion. Pure.
     *
     * @param array<int,array{kind:string,id:int,quantity:int,tax:float,total:float}> $rows
     * @return array{items:array<int,array<string,mixed>>,adjustments:array<int,array<string,mixed>>,left:float}
     */
    public static function allocate(float $amount, array $rows, int $decimals): array
    {
        $unit = 10 ** max(0, $decimals);
        $left = (int) round($amount * $unit);
        $items = [];
        $adjustments = [];
        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $total = (int) round((float) $row['total'] * $unit);
            if ($total <= 0) {
                continue;
            }
            $take = min($left, $total);
            $tax = max(0, (int) round((float) $row['tax'] * $unit));
            $taxPart = max(0, min($tax, (int) round($take * $tax / $total)));
            $amounts = [
                'subtotal' => round(($take - $taxPart) / $unit, $decimals),
                'tax' => round($taxPart / $unit, $decimals),
                'total' => round($take / $unit, $decimals),
            ];
            if ($row['kind'] === 'item') {
                // Units only for what the amount covers: a small partial refund takes none, so access stays.
                $units = $take >= $total ? max(1, (int) $row['quantity']) : intdiv(max(1, (int) $row['quantity']) * $take, $total);
                $items[] = ['order_item_id' => (int) $row['id'], 'quantity' => $units] + $amounts;
            } else {
                $adjustments[] = ['adjustment_id' => (int) $row['id']] + $amounts;
            }
            $left -= $take;
        }
        return ['items' => $items, 'adjustments' => $adjustments, 'left' => round(max(0, $left) / $unit, $decimals)];
    }

    /**
     * Record a refund made at Paddle as an EDD refund of $amount (in the order's terms). Never sends
     * anything back to Paddle: Refunds::onRefund() ignores refunds recorded here.
     *
     * @return int|\WP_Error the EDD refund id
     */
    public static function recordRefund(Order $order, float $amount)
    {
        $decimals = Money::decimals((string) $order->currency);
        $remaining = self::remainingRefund($order);
        if ($amount <= 0 || $remaining <= 0) {
            return new \WP_Error('stackless_edd_paddle_refund', __('Nothing is left to refund on this order.', 'stackless-lite-edd'));
        }
        if (Money::toMinor($amount, (string) $order->currency) >= Money::toMinor($remaining, (string) $order->currency)) {
            $items = 'all';
            $adjustments = 'all';
        } else {
            $plan = self::allocate($amount, self::refundables($order), $decimals);
            if ($plan['left'] > 0 || (!$plan['items'] && !$plan['adjustments'])) {
                return new \WP_Error('stackless_edd_paddle_refund', __('The refund could not be spread over the order items.', 'stackless-lite-edd'));
            }
            $items = $plan['items'];
            $adjustments = $plan['adjustments'];
        }
        $orderId = (int) $order->id;
        // A webhook has no signed-in user; the refund window does not apply to money Paddle already returned.
        $override = static function ($can, $id) use ($orderId) {
            return (int) $id === $orderId ? true : $can;
        };
        add_filter('edd_is_order_refundable_by_override', $override, 10, 2);
        Refunds::$mirroring = true;
        try {
            return edd_refund_order($orderId, $items, $adjustments);
        } finally {
            Refunds::$mirroring = false;
            remove_filter('edd_is_order_refundable_by_override', $override, 10);
        }
    }
}
