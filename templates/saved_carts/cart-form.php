<?php
/**
 * "Save this cart" form below the checkout cart.
 *
 * Override in your theme at stackless-edd/saved_carts/cart-form.php.
 *
 * @var string $nonce
 * @var string $account_url
 */

defined('ABSPATH') || exit;

$stackless_edd_button_class = 'button edd-submit ' . (function_exists('edd_get_button_color_class') ? edd_get_button_color_class() : '');
?>
<form method="post" class="stackless-saved-carts-save stackless-saved-carts-cart">
    <input type="hidden" name="stackless_edd_saved_carts_op" value="save">
    <input type="hidden" name="_stackless_edd_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
    <label for="stackless-cart-save-name"><strong><?php esc_html_e('Save this cart for later', 'stackless-lite-edd'); ?></strong></label>
    <span class="stackless-saved-carts-row">
        <input type="text" id="stackless-cart-save-name" name="cart_name" maxlength="100" required placeholder="<?php esc_attr_e('Cart name', 'stackless-lite-edd'); ?>">
        <button type="submit" class="<?php echo esc_attr($stackless_edd_button_class); ?>"><?php esc_html_e('Save cart', 'stackless-lite-edd'); ?></button>
    </span>
    <?php if ($account_url) : ?>
        <a class="stackless-saved-carts-muted" href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('View saved carts', 'stackless-lite-edd'); ?></a>
    <?php endif; ?>
</form>
