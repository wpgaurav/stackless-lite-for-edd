<?php
/**
 * Polar payment fields inside EDD's purchase form (a redirect gateway: description only).
 *
 * Override by copying to yourtheme/stackless-edd/polar_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\PolarGateway\Gateway $gateway
 * @var string $description
 * @var bool   $sandbox
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-polar-fields" class="stackless-polar">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($sandbox) : ?>
		<p class="stackless-polar__sandbox"><strong><?php esc_html_e('Sandbox mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
