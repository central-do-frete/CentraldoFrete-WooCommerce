<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cdfrete_Product_Fields {

	/** Prefixed meta key holding the per-product cargo type. */
	public const META_KEY = '_cdfrete_cargo_type';

	/** Unprefixed key used up to 3.1.0, still read so saved products keep their cargo type. */
	private const LEGACY_META_KEY = 'cargo_type';

	public static function init(): void {
		add_action( 'woocommerce_product_options_shipping', [ __CLASS__, 'render_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_field' ] );
	}

	/**
	 * Render cargo type selector on product edit page.
	 */
	public static function render_field(): void {
		global $post;

		$cargo_types = Cdfrete_Shipping_Method::get_cargo_types();

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
		$current = self::get_cargo_type( (int) $post->ID );

		woocommerce_wp_select( [
			'id'          => self::META_KEY,
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
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) );
		update_post_meta( $post_id, self::META_KEY, $value );

		// The prefixed key is now authoritative, so the legacy one must not shadow it.
		delete_post_meta( $post_id, self::LEGACY_META_KEY );
	}

	/**
	 * Cargo type saved on a product, falling back to the unprefixed key written by
	 * versions up to 3.1.0 so an existing store keeps quoting the right cargo type.
	 */
	public static function get_cargo_type( int $product_id ): string {
		$value = (string) get_post_meta( $product_id, self::META_KEY, true );

		if ( '' !== $value ) {
			return $value;
		}

		return (string) get_post_meta( $product_id, self::LEGACY_META_KEY, true );
	}
}
