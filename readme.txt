=== Central do Frete ===
Contributors: centraldofrete
Tags: shipping, freight, carriers, brazil, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer freight in the cart with carriers rated on real performance, and Central do Frete following the shipment through.

== Description ==

Central do Frete sits between your store and the carriers. We select carriers on real performance - delivery time, damage rate and support - not on who pays to be listed, and we stay on the shipment after it leaves: the carrier moves your cargo, we are the ones who follow it and chase the carrier when something goes wrong.

This plugin brings that into the WooCommerce checkout. The shopper sees prices and delivery estimates from the carriers your account already has, in a single request, and you do not set up carrier by carrier.

**A Central do Frete account is required.** The plugin does not calculate freight on its own. It queries the service using your account access token.

= Features =

* Offer freight in the cart and at checkout, from carriers rated on real performance
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

* The destination postcode entered by the shopper, and the store postcode as the origin. A store with no usable postcode of its own - missing, or not 8 numbers - sends no origin, and the quote then leaves from the pickup address registered in your Central do Frete account
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

= What does Central do Frete do that a carrier plugin does not? =

We choose which carriers you get to compare, and we keep following the shipment. Carriers are selected on measured performance and reviewed every quarter on delivery time, damage rate and support. When a shipment goes wrong, chasing the carrier is our job, not yours.

= Does the plugin charge anything? =

The plugin is free. You pay for the freight you contract through Central do Frete, according to the terms of your account.

= How do I stop Central do Frete from quoting some products? =

Use shipping classes. Create the class under **WooCommerce › Settings › Shipping › Shipping classes**, assign your products in the Shipping tab, and in the method settings choose between serving only the selected classes or every class except them. If a cart ends up with no shipping method available the shopper cannot complete the order, so keep another method in the same zone for the products you leave out.

= Is my customer's CPF or CNPJ sent to the service? =

Only when those checkout fields exist and are filled in. If you would rather not send them, remove or leave the `billing_cpf` and `billing_cnpj` fields empty, or filter the value in your theme. Quoting works without them.

= Can I decide in code when Central do Frete shows up? =

Yes. The `woocommerce_shipping_centraldofrete_is_available` filter receives the plugin decision and has the final say.

= Quotes are wrong or do not show up =

Turn on **Modo debug** in the method settings and check the logs under **WooCommerce › Status › Logs**, in the `central-do-frete-*` file. The most common causes are products without weight and dimensions, an invalid token, and an origin that is not where the freight actually leaves from - the method settings screen states which postcode the quotes are using.

== Screenshots ==

1. Connect the store to your Central do Frete account with the access token, and choose how the rates are displayed at checkout.
2. Shipping class restriction: pick which classes Central do Frete serves, and how a mixed cart behaves.
3. Carrier options at the cart, with logo, delivery estimate and price.
4. Shipping calculator on the product page, so the shopper checks the freight before adding the item to the cart.

== Changelog ==

= 3.3.0 =
* **The product page calculator setting changed meaning: it now decides whether that zone answers, not only whether the field is drawn.** A shopper whose postcode fell into a zone with the calculator turned off still got prices from that zone, because the switch was read once for the whole store. Each zone's switch now governs its own answers, and a shopper in a region you turned it off for is told the calculation is not available there. Turned on in every zone, which is the default, nothing changes
* A store postcode that is filled in but is not 8 numbers no longer breaks every quote. It is treated as no postcode at all, so the quote leaves from the pickup address registered in your account, and the method settings screen says the store postcode is invalid - a separate warning from the one for a store address that was never filled in, because the fix is different
* Stores that use Central do Frete in more than one shipping zone with different tokens now see the right pickup postcode on each zone's settings screen. The plugin kept only the last one resolved, so two zones overwrote each other and the screen named whichever account had quoted most recently
* A shipping zone you added Central do Frete to but never pasted a token into now says so on its settings screen, and names the zone. It looked finished - WooCommerce enables the method as soon as you add it, and the cargo type list is shared by the whole store - while quoting nothing
* The **Ativar método de entrega** checkbox now governs the product page calculator as well. A shipping zone you switched the method off in kept quoting on the product page while the cart and the checkout offered that region nothing; shoppers there now read the same thing as when the calculator itself is switched off, which is what you chose in both cases
* A store that uses Central do Frete in more than one shipping zone and defines any of them by state no longer gets prices from the wrong zone on the product page. The calculator knows the postcode and not the state, and a zone defined by state cannot be identified from a postcode, so the quote used to fall through to whatever broader zone came next and answer with its token, its handling fee and its display rules - a different price from the one the cart showed for the same basket. The calculator now says the delivery area could not be identified and shows no price. The cart and the checkout, which know the state, are unaffected
* A product whose shipping class one zone does not carry no longer reads as if the whole store refused to quote it. The message named no region and arrived as an error, so a shopper in a zone that excludes that class was told the product is not quoted by Central do Frete at all - and left, while every other zone was quoting it. It now says the product is not quoted in the region of that postcode, as a note, and points to the store
* The product page calculator now tells a shopper four different things instead of one when no price is coming, each true of their own case: the calculation is not available for that region, when you switched the method or the calculator off in the zone that covers it; that we could not calculate the freight for that postcode and to contact the store, when the zone that covers it has no token or was added and never saved; that the delivery area could not be identified, when no zone matched it; and that the product is not quoted in that region, when the zone that covers it does not carry the product's shipping class. None of the four is shown as an error, and none of them tells a shopper you do not deliver to their postcode
* Shoppers no longer read "Não atendemos este CEP" for a postcode you do deliver to. A zone added and never saved was reaching them with that sentence, and so was a store that defines its zones by state. This changes what the 3.2.0 upgrade notice told you about zones defined by state: the calculator now says it could not identify the delivery area, not that the postcode is not served. The cart and the checkout are unaffected, as they were then

= 3.2.0 =
* A store with no postcode of its own now gets quotes: the request goes out with no origin and Central do Frete uses the pickup address registered in your account. Until now the plugin gave up and offered no freight at all
* The method settings screen states which postcode the quotes leave from, and says so as a warning when that is the account's pickup address instead of the store address, because a different origin changes both the price and the list of carriers
* Stores that use Central do Frete in more than one shipping zone no longer read another zone's settings: the product page calculator answers with the zone the shopper's postcode falls into, and applies that zone's shipping class restriction
* Debug mode now writes logs whenever any shipping zone has it turned on, instead of depending on which zone the database returned first
* Cached quotes are now separated per Central do Frete account, so two shipping zones with different tokens cannot read each other's prices. Every cached quote is discarded once on update
* `Cdfrete_Shipping_Method::get_settings()` was removed. It answered with an arbitrary shipping zone; use `get_settings_for_destination()`, `get_instance_settings()` or `get_all_settings()`

= 3.1.1 =
* The cargo type button on the settings screen now loads its script through the WordPress script queue instead of printing it inline
* Every class, constant, hook, handle and style name carries the longer cdfrete prefix, so the plugin cannot collide with another one
* The per-product cargo type moved to a prefixed meta key; the previous key is still read, so saved products keep their setting
* The access token and the shopper's name and tax id are no longer written to the WooCommerce log
* Carrier names containing "&" are no longer shown escaped twice in the product page calculator
* The product page calculator's own messages are now translatable
* A quote is no longer returned for a product the shop has not published
* Cart and checkout quotes now share the versioned cache key, so a cache format change clears them too
* The postcode field has a proper label, results are announced to screen readers, and the decorative icon is hidden from them
* Settings, shipping zones, saved cargo types and the data recorded on existing orders are untouched by this update

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

= 3.3.0 =
The product page calculator switch now decides whether a shipping zone answers, not only whether the field is drawn. If you use Central do Frete in more than one zone and turned the calculator off in one of them, shoppers with a postcode in that zone now read that the calculation is not available there, instead of getting that zone's prices. The **Ativar método de entrega** checkbox now governs the product page calculator too, so a zone you switched the method off in stops quoting there as well. With both on everywhere, which is the default, nothing changes. Read this if you define any shipping zone by state and use Central do Frete in more than one zone: the product page calculator now shows no price for those postcodes and says the delivery area could not be identified, because it knows the postcode and not the state, and it used to answer with another zone's token, fee and rules; the cart and the checkout are unaffected. A store postcode that is filled in but is not 8 numbers now quotes from your account's pickup address instead of failing, and the settings screen says the postcode is invalid. This also corrects what the 3.2.0 notice below says about zones defined by state: the calculator no longer answers that it does not serve the postcode, it says the delivery area could not be identified.

= 3.2.0 =
If your store has no postcode set, Central do Frete now quotes from the pickup address registered in your account instead of offering no freight. Open the method settings: the screen states which postcode is in use. Cached quotes are cleared once. On a store that uses the method in more than one shipping zone and defines those zones by state, the product page calculator may answer that it does not serve a postcode; the cart and the checkout are unaffected.

= 3.1.1 =
Safe to update: your settings, shipping zones, product cargo types and existing orders are unchanged. Only if you wrote custom CSS for the product page calculator: its class names changed from cdf- to cdfrete- (for example .cdf-rates-table is now .cdfrete-rates-table).

= 3.1.0 =
Lets you choose which shipping classes Central do Frete serves. Nothing changes for existing setups: by default the method keeps quoting every class.
