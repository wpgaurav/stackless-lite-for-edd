<?php
/**
 * Account page → Payment methods: saved Mollie methods, and the form that adds a new one on
 * Mollie's hosted page.
 *
 * Override by copying to yourtheme/stackless-edd/mollie_gateway/payment-methods.php.
 * Keep the hidden inputs and the nonce: the module reads them.
 *
 * @var bool $adding
 * @var \StacklessEDD\Contracts\SubscriptionData|null $subscription
 * @var \StacklessEDD\Modules\MollieGateway\PaymentToken[] $tokens
 * @var array<string,string> $methods id => label for the add form; empty = the buyer picks on Mollie's page
 * @var string $list_url
 * @var string $add_url
 * @var string $nonce_action
 * @var bool $test_mode
 *
 * @package StacklessEDD
 */

use StacklessEDD\Modules\MollieGateway\Gateway;

defined('ABSPATH') || exit;
?>
<section class="stackless-mollie-methods">
<?php if ($adding) : ?>
	<h3><?php echo $subscription ? esc_html(sprintf(/* translators: %d: subscription number */ __('New Mollie payment method for subscription #%d', 'stackless-lite-edd'), $subscription->get_id())) : esc_html__('Add a payment method with Mollie', 'stackless-lite-edd'); ?></h3>
	<form method="post" class="stackless-mollie-add-method" action="">
		<?php wp_nonce_field($nonce_action, '_stackless_edd_mollie_nonce'); ?>
		<input type="hidden" name="stackless_edd_mollie_task" value="add">
		<?php if ($subscription) : ?>
			<input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription->get_id()); ?>">
		<?php endif; ?>
		<?php if ($methods) : ?>
			<p class="edd-form-group">
				<label class="edd-label" for="<?php echo esc_attr(Gateway::FIELD_METHOD); ?>"><?php esc_html_e('Payment method', 'stackless-lite-edd'); ?></label>
				<select class="edd-select" name="<?php echo esc_attr(Gateway::FIELD_METHOD); ?>" id="<?php echo esc_attr(Gateway::FIELD_METHOD); ?>">
					<option value=""><?php esc_html_e('Choose on the next page', 'stackless-lite-edd'); ?></option>
					<?php foreach ($methods as $stackless_edd_method_id => $stackless_edd_method_label) : ?>
						<option value="<?php echo esc_attr($stackless_edd_method_id); ?>"><?php echo esc_html($stackless_edd_method_label); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
		<p class="stackless-mollie-save-notice"><?php esc_html_e('You\'ll confirm the method on Mollie\'s secure page. Saving it takes a payment of 0.00 or one cent, and a cent is refunded once Mollie confirms the method.', 'stackless-lite-edd'); ?></p>
		<?php if ($test_mode) : ?>
			<p class="stackless-mollie__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
		<?php endif; ?>
		<p class="stackless-mollie-actions">
			<button type="submit" class="button edd-submit"><?php esc_html_e('Continue to Mollie', 'stackless-lite-edd'); ?></button>
			<a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
		</p>
	</form>
<?php else : ?>
	<h3><?php esc_html_e('Mollie', 'stackless-lite-edd'); ?></h3>
	<?php if ($tokens) : ?>
		<table class="stackless-mollie-methods__table edd-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Method', 'stackless-lite-edd'); ?></th>
					<th scope="col"><?php esc_html_e('Expires', 'stackless-lite-edd'); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'stackless-lite-edd'); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($tokens as $stackless_edd_token) : ?>
				<tr>
					<td>
						<?php echo esc_html($stackless_edd_token->displayName()); ?>
						<?php if ($stackless_edd_token->is_default) : ?>
							<span class="stackless-mollie-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ($stackless_edd_token->exp_month !== '' && $stackless_edd_token->exp_year !== '') {
							echo esc_html($stackless_edd_token->exp_month . '/' . substr($stackless_edd_token->exp_year, -2));
							if ($stackless_edd_token->isExpired()) {
								echo ' <span class="stackless-mollie-expired">' . esc_html__('Expired', 'stackless-lite-edd') . '</span>';
							}
						} else {
							echo '&ndash;';
						}
						?>
					</td>
					<td class="stackless-mollie-methods__actions">
						<?php if (!$stackless_edd_token->is_default) : ?>
							<form method="post" action="">
								<?php wp_nonce_field($nonce_action, '_stackless_edd_mollie_nonce'); ?>
								<input type="hidden" name="stackless_edd_mollie_task" value="default">
								<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
								<button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this payment method? Mollie will no longer charge it.', 'stackless-lite-edd'))); ?>);">
							<?php wp_nonce_field($nonce_action, '_stackless_edd_mollie_nonce'); ?>
							<input type="hidden" name="stackless_edd_mollie_task" value="delete">
							<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
							<button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="stackless-mollie-methods__empty"><?php esc_html_e('No saved Mollie payment methods found.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add a payment method with Mollie', 'stackless-lite-edd'); ?></a></p>
<?php endif; ?>
</section>
