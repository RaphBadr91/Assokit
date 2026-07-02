<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Widgets;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Locations.
 *
 * @since 1.1.0
 */
class Locations extends \WP_Widget {
	/**
	 * The default attributes.
	 *
	 * @since 1.2.12
	 *
	 * @var array
	 */
	private $defaults = [];

	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		// Widget defaults.
		$this->defaults = [
			'title'      => '',
			'categoryId' => ''
		];

		// Widget Slug.
		$widgetSlug = 'aioseo-local-category-widget';

		// Widget basics.
		$widgetOps = [
			'classname'   => $widgetSlug,
			'description' => esc_html__( 'Display a list of locations by category.', 'aioseo-local-business' ),
		];

		// Widget controls.
		$controlOps = [
			'id_base' => $widgetSlug,
		];

		// Load widget.
		parent::__construct( $widgetSlug, esc_html__( 'AIOSEO Local - Locations by Category', 'aioseo-local-business' ), $widgetOps, $controlOps );
	}

	/**
	 * Widget callback.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $args     Widget args.
	 * @param  array $instance The widget instance options.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		if ( aioseo()->options->localBusiness->locations->general->multiple ) {

			if ( empty( $instance['categoryId'] ) || is_wp_error( get_term( $instance['categoryId'], aioseoLocalBusiness()->taxonomy->getName() ) ) ) {
				return;
			}

			// Merge with defaults.
			$instance = wp_parse_args( (array) $instance, $this->defaults );

			echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			// Title.
			if ( ! empty( $instance['title'] ) ) {
				echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,Generic.Files.LineLength.MaxExceeded
			}

			// Location info.
			if ( ! empty( $instance['categoryId'] ) ) {
				aioseoLocalBusiness()->locations->outputLocationCategory( absint( $instance['categoryId'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Widget option update.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $newInstance New instance options.
	 * @param  array $oldInstance Old instance options.
	 * @return array              Processed new instance options.
	 */
	public function update( $newInstance, $oldInstance ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$newInstance['title']      = wp_strip_all_tags( $newInstance['title'] );
		$newInstance['categoryId'] = ! empty( $newInstance['categoryId'] ) ? (int) $newInstance['categoryId'] : 0;

		return $newInstance;
	}

	/**
	 * Widget options form.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $instance The widget instance options.
	 * @return void
	 */
	public function form( $instance ) {
		// Merge with defaults.
		$instance = wp_parse_args( (array) $instance, $this->defaults );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php echo esc_html( __( 'Title:', 'aioseo-local-business' ) ); ?>
			</label>
			<input
					type="text"
					id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
					value="<?php echo esc_attr( $instance['title'] ); ?>"
					class="widefat"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'categoryId' ) ); ?>">
				<?php echo esc_html__( 'Category:', 'aioseo-local-business' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'categoryId' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'categoryId' ) ); ?>">
				<?php
				$categories = aioseoLocalBusiness()->locations->getLocationCategories();
				if ( ! empty( $categories ) ) {
					echo '<option value="" selected disabled>' . esc_html__( 'Select a category', 'aioseo-local-business' ) . '</option>';
					foreach ( $categories as $category ) {
						echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $instance['categoryId'], $category->term_id, false ) . '>';
						echo esc_html( $category->name );
						echo '</option>';
					}
				} else {
					echo '<option value="">' . esc_html__( 'No categories available', 'aioseo-local-business' ) . '</option>';
				}
				?>
			</select>
		</p>
		<?php
	}
}