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
 * @version     2.0.2
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
    public $orderStatusCompleted;
    public $orderStatusExpired;

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
        $this->orderStatusCompleted = $this->get_option('order_status_completed', 'completed');
        $this->orderStatusExpired = $this->get_option('order_status_expired', 'failed');

        // Checking which button theme selected and outputing relevated.
        $this->icon = ('Light' === $this->buttonType) ? apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-white.svg', __FILE__)) : apply_filters('woocommerce_bfx_icon', plugins_url('../assets/img/bfx-pay-dark.svg', __FILE__));
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        add_filter('woocommerce_payment_complete_order_status', [$this, 'change_payment_complete_order_status'], 10, 3);
        // Customer Emails.
        add_action('woocommerce_email_order_details', [$this, 'remove_order_details'], 1, 4);
        add_action('woocommerce_email_order_details', [$this, 'email_template'], 20, 4);
        add_action('woocommerce_api_bitfinex', [$this, 'webhook']);

        // Cron
        add_filter('cron_schedules', [$this, 'cron_add_fifteen_min']);
        add_filter('woocommerce_add_error', [$this, 'woocommerce_add_error']);
        add_action('wp', [$this, 'bitfinex_cron_activation']);
        add_action('bitfinex_fifteen_min_event', [$this, 'cron_invoice_check']);

        $baseUrl = $this->baseApiUrl;
        $this->client = new GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'timeout' => 3.0,
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
        $errPos = strpos($error, '["error",null,"ERR_PAY');
        if (false !== $errPos) {
            $bracketPos = strpos($error, ']', $errPos);

            if (false !== $bracketPos) {
                $errJson = json_decode(substr($error, $errPos, $bracketPos), true);

                return 'Bitfinex invoice creation failed with reason: '.$errJson[2];
            }
        }

        return 'Internal server error, please try again later';
    }

    /**
     * Cron.
     */
    public function cron_add_fifteen_min($schedules)
    {
        $schedules['fifteen_min'] = [
            'interval' => 60 * 15,
            'display' => 'Bitfinex cron',
        ];

        return $schedules;
    }

    public function bitfinex_cron_activation()
    {
        if (!wp_next_scheduled('bitfinex_fifteen_min_event')) {
            wp_schedule_event(time(), 'fifteen_min', 'bitfinex_fifteen_min_event');
        }
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
     * Update order status
     */
    protected function update_order_status($order, $status)
    {
        if ($status === 'completed') {
            ob_start();
            $order->payment_complete();
            ob_clean();
        } else {
            $order->update_status($status);
        }
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
                'label' => __('Enable', 'bitfinex-pay'),'completed' => 'Completed',
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
                    'EUT-ETH' => 'Tether EURt - Ethereum',
                    'AVAX' => 'Avalanche',
                    'DOGE' => 'Dogecoin',
                    'MATICM' => 'MATIC - Mainnet',
                    'MATIC' => 'MATIC - Ethereum'
                ],
            ],
            'currency' => [
                'title' => __('Base fiat currency', 'bitfinex-pay'),
                'description' => __('This is the fiat currency which is shown as base price on your
invoices.', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'select',
                'default' => 'USD',
                'options' => [
                    'USD' => 'USD',
                    'EUR' => 'EUR',
                    'GBP' => 'GBP',
                    'CHF' => 'CHF',
                ],
            ],
            'order_status_completed' => [
                'title' => __('Order Status Completed Rule', 'bitfinex-pay'),
                'description' => __('Order status rules completed', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'select',
                'default' => 'completed',
                'options' => [
                    'processing' => 'Processing',
                    'completed' => 'Completed'
                ],
            ],
            'order_status_expired' => [
                'title' => __('Order Status Expired Rule', 'bitfinex-pay'),
                'description' => __('Order status rules completed', 'bfx-pay-woocommerce'),
                'desc_tip' => true,
                'type' => 'select',
                'default' => 'failed',
                'options' => [
                    'failed' => 'Failed',
                    'processing' => 'Processing',
                    'on-hold' => 'On Hold'
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
        $apiKey = $this->apiKey;
        $apiSecret = $this->apiSecret;
        $url = $this->get_return_url($order);
        $hook = get_site_url().'?wc-api=bitfinex';
        $totalSum = $order->get_total();
        $payCurrencies = $this->get_option('pay_currencies');
        $currency = $this->get_option('currency');
        $duration = $this->get_option('duration');
        $apiPath = 'v2/auth/w/ext/pay/invoice/create';
        $nonce = (string) (time() * 1000 * 1000); // epoch in ms * 1000
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
            $r = $this->client->post($apiPath, [
                'headers' => $headers,
                'body' => $bodyJson,
            ]);
            $response = $r->getBody()->getContents();
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->info('CREATE INVOICE CALL >> '.wc_print_r($response, true), ['source' => 'bitfinex-pay']);
            }
        } catch (\Throwable $ex) {
            $error = $ex->getMessage();
            $userError = $this->format_bfx_error($error);

            $logger = wc_get_logger();
            $logger->error('CREATE INVOICE CALL >> '.$error, ['source' => 'bitfinex-pay']);

            wc_add_notice($userError, 'error');
            $order->update_status('failed', $userError);

            return [
                'result' => 'failure',
                'messages' => 'failed',
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
        $apiPathin = 'v2/auth/r/ext/pay/invoices';
        $apiKey = $this->apiKey;
        $apiSecret = $this->apiSecret;
        $nonce = (string) (time() * 1000 * 1000); // epoch in ms * 1000
        $now = round(microtime(true) * 1000);
        $end = $now;
        while ($end > $now - (60 * 60000) * 25) {
            $start = $end - (60 * 60000) * 2;
            $bodyin = [
                'start' => $start,
                'end' => $end,
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
                $rin = $this->client->post($apiPathin, [
                    'headers' => $headersin,
                    'body' => $bodyJsonin,
                ]);
                $responsein = $rin->getBody()->getContents();
                if ($this->debug) {
                    wc_add_notice($responsein, 'notice');
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
                        if ('COMPLETED' === $invoice->status) {
                            $this->update_order_status($order, $this->orderStatusCompleted);
                        } elseif ('PENDING' === $invoice->status) {
                            $order->update_status('on-hold');
                        } elseif ('EXPIRED' === $invoice->status) {
                            $this->update_order_status($order, $this->orderStatusExpired);
                        } else {
                            $order->update_status('failed');
                        }
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

        if ('COMPLETED' === $data['status']) {
            $this->update_order_status($order, $this->orderStatusCompleted);
        } elseif ('EXPIRED' === $data['status']) {
            $this->update_order_status($order, $this->orderStatusExpired);
            return;
        } else {
            $this->update_order_status($order, 'failed');
            return;
        }

        if ($this->debug) {
            update_option('webhook_debug', $payload);
            $logger = wc_get_logger();
            $logger->info('WEBHOOK CALL >> '.wc_print_r($payload, true), ['source' => 'bitfinex-pay']);
        }

        status_header(200);
        echo 'true';
        exit();
    }

    /**
     * Change payment complete order status to completed for Вitfinex payments method orders.
     *
     * @param string         $status   current order status
     * @param int            $order_id order ID
     * @param WC_Order|false $order    order object
     *
     * @return string
     */
    public function change_payment_complete_order_status($status, $order_id = 0, $order = false)
    {
        if ($order && 'bfx_payment' === $order->get_payment_method()) {
            $status = 'completed';
        }

        return $status;
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
        $metadata = $order->get_meta_data();
        $invoiceId = null;
        foreach ($metadata as &$entry) {
            $data = $entry->get_data();

            if ($data['key'] === 'bitfinex_invoice_id') {
                $invoiceId = $data['value'];
            }
        }
        if (!$invoiceId) {
            return;
        }

        $body = [
            'id' => $invoiceId
        ];
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $r = $this->client->post('v2/ext/pay/invoice', [
            'headers' => $headers,
            'body' => $bodyJson,
        ]);
        $response = $r->getBody()->getContents();
        $data = json_decode($response, true);

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
