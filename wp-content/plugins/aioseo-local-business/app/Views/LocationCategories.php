<?php
/**
 * This is the output for Local Business - Locations by Category on the frontend.
 *
 * @since 1.1.1
 * @version 1.1.1
 */

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php if ( ! empty( $categories ) ) : ?>
	<ul class="aioseo-location-categories <?php echo esc_attr( $instance['class'] ); ?>">
	<?php
		wp_list_categories( [
			'title_li' => '',
			'taxonomy' => 'aioseo-location-category',
			'include'  => wp_list_pluck( $categories, 'term_id' )
		] );
	?>
	</ul>
<?php endif; ?>