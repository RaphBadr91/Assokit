<?php
/**
 * This is the output for Local Business - Map Info Window on the frontend.
 *
 * @since 1.1.3
 * @version 1.2.1
 */

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$businessData = ! empty( $locationData->locations->business ) ? $locationData->locations->business : null;
?>
<div class="marker-info-window">
	<?php if ( $businessData ) : ?>
		<?php if ( ! empty( $businessData->name ) ) : ?>
			<div class="name">
				<?php echo esc_html( $businessData->name ) ?>
			</div>
		<?php endif; ?>
		<?php
		$haveAddress = array_filter( (array) $businessData->address );
		unset( $haveAddress['addressFormat'] );
		if ( ! empty( $haveAddress ) && isset( $businessData->address->addressFormat ) ) :
			?>
			<div class="address">
				<?php
				$value = aioseoLocalBusiness()->tags->replaceTags( aioseo()->helpers->decodeHtmlEntities( $businessData->address->addressFormat ), null, $businessData->address );
				echo nl2br( wp_kses_post( $value ) );
				?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $businessData->contact->phoneFormatted ) ) : ?>
			<div class="telephone">
				<?php echo esc_html( aioseoLocalBusiness()->locations->formatPhone( $businessData->contact->phoneFormatted ) ); ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>