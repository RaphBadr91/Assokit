<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Widgets;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AIOSEO Business Info widget.
 *
 * @since 1.1.3
 */
class Map extends \WP_Widget {
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
	 * @since 1.1.3
	 */
	public function __construct() {
		// Widget defaults.
		$this->defaults = [
			'title'      => '',
			'locationId' => '',
			'showLabel'  => true,
			'showIcon'   => true,
			'width'      => '100%',
			'height'     => '450px',
			'label'      => __( 'Our location:', 'aioseo-local-business' )
		];

		// Widget Slug.
		$widgetSlug = 'aioseo-local-map-widget';

		// Widget basics.
		$widgetOps = [
			'classname'   => $widgetSlug,
			'description' => esc_html__( 'Display a location map.', 'aioseo-local-business' ),
		];

		// Widget controls.
		$controlOps = [
			'id_base' => $widgetSlug,
		];

		// Load widget.
		parent::__construct( $widgetSlug, esc_html__( 'AIOSEO Local - Map', 'aioseo-local-business' ), $widgetOps, $controlOps );
	}

	/**
	 * Widget callback.
	 *
	 * @since 1.1.3
	 *
	 * @param  array $args     Widget args.
	 * @param  array $instance The widget instance options.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		// Merge with defaults.
		$instance = wp_parse_args( (array) $instance, $this->defaults );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Title.
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,Generic.Files.LineLength.MaxExceeded
		}

		// Location info.
		aioseoLocalBusiness()->locations->outputLocationMap( $instance['locationId'], $instance );

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Widget option update.
	 *
	 * @since 1.1.3
	 *
	 * @param  array $newInstance New instance options.
	 * @param  array $oldInstance Old instance options.
	 * @return array              Processed new instance options.
	 */
	public function update( $newInstance, $oldInstance ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$newInstance['title']     = wp_strip_all_tags( $newInstance['title'] );
		$newInstance['showLabel'] = isset( $newInstance['showLabel'] ) ? '1' : false;
		$newInstance['showIcon']  = isset( $newInstance['showIcon'] ) ? '1' : false;

		return $newInstance;
	}

	/**
	 * Widget options form.
	 *
	 * @since 1.1.3
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
		<?php if ( aioseo()->options->localBusiness->locations->general->multiple ) { ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'locationId' ) ); ?>">
					<?php esc_html_e( 'Location:', 'aioseo-local-business' ); ?>
				</label>
				<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'locationId' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'locationId' ) ); ?>">
					<?php
					$locations = aioseoLocalBusiness()->locations->getLocations( [
						'order_by' => 'post_title',
						'order'    => 'asc'
					] );
					if ( ! empty( $locations ) ) {
						echo '<option value="" selected disabled>' . esc_html__( 'Select your location', 'aioseo-local-business' ) . '</option>';
						foreach ( $locations as $location ) {
							echo '<option value="' . esc_attr( $location->ID ) . '" ' . selected( $instance['locationId'], $location->ID, false ) . '>' . esc_html( $location->post_title ) . '</option>'; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
						}
					} else {
						echo '<option value="">' . esc_html__( 'No locations available', 'aioseo-local-business' ) . '</option>';
					}
					?>
				</select>
			</p>
		<?php } ?>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showLabel' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showLabel' ) ); ?>" <?php checked( '1', $instance['showLabel'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showLabel' ) ); ?>">
				<?php esc_html_e( 'Show label', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showIcon' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showIcon' ) ); ?>" <?php checked( '1', $instance['showIcon'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showIcon' ) ); ?>">
				<?php esc_html_e( 'Show icon', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'width' ) ); ?>">
				<?php echo esc_html( __( 'Width', 'aioseo-local-business' ) ); ?>:
			</label>
			<input
					type="text"
					id="<?php echo esc_attr( $this->get_field_id( 'width' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'width' ) ); ?>"
					value="<?php echo esc_attr( $instance['width'] ); ?>"
					class="widefat"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>">
				<?php echo esc_html( __( 'Height', 'aioseo-local-business' ) ); ?>:
			</label>
			<input
					type="text"
					id="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'height' ) ); ?>"
					value="<?php echo esc_attr( $instance['height'] ); ?>"
					class="widefat"
			/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>">
				<?php esc_html_e( 'Label', 'aioseo-local-business' ); ?>
			</label>
			<input
					type="text"
					id="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'label' ) ); ?>"
					value="<?php echo esc_attr( $instance['label'] ); ?>"
					class="widefat"
			/>
		</p>
		<?php
	}
}