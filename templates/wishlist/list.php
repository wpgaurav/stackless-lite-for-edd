<?php
/**
 * Wishlist page, account page tab and shared view.
 *
 * Override in your theme at stackless-edd/wishlist/list.php.
 *
 * @var array<int, array<string, mixed>> $rows each: download_id, price_id, name, url, image, price_html, can_move
 * @var bool   $owner     the viewer owns this list
 * @var bool   $guest     the viewer is not signed in
 * @var string $share_url
 * @var string $nonce
 * @var string $login_url
 * @var string $shop_url
 * @var string $shared_by (shared view only)
 * @var bool   $shared    (shared view only)
 */

defined('ABSPATH') || exit;

$stackless_button_class = 'button edd-submit ' . (function_exists('edd_get_button_color_class') ? edd_get_button_color_class() : '');

$shared = !empty($shared);
?>
<section class="stackless-wishlist" data-stackless-wishlist-list aria-label="<?php esc_attr_e('Wishlist', 'stackless-lite-edd'); ?>">
    <?php if ($shared && !empty($shared_by)) : ?>
        <p class="stackless-wishlist-intro">
            <?php
            /* translators: %s: customer display name */
            echo esc_html(sprintf(__('Downloads saved by %s.', 'stackless-lite-edd'), $shared_by));
            ?>
        </p>
    <?php endif; ?>

    <?php if (!$rows) : ?>
        <div class="stackless-wishlist-empty">
            <p><strong><?php esc_html_e('No saved downloads yet.', 'stackless-lite-edd'); ?></strong></p>
            <?php if (!$shared) : ?>
                <p><?php esc_html_e('Tap the heart on any download to keep it here for later.', 'stackless-lite-edd'); ?></p>
            <?php endif; ?>
            <p><a class="<?php echo esc_attr($stackless_button_class); ?>" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Browse downloads', 'stackless-lite-edd'); ?></a></p>
        </div>
    <?php else : ?>
        <ul class="stackless-wishlist-items">
            <?php foreach ($rows as $row) : ?>
                <li class="stackless-wishlist-item" data-key="<?php echo esc_attr($row['download_id'] . ':' . $row['price_id']); ?>">
                    <a class="stackless-wishlist-thumb" href="<?php echo esc_url($row['url']); ?>"><?php echo wp_kses_post($row['image']); ?></a>
                    <div class="stackless-wishlist-info">
                        <a class="stackless-wishlist-name" href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['name']); ?></a>
                        <span class="stackless-wishlist-price"><?php echo wp_kses_post($row['price_html']); ?></span>
                    </div>
                    <div class="stackless-wishlist-actions">
                        <?php if ($row['can_move']) : ?>
                            <form method="post" data-stackless-wishlist-action="move">
                                <input type="hidden" name="stackless_edd_wishlist_op" value="move">
                                <input type="hidden" name="download_id" value="<?php echo esc_attr((string) $row['download_id']); ?>">
                                <input type="hidden" name="price_id" value="<?php echo esc_attr((string) $row['price_id']); ?>">
                                <input type="hidden" name="_stackless_edd_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                                <button type="submit" class="<?php echo esc_attr($stackless_button_class); ?>"><?php echo esc_html($owner ? __('Move to cart', 'stackless-lite-edd') : __('Add to cart', 'stackless-lite-edd')); ?></button>
                            </form>
                        <?php else : ?>
                            <a class="<?php echo esc_attr($stackless_button_class); ?>" href="<?php echo esc_url($row['url']); ?>"><?php esc_html_e('View download', 'stackless-lite-edd'); ?></a>
                        <?php endif; ?>
                        <?php if ($owner) : ?>
                            <form method="post" data-stackless-wishlist-action="remove">
                                <input type="hidden" name="stackless_edd_wishlist_op" value="remove">
                                <input type="hidden" name="download_id" value="<?php echo esc_attr((string) $row['download_id']); ?>">
                                <input type="hidden" name="price_id" value="<?php echo esc_attr((string) $row['price_id']); ?>">
                                <input type="hidden" name="_stackless_edd_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                                <button type="submit" class="stackless-wishlist-remove" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: download name */ __('Remove %s from wishlist', 'stackless-lite-edd'), $row['name'])); ?>"><?php esc_html_e('Remove', 'stackless-lite-edd'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="stackless-wishlist-status" role="status" aria-live="polite"></p>

    <?php if ($guest && $rows && $login_url) : ?>
        <p class="stackless-wishlist-note">
            <?php esc_html_e('This list is saved on this device.', 'stackless-lite-edd'); ?>
            <a href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Sign in to keep it in your account.', 'stackless-lite-edd'); ?></a>
        </p>
    <?php endif; ?>

    <?php if ($share_url) : ?>
        <div class="stackless-wishlist-share">
            <label for="stackless-wishlist-share-url"><strong><?php esc_html_e('Share this wishlist', 'stackless-lite-edd'); ?></strong></label>
            <div class="stackless-wishlist-share-row">
                <input id="stackless-wishlist-share-url" type="text" readonly value="<?php echo esc_url($share_url); ?>" data-stackless-share-url>
                <button type="button" class="<?php echo esc_attr($stackless_button_class); ?>" data-stackless-copy><?php esc_html_e('Copy link', 'stackless-lite-edd'); ?></button>
            </div>
            <form method="post" class="stackless-wishlist-reshare">
                <input type="hidden" name="stackless_edd_wishlist_op" value="reshare">
                <input type="hidden" name="_stackless_edd_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
                <button type="submit" class="stackless-link-button"><?php esc_html_e('Create a new link (stops the old one working)', 'stackless-lite-edd'); ?></button>
            </form>
        </div>
    <?php endif; ?>
</section>
