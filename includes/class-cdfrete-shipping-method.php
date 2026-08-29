<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cdfrete_Shipping_Method extends WC_Shipping_Method {

	private const CARGO_TYPES_OPTION = 'centraldofrete_cargotypes';

	/** Origins the service resolved for quotes sent without one, keyed by account scope. */
	private const RESOLVED_ORIGIN_OPTION = 'cdfrete_resolved_origin';

	/** How many accounts that map keeps, the least recently changed dropping out first. */
	private const RESOLVED_ORIGIN_LIMIT = 20;

	/** How the store postcode stands as a quotation origin. */
	public const ORIGIN_VALID     = 'valid';
	public const ORIGIN_MISSING   = 'missing';
	public const ORIGIN_MALFORMED = 'malformed';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'centraldofrete';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Central do Frete', 'central-do-frete' );
		$this->method_description = __( 'Cotação de frete em tempo real com múltiplas transportadoras.', 'central-do-frete' );
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
		];

		$this->init();
	}

	private function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = __( 'Central do Frete', 'central-do-frete' );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'maybe_refresh_cargo_types' ] );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'flush_shipping_rate_cache' ] );
	}

	public function init_form_fields(): void {
		$cargo_type_options = $this->get_cargo_type_options();
		$has_cargo_types    = count( $cargo_type_options ) > 1;

		$this->instance_form_fields = array_merge( [
			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 1: CONEXÃO
			// ═══════════════════════════════════════════════════════════════
			'section_connection' => [
				'title'       => __( '🔌 Conexão com a Central do Frete', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Configure sua conta para começar a usar o serviço.', 'central-do-frete' ),
			],
			'enabled' => [
				'title'       => __( 'Ativar método de entrega', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'label'       => __( 'Habilitar cotações da Central do Frete', 'central-do-frete' ),
			],
			'token' => [
				'title'       => __( 'Token de acesso', 'central-do-frete' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: link to Central do Frete panel */
					__( 'Encontre seu token em %s → Integrações → API.', 'central-do-frete' ),
					'<a href="https://app.centraldofrete.com" target="_blank">app.centraldofrete.com</a>'
				),
				'placeholder' => __( 'Cole seu token aqui', 'central-do-frete' ),
			],

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 2: COMO EXIBIR O FRETE
			// ═══════════════════════════════════════════════════════════════
			'section_display' => [
				'title'       => __( '🛒 Como exibir o frete para o cliente', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Personalize como as opções de frete aparecem no checkout.', 'central-do-frete' ),
			],
			'display_limit' => [
				'title'       => __( 'Quantas opções mostrar?', 'central-do-frete' ),
				'type'        => 'select',
				'default'     => 'all',
				'options'     => [
					'all'              => __( 'Mostrar todas as transportadoras', 'central-do-frete' ),
					'top3'             => __( 'Apenas as 3 mais baratas', 'central-do-frete' ),
					'economic_express' => __( 'Só a mais barata e a mais rápida', 'central-do-frete' ),
				],
				'description' => __( 'Menos opções = decisão mais fácil para o cliente.', 'central-do-frete' ),
			],
			'display_date' => [
				'title'       => __( 'Mostrar prazo de entrega', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'label'       => __( 'Exibir "Entrega em X dias úteis"', 'central-do-frete' ),
				'description' => __( 'Ajuda o cliente a escolher entre preço e velocidade.', 'central-do-frete' ),
			],
			'show_carrier_logo' => [
				'title'       => __( 'Mostrar logo da transportadora', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'label'       => __( 'Exibir logotipo junto ao nome', 'central-do-frete' ),
				'description' => __( 'Pode não funcionar em todos os temas.', 'central-do-frete' ),
			],
			'hide_no_pickup' => [
				'title'       => __( 'Esconder fretes "Balcão"', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'label'       => __( 'Não mostrar opções sem coleta', 'central-do-frete' ),
				'description' => __( 'Opções "Balcão" exigem que você leve o produto até a transportadora.', 'central-do-frete' ),
			],
			'product_calculator' => [
				'title'       => __( 'Calculador na página do produto', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'label'       => __( 'Cotar na página do produto', 'central-do-frete' ),
				'description' => __( 'Permite calcular o frete antes de adicionar ao carrinho. Vale para esta área de entrega: quem digitar um CEP de outra área recebe o que estiver configurado lá.', 'central-do-frete' ),
			],

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 3: AJUSTES DE PRAZO E PREÇO
			// ═══════════════════════════════════════════════════════════════
			'section_adjustments' => [
				'title'       => __( '⚙️ Ajustes de prazo e preço', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Adicione dias extras ou taxas ao frete calculado.', 'central-do-frete' ),
			],
			'additional_time' => [
				'title'             => __( 'Dias adicionais', 'central-do-frete' ),
				'type'              => 'number',
				'default'           => 0,
				'description'       => __( 'Tempo extra para preparar o pedido antes de enviar. Zero = usar prazo da transportadora.', 'central-do-frete' ),
				'placeholder'       => '0',
				'custom_attributes' => [ 'min' => 0, 'max' => 30, 'step' => 1 ],
			],
			'handling_fee' => [
				'title'       => __( 'Taxa adicional (R$)', 'central-do-frete' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Valor extra cobrado em cada frete (embalagem, manuseio, etc). Zero = sem taxa.', 'central-do-frete' ),
				'placeholder' => '0,00',
			],

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 4: DIMENSÕES PADRÃO
			// ═══════════════════════════════════════════════════════════════
			'section_defaults' => [
				'title'       => __( '📦 Dimensões padrão dos produtos', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Usadas quando um produto não tem peso ou medidas cadastradas.', 'central-do-frete' ),
			],
			'default_weight' => [
				'title'       => __( 'Peso padrão (kg)', 'central-do-frete' ),
				'type'        => 'text',
				'default'     => '0.3',
				'placeholder' => '0.3',
				'description' => __( 'Ex: 0.3 = 300 gramas', 'central-do-frete' ),
			],
			'default_height' => [
				'title'       => __( 'Altura padrão (cm)', 'central-do-frete' ),
				'type'        => 'text',
				'default'     => '2',
				'placeholder' => '2',
			],
			'default_width' => [
				'title'       => __( 'Largura padrão (cm)', 'central-do-frete' ),
				'type'        => 'text',
				'default'     => '11',
				'placeholder' => '11',
			],
			'default_length' => [
				'title'       => __( 'Comprimento padrão (cm)', 'central-do-frete' ),
				'type'        => 'text',
				'default'     => '16',
				'placeholder' => '16',
			],

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 5: TIPO DE CARGA
			// ═══════════════════════════════════════════════════════════════
			'section_cargo' => [
				'title'       => __( '🏷️ Tipo de carga', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Categoria dos produtos para cotação. Alguns fretes variam conforme o tipo.', 'central-do-frete' ),
			],
			'refresh_cargo_types' => [
				'title'       => __( 'Carregar tipos disponíveis', 'central-do-frete' ),
				'type'        => 'cdfrete_refresh_button',
				'description' => $has_cargo_types
					? sprintf(
						/* translators: %d: number of cargo types */
						__( '✅ %d tipos de carga carregados.', 'central-do-frete' ),
						count( $cargo_type_options ) - 1
					)
					: __( '⚠️ Salve o token acima e clique em "Atualizar" para carregar os tipos.', 'central-do-frete' ),
			],
			'default_cargo_type' => [
				'title'       => __( 'Tipo padrão', 'central-do-frete' ),
				'type'        => 'select',
				'options'     => $cargo_type_options,
				'description' => __( 'Usado quando o produto não tem um tipo específico definido.', 'central-do-frete' ),
			],

		], $this->get_shipping_class_fields(), [

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 7: CONFIGURAÇÕES TÉCNICAS
			// ═══════════════════════════════════════════════════════════════
			'section_advanced' => [
				'title'       => __( '🔧 Configurações técnicas', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Opções avançadas. Altere apenas se souber o que está fazendo.', 'central-do-frete' ),
			],
			'cache_ttl' => [
				'title'       => __( 'Cache de cotações', 'central-do-frete' ),
				'type'        => 'select',
				'default'     => '1h',
				'options'     => [
					'15min' => __( '15 minutos (mais consultas à API)', 'central-do-frete' ),
					'30min' => __( '30 minutos', 'central-do-frete' ),
					'1h'    => __( '1 hora (recomendado)', 'central-do-frete' ),
					'2h'    => __( '2 horas (menos consultas à API)', 'central-do-frete' ),
				],
				'description' => __( 'Quanto tempo guardar cotações na memória. Reduz chamadas à API.', 'central-do-frete' ),
			],
			'api_timeout' => [
				'title'             => __( 'Tempo limite da API', 'central-do-frete' ),
				'type'              => 'number',
				'default'           => 15,
				'custom_attributes' => [ 'min' => 5, 'max' => 60, 'step' => 1 ],
				'description'       => __( 'Segundos para aguardar resposta. Aumente se tiver timeouts frequentes.', 'central-do-frete' ),
			],
			'debug' => [
				'title'       => __( 'Modo debug', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'label'       => __( 'Salvar logs detalhados', 'central-do-frete' ),
				'description' => sprintf(
					/* translators: %s: link to WooCommerce logs */
					__( 'Útil para resolver problemas. Veja em %s.', 'central-do-frete' ),
					'<a href="' . admin_url( 'admin.php?page=wc-status&tab=logs' ) . '">WooCommerce → Status → Logs</a>'
				),
			],
		] );
	}

	/**
	 * Shipping class restriction fields, empty when the store has no classes registered.
	 */
	private function get_shipping_class_fields(): array {
		$class_options = $this->get_shipping_class_options();

		if ( empty( $class_options ) ) {
			return [];
		}

		return [
			'section_shipping_classes' => [
				'title'       => __( '🧱 Restrição por classe de entrega', 'central-do-frete' ),
				'type'        => 'title',
				'description' => __( 'Define com quais classes de entrega a Central do Frete trabalha. Se o carrinho ficar sem nenhum método disponível, o cliente não consegue fechar o pedido, então mantenha outro método nesta área de entrega para os produtos que você deixar de fora.', 'central-do-frete' ),
			],
			'shipping_class_rule' => [
				'title'       => __( 'Quais classes a Central do Frete atende?', 'central-do-frete' ),
				'type'        => 'select',
				'default'     => Cdfrete_Shipping_Class_Rule::RULE_ALL,
				'options'     => [
					Cdfrete_Shipping_Class_Rule::RULE_ALL     => __( 'Todas as classes', 'central-do-frete' ),
					Cdfrete_Shipping_Class_Rule::RULE_INCLUDE => __( 'Apenas as classes selecionadas', 'central-do-frete' ),
					Cdfrete_Shipping_Class_Rule::RULE_EXCLUDE => __( 'Todas, exceto as selecionadas', 'central-do-frete' ),
				],
			],
			'shipping_classes' => [
				'title'       => __( 'Classes de entrega', 'central-do-frete' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'default'     => [],
				'options'     => $class_options,
				'description' => __( 'Sem nenhuma classe selecionada, a Central do Frete continua atendendo todas.', 'central-do-frete' ),
			],
			'shipping_class_strict' => [
				'title'       => __( 'Carrinho misto', 'central-do-frete' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'label'       => __( 'Exigir que todos os produtos do carrinho se enquadrem', 'central-do-frete' ),
				'description' => __( 'Desligado, basta um produto atendido para a Central do Frete aparecer num carrinho misto.', 'central-do-frete' ),
			],
		];
	}

	/**
	 * Registered shipping classes as select options, keyed by term id.
	 */
	private function get_shipping_class_options(): array {
		$options = [];

		foreach ( WC()->shipping()->get_shipping_classes() as $shipping_class ) {
			if ( isset( $shipping_class->term_id, $shipping_class->name ) ) {
				$options[ (string) $shipping_class->term_id ] = $shipping_class->name;
			}
		}

		if ( ! empty( $options ) ) {
			$options[ Cdfrete_Shipping_Class_Rule::NO_CLASS ] = __( 'Produtos sem classe de entrega', 'central-do-frete' );
		}

		return $options;
	}

	/**
	 * Register the admin script so a settings screen can enqueue it when the cargo
	 * type button is actually rendered.
	 */
	public static function register_admin_assets(): void {
		wp_register_script(
			'cdfrete-admin',
			CDFRETE_PLUGIN_URL . 'assets/js/cdfrete-admin.js',
			[ 'jquery' ],
			CDFRETE_VERSION,
			true
		);
	}

	/**
	 * Enqueue the admin script and hand it the data the handler needs.
	 */
	private static function enqueue_admin_assets(): void {
		if ( ! wp_script_is( 'cdfrete-admin', 'registered' ) ) {
			self::register_admin_assets();
		}

		wp_localize_script(
			'cdfrete-admin',
			'cdfreteAdminParams',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'cdfrete_refresh_cargo_types' ),
				'loading'         => __( 'Carregando...', 'central-do-frete' ),
				'buttonLabel'     => __( 'Atualizar Tipos de Carga', 'central-do-frete' ),
				'error'           => __( 'Erro ao atualizar.', 'central-do-frete' ),
				'connectionError' => __( 'Erro de conexão.', 'central-do-frete' ),
			]
		);

		wp_enqueue_script( 'cdfrete-admin' );
	}

	/**
	 * Generate custom button field for refreshing cargo types.
	 */
	public function generate_cdfrete_refresh_button_html( $key, $data ): string {
		$defaults = [
			'title'       => '',
			'description' => '',
		];
		$data = wp_parse_args( $data, $defaults );

		self::enqueue_admin_assets();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button
					type="button"
					class="button"
					id="cdfrete-refresh-cargo-types"
					data-instance-id="<?php echo esc_attr( (string) $this->instance_id ); ?>"
				>
					<?php esc_html_e( 'Atualizar Tipos de Carga', 'central-do-frete' ); ?>
				</button>
				<p class="description" id="cdfrete-cargo-types-status"><?php echo wp_kses_post( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for refreshing cargo types.
	 */
	public static function ajax_refresh_cargo_types(): void {
		check_ajax_referer( 'cdfrete_refresh_cargo_types', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissão negada.', 'central-do-frete' ) ] );
		}

		$instance_id = absint( $_POST['instance_id'] ?? 0 );
		$settings    = get_option( 'woocommerce_centraldofrete_' . $instance_id . '_settings', [] );
		$token       = $settings['token'] ?? '';

		Cdfrete_API_Client::log( 'info', sprintf(
			'[ADMIN] Atualizando tipos de carga - Instance ID: %d, Token: %s',
			$instance_id,
			empty( $token ) ? 'não configurado' : 'configurado'
		) );

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => __( 'Token não configurado. Salve as configurações primeiro.', 'central-do-frete' ) ] );
		}

		$client = new Cdfrete_API_Client( $token );
		$types  = $client->get_cargo_types();

		if ( $types === false ) {
			wp_send_json_error( [
				'message' => __( 'Erro ao buscar tipos de carga. Verifique o token e os logs do WooCommerce.', 'central-do-frete' ),
			] );
		}

		if ( empty( $types ) ) {
			wp_send_json_error( [
				'message' => __( 'A API retornou uma lista vazia de tipos de carga.', 'central-do-frete' ),
			] );
		}

		update_option( self::CARGO_TYPES_OPTION, $types, false );

		Cdfrete_API_Client::log( 'info', sprintf( '[ADMIN] %d tipos de carga salvos', count( $types ) ) );

		wp_send_json_success( [
			/* translators: %d: number of cargo types loaded from the API */
			'message' => sprintf( __( '%d tipos de carga carregados!', 'central-do-frete' ), count( $types ) ),
			'count'   => count( $types ),
		] );
	}

	/**
	 * Check if shipping is available for the given package.
	 */
	public function is_available( $package ): bool {
		if ( $this->enabled !== 'yes' ) {
			return false;
		}

		$settings     = $this->get_shipping_class_settings();
		$cart_classes = Cdfrete_Shipping_Class_Rule::classes_from_package( $package );
		$available    = Cdfrete_Shipping_Class_Rule::allows_for_settings( $cart_classes, $settings );

		if ( ! $available ) {
			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] Carrinho fora da restrição de classe de entrega - Regra: %s, Classes do carrinho: [%s]',
				$settings['shipping_class_rule'],
				implode( ', ', $cart_classes ) ?: 'N/A'
			) );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mirrors the WC_Shipping_Method core filter for this method id, so integrations keep working.
		return apply_filters( 'woocommerce_shipping_centraldofrete_is_available', $available, $package, $this );
	}

	/**
	 * Shipping class restriction as `Cdfrete_Shipping_Class_Rule` reads it.
	 */
	private function get_shipping_class_settings(): array {
		return [
			'shipping_class_rule'   => $this->get_option( 'shipping_class_rule', Cdfrete_Shipping_Class_Rule::RULE_ALL ),
			'shipping_classes'      => (array) $this->get_option( 'shipping_classes', [] ),
			'shipping_class_strict' => $this->get_option( 'shipping_class_strict', 'no' ),
		];
	}

	/**
	 * How a raw store postcode stands as a quotation origin.
	 *
	 * Kept free of WordPress so it can be tested on its own, and it is the one place that
	 * decides: the service validates `from` as exactly eight characters and only falls back to
	 * the pickup address of the account when the key is absent, so anything that is not eight
	 * digits has to leave as no origin at all rather than as a value that fails on the way in.
	 * `postcode` is therefore either empty or eight digits, never anything else.
	 *
	 * @return array{status: string, postcode: string, typed: string}
	 */
	public static function classify_origin( string $raw ): array {
		$typed  = trim( $raw );
		$digits = preg_replace( '/\D/', '', $typed );

		if ( '' === $digits ) {
			return [ 'status' => self::ORIGIN_MISSING, 'postcode' => '', 'typed' => $typed ];
		}

		if ( strlen( $digits ) !== 8 ) {
			return [ 'status' => self::ORIGIN_MALFORMED, 'postcode' => '', 'typed' => $typed ];
		}

		return [ 'status' => self::ORIGIN_VALID, 'postcode' => $digits, 'typed' => $typed ];
	}

	/**
	 * How the postcode set in WooCommerce stands as a quotation origin.
	 */
	private static function store_origin(): array {
		return self::classify_origin( (string) get_option( 'woocommerce_store_postcode', '' ) );
	}

	/**
	 * The postcode the store quotes from: eight digits, or empty when the store has none the
	 * service would accept. An origin it would reject is left out so the account decides.
	 */
	public static function store_origin_postcode(): string {
		return self::store_origin()['postcode'];
	}

	/**
	 * Remember the origin the service resolved for a quote sent without one.
	 *
	 * It exists so the settings screen can name the postcode instead of only naming where it
	 * comes from, and it is kept per account because a store can carry a different token per
	 * shipping zone, each with its own pickup address.
	 */
	public static function record_resolved_origin( string $account, string $zipcode ): void {
		$stored = get_option( self::RESOLVED_ORIGIN_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$updated = self::with_resolved_origin( $stored, $account, $zipcode, time() );

		if ( $updated === $stored ) {
			return;
		}

		update_option( self::RESOLVED_ORIGIN_OPTION, $updated, false );
	}

	/**
	 * The resolved origin map with one account's postcode written into it.
	 *
	 * Kept free of WordPress so it can be tested on its own. Entries the map does not recognise
	 * are dropped, which is also how the single pair an older version stored is retired, and the
	 * accounts whose origin changed longest ago are pruned past the limit so a merchant rotating
	 * tokens cannot grow the option without bound. The map comes back identical when nothing
	 * moved, so the caller writes to the database only on a real change - which is why `updated`
	 * dates the last change to an origin and not the last quote that confirmed it, and why the
	 * prune goes by that same measure rather than by recent use.
	 *
	 * @param array  $stored  Map as it stands, keyed by account scope.
	 * @param string $account Account scope the quote went out under.
	 * @param string $zipcode Origin the service reported, digits only.
	 * @param int    $now     Timestamp to record against the entry.
	 */
	public static function with_resolved_origin( array $stored, string $account, string $zipcode, int $now, int $limit = self::RESOLVED_ORIGIN_LIMIT ): array {
		$map = self::normalize_resolved_origins( $stored );

		if ( ( $map[ $account ]['zipcode'] ?? null ) !== $zipcode ) {
			$map[ $account ] = [ 'zipcode' => $zipcode, 'updated' => $now ];
		}

		if ( count( $map ) > $limit ) {
			uasort( $map, static function ( array $a, array $b ): int {
				return $b['updated'] <=> $a['updated'];
			} );

			$map = array_slice( $map, 0, $limit, true );
		}

		return $map === $stored ? $stored : $map;
	}

	/**
	 * Only entries shaped the way this version writes them, in the order they were stored.
	 *
	 * The key is read back as whatever PHP made of it: an account scope is a sha256 prefix, and
	 * one that happens to be all digits becomes an integer key on the way into the array. It is
	 * the shape of the entry that says whether a row belongs to this version, never the key.
	 */
	private static function normalize_resolved_origins( array $stored ): array {
		$map = [];

		foreach ( $stored as $account => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['zipcode'] ) ) {
				continue;
			}

			$map[ (string) $account ] = [
				'zipcode' => (string) $entry['zipcode'],
				'updated' => (int) ( $entry['updated'] ?? 0 ),
			];
		}

		return $map;
	}

	/**
	 * The last origin the service resolved for this account, or an empty string.
	 */
	public static function get_resolved_origin( string $account ): string {
		$stored = get_option( self::RESOLVED_ORIGIN_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return (string) ( self::normalize_resolved_origins( $stored )[ $account ]['zipcode'] ?? '' );
	}

	/**
	 * Say which postcode the quotes leave from, above the settings form.
	 *
	 * Hooked on `get_admin_options_html()` rather than `admin_options()` so the notice shows
	 * on the instance settings screen and inside the shipping zone modal alike.
	 *
	 * A quote from the wrong origin still looks like a quote: the prices and the carrier list
	 * change, nothing errors. So the screen states the origin it is about to use, and says so
	 * loudly when that is not the postcode the merchant set in WooCommerce.
	 */
	public function get_admin_options_html(): string {
		return $this->token_notice_html() . $this->origin_notice_html() . parent::get_admin_options_html();
	}

	/**
	 * Say that this shipping zone has no token, above the settings form.
	 *
	 * A zone the merchant added but never pasted a token into looks finished: WooCommerce
	 * enables it on the spot with the form defaults, and the cargo type list is store wide, so
	 * a zone with no token of its own can even show the loaded-types tick. It quotes nothing.
	 * The zone is named because a store carrying the method in several zones needs to know
	 * which one this is, and the token notice comes first because nothing else on the screen
	 * matters until there is a token.
	 */
	private function token_notice_html(): string {
		if ( ! empty( $this->get_option( 'token', '' ) ) ) {
			return '';
		}

		$panel_link = '<a href="https://app.centraldofrete.com" target="_blank">app.centraldofrete.com</a>';
		$zone_name  = $this->zone_name();

		$line = '' === $zone_name
			? sprintf(
				/* translators: %s: link to the Central do Frete panel */
				__( 'Esta área de entrega está sem token de acesso, então a Central do Frete não cota nela. Cole o token em %s → Integrações → API.', 'central-do-frete' ),
				$panel_link
			)
			: sprintf(
				/* translators: 1: shipping zone name, 2: link to the Central do Frete panel */
				__( 'A área de entrega <strong>%1$s</strong> está sem token de acesso, então a Central do Frete não cota nela. Cole o token em %2$s → Integrações → API.', 'central-do-frete' ),
				esc_html( $zone_name ),
				$panel_link
			);

		return sprintf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			wp_kses_post( $line )
		);
	}

	/**
	 * The name of the shipping zone this instance sits in, empty when there is none to name.
	 */
	private function zone_name(): string {
		if ( empty( $this->instance_id ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return '';
		}

		$zone = WC_Shipping_Zones::get_zone_by( 'instance_id', $this->instance_id );

		return $zone instanceof WC_Shipping_Zone ? trim( (string) $zone->get_zone_name() ) : '';
	}

	/**
	 * The store postcode fails in two different ways, and the merchant has to be able to tell
	 * which one is theirs: an unfilled address and a mistyped one have different fixes. So a
	 * postcode the service would reject gets its own warning saying it is invalid, instead of
	 * being reported as an address that was never filled in.
	 */
	private function origin_notice_html(): string {
		$origin       = self::store_origin();
		$general_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
			esc_html__( 'WooCommerce › Configurações › Geral', 'central-do-frete' )
		);

		if ( self::ORIGIN_VALID === $origin['status'] ) {
			return sprintf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				wp_kses_post( sprintf(
					/* translators: 1: store postcode, 2: link to the WooCommerce general settings */
					__( 'As cotações saem do CEP da loja, <strong>%1$s</strong>. Para mudar a origem, altere o endereço da loja em %2$s.', 'central-do-frete' ),
					esc_html( self::format_postcode( $origin['postcode'] ) ),
					$general_link
				) )
			);
		}

		$resolved   = self::get_resolved_origin( Cdfrete_API_Client::account_scope( (string) $this->get_option( 'token', '' ) ) );
		$last_quote = '' === $resolved
			? ''
			: ' ' . sprintf(
				/* translators: %s: postcode the service used on the last quote */
				__( 'Na última cotação isso foi o CEP <strong>%s</strong>.', 'central-do-frete' ),
				esc_html( self::format_postcode( $resolved ) )
			);

		if ( self::ORIGIN_MALFORMED === $origin['status'] ) {
			$origin_line = sprintf(
				/* translators: %s: store postcode as the merchant typed it */
				__( 'O CEP da loja, <strong>%s</strong>, não é válido: um CEP tem 8 números. Enquanto ele não for corrigido, as cotações são enviadas sem origem e a Central do Frete usa o endereço de coleta cadastrado na sua conta.', 'central-do-frete' ),
				esc_html( $origin['typed'] )
			);

			$action_line = sprintf(
				/* translators: %s: link to the WooCommerce general settings */
				__( 'A origem muda o preço e a lista de transportadoras. Corrija o CEP da loja em %s.', 'central-do-frete' ),
				$general_link
			);
		} else {
			$origin_line = __( 'A loja está sem CEP, então as cotações são enviadas sem origem e a Central do Frete usa o endereço de coleta cadastrado na sua conta.', 'central-do-frete' );

			$action_line = sprintf(
				/* translators: %s: link to the WooCommerce general settings */
				__( 'A origem muda o preço e a lista de transportadoras. Se os seus envios não saem do endereço de coleta da conta, preencha o CEP da loja em %s.', 'central-do-frete' ),
				$general_link
			);
		}

		return sprintf(
			'<div class="notice notice-warning inline"><p>%1$s</p><p>%2$s</p></div>',
			wp_kses_post( $origin_line . $last_quote ),
			wp_kses_post( $action_line )
		);
	}

	/**
	 * Eight digits as a Brazilian postcode, so the merchant reads it the way it was typed.
	 */
	private static function format_postcode( string $digits ): string {
		return strlen( $digits ) === 8 ? substr( $digits, 0, 5 ) . '-' . substr( $digits, 5 ) : $digits;
	}

	/**
	 * Calculate shipping rates.
	 */
	public function calculate_shipping( $package = [] ): void {
		$start_time = microtime( true );

		$token = $this->get_option( 'token' );
		if ( empty( $token ) ) {
			Cdfrete_API_Client::log( 'error', '[SHIP] Token não configurado.' );
			return;
		}

		$from = self::store_origin_postcode();
		$to   = preg_replace( '/\D/', '', $package['destination']['postcode'] ?? '' );

		Cdfrete_API_Client::log( 'info', sprintf(
			'[SHIP] Iniciando cotação - Origem: %s, Destino: %s, Itens: %d',
			$from ?: 'cadastro da conta na Central do Frete',
			$to ?: 'N/A',
			count( $package['contents'] ?? [] )
		) );

		if ( empty( $to ) ) {
			Cdfrete_API_Client::log( 'debug', '[SHIP] CEP de destino não informado (aguardando input do cliente)' );
			return;
		}

		if ( ( $package['destination']['country'] ?? 'BR' ) !== 'BR' ) {
			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] País não suportado: %s',
				$package['destination']['country'] ?? 'N/A'
			) );
			return;
		}

		// Build volumes and cargo types from package contents.
		$volumes     = [];
		$cargo_types = [];
		$invoice     = 0.0;

		$default_height     = $this->fix_decimal( $this->get_option( 'default_height', '2' ) );
		$default_width      = $this->fix_decimal( $this->get_option( 'default_width', '11' ) );
		$default_length     = $this->fix_decimal( $this->get_option( 'default_length', '16' ) );
		$default_weight     = $this->fix_decimal( $this->get_option( 'default_weight', '0.3' ) );
		$default_cargo_type = $this->get_option( 'default_cargo_type', '' );

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[SHIP] Dimensões padrão - H: %.2f, W: %.2f, L: %.2f, Peso: %.2fkg, Carga: %s',
			$default_height,
			$default_width,
			$default_length,
			$default_weight,
			$default_cargo_type ?: 'N/A'
		) );

		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			$qty     = (int) $item['quantity'];

			$height = (float) $product->get_height() ?: $default_height;
			$width  = (float) $product->get_width()  ?: $default_width;
			$length = (float) $product->get_length() ?: $default_length;
			$weight = (float) $product->get_weight() ?: $default_weight;

			$volumes[] = [
				'quantity' => $qty,
				'width'    => $width,
				'height'   => $height,
				'length'   => $length,
				'weight'   => $weight,
			];

			$product_id = $product->get_id();
			$cargo_type = Cdfrete_Product_Fields::get_cargo_type( (int) $product_id );
			if ( empty( $cargo_type ) ) {
				$cargo_type = $default_cargo_type;
			}
			if ( ! empty( $cargo_type ) ) {
				$cargo_types[] = (int) $cargo_type;
			}

			$invoice += (float) $product->get_price() * $qty;

			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] Produto #%d: %s - Qtd: %d, H: %.2f, W: %.2f, L: %.2f, Peso: %.2fkg, Carga: %s, Preço: R$ %.2f',
				$product_id,
				$product->get_name(),
				$qty,
				$height,
				$width,
				$length,
				$weight,
				$cargo_type ?: 'padrão',
				(float) $product->get_price()
			) );
		}

		if ( empty( $volumes ) ) {
			Cdfrete_API_Client::log( 'warning', '[SHIP] Nenhum volume no pacote' );
			return;
		}

		// Build recipient info.
		$recipient = $this->get_recipient_info( $package );

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[SHIP] Destinatário: %s, NF: R$ %.2f, Tipos carga: [%s]',
			$recipient ? 'identificado' : 'N/A',
			$invoice,
			implode( ', ', $cargo_types ) ?: 'N/A'
		) );

		// One builder for both quoting paths, so the cache version invalidates the cart too.
		$cache_key = Cdfrete_Cache::build_key(
			Cdfrete_API_Client::account_scope( $token ),
			$from,
			$to,
			$volumes,
			$cargo_types,
			$recipient
		);
		$cached    = Cdfrete_Cache::get( $cache_key );

		if ( $cached !== false ) {
			$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			Cdfrete_API_Client::log( 'info', sprintf(
				'[SHIP] Cache HIT - Key: %s..., %d opções, %.2fms',
				substr( $cache_key, 0, 25 ),
				count( $cached ),
				$elapsed
			) );
			$this->add_rates_from_services( $cached, $start_time );
			return;
		}

		Cdfrete_API_Client::log( 'info', '[SHIP] Cache MISS - Consultando API Central do Frete' );

		// Fetch from API.
		$api_start = microtime( true );
		$timeout   = (int) $this->get_option( 'api_timeout', 15 );
		$client    = new Cdfrete_API_Client( $token, $timeout );

		$services = $client->get_quotation( $from, $to, $volumes, $cargo_types, $invoice, $recipient );

		$api_elapsed = round( ( microtime( true ) - $api_start ) * 1000, 2 );

		if ( $services === false ) {
			Cdfrete_API_Client::log( 'error', sprintf(
				'[SHIP] Falha na API após %.2fms',
				$api_elapsed
			) );
			return;
		}

		Cdfrete_API_Client::log( 'info', sprintf(
			'[SHIP] API retornou %d opções em %.2fms',
			count( $services ),
			$api_elapsed
		) );

		// Cache results.
		$ttl = Cdfrete_Cache::ttl_from_setting( $this->get_option( 'cache_ttl', '1h' ) );
		Cdfrete_Cache::set( $cache_key, $services, $ttl );

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[SHIP] Cache salvo - Key: %s..., TTL: %ds',
			substr( $cache_key, 0, 25 ),
			$ttl
		) );

		$this->add_rates_from_services( $services, $start_time );
	}

	/**
	 * Get recipient info from logged in customer or checkout session.
	 */
	private function get_recipient_info( array $package ): ?array {
		$customer = WC()->customer;

		if ( ! $customer ) {
			Cdfrete_API_Client::log( 'debug', '[SHIP] Destinatário: WC()->customer não disponível' );
			return null;
		}

		// Try to get CPF/CNPJ from customer meta (Brazilian Extra Checkout Fields plugin).
		$billing_cpf  = $customer->get_meta( 'billing_cpf' );
		$billing_cnpj = $customer->get_meta( 'billing_cnpj' );
		$document     = ! empty( $billing_cnpj ) ? $billing_cnpj : $billing_cpf;

		// Also try persontype field (some plugins use this).
		if ( empty( $document ) ) {
			$persontype = $customer->get_meta( 'billing_persontype' );
			if ( $persontype === '2' ) { // Pessoa Jurídica
				$document = $customer->get_meta( 'billing_cnpj' );
			} elseif ( $persontype === '1' ) { // Pessoa Física
				$document = $customer->get_meta( 'billing_cpf' );
			}
		}

		if ( empty( $document ) ) {
			Cdfrete_API_Client::log( 'debug', '[SHIP] Destinatário: CPF/CNPJ não encontrado no cliente' );
			return null;
		}

		$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );

		// The tax id and the shopper's name are deliberately left out of the log.
		Cdfrete_API_Client::log( 'debug', '[SHIP] Destinatário identificado e enviado na cotação' );

		return [
			'document' => preg_replace( '/\D/', '', $document ),
			'name'     => $name,
		];
	}

	/**
	 * Add WooCommerce shipping rates from service results.
	 */
	private function add_rates_from_services( array $services, ?float $start_time = null ): void {
		$original_count = count( $services );

		$display_date      = $this->get_option( 'display_date', 'yes' ) === 'yes';
		$show_carrier_logo = $this->get_option( 'show_carrier_logo', 'no' ) === 'yes';
		$additional_time   = (int) $this->get_option( 'additional_time', 0 );
		$handling_fee      = $this->fix_decimal( $this->get_option( 'handling_fee', '0' ) );
		$hide_no_pickup    = $this->get_option( 'hide_no_pickup', 'no' ) === 'yes';
		$display_limit     = $this->get_option( 'display_limit', 'all' );

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[SHIP] Configurações - Prazo: %s, Logo: %s, Dias+: %d, Taxa: R$ %.2f, Ocultar Balcão: %s, Limite: %s',
			$display_date ? 'sim' : 'não',
			$show_carrier_logo ? 'sim' : 'não',
			$additional_time,
			$handling_fee,
			$hide_no_pickup ? 'sim' : 'não',
			$display_limit
		) );

		// Log all services before filtering.
		foreach ( $services as $idx => $s ) {
			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] Opção %d: %s %s - R$ %.2f, %d dias, dispatch: %s',
				$idx + 1,
				$s['shipping_carrier'] ?? 'N/A',
				$s['service_type'] ?? '',
				$s['price'] ?? 0,
				$s['delivery_time'] ?? 0,
				$s['dispatch'] ?? 'N/A'
			) );
		}

		// Filter out services that require drop-off at carrier (no pickup).
		if ( $hide_no_pickup ) {
			$before_filter = count( $services );
			$services = array_filter( $services, function ( $s ) {
				return empty( $s['requires_drop'] );
			} );
			$services = array_values( $services );

			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] Filtro Balcão aplicado: %d -> %d opções',
				$before_filter,
				count( $services )
			) );
		}

		// Sort by price for limiting.
		usort( $services, function ( $a, $b ) {
			return $a['price'] <=> $b['price'];
		} );

		// Apply display limit.
		$before_limit = count( $services );
		$services     = $this->apply_display_limit( $services, $display_limit );

		Cdfrete_API_Client::log( 'debug', sprintf(
			'[SHIP] Limite "%s" aplicado: %d -> %d opções',
			$display_limit,
			$before_limit,
			count( $services )
		) );

		if ( empty( $services ) ) {
			Cdfrete_API_Client::log( 'warning', '[SHIP] Todas as opções foram filtradas - nenhuma taxa adicionada' );
			return;
		}

		foreach ( $services as $service ) {
			$label = $service['shipping_carrier'];

			if ( ! empty( $service['service_type'] ) ) {
				$label .= ' - ' . $service['service_type'];
			}

			if ( $display_date && ! empty( $service['delivery_time'] ) ) {
				$days = $service['delivery_time'] + $additional_time;
				$label .= sprintf(
					' (%s)',
					sprintf(
						/* translators: %d: number of business days until delivery */
						_n( 'Entrega em %d dia útil', 'Entrega em %d dias úteis', $days, 'central-do-frete' ),
						$days
					)
				);
			}

			$cost = $service['price'] + $handling_fee;

			$meta_data = [
				// These are order data, not global names: WooCommerce copies them onto the
				// order's shipping line, where merchants and support read them back. They keep
				// their original spelling so an upgrade does not split orders into two eras.
				'CDF_ID'        => $service['id'],
				'CDF_QUOTATION' => $service['quotation_code'] ?? '',
				'CDF_DISPATCH'  => $service['dispatch'] ?? '',
			];

			// Add logo URL to meta if enabled.
			if ( $show_carrier_logo && ! empty( $service['carrier_logo'] ) ) {
				$meta_data['CDF_LOGO'] = $service['carrier_logo'];
			}

			// Kept as CDF_ for the same reason as the meta above: merchants filter on this
			// rate id to hide or rename a carrier, and it is already namespaced by the method id.
			$rate_id = $this->get_rate_id( 'CDF_' . sanitize_title( $service['shipping_carrier'] . '_' . $service['service_type'] ) );

			$this->add_rate( [
				'id'        => $rate_id,
				'label'     => $label,
				'cost'      => $cost,
				'meta_data' => $meta_data,
			] );

			Cdfrete_API_Client::log( 'debug', sprintf(
				'[SHIP] Taxa adicionada: %s = R$ %.2f (%s)',
				$rate_id,
				$cost,
				$label
			) );
		}

		// Log completion time if start_time provided.
		if ( $start_time !== null ) {
			$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			Cdfrete_API_Client::log( 'info', sprintf(
				'[SHIP] Concluído em %.2fms - %d de %d opções retornadas',
				$elapsed,
				count( $services ),
				$original_count
			) );
		}
	}

	/**
	 * Apply display limit to services list.
	 */
	private function apply_display_limit( array $services, string $limit ): array {
		if ( empty( $services ) ) {
			return $services;
		}

		switch ( $limit ) {
			case 'top3':
				return array_slice( $services, 0, 3 );

			case 'economic_express':
				// Services already sorted by price, so first is cheapest.
				$cheapest = $services[0];

				// Find fastest (minimum delivery time).
				$fastest = $services[0];
				foreach ( $services as $s ) {
					if ( $s['delivery_time'] < $fastest['delivery_time'] ) {
						$fastest = $s;
					}
				}

				Cdfrete_API_Client::log( 'debug', sprintf(
					'[SHIP] economic_express - Econômica: %s %s (R$ %.2f, %d dias, ID: %s) | Rápida: %s %s (R$ %.2f, %d dias, ID: %s)',
					$cheapest['shipping_carrier'],
					$cheapest['service_type'],
					$cheapest['price'],
					$cheapest['delivery_time'],
					$cheapest['id'],
					$fastest['shipping_carrier'],
					$fastest['service_type'],
					$fastest['price'],
					$fastest['delivery_time'],
					$fastest['id']
				) );

				// If they're the same service, return just one.
				// Use == instead of === to handle int/string type differences.
				if ( $cheapest['id'] == $fastest['id'] ) {
					Cdfrete_API_Client::log( 'debug', '[SHIP] economic_express - Mesma opção (mais barata = mais rápida)' );
					return [ $cheapest ];
				}

				return [ $cheapest, $fastest ];

			case 'all':
			default:
				return $services;
		}
	}

	/**
	 * Get cargo type options for the select field.
	 */
	private function get_cargo_type_options(): array {
		$options = [ '' => __( 'Selecione o tipo de carga', 'central-do-frete' ) ];
		$types   = get_option( self::CARGO_TYPES_OPTION, [] );

		if ( is_array( $types ) ) {
			foreach ( $types as $type ) {
				if ( isset( $type['id'], $type['name'] ) ) {
					$options[ $type['id'] ] = $type['name'];
				}
			}
		}

		return $options;
	}

	/**
	 * Refresh cargo types from API after settings save.
	 */
	public function maybe_refresh_cargo_types(): void {
		$token = $this->get_option( 'token' );
		if ( empty( $token ) ) {
			return;
		}

		// Only refresh if cargo types are empty.
		$types = get_option( self::CARGO_TYPES_OPTION, [] );
		if ( ! empty( $types ) ) {
			return;
		}

		$client    = new Cdfrete_API_Client( $token );
		$new_types = $client->get_cargo_types();

		if ( $new_types !== false ) {
			update_option( self::CARGO_TYPES_OPTION, $new_types, false );
		}
	}

	/**
	 * Rates live in the customer session keyed by a hash of the cart plus the shipping
	 * transient version. The hash does not cover our settings, so without bumping the
	 * version the merchant keeps seeing the rates from before the change.
	 */
	public function flush_shipping_rate_cache(): void {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	/**
	 * Get all cargo types (static, for use by other classes).
	 */
	public static function get_cargo_types(): array {
		$types = get_option( self::CARGO_TYPES_OPTION, [] );
		if ( ! is_array( $types ) ) {
			return [];
		}

		$options = [];
		foreach ( $types as $type ) {
			if ( isset( $type['id'], $type['name'] ) ) {
				$options[ $type['id'] ] = $type['name'];
			}
		}
		asort( $options );

		return $options;
	}

	/**
	 * Settings of one instance, by id.
	 */
	public static function get_instance_settings( int $instance_id ): array {
		$settings = get_option( 'woocommerce_centraldofrete_' . $instance_id . '_settings', [] );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Settings of the instance that serves a destination.
	 *
	 * A store can add the method to several shipping zones, each with its own token, handling
	 * fee and display rules, so "the settings" only mean something next to a destination. The
	 * zone comes from WooCommerce's own matcher, and no settings are returned rather than
	 * another zone's.
	 *
	 * "Offers this method" here means the zone method row is enabled, which is the zone screen
	 * toggle. It is not the same as the instance's own "Ativar método de entrega" checkbox:
	 * that one lives in the settings, so a zone can be matched here with the method switched off
	 * in it. Every caller has to read that checkbox for itself - `is_available()` does for the
	 * cart and the checkout, `Cdfrete_Frontend_Calculator::method_is_enabled()` for the product
	 * page calculator.
	 *
	 * @param array $destination Package destination: country, state and postcode.
	 */
	public static function get_settings_for_destination( array $destination ): array {
		return self::resolve_for_destination( $destination )['settings'];
	}

	/**
	 * The instance that serves a destination, together with its settings.
	 *
	 * Empty settings are not the same fact as no instance, and a caller that has to tell the
	 * two apart cannot do it from the settings alone: WooCommerce enables the method the moment
	 * it is added to a zone but stores no settings until the merchant saves the form, so a zone
	 * that answers can answer with nothing. The instance id says which of the two happened.
	 *
	 * @param array $destination Package destination: country, state and postcode.
	 *
	 * @return array{instance_id: int|null, settings: array}
	 */
	public static function resolve_for_destination( array $destination ): array {
		$instance_id = self::instance_id_for_destination( $destination );

		return [
			'instance_id' => $instance_id,
			'settings'    => null === $instance_id ? [] : self::get_instance_settings( $instance_id ),
		];
	}

	private static function instance_id_for_destination( array $destination ): ?int {
		$enabled = self::get_enabled_instance_ids();

		if ( empty( $enabled ) ) {
			return null;
		}

		$destination = self::destination_for_zone_matching( $destination );

		$zone = WC_Shipping_Zones::get_zone_matching_package( [ 'destination' => $destination ] );

		$zone_instance_ids = [];

		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'centraldofrete' === $method->id ) {
				$zone_instance_ids[] = (int) $method->instance_id;
			}
		}

		$picked = self::pick_instance( $enabled, $zone_instance_ids, self::quotable_instance_ids( $zone_instance_ids ) );

		if ( '' !== trim( (string) $destination['state'] ) ) {
			return $picked;
		}

		return self::stateless_pick( $picked, $zone_instance_ids, self::state_defined_instance_ids() );
	}

	/**
	 * The destination as the zone matcher has to receive it, with its state filled in.
	 *
	 * Kept free of WordPress so it can be tested on its own. WooCommerce matches a zone defined
	 * by state on "<country>:<state>", so a destination with a blank state skips every such zone
	 * and falls through to the next by order without a word - a broader zone with another token,
	 * another handling fee and another restriction. The postcode is not silent about the state:
	 * Correios allocates the ranges per federative unit, so the one the shopper just typed is
	 * derived and matched on, and the product page resolves the zone the checkout will.
	 *
	 * This is not the session state CF-387 excluded. That one belongs to whichever address the
	 * session happens to hold and matches a zone the shopper is not in; this one is the
	 * destination itself. A state the caller already has is therefore left exactly as it is, and
	 * outside Brazil nothing is derived, because the ranges mean nothing there.
	 *
	 * @param array $destination Package destination: country, state and postcode.
	 */
	public static function destination_for_zone_matching( array $destination ): array {
		$destination = array_merge( [
			'country'  => 'BR',
			'state'    => '',
			'postcode' => '',
		], $destination );

		$stateless = '' === trim( (string) $destination['state'] );
		$brazilian = 'BR' === strtoupper( trim( (string) $destination['country'] ) );

		if ( $stateless && $brazilian ) {
			$derived = self::state_for_postcode( (string) $destination['postcode'] );

			if ( null !== $derived ) {
				$destination['state'] = $derived;
			}
		}

		return $destination;
	}

	/**
	 * The federative unit a Brazilian postcode belongs to, or null when no range covers it.
	 *
	 * Kept free of WordPress so it can be tested on its own. Null is not a fallback state and no
	 * range is widened to avoid one: a wrong unit here means a deterministically wrong zone and
	 * no sign that anything went wrong, which is worse than showing no price. A postcode no
	 * published range covers leaves the destination stateless, and `stateless_pick()` then
	 * refuses rather than answer from a zone that only looks like a match.
	 *
	 * @param string $postcode Destination postcode, with or without punctuation.
	 */
	public static function state_for_postcode( string $postcode ): ?string {
		$digits = (string) preg_replace( '/\D/', '', $postcode );

		if ( strlen( $digits ) !== 8 ) {
			return null;
		}

		$number = (int) $digits;

		foreach ( self::POSTCODE_STATE_RANGES as $range ) {
			if ( $number >= (int) $range[1] && $number <= (int) $range[2] ) {
				return $range[0];
			}
		}

		return null;
	}

	/**
	 * Postcode ranges per federative unit, each with the postcode that verified it.
	 *
	 * Ranges as published by Correios, "Faixa de CEP por UF/Localidade":
	 * https://buscacepinter.correios.com.br/app/faixa_cep_uf_localidade/index.php
	 * Every row was verified on 2026-08-28 by resolving the probe postcode recorded for it
	 * through ViaCEP and comparing the federative unit reported; all thirty matched. The probe
	 * stays next to its range so a later session can re-verify the table without redoing the
	 * research, and nothing is added here that cannot be cited the same way.
	 *
	 * 78900000-78999999 is deliberately absent. It is listed historically for Rondônia, but a
	 * sweep of the whole range on 2026-08-28 found no allocated postcode answering: it is the
	 * range from before the renumbering to 768xx, and Rondônia's live range 76800000-76999999
	 * verified fine. It stays a gap, and a postcode inside it derives nothing.
	 *
	 * The bounds are strings so they read as Correios publishes them - written as integers, the
	 * leading zero would make 01000000 an octal literal.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: string}> Unit, first, last, probe.
	 */
	private const POSTCODE_STATE_RANGES = [
		[ 'SP', '01000000', '19999999', '08599000' ],
		[ 'RJ', '20000000', '28999999', '26299000' ],
		[ 'ES', '29000000', '29999999', '29299000' ],
		[ 'MG', '30000000', '39999999', '39499000' ],
		[ 'BA', '40000000', '48999999', '40010000' ],
		[ 'SE', '49000000', '49999999', '49001000' ],
		[ 'PE', '50000000', '56999999', '50010000' ],
		[ 'AL', '57000000', '57999999', '57699000' ],
		[ 'PB', '58000000', '58999999', '58499000' ],
		[ 'RN', '59000000', '59999999', '59299000' ],
		[ 'CE', '60000000', '63999999', '62399000' ],
		[ 'PI', '64000000', '64999999', '64099000' ],
		[ 'MA', '65000000', '65999999', '65049000' ],
		[ 'PA', '66000000', '68899999', '68754000' ],
		[ 'AP', '68900000', '68999999', '68994000' ],
		[ 'AM', '69000000', '69299999', '69059000' ],
		[ 'RR', '69300000', '69399999', '69319000' ],
		[ 'AM', '69400000', '69899999', '69424000' ],
		[ 'AC', '69900000', '69999999', '69919000' ],
		[ 'DF', '70000000', '72799999', '71959000' ],
		[ 'GO', '72800000', '72999999', '72899000' ],
		[ 'DF', '73000000', '73699999', '73006000' ],
		[ 'GO', '73700000', '76799999', '75714000' ],
		[ 'RO', '76800000', '76999999', '76839000' ],
		[ 'TO', '77000000', '77999999', '77449000' ],
		[ 'MT', '78000000', '78899999', '78449000' ],
		[ 'MS', '79000000', '79999999', '79949000' ],
		[ 'PR', '80000000', '87999999', '80010000' ],
		[ 'SC', '88000000', '89999999', '89899000' ],
		[ 'RS', '90000000', '99999999', '96999000' ],
	];

	/**
	 * Whether a zone matched without a state is the zone the destination really falls into.
	 *
	 * Kept free of WordPress so it can be tested on its own. WooCommerce matches a state
	 * location as "<country>:<state>", so with no state that criterion matches nothing: a zone
	 * defined by state is skipped rather than considered, and the query falls through to the
	 * next zone by order, which may be a country wide zone carrying this method with another
	 * token, another fee and another restriction. Nothing distinguishes that from a real match,
	 * so while a zone defined by state carries the method anywhere else, the match does not
	 * stand and the postcode gets no price instead of another region's.
	 *
	 * This is the exception now rather than the rule: `state_for_postcode()` fills the state in
	 * before the zone is matched, so a destination arrives here stateless only when no published
	 * range covers its postcode, or when the caller passed neither state nor a Brazilian country.
	 *
	 * @param int|null $picked            Instance the matched zone points at, null when none did.
	 * @param int[]    $zone_instance_ids Ids this method has in the matched zone.
	 * @param int[]    $state_defined_ids Ids of enabled instances in zones defined by state.
	 */
	public static function stateless_pick( ?int $picked, array $zone_instance_ids, array $state_defined_ids ): ?int {
		if ( null === $picked ) {
			return null;
		}

		$elsewhere = array_diff(
			array_map( 'intval', $state_defined_ids ),
			array_map( 'intval', $zone_instance_ids )
		);

		return empty( $elsewhere ) ? $picked : null;
	}

	/**
	 * Which enabled instance a matched zone points at.
	 *
	 * Kept free of WordPress so the choice can be tested on its own. Only an instance of the
	 * matched zone qualifies: a destination resolving to a zone that does not carry this method
	 * gets nothing back rather than some other zone's settings. Every destination is matched,
	 * including in a store with a single instance - it used to answer without consulting the
	 * zone, on the grounds that a postcode alone could not reach a zone defined by state, and
	 * the postcode now carries its state.
	 *
	 * One zone can hold the method twice, and then the lowest id is the entry added first, which
	 * is the one most likely to be a form the merchant closed without saving. Preferring an
	 * instance that can quote is not enough on its own, because it narrows the field without
	 * ordering it, so the whole tie-break is written out here: among the matched zone's enabled
	 * instances, those that can quote beat those that cannot, and the lowest id wins inside
	 * whichever of those two groups is used. The answer follows from the ids and the quotable
	 * list alone, so it does not move with the order the database returned them in.
	 *
	 * @param int[] $enabled_ids       Ids of every enabled instance, store wide.
	 * @param int[] $zone_instance_ids Ids this method has in the matched zone.
	 * @param int[] $quotable_ids      Of those, the ones that could actually return a price.
	 */
	public static function pick_instance( array $enabled_ids, array $zone_instance_ids, array $quotable_ids = [] ): ?int {
		$enabled    = array_values( array_unique( array_map( 'intval', $enabled_ids ) ) );
		$candidates = array_intersect( $enabled, array_map( 'intval', $zone_instance_ids ) );

		if ( empty( $candidates ) ) {
			return null;
		}

		$quotable = array_intersect( $candidates, array_map( 'intval', $quotable_ids ) );

		return min( empty( $quotable ) ? $candidates : $quotable );
	}

	/**
	 * Which of these instances could actually return a price.
	 *
	 * Saved, switched on and holding a token is what separates an entry the merchant decided on
	 * from one they abandoned: WooCommerce enables a zone method the moment it is added and
	 * stores no settings until the form is saved, so an abandoned entry sits there enabled and
	 * empty. Two shipping zones with different settings are two decisions to respect, but one
	 * zone holding two entries where only one was ever configured is a single decision plus a
	 * leftover, and refusing on account of the leftover would tell the shopper their region
	 * cannot be quoted while the sibling entry quotes that region.
	 *
	 * The calculator switch is deliberately not part of this: a zone with the calculator turned
	 * off is a decision, and this asks which entries carry one at all.
	 *
	 * @param int[] $instance_ids Instances to test.
	 *
	 * @return int[] Those that could quote.
	 */
	private static function quotable_instance_ids( array $instance_ids ): array {
		$quotable = [];

		foreach ( $instance_ids as $instance_id ) {
			$settings = self::get_instance_settings( (int) $instance_id );

			if ( empty( $settings ) || empty( $settings['token'] ) ) {
				continue;
			}

			if ( ! Cdfrete_Frontend_Calculator::method_is_enabled( $settings ) ) {
				continue;
			}

			$quotable[] = (int) $instance_id;
		}

		return $quotable;
	}

	/**
	 * Debug mode has no zone context: it is a diagnostic switch, and a merchant who turned it
	 * on in one zone asked for logs from the whole plugin.
	 */
	public static function debug_enabled(): bool {
		foreach ( self::get_all_settings() as $settings ) {
			if ( ( $settings['debug'] ?? 'no' ) === 'yes' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Settings of every enabled instance, for callers that have no shipping zone context.
	 *
	 * @return array[] One settings array per instance.
	 */
	public static function get_all_settings(): array {
		$settings = [];

		foreach ( self::get_enabled_instance_ids() as $instance_id ) {
			$instance_settings = self::get_instance_settings( (int) $instance_id );

			if ( ! empty( $instance_settings ) ) {
				$settings[] = $instance_settings;
			}
		}

		return $settings;
	}

	/**
	 * There is no WooCommerce API to look up instances of one method across every zone
	 * without instantiating all methods of all zones, which is heavy for a product page.
	 * The query takes no user input and the result is reused for the rest of the request.
	 */
	private static function get_enabled_instance_ids(): array {
		static $instance_ids = null;

		if ( null !== $instance_ids ) {
			return $instance_ids;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$instance_ids = $wpdb->get_col(
			"SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = 'centraldofrete' AND is_enabled = 1"
		);

		return $instance_ids;
	}

	/**
	 * Enabled instances sitting in a shipping zone that has at least one state location.
	 *
	 * Same reasoning as `get_enabled_instance_ids()`: reading zone locations through the
	 * WooCommerce API means instantiating every method of every zone, which is too much for a
	 * product page. The query takes no user input and the result is reused for the rest of the
	 * request.
	 */
	private static function state_defined_instance_ids(): array {
		static $instance_ids = null;

		if ( null !== $instance_ids ) {
			return $instance_ids;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$instance_ids = $wpdb->get_col(
			"SELECT DISTINCT methods.instance_id
			FROM {$wpdb->prefix}woocommerce_shipping_zone_methods AS methods
			INNER JOIN {$wpdb->prefix}woocommerce_shipping_zone_locations AS locations
				ON locations.zone_id = methods.zone_id
			WHERE methods.method_id = 'centraldofrete'
				AND methods.is_enabled = 1
				AND locations.location_type = 'state'"
		);

		return $instance_ids;
	}

	/**
	 * Convert comma-decimal to dot-decimal.
	 */
	private function fix_decimal( string $value ): float {
		return (float) str_replace( ',', '.', $value );
	}
}
