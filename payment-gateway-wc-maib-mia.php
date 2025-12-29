<?php

/**
 * Plugin Name: Payment Gateway for maib MIA for WooCommerce
 * Description: Accept MIA Instant Payments directly on your store with the Payment Gateway for maib MIA for WooCommerce.
 * Plugin URI: https://github.com/alexminza/payment-gateway-wc-maib-mia
 * Version: 1.0.3
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: payment-gateway-wc-maib-mia
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.9
 * WC requires at least: 3.3
 * WC tested up to: 10.4.3
 * Requires Plugins: woocommerce
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/payment-gateway-wc-maib-mia
// This plugin is based on PHP SDK for maib MIA API https://github.com/alexminza/maib-mia-sdk-php (https://packagist.org/packages/alexminza/maib-mia-sdk)

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';

use Maib\MaibMia\MaibMiaClient;

add_action('plugins_loaded', 'maib_mia_init', 0);

function maib_mia_init()
{
    // https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_MAIB_MIA extends WC_Payment_Gateway
    {
        //region Constants
        const MOD_ID      = 'maib_mia';
        const MOD_PREFIX  = 'maib_mia_';
        const MOD_TITLE   = 'maib MIA';
        const MOD_VERSION = '1.0.3';

        const SUPPORTED_CURRENCIES = array('MDL');
        const ORDER_TEMPLATE       = 'Order #%1$s';

        const MOD_ACTION_CHECK_PAYMENT = self::MOD_PREFIX . 'check_payment';

        const MOD_QR_ID           = self::MOD_PREFIX . 'qr_id';
        const MOD_QR_URL          = self::MOD_PREFIX . 'qr_url';
        const MOD_PAY_ID          = self::MOD_PREFIX . 'pay_id';
        const MOD_PAYMENT_RECEIPT = self::MOD_PREFIX . 'payment_receipt';

        const DEFAULT_TIMEOUT  = 30;   // seconds
        const DEFAULT_VALIDITY = 360;  // minutes
        const MIN_VALIDITY     = 1;    // minutes
        const MAX_VALIDITY     = 1440; // minutes
        //endregion

        protected $testmode, $debug, $logger, $order_template, $transaction_validity;
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

            $this->enabled     = $this->get_option('enabled', 'no');
            $this->title       = $this->get_option('title', $this->method_title);
            $this->description = $this->get_option('description');
            $this->icon        = plugins_url('/assets/img/mia.svg', __FILE__);

            $this->testmode    = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->debug       = wc_string_to_bool($this->get_option('debug', 'no'));
            $this->logger      = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

            if ($this->testmode) {
                $this->description = $this->get_test_message($this->description);
            }

            $this->order_template       = $this->get_option('order_template', self::ORDER_TEMPLATE);
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
                    'default'     => $this->method_title,
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

        public function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true)) {
                return false;
            }

            return true;
        }

        public function is_available()
        {
            if (!$this->is_valid_for_use()) {
                return false;
            }

            if (!$this->check_settings()) {
                return false;
            }

            return parent::is_available();
        }

        public function needs_setup()
        {
            return !$this->check_settings();
        }

        public function admin_options()
        {
            $this->validate_settings();
            $this->display_errors();

            parent::admin_options();
        }

        //region Settings validation
        protected function check_settings()
        {
            return !empty($this->maib_mia_client_id)
                && !empty($this->maib_mia_client_secret)
                && !empty($this->maib_mia_signature_key)
                && !empty($this->maib_mia_callback_url);
        }

        protected function validate_settings()
        {
            $validate_result = true;

            if (!$this->is_valid_for_use()) {
                $this->add_error(
                    sprintf(
                        '<strong>%1$s: %2$s</strong>. %3$s: %4$s',
                        esc_html__('Unsupported store currency', 'payment-gateway-wc-maib-mia'),
                        esc_html(get_woocommerce_currency()),
                        esc_html__('Supported currencies', 'payment-gateway-wc-maib-mia'),
                        esc_html(join(', ', self::SUPPORTED_CURRENCIES))
                    )
                );

                $validate_result = false;
            }

            if (!$this->check_settings()) {
                /* translators: 1: Plugin installation instructions URL */
                $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'payment-gateway-wc-maib-mia'), 'https://wordpress.org/plugins/payment-gateway-wc-maib-mia/#installation');
                $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'payment-gateway-wc-maib-mia'), esc_html__('Not configured', 'payment-gateway-wc-maib-mia'), wp_kses_post($message_instructions)));
                $validate_result = false;
            }

            return $validate_result;
        }

        // https://developer.woocommerce.com/docs/extensions/settings-and-config/implementing-settings/
        protected function get_settings_field_label($key)
        {
            $form_fields = $this->get_form_fields();
            return $form_fields[$key]['title'];
        }

        public function validate_required_field($key, $value)
        {
            if (empty($value)) {
                /* translators: 1: Field label */
                WC_Admin_Settings::add_error(esc_html(sprintf(__('%1$s field must be set.', 'payment-gateway-wc-maib-mia'), $this->get_settings_field_label($key))));
            }

            return $value;
        }

        public function validate_order_template_field($key, $value)
        {
            return $this->validate_required_field($key, $value);
        }

        public function validate_transaction_validity_field($key, $value)
        {
            if (isset($value) && !$this->validate_transaction_validity($value)) {
                /* translators: 1: Field label, 2: Min value, 3: Max value */
                WC_Admin_Settings::add_error(esc_html(sprintf(__('%1$s field must be an integer between %2$d and %3$d.', 'payment-gateway-wc-maib-mia'), $this->get_settings_field_label($key), self::MIN_VALIDITY, self::MAX_VALIDITY)));
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

        protected function logs_admin_website_notice()
        {
            if (current_user_can('manage_woocommerce')) {
                $message = $this->get_logs_admin_message();
                wc_add_notice($message, 'error');
            }
        }

        protected function logs_admin_notice()
        {
            $message = $this->get_logs_admin_message();
            WC_Admin_Notices::add_custom_notice("{$this->id}_logs_admin_notice", $message);
        }

        protected function settings_admin_notice()
        {
            $message = $this->get_settings_admin_message();
            WC_Admin_Notices::add_custom_notice("{$this->id}_settings_admin_notice", $message);
        }

        protected function get_settings_admin_message()
        {
            /* translators: 1: Payment method title, 2: Plugin settings URL */
            $message = sprintf(wp_kses_post(__('%1$s is not properly configured. Verify plugin <a href="%2$s">Connection Settings</a>.', 'payment-gateway-wc-maib-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
        }

        protected function get_logs_admin_message()
        {
            /* translators: 1: Payment method title, 2: Plugin settings URL */
            $message = sprintf(wp_kses_post(__('See <a href="%2$s">%1$s settings</a> page for log details and setup instructions.', 'payment-gateway-wc-maib-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
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
                $log_file_name = WC_Log_Handler_File::get_log_file_path($log_name);

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

        /**
         * @param MaibMiaClient $client
         * @link https://github.com/alexminza/maib-mia-sdk-php/blob/main/README.md#get-access-token-with-client-id-and-client-secret
         * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/authentication/obtain-authentication-token
         */
        private function maib_mia_generate_token($client)
        {
            $get_token_response = $client->getToken($this->maib_mia_client_id, $this->maib_mia_client_secret);
            $access_token = strval($get_token_response['result']['accessToken']);

            return $access_token;
        }

        /**
         * @param MaibMiaClient $client
         * @param string $auth_token
         * @param string $order_id
         * @param string $order_name
         * @param float  $total_amount
         * @param string $currency
         * @param string $callback_url
         * @param string $redirect_url
         * @param int    $validity_minutes
         * @link https://github.com/alexminza/maib-mia-sdk-php/blob/main/README.md#create-a-dynamic-order-payment-qr
         * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-initiation/create-qr-code-static-dynamic
         */
        private function maib_mia_pay($client, $auth_token, $order_id, $order_name, $total_amount, $currency, $callback_url, $redirect_url, $validity_minutes)
        {
            $expires_at = (new DateTime())->modify("+{$validity_minutes} minutes")->format('c');

            $qr_data = array(
                'type' => 'Dynamic',
                'expiresAt' => $expires_at,
                'amountType' => 'Fixed',
                'amount' => $total_amount,
                'currency' => $currency,
                'description' => $order_name,
                'orderId' => strval($order_id),
                'callbackUrl' => $callback_url,
                'redirectUrl' => $redirect_url,
            );

            return $client->createQr($qr_data, $auth_token);
        }

        /**
         * @param MaibMiaClient $client
         * @param string $auth_token
         * @param string $qr_id
         * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-qr-details-by-id
         */
        private function maib_mia_qr_details($client, $auth_token, $qr_id)
        {
            $qr_details = $client->qrDetails($qr_id, $auth_token);

            if (!empty($qr_details)) {
                $qr_details_ok = boolval($qr_details['ok']);

                if ($qr_details_ok) {
                    $qr_details_result = (array) $qr_details['result'];
                    return $qr_details_result;
                }
            }

            return null;
        }

        /**
         * @param MaibMiaClient $client
         * @param string $auth_token
         * @param string $qr_id
         * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-qr-details-by-id
         */
        private function maib_mia_qr_active($client, $auth_token, $qr_id)
        {
            $qr_details = $this->maib_mia_qr_details($client, $auth_token, $qr_id);

            if (!empty($qr_details)) {
                $qr_details_status = strval($qr_details['status']);

                if (strtolower($qr_details_status) === 'active') {
                    $qr_details_expires_at = strval($qr_details['expiresAt']);

                    $now = new DateTime();
                    $expires_at = new DateTime($qr_details_expires_at);

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
         * @param MaibMiaClient $client
         * @param string $auth_token
         * @param string $qr_id
         * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-list-of-payments-with-filtering-options
         */
        private function maib_mia_qr_payment($client, $auth_token, $qr_id)
        {
            $qr_payments_data = array('qrId' => $qr_id,);
            $qr_payments = $client->paymentList($qr_payments_data, $auth_token);

            if (!empty($qr_payments)) {
                $qr_payments_ok = boolval($qr_payments['ok']);

                if ($qr_payments_ok) {
                    $qr_payments_result = (array) $qr_payments['result'];

                    if (!empty($qr_payments_result)) {
                        $qr_payments_result_count = absint($qr_payments_result['totalCount']);

                        if (1 === $qr_payments_result_count) {
                            $qr_payments_result_items = (array) $qr_payments_result['items'];
                            return (array) $qr_payments_result_items[0];
                        } elseif ($qr_payments_result_count > 1) {
                            $this->log(
                                sprintf('Multiple QR %1$s payments', $qr_id),
                                WC_Log_Levels::ERROR,
                                array(
                                    'qr_payments' => $qr_payments->toArray(),
                                )
                            );
                        }
                    }
                }
            }

            return null;
        }
        //endregion

        //region Payment
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
                } catch (Exception $ex) {
                    $this->log(
                        $ex->getMessage(),
                        WC_Log_Levels::ERROR,
                        array(
                            'exception' => (string) $ex,
                            'order_id' => $order_id,
                        )
                    );
                }
                //endregion

                $create_qr_response = $this->maib_mia_pay(
                    $client,
                    $auth_token,
                    $order_id,
                    $this->get_order_description($order),
                    $order->get_total(),
                    $order->get_currency(),
                    $this->maib_mia_callback_url,
                    $this->get_redirect_url($order),
                    $this->transaction_validity
                );
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'order_id' => $order_id,
                    )
                );
            }

            if (!empty($create_qr_response)) {
                $create_qr_response_ok = boolval($create_qr_response['ok']);
                if ($create_qr_response_ok) {
                    $create_qr_response_result = (array) $create_qr_response['result'];
                    $qr_id = strval($create_qr_response_result['qrId']);
                    $qr_url = strval($create_qr_response_result['url']);

                    //region Update order payment transaction metadata
                    // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#apis-for-gettingsetting-posts-and-postmeta
                    $order->add_meta_data(self::MOD_QR_ID, $qr_id, true);
                    $order->add_meta_data(self::MOD_QR_URL, $qr_url, true);
                    $order->save();
                    //endregion

                    /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
                    $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->method_title, $qr_id));
                    $message = $this->get_test_message($message);
                    $this->log(
                        $message,
                        WC_Log_Levels::INFO,
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
            }

            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'payment-gateway-wc-maib-mia'), $order_id, $this->method_title));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                WC_Log_Levels::ERROR,
                array(
                    'create_qr_response' => $create_qr_response ? $create_qr_response->toArray() : null,
                )
            );

            $order->add_order_note($message);

            // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
            if (WC()->is_store_api_request()) {
                throw new Exception(esc_html($message));
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
            $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            if ('GET' === $request_method) {
                /* translators: 1: Payment method title */
                $message = sprintf(__('%1$s Callback URL', 'payment-gateway-wc-maib-mia'), $this->method_title);
                return self::return_response(WP_Http::OK, $message);
            } elseif ('POST' !== $request_method) {
                return self::return_response(WP_Http::METHOD_NOT_ALLOWED);
            }

            //region Validate callback
            $callback_body = null;
            $callback_data = null;
            $validation_result = false;

            try {
                $callback_body = wc_clean(file_get_contents('php://input'));
                if (empty($callback_body)) {
                    throw new Exception('Empty callback body');
                }

                /** @var array */
                $callback_data = wc_clean(json_decode($callback_body, true));
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception(json_last_error_msg());
                }
                if (empty($callback_data) || !is_array($callback_data)) {
                    throw new Exception('Invalid callback data');
                }

                $validation_result = MaibMiaClient::validateCallbackSignature($callback_data, $this->maib_mia_signature_key);
                $this->log(
                    sprintf(__('Payment notification callback', 'payment-gateway-wc-maib-mia')),
                    WC_Log_Levels::DEBUG,
                    array(
                        'validation_result' => $validation_result,
                        // 'callback_body' => $callback_body,
                        'callback_data' => $callback_data,
                    )
                );
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'callback_body' => $callback_body,
                        'callback_data' => $callback_data,
                    )
                );

                return self::return_response(WP_Http::INTERNAL_SERVER_ERROR);
            }

            if (!$validation_result) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('%1$s callback signature validation failed.', 'payment-gateway-wc-maib-mia'), $this->method_title));
                $this->log($message, WC_Log_Levels::ERROR);
                return self::return_response(WP_Http::UNAUTHORIZED, 'Invalid callback signature');
            }
            //endregion

            //region Validate QR status
            $callback_data_result = (array) $callback_data['result'];
            $callback_qr_status = strval($callback_data_result['qrStatus']);
            if (strtolower($callback_qr_status) !== 'paid') {
                return self::return_response(WP_Http::ACCEPTED);
            }
            //endregion

            //region Validate order ID
            $callback_order_id = absint($callback_data_result['orderId']);
            $order = wc_get_order($callback_order_id);

            if (empty($order)) {
                /* translators: 1: Order ID, 2: Payment method title */
                $message = sprintf(__('Order not found by Order ID: %1$d received from %2$s.', 'payment-gateway-wc-maib-mia'), $callback_order_id, $this->method_title);
                $this->log($message, WC_Log_Levels::ERROR);

                return self::return_response(WP_Http::UNPROCESSABLE_ENTITY, 'Order not found');
            }
            //endregion

            $confirm_payment_result = $this->confirm_payment($order, $callback_data_result, $callback_data);

            if(is_wp_error($confirm_payment_result)) {
                return self::return_response($confirm_payment_result->get_error_code(), $confirm_payment_result->get_error_message());
            }

            return self::return_response(WP_Http::OK);
        }

        /**
         * @param \WC_Order $order
         */
        public function check_payment($order)
        {
            $order_id = $order->get_id();
            $qr_payment = null;

            $qr_id = strval($order->get_meta(self::MOD_QR_ID, true));
            if (empty($qr_id)) {
                /* translators: 1: Order ID, 2: Meta field key */
                $message = esc_html(sprintf(__('Order #%1$s missing meta %2$s', 'payment-gateway-wc-maib-mia'), $order_id, self::MOD_QR_ID));
                WC_Admin_Meta_Boxes::add_error($message);
                return;
            }

            try {
                $client = $this->init_maib_mia_client();
                $auth_token = $this->maib_mia_generate_token($client);

                $qr_payment = $this->maib_mia_qr_payment($client, $auth_token, $qr_id)
                    ?? $this->maib_mia_qr_details($client, $auth_token, $qr_id);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'order_id' => $order_id,
                    )
                );
            }

            if (!empty($qr_payment)) {
                $qr_payment_status = strval($qr_payment['status']);

                /* translators: 1: Order ID, 2: Payment method title, 3: Payment status */
                $message = esc_html(sprintf(__('Order #%1$s payment %2$s QR Payment status: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->method_title, $qr_payment_status));
                $message = $this->get_test_message($message);
                WC_Admin_Notices::add_custom_notice('check_payment', $message);

                $this->log(
                    $message,
                    WC_Log_Levels::INFO,
                    array(
                        'qr_payment' => $qr_payment,
                    )
                );

                if (strtolower($qr_payment_status) === 'executed') {
                    $confirm_payment_result = $this->confirm_payment($order, $qr_payment, $qr_payment);

                    if (is_wp_error($confirm_payment_result)) {
                        WC_Admin_Meta_Boxes::add_error($confirm_payment_result->get_error_message());
                    }
                }

                return;
            }

            /* translators: 1: Order ID */
            $message = esc_html(sprintf(__('Order #%1$s check payment failed.', 'payment-gateway-wc-maib-mia'), $order_id));
            WC_Admin_Meta_Boxes::add_error($message);
        }

        /**
         * @param \WC_Order $order
         * @param array     $payment_data
         * @param string    $payment_receipt_data
         */
        protected function confirm_payment($order, $payment_data, $payment_receipt_data)
        {
            //region Check order data
            $payment_data_amount = floatval($payment_data['amount']);
            $payment_data_currency = strval($payment_data['currency']);

            $order_id = $order->get_id();
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();

            $order_price = $this->format_price($order_total, $order_currency);
            $payment_data_price = $this->format_price($payment_data_amount, $payment_data_currency);

            if ($order_price !== $payment_data_price) {
                /* translators: 1: Payment data price, 2: Order total price */
                $message = sprintf(__('Order amount mismatch: Payment: %1$s, Order: %2$s.', 'payment-gateway-wc-maib-mia'), $payment_data_price, $order_price);
                $this->log($message, WC_Log_Levels::ERROR);

                return new WP_Error(WP_Http::UNPROCESSABLE_ENTITY, 'Order data mismatch');
            }

            if ($order->is_paid()) {
                /* translators: 1: Order ID */
                $message = sprintf(__('Order #%1$s already fully paid.', 'payment-gateway-wc-maib-mia'), $order_id);
                $this->log($message, WC_Log_Levels::ERROR);

                return new WP_Error(WP_Http::ACCEPTED, 'Order already fully paid');
            }
            //endregion

            //region Complete order payment
            $payment_data_pay_id = strval($payment_data['payId']);
            $payment_data_reference_id = strval($payment_data['referenceId']);

            $order->add_meta_data(self::MOD_PAYMENT_RECEIPT, wp_json_encode($payment_receipt_data), true);
            $order->add_meta_data(self::MOD_PAY_ID, $payment_data_pay_id, true);
            $order->save();

            $order->payment_complete($payment_data_reference_id);
            //endregion

            /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
            $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'payment-gateway-wc-maib-mia'), $order_id, $this->method_title, $payment_data_reference_id));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                WC_Log_Levels::INFO,
                array(
                    'payment_receipt_data' => $payment_receipt_data,
                )
            );

            $order->add_order_note($message);
            return true;
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            if (!$this->check_settings()) {
                $message = $this->get_settings_admin_message();
                return new WP_Error('check_settings', $message);
            }

            $order = wc_get_order($order_id);
            $pay_id = strval($order->get_meta(self::MOD_PAY_ID, true));
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();
            $payment_refund_response = null;

            //region Validate refund amount
            if (isset($amount) && $amount !== $order_total) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('Partial refunds are not currently supported by %1$s.', 'payment-gateway-wc-maib-mia'), $this->method_title));
                $this->log($message, WC_Log_Levels::ERROR);

                return new WP_Error('partial_refund', $message);
            }
            //endregion

            try {
                $client = $this->init_maib_mia_client();
                $auth_token = $this->maib_mia_generate_token($client);

                $payment_refund_response = $client->paymentRefund($pay_id, $reason, $auth_token);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'exception' => (string) $ex,
                        'order_id' => $order_id,
                        'amount' => $amount,
                        'reason' => $reason,
                    )
                );

                return new WP_Error('process_refund', $ex->getMessage());
            }

            if (!empty($payment_refund_response)) {
                $payment_refund_response_ok = boolval($payment_refund_response['ok']);
                if ($payment_refund_response_ok) {
                    $payment_refund_response_result = (array) $payment_refund_response['result'];

                    $refund_status = strval($payment_refund_response_result['status']);
                    if (strtolower($refund_status) === 'refunded') {
                        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'payment-gateway-wc-maib-mia'), $order_id, $this->format_price($order_total, $order_currency), $this->method_title));
                        $message = $this->get_test_message($message);
                        $this->log(
                            $message,
                            WC_Log_Levels::INFO,
                            array(
                                'payment_refund_response' => $payment_refund_response->toArray(),
                            )
                        );

                        $order->add_order_note($message);
                        return true;
                    }
                }
            }

            /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'payment-gateway-wc-maib-mia'), $order_id, $this->format_price($order_total, $order_currency), $this->method_title));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                WC_Log_Levels::ERROR,
                array(
                    'payment_refund_response' => $payment_refund_response ? $payment_refund_response->toArray() : null,
                )
            );

            $order->add_order_note($message);
            $this->logs_admin_notice();

            return new WP_Error('process_refund', $message);
        }
        //endregion

        //region Utility
        /**
         * @param float  $price
         * @param string $currency
         */
        protected function format_price($price, $currency)
        {
            $args = array(
                'currency' => $currency,
                'in_span' => false,
            );

            return wc_price($price, $args);
        }

        /**
         * @param \WC_Order $order
         */
        protected function get_order_description($order)
        {
            $description = sprintf($this->order_template, $order->get_id());
            return apply_filters('maib_mia_order_description', $description, $order);
        }

        /**
         * @param string $message
         */
        protected function get_test_message($message)
        {
            if ($this->testmode) {
                /* translators: 1: Original message */
                $message = esc_html(sprintf(__('TEST: %1$s', 'payment-gateway-wc-maib-mia'), $message));
            }

            return $message;
        }

        /**
         * @param \WC_Order $order
         */
        protected function get_redirect_url($order)
        {
            $redirect_url = $this->get_return_url($order);
            return apply_filters('maib_mia_redirect_url', $redirect_url);
        }

        protected function get_callback_url()
        {
            // https://developer.woocommerce.com/docs/extensions/core-concepts/woocommerce-plugin-api-callback/
            $callback_url = WC()->api_request_url("wc_{$this->id}");
            return apply_filters('maib_mia_callback_url', $callback_url);
        }

        protected static function get_logs_url()
        {
            return add_query_arg(
                array(
                    'page'   => 'wc-status',
                    'tab'    => 'logs',
                    'source' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        public static function get_settings_url()
        {
            return add_query_arg(
                array(
                    'page'    => 'wc-settings',
                    'tab'     => 'checkout',
                    'section' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        /**
         * @param string $message
         * @param string $level
         * @param array  $additional_context
         */
        protected function log($message, $level = WC_Log_Levels::DEBUG, $additional_context = null)
        {
            // https://developer.woocommerce.com/docs/best-practices/data-management/logging/
            // https://stackoverflow.com/questions/1423157/print-php-call-stack
            $log_context = array('source' => $this->id);
            if (!empty($additional_context)) {
                $log_context = array_merge($log_context, $additional_context);
            }

            $this->logger->log($level, $message, $log_context);
        }

        /**
         * @param int    $status_code
         * @param string $response_text
         */
        protected static function return_response($status_code, $response_text = null)
        {
            if (empty($response_text)) {
                $response_text = get_status_header_desc($status_code);
            }

            http_response_code($status_code);
            echo esc_html($response_text);
            exit;
        }
        //endregion

        //region Init
        public static function plugin_action_links($links)
        {
            $plugin_links = array(
                sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(self::get_settings_url()),
                    esc_html__('Settings', 'payment-gateway-wc-maib-mia')
                ),
            );

            return array_merge($plugin_links, $links);
        }

        /**
         * @param array $actions
         * @param \WC_Order $order
         */
        public static function order_actions($actions, $order)
        {
            if ($order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
                return $actions;
            }

            /* translators: 1: Payment method title */
            $actions[self::MOD_ACTION_CHECK_PAYMENT] = esc_html(sprintf(__('Check %1$s order payment', 'payment-gateway-wc-maib-mia'), self::MOD_TITLE));
            return $actions;
        }

        /**
         * @param \WC_Order $order
         */
        public static function action_check_payment($order)
        {
            $plugin = new self();
            return $plugin->check_payment($order);
        }

        public static function add_gateway($methods)
        {
            $methods[] = self::class;
            return $methods;
        }
        //endregion
    }

    //region Init payment gateway
    add_filter('woocommerce_payment_gateways', array(WC_Gateway_MAIB_MIA::class, 'add_gateway'));

    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Gateway_MAIB_MIA::class, 'plugin_action_links'));

        //Add WooCommerce order actions
        add_filter('woocommerce_order_actions', array(WC_Gateway_MAIB_MIA::class, 'order_actions'), 10, 2);
        add_action('woocommerce_order_action_' . WC_Gateway_MAIB_MIA::MOD_ACTION_CHECK_PAYMENT, array(WC_Gateway_MAIB_MIA::class, 'action_check_payment'));
    }
    //endregion
}

//region Declare WooCommerce compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // WooCommerce HPOS compatibility
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#declaring-extension-incompatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // WooCommerce Cart Checkout Blocks compatibility
            // https://github.com/woocommerce/woocommerce/pull/36426
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
);
//endregion

//region Register WooCommerce Blocks payment method type
add_action(
    'woocommerce_blocks_loaded',
    function () {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            require_once plugin_dir_path(__FILE__) . 'payment-gateway-wc-maib-mia-wbc.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_MAIB_MIA_WBC());
                }
            );
        }
    }
);
//endregion
