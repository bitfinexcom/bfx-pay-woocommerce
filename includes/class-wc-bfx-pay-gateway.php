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
 *
 * @version     3.2.1
 */
class WC_Bfx_Pay_Gateway extends WC_Payment_Gateway
{
    /**
     * API Context used for Bitfinex Merchant Authorization.
     *
     * @var null
     */
    public $baseApiUrl = 'https://api.bitfinex.com/';
    public $apiKey;
    public $apiSecret;
    public $isEnabled;
    public $title;
    public $description;
    public $instructions;
    public $complectedInstructions;
    public $buttonType;
    public $checkReqButton;
    public $payCurrencies;
    public $duration;
    public $icon;
    public $client;
    public $id;
    public $method_title;
    public $method_description;
    public $has_fields;
    public $debug;
    public $form_fields;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        global $woocommerce;
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->isEnabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->complectedInstructions = $this->get_option('complected_instruction');
        $this->buttonType = $this->get_option('button_type');
        $this->baseApiUrl = $this->get_option('base_api_url');
        if (!$this->baseApiUrl) {
            $this->baseApiUrl = 'https://api.bitfinex.com/';
        }
        $this->apiKey = $this->get_option('api_key');
        $this->apiSecret = $this->get_option('api_secret') ?? false;
        $this->checkReqButton = $this->get_option('button_req_checkout');
        $this->payCurrencies = $this->get_option('pay_currencies');
        $this->duration = $this->get_option('duration') ?? 86399;
        $this->debug = ('yes' === $this->get_option('debug'));

        // Checking which button theme selected and outputing relevated.
        $this->icon = ('Light' === $this->buttonType) ? apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-white.svg', __FILE__)) : apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-dark.svg', __FILE__));
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        // Customer Emails.
        add_action('woocommerce_email_order_details', [$this, 'remove_order_details'], 1, 4);
        add_action('woocommerce_email_order_details', [$this, 'email_template'], 20, 4);
        add_action('woocommerce_api_bitfinex', [$this, 'webhook']);

        add_filter('woocommerce_add_error', [$this, 'woocommerce_add_error']);

        $baseUrl = $this->baseApiUrl;
        $this->client = new GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 10.0,
        ]);
    }

    /**
     * Handle fixing guzzle curl error.
     */
    public function woocommerce_add_error($error)
    {
        if (false !== strpos(strtolower($error), 'curl')) {
            $logger = wc_get_logger();
            $logger->error($error, ['source' => 'bitfinex-pay']);

            return 'Internal server error, please try again later';
        }

        return $error;
    }

    protected function format_bfx_error($error)
    {
        if ($error instanceof GuzzleHttp\Exception\ServerException) {
            $response = $error->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            $errorObj = json_decode($responseBodyAsString);
            $errMsg = $errorObj[2];
            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_NOT_AVAILABLE_IN_COUNTRY_OR_REGION') {
                return 'Bitfinex Pay is not available in your country or region';
            }

            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_CURRENCY_INVALID') {
                return 'Bitfinex Pay does not support this currency';
            }

            if ($errMsg === 'ERR_CREATE_INVOICE: ERR_PAY_AMOUNT_INVALID') {
                return 'Bitfinex Pay can not process order with amount less than 0.1 USD equivalent.';
            }

            return 'Bitfinex invoice creation failed with reason: '.$errMsg;
        }

        return 'Internal server error, please try again later';
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties()
    {
        $this->id = 'bfx_payment';
        $this->icon = apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-white.svg', __FILE__));
        $this->method_title = __('Bitfinex Payment', 'bitfinex-pay');
        $this->method_description = __('Bitfinex Payment', 'bitfinex-pay');
        $this->has_fields = true;
    }
    /**
     * General function to update order status
     *
     * @param object $order WC Order
     * @param string $payment_status Status of the payment
     *
     * @return bool true if known status, false for unknown status
     */
    protected function update_order_status($order, $payment_status)
    {
        if ('COMPLETED' === $payment_status) {
            ob_start();
            $order->payment_complete();
            ob_clean();
            return true;
        }

        if ('PENDING' === $payment_status) {
            $order->update_status('on-hold');
            return true;
        }

        if ('EXPIRED' === $payment_status) {
            $order->update_status('failed');
            return true;
        }

        return false;
    }

    protected function bfx_request($path, $body) {
        $apiKey = $this->apiKey;
        $apiSecret = $this->apiSecret;
        $nonce = (string) (time() * 1000 * 1000); // epoch in ms * 1000
        $bodyJsonin = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signaturein = "/api/{$path}{$nonce}{$bodyJsonin}";
        $sigin = hash_hmac('sha384', $signaturein, $apiSecret);
        $headersin = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'bfx-nonce' => $nonce,
            'bfx-apikey' => $apiKey,
            'bfx-signature' => $sigin,
        ];
        $rin = $this->client->post($path, [
            'headers' => $headersin,
            'body' => $bodyJsonin,
        ]);
        $responsein = $rin->getBody()->getContents();
        return $responsein;
    }

    protected function get_bfx_invoices($start, $end)
    {
        $apiPathin = 'v2/auth/r/ext/pay/invoices';
        $bodyin = [
            'start' => $start,
            'end' => $end,
            'limit' => 100,
        ];

        return $this->bfx_request($apiPathin, $bodyin);
    }

    protected function get_bfx_invoice($id)
    {
        $apiPathin = 'v2/auth/r/ext/pay/invoices';
        $body = [
            'id' => $id
        ];
        $response = $this->bfx_request($apiPathin, $body);
        $data = json_decode($response, true);
        return $data[0];
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'bitfinex-pay'),
                'label' => __('Enable', 'bitfinex-pay'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'bitfinex-pay'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __('This controls the title which the user sees during checkout.', 'bitfinex-pay'),
            ],
            'description' => [
                'title' => __('Description', 'bitfinex-pay'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __('This controls the description which the user sees during checkout.', 'bitfinex-pay'),
            ],
            'instructions' => [
                'title' => __('Order received email instructions', 'bitfinex-pay'),
                'type' => 'textarea',
                'default' => __('', 'bitfinex-pay'),
                'desc_tip' => true,
                'description' => __('You can add a message to the email the user receives when starting the order', 'bitfinex-pay'),
            ],
            'complected_instruction' => [
                'title' => __('Order completed email instructions', 'bfx-pay-woocommerce'),
                'type' => 'textarea',
                'default' => __('', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'description' => __('You can add a thank you message to the email the user receives when the payments is received', 'bfx-pay-woocommerce'),
            ],
            'base_api_url' => [
                'title' => __('Api url', 'bitfinex-pay'),
                'type' => 'text',
                'default' => '',
                'description' => __('By default it is "https://api.bitfinex.com/"', 'bitfinex-pay'),
                'desc_tip' => true,
            ],
            'redirect_url' => [
                'title' => __('Redirect url', 'bitfinex-pay'),
                'type' => 'text',
                'default' => 'https://pay.bitfinex.com/gateway/order/',
                'description' => __('By default it is "https://pay.bitfinex.com/gateway/order/"', 'bitfinex-pay'),
                'desc_tip' => true,
            ],
            'api_key' => [
                'title' => __('Api key', 'bitfinex-pay'),
                'type' => 'text',
                'default' => '',
                'description' => __('Enter your bitfinex Api key', 'bitfinex-pay'),
                'desc_tip' => true,
            ],
            'api_secret' => [
                'title' => __('Api secret', 'bitfinex-pay'),
                'type' => 'password',
                'default' => '',
                'description' => __('Enter your bitfinex Api secret', 'bitfinex-pay'),
                'desc_tip' => true,
            ],
            'button_type' => [
                'title' => __('Button theme', 'bitfinex-pay'),
                'description' => __('Type of button image to display on cart and checkout pages', 'bitfinex-pay'),
                'desc_tip' => true,
                'type' => 'select',
                'default' => 'Light',
                'options' => [
                    'Light' => __('Light theme Bitfinex button', 'bitfinex-pay'),
                    'Dark' => __('Dark theme Bitfinex button', 'bitfinex-pay'),
                ],
            ],
            'button_req_checkout' => [
                'title' => __('Enable/Disable one click checkout button for products', 'bitfinex-pay'),
                'label' => __('Enable', 'bitfinex-pay'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'debug' => [
                'title' => __('Debug', 'bitfinex-pay'),
                'label' => __('Enable debugging messages', 'bitfinex-pay'),
                'type' => 'checkbox',
                'description' => __('Sends debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-amazon-payments-advanced'),
                'desc_tip' => true,
                'default' => 'yes',
            ],
            'pay_currencies' => [
                'title' => __('Pay currencies', 'bitfinex-pay'),
                'description' => __('Select pay currencies that you preffer. It may be several with ctrl/cmd button.', 'bitfinex-pay'),
                'desc_tip' => true,
                'type' => 'multiselect',
                'default' => 'BTC',
                'options' => [
                    'BTC' => 'Bitcoin',
                    'LNX' => 'Bitcoin - Lightning',
                    'LBT' => 'Bitcoin - Liquid',
                    'LTC' => 'Litecoin',
                    'ETH' => 'Ethereum',
                    'UST-ETH' => 'Tether USDt - Ethereum',
                    'UST-TRX' => 'Tether USDt - Tron',
                    'UST-PLY' => 'Tether USDt - Polygon',
                    'UST-LBT' => 'Tether USDt - Liquid',
                    'UST-TON' => 'Tether USDt - Ton',
                    'EUT-ETH' => 'Tether EURt - Ethereum',
                    'AVAX' => 'Avalanche',
                    'DOGE' => 'Dogecoin',
                    'MATICM' => 'MATIC - Mainnet',
                    'MATIC' => 'MATIC - Ethereum'
                ],
            ],
            'duration' => [
                'title' => __('Duration: sec', 'bitfinex-pay'),
                'label' => __('sec', 'bitfinex-pay'),
                'type' => 'number',
                'default' => '86399',
                'custom_attributes' => ['step' => 'any', 'min' => '0'],
                'desc_tip' => true,
                'description' => __('This controls the duration.', 'bitfinex-pay'),
            ],
        ];
    }

    /**
     * Getting Gateway icon uri.
     */
    public function get_icon_uri()
    {
        $bfxImgPath = plugin_dir_url(__FILE__).'../assets/img/';
        $bfxPayWhite = $bfxImgPath.'bfx-pay-white.svg';
        $bfxPayDark = $bfxImgPath.'bfx-pay-dark.svg';

        return ('Light' === $this->buttonType) ? $bfxPayWhite : $bfxPayDark;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function payment_fields()
    {
        echo wpautop(wp_kses_post($this->description));
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id order ID
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);
        $res = $this->client->get('v2/platform/status');

        if (1 !== json_decode($res->getBody()->getContents())[0]) {
            throw new Exception(sprintf('This payment method is currently unavailable. Try again later or choose another one'));
        }
        $url = $this->get_return_url($order);
        $hook = get_site_url().'?wc-api=bitfinex';
        $totalSum = $order->get_total();
        $payCurrencies = $this->get_option('pay_currencies');
        $currency = get_woocommerce_currency();
        $duration = $this->get_option('duration');
        $apiPath = 'v2/auth/w/ext/pay/invoice/create';

        $body = [
            'amount' => $totalSum,
            'currency' => $currency,
            'payCurrencies' => $payCurrencies,
            'orderId' => "$order_id",
            'duration' => intval($duration),
            'webhook' => $hook,
            'redirectUrl' => $url,
            'customerInfo' => [
                'nationality' => $order->get_billing_country(),
                'residCountry' => $order->get_billing_country(),
                'residCity' => $order->get_billing_city(),
                'residState' => $order->get_billing_state(),
                'residZipCode' => $order->get_billing_postcode(),
                'residStreet' => $order->get_billing_address_1(),
                'fullName' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
            ],
        ];

        try {
            $response = $this->bfx_request($apiPath, $body);
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->info('CREATE INVOICE CALL >> '.wc_print_r($response, true), ['source' => 'bitfinex-pay']);
            }
        } catch (\Throwable $ex) {
            $error = $ex->getMessage();
            $userError = $this->format_bfx_error($ex);

            $logger = wc_get_logger();
            $logger->error('CREATE INVOICE CALL >> '.$error, ['source' => 'bitfinex-pay']);

            wc_add_notice($userError, 'error');
            $order->update_status('failed', $userError);

            return [
                'result' => 'failure',
                'messages' => $userError,
            ];
        }

        $data = json_decode($response);

        $order->update_meta_data('bitfinex_invoice_id', $data->id);
        $order->save_meta_data();

        // Mark as on-hold (we're awaiting the bitfinex payment)
        $order->update_status('on-hold', __('Awaiting bitfinex payment', 'woocommerce'));

        // Remove cart
        $woocommerce->cart->empty_cart();

        // Return thankyou redirect
        $redirectUrl = $this->get_option('redirect_url');
        if (!$redirectUrl) {
            $redirectUrl = 'https://pay.bitfinex.com/gateway/order/';
        }
        return [
            'result' => 'success',
            'redirect' => $redirectUrl.$data->id,
        ];
    }

    /**
     * cron_invoice_check.
     */
    public function cron_invoice_check()
    {
        if ($this->debug) {
            $logger = wc_get_logger();
            $logger->info('BEGIN CRON INVOICE CHECK', ['source' => 'bitfinex-pay']);
        }
        $now = round(microtime(true) * 1000);
        $end = $now;
        while ($end > $now - (60 * 60000) * 25) {
            $start = $end - (60 * 60000) * 2;

            try {
                $responsein = $this->get_bfx_invoices($start, $end);
                if ($this->debug) {
                    $logger = wc_get_logger();
                    $logger->info('CRON READ INVOICE CALL >> '.wc_print_r($responsein, true), ['source' => 'bitfinex-pay']);
                }
                $datain = json_decode($responsein);
                foreach ($datain as $invoice) {
                    $order = wc_get_order($invoice->orderId);
                    if (false === $order) {
                        continue;
                    }
                    if ('on-hold' === $order->get_status()) {
                        $this->update_order_status($order, $invoice->status);
                    }
                }
            } catch (\Throwable $exin) {
                print_r($exin->getMessage());
            }
            $end -= (60 * 60000) * 2;
        }
    }

    /**
     * Webhook.
     */
    public function webhook()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            return;
        }
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        $order = wc_get_order($data['orderId']);

        if (!$order) {
            exit();
        }

        if ($this->debug) {
            $logger = wc_get_logger();
            $logger->info('WEBHOOK CALL >> '.wc_print_r($payload, true), ['source' => 'bitfinex-pay']);
        }

        $invoice_id = get_post_meta($order->id, 'bitfinex_invoice_id', true);
        $invoice_data = $this->get_bfx_invoice($invoice_id);

        if ($this->debug) {
            $logger = wc_get_logger();
            $logger->info('WEBHOOK INVOICE DATA >> '.wc_print_r($invoice_data, true), ['source' => 'bitfinex-pay']);
        }

        $is_success = $this->update_order_status($order, $invoice_data['status']);

        if ($is_success) {
            status_header(200);
            echo 'true';
        }

        exit();
    }

    /**
     * Remove email templates.
     */
    public function remove_order_details()
    {
        $mailer = WC()->mailer(); // get the instance of the WC_Emails class
        remove_action('woocommerce_email_order_details', array($mailer, 'order_details'));
    }

    /**
     * New email templates.
     */
    public function email_template($order, $sent_to_admin, $plain_text, $email)
    {
        $order->read_meta_data(true);
        $invoiceId = get_post_meta($order->id, 'bitfinex_invoice_id', true);

        if (!$invoiceId) {
            return;
        }

        $data = $this->get_bfx_invoice($invoiceId);

        $payment = null;
        $amount = null;

        if ($email->id == 'customer_completed_order') {
            $payment = $data['payment'];
            $amount = $payment['amount'];
            $amount = preg_replace('/0+$/', '', sprintf('%.10f', $amount));
        }

        $product = $order->get_items();
        $subtotal = $order->get_order_item_totals();
        $total = $order->get_order_item_totals();
        $products = '';

        foreach ($product as $item) {
            $row ='<tr>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif;word-wrap:break-word">
            '.$item['name'].'</td>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif">'.$item['quantity'].'</td>
            <td style="color:#636363;border:1px solid #e5e5e5;padding:12px;text-align:left;vertical-align:middle;font-family:Helvetica Neue,Helvetica,Roboto,Arial,sans-serif">
             <span>'.$item['total'].'</span>
             </td>
            </tr>';
            $products .= $row;
        }

        if ($email->id == 'customer_completed_order') {
            echo '<p>' . $this->complectedInstructions . '</p>';
        }

        if ($email->id === 'customer_on_hold_order') {
            echo '<p>' . $this->instructions . '</p>';
        } ?>

        <div style="color:#636363;font-family:&quot;Helvetica Neue&quot;,Helvetica,Robot,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left">


            <h2 style="color:#96588a;display:block;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left">
                [Order # <?php echo$order->id; ?>] (<?php echo substr($order->date_created, 0, 10); ?>)</h2>
            <?php
            if ($email->id == 'customer_completed_order') {
                ?>
                <p style="margin:10px 0 16px; font-weight:bold; display: grid;">Transaction id: <span style="color: #03ca9b"><?php echo $payment['txid']?></span></p>
                <?php
            } ?>
            <div style="margin-bottom:40px">
                <table cellspacing="0" cellpadding="6" border="1" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif">
                    <thead>
                    <tr>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Product</th>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Quantity</th>
                        <th scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Price</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php echo $products ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px">Subtotal:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px"><span><?php echo $subtotal['cart_subtotal']['value'] ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Bitfinex Pay:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><?php echo $this->title ?></td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Total:</th>
                        <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><span><?php echo $total['order_total']['value'] ?></span></td>
                    </tr>
                    <?php
                    if ($email->id == 'customer_completed_order') {
                        ?>
                        <tr>
                            <th scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left">Paid with:</th>
                            <td style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left"><span><?php echo  $amount.' '.$payment['currency']?></span></td>
                        </tr>
                        <?php
                    } ?>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php
        if ($email->id === 'customer_on_hold_order') { ?>
        <span>If you accidentally closed the payment process or want to complete it later, you can access it
using this <a href="<?php echo  $this->get_option('redirect_url').$invoiceId;?>">link</a>. Please keep in mind that this invoice will expire in <?php echo  date("H\h:i\m");?></span>
        <span></span>
        <?php }
        }
}
