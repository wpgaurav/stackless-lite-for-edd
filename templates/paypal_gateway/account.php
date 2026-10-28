<?php
/**
 * Account page → PayPal: saved PayPal accounts, and the button to save another.
 *
 * Override by copying to yourtheme/stackless-edd/paypal_gateway/account.php.
 *
 * @var \StacklessEDD\Modules\PayPalGateway\Gateway $gateway
 * @var array<int,array<string,mixed>>            $tokens
 * @var string                                    $environment live|sandbox
 * @var bool                                      $can_add
 * @var string                                    $currency
 * @var string                                    $field         POST field naming the action
 * @var string                                    $add_action
 * @var string                                    $remove_action
 * @var string                                    $form_url      this tab's URL (forms post back to it)
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-paypal-account">
	<h2><?php esc_html_e('Saved PayPal accounts', 'stackless-lite-edd'); ?></h2>

	<?php if (!$tokens) : ?>
		<p><?php esc_html_e('No PayPal account is saved yet. Accounts you use for subscriptions are saved here so renewals are paid automatically.', 'stackless-lite-edd'); ?></p>
	<?php else : ?>
		<ul class="stackless-paypal-account__list">
			<?php foreach ($tokens as $stackless_edd_token) : ?>
				<li class="stackless-paypal-account__item">
					<span><?php echo esc_html(\StacklessEDD\Modules\PayPalGateway\Tokens::displayName($stackless_edd_token)); ?></span>
					<?php if ((string) ($stackless_edd_token['environment'] ?? '') === 'sandbox') : ?>
						<span class="stackless-paypal-account__badge"><?php esc_html_e('Sandbox', 'stackless-lite-edd'); ?></span>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url($form_url); ?>" class="stackless-paypal-account__remove">
						<input type="hidden" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($remove_action); ?>" />
						<input type="hidden" name="token_id" value="<?php echo esc_attr((string) $stackless_edd_token['id']); ?>" />
						<?php wp_nonce_field($remove_action); ?>
						<button type="submit" class="button edd-submit" onclick="return window.confirm('<?php echo esc_js(__('Remove this PayPal account? Subscriptions that use it will need another payment method.', 'stackless-lite-edd')); ?>');"><?php esc_html_e('Remove', 'stackless-lite-edd'); ?></button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ($can_add) : ?>
		<h3><?php esc_html_e('Save a PayPal account', 'stackless-lite-edd'); ?></h3>
		<p class="stackless-paypal__vault-note"><?php esc_html_e('Nothing is charged now. The account is saved for future subscription payments, and you can remove it any time here or in your PayPal account.', 'stackless-lite-edd'); ?></p>
		<form method="post" action="<?php echo esc_url($form_url); ?>" id="stackless-paypal-add-method" class="stackless-paypal">
			<input type="hidden" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($add_action); ?>" />
			<?php wp_nonce_field($add_action); ?>
			<div
				id="stackless-paypal-buttons"
				class="stackless-paypal__buttons"
				data-context="add-payment-method"
				data-mode="setup"
				data-vault="1"
				data-intent="capture"
				data-currency="<?php echo esc_attr($currency); ?>"
				data-total="0"
			></div>
			<p class="stackless-paypal__status" role="status" aria-live="polite"></p>
			<input type="hidden" name="<?php echo esc_attr(\StacklessEDD\Modules\PayPalGateway\Gateway::FIELD_ORDER); ?>" value="" />
			<input type="hidden" name="<?php echo esc_attr(\StacklessEDD\Modules\PayPalGateway\Gateway::FIELD_SETUP); ?>" value="" />
			<noscript><p><?php esc_html_e('Turn on JavaScript to connect a PayPal account.', 'stackless-lite-edd'); ?></p></noscript>
		</form>
	<?php endif; ?>
</div>
