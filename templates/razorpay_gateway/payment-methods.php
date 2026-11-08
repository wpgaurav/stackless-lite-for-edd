<?php
/**
 * Account page → Payment methods: saved Razorpay mandates, and the form that saves a new one.
 *
 * Override by copying to yourtheme/stackless-edd/razorpay_gateway/payment-methods.php. Keep the
 * hidden task, nonce and token fields: the gateway reads them.
 *
 * @var bool                                                  $adding
 * @var int                                                   $subscription_id
 * @var \StacklessEDD\Modules\RazorpayGateway\PaymentToken[] $tokens
 * @var array<string,string>                                  $methods Renewal methods that can be saved.
 * @var string                                                $list_url
 * @var string                                                $add_url
 * @var string                                                $nonce_action
 * @var bool                                                  $testmode
 * @var bool                                                  $can_add
 *
 * @package StacklessEDD
 */

use StacklessEDD\Modules\RazorpayGateway\Gateway;

defined('ABSPATH') || exit;
?>
<section class="stackless-razorpay-methods">
	<h3><?php esc_html_e('Razorpay', 'stackless-lite-edd'); ?></h3>
<?php if ($adding) : ?>
	<?php if (!$can_add || !$methods) : ?>
		<p><?php esc_html_e('Razorpay payment methods cannot be added right now.', 'stackless-lite-edd'); ?></p>
		<p><a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Back to payment methods', 'stackless-lite-edd'); ?></a></p>
	<?php else : ?>
		<form method="post" class="stackless-razorpay-add-method" action="">
			<?php wp_nonce_field($nonce_action, '_stackless_edd_razorpay_nonce'); ?>
			<input type="hidden" name="stackless_edd_razorpay_task" value="add">
			<?php if ($subscription_id > 0) : ?>
				<input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription_id); ?>">
			<?php endif; ?>
			<p><strong><?php esc_html_e('Pay renewals with', 'stackless-lite-edd'); ?></strong></p>
			<?php $stackless_edd_first = true; ?>
			<?php foreach ($methods as $stackless_edd_method_id => $stackless_edd_method_label) : ?>
				<p>
					<label class="stackless-razorpay__method">
						<input type="radio" name="<?php echo esc_attr(Gateway::FIELD_METHOD); ?>" value="<?php echo esc_attr($stackless_edd_method_id); ?>" <?php checked($stackless_edd_first); ?> />
						<?php echo esc_html($stackless_edd_method_label); ?>
					</label>
				</p>
				<?php $stackless_edd_first = false; ?>
			<?php endforeach; ?>
			<p><small><?php esc_html_e('You approve the mandate once in Razorpay. Cards and UPI are verified with ₹1, which is refunded straight away. Renewals are in Indian rupees, and you are notified before every debit.', 'stackless-lite-edd'); ?></small></p>
			<?php if ($testmode) : ?>
				<p class="stackless-razorpay__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
			<?php endif; ?>
			<p class="stackless-razorpay-actions">
				<button type="submit" class="button edd-submit"><?php esc_html_e('Continue to Razorpay', 'stackless-lite-edd'); ?></button>
				<a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
			</p>
		</form>
	<?php endif; ?>
<?php else : ?>
	<?php if ($tokens) : ?>
		<table class="stackless-razorpay-methods__table edd-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Method', 'stackless-lite-edd'); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'stackless-lite-edd'); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($tokens as $stackless_edd_token) : ?>
				<tr>
					<td>
						<?php echo esc_html($stackless_edd_token->displayNameWithStatus()); ?>
						<?php if ($stackless_edd_token->is_default) : ?>
							<span class="stackless-razorpay-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
						<?php if ($stackless_edd_token->isExpired()) : ?>
							<span class="stackless-razorpay-expired"><?php esc_html_e('Expired', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
					</td>
					<td class="stackless-razorpay-methods__actions">
						<?php if (!$stackless_edd_token->is_default) : ?>
							<form method="post" action="">
								<?php wp_nonce_field($nonce_action, '_stackless_edd_razorpay_nonce'); ?>
								<input type="hidden" name="stackless_edd_razorpay_task" value="default">
								<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
								<button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this payment method? Its mandate is cancelled and it will no longer be charged.', 'stackless-lite-edd'))); ?>);">
							<?php wp_nonce_field($nonce_action, '_stackless_edd_razorpay_nonce'); ?>
							<input type="hidden" name="stackless_edd_razorpay_task" value="delete">
							<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
							<button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="stackless-razorpay-methods__empty"><?php esc_html_e('No saved Razorpay methods.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<?php if ($can_add && $methods) : ?>
		<p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add a Razorpay payment method', 'stackless-lite-edd'); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
</section>
