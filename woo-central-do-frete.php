<?php
/**
 * Plugin Name: WooCommerce Central do Frete
 * Plugin URI: https://github.com/central-do-frete/CentraldoFrete-WooCommerce
 * Description: Módulo de cotações de frete da Central do Frete para WooCommerce
 * Author: Central do Frete
 * Author URI: https://centraldofrete.com
 * Version: 2.0.4
 * License: GPLv2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce_CentralDoFrete_Main' ) ) :

	/**
	 * Central do Frete main class.
	 */
	class WooCommerce_CentralDoFrete_Main {
		/**
		 * Plugin version.
		 * @var string
		 */
		const VERSION = '2.0.1';

		/**
		 * Instance of this class.
		 * @var object
		 */
		protected static $instance = null;

		/**
		 * Initialize the plugin
		 */
		private function __construct() {
			$this->initialize();
		}

		/**
		 * Verify if all plugin dependencies are available
		 * @return bool
		 */
		function verifyRequirements() {
			if ( ! class_exists( 'WC_Integration' ) ) {
				add_action( 'admin_notices', array( $this, 'missingWooCommerceNotice' ) );

				return false;
			}

			return true;
		}

		/**
		 * Show message when WooCommerce aren't installed
		 */
		function missingWooCommerceNotice() {
			$class = 'notice notice-warning';
			$message = __( 'Desculpe, o plugin Central do Frete necessita do WooCommerce. Por favor, instalte o plugin WooCommerce.' );
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}

		/**
		 * Initialize plugin
		 */
		public function initialize() {
			if ( $this->verifyRequirements() ) {
				$this->loadDependencies();
				$this->addActions();
			}
		}

		/**
		 * Add this plugin to shipping methods - addCentralDoFreteMethod
		 * Add product custom attributes - addCustomShippingOptionToProductForm
		 * Add listener when save product with custom attributes - saveCustomField
		 */
		public function addActions() {
			add_filter( 'woocommerce_shipping_methods', array( $this, 'addCentralDoFreteMethod' ) );
			add_action( 'woocommerce_product_options_shipping', array(
				'WooCommerce_CentralDoFrete_Method',
				'addCustomShippingOptionToProductForm'
			) );
			add_action( 'woocommerce_process_product_meta', array(
				'WooCommerce_CentralDoFrete_Method',
				'saveCustomField'
			) );
		}

		/**
		 * Load plugins classes
		 */
		public function loadDependencies() {
			include( self::getPluginPath() . 'classes/WooCentralDoFrete.php' );
			include( self::getPluginPath() . 'classes/Helper.php' );
		}

		/**
		 *
		 * Return an instance of this class.
		 * @return object A single instance of this class.
		 */
		public static function getInstance() {
			// If the single instance hasn't been set, set it now.
			if ( null === self::$instance ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Get plugin path.
		 * @return string
		 */
		public static function getPluginPath() {
			return plugin_dir_path( __FILE__ );
		}

		/**
		 * Add the Central do Frete to shipping methods.
		 *
		 * @param array $methods
		 *
		 * @return array
		 */
		function addCentralDoFreteMethod( $methods ) {
			$methods['centraldofrete'] = 'WooCommerce_CentralDoFrete_Method';

			return $methods;
		}

		/**
		 * Output a message or error
		 *
		 * @param string $message
		 * @param string $type
		 */
		public function debug( $message, $type = 'notice' ) {
			if ( $this->debug && ! is_admin() ) {
				if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
					wc_add_notice( $message, $type );
				} else {
					global $woocommerce;
					$woocommerce->add_message( $message );
				}
			}
		}

	}

	add_action( 'plugins_loaded', array( 'WooCommerce_CentralDoFrete_Main', 'getInstance' ) );

endif;
