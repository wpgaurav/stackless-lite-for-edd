<?php
/**
 * Razorpay payment fields in EDD's purchase form (Razorpay Checkout opens after Purchase).
 *
 * Override by copying to yourtheme/stackless-edd/razorpay_gateway/payment-fields.php.
 * Keep the radio inputs' name: the gateway reads the renewal method from it.
 *
 * @var string               $label       Checkout label of the gateway.
 * @var string               $description
 * @var bool                 $testmode
 * @var array<string,string> $methods Renewal methods to choose from (only when a mandate is saved).
 * @var string               $field   POST field name of the renewal method.
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-razorpay-fields" class="stackless-razorpay">
	<legend><?php echo esc_html($label); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($methods) : ?>
		<div class="stackless-razorpay__methods">
			<p class="stackless-razorpay__legend"><strong><?php esc_html_e('Pay renewals with', 'stackless-lite-edd'); ?></strong></p>
			<?php $stackless_edd_first = true; ?>
			<?php foreach ($methods as $stackless_edd_method_id => $stackless_edd_method_label) : ?>
				<label class="stackless-razorpay__method">
					<input type="radio" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($stackless_edd_method_id); ?>" <?php checked($stackless_edd_first); ?> />
					<?php echo esc_html($stackless_edd_method_label); ?>
				</label>
				<?php $stackless_edd_first = false; ?>
			<?php endforeach; ?>
			<p class="stackless-razorpay__note"><small><?php esc_html_e('Your bank or UPI app asks you to approve the mandate once. Subscriptions renew in Indian rupees, and you are notified before every debit.', 'stackless-lite-edd'); ?></small></p>
		</div>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-razorpay__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
