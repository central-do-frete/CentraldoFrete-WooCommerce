<?php

use PHPUnit\Framework\TestCase;

/**
 * A postcode that gets no price gets one of five answers, and they are five because they are
 * five different facts. Drawing the calculator and answering it stopped sharing one instance,
 * so the zone that answers is no longer the zone the form came from: a zone the merchant added
 * and never saved is enabled from the moment it is added and can be the one a postcode resolves
 * to. It used to land in the same branch as "no zone matched" and both told the shopper "Não
 * atendemos este CEP", in red, about a region the store very often does serve. What the shopper
 * then reads is `RefusalTest`; this is the state the code reaches and the line the merchant gets.
 */
class QuoteStateTest extends TestCase {

	private const READY = [ 'enabled' => 'yes', 'token' => 'abc', 'product_calculator' => 'yes' ];

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
	 * "Ativar método de entrega" is a different switch from the zone screen toggle the instance
	 * list is read from, and the calculator used to ignore it: the zone quoted on the product
	 * page while the cart and the checkout offered that region nothing.
	 */
	public function test_a_zone_with_the_method_switched_off_does_not_quote(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_METHOD_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'enabled' => 'no', 'token' => 'abc', 'product_calculator' => 'yes' ] )
		);
	}

	/**
	 * A method that is off quotes nowhere, so it is what the merchant is told about, even with
	 * the calculator switch off as well.
	 */
	public function test_the_method_switch_is_read_before_the_calculator_one(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_METHOD_OFF,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'enabled' => 'no', 'token' => 'abc', 'product_calculator' => 'no' ] )
		);
	}

	public function test_a_finished_zone_quotes(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, self::READY )
		);
	}

	/** Both switches default to on, which is how a zone saved before either field existed quotes. */
	public function test_a_zone_saved_before_the_switches_existed_still_quotes(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::QUOTE_READY,
			Cdfrete_Frontend_Calculator::quote_state( 7, [ 'token' => 'abc' ] )
		);
	}

	/**
	 * The switches default to on, so reading them on a zone with no settings at all would report
	 * a choice the merchant never made - and would tell the shopper the region is not covered.
	 */
	public function test_a_zone_never_saved_is_not_reported_as_one_that_switched_anything_off(): void {
		$state = Cdfrete_Frontend_Calculator::quote_state( 7, [] );

		$this->assertNotSame( Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF, $state );
		$this->assertNotSame( Cdfrete_Frontend_Calculator::QUOTE_METHOD_OFF, $state );
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
			'debug',
			Cdfrete_Frontend_Calculator::quote_state_log( Cdfrete_Frontend_Calculator::QUOTE_METHOD_OFF, 7, '30240440' )['level']
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
			Cdfrete_Frontend_Calculator::QUOTE_METHOD_OFF,
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
		];
	}
}
