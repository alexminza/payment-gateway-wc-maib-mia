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
    class WC_MAIB_MIA extends WC_Payment_Gateway {}

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
