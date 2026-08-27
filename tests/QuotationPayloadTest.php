<?php

use PHPUnit\Framework\TestCase;

/**
 * A store that never filled in its postcode used to get no quote at all. It now quotes from
 * the pickup address registered in the Central do Frete account, which the service supplies
 * only when the request leaves `from` out - an empty `from` is a present value and is rejected
 * by the eight character rule before the fallback is ever reached.
 */
class QuotationPayloadTest extends TestCase {

	private const VOLUMES = [ [ 'quantity' => 1, 'width' => 10.0, 'height' => 8.0, 'length' => 4.0, 'weight' => 3.0 ] ];

	private function payload( string $from, ?array $recipient = null ): array {
		return Cdfrete_API_Client::build_quotation_payload( $from, '30240440', self::VOLUMES, [ 13 ], 201.92, $recipient );
	}

	public function test_a_store_without_a_postcode_sends_no_origin_at_all(): void {
		$this->assertArrayNotHasKey( 'from', $this->payload( '' ) );
	}

	public function test_the_rest_of_the_quote_is_still_sent_without_an_origin(): void {
		$payload = $this->payload( '' );

		$this->assertSame( '30240440', $payload['to'] );
		$this->assertSame( self::VOLUMES, $payload['volumes'] );
		$this->assertSame( [ 13 ], $payload['cargo_types'] );
		$this->assertSame( 201.92, $payload['invoice_amount'] );
	}

	public function test_a_configured_postcode_is_sent_as_the_origin(): void {
		$this->assertSame( '09531190', $this->payload( '09531190' )['from'] );
	}

	public function test_the_recipient_is_sent_only_when_the_checkout_had_a_document(): void {
		$this->assertArrayNotHasKey( 'recipient', $this->payload( '09531190' ) );
		$this->assertArrayNotHasKey( 'recipient', $this->payload( '09531190', [ 'name' => 'Fulano' ] ) );

		$this->assertSame(
			[ 'document' => '22531311000110', 'name' => 'Fulano' ],
			$this->payload( '09531190', [ 'document' => '22531311000110', 'name' => 'Fulano' ] )['recipient']
		);
	}

	public function test_a_repeated_cargo_type_is_sent_once_and_as_a_list(): void {
		$payload = Cdfrete_API_Client::build_quotation_payload( '', '30240440', self::VOLUMES, [ 13, 37, 13 ], 10.0 );

		$this->assertSame( [ 13, 37 ], $payload['cargo_types'] );
	}
}
