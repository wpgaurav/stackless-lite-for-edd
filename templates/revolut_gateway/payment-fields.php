<?php
/**
 * Revolut payment fields inside EDD's purchase form (shortcode and block checkout).
 *
 * Override by copying to yourtheme/stackless-edd/revolut_gateway/payment-fields.php.
 *
 * @var string $label
 * @var string $description
 * @var bool   $testmode
 * @var bool   $requires_save Whether this payment saves the card for later
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<fieldset id="stackless-revolut-fields" class="stackless-revolut">
	<legend><?php echo esc_html($label); ?></legend>
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($requires_save) : ?>
		<p class="stackless-revolut__save-note"><?php esc_html_e('You enter your card in Revolut\'s window on the next page. It is saved with Revolut for future payments.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-revolut__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
	<?php endif; ?>
</fieldset>
