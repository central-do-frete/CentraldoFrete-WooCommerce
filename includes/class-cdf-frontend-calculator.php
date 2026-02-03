<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDF_Frontend_Calculator {

	public static function init(): void {
		add_action( 'woocommerce_after_add_to_cart_form', [ __CLASS__, 'render_calculator' ] );
		add_action( 'wp_ajax_cdf_calculate_shipping', [ __CLASS__, 'ajax_calculate' ] );
		add_action( 'wp_ajax_nopriv_cdf_calculate_shipping', [ __CLASS__, 'ajax_calculate' ] );
	}

	/**
	 * Render the shipping calculator on the product page.
	 */
	public static function render_calculator(): void {
		$settings = CDF_Shipping_Method::get_settings();

		if ( empty( $settings['token'] ) ) {
			return;
		}

		if ( ( $settings['product_calculator'] ?? 'yes' ) !== 'yes' ) {
			return;
		}

		// Enqueue assets.
		wp_enqueue_style(
			'cdf-calculator',
			CDF_PLUGIN_URL . 'assets/css/cdf-calculator.css',
			[],
			CDF_VERSION
		);

		wp_enqueue_script(
			'cdf-calculator',
			CDF_PLUGIN_URL . 'assets/js/cdf-calculator.js',
			[],
			CDF_VERSION,
			true
		);

		wp_localize_script( 'cdf-calculator', 'cdf_params', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'cdf_calculate_nonce' ),
			'product_id' => get_the_ID(),
		] );

		// Load template.
		$template = CDF_PLUGIN_DIR . 'templates/product-shipping-calculator.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Handle AJAX shipping calculation.
	 */
	public static function ajax_calculate(): void {
		$start_time = microtime( true );

		check_ajax_referer( 'cdf_calculate_nonce', 'nonce' );

		$postcode   = preg_replace( '/\D/', '', sanitize_text_field( $_POST['postcode'] ?? '' ) );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$quantity   = max( 1, absint( $_POST['quantity'] ?? 1 ) );

		CDF_API_Client::log( 'info', sprintf(
			'[CALC] Iniciando cotação - Produto: %d, CEP: %s, Qtd: %d',
			$product_id,
			$postcode,
			$quantity
		) );

		if ( strlen( $postcode ) !== 8 ) {
			CDF_API_Client::log( 'warning', '[CALC] CEP inválido: ' . $postcode );
			wp_send_json_error( [ 'message' => __( 'CEP inválido. Digite 8 números.', 'central-do-frete' ) ] );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			CDF_API_Client::log( 'error', '[CALC] Produto não encontrado: ' . $product_id );
			wp_send_json_error( [ 'message' => __( 'Produto não encontrado.', 'central-do-frete' ) ] );
		}

		$settings = CDF_Shipping_Method::get_settings();
		if ( empty( $settings['token'] ) ) {
			CDF_API_Client::log( 'error', '[CALC] Token não configurado' );
			wp_send_json_error( [ 'message' => __( 'Plugin não configurado.', 'central-do-frete' ) ] );
		}

		$from = preg_replace( '/\D/', '', get_option( 'woocommerce_store_postcode', '' ) );
		if ( empty( $from ) ) {
			CDF_API_Client::log( 'error', '[CALC] CEP de origem não configurado' );
			wp_send_json_error( [ 'message' => __( 'CEP de origem não configurado.', 'central-do-frete' ) ] );
		}

		$default_height = (float) str_replace( ',', '.', $settings['default_height'] ?? '2' );
		$default_width  = (float) str_replace( ',', '.', $settings['default_width'] ?? '11' );
		$default_length = (float) str_replace( ',', '.', $settings['default_length'] ?? '16' );
		$default_weight = (float) str_replace( ',', '.', $settings['default_weight'] ?? '0.3' );

		$height = (float) $product->get_height() ?: $default_height;
		$width  = (float) $product->get_width()  ?: $default_width;
		$length = (float) $product->get_length() ?: $default_length;
		$weight = (float) $product->get_weight() ?: $default_weight;

		$volumes = [ [
			'quantity' => $quantity,
			'width'    => $width,
			'height'   => $height,
			'length'   => $length,
			'weight'   => $weight,
		] ];

		CDF_API_Client::log( 'debug', sprintf(
			'[CALC] Dimensões - Produto: %s, H: %.2f, W: %.2f, L: %.2f, Peso: %.2fkg',
			$product->get_name(),
			$height,
			$width,
			$length,
			$weight
		) );

		$cargo_type = get_post_meta( $product_id, 'cargo_type', true );
		if ( empty( $cargo_type ) ) {
			$cargo_type = $settings['default_cargo_type'] ?? '';
		}
		$cargo_types = ! empty( $cargo_type ) ? [ (int) $cargo_type ] : [];

		CDF_API_Client::log( 'debug', '[CALC] Tipo de carga: ' . ( $cargo_type ?: 'não definido' ) );

		$invoice = (float) $product->get_price() * $quantity;

		// Check cache.
		$cache_key = CDF_Cache::build_key( $from, $postcode, $volumes, $cargo_types );
		$services  = CDF_Cache::get( $cache_key );

		if ( $services !== false ) {
			CDF_API_Client::log( 'info', sprintf(
				'[CALC] Cache HIT - Key: %s, %d opções',
				substr( $cache_key, 0, 20 ) . '...',
				count( $services )
			) );
		} else {
			CDF_API_Client::log( 'info', '[CALC] Cache MISS - Consultando API' );

			$timeout = (int) ( $settings['api_timeout'] ?? 15 );
			$client  = new CDF_API_Client( $settings['token'], $timeout );

			$services = $client->get_quotation( $from, $postcode, $volumes, $cargo_types, $invoice );

			if ( $services === false ) {
				CDF_API_Client::log( 'error', '[CALC] Falha na API' );
				wp_send_json_error( [ 'message' => __( 'Erro ao consultar frete. Tente novamente.', 'central-do-frete' ) ] );
			}

			$ttl = CDF_Cache::ttl_from_setting( $settings['cache_ttl'] ?? '1h' );
			CDF_Cache::set( $cache_key, $services, $ttl );

			CDF_API_Client::log( 'info', sprintf(
				'[CALC] API retornou %d opções, cache salvo (TTL: %ds)',
				count( $services ),
				$ttl
			) );
		}

		if ( empty( $services ) ) {
			CDF_API_Client::log( 'warning', '[CALC] Nenhuma opção de frete retornada' );
			wp_send_json_error( [ 'message' => __( 'Nenhuma opção de frete encontrada para este CEP.', 'central-do-frete' ) ] );
		}

		// Apply filters.
		$hide_no_pickup = ( $settings['hide_no_pickup'] ?? 'no' ) === 'yes';
		$display_limit  = $settings['display_limit'] ?? 'all';

		$original_count = count( $services );

		if ( $hide_no_pickup ) {
			$services = array_filter( $services, function ( $s ) {
				return empty( $s['requires_drop'] );
			} );
			$services = array_values( $services );

			CDF_API_Client::log( 'debug', sprintf(
				'[CALC] Filtro Balcão: %d -> %d opções',
				$original_count,
				count( $services )
			) );
		}

		// Sort by price.
		usort( $services, function ( $a, $b ) {
			return $a['price'] <=> $b['price'];
		} );

		// Apply display limit.
		$before_limit = count( $services );
		$services     = self::apply_display_limit( $services, $display_limit );

		CDF_API_Client::log( 'debug', sprintf(
			'[CALC] Limite "%s": %d -> %d opções',
			$display_limit,
			$before_limit,
			count( $services )
		) );

		if ( empty( $services ) ) {
			CDF_API_Client::log( 'warning', '[CALC] Todas as opções filtradas' );
			wp_send_json_error( [ 'message' => __( 'Nenhuma opção de frete disponível para este CEP.', 'central-do-frete' ) ] );
		}

		$additional_time   = (int) ( $settings['additional_time'] ?? 0 );
		$handling_fee      = (float) str_replace( ',', '.', $settings['handling_fee'] ?? '0' );
		$show_carrier_logo = ( $settings['show_carrier_logo'] ?? 'no' ) === 'yes';

		$results = [];
		foreach ( $services as $service ) {
			$days = $service['delivery_time'] + $additional_time;
			$cost = $service['price'] + $handling_fee;

			$results[] = [
				'carrier'       => esc_html( $service['shipping_carrier'] ),
				'service_type'  => esc_html( $service['service_type'] ?? '' ),
				'price'         => number_format( $cost, 2, ',', '.' ),
				'delivery_time' => sprintf(
					_n( '%d dia útil', '%d dias úteis', $days, 'central-do-frete' ),
					$days
				),
				'logo'          => $show_carrier_logo ? esc_url( $service['carrier_logo'] ?? '' ) : '',
			];
		}

		$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		CDF_API_Client::log( 'info', sprintf(
			'[CALC] Concluído em %.2fms - %d opções retornadas',
			$elapsed,
			count( $results )
		) );

		$response = [ 'rates' => $results ];

		// Add debug info if debug mode is enabled.
		if ( ( $settings['debug'] ?? 'no' ) === 'yes' ) {
			$response['_debug'] = [
				'elapsed_ms'        => $elapsed,
				'show_carrier_logo' => $show_carrier_logo,
				'raw_services'      => array_map( function( $s ) {
					return [
						'carrier'      => $s['shipping_carrier'] ?? '',
						'carrier_logo' => $s['carrier_logo'] ?? '(empty)',
					];
				}, $services ),
			];
		}

		wp_send_json_success( $response );
	}

	/**
	 * Apply display limit to services list.
	 */
	private static function apply_display_limit( array $services, string $limit ): array {
		if ( empty( $services ) ) {
			return $services;
		}

		switch ( $limit ) {
			case 'top3':
				return array_slice( $services, 0, 3 );

			case 'economic_express':
				$cheapest = $services[0];
				$fastest  = $services[0];

				foreach ( $services as $s ) {
					if ( $s['delivery_time'] < $fastest['delivery_time'] ) {
						$fastest = $s;
					}
				}

				CDF_API_Client::log( 'debug', sprintf(
					'[CALC] economic_express - Econômica: %s (R$ %.2f, %d dias) | Rápida: %s (R$ %.2f, %d dias)',
					$cheapest['shipping_carrier'],
					$cheapest['price'],
					$cheapest['delivery_time'],
					$fastest['shipping_carrier'],
					$fastest['price'],
					$fastest['delivery_time']
				) );

				// Use == instead of === to handle int/string type differences.
				if ( $cheapest['id'] == $fastest['id'] ) {
					CDF_API_Client::log( 'debug', '[CALC] economic_express - Mesma opção (mais barata = mais rápida)' );
					return [ $cheapest ];
				}

				return [ $cheapest, $fastest ];

			case 'all':
			default:
				return $services;
		}
	}
}
