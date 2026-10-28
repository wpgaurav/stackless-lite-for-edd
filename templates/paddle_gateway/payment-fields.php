<?php
/**
 * Paddle payment fields on the EDD checkout (printed where EDD's card form would be).
 *
 * Override by copying to yourtheme/stackless-edd/paddle_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\PaddleGateway\Gateway $gateway
 * @var string $description
 * @var bool   $sandbox
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-paddle" data-gateway="<?php echo esc_attr($gateway->id()); ?>">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($sandbox) : ?>
		<p class="stackless-paddle__sandbox"><strong><?php esc_html_e('Sandbox mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</div>
