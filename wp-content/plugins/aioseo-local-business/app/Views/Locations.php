<?php
/**
 * This is the output for Local Business - Locations by Category on the frontend.
 *
 * @since 1.1.0
 * @version 1.1.0
 */

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<ul class="aioseo-category-locations aioseo-category-locations-<?php echo esc_attr( $termId ); ?> <?php echo esc_attr( $instance['class'] ); ?>">
	<?php foreach ( $locations as $location ) { ?>
		<li>
			<a href="<?php echo esc_attr( get_permalink( $location ) ); ?>"><?php echo esc_html( $location->post_title ); ?></a>
		</li>
	<?php } ?>
</ul>