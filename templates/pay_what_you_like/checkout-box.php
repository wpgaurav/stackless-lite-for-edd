<?php
/**
 * "Name your price" box on the checkout, one amount field per cart line that takes a chosen
 * amount. It sits just above the purchase form, in a form of its own: with JavaScript each change
 * updates the cart and the totals in place; without it, "Update price" posts the amounts.
 *
 * Override in your theme at stackless-edd/pay_what_you_like/checkout-box.php.
 *
 * @var string $heading
 * @var string $note
 * @var string $nonce
 * @var array<int, array<string, mixed>> $fields arguments for pay_what_you_like/amount-field.php
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;

$stackless_edd_title_id = wp_unique_id('stackless-edd-pwyl-title-');
?>
<section class="stackless-pwyl-box" aria-labelledby="<?php echo esc_attr($stackless_edd_title_id); ?>" data-stackless-pwyl-box>
	<h3 class="stackless-pwyl-box__title" id="<?php echo esc_attr($stackless_edd_title_id); ?>"><?php echo esc_html($heading); ?></h3>
	<?php if ($note !== '') : ?>
		<p class="stackless-pwyl-box__note"><?php echo esc_html($note); ?></p>
	<?php endif; ?>
	<form method="post" action="" class="stackless-pwyl-box__form" data-stackless-pwyl-form>
		<input type="hidden" name="stackless_edd_pwyl_nonce" value="<?php echo esc_attr($nonce); ?>">
		<?php
		foreach ($fields as $stackless_edd_field) {
			stackless_edd_get_template('pay_what_you_like/amount-field.php', $stackless_edd_field + ['note' => '', 'product_id' => 0, 'variable' => false, 'prices' => []]);
		}
		?>
		<noscript><button type="submit" class="button edd-submit stackless-pwyl-box__submit"><?php esc_html_e('Update price', 'stackless-lite-edd'); ?></button></noscript>
	</form>
</section>
