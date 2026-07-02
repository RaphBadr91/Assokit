<?php
/**
 * This is the output for Local Business - Map on the frontend.
 *
 * @since 1.1.3
 * @version 1.1.3
 */

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mapStyle = [
	'width'  => is_numeric( $instance['width'] ) ? $instance['width'] . 'px' : $instance['width'],
	'height' => is_numeric( $instance['height'] ) ? $instance['height'] . 'px' : $instance['height']
];

$mapStyle = array_reduce( array_keys( $mapStyle ), function ( $carry, $key ) use ( $mapStyle ) {
	return $carry . $key . ': ' . $mapStyle[ $key ] . '; ';
} );
?>
<div class="aioseo-location-map <?php echo esc_attr( $instance['class'] ); ?>">
	<?php
	$svgIcon = apply_filters( 'aioseo_local_business_map_icon', '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 11.14L9.83 5.2C9.04 4.77 8.5 3.95 8.5 3C8.5 1.62 9.62 0.499999 11 0.499999C12.38 0.499999 13.5 1.62 13.5 3C13.5 3.95 12.96 4.77 12.17 5.2L11 11.14ZM11 1.5C10.17 1.5 9.5 2.17 9.5 3C9.5 3.83 10.17 4.5 11 4.5C11.83 4.5 12.5 3.83 12.5 3C12.5 2.17 11.83 1.5 11 1.5ZM12.72 6.3L11 13.68L9.27 6.38L5 4.97L2.98023e-08 6.97V15.97L5 13.97L11.12 16L16 13.97V4.97L12.72 6.3Z" fill="currentcolor"/></svg>' ); // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	?>
	<div class="d-flex">
		<?php if ( $instance['showIcon'] ) : ?>
			<div class="col-auto">
				<span class="icon map">
					<?php echo aioseo()->helpers->escSvg( $svgIcon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</div>
		<?php endif; ?>
		<?php if ( $instance['showLabel'] ) : ?>
			<div class="col">
				<div class="label">
					<?php echo esc_html( $instance['label'] ); ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<div id="<?php echo esc_attr( $instance['mapId'] ) ?>" class="aioseo-local-map" style="<?php echo esc_attr( $mapStyle ) ?>">
		<?php aioseo()->templates->getTemplate( 'parts/loader.php' ); ?>
	</div>
</div>