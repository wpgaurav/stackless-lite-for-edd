<?php
/**
 * Account page → Payment methods: cards saved with Braintree, and the form that adds a new one.
 *
 * Override by copying to yourtheme/stackless-edd/braintree_gateway/payment-methods.php. Keep the
 * #stackless-braintree-form container with its data-config, the field containers, the hidden
 * inputs and the form class: the script mounts Hosted Fields there and fills the nonce.
 *
 * @var bool                                          $adding
 * @var bool                                          $can_add
 * @var \StacklessEDD\Contracts\SubscriptionData|null $subscription
 * @var \StacklessEDD\Modules\BraintreeGateway\Card[] $tokens
 * @var string                                        $list_url
 * @var string                                        $add_url
 * @var string                                        $nonce_action
 * @var string                                        $nonce_field
 * @var string                                        $field
 * @var bool                                          $testmode
 * @var array<string,mixed>                           $data Settings for the card form when adding.
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-braintree-methods">
<?php if ($adding) : ?>
    <h3><?php echo $subscription ? esc_html(sprintf(/* translators: %d: subscription number */ __('New card for subscription #%d', 'stackless-lite-edd'), $subscription->get_id())) : esc_html__('Add a card', 'stackless-lite-edd'); ?></h3>
    <form method="post" class="stackless-braintree-add-method" action="">
        <?php wp_nonce_field($nonce_action, $nonce_field); ?>
        <input type="hidden" name="<?php echo esc_attr($field); ?>" value="add">
        <?php if ($subscription) : ?>
            <input type="hidden" name="subscription" value="<?php echo esc_attr((string) $subscription->get_id()); ?>">
        <?php endif; ?>
        <div class="stackless-braintree" id="stackless-braintree-form" data-config="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
            <div class="stackless-braintree__card">
                <div class="stackless-braintree__field stackless-braintree__field--wide">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['number']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="number"></div>
                </div>
                <div class="stackless-braintree__field">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['expiry']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="expiration"></div>
                </div>
                <div class="stackless-braintree__field">
                    <span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['cvv']); ?></span>
                    <div class="stackless-braintree__input" data-stackless-braintree="cvv"></div>
                </div>
            </div>
            <p class="stackless-braintree__status" role="alert" aria-live="polite"></p>
            <input type="hidden" name="<?php echo esc_attr($data['fields']['nonce']); ?>" value="" />
            <?php if ($testmode) : ?>
                <p class="stackless-braintree__testmode"><strong><?php echo esc_html($data['i18n']['testmode']); ?></strong></p>
            <?php endif; ?>
        </div>
        <p class="stackless-braintree__save-notice"><?php esc_html_e('Your card is saved securely with Braintree.', 'stackless-lite-edd'); ?></p>
        <p class="stackless-braintree__actions">
            <button type="submit" class="button edd-submit"><?php echo $subscription ? esc_html__('Use this card', 'stackless-lite-edd') : esc_html__('Save card', 'stackless-lite-edd'); ?></button>
            <a href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Cancel', 'stackless-lite-edd'); ?></a>
        </p>
    </form>
<?php else : ?>
    <h3><?php esc_html_e('Cards saved with Braintree', 'stackless-lite-edd'); ?></h3>
    <?php if ($tokens) : ?>
        <table class="stackless-braintree-methods__table edd-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Card', 'stackless-lite-edd'); ?></th>
                    <th scope="col"><?php esc_html_e('Expires', 'stackless-lite-edd'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'stackless-lite-edd'); ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tokens as $stackless_edd_token) : ?>
                <tr>
                    <td>
                        <?php echo esc_html($stackless_edd_token->brandLabel() . ' •••• ' . $stackless_edd_token->last4); ?>
                        <?php if ($stackless_edd_token->default) : ?>
                            <span class="stackless-braintree-default"><?php esc_html_e('Default', 'stackless-lite-edd'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($stackless_edd_token->month !== '' && $stackless_edd_token->year !== '') {
                            echo esc_html($stackless_edd_token->month . '/' . substr($stackless_edd_token->year, -2));
                            if (\StacklessEDD\Modules\BraintreeGateway\Tokens::expired($stackless_edd_token)) {
                                echo ' <span class="stackless-braintree-expired">' . esc_html__('Expired', 'stackless-lite-edd') . '</span>';
                            }
                        }
                        ?>
                    </td>
                    <td class="stackless-braintree-methods__actions">
                        <?php if (!$stackless_edd_token->default) : ?>
                            <form method="post" action="">
                                <?php wp_nonce_field($nonce_action, $nonce_field); ?>
                                <input type="hidden" name="<?php echo esc_attr($field); ?>" value="default">
                                <input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
                                <button type="submit" class="button-link"><?php esc_html_e('Make default', 'stackless-lite-edd'); ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Remove this card? It will no longer be charged.', 'stackless-lite-edd'))); ?>);">
                            <?php wp_nonce_field($nonce_action, $nonce_field); ?>
                            <input type="hidden" name="<?php echo esc_attr($field); ?>" value="delete">
                            <input type="hidden" name="token" value="<?php echo esc_attr((string) $stackless_edd_token->id); ?>">
                            <button type="submit" class="button-link"><?php esc_html_e('Delete', 'stackless-lite-edd'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="stackless-braintree-methods__empty"><?php esc_html_e('No cards saved with Braintree yet.', 'stackless-lite-edd'); ?></p>
    <?php endif; ?>
    <?php if ($can_add) : ?>
        <p><a class="button edd-submit" href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Add a card', 'stackless-lite-edd'); ?></a></p>
    <?php endif; ?>
<?php endif; ?>
</section>
