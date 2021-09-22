<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Bitfinex Pay Woocommerce Plugin
 * Plugin URI:        https://github.com/bitfinexcom/bfx-pay-woocommerce/
 * Description:       Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services as long as they have an Intermediate-verified (or higher KYC level) Merchant account on the Bitfinex platform.
 * Version:           1.0.0
 * Author:            Bitfinex
 * Author URI:        https://www.bitfinex.com/
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       bfx-pay-woocommerce
 * Domain Path:       /languages
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_action('plugins_loaded', 'bfx_pay_woocommerce_init', 11);
add_action('woocommerce_after_add_to_cart_form', 'buy_checkout_on_archive');
add_action('template_redirect', 'addtocart_on_archives_redirect_checkout');
add_action('phpmailer_init', 'mailer_config', 10, 1);
add_action('wp_mail_failed', 'log_mailer_errors', 10, 1);

add_filter('woocommerce_payment_gateways', 'add_to_woo_bfx_payment_gateway');
add_filter('plugin_row_meta', 'bfx_pay_plugin_row_meta', 10, 3);

function bfx_pay_woocommerce_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        require_once __DIR__.'/vendor/autoload.php';
        require_once __DIR__.'/includes/class-wc-bfx-pay-gateway.php';
    }
}

function add_to_woo_bfx_payment_gateway($gateways)
{
    $gateways[] = 'WC_Bfx_Pay_Gateway';

    return $gateways;
}

function bfx_pay_plugin_row_meta($links, $file)
{
    if (strpos($file, basename(__FILE__))) {
        $links[] = '<a href="https://pay.bitfinex.com/" target="_blank">Docs</a>';
        $links[] = '<a href="https://docs.bitfinex.com/reference#merchants" target="_blank">API docs</a>';
    }

    return $links;
}

function buy_checkout_on_archive()
{
    if (class_exists('WC_Bfx_Pay_Gateway')) {
        global $product;
        $instance = new WC_Bfx_Pay_Gateway();
        $icon = $instance->get_icon_uri();
        $checkButton = ('yes' === $instance->checkReqButton) ? true : false;

        if ($checkButton) {
            if ($product->is_type('simple')) {
                $productId = $product->get_id();
                $buttonUrl = '?addtocart='.$productId;
                $buttonClass = 'button loop-checkout-btn';

                echo '<a href="'.$buttonUrl.'" class="'.$buttonClass.'"><img src="'.$icon.'" alt="" width="110" style="margin-bottom: 0;"></a>';
            }
        }
    }
}

function addtocart_on_archives_redirect_checkout()
{
    if (isset($_GET['addtocart']) && $_GET['addtocart'] > 0) {
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart(intval($_GET['addtocart']));
        WC()->session->set('chosen_payment_method', 'bfx_payment');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
