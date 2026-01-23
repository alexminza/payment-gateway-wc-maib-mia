<?php

/**
 * @package payment-gateway-wc-maib-mia
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

use Maib\MaibMia\MaibMiaClient;

class WC_Gateway_MAIB_MIA extends WC_Payment_Gateway_Base
{
    //region Constants
    const MOD_ID          = 'maib_mia';
    const MOD_TEXT_DOMAIN = 'payment-gateway-wc-maib-mia';
    const MOD_PREFIX      = 'maib_mia_';
    const MOD_TITLE       = 'maib MIA';
    const MOD_VERSION     = '1.1.1';
    const MOD_PLUGIN_FILE = MAIB_MIA_MOD_PLUGIN_FILE;

    const SUPPORTED_CURRENCIES = array('MDL');
    const ORDER_TEMPLATE       = 'Order #%1$s';

    const MOD_ACTION_CHECK_PAYMENT = self::MOD_PREFIX . 'check_payment';

    const MOD_QR_ID           = self::MOD_PREFIX . 'qr_id';
    const MOD_QR_URL          = self::MOD_PREFIX . 'qr_url';
    const MOD_PAY_ID          = self::MOD_PREFIX . 'pay_id';
    const MOD_PAYMENT_RECEIPT = self::MOD_PREFIX . 'payment_receipt';

    /**
     * Default API request timeout (seconds).
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * Default transaction validity (minutes).
     */
    const DEFAULT_VALIDITY = 360;

    const MIN_VALIDITY     = 1;    // minutes
    const MAX_VALIDITY     = 1440; // minutes
    //endregion

    protected $transaction_validity;
    protected $maib_mia_base_url, $maib_mia_callback_url, $maib_mia_client_id, $maib_mia_client_secret, $maib_mia_signature_key;

    public function __construct()
    {
        $this->id                 = self::MOD_ID;
        $this->method_title       = self::MOD_TITLE;
        $this->method_description = __('Accept MIA Instant Payments through maib.', 'payment-gateway-wc-maib-mia');
        $this->has_fields         = false;
        $this->supports           = array('products', 'refunds');

        //region Initialize settings
        $this->init_form_fields();
        $this->init_settings();

        parent::__construct();

        $this->icon = plugins_url('/assets/img/mia.svg', self::MOD_PLUGIN_FILE);
        $this->transaction_validity = intval($this->get_option('transaction_validity', self::DEFAULT_VALIDITY));

        // https://github.com/alexminza/maib-mia-sdk-php/blob/main/src/MaibMia/MaibMiaClient.php
        $this->maib_mia_base_url      = $this->testmode ? MaibMiaClient::SANDBOX_BASE_URL : MaibMiaClient::DEFAULT_BASE_URL;
        $this->maib_mia_callback_url  = $this->get_option('maib_mia_callback_url', $this->get_callback_url());
        $this->maib_mia_client_id     = $this->get_option('maib_mia_client_id');
        $this->maib_mia_client_secret = $this->get_option('maib_mia_client_secret');
        $this->maib_mia_signature_key = $this->get_option('maib_mia_signature_key');

        if (is_admin()) {
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
        }
        //endregion

        add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __('Enable/Disable', 'payment-gateway-wc-maib-mia'),
                'type'        => 'checkbox',
                'label'       => __('Enable this gateway', 'payment-gateway-wc-maib-mia'),
                'default'     => 'yes',
            ),
            'title'           => array(
                'title'       => __('Title', 'payment-gateway-wc-maib-mia'),
                'type'        => 'text',
                'description' => __('Payment method title that the customer will see during checkout.', 'payment-gateway-wc-maib-mia'),
                'desc_tip'    => true,
                'default'     => $this->get_method_title(),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'description'     => array(
                'title'       => __('Description', 'payment-gateway-wc-maib-mia'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see during checkout.', 'payment-gateway-wc-maib-mia'),
                'desc_tip'    => true,
                'default'     => __('Pay instantly by scanning the QR code using your bank\'s mobile application.', 'payment-gateway-wc-maib-mia'),
            ),

            'testmode'        => array(
                'title'       => __('Test mode', 'payment-gateway-wc-maib-mia'),
                'type'        => 'checkbox',
                'label'       => __('Enabled', 'payment-gateway-wc-maib-mia'),
                'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'payment-gateway-wc-maib-mia'),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'debug'           => array(
                'title'       => __('Debug mode', 'payment-gateway-wc-maib-mia'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'payment-gateway-wc-maib-mia'),
                'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'payment-gateway-wc-maib-mia'), esc_url(self::get_logs_url())),
                'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'payment-gateway-wc-maib-mia'),
                'default'     => 'no',
            ),

            'order_template'  => array(
                'title'       => __('Order description', 'payment-gateway-wc-maib-mia'),
                'type'        => 'text',
                /* translators: 1: Example placeholder shown to user, represents Order ID */
                'description' => __('Format: <code>%1$s</code> - Order ID', 'payment-gateway-wc-maib-mia'),
                'desc_tip'    => __('Order description that the customer will see in the app during payment.', 'payment-gateway-wc-maib-mia'),
                'default'     => self::ORDER_TEMPLATE,
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'transaction_validity'  => array(
                'title'       => __('Transaction validity', 'payment-gateway-wc-maib-mia'),
                'type'        => 'number',
                /* translators: 1: Transaction validity in minutes */
                'description' => sprintf(__('Default: %1$s minutes', 'payment-gateway-wc-maib-mia'), self::DEFAULT_VALIDITY),
                'desc_tip'    => __('QR code validity time in minutes.', 'payment-gateway-wc-maib-mia'),
                'default'     => self::DEFAULT_VALIDITY,
                'custom_attributes' => array(
                    'min'      => self::MIN_VALIDITY,
                    'step'     => 1,
                    'max'      => self::MAX_VALIDITY,
                    'required' => 'required',
                ),
            ),

            'connection_settings' => array(
                'title'       => __('Connection Settings', 'payment-gateway-wc-maib-mia'),
                'description' => __('Payment gateway connection credentials are provided by the bank.', 'payment-gateway-wc-maib-mia'),
                'type'        => 'title',
            ),
            'maib_mia_client_id' => array(
                'title'       => __('Client ID', 'payment-gateway-wc-maib-mia'),
                'type'        => 'text',
                'desc_tip'    => 'Client ID',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'maib_mia_client_secret' => array(
                'title'       => __('Client Secret', 'payment-gateway-wc-maib-mia'),
                'type'        => 'password',
                'desc_tip'    => 'Client Secret',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'maib_mia_signature_key' => array(
                'title'       => __('Signature Key', 'payment-gateway-wc-maib-mia'),
                'type'        => 'password',
                'desc_tip'    => 'Signature Key',
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),

            'payment_notification' => array(
                'title'       => __('Payment Notification', 'payment-gateway-wc-maib-mia'),
                'type'        => 'title',
            ),
            'maib_mia_callback_url' => array(
                'title'       => __('Callback URL', 'payment-gateway-wc-maib-mia'),
                'type'        => 'text',
                'description' => sprintf('<code>%1$s</code>', esc_url($this->get_callback_url())),
                'desc_tip'    => 'Callback URL',
                'default'     => $this->get_callback_url(),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
        );
    }

    //region Settings validation
    protected function check_settings()
    {
        return parent::check_settings()
            && !empty($this->maib_mia_client_id)
            && !empty($this->maib_mia_client_secret)
            && !empty($this->maib_mia_signature_key)
            && !empty($this->maib_mia_callback_url);
    }

    protected function validate_settings()
    {
        if (!parent::validate_settings()) {
            return false;
        }

        if (!$this->check_settings()) {
            /* translators: 1: Plugin installation instructions URL */
            $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'payment-gateway-wc-maib-mia'), 'https://wordpress.org/plugins/payment-gateway-wc-maib-mia/#installation');
            $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'payment-gateway-wc-maib-mia'), esc_html__('Not configured', 'payment-gateway-wc-maib-mia'), wp_kses_post($message_instructions)));
            return false;
        }

        return true;
    }

    public function validate_order_template_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_transaction_validity_field($key, $value)
    {
        if (isset($value) && !$this->validate_transaction_validity($value)) {
            /* translators: 1: Field label, 2: Min value, 3: Max value */
            $this->add_error(esc_html(sprintf(__('%1$s field must be an integer between %2$d and %3$d.', 'payment-gateway-wc-maib-mia'), $this->get_settings_field_label($key), self::MIN_VALIDITY, self::MAX_VALIDITY)));
        }

        return $value;
    }

    public function validate_maib_mia_client_id_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_mia_client_secret_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_mia_signature_key_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_maib_mia_callback_url_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    protected function validate_transaction_validity($value)
    {
        $transaction_validity = intval($value);
        return $transaction_validity >= self::MIN_VALIDITY
            && $transaction_validity <= self::MAX_VALIDITY;
    }
    //endregion

    //region maib MIA
    /**
     * @link https://github.com/alexminza/maib-mia-sdk-php/blob/main/README.md#getting-started
     */
    protected function init_maib_mia_client()
    {
        $options = array(
            'base_uri' => $this->maib_mia_base_url,
            'timeout'  => self::DEFAULT_TIMEOUT,
        );

        if ($this->debug) {
            $log_name = "{$this->id}_guzzle";
            $log_file_name = \WC_Log_Handler_File::get_log_file_path($log_name);

            $log = new \Monolog\Logger($log_name);
            $log->pushHandler(new \Monolog\Handler\StreamHandler($log_file_name, \Monolog\Logger::DEBUG));

            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

            $options['handler'] = $stack;
        }

        $guzzle_client = new \GuzzleHttp\Client($options);
        $client = new MaibMiaClient($guzzle_client);

        return $client;
    }

    private function maib_mia_get_response_result(?\GuzzleHttp\Command\Result $response)
    {
        if (!empty($response)) {
            $response_ok = boolval($response['ok']);
            if ($response_ok) {
                $response_result = (array) $response['result'];
                return $response_result;
            }
        }

        return null;
    }

    /**
     * @link https://github.com/alexminza/maib-mia-sdk-php/blob/main/README.md#get-access-token-with-client-id-and-client-secret
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/authentication/obtain-authentication-token
     */
    private function maib_mia_generate_token(MaibMiaClient $client)
    {
        $get_token_response = $client->getToken($this->maib_mia_client_id, $this->maib_mia_client_secret);
        $get_token_result = $this->maib_mia_get_response_result($get_token_response);

        $access_token = strval($get_token_result['accessToken']);
        return $access_token;
    }

    /**
     * @link https://github.com/alexminza/maib-mia-sdk-php/blob/main/README.md#create-a-dynamic-order-payment-qr
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-initiation/create-qr-code-static-dynamic
     */
    private function maib_mia_pay(MaibMiaClient $client, string $auth_token, \WC_Order $order)
    {
        $expires_at = (new \DateTime())->modify("+{$this->transaction_validity} minutes")->format('c');

        $qr_data = array(
            'type' => 'Dynamic',
            'expiresAt' => $expires_at,
            'amountType' => 'Fixed',
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'description' => $this->get_order_description($order),
            'orderId' => strval($order->get_id()),
            'callbackUrl' => $this->maib_mia_callback_url,
            'redirectUrl' => $this->get_redirect_url($order),
        );

        return $client->qrCreate($qr_data, $auth_token);
    }

    /**
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-qr-details-by-id
     */
    private function maib_mia_qr_active(MaibMiaClient $client, string $auth_token, string $qr_id)
    {
        $qr_details_response = $client->qrDetails($qr_id, $auth_token);
        $qr_details_result = $this->maib_mia_get_response_result($qr_details_response);

        if (!empty($qr_details_result)) {
            $qr_details_status = strval($qr_details_result['status']);

            if (strtolower($qr_details_status) === 'active') {
                $qr_details_expires_at = strval($qr_details_result['expiresAt']);

                $now = new \DateTime();
                $expires_at = new \DateTime($qr_details_expires_at);

                if ($expires_at > $now) {
                    $min_validity_seconds = $this->transaction_validity * 60 / 2;
                    $remaining_seconds = $expires_at->getTimestamp() - $now->getTimestamp();

                    return $remaining_seconds >= $min_validity_seconds;
                }
            }
        }

        return false;
    }

    /**
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-list-of-payments-with-filtering-options
     */
    private function maib_mia_qr_payment(MaibMiaClient $client, string $auth_token, string $qr_id, string $order_id)
    {
        $payment_list_data = array(
            'qrId' => $qr_id,
            'orderId' => $order_id,
            'status' => 'Executed',
        );

        $payment_list_response = $client->paymentList($payment_list_data, $auth_token);
        $payment_list_result = $this->maib_mia_get_response_result($payment_list_response);

        if (!empty($payment_list_result)) {
            $payment_list_result_count = intval($payment_list_result['totalCount']);

            if (1 === $payment_list_result_count) {
                $payment_list_result_items = (array) $payment_list_result['items'];
                return (array) $payment_list_result_items[0];
            } elseif ($payment_list_result_count > 1) {
                $this->log(
                    sprintf('Multiple order #%1$s QR %2$s payments', $order_id, $qr_id),
                    \WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'qr_id' => $qr_id,
                        'payment_list_response' => $payment_list_response->toArray(),
                    )
                );
            }
        }

        return null;
    }

    /**
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-refund/refund-completed-payment
     */
    private function maib_mia_qr_refund(MaibMiaClient $client, string $auth_token, string $pay_id, float $amount, string $reason)
    {
        $refund_data = array(
            'amount' => $amount,
            'reason' => $reason,
            'callbackUrl' => $this->maib_mia_callback_url,
        );

        return $client->paymentRefund($pay_id, $refund_data, $auth_token);
    }
    //endregion

    //region Payment
    /**
     * @param int $order_id
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $create_qr_response = null;

        try {
            $client = $this->init_maib_mia_client();
            $auth_token = $this->maib_mia_generate_token($client);

            //region Existing QR
            try {
                $qr_id = strval($order->get_meta(self::MOD_QR_ID, true));
                $qr_url = strval($order->get_meta(self::MOD_QR_URL, true));

                if (!empty($qr_id) && !empty($qr_url)) {
                    if ($this->maib_mia_qr_active($client, $auth_token, $qr_id)) {
                        return array(
                            'result'   => 'success',
                            'redirect' => $qr_url,
                        );
                    }
                }
            } catch (\Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    \WC_Log_Levels::ERROR,
                    array(
                        'response' => self::get_guzzle_error_response_body($ex),
                        'order_id' => $order_id,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }
            //endregion

            $create_qr_response = $this->maib_mia_pay($client, $auth_token, $order);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $create_qr_result = $this->maib_mia_get_response_result($create_qr_response);
        if (!empty($create_qr_result)) {
            $qr_id = strval($create_qr_result['qrId']);
            $qr_url = strval($create_qr_result['url']);

            //region Update order payment transaction metadata
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#apis-for-gettingsetting-posts-and-postmeta
            $order->update_meta_data(self::MOD_QR_ID, $qr_id);
            $order->update_meta_data(self::MOD_QR_URL, $qr_url);
            $order->save();
            //endregion

            /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
            $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->get_method_title(), $qr_id));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'create_qr_response' => $create_qr_response->toArray(),
                )
            );

            $order->add_order_note($message);

            return array(
                'result'   => 'success',
                'redirect' => $qr_url,
            );
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'payment-gateway-wc-maib-mia'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'create_qr_response' => $create_qr_response ? $create_qr_response->toArray() : null,
            )
        );

        $order->add_order_note($message);

        // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
        if (WC()->is_store_api_request()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is already escaped.
            throw new \Exception($message);
        }

        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        return array(
            'result'   => 'failure',
            'messages' => $message,
        );
    }

    public function check_response()
    {
        $this->log_request(__FUNCTION__);

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('GET' === $request_method) {
            /* translators: 1: Payment method title */
            $message = sprintf(__('%1$s Callback URL', 'payment-gateway-wc-maib-mia'), $this->get_method_title());
            return self::return_response(\WP_Http::OK, $message);
        } elseif ('POST' !== $request_method) {
            return self::return_response(\WP_Http::METHOD_NOT_ALLOWED);
        }

        //region Validate callback
        $callback_body = null;
        $callback_data = null;
        $validation_result = false;

        try {
            $callback_body = file_get_contents('php://input');
            if (empty($callback_body)) {
                throw new \Exception('Empty callback body');
            }

            /** @var array */
            $callback_data = wc_clean(json_decode($callback_body, true));
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(json_last_error_msg());
            }
            if (empty($callback_data) || !is_array($callback_data)) {
                throw new \Exception('Invalid callback data');
            }

            $validation_result = MaibMiaClient::validateCallbackSignature($callback_data, $this->maib_mia_signature_key);
            $this->log(
                sprintf(__('Payment notification callback', 'payment-gateway-wc-maib-mia')),
                \WC_Log_Levels::INFO,
                array(
                    'validation_result' => $validation_result,
                    // 'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                )
            );
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'callback_body' => $callback_body,
                    'callback_data' => $callback_data,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return self::return_response(\WP_Http::INTERNAL_SERVER_ERROR);
        }

        if (!$validation_result) {
            $message = esc_html(__('Callback signature validation failed.', 'payment-gateway-wc-maib-mia'));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'validation_result' => $validation_result,
                    'callback_data' => $callback_data,
                )
            );

            return self::return_response(\WP_Http::UNAUTHORIZED, 'Invalid callback signature');
        }
        //endregion

        //region Validate QR status
        $callback_data_result = (array) $callback_data['result'];
        $callback_qr_status = strval($callback_data_result['qrStatus']);
        if (strtolower($callback_qr_status) !== 'paid') {
            return self::return_response(\WP_Http::ACCEPTED);
        }
        //endregion

        //region Validate order ID
        $callback_order_id = absint($callback_data_result['orderId']);
        $order = wc_get_order($callback_order_id);

        if (empty($order)) {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = sprintf(__('Order not found by Order ID: %1$d received from %2$s.', 'payment-gateway-wc-maib-mia'), $callback_order_id, $this->get_method_title());
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'callback_data' => $callback_data,
                )
            );

            return self::return_response(\WP_Http::UNPROCESSABLE_ENTITY, 'Order not found');
        }
        //endregion

        $confirm_payment_result = $this->confirm_payment($order, $callback_data_result, $callback_data);

        if (is_wp_error($confirm_payment_result)) {
            return self::return_response($confirm_payment_result->get_error_code(), $confirm_payment_result->get_error_message());
        }

        return self::return_response(\WP_Http::OK);
    }

    public function check_payment(\WC_Order $order)
    {
        $order_id = strval($order->get_id());
        $qr_payment = null;
        $qr_details = null;

        $qr_id = strval($order->get_meta(self::MOD_QR_ID, true));
        if (empty($qr_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-maib-mia'), $order_id, self::MOD_QR_ID));
            \WC_Admin_Meta_Boxes::add_error($message);
            return;
        }

        try {
            $client = $this->init_maib_mia_client();
            $auth_token = $this->maib_mia_generate_token($client);

            $qr_payment = $this->maib_mia_qr_payment($client, $auth_token, $qr_id, $order_id);

            if (empty($qr_payment)) {
                $qr_details_response = $client->qrDetails($qr_id, $auth_token);
                $qr_details = $this->maib_mia_get_response_result($qr_details_response);
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $payment_status = null;
        if (!empty($qr_payment)) {
            $payment_status = strval($qr_payment['status']);
        } elseif (!empty($qr_details)) {
            $payment_status = strval($qr_details['status']);
        }

        if (!empty($payment_status)) {
            /* translators: 1: Order ID, 2: Payment method title, 3: Payment status */
            $message = esc_html(sprintf(__('Order #%1$s %2$s payment status: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->get_method_title(), $payment_status));
            $message = $this->get_test_message($message);
            \WC_Admin_Meta_Boxes::add_error($message);

            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'qr_payment' => $qr_payment,
                    'qr_details' => $qr_details,
                )
            );
        } else {
            /* translators: 1: Order ID */
            $message = esc_html(sprintf(__('Order #%1$s payment check failed.', 'payment-gateway-wc-maib-mia'), $order_id));
            \WC_Admin_Meta_Boxes::add_error($message);

            return;
        }

        if (!empty($qr_payment)) {
            if (strtolower($payment_status) === 'executed') {
                $confirm_payment_result = $this->confirm_payment($order, $qr_payment, $qr_payment);

                if (is_wp_error($confirm_payment_result)) {
                    \WC_Admin_Meta_Boxes::add_error($confirm_payment_result->get_error_message());
                }
            }
        }
    }

    protected function confirm_payment(\WC_Order $order, array $payment_data, array $payment_receipt_data)
    {
        //region Check order data
        $payment_data_order_id = intval($payment_data['orderId']);
        $payment_data_amount = floatval($payment_data['amount']);
        $payment_data_currency = strval($payment_data['currency']);

        $order_id = $order->get_id();
        $order_total = $order->get_total();
        $order_currency = $order->get_currency();

        $order_price = $this->format_price($order_total, $order_currency);
        $payment_data_price = $this->format_price($payment_data_amount, $payment_data_currency);

        if ($order_id !== $payment_data_order_id || $order_price !== $payment_data_price) {
            /* translators: 1: Payment data order ID, 2: Payment data price, 3: Order ID, 4: Order total price */
            $message = sprintf(__('Order payment data mismatch: Payment: #%1$s %2$s, Order: #%3$s %4$s.', 'payment-gateway-wc-maib-mia'), $payment_data_order_id, $payment_data_price, $order_id, $order_price);
            $this->log($message, \WC_Log_Levels::ERROR);

            return new \WP_Error(\WP_Http::UNPROCESSABLE_ENTITY, 'Order payment data mismatch');
        }

        if ($order->is_paid()) {
            /* translators: 1: Order ID */
            $message = sprintf(__('Order #%1$s already fully paid.', 'payment-gateway-wc-maib-mia'), $order_id);
            $this->log($message, \WC_Log_Levels::WARNING);

            return new \WP_Error(\WP_Http::ACCEPTED, 'Order already fully paid');
        }
        //endregion

        //region Complete order payment
        $payment_data_pay_id = strval($payment_data['payId']);
        $payment_data_reference_id = strval($payment_data['referenceId']);

        $order->update_meta_data(self::MOD_PAYMENT_RECEIPT, wp_json_encode($payment_receipt_data));
        $order->update_meta_data(self::MOD_PAY_ID, $payment_data_pay_id);
        $order->save();

        $order->payment_complete($payment_data_reference_id);
        //endregion

        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
        $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->get_method_title(), $payment_data_reference_id));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::INFO,
            array(
                'payment_receipt_data' => $payment_receipt_data,
            )
        );

        $order->add_order_note($message);
        return true;
    }

    /**
     * @param  int    $order_id
     * @param  float  $amount
     * @param  string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$this->check_settings()) {
            $message = $this->get_settings_admin_message();
            return new \WP_Error('check_settings', $message);
        }

        $order = wc_get_order($order_id);
        $order_currency = $order->get_currency();

        $pay_id = strval($order->get_meta(self::MOD_PAY_ID, true));
        if (empty($pay_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'payment-gateway-wc-maib-mia'), $order_id, self::MOD_PAY_ID));
            return new \WP_Error('order_pay_id', $message);
        }

        $payment_refund_response = null;
        try {
            $client = $this->init_maib_mia_client();
            $auth_token = $this->maib_mia_generate_token($client);

            $payment_refund_response = $this->maib_mia_qr_refund($client, $auth_token, $pay_id, $amount, $reason);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'response' => self::get_guzzle_error_response_body($ex),
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $payment_refund_result = $this->maib_mia_get_response_result($payment_refund_response);
        if (!empty($payment_refund_result)) {
            $refund_status = strval($payment_refund_result['status']);
            if (in_array(strtolower($refund_status), array('refunded', 'created'), true)) {
                /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'payment-gateway-wc-maib-mia'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'payment_refund_response' => $payment_refund_response->toArray(),
                    )
                );

                $order->add_order_note($message);
                return true;
            }
        }

        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'payment-gateway-wc-maib-mia'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'payment_refund_response' => $payment_refund_response ? $payment_refund_response->toArray() : null,
            )
        );

        $order->add_order_note($message);
        return new \WP_Error('process_refund', $message);
    }
    //endregion

    //region Utility
    protected function get_redirect_url(\WC_Order $order)
    {
        $redirect_url = $this->get_return_url($order);
        return (string) apply_filters('maib_mia_redirect_url', $redirect_url, $order);
    }

    protected function get_callback_url()
    {
        // https://developer.woocommerce.com/docs/extensions/core-concepts/woocommerce-plugin-api-callback/
        $callback_url = WC()->api_request_url("wc_{$this->id}");
        return (string) apply_filters('maib_mia_callback_url', $callback_url);
    }
    //endregion

    //region Init
    public static function order_actions(array $actions, \WC_Order $order)
    {
        if ($order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
            return $actions;
        }

        /* translators: 1: Payment method title */
        $actions[self::MOD_ACTION_CHECK_PAYMENT] = esc_html(sprintf(__('Check %1$s order payment', 'payment-gateway-wc-maib-mia'), self::MOD_TITLE));
        return $actions;
    }

    public static function action_check_payment(\WC_Order $order)
    {
        $plugin = new self();
        $plugin->check_payment($order);
    }
    //endregion
}
