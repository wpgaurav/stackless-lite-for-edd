<?php

namespace StacklessEDD\Modules\GatewayTools;

use StacklessEDD\Admin\SettingsPage;
use StacklessEDD\Core\Gateways;
use StacklessEDD\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Payment Gateway Tools: copies of the hosted gateways, and the "Stackless" label
 * that marks Stackless's own gateways wherever the admin lists payment methods.
 */
final class Module extends BaseModule
{
    public const AJAX = 'stackless_edd_gateway_tools_copy';

    public function id(): string
    {
        return 'gateway_tools';
    }

    public function title(): string
    {
        return __('Payment Gateway Tools', 'stackless-lite-edd');
    }

    public function description(): string
    {
        return __('Duplicate the Polar, Paddle and Dodo Payments gateways to take payments with a second account, each copy with its own keys, webhook URL, badge and orders. Marks every gateway Stackless adds with a Stackless label in EDD’s payment settings and order screens.', 'stackless-lite-edd');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function boot(): void
    {
        if (!is_admin()) {
            return;
        }
        add_filter('stackless_edd_gateway_copies_panel', [$this, 'panel'], 10, 2);
        add_action('wp_ajax_' . self::AJAX, [$this, 'ajax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 30);
    }

    private static function allowed(): bool
    {
        return current_user_can('manage_shop_settings');
    }

    /** Admin screens that list or name payment methods: EDD settings, orders, customers, reports, and Stackless screens. */
    private static function labelScreen(string $hook): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, ['edd-settings', 'edd-payment-history', 'edd-customers', 'edd-reports'], true) || strpos($page, 'stackless-edd') === 0;
    }

    public function enqueue(string $hook): void
    {
        if (!self::allowed() || !self::labelScreen($hook)) {
            return;
        }
        $version = defined('STACKLESS_EDD_VERSION') ? STACKLESS_EDD_VERSION : '0';
        wp_enqueue_style('stackless-edd-gateway_tools-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/gateway_tools/admin.css', [], $version);
        wp_enqueue_script('stackless-edd-gateway_tools-admin', STACKLESS_EDD_PLUGIN_URL . 'assets/gateway_tools/admin.js', [], $version, true);
        wp_add_inline_script('stackless-edd-gateway_tools-admin', 'window.stacklessEddGatewayTools=' . wp_json_encode(self::config()) . ';', 'before');
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::AJAX),
            'marker' => '(Stackless)',
            'label' => __('Stackless', 'stackless-lite-edd'),
            'gateways' => (object) self::stacklessTitles(),
            'i18n' => [
                'labelTitle' => __('Added by Stackless for EDD.', 'stackless-lite-edd'),
                'copyTitle' => __('A copy with its own account and settings.', 'stackless-lite-edd'),
                'duplicatePrompt' => __('Badge label for the new copy (optional, for example “EU account”). It starts switched off; connect it from its settings.', 'stackless-lite-edd'),
                'renamePrompt' => __('Badge label for this copy. Leave it empty to show “Copy N”.', 'stackless-lite-edd'),
                'removeConfirm' => __('Remove this copy and its saved settings? This only works while it has no orders or subscriptions.', 'stackless-lite-edd'),
                'failed' => __('That did not work. Reload the page and try again.', 'stackless-lite-edd'),
            ],
        ];
    }

    /**
     * Customer-facing titles of the Stackless gateways registered on this request, for gateway
     * selects on EDD's order screens (an option's text is plain, so it gets " · Stackless").
     *
     * @return array<string,array{title:string,badge:string}>
     */
    private static function stacklessTitles(): array
    {
        $titles = [];
        foreach (Gateways::all() as $id => $gateway) {
            // Every gateway Stackless adds carries the marker in its admin label.
            if (strpos($gateway->adminLabel(), '(Stackless)') === false) {
                continue;
            }
            $title = trim(wp_strip_all_tags($gateway->checkoutLabel()));
            if ($title !== '') {
                $titles[(string) $id] = ['title' => $title, 'badge' => Copies::badge((string) $id)];
            }
        }
        return $titles;
    }

    /** The Stackless settings screen, where gateway modules print the Copies panel. */
    private static function onSettings(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === SettingsPage::SETTINGS;
    }

    /**
     * The Copies panel inside a copyable gateway's settings (original or copy).
     *
     * @param mixed $html
     * @param mixed $id
     */
    public function panel($html, $id = ''): string
    {
        $id = (string) $id;
        if (!self::allowed() || !self::onSettings()) {
            return (string) $html;
        }
        $family = Copies::familyOf($id);
        if ($family === '') {
            return (string) $html;
        }
        $title = Copies::families()[$family]['title'];
        $copies = Copies::of($family);
        $button = static function (string $task, string $target, string $text, string $class = 'button'): string {
            return '<button type="button" class="' . esc_attr($class) . '" data-stackless-copy-task="' . esc_attr($task) . '" data-stackless-copy-id="' . esc_attr($target) . '">' . esc_html($text) . '</button>';
        };

        $out = '<span class="stackless-copies">';
        if ($id !== $family) {
            $out .= sprintf(
                /* translators: 1: gateway name, 2: link to the original gateway */
                esc_html__('This is a copy of %1$s with its own account, keys, webhook URL and orders. %2$s', 'stackless-lite-edd'),
                esc_html($title),
                '<a href="' . esc_url(Copies::settingsUrl($family)) . '">' . esc_html__('Open the original', 'stackless-lite-edd') . '</a>'
            );
            $out .= '<br><br>' . $button('rename', $id, __('Rename badge', 'stackless-lite-edd')) . ' ';
            $out .= Copies::inUse($id)
                ? '<em>' . esc_html__('Has orders, so it cannot be removed. Switch it off instead.', 'stackless-lite-edd') . '</em>'
                : $button('remove', $id, __('Remove copy', 'stackless-lite-edd'), 'button button-link-delete');
            return $out . '</span>';
        }

        $out .= sprintf(
            /* translators: %s: gateway name */
            esc_html__('Take payments with a second %s account: each copy has its own keys, webhook URL, badge and orders, and starts switched off.', 'stackless-lite-edd'),
            esc_html($title)
        ) . '<br><br>';
        foreach ($copies as $copyId => $copy) {
            $out .= '<span class="stackless-copies__row"><strong>' . esc_html($title) . '</strong> <span class="stackless-gateway-copy">' . esc_html(Copies::badge($copyId)) . '</span> ';
            $out .= '<a class="button" href="' . esc_url(Copies::settingsUrl($copyId)) . '">' . esc_html__('Manage', 'stackless-lite-edd') . '</a> ';
            $out .= $button('rename', $copyId, __('Rename', 'stackless-lite-edd')) . '</span><br>';
        }
        if (count($copies) < Copies::MAX_PER_FAMILY) {
            $out .= '<br>' . $button('duplicate', $family, __('Duplicate this gateway', 'stackless-lite-edd'), 'button button-secondary');
        }
        return $out . '</span>';
    }

    public function ajax(): void
    {
        if (!self::allowed() || !check_ajax_referer(self::AJAX, 'nonce', false)) {
            wp_send_json_error(['message' => __('You are not allowed to change payment gateways.', 'stackless-lite-edd')], 403);
        }
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        $id = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';

        if ($task === 'duplicate') {
            $result = Copies::add($id, $label);
            if (is_array($result)) {
                Copies::flush();
                $result['url'] = Copies::settingsUrl($result['id']);
            }
        } elseif ($task === 'rename') {
            $result = Copies::rename($id, $label);
        } elseif ($task === 'remove') {
            $family = Copies::familyOf($id);
            $result = Copies::remove($id);
            if ($result === true) {
                $result = ['id' => $family ?: $id, 'removed' => true, 'url' => $family !== '' ? Copies::settingsUrl($family) : ''];
            }
        } else {
            $result = new \WP_Error('stackless_edd_copy_task', __('Unknown action.', 'stackless-lite-edd'));
        }
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success(is_array($result) ? $result : ['id' => $id]);
    }
}
