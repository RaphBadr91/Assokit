<?php
namespace AIOSEO\Plugin\Pro\Standalone\BbPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone as CommonStandalone;
use AIOSEO\Plugin\Pro\Schema\Graphs as ProGraphs;

/**
 * bbPress Component class.
 *
 * @since 4.8.1
 */
class Component extends CommonStandalone\BbPress\Component {
	/**
	 * Determines the schema type for the current component.
	 *
	 * @since 4.8.1
	 *
	 * @return void
	 */
	public function determineSchemaGraphsAndContext() {
		if ( 'bbp-topic_single' === $this->templateType ) {
			aioseo()->schema->graphs[] = 'DiscussionForumPosting';

			ProGraphs\DiscussionForumPosting::setOverwriteGraphData( [
				'properties' => [
					'headline'      => $this->topic['title'],
					'text'          => $this->topic['content'],
					'datePublished' => $this->topic['date'],
					'author'        => $this->topic['author'],
					'comment'       => $this->topic['comment'] ?? []
				]
			] );
		}
	}
}