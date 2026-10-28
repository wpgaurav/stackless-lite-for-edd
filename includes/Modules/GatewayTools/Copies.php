<?php

namespace StacklessEDD\Modules\GatewayTools;

use StacklessEDD\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Adds, renames and removes copies of Stackless's hosted gateways (Polar, Paddle,
 * Dodo Payments): a second account of the same provider, each copy with its own
 * EDD gateway id ("<family>_copyN"), settings, webhook URL and orders.
 *
 * Each gateway module owns its own list (an option it names when it declares the
 * family on stackless_edd_gateway_copy_families) and keeps running its copies while this
 * module is off; this module only edits those lists. Card gateways (Stripe, PayPal)
 * are not offered: their saved cards and customers belong to one account, and a copy
 * could not charge them.
 */
final class Copies
{
    /** Highest copy count per family (copy numbers 2 to 10). */
    public const MAX_PER_FAMILY = 9;

    /** @var array<string,array{title:string,option:string}>|null Declared families, read once per request. */
    private static $families;

    /** @var array<string,array<string,array{number:int,label:string,created:string}>> Copy lists by family, read once per request. */
    private static $lists = [];

    /** Forget what this request read (after a change, and between tests). */
    public static function flush(): void
    {
        self::$families = null;
        self::$lists = [];
    }

    /** @return array<string,array{title:string,option:string}> family gateway id => declaration */
    public static function families(): array
    {
        if (self::$families !== null) {
            return self::$families;
        }
        $families = [];
        foreach ((array) apply_filters('stackless_edd_gateway_copy_families', []) as $family => $declared) {
            if (!is_string($family) || !is_array($declared) || empty($declared['option']) || strpos($family, 'stackless_edd_') !== 0) {
                continue;
            }
            $families[$family] = [
                'title' => (string) ($declared['title'] ?? $family),
                'option' => (string) $declared['option'],
            ];
        }
        return self::$families = $families;
    }

    /** @return array<string,array{number:int,label:string,created:string}> copies of one family, keyed by gateway id */
    public static function of(string $family): array
    {
        if (isset(self::$lists[$family])) {
            return self::$lists[$family];
        }
        $declared = self::families()[$family] ?? null;
        if (!$declared) {
            return [];
        }
        $saved = get_option($declared['option'], []);
        $copies = [];
        foreach (is_array($saved) ? $saved : [] as $id => $copy) {
            $number = is_array($copy) ? (int) ($copy['number'] ?? 0) : 0;
            if ($number < 2 || $id !== $family . '_copy' . $number) {
                continue;
            }
            $copies[$id] = ['number' => $number, 'label' => self::cleanLabel((string) ($copy['label'] ?? '')), 'created' => (string) ($copy['created'] ?? '')];
        }
        ksort($copies, SORT_NATURAL);
        return self::$lists[$family] = $copies;
    }

    /** @param array<string,array{number:int,label:string,created:string}> $copies */
    private static function save(string $family, array $copies): void
    {
        update_option(self::families()[$family]['option'], $copies, true);
        unset(self::$lists[$family]);
    }

    /** Family gateway id of a gateway id (the original or a copy), '' when it is not copyable. */
    public static function familyOf(string $id): string
    {
        foreach (array_keys(self::families()) as $family) {
            if ($id === $family || isset(self::of($family)[$id])) {
                return $family;
            }
        }
        return '';
    }

    /** Badge text: the copy's label, else "Copy N". '' for an original. */
    public static function badge(string $id): string
    {
        $family = self::familyOf($id);
        $copy = $family !== '' ? (self::of($family)[$id] ?? null) : null;
        if (!$copy) {
            return '';
        }
        /* translators: %d: copy number, starting at 2 */
        return $copy['label'] !== '' ? $copy['label'] : sprintf(__('Copy %d', 'stackless-lite-edd'), $copy['number']);
    }

    /** Lowest free copy number, or 0 when the family is full. @param int[] $used */
    public static function nextNumber(array $used, int $max = self::MAX_PER_FAMILY): int
    {
        if (count($used) >= $max) {
            return 0;
        }
        $number = 2;
        while (in_array($number, $used, true)) {
            $number++;
        }
        return $number;
    }

    /** @return array{id:string,number:int,label:string}|\WP_Error */
    public static function add(string $family, string $label = '')
    {
        $declared = self::families()[$family] ?? null;
        if (!$declared) {
            return new \WP_Error('stackless_edd_copy_family', __('Only Stackless’s hosted gateways (Polar, Paddle and Dodo Payments) can be duplicated, and only while their module is on.', 'stackless-lite-edd'));
        }
        $copies = self::of($family);
        $number = self::nextNumber(array_column($copies, 'number'));
        if ($number === 0) {
            /* translators: %d: maximum number of copies */
            return new \WP_Error('stackless_edd_copy_limit', sprintf(__('A gateway can have at most %d copies.', 'stackless-lite-edd'), self::MAX_PER_FAMILY));
        }
        $id = $family . '_copy' . $number;
        $copies[$id] = ['number' => $number, 'label' => self::cleanLabel($label), 'created' => gmdate('c')];
        self::save($family, $copies);
        do_action('stackless_edd_gateway_copy_added', $id, $family);
        return ['id' => $id, 'number' => $number, 'label' => $copies[$id]['label']];
    }

    /** @return true|\WP_Error */
    public static function rename(string $id, string $label)
    {
        $family = self::familyOf($id);
        $copies = $family !== '' ? self::of($family) : [];
        if (!isset($copies[$id])) {
            return new \WP_Error('stackless_edd_copy_missing', __('That gateway copy no longer exists.', 'stackless-lite-edd'));
        }
        $copies[$id]['label'] = self::cleanLabel($label);
        self::save($family, $copies);
        return true;
    }

    /**
     * Remove a copy that never took an order. A copy with orders or subscriptions stays,
     * so its refunds, renewals and webhooks keep working; switch it off instead.
     *
     * @return true|\WP_Error
     */
    public static function remove(string $id)
    {
        $family = self::familyOf($id);
        $copies = $family !== '' ? self::of($family) : [];
        if (!isset($copies[$id])) {
            return new \WP_Error('stackless_edd_copy_missing', __('That gateway copy no longer exists.', 'stackless-lite-edd'));
        }
        if (self::inUse($id)) {
            return new \WP_Error('stackless_edd_copy_in_use', __('This copy has orders or subscriptions, so it stays to keep their refunds and renewals working. Switch it off in Downloads → Settings → Payments instead.', 'stackless-lite-edd'));
        }
        unset($copies[$id]);
        self::save($family, $copies);
        do_action('stackless_edd_gateway_copy_removed', $id, $family);
        return true;
    }

    /**
     * Whether any EDD order (sales, renewals, refunds, invoices; any status, trash included: a
     * trashed order can be restored, and its refunds still need the copy) or any subscription uses
     * this gateway id. Subscriptions answer through the stackless_edd_gateway_in_use filter.
     */
    public static function inUse(string $id): bool
    {
        $orders = function_exists('edd_count_orders') ? (int) edd_count_orders(['gateway' => $id]) : 0;
        /**
         * Whether something other than an order still depends on this gateway id.
         *
         * @param bool   $in_use     True when orders use it.
         * @param string $gateway_id EDD gateway id of the copy.
         */
        return (bool) apply_filters('stackless_edd_gateway_in_use', $orders > 0, $id);
    }

    /**
     * Where a gateway's settings are edited. Gateway modules may answer the filter; the default is
     * the module settings screen (derived from the family's option, stackless_edd_{module}_copies)
     * with the copy's id in ?gateway=.
     */
    public static function settingsUrl(string $id): string
    {
        $family = self::familyOf($id);
        $url = '';
        if ($family !== '' && class_exists(SettingsPage::class)) {
            $option = self::families()[$family]['option'];
            $module = preg_match('/^stackless_edd_(.+)_copies$/', $option, $m) ? $m[1] : '';
            if ($module !== '') {
                $url = SettingsPage::url($module, $id === $family ? [] : ['gateway' => $id]);
            }
        }
        if ($url === '') {
            $url = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways');
        }
        return (string) apply_filters('stackless_edd_gateway_settings_url', $url, $id, $family);
    }

    public static function cleanLabel(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($label)));
        return function_exists('mb_substr') ? mb_substr($label, 0, 30) : substr($label, 0, 30);
    }
}
