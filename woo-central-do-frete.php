<?php
/**
 * Plugin Name: Central do Frete para WooCommerce
 * Plugin URI: https://github.com/central-do-frete/CentraldoFrete-WooCommerce
 * Description: Cotação de frete em tempo real com múltiplas transportadoras via Central do Frete.
 * Author: Central do Frete
 * Author URI: https://centraldofrete.com
 * Version: 3.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPLv2
 * Text Domain: central-do-frete
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CDF_VERSION', '3.0.0' );
define( 'CDF_PLUGIN_FILE', __FILE__ );
define( 'CDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Initialize plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Central do Frete requer o WooCommerce instalado e ativo.', 'central-do-frete' )
			);
		} );
		return;
	}

	require_once CDF_PLUGIN_DIR . 'includes/class-cdf-loader.php';
	CDF_Loader::init();
} );
