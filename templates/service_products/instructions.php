<?php
/**
 * After-payment instructions for service downloads (purchase confirmation, receipt page and the
 * account order view, which links to the receipt).
 *
 * Override in your theme at stackless-edd/service_products/instructions.php.
 *
 * @var array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}> $blocks
 * @var \EDD\Orders\Order $order
 */

defined('ABSPATH') || exit;

foreach ($blocks as $block) : ?>
    <section class="stackless-service-instructions">
        <h2><?php echo esc_html($block['heading']); ?></h2>
        <?php if (trim(wp_strip_all_tags($block['instructions'])) !== '') : ?>
            <?php echo wp_kses_post(wpautop($block['instructions'])); ?>
        <?php endif; ?>
        <?php if ($block['link'] !== '') : ?>
            <p class="stackless-service-link"><a class="button edd-submit" href="<?php echo esc_url($block['link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($block['link_label']); ?></a></p>
        <?php endif; ?>
    </section>
<?php endforeach;
