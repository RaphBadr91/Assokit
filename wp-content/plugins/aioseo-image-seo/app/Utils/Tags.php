<?php
namespace AIOSEO\Plugin\Addon\ImageSeo\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to replace tag values with their data counterparts.
 *
 * @since 1.0.0
 */
class Tags {
	/**
	 * An array of tag values that we support.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $tags = [];

	/**
	 * An array of contexts to separate tags.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $context = [
		// AIOSEO Details column editors.
		'imageSeoTitleColumn' => [
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		],
		'imageSeoAltColumn'   => [
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		],
		// Search Appearance setting editors.
		'imageSeoTitle'       => [
			'alt_tag',
			'attachment_caption',
			'attachment_description',
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_description',
			'image_seo_title',
			'image_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		],
		'imageSeoAlt'         => [
			'alt_tag',
			'attachment_caption',
			'attachment_description',
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_description',
			'image_seo_title',
			'image_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		],
		'imageSeoCaption'     => [
			'alt_tag',
			'attachment_caption',
			'attachment_description',
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_description',
			'image_seo_title',
			'image_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		],
		'imageSeoDescription' => [
			'alt_tag',
			'attachment_caption',
			'attachment_description',
			'author_first_name',
			'author_last_name',
			'author_name',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'image_seo_description',
			'image_seo_title',
			'image_title',
			'post_date',
			'post_day',
			'post_month',
			'post_seo_description',
			'post_seo_title',
			'post_year',
			'separator_sa',
			'site_title',
			'tagline',
		]
	];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		aioseo()->tags->addContext( $this->context );

		add_action( 'wp_loaded', [ $this, 'registerTags' ] );
	}

	/**
	 * Register the tags.
	 *
	 * @since 1.1.14
	 *
	 * @return void
	 */
	public function registerTags() {
		$this->tags = [
			[
				'id'          => 'image_title',
				'name'        => __( 'Image Title', 'aioseo-image-seo' ),
				'description' => __( 'Your image title.', 'aioseo-image-seo' )
			],
			[
				'id'          => 'image_seo_title',
				'name'        => __( 'Image SEO Title', 'aioseo-image-seo' ),
				'description' => __( 'Your image SEO title. This is the title you manually enter in the SEO settings.', 'aioseo-image-seo' )
			],
			[
				'id'          => 'image_seo_description',
				'name'        => __( 'Image SEO Description', 'aioseo-image-seo' ),
				'description' => __( 'Your image SEO description. This is the meta description you enter in the SEO settings.', 'aioseo-image-seo' )
			],
			[
				'id'          => 'post_seo_title',
				'name'        => __( 'Post SEO Title', 'aioseo-image-seo' ),
				'description' => __( 'The SEO title set for the page/post.', 'aioseo-image-seo' )
			],
			[
				'id'          => 'post_seo_description',
				'name'        => __( 'Post SEO Description', 'aioseo-image-seo' ),
				'description' => __( 'The SEO description set for the page/post.', 'aioseo-image-seo' )
			],
			[
				'id'          => 'post_day',
				'name'        => __( 'Post Publication Day', 'aioseo-image-seo' ),
				'description' => __( 'The day of the month when the post/page, where the image is embedded, was published, formatted based on your locale.', 'aioseo-image-seo' ),
				'context'     => [ 'imageSeoTitle', 'imageSeoAlt' ]
			],
			[
				'id'          => 'post_month',
				'name'        => __( 'Post Publication Month', 'aioseo-image-seo' ),
				'description' => __( 'The month when the post/page, where the image is embedded, was published, formatted based on your locale.', 'aioseo-image-seo' ),
				'context'     => [ 'imageSeoTitle', 'imageSeoAlt' ]
			],
			[
				'id'          => 'post_year',
				'name'        => __( 'Post Publication Year', 'aioseo-image-seo' ),
				'description' => __( 'The year when the post/page, where the image is embedded, was published, formatted based on your locale.', 'aioseo-image-seo' ),
				'context'     => [ 'imageSeoTitle', 'imageSeoAlt' ]
			]
		];

		aioseo()->tags->addTags( $this->tags );
	}

	/**
	 * Replace the tags in the string provided.
	 *
	 * @since 4.0.0
	 *
	 * @param  string  $string        The string with tags.
	 * @param  int     $id            The page or post ID.
	 * @param  string  $attributeName The image attribute name.
	 * @return string                 The string with tags replaced.
	 */
	public function replaceTags( $string, $id, $attributeName ) {
		if ( ! $string ) {
			return $string;
		}

		if ( preg_match( '/#/', (string) $string ) ) {
			// Replace separator tag so we don't strip it as punctuation.
			$separatorTag = aioseo()->tags->denotationChar . 'separator_sa';
			$string       = preg_replace( "/$separatorTag(?![a-zA-Z0-9_])/im", '>thisisjustarandomplaceholder<', (string) $string );

			foreach ( $this->tags as $tag ) {
				if ( 'custom_field' === $tag['id'] ) {
					continue;
				}

				$tagId   = aioseo()->tags->denotationChar . $tag['id'];
				$pattern = "/$tagId(?![a-zA-Z0-9_])/im";
				if ( preg_match( $pattern, (string) $string ) ) {
					$string = preg_replace( $pattern, $this->getTagValue( $tag, $id ), (string) $string );
				}
			}

			$string = aioseo()->tags->replaceTags( $string, $id );

			$string = preg_replace(
				'/>thisisjustarandomplaceholder<(?![a-zA-Z0-9_])/im',
				aioseo()->helpers->decodeHtmlEntities( aioseo()->options->searchAppearance->global->separator ),
				(string) $string
			);
		}

		$casing = aioseo()->options->image->{$attributeName}->casing;
		if ( $casing ) {
			$string = aioseo()->helpers->convertCase( $string, $casing );
		}

		if ( aioseo()->options->image->{$attributeName}->stripPunctuation ) {
			$string = aioseoImageSeo()->helpers->stripPunctuation( $string, $attributeName, true );
		}

		return $string;
	}

	/**
	 * Get the value of the tag to replace.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $tag The tag to look for.
	 * @param  int    $id  The attachment page ID.
	 * @return string      The value of the tag.
	 */
	public function getTagValue( $tag, $id ) {
		switch ( $tag['id'] ) {
			case 'image_title':
				$attachment = get_post( $id );

				return ! empty( $attachment->post_title ) ? $attachment->post_title : '';
			case 'image_seo_title':
				$metaData = aioseo()->meta->metaData->getMetaData( $id );

				return ! empty( $metaData->title ) ? aioseo()->meta->title->helpers->prepare( $metaData->title ) : '';
			case 'image_seo_description':
				$metaData = aioseo()->meta->metaData->getMetaData( $id );

				return ! empty( $metaData->description ) ? aioseo()->meta->description->helpers->prepare( $metaData->description ) : '';
			case 'post_seo_title':
				$postId   = get_the_ID();
				$metaData = aioseo()->meta->metaData->getMetaData( $postId );

				// If the title is empty, means it uses the default format.
				// NOTE: the title is reset to empty if using the default format on `Models\Post::checkForDefaultFormat` function.
				if ( empty( $metaData->title ) ) {
					$metaData->title = trim( aioseo()->meta->title->getPostTypeTitle( get_post_type( $postId ) ) );
				}

				return ! empty( $metaData->title ) ? aioseo()->meta->title->helpers->prepare( $metaData->title ) : aioseo()->meta->title->helpers->prepare( get_the_title( $postId ) );
			case 'post_seo_description':
				$postId   = get_the_ID();
				$metaData = aioseo()->meta->metaData->getMetaData( $postId );

				// If the description is empty, means it uses the default format.
				// NOTE: the description is reset to empty if using the default format on `Models\Post::checkForDefaultFormat` function.
				if ( empty( $metaData->description ) ) {
					$metaData->description = trim( aioseo()->meta->description->getPostTypeDescription( get_post_type( $postId ) ) );
				}

				return ! empty( $metaData->description ) ? aioseo()->meta->description->helpers->prepare( $metaData->description ) : '';
			case 'post_day':
				return get_the_date( 'd', get_the_ID() );
			case 'post_month':
				return get_the_date( 'F', get_the_ID() );
			case 'post_year':
				return get_the_date( 'Y', get_the_ID() );
		}

		return aioseo()->tags->getTagValue( $tag, $id );
	}
}