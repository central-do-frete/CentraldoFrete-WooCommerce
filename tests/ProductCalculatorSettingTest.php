<?php

use PHPUnit\Framework\TestCase;

/**
 * The product page calculator switch lives on the shipping method instance, so it belongs to
 * one shipping zone. It used to be read only where the widget is drawn, over every enabled
 * instance, so a zone with it turned off still answered with its own prices as soon as another
 * zone had it on. It is now the same reading in both places: the zone the shopper's postcode
 * falls into decides whether it answers at all.
 */
class ProductCalculatorSettingTest extends TestCase {

	public function test_a_zone_with_the_calculator_on_offers_it(): void {
		$this->assertTrue( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'product_calculator' => 'yes' ] ) );
	}

	public function test_a_zone_with_the_calculator_off_does_not_offer_it(): void {
		$this->assertFalse( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'product_calculator' => 'no' ] ) );
	}

	/**
	 * A zone saved before the field existed has no value for it, and the field defaults to on,
	 * so it has to keep quoting rather than fall silent on update.
	 */
	public function test_a_zone_saved_before_the_setting_existed_keeps_offering_it(): void {
		$this->assertTrue( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'token' => 'abc' ] ) );
	}

	public function test_only_the_stored_yes_counts_as_on(): void {
		$this->assertFalse( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'product_calculator' => '' ] ) );
	}

	/**
	 * "Ativar método de entrega" used to sit beside this one and was removed in 3.3.0: it fed a
	 * property WooCommerce overwrites from the zone method row, so it governed nothing anywhere.
	 * Stores that saved it keep the row, and it must stay a value nobody reads - acting on it
	 * now would switch off a zone that quotes today.
	 */
	public function test_the_removed_method_checkbox_does_not_affect_the_calculator_switch(): void {
		$this->assertTrue( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'enabled' => 'no', 'product_calculator' => 'yes' ] ) );
		$this->assertFalse( Cdfrete_Frontend_Calculator::calculator_is_offered( [ 'enabled' => 'yes', 'product_calculator' => 'no' ] ) );
	}
}
