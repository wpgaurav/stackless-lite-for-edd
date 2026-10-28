<?php
/**
 * Stripe payment fields inside EDD's purchase form (shortcode and block checkout).
 *
 * Override by copying to yourtheme/stackless-edd/stripe_gateway/payment-fields.php.
 * Keep the #stackless-stripe-payment-element container, its data attributes and the hidden input:
 * the script mounts the Stripe Payment Element there and fills the input with a
 * ConfirmationToken id when the shopper clicks Purchase.
 *
 * @var \StacklessEDD\Modules\StripeGateway\Gateway $gateway
 * @var array{flow:string, mode:string, amount:int, currency:string, requires_save:bool, capture:string} $context
 * @var string $description
 * @var bool $test_mode
 * @var \StacklessEDD\Modules\StripeGateway\PaymentToken[] $tokens
 * @var bool $show_save
 *
 * @package StacklessEDD
 */

use StacklessEDD\Modules\StripeGateway\Gateway;

defined('ABSPATH') || exit;
?>
<fieldset id="edd_cc_fields" class="edd-do-validate stackless-stripe-fields" data-flow="<?php echo esc_attr($context['flow']); ?>">
    <legend><?php esc_html_e('Payment', 'stackless-lite-edd'); ?></legend>
    <?php if ($description) : ?>
        <div class="stackless-stripe-description"><?php echo wp_kses_post(wpautop(wptexturize($description))); ?></div>
    <?php endif; ?>

    <?php if ($test_mode) : ?>
        <p class="stackless-stripe-test-notice">
            <?php
            echo wp_kses(
                sprintf(
                    /* translators: %s: test card number */
                    __('Test mode: use card %s with any future expiry date and any CVC.', 'stackless-lite-edd'),
                    '<code>4242 4242 4242 4242</code>'
                ),
                ['code' => []]
            );
            ?>
        </p>
    <?php endif; ?>

    <?php if ($tokens) : ?>
        <ul class="stackless-stripe-saved-methods" role="radiogroup" aria-label="<?php esc_attr_e('Saved payment methods', 'stackless-lite-edd'); ?>">
            <?php foreach ($tokens as $stackless_edd_index => $stackless_edd_token) : ?>
                <li>
                    <label>
                        <input type="radio" name="<?php echo esc_attr(Gateway::FIELD_TOKEN); ?>" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>"<?php checked($stackless_edd_token->is_default || $stackless_edd_index === 0); ?>>
                        <span><?php echo esc_html($stackless_edd_token->displayName()); ?></span>
                    </label>
                </li>
            <?php endforeach; ?>
            <li>
                <label>
                    <input type="radio" name="<?php echo esc_attr(Gateway::FIELD_TOKEN); ?>" value="new">
                    <span><?php esc_html_e('Use a new payment method', 'stackless-lite-edd'); ?></span>
                </label>
            </li>
        </ul>
    <?php endif; ?>

    <div class="stackless-stripe-new-method"<?php echo $tokens ? ' hidden' : ''; ?>>
        <div
            id="stackless-stripe-payment-element"
            class="stackless-stripe-payment-element"
            data-flow="<?php echo esc_attr($context['flow']); ?>"
            data-mode="<?php echo esc_attr($context['mode']); ?>"
            data-amount="<?php echo esc_attr((string) $context['amount']); ?>"
            data-currency="<?php echo esc_attr(strtolower($context['currency'])); ?>"
            data-requires-save="<?php echo $context['requires_save'] ? '1' : '0'; ?>"
            data-capture="<?php echo esc_attr($context['capture']); ?>"
        ></div>
        <div class="stackless-stripe-errors" role="alert" aria-live="polite"></div>
        <input type="hidden" name="<?php echo esc_attr(Gateway::FIELD_CONFIRMATION_TOKEN); ?>" class="stackless-stripe-confirmation-token" value="">
        <?php if ($context['requires_save']) : ?>
            <p class="stackless-stripe-save-notice"><?php esc_html_e('Your payment details will be saved securely by Stripe and used for future renewals.', 'stackless-lite-edd'); ?></p>
        <?php elseif ($show_save) : ?>
            <p class="stackless-stripe-save">
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(Gateway::FIELD_SAVE); ?>" id="<?php echo esc_attr(Gateway::FIELD_SAVE); ?>" value="1">
                    <?php esc_html_e('Save payment information to my account for future purchases.', 'stackless-lite-edd'); ?>
                </label>
            </p>
        <?php endif; ?>
    </div>
</fieldset>
