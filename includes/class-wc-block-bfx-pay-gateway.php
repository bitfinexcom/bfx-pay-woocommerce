<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Block_Bfx_Pay_Gateway extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'bfx_payment';// your payment gateway name
    public function initialize() {
        $this->settings = get_option( 'woocommerce_wc_block_bfx_pay_gateway_settings', [] );
        $this->gateway = new WC_Bfx_Pay_Gateway();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc_block_bfx_pay_gateway-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc_block_bfx_pay_gateway-integration');
        }
        return [ 'wc_block_bfx_pay_gateway-integration' ];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => $this->gateway->get_icon_uri(),
        ];
    }
}
?>
