<?php
/**
 * Account page → Payment methods: saved Airwallex cards, and the form that adds a new one on
 * Airwallex's hosted page.
 *
 * Override by copying to yourtheme/stackless-edd/airwallex_gateway/payment-methods.php.
 * Keep the hidden inputs and the nonce: the module reads them.
 *
 * @var bool $adding
 * @var \StacklessEDD\Contracts\SubscriptionData|null $subscription
 * @var \StacklessEDD\Modules\AirwallexGateway\SavedMethod[] $tokens
 * @var string $list_url
 * @var string $add_url
 * @var string $nonce_action
 * @var string $nonce_field
 * @var string $task_field
 * @var bool $test_mode
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-airwallex-methods">
<?php if ($adding) : ?>
	<h3><?php echo $subscription ? esc_html(sprintf(/* translators: %d: subscription number */ __('New Airwallex card for subscription #%d', 'stackless-lite-edd'), $subscription->get_id())) : esc_html__('Add a card with Airwallex', 'stackless-lite-edd'); ?></h3>
	<form method="post" class="stackless-airwallex-add-method" action="">
		<?php wp_nonce_field($nonce_action, $nonce_field); ?>
		<input type="hidden" name="<?php echo esc_attr($task_field); ?>" value="add">
		<?php if ($subscription) : ?>
			<input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription->get_id()); ?>">
		<?php endif; ?>
		<p class="stackless-airwallex-save-notice"><?php esc_html_e('You\'ll enter the card on Airwallex\'s secure page. Saving it does not charge it. It can then be charged automatically for renewals.', 'stackless-lite-edd'); ?></p>
		<?php if ($test_mode) : ?>
			<p class="stackless-airwallex__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
		<?php endif; ?>
		<p class="stackless-airwallex-actions">
			<button type="submit" class="button edd-submit"><?php esc_html_e('Continue to Airwallex', 'stackless-lite-edd'); ?></button>
			<a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
		</p>
	</form>
<?php else : ?>
	<h3><?php esc_html_e('Airwallex', 'stackless-lite-edd'); ?></h3>
	<?php if ($tokens) : ?>
		<table class="stackless-airwallex-methods__table edd-table">
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
							<span class="stackless-airwallex-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ($stackless_edd_token->exp_month !== '' && $stackless_edd_token->exp_year !== '') {
							echo esc_html($stackless_edd_token->exp_month . '/' . substr($stackless_edd_token->exp_year, -2));
							if ($stackless_edd_token->isExpired()) {
								echo ' <span class="stackless-airwallex-expired">' . esc_html__('Expired', 'stackless-lite-edd') . '</span>';
							}
						} else {
							echo '&ndash;';
						}
						?>
					</td>
					<td class="stackless-airwallex-methods__actions">
						<?php if (!$stackless_edd_token->is_default) : ?>
							<form method="post" action="">
								<?php wp_nonce_field($nonce_action, $nonce_field); ?>
								<input type="hidden" name="<?php echo esc_attr($task_field); ?>" value="default">
								<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
								<button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this card? Airwallex will no longer charge it.', 'stackless-lite-edd'))); ?>);">
							<?php wp_nonce_field($nonce_action, $nonce_field); ?>
							<input type="hidden" name="<?php echo esc_attr($task_field); ?>" value="delete">
							<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
							<button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="stackless-airwallex-methods__empty"><?php esc_html_e('No saved Airwallex cards found.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add a card with Airwallex', 'stackless-lite-edd'); ?></a></p>
<?php endif; ?>
</section>
