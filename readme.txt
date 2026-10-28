=== Stackless Lite for EDD ===
Contributors: gauravtiwari
Tags: easy digital downloads, wishlist, pay what you want, quantity limits, checkout
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fifteen free store tools for Easy Digital Downloads in one plugin: wishlists, saved carts, pay what you like, buy links, quantity limits and more.

== Description ==

Stackless Lite for EDD replaces a handful of small Easy Digital Downloads add-ons with one plugin. Every feature is a module you switch on from Stackless → Dashboard. Switched-off modules load no code.

Modules in Lite:

* **Free Price Text**: show "Free" or your own text instead of a zero price.
* **Custom Add to Cart Text**: rename the purchase button per download type, download or price option.
* **Sold Count**: an "X sold" badge once a download passes a threshold.
* **Min/Max Quantity**: minimum, maximum and step quantities, store-wide or per download and price option.
* **Hide Price Until Login**: hide prices and buying from guests, store-wide, by category or per download.
* **External Downloads**: sell a download on another website with an external buy link.
* **Product Details Table**: a specifications table on downloads that also feeds product schema.
* **Wishlist**: guest and account wishlists that merge on sign-in, with share links and move to cart.
* **Saved Carts & Order Again**: customers save carts under a name and reorder from past orders.
* **Service Products**: after-payment instructions and a booking or intake link for services.
* **EDD Optimizer**: load EDD scripts and styles only where they are used.
* **Checkout Enhancements**: consent checkboxes with stored proof, a shorter checkout for free orders and a trust block.
* **Extra Enhancements**: per-download payment methods, sticky ?coupon= links, internal test emails and a checkout guard.
* **Buy Links**: shareable, signed links that fill the cart and open checkout.
* **Pay What You Like**: buyers choose the price, with a minimum, a suggested amount and quick amounts.

Customers also get an account page with their orders, downloads, profile and the tabs your modules add.

= Stackless for EDD =

[Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/) adds subscriptions, Stripe, PayPal, Polar, Paddle and Dodo Payments, software licensing, page access, multi-currency, invoices, quotes, order bumps, on-page and modal checkout, coupon affiliates, webhooks, FluentCRM and AI agents. The Lite dashboard lists those modules so you can see what each one does. Lite never limits or times out its own features.

= Privacy =

Lite does not track you or your customers and sends no data to the author. Once every 12 hours WordPress asks gauravtiwari.org whether a newer version of Lite exists (a plain request with no site details beyond what WordPress sends with any request).

== Installation ==

1. Install and activate Easy Digital Downloads 3.5 or newer.
2. Upload the `stackless-lite-edd` folder to `/wp-content/plugins/`, or upload the zip from Plugins → Add New → Upload Plugin.
3. Activate Stackless Lite for EDD.
4. Open Stackless → Dashboard and switch on the modules you want.

== Frequently Asked Questions ==

= How do I upgrade to Stackless for EDD? =

Install and activate Stackless for EDD. It switches Lite off on its own and tells you once that your settings and data carried over. Every Lite setting, wishlist, saved cart, download setting, quantity limit, pay-what-you-like amount and consent record stays where it is, because both plugins use the same options, meta, tables and hooks. You can then delete Lite.

= Can I go back from Stackless for EDD to Lite? =

Yes. Deactivate Stackless for EDD and activate Lite. Lite runs its modules with the same data. Settings and data of the modules only Stackless for EDD has are left untouched, so they are all there if you activate it again.

= Can Lite and Stackless for EDD run together? =

No, and they don't need to: Stackless for EDD includes everything in Lite. If both are active, Lite stays idle.

= Does deleting Lite remove my data? =

No. Data is kept unless you define `STACKLESS_EDD_REMOVE_ALL_DATA` as `true` in wp-config.php before deleting, and even then nothing is removed while Stackless for EDD is installed.

= How does Lite update? =

From gauravtiwari.org, without a license key. Updates show up on Dashboard → Updates like any other plugin, and each package is checked against its published SHA-256 checksum before it installs.

== Changelog ==

= 1.0.0 =
* First release: 15 modules, the Stackless dashboard, settings and emails screens, and the customer account page.
