=== Stackless Lite for EDD ===
Contributors: gauravtiwari
Tags: easy digital downloads, stripe, paypal, wishlist, pay what you want
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

21 free modules for Easy Digital Downloads: Stripe, PayPal, Polar, Paddle and Dodo Payments, wishlists, saved carts, pay what you like and more.

== Description ==

Stackless Lite for EDD replaces a handful of Easy Digital Downloads add-ons with one plugin, payment gateways included. Every feature is a module you switch on from Stackless → Dashboard. Switched-off modules load no code.

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

Payments:

* **Stripe**: cards, Apple Pay, Google Pay, Link and local payment methods, with refunds from the order screen.
* **PayPal**: PayPal Smart Buttons and Pay Later at checkout, with refunds.
* **Polar**: Polar as a merchant of record, with hosted checkout and sales tax and VAT handled for you.
* **Paddle**: Paddle Billing as a merchant of record, with an overlay checkout and sales tax and VAT handled for you.
* **Dodo Payments**: Dodo Payments as a merchant of record, with hosted checkout and sales tax, VAT and GST handled for you.
* **Payment Gateway Tools**: take payments with more than one Polar, Paddle or Dodo Payments account.

Customers also get an account page with their orders, downloads, profile and the tabs your modules add.

= Stackless for EDD =

[Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/) adds subscriptions with automatic renewals and saved payment methods, software licensing, page access, multi-currency, invoices, quotes, order bumps, on-page and modal checkout, coupon affiliates, webhooks, FluentCRM and AI agents. The Lite dashboard lists those modules so you can see what each one does. Lite never limits or times out its own features.

= Privacy =

Lite does not track you or your customers and sends no data to the author.
Once every 12 hours WordPress asks gauravtiwari.org whether a newer version of Lite exists (a plain request with no site details beyond what WordPress sends with any request).

== External services ==

The payment modules connect to the payment provider you switch on, and only that one. Nothing is sent until you add your API keys and a customer pays with that provider.

= Stripe =

When a customer pays with Stripe, the checkout loads Stripe.js from js.stripe.com so card details go straight to Stripe and never reach your server. Your site sends the order amount, currency, order ID, and the customer's name and email to the Stripe API (api.stripe.com) to create the payment, and Stripe sends payment events back to your site's webhook. Stripe's [Services Agreement](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy) apply.

= PayPal =

When a customer pays with PayPal, the checkout loads the PayPal JavaScript SDK from www.paypal.com and your site creates and captures the order through the PayPal API (api-m.paypal.com, or the sandbox in test mode), sending the order amount, currency, a description of the purchase and the order ID. PayPal sends payment events back to your site's webhook. PayPal's [User Agreement](https://www.paypal.com/us/legalhub/useragreement-full) and [Privacy Statement](https://www.paypal.com/us/legalhub/privacy-full) apply.

= Polar =

When a customer pays with Polar, your site creates a checkout through the Polar API (api.polar.sh, or sandbox-api.polar.sh in test mode), sending the products, amount, currency, order ID and the customer's email, and sends the customer to Polar's hosted checkout. Polar sends payment events back to your site's webhook. Polar's [Terms of Service](https://polar.sh/legal/terms) and [Privacy Policy](https://polar.sh/legal/privacy) apply.

= Paddle =

When a customer pays with Paddle, the checkout loads Paddle.js from cdn.paddle.com and your site creates a transaction through the Paddle API (api.paddle.com, or sandbox-api.paddle.com in test mode), sending the products, amount, currency, order ID and the customer's email. Paddle sends payment events back to your site's webhook. Paddle's [Terms](https://www.paddle.com/legal/terms) and [Privacy Policy](https://www.paddle.com/legal/privacy) apply.

= Dodo Payments =

When a customer pays with Dodo Payments, your site creates a checkout through the Dodo Payments API (live.dodopayments.com, or test.dodopayments.com in test mode), sending the products, amount, currency, order ID and the customer's name and email, and sends the customer to Dodo's hosted checkout. Dodo Payments sends payment events back to your site's webhook. Dodo Payments' [Terms of Use](https://dodopayments.com/legal/terms-of-use) and [Privacy Policy](https://dodopayments.com/legal/privacy-policy) apply.

= Links =

* [Product page and free download](https://gauravtiwari.org/product/stackless-lite-for-edd/)
* [Documentation](https://gauravtiwari.org/docs/stackless-for-edd/)
* [Source code on GitHub](https://github.com/wpgaurav/stackless-lite-for-edd)
* [Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/), the full plugin

== Installation ==

1. Install and activate Easy Digital Downloads 3.5 or newer (free or Pro).
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

= 1.2.0 =
* Paddle and Stripe: set up the webhook in one click from the gateway settings, for live and sandbox or test mode. Stackless creates the endpoint in your Paddle or Stripe account (or repairs one that already points at your store) and saves the secret for you.
* Paddle: the key instructions link straight to Paddle's Authentication screen for live and sandbox, and list the exact permissions to choose.
* Lite now activates on sites running Easy Digital Downloads Pro.
* Shared wishlist links open in the shared view again.

= 1.1.0 =
* New in Lite: Stripe, PayPal, Polar, Paddle and Dodo Payments gateways, Payment Gateway Tools, and the email for failed live payments in Extra Enhancements.
* The Emails screen no longer shows in Lite, which has no module that sends email.
* Safer input handling, output escaping and database queries throughout.

= 1.0.0 =
* First release: 15 modules, the Stackless dashboard, settings and emails screens, and the customer account page.
