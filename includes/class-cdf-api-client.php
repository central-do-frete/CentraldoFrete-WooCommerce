<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDF_API_Client {

	private const API_URL = 'https://api.centraldofrete.com/';

	private string $token;
	private int    $timeout;

	public function __construct( string $token, int $timeout = 15 ) {
		$this->token   = $token;
		$this->timeout = $timeout;
	}

	/**
	 * Build request headers.
	 */
	private function headers(): array {
		return [
			'Authorization' => $this->token,
			'Content-Type'  => 'application/json',
			'source'        => 'WORDPRESS',
		];
	}

	/**
	 * Make a GET request with retry.
	 *
	 * @return array|WP_Error
	 */
	private function get( string $endpoint ) {
		$url  = self::API_URL . $endpoint;
		$args = [
			'headers' => $this->headers(),
			'timeout' => $this->timeout,
		];

		self::log( 'debug', sprintf( '[API] GET %s (timeout: %ds)', $endpoint, $this->timeout ) );

		$start    = microtime( true );
		$response = wp_remote_get( $url, $args );
		$elapsed  = round( ( microtime( true ) - $start ) * 1000, 2 );

		// Retry once on timeout/connection error.
		if ( is_wp_error( $response ) ) {
			self::log( 'warning', sprintf(
				'[API] GET %s falhou em %.2fms (%s), tentando novamente...',
				$endpoint,
				$elapsed,
				$response->get_error_message()
			) );

			$start    = microtime( true );
			$response = wp_remote_get( $url, $args );
			$elapsed  = round( ( microtime( true ) - $start ) * 1000, 2 );

			if ( is_wp_error( $response ) ) {
				self::log( 'error', sprintf(
					'[API] GET %s falhou novamente em %.2fms: %s',
					$endpoint,
					$elapsed,
					$response->get_error_message()
				) );
			}
		}

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			self::log( 'debug', sprintf(
				'[API] GET %s respondeu HTTP %d em %.2fms',
				$endpoint,
				$code,
				$elapsed
			) );
		}

		return $response;
	}

	/**
	 * Make a POST request with retry.
	 *
	 * @return array|WP_Error
	 */
	private function post( string $endpoint, array $body ) {
		$url  = self::API_URL . $endpoint;
		$args = [
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
			'timeout' => $this->timeout,
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log( 'warning', sprintf( 'POST %s falhou, tentando novamente: %s', $endpoint, $response->get_error_message() ) );
			$response = wp_remote_post( $url, $args );
		}

		return $response;
	}

	/**
	 * Validate token by making a test request.
	 */
	public function validate_token(): bool {
		$response = $this->get( 'v1/quotation' );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Fetch cargo types from the API.
	 *
	 * @return array|false
	 */
	public function get_cargo_types() {
		self::log( 'info', '[CARGO] Buscando tipos de carga da API' );

		$response = $this->get( 'v1/cargo-type' );

		if ( is_wp_error( $response ) ) {
			self::log( 'error', '[CARGO] Erro ao buscar tipos de carga: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );

		self::log( 'debug', sprintf( '[CARGO] HTTP %d - Body: %s', $code, substr( $raw_body, 0, 500 ) ) );

		if ( $code !== 200 ) {
			self::log( 'error', sprintf( '[CARGO] API cargo-type retornou HTTP %d: %s', $code, $raw_body ) );
			return false;
		}

		$body = json_decode( $raw_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			self::log( 'error', '[CARGO] Erro ao decodificar JSON: ' . json_last_error_msg() );
			return false;
		}

		if ( ! is_array( $body ) ) {
			self::log( 'error', '[CARGO] Resposta não é um array: ' . gettype( $body ) );
			return false;
		}

		// Handle different response structures.
		// Some APIs return { data: [...] } or { cargo_types: [...] }
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = $body['data'];
		} elseif ( isset( $body['cargo_types'] ) && is_array( $body['cargo_types'] ) ) {
			$body = $body['cargo_types'];
		}

		self::log( 'info', sprintf( '[CARGO] %d tipos de carga carregados', count( $body ) ) );

		return $body;
	}

	/**
	 * Request a shipping quotation.
	 *
	 * @param string     $from        Origin ZIP code.
	 * @param string     $to          Destination ZIP code.
	 * @param array      $volumes     List of volume arrays.
	 * @param array      $cargo_types List of cargo type IDs.
	 * @param float      $invoice     Invoice amount.
	 * @param array|null $recipient   Optional recipient data (document, name).
	 *
	 * @return array|false Array of shipping options or false on failure.
	 */
	public function get_quotation( string $from, string $to, array $volumes, array $cargo_types, float $invoice, ?array $recipient = null ) {
		$payload = [
			'cargo_types'    => array_values( array_unique( $cargo_types ) ),
			'volumes'        => $volumes,
			'invoice_amount' => $invoice,
			'from'           => $from,
			'to'             => $to,
		];

		// Add recipient if provided.
		if ( ! empty( $recipient ) && ! empty( $recipient['document'] ) ) {
			$payload['recipient'] = [
				'document' => $recipient['document'],
				'name'     => $recipient['name'] ?? '',
			];
		}

		self::log( 'info', 'Solicitando cotação: ' . wp_json_encode( $payload ) );

		// Step 1: Create quotation.
		$response = $this->post( 'v1/quotation', $payload );

		if ( is_wp_error( $response ) ) {
			self::log( 'error', 'Erro ao criar cotação: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::log( 'error', sprintf( 'API quotation retornou HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['code'] ) ) {
			self::log( 'error', 'Resposta da cotação sem código: ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		$quotation_code = $body['code'];

		// Step 2: Fetch quotation results.
		$results_response = $this->get( 'v1/quotation/' . $quotation_code );

		if ( is_wp_error( $results_response ) ) {
			self::log( 'error', 'Erro ao buscar resultados da cotação: ' . $results_response->get_error_message() );
			return false;
		}

		$results_code = wp_remote_retrieve_response_code( $results_response );
		if ( $results_code !== 200 ) {
			self::log( 'error', sprintf( 'API quotation/%s retornou HTTP %d', $quotation_code, $results_code ) );
			return false;
		}

		$results = json_decode( wp_remote_retrieve_body( $results_response ), true );

		if ( empty( $results['prices'] ) || ! is_array( $results['prices'] ) ) {
			self::log( 'warning', 'Nenhum resultado de frete encontrado para a cotação ' . $quotation_code );
			return [];
		}

		// Attach quotation code to each result and capture all available fields.
		$services = [];
		foreach ( $results['prices'] as $price ) {
			// dispatch = "Balcão" means customer must go to carrier (no pickup).
			// dispatch = "Coleta" or other means carrier picks up.
			$dispatch       = $price['dispatch'] ?? '';
			$requires_drop  = ( mb_strtolower( $dispatch ) === 'balcão' );

			$services[] = [
				'id'               => $price['id'] ?? '',
				'price'            => (float) ( $price['price'] ?? 0 ),
				'shipping_carrier' => $price['shipping_carrier'] ?? '',
				'carrier_logo'     => $price['logo'] ?? '',
				'delivery_time'    => (int) ( $price['delivery_time'] ?? 0 ),
				'service_type'     => $price['service_type'] ?? '',
				'modal'            => $price['modal'] ?? '',
				'dispatch'         => $dispatch,
				'delivery'         => $price['delivery'] ?? '',
				'requires_drop'    => $requires_drop,
				'quotation_code'   => $quotation_code,
			];
		}

		self::log( 'info', sprintf( 'Cotação %s retornou %d opções de frete', $quotation_code, count( $services ) ) );

		return $services;
	}

	/**
	 * Log a message to WooCommerce logs.
	 */
	public static function log( string $level, string $message ): void {
		// Check if debug mode is enabled (except for errors which always log).
		if ( $level !== 'error' && $level !== 'warning' ) {
			$settings = CDF_Shipping_Method::get_settings();
			if ( ( $settings['debug'] ?? 'no' ) !== 'yes' ) {
				return;
			}
		}

		try {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->log( $level, $message, [ 'source' => 'central-do-frete' ] );
			}
		} catch ( \Exception $e ) {
			// Silently fail if logging is not available.
		}
	}
}
