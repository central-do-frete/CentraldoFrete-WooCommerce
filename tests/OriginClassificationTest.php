<?php

use PHPUnit\Framework\TestCase;

/**
 * The store postcode has three states, not two, and the plugin has to keep them apart.
 *
 * The service accepts an origin of exactly eight characters and falls back to the pickup
 * address of the account only when the key is absent, so a postcode that is filled in but
 * malformed used to leave as a value the service rejected: every quote failed while the
 * settings screen reported the origin as working. It is now classified where the origin is
 * produced, so the payload rule holds by construction and the notice can name the real problem.
 */
class OriginClassificationTest extends TestCase {

	private const VOLUMES = [ [ 'quantity' => 1, 'width' => 10.0, 'height' => 8.0, 'length' => 4.0, 'weight' => 3.0 ] ];

	public function test_eight_digits_are_an_origin_the_service_accepts(): void {
		$origin = Cdfrete_Shipping_Method::classify_origin( '09531190' );

		$this->assertSame( Cdfrete_Shipping_Method::ORIGIN_VALID, $origin['status'] );
		$this->assertSame( '09531190', $origin['postcode'] );
	}

	public function test_a_postcode_typed_with_a_dash_is_still_valid(): void {
		$origin = Cdfrete_Shipping_Method::classify_origin( '09531-190' );

		$this->assertSame( Cdfrete_Shipping_Method::ORIGIN_VALID, $origin['status'] );
		$this->assertSame( '09531190', $origin['postcode'] );
	}

	public function test_a_store_that_never_filled_one_in_is_missing_rather_than_malformed(): void {
		foreach ( [ '', '   ', '-', 'CEP' ] as $raw ) {
			$this->assertSame(
				Cdfrete_Shipping_Method::ORIGIN_MISSING,
				Cdfrete_Shipping_Method::classify_origin( $raw )['status'],
				sprintf( 'Store postcode %s should read as missing', var_export( $raw, true ) )
			);
		}
	}

	public function test_a_postcode_that_is_not_eight_digits_is_malformed(): void {
		foreach ( [ '1234-567', '1234567', '095311901', '01310' ] as $raw ) {
			$this->assertSame(
				Cdfrete_Shipping_Method::ORIGIN_MALFORMED,
				Cdfrete_Shipping_Method::classify_origin( $raw )['status'],
				sprintf( 'Store postcode %s should read as malformed', var_export( $raw, true ) )
			);
		}
	}

	public function test_a_malformed_postcode_yields_no_origin_to_send(): void {
		$this->assertSame( '', Cdfrete_Shipping_Method::classify_origin( '1234-567' )['postcode'] );
	}

	public function test_the_merchant_is_shown_the_postcode_as_it_was_typed(): void {
		$this->assertSame( '1234-567', Cdfrete_Shipping_Method::classify_origin( '  1234-567  ' )['typed'] );
	}

	/**
	 * The two halves of the rule together: whatever the merchant typed, the origin that reaches
	 * the request body is either eight digits or no `from` key at all. A present-but-wrong value
	 * fails the service's validation before the account fallback is ever reached.
	 *
	 * @dataProvider unusable_postcodes
	 */
	public function test_an_origin_the_service_would_reject_never_reaches_the_request( string $raw ): void {
		$payload = Cdfrete_API_Client::build_quotation_payload(
			Cdfrete_Shipping_Method::classify_origin( $raw )['postcode'],
			'30240440',
			self::VOLUMES,
			[ 13 ],
			201.92
		);

		$this->assertArrayNotHasKey( 'from', $payload );
	}

	public function unusable_postcodes(): array {
		return [
			'never filled in'    => [ '' ],
			'whitespace only'    => [ ' ' ],
			'seven digits'       => [ '1234-567' ],
			'nine digits'        => [ '095311901' ],
			'letters'            => [ 'sem cep' ],
		];
	}

	public function test_a_usable_postcode_still_reaches_the_request(): void {
		$payload = Cdfrete_API_Client::build_quotation_payload(
			Cdfrete_Shipping_Method::classify_origin( '09531-190' )['postcode'],
			'30240440',
			self::VOLUMES,
			[ 13 ],
			201.92
		);

		$this->assertSame( '09531190', $payload['from'] );
	}
}
