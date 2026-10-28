<?php
/**
 * Saved carts for visitors who are not signed in.
 *
 * Override in your theme at stackless-edd/saved_carts/signed-out.php.
 *
 * @var string $login_url
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-saved-carts stackless-saved-carts-empty">
    <p><strong><?php esc_html_e('Keep your carts for later', 'stackless-lite-edd'); ?></strong></p>
    <p><?php esc_html_e('Sign in to save carts and reorder past purchases.', 'stackless-lite-edd'); ?></p>
    <p><a class="button edd-submit" href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Sign in', 'stackless-lite-edd'); ?></a></p>
</section>
