<?php
/**
 * Service instructions in the plain-text purchase receipt email (EDD email template "No template, plain text only").
 *
 * Override in your theme at stackless-edd/service_products/email-plain.php.
 *
 * @var array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}> $blocks
 * @var \EDD\Orders\Order $order
 */

defined('ABSPATH') || exit;

foreach ($blocks as $stackless_edd_block) {
    echo "\n" . esc_html(function_exists('mb_strtoupper') ? mb_strtoupper($stackless_edd_block['heading']) : strtoupper($stackless_edd_block['heading'])) . "\n\n";
    $stackless_edd_text = trim(wp_strip_all_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], "\n", $stackless_edd_block['instructions'])));
    if ($stackless_edd_text !== '') {
        echo esc_html(html_entity_decode($stackless_edd_text, ENT_QUOTES, 'UTF-8')) . "\n";
    }
    if ($stackless_edd_block['link'] !== '') {
        echo "\n" . esc_html($stackless_edd_block['link_label']) . ': ' . esc_url_raw($stackless_edd_block['link']) . "\n";
    }
    echo "\n----------------------------------------\n";
}
