<?php
/**
 * The plugin only runs inside WordPress, and the tests deliberately do not load it. The few
 * WordPress functions the units under test reach for are stubbed here, with the same contract
 * WordPress gives them, so a test failure means the plugin is wrong and not the harness.
 */

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

/**
 * `Cdfrete_Shipping_Method` extends a WooCommerce class, so the file cannot be loaded without
 * one. This stub exists only to make the file loadable: the tests call static helpers that
 * touch nothing on the parent, and nothing here is meant to imitate WooCommerce behaviour.
 */
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public $id                   = '';
		public $instance_id          = 0;
		public $method_title         = '';
		public $method_description   = '';
		public $supports             = [];
		public $title                = '';
		public $enabled              = 'yes';
		public $instance_form_fields = [];

		public function init_settings() {}

		public function get_option( $key, $empty_value = null ) {
			return $empty_value;
		}

		public function get_admin_options_html() {
			return '';
		}
	}
}
