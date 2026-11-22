<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * EDD's refund form before 3.7.1 has no way for a gateway to check it ahead of the refund record
 * (FormParser::verify_nonce()). A ticked "refund through the gateway" box is refused there before
 * anything is recorded or sent. Unticked refunds, payments and renewals work as usual.
 */
final class RefundForm
{
    /** The gateways' checkboxes. The gift card box moves no money at a provider. */
    private const BOX = '/^stackless-edd-(?!gc-)[a-z_]+-refund$/';

    public static function boot(): void
    {
        // Before the gateways' own checks (1) and EDD's handler (10).
        add_action('wp_ajax_edd_process_refund_form', [self::class, 'refuse'], 0);
    }

    public static function supported(): bool
    {
        // The stubs are from a newer EDD; the plugin boots from 3.5, where this is false.
        return method_exists('EDD\Orders\Refunds\FormParser', 'verify_nonce'); // @phpstan-ignore function.alreadyNarrowedType
    }

    public static function refuse(): void
    {
        if (self::supported() || !current_user_can('edit_shop_payments')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only, to refuse; EDD checks the form's nonce for everything it records.
        if (empty($_POST['data']) || !is_string($_POST['data'])) {
            return;
        }
        parse_str(wp_unslash($_POST['data']), $form);
        // phpcs:enable
        foreach ($form as $name => $value) {
            if (!empty($value) && preg_match(self::BOX, (string) $name)) {
                wp_send_json_error(__('Nothing was refunded or recorded. Refunds through the payment gateway need Easy Digital Downloads 3.7.1 or newer. Refund the payment in the provider’s dashboard, then untick the gateway’s box to record the refund here.', 'stackless-lite-edd'), 422);
            }
        }
    }
}
