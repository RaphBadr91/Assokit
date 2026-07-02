<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles video URL extraction from other plugins/themes.
 *
 * @since 1.1.4
 */
class ThirdParty {
	/**
	 * The post object.
	 *
	 * @since 1.1.4
	 *
	 * @var WP_Post|Object
	 */
	private $post;

	/**
	 * Returns all video URLs for the given post that we can extract from other plugins/themes.
	 *
	 * @since 1.1.4
	 *
	 * @param  WP_Post|Object $post The post object.
	 * @return array                The video URLs.
	 */
	public function getVideoUrls( $post ) {
		$this->post = $post;

		$methodNames = [
			'elementor'
		];

		$videoUrls = [];
		foreach ( $methodNames as $methodName ) {
			$videoUrls = array_merge(
				$videoUrls,
				$this->{$methodName}()
			);
		}

		return array_unique( $videoUrls );
	}

	/**
	 * Returns a list of video URLs for Elementor.
	 * We support the regular Video component out-of-the-box but need custom support for the Video Playlist component.
	 *
	 * @since 1.1.4
	 *
	 * @return array The video URLs.
	 */
	private function elementor() {
		$postId = $this->post->ID;
		if ( ! aioseo()->standalone->pageBuilderIntegrations['elementor']->isBuiltWith( $postId ) ) {
			return [];
		}

		$elementorData = get_post_meta( $postId, '_elementor_data', true );
		if ( empty( $elementorData ) ) {
			return [];
		}

		$elementorData = json_decode( $elementorData );
		if ( empty( $elementorData ) ) {
			return [];
		}

		$widgets = [];
		foreach ( $elementorData as $section ) {
			$widgets = array_merge(
				$widgets,
				$this->getWidgets( $section )
			);
		}

		$videoUrls = [];
		foreach ( $widgets as $widget ) {
			$videoUrls = array_merge(
				$videoUrls,
				$this->extractElementorVideo( $widget )
			);
		}

		return $videoUrls;
	}

	/**
	 * Extract videos from elementor Video widget.
	 *
	 * @since 1.1.18
	 *
	 * @param  object $data The data of the video widget.
	 * @return array        The array of video urls found.
	 */
	private function extractElementorVideo( $data ) {
		$videoUrls = [];

		$type = $data->settings->video_type ?? $data->settings->type ?? ( ! empty( $data->settings->show_image_overlay ) ? 'overlay' : '' );

		// Default sample urls for all providers.
		$defaultUrls = [
			'youtube'     => 'https://www.youtube.com/watch?v=XHOmBV4js_E',
			'vimeo'       => 'https://vimeo.com/235215203',
			'dailymotion' => 'https://www.dailymotion.com/video/x6tqhqb',
		];

		if ( empty( $type ) ) {
			$videoUrls[] = $data->settings->youtube_url;

			return $videoUrls;
		}

		// Recurse widgets that have tabs.
		if ( ! empty( $data->settings->tabs ) ) {
			foreach ( $data->settings->tabs as $tab ) {
				$videoUrls = array_merge(
					$videoUrls,
					$this->extractElementorVideo( $tab )
				);
			}

			return $videoUrls;
		}

		// Check type of video and extract the URL.
		switch ( $type ) {
			case 'vimeo':
				$videoUrls[] = $data->settings->vimeo_url;
				break;
			case 'dailymotion':
				$videoUrls[] = $data->settings->dailymotion_url;
				break;
			case 'videopress':
				$isInsertUrl   = isset( $data->settings->insert_url ) && 'yes' === $data->settings->insert_url;
				$hasVideopress = isset( $data->settings->videopress_url );
				$hasHostedUrl  = isset( $data->settings->hosted_url->url );

				if ( $isInsertUrl ) {
					$videoUrls[] = $hasVideopress ? $data->settings->videopress_url : ( $hasHostedUrl ? $data->settings->hosted_url->url : '' );
				} else {
					$videoUrls[] = $hasHostedUrl ? $data->settings->hosted_url->url : $data->settings->videopress_url;
				}
				break;
			case 'hosted':
				$videoUrls[] = ( isset( $data->settings->insert_url ) && 'yes' === $data->settings->insert_url ) ? $data->settings->external_url->url ?? '' : $data->settings->hosted_url->url ?? '';
				break;
			case 'overlay':
				if ( $data->settings->youtube_url !== $defaultUrls['youtube'] ) {
					$videoUrls[] = $data->settings->youtube_url;
					break;
				}

				if ( $data->settings->vimeo_url !== $defaultUrls['vimeo'] ) {
					$videoUrls[] = $data->settings->vimeo_url;
					break;
				}

				if ( $data->settings->dailymotion_url !== $defaultUrls['dailymotion'] ) {
					$videoUrls[] = $data->settings->dailymotion_url;
					break;
				}

				break;
			default:
				$videoUrls[] = $data->settings->youtube_url;
				break;
		}

		return array_values( array_filter( $videoUrls ) );
	}

	/**
	 * Returns all widgets that are nested inside the given Elementor element.
	 *
	 * @since 1.1.4
	 *
	 * @param  object $element The Elementor object.
	 * @return array           The nested widgets.
	 */
	private function getWidgets( $element ) {
		if ( ! isset( $element->elements ) ) {
			return [];
		}

		$allowedWidgets = [ 'video-playlist', 'video' ];
		$widgets = [];

		// Use recursion to grab all nested widgets that are grandchildren (or even deeper).
		foreach ( $element->elements as $childElement ) {
			// Grab all video playlist widgets that are children of the current element.
			if ( 'widget' === $childElement->elType && in_array( $childElement->widgetType, $allowedWidgets, true ) ) {
				$widgets[] = $childElement;
			}

			// Use recursion to grab all nested widgets that are grandchildren (or even deeper).
			$widgets = array_merge(
				$widgets,
				$this->getWidgets( $childElement )
			);
		}

		return $widgets;
	}
}