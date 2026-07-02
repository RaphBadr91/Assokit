<?php
namespace AIOSEO\Plugin\Addon\ImageSeo\Image;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds support for Image SEO.
 *
 * @since 1.0.0
 */
class Image {
	/**
	 * The supported image extensions.
	 *
	 * @since 1.1.6
	 *
	 * @var array
	 */
	private $supportedExtensions = [ 'png', 'jpg', 'jpeg', 'gif', 'heic', 'svg', 'ico', 'webp', 'avif' ];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Filter images embedded into the post content or 3rd party plugins.
		add_filter( 'the_content', [ $this, 'filterContent' ], 99999 );
		add_filter( 'seedprod_lpage_content', [ $this, 'filterContent' ] );
		add_filter( 'woocommerce_short_description', [ $this, 'filterContent' ] );

		// Filter images on attachment pages.
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filterImageAttributes' ], 10, 2 );

		// Filter attachment data on upload.
		add_filter( 'wp_insert_attachment_data', [ $this, 'filterImageData' ], 10, 2 );
		add_filter( 'wp_unique_filename', [ $this, 'filterFilename' ], 20, 3 );

		// Filter embedded attachment caption and description smart tags on-the-fly.
		add_action( 'template_redirect', [ $this, 'parseEmbeddedImageSmartTags' ] );

		// Bulk actions.
		add_filter( 'bulk_actions-upload', [ $this, 'registerBulkActions' ] );
		add_filter( 'handle_bulk_actions-upload', [ $this, 'handleBulkActions' ], 10, 3 );
	}

	/**
	 * Filters the content of the requested post.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $content The post content.
	 * @return string          The filtered post content.
	 */
	public function filterContent( $content ) {
		if ( empty( $content ) || is_admin() || ! is_singular() ) {
			return $content;
		}

		return preg_replace_callback( '/(<img.[^>]*+>)(<\/a>)?(<figcaption(.*?)>(.*?)<\/figcaption>)?/', [ $this, 'filterEmbeddedImages' ], $content );
	}

	/**
	 * Filters the attributes of image attachment pages.
	 *
	 * @since 1.0.0
	 *
	 * @param  array    $attributes The image attributes.
	 * @param  \WP_Post $post       The post object.
	 * @return array                The filtered image attributes
	 */
	public function filterImageAttributes( $attributes, $post = null ) {
		if ( is_admin() || ( ! is_singular() && ! is_home() ) ) {
			return $attributes;
		}

		$attributes['title'] = $this->getAttribute( 'title', $post->ID );
		$attributes['alt']   = $this->getAttribute( 'altTag', $post->ID );

		return $attributes;
	}

	/**
	 * Filters the attributes of images that are embedded in the post content.
	 * Callback function for filterContent().
	 *
	 * @since 1.0.0
	 *
	 * @param  array  $images The HTML image tag (first match of Regex pattern).
	 * @return string         The filtered HTML image tag.
	 */
	public function filterEmbeddedImages( $images ) {
		$image             = ( $images[1] ?? '' ) . ( $images[2] ?? '' );
		$captionAttributes = $images[4] ?? '';
		$caption           = $images[5] ?? '';
		$id                = $this->imageId( $image );

		if ( ! $id ) {
			return $images[0];
		}

		if ( ! $this->isExcluded( 'title' ) ) {
			$title = $this->findExistingAttribute( 'title', $image );
			$image = $this->insertAttribute(
				$image,
				'title',
				$this->getAttribute( 'title', $id, $title )
			);
		}

		if ( ! $this->isExcluded( 'altTag' ) ) {
			$altTag = $this->findExistingAttribute( 'alt', $image );
			$image  = $this->insertAttribute(
				$image,
				'alt',
				$this->getAttribute( 'altTag', $id, $altTag )
			);
		}

		$output = $image;

		if ( ! empty( $caption ) ) {
			$caption = aioseoImageSeo()->tags->replaceTags( $caption, $id, 'caption' );
			$output  .= "<figcaption$captionAttributes>$caption</figcaption>";
		}

		return $output;
	}

	/**
	 * Tries to extract the attachment page ID of an image.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $image The image HTML tag.
	 * @return mixed         The ID of the attachment page or false if no ID could be found.
	 */
	private function imageId( $image ) {
		$imageId = false;

		// Check if class contains an ID.
		if ( preg_match( '#wp-image-(\d+)#', $this->findExistingAttribute( 'class', $image ), $matches ) ) {
			$imageId = intval( $matches[1] );
		}

		// Check for SeedProd image.
		if ( ! $imageId && preg_match( '#sp-image-block-([a-z0-9]+)#', $this->findExistingAttribute( 'class', $image ), $matches ) ) {
			$imageId = intval( $matches[1] );
		}

		// Allow WPML to find the translated attachment page.
		if ( aioseo()->helpers->isWpmlActive() ) {
			$imageId = apply_filters( 'wpml_object_id', $imageId, 'attachment', true );
		}

		return $imageId;
	}

	/**
	 * Inserts a given value for a given image attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $image         The image HTML.
	 * @param  string $attributeName The attribute name.
	 * @param  string $value         The attribute value.
	 * @return array                 The modified image attributes.
	 */
	private function insertAttribute( $image, $attributeName, $value ) {
		if ( empty( $value ) ) {
			return $image;
		}

		$value = esc_attr( $value );

		$image = preg_replace( $this->attributeRegex( $attributeName, true ), '${1}' . $value . '${2}', $image, 1, $count );

		// Attribute does not exist. Let's append it at the beginning of the tag.
		if ( ! $count ) {
			$image = preg_replace( '/<img /', '<img ' . $this->attributeToHtml( $attributeName, $value ) . ' ', $image );
		}

		return $image;
	}

	/**
	 * Returns the value of a given image attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $attributeName The attribute name.
	 * @param  int    $id            The attachment page ID.
	 * @param  string $value         The value, if it already exists.
	 * @return string                The attribute value.
	 */
	private function getAttribute( $attributeName, $id, $value = '' ) {
		$format = aioseo()->options->image->$attributeName->format;

		if ( $value ) {
			// If the value already exists on the image (e.g. alt text on Image Block), use that to replace the relevant tag in the format.
			$tag = 'title' === $attributeName ? '#image_title' : '#alt_tag';

			// Replace the tag with the existing value instead of using the default format.
			$format = aioseo()->helpers->pregReplace( "/$tag/", $value, $value );

			// Because some HTML entities are already decoded, we must decode them so that we can strip them.
			$format = aioseo()->helpers->decodeHtmlEntities( $format );
		}

		// We need to escape the string for HTML attributes.
		// This is because the string can contain HTML entities, and we need to make sure they are escaped.
		// We don't want to use `esc_html()` because it will strip the HTML tags.
		$attribute = esc_attr( aioseoImageSeo()->tags->replaceTags( $format, $id, $attributeName ) );

		$snakeName = aioseo()->helpers->toSnakeCase( $attributeName );

		return apply_filters( "aioseo_image_seo_$snakeName", $attribute, [ $id ] );
	}

	/**
	 * Returns the value of the given attribute if it already exists.
	 *
	 * @since 1.0.6
	 *
	 * @param  string $attributeName The attribute name, "title" or "alt".
	 * @param  string $image         The image HTML.
	 * @return string                The value.
	 */
	private function findExistingAttribute( $attributeName, $image ) {
		preg_match( $this->attributeRegex( $attributeName ), $image, $value );

		return ! empty( $value ) ? $value[1] : false;
	}

	/**
	 * Returns a regex string to match an attribute.
	 *
	 * @since 1.0.7
	 *
	 * @param  string $attributeName      The attribute name.
	 * @param  bool   $groupReplaceValue  Regex groupings without the value.
	 * @return string                     The regex string.
	 */
	private function attributeRegex( $attributeName, $groupReplaceValue = false ) {
		$regex = $groupReplaceValue ? "/(\s%s=['\"]).*?(['\"])/" : "/\s%s=['\"](.*?)['\"]/";

		return sprintf( $regex, trim( $attributeName ) );
	}

	/**
	 * Returns an attribute as HTML.
	 *
	 * @since 1.0.7
	 *
	 * @param  string $attributeName The attribute name.
	 * @param  string $value         The attribute value.
	 * @return string                The HTML formatted attribute.
	 */
	private function attributeToHtml( $attributeName, $value ) {
		return sprintf( '%s="%s"', $attributeName, esc_attr( $value ) );
	}

	/**
	 * Filter image caption and description data.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $processedData   An array of slashed, sanitized, and processed attachment image post data.
	 * @param  array $unprocessedData An array of slashed and sanitized attachment post data, but not processed.
	 * @param  bool  $bulk            Whether a bulk action is triggering this method.
	 * @return array                  An array of slashed, sanitized, modified and processed attachment image post data.
	 */
	public function filterImageData( $processedData, $unprocessedData = [], $bulk = false ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// If the attachment is not an image, return.
		if ( ! strstr( $processedData['post_mime_type'], 'image/' ) ) {
			return $processedData;
		}

		// If the post already has an ID, an existing attachment is being updated.
		// In that case, we don't want to update the caption/description (only when uploading new images), except if we're running the bulk action.
		if ( ! $bulk && ! empty( $unprocessedData['ID'] ) ) {
			return $processedData;
		}

		if ( $bulk || aioseo()->options->image->caption->autogenerate ) {
			$processedData['post_excerpt'] = aioseo()->options->image->caption->format;
		}

		if ( $bulk || aioseo()->options->image->description->autogenerate ) {
			$processedData['post_content'] = aioseo()->options->image->description->format;
		}

		if ( ! $bulk ) {
			// Harmonize the attachment post title/name with the filename after it's been sanitized.
			$processedData['post_title'] = $this->filterFilenameHelper( $processedData['post_title'] );
			$processedData['post_name']  = $this->filterFilenameHelper( $processedData['post_name'] );
		}

		return $processedData;
	}

	/**
	 * Filters the filename of new images when uploaded.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $filename  The filename.
	 * @param  string $extension The file extension.
	 * @param  string $dir       Directory path.
	 * @return string            Modified uploaded filename.
	 */
	public function filterFilename( $filename, $extension, $dir ) {
		if (
			// Ignore files that are not images.
			! $this->isImage( $filename, $extension ) ||
			(
				// Don't mess with BuddyPress avatar uploads.
				doing_action( 'wp_ajax_bp_avatar_upload' ) || doing_action( 'wp_ajax_bp_avatar_set' )
			)
		) {
			return $filename;
		}

		$originalFilename = $filename;

		// First, remove the extension part of the filename temporarily so that we don't mess with it.
		// We'll add it back at the end.
		$filename = preg_replace( "/$extension$/i", '', (string) $filename );
		$filename = $this->filterFilenameHelper( $filename );

		// Now, add back the extension.
		$filename .= $extension;

		$dir    = trailingslashit( $dir );
		$number = '';
		while ( file_exists( $dir . $filename ) ) {
			$newNumber = (int) $number + 1;

			$filename = str_replace(
				[ "-{$number}{$extension}", "{$number}{$extension}" ],
				"-{$newNumber}{$extension}",
				$filename
			);

			$number = $newNumber;

			// If we've reached 10000, we're probably in an infinite loop.
			if ( $number > 9999 ) {
				return $originalFilename;
			}
		}

		return $filename;
	}

	/**
	 * Helper method for filterFileanmeHelper().
	 *
	 * @since 1.1.1
	 *
	 * @param  string $string The string.
	 * @return string         The filtered string.
	 */
	private function filterFilenameHelper( $string ) {
		$words    = aioseo()->options->image->filename->wordsToStrip;
		$casing   = aioseo()->options->image->filename->casing;

		// First, strip all words that are on the blacklist.
		if ( ! empty( $words ) ) {
			$words = explode( "\n", $words );

			foreach ( $words as $word ) {
				$escapedWord = preg_quote( $word );
				// Strip the word, but not if it's part of another, longer word.
				$string = preg_replace( "/(\b|\_|[0-9])({$escapedWord})(\b|\_|[0-9])/i", '$1$3', $string );
			}
		}

		// Next, change the casing.
		if ( ! empty( $casing ) ) {
			$string = aioseo()->helpers->convertCase( $string, $casing );
		}

		// Finally, strip the punctuation.
		if ( aioseo()->options->image->filename->stripPunctuation ) {
			$string = aioseoImageSeo()->helpers->stripPunctuation( $string, 'filename' );
		}

		return ltrim( $string, '-' );
	}

	/**
	 * Checks whether the given file is an image.
	 *
	 * @since 1.1.6
	 *
	 * @param  string $filename  The filename.
	 * @param  string $extension The file extension.
	 * @return bool              Whether the file is an image.
	 */
	private function isImage( $filename, $extension = '' ) {
		if ( ! $extension ) {
			preg_match( '/.*\.(.*?)$/', $filename, $matches );
			if ( ! empty( $matches[1] ) ) {
				$extension = $matches[1];
			}
		}

		return in_array( ltrim( strtolower( $extension ), '.' ), $this->supportedExtensions, true );
	}

	/**
	 * Parses the attachment caption and description when they are loaded.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function parseEmbeddedImageSmartTags() {
		if ( aioseo()->helpers->attachmentIs( 'image' ) ) {
			// phpcs:disable Squiz.NamingConventions.ValidVariableName.NotCamelCaps
			global $wp_query;

			$wp_query->post->post_content = aioseoImageSeo()->tags->replaceTags(
				$wp_query->post->post_content,
				$wp_query->post->ID,
				'description'
			);

			$wp_query->post->post_excerpt = aioseoImageSeo()->tags->replaceTags(
				$wp_query->post->post_excerpt,
				$wp_query->post->ID,
				'caption'
			);

			// phpcs:enable
		}
	}

	/**
	 * Register the bulk action.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $bulkActions List of bulk actions.
	 * @return array              Modified list of bulk actions.
	 */
	public function registerBulkActions( $bulkActions ) {
		$bulkActions['autogenerate_attributes'] = __( 'Autogenerate image attributes', 'aioseo-image-seo' );

		return $bulkActions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $sendBack   The redirect URL.
	 * @param  string $actionName The action being taken.
	 * @param  array  $posts      The items the bulk action is being applied to.
	 * @return string             The redirect URL.
	 */
	public function handleBulkActions( $sendBack, $actionName = '', $posts = [] ) {
		if ( 'autogenerate_attributes' !== $actionName ) {
			return $sendBack;
		}

		foreach ( $posts as $id ) {
			$post = get_post( $id, ARRAY_A );
			if ( empty( $post['post_type'] ) || 'attachment' !== $post['post_type'] ) {
				continue;
			}

			$filteredData = $this->filterImageData( $post, null, true );
			wp_update_post( $filteredData );
		}

		return $sendBack;
	}

	/**
	 * Check if the post or term should be excluded.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $attributeName Image attribute name eg. title, alt...
	 * @return bool                  Whether the post/term is excluded or not.
	 */
	private function isExcluded( $attributeName ) {
		$postId        = get_the_ID();
		$excludedPosts = aioseo()->options->image->{$attributeName}->advancedSettings->excludePosts;

		if ( ! $postId ) {
			return false;
		}

		foreach ( $excludedPosts as $p ) {
			$post = json_decode( $p );

			if ( $post->value === $postId ) {
				return true;
			}
		}

		$excludedTermIds = [];
		$excludedTerms   = aioseo()->options->image->$attributeName->advancedSettings->excludeTerms;
		foreach ( $excludedTerms as $t ) {
			$term = json_decode( $t );

			if ( is_object( $term ) ) {
				$excludedTermIds[] = (int) $term->value;
			}
		}

		// Check if there is at least one excluded term assigned to the post.
		$excludedTermRelationships = [];
		if ( count( $excludedTermIds ) ) {
			$excludedTermRelationships = aioseo()->core->db->start( 'term_relationships' )
				->select( 'object_id' )
				->where( 'object_id =', $postId )
				->whereIn( 'term_taxonomy_id', $excludedTermIds )
				->limit( 1 )
				->run()
				->result();
		}

		return ! empty( $excludedTermRelationships );
	}
}