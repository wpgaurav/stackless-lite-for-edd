<?php
/**
 * The page that opens Razorpay Checkout: after Purchase (an order's payment) and on the account
 * page (saving a payment method). EDD has no order-pay page, so it prints its own small document
 * and works whatever the theme does. The script opens Razorpay Checkout on load; the button opens
 * it again after the buyer closes it.
 *
 * Override by copying to yourtheme/stackless-edd/razorpay_gateway/pay-page.php. Keep the
 * #stackless-razorpay-checkout container, the .stackless-razorpay__status element and the
 * #stackless-razorpay-pay button: the script uses them.
 *
 * @var string              $heading
 * @var string[]            $lines        Explanations shown above the button.
 * @var string              $error        Shown instead of opening Razorpay when not empty.
 * @var string              $button       Button label.
 * @var string              $cancel       Where the second link goes.
 * @var string              $cancel_label
 * @var bool                $testmode
 * @var array<string,mixed> $config       Options for Razorpay Checkout plus return and cancel URLs ([] when nothing can be paid).
 * @var string              $sdk          Razorpay checkout.js URL.
 * @var string              $script       This gateway's checkout script URL.
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html($heading); ?> - <?php bloginfo('name'); ?></title>
	<style>
		body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f6f7f7; color: #1d2327; }
		.stackless-razorpay { max-width: 460px; margin: 10vh auto; background: #fff; padding: 32px; border-radius: 4px; border: 1px solid #dcdcde; }
		.stackless-razorpay h1 { font-size: 20px; margin: 0 0 12px; }
		.stackless-razorpay .button { display: inline-block; box-sizing: border-box; min-height: 40px; padding: 8px 18px; border-radius: 2px; border: 1px solid #2271b1; background: #2271b1; color: #fff; font-size: 15px; line-height: 22px; cursor: pointer; text-decoration: none; }
		.stackless-razorpay .button.secondary { background: #fff; color: #2271b1; }
		.stackless-razorpay__error { padding: 8px 12px; border: 1px solid #d63638; background: #fcf0f1; border-radius: 2px; }
		@media (max-width: 520px) { .stackless-razorpay { margin: 16px; padding: 20px; } }
	</style>
</head>
<body>
	<div class="stackless-razorpay stackless-razorpay--checkout" id="stackless-razorpay-checkout">
		<h1><?php echo esc_html($heading); ?></h1>
		<?php if ($error !== '') : ?>
			<p class="stackless-razorpay__error" role="alert"><?php echo esc_html($error); ?></p>
		<?php endif; ?>
		<?php if ($config) : ?>
			<?php foreach ($lines as $stackless_edd_line) : ?>
				<p><?php echo esc_html($stackless_edd_line); ?></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<p class="stackless-razorpay__status" role="status" aria-live="polite"></p>
		<p>
			<?php if ($config) : ?>
				<button type="button" class="button" id="stackless-razorpay-pay"><?php echo esc_html($button); ?></button>
			<?php endif; ?>
			<a class="button secondary" href="<?php echo esc_url($cancel); ?>"><?php echo esc_html($cancel_label); ?></a>
		</p>
		<?php if ($testmode) : ?>
			<p class="stackless-razorpay__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
		<?php endif; ?>
	</div>
	<?php if ($config) : ?>
		<script>window.stacklessEddRazorpay = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a standalone document without wp_head(); nothing else is loaded. ?>
		<script src="<?php echo esc_url($sdk); ?>"></script>
		<script src="<?php echo esc_url($script); ?>"></script>
		<?php // phpcs:enable ?>
	<?php endif; ?>
</body>
</html>
