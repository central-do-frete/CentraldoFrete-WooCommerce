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

	public function test_a_single_enabled_instance_is_matched_like_any_other(): void {
		// It used to answer without consulting the zone, because a postcode alone could not reach
		// a zone defined by state. The postcode now carries its state, so the exemption is gone:
		// the only instance in the store still has to be in the zone the destination falls into.
		$this->assertNull( Cdfrete_Shipping_Method::pick_instance( [ 7 ], [] ) );
		$this->assertSame( 7, Cdfrete_Shipping_Method::pick_instance( [ 7 ], [ 7 ] ) );
	}

	public function test_a_zone_holding_an_abandoned_entry_answers_from_the_one_that_can_quote(): void {
		// Added to the zone, form closed without saving, then added again and configured. The
		// leftover is the older id, so the lowest-id rule alone would answer from the empty one
		// while the cart quotes that same basket from the configured sibling.
		$this->assertSame( 14, Cdfrete_Shipping_Method::pick_instance( [ 9, 14 ], [ 9, 14 ], [ 14 ] ) );
	}

	public function test_the_lowest_id_still_wins_among_instances_that_can_all_quote(): void {
		$this->assertSame( 9, Cdfrete_Shipping_Method::pick_instance( [ 9, 14 ], [ 9, 14 ], [ 9, 14 ] ) );
	}

	public function test_the_lowest_id_still_wins_when_none_of_them_can_quote(): void {
		$this->assertSame( 9, Cdfrete_Shipping_Method::pick_instance( [ 9, 14 ], [ 9, 14 ], [] ) );
	}

	public function test_a_quotable_instance_outside_the_matched_zone_does_not_rescue_the_match(): void {
		// Preferring one that can quote narrows the candidates; it never widens them past the zone.
		$this->assertNull( Cdfrete_Shipping_Method::pick_instance( [ 3, 9 ], [], [ 3, 9 ] ) );
	}

	public function test_the_tie_break_is_stable_across_input_orderings(): void {
		$this->assertSame(
			Cdfrete_Shipping_Method::pick_instance( [ 9, 14, 21 ], [ 9, 14, 21 ], [ 14, 21 ] ),
			Cdfrete_Shipping_Method::pick_instance( [ 21, 9, 14 ], [ 21, 14, 9 ], [ 21, 14 ] )
		);
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

	/**
	 * Refusing was the safe answer, not the right one. The state the zone matcher needs is in the
	 * postcode the shopper typed, so it is filled in before the match and the zone defined by
	 * state becomes eligible instead of being skipped - the same zone the checkout will use.
	 */
	public function test_the_state_of_the_typed_postcode_is_filled_in_before_the_zone_is_matched(): void {
		$destination = Cdfrete_Shipping_Method::destination_for_zone_matching( [
			'country'  => 'BR',
			'state'    => '',
			'postcode' => '01310100',
		] );

		$this->assertSame( 'SP', $destination['state'] );
		$this->assertSame( '01310100', $destination['postcode'] );
		$this->assertSame( 'BR', $destination['country'] );
	}

	/**
	 * The cart and the checkout know the state from the address the shopper is buying to, which
	 * is the fact itself rather than a derivation of it, and it stays untouched.
	 */
	public function test_a_state_the_caller_already_has_is_left_alone(): void {
		$destination = Cdfrete_Shipping_Method::destination_for_zone_matching( [
			'country'  => 'BR',
			'state'    => 'RJ',
			'postcode' => '01310100',
		] );

		$this->assertSame( 'RJ', $destination['state'] );
	}

	/** These ranges are Correios' allocation and say nothing about any other country. */
	public function test_nothing_is_derived_outside_brazil(): void {
		$destination = Cdfrete_Shipping_Method::destination_for_zone_matching( [
			'country'  => 'PT',
			'state'    => '',
			'postcode' => '01310100',
		] );

		$this->assertSame( '', $destination['state'] );
		$this->assertSame( 'PT', $destination['country'] );
	}

	/**
	 * A postcode no published range covers derives nothing rather than a neighbour's state, so
	 * the destination stays stateless and `stateless_pick()` decides - which is the refusal that
	 * used to be the rule and is now the exception.
	 */
	public function test_a_postcode_outside_every_published_range_leaves_the_destination_stateless(): void {
		$destination = Cdfrete_Shipping_Method::destination_for_zone_matching( [
			'country'  => 'BR',
			'state'    => '',
			'postcode' => '78950000',
		] );

		$this->assertSame( '', $destination['state'] );
	}

	/** A caller that supplies only a postcode still gets a destination the matcher can read. */
	public function test_the_country_and_state_keys_are_always_present(): void {
		$destination = Cdfrete_Shipping_Method::destination_for_zone_matching( [ 'postcode' => '90000000' ] );

		$this->assertSame( 'BR', $destination['country'] );
		$this->assertSame( 'RS', $destination['state'] );
	}
}
