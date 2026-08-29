<?php

use PHPUnit\Framework\TestCase;

/**
 * Every sentence telling a shopper this store will not price their postcode leaves through one
 * function, and it cannot leave without saying whether the plugin checked that the region gets
 * no quote. The handler still answers a bad postcode, a missing product, a failed request and a
 * postcode the service returned nothing for on its own, and those are not swept up here. Refusals used
 * to answer that question by accident: one of them told shoppers the store does not quote a
 * product it quotes in every other zone, which reads as "there is no freight for this at all"
 * and ends the visit. These tests hold the guard rail rather than the wording: a sentence that
 * claims coverage may only go out on a verdict that was actually reached.
 */
class RefusalTest extends TestCase {

	/**
	 * The calculator switch the merchant turned off, and the class restriction of the zone that
	 * answers, are settings the plugin read. Everything else is a guess - including a list the
	 * merchant's own filter emptied, where carriers did quote and the plugin hid them.
	 */
	private const CHECKED = [
		Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF  => Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT,
		Cdfrete_Frontend_Calculator::REFUSE_CLASS_EXCLUDED => Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT,
		Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE         => Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN,
		Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED     => Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN,
		Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN        => Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN,
		Cdfrete_Frontend_Calculator::REFUSE_ALL_FILTERED   => Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN,
	];

	/**
	 * Omitting the verdict is the way the next wrong refusal would be born, so the signature has
	 * to reject it instead of picking an answer on the author's behalf.
	 */
	public function test_a_refusal_cannot_be_written_without_a_coverage_verdict(): void {
		$this->expectException( ArgumentCountError::class );

		// Called indirectly so the file still parses under a static analyser: the point of the
		// test is what happens at run time when the verdict is left out.
		call_user_func(
			[ Cdfrete_Frontend_Calculator::class, 'refuse' ],
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF
		);
	}

	public function test_every_refusal_says_something_and_none_of_them_is_an_error(): void {
		foreach ( self::CHECKED as $cause => $coverage ) {
			$answer = Cdfrete_Frontend_Calculator::refuse( $cause, $coverage );

			$this->assertNotSame( '', $answer['message'], $cause . ' must say something' );
			$this->assertTrue( $answer['notice'], $cause . ' is not a failure and must not be red' );
		}
	}

	/**
	 * The rule the single site exists for: a sentence that asserts the region gets no quote is
	 * only allowed to a caller that read the setting withholding it.
	 */
	public function test_a_sentence_that_claims_coverage_is_dropped_when_the_plugin_did_not_check(): void {
		$claims_coverage = [
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::REFUSE_CLASS_EXCLUDED,
		];

		foreach ( $claims_coverage as $cause ) {
			$this->assertSame(
				self::neutral_answer(),
				Cdfrete_Frontend_Calculator::refuse( $cause, Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN ),
				$cause . ' must fall back to the sentence that claims nothing'
			);
		}
	}

	/**
	 * A refusal nobody registered is the one being written right now, and it gets the sentence
	 * that cannot be wrong rather than nothing at all.
	 */
	public function test_an_unregistered_refusal_falls_back_to_the_sentence_that_claims_nothing(): void {
		$this->assertSame(
			self::neutral_answer(),
			Cdfrete_Frontend_Calculator::refuse( 'a_refusal_written_after_this_test', Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT )
		);
	}

	/**
	 * Claiming a verdict the cause was not written under is the same mistake as omitting it, so
	 * the cross-check has to run in that direction too. "The delivery area could not be
	 * identified" is not something a caller that read a switch is entitled to say.
	 */
	public function test_a_verdict_the_cause_was_not_written_under_is_rejected_either_way(): void {
		$this->assertNotSame(
			Cdfrete_Frontend_Calculator::refuse( Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE, Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN ),
			Cdfrete_Frontend_Calculator::refuse( Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE, Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT )
		);

		$this->assertSame(
			self::neutral_answer(),
			Cdfrete_Frontend_Calculator::refuse( Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE, Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT )
		);
	}

	/**
	 * The product is quoted; the zone the shopper's postcode falls into is what does not quote
	 * its shipping class. Said store wide, a sellable product loses the sale on a sentence the
	 * plugin was never in a position to write.
	 */
	public function test_the_shipping_class_refusal_is_about_the_region_and_not_the_store(): void {
		$answer = Cdfrete_Frontend_Calculator::refuse(
			Cdfrete_Frontend_Calculator::REFUSE_CLASS_EXCLUDED,
			Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT
		);

		$this->assertNotFalse( stripos( $answer['message'], 'região' ), 'the refusal must be scoped to the region' );
		$this->assertTrue( $answer['notice'], 'a product other zones quote is not an error' );
	}

	/**
	 * "Esconder fretes Balcão" can empty a list every carrier answered: the plugin watched them
	 * price that exact postcode and removed them itself, so it is in no position to say there is
	 * no freight for it - the sentence that ends the visit for a product the store does ship.
	 */
	public function test_options_the_merchants_filter_removed_are_not_reported_as_no_freight(): void {
		$answer = Cdfrete_Frontend_Calculator::refuse(
			Cdfrete_Frontend_Calculator::REFUSE_ALL_FILTERED,
			Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN
		);

		$this->assertTrue( $answer['notice'], 'nothing failed, so it must not be shown as an error' );

		foreach ( [ 'nenhuma opção', 'não há opç', 'indisponível', 'não disponível' ] as $claim ) {
			$this->assertFalse(
				stripos( $answer['message'], $claim ),
				'a hidden option is not an absent one: ' . $claim
			);
		}
	}

	/**
	 * It is not a coverage fact either. A caller claiming it read a setting that rules the region
	 * out gets the sentence that claims nothing, like every other unverified refusal.
	 */
	public function test_a_filtered_list_may_not_be_dressed_up_as_a_region_the_merchant_ruled_out(): void {
		$this->assertSame(
			self::neutral_answer(),
			Cdfrete_Frontend_Calculator::refuse(
				Cdfrete_Frontend_Calculator::REFUSE_ALL_FILTERED,
				Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT
			)
		);
	}

	/**
	 * A zone with no token and a zone that was never saved are the same fact to the shopper -
	 * this store cannot quote here - and the difference between them is the merchant's to fix.
	 */
	public function test_the_two_unfinished_zones_read_the_same_to_the_shopper(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::refuse( Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED, Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN ),
			Cdfrete_Frontend_Calculator::refuse( Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN, Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN )
		);
	}

	/**
	 * A postcode no zone matched, a region with the calculator off, an unfinished zone and a
	 * shipping class the zone does not carry are four different facts and stay four sentences.
	 */
	public function test_the_refusals_that_differ_are_not_collapsed_into_one_sentence(): void {
		$messages = [];

		foreach ( [
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::REFUSE_CLASS_EXCLUDED,
			Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE,
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
		] as $cause ) {
			$messages[] = Cdfrete_Frontend_Calculator::refuse( $cause, self::CHECKED[ $cause ] )['message'];
		}

		$this->assertCount( 4, array_unique( $messages ) );
	}

	/**
	 * No refusal may tell a shopper the store does not deliver to them. The plugin cannot know
	 * that: a store defining its zones by state reaches these branches for regions it serves.
	 */
	public function test_no_refusal_tells_the_shopper_the_store_does_not_deliver_there(): void {
		$coverage_claims = [ 'não atendemos', 'não atende', 'não entregamos', 'fora da área' ];

		foreach ( self::CHECKED as $cause => $coverage ) {
			$message = Cdfrete_Frontend_Calculator::refuse( $cause, $coverage )['message'];

			foreach ( $coverage_claims as $claim ) {
				$this->assertFalse( stripos( $message, $claim ), $cause . ' must not claim the store does not serve them' );
			}
		}
	}

	/** A token is nothing the shopper can do anything about, so it stays in the log. */
	public function test_no_refusal_puts_the_token_in_front_of_the_shopper(): void {
		foreach ( self::CHECKED as $cause => $coverage ) {
			$this->assertFalse(
				stripos( Cdfrete_Frontend_Calculator::refuse( $cause, $coverage )['message'], 'token' ),
				$cause . ' must not name the token'
			);
		}
	}

	/**
	 * An unfinished zone fails the same way on the next attempt, so the answer must not send the
	 * shopper back to the button.
	 */
	public function test_the_unfinished_zone_does_not_suggest_trying_again(): void {
		$message = self::neutral_answer()['message'];

		$this->assertFalse( stripos( $message, 'tente novamente' ) );
		$this->assertFalse( stripos( $message, 'tente mais tarde' ) );
	}

	/**
	 * The verdict the code reaches, read apart from the sentences: only the calculator switch
	 * the merchant turned off proves this region gets no quote.
	 */
	public function test_only_a_switch_the_merchant_turned_off_counts_as_checked(): void {
		$this->assertSame(
			Cdfrete_Frontend_Calculator::COVERAGE_RULED_OUT,
			Cdfrete_Frontend_Calculator::coverage_verdict( Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF )
		);

		foreach ( [
			Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE,
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
			Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN,
		] as $state ) {
			$this->assertSame(
				Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN,
				Cdfrete_Frontend_Calculator::coverage_verdict( $state ),
				$state . ' proves nothing about coverage'
			);
		}
	}

	/**
	 * End to end for the path the AJAX handler takes: a state the code reached can never produce
	 * a sentence about coverage unless a switch was read.
	 */
	public function test_a_state_that_proves_nothing_never_produces_a_sentence_about_the_region(): void {
		$region_off = Cdfrete_Frontend_Calculator::refuse(
			Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF,
			Cdfrete_Frontend_Calculator::coverage_verdict( Cdfrete_Frontend_Calculator::QUOTE_CALCULATOR_OFF )
		)['message'];

		foreach ( [
			Cdfrete_Frontend_Calculator::QUOTE_NO_ZONE,
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
			Cdfrete_Frontend_Calculator::QUOTE_NO_TOKEN,
		] as $state ) {
			$this->assertNotSame(
				$region_off,
				Cdfrete_Frontend_Calculator::refuse( $state, Cdfrete_Frontend_Calculator::coverage_verdict( $state ) )['message'],
				$state . ' must not read as a region the merchant switched off'
			);
		}
	}

	private static function neutral_answer(): array {
		return Cdfrete_Frontend_Calculator::refuse(
			Cdfrete_Frontend_Calculator::QUOTE_NEVER_SAVED,
			Cdfrete_Frontend_Calculator::COVERAGE_UNKNOWN
		);
	}
}
