<?php
/**
 * Wishlist button, printed after the download's purchase button.
 *
 * Override in your theme at stackless-edd/wishlist/button.php.
 *
 * @var int    $download_id
 * @var int    $price_id  variable price saved by this button (0 = the download itself)
 * @var string $context   loop|single
 * @var bool   $in_list
 * @var bool   $variable  the button follows the price option chosen in the purchase form
 * @var string $nonce
 */

defined('ABSPATH') || exit;

$stackless_edd_label = $in_list ? __('Saved to wishlist', 'stackless-lite-edd') : __('Save to wishlist', 'stackless-lite-edd');
?>
<form class="stackless-wishlist-form stackless-wishlist-form--<?php echo esc_attr($context); ?>" method="post" data-stackless-wishlist-button data-download-id="<?php echo esc_attr((string) $download_id); ?>" data-variable="<?php echo $variable ? '1' : '0'; ?>">
    <input type="hidden" name="stackless_edd_wishlist_op" value="toggle">
    <input type="hidden" name="download_id" value="<?php echo esc_attr((string) $download_id); ?>">
    <input type="hidden" name="price_id" value="<?php echo esc_attr((string) $price_id); ?>">
    <input type="hidden" name="_stackless_edd_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
    <button type="submit" class="stackless-wishlist-toggle<?php echo $in_list ? ' is-saved' : ''; ?>" aria-pressed="<?php echo $in_list ? 'true' : 'false'; ?>">
        <svg class="stackless-wishlist-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19.5 12.572 12 20l-7.5-7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="stackless-wishlist-label"><?php echo esc_html($stackless_edd_label); ?></span>
    </button>
    <span class="stackless-wishlist-status screen-reader-text" role="status" aria-live="polite"></span>
</form>
