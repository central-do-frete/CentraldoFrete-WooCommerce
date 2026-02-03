<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDF_Loader {

	private static bool $loaded = false;

	public static function init(): void {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		self::load_files();
		self::register_hooks();
	}

	private static function load_files(): void {
		require_once CDF_PLUGIN_DIR . 'includes/class-cdf-cache.php';
		require_once CDF_PLUGIN_DIR . 'includes/class-cdf-api-client.php';
		require_once CDF_PLUGIN_DIR . 'includes/class-cdf-shipping-method.php';
		require_once CDF_PLUGIN_DIR . 'includes/class-cdf-product-fields.php';
		require_once CDF_PLUGIN_DIR . 'includes/class-cdf-frontend-calculator.php';
	}

	private static function register_hooks(): void {
		// Register shipping method.
		add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
			$methods['centraldofrete'] = 'CDF_Shipping_Method';
			return $methods;
		} );

		// Product fields.
		CDF_Product_Fields::init();

		// Frontend calculator.
		CDF_Frontend_Calculator::init();

		// Admin AJAX handlers (must be registered globally, not just when shipping method is instantiated).
		add_action( 'wp_ajax_cdf_refresh_cargo_types', [ 'CDF_Shipping_Method', 'ajax_refresh_cargo_types' ] );

		// Display carrier logo in shipping label.
		add_filter( 'woocommerce_cart_shipping_method_full_label', [ __CLASS__, 'add_carrier_logo_to_label' ], 10, 2 );
	}

	/**
	 * Add carrier logo to shipping method label in cart/checkout.
	 *
	 * @param string $label   The shipping method label.
	 * @param object $method  The shipping method object (WC_Shipping_Rate).
	 * @return string
	 */
	public static function add_carrier_logo_to_label( string $label, $method ): string {
		// Only for Central do Frete methods.
		if ( strpos( $method->get_id(), 'centraldofrete' ) === false ) {
			return $label;
		}

		// Check if logo is in meta data.
		$meta_data = $method->get_meta_data();
		$logo_url  = $meta_data['CDF_LOGO'] ?? '';

		if ( empty( $logo_url ) ) {
			return $label;
		}

		// Build logo HTML.
		$logo_html = sprintf(
			'<img src="%s" alt="" class="cdf-carrier-logo" style="width: 50px; height: 20px; object-fit: contain; vertical-align: middle; margin-right: 8px; background: #fff; border-radius: 2px;" />',
			esc_url( $logo_url )
		);

		return $logo_html . $label;
	}
}
