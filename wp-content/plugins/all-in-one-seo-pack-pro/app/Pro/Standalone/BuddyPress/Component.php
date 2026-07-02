<?php
namespace AIOSEO\Plugin\Pro\Standalone\BuddyPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone as CommonStandalone;
use AIOSEO\Plugin\Pro\Schema\Graphs as ProGraphs;

/**
 * BuddyPress Component class.
 *
 * @since 4.8.1
 */
class Component extends CommonStandalone\BuddyPress\Component {
	/**
	 * Determines the schema type for the current component.
	 *
	 * @since 4.8.1
	 *
	 * @param  \AIOSEO\Plugin\Common\Schema\Context $contextInstance The Context class instance.
	 * @return void
	 */
	public function determineSchemaGraphsAndContext( $contextInstance ) {
		parent::determineSchemaGraphsAndContext( $contextInstance );

		if ( 'bp-activity_single' === $this->templateType ) {
			aioseo()->schema->graphs[] = 'DiscussionForumPosting';

			ProGraphs\DiscussionForumPosting::setOverwriteGraphData( [
				'properties' => [
					'headline'      => sanitize_text_field( $this->activity['action'] ),
					'text'          => $this->activity['content_rendered'],
					'datePublished' => $this->activity['date_recorded'],
					'author'        => $this->activity['user_fullname'],
					'comment'       => $this->activity['children'] ?? []
				]
			] );
		}
	}
}