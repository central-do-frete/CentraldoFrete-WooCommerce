<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cdfrete_Frontend_Calculator {

	public static function init(): void {
		add_action( 'woocommerce_after_add_to_cart_form', [ __CLASS__, 'render_calculator' ] );
		add_action( 'wp_ajax_cdfrete_calculate_shipping', [ __CLASS__, 'ajax_calculate' ] );
		add_action( 'wp_ajax_nopriv_cdfrete_calculate_shipping', [ __CLASS__, 'ajax_calculate' ] );
	}

	/**
	 * Render the shipping calculator on the product page.
	 */
	public static function render_calculator(): void {
		if ( ! self::any_instance_offers_the_calculator() ) {
			return;
		}

		$product = wc_get_product( get_the_ID() );

		if ( ! $product || ! self::is_product_served( $product ) ) {
			return;
		}

		// Enqueue assets.
		wp_enqueue_style(
			'cdfrete-calculator',
			CDFRETE_PLUGIN_URL . 'assets/css/cdfrete-calculator.css',
			[],
			CDFRETE_VERSION
		);

		wp_enqueue_script(
			'cdfrete-calculator',
			CDFRETE_PLUGIN_URL . 'assets/js/cdfrete-calculator.js',
			[],
			CDFRETE_VERSION,
			true
		);

		wp_localize_script( 'cdfrete-calculator', 'cdfrete_params', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'cdfrete_calculate_nonce' ),
			'product_id' => get_the_ID(),
			'i18n'       => [
				'calculate'       => __( 'Calcular', 'central-do-frete' ),
				'calculating'     => __( 'Calculando...', 'central-do-frete' ),
				'invalidPostcode' => __( 'Digite um CEP válido com 8 números.', 'central-do-frete' ),
				'requestFailed'   => __( 'Erro ao calcular frete.', 'central-do-frete' ),
				'connectionError' => __( 'Erro de conexão. Tente novamente.', 'central-do-frete' ),
				'noRates'         => __( 'Nenhuma opção de frete disponível.', 'central-do-frete' ),
				'carrierColumn'   => __( 'Transportadora', 'central-do-frete' ),
				'timeColumn'      => __( 'Prazo', 'central-do-frete' ),
				'priceColumn'     => __( 'Valor', 'central-do-frete' ),
			],
		] );

		// Load template.
		$template = CDFRETE_PLUGIN_DIR . 'templates/product-shipping-calculator.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Handle AJAX shipping calculation.
	 */
	public static function ajax_calculate(): void {
		$start_time = microtime( true );

		check_ajax_referer( 'cdfrete_calculate_nonce', 'nonce' );

		$postcode   = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ) );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$quantity   = max( 1, absint( $_POST['quantity'] ?? 1 ) );

		Cdfrete_API_Client::log( 'info', sprintf(
			'[CALC] Iniciando cotação - Produto: %d, CEP: %s, Qtd: %d',
			$product_id,
			$postcode,
			$quantity
		) );

		if ( strlen( $postcode ) !== 8 ) {
			Cdfrete_API_Client::log( 'warning', '[CALC] CEP inválido: ' . $postcode );
			wp_send_json_error( [ 'message' => __( 'CEP inválido. Digite 8 números.', 'central-do-frete' ) ] );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			Cdfrete_API_Client::log( 'error', '[CALC] Produto não encontrado: ' . $product_id );
			wp_send_json_error( [ 'message' => __( 'Produto não encontrado.', 'central-do-frete' ) ] );
		}

		// A quote is derived from weight, size and price, so it must not describe a product
		// the shop has not published. Variations inherit their parent's status.
		$published = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

		if ( ! $published || 'publish' !== $published->get_status() ) {
			Cdfrete_API_Client::log( 'warning', sprintf( '[CALC] Produto %d não está publicado', $product_id ) );
			wp_send_json_error( [ 'message' => __( 'Produto não encontrado.', 'central-do-frete' ) ] );
		}

		// The postcode the shopper typed is the destination, so it is also what decides which
		// shipping zone - and therefore which token, fee, class restriction and display rules -
		// govern this quote. The state is deliberately left out: the session may hold one from
		// another address, and a wrong state matches a wrong zone.
		$settings = Cdfrete_Shipping_Method::get_settings_for_destination( [
			'country'  => 'BR',
			'state'    => '',
			'postcode' => $postcode,
		] );

		if ( empty( $settings ) ) {
			Cdfrete_API_Client::log( 'warning', sprintf(
				'[CALC] Nenhuma área de entrega com a Central do Frete corresponde ao CEP %s',
				$postcode
			) );
			wp_send_json_error( [ 'message' => __( 'Não atendemos este CEP.', 'central-do-frete' ) ] );
		}

		// The restriction of the zone that answers, not of any zone that happens to allow it.
		$product_class = Cdfrete_Shipping_Class_Rule::class_of_product( $product );

		if ( ! Cdfrete_Shipping_Class_Rule::allows_for_settings( [ $product_class ], $settings ) ) {
			Cdfrete_API_Client::log( 'debug', sprintf(
				'[CALC] Produto %d fora da restrição de classe de entrega (classe: %s)',
				$product_id,
				$product_class
			) );
			wp_send_json_error( [ 'message' => __( 'Este produto não é cotado pela Central do Frete.', 'central-do-frete' ) ] );
		}

		if ( empty( $settings['token'] ) ) {
			Cdfrete_API_Client::log( 'error', '[CALC] Token não configurado' );
			wp_send_json_error( [ 'message' => __( 'Plugin não configurado.', 'central-do-frete' ) ] );
		}

		// Empty is allowed: the service then quotes from the pickup address of the account.
		$from = Cdfrete_Shipping_Method::store_origin_postcode();

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

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[CALC] Dimensões - Produto: %s, H: %.2f, W: %.2f, L: %.2f, Peso: %.2fkg',
			$product->get_name(),
			$height,
			$width,
			$length,
			$weight
		) );

		$cargo_type = Cdfrete_Product_Fields::get_cargo_type( (int) $product_id );
		if ( empty( $cargo_type ) ) {
			$cargo_type = $settings['default_cargo_type'] ?? '';
		}
		$cargo_types = ! empty( $cargo_type ) ? [ (int) $cargo_type ] : [];

		Cdfrete_API_Client::log( 'debug', '[CALC] Tipo de carga: ' . ( $cargo_type ?: 'não definido' ) );

		$invoice = (float) $product->get_price() * $quantity;

		// Check cache.
		$cache_key = Cdfrete_Cache::build_key(
			Cdfrete_API_Client::account_scope( (string) $settings['token'] ),
			$from,
			$postcode,
			$volumes,
			$cargo_types
		);
		$services  = Cdfrete_Cache::get( $cache_key );

		if ( $services !== false ) {
			Cdfrete_API_Client::log( 'info', sprintf(
				'[CALC] Cache HIT - Key: %s, %d opções',
				substr( $cache_key, 0, 20 ) . '...',
				count( $services )
			) );
		} else {
			Cdfrete_API_Client::log( 'info', '[CALC] Cache MISS - Consultando API' );

			$timeout = (int) ( $settings['api_timeout'] ?? 15 );
			$client  = new Cdfrete_API_Client( $settings['token'], $timeout );

			$services = $client->get_quotation( $from, $postcode, $volumes, $cargo_types, $invoice );

			if ( $services === false ) {
				Cdfrete_API_Client::log( 'error', '[CALC] Falha na API' );
				wp_send_json_error( [ 'message' => __( 'Erro ao consultar frete. Tente novamente.', 'central-do-frete' ) ] );
			}

			$ttl = Cdfrete_Cache::ttl_from_setting( $settings['cache_ttl'] ?? '1h' );
			Cdfrete_Cache::set( $cache_key, $services, $ttl );

			Cdfrete_API_Client::log( 'info', sprintf(
				'[CALC] API retornou %d opções, cache salvo (TTL: %ds)',
				count( $services ),
				$ttl
			) );
		}

		if ( empty( $services ) ) {
			Cdfrete_API_Client::log( 'warning', '[CALC] Nenhuma opção de frete retornada' );
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

			Cdfrete_API_Client::log( 'debug', sprintf(
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

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[CALC] Limite "%s": %d -> %d opções',
			$display_limit,
			$before_limit,
			count( $services )
		) );

		if ( empty( $services ) ) {
			Cdfrete_API_Client::log( 'warning', '[CALC] Todas as opções filtradas' );
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
				// Not escaped here on purpose: these travel as JSON and the script escapes them
				// when it inserts them. Escaping twice renders "A & B" as "A &amp; B".
				'carrier'       => $service['shipping_carrier'],
				'service_type'  => $service['service_type'] ?? '',
				'price'         => number_format( $cost, 2, ',', '.' ),
				'delivery_time' => sprintf(
					/* translators: %d: number of business days until delivery */
					_n( '%d dia útil', '%d dias úteis', $days, 'central-do-frete' ),
					$days
				),
				'logo'          => $show_carrier_logo ? esc_url( $service['carrier_logo'] ?? '' ) : '',
			];
		}

		$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		Cdfrete_API_Client::log( 'info', sprintf(
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
	 * The product page has no destination yet, so the calculator shows up when any enabled
	 * instance is configured to offer it. Which zone answers is decided once the shopper
	 * types a postcode.
	 */
	private static function any_instance_offers_the_calculator(): bool {
		foreach ( Cdfrete_Shipping_Method::get_all_settings() as $settings ) {
			if ( ! empty( $settings['token'] ) && ( $settings['product_calculator'] ?? 'yes' ) === 'yes' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The product page has no shipping zone context, so the calculator shows up when any
	 * enabled instance of the method serves this product's shipping class.
	 *
	 * @param WC_Product $product Product being displayed or quoted.
	 */
	private static function is_product_served( $product ): bool {
		$instances = Cdfrete_Shipping_Method::get_all_settings();

		if ( empty( $instances ) ) {
			return true;
		}

		$classes = [ Cdfrete_Shipping_Class_Rule::class_of_product( $product ) ];

		foreach ( $instances as $settings ) {
			if ( Cdfrete_Shipping_Class_Rule::allows_for_settings( $classes, $settings ) ) {
				return true;
			}
		}

		return false;
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

				Cdfrete_API_Client::log( 'debug', sprintf(
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
					Cdfrete_API_Client::log( 'debug', '[CALC] economic_express - Mesma opção (mais barata = mais rápida)' );
					return [ $cheapest ];
				}

				return [ $cheapest, $fastest ];

			case 'all':
			default:
				return $services;
		}
	}
}
