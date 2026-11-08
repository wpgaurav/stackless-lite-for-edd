=== Stackless Lite for EDD ===
Contributors: gauravtiwari
Tags: easy digital downloads, stripe, paypal, wishlist, pay what you want
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

28 free modules for Easy Digital Downloads: Stripe, PayPal, Razorpay, Mollie, Square, Creem, Paystack, Flutterwave, Mercado Pago, Polar, Paddle and Dodo Payments, wishlists, saved carts, pay what you like and more.

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

* **Stripe**: cards, Apple Pay, Google Pay, Link and local payment methods, with refunds from the order screen, plus Stripe Managed Payments with Stripe as merchant of record for digital downloads.
* **PayPal**: PayPal Smart Buttons and Pay Later at checkout, card fields, Fastlane, Apple Pay and Google Pay, with refunds.
* **Razorpay**: UPI, cards, netbanking and wallets with Razorpay Checkout, with refunds both ways and a signed webhook.
* **Mollie**: cards, iDEAL, Bancontact, PayPal, Apple Pay and every other method in your Mollie account on Mollie's hosted checkout, with refunds both ways.
* **Square**: cards, Apple Pay and Google Pay with Square's own payment form, cards saved on file with Square, and refunds both ways.
* **Creem**: Creem as a merchant of record for software and digital products, with hosted checkout and sales tax and VAT handled for you, in US dollars or euros.
* **Paystack**: cards, bank transfer, USSD, mobile money and Apple Pay on Paystack's hosted checkout, for stores in Nigeria, Ghana, South Africa, Kenya and Côte d'Ivoire.
* **Flutterwave**: cards, bank transfer, mobile money and USSD on Flutterwave's hosted checkout, with refunds both ways.
* **Mercado Pago**: Checkout Pro for stores in Argentina, Brazil, Chile, Colombia, Mexico, Peru and Uruguay, with cards, Pix, boleto, OXXO and local methods in the account's currency.
* **Polar**: Polar as a merchant of record, with hosted checkout and sales tax and VAT handled for you.
* **Paddle**: Paddle Billing as a merchant of record, with an overlay checkout and sales tax and VAT handled for you.
* **Dodo Payments**: Dodo Payments as a merchant of record, with hosted checkout and sales tax, VAT and GST handled for you.
* **Payment Gateway Tools**: take payments with more than one Polar, Paddle, Dodo Payments or Creem account.

Customers also get an account page with their orders, downloads, profile and the tabs your modules add.

= Stackless for EDD =

[Stackless for EDD](https://gauravtiwari.org/product/stackless-for-edd/) adds subscriptions with automatic renewals and saved payment methods, an EDD Recurring importer and Billing Health, software licensing with beta channels, page access, multi-currency, invoices, quotes, order bumps, post-purchase offers, checkout recovery, VAT numbers, gift cards and store credit, on-page and modal checkout, coupon affiliates, webhooks, FluentCRM and AI agents. The Lite dashboard lists those modules so you can see what each one does. Lite never limits or times out its own features.

= Privacy =

Lite does not track you or your customers and sends no data to the author.
Once every 12 hours WordPress asks gauravtiwari.org whether a newer version of Lite exists (a plain request with no site details beyond what WordPress sends with any request).

== External services ==

The payment modules connect to the payment provider you switch on, and only that one. Nothing is sent until you add your API keys and a customer pays with that provider.

= Stripe =

When a customer pays with Stripe, the checkout loads Stripe.js from js.stripe.com so card details go straight to Stripe and never reach your server. Your site sends the order amount, currency, order ID, and the customer's name and email to the Stripe API (api.stripe.com) to create the payment, and Stripe sends payment events back to your site's webhook. With Stripe Managed Payments, the buyer pays on a page of your store that embeds Stripe Checkout (js.stripe.com); Stripe is the seller of record and receives the order's line items, amounts and the customer's email. Stripe's [Services Agreement](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy) apply.

= PayPal =

When a customer pays with PayPal, the checkout loads the PayPal JavaScript SDK from www.paypal.com and your site creates and captures the order through the PayPal API (api-m.paypal.com, or the sandbox in test mode), sending the order amount, currency, a description of the purchase and the order ID. PayPal card fields and Fastlane load from www.paypal.com, so card details go to PayPal and never reach your server; card payments also send the billing address for verification, and Fastlane looks up the buyer's email with PayPal. Apple Pay through PayPal loads Apple Pay JS from applepay.cdn-apple.com and Google Pay loads from pay.google.com; both receive the total and currency for the payment sheet. PayPal sends payment events back to your site's webhook. PayPal's [User Agreement](https://www.paypal.com/us/legalhub/useragreement-full) and [Privacy Statement](https://www.paypal.com/us/legalhub/privacy-full) apply.

= Razorpay =

When a customer pays with Razorpay, the payment page loads Razorpay Checkout from checkout.razorpay.com, where the customer enters payment details with Razorpay, never on your site. Your site creates the payment through the Razorpay API (api.razorpay.com), sending the amount, currency, order ID and your site's host name, and for saved payment methods the customer's name, email and phone. Razorpay sends payment, refund and mandate events back to your site's webhook. Razorpay's [Terms of Use](https://razorpay.com/terms/) and [Privacy Policy](https://razorpay.com/privacy/) apply.

= Mollie =

When a customer pays with Mollie, your site creates the payment through the Mollie API (api.mollie.com), sending the amount, currency, a description with the order number, the order ID and your site's host name, the return and webhook addresses, the customer's name, email and address, and for saved payment methods a Mollie customer with the name and email. The customer pays on Mollie's hosted checkout (www.mollie.com). Mollie calls your site's webhook with a payment ID, and your site reads the payment back from the Mollie API. Mollie's [User Agreement](https://www.mollie.com/user-agreement) and [Privacy Policy](https://www.mollie.com/privacy) apply.

= Square =

When a customer pays with Square, the checkout loads Square's Web Payments SDK from web.squarecdn.com (sandbox.web.squarecdn.com in sandbox mode), so card details go straight to Square and never reach your server. Your site creates the payment through the Square API (connect.squareup.com, or connect.squareupsandbox.com in sandbox mode), sending the amount, currency, order ID, a note with the order number, the customer's email and billing address, and for saved cards a Square customer with the name and email. Google Pay and Apple Pay open their own payment sheets. Square sends payment, refund and card events back to your site's webhook. Square's [Terms of Service](https://squareup.com/legal/general/ua) and [Privacy Notice](https://squareup.com/legal/general/privacy) apply.

= Creem =

When a customer pays with Creem, your site creates a product and a checkout through the Creem API (api.creem.io, or test-api.creem.io in test mode), sending the download name, price, currency, order ID, subscription schedule and the customer's email, and sends the customer to Creem's hosted checkout. Creem sends payment, subscription, refund and dispute events back to your site's webhook. Creem's [Terms of Service](https://www.creem.io/terms) and [Privacy Policy](https://www.creem.io/privacy) apply.

= Paystack =

When a customer pays with Paystack, your site starts a transaction through the Paystack API (api.paystack.co), sending the amount, currency, order ID and number, and the customer's email and name, and sends the customer to Paystack's hosted checkout. Paystack sends payment, refund and dispute events back to your site's webhook. Paystack's [Terms of Service](https://paystack.com/terms) and [Merchant Privacy Policy](https://paystack.com/privacy/merchant) apply.

= Flutterwave =

When a customer pays with Flutterwave, your site creates a payment link through the Flutterwave API (api.flutterwave.com), sending the amount, currency, order ID and number, the store name, and the customer's email and name, and sends the customer to Flutterwave's hosted checkout. Flutterwave sends payment and refund events back to your site's webhook. Flutterwave's [Terms and Conditions](https://flutterwave.com/us/terms) and [Privacy Notice](https://flutterwave.com/us/privacy-notice) apply.

= Mercado Pago =

When a customer pays with Mercado Pago, your site creates a Checkout Pro preference through the Mercado Pago API (api.mercadopago.com), sending the order total, currency, order ID and number, the site host, and the customer's email and name, and sends the customer to Mercado Pago's checkout. Your site also reads the account's country and test status from the API when you save the access token. Mercado Pago sends payment notifications back to your site's webhook. Mercado Pago's terms and privacy policy for your account's country apply, for example in [Argentina](https://www.mercadopago.com.ar/ayuda/terminos-y-politicas_194) ([privacy](https://www.mercadopago.com.ar/privacidad)), [Brazil](https://www.mercadopago.com.br/ajuda/termos-e-politicas_194) ([privacy](https://www.mercadopago.com.br/privacidade)) and [Mexico](https://www.mercadopago.com.mx/ayuda/terminos-y-politicas_194) ([privacy](https://www.mercadopago.com.mx/privacidad)).

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

== Source code and libraries ==

The admin toasts in `assets/js/toast.js` bundle [Notch Toast](https://github.com/Devsethi3/notch-toast) 0.2.0 and [Motion](https://motion.dev/) 14.7.1, both MIT licensed (see `assets/js/toast.LICENSE.txt`). React comes from WordPress. `assets/js/toast.js` is the readable build and `toast.min.js` its minified copy; the source (`tools/build/toast/entry.js`) and the build script (`tools/build/toast.mjs`) are in the [GitHub repository](https://github.com/wpgaurav/stackless-lite-for-edd).

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

= 2.0.1 =
* Improved: Payment Gateway Tools names Creem among the gateways you can duplicate.

= 2.0.0 =
* New: Creem, Razorpay, Mollie, Square, Paystack, Flutterwave and Mercado Pago gateways. Razorpay, Mollie and Square save payment methods that customers manage on the account page.
* New: Stripe Managed Payments, a second Stripe gateway with Stripe as merchant of record for digital downloads, paid in Stripe Checkout embedded in a page of your store.
* New: PayPal card fields with 3-D Secure, Fastlane for guest buyers in the US, and Apple Pay and Google Pay through PayPal. PayPal's own card form comes back when card fields aren't offered.
* New: Payment Gateway Tools can run several Creem copies, one per Creem store.
* Improved: saving the Stripe settings adds any missing events to an existing Stripe webhook.
* Improved: test-mode failures on providers whose test and live modes share one API host no longer send the failed live payment email.
* Improved: every Stackless box on the download edit screen links to its documentation.
* Fixed: payments through a copy of Polar, Paddle, Dodo Payments or Creem keep their transaction ID on the order.

= 1.4.0 =
* The Stackless dashboard lists Google Product Feed, new in Stackless for EDD: a Google Merchant Center feed of your downloads for free listings and Shopping ads.

= 1.3.0 =
* Short confirmations on Stackless admin screens, such as "Settings saved." and switching a module on or off, show as a toast that drops from the admin bar. Errors and longer messages stay on the page.

= 1.2.1 =
* Checkout: a logged-in buyer's name is filled in from their earlier orders. Their account keeps the name typed at checkout when it had none, so they don't type it again on the next purchase.
* Stackless dashboard: switching on a module that needs another one switches that one on too and says so. "Needs …" notes update right away, without a page reload.

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
