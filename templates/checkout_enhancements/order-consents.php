<?php
/**
 * Consent answers stored with an order: the purchase receipt and the order in purchase history.
 *
 * Override in your theme at stackless-edd/checkout_enhancements/order-consents.php.
 *
 * @var \EDD\Orders\Order $order
 * @var array[]           $records id, type, label, version, required, accepted, at (UTC)
 * @var string            $heading
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-order-consents">
    <h3 class="stackless-order-consents__title"><?php echo esc_html($heading); ?></h3>
    <dl>
        <?php foreach ($records as $record) :
            $time = strtotime((string) $record['at'] . ' UTC'); ?>
            <div>
                <dt><?php echo esc_html(wp_strip_all_tags($record['label'])); ?></dt>
                <dd>
                    <?php echo esc_html(!empty($record['accepted']) ? __('Accepted', 'stackless-lite-edd') : __('Not accepted', 'stackless-lite-edd')); ?>
                    <?php if ($time) : ?><br><small><time datetime="<?php echo esc_attr(gmdate('c', $time)); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time)); ?></time></small><?php endif; ?>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>
