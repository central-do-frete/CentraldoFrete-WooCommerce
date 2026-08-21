<?php

use PHPUnit\Framework\TestCase;

class ShippingClassRuleTest extends TestCase {

	private const MARCENARIA = '10';
	private const VIDRO      = '20';
	private const PEQUENOS   = '30';

	public function test_rule_all_ignores_the_selection(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'all', [ self::MARCENARIA ], false )
		);
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'all', [ self::MARCENARIA ], true )
		);
	}

	public function test_include_without_strict_needs_one_matching_product(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::PEQUENOS ], 'include', [ self::MARCENARIA ], false )
		);
	}

	public function test_include_without_strict_rejects_a_cart_with_no_matching_product(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'include', [ self::MARCENARIA, self::VIDRO ], false )
		);
	}

	public function test_include_with_strict_needs_every_product_to_match(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::VIDRO ], 'include', [ self::MARCENARIA, self::VIDRO ], true )
		);
	}

	public function test_include_with_strict_rejects_a_mixed_cart(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::PEQUENOS ], 'include', [ self::MARCENARIA ], true )
		);
	}

	public function test_exclude_without_strict_hides_only_when_the_whole_cart_is_excluded(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'exclude', [ self::PEQUENOS ], false )
		);
	}

	public function test_exclude_without_strict_keeps_a_mixed_cart(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::PEQUENOS ], 'exclude', [ self::PEQUENOS ], false )
		);
	}

	public function test_exclude_with_strict_hides_a_mixed_cart(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::PEQUENOS ], 'exclude', [ self::PEQUENOS ], true )
		);
	}

	public function test_exclude_with_strict_keeps_a_cart_with_nothing_excluded(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA ], 'exclude', [ self::PEQUENOS ], true )
		);
	}

	public function test_empty_selection_behaves_like_rule_all(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'include', [], true )
		);
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'exclude', [], true )
		);
	}

	public function test_cart_without_shippable_products_is_allowed(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [], 'include', [ self::MARCENARIA ], true )
		);
	}

	public function test_products_without_a_class_can_be_targeted(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ CDF_Shipping_Class_Rule::NO_CLASS ], 'exclude', [ CDF_Shipping_Class_Rule::NO_CLASS ], false )
		);
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ CDF_Shipping_Class_Rule::NO_CLASS ], 'include', [ CDF_Shipping_Class_Rule::NO_CLASS ], true )
		);
	}

	public function test_selection_pointing_to_a_deleted_class_matches_nothing(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'include', [ '999' ], false )
		);
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'exclude', [ '999' ], true )
		);
	}

	public function test_unknown_rule_behaves_like_rule_all(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS ], 'whatever', [ self::MARCENARIA ], true )
		);
	}

	public function test_integer_and_string_class_keys_are_the_same_class(): void {
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ 30 ], 'exclude', [ '30' ], false )
		);
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ '30' ], 'include', [ 30 ], true )
		);
	}

	public function test_repeated_classes_do_not_change_the_decision(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows( [ self::MARCENARIA, self::MARCENARIA ], 'include', [ self::MARCENARIA ], true )
		);
		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows( [ self::PEQUENOS, self::PEQUENOS ], 'exclude', [ self::PEQUENOS ], false )
		);
	}

	public function test_settings_defaults_keep_the_method_available(): void {
		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows_for_settings( [ self::PEQUENOS ], [] )
		);
	}

	public function test_package_reports_one_key_per_distinct_class(): void {
		$package = [
			'contents' => [
				[ 'data' => new FakeProduct( self::MARCENARIA ) ],
				[ 'data' => new FakeProduct( self::PEQUENOS ) ],
				[ 'data' => new FakeProduct( self::MARCENARIA ) ],
			],
		];

		$this->assertSame(
			[ self::MARCENARIA, self::PEQUENOS ],
			CDF_Shipping_Class_Rule::classes_from_package( $package )
		);
	}

	public function test_package_maps_products_without_a_class(): void {
		$package = [ 'contents' => [ [ 'data' => new FakeProduct( 0 ) ] ] ];

		$this->assertSame(
			[ CDF_Shipping_Class_Rule::NO_CLASS ],
			CDF_Shipping_Class_Rule::classes_from_package( $package )
		);
	}

	public function test_package_skips_products_that_do_not_need_shipping(): void {
		$package = [
			'contents' => [
				[ 'data' => new FakeProduct( self::MARCENARIA, false ) ],
				[ 'data' => new FakeProduct( self::PEQUENOS ) ],
			],
		];

		$this->assertSame(
			[ self::PEQUENOS ],
			CDF_Shipping_Class_Rule::classes_from_package( $package )
		);
	}

	public function test_package_without_contents_reports_no_class(): void {
		$this->assertSame( [], CDF_Shipping_Class_Rule::classes_from_package( [] ) );
	}

	public function test_settings_are_read_with_the_documented_keys(): void {
		$settings = [
			'shipping_class_rule'   => 'exclude',
			'shipping_classes'      => [ self::PEQUENOS ],
			'shipping_class_strict' => 'yes',
		];

		$this->assertFalse(
			CDF_Shipping_Class_Rule::allows_for_settings( [ self::MARCENARIA, self::PEQUENOS ], $settings )
		);

		$settings['shipping_class_strict'] = 'no';

		$this->assertTrue(
			CDF_Shipping_Class_Rule::allows_for_settings( [ self::MARCENARIA, self::PEQUENOS ], $settings )
		);
	}
}

/**
 * Stands in for WC_Product: the rule only ever asks these two questions.
 */
class FakeProduct {

	/** @var int|string */
	private $shipping_class_id;

	private bool $needs_shipping;

	/**
	 * @param int|string $shipping_class_id
	 */
	public function __construct( $shipping_class_id, bool $needs_shipping = true ) {
		$this->shipping_class_id = $shipping_class_id;
		$this->needs_shipping    = $needs_shipping;
	}

	public function get_shipping_class_id() {
		return $this->shipping_class_id;
	}

	public function needs_shipping(): bool {
		return $this->needs_shipping;
	}
}
