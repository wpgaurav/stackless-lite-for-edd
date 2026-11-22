<?php
/**
 * Braintree card form inside EDD's purchase form (shortcode and block checkout). Braintree's
 * Hosted Fields fill the field containers with their own iframes; card numbers never touch this
 * site.
 *
 * Override by copying to yourtheme/stackless-edd/braintree_gateway/payment-fields.php. Keep the
 * ids, the data-stackless-braintree attributes, the hidden inputs and data-config: the script
 * reads them.
 *
 * @var \StacklessEDD\Modules\BraintreeGateway\Gateway $gateway
 * @var string                                         $description
 * @var bool                                           $testmode
 * @var bool                                           $postal
 * @var array<string,mixed>                            $data Settings for the card form (see Gateway::scriptData()).
 * @var \StacklessEDD\Modules\BraintreeGateway\Card[]  $tokens
 * @var bool                                           $show_save
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="edd_cc_fields" class="stackless-braintree-fields">
    <legend><?php esc_html_e('Payment', 'stackless-lite-edd'); ?></legend>
    <div class="stackless-braintree" id="stackless-braintree-form" data-config="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
        <?php if ($description !== '') : ?>
            <?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
        <?php endif; ?>

        <?php if ($tokens) : ?>
            <ul class="stackless-braintree__saved" role="radiogroup" aria-label="<?php esc_attr_e('Saved cards', 'stackless-lite-edd'); ?>">
                <?php foreach ($tokens as $stackless_edd_index => $stackless_edd_token) : ?>
                    <li>
                        <label>
                            <input type="radio" name="<?php echo esc_attr($data['fields']['saved']); ?>" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>"<?php checked($stackless_edd_index === 0); ?>>
                            <span><?php echo esc_html($stackless_edd_token->label()); ?></span>
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

        <div class="stackless-braintree__new"<?php echo $tokens ? ' hidden' : ''; ?>>
            <div class="stackless-braintree__card">
                <div class="stackless-braintree__field stackless-braintree__field--wide">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['number']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="number"></div>
                </div>
                <div class="stackless-braintree__field">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['expiry']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="expiration"></div>
                </div>
                <div class="stackless-braintree__field">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['cvv']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="cvv"></div>
                </div>
                <?php if ($postal) : ?>
                    <div class="stackless-braintree__field">
                        <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['postal']); ?></span>
                        <div class="stackless-braintree__input" data-stackless-braintree="postal"></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($data['requiresSave'])) : ?>
                <p class="stackless-braintree__save-notice"><?php esc_html_e('Your card will be saved securely with Braintree and used for future renewals.', 'stackless-lite-edd'); ?></p>
            <?php elseif ($show_save) : ?>
                <p class="stackless-braintree__save">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($data['fields']['save']); ?>" id="<?php echo esc_attr($data['fields']['save']); ?>" value="1">
                        <?php esc_html_e('Save this card to my account for future purchases.', 'stackless-lite-edd'); ?>
                    </label>
                </p>
            <?php endif; ?>
        </div>
        <p class="stackless-braintree__status" role="alert" aria-live="polite"></p>
        <input type="hidden" name="<?php echo esc_attr($data['fields']['nonce']); ?>" value="" />
        <input type="hidden" name="<?php echo esc_attr($data['fields']['device']); ?>" value="" />
        <?php if ($testmode) : ?>
            <p class="stackless-braintree__testmode"><strong><?php echo esc_html($data['i18n']['testmode']); ?></strong></p>
        <?php endif; ?>
    </div>
</fieldset>
