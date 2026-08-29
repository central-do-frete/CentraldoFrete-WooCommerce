<?php

use PHPUnit\Framework\TestCase;

/**
 * When a quote goes out with no origin, the postcode the service resolved is remembered so the
 * settings screen can name it. A store can carry a different token per shipping zone, and each
 * of those accounts has its own pickup address, so a single remembered pair meant two zones
 * overwrote each other on every alternating quote: a write to the database every time, and a
 * settings screen that named whichever account had quoted most recently.
 */
class ResolvedOriginTest extends TestCase {

	private const ACCOUNT_A = 'aaaaaaaaaaaaaaaa';
	private const ACCOUNT_B = 'bbbbbbbbbbbbbbbb';

	public function test_the_first_origin_is_recorded_against_its_account(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], self::ACCOUNT_A, '01310100', 1000 );

		$this->assertSame( '01310100', $map[ self::ACCOUNT_A ]['zipcode'] );
		$this->assertSame( 1000, $map[ self::ACCOUNT_A ]['updated'] );
	}

	public function test_two_accounts_are_remembered_side_by_side(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], self::ACCOUNT_A, '01310100', 1000 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_B, '30240440', 1001 );

		$this->assertSame( '01310100', $map[ self::ACCOUNT_A ]['zipcode'] );
		$this->assertSame( '30240440', $map[ self::ACCOUNT_B ]['zipcode'] );
	}

	public function test_two_zones_alternating_stop_overwriting_each_other(): void {
		$map = [];

		for ( $i = 0; $i < 6; $i++ ) {
			$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_A, '01310100', 1000 + $i );
			$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_B, '30240440', 1000 + $i );
		}

		$this->assertSame( '01310100', $map[ self::ACCOUNT_A ]['zipcode'] );
		$this->assertSame( '30240440', $map[ self::ACCOUNT_B ]['zipcode'] );
	}

	/**
	 * The caller writes to the database only when the map comes back different, so an unchanged
	 * origin has to come back identical - including after another account was recorded.
	 */
	public function test_recording_the_same_origin_again_changes_nothing(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], self::ACCOUNT_A, '01310100', 1000 );

		$this->assertSame( $map, Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_A, '01310100', 2000 ) );
	}

	public function test_a_quote_from_the_other_account_does_not_dirty_the_first(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], self::ACCOUNT_A, '01310100', 1000 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_B, '30240440', 1001 );

		$this->assertSame( $map, Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_B, '30240440', 2000 ) );
	}

	public function test_an_account_that_moved_its_pickup_address_is_updated(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], self::ACCOUNT_A, '01310100', 1000 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_A, '30240440', 2000 );

		$this->assertSame( '30240440', $map[ self::ACCOUNT_A ]['zipcode'] );
		$this->assertSame( 2000, $map[ self::ACCOUNT_A ]['updated'] );
	}

	public function test_the_map_is_bounded_and_drops_the_least_recently_changed(): void {
		$map = [];

		foreach ( [ 'account-1', 'account-2', 'account-3' ] as $i => $account ) {
			$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, $account, '0131010' . $i, 1000 + $i, 2 );
		}

		$this->assertCount( 2, $map );
		$this->assertArrayNotHasKey( 'account-1', $map );
		$this->assertSame( '01310101', $map['account-2']['zipcode'] );
		$this->assertSame( '01310102', $map['account-3']['zipcode'] );
	}

	/**
	 * "Least recently changed" is the measure, and it is not the same as least recently used: an
	 * entry is rewritten only when the origin moved, precisely so an unchanged origin costs no
	 * database write, so its timestamp dates the last move and never the last quote. An account
	 * quoting daily from a pickup address it never changed therefore keeps an old timestamp and
	 * is the first out. Written down because the two readings pick opposite victims.
	 */
	public function test_quoting_again_from_an_unchanged_origin_does_not_move_an_account_up(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], 'account-1', '01310100', 1000, 2 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-2', '30240440', 1001, 2 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-1', '01310100', 3000, 2 );

		$this->assertSame( 1000, $map['account-1']['updated'] );

		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-3', '70040010', 3001, 2 );

		$this->assertCount( 2, $map );
		$this->assertArrayNotHasKey( 'account-1', $map );
	}

	/**
	 * An account that moved its pickup address is by that fact the most recently changed, so it
	 * survives a prune that its original timestamp would have lost.
	 */
	public function test_an_account_that_moved_its_origin_survives_the_prune(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], 'account-1', '01310100', 1000, 2 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-2', '30240440', 1001, 2 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-1', '70040010', 3000, 2 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, 'account-3', '88010400', 3001, 2 );

		$this->assertCount( 2, $map );
		$this->assertSame( '70040010', $map['account-1']['zipcode'] );
		$this->assertArrayNotHasKey( 'account-2', $map );
	}

	/**
	 * An account scope is the first 16 characters of a sha256 digest, so about one token in a
	 * few thousand produces one that is all digits. PHP stores a canonical decimal string key
	 * as an integer, so such an account came back from the option with an integer key and was
	 * thrown away as unrecognised: it was re-added on every uncached quote, which is a database
	 * write each time, and the settings screen could never name the postcode it resolved to.
	 */
	public function test_an_all_digit_account_scope_survives_a_round_trip(): void {
		$account = '1234567890123456';

		$this->assertSame( $account, (string) (int) $account, 'This account scope must be one PHP stores as an integer key' );

		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], $account, '01310100', 1000 );

		$this->assertSame( '01310100', $map[ $account ]['zipcode'] );
	}

	public function test_an_all_digit_account_is_not_rewritten_on_every_quote(): void {
		$account = '1234567890123456';

		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], $account, '01310100', 1000 );

		$this->assertSame( $map, Cdfrete_Shipping_Method::with_resolved_origin( $map, $account, '01310100', 2000 ) );
	}

	public function test_an_all_digit_account_does_not_evict_the_others(): void {
		$map = Cdfrete_Shipping_Method::with_resolved_origin( [], '1234567890123456', '01310100', 1000 );
		$map = Cdfrete_Shipping_Method::with_resolved_origin( $map, self::ACCOUNT_A, '30240440', 1001 );

		$this->assertSame( '01310100', $map['1234567890123456']['zipcode'] );
		$this->assertSame( '30240440', $map[ self::ACCOUNT_A ]['zipcode'] );
	}

	/**
	 * Before this the option held one flat `{account, zipcode, updated}` pair. It has to be
	 * retired rather than read as if its keys were accounts.
	 */
	public function test_the_single_pair_an_older_version_stored_is_retired(): void {
		$stored = [ 'account' => self::ACCOUNT_A, 'zipcode' => '01310100', 'updated' => 900 ];

		$map = Cdfrete_Shipping_Method::with_resolved_origin( $stored, self::ACCOUNT_B, '30240440', 1000 );

		$this->assertSame( [ self::ACCOUNT_B => [ 'zipcode' => '30240440', 'updated' => 1000 ] ], $map );
	}
}
