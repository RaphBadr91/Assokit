<?php
namespace AIOSEO\Plugin\Pro\Llms;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles content rendering for LLMS full text generation.
 *
 * @since 4.8.8
 */
class ContentRenderer {
	/**
	 * Gets the page builder name for a post using AIOSEO's existing helper.
	 *
	 * @since 4.8.8
	 *
	 * @param  int    $postId The post ID.
	 * @return string         The page builder name or empty string.
	 */
	private function getPageBuilderName( $postId ) {
		return aioseo()->helpers->getPostPageBuilderName( $postId );
	}

	/**
	 * Checks if a post is built with a specific page builder using AIOSEO's existing integrations.
	 *
	 * @since 4.8.8
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $builderName The page builder name.
	 * @return bool                Whether the post is built with the specified builder.
	 */
	private function isBuiltWith( $postId, $builderName ) {
		if ( ! isset( aioseo()->standalone->pageBuilderIntegrations[ $builderName ] ) ) {
			return false;
		}

		return aioseo()->standalone->pageBuilderIntegrations[ $builderName ]->isBuiltWith( $postId );
	}
	/**
	 * Gets the properly rendered content for a post, ensuring it works in both Action Scheduler and normal contexts.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post  The post object.
	 * @param  bool     $short Whether to return a short version (like excerpt/description) instead of full content.
	 * @return string
	 */
	public function getRenderedContent( $post, $short = false ) {
		// Ensure we have a full WP_Post object
		if ( ! is_a( $post, 'WP_Post' ) || ! isset( $post->post_content ) ) {
			$post = get_post( $post->ID );
			if ( ! is_a( $post, 'WP_Post' ) ) {
				return '';
			}
		}

		// If short mode is requested, get the full content first and then shorten it
		if ( $short ) {
			// Get the full rendered content first
			$fullContent = $this->getRenderedContent( $post, false );
			// Then shorten it
			return $this->shortenContent( $fullContent );
		}

		// Check if we can use AIOSEO's existing page builder processing
		$pageBuilder = $this->getPageBuilderName( $post->ID );

		if ( ! empty( $pageBuilder ) && isset( aioseo()->standalone->pageBuilderIntegrations[ $pageBuilder ] ) ) {
			$processedContent = $this->processPageBuilderContent( $post, $pageBuilder );
			// If the page builder processed the content successfully, return it
			if ( ! $this->containsUnrenderedShortcodes( $processedContent ) ) {
				return $processedContent;
			}
		}

		// Special handling for Thrive Architect
		if ( 'thrive-architect' === $pageBuilder ) {
			$thriveContent = $this->processThriveArchitectContent( $post );
			if ( ! empty( $thriveContent ) && ! $this->containsUnrenderedShortcodes( $thriveContent ) ) {
				return $thriveContent;
			}
		}

		// Special handling for Elementor
		if ( 'elementor' === $pageBuilder ) {
			$elementorContent = $this->processElementorContent( $post );
			if ( ! empty( $elementorContent ) && ! $this->containsUnrenderedShortcodes( $elementorContent ) ) {
				return $elementorContent;
			}
		}

		// If we're in a proper WordPress context and no page builder was detected, use standard approach
		if ( ! wp_doing_cron() && ! wp_doing_ajax() && ! is_admin() ) {
			return apply_filters( 'the_content', $post->post_content );
		}

		// For Action Scheduler and other non-frontend contexts, we need to simulate a frontend request
		return $this->simulateFrontendRequest( $post );
	}

	/**
	 * Shortens the full rendered content to create a description.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $content The full rendered content.
	 * @return string
	 */
	private function shortenContent( $content ) {
		// If content is empty, return empty
		if ( empty( $content ) ) {
			return '';
		}

		// Strip HTML tags and get plain text
		$plainContent = wp_strip_all_tags( $content );

		// Clean up whitespace
		$plainContent = preg_replace( '/\s+/', ' ', $plainContent );
		$plainContent = trim( $plainContent );

		// If content is empty after cleaning, return empty
		if ( empty( $plainContent ) ) {
			return '';
		}

		// If content is short enough, return as-is
		if ( strlen( $plainContent ) <= 160 ) {
			return $plainContent;
		}

		// Truncate at word boundary and add ellipsis
		$shortened = substr( $plainContent, 0, 160 );
		$lastSpace = strrpos( $shortened, ' ' );

		if ( false !== $lastSpace && 100 < $lastSpace ) {
			$shortened = substr( $shortened, 0, $lastSpace );
		}

		return $shortened . '...';
	}



	/**
	 * Processes content using the appropriate page builder integration.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post        The post object.
	 * @param  string   $pageBuilder The page builder name.
	 * @return string
	 */
	private function processPageBuilderContent( $post, $pageBuilder ) {
		$integration = aioseo()->standalone->pageBuilderIntegrations[ $pageBuilder ];

		// For WPBakery, we need to ensure shortcodes are mapped before processing
		if ( 'wpbakery' === $pageBuilder ) {
			if ( method_exists( '\WPBMap', 'addAllMappedShortcodes' ) ) {
				\WPBMap::addAllMappedShortcodes();
			}
		}

		// Process the content using the page builder's method
		$processedContent = $integration->processContent( $post->ID, $post->post_content );

		// If the page builder didn't process the content (returned raw), try applying the_content filter
		if ( $processedContent === $post->post_content || $this->containsUnrenderedShortcodes( $processedContent ) ) {
			// Set up the page builder context first
			$this->setupPageBuilderContext( $post );

			// Then apply the content filter
			$processedContent = apply_filters( 'the_content', $post->post_content );
		}

		return $processedContent;
	}

	/**
	 * Simulates a frontend request to properly render content with page builders.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function simulateFrontendRequest( $post ) {
		// Try using WordPress's internal request handling first
		$content = $this->renderContentViaInternalRequest( $post );

		// If that didn't work or returned raw shortcodes, try the manual approach
		if ( $this->containsUnrenderedShortcodes( $content ) ) {
			$content = $this->renderContentManually( $post );
		}

		// If still not working, try making an actual HTTP request
		if ( $this->containsUnrenderedShortcodes( $content ) ) {
			$content = $this->renderContentViaHttpRequest( $post );
		}

		return $content;
	}

	/**
	 * Renders content using WordPress's internal request handling.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function renderContentViaInternalRequest( $post ) {
		// Store original state
		$originalPost  = $GLOBALS['post'] ?? null;
		$originalQuery = $GLOBALS['wp_query'] ?? null;

		try {
			// Set up the post
			$GLOBALS['post'] = $post;
			setup_postdata( $post );

			// Create a proper query
			$query = new \WP_Query( [
				'p'           => $post->ID,
				'post_type'   => $post->post_type,
				'post_status' => 'publish'
			] );

			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;

			// Set up page builder context
			$this->setupPageBuilderContext( $post );

			// Process shortcodes using AIOSEO's helper which handles Divi properly
			$content = aioseo()->helpers->doShortcodes( $post->post_content, [], $post->ID );

			// Apply the content filters
			$content = apply_filters( 'the_content', $content );

		} finally {
			// Restore original state
			$GLOBALS['post']     = $originalPost;
			$GLOBALS['wp_query'] = $originalQuery;
			if ( $originalPost ) {
				setup_postdata( $originalPost );
			}
		}

		return $content;
	}

	/**
	 * Renders content manually with extensive context setup.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function renderContentManually( $post ) {
		// Store all current global state
		$originalGlobals = [
			'post'         => $GLOBALS['post'] ?? null,
			'wp_query'     => $GLOBALS['wp_query'] ?? null,
			'wp_the_query' => $GLOBALS['wp_the_query'] ?? null,
			'wp'           => $GLOBALS['wp'] ?? null,
		];

		// Store Divi-specific globals if they exist
		$originalDiviGlobals = [];
		$diviGlobals = [
			'et_pb_current_template',
			'et_pb_rendering_column',
			'et_pb_rendering_specialty_section',
			'et_pb_rendering_row',
			'et_pb_rendering_section',
		];
		foreach ( $diviGlobals as $global ) {
			$originalDiviGlobals[ $global ] = $GLOBALS[ $global ] ?? null;
		}

		try {
			// Set up the post context
			$GLOBALS['post'] = $post;
			setup_postdata( $post );

			// Create a proper WP_Query object
			$query = new \WP_Query();
			$query->init();
			$query->is_single         = true;
			$query->is_singular       = true;
			$query->is_page           = ( 'page' === $post->post_type );
			$query->is_home           = false;
			$query->is_front_page     = ( 'page' === $post->post_type && get_option( 'page_on_front' ) === $post->ID ); // @phpstan-ignore-line
			$query->queried_object    = $post;
			$query->queried_object_id = $post->ID;
			$query->post_count        = 1;
			$query->found_posts       = 1;
			$query->max_num_pages     = 1;

			// Set the global query objects
			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;

			// Set up page builder context
			$this->setupPageBuilderContext( $post );

			// Process shortcodes using AIOSEO's helper which handles Divi properly
			$content = aioseo()->helpers->doShortcodes( $post->post_content, [], $post->ID );

			// Apply the content filters
			$content = apply_filters( 'the_content', $content );

		} finally {
			// Restore all original global state
			foreach ( $originalGlobals as $key => $value ) {
				$GLOBALS[ $key ] = $value;
			}

			foreach ( $originalDiviGlobals as $key => $value ) {
				$GLOBALS[ $key ] = $value;
			}

			// Restore post data if we had an original post
			if ( $originalGlobals['post'] ) {
				setup_postdata( $originalGlobals['post'] );
			}
		}

		return $content;
	}

	/**
	 * Renders content by making an HTTP request to the frontend.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function renderContentViaHttpRequest( $post ) {
		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return $post->post_content;
		}

		// Add a parameter to indicate this is for LLMS generation
		$url = add_query_arg( 'aioseo_llms_generation', '1', $url );

		// Make the HTTP request
		$response = wp_remote_get( $url, [
			'timeout'    => 30,
			'user-agent' => 'AIOSEO-LLMS-Generator/1.0',
			'headers'    => [
				'X-AIOSEO-LLMS-Generation' => '1',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $post->post_content;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $post->post_content;
		}

		// Extract the content from the HTML response
		$content = $this->extractContentFromHtml( $body );

		return $content ?: $post->post_content;
	}

	/**
	 * Extracts the main content from HTML response.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $html The HTML content.
	 * @return string
	 */
	private function extractContentFromHtml( $html ) {
		// Remove CSS and JavaScript first
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', '', $html );

		// Try to find the main content area
		$patterns = [
			'/<main[^>]*>(.*?)<\/main>/is',
			'/<article[^>]*>(.*?)<\/article>/is',
			'/<div[^>]*class="[^"]*entry-content[^"]*"[^>]*>(.*?)<\/div>/is',
			'/<div[^>]*class="[^"]*post-content[^"]*"[^>]*>(.*?)<\/div>/is',
			'/<div[^>]*class="[^"]*content[^"]*"[^>]*>(.*?)<\/div>/is',
			'/<div[^>]*class="[^"]*et_pb_section[^"]*"[^>]*>(.*?)<\/div>/is',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $matches ) ) {
				$content = $matches[1];
				// Clean up the content
				$content = $this->cleanHtmlContent( $content );
				if ( ! empty( $content ) ) {
					return $content;
				}
			}
		}

		// Fallback: return the entire body content
		return $this->cleanHtmlContent( $html );
	}

	/**
	 * Cleans HTML content for better markdown conversion.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $content The HTML content to clean.
	 * @return string
	 */
	private function cleanHtmlContent( $content ) {
		// Remove unwanted elements
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
		$content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $content );
		$content = preg_replace( '/<link[^>]*>/i', '', $content );
		$content = preg_replace( '/<meta[^>]*>/i', '', $content );
		$content = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $content );

		// Remove author bio sections that are duplicated
		$content = preg_replace( '/<div[^>]*class="[^"]*aioseo-author-bio[^"]*"[^>]*>.*?<\/div>/is', '', $content );

		// Remove empty paragraphs and divs
		$content = preg_replace( '/<p[^>]*>\s*<\/p>/i', '', $content );
		$content = preg_replace( '/<div[^>]*>\s*<\/div>/i', '', $content );

		// Clean up whitespace
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// Strip tags but keep essential formatting
		$content = strip_tags( $content, '<p><br><strong><em><a><ul><ol><li><h1><h2><h3><h4><h5><h6><img><div><span><button>' );

		return $content;
	}


	/**
	 * Checks if content contains unrendered shortcodes.
	 *
	 * @since 4.8.8
	 *
	 * @param  string $content The content to check.
	 * @return bool
	 */
	private function containsUnrenderedShortcodes( $content ) {
		// Check for common page builder shortcodes
		$pageBuilderShortcodes = [
			// Divi shortcodes
			'[et_pb_section',
			'[et_pb_row',
			'[et_pb_column',
			'[et_pb_text',
			'[et_pb_button',
			// Elementor shortcodes
			'[elementor-template',
			'[elementor-widget',
			// WPBakery shortcodes
			'[vc_row',
			'[vc_column',
			'[vc_column_text',
			'[vc_button',
			// Avada shortcodes
			'[fusion_',
			// SiteOrigin shortcodes
			'[siteorigin_widget',
			'[so-panel',
			// Thrive Architect shortcodes
			'[tcb_',
			'[thrive_',
			'[tve_',
			// SeedProd shortcodes
			'[seedprod_',
		];

		foreach ( $pageBuilderShortcodes as $shortcode ) {
			if ( strpos( $content, $shortcode ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sets up page builder context for proper shortcode rendering.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return void
	 */
	private function setupPageBuilderContext( $post ) {
		$pageBuilder = $this->getPageBuilderName( $post->ID );

		// Set up context for each page builder
		switch ( $pageBuilder ) {
			case 'divi':
				$this->setupDiviContext( $post );
				break;
			case 'elementor':
				$this->setupElementorContext();
				break;
			case 'avada':
				$this->setupAvadaContext();
				break;
			case 'wpbakery':
				$this->setupWPBakeryContext();
				break;
			case 'siteorigin':
				$this->setupSiteOriginContext();
				break;
			case 'thrive-architect':
				$this->setupThriveArchitectContext( $post );
				break;
			case 'seedprod':
				$this->setupSeedProdContext();
				break;
		}
	}

	/**
	 * Sets up Divi Builder context for proper shortcode rendering.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return void
	 */
	private function setupDiviContext( $post ) {
		// Initialize Divi's global variables
		$GLOBALS['et_pb_current_template'] = '';
		$GLOBALS['et_pb_rendering_column'] = false;
		$GLOBALS['et_pb_rendering_specialty_section'] = false;
		$GLOBALS['et_pb_rendering_row'] = false;
		$GLOBALS['et_pb_rendering_section'] = false;

		// Set up Divi's post context
		if ( method_exists( 'ET_Builder_Element', 'set_global_attributes' ) ) {
			\ET_Builder_Element::set_global_attributes( $post );
		}

		// Ensure Divi's shortcode functions are available
		if ( function_exists( 'et_pb_shortcode_css' ) ) {
			// Initialize Divi's CSS system
			et_pb_shortcode_css( $post->post_content );
		}

		// Set up Divi's module context
		if ( class_exists( 'ET_Builder_Module' ) && method_exists( 'ET_Builder_Module', 'set_global_attributes' ) ) {
			// Initialize the module system
			\ET_Builder_Module::set_global_attributes( $post );
		}

		// Use AIOSEO's Divi internal rendering flag
		aioseo()->helpers->setDiviInternalRendering( true );
	}

	/**
	 * Sets up Elementor context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	private function setupElementorContext() {
		// Set Elementor rendering flag
		$GLOBALS['elementor_rendering'] = true;

		// Don't try to initialize Elementor as it's already loaded and causes class conflicts
		// Just set the necessary flags for content rendering
		$GLOBALS['elementor_frontend_rendering'] = true;
		$GLOBALS['elementor_content_rendering'] = true;
	}

	/**
	 * Processes Elementor content specifically.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function processElementorContent( $post ) {
		// Try to get Elementor document and render content
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$elementor = \Elementor\Plugin::instance();

				// Get the document for this post
				if ( isset( $elementor->documents ) && method_exists( $elementor->documents, 'get' ) ) {
					$document = $elementor->documents->get( $post->ID );
					if ( $document && method_exists( $document, 'is_built_with_elementor' ) && $document->is_built_with_elementor() ) {
						// Try to get the frontend content
						if ( isset( $elementor->frontend ) && method_exists( $elementor->frontend, 'get_builder_content' ) ) {
							$content = $elementor->frontend->get_builder_content( $post->ID );
							if ( ! empty( $content ) ) {
								return $content;
							}
						}

						// Alternative: try to get the content from the document
						if ( method_exists( $document, 'get_content' ) ) {
							$content = $document->get_content();
							if ( ! empty( $content ) ) {
								return $content;
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				// If Elementor processing fails, continue
			}
		}

		// Fallback: try to render using Elementor's frontend class directly
		if ( class_exists( '\Elementor\Frontend' ) ) {
			try {
				$frontend = new \Elementor\Frontend();
				if ( method_exists( $frontend, 'get_builder_content' ) ) {
					$content = $frontend->get_builder_content( $post->ID );
					if ( ! empty( $content ) ) {
						return $content;
					}
				}
			} catch ( \Exception $e ) {
				// If this also fails, continue to standard processing
			}
		}

		// Final fallback to standard WordPress content processing
		return apply_filters( 'the_content', $post->post_content );
	}

	/**
	 * Sets up Avada (Fusion Builder) context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	private function setupAvadaContext() {
		// Set Avada rendering flags
		$GLOBALS['fusion_builder_doing_ajax'] = false;
		$GLOBALS['fusion_builder_rendering'] = true;

		// Initialize Fusion Builder if available
		if ( class_exists( 'FusionBuilder' ) ) {
			// Ensure Fusion Builder is properly initialized
			if ( method_exists( 'FusionBuilder', 'get_instance' ) ) {
				$fusionBuilder = \FusionBuilder::get_instance();
				if ( method_exists( $fusionBuilder, 'init' ) ) {
					$fusionBuilder->init();
				}
			}
		}

		// Set up Fusion Core if available
		if ( class_exists( 'FusionCore_Plugin' ) ) {
			$GLOBALS['fusion_core_rendering'] = true;
		}
	}

	/**
	 * Sets up WPBakery Page Builder context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	private function setupWPBakeryContext() {
		// Set WPBakery rendering flags
		$GLOBALS['vc_doing_ajax'] = false;
		$GLOBALS['vc_rendering'] = true;

		// Initialize WPBakery if available
		if ( class_exists( 'Vc_Manager' ) ) {
			$vcManager = \Vc_Manager::getInstance();
			if ( method_exists( $vcManager, 'init' ) ) {
				$vcManager->init();
			}
		}

		// Add all mapped shortcodes
		if ( class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
			\WPBMap::addAllMappedShortcodes();
		}

		// Set up WPBakery's shortcode system
		if ( class_exists( 'WPBakeryShortcode' ) ) {
			$GLOBALS['vc_shortcode_rendering'] = true;
		}
	}

	/**
	 * Sets up SiteOrigin Page Builder context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	private function setupSiteOriginContext() {
		// Set SiteOrigin rendering flags
		$GLOBALS['siteorigin_panels_rendering'] = true;
		$GLOBALS['siteorigin_widget_rendering'] = true;

		// Initialize SiteOrigin Panels if available
		if ( class_exists( 'SiteOrigin_Panels' ) ) {
			$GLOBALS['siteorigin_panels_initialized'] = true;
		}

		// Initialize SiteOrigin Widgets if available
		if ( class_exists( 'SiteOrigin_Widgets_Bundle' ) ) {
			$GLOBALS['siteorigin_widgets_initialized'] = true;
		}
	}

	/**
	 * Sets up Thrive Architect context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return void
	 */
	private function setupThriveArchitectContext( $post ) {
		// Set Thrive Architect rendering flags
		$GLOBALS['tcb_rendering'] = true;
		$GLOBALS['thrive_architect_rendering'] = true;
		$GLOBALS['tve_rendering'] = true;

		// Initialize Thrive Architect if available
		if ( class_exists( 'TCB_Editor' ) ) {
			$GLOBALS['tcb_editor_initialized'] = true;
		}

		// Set up Thrive Architect's content system
		if ( class_exists( 'TCB_Post' ) ) {
			$GLOBALS['tcb_post_rendering'] = true;
		}

		// Initialize Thrive Architect's main classes
		if ( class_exists( 'Thrive_Architect' ) ) {
			$GLOBALS['thrive_architect_initialized'] = true;
		}

		// Set up Thrive Architect's frontend
		if ( class_exists( 'TCB_Frontend' ) ) {
			$GLOBALS['tcb_frontend_rendering'] = true;
		}

		// Initialize Thrive Architect's shortcode system
		if ( class_exists( 'TCB_Shortcode' ) ) {
			$GLOBALS['tcb_shortcode_rendering'] = true;
		}

		// Set up Thrive Architect's content processing
		if ( function_exists( 'tcb_post' ) ) {
			$tcbPost = tcb_post( $post->ID );
			if ( $tcbPost && method_exists( $tcbPost, 'get_rendered_content' ) ) {
				$GLOBALS['tcb_post_object'] = $tcbPost;
			}
		}
	}

	/**
	 * Sets up SeedProd context for proper content rendering.
	 *
	 * @since 4.8.8
	 *
	 * @return void
	 */
	private function setupSeedProdContext() {
		// Set SeedProd rendering flags
		$GLOBALS['seedprod_rendering'] = true;
		$GLOBALS['seedprod_builder_rendering'] = true;

		// Initialize SeedProd if available
		if ( class_exists( 'SeedProd' ) ) {
			$GLOBALS['seedprod_initialized'] = true;
		}
	}

	/**
	 * Processes Thrive Architect content specifically.
	 *
	 * @since 4.8.8
	 *
	 * @param  \WP_Post $post The post object.
	 * @return string
	 */
	private function processThriveArchitectContent( $post ) {
		// Try to get rendered content from Thrive Architect
		if ( function_exists( 'tcb_post' ) ) {
			$tcbPost = tcb_post( $post->ID );
			if ( $tcbPost && method_exists( $tcbPost, 'get_rendered_content' ) ) {
				$content = $tcbPost->get_rendered_content();
				if ( ! empty( $content ) ) {
					return $content;
				}
			}
		}

		// Try using Thrive Architect's frontend rendering
		if ( class_exists( 'TCB_Frontend' ) ) {
			$frontend = new \TCB_Frontend();
			if ( method_exists( $frontend, 'render_content' ) ) {
				$content = $frontend->render_content( $post->post_content );
				if ( ! empty( $content ) ) {
					return $content;
				}
			}
		}

		// Try using Thrive Architect's shortcode system
		if ( class_exists( 'TCB_Shortcode' ) ) {
			$shortcode = new \TCB_Shortcode();
			if ( method_exists( $shortcode, 'render' ) ) {
				$content = $shortcode->render( $post->post_content );
				if ( ! empty( $content ) ) {
					return $content;
				}
			}
		}

		// Fallback to standard WordPress content processing
		return apply_filters( 'the_content', $post->post_content );
	}
}