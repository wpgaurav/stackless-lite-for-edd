<?php
/**
 * PayPal payment fields inside EDD's purchase form.
 *
 * Override by copying to yourtheme/stackless-edd/paypal_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\PayPalGateway\Gateway $gateway
 * @var string $description
 * @var string $context        checkout
 * @var string $mode           order|setup
 * @var bool   $requires_vault
 * @var string $intent         capture|authorize
 * @var string $currency
 * @var string $total
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-paypal-fields" class="stackless-paypal" data-stackless-paypal="1">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<div class="stackless-paypal__description"><?php echo wp_kses_post(wpautop(wptexturize($description))); ?></div>
	<?php endif; ?>

	<?php if ($requires_vault) : ?>
		<p class="stackless-paypal__vault-note"><?php esc_html_e('Your PayPal account will be saved so future subscription payments are taken automatically. You can remove it any time in your PayPal account.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>

	<div
		id="stackless-paypal-buttons"
		class="stackless-paypal__buttons"
		data-context="<?php echo esc_attr($context); ?>"
		data-mode="<?php echo esc_attr($mode); ?>"
		data-vault="<?php echo $requires_vault ? '1' : '0'; ?>"
		data-intent="<?php echo esc_attr($intent); ?>"
		data-currency="<?php echo esc_attr($currency); ?>"
		data-total="<?php echo esc_attr($total); ?>"
	></div>
	<div class="stackless-paypal__messages" id="stackless-paypal-messages"></div>
	<p class="stackless-paypal__status" role="status" aria-live="polite"></p>

	<input type="hidden" name="<?php echo esc_attr(\StacklessEDD\Modules\PayPalGateway\Gateway::FIELD_ORDER); ?>" value="" />
	<input type="hidden" name="<?php echo esc_attr(\StacklessEDD\Modules\PayPalGateway\Gateway::FIELD_SETUP); ?>" value="" />
	<noscript><p><?php esc_html_e('Complete your purchase and you will be sent to PayPal to pay.', 'stackless-lite-edd'); ?></p></noscript>
</fieldset>
