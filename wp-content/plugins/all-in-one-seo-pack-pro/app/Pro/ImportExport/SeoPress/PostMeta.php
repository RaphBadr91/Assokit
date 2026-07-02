<?php
namespace AIOSEO\Plugin\Pro\ImportExport\SeoPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\ImportExport\SeoPress as CommonSeoPress;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

/**
 * Imports the post meta from SEOPress.
 *
 * @since 4.1.4
 */
class PostMeta extends CommonSeoPress\PostMeta {
	/**
	 * Maybe import redirects from post meta.
	 *
	 * @since 4.1.4
	 *
	 * @param  object $postMeta The post meta from database.
	 * @param  int    $postId   The post meta from database.
	 * @return array            The meta data.
	 */
	public function getMetaData( $postMeta, $postId ) {
		$meta = parent::getMetaData( $postMeta, $postId );

		// Check if aioseoRedirects is active and try to import redirects from post meta.
		$redirectsAddon = aioseo()->addons->getAddon( 'aioseo-redirects' );
		if ( ! empty( $redirectsAddon ) && $redirectsAddon->isActive ) {
			$customRules    = null;
			$parsedPostMeta = [];

			foreach ( $postMeta as $item ) {
				$parsedPostMeta[ $item->meta_key ] = $item->meta_value;
			}

			// Only create the new redirect if we actually have a target.
			if ( ! empty( $parsedPostMeta['_seopress_redirections_value'] ) ) {
				if ( ! empty( $parsedPostMeta['_seopress_redirections_logged_status'] ) && 'both' !== $parsedPostMeta['_seopress_redirections_logged_status'] ) {
					$mappedStatuses = [
						'only_logged_in'     => 'loggedin',
						'only_not_logged_in' => 'loggedout'
					];

					if ( in_array( $parsedPostMeta['_seopress_redirections_logged_status'], array_keys( $mappedStatuses ), true ) ) {
						$customRules = wp_json_encode( [
							[
								'type'  => 'login',
								'key'   => null,
								'value' => $mappedStatuses[ $parsedPostMeta['_seopress_redirections_logged_status'] ],
								'regex' => null
							]
						] );
					}
				}

				$redirectMeta = [
					'post_id'      => $postId,
					'type'         => $parsedPostMeta['_seopress_redirections_type'],
					'target_url'   => $parsedPostMeta['_seopress_redirections_value'],
					'enabled'      => empty( $parsedPostMeta['_seopress_redirections_enabled'] ) ? 0 : 1,
					'source_url'   => get_permalink( $postId ),
					'custom_rules' => $customRules,
				];

				$this->migrateMetaRedirect( $redirectMeta );
			}
		}

		return $meta;
	}

	/**
	 * Import the redirects from post meta.
	 *
	 * @since 4.1.4
	 *
	 * @return void
	 */
	private function migrateMetaRedirect( $rule ) {
		// Double check if aioseoRedirects is active.
		if ( ! function_exists( 'aioseoRedirects' ) ) {
			return;
		}

		$urlFrom = wp_make_link_relative( $rule['source_url'] );
		$urlTo   = '/';

		if ( ! empty( $rule['target_url'] ) ) {
			$urlTo = 0 === strpos( $rule['target_url'], 'http' ) || '/' === $rule['target_url'] ? $rule['target_url'] : '/' . $rule['target_url'];
		}

		aioseoRedirects()->importExport->seoPress->importRule( [
			'post_id'      => $rule['post_id'],
			'source_url'   => $urlFrom,
			'target_url'   => $urlTo,
			'type'         => $rule['type'],
			'query_param'  => json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
			'group'        => 'manual',
			'regex'        => false,
			'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
			'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
			'enabled'      => $rule['enabled'] ?? 0,
			'custom_rules' => $rule['custom_rules'] ?? null,
		] );
	}
}