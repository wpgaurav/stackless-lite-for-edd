<?php
/**
 * Consent checkboxes on the checkout, above the purchase button.
 *
 * Override in your theme at stackless-edd/checkout_enhancements/consents.php.
 *
 * @var array[] $consents id, type, label (limited HTML), required, version
 * @var array   $allowed  wp_kses allow-list for the wording
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset class="stackless-consents" data-stackless-consents>
    <?php foreach ($consents as $stackless_edd_consent) :
        $stackless_edd_field_id = 'stackless_consent_' . $stackless_edd_consent['id']; ?>
        <p class="stackless-consent-row<?php echo $stackless_edd_consent['required'] ? ' validate-required' : ''; ?>" id="<?php echo esc_attr($stackless_edd_field_id . '_field'); ?>">
            <label class="stackless-consent" for="<?php echo esc_attr($stackless_edd_field_id); ?>">
                <input type="checkbox" id="<?php echo esc_attr($stackless_edd_field_id); ?>" name="stackless_consent[<?php echo esc_attr($stackless_edd_consent['id']); ?>]" value="1" data-stackless-consent<?php echo $stackless_edd_consent['required'] ? ' data-required aria-required="true"' : ''; ?>>
                <span><?php echo wp_kses($stackless_edd_consent['label'], $allowed); ?><?php if ($stackless_edd_consent['required']) : ?> <abbr class="stackless-consent-required edd-required-indicator" title="<?php esc_attr_e('required', 'stackless-lite-edd'); ?>">*</abbr><?php endif; ?></span>
            </label>
        </p>
    <?php endforeach; ?>
</fieldset>
