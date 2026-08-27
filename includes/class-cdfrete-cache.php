<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cdfrete_Cache {

	private const PREFIX = 'cdfrete_quote_';

	/**
	 * Cache version - increment this when data structure changes.
	 * This ensures old cached data without new fields (like carrier_logo) is invalidated.
	 */
	private const VERSION = 3;

	/**
	 * Generate a cache key from shipping parameters.
	 *
	 * `$account` is `Cdfrete_API_Client::account_scope()` for the token that will be quoted
	 * with. Prices depend on the account, and an empty `$from` means the origin is the pickup
	 * address of that account, so two shipping zones carrying different tokens must not read
	 * each other's entries. An empty `$from` cannot collide with a configured one either:
	 * `Cdfrete_Shipping_Method::classify_origin()` lets through eight digits or nothing at all.
	 */
	public static function build_key( string $account, string $from, string $to, array $volumes, array $cargo_types, ?array $recipient = null ): string {
		$data = wp_json_encode( [
			'v'           => self::VERSION,
			'account'     => $account,
			'from'        => $from,
			'to'          => $to,
			'volumes'     => $volumes,
			'cargo_types' => $cargo_types,
			'recipient'   => $recipient,
		] );
		return self::PREFIX . md5( $data );
	}

	/**
	 * Get cached quotation results.
	 *
	 * @return array|false
	 */
	public static function get( string $key ) {
		return get_transient( $key );
	}

	/**
	 * Store quotation results in cache.
	 */
	public static function set( string $key, array $data, int $ttl = HOUR_IN_SECONDS ): void {
		set_transient( $key, $data, $ttl );
	}

	/**
	 * Delete a specific cache entry.
	 */
	public static function delete( string $key ): void {
		delete_transient( $key );
	}

	/**
	 * Flush all quotation caches.
	 */
	public static function flush_all(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::PREFIX . '%',
				'_transient_timeout_' . self::PREFIX . '%'
			)
		);
	}

	/**
	 * Get TTL in seconds from settings string.
	 */
	public static function ttl_from_setting( string $setting ): int {
		$map = [
			'15min' => 15 * MINUTE_IN_SECONDS,
			'30min' => 30 * MINUTE_IN_SECONDS,
			'1h'    => HOUR_IN_SECONDS,
			'2h'    => 2 * HOUR_IN_SECONDS,
		];
		return $map[ $setting ] ?? HOUR_IN_SECONDS;
	}
}
