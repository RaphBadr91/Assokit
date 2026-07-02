<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Graph Organization graph class.
 *
 * @since 1.3.3
 */
class KgOrganization extends Base {
	/**
	 *The data for the global options.
	 *
	 * @since 1.3.3
	 *
	 * @var object|null
	 */
	protected $dataObject = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.3
	 */
	public function __construct() {
		$this->dataObject = aioseoLocalBusiness()->helpers->getLocalBusinessOptions();
	}

	/**
	 * Returns the graph data.
	 *
	 * @since 1.3.3
	 *
	 * @return void
	 */
	public function get() {}

	/**
	 * Returns the graph data.
	 *
	 * @since 1.3.3
	 *
	 * @return array $data The graph data.
	 */
	public function getAdditionalGraphData( $postId, $data ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( aioseo()->options->localBusiness->locations->general->multiple ) {
			return $data;
		}

		$additionalData = [
			'address' => $this->address()
		];

		if ( empty( $data['email'] ) ) {
			$additionalData['email'] = $this->dataObject->locations->business->contact->email;
		}

		$additionalData += $this->ids();

		return array_merge( $data, $additionalData );
	}
}