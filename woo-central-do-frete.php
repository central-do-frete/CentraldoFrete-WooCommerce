<?php
/**
 * Plugin Name: WooCommerce Central do Frete
 * Plugin URI: https://github.com/buzzmage/woo-central-do-frete
 * Description: Módulo de cotações de frete da Central do Frete para WooCommerce
 * Author: Buzz e-Commerce
 * Author URI: http://www.sitedabuzz.com.br
 * Version: 1.0.0.0
 * License: GPLv2
 */

define('WOO_CENTRAL_BASE_PATH', plugin_dir_path(__FILE__));

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('Woo_CentralDoFrete_Default')) :

    /**
     * Central do Frete default class.
     */
    class Woo_CentralDoFrete_Default
    {
        /**
         * Plugin version.
         * @var string
         */
        const VERSION = '1.0.0';

        /**
         * Instance of this class.
         * @var object
         */
        protected static $instance = null;

        /**
         * Initialize the plugin
         */
        private function __construct()
        {
            // Checks with WooCommerce is installed.
            if (class_exists('WC_Integration')) {

                include_once WOO_CENTRAL_BASE_PATH . 'classes/class-wc-centraldofrete-helper.php';
                include_once WOO_CENTRAL_BASE_PATH . 'classes/class-wc-centraldofrete.php';

                add_filter('woocommerce_shipping_methods', array($this, 'wccentraldofrete_add_method'));
                add_action('woocommerce_product_options_shipping', array('Woo_CentralDoFrete', 'add_custom_shipping_option_to_products'));
                add_action('woocommerce_process_product_meta', array('Woo_CentralDoFrete', 'save_custom_field'));
            } else {
                add_action('admin_notices', array($this, 'wccentraldofrete_woocommerce_fallback_notice'));
            }

            if (!class_exists('SimpleXmlElement')) {
                add_action('admin_notices', 'wccentraldofrete_extensions_missing_notice');
            }

        }

        /**
         *
         * Return an instance of this class.
         * @return object A single instance of this class.
         */
        public static function get_instance()
        {
            // If the single instance hasn't been set, set it now.
            if (null === self::$instance) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * Get main file.
         * @return string
         */
        public static function get_main_file()
        {
            return __FILE__;
        }

        /**
         * Get plugin path.
         * @return string
         */
        public static function get_plugin_path()
        {
            return plugin_dir_path(__FILE__);
        }

        /**
         * Add the Central do Frete to shipping methods.
         * @param array $methods
         * @return array
         */
        function wccentraldofrete_add_method($methods)
        {
            $methods['centraldofrete'] = 'Woo_CentralDoFrete';

            return $methods;
        }

    }

    add_action('plugins_loaded', array('Woo_CentralDoFrete_Default', 'get_instance'));

endif;
