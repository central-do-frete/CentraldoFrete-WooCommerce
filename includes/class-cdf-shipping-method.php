<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDF_Shipping_Method extends WC_Shipping_Method {

	private const CARGO_TYPES_OPTION = 'centraldofrete_cargotypes';

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
	}

	public function init_form_fields(): void {
		$cargo_type_options = $this->get_cargo_type_options();
		$has_cargo_types    = count( $cargo_type_options ) > 1;

		$this->instance_form_fields = [
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
				'label'       => __( 'Mostrar campo "Calcule o frete"', 'central-do-frete' ),
				'description' => __( 'Permite calcular o frete antes de adicionar ao carrinho.', 'central-do-frete' ),
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
				'type'        => 'cdf_refresh_button',
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

			// ═══════════════════════════════════════════════════════════════
			// SEÇÃO 6: CONFIGURAÇÕES TÉCNICAS
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
		];
	}

	/**
	 * Generate custom button field for refreshing cargo types.
	 */
	public function generate_cdf_refresh_button_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$defaults  = [
			'title'       => '',
			'description' => '',
		];
		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button" id="cdf-refresh-cargo-types">
					<?php esc_html_e( 'Atualizar Tipos de Carga', 'central-do-frete' ); ?>
				</button>
				<p class="description" id="cdf-cargo-types-status"><?php echo wp_kses_post( $data['description'] ); ?></p>
				<script>
				jQuery(function($) {
					$('#cdf-refresh-cargo-types').on('click', function() {
						var $btn = $(this);
						var $status = $('#cdf-cargo-types-status');
						$btn.prop('disabled', true).text('Carregando...');
						$.post(ajaxurl, {
							action: 'cdf_refresh_cargo_types',
							nonce: '<?php echo esc_js( wp_create_nonce( 'cdf_refresh_cargo_types' ) ); ?>',
							instance_id: '<?php echo esc_js( $this->instance_id ); ?>'
						}, function(response) {
							$btn.prop('disabled', false).text('Atualizar Tipos de Carga');
							if (response.success) {
								$status.text(response.data.message);
								location.reload();
							} else {
								$status.text(response.data.message || 'Erro ao atualizar.');
							}
						}).fail(function() {
							$btn.prop('disabled', false).text('Atualizar Tipos de Carga');
							$status.text('Erro de conexão.');
						});
					});
				});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for refreshing cargo types.
	 */
	public static function ajax_refresh_cargo_types(): void {
		check_ajax_referer( 'cdf_refresh_cargo_types', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissão negada.', 'central-do-frete' ) ] );
		}

		$instance_id = absint( $_POST['instance_id'] ?? 0 );
		$settings    = get_option( 'woocommerce_centraldofrete_' . $instance_id . '_settings', [] );
		$token       = $settings['token'] ?? '';

		CDF_API_Client::log( 'info', sprintf(
			'[ADMIN] Atualizando tipos de carga - Instance ID: %d, Token: %s***',
			$instance_id,
			substr( $token, 0, 10 )
		) );

		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => __( 'Token não configurado. Salve as configurações primeiro.', 'central-do-frete' ) ] );
		}

		$client = new CDF_API_Client( $token );
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

		CDF_API_Client::log( 'info', sprintf( '[ADMIN] %d tipos de carga salvos', count( $types ) ) );

		wp_send_json_success( [
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

		return apply_filters( 'woocommerce_shipping_centraldofrete_is_available', true, $package, $this );
	}

	/**
	 * Calculate shipping rates.
	 */
	public function calculate_shipping( $package = [] ): void {
		$start_time = microtime( true );

		$token = $this->get_option( 'token' );
		if ( empty( $token ) ) {
			CDF_API_Client::log( 'error', '[SHIP] Token não configurado.' );
			return;
		}

		$from = preg_replace( '/\D/', '', get_option( 'woocommerce_store_postcode', '' ) );
		$to   = preg_replace( '/\D/', '', $package['destination']['postcode'] ?? '' );

		CDF_API_Client::log( 'info', sprintf(
			'[SHIP] Iniciando cotação - Origem: %s, Destino: %s, Itens: %d',
			$from ?: 'N/A',
			$to ?: 'N/A',
			count( $package['contents'] ?? [] )
		) );

		if ( empty( $from ) ) {
			CDF_API_Client::log( 'warning', '[SHIP] CEP de origem não configurado na loja' );
			return;
		}

		if ( empty( $to ) ) {
			CDF_API_Client::log( 'debug', '[SHIP] CEP de destino não informado (aguardando input do cliente)' );
			return;
		}

		if ( ( $package['destination']['country'] ?? 'BR' ) !== 'BR' ) {
			CDF_API_Client::log( 'debug', sprintf(
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

		CDF_API_Client::log( 'debug', sprintf(
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
			$cargo_type = get_post_meta( $product_id, 'cargo_type', true );
			if ( empty( $cargo_type ) ) {
				$cargo_type = $default_cargo_type;
			}
			if ( ! empty( $cargo_type ) ) {
				$cargo_types[] = (int) $cargo_type;
			}

			$invoice += (float) $product->get_price() * $qty;

			CDF_API_Client::log( 'debug', sprintf(
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
			CDF_API_Client::log( 'warning', '[SHIP] Nenhum volume no pacote' );
			return;
		}

		// Build recipient info.
		$recipient = $this->get_recipient_info( $package );

		CDF_API_Client::log( 'debug', sprintf(
			'[SHIP] Destinatário: %s, NF: R$ %.2f, Tipos carga: [%s]',
			$recipient ? substr( $recipient['document'], 0, 3 ) . '***' : 'N/A',
			$invoice,
			implode( ', ', $cargo_types ) ?: 'N/A'
		) );

		// Check cache (include recipient in key if present).
		$cache_data = [
			'from'        => $from,
			'to'          => $to,
			'volumes'     => $volumes,
			'cargo_types' => $cargo_types,
			'recipient'   => $recipient,
		];
		$cache_key = 'cdf_quote_' . md5( wp_json_encode( $cache_data ) );
		$cached    = CDF_Cache::get( $cache_key );

		if ( $cached !== false ) {
			$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			CDF_API_Client::log( 'info', sprintf(
				'[SHIP] Cache HIT - Key: %s..., %d opções, %.2fms',
				substr( $cache_key, 0, 25 ),
				count( $cached ),
				$elapsed
			) );
			$this->add_rates_from_services( $cached, $start_time );
			return;
		}

		CDF_API_Client::log( 'info', '[SHIP] Cache MISS - Consultando API Central do Frete' );

		// Fetch from API.
		$api_start = microtime( true );
		$timeout   = (int) $this->get_option( 'api_timeout', 15 );
		$client    = new CDF_API_Client( $token, $timeout );

		$services = $client->get_quotation( $from, $to, $volumes, $cargo_types, $invoice, $recipient );

		$api_elapsed = round( ( microtime( true ) - $api_start ) * 1000, 2 );

		if ( $services === false ) {
			CDF_API_Client::log( 'error', sprintf(
				'[SHIP] Falha na API após %.2fms',
				$api_elapsed
			) );
			return;
		}

		CDF_API_Client::log( 'info', sprintf(
			'[SHIP] API retornou %d opções em %.2fms',
			count( $services ),
			$api_elapsed
		) );

		// Cache results.
		$ttl = CDF_Cache::ttl_from_setting( $this->get_option( 'cache_ttl', '1h' ) );
		CDF_Cache::set( $cache_key, $services, $ttl );

		CDF_API_Client::log( 'debug', sprintf(
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
			CDF_API_Client::log( 'debug', '[SHIP] Destinatário: WC()->customer não disponível' );
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
			CDF_API_Client::log( 'debug', '[SHIP] Destinatário: CPF/CNPJ não encontrado no cliente' );
			return null;
		}

		$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );

		CDF_API_Client::log( 'debug', sprintf(
			'[SHIP] Destinatário encontrado: %s*** (%s)',
			substr( preg_replace( '/\D/', '', $document ), 0, 3 ),
			$name ?: 'sem nome'
		) );

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

		CDF_API_Client::log( 'debug', sprintf(
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
			CDF_API_Client::log( 'debug', sprintf(
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

			CDF_API_Client::log( 'debug', sprintf(
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

		CDF_API_Client::log( 'debug', sprintf(
			'[SHIP] Limite "%s" aplicado: %d -> %d opções',
			$display_limit,
			$before_limit,
			count( $services )
		) );

		if ( empty( $services ) ) {
			CDF_API_Client::log( 'warning', '[SHIP] Todas as opções foram filtradas - nenhuma taxa adicionada' );
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
						_n( 'Entrega em %d dia útil', 'Entrega em %d dias úteis', $days, 'central-do-frete' ),
						$days
					)
				);
			}

			$cost = $service['price'] + $handling_fee;

			$meta_data = [
				'CDF_ID'        => $service['id'],
				'CDF_QUOTATION' => $service['quotation_code'] ?? '',
				'CDF_DISPATCH'  => $service['dispatch'] ?? '',
			];

			// Add logo URL to meta if enabled.
			if ( $show_carrier_logo && ! empty( $service['carrier_logo'] ) ) {
				$meta_data['CDF_LOGO'] = $service['carrier_logo'];
			}

			$rate_id = $this->get_rate_id( 'CDF_' . sanitize_title( $service['shipping_carrier'] . '_' . $service['service_type'] ) );

			$this->add_rate( [
				'id'        => $rate_id,
				'label'     => $label,
				'cost'      => $cost,
				'meta_data' => $meta_data,
			] );

			CDF_API_Client::log( 'debug', sprintf(
				'[SHIP] Taxa adicionada: %s = R$ %.2f (%s)',
				$rate_id,
				$cost,
				$label
			) );
		}

		// Log completion time if start_time provided.
		if ( $start_time !== null ) {
			$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			CDF_API_Client::log( 'info', sprintf(
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

				CDF_API_Client::log( 'debug', sprintf(
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
					CDF_API_Client::log( 'debug', '[SHIP] economic_express - Mesma opção (mais barata = mais rápida)' );
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

		$client    = new CDF_API_Client( $token );
		$new_types = $client->get_cargo_types();

		if ( $new_types !== false ) {
			update_option( self::CARGO_TYPES_OPTION, $new_types, false );
		}
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
	 * Get shipping method settings (static helper).
	 */
	public static function get_settings(): array {
		global $wpdb;

		$instance_id = $wpdb->get_var(
			"SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = 'centraldofrete' LIMIT 1"
		);

		if ( ! $instance_id ) {
			return [];
		}

		return get_option( 'woocommerce_centraldofrete_' . $instance_id . '_settings', [] );
	}

	/**
	 * Convert comma-decimal to dot-decimal.
	 */
	private function fix_decimal( string $value ): float {
		return (float) str_replace( ',', '.', $value );
	}
}
