<?php
/**
 * Class WC_Bfx_Pay_Gateway file.
 */
/**
 * Bitfinex Gateway.
 *
 * Provides a Bitfinex Payment Gateway.
 *
 * @class       WC_Bfx_Pay_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 */

class WC_Bfx_Pay_Gateway extends WC_Payment_Gateway {

    /**
     * API Context used for Bitfinex Merchant Authorization
     * @var null
     */
    public $baseApiUrl = 'https://api.bitfinex.com/';
    public $api_key;
    public $api_secret;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        global $woocommerce;
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->button_type = $this->get_option('button_type');
        $this->baseApiUrl = $this->get_option('base_api_url') ?? $this->baseApiUrl . '/v2/';
        $this->api_key = $this->get_option('api_key');
        $this->api_secret = $this->get_option('api_secret') ?? false;
        $this->check_req_button = $this->get_option('button_req_checkout');
        $this->pay_currencies = $this->get_option('pay_currencies');
        $this->duration = $this->get_option('duration') ?? 86399;

        // Checking which button theme selected and outputing relevated.
        $this->icon = ('Light' === $this->button_type) ? apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-white.svg', __FILE__)) : apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-dark.svg', __FILE__));
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        add_filter('woocommerce_payment_complete_order_status', [$this, 'change_payment_complete_order_status'], 10, 3);
        // Customer Emails.
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
        add_action('woocommerce_api_bitfinex', [$this, 'webhook']);
        // Cron
        add_filter('cron_schedules', 'cron_add_five_min');
        function cron_add_five_min($schedules)
        {
            $schedules['five_min'] = [
                'interval' => 60 * 5,
                'display' => 'Bitfinex cron',
            ];

            return $schedules;
        }

        add_action('wp', 'bitfinex_cron_activation');
        function bitfinex_cron_activation()
        {
            if (!wp_next_scheduled('bitfinex_five_min_event')) {
                wp_schedule_event(time(), 'five_min', 'bitfinex_five_min_event');
            }
        }

        add_action('bitfinex_five_min_event', 'cron_invoice_check');
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties() {
        $this->id = 'bfx_payment';
        $this->icon = apply_filters( 'woocommerce_bfx_icon', plugins_url( '../assets/img/bfx-pay-white.svg' , __FILE__) );
        $this->method_title = __( 'Bitfinex Payment', 'bfx-pay-woocommerce' );
        $this->method_description = __( 'Bitfinex Payment', 'bfx-pay-woocommerce' );
        $this->has_fields = true;
        $this->debug = ( 'yes' === $this->get_option( 'debug' ) );
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'bfx-pay-woocommerce'),
                'label' => __('Enable', 'bfx-pay-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'bfx-pay-woocommerce'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __('This controls the title which the user sees during checkout.', 'bfx-pay-woocommerce'),
            ],
            'description' => [
                'title' => __('Description', 'bfx-pay-woocommerce'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __('This controls the description which the user sees during checkout.', 'bfx-pay-woocommerce'),
            ],
            'instructions' => [
                'title' => __('Instructions', 'bfx-pay-woocommerce'),
                'type' => 'textarea',
                'default' => __('', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'description' => __('Instructions that will be added to the thank you page and emails.', 'bfx-pay-woocommerce'),
            ],
            'base_api_url' => [
                'title' => __('Api url', 'bfx-pay-woocommerce'),
                'type' => 'text',
                'default' => '',
                'description' => __('By default it is "https://api.bitfinex.com/v2/"', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
            ],
            'redirect_url' => [
                'title' => __('Redirect url', 'bfx-pay-woocommerce'),
                'type' => 'text',
                'default' => 'https://pay.bitfinex.com/gateway/order/',
                'description' => __('By default it is "https://pay.bitfinex.com/gateway/order/"', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
            ],
            'api_key' => [
                'title' => __('Api key', 'bfx-pay-woocommerce'),
                'type' => 'text',
                'default' => '',
                'description' => __('Enter your bitfinex Api key', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
            ],
            'api_secret' => [
                'title' => __('Api secret', 'bfx-pay-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('Enter your bitfinex Api secret', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
            ],
            'button_type' => [
                'title' => __('Button theme', 'bfx-pay-woocommerce'),
                'description' => __('Type of button image to display on cart and checkout pages', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'select',
                'default' => 'Light',
                'options' => [
                    'Light' => __('Light theme Bitfinex button', 'bfx-pay-woocommerce'),
                    'Dark' => __('Dark theme Bitfinex button', 'bfx-pay-woocommerce'),
                ],
            ],
            'button_req_checkout' => [
                'title' => __('Enable/Disable one click checkout button for products', 'bfx-pay-woocommerce'),
                'label' => __('Enable', 'bfx-pay-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'debug' => [
                'title' => __('Debug', 'bfx-pay-woocommerce'),
                'label' => __('Enable debugging messages', 'bfx-pay-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Sends debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-amazon-payments-advanced'),
                'desc_tip' => true,
                'default' => 'yes',
            ],
            'pay_currencies' => [
                'title' => __('Pay currencies', 'bfx-pay-woocommerce'),
                'description' => __('Select pay currencies that you preffer. It may be several with ctrl/cmd button.', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'multiselect',
                'default' => 'BTC',
                'options' => [
                    'BTC' => 'BTC',
                    'ETH' => 'ETH',
                    'UST-ETH' => 'UST-ETH',
                ],
            ],
            'currency' => [
                'title' => __('Currency', 'bfx-pay-woocommerce'),
                'description' => __('Select currency.', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'multiselect',
                'default' => 'USD',
                'options' => [
                    'USD' => 'USD',
                ],
            ],
            'duration' => [
                'title' => __('Duration', 'bfx-pay-woocommerce'),
                'type' => 'number',
                'default' => '86399',
                'desc_tip' => true,
                'description' => __('This controls the duration.', 'bfx-pay-woocommerce'),
            ],
        ];
    }

    /**
     * Getting Gateway icon uri.
     */
    public function get_icon_uri() {
        $selected_icon = ('Light' === $this->button_type) ? plugin_dir_url(__FILE__)
            .'../assets/img/bfx-pay-white.svg' : plugin_dir_url(__FILE__)
            .'../assets/img/bfx-pay-dark.svg';

        return $selected_icon;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function payment_fields() {
        global $woocommerce;
        $order = new WC_Order($order_id);
        echo wpautop(wp_kses_post($this->description));
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function process_payment( $order_id ) {
        global $woocommerce;
        $order = new WC_Order($order_id);
        $baseUrl = $this->baseApiUrl;

        $client = new GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 3.0,
        ]);

        $res = $client->request('GET', 'https://api-pub.bitfinex.com/v2/platform/status');
        if ($res->getBody()!=1){
            throw new Exception(sprintf('This payment method is currently unavailable. Try again later or choose another one'));
        }
        $apiKey = $this->api_key;
        $apiSecret = $this->api_secret;
        $url = $this->get_return_url($order);
        $hook = get_site_url().'?wc-api=bitfinex';
        $totalSum = $order->get_total();
        $pay_currencies = $this->get_option('pay_currencies');
        $currency = $this->get_option('currency');
        $duration = $this->get_option('duration');
        $apiPath = 'v2/auth/w/ext/pay/invoice/create';
        $nonce = (string) (time() * 1000 * 1000); // epoch in ms * 1000
        $body = [
            'amount' => $totalSum,
            'currency' => $currency[0],
            'payCurrencies' => $pay_currencies,
            'orderId' => "$order_id",
            'duration' => intval($duration),
            'webhook' => $hook,
            'redirectUrl' => $url,
            'customerInfo' => [
                'nationality' => $order->get_billing_country(),
                'residCountry' => $order->get_billing_country(),
                'residCity' => $order->get_billing_city(),
                'residZipCode' => $order->get_billing_postcode(),
                'residStreet' => $order->get_billing_address_1(),
                'fullName' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
            ],
        ];

        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = "/api/{$apiPath}{$nonce}{$bodyJson}";

        $sig = hash_hmac('sha384', $signature, $apiSecret);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'bfx-nonce' => $nonce,
            'bfx-apikey' => $apiKey,
            'bfx-signature' => $sig,
        ];

        try {
            $r = $client->post($apiPath, [
                'headers' => $headers,
                'body' => $bodyJson,
            ]);
            $response = $r->getBody()->getContents();
            if ($this->debug) {
                wc_add_notice($response, 'notice');
            }
        } catch (\Throwable $ex) {
            wc_add_notice($ex->getMessage(), 'error');
            $order->update_status('failed', $ex->getMessage());

            return [
                'result' => 'failure',
                'messages' => 'failed',
            ];
        }

        $data = json_decode($response);

        // Mark as on-hold (we're awaiting the bitfinex payment)
        $order->update_status('on-hold', __('Awaiting bitfinex payment', 'woocommerce'));

        // Remove cart
        $woocommerce->cart->empty_cart();

        // Return thankyou redirect
        return [
            'result' => 'success',
            'redirect' => $this->get_option('redirect_url') . $data->id,
        ];
    }

    /**
     * cron_invoice_check
     */
    public function cron_invoice_check() {
        $baseUrl = $this->baseApiUrl;
        $client = new GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 3.0,
        ]);
        $apiPathin = 'v2/auth/r/ext/pay/invoices';
        $apiKey = $this->api_key;
        $apiSecret = $this->api_secret;
        $nonce = (string) (time() * 1000 * 1000); // epoch in ms * 1000
        $bodyin = [
            'start' => round(microtime(true) * 1000) - 5 * 60000,
            'end' => round(microtime(true) * 1000),
            'limit' => 100,
        ];
        $bodyJsonin = json_encode($bodyin, JSON_UNESCAPED_SLASHES);
        $signaturein = "/api/{$apiPathin}{$nonce}{$bodyJsonin}";
        $sigin = hash_hmac('sha384', $signaturein, $apiSecret);
        $headersin = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'bfx-nonce' => $nonce,
            'bfx-apikey' => $apiKey,
            'bfx-signature' => $sigin,
        ];
        try {
            $rin = $client->post($apiPathin, [
                'headers' => $headersin,
                'body' => $bodyJsonin,
            ]);
            $responsein = $rin->getBody()->getContents();
            if ($this->debug) {
                wc_add_notice($responsein, 'notice');
            }
            $datain = json_decode($responsein);
            foreach ($datain as $invoice) {
                $order = wc_get_order($invoice->orderId);
                if ($order === false) {
                    continue;
                }
                if ($order->get_status() === 'on-hold') {
                    if ($invoice->status === 'COMPLETED') {
                        $order->payment_complete();
                    } else {
                        $order->update_status('failed');
                    }
                }
            }
        } catch (\Throwable $exin) {
            print_r($exin->getMessage());
        }
    }

    /**
     * Webhook
     */
    public function webhook() {
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        if ('COMPLETED' !== $data['status']) {
            $order->update_status('failed');
            return;
        }
        ob_start();
        $order = wc_get_order($data['orderId']);
        $order->payment_complete();

        $to = $order->get_billing_email();
        $subject = 'Payment BFX';
        define('WP_USE_THEMES', false);
        require 'wp-load.php';

        $headers = 'MIME-Version: 1.0'."\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8 \r\n";
        $invoice = $data['invoices'][0];
        $matches = [];
        preg_match('/"invoices":.*?]/', $payload, $matches);
        $rawInvoices = $matches[0];
        preg_match('/"amount":(.*?),"/', $rawInvoices, $matches);
        $amount = $matches[1];

        $name = $order->get_billing_first_name();
        $orderId = $order->get_id();
        $date = $order->get_date_paid();
        $payment = $order->get_payment_method();
        $currency = $order->get_currency();
        $subtotal = $order->get_order_item_totals();
        $total = $order->get_order_item_totals();
        $address = $order->get_formatted_billing_address();
        $product = $order->get_items();
        foreach ($product as $item) {
            $productName = $item['name'];
            $count = $item['quantity'];
        }

        $file = plugin_dir_path(__FILE__).'../assets/img/bfx-pay-white.png';
        $uid = 'bfx-pay-white';
        $name = 'bfx-pay-white.png';

        global $phpmailer;
        add_action('phpmailer_init', function (&$phpmailer) use ($file, $uid, $name) {
            $phpmailer->SMTPKeepAlive = true;
            $phpmailer->AddEmbeddedImage($file, $uid, $name);
        });

        wp_mail($to, $subject, self::htmlEmailTemplate($name, $orderId, $date, $payment, $currency, $subtotal, $total, $address, $count, $productName, $invoice, $amount), $headers);

        update_option('webhook_debug', $_POST);
        ob_clean();
        status_header(200);
        echo 'true';
        exit();
    }

    /**
     * Change payment complete order status to completed for Вitfinex payments method orders.
     *
     * @param  string         $status Current order status.
     * @param  int            $order_id Order ID.
     * @param  WC_Order|false $order Order object.
     * @return string
     */
    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ($order && 'bfx_payment' === $order->get_payment_method()) {
            $status = 'completed';
        }

        return $status;
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin  Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)).PHP_EOL);
        }
    }

    static function htmlEmailTemplate($name, $orderId, $date, $payment, $currency, $subtotal, $total, $address, $count, $productName, $invoice, $amount) {
        $message = '
<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
                <tbody><tr>
                    <td align="center" valign="top">
                        <div id="m_1823764989813667934template_header_image">
                                                    </div>
                        <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color:#f5f5f5;border:1px solid #dedede;border-radius:3px">
                            <tbody><tr>
                                <td align="center" valign="top">
                                    <table border="0" cellpadding="0" cellspacing="0" width="100%" id="m_1823764989813667934template_header" style="color:black;border-bottom:0;font-weight:bold;line-height:100%;vertical-align:middle;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;border-radius:3px 3px 0 0">
                                        <tbody><tr>
                                            <td id="m_1823764989813667934header_wrapper" style="padding:36px 48px;display:block">
                                                <h1 style="font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:16px;font-weight:300;line-height:150%;margin:0;text-align:center;background-color:inherit; color:black">Julie`s Fashion</h1>
                                            </td>
                                        </tr>
                                    </tbody></table>
                                </td>
                            </tr>
                            <tr>
                                <td align="center" valign="top">

                                    <table border="0" cellpadding="0" cellspacing="0" width="600" id="m_1823764989813667934template_body">
                                        <tbody><tr>
                                            <td valign="top" id="m_1823764989813667934body_content" style="background-color:#FFFFFF">

                                                <table border="0" cellpadding="20" cellspacing="0" width="100%">
                                                    <tbody><tr>
                                                        <td valign="top" style="padding:0px 48px 32px">
                                                            <div id="m_1823764989813667934body_content_inner" style="color:#636363;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left"><span>
<h1 style="font-size:18px;font-weight:bold; text-align:center">Thanks for shopping with us</h1>
<p style="margin:0 0 16px; font-weight:bold;">Hi '.$name.',</p>
<p style="margin:0 0 16px">We have finished processing your order</p>

<div style="display:flex;justify-content:space-between;">
<p style="margin:0 0 16px;font-weight:bold;">Order # '.$orderId.'  </p>
<p style="margin:0 0 16px;font-weight:bold; margin: 0 0 0 auto;">&ensp;&ensp;&ensp;'.substr($date, 0, 10).'</p>
</div>
<div style="display:flex; justify-content:space-between;">
<p style="margin:0 0 16px;font-weight:bold;">Payment method: <img src="cid:bfx-pay-white" alt="Bitfinex"></p>
<p style="margin:0 0 16px; font-weight:bold;margin: 0 0 0 auto;">'.$amount.' '.$invoice['payCurrency'].'</p>
</div>
<p style="margin:0 0 16px; font-weight:bold;">Transaction address: <a style="color: green">'.$invoice['address'].'</a></p>

<div style="margin-bottom:40px">
    <table cellspacing="0" cellpadding="6" border="1" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;">
        <thead>
            <tr>
                <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Product</th>
                <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Quantity</th>
                <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Price</th>
            </tr>
        </thead>
        <tbody>
                <tr>
        <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica,Roboto,Arial,sans-serif;word-wrap:break-word">'.$productName.'</td>
        <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica,Roboto,Arial,sans-serif">'.$count.'</td>
        <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica,Roboto,Arial,sans-serif">
            <span>'.$subtotal['cart_subtotal']['value'].'</span>        </td>
    </tr>
            <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px">Subtotal</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px"><span>'.$subtotal['cart_subtotal']['value'].'</span></td>
                    </tr>
                                        <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Total:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><span>'.$total['order_total']['value'].'</span></td>
                    </tr>

        </tbody>
    </table>
</div>
<table cellspacing="0" cellpadding="0" border="0" style="width:100%;vertical-align:top;margin-bottom:40px;padding:0">
    <tbody><tr>
        <td valign="top" width="50%" style="text-align:left;border:0;padding:0">
            <h2 style="display:block;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left">Billing Address</h2>

            <address style="padding:12px;color:#636363;border:1px solid #e5e5e5 background-color:#f5f5f5;">'.$address.'
            </address>
        </td>
            </tr>
</tbody></table>
                                                            </span></div>
                                                        </td>
                                                    </tr>
                                                </tbody></table>
                                            </td>
                                        </tr>
                                    </tbody></table>
                                </td>
                            </tr>
                        </tbody></table>
                    </td>
                </tr>
&nbsp;
        ';

        return $message;
    }
}
