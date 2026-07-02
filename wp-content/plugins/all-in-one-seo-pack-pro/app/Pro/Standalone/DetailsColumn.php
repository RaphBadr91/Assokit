<?php
namespace AIOSEO\Plugin\Pro\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone\DetailsColumn as CommonDetailsColumn;
use AIOSEO\Plugin\Pro\Models;

/**
 * Handles the AIOSEO Details term column.
 *
 * @since 4.2.0
 */
class DetailsColumn extends CommonDetailsColumn {
	/**
	 * Class constructor.
	 *
	 * @since 4.2.0
	 */
	public function __construct() {
		parent::__construct();

		if ( wp_doing_ajax() ) {
			add_action( 'init', [ $this, 'addTaxonomyColumnsAjax' ], 1 );
		}

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'current_screen', [ $this, 'addTaxonomyColumns' ], 1 );
	}

	/**
	 * Gets the post data for the column.
	 *
	 * @since 4.5.0
	 *
	 * @param  int    $postId     The Post ID.
	 * @param  string $columnName The column name.
	 * @return array              The post data.
	 */
	protected function getPostData( $postId, $columnName ) {
		$postData = parent::getPostData( $postId, $columnName );

		if (
			'publish' === get_post_status( $postId ) &&
			aioseo()->searchStatistics->api->auth->isConnected()
		) {
			$permalink = get_permalink( $postId );
			$path      = aioseo()->searchStatistics->helpers->getPageSlug( $permalink );

			$postData['page']             = $path;
			$postData['inspectionResult'] = aioseo()->searchStatistics->urlInspection->get( $path );
		}

		return $postData;
	}

	/**
	 * Registers the AIOSEO Details column for taxonomies.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_Screen $screen The current screen.
	 * @return void
	 */
	public function addTaxonomyColumns( $screen ) {
		if ( ! $this->isTaxonomyColumn( $screen->base, $screen->taxonomy ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_filter( "manage_edit-{$screen->taxonomy}_columns", [ $this, 'addColumn' ], 10, 1 );
		add_filter( "manage_{$screen->taxonomy}_custom_column", [ $this, 'renderTaxonomyColumn' ], 10, 3 );
	}

	/**
	 * Registers our taxonomy columns after a term has been updated by ajax.
	 *
	 * @since 4.2.3
	 *
	 * @return void
	 */
	public function addTaxonomyColumnsAjax() {
		$isQuickEditRequest = isset( $_POST['_inline_edit'], $_POST['tax_ID'], $_POST['taxonomy'] ) && wp_verify_nonce( wp_unslash( $_POST['_inline_edit'] ), 'taxinlineeditnonce' );
		$isAddTagRequest    = isset( $_POST['_wpnonce_add-tag'], $_POST['tag-name'], $_POST['taxonomy'] ) && wp_verify_nonce( wp_unslash( $_POST['_wpnonce_add-tag'] ), 'add-tag' );

		if ( empty( $_POST['aioseo-has-details-column'] ) ||
			( ! $isQuickEditRequest && ! $isAddTagRequest )
		) {
			return;
		}

		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) );

		add_filter( "manage_edit-{$taxonomy}_columns", [ $this, 'addColumn' ], 11 );
		add_filter( "manage_{$taxonomy}_custom_column", [ $this, 'renderTaxonomyColumn' ], 11, 3 );
	}

	/**
	 * Renders the column in the taxonomy table.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $out        The output to display.
	 * @param  string $columnName The column name.
	 * @param  int    $termId     The current term id.
	 * @return string             A rendered html.
	 */
	public function renderTaxonomyColumn( $out, $columnName = '', $termId = 0 ) {
		if ( 'aioseo-details' !== $columnName ) {
			return $out;
		}

		// phpcs:disable Squiz.NamingConventions.ValidVariableName
		global $wp_scripts, $wp_query;
		if (
			! is_object( $wp_scripts ) ||
			! method_exists( $wp_scripts, 'get_data' ) ||
			! method_exists( $wp_scripts, 'add_data' )
		) {
			return $out;
		}

		// Add this column/post to the localized array.
		$data = $wp_scripts->get_data( 'aioseo/js/' . $this->scriptSlug, 'data' );
		// phpcs:enable Squiz.NamingConventions.ValidVariableName

		if ( ! is_array( $data ) ) {
			$data = json_decode( str_replace( 'var aioseo = ', '', substr( $data, 0, -1 ) ), true );
		}

		$nonce   = wp_create_nonce( "aioseo_meta_{$columnName}_{$termId}" );
		$terms   = $data['terms'] ?? [];
		$theTerm = Models\Term::getTerm( $termId );

		// Turn on the tax query so we can get specific tax data.
		$originalTax      = $wp_query->is_tax; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		$wp_query->is_tax = true; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		$terms[] = [
			'id'              => $termId,
			'columnName'      => $columnName,
			'nonce'           => $nonce,
			'title'           => ! empty( $theTerm->title ) ? $theTerm->title : '',
			'showTitle'       => apply_filters( 'aioseo_details_column_term_show_title', true, $termId ),
			'description'     => ! empty( $theTerm->description ) ? $theTerm->description : '',
			'showDescription' => apply_filters( 'aioseo_details_column_term_show_description', true, $termId ),
		];

		$wp_query->is_tax = $originalTax; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		$data['terms']    = $terms;

		$wp_scripts->add_data( 'aioseo/js/' . $this->scriptSlug, 'data', '' ); // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		wp_localize_script( 'aioseo/js/' . $this->scriptSlug, 'aioseo', $data );

		ob_start();
		require AIOSEO_DIR . '/app/Common/Views/admin/terms/columns.php';
		$out = ob_get_clean();

		return $out;
	}

	/**
	 * Check if the taxonomy should show AIOSEO column.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $taxonomy The taxonomy slug.
	 * @return bool             Whether the taxonomy should show AIOSEO column.
	 */
	private function isTaxonomyColumn( $screen, $taxonomy ) {
		if ( 'type' === $taxonomy ) {
			$taxonomy = '_aioseo_type';
		}

		if ( 'edit-tags' === $screen ) {
			if (
				aioseo()->options->advanced->taxonomies->all &&
				(
					in_array( $taxonomy, aioseo()->helpers->getPublicTaxonomies( true ), true ) ||
					aioseo()->helpers->isWooCommerceProductAttribute( $taxonomy )
				)
			) {
				return true;
			}

			$taxonomies = aioseo()->options->advanced->taxonomies->included;
			if (
				in_array( $taxonomy, $taxonomies, true ) ||
				( aioseo()->helpers->isWooCommerceProductAttribute( $taxonomy ) && in_array( 'product_attributes', $taxonomies, true ) )
			) {
				return true;
			}
		}

		return false;
	}
}