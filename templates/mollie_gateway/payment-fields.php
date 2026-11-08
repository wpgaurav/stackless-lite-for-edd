<?php
/**
 * Mollie payment fields inside EDD's purchase form (shortcode and block checkout).
 *
 * Override by copying to yourtheme/stackless-edd/mollie_gateway/payment-fields.php.
 * Keep the field names: the gateway reads the method choice, the saved method and the save box.
 *
 * @var \StacklessEDD\Modules\MollieGateway\Gateway $gateway
 * @var string $description
 * @var bool $testmode
 * @var array<string,string> $methods id => label; empty = the buyer picks on Mollie's page
 * @var string $field POST field name of the method choice
 * @var string $token_field POST field name of the saved method choice
 * @var string $save_field POST field name of the "save for later" box
 * @var \StacklessEDD\Modules\MollieGateway\PaymentToken[] $tokens
 * @var bool $requires_save Whether this payment saves the method for later
 * @var bool $show_save Whether to offer the "save for later" box
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-mollie-fields" class="stackless-mollie">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>

	<?php if ($tokens) : ?>
		<ul class="stackless-mollie__saved" role="radiogroup" aria-label="<?php esc_attr_e('Saved payment methods', 'stackless-lite-edd'); ?>">
			<?php foreach ($tokens as $stackless_edd_index => $stackless_edd_token) : ?>
				<li>
					<label>
						<input type="radio" name="<?php echo esc_attr($token_field); ?>" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>"<?php checked($stackless_edd_token->is_default || $stackless_edd_index === 0); ?>>
						<span><?php echo esc_html($stackless_edd_token->displayName()); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
			<li>
				<label>
					<input type="radio" name="<?php echo esc_attr($token_field); ?>" value="new">
					<span><?php esc_html_e('Use a new payment method', 'stackless-lite-edd'); ?></span>
				</label>
			</li>
		</ul>
	<?php endif; ?>

	<?php if ($methods) : ?>
		<p class="edd-form-group stackless-mollie__method">
			<label class="edd-label" for="<?php echo esc_attr($field); ?>"><?php echo $tokens ? esc_html__('New payment method', 'stackless-lite-edd') : esc_html__('Payment method', 'stackless-lite-edd'); ?></label>
			<select class="edd-select" name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>">
				<option value=""><?php esc_html_e('Choose on the next page', 'stackless-lite-edd'); ?></option>
				<?php foreach ($methods as $stackless_edd_method_id => $stackless_edd_method_label) : ?>
					<option value="<?php echo esc_attr($stackless_edd_method_id); ?>"><?php echo esc_html($stackless_edd_method_label); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>

	<?php if ($requires_save) : ?>
		<p class="stackless-mollie__save-note"><?php esc_html_e('This payment method is saved with Mollie for future payments.', 'stackless-lite-edd'); ?></p>
	<?php elseif ($show_save) : ?>
		<p class="stackless-mollie__save">
			<label>
				<input type="checkbox" name="<?php echo esc_attr($save_field); ?>" value="1">
				<?php esc_html_e('Save this payment method for future purchases', 'stackless-lite-edd'); ?>
			</label>
		</p>
	<?php endif; ?>

	<?php if ($testmode) : ?>
		<p class="stackless-mollie__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
