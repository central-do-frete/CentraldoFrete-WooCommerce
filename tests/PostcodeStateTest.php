<?php

use PHPUnit\Framework\TestCase;

/**
 * WooCommerce matches a shipping zone defined by state as "<country>:<state>", so a destination
 * with no state skips that zone without a word and lands in whatever broader zone comes next -
 * another token, another handling fee, another set of rules. The postcode carries the state:
 * Correios allocates the ranges per federative unit, so the one the shopper just typed is
 * derived and the zone is matched the same way the checkout matches it.
 *
 * A wrong entry in that table is a deterministically wrong zone with nothing on screen to say
 * so, which is worse than showing no price at all, so the table is held here against the data
 * it was built from rather than against itself. The expectations below are the ranges published
 * by Correios ("Faixa de CEP por UF/Localidade"), each verified on 2026-08-28 by resolving its
 * probe postcode through ViaCEP and comparing the federative unit reported. 78900000-78999999 is
 * absent on purpose: it is listed historically for Rondônia, no allocated postcode in it
 * answered, and a range that cannot be cited stays a gap that derives nothing.
 */
class PostcodeStateTest extends TestCase {

	/** Federative unit, first postcode, last postcode, probe postcode verified for it. */
	private const RANGES = [
		[ 'SP', '01000000', '19999999', '08599000' ],
		[ 'RJ', '20000000', '28999999', '26299000' ],
		[ 'ES', '29000000', '29999999', '29299000' ],
		[ 'MG', '30000000', '39999999', '39499000' ],
		[ 'BA', '40000000', '48999999', '40010000' ],
		[ 'SE', '49000000', '49999999', '49001000' ],
		[ 'PE', '50000000', '56999999', '50010000' ],
		[ 'AL', '57000000', '57999999', '57699000' ],
		[ 'PB', '58000000', '58999999', '58499000' ],
		[ 'RN', '59000000', '59999999', '59299000' ],
		[ 'CE', '60000000', '63999999', '62399000' ],
		[ 'PI', '64000000', '64999999', '64099000' ],
		[ 'MA', '65000000', '65999999', '65049000' ],
		[ 'PA', '66000000', '68899999', '68754000' ],
		[ 'AP', '68900000', '68999999', '68994000' ],
		[ 'AM', '69000000', '69299999', '69059000' ],
		[ 'RR', '69300000', '69399999', '69319000' ],
		[ 'AM', '69400000', '69899999', '69424000' ],
		[ 'AC', '69900000', '69999999', '69919000' ],
		[ 'DF', '70000000', '72799999', '71959000' ],
		[ 'GO', '72800000', '72999999', '72899000' ],
		[ 'DF', '73000000', '73699999', '73006000' ],
		[ 'GO', '73700000', '76799999', '75714000' ],
		[ 'RO', '76800000', '76999999', '76839000' ],
		[ 'TO', '77000000', '77999999', '77449000' ],
		[ 'MT', '78000000', '78899999', '78449000' ],
		[ 'MS', '79000000', '79999999', '79949000' ],
		[ 'PR', '80000000', '87999999', '80010000' ],
		[ 'SC', '88000000', '89999999', '89899000' ],
		[ 'RS', '90000000', '99999999', '96999000' ],
	];

	/** Listed for Rondônia before the renumbering to 768xx; nothing is allocated in it today. */
	private const LEGACY_RONDONIA_GAP = [ '78900000', '78999999' ];

	public function test_every_range_derives_its_state_at_both_bounds_and_in_the_middle(): void {
		foreach ( self::RANGES as [ $state, $first, $last ] ) {
			$middle = sprintf( '%08d', intdiv( (int) $first + (int) $last, 2 ) );

			foreach ( [ $first, $middle, $last ] as $postcode ) {
				$this->assertSame(
					$state,
					Cdfrete_Shipping_Method::state_for_postcode( $postcode ),
					$postcode . ' belongs to ' . $state
				);
			}
		}
	}

	/** The postcode each range was verified with, resolved through ViaCEP on 2026-08-28. */
	public function test_the_probe_postcode_recorded_for_each_range_derives_its_state(): void {
		foreach ( self::RANGES as [ $state, , , $probe ] ) {
			$this->assertSame( $state, Cdfrete_Shipping_Method::state_for_postcode( $probe ) );
		}
	}

	/**
	 * Bounds and a middle point would miss a range shadowed by an overlapping one, and the
	 * shadowed postcodes would quietly derive a neighbour's state. The whole numbering space is
	 * walked instead, at a step finer than the narrowest range, so an overlap anywhere shows up.
	 */
	public function test_the_whole_postcode_space_derives_the_published_state_and_nothing_else(): void {
		$mismatches = [];

		for ( $number = 0; $number <= 99999999; $number += 100000 ) {
			$postcode = sprintf( '%08d', $number );
			$derived  = Cdfrete_Shipping_Method::state_for_postcode( $postcode );
			$expected = self::published_state( $number );

			if ( $derived !== $expected ) {
				$mismatches[ $postcode ] = [ 'expected' => $expected, 'derived' => $derived ];
			}
		}

		$this->assertSame( [], $mismatches );
	}

	/** The fixture the assertions above rest on has to be sound itself. */
	public function test_the_published_ranges_are_ordered_and_do_not_overlap(): void {
		$previous_last = -1;

		foreach ( self::RANGES as [ $state, $first, $last ] ) {
			$this->assertGreaterThan( $previous_last, (int) $first, $state . ' starts after the range before it' );
			$this->assertGreaterThanOrEqual( (int) $first, (int) $last, $state . ' ends at or after it starts' );

			$previous_last = (int) $last;
		}
	}

	/**
	 * A gap is not a guess. No allocated postcode in the legacy Rondônia range answered, so it
	 * derives nothing and the destination stays stateless, which is the refusal already built.
	 */
	public function test_the_excluded_legacy_rondonia_range_derives_nothing(): void {
		[ $first, $last ] = self::LEGACY_RONDONIA_GAP;

		$middle = sprintf( '%08d', intdiv( (int) $first + (int) $last, 2 ) );

		foreach ( [ $first, $middle, $last ] as $postcode ) {
			$this->assertNull( Cdfrete_Shipping_Method::state_for_postcode( $postcode ), $postcode . ' is not allocated' );
		}
	}

	/** Rondônia's live range, so the gap above is a gap and not a state that went missing. */
	public function test_rondonia_still_derives_from_its_live_range(): void {
		$this->assertSame( 'RO', Cdfrete_Shipping_Method::state_for_postcode( '76839000' ) );
	}

	public function test_a_postcode_below_every_published_range_derives_nothing(): void {
		$this->assertNull( Cdfrete_Shipping_Method::state_for_postcode( '00000000' ) );
		$this->assertNull( Cdfrete_Shipping_Method::state_for_postcode( '00999999' ) );
	}

	public function test_a_postcode_that_is_not_eight_digits_derives_nothing(): void {
		foreach ( [ '', '0131010', '013101000', 'abcdefgh', '   ' ] as $postcode ) {
			$this->assertNull( Cdfrete_Shipping_Method::state_for_postcode( $postcode ), $postcode . ' is not a postcode' );
		}
	}

	/** The shopper types what they type, and the form is the same one the checkout accepts. */
	public function test_punctuation_the_shopper_typed_does_not_change_the_state(): void {
		$this->assertSame( 'SP', Cdfrete_Shipping_Method::state_for_postcode( '01310-100' ) );
		$this->assertSame( 'SP', Cdfrete_Shipping_Method::state_for_postcode( '01310.100' ) );
		$this->assertSame( 'SP', Cdfrete_Shipping_Method::state_for_postcode( ' 01310100 ' ) );
	}

	/**
	 * The state has to arrive as WooCommerce stores it, because the zone criterion is a literal
	 * comparison against "<country>:<state>" and "BR:sp" matches no zone at all.
	 */
	public function test_the_state_is_the_two_letter_code_in_upper_case(): void {
		foreach ( self::RANGES as [ , , , $probe ] ) {
			$derived = Cdfrete_Shipping_Method::state_for_postcode( $probe );

			$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', (string) $derived );
		}
	}

	/**
	 * The federative unit a postcode belongs to according to the published ranges, or null where
	 * nothing is published for it.
	 */
	private static function published_state( int $number ): ?string {
		foreach ( self::RANGES as [ $state, $first, $last ] ) {
			if ( $number >= (int) $first && $number <= (int) $last ) {
				return $state;
			}
		}

		return null;
	}
}
