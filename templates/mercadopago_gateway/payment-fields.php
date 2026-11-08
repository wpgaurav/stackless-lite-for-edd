<?php
/**
 * Mercado Pago payment fields inside EDD's purchase form (a redirect gateway: description only).
 *
 * Override by copying to yourtheme/stackless-edd/mercadopago_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\MercadoPagoGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-mercadopago-fields" class="stackless-mercadopago">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-mercadopago__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
