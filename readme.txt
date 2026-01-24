=== Payment Gateway for maib MIA for WooCommerce ===
Contributors: alexminza
Tags: Moldova, maib, MIA, QR, payment gateway
Requires at least: 4.8
Tested up to: 6.9
Stable tag: 1.1.1
Requires PHP: 7.2.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept MIA Instant Payments directly on your store with the maib MIA payment gateway for WooCommerce.

== Description ==

Accept MIA Instant Payments directly on your store with the Payment Gateway for maib MIA for WooCommerce.

= Features =

* Online payments with [MIA Instant Payments](https://mia.bnm.md/en)
* Reverse transactions – partial or complete refunds
* Admin order actions – check order payment status
* Supports WooCommerce [block-based checkout experience](https://woocommerce.com/checkout-blocks/)
* Free to use – [Open-source GPL-3.0 license on GitHub](https://github.com/alexminza/payment-gateway-wc-maib-mia)

= Getting Started =

* [Installation Instructions](./installation/)
* [Frequently Asked Questions](./faq/)

== Installation ==

1. Configure the plugin Connection Settings by entering the connection credentials received from the bank
2. Provide the *Callback URL* to the bank to enable online payment notifications
3. Enable *Test* and *Debug* modes in the plugin settings
4. Perform the following test cases and provide the requested details to the bank:
    * **Test case #1**: Create a new order and pay
    * **Test case #2**: Create a new order and pay, afterwards perform a full order refund
5. Disable *Test* and *Debug* modes when ready to accept live payments

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the *WooCommerce > Settings > Payments > maib MIA* screen to configure the plugin.

= Where can I get the Connection Settings? =

The connection settings are provided by maib. This data is used by the plugin to connect to the maib MIA payment gateway and process the transactions. Please see [https://www.maib.md/en/plati-prin-qr-mia](https://www.maib.md/en/plati-prin-qr-mia) for details.

= What store settings are supported? =

maib MIA currently supports transactions in MDL (Moldovan Leu).

= How can I contribute to the plugin? =

If you're a developer and you have some ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [Github repository for the plugin](https://github.com/alexminza/payment-gateway-wc-maib-mia).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/payment-gateway-wc-maib-mia) to get started.

== Screenshots ==

1. Plugin settings
2. Connection settings
3. Refunds
4. Order actions

== Changelog ==

See [payment-gateway-wc-maib-mia project releases on GitHub](https://github.com/alexminza/payment-gateway-wc-maib-mia/releases) for details.

= 1.1.1 =
Code reorganization and refactoring for better maintainability.

= 1.1.0 =
Added partial refunds.

= 1.0.3 =
Added manual check payment status order action.

= 1.0.2 =
Improved QR code generation logic and settings validation.

= 1.0.1 =
Included vendor packages.

= 1.0.0 =
Initial version release.

== Upgrade Notice ==

= 1.1.1 =
Code reorganization and refactoring for better maintainability.

= 1.1.0 =
Added partial refunds.

= 1.0.3 =
Added manual check payment status order action.

= 1.0.2 =
Improved QR code generation logic and settings validation.

= 1.0.1 =
Included vendor packages.

= 1.0.0 =
Initial version release.
