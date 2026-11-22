<?php

namespace StacklessEDD\Core;

use EDD\Orders\Order;

defined('ABSPATH') || exit;

/**
 * Payments a provider took that their order was not waiting for: a replaced checkout paid later,
 * a payment after the buyer switched method, a second payment on a paid order.
 *
 * Each is noted on its order, which is held while it still awaits payment, and stays in an admin
 * notice, with the payment id to refund, until it is refunded or the merchant marks it handled.
 */
final class ExtraPayments
{
    public const OPTION = 'stackless_edd_extra_payments';
    public const ACTION = 'stackless_edd_extra_payment_done';

    /** Order meta, one row per payment flagged on the order, kept so a redelivery is noted once. */
    public const META = '_stackless_edd_extra_payment';

    /** Order meta, one row per subscription an extra payment started: never the order's own. */
    public const META_SUBSCRIPTION = '_stackless_edd_extra_subscription';

    private const CAPABILITY = 'edit_shop_payments';

    public static function boot(): void
    {
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /** @return array<string,array<string,mixed>> payment id => what is known about it */
    public static function all(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }

    /**
     * Record a payment the order was not waiting for. Call with the order's lock held.
     *
     * @param string $paymentId    The provider's payment id, the one to refund.
     * @param int    $minor        What was paid, in the lowest denomination (0 when not known).
     * @param string $amount       The same as plain text, '' when not known.
     * @param bool   $testOnLive   A test payment on a live order: noted, never holds the order.
     * @param string $subscription The provider's subscription it started, to cancel there.
     */
    public static function flag(string $provider, Order $order, string $paymentId, int $minor, string $amount, bool $testOnLive = false, string $subscription = ''): string
    {
        $orderId = (int) $order->id;
        if ($paymentId === '') {
            return 'extra payment';
        }
        $seen = in_array($paymentId, array_map('strval', (array) edd_get_order_meta($orderId, self::META, false)), true);
        // A provider may name the subscription only in a later event about the same payment.
        $named = $subscription !== '' && !self::extraSubscription($orderId, $subscription);
        if ($seen && !$named) {
            return 'extra payment';
        }
        if ($named) {
            edd_add_order_meta($orderId, self::META_SUBSCRIPTION, $subscription);
        }

        $row = [
            'provider' => $provider,
            'order' => $orderId,
            'minor' => $minor,
            'amount' => $amount,
            'test' => $testOnLive,
            'subscription' => $subscription,
        ];
        if ($seen) {
            self::change(static function (array $all) use ($paymentId, $subscription): array {
                if (isset($all[$paymentId])) {
                    $all[$paymentId]['subscription'] = $subscription;
                }
                return $all;
            });
            stackless_edd_add_order_note($orderId, self::sentence($paymentId, $row));
            return 'extra payment';
        }
        edd_add_order_meta($orderId, self::META, $paymentId);
        self::change(static function (array $all) use ($paymentId, $row): array {
            $all[$paymentId] = $row;
            return $all;
        });

        stackless_edd_add_order_note($orderId, self::sentence($paymentId, $row));
        $fresh = edd_get_order($orderId);
        // Held, so it is neither delivered nor charged again before the merchant has looked.
        if (!$testOnLive && $fresh instanceof Order && in_array((string) $fresh->status, edd_recoverable_order_statuses(), true)) {
            edd_update_order_status($orderId, 'on_hold');
        }
        return 'extra payment';
    }

    /** Whether a payment was flagged on this order and then refunded or handled: it never pays the order. */
    public static function handled(int $orderId, string $paymentId): bool
    {
        return $paymentId !== '' && !isset(self::all()[$paymentId])
            && in_array($paymentId, array_map('strval', (array) edd_get_order_meta($orderId, self::META, false)), true);
    }

    public static function hasExtra(int $orderId): bool
    {
        return (bool) array_filter((array) edd_get_order_meta($orderId, self::META, false));
    }

    /** Whether an extra payment started this subscription. Kept after the payment is refunded or handled. */
    public static function extraSubscription(int $orderId, string $id): bool
    {
        return $id !== '' && in_array($id, array_map('strval', (array) edd_get_order_meta($orderId, self::META_SUBSCRIPTION, false)), true);
    }

    /** Forget a payment. False when it was not listed. */
    public static function resolve(string $paymentId): bool
    {
        if ($paymentId === '' || !isset(self::all()[$paymentId])) {
            return false;
        }
        self::change(static function (array $all) use ($paymentId): array {
            unset($all[$paymentId]);
            return $all;
        });
        return true;
    }

    /**
     * A refund the provider reported: a listed payment is forgotten once it is refunded in full.
     *
     * @return bool Whether the refund is of a listed payment, and so not of the order's own.
     */
    public static function refunded(string $paymentId, int $minor): bool
    {
        $row = self::all()[$paymentId] ?? null;
        if ($paymentId === '' || !is_array($row)) {
            return false;
        }
        if ($minor >= max(1, (int) ($row['minor'] ?? 0))) {
            self::resolve($paymentId);
        }
        return true;
    }

    /** The merchant handled it by hand. */
    public static function dismiss(string $paymentId): bool
    {
        $row = self::all()[$paymentId] ?? null;
        if (!is_array($row) || !current_user_can(self::CAPABILITY) || !self::resolve($paymentId)) {
            return false;
        }
        /* translators: %s: payment id */
        stackless_edd_add_order_note((int) ($row['order'] ?? 0), sprintf(__('Extra payment %s was marked as handled.', 'stackless-lite-edd'), $paymentId));
        return true;
    }

    public static function handle(): void
    {
        $paymentId = isset($_GET['payment']) ? sanitize_text_field(wp_unslash($_GET['payment'])) : '';
        check_admin_referer(self::ACTION . $paymentId);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite-edd'), '', ['response' => 403]);
        }
        self::dismiss($paymentId);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public static function notice(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $all = self::all();
        if (!$all) {
            return;
        }
        // Shown on every admin screen, most of which load no Stackless stylesheet: a tinted box, 1px all round.
        echo '<style>div.notice.stackless-extra-payments{border:1px solid #e6a7a9;border-radius:2px;background:#fcf0f1;box-shadow:none}</style>';
        // edd-notice: without it EDD hides the notice on its own screens, Orders among them.
        echo '<div class="notice edd-notice notice-error stackless-extra-payments"><p><strong>' . esc_html__('Payments that may need a refund', 'stackless-lite-edd') . '</strong></p><ul>';
        foreach (array_slice($all, 0, 20, true) as $paymentId => $row) {
            $paymentId = (string) $paymentId;
            $order = admin_url('edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . (int) ($row['order'] ?? 0));
            $done = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&payment=' . rawurlencode($paymentId)), self::ACTION . $paymentId);
            echo '<li>' . esc_html(self::sentence($paymentId, (array) $row))
                . ' <a href="' . esc_url($order) . '">' . esc_html__('View order', 'stackless-lite-edd') . '</a>'
                . ' | <a href="' . esc_url($done) . '">' . esc_html__('Mark as handled', 'stackless-lite-edd') . '</a></li>';
        }
        echo '</ul>';
        if (count($all) > 20) {
            /* translators: %d: how many more payments */
            echo '<p>' . esc_html(sprintf(__('And %d more.', 'stackless-lite-edd'), count($all) - 20)) . '</p>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $row */
    private static function sentence(string $paymentId, array $row): string
    {
        $provider = (string) ($row['provider'] ?? '');
        $orderId = (int) ($row['order'] ?? 0);
        $amount = (string) ($row['amount'] ?? '');
        $text = $amount !== ''
            /* translators: 1: payment provider, 2: amount, 3: payment id, 4: order id */
            ? sprintf(__('%1$s took %2$s in payment %3$s for order #%4$d, which was not waiting for it. If the customer paid twice or the order will not be delivered, refund that payment in %1$s.', 'stackless-lite-edd'), $provider, $amount, $paymentId, $orderId)
            /* translators: 1: payment provider, 2: payment or subscription id, 3: order id */
            : sprintf(__('%1$s reported %2$s for order #%3$d, which was not waiting for it. Check it in %1$s and refund anything it took.', 'stackless-lite-edd'), $provider, $paymentId, $orderId);
        if (!empty($row['test'])) {
            $text .= ' ' . __('It is a test payment.', 'stackless-lite-edd');
        }
        if (!empty($row['subscription'])) {
            /* translators: 1: subscription id, 2: payment provider */
            $text .= ' ' . sprintf(__('Also cancel subscription %1$s in %2$s, or it keeps billing.', 'stackless-lite-edd'), (string) $row['subscription'], $provider);
        }
        return $text;
    }

    /** Read, change and write the list while no other request does the same. */
    private static function change(callable $change): void
    {
        global $wpdb;
        $lock = 'stackless-edd-' . md5($wpdb->prefix . self::OPTION);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock; webhooks arrive in parallel.
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) === '1';
        try {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            update_option(self::OPTION, $change(self::all()));
        } finally {
            if ($locked) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the named lock.
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }
}
