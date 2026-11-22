<?php
/**
 * Airwallex payment fields inside EDD's purchase form (shortcode and block checkout).
 *
 * Override by copying to yourtheme/stackless-edd/airwallex_gateway/payment-fields.php.
 *
 * @var \StacklessEDD\Modules\AirwallexGateway\Gateway $gateway
 * @var string $description
 * @var bool $testmode
 * @var bool $requires_save Whether this payment saves the card for later
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-airwallex-fields" class="stackless-airwallex">
	<legend><?php echo esc_html($gateway->checkoutLabel()); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($requires_save) : ?>
		<p class="stackless-airwallex__save-note"><?php esc_html_e('Your card is saved with Airwallex and charged automatically for renewals.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-airwallex__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
