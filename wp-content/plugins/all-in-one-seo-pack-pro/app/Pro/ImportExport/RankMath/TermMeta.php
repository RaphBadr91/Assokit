<?php
namespace AIOSEO\Plugin\Pro\ImportExport\RankMath;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

/**
 * Imports the term meta from Rank Math.
 *
 * @since 4.0.0
 */
class TermMeta {
	/**
	 * The term action name.
	 *
	 * @since 4.4.3
	 *
	 * @var string
	 */
	private $termActionName = 'aioseo_import_term_meta_rank_math';

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
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function scheduleImport() {
		try {
			if ( as_next_scheduled_action( $this->termActionName ) ) {
				return;
			}

			if ( ! aioseo()->core->cache->get( 'import_term_meta_rank_math' ) ) {
				aioseo()->core->cache->update( 'import_term_meta_rank_math', time(), WEEK_IN_SECONDS );
			}

			as_schedule_single_action( time(), $this->termActionName, [], 'aioseo' );
		} catch ( \Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * Imports the term meta.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function importTermMeta() {
		$termsPerAction   = 100;
		$publicTaxonomies = implode( "', '", aioseo()->helpers->getpublicTaxonomies( true ) );
		$timeStarted      = gmdate( 'Y-m-d H:i:s', aioseo()->core->cache->get( 'import_term_meta_rank_math' ) );

		$terms = aioseo()->core->db
			->start( 'terms' . ' as t' )
			->select( 't.term_id' )
			->leftJoin( 'termmeta as tm', '`t`.`term_id` = `tm`.`term_id`' )
			->leftJoin( 'term_taxonomy as tt', '`t`.`term_id` = `tt`.`term_id`' )
			->leftJoin( 'aioseo_terms as at', '`t`.`term_id` = `at`.`term_id`' )
			->whereRaw( "( tt.taxonomy IN ( '$publicTaxonomies' ) )" )
			->whereRaw( "( at.term_id IS NULL OR at.updated < '$timeStarted' )" )
			->orderBy( 't.term_id DESC' )
			->groupBy( 't.term_id' )
			->limit( $termsPerAction )
			->run()
			->result();

		if ( ! $terms || ! count( $terms ) ) {
			aioseo()->core->cache->delete( 'import_term_meta_rank_math' );

			return;
		}

		$mappedMeta = [
			'rank_math_title'                => 'title',
			'rank_math_description'          => 'description',
			'rank_math_canonical_url'        => 'canonical_url',
			'rank_math_focus_keyword'        => 'keywords',
			'rank_math_robots'               => '',
			'rank_math_advanced_robots'      => '',
			'rank_math_facebook_title'       => 'og_title',
			'rank_math_facebook_description' => 'og_description',
			'rank_math_facebook_image'       => 'og_image_custom_url',
			'rank_math_twitter_use_facebook' => 'twitter_use_og',
			'rank_math_twitter_title'        => 'twitter_title',
			'rank_math_twitter_description'  => 'twitter_description',
			'rank_math_twitter_image'        => 'twitter_image_custom_url',
			'rank_math_twitter_card_type'    => 'twitter_card'
		];

		foreach ( $terms as $term ) {
			$termMeta = aioseo()->core->db
				->start( 'termmeta' . ' as tm' )
				->select( 'tm.meta_key, tm.meta_value' )
				->where( 'tm.term_id', $term->term_id )
				->whereRaw( "`tm`.`meta_key` LIKE 'rank_math_%'" )
				->run()
				->result();

			$this->migrateRedirects( $term );

			$meta = [
				'term_id' => $term->term_id,
			];

			foreach ( $termMeta as $record ) {
				$name  = $record->meta_key;
				$value = $record->meta_value;

				if ( ! in_array( $name, array_keys( $mappedMeta ), true ) ) {
					continue;
				}

				switch ( $name ) {
					case 'rank_math_focus_keyword':
						$keywords = [
							[
								'label' => aioseo()->helpers->sanitizeOption( $value ),
								'value' => aioseo()->helpers->sanitizeOption( $value )
							]
						];

						$meta['keywords'] = $keywords;
						break;
					case 'rank_math_robots':
						$value = aioseo()->helpers->maybeUnserialize( $value );
						if ( ! empty( $value ) ) {
							$meta['robots_default'] = false;
							foreach ( $value as $robotsName ) {
								$meta[ "robots_$robotsName" ] = true;
							}
						}
						break;
					case 'rank_math_advanced_robots':
						$value = aioseo()->helpers->maybeUnserialize( $value );
						if ( isset( $value['max-snippet'] ) && is_numeric( $value['max-snippet'] ) ) {
							$meta['robots_max_snippet'] = intval( $value['max-snippet'] );
						}
						if ( isset( $value['max-video-preview'] ) && is_numeric( $value['max-video-preview'] ) ) {
							$meta['robots_max_videopreview'] = intval( $value['max-video-preview'] );
						}
						if ( ! empty( $value['max-image-preview'] ) ) {
							$meta['robots_max_imagepreview'] = aioseo()->helpers->sanitizeOption( lcfirst( $value['max-image-preview'] ) );
						}
						break;
					case 'rank_math_facebook_image':
						$meta['og_image_type']        = 'custom_image';
						$meta[ $mappedMeta[ $name ] ] = esc_url( $value );
						break;
					case 'rank_math_twitter_image':
						$meta['twitter_image_type']   = 'custom_image';
						$meta[ $mappedMeta[ $name ] ] = esc_url( $value );
						break;
					case 'rank_math_twitter_card_type':
						preg_match( '#large#', (string) $value, $match );
						$meta[ $mappedMeta[ $name ] ] = ! empty( $match ) ? 'summary_large_image' : 'summary';
						break;
					case 'rank_math_twitter_use_facebook':
						$meta[ $mappedMeta[ $name ] ] = 'on' === $value;
						break;
					case 'rank_math_title':
					case 'rank_math_description':
						$value = aioseo()->importExport->rankMath->helpers->macrosToSmartTags( $value, 'term' );
					default:
						$meta[ $mappedMeta[ $name ] ] = esc_html( wp_strip_all_tags( strval( $value ) ) );
						break;
				}
			}

			$aioseoterm = Models\Term::getTerm( $term->term_id );
			$aioseoterm->set( $meta );
			$aioseoterm->save();
		}

		if ( count( $terms ) === $termsPerAction ) {
			try {
				as_schedule_single_action( time() + 5, $this->termActionName, [], 'aioseo' );
			} catch ( \Exception $e ) {
				// Do nothing.
			}
		} else {
			aioseo()->core->cache->delete( 'import_term_meta_rank_math' );
		}
	}

	/**
	 * Migrate redirects
	 *
	 * @since 4.8.3
	 *
	 * @param  object $term The term object.
	 * @return void
	 */
	private function migrateRedirects( $term ) {
		if ( ! aioseo()->core->db->tableExists( 'rank_math_redirections' ) ) {
			return;
		}

		if ( ! function_exists( 'aioseoRedirects' ) ) {
			return;
		}

		aioseoRedirects()->importExport->rankMath->importTermRedirect( $term->term_id );
	}
}