<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Imports the Redirection from SEOPress.
 *
 * @since 1.1.1
 */
class SeoPress extends Importer {
	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.1.1
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'SEOPress PRO',
			'version'  => '4.0',
			'basename' => 'wp-seopress-pro/seopress-pro.php',
			'slug'     => 'seopress-pro'
		]
	];

	/**
	 * Import.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function doImport() {
		$rules = $this->getRules();

		if ( empty( $rules ) ) {
			return;
		}

		$paramsMap = [
			'exact_match'        => 'exact',
			'without_param'      => 'ignore',
			'with_ignored_param' => 'pass',
		];

		foreach ( $rules as $rule ) {
			$urlFrom = $this->leadingSlashIt( $rule['urlFrom'] );
			$urlTo   = 0 === strpos( $rule['urlTo'], 'http' ) || '/' === $rule['urlTo'] ? $rule['urlTo'] : $this->leadingSlashIt( $rule['urlTo'] );
			if ( empty( $urlTo ) ) {
				$urlTo = '/';
			}

			// Codes higher than 400 don't have a target URL.
			if ( 400 <= $rule['type'] ) {
				$urlTo = '';
			}

			$customRules = null;
			if ( ! empty( $rule['loggedStatus'] ) && 'both' !== $rule['loggedStatus'] ) {
				$mappedStatuses = [
					'only_logged_in'     => 'loggedin',
					'only_not_logged_in' => 'loggedout'
				];

				if ( ! in_array( $rule['loggedStatus'], array_keys( $mappedStatuses ), true ) ) {
					continue;
				}

				$customRules = wp_json_encode( [
					[
						'type'  => 'login',
						'key'   => null,
						'value' => $mappedStatuses[ $rule['loggedStatus'] ],
						'regex' => null
					]
				] );
			}

			$this->importRule([
				'source_url'   => $urlFrom,
				'post_id'      => null,
				'target_url'   => $urlTo,
				'type'         => $rule['type'],
				'query_param'  => ! empty( $paramsMap[ $rule['param'] ] ) ? $paramsMap[ $rule['param'] ] : json_decode( aioseoRedirects()->options->redirectDefaults->queryParam )->value,
				'group'        => 'manual',
				'regex'        => false,
				'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
				'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
				'enabled'      => 'yes' === $rule['enabled'],
				'custom_rules' => $customRules
			]);
		}
	}

	/**
	 * Get SEOPress redirect rules.
	 *
	 * @since 1.1.1
	 *
	 * @return array Array of redirect rules.
	 */
	private function getRules() {
		$rules = [];

		$redirectsQuery = new \WP_Query( [
			'post_type'              => 'seopress_404',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		] );

		if ( $redirectsQuery->have_posts() ) {
			while ( $redirectsQuery->have_posts() ) {
				$redirectsQuery->the_post();
				$redirectionObject = get_post();

				$rules[] = [
					'urlFrom'      => $redirectionObject->post_title,
					'urlTo'        => get_post_meta( get_the_ID(), '_seopress_redirections_value', true ),
					'type'         => get_post_meta( get_the_ID(), '_seopress_redirections_type', true ),
					'enabled'      => get_post_meta( get_the_ID(), '_seopress_redirections_enabled', true ),
					'param'        => get_post_meta( get_the_ID(), '_seopress_redirections_param', true ),
					'hits'         => get_post_meta( get_the_ID(), 'seopress_404_count', true ),
					'loggedStatus' => get_post_meta( get_the_ID(), '_seopress_redirections_logged_status', true ),
				];
			}
		}

		wp_reset_postdata();

		return $rules;
	}
}