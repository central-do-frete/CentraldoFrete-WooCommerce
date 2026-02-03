<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="cdf-shipping-calculator" class="cdf-shipping-calculator">
	<p class="cdf-title">
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
		<?php esc_html_e( 'Calcule o frete', 'central-do-frete' ); ?>
	</p>
	<form class="cdf-form-row" onsubmit="return false;">
		<input
			type="text"
			class="cdf-postcode-input"
			placeholder="<?php esc_attr_e( 'Digite seu CEP', 'central-do-frete' ); ?>"
			maxlength="9"
			inputmode="numeric"
			autocomplete="postal-code"
		/>
		<button type="submit" class="cdf-calculate-btn">
			<?php esc_html_e( 'Calcular', 'central-do-frete' ); ?>
		</button>
	</form>
	<div class="cdf-results"></div>
</div>
