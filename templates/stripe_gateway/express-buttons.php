<?php
/**
 * Express checkout buttons (Apple Pay, Google Pay, Link) for download pages and the checkout.
 *
 * Override by copying to yourtheme/stackless-edd/stripe_gateway/express-buttons.php.
 * Keep the .stackless-stripe-express element and its data-config attribute; the script mounts the
 * Stripe Express Checkout Element inside it and reveals the wrapper only when a wallet is available.
 *
 * @var string $context product|checkout
 * @var array<string,mixed> $config
 * @var string $terms_text EDD's "Agree to terms" label when the store asks for it, else ''.
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-stripe-express-wrap stackless-stripe-express-<?php echo esc_attr($context); ?>" hidden>
    <div class="stackless-stripe-express" data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>"></div>
    <div class="stackless-stripe-express-errors" role="alert" aria-live="polite"></div>
    <?php if ($terms_text !== '') : ?>
        <p class="stackless-stripe-express-terms">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: the store's "agree to terms" label */
                __('By paying with an express method you accept the store terms (%s).', 'stackless-lite-edd'),
                wp_strip_all_tags($terms_text)
            ));
            ?>
        </p>
    <?php endif; ?>
    <?php if ($context !== 'product') : ?>
        <p class="stackless-stripe-express-separator"><span><?php esc_html_e('or', 'stackless-lite-edd'); ?></span></p>
    <?php endif; ?>
</div>
