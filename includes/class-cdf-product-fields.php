<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDF_Product_Fields {

	public static function init(): void {
		add_action( 'woocommerce_product_options_shipping', [ __CLASS__, 'render_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_field' ] );
	}

	/**
	 * Render cargo type selector on product edit page.
	 */
	public static function render_field(): void {
		global $post;

		$cargo_types = CDF_Shipping_Method::get_cargo_types();

		// Show message if no cargo types loaded.
		if ( empty( $cargo_types ) ) {
			echo '<p class="form-field">';
			echo '<label>' . esc_html__( 'Tipo de Carga (Central do Frete)', 'central-do-frete' ) . '</label>';
			echo '<span class="description">';
			echo esc_html__( 'Configure o token e atualize os tipos de carga nas configurações do plugin.', 'central-do-frete' );
			echo '</span>';
			echo '</p>';
			return;
		}

		$options = [ '' => __( 'Usar padrão do plugin', 'central-do-frete' ) ] + $cargo_types;
		$current = get_post_meta( $post->ID, 'cargo_type', true );

		woocommerce_wp_select( [
			'id'          => 'cargo_type',
			'label'       => __( 'Tipo de Carga (Central do Frete)', 'central-do-frete' ),
			'options'     => $options,
			'value'       => $current,
			'desc_tip'    => true,
			'description' => __( 'Selecione o tipo de carga para este produto. Se não selecionado, será usado o padrão configurado no plugin.', 'central-do-frete' ),
		] );
	}

	/**
	 * Save cargo type on product save.
	 */
	public static function save_field( int $post_id ): void {
		if ( ! isset( $_POST['cargo_type'] ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST['cargo_type'] ) );
		update_post_meta( $post_id, 'cargo_type', $value );
	}
}
