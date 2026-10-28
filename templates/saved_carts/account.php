<?php
/**
 * Saved carts and order again (account page tab and [stackless_edd_saved_carts]).
 *
 * Override in your theme at stackless-edd/saved_carts/account.php.
 *
 * @var array<int, array<string, mixed>> $carts  each: key, name, created, lines, quantity, previews[]
 * @var array<int, array<string, mixed>> $orders each: id, number, date, status, total_html, again_url, view_url
 * @var bool   $can_save
 * @var int    $cart_count
 * @var int    $limit
 * @var string $nonce
 * @var bool   $in_account
 * @var string $shop_url
 */

defined('ABSPATH') || exit;

$stackless_edd_button_class = 'button edd-submit ' . (function_exists('edd_get_button_color_class') ? edd_get_button_color_class() : '');
?>
<section class="stackless-saved-carts" aria-label="<?php esc_attr_e('Saved carts and order again', 'stackless-lite-edd'); ?>">
    <p class="stackless-saved-carts-intro"><?php esc_html_e('Keep a cart for later, or start a new cart from a past order. Prices are checked again when you restore.', 'stackless-lite-edd'); ?></p>

    <div class="stackless-saved-carts-panel">
        <h3><?php esc_html_e('Save your current cart', 'stackless-lite-edd'); ?></h3>
        <?php if ($can_save) : ?>
            <form method="post" class="stackless-saved-carts-save">
                <input type="hidden" name="stackless_edd_saved_carts_op" value="save">
                <input type="hidden" name="_stackless_edd_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                <p class="stackless-saved-carts-muted">
                    <?php
                    /* translators: %d: number of items */
                    echo esc_html(sprintf(_n('%d item in your cart.', '%d items in your cart.', $cart_count, 'stackless-lite-edd'), $cart_count));
                    ?>
                </p>
                <label for="stackless-saved-cart-name"><?php esc_html_e('Cart name', 'stackless-lite-edd'); ?></label>
                <span class="stackless-saved-carts-row">
                    <input type="text" id="stackless-saved-cart-name" name="cart_name" maxlength="100" required placeholder="<?php esc_attr_e('For example: monthly bundle', 'stackless-lite-edd'); ?>">
                    <button type="submit" class="<?php echo esc_attr($stackless_edd_button_class); ?>"><?php esc_html_e('Save cart', 'stackless-lite-edd'); ?></button>
                </span>
            </form>
        <?php else : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Your cart is empty. Add downloads, then save the cart here.', 'stackless-lite-edd'); ?></p>
            <p><a class="<?php echo esc_attr($stackless_edd_button_class); ?>" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Browse downloads', 'stackless-lite-edd'); ?></a></p>
        <?php endif; ?>
    </div>

    <div class="stackless-saved-carts-section">
        <h3><?php esc_html_e('Saved for later', 'stackless-lite-edd'); ?> <span class="stackless-saved-carts-count"><?php echo esc_html(count($carts) . ' / ' . $limit); ?></span></h3>
        <?php if (!$carts) : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Saved carts appear here.', 'stackless-lite-edd'); ?></p>
        <?php endif; ?>
        <?php foreach ($carts as $stackless_edd_cart) : ?>
            <article class="stackless-saved-cart">
                <div class="stackless-saved-cart-info">
                    <strong><?php echo esc_html($stackless_edd_cart['name']); ?></strong>
                    <span class="stackless-saved-carts-muted">
                        <?php
                        /* translators: %d: number of downloads */
                        echo esc_html(sprintf(_n('%d download', '%d downloads', (int) $stackless_edd_cart['lines'], 'stackless-lite-edd'), (int) $stackless_edd_cart['lines']));
                        if ($stackless_edd_cart['created']) {
                            echo ' · ' . esc_html(wp_date((string) get_option('date_format'), (int) $stackless_edd_cart['created']));
                        }
                        ?>
                    </span>
                    <span class="stackless-saved-cart-previews">
                        <?php foreach ($stackless_edd_cart['previews'] as $preview) : ?>
                            <span title="<?php echo esc_attr($preview['name']); ?>"><?php echo wp_kses_post($preview['image']); ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>
                <div class="stackless-saved-cart-actions">
                    <form method="post">
                        <input type="hidden" name="stackless_edd_saved_carts_op" value="restore">
                        <input type="hidden" name="cart_key" value="<?php echo esc_attr($stackless_edd_cart['key']); ?>">
                        <input type="hidden" name="_stackless_edd_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <label class="stackless-saved-carts-muted"><input type="checkbox" name="replace" value="1" checked> <?php esc_html_e('Replace my current cart', 'stackless-lite-edd'); ?></label>
                        <button type="submit" class="<?php echo esc_attr($stackless_edd_button_class); ?>"><?php esc_html_e('Restore cart', 'stackless-lite-edd'); ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="stackless_edd_saved_carts_op" value="delete">
                        <input type="hidden" name="cart_key" value="<?php echo esc_attr($stackless_edd_cart['key']); ?>">
                        <input type="hidden" name="_stackless_edd_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <button type="submit" class="stackless-link-button"><?php esc_html_e('Remove', 'stackless-lite-edd'); ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="stackless-saved-carts-section">
        <h3><?php esc_html_e('Order again', 'stackless-lite-edd'); ?></h3>
        <?php if (!$orders) : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Your past orders appear here so you can reorder in one click.', 'stackless-lite-edd'); ?></p>
        <?php endif; ?>
        <?php foreach ($orders as $order) : ?>
            <article class="stackless-saved-cart">
                <div class="stackless-saved-cart-info">
                    <strong>
                        <?php
                        /* translators: %s: order number */
                        echo esc_html(sprintf(__('Order #%s', 'stackless-lite-edd'), $order['number']));
                        ?>
                    </strong>
                    <span class="stackless-saved-carts-muted">
                        <?php
                        echo esc_html(($order['date'] !== '' ? $order['date'] . ' · ' : '') . $order['status'] . ' · ');
                        echo wp_kses_post($order['total_html']);
                        ?>
                    </span>
                </div>
                <div class="stackless-saved-cart-actions">
                    <a class="<?php echo esc_attr($stackless_edd_button_class); ?>" href="<?php echo esc_url($order['again_url']); ?>"><?php esc_html_e('Order again', 'stackless-lite-edd'); ?></a>
                    <a class="stackless-link-button" href="<?php echo esc_url($order['view_url']); ?>"><?php esc_html_e('View', 'stackless-lite-edd'); ?></a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
