<?php
/**
 * Stackless Lite for EDD: what the Stackless for EDD modules do, for the locked dashboard cards and the
 * read-only details view. Static text only: no code, settings or data of those modules ships in Lite.
 *
 * Each entry, keyed by a slug: title, description, group (dashboard group), icon (assets/icons name),
 * features (short bullet list).
 *
 * @package StacklessEDD
 */

defined('ABSPATH') || exit;

return [
    'subscriptions' => [
        'title' => __('Subscriptions', 'stackless-lite-edd'),
        'description' => __('Recurring billing on downloads and variable prices, with automatic or invoiced renewals and a customer portal.', 'stackless-lite-edd'),
        'group' => 'subscriptions',
        'icon' => 'repeat',
        'features' => [
            __('Daily, weekly, monthly or yearly plans on any download or price option', 'stackless-lite-edd'),
            __('Free trials, signup fees and fixed numbers of payments', 'stackless-lite-edd'),
            __('Mixed carts with subscriptions and one-time downloads in one order', 'stackless-lite-edd'),
            __('Automatic renewals through Stripe or PayPal, or renewal invoices customers pay by link', 'stackless-lite-edd'),
            __('Failed-payment retries, a grace period and renewal and trial-ending reminders', 'stackless-lite-edd'),
            __('Customers cancel, renew early, turn auto-renew off or switch plans with proration', 'stackless-lite-edd'),
        ],
    ],
    'on-page-checkout' => [
        'title' => __('On-page Checkout', 'stackless-lite-edd'),
        'description' => __('Plan cards and your checkout on any page with a block or shortcode, so buyers pay without leaving it.', 'stackless-lite-edd'),
        'group' => 'checkout',
        'icon' => 'browser-check',
        'features' => [
            __('Plan cards and the checkout on any page through a block or a shortcode', 'stackless-lite-edd'),
            __('Each plan gets its own cart, so the store cart is left alone', 'stackless-lite-edd'),
            __('Buyers pay with the gateways you already use', 'stackless-lite-edd'),
        ],
    ],
    'modal-checkout' => [
        'title' => __('Modal Checkout', 'stackless-lite-edd'),
        'description' => __('Buy buttons and checkout links open the checkout in a modal on the same page.', 'stackless-lite-edd'),
        'group' => 'checkout',
        'icon' => 'app-window',
        'features' => [
            __('Purchase buttons and checkout links open checkout in a modal', 'stackless-lite-edd'),
            __('Works with buy links and add-to-cart links', 'stackless-lite-edd'),
            __('Optional classes to style each payment method', 'stackless-lite-edd'),
        ],
    ],
    'checkout-builder' => [
        'title' => __('Checkout Builder', 'stackless-lite-edd'),
        'description' => __('Ready-made checkout layouts with your colours, previewed and switched on without an editor.', 'stackless-lite-edd'),
        'group' => 'checkout',
        'icon' => 'layout-columns',
        'features' => [
            __('Five checkout layouts, previewed with sample content or your own cart', 'stackless-lite-edd'),
            __('Your accent, background and summary colours, width and corner radius', 'stackless-lite-edd'),
            __('Text colour picked automatically for contrast', 'stackless-lite-edd'),
            __('Works with the EDD Checkout block and the [download_checkout] shortcode', 'stackless-lite-edd'),
        ],
    ],
    'order-bumps' => [
        'title' => __('Order Bumps', 'stackless-lite-edd'),
        'description' => __('One-click add-on offers at checkout, shown by cart rules and priced on the server.', 'stackless-lite-edd'),
        'group' => 'sales',
        'icon' => 'shopping-cart-plus',
        'features' => [
            __('Offers shown by cart rules, before the payment details, above the purchase button or in the summary', 'stackless-lite-edd'),
            __('Percentage off, amount off, a fixed price or the regular price', 'stackless-lite-edd'),
            __('Download image, custom image or no image', 'stackless-lite-edd'),
            __('Take rates for every bump', 'stackless-lite-edd'),
        ],
    ],
    'custom-invoices' => [
        'title' => __('Custom Invoices', 'stackless-lite-edd'),
        'description' => __('Invoice customers for downloads or custom items with a branded, printable invoice and a secure pay link.', 'stackless-lite-edd'),
        'group' => 'sales',
        'icon' => 'file-invoice',
        'features' => [
            __('Numbered invoices with your prefix, suffix and format', 'stackless-lite-edd'),
            __('Your business profile, logo, tax IDs, payment terms and footer', 'stackless-lite-edd'),
            __('A secure pay link that takes the customer to checkout', 'stackless-lite-edd'),
            __('Recurring invoices, deposits with automatic balance invoices, and due-date reminders', 'stackless-lite-edd'),
            __('An Invoices tab on the customer account page', 'stackless-lite-edd'),
        ],
    ],
    'request-quotes' => [
        'title' => __('Request a Quote', 'stackless-lite-edd'),
        'description' => __('Let customers ask for a quote from any page or download, and reply with a proposal they accept online.', 'stackless-lite-edd'),
        'group' => 'sales',
        'icon' => 'message-2-dollar',
        'features' => [
            __('A quote form on any page and an optional button on download pages', 'stackless-lite-edd'),
            __('Proposals with a validity period and an expiry reminder', 'stackless-lite-edd'),
            __('Accepting a proposal issues the invoice and takes the customer to payment', 'stackless-lite-edd'),
            __('Quote-only downloads show "Price on request" instead of a price', 'stackless-lite-edd'),
        ],
    ],
    'coupon-affiliates' => [
        'title' => __('Coupon Affiliates', 'stackless-lite-edd'),
        'description' => __('Customers and partners earn a commission on every paid order that uses their discount code.', 'stackless-lite-edd'),
        'group' => 'sales',
        'icon' => 'tag',
        'features' => [
            __('Personal discount codes from the account page, or any discount you assign', 'stackless-lite-edd'),
            __('Commission rate, refund hold and minimum payout you set', 'stackless-lite-edd'),
            __('Automatic reversal on refunds and cancellations', 'stackless-lite-edd'),
            __('Manual payouts with CSV export', 'stackless-lite-edd'),
        ],
    ],
    'multi-currency' => [
        'title' => __('Multi-Currency Checkout', 'stackless-lite-edd'),
        'description' => __('One settlement currency per cart and order, with exact prices per currency.', 'stackless-lite-edd'),
        'group' => 'currency',
        'icon' => 'world',
        'features' => [
            __('Exact prices per download and price option in each currency', 'stackless-lite-edd'),
            __('Manual rates or automatic Frankfurter exchange rates', 'stackless-lite-edd'),
            __('Rate markup, price rounding and rules for downloads without a price', 'stackless-lite-edd'),
            __('Orders, renewals and refunds stay in the currency the customer paid', 'stackless-lite-edd'),
        ],
    ],
    'currency-selector' => [
        'title' => __('Storefront Currency Selector', 'stackless-lite-edd'),
        'description' => __('Let shoppers pick their currency with a shortcode, a block, or a selector at checkout.', 'stackless-lite-edd'),
        'group' => 'currency',
        'icon' => 'currency-dollar',
        'features' => [
            __('A selector shortcode, a block, and one above the checkout payment methods', 'stackless-lite-edd'),
            __('Local currency by visitor location', 'stackless-lite-edd'),
            __('Switching reprices the cart on the server', 'stackless-lite-edd'),
        ],
    ],
    'currency-gateways' => [
        'title' => __('Currency-Aware Payment Gateways', 'stackless-lite-edd'),
        'description' => __('Choose which payment gateways handle each currency and which one is shown first.', 'stackless-lite-edd'),
        'group' => 'currency',
        'icon' => 'arrows-exchange',
        'features' => [
            __('Gateways per currency, with a default for each', 'stackless-lite-edd'),
            __('Unsupported gateways are removed at checkout and refused on the server', 'stackless-lite-edd'),
        ],
    ],
    'software-licensing' => [
        'title' => __('Software Licensing', 'stackless-lite-edd'),
        'description' => __('Sell plugins and themes with license keys, site activation limits, a license API and automatic updates from your store.', 'stackless-lite-edd'),
        'group' => 'access',
        'icon' => 'key',
        'features' => [
            __('License keys with site activation limits and development sites that don\'t count', 'stackless-lite-edd'),
            __('A license API with rate limits and automatic plugin and theme updates', 'stackless-lite-edd'),
            __('Lifetime, subscription or fixed-length licenses with grace days', 'stackless-lite-edd'),
            __('Version history and changelogs per download', 'stackless-lite-edd'),
            __('A Licenses tab where customers manage their sites', 'stackless-lite-edd'),
        ],
    ],
    'page-access' => [
        'title' => __('Page Access', 'stackless-lite-edd'),
        'description' => __('Unlock chosen pages for customers who buy a download.', 'stackless-lite-edd'),
        'group' => 'access',
        'icon' => 'lock',
        'features' => [
            __('Published pages show an access notice to visitors without a purchase', 'stackless-lite-edd'),
            __('Private pages stay hidden from everyone except buyers', 'stackless-lite-edd'),
            __('Subscriptions keep access while active', 'stackless-lite-edd'),
            __('A My pages tab on the account page', 'stackless-lite-edd'),
        ],
    ],
    'product-addons' => [
        'title' => __('Fields & Paid Add-ons', 'stackless-lite-edd'),
        'description' => __('Download add-ons with their own prices, and collapsible checkout fields with optional fees.', 'stackless-lite-edd'),
        'group' => 'store',
        'icon' => 'puzzle',
        'features' => [
            __('Checkbox, dropdown, text and number add-ons on download pages', 'stackless-lite-edd'),
            __('Per-item or one-off add-on prices', 'stackless-lite-edd'),
            __('Extra checkout fields in a collapsible section, with optional fees', 'stackless-lite-edd'),
        ],
    ],
    'edd-redesign' => [
        'title' => __('EDD Redesign', 'stackless-lite-edd'),
        'description' => __('Give the Easy Digital Downloads admin a clean, modern look. Nothing changes on your storefront.', 'stackless-lite-edd'),
        'group' => 'admin',
        'icon' => 'palette',
        'features' => [
            __('Orders, downloads, customers, discounts, reports, settings and tools restyled', 'stackless-lite-edd'),
            __('Your accent color and table density', 'stackless-lite-edd'),
            __('Store-at-a-glance numbers and guidance while you work', 'stackless-lite-edd'),
        ],
    ],
    'fluent-crm' => [
        'title' => __('FluentCRM', 'stackless-lite-edd'),
        'description' => __('Connects Stackless to FluentCRM for subscriptions, renewals, licenses, invoices and quotes.', 'stackless-lite-edd'),
        'group' => 'admin',
        'icon' => 'mail',
        'features' => [
            __('Automation triggers for subscriptions, renewals, licenses, invoices and quotes', 'stackless-lite-edd'),
            __('Tags and lists that follow subscription status', 'stackless-lite-edd'),
            __('Smart codes for your emails', 'stackless-lite-edd'),
            __('Subscriptions and licenses on each contact\'s profile', 'stackless-lite-edd'),
        ],
    ],
    'developer-api' => [
        'title' => __('Webhooks and REST API', 'stackless-lite-edd'),
        'description' => __('Connect Stackless to Zapier, Make, n8n or your own code.', 'stackless-lite-edd'),
        'group' => 'admin',
        'icon' => 'code',
        'features' => [
            __('Signed webhooks for order, subscription and license events, retried on failure', 'stackless-lite-edd'),
            __('A subscriptions REST API: list, read, cancel and reactivate', 'stackless-lite-edd'),
            __('Works with EDD API keys', 'stackless-lite-edd'),
        ],
    ],
    'ai-agents' => [
        'title' => __('AI Agents', 'stackless-lite-edd'),
        'description' => __('Let AI agents such as Claude, ChatGPT and Cursor run your store through the free Site Agent plugin.', 'stackless-lite-edd'),
        'group' => 'admin',
        'icon' => 'sparkles',
        'features' => [
            __('Sales reports, best sellers, orders, customers and downloads', 'stackless-lite-edd'),
            __('Invoices, quotes, subscriptions, licenses and page access', 'stackless-lite-edd'),
            __('Agents work as a WordPress user you choose', 'stackless-lite-edd'),
            __('Agents ask you before anything reaches a customer', 'stackless-lite-edd'),
        ],
    ],
];
