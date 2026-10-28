<?php
/**
 * One Pay What You Like amount field: on a purchase form and in the checkout "Name your price" box.
 *
 * Override in your theme at stackless-edd/pay_what_you_like/amount-field.php.
 * Keep the input name, the hidden download input and the data-stackless-pwyl* attributes: the
 * server reads the name, and front.js reads the attributes.
 *
 * @var string $id
 * @var string $input_name   input name
 * @var string $label
 * @var string $context      product or checkout
 * @var string $value        amount shown in the field
 * @var string $min          plain decimal ("12.50") for the script
 * @var string $max
 * @var int    $decimals
 * @var string $min_label    formatted minimum
 * @var string $max_label    formatted maximum
 * @var string $help
 * @var string $symbol       currency symbol
 * @var bool   $symbol_after
 * @var array<int, array{value: string, label: string, pressed: bool}> $presets
 * @var string $note         line under the label (purchase forms)
 * @var int    $product_id   purchase forms only
 * @var bool   $variable     purchase form of a variable download: follows the chosen price option
 * @var array<string, mixed> $prices per price option: limits for the script, or false for a fixed price
 * @var bool   $hidden       start hidden (a variable download whose default option has a fixed price)
 * @var string $key          checkout: the cart line the field belongs to
 * @var string $error        optional message for an amount that no longer fits
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;

$stackless_edd_error = isset($error) ? (string) $error : '';
$stackless_edd_symbol = '<span class="stackless-pwyl__symbol" aria-hidden="true">' . esc_html($symbol) . '</span>';
$stackless_edd_prices = isset($prices) && is_array($prices) ? $prices : [];
$stackless_edd_variable = !empty($variable);
?>
<div class="stackless-pwyl stackless-pwyl--<?php echo esc_attr($context); ?>" data-stackless-pwyl data-context="<?php echo esc_attr($context); ?>" data-min="<?php echo esc_attr($min); ?>" data-max="<?php echo esc_attr($max); ?>" data-decimals="<?php echo esc_attr((string) $decimals); ?>" data-min-label="<?php echo esc_attr($min_label); ?>" data-max-label="<?php echo esc_attr($max_label); ?>"<?php echo isset($key) && $key !== '' ? ' data-key="' . esc_attr((string) $key) . '"' : ''; ?><?php echo $stackless_edd_variable ? ' data-variable="1" data-download="' . esc_attr((string) $product_id) . '" data-prices="' . esc_attr((string) wp_json_encode($stackless_edd_prices)) . '"' : ''; ?><?php echo !empty($hidden) ? ' hidden' : ''; ?>>
	<?php if ($context === 'product') : ?>
		<input type="hidden" name="<?php echo esc_attr(\StacklessEDD\Modules\PayWhatYouLike\Cart::PRODUCT_MARKER); ?>" value="<?php echo esc_attr((string) $product_id); ?>">
	<?php endif; ?>
	<label class="stackless-pwyl__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
	<?php if ($note !== '') : ?>
		<p class="stackless-pwyl__note"><?php echo esc_html($note); ?></p>
	<?php endif; ?>
	<div class="stackless-pwyl__input">
		<?php echo $symbol_after ? '' : $stackless_edd_symbol; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		<input type="text" inputmode="decimal" autocomplete="off" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($value); ?>" aria-describedby="<?php echo esc_attr($id . '-help'); ?>" aria-invalid="<?php echo $stackless_edd_error !== '' ? 'true' : 'false'; ?>" data-stackless-pwyl-amount>
		<?php echo $symbol_after ? $stackless_edd_symbol : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
	</div>
	<div class="stackless-pwyl__presets" role="group" aria-label="<?php esc_attr_e('Quick amounts', 'stackless-lite-edd'); ?>" data-stackless-pwyl-presets<?php echo $presets ? '' : ' hidden'; ?>>
		<?php foreach ($presets as $stackless_edd_preset) : ?>
			<button type="button" class="stackless-pwyl__preset" data-stackless-pwyl-preset="<?php echo esc_attr($stackless_edd_preset['value']); ?>" aria-pressed="<?php echo $stackless_edd_preset['pressed'] ? 'true' : 'false'; ?>"><?php echo esc_html($stackless_edd_preset['label']); ?></button>
		<?php endforeach; ?>
	</div>
	<p class="stackless-pwyl__help" id="<?php echo esc_attr($id . '-help'); ?>" data-stackless-pwyl-help><?php echo esc_html($help); ?></p>
	<p class="stackless-pwyl__status<?php echo $stackless_edd_error !== '' ? ' is-error' : ''; ?>" role="status" aria-live="polite" data-stackless-pwyl-status><?php echo esc_html($stackless_edd_error); ?></p>
</div>
