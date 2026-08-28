<?php

use PHPUnit\Framework\TestCase;

/**
 * CF-387: a store can add Central do Frete to more than one shipping zone, each with its own
 * token, handling fee and display rules. The instance that answers has to be the one from the
 * zone the destination falls into, never whichever row the database happened to return first.
 */
class InstanceForDestinationTest extends TestCase {

	public function test_no_enabled_instance_answers_nothing(): void {
		$this->assertNull( Cdfrete_Shipping_Method::pick_instance( [], [ 4 ] ) );
	}

	public function test_a_single_enabled_instance_answers_without_consulting_the_zone(): void {
		// The product page knows a postcode but no state, so a zone defined by state cannot be
		// matched. With one instance there is nothing to get wrong, and it must keep quoting.
		$this->assertSame( 7, Cdfrete_Shipping_Method::pick_instance( [ 7 ], [] ) );
	}

	public function test_the_instance_of_the_matched_zone_wins(): void {
		$this->assertSame( 9, Cdfrete_Shipping_Method::pick_instance( [ 3, 9, 14 ], [ 9 ] ) );
	}

	public function test_the_order_the_ids_arrive_in_does_not_change_the_answer(): void {
		$this->assertSame(
			Cdfrete_Shipping_Method::pick_instance( [ 3, 9, 14 ], [ 9 ] ),
			Cdfrete_Shipping_Method::pick_instance( [ 14, 9, 3 ], [ 9 ] )
		);
	}

	public function test_a_zone_without_the_method_gets_no_settings_instead_of_another_zones(): void {
		$this->assertNull( Cdfrete_Shipping_Method::pick_instance( [ 3, 9 ], [] ) );
	}

	public function test_an_instance_of_the_matched_zone_that_is_disabled_does_not_answer(): void {
		$this->assertNull( Cdfrete_Shipping_Method::pick_instance( [ 3, 9 ], [ 14 ] ) );
	}

	public function test_a_zone_carrying_the_method_twice_answers_the_same_way_every_time(): void {
		$this->assertSame( 9, Cdfrete_Shipping_Method::pick_instance( [ 3, 9, 14 ], [ 14, 9 ] ) );
	}

	public function test_ids_read_from_the_database_as_strings_are_compared_as_numbers(): void {
		$this->assertSame( 9, Cdfrete_Shipping_Method::pick_instance( [ '3', '9', '14' ], [ '9' ] ) );
	}

	/**
	 * The product page knows a postcode and no state, and WooCommerce matches a state location
	 * as "<country>:<state>". With the state blank that criterion matches nothing, so a zone
	 * defined by state is not considered at all and the query falls through to the next zone by
	 * order. A store with "Brazil : SP" and "Brazil" both carrying the method would have quoted
	 * every São Paulo postcode from the country wide zone - its token, its fee, its rules -
	 * while the cart, which knows the state, priced the same basket from the other one.
	 */
	public function test_a_zone_defined_by_state_elsewhere_cancels_a_postcode_only_match(): void {
		$this->assertNull( Cdfrete_Shipping_Method::stateless_pick( 3, [ 3 ], [ 9 ] ) );
	}

	public function test_a_postcode_only_match_stands_when_no_zone_is_defined_by_state(): void {
		$this->assertSame( 3, Cdfrete_Shipping_Method::stateless_pick( 3, [ 3 ], [] ) );
	}

	/**
	 * A zone that lists a state as well as the location it matched on is the zone the shopper is
	 * in, not a zone that was skipped, so nothing was missed and its answer stands.
	 */
	public function test_a_state_location_in_the_matched_zone_itself_does_not_cancel_it(): void {
		$this->assertSame( 3, Cdfrete_Shipping_Method::stateless_pick( 3, [ 3 ], [ 3 ] ) );
	}

	public function test_a_zone_that_matched_nothing_stays_nothing(): void {
		$this->assertNull( Cdfrete_Shipping_Method::stateless_pick( null, [], [ 9 ] ) );
	}

	public function test_state_defined_ids_read_from_the_database_as_strings_are_compared_as_numbers(): void {
		$this->assertSame( 3, Cdfrete_Shipping_Method::stateless_pick( 3, [ 3 ], [ '3' ] ) );
		$this->assertNull( Cdfrete_Shipping_Method::stateless_pick( 3, [ '3' ], [ '9' ] ) );
	}
}
