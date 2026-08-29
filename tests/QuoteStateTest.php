<?php

use PHPUnit\Framework\TestCase;

/**
 * A postcode that gets no price gets one of four answers, and they are four because they are
 * four different facts. Drawing the calculator and answering it stopped sharing one instance,
 * so the zone that answers is no longer the zone the form came from: a zone the merchant added
 * and never saved is enabled from the moment it is added and can be the one a postcode resolves
 * to. It used to land in the same branch as "no zone matched" and both told the shopper "Não
 * atendemos este CEP", in red, about a region the store very often does serve. What the shopper
 * then reads is `RefusalTest`; this is the state the code reaches and the line the merchant gets.
 */
class QuoteStateTest extends TestCase {

	private const READY = [ 'token' => 'abc', 'product_calculator' => 'yes' ];

	public function test_no_zone_matching_the_postcode_is_its_own_state(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE,
			Cdfrete_Frontend_Calculator::quote_state( null, [] )
		);
	}

	/**
	 * WooCommerce inserts the zone method row enabled and stores no settings until the merchant
	 * saves the form, so the zone answers with an empty array. That is a zone which exists, not
	 * a postcode nobody covers.
	 */
	public function test_a_zone_added_and_never_saved_is_told_apart_from_no_zone_at_all(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
			Cdfrete_Frontend_Calculator::quote_state( 7, [] )
		);
	}

	public function test_a_zone_saved_without_a_token_is_its_own_state(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'token' => '', 'product_calculator' => 'yes' ] )
		);
	}

	public function test_a_zone_with_the_calculator_switched_off_is_its_own_state(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'token' => 'abc', 'product_calculator' => 'no' ] )
		);
	}

	/**
	 * The "Ativar método de entrega" checkbox was removed in 3.3.0: it fed a property
	 * WooCommerce overwrites from the zone method row, so the cart never honoured it either.
	 * A store that saved it keeps the row, and a finished zone has to go on quoting - reading
	 * the leftover now would refuse a region the cart has always priced.
	 */
	public function test_the_removed_method_checkbox_no_longer_withholds_a_quote(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'enabled' => 'no', 'token' => 'abc', 'product_calculator' => 'yes' ] )
		);
	}

	/** The calculator switch still decides, whatever the leftover next to it says. */
	public function test_the_calculator_switch_still_decides_beside_the_leftover(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'enabled' => 'no', 'token' => 'abc', 'product_calculator' => 'no' ] )
		);
	}

	public function test_a_finished_zone_quotes(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, self::READY )
		);
	}

	/** The switch defaults to on, which is how a zone saved before the field existed quotes. */
	public function test_a_zone_saved_before_the_switch_existed_still_quotes(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'token' => 'abc' ] )
		);
	}

	/**
	 * The switch defaults to on, so reading it on a zone with no settings at all would report a
	 * choice the merchant never made - and would tell the shopper the region is not covered.
	 */
	public function test_a_zone_never_saved_is_not_reported_as_one_that_switched_anything_off(): void {
		$this->assertNotSame(
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [] )
		);
	}

	public function test_the_log_names_which_state_occurred(): void {
		$lines = [];

		foreach ( self::states_without_a_quote() as $state ) {
			$lines[] = Cdfrete_Frontend_Calculator::quote_state_log( $state, 7, '30240440' )['message'];
		}

		$this->assertCount( count( $lines ), array_unique( $lines ) );

		foreach ( $lines as $line ) {
			$this->assertStringContainsString( '30240440', $line );
		}
	}

	/**
	 * The merchant reads it in the log and finds the zone by its instance id; the shopper never
	 * sees the word, because a token is not something they can do anything about.
	 */
	public function test_the_missing_token_is_named_in_the_log(): void {
		$log = Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN, 7, '30240440' );

		$this->assertStringContainsString( 'Token não configurado', $log['message'] );
		$this->assertStringContainsString( '7', $log['message'] );
	}

	public function test_a_zone_never_saved_is_logged_as_unsaved_rather_than_untokened(): void {
		$log = Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED, 7, '30240440' );

		$this->assertStringNotContainsString( 'Token não configurado', $log['message'] );
		$this->assertStringContainsString( '7', $log['message'] );
	}

	/**
	 * A zone the merchant switched off is a decision, not a fault; the unfinished ones are
	 * something to fix, so they have to stand out in a log the merchant reads.
	 */
	public function test_a_deliberate_switch_is_not_logged_at_the_level_of_a_fault(): void {
		$this->assertSame(
			'debug',
			Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF, 7, '30240440' )['level']
		);

		$this->assertSame(
			'error',
			Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED, 7, '30240440' )['level']
		);

		$this->assertSame(
			'error',
			Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN, 7, '30240440' )['level']
		);
	}

	/**
	 * The states are read one by one, so a fifth one added later without a line of its own used
	 * to inherit whichever branch sat in the default. That branch was the missing token, at the
	 * level of a fault: the merchant would be sent to a settings screen to fix a token that is
	 * already there. An unrecognised state names itself instead, the way `refuse()` falls back to
	 * the sentence that claims nothing.
	 */
	public function test_a_state_with_no_line_of_its_own_is_not_reported_as_a_missing_token(): void {
		$log = Cdfrete_Frontend_Calculator::quote_state_log( 'a_state_added_after_this_test', 7, '30240440' );

		$this->assertStringNotContainsString( 'Token não configurado', $log['message'] );
		$this->assertStringContainsString( 'a_state_added_after_this_test', $log['message'] );
		$this->assertStringContainsString( '30240440', $log['message'] );
		$this->assertNotSame( 'error', $log['level'] );
	}

	/**
	 * @return string[]
	 */
	private static function states_without_a_quote(): array {
		return [
			Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE,
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
			Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN,
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
		];
	}
}
