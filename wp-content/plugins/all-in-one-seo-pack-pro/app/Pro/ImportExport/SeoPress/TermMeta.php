<?php
namespace AIOSEO\Plugin\Pro\ImportExport\SeoPress;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

/**
 * Imports the term meta from SEOPress.
 *
 * @since 4.1.4
 */
class TermMeta {
	/**
	 * The term action name.
	 *
	 * @since 4.4.3
	 *
	 * @var string
	 */
	private $termActionName = 'aioseo_import_term_meta_seopress';

	/**
	 * The mapped meta
	 *
	 * @since 4.8.1
	 *
	 * @var array
	 */
	private $mappedMeta = [
		'_seopress_robots_archive'       => 'robots_noarchive',
		'_seopress_robots_canonical'     => 'canonical_url',
		'_seopress_robots_imageindex'    => 'robots_noimageindex',
		'_seopress_robots_odp'           => 'robots_noodp',
		'_seopress_robots_snippet'       => 'robots_nosnippet',
		'_seopress_social_fb_desc'       => 'og_description',
		'_seopress_social_fb_img'        => 'og_image_custom_url',
		'_seopress_social_fb_title'      => 'og_title',
		'_seopress_social_twitter_desc'  => 'twitter_description',
		'_seopress_social_twitter_img'   => 'twitter_image_custom_url',
		'_seopress_social_twitter_title' => 'twitter_title',
		'_seopress_titles_desc'          => 'description',
		'_seopress_titles_title'         => 'title',
		'_seopress_robots_follow'        => 'robots_nofollow',
		'_seopress_robots_index'         => 'robots_noindex'
	];

	/**
	 * Class constructor.
	 *
	 * @since 4.4.3
	 *
	 */
	public function __construct() {
		add_action( $this->termActionName, [ $this, 'importTermMeta' ] );
	}

	/**
	 * Schedules the term meta import.
	 *
	 * @since 4.1.4
	 *
	 * @return void
	 */
	public function scheduleImport() {
		if ( aioseo()->actionScheduler->scheduleSingle( $this->termActionName, 0 ) ) {
			if ( ! aioseo()->core->cache->get( 'import_term_meta_seopress' ) ) {
				aioseo()->core->cache->update( 'import_term_meta_seopress', time(), WEEK_IN_SECONDS );
			}
		}
	}

	/**
	 * Imports the term meta.
	 *
	 * @since 4.1.4
	 *
	 * @return void
	 */
	public function importTermMeta() {
		$termsPerAction   = 100;
		$publicTaxonomies = implode( "', '", aioseo()->helpers->getpublicTaxonomies( true ) );
		$timeStarted      = gmdate( 'Y-m-d H:i:s', aioseo()->core->cache->get( 'import_term_meta_seopress' ) );

		$terms = aioseo()->core->db
			->start( 'terms as t' )
			->select( 't.term_id' )
			->join( 'termmeta as tm', '`t`.`term_id` = `tm`.`term_id`' )
			->join( 'term_taxonomy as tt', '`t`.`term_id` = `tt`.`term_id`' )
			->leftJoin( 'aioseo_terms as at', '`t`.`term_id` = `at`.`term_id`' )
			->whereRaw( "tm.meta_key LIKE '_seopress_%'" )
			->whereRaw( "( tt.taxonomy IN ( '$publicTaxonomies' ) )" )
			->whereRaw( "( at.term_id IS NULL OR at.updated < '$timeStarted' )" )
			->orderBy( 't.term_id DESC' )
			->limit( $termsPerAction )
			->run()
			->result();

		if ( ! $terms || ! count( $terms ) ) {
			aioseo()->core->cache->delete( 'import_term_meta_seopress' );

			return;
		}

		foreach ( $terms as $term ) {
			$termMeta = aioseo()->core->db
				->start( 'termmeta as tm' )
				->select( 'tm.meta_key, tm.meta_value' )
				->where( 'tm.term_id', $term->term_id )
				->whereRaw( "`tm`.`meta_key` LIKE '_seopress_%'" )
				->run()
				->result();

			$meta = array_merge( [
				'term_id' => (int) $term->term_id,
			], $this->getMetaData( $termMeta, $term->term_id ) );

			$aioseoterm = Models\Term::getTerm( $term->term_id );
			$aioseoterm->set( $meta );
			$aioseoterm->save();
		}

		if ( count( $terms ) === $termsPerAction ) {
			aioseo()->actionScheduler->scheduleSingle( $this->termActionName, 5, [], true );
		} else {
			aioseo()->core->cache->delete( 'import_term_meta_seopress' );
		}
	}

	/**
	 * Get the meta data by term meta.
	 *
	 * @since 4.8.1
	 *
	 * @param object $termMeta The term meta from database.
	 * @param int    $termId   The term ID.
	 * @return array           The meta data.
	 */
	private function getMetaData( $termMeta, $termId ) {
		$meta = [
			'term_id'             => $termId,
			'robots_default'      => true,
			'robots_noarchive'    => false,
			'canonical_url'       => '',
			'robots_nofollow'     => false,
			'robots_noimageindex' => false,
			'robots_noindex'      => false,
			'robots_noodp'        => false,
			'robots_nosnippet'    => false
		];

		foreach ( $termMeta as $record ) {
			$name  = $record->meta_key;
			$value = $record->meta_value;

			if ( ! in_array( $name, array_keys( $this->mappedMeta ), true ) ) {
				continue;
			}

			switch ( $name ) {
				case '_seopress_robots_odp':
				case '_seopress_robots_imageindex':
				case '_seopress_robots_archive':
				case '_seopress_robots_snippet':
				case '_seopress_robots_follow':
				case '_seopress_robots_index':
					if ( 'yes' === $value ) {
						$meta['robots_default']       = false;
						$meta[ $this->mappedMeta[ $name ] ] = true;
					}
					break;
				case '_seopress_social_fb_img':
					$meta['og_image_type']        = 'custom_image';
					$meta[ $this->mappedMeta[ $name ] ] = esc_url( $value );
					break;
				case '_seopress_social_twitter_img':
					$meta['twitter_image_type']   = 'custom_image';
					$meta[ $this->mappedMeta[ $name ] ] = esc_url( $value );
					break;
				case '_seopress_titles_title':
				case '_seopress_titles_desc':
					$value = aioseo()->importExport->seoPress->helpers->macrosToSmartTags( $value, 'term' );
				default:
					$meta[ $this->mappedMeta[ $name ] ] = esc_html( wp_strip_all_tags( strval( $value ) ) );
					break;
			}
		}

		$this->checkForRedirectsToMigrate( $termMeta, $termId );

		return $meta;
	}

	/**
	 * Get the redirect meta and migrate it.
	 *
	 * @since 4.8.1
	 *
	 * @param  object $termMeta The term meta from database.
	 * @param  int    $termId   The term ID.
	 * @return void
	 */
	private function checkForRedirectsToMigrate( $termMeta, $termId ) {
		// Check if aioseoRedirects is active and try to import redirects from term meta.
		$redirectsAddon = aioseo()->addons->getAddon( 'aioseo-redirects' );
		if ( ! empty( $redirectsAddon ) && $redirectsAddon->isActive ) {
			$customRules    = null;
			$parsedTermMeta = [];

			foreach ( $termMeta as $item ) {
				$parsedTermMeta[ $item->meta_key ] = $item->meta_value;
			}

			// Only create the new redirect if we actually have a target.
			if ( ! empty( $parsedTermMeta['_seopress_redirections_value'] ) ) {
				if ( ! empty( $parsedTermMeta['_seopress_redirections_logged_status'] ) && 'both' !== $parsedTermMeta['_seopress_redirections_logged_status'] ) {
					$mappedStatuses = [
						'only_logged_in'     => 'loggedin',
						'only_not_logged_in' => 'loggedout'
					];

					if ( in_array( $parsedTermMeta['_seopress_redirections_logged_status'], array_keys( $mappedStatuses ), true ) ) {
						$customRules = wp_json_encode( [
							[
								'type'  => 'login',
								'key'   => null,
								'value' => $mappedStatuses[ $parsedTermMeta['_seopress_redirections_logged_status'] ],
								'regex' => null
							]
						] );
					}
				}

				$redirectMeta = [
					'type'         => $parsedTermMeta['_seopress_redirections_type'],
					'target_url'   => $parsedTermMeta['_seopress_redirections_value'],
					'enabled'      => empty( $parsedTermMeta['_seopress_redirections_enabled'] ) ? 0 : 1,
					'source_url'   => get_term_link( $termId ),
					'custom_rules' => $customRules,
				];

				$this->migrateMetaRedirect( $redirectMeta );
			}
		}
	}

	/**
	 * Import the redirects from term meta.
	 *
	 * @since 4.8.1
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
			'post_id'      => null,
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