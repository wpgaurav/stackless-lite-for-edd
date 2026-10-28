<?php
/**
 * Dodo Payments payment fields on the EDD checkout (printed where EDD's card form would be).
 *
 * Override by copying to yourtheme/stackless-edd/dodo_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\DodoGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-dodo" data-gateway="<?php echo esc_attr($gateway->id()); ?>">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-dodo__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</div>
