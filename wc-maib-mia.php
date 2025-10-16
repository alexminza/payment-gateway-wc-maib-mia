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

        const MAIB_TRANS_ID        = 'trans_id';
        const MAIB_TRANSACTION_ID  = 'TRANSACTION_ID';

        const MAIB_ERROR           = 'error';
        #endregion

        protected $testmode, $debug, $logger, $transaction_type, $order_template;
        protected $maib_mia_base_url, $maib_mia_client_id, $maib_mia_client_secret, $maib_mia_signature_key;

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

            $plugin_dir               = plugin_dir_url(__FILE__);
            $this->icon               = apply_filters('woocommerce_maib_mia_icon', "{$plugin_dir}assets/img/mia_instant_mobile.svg");

            $this->testmode           = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->debug              = wc_string_to_bool($this->get_option('debug', 'no'));
            $this->logger             = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

            if ($this->testmode)
                $this->description = $this->get_test_message($this->description);

            $this->order_template     = $this->get_option('order_template', self::ORDER_TEMPLATE);

            #https://github.com/alexminza/maib-mia-sdk-php/blob/v1.0.0/src/MaibMia/MaibMiaClient.php
            $this->maib_mia_base_url  = $this->testmode ? MaibMiaClient::SANDBOX_BASE_URL : MaibMiaClient::DEFAULT_BASE_URL;

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
                    'description' => sprintf(
                        '<b>%1$s:</b> <code>%2$s</code>',
                        esc_html__('Callback URL', 'wc-maib-mia'),
                        esc_url($this->get_callback_url())
                    ),
                    'type'        => 'title'
                )
            );
        }

        public function is_valid_for_use()
        {
            if (!in_array(get_option('woocommerce_currency'), self::SUPPORTED_CURRENCIES)) {
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
                    esc_html(get_option('woocommerce_currency')),
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

        #region Utility
        protected function get_test_message($message)
        {
            if ($this->testmode)
                $message = sprintf(esc_html__('TEST: %1$s', 'wc-maib-mia'), esc_html($message));

            return $message;
        }

        protected static function get_language()
        {
            $lang = get_locale();
            return substr($lang, 0, 2);
        }

        protected static function get_client_ip()
        {
            return WC_Geolocation::get_ip_address();
        }

        protected function get_callback_url()
        {
            //https://developer.woo.com/docs/woocommerce-plugin-api-callbacks/
            return WC()->api_request_url("wc_{$this->id}");
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
