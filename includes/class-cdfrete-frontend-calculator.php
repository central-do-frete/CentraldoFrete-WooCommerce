<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cdfrete_Frontend_Calculator {

	/** What the zone a shopper's postcode resolves to can do about quoting it. */
	public const QUOTE_READY           = 'ready';
	public const QUOTE_NO_ZONE         = 'no_zone';
	public const QUOTE_METHOD_OFF      = 'method_off';
	public const QUOTE_CALCULATOR_OFF  = 'calculator_off';
	public const QUOTE_NEVER_SAVED     = 'never_saved';
	public const QUOTE_NO_TOKEN        = 'no_token';

	/** A refusal that is not about the zone: the zone quotes, but not this product's class. */
	public const REFUSE_CLASS_EXCLUDED = 'class_excluded';

	/** Nor this one: carriers priced the postcode and the zone's own filters hid every one. */
	public const REFUSE_ALL_FILTERED   = 'all_filtered';

	/** Whether the plugin read a setting that withholds the quote here, or does not know. */
	public const COVERAGE_RULED_OUT = 'ruled_out';
	public const COVERAGE_UNKNOWN   = 'unknown';

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
		// govern this quote. The state is deliberately not read from the session: the session may
		// hold one from another address, and a wrong state matches a wrong zone. It is left blank
		// here and derived from this postcode by `Cdfrete_Shipping_Method::state_for_postcode()`
		// before the zone is matched, which is the state of the destination itself rather than of
		// some address it happened to be typed next to.
		$resolved = Cdfrete_Shipping_Method::resolve_for_destination( [
			'country'  => 'BR',
			'state'    => '',
			'postcode' => $postcode,
		] );

		$settings = $resolved['settings'];
		$state    = self::quote_state( $resolved['instance_id'], $settings );

		// Not dead defensive code. Drawing the widget and answering it stopped sharing one
		// instance - the form is on the page because some zone offers the calculator, while the
		// answer comes from the zone this postcode resolves to - so a zone the merchant added
		// and never finished can now be the one that answers. That was impossible while a
		// single arbitrary instance did both jobs: the zone that drew the form was the zone
		// that answered, and it had a token because otherwise nothing was drawn. Each way of
		// not quoting is a different fact, and the shopper reads only the part that is theirs.
		if ( self::QUOTE_READY !== $state ) {
			$diagnostic = self::quote_state_log( $state, $resolved['instance_id'], $postcode );

			Cdfrete_API_Client::log( $diagnostic['level'], $diagnostic['message'] );
			wp_send_json_error( self::refuse( $state, self::coverage_verdict( $state ) ) );
		}

		// The restriction of the zone that answers, not of any zone that happens to allow it.
		$product_class = Cdfrete_Shipping_Class_Rule::class_of_product( $product );

		if ( ! Cdfrete_Shipping_Class_Rule::allows_for_settings( [ $product_class ], $settings ) ) {
			Cdfrete_API_Client::log( 'debug', sprintf(
				'[CALC] Produto %d fora da restrição de classe de entrega da área de entrega #%d (classe: %s)',
				$product_id,
				(int) $resolved['instance_id'],
				$product_class
			) );

			// The rule of the zone that answers was read, so the region is settled; the rest of
			// the store was not read and keeps its own zones' rules.
			wp_send_json_error( self::refuse( self::REFUSE_CLASS_EXCLUDED, self::COVERAGE_RULED_OUT ) );
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
			$cargo_types,
			$invoice
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

		// Carriers priced this postcode and the merchant's own filter removed all of them, so the
		// one thing the plugin may not say here is that there is no freight for it.
		if ( empty( $services ) ) {
			Cdfrete_API_Client::log( 'warning', sprintf(
				'[CALC] Todas as %d opções retornadas para o CEP %s foram filtradas na área de entrega #%d',
				$original_count,
				$postcode,
				(int) $resolved['instance_id']
			) );

			wp_send_json_error( self::refuse( self::REFUSE_ALL_FILTERED, self::COVERAGE_UNKNOWN ) );
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
	 * What the zone a postcode resolves to can do about quoting it.
	 *
	 * Kept free of WordPress so the split can be tested on its own. The five ways of not
	 * quoting are separate answers because they are separate facts: no zone here carries the
	 * method at all, the zone that does was added and never saved, it was saved without a
	 * token, the merchant turned the method itself off for it, or turned off only the product
	 * page calculator. Never saved is read before either switch because a zone with no settings
	 * has no switch to read - both fields default to on, which would report a choice the
	 * merchant never made. The method switch is read before the calculator one because a method
	 * that is off quotes nowhere, calculator or not.
	 *
	 * @param int|null $instance_id Instance the destination resolved to, null when none did.
	 * @param array    $settings    Settings of that instance, empty when it has none stored.
	 */
	public static function quote_state( ?int $instance_id, array $settings ): string {
		if ( null === $instance_id ) {
			return self::QUOTE_NO_ZONE;
		}

		if ( empty( $settings ) ) {
			return self::QUOTE_NEVER_SAVED;
		}

		if ( ! self::method_is_enabled( $settings ) ) {
			return self::QUOTE_METHOD_OFF;
		}

		if ( ! self::calculator_is_offered( $settings ) ) {
			return self::QUOTE_CALCULATOR_OFF;
		}

		if ( empty( $settings['token'] ) ) {
			return self::QUOTE_NO_TOKEN;
		}

		return self::QUOTE_READY;
	}

	/**
	 * The one place a shopper is told this store will not price their postcode.
	 *
	 * Not every answer without a price comes through here, and none of the others should. Four
	 * cases are reported straight from the handler because each states what really happened: a
	 * postcode that is not eight digits, a product that does not exist or is unpublished, a
	 * request the service failed, and a postcode the service itself returned no carrier for.
	 * The last one is the only one that reads like a statement about the store, and it is
	 * allowed to: the service was asked and answered nothing, which the plugin did verify.
	 *
	 * The coverage verdict is a required argument with no default because refusals written here
	 * kept asserting what nobody had checked: one of them told shoppers the store does not quote
	 * a product it quotes every day, and they left instead of trying the cart. Whoever writes the
	 * next refusal will not have read any of that, so the question is put in the signature where
	 * it cannot be skipped, and a sentence written for a verdict the caller did not reach is
	 * dropped for the one that claims nothing. Nothing failed here, so nothing arrives as an
	 * error either.
	 *
	 * @param string $cause    Why there is no price: a QUOTE_ constant other than QUOTE_READY,
	 *                         or one of the REFUSE_ constants.
	 * @param string $coverage COVERAGE_RULED_OUT only when the plugin read the setting that
	 *                         withholds the quote in this region, COVERAGE_UNKNOWN otherwise.
	 */
	public static function refuse( string $cause, string $coverage ): array {
		$refusals = self::refusals();
		$refusal  = $refusals[ $cause ] ?? null;

		if ( null === $refusal || $refusal['coverage'] !== $coverage ) {
			$refusal = $refusals[ self::QUOTE_NEVER_SAVED ];
		}

		return [
			'message' => $refusal['message'],
			'notice'  => true,
		];
	}

	/**
	 * One true sentence per refusal, next to the verdict it was written under.
	 *
	 * A region the merchant switched off is the only thing here the plugin can call a decision,
	 * and it says so without naming the switch, which is the merchant's business. The zone that
	 * excludes a shipping class is settled too, but only for that region: the sentence used to
	 * read store wide and sent shoppers away from a product other zones quote. An unfinished
	 * zone is not a coverage fact and is nothing the shopper can fix, so it claims neither and
	 * does not suggest trying again - the next attempt fails the same way until the merchant
	 * finishes the zone. A postcode no zone matched says only that, and asks nothing of the
	 * shopper: the two ways of getting here are a postcode no published range covers and a
	 * postcode whose zone does not carry this method, and in both the plugin read the number it
	 * was given and has nothing to report about coverage or about the number. Options the zone's
	 * filters removed are the plainest case of all: the plugin watched carriers price that
	 * postcode and hid them itself, so it says the store is not showing them rather than that
	 * there are none.
	 */
	private static function refusals(): array {
		$unfinished = __( 'Não conseguimos calcular o frete para este CEP nesta página. Entre em contato com a loja para saber as opções de entrega.', 'central-do-frete' );
		$region_off = __( 'O cálculo de frete não está disponível para esta região.', 'central-do-frete' );

		return [
			self::QUOTE_METHOD_OFF => [
				'coverage' => self::COVERAGE_RULED_OUT,
				'message'  => $region_off,
			],
			self::QUOTE_CALCULATOR_OFF => [
				'coverage' => self::COVERAGE_RULED_OUT,
				'message'  => $region_off,
			],
			self::REFUSE_CLASS_EXCLUDED => [
				'coverage' => self::COVERAGE_RULED_OUT,
				'message'  => __( 'Este produto não é cotado pela Central do Frete na região deste CEP. Entre em contato com a loja para saber as opções de entrega.', 'central-do-frete' ),
			],
			self::REFUSE_ALL_FILTERED => [
				'coverage' => self::COVERAGE_UNKNOWN,
				'message'  => __( 'Não estamos exibindo opções de frete para este CEP. Entre em contato com a loja para saber as opções de entrega.', 'central-do-frete' ),
			],
			self::QUOTE_NO_ZONE => [
				'coverage' => self::COVERAGE_UNKNOWN,
				'message'  => __( 'Não foi possível identificar a área de entrega deste CEP. Entre em contato com a loja para saber as opções de entrega.', 'central-do-frete' ),
			],
			self::QUOTE_NEVER_SAVED => [
				'coverage' => self::COVERAGE_UNKNOWN,
				'message'  => $unfinished,
			],
			self::QUOTE_NO_TOKEN => [
				'coverage' => self::COVERAGE_UNKNOWN,
				'message'  => $unfinished,
			],
		];
	}

	/**
	 * What the plugin actually verified about coverage when a zone gave no price.
	 *
	 * Kept free of WordPress so it can be tested on its own, and kept apart from the sentences
	 * on purpose: this reads the state the code reached, `refusals()` declares what each
	 * sentence asserts, and `refuse()` only lets a sentence out when the two agree. A switch the
	 * merchant turned off for that zone is the only proof the plugin has that the region gets no
	 * quote. An unfinished zone and a postcode no zone matched prove nothing about coverage.
	 *
	 * @param string $state One of the QUOTE_ constants, other than QUOTE_READY.
	 */
	public static function coverage_verdict( string $state ): string {
		$switched_off = [ self::QUOTE_METHOD_OFF, self::QUOTE_CALCULATOR_OFF ];

		return in_array( $state, $switched_off, true ) ? self::COVERAGE_RULED_OUT : self::COVERAGE_UNKNOWN;
	}

	/**
	 * What the log says about a postcode that gets no price, naming which state occurred.
	 *
	 * Kept free of WordPress so it can be tested on its own. This is where the difference the
	 * shopper is spared is written down, in the merchant's own terms and against the instance
	 * it belongs to, so an unfinished zone can be found and finished. A state nobody registered
	 * here names itself instead of borrowing the line of the last case, the same way `refuse()`
	 * falls back to the sentence that claims nothing: a merchant sent to fix a token that is
	 * already there loses more time than one told the plugin reached a state it cannot describe.
	 *
	 * @param string   $state       One of the QUOTE_ constants, other than QUOTE_READY.
	 * @param int|null $instance_id Instance the destination resolved to, null when none did.
	 * @param string   $postcode    Destination postcode, eight digits.
	 *
	 * @return array{level: string, message: string}
	 */
	public static function quote_state_log( string $state, ?int $instance_id, string $postcode ): array {
		switch ( $state ) {
			case self::QUOTE_NO_ZONE:
				return [
					'level'   => 'warning',
					'message' => sprintf(
						'[CALC] Nenhuma área de entrega com a Central do Frete corresponde ao CEP %s',
						$postcode
					),
				];

			case self::QUOTE_NEVER_SAVED:
				return [
					'level'   => 'error',
					'message' => sprintf(
						'[CALC] Área de entrega #%d nunca foi salva: a Central do Frete foi adicionada à zona que atende o CEP %s, mas as configurações não foram gravadas',
						(int) $instance_id,
						$postcode
					),
				];

			case self::QUOTE_METHOD_OFF:
				return [
					'level'   => 'debug',
					'message' => sprintf(
						'[CALC] Método de entrega desativado na área de entrega #%d, que atende o CEP %s',
						(int) $instance_id,
						$postcode
					),
				];

			case self::QUOTE_CALCULATOR_OFF:
				return [
					'level'   => 'debug',
					'message' => sprintf(
						'[CALC] Calculadora desligada na área de entrega #%d, que atende o CEP %s',
						(int) $instance_id,
						$postcode
					),
				];

			case self::QUOTE_NO_TOKEN:
				return [
					'level'   => 'error',
					'message' => sprintf(
						'[CALC] Token não configurado na área de entrega #%d, que atende o CEP %s',
						(int) $instance_id,
						$postcode
					),
				];

			default:
				return [
					'level'   => 'warning',
					'message' => sprintf(
						'[CALC] Estado de cotação não reconhecido "%s" na área de entrega #%d, que atende o CEP %s',
						$state,
						(int) $instance_id,
						$postcode
					),
				];
		}
	}

	/**
	 * Whether one shipping zone offers the product page calculator.
	 *
	 * Read in the one place that draws the widget and in the one place that answers it, so the
	 * setting cannot mean two things. Kept free of WordPress so it can be tested on its own.
	 * Absent counts as on, which is how a zone saved before the field existed keeps quoting.
	 *
	 * @param array $settings Settings of a single instance.
	 */
	public static function calculator_is_offered( array $settings ): bool {
		return ( $settings['product_calculator'] ?? 'yes' ) === 'yes';
	}

	/**
	 * Whether the merchant left "Ativar método de entrega" on for one instance.
	 *
	 * Kept free of WordPress so it can be tested on its own. `is_available()` reads the same
	 * checkbox for the cart and the checkout, and it is a different switch from the zone screen
	 * toggle `get_enabled_instance_ids()` reads, so the calculator has to read it too: without
	 * it a zone quotes on the product page while the cart offers that region nothing. Absent
	 * counts as on, which is the field's own default.
	 *
	 * @param array $settings Settings of a single instance.
	 */
	public static function method_is_enabled( array $settings ): bool {
		return ( $settings['enabled'] ?? 'yes' ) === 'yes';
	}

	/**
	 * The product page has no destination yet, so the calculator shows up when any enabled
	 * instance is configured to offer it. Which zone answers is decided once the shopper
	 * types a postcode, and that zone's own switches decide whether it answers at all.
	 */
	private static function any_instance_offers_the_calculator(): bool {
		foreach ( Cdfrete_Shipping_Method::get_all_settings() as $settings ) {
			if ( empty( $settings['token'] ) || ! self::method_is_enabled( $settings ) ) {
				continue;
			}

			if ( self::calculator_is_offered( $settings ) ) {
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
