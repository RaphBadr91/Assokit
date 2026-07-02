<?php
namespace AIOSEO\Plugin\Pro\Schema;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema as CommonSchema;

/**
 * Builds our schema.
 *
 * @since 4.0.13
 */
class Schema extends CommonSchema\Schema {
	/**
	 * The Pro subdirectories that contain graph classes.
	 *
	 * @since 4.2.5
	 *
	 * @var array
	 */
	private $proGraphSubDirectories = [
		'Course',
		'Music',
		'Product'
	];

	/**
	 * Instance of the FAQPage class.
	 *
	 * @since 4.2.6
	 *
	 * @var Graphs\FAQPage
	 */
	private $faqPageInstance = null;

	/**
	 * The user-defined FAQPage graph (if there is one).
	 *
	 * @since 4.2.6
	 *
	 * @var Object
	 */
	private $faqPageGraphData = null;

	/**
	 * Buffer to store FAQPage pairs before we output them under one main entity.
	 *
	 * @since 4.2.6
	 *
	 * @var array
	 */
	private $faqPages = [];

	/**
	 * Generates the JSON schema after the graphs/context have been determined.
	 *
	 * @since 4.2.5
	 *
	 * @param  array  $graphs       The graphs from the schema validator.
	 * @param  array  $customGraphs The graphs from the schema validator.
	 * @param  object $defaultGraph The default graph data.
	 * @return string               The JSON schema output.
	 */
	protected function generateSchema( $graphs = [], $customGraphs = [], $defaultGraph = null, $blockGraphs = [] ) {
		// Now, filter the graphs.
		$this->graphs = apply_filters(
			'aioseo_schema_graphs',
			array_unique( array_filter( array_values( $this->graphs ) ) )
		);

		if ( empty( $this->graphs ) ) {
			return '';
		}

		// Check if a WebPage graph is included. Otherwise add the default one.
		$webPageGraphFound = false;
		foreach ( $this->graphs as $graphName ) {
			if ( in_array( $graphName, $this->webPageGraphs, true ) ) {
				$webPageGraphFound = true;
				break;
			}
		}

		$post       = aioseo()->helpers->getPost();
		$metaData   = aioseo()->meta->metaData->getMetaData( $post );
		$postGraphs = ! empty( $graphs ) ? $graphs : [];
		if ( ! aioseo()->schema->generatingValidatorOutput && ! empty( $metaData->schema->graphs ) ) {
			$postGraphs = $metaData->schema->graphs;
		}

		if ( ! aioseo()->schema->generatingValidatorOutput && ! empty( $metaData->schema->default ) ) {
			$defaultGraph = $metaData->schema->default;
		}

		foreach ( $postGraphs as $graphData ) {
			$graphData = (object) $graphData;

			if ( in_array( $graphData->graphName, $this->webPageGraphs, true ) ) {
				$webPageGraphFound = true;
				break;
			}
		}

		if (
			! empty( $defaultGraph->isEnabled ) &&
			! empty( $defaultGraph->graphName ) &&
			in_array( $defaultGraph->graphName, [ 'FAQPage', 'WebPage' ], true )
		) {
			$webPageGraphFound = true;
		}

		if ( ! $webPageGraphFound ) {
			$this->graphs[] = 'WebPage';
		}

		// Add the SiteNavigationElement schema if Table of contents block is present with headings.
		if ( $this->hasTableOfContentHeadings( $post ) ) {
			$this->graphs[] = 'SiteNavigationElement';
		}

		// Now that we've determined the graphs, start generating their data.
		$schema = [
			'@context' => 'https://schema.org',
			// Let's first grab all the user-defined graphs (Schema Generator + blocks) if this a post.
			// We want to do this before we get the regular smart graphs since we want to give the user-defined graphs a chance to "enqueue" any smart graphs they might require.
			'@graph'   => $this->getUserDefinedGraphs( $graphs, $customGraphs, $defaultGraph, $blockGraphs )
		];

		// By determining the length of the array after every iteration, we are able to add additional graphs during runtime.
		// e.g. The Article graph may require a Person graph to be output for the author.
		$this->graphs = array_values( $this->graphs );
		for ( $i = 0; $i < count( $this->graphs ); $i++ ) {
			$namespace = $this->getGraphNamespace( $this->graphs[ $i ] );
			if ( $namespace ) {
				$schema['@graph'][] = ( new $namespace() )->get();
				continue;
			}

			// If we still haven't found a graph, check the addons (e.g. Local Business).
			$graphData = $this->getAddonGraphData( $this->graphs[ $i ] );
			if ( ! empty( $graphData ) ) {
				$schema['@graph'][] = $graphData;
				continue;
			}
		}

		return aioseo()->schema->helpers->getOutput( $schema );
	}

	/**
	 * Gets the relevant namespace for the given graph.
	 *
	 * @since 4.2.5
	 *
	 * @param  string $graphName The graph name.
	 * @return string            The namespace.
	 */
	protected function getGraphNamespace( $graphName ) {
		// Check if a Pro graph exists.
		// We must do this before we check in the Common graphs in case we override one.
		$namespace = "\AIOSEO\Plugin\Pro\Schema\Graphs\\{$graphName}";
		if ( class_exists( $namespace ) ) {
			return $namespace;
		}

		// If we can't find it in the root dir, check if we can find it in a sub dir.
		foreach ( $this->proGraphSubDirectories as $dirName ) {
			$namespace = "\AIOSEO\Plugin\Pro\Schema\Graphs\\{$dirName}\\{$graphName}";
			if ( class_exists( $namespace ) ) {
				return $namespace;
			}
		}

		$namespace = "\AIOSEO\Plugin\Common\Schema\Graphs\\{$graphName}";
		if ( class_exists( $namespace ) ) {
			return $namespace;
		}

		// If we can't find it in the root dir, check if we can find it in a sub dir.
		foreach ( $this->graphSubDirectories as $dirName ) {
			$namespace = "\AIOSEO\Plugin\Common\Schema\Graphs\\{$dirName}\\{$graphName}";
			if ( class_exists( $namespace ) ) {
				return $namespace;
			}
		}

		return '';
	}

	/**
	 * Returns the output for the user-defined graphs (Schema Generator + blocks).
	 *
	 * @since 4.2.5
	 *
	 * @param  array $graphs       The graphs from the validator.
	 * @param  array $customGraphs The custom graphs from the validator.
	 * @param  array $default      The default graph data.
	 * @param  array $blockGraphs  Graphs generated by blocks.
	 * @return array               The graphs.
	 */
	private function getUserDefinedGraphs( $graphs = [], $customGraphs = [], $default = [], $blockGraphs = [] ) {
		// Get individual value.
		$post     = aioseo()->helpers->getPost();
		$metaData = aioseo()->meta->metaData->getMetaData( $post );
		if ( ! is_a( $post, 'WP_Post' ) || empty( $metaData->post_id ) ) {
			return [];
		}

		$graphs            = aioseo()->schema->generatingValidatorOutput || ! empty( $graphs ) ? $graphs : $metaData->schema->graphs;
		$userDefinedGraphs = [];
		foreach ( $graphs as $graphData ) {
			$graphData = json_decode( wp_json_encode( $graphData ) );

			if (
				empty( $graphData->id ) ||
				empty( $graphData->graphName ) ||
				empty( $graphData->properties )
			) {
				continue;
			}

			// If the graph has a subtype, this is the place where we need to replace the main graph name with the one of the subtype.
			if ( ! empty( $graphData->properties->type ) ) {
				$graphData->graphName = $graphData->properties->type;
			}

			switch ( $graphData->graphName ) {
				case 'FAQPage':
					if ( null === $this->faqPageInstance ) {
						$this->faqPageInstance = new Graphs\FAQPage();
					}

					// FAQ pages need to be collected first and added later because they should be nested under a parent graph.
					// We'll also store the data since we need it for the name/description properties.
					$this->faqPageGraphData = $graphData;
					$this->faqPages         = array_merge( $this->faqPages, $this->faqPageInstance->get( $graphData ) );
					break;
				default:
					$namespace = $this->getGraphNamespace( $graphData->graphName );
					if ( $namespace ) {
						$userDefinedGraphs[] = ( new $namespace() )->get( $graphData );
					}
					break;
			}
		}

		$customGraphs = aioseo()->schema->generatingValidatorOutput || ! empty( $customGraphs ) ? $customGraphs : $metaData->schema->customGraphs;
		foreach ( $customGraphs as $customGraphData ) {
			$customGraphData = (object) $customGraphData;

			if ( empty( $customGraphData->schema ) ) {
				continue;
			}

			$customSchema = json_decode( $customGraphData->schema, true );
			if ( ! empty( $customSchema ) ) {
				if ( isset( $customSchema['@graph'] ) && is_array( $customSchema['@graph'] ) ) {
					foreach ( $customSchema['@graph'] as $graph ) {
						$userDefinedGraphs[] = $graph;
					}
				} else {
					$userDefinedGraphs[] = $customSchema;
				}
			}
		}

		$default = aioseo()->schema->generatingValidatorOutput || ! empty( $default ) ? $default : $metaData->schema->default;
		if ( ! empty( $default->isEnabled ) && ! empty( $default->graphName ) ) {
			$parentGraphName = $this->getParentGraph( $default->graphName );
			$graphData       = ! empty( $default->data->{$parentGraphName} ) ? $default->data->{$parentGraphName} : [];
			$namespace       = $this->getGraphNamespace( $default->graphName );

			switch ( $default->graphName ) {
				case 'FAQPage':
					if ( null === $this->faqPageInstance ) {
						$this->faqPageInstance = new Graphs\FAQPage();
					}

					// FAQ pages need to be collected first and added later because they should be nested under a parent graph.
					// We'll also store the data since we need it for the name/description properties.
					$graphData              = $default->data->FAQPage;
					$this->faqPageGraphData = $graphData;
					$this->faqPages         = array_merge( $this->faqPages, $this->faqPageInstance->get( $graphData ) );
					break;
				default:
					$namespace = $this->getGraphNamespace( $default->graphName );
					if ( $namespace ) {
						$userDefinedGraphs[] = ( new $namespace() )->get( $graphData );
					}
					break;
			}
		}

		$userDefinedGraphs = array_merge(
			$userDefinedGraphs,
			$this->parseBlockGraphs( $this->generatingValidatorOutput ? $blockGraphs : ( $metaData->schema->blockGraphs ?? [] ) )
		);

		$this->faqPages = array_filter( $this->faqPages );
		if ( ! empty( $this->faqPages ) && $this->faqPageInstance ) {
			$userDefinedGraphs[] = $this->faqPageInstance->getMainGraph( $this->faqPages, $this->faqPageGraphData );
		}

		return $userDefinedGraphs;
	}

	/**
	 * Parses the schema supported blocks that are embedded into the post.
	 *
	 * @since   4.2.3
	 * @version 4.5.6 Renamed from getBlockGraphs to parseBlockGraphs.
	 *
	 * @param  array $blockGraphs Graphs generated by blocks.
	 * @return array              The schema graph data.
	 */
	private function parseBlockGraphs( $blockGraphs ) {
		$post = aioseo()->helpers->getPost();
		if (
			! is_a( $post, 'WP_Post' ) ||
			! is_array( $blockGraphs )
		) {
			return [];
		}

		$graphs = [];
		foreach ( $blockGraphs as $blockGraphData ) {
			// If the type isn't set for whatever reason, then bail.
			if ( empty( $blockGraphData->type ) ) {
				continue;
			}

			$type = strtolower( $blockGraphData->type );
			switch ( $type ) {
				case 'aioseo/faq':
					if ( null === $this->faqPageInstance ) {
						$this->faqPageInstance = new Graphs\FAQPage();
					}

					// FAQ pages need to be collected first and added later because they should be nested under a parent graph.
					$this->faqPages[] = $this->faqPageInstance->get( $blockGraphData, true );
					break;
				case 'aioseo/product':
					$graphs[] = ( new Graphs\Product\Product() )->get( $blockGraphData );
					break;
				case 'aioseo/recipe':
					$graphs[] = ( new Graphs\Recipe() )->get( $blockGraphData );
					break;
				default:
					break;
			}
		}

		return $graphs;
	}

	/**
	 * Modifies the global $wp_query and $post objects, so schema markup can be generated per post ID.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_Post $postObject The WP post object.
	 * @return array                The necessary data, so the query can be correctly reset.
	 */
	private function maybeModifyWpQuery( $postObject ) {
		global $wp_query, $post; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		$originalQuery = is_object( $wp_query ) ? clone $wp_query : $wp_query; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		$originalPost  = is_object( $post ) ? clone $post : $post;
		$isNewPost     = ! empty( $originalPost ) && ! $originalPost->post_title && ! $originalPost->post_name && 'auto-draft' === $originalPost->post_status;

		// Only modify the query if there is no post on it set yet.
		// Otherwise, page builders like Divi and Elementor can't seem to load their visual builder.
		// phpcs:disable Squiz.NamingConventions.ValidVariableName
		if ( empty( $originalQuery->post ) ) {
			$post                        = $postObject;
			$wp_query->post              = $postObject;
			$wp_query->posts             = [ $postObject ];
			$wp_query->post_count        = 1;
			$wp_query->queried_object    = $postObject;
			$wp_query->queried_object_id = $postObject->ID;
			$wp_query->is_single         = true;
			$wp_query->is_singular       = true;
		}
		// phpcs:enable Squiz.NamingConventions.ValidVariableName

		return [
			'originalQuery' => $originalQuery,
			'originalPost'  => $originalPost,
			'isNewPost'     => $isNewPost,
		];
	}

	/**
	 * Modifies the global $wp_query and $post objects to their original states.
	 *
	 * @since 4.4.0
	 *
	 * @param object $originalQuery The $wp_query object before it was modified.
	 * @param object $originalPost  The $post object before it was modified.
	 * @param bool   $isNewPost     Whether the post is new or not.
	 * @return void
	 */
	private function maybeRestoreWpQuery( $originalQuery, $originalPost, $isNewPost ) {
		global $wp_query, $post; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		// Reset the global objects.
		if ( empty( $originalQuery->post ) ) {
			$wp_query = $originalQuery; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			$post     = $originalPost;
		}

		// We must reset the title for new posts because they will be given an "Auto Draft" one due to the schema class determining the schema output for the validator.
		if ( $isNewPost ) {
			$post->post_title = '';
		}
	}

	/**
	 * Determines the smart graphs that need to be build, as well as the current context for the breadcrumbs.
	 *
	 * This can't run in the constructor since the queried object needs to be available first.
	 *
	 * @since 4.2.5
	 *
	 * @return void
	 */
	protected function determineSmartGraphsAndContext() {
		parent::determineSmartGraphsAndContext();

		// Check if our addons need to register graphs.
		$addonsGraphs = array_filter( aioseo()->addons->doAddonFunction( 'schema', 'determineGraphsAndContext' ) );
		foreach ( $addonsGraphs as $addonGraphs ) {
			$this->graphs = array_values( array_merge( $this->graphs, $addonGraphs ) );
		}
	}

	/**
	 * Determines the smart graphs and context for singular pages.
	 *
	 * @since 4.2.6
	 *
	 * @param  \AIOSEO\Plugin\Common\Schema\Context $contextInstance The Context class instance.
	 * @return void
	 */
	protected function determineContextSingular( $contextInstance ) {
		$this->context = $contextInstance->post();
	}

	/**
	 * Returns the default graph for the current post.
	 *
	 * @since 4.2.6
	 *
	 * @return string The default graph.
	 */
	public function getDefaultPostGraph() {
		$metaData = aioseo()->meta->metaData->getMetaData();
		if ( isset( $metaData->schema->default->graphName ) ) {
			return $metaData->schema->default->graphName;
		}

		return $this->getDefaultPostTypeGraph();
	}

	/**
	 * Gets the graph data from our addons.
	 *
	 * @since 4.0.17
	 *
	 * @param  string $graphName The graph name.
	 * @return array             The graph data.
	 */
	public function getAddonGraphData( $graphName ) {
		$addonsGraphData = aioseo()->addons->doAddonFunction( 'schema', 'get', [ $graphName ] );

		foreach ( $addonsGraphData as $addonGraphData ) {
			if ( ! empty( $addonGraphData ) ) {
				return $addonGraphData;
			}
		}

		return [];
	}

	/**
	 * Returns the simulated schema output for the Schema Validator in the post editor.
	 *
	 * @since 4.2.5
	 *
	 * @param  int    $postId       The post ID.
	 * @param  array  $graphs       The graphs from the schema validator.
	 * @param  array  $customGraphs The custom graphs from the schema validator.
	 * @param  array  $defaultGraph The default graph data.
	 * @return string               The JSON schema output.
	 */
	public function getValidatorOutput( $postId, $graphs = [], $customGraphs = [], $defaultGraph = null, $blockGraphs = [] ) {
		if ( ! current_user_can( 'read_post', $postId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Unauthorized.'
			], 401 );
		}

		$postObject = aioseo()->helpers->getPost( $postId );
		if ( ! is_a( $postObject, 'WP_Post' ) ) {
			return '';
		}

		$this->generatingValidatorOutput = true;
		$modifiedQuery                   = $this->maybeModifyWpQuery( $postObject );

		$this->determineSmartGraphsAndContext();
		$output = $this->generateSchema( $graphs, $customGraphs, $defaultGraph, $blockGraphs );

		$this->maybeRestoreWpQuery( $modifiedQuery['originalQuery'], $modifiedQuery['originalPost'], $modifiedQuery['isNewPost'] );
		$this->generatingValidatorOutput = false;

		return $output;
	}

	/**
	 * Returns a simulated schema output based on data manually set.
	 *
	 * @since 4.4.0
	 *
	 * @param  int    $postId     The post ID.
	 * @param  array  $postSchema The whole schema field.
	 * @return string             The JSON schema output.
	 */
	public function getUserDefinedSchemaOutput( $postId, $postSchema ) {
		$postObject = aioseo()->helpers->getPost( $postId );
		if ( ! is_a( $postObject, 'WP_Post' ) ) {
			return '';
		}

		$modWpQuery = $this->maybeModifyWpQuery( $postObject );

		$this->determineSmartGraphsAndContext();

		$rawOutput = [
			'@context' => 'https://schema.org',
			'@graph'   => aioseo()->schema->getUserDefinedGraphs(
				! empty( $postSchema['graphs'] ) ? $postSchema['graphs'] : [ [ 'id' => '' ] ],
				! empty( $postSchema['customGraphs'] ) ? $postSchema['customGraphs'] : [ [ 'schema' => '' ] ],
				! empty( $postSchema['default'] ) ? (object) $postSchema['default'] : (object) [ 'graphName' => '' ]
			)
		];

		$this->maybeRestoreWpQuery( $modWpQuery['originalQuery'], $modWpQuery['originalPost'], $modWpQuery['isNewPost'] );

		if ( empty( $rawOutput['@graph'] ) ) {
			return '';
		}

		return aioseo()->schema->helpers->getOutput( $rawOutput, false );
	}

	/**
	 * Resets the schema class after determining the output.
	 * We have to flush buffer properties in order to ensure no data leaks over when we are
	 * determining the schema output multiple times (e.g. SEO revisions).
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	public function reset() {
		$this->faqPages = [];
	}

	/**
	 * Returns the parent graph for a potentional child graph.
	 *
	 * @since 4.4.0
	 *
	 * @param  string $potentionalChildGraph The potentional child graph name.
	 * @return string                        The parent graph or the potentional child graph name if no parent graph was found.
	 */
	private function getParentGraph( $potentionalChildGraph ) {
		$mappings = [
			'Article' => [
				'BlogPosting',
				'NewsArticle'
			],
			'WebPage' => [
				'AboutPage',
				'CollectionPage',
				'ContactPage',
				'ItemPage',
				'ProfilePage',
				'RealEstateListing',
				'SearchResultsPage'
			]
		];

		foreach ( $mappings as $parentGraph => $childGraphs ) {
			if ( in_array( $potentionalChildGraph, $childGraphs, true ) ) {
				return $parentGraph;
			}
		}

		return $potentionalChildGraph;
	}

	/**
	 * Determines if the post has a Table of Contents block with headings.
	 * This is used to determine if the SiteNavigationElement schema should be added.
	 *
	 * @since 4.6.8
	 *
	 * @param  object $post The post object.
	 * @return bool         Whether the post has a Table of Contents block with headings.
	 */
	private function hasTableOfContentHeadings( $post ) {
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return false;
		}

		$parsedBlocks = aioseo()->helpers->parseBlocks( $post );
		if ( empty( $parsedBlocks ) || ! is_array( $parsedBlocks ) ) {
			return false;
		}

		$tocBlocksWithHeadings = array_filter( $parsedBlocks, function ( $block ) {
			if ( empty( $block['blockName'] ) || empty( $block['attrs'] ) ) {
				return false;
			}

			return 'aioseo/table-of-contents' === $block['blockName'] && 0 < count( $block['attrs']['headings'] ?? [] );
		} );

		return ! empty( $tocBlocksWithHeadings );
	}
}