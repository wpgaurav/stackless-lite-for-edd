<?php
/**
 * Customer account page: tab navigation plus the current tab.
 *
 * Override in your theme at stackless-edd/account/account.php.
 *
 * @var array<string,array{title:string,icon:string,priority:int,callback:callable}> $tabs
 * @var string               $current
 * @var array<string,string> $query
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-account">
    <nav class="stackless-account__nav" aria-label="<?php esc_attr_e('Account', 'stackless-lite-edd'); ?>">
        <ul>
            <?php foreach ($tabs as $tab_id => $tab) : ?>
                <li>
                    <a href="<?php echo esc_url(stackless_edd_account_url($tab_id)); ?>"<?php echo $tab_id === $current ? ' aria-current="page"' : ''; ?>>
                        <?php echo \StacklessEDD\Admin\Icons::svg($tab['icon'], 18); // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG. ?>
                        <span><?php echo esc_html($tab['title']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li>
                <a href="<?php echo esc_url(wp_logout_url(stackless_edd_account_url())); ?>">
                    <?php echo \StacklessEDD\Admin\Icons::svg('external-link', 18); // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG. ?>
                    <span><?php esc_html_e('Log out', 'stackless-lite-edd'); ?></span>
                </a>
            </li>
        </ul>
    </nav>
    <div class="stackless-account__content">
        <?php
        if (isset($tabs[$current])) {
            call_user_func($tabs[$current]['callback'], $query);
        }
        ?>
    </div>
</div>
