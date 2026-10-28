<img src=".github/assets/icon-256x256.png" width="96" height="96" alt="Stackless Lite for EDD logo">

# Stackless Lite for EDD

Fifteen free store tools for [Easy Digital Downloads](https://wordpress.org/plugins/easy-digital-downloads/) in one plugin. Switch on only what you use; switched-off modules load no code.

**[Product page](https://gauravtiwari.org/product/stackless-lite-for-edd/)** · [Download the latest ZIP](https://gauravtiwari.org/wp-json/gt-plugin-updates/v1/plugins/stackless-lite-for-edd/download) · [Documentation](https://gauravtiwari.org/docs/stackless-for-edd/) · [Upgrade to Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/)

| Group | Modules |
|---|---|
| Catalog | Free Price Text, Custom Add to Cart Text, Sold Count, Min/Max Quantity, Hide Price Until Login, External Downloads, Product Details Table |
| Store tools | Wishlist, Saved Carts & Order Again, Service Products, EDD Optimizer |
| Checkout and sales | Checkout Enhancements, Extra Enhancements, Buy Links, Pay What You Like |

Customers also get an account page with their orders, downloads, profile and the tabs your modules add.

**Requires** WordPress 6.7+, PHP 8.0+ and Easy Digital Downloads 3.5+. **Version** 1.2.1. **License** GPL-2.0-or-later.

## Install

1. Download `stackless-lite-edd-<version>.zip` from [Releases](../../releases) or the [product page](https://gauravtiwari.org/product/stackless-lite-for-edd/).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Open **Stackless → Dashboard** and switch on the modules you want.

Keep the plugin folder named `stackless-lite-edd`. GitHub's "Download ZIP" button names it after the branch, so use the release zip.

## Updates

Lite updates from gauravtiwari.org without a license key. Updates appear under **Dashboard → Updates** like any plugin, and each package is checked against its SHA-256 checksum before it installs. Lite sends nothing beyond the plain request WordPress makes, and it does no tracking.

## Moving to Stackless for EDD

[Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/) adds subscriptions, Stripe, PayPal, Polar, Paddle and Dodo Payments, software licensing, page access, multi-currency, invoices, quotes, order bumps, on-page and modal checkout, coupon affiliates, webhooks, FluentCRM and AI agents.

- **Upgrade:** activate Stackless for EDD. It switches Lite off and keeps every Lite setting and record (same options, meta, tables and hooks).
- **Go back:** deactivate Stackless for EDD and activate Lite. Data of the modules Lite doesn't have stays untouched for later.
- The two never run together. If both are active, Lite stays idle.

## Data and uninstall

Deleting Lite keeps your data. To remove it, define `STACKLESS_EDD_REMOVE_ALL_DATA` as `true` in `wp-config.php` before deleting. Nothing is removed while Stackless for EDD is installed, and EDD orders are never deleted.

## Support

Bugs and feature ideas: [open an issue](../../issues/new/choose). Documentation: [gauravtiwari.org/docs/stackless-for-edd](https://gauravtiwari.org/docs/stackless-for-edd/).
