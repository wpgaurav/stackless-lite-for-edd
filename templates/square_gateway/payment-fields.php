<?php
/**
 * Square card form inside EDD's purchase form (shortcode and block checkout). Square's Web
 * Payments SDK fills #stackless-square-card with its own secure fields; card numbers never touch
 * this site.
 *
 * Override by copying to yourtheme/stackless-edd/square_gateway/payment-fields.php. Keep the ids,
 * the hidden inputs and the data-config attribute: the script reads them.
 *
 * @var \StacklessEDD\Modules\SquareGateway\Gateway        $gateway
 * @var string                                             $description
 * @var bool                                               $testmode
 * @var array<string,mixed>                                $data Settings for the card form (see Gateway::scriptData()).
 * @var \StacklessEDD\Modules\SquareGateway\PaymentToken[] $tokens
 * @var bool                                               $show_save
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="edd_cc_fields" class="stackless-square-fields">
    <legend><?php esc_html_e('Payment', 'stackless-lite-edd'); ?></legend>
    <div class="stackless-square" id="stackless-square-form" data-config="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
        <?php if ($description !== '') : ?>
            <?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
        <?php endif; ?>

        <?php if (!empty($data['wallets'])) : ?>
            <div class="stackless-square__wallets">
                <?php if (in_array('apple_pay', $data['wallets'], true)) : ?>
                    <button type="button" class="stackless-square__apple-pay" id="stackless-square-apple-pay" hidden aria-label="<?php esc_attr_e('Pay with Apple Pay', 'stackless-lite-edd'); ?>"></button>
                <?php endif; ?>
                <?php if (in_array('google_pay', $data['wallets'], true)) : ?>
                    <div class="stackless-square__google-pay" id="stackless-square-google-pay"></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($tokens) : ?>
            <ul class="stackless-square__saved" role="radiogroup" aria-label="<?php esc_attr_e('Saved cards', 'stackless-lite-edd'); ?>">
                <?php foreach ($tokens as $stackless_edd_index => $stackless_edd_token) : ?>
                    <li>
                        <label>
                            <input type="radio" name="<?php echo esc_attr($data['fields']['saved']); ?>" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>"<?php checked($stackless_edd_token->is_default || $stackless_edd_index === 0); ?>>
                            <span><?php echo esc_html($stackless_edd_token->displayName()); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
                <li>
                    <label>
                        <input type="radio" name="<?php echo esc_attr($data['fields']['saved']); ?>" value="new">
                        <span><?php esc_html_e('Use a new card', 'stackless-lite-edd'); ?></span>
                    </label>
                </li>
            </ul>
        <?php endif; ?>

        <div class="stackless-square__new"<?php echo $tokens ? ' hidden' : ''; ?>>
            <div class="stackless-square__card" id="stackless-square-card"></div>
            <?php if (!empty($data['requiresSave'])) : ?>
                <p class="stackless-square__save-notice"><?php esc_html_e('Your card will be saved securely with Square and used for future renewals.', 'stackless-lite-edd'); ?></p>
            <?php elseif ($show_save) : ?>
                <p class="stackless-square__save">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($data['fields']['save']); ?>" id="<?php echo esc_attr($data['fields']['save']); ?>" value="1">
                        <?php esc_html_e('Save this card to my account for future purchases.', 'stackless-lite-edd'); ?>
                    </label>
                </p>
            <?php endif; ?>
        </div>
        <p class="stackless-square__status" role="alert" aria-live="polite"></p>
        <input type="hidden" name="<?php echo esc_attr($data['fields']['token']); ?>" value="" />
        <input type="hidden" name="<?php echo esc_attr($data['fields']['method']); ?>" value="card" />
        <?php if ($testmode) : ?>
            <p class="stackless-square__testmode"><strong><?php esc_html_e('Sandbox: use Square\'s test cards; no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
        <?php endif; ?>
    </div>
</fieldset>
