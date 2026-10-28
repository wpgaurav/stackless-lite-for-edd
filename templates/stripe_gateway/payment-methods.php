<?php
/**
 * Account page → Payment methods: saved Stripe methods, and the form that adds a new one.
 *
 * Override by copying to yourtheme/stackless-edd/stripe_gateway/payment-methods.php.
 * Keep the #stackless-stripe-payment-element container, its data attributes, the hidden inputs
 * and the form class: the script mounts the Payment Element and creates the ConfirmationToken.
 *
 * @var bool $adding
 * @var \StacklessEDD\Contracts\SubscriptionData|null $subscription
 * @var \StacklessEDD\Modules\StripeGateway\PaymentToken[] $tokens
 * @var string $list_url
 * @var string $add_url
 * @var string $nonce_action
 * @var bool $test_mode
 * @var string $currency
 *
 * @package StacklessEDD
 */

use StacklessEDD\Modules\StripeGateway\Gateway;

defined('ABSPATH') || exit;
?>
<section class="stackless-stripe-methods">
<?php if ($adding) : ?>
    <h3><?php echo $subscription ? esc_html(sprintf(/* translators: %d: subscription number */ __('New payment method for subscription #%d', 'stackless-lite-edd'), $subscription->get_id())) : esc_html__('Add payment method', 'stackless-lite-edd'); ?></h3>
    <form method="post" class="stackless-stripe-add-method" action="">
        <?php wp_nonce_field($nonce_action, '_stackless_edd_stripe_nonce'); ?>
        <input type="hidden" name="stackless_edd_stripe_task" value="add">
        <?php if ($subscription) : ?>
            <input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription->get_id()); ?>">
        <?php endif; ?>
        <?php if ($test_mode) : ?>
            <p class="stackless-stripe-test-notice">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: test card number */
                        __('Test mode: use card %s with any future expiry date and any CVC.', 'stackless-lite-edd'),
                        '<code>4242 4242 4242 4242</code>'
                    ),
                    ['code' => []]
                );
                ?>
            </p>
        <?php endif; ?>
        <div
            id="stackless-stripe-payment-element"
            class="stackless-stripe-payment-element"
            data-flow="add"
            data-mode="setup"
            data-amount="0"
            data-currency="<?php echo esc_attr(strtolower($currency)); ?>"
            data-requires-save="1"
            data-capture="automatic"
        ></div>
        <div class="stackless-stripe-errors" role="alert" aria-live="polite"></div>
        <input type="hidden" name="<?php echo esc_attr(Gateway::FIELD_CONFIRMATION_TOKEN); ?>" class="stackless-stripe-confirmation-token" value="">
        <p class="stackless-stripe-save-notice"><?php esc_html_e('Your payment details are saved securely by Stripe.', 'stackless-lite-edd'); ?></p>
        <p class="stackless-stripe-actions">
            <button type="submit" class="button edd-submit"><?php echo $subscription ? esc_html__('Use this payment method', 'stackless-lite-edd') : esc_html__('Add payment method', 'stackless-lite-edd'); ?></button>
            <a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
        </p>
    </form>
<?php else : ?>
    <?php if ($tokens) : ?>
        <table class="stackless-stripe-methods__table edd-table">
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
                            <span class="stackless-stripe-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($stackless_edd_token->exp_month !== '' && $stackless_edd_token->exp_year !== '') {
                            echo esc_html($stackless_edd_token->exp_month . '/' . substr($stackless_edd_token->exp_year, -2));
                            if ($stackless_edd_token->isExpired()) {
                                echo ' <span class="stackless-stripe-expired">' . esc_html__('Expired', 'stackless-lite-edd') . '</span>';
                            }
                        } else {
                            echo '&ndash;';
                        }
                        ?>
                    </td>
                    <td class="stackless-stripe-methods__actions">
                        <?php if (!$stackless_edd_token->is_default) : ?>
                            <form method="post" action="">
                                <?php wp_nonce_field($nonce_action, '_stackless_edd_stripe_nonce'); ?>
                                <input type="hidden" name="stackless_edd_stripe_task" value="default">
                                <input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
                                <button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this payment method? It will no longer be charged.', 'stackless-lite-edd'))); ?>);">
                            <?php wp_nonce_field($nonce_action, '_stackless_edd_stripe_nonce'); ?>
                            <input type="hidden" name="stackless_edd_stripe_task" value="delete">
                            <input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
                            <button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="stackless-stripe-methods__empty"><?php esc_html_e('No saved methods found.', 'stackless-lite-edd'); ?></p>
    <?php endif; ?>
    <p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add payment method', 'stackless-lite-edd'); ?></a></p>
<?php endif; ?>
</section>
