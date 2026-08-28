<?php

use PHPUnit\Framework\TestCase;

/**
 * A postcode that gets no price gets one of three answers, and they are three because they are
 * three different facts. Drawing the calculator and answering it stopped sharing one instance,
 * so the zone that answers is no longer the zone the form came from: a zone the merchant added
 * and never saved is enabled from the moment it is added and can be the one a postcode resolves
 * to. It used to land in the same branch as "no zone matched" and both told the shopper "Não
 * atendemos este CEP", in red, about a region the store very often does serve.
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

	public function test_a_finished_zone_quotes(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, self::READY )
		);
	}

	/**
	 * The switch defaults to on, so reading it on a zone with no settings at all would report a
	 * choice the merchant never made - and would tell the shopper the region is not covered.
	 */
	public function test_a_zone_never_saved_is_not_reported_as_one_that_switched_the_calculator_off(): void {
		$this->assertNotSame(
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [] )
		);
	}

	public function test_the_three_shopper_answers_are_three_different_messages(): void {
		$messages = [
			Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF )['message'],
			Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED )['message'],
			Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE )['message'],
		];

		$this->assertCount( 3, array_unique( $messages ) );

		foreach ( $messages as $message ) {
			$this->assertNotSame( '', $message );
		}
	}

	/**
	 * A zone with no token and a zone that was never saved are the same fact to the shopper -
	 * this store cannot quote here - and the difference between them is the merchant's to fix.
	 */
	public function test_the_two_unfinished_zones_read_the_same_to_the_shopper(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED ),
			Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN )
		);
	}

	/**
	 * Nothing failed and nothing is the shopper's fault, so none of the three may arrive on the
	 * channel the script paints red.
	 */
	public function test_none_of_the_answers_is_an_error(): void {
		foreach ( self::states_without_a_quote() as $state ) {
			$this->assertTrue( Cdfrete_Frontend_Calculator::unavailable_answer( $state )['notice'] );
		}
	}

	/**
	 * Only the zone whose calculator the merchant switched off may say anything about where the
	 * store delivers, because there the merchant chose it. An unfinished zone and a postcode no
	 * zone matched are not coverage facts, and a store defining its zones by state does serve
	 * the postcodes that reach them.
	 */
	public function test_only_the_switched_off_zone_makes_a_claim_about_coverage(): void {
		$coverage_claims = [ 'não atendemos', 'não atende', 'não entregamos', 'fora da área' ];

		foreach ( [ Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED, Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN, Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE ] as $state ) {
			$message = Cdfrete_Frontend_Calculator::unavailable_answer( $state )['message'];

			foreach ( $coverage_claims as $claim ) {
				$this->assertFalse(
					stripos( $message, $claim ),
					$state . ' must not tell the shopper the store does not serve them'
				);
			}
		}
	}

	/**
	 * An unfinished zone fails the same way on the next attempt, so the answer must not send the
	 * shopper back to the button.
	 */
	public function test_the_unfinished_zone_does_not_suggest_trying_again(): void {
		$message = Cdfrete_Frontend_Calculator::unavailable_answer( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED )['message'];

		$this->assertFalse( stripos( $message, 'tente novamente' ) );
		$this->assertFalse( stripos( $message, 'tente mais tarde' ) );
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
	public function test_the_missing_token_is_named_in_the_log_and_nowhere_else(): void {
		$log = Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN, 7, '30240440' );

		$this->assertStringContainsString( 'Token não configurado', $log['message'] );
		$this->assertStringContainsString( '7', $log['message'] );

		foreach ( self::states_without_a_quote() as $state ) {
			$this->assertFalse(
				stripos( Cdfrete_Frontend_Calculator::unavailable_answer( $state )['message'], 'token' ),
				$state . ' must not put the token in front of the shopper'
			);
		}
	}

	public function test_a_zone_never_saved_is_logged_as_unsaved_rather_than_untokened(): void {
		$log = Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED, 7, '30240440' );

		$this->assertStringNotContainsString( 'Token não configurado', $log['message'] );
		$this->assertStringContainsString( '7', $log['message'] );
	}

	/**
	 * A zone the merchant switched off is a decision, not a fault; the other two are something
	 * to fix, so they have to stand out in a log the merchant reads.
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
