<?php
namespace AIOSEO\Plugin\Pro\Llms;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Llms\Llms as CommonLlms;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Handles the LLMS-full.txt generation by creating a static file that contains more data than the LLMS.txt file.
 *
 * @since 4.8.8
 */
class Llms extends CommonLlms {
	/**
	 * The content renderer instance.
	 *
	 * @since 4.8.8
	 *
	 * @var \AIOSEO\Plugin\Pro\Llms\ContentRenderer
	 */
	private $contentRenderer;

	/**
	 * The action scheduler action for the LLMS-full.txt recurrent generation.
	 *
	 * @since 4.8.8
	 *
	 * @var string
	 */
	public $llmsFullTxtRecurrentAction = 'aioseo_generate_llms_full_txt';

	/**
	 * The action scheduler action for the LLMS-full.txt single generation.
	 *
	 * @since 4.8.8
	 *
	 * @var string
	 */
	public $llmsFullTxtSingleAction = 'aioseo_generate_llms_full_txt_single';

	/**
	 * Class constructor.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();

		// Initialize the content renderer.
		$this->contentRenderer = new ContentRenderer();

		// Use 'init' hook with high priority to run before redirects plugin
		add_action( 'init', [ $this, 'checkIfPageIsMd' ], 1 );

		add_action( 'init', [ $this, 'scheduleRecurrentGenerationForLlmsFullTxt' ] );
		add_action( $this->llmsFullTxtRecurrentAction, [ $this, 'generateLlmsFullTxt' ] );

		add_action( 'wp_insert_post', [ $this, 'scheduleSingleGenerationForLlmsFullTxt' ] );
		add_action( 'edited_term', [ $this, 'scheduleSingleGenerationForLlmsFullTxt' ] );
		add_action( $this->llmsFullTxtSingleAction, [ $this, 'generateLlmsFullTxt' ] );
	}

	/**
	 * Schedules the LLMS-full.txt file generation.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function scheduleRecurrentGenerationForLlmsFullTxt() {
		if (
			! aioseo()->options->sitemap->llms->enableFull ||
			aioseo()->actionScheduler->isScheduled( $this->llmsFullTxtRecurrentAction )
		) {
			return;
		}

		aioseo()->actionScheduler->scheduleRecurrent( $this->llmsFullTxtRecurrentAction, 10, DAY_IN_SECONDS );
	}

	/**
	 * Schedules a single LLMS-full.txt file generation.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function scheduleSingleGenerationForLlmsFullTxt() {
		if (
			! aioseo()->options->sitemap->llms->enableFull ||
			aioseo()->actionScheduler->isScheduled( $this->llmsFullTxtSingleAction )
		) {
			return;
		}

		aioseo()->actionScheduler->scheduleSingle( $this->llmsFullTxtSingleAction, 10 );
	}

	/**
	 * Generates the LLMS-full.txt file.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function generateLlmsFullTxt() {
		if ( isset( aioseo()->options->sitemap->llms->enableFull ) && ! aioseo()->options->sitemap->llms->enableFull ) {
			aioseo()->actionScheduler->unschedule( $this->llmsFullTxtSingleAction );
			aioseo()->actionScheduler->unschedule( $this->llmsFullTxtRecurrentAction );
			$this->deleteLlmsFullTxt();

			return;
		}

		$fs   = aioseo()->core->fs;
		$file = ABSPATH . sanitize_file_name( 'llms-full.txt' );

		$this->setSiteInfo();
		$content = $this->generateContent();

		// Add UTF-8 BOM to help browsers recognize the encoding
		$content = "\xEF\xBB\xBF" . $content;
		$fs->putContents( $file, $content );
	}

	/**
	 * Deletes the LLMS-full.txt file.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function deleteLlmsFullTxt() {
		$fs   = aioseo()->core->fs;
		$file = ABSPATH . sanitize_file_name( 'llms-full.txt' );
		if ( $fs->isWpfsValid() && $fs->fs->exists( $file ) ) {
			$fs->fs->delete( $file, false, 'f' );
		}
	}

	/**
	 * Generates the LLMS-full.txt file content in a markdown format.
	 *
	 * @since 4.8.8
	 *
	 * @return string
	 */
	private function generateContent() {
		$this->setSiteInfo();

		$content  = $this->getHeader( true );
		$content .= $this->getSiteDescription();
		$content .= $this->getContent( true );

		return $content;
	}

	/**
	 * Gets the post content section of the llms.txt file.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post     The post object.
	 * @param  bool     $llmsFull Whether to include the llms-full.txt file.
	 * @return string             The content of the llms.txt file.
	 */
	protected function getPostContent( $post, $llmsFull = false ) {
		if ( ! $llmsFull ) {
			return parent::getPostContent( $post );
		}

		return $this->getFullPostContent( $post );
	}

	/**
	 * Gets the full content for a single post in markdown format.
	 *
	 * @since 4.8.8
	 *
	 * @param \WP_Post $post The post object.
	 * @return string
	 */
	protected function getFullPostContent( $post ) {
		$content = '';

		// Post title and link
		$content .= '### [' . aioseo()->helpers->decodeHtmlEntities( $post->post_title ) . '](' . aioseo()->helpers->decodeUrl( get_permalink( $post->ID ) ) . ")\n\n";

		// Post meta information
		$content .= '**Published:** ' . get_the_date( 'F j, Y', $post->ID ) . "\n";
		$content .= '**Author:** ' . get_the_author_meta( 'display_name', get_post_field( 'post_author', $post->ID ) ) . "\n\n";

		// Post excerpt if available
		if ( ! empty( $post->post_excerpt ) ) {
			$content .= '**Excerpt:** ' . aioseo()->helpers->decodeHtmlEntities( $post->post_excerpt ) . "\n\n";
		}

		// Full post content
		$post        = get_post( $post->ID );
		$postContent = $this->contentRenderer->getRenderedContent( $post );
		if ( ! empty( $postContent ) ) {
			$content .= '**Content:**' . "\n\n";
			$content .= $this->convertToMarkdown( $postContent ) . "\n\n";
		}

		// Get all taxonomies associated with this post
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			// Skip built-in taxonomies that are not public or don't have a UI
			if ( ! $taxonomy->public || ! $taxonomy->show_ui ) {
				continue;
			}

			$terms = get_the_terms( $post->ID, $taxonomy->name );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$content .= '**' . $taxonomy->labels->name . ':** ';
				$termNames = array_map( function( $term ) {
					return $term->name;
				}, $terms );
				$content .= implode( ', ', $termNames ) . "\n\n";
			}
		}

		$content .= "---\n\n";

		return $content;
	}

	/**
	 * Converts HTML content to markdown format using the league/html-to-markdown package.
	 *
	 * @since 4.8.8
	 *
	 * @param string $html The HTML content to convert.
	 * @return string
	 */
	private function convertToMarkdown( $html ) {
		try {
			$converter = new HtmlConverter();

			// Configure conversion options for better WordPress content handling
			$converter->getConfig()->setOption( 'strip_tags', true );
			$converter->getConfig()->setOption( 'hard_break', true );
			$converter->getConfig()->setOption( 'header_style', 'atx' );
			$converter->getConfig()->setOption( 'remove_nodes', 'script style link[rel="stylesheet"] meta noscript' );

			// Convert HTML to Markdown
			$markdown = $converter->convert( $html );
			// Additional cleanup to ensure all HTML tags are stripped
			$markdown = wp_strip_all_tags( $markdown );
			$markdown = preg_replace( '/\n\s*\n\s*\n+/', "\n\n", (string) trim( $markdown ) );
			$markdown = aioseo()->helpers->decodeHtmlEntities( $markdown );

			return $markdown;
		} catch ( \Exception $e ) {
			// Fallback: strip HTML tags and clean up using AIOSEO's existing method
			$content = wp_strip_all_tags( $html );
			$content = preg_replace( '/\n\s*\n\s*\n+/', "\n\n", (string) trim( $content ) );
			$content = aioseo()->helpers->decodeHtmlEntities( $content );

			return $content;
		}
	}

	/**
	 * Handles .md requests and outputs markdown content.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function checkIfPageIsMd() {
		if ( ! aioseo()->options->sitemap->llms->convertToMd ) {
			return;
		}

		// If post not found, let redirects plugin handle it normally
		$post = $this->getPostByPathForMd();
		if ( ! $post ) {
			return;
		}

		// Add filter to prevent redirects plugin from interfering with this specific request
		add_filter( 'aioseo_redirects_avoid_advanced_404_redirect', [ $this, 'avoidRedirectForThisRequest' ], 10, 2 );

		// Handle the request on template redirect to give plugins time to register shortcodes/blocks.
		add_action( 'template_redirect', [ $this, 'handleMdRequest' ], 1 );
	}

	/**
	 * Prevents redirects plugin from interfering with the current request.
	 *
	 * @since 4.8.8
	 *
	 * @param  bool   $avoid      Whether to avoid redirect.
	 * @param  string $requestUrl The request URL.
	 * @return bool
	 */
	public function avoidRedirectForThisRequest( $avoid, $requestUrl ) {
		// Only avoid redirect for the current request URL
		$currentUrl = aioseo()->helpers->getRequestUrl();
		if ( $requestUrl === $currentUrl ) {
			return true;
		}

		return $avoid;
	}

	/**
	 * Handles the .md request.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	public function handleMdRequest() {
		$post = $this->getPostByPathForMd();
		if ( ! $post ) {
			return;
		}

		// Check if user can read this post
		if ( 'private' === $post->post_status ) {
			wp_die( 'Access denied', '403 Forbidden', [ 'response' => 403 ] );
		}

		// Generate markdown content using existing method
		$markdown = $this->getFullPostContent( $post );

		// Set headers for markdown response
		$charset = aioseo()->helpers->getCharset();

		status_header( 200 );
		header( "Content-Type: text/markdown; charset={$charset}" );
		header( 'X-Robots-Tag: noindex, follow' );

		// Output the markdown
		echo $markdown; #phpcs:ignore
		exit;
	}

	/**
	 * Gets the post by path for .md requests.
	 *
	 * @since 4.8.8
	 *
	 * @return \WP_Post|void The post object or false if not found.
	 */
	private function getPostByPathForMd() {
		// Get the current URL using AIOSEO helper
		$currentUrl = aioseo()->helpers->getRequestUrl();

		// Check if the URL ends with .md using AIOSEO helper
		if ( ! aioseo()->helpers->stringContains( $currentUrl, '.md' ) ) {
			return;
		}

		// Remove .md from the URL to get the original URL
		$originalUrl = str_replace( '.md', '', $currentUrl );

		// Parse the URL to get the post/page/term
		$parsedUrl = wp_parse_url( $originalUrl );
		$path      = $parsedUrl['path'] ?? '';

		// Remove leading slash using AIOSEO helper
		$path = aioseo()->helpers->unleadingSlashIt( $path );

		// Try to find a post using our specialized function for .md requests
		$post = $this->getPostByPathForMdHelper( $path );

		return $post;
	}

	/**
	 * Gets the post by path with proper non-Latin character handling for .md requests.
	 * Helper function for getPostByPathForMd().
	 *
	 * @since 4.8.8
	 *
	 * @param  string          $path The URL path to search for.
	 * @return \WP_Post|false        The post object or false if not found.
	 */
	private function getPostByPathForMdHelper( $path ) {
		// First try to find a post using the AIOSEO getPostByPath helper
		$post = aioseo()->helpers->getPostByPath( $path );
		if ( $post ) {
			return $post;
		}

		// If that fails and we have non-Latin characters, try a different approach
		$path  = trim( $path, '/' );
		$parts = explode( '/', $path );

		// Convert each part to the format WordPress stores in the database
		$sanitizedParts = array_map( function( $part ) {
			return sanitize_title( $part );
		}, $parts );

		// Try to find posts with the sanitized slug using AIOSEO database functions
		$posts = aioseo()->core->db->start( 'posts' )
			->select( 'ID' )
			->where( 'post_name', end( $sanitizedParts ) )
			->where( 'post_status', 'publish' )
			->limit( 1 )
			->run()
			->result();

		if ( ! empty( $posts ) ) {
			return get_post( $posts[0]->ID );
		}

		// Get all post types using AIOSEO database functions
		$excludedPostTypes = [
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation'
		];

		$postTypes = aioseo()->core->db->start( 'posts' )
			->select( 'DISTINCT post_type' )
			->where( 'post_status', 'publish' )
			->whereNotIn( 'post_type', $excludedPostTypes )
			->orderBy( 'post_type' )
			->run()
			->result();

		$postTypeNames = array_map( function( $row ) {
			return $row->post_type;
		}, $postTypes );

		// Try each post type individually with a more targeted search
		foreach ( $postTypeNames as $postType ) {
			$post = $this->findPostInPostType( $postType, $path );
			if ( $post ) {
				return $post;
			}
		}

		return false;
	}

	/**
	 * Finds a post within a specific post type that matches the given path.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $postType The post type to search in.
	 * @param  string $path     The path to match.
	 * @return \WP_Post|false   The post object or false if not found.
	 */
	private function findPostInPostType( $postType, $path ) {
		// Get posts of this type and check their permalinks
		$posts = aioseo()->core->db->start( 'posts' )
			->select( 'ID' )
			->where( 'post_type', $postType )
			->where( 'post_status', 'publish' )
			->limit( 50 )
			->run()
			->result();

		foreach ( $posts as $postRow ) {
			$post = get_post( $postRow->ID );
			if ( ! $post ) {
				continue;
			}

			// Force WordPress to generate the proper permalink
			$permalink = get_permalink( $post->ID );

			// If we get a query string URL, try to get the proper permalink
			if ( strpos( $permalink, '?p=' ) !== false ) {
				$post = $this->findPostByConstructedPath( $post, $postType, $path );
				if ( $post ) {
					return $post;
				}
				continue;
			}

			// Normal permalink processing
			$permalinkPath = trim( str_replace( home_url(), '', $permalink ), '/' );
			$permalinkPath = aioseo()->helpers->decodeUrl( $permalinkPath );

			if ( $permalinkPath === $path ) {
				return $post;
			}
		}

		return false;
	}

	/**
	 * Finds a post by constructing its expected path when permalinks return query strings.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post     The post object.
	 * @param  string   $postType The post type.
	 * @param  string   $path     The path to match.
	 * @return \WP_Post|false     The post object or false if not found.
	 */
	private function findPostByConstructedPath( $post, $postType, $path ) {
		// Force rewrite rules to be loaded
		global $wp_rewrite; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
		if ( ! $wp_rewrite->using_permalinks() ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			// If permalinks are not enabled, we can't match by path
			return false;
		}

		// Try to get the post name and construct the path manually
		$postName = $post->post_name;
		if ( empty( $postName ) ) {
			return false;
		}

		// Get the post type object to understand its rewrite structure
		$postTypeObj = get_post_type_object( $postType );
		$expectedPath = '';

		if ( $postTypeObj ) {
			$expectedPath = $this->getExpectedPathFromPostTypeObject( $postTypeObj, $postType, $postName, $post->ID );
		} else {
			$expectedPath = $this->getExpectedPathFromRewriteRules( $postType, $postName );
		}

		return ( $expectedPath === $path ) ? $post : false;
	}

	/**
	 * Gets the expected path from a post type object.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post_Type $postTypeObj The post type object.
	 * @param  string        $postType    The post type name.
	 * @param  string        $postName    The post name/slug.
	 * @param  int           $postId      The post ID.
	 * @return string                     The expected path.
	 */
	private function getExpectedPathFromPostTypeObject( $postTypeObj, $postType, $postName, $postId ) {
		// Check if this post type has a custom rewrite slug
		$rewriteSlug = ! empty( $postTypeObj->rewrite['slug'] )
			? $postTypeObj->rewrite['slug']
			: $postType;

		// Check if this post type has hierarchical rewrite (like pages)
		if ( ! empty( $postTypeObj->hierarchical ) ) {
			// For hierarchical post types, we need to check the full path
			return $this->getHierarchicalPath( $postId, $rewriteSlug );
		}

		// For non-hierarchical post types, use simple slug structure
		return $rewriteSlug . '/' . $postName;
	}

	/**
	 * Gets the expected path from WordPress rewrite rules when post type object is null.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $postType The post type name.
	 * @param  string $postName The post name/slug.
	 * @return string           The expected path.
	 */
	private function getExpectedPathFromRewriteRules( $postType, $postName ) {
		// Try to get the rewrite slug from WordPress's rewrite rules
		$rewriteSlug = $this->getRewriteSlugFromRules( $postType );

		if ( $rewriteSlug ) {
			return $rewriteSlug . '/' . $postName;
		}

		// Try common patterns for post type names
		return $this->guessRewriteSlug( $postType ) . '/' . $postName;
	}

	/**
	 * Gets the hierarchical path for a post (useful for pages and hierarchical custom post types).
	 *
	 * @since 4.8.8
	 *
	 * @param int    $postId      The post ID.
	 * @param string $rewriteSlug The rewrite slug for the post type.
	 * @return string             The hierarchical path.
	 */
	private function getHierarchicalPath( $postId, $rewriteSlug ) {
		$post = get_post( $postId );
		if ( ! $post ) {
			return '';
		}

		$path = $post->post_name;
		$parentId = $post->post_parent;

		// Build the path by going up the hierarchy
		while ( $parentId > 0 ) {
			$parent = get_post( $parentId );
			if ( ! $parent ) {
				break;
			}
			$path = $parent->post_name . '/' . $path;
			$parentId = $parent->post_parent;
		}

		return $rewriteSlug . '/' . $path;
	}

	/**
	 * Gets the rewrite slug from WordPress rewrite rules when post type object is null.
	 *
	 * @since 4.8.8
	 *
	 * @param string $postType The post type name.
	 * @return string|false    The rewrite slug or false if not found.
	 */
	private function getRewriteSlugFromRules( $postType ) {
		global $wp_rewrite; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		// Get the permalink structure for this post type
		$permastruct = $wp_rewrite->get_extra_permastruct( $postType ); // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		if ( $permastruct ) {
			// Extract the slug from the permalink structure
			// e.g., "/%postname%/" -> ""
			// e.g., "/tool/%postname%/" -> "tool"
			$slug = trim( str_replace( [ '%postname%', '%post_id%', '%' ], '', $permastruct ), '/' );

			return $slug;
		}

		return false;
	}

	/**
	 * Guesses the rewrite slug for a post type when other methods fail.
	 *
	 * @since 4.8.8
	 *
	 * @param string $postType The post type name.
	 * @return string          The guessed rewrite slug.
	 */
	private function guessRewriteSlug( $postType ) {
		// Common patterns for post type names
		$patterns = [
			// If post type ends with 's', try removing it (tools -> tool)
			'/s$/' => '',
			// If post type has underscores, try replacing with hyphens
			'/_/'  => '-',
		];

		$slug = $postType;

		foreach ( $patterns as $pattern => $replacement ) {
			$slug = preg_replace( $pattern, $replacement, $slug );
		}

		// Special cases
		$specialCases = [
			'tools'    => 'tool',
			'products' => 'product',
			'pages'    => 'page',
			'posts'    => 'post',
		];

		if ( isset( $specialCases[ $postType ] ) ) {
			return $specialCases[ $postType ];
		}

		return $slug;
	}
}