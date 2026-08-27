<?php

use PHPUnit\Framework\TestCase;

/**
 * The origin is allowed to be empty now: the service then quotes from the pickup address of
 * the Central do Frete account. That makes the key responsible for two things it was not
 * before - telling an empty origin apart from a configured one, and telling two accounts
 * apart, because with no origin in the request the account is what decides where the freight
 * leaves from.
 */
class CacheKeyTest extends TestCase {

	private const ACCOUNT_A = 'aaaaaaaaaaaaaaaa';
	private const ACCOUNT_B = 'bbbbbbbbbbbbbbbb';

	private const VOLUMES = [ [ 'quantity' => 1, 'width' => 10.0, 'height' => 8.0, 'length' => 4.0, 'weight' => 3.0 ] ];

	private function key( string $account, string $from, string $to = '30240440', ?array $recipient = null ): string {
		return Cdfrete_Cache::build_key( $account, $from, $to, self::VOLUMES, [ 13 ], $recipient );
	}

	public function test_an_empty_origin_does_not_collide_with_a_configured_one(): void {
		$this->assertNotSame(
			$this->key( self::ACCOUNT_A, '' ),
			$this->key( self::ACCOUNT_A, '09531190' )
		);
	}

	public function test_an_empty_origin_is_stable_for_the_same_account(): void {
		$this->assertSame(
			$this->key( self::ACCOUNT_A, '' ),
			$this->key( self::ACCOUNT_A, '' )
		);
	}

	public function test_two_accounts_quoting_without_an_origin_get_separate_entries(): void {
		$this->assertNotSame(
			$this->key( self::ACCOUNT_A, '' ),
			$this->key( self::ACCOUNT_B, '' )
		);
	}

	public function test_two_accounts_quoting_from_the_same_origin_also_get_separate_entries(): void {
		$this->assertNotSame(
			$this->key( self::ACCOUNT_A, '09531190' ),
			$this->key( self::ACCOUNT_B, '09531190' )
		);
	}

	public function test_the_destination_still_separates_entries(): void {
		$this->assertNotSame(
			$this->key( self::ACCOUNT_A, '', '30240440' ),
			$this->key( self::ACCOUNT_A, '', '01310100' )
		);
	}

	public function test_the_recipient_still_separates_entries(): void {
		$this->assertNotSame(
			$this->key( self::ACCOUNT_A, '' ),
			$this->key( self::ACCOUNT_A, '', '30240440', [ 'document' => '22531311000110', 'name' => 'Fulano' ] )
		);
	}

	public function test_every_key_carries_the_plugin_prefix(): void {
		$this->assertStringStartsWith( 'cdfrete_quote_', $this->key( self::ACCOUNT_A, '' ) );
	}
}
