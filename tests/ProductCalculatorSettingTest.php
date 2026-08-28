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
	 * "Ativar método de entrega" is the other switch on the same instance, and `is_available()`
	 * reads it for the cart and the checkout. The calculator reads it the same way, or it quotes
	 * a region the rest of the store has nothing for.
	 */
	public function test_a_zone_with_the_method_switched_off_is_not_active(): void {
		$this->assertFalse( Cdfrete_Frontend_Calculator::method_is_enabled( [ 'enabled' => 'no', 'token' => 'abc' ] ) );
	}

	public function test_a_zone_with_the_method_switched_on_is_active(): void {
		$this->assertTrue( Cdfrete_Frontend_Calculator::method_is_enabled( [ 'enabled' => 'yes' ] ) );
	}

	/** The field defaults to on, so a zone saved before it existed keeps quoting. */
	public function test_a_zone_saved_without_the_method_switch_is_active(): void {
		$this->assertTrue( Cdfrete_Frontend_Calculator::method_is_enabled( [ 'token' => 'abc' ] ) );
	}

	public function test_the_two_switches_are_read_separately(): void {
		$calculator_off = [ 'enabled' => 'yes', 'product_calculator' => 'no' ];

		$this->assertTrue( Cdfrete_Frontend_Calculator::method_is_enabled( $calculator_off ) );
		$this->assertFalse( Cdfrete_Frontend_Calculator::calculator_is_offered( $calculator_off ) );
	}
}
