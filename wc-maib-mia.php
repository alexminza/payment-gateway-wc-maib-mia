<?php

/**
 * Plugin Name: maib MIA Payment Gateway for WooCommerce
 * Description: Accept MIA payments directly on your store with the maib MIA Payment Gateway for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-maib-mia
 * Version: 1.0.0-dev
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-maib-mia
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.8
 * WC requires at least: 3.3
 * WC tested up to: 10.2.2
 * Requires Plugins: woocommerce
 */

//Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-maib-mia
//This plugin is based on PHP SDK for maib MIA API https://github.com/alexminza/maib-mia-sdk-php (https://packagist.org/packages/alexminza/maib-mia-sdk)

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';

use Maib\MaibMia\MaibMiaClient;

add_action('plugins_loaded', 'woocommerce_maib_mia_plugins_loaded', 0);

function woocommerce_maib_mia_plugins_loaded()
{
    load_plugin_textdomain('wc-maib-mia', false, dirname(plugin_basename(__FILE__)) . '/languages');

    //https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'woocommerce_maib_mia_missing_wc_notice');
        return;
    }

    woocommerce_maib_mia_init();
}

function woocommerce_maib_mia_missing_wc_notice()
{
    echo sprintf('<div class="notice notice-error is-dismissible"><p>%1$s</p></div>', esc_html__('maib MIA Payment Gateway requires WooCommerce to be installed and active.', 'wc-maib-mia'));
}

function woocommerce_maib_mia_init()
{
    class WC_MAIB_MIA extends WC_Payment_Gateway
    {
        #region Constants
        const MOD_ID             = 'maib_mia';
        const MOD_TITLE          = 'maib MIA';
        const MOD_PREFIX         = 'maib_mia_';

        const SUPPORTED_CURRENCIES = ['MDL'];
        const ORDER_TEMPLATE       = 'Order #%1$s';

        const MOD_QR_ID            =  self::MOD_PREFIX . 'qr_id';
        const MOD_QR_URL           =  self::MOD_PREFIX . 'qr_url';
        const MOD_PAY_ID           =  self::MOD_PREFIX . 'pay_id';
        const MOD_CALLBACK         =  self::MOD_PREFIX . 'callback';

        const DEFAULT_TIMEOUT  = 15; //seconds
        const DEFAULT_VALIDITY = 15; //minutes
        #endregion

        protected $testmode, $debug, $logger, $transaction_type, $order_template, $transaction_validity;
        protected $maib_mia_base_url, $maib_mia_callback_url, $maib_mia_client_id, $maib_mia_client_secret, $maib_mia_signature_key;

        public function __construct()
        {
            $this->id                 = self::MOD_ID;
            $this->method_title       = self::MOD_TITLE;
            $this->method_description = 'maib MIA Payment Gateway for WooCommerce';
            $this->has_fields         = false;
            $this->supports           = array('products', 'refunds');

            #region Initialize user set variables
            $this->enabled            = $this->get_option('enabled', 'no');
            $this->title              = $this->get_option('title', $this->method_title);
            $this->description        = $this->get_option('description');
            $this->icon               = apply_filters('woocommerce_maib_mia_icon', plugin_dir_url(__FILE__) . 'assets/img/mia.svg');

            $this->testmode           = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->debug              = wc_string_to_bool($this->get_option('debug', 'no'));
            $this->logger             = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

            if ($this->testmode)
                $this->description = $this->get_test_message($this->description);

            $this->order_template       = $this->get_option('order_template', self::ORDER_TEMPLATE);
            $this->transaction_validity = intval($this->get_option('transaction_validity', self::DEFAULT_VALIDITY));

            #https://github.com/alexminza/maib-mia-sdk-php/blob/v1.0.0/src/MaibMia/MaibMiaClient.php
            $this->maib_mia_base_url  = $this->testmode ? MaibMiaClient::SANDBOX_BASE_URL : MaibMiaClient::DEFAULT_BASE_URL;
            $this->maib_mia_callback_url  = $this->get_option('maib_mia_callback_url', $this->get_callback_url());

            $this->maib_mia_client_id     = $this->get_option('maib_mia_client_id');
            $this->maib_mia_client_secret = $this->get_option('maib_mia_client_secret');
            $this->maib_mia_signature_key = $this->get_option('maib_mia_signature_key');

            $this->init_form_fields();
            $this->init_settings();
            #endregion

            if (is_admin())
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));

            add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled'         => array(
                    'title'       => __('Enable/Disable', 'wc-maib-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable this gateway', 'wc-maib-mia'),
                    'default'     => 'yes'
                ),
                'title'           => array(
                    'title'       => __('Title', 'wc-maib-mia'),
                    'type'        => 'text',
                    'description' => __('Payment method title that the customer will see during checkout.', 'wc-maib-mia'),
                    'desc_tip'    => true,
                    'default'     => self::MOD_TITLE
                ),
                'description'     => array(
                    'title'       => __('Description', 'wc-maib-mia'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description that the customer will see during checkout.', 'wc-maib-mia'),
                    'desc_tip'    => true,
                    'default'     => ''
                ),

                'testmode'        => array(
                    'title'       => __('Test mode', 'wc-maib-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enabled', 'wc-maib-mia'),
                    'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'wc-maib-mia'),
                    'desc_tip'    => true,
                    'default'     => 'no'
                ),
                'debug'           => array(
                    'title'       => __('Debug mode', 'wc-maib-mia'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable logging', 'wc-maib-mia'),
                    'default'     => 'no',
                    'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'wc-maib-mia'), esc_url(self::get_logs_url())),
                    'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'wc-maib-mia')
                ),

                'order_template'  => array(
                    'title'       => __('Order description', 'wc-maib-mia'),
                    'type'        => 'text',
                    'description' => __('Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', 'wc-maib-mia'),
                    'desc_tip'    => __('Order description that the customer will see on the bank payment page.', 'wc-maib-mia'),
                    'default'     => self::ORDER_TEMPLATE
                ),
                'transaction_validity'  => array(
                    'title'       => __('Transaction validity', 'wc-maib-mia'),
                    'type'        => 'decimal',
                    'description' => __('Transaction validity in minutes', 'wc-maib-mia'),
                    'desc_tip'    => true,
                    'default'     => self::DEFAULT_VALIDITY
                ),

                'connection_settings' => array(
                    'title'       => __('Connection Settings', 'wc-maib-mia'),
                    'description' => __('Payment gateway connection credentials are provided by the bank.', 'wc-maib-mia'),
                    'type'        => 'title'
                ),
                'maib_mia_client_id' => array(
                    'title'       => __('Client ID', 'wc-maib-mia'),
                    'type'        => 'text',
                ),
                'maib_mia_client_secret' => array(
                    'title'       => __('Client Secret', 'wc-maib-mia'),
                    'type'        => 'password',
                ),
                'maib_mia_signature_key' => array(
                    'title'       => __('Signature Key', 'wc-maib-mia'),
                    'type'        => 'password',
                ),

                'payment_notification' => array(
                    'title'       => __('Payment Notification', 'wc-maib-mia'),
                    'type'        => 'title'
                ),
                'maib_mia_callback_url' => array(
                    'title'       => __('Callback URL', 'wc-maib-mia'),
                    'type'        => 'text',
                    'description' => sprintf('<code>%1$s</code>', esc_url($this->get_callback_url())),
                    'default'     => $this->get_callback_url()
                ),
            );
        }

        public function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), self::SUPPORTED_CURRENCIES)) {
                return false;
            }

            return true;
        }

        public function is_available()
        {
            if (!$this->is_valid_for_use())
                return false;

            if (!$this->check_settings())
                return false;

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

        protected function check_settings()
        {
            return !self::string_empty($this->maib_mia_client_id)
                && !self::string_empty($this->maib_mia_client_secret)
                && !self::string_empty($this->maib_mia_signature_key);
        }

        protected function validate_settings()
        {
            $validate_result = true;

            if (!$this->is_valid_for_use()) {
                $this->add_error(sprintf(
                    '<strong>%1$s: %2$s</strong>. %3$s: %4$s',
                    esc_html__('Unsupported store currency', 'wc-maib-mia'),
                    esc_html(get_woocommerce_currency()),
                    esc_html__('Supported currencies', 'wc-maib-mia'),
                    esc_html(join(', ', self::SUPPORTED_CURRENCIES))
                ));

                $validate_result = false;
            }

            if (!$this->check_settings()) {
                $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'wc-maib-mia'), 'https://wordpress.org/plugins/wc-maib-mia/#installation');
                $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'wc-maib-mia'), esc_html__('Not configured', 'wc-maib-mia'), wp_kses_post($message_instructions)));
                $validate_result = false;
            }

            return $validate_result;
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
            WC_Admin_Notices::add_custom_notice(self::MOD_ID . '_logs_admin_notice', $message);
        }

        protected function settings_admin_notice()
        {
            $message = $this->get_settings_admin_message();
            WC_Admin_Notices::add_custom_notice(self::MOD_ID . '_settings_admin_notice', $message);
        }

        protected function get_settings_admin_message()
        {
            $message = sprintf(wp_kses_post(__('%1$s is not properly configured. Verify plugin <a href="%2$s">Connection Settings</a>.', 'wc-maib-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
        }

        protected function get_logs_admin_message()
        {
            $message = sprintf(wp_kses_post(__('See <a href="%2$s">%1$s settings</a> page for log details and setup instructions.', 'wc-maib-mia')), esc_html($this->method_title), esc_url(self::get_settings_url()));
            return $message;
        }

        #region Payment
        protected function init_maib_mia_client()
        {
            $options = [
                'base_uri' => $this->maib_mia_base_url,
                'timeout' => self::DEFAULT_TIMEOUT
            ];

            if ($this->debug) {
                $logName = self::MOD_ID . '_guzzle';
                $logFileName = WC_Log_Handler_File::get_log_file_path($logName);

                $log = new \Monolog\Logger($logName);
                $log->pushHandler(new \Monolog\Handler\StreamHandler($logFileName, \Monolog\Logger::DEBUG));

                $stack = \GuzzleHttp\HandlerStack::create();
                $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

                $options['handler'] = $stack;
            }

            $guzzleClient = new \GuzzleHttp\Client($options);
            $client = new MaibMiaClient($guzzleClient);

            return $client;
        }

        /**
         * @param MaibMiaClient $client
         */
        private function maib_mia_generate_token($client)
        {
            $tokenResponse = $client->getToken($this->maib_mia_client_id, $this->maib_mia_client_secret);
            $accessToken = $tokenResponse['result']['accessToken'];

            return $accessToken;
        }

        /**
         * @param MaibMiaClient $client
         * @param string $token
         * @param string $order_id
         * @param string $order_name
         * @param float  $total_amount
         * @param string $currency
         * @param string $callback_url
         * @param string $redirect_url
         * @param int    $validity_minutes
         */
        private function maib_mia_pay($client, $token, $order_id, $order_name, $total_amount, $currency, $callback_url, $redirect_url, $validity_minutes)
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
                'redirectUrl' => $redirect_url
            );

            return $client->createQr($qr_data, $token);
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();
            $order_description = $this->get_order_description($order);
            $callback_url = $this->maib_mia_callback_url;
            $redirect_url = $this->get_redirect_url($order);
            $create_qr_response = null;

            try {
                $client = $this->init_maib_mia_client();
                $token = $this->maib_mia_generate_token($client);

                $create_qr_response = $this->maib_mia_pay($client, $token, $order_id, $order_description, $order_total, $order_currency, $callback_url, $redirect_url, $this->transaction_validity);
                $this->log(self::print_var($create_qr_response));
            } catch (Exception $ex) {
                $this->log($ex, WC_Log_Levels::ERROR);
            }

            if (!empty($create_qr_response)) {
                $create_qr_response_ok = $create_qr_response['ok'];
                if ($create_qr_response_ok) {
                    $create_qr_response_result = $create_qr_response['result'];
                    $qr_id = $create_qr_response_result['qrId'];
                    $qr_url = $create_qr_response_result['url'];

                    #region Update order payment transaction metadata
                    //https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#apis-for-gettingsetting-posts-and-postmeta
                    //https://developer.woocommerce.com/docs/hpos-extension-recipe-book/#2-supporting-high-performance-order-storage-in-your-extension
                    $order->add_meta_data(self::MOD_QR_ID, $qr_id, true);
                    $order->add_meta_data(self::MOD_QR_URL, $qr_url, true);
                    $order->save();
                    #endregion

                    $message = sprintf(esc_html__('Payment initiated via %1$s: %2$s', 'wc-maib-mia'), esc_html($this->method_title), esc_html(self::print_response_object($create_qr_response)));
                    $message = $this->get_test_message($message);
                    $this->log($message, WC_Log_Levels::INFO);
                    $order->add_order_note($message);

                    return array(
                        'result'   => 'success',
                        'redirect' => $qr_url
                    );
                }
            }

            $message = sprintf(esc_html__('Payment initiation failed via %1$s: %2$s', 'wc-maib-mia'), esc_html($this->method_title), esc_html(self::print_response_object($create_qr_response)));
            $message = $this->get_test_message($message);
            $order->add_order_note($message);
            $this->log($message, WC_Log_Levels::ERROR);

            $message = sprintf(esc_html__('Order #%1$s payment initiation failed via %2$s.', 'wc-maib-mia'), esc_html($order_id), esc_html($this->method_title));

            //https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
            $is_store_api_request = method_exists(WC(), 'is_store_api_request') && WC()->is_store_api_request();
            if ($is_store_api_request) {
                throw new Exception($message);
            }

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            return array(
                'result'   => 'failure',
                'messages' => $message
            );
        }

        public function check_response()
        {
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $message = sprintf(esc_html__('This %1$s Callback URL works and should not be called directly.', 'wc-maib-mia'), esc_html($this->method_title));
                wc_add_notice($message, 'notice');

                wp_safe_redirect(wc_get_cart_url());
                return false;
            }

            #region Validate callback
            try {
                $callback_body = file_get_contents('php://input');
                $this->log(sprintf(esc_html__('Payment notification callback: %1$s', 'wc-maib-mia'), self::print_var($callback_body)));

                $callback_data = json_decode($callback_body, true);
                $validation_result = MaibMiaClient::validateCallbackSignature($callback_data, $this->maib_mia_signature_key);
            } catch (Exception $ex) {
                $this->log($ex, WC_Log_Levels::ERROR);
                wp_die(get_status_header_desc(WP_Http::INTERNAL_SERVER_ERROR), WP_Http::INTERNAL_SERVER_ERROR);
                throw $ex;
            }

            if (!$validation_result) {
                $message = sprintf(esc_html__('%1$s callback signature validation failed.', 'wc-maib-mia'), esc_html($this->method_title));
                $this->log($message, WC_Log_Levels::ERROR);

                wp_die('Invalid callback signature', WP_Http::UNAUTHORIZED);
                return false;
            }
            #endregion

            #region Validate order ID
            $callback_data_result = $callback_data['result'];
            $callback_order_id = intval($callback_data_result['orderId']);
            $order = wc_get_order($callback_order_id);

            if (!$order) {
                $message = sprintf(esc_html__('Order not found by Order ID: %1$d received from %2$s.', 'wc-maib-mia'), $callback_order_id, esc_html($this->method_title));
                $this->log($message, WC_Log_Levels::ERROR);

                wp_die('Order not found', WP_Http::UNPROCESSABLE_ENTITY);
                return false;
            }
            #endregion

            $callback_qr_status = strval($callback_data_result['qrStatus']);
            if (strtolower($callback_qr_status) === 'paid') {
                #region Check order data
                $callback_amount = floatval($callback_data_result['amount']);
                $callback_currency = strval($callback_data_result['currency']);

                $order_total = $order->get_total();
                $order_currency = $order->get_currency();

                if ($order_total !== $callback_amount || $order_currency !== $callback_currency) {
                    $message = sprintf(esc_html__('Order amount mismatch: Callback %1$f %2$s, Order %3$f %4$s.', 'wc-maib-mia'), $callback_amount, $callback_currency, $order_total, $order_currency);
                    $this->log($message, WC_Log_Levels::ERROR);

                    wp_die('Order data mismatch', WP_Http::UNPROCESSABLE_ENTITY);
                    return false;
                }

                if ($order->is_paid()) {
                    $message = sprintf(esc_html__('Callback order already fully paid: %1$d.', 'wc-maib-mia'), $callback_order_id);
                    $this->log($message, WC_Log_Levels::ERROR);

                    wp_die('Order already fully paid', WP_Http::OK);
                    return false;
                }
                #endregion

                #region Complete order payment
                $callback_pay_id = strval($callback_data_result['payId']);
                $callback_reference_id = strval($callback_data_result['referenceId']);

                $order->add_meta_data(self::MOD_CALLBACK, $callback_body, true);
                $order->add_meta_data(self::MOD_PAY_ID, $callback_pay_id, true);
                $order->save();

                $order->payment_complete($callback_reference_id);
                #endergion

                $message = sprintf(esc_html__('Payment completed via %1$s: %2$s', 'wc-maib-mia'), esc_html($this->method_title), esc_html($callback_body));
                $message = $this->get_test_message($message);
                $this->log($message, WC_Log_Levels::INFO);
                $order->add_order_note($message);
                return true;
            }
        }

        public function process_refund($order_id, $amount = null, $reason = '') {}
        #endregion

        #region Order
        protected static function get_order_net_total($order)
        {
            //https://github.com/woocommerce/woocommerce/issues/17795
            //https://github.com/woocommerce/woocommerce/pull/18196
            $total_refunded = 0;
            $order_refunds = $order->get_refunds();
            foreach ($order_refunds as $refund) {
                if ($refund->get_refunded_payment())
                    $total_refunded += $refund->get_amount();
            }

            $order_total = $order->get_total();
            return $order_total - $total_refunded;
        }

        protected static function get_order_pay_id($order)
        {
            //https://woocommerce.github.io/code-reference/classes/WC-Data.html#method_get_meta
            $pay_id = $order->get_meta(self::MOD_PAY_ID, true);
            return $pay_id;
        }

        protected function get_order_description($order)
        {
            $description = sprintf(
                $this->order_template,
                $order->get_id(),
                self::get_order_items_summary($order)
            );

            return apply_filters(self::MOD_ID . '_order_description', $description, $order);
        }

        protected static function get_order_items_summary($order)
        {
            $items = $order->get_items();
            $items_names = array_map(function ($item) {
                return $item->get_name();
            }, $items);

            return join(', ', $items_names);
        }
        #endregion

        #region Utility
        protected function get_test_message($message)
        {
            if ($this->testmode)
                $message = sprintf(esc_html__('TEST: %1$s', 'wc-maib-mia'), esc_html($message));

            return $message;
        }

        protected function get_redirect_url($order)
        {
            $redirectUrl = $this->get_return_url($order);
            return apply_filters(self::MOD_ID . '_redirect_url', $redirectUrl);
        }

        protected function get_callback_url()
        {
            //https://developer.woo.com/docs/woocommerce-plugin-api-callbacks/
            $callbackUrl = WC()->api_request_url("wc_{$this->id}");
            return apply_filters(self::MOD_ID . '_callback_url', $callbackUrl);
        }

        protected static function get_logs_url()
        {
            return add_query_arg(
                array(
                    'page'   => 'wc-status',
                    'tab'    => 'logs',
                    'source' => self::MOD_ID
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
                    'section' => self::MOD_ID
                ),
                admin_url('admin.php')
            );
        }

        protected function log($message, $level = WC_Log_Levels::DEBUG)
        {
            //https://developer.woo.com/docs/logging-in-woocommerce/
            //https://stackoverflow.com/questions/1423157/print-php-call-stack
            $log_context = array('source' => self::MOD_ID);
            $this->logger->log($level, $message, $log_context);
        }

        protected static function static_log($message, $level = WC_Log_Levels::DEBUG)
        {
            $logger = wc_get_logger();
            $log_context = array('source' => self::MOD_ID);
            $logger->log($level, $message, $log_context);
        }

        protected static function print_var($var)
        {
            //https://woocommerce.github.io/code-reference/namespaces/default.html#function_wc_print_r
            return wc_print_r($var, true);
        }

        /**
         * @param GuzzleHttp\Command\Result $response
         */
        protected static function print_response_object($response)
        {
            if ($response)
                return json_encode($response->toArray());

            return '';
        }

        protected static function string_empty($string)
        {
            return is_null($string) || strlen($string) === 0;
        }
        #endregion

        #region Admin
        public static function plugin_links($links)
        {
            $plugin_links = array(
                sprintf('<a href="%1$s">%2$s</a>', esc_url(self::get_settings_url()), esc_html__('Settings', 'wc-maib-mia'))
            );

            return array_merge($plugin_links, $links);
        }
        #endregion

        #region WooCommerce
        public static function add_gateway($methods)
        {
            $methods[] = self::class;
            return $methods;
        }
        #endregion
    }

    //Add gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', array(WC_MAIB_MIA::class, 'add_gateway'));

    #region Admin init
    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_MAIB_MIA::class, 'plugin_links'));
    }
    #endregion
}

#region Declare WooCommerce compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        //WooCommerce HPOS compatibility
        //https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#declaring-extension-incompatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

        //WooCommerce Cart Checkout Blocks compatibility
        //https://github.com/woocommerce/woocommerce/pull/36426
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
#endregion

#region Register WooCommerce Blocks payment method type
add_action('woocommerce_blocks_loaded', function () {
    if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        require_once plugin_dir_path(__FILE__) . 'wc-maib-mia-wbc.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_MAIB_MIA_WBC());
            }
        );
    }
});
#endregion
