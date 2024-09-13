<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Bitfinex Pay
 * Plugin URI:        https://github.com/bitfinexcom/bfx-pay-woocommerce/
 * Description:       Allows e-commerce customers to pay for goods and services with crypto currencies. It provides a payment gateway that could be used by any e-commerce to sell their products and services as long as they have an Intermediate-verified (or higher KYC level) Merchant account on the Bitfinex platform.
 * Version:           3.2.0
 * Author:            Bitfinex
 * Author URI:        https://www.bitfinex.com/
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       bitfinex-pay
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_action('plugins_loaded', 'bfx_pay_woocommerce_init', 11);
add_action('woocommerce_after_add_to_cart_form', 'bfx_pay_buy_checkout_on_archive');
add_action('template_redirect', 'bfx_pay_addtocart_on_archives_redirect_checkout');

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bfx_pay_settings_link', 10);
add_filter('woocommerce_payment_gateways', 'bfx_pay_add_bfx_payment_gateway_woo');
add_filter('plugin_row_meta', 'bfx_pay_plugin_row_meta', 10, 3);

add_filter('pre_option_woocommerce_currency_pos', 'currency_position');

// Start block checkout
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');
add_action( 'woocommerce_blocks_loaded', 'register_order_approval_payment_method_type' );
// End block checkout

// Cron
add_filter('cron_schedules', 'bfx_pay_cron_add_fifteen_min');
add_action( 'bfx_pay_cron_hook', 'bfx_pay_cron_exec' );
add_action('wp', 'bfx_pay_add_cron');

// Start Hook for block checkout
/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
*/
function declare_cart_checkout_blocks_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

/**
 * Custom function to register a payment method type
 */
function register_order_approval_payment_method_type() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    require_once __DIR__.'/includes/class-wc-block-bfx-pay-gateway.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Block_Bfx_Pay_Gateway );
        }
    );
    wp_enqueue_style(
        'WC_bfx_pay_block_checkout',
        plugin_dir_url(__FILE__) . '/includes/checkout.css',
        array(),
    );
}
// End Hook for block checkout

function bfx_pay_cron_add_fifteen_min($schedules)
{
    $schedules['bfx_pay_fifteen_min'] = [
        'interval' => 60 * 15,
        'display' => 'Every 15 minute',
    ];

    return $schedules;
}

function bfx_pay_cron_exec() {
    if (class_exists('WC_Bfx_Pay_Gateway')) {
        $instance = new WC_Bfx_Pay_Gateway();
        $instance->cron_invoice_check();
    }
}

function bfx_pay_add_cron() {
    if ( ! wp_next_scheduled( 'bfx_pay_cron_hook' ) ) {
        wp_schedule_event( time(), 'bfx_pay_fifteen_min', 'bfx_pay_cron_hook' );
    }
}


function currency_position()
{
    return 'right_space';
}

function bfx_pay_settings_link($links): array
{
    $custom['settings'] = sprintf(
        '<a href="%s" aria-label="%s">%s</a>',
        esc_url(
            add_query_arg(
                array(
                    'page' => 'wc-settings&tab=checkout&section=bfx_payment',
                ),
                admin_url('admin.php')
            )
        ),
        esc_attr__('Go to BFX Settings page', 'bfx-pay-woocommerce'),
        esc_html__('Settings', 'bfx-pay-woocommerce')
    );

    return array_merge($custom, (array)$links);
}

function bfx_pay_woocommerce_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        require_once __DIR__.'/vendor/autoload.php';
        require_once __DIR__.'/includes/class-wc-bfx-pay-gateway.php';
    }
}

function bfx_pay_add_bfx_payment_gateway_woo($gateways)
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

function bfx_pay_buy_checkout_on_archive()
{
    if (class_exists('WC_Bfx_Pay_Gateway')) {
        global $product;
        $instance = new WC_Bfx_Pay_Gateway();
        $icon = $instance->get_icon_uri();
        $checkButton = ('yes' === $instance->isEnabled && 'yes' === $instance->checkReqButton) ? true : false;

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

function bfx_pay_addtocart_on_archives_redirect_checkout()
{
    if (isset($_GET['addtocart']) && $_GET['addtocart'] > 0) {
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart(intval($_GET['addtocart']));
        WC()->session->set('chosen_payment_method', 'bfx_payment');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
