<?php
/**
 * Paystack payment fields inside EDD's purchase form (a redirect gateway: description only).
 *
 * Override by copying to yourtheme/stackless-edd/paystack_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\PaystackGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-paystack-fields" class="stackless-paystack">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-paystack__test"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
