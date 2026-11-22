<?php
/**
 * Account page → Payment methods: saved Revolut cards, and the form that adds one in Revolut's
 * checkout window.
 *
 * Override by copying to yourtheme/stackless-edd/revolut_gateway/payment-methods.php.
 * Keep the hidden inputs and the nonce: the module reads them.
 *
 * @var bool $adding
 * @var \StacklessEDD\Contracts\SubscriptionData|null $subscription
 * @var array<int,array{id:int, label:string, is_default:bool, expired:bool}> $methods
 * @var string $list_url
 * @var string $add_url
 * @var string $nonce_action
 * @var bool $test_mode
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-revolut-methods">
<?php if ($adding) : ?>
	<h3>
		<?php
		echo $subscription
			/* translators: %d: subscription number */
			? esc_html(sprintf(__('New Revolut card for subscription #%d', 'stackless-lite-edd'), $subscription->get_id()))
			: esc_html__('Add a card with Revolut', 'stackless-lite-edd');
		?>
	</h3>
	<form method="post" class="stackless-revolut-add-method" action="">
		<?php wp_nonce_field($nonce_action, '_stackless_edd_revolut_nonce'); ?>
		<input type="hidden" name="stackless_edd_revolut_task" value="add">
		<?php if ($subscription) : ?>
			<input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription->get_id()); ?>">
		<?php endif; ?>
		<p class="stackless-revolut-save-notice"><?php esc_html_e('You enter the card in Revolut\'s checkout window. Nothing is charged now. The card is saved with Revolut so this store can charge it for your future payments, such as renewals.', 'stackless-lite-edd'); ?></p>
		<?php if ($test_mode) : ?>
			<p class="stackless-revolut__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite-edd'); ?></strong></p>
		<?php endif; ?>
		<p class="stackless-revolut-actions">
			<button type="submit" class="button edd-submit"><?php esc_html_e('Continue to Revolut', 'stackless-lite-edd'); ?></button>
			<a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
		</p>
	</form>
<?php else : ?>
	<h3><?php esc_html_e('Revolut', 'stackless-lite-edd'); ?></h3>
	<?php if ($methods) : ?>
		<table class="stackless-revolut-methods__table edd-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e('Method', 'stackless-lite-edd'); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'stackless-lite-edd'); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($methods as $stackless_edd_method) : ?>
				<tr>
					<td>
						<?php echo esc_html($stackless_edd_method['label']); ?>
						<?php if ($stackless_edd_method['is_default']) : ?>
							<span class="stackless-revolut-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
						<?php if ($stackless_edd_method['expired']) : ?>
							<span class="stackless-revolut-expired"><?php esc_html_e('Expired', 'stackless-lite-edd'); ?></span>
						<?php endif; ?>
					</td>
					<td class="stackless-revolut-methods__actions">
						<?php if (!$stackless_edd_method['is_default']) : ?>
							<form method="post" action="">
								<?php wp_nonce_field($nonce_action, '_stackless_edd_revolut_nonce'); ?>
								<input type="hidden" name="stackless_edd_revolut_task" value="default">
								<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_method['id']); ?>">
								<button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this card? Revolut will no longer charge it.', 'stackless-lite-edd'))); ?>);">
							<?php wp_nonce_field($nonce_action, '_stackless_edd_revolut_nonce'); ?>
							<input type="hidden" name="stackless_edd_revolut_task" value="delete">
							<input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_method['id']); ?>">
							<button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="stackless-revolut-methods__empty"><?php esc_html_e('No saved Revolut cards found.', 'stackless-lite-edd'); ?></p>
	<?php endif; ?>
	<p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add a card with Revolut', 'stackless-lite-edd'); ?></a></p>
<?php endif; ?>
</section>
