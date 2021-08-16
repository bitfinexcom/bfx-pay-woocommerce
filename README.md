# bfx-pay-woocommerce
Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services as long as they have an Intermediate-verified (or higher KYC level) Merchant account on the Bitfinex platform.

## How to install
*Make sure the WooCommerce plugin is installed before starting installation.*

1. Run `composer install`.
2. Create a zip archive from the plugin folder.
3. Go to the WordPress admin panel then navigate to the `Plugins` > `Add New` from the left sidebar.
4. Click to the `Upload Plugin` button at the top of the page.
5. Select the zip file which was created on step 1 and click the `Install Now` button. Don't forget to activate the plugin by the `Activate Plugin` button at the bottom of the page.
6. Install plugin WP Mail SMTP or other and configure mail settings.

## Configuration
1. First of all you should activate Bitfinex payment method, to do that open `WooCommerce` > `Settings` > `Payments` and set the `Bitfinex Payment` toggle to "On".
2. Enter your Public and Secret keys from the Bitfinex platform in the proper fields and set all ticks to enable.
3. Make sure that the webhook address `https://your-domain?wc-api=bitfinex` is available to get incoming requests.