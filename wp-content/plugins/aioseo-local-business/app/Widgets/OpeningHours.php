<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Widgets;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OpeningHours.
 *
 * @since 1.1.0
 */
class OpeningHours extends \WP_Widget {
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
			'title'         => '',
			'locationId'    => '',
			'showTitle'     => true,
			'showIcons'     => true,
			'showMonday'    => true,
			'showTuesday'   => true,
			'showWednesday' => true,
			'showThursday'  => true,
			'showFriday'    => true,
			'showSaturday'  => true,
			'showSunday'    => true,
			'label'         => __( 'Our Opening Hours:', 'aioseo-local-business' ),
			'after'         => '',
		];

		// Widget Slug.
		$widgetSlug = 'aioseo-local-opening-hours-widget';

		// Widget basics.
		$widgetOps = [
			'classname'   => $widgetSlug,
			'description' => esc_html__( 'Display opening hours of a location.', 'aioseo-local-business' ),
		];

		// Widget controls.
		$controlOps = [
			'id_base' => $widgetSlug,
		];

		// Load widget.
		parent::__construct( $widgetSlug, esc_html__( 'AIOSEO Local - Opening Hours', 'aioseo-local-business' ), $widgetOps, $controlOps );
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
		// Merge with defaults.
		$instance = wp_parse_args( (array) $instance, $this->defaults );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Title.
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,Generic.Files.LineLength.MaxExceeded
		}

		// Location info.
		aioseoLocalBusiness()->locations->outputOpeningHours( $instance['locationId'], $instance ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// After.
		if ( ! empty( $instance['after'] ) ) {
			echo $instance['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		// $newInstance['locationId']    = ! empty( $newInstance['locationId'] ) ? (int) $newInstance['locationId'] : 0;
		$newInstance['title']         = wp_kses( $newInstance['title'], 'post' );
		$newInstance['showTitle']     = ! empty( $newInstance['showTitle'] ) ? '1' : false;
		$newInstance['showIcons']     = ! empty( $newInstance['showIcons'] ) ? '1' : false;
		$newInstance['showMonday']    = ! empty( $newInstance['showMonday'] ) ? '1' : false;
		$newInstance['showTuesday']   = ! empty( $newInstance['showTuesday'] ) ? '1' : false;
		$newInstance['showWednesday'] = ! empty( $newInstance['showWednesday'] ) ? '1' : false;
		$newInstance['showThursday']  = ! empty( $newInstance['showThursday'] ) ? '1' : false;
		$newInstance['showFriday']    = ! empty( $newInstance['showFriday'] ) ? '1' : false;
		$newInstance['showSaturday']  = ! empty( $newInstance['showSaturday'] ) ? '1' : false;
		$newInstance['showSunday']    = ! empty( $newInstance['showSunday'] ) ? '1' : false;
		$newInstance['after']         = wp_kses( $newInstance['after'], 'post' );

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
		<?php if ( aioseo()->options->localBusiness->locations->general->multiple ) { ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'locationId' ) ); ?>">
					<?php echo esc_html__( 'Location:', 'aioseo-local-business' ); ?>
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
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showTitle' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showTitle' ) ); ?>" <?php checked( '1', $instance['showTitle'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showTitle' ) ); ?>">
				<?php esc_html_e( 'Show title', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showIcons' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showIcons' ) ); ?>" <?php checked( '1', $instance['showIcons'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showIcons' ) ); ?>">
				<?php esc_html_e( 'Show icons', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<strong><?php esc_html_e( 'Opening Hours', 'aioseo-local-business' ); ?></strong>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showMonday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showMonday' ) ); ?>" <?php checked( '1', $instance['showMonday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showMonday' ) ); ?>">
				<?php esc_html_e( 'Monday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showTuesday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showTuesday' ) ); ?>" <?php checked( '1', $instance['showTuesday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showTuesday' ) ); ?>">
				<?php esc_html_e( 'Tuesday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showWednesday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showWednesday' ) ); ?>" <?php checked( '1', $instance['showWednesday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showWednesday' ) ); ?>">
				<?php esc_html_e( 'Wednesday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showThursday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showThursday' ) ); ?>" <?php checked( '1', $instance['showThursday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showThursday' ) ); ?>">
				<?php esc_html_e( 'Thursday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showFriday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showFriday' ) ); ?>" <?php checked( '1', $instance['showFriday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showFriday' ) ); ?>">
				<?php esc_html_e( 'Friday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showSaturday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showSaturday' ) ); ?>" <?php checked( '1', $instance['showSaturday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showSaturday' ) ); ?>">
				<?php esc_html_e( 'Saturday', 'aioseo-local-business' ); ?>
			</label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'showSunday' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'showSunday' ) ); ?>" <?php checked( '1', $instance['showSunday'] ); ?>> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
			<label for="<?php echo esc_attr( $this->get_field_id( 'showSunday' ) ); ?>">
				<?php esc_html_e( 'Sunday', 'aioseo-local-business' ); ?>
			</label>
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
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'after' ) ); ?>">
				<?php echo esc_html__( 'After widget text:', 'aioseo-local-business' ); ?>
			</label><br />
			<textarea id="<?php echo esc_attr( $this->get_field_id( 'after' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'after' ) ); ?>" class="widefat"><?php echo esc_attr( $instance['after'] ); ?></textarea> <?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
		</p>
		<?php
	}
}