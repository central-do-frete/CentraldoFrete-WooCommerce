=== Central do Frete ===
Contributors: centraldofrete
Tags: shipping, freight, carriers, brazil, delivery
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time freight quotes from multiple Brazilian carriers in your WooCommerce store, using your Central do Frete account.

== Description ==

Central do Frete is a Brazilian freight broker that connects merchants and carriers. This plugin brings the quotes from your account into the WooCommerce checkout: the shopper sees real prices and delivery estimates from several carriers in a single request, and you do not have to set up each carrier separately.

**A Central do Frete account is required.** The plugin does not calculate freight on its own. It queries the service using your account access token.

= Features =

* Real-time quotes from multiple carriers in the cart and at checkout
* Shipping calculator on the product page, before the item is added to the cart
* Cargo type per product, or a single default for the whole store
* Shipping class restriction, so you choose which classes Central do Frete serves
* Display control: all carriers, the three cheapest, or only the cheapest and the fastest
* Extra handling days and a handling fee added on top of the quoted price
* Default dimensions for products with no weight or measurements
* Quote caching to reduce calls to the service
* Compatible with HPOS, the classic checkout and the block checkout

= External service =

This plugin depends on the Central do Frete service and sends data to the API at `https://api.centraldofrete.com` every time a quote is calculated. The plugin does not work without it.

Data sent on each quote:

* Origin postcode (the store postcode) and the destination postcode entered by the shopper
* Weight, height, width, length and quantity of the cart volumes
* Total value of the products, used as the invoice amount
* Cargo type configured on the products
* Recipient name and CPF or CNPJ (Brazilian tax IDs), **only when** those fields exist in the store checkout (for example through Brazilian extra checkout field plugins) and are filled in. They are used for more accurate quotes and can be avoided, see the FAQ.

Your account access token is sent with every request, for authentication.

Terms of use: https://centraldofrete.com/termos
Privacy policy: https://centraldofrete.com/privacidade

By installing and configuring the plugin you agree to send this data to Central do Frete. No quote is requested before the token is configured and the shipping method is added to a shipping zone.

== Installation ==

1. Install and activate the plugin under **Plugins › Add New**
2. Go to **WooCommerce › Settings › Shipping** and open (or create) a shipping zone for Brazil
3. Click **Add shipping method** and choose **Central do Frete**
4. Edit the method and paste your **access token**, available at app.centraldofrete.com under Integrações › API
5. Save, then click **Atualizar Tipos de Carga** to load the cargo types available in your account
6. Make sure the store postcode is set under **WooCommerce › Settings › General**, and that your products have weight and dimensions

== Frequently Asked Questions ==

= Do I need a Central do Frete account? =

Yes. The plugin is the interface to your account inside WooCommerce. Without the access token it cannot quote anything.

= Does the plugin charge anything? =

The plugin is free. You pay for the freight you contract through Central do Frete, according to the terms of your account.

= How do I stop Central do Frete from quoting some products? =

Use shipping classes. Create the class under **WooCommerce › Settings › Shipping › Shipping classes**, assign your products in the Shipping tab, and in the method settings choose between serving only the selected classes or every class except them. If a cart ends up with no shipping method available the shopper cannot complete the order, so keep another method in the same zone for the products you leave out.

= Is my customer's CPF or CNPJ sent to the service? =

Only when those checkout fields exist and are filled in. If you would rather not send them, remove or leave the `billing_cpf` and `billing_cnpj` fields empty, or filter the value in your theme. Quoting works without them.

= Can I decide in code when Central do Frete shows up? =

Yes. The `woocommerce_shipping_centraldofrete_is_available` filter receives the plugin decision and has the final say.

= Quotes are wrong or do not show up =

Turn on **Modo debug** in the method settings and check the logs under **WooCommerce › Status › Logs**, in the `central-do-frete-*` file. The most common causes are a missing store postcode, products without weight and dimensions, and an invalid token.

== Screenshots ==

1. Connect the store to your Central do Frete account with the access token, and choose how the rates are displayed at checkout.
2. Shipping class restriction: pick which classes Central do Frete serves, and how a mixed cart behaves.
3. Carrier options at the cart, with logo, delivery estimate and price.
4. Shipping calculator on the product page, so the shopper checks the freight before adding the item to the cart.

== Changelog ==

= 3.1.0 =
* Shipping class restriction, configured per shipping zone
* The product page calculator follows the same restriction
* Saving the settings now invalidates the WooCommerce shipping rate cache
* Automated tests for the shipping class rule

= 3.0.0 =
* Full rewrite of the plugin
* Quote caching with transients
* Shipping calculator on the product page
* Carrier logos in the rate label
* HPOS compatibility
* Reorganized settings screen
* Structured logs

= 2.0.x =
* Previous version

== Upgrade Notice ==

= 3.1.0 =
Lets you choose which shipping classes Central do Frete serves. Nothing changes for existing setups: by default the method keeps quoting every class.
