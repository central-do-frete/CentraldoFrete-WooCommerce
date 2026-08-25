<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the Central do Frete method serves the shipping classes of a cart.
 *
 * `allows()` is deliberately free of WordPress calls so the truth table can be unit
 * tested. Everything that touches WooCommerce lives in the *_from_* helpers.
 */
class Cdfrete_Shipping_Class_Rule {

	public const RULE_ALL     = 'all';
	public const RULE_INCLUDE = 'include';
	public const RULE_EXCLUDE = 'exclude';

	/** Products with no shipping class are selectable as if they were one. */
	public const NO_CLASS = 'none';

	/**
	 * @param array  $cart_classes Class keys present in the cart.
	 * @param string $rule         One of the RULE_* constants.
	 * @param array  $selected     Class keys chosen by the merchant.
	 * @param bool   $strict       Require every product to qualify, not just one.
	 */
	public static function allows( array $cart_classes, string $rule, array $selected, bool $strict ): bool {
		if ( self::RULE_INCLUDE !== $rule && self::RULE_EXCLUDE !== $rule ) {
			return true;
		}

		$selected = self::normalize( $selected );
		$cart     = self::normalize( $cart_classes );

		if ( empty( $selected ) || empty( $cart ) ) {
			return true;
		}

		$matched = count( array_intersect( $cart, $selected ) );

		if ( self::RULE_INCLUDE === $rule ) {
			return $strict ? $matched === count( $cart ) : $matched > 0;
		}

		return $strict ? 0 === $matched : $matched < count( $cart );
	}

	/**
	 * Same decision, reading the merchant configuration straight from a settings array.
	 */
	public static function allows_for_settings( array $cart_classes, array $settings ): bool {
		return self::allows(
			$cart_classes,
			(string) ( $settings['shipping_class_rule'] ?? self::RULE_ALL ),
			(array) ( $settings['shipping_classes'] ?? [] ),
			( $settings['shipping_class_strict'] ?? 'no' ) === 'yes'
		);
	}

	/**
	 * Class keys of every item in the package that actually needs shipping.
	 */
	public static function classes_from_package( array $package ): array {
		$classes = [];

		foreach ( $package['contents'] ?? [] as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}

			$classes[] = self::class_of_product( $product );
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Variations inherit the parent class on their own in the `view` context.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function class_of_product( $product ): string {
		$class_id = (int) $product->get_shipping_class_id();

		return $class_id > 0 ? (string) $class_id : self::NO_CLASS;
	}

	private static function normalize( array $classes ): array {
		return array_values( array_unique( array_map( 'strval', $classes ) ) );
	}
}
