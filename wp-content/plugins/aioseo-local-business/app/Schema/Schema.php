<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Schema;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds our schema.
 *
 * @since 1.0.0
 */
class Schema {
	/**
	 * Determines which graphs need to be output.
	 *
	 * @since   1.1.0
	 * @version 1.3.0
	 *
	 * @return array A list of graphs that need to be output.
	 */
	public function determineGraphsAndContext() {
		if ( ! aioseo()->options->localBusiness->locations->general->multiple ) {
			return is_front_page() || ( aioseo()->helpers->isStaticHomepage() && aioseo()->schema->generatingValidatorOutput ) ? [ 'LocalBusiness' ] : [];
		}

		return is_singular( aioseoLocalBusiness()->postType->getName() ) ? [ 'LocalBusiness' ] : [];
	}

	/**
	 * Returns data for the given graph if it's contained within this addon.
	 *
	 * @since 1.0.0
	 *
	 * @return array The graph data.
	 */
	public function get( $graphName ) {
		$namespace = __NAMESPACE__ . "\Graphs\\$graphName";
		if ( ! class_exists( $namespace ) ) {
			return [];
		}

		return ( new $namespace() )->get();
	}
}