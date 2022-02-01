=== Bitfinex Pay ===
Contributors: bitfinex, viganabd
Tags: bitcoin payments, crypto payments, bitcoin, tether token, bitfinex pay, cryptocurrency pay, pay with bitcoin, ethereum payments, tether payments
Requires at least: 5.0
Tested up to: 5.8
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv3
License URI: https://github.com/bitfinexcom/bfx-pay-woocommerce/blob/main/LICENSE

Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services as long as they have an Intermediate-verified (or higher KYC level) Merchant account on the Bitfinex platform.

== Description ==
Bitfinex Pay allows you to accept payments in various cryptocurrencies seamlessly. It is built with both the merchant and customer in mind, so it is loaded with beneficial features for both parties. The Bitfinex Pay WooCommerce plugin provides you with all Bitfinex Pay’s superpowers with a minimum setup time. Backed with Bitfinex’s top-notch technology and experience as one of the longest-running crypto exchanges, Bitfinex Pay is ready to serve e-commerce platforms, online businesses, and service providers looking to adopt cryptocurrency payments.

**Bringing the global cryptocurrency economy into your business**

Cryptocurrencies are being adopted globally and rapidly. The technology behind cryptocurrency allows transactions without intermediaries, enabling crypto to reach the unbanked and people in remote and marginalised areas. As the world shifts towards a cashless economy, crypto is a viable addition to existing payment systems.

Bitfinex Pay allows you to accept payments in various cryptocurrencies seamlessly. It is built with both the merchant and customer in mind, so it is loaded with beneficial features for both parties. The Bitfinex Pay WooCommerce plugin provides you with all Bitfinex Pay’s superpowers with a minimum setup time. Backed with Bitfinex’s top-notch technology and experience as one of the longest-running crypto exchanges, Bitfinex Pay is ready to serve e-commerce platforms, online businesses, and service providers looking to adopt cryptocurrency payments.

**_Tap into the global crypto economy, start accepting crypto payments in your business!_**

_Bitfinex Pay is available only to Eligible Merchants (as defined in the terms of service available here). In particular, Bitfinex Pay is not available to U.S. merchants, and may not be used by U.S. customers._

**Why Bitfinex Pay?**
- **A wide choice of cryptocurrencies** - Bitfinex Pay offers various cryptocurrencies such as Bitcoin, Ethereum, Tether tokens (USDt), Bitcoin via the Lightning Network and more to follow.
- **No additional processing fees** - Bitfinex Pay services come with no additional processing or hidden fees. Check out Bitfinex Pay terms for incurring transaction fees on the network that merchants should be aware of.
- **Swift process** - Upon successful transactions, you will generally receive the payment in your wallet within minutes.
- **Very intuitive** - Bitfinex Pay’s interface is very intuitive, making it easy to navigate by all kinds of customers. 
- **Backed with Bitfinex’s technology & reputation** - As one of the pioneers in the industry, Bitfinex has an impeccable track record of providing services to our users and the crypto community at large. 


== Installation ==
- First of all you should activate Bitfinex payment method, to do that open WooCommerce > Settings > Payments and click Set up on Bitfinex Payment method.
- Enter your Public and Secret keys from the Bitfinex platform in the proper fields and set all ticks to enable.
- Make sure that the webhook address https://your-domain?wc-api=bitfinex is available to get incoming requests.
Additional information can be found in https://github.com/bitfinexcom/bfx-pay-woocommerce#readme and https://pay.bitfinex.com/

== Frequently Asked Questions ==
= How does Bitfinex Pay work? =
- Your customers click the Bitfinex Pay button on your product page when checking out.
- Upon clicking, they will be directed to the Bitfinex payment gateway page. (This page contains your customers’ order details.)
- The countdown shows the remaining amount of time to complete the payment. 
- Your customers will be redirected back to your website upon successful payment.

Note: Customers making payments do not have to be Bitfinex users. If your customer is a Bitfinex user, they can log in via the Pay with Bitfinex button, and it will be considered an internal transfer within Bitfinex; the transaction will not be broadcast on the blockchain and will therefore also be faster than on-chain transactions.

If your customer does not complete the payment before the countdown period, the invoice will be marked as Expired, and the customer will need to create a new payment request. You can also customise the time for the payment countdown when you configure the authenticated API endpoints.

= How Bitfinex Pay works on your website =
- Customers click the Bitfinex Pay button on your checkout page.
- Upon clicking, they will be directed to the Bitfinex payment gateway page.
- The page contains your customers’ invoices and order details.
- The countdown shows the remaining amount of time to complete the payment.
- Your customers will be redirected back to your website upon successful payment.

= How to set up =
- Sign in or sign up for a Bitfinex account.
- Complete the verification process at least to the Intermediate level.
- Apply for Merchant verification.
- Set up a new sub-account and verify it.
- Create your API Key, learn how to do this here.
- Create an order with authenticated API endpoints, learn how to do it here.

For more information about Bitfinex Pay, visit Bitfinex Knowledge Base.

= What do the different invoice statuses mean? =

An invoice will have different statuses, starting from when the invoice is created until the time the funds are received in your account:
- Created: An invoice has been created;
- Pending: A deposit is pending confirmation to your account (for information on cryptocurrency deposit times, please view Where is my cryptocurrency deposit or withdrawal?);
- Completed: A deposit has been completed, and the payment amount and invoice amount was exactly matched;
- Expired: An invoice payment time has expired, meaning that the funds were not deposited in the required countdown period provided.

= What are the invoice limitation amounts? =

The minimum invoice amount is $0.1 equivalent.

If your customer is a Bitfinex user who authenticates their Bitfinex account via the Pay with Bitfinex button, they can pay the minimum invoice amount equivalent, which will be deducted from their Bitfinex wallet balance.

However, if your customer is a Bitfinex user who makes the payment through the transaction address provided in the encrypted QR code or from the address box, they will be subject to the minimum withdrawal amount of $5 USD equivalent. For more information, please see Minimum Withdrawals.

Note: The maximum invoice amount is $ 1,000 (equivalent).


== Changelog ==
= 1.0.5 =
* Added support for EUR, GBP fiat currencies
* Added support for LTC, SOL, DOGE, MATIC, MATICM, AVAX crypto currencies

= 1.0.4 =
* Added support for LBT and UST-LBT

= 1.0.3 =
* Added state to customer info

= 1.0.2 =
* fixed issue with showing pay button when plugin is disabled

= 1.0.1 =
* renamed plugin to "bitfinex pay"
* adjusted function names with bfx_pay prefix to avoid conflicts
* removed unnecessary require on webhook call

= 1.0.0 =
* initial implementation
