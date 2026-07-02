<?php
namespace AIOSEO\Plugin\Pro\SeoRevisions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models as ProModels;
use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * Object Revisions class.
 *
 * @since 4.4.0
 */
class ObjectRevisions {
	/**
	 * The object id.
	 *
	 * @since 4.4.0
	 *
	 * @var int
	 */
	public $objectId;

	/**
	 * The object type (post or term).
	 *
	 * @since 4.4.0
	 *
	 * @var string
	 */
	public $objectType;

	/**
	 * Default ordering used when querying revisions.
	 *
	 * @since 4.4.0
	 *
	 * @var string
	 */
	private $defaultOrderBy = 'id DESC';

	/**
	 * Class constructor.
	 *
	 * @since 4.4.0
	 *
	 * @param  int    $objectId   Object id.
	 * @param  string $objectType Object type. 'post' or 'term'. Default: 'post'.
	 * @return void
	 */
	public function __construct( $objectId, $objectType = 'post' ) {
		$this->objectId   = absint( $objectId );
		$this->objectType = trim( (string) $objectType );
	}

	/**
	 * Get all revisions belonging to a post/term.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $args Array of options: 'limit', 'offset', 'orderBy'.
	 * @return array       An array empty or filled with found revision objects.
	 */
	public function getRevisions( $args = [] ) {
		$args = array_merge( [
			'limit'   => 0,
			'offset'  => 0,
			'orderBy' => $this->defaultOrderBy
		], $args );

		return aioseo()->core->db
			->start( 'aioseo_revisions' )
			->where( 'object_id', $this->objectId )
			->where( 'object_type', $this->objectType )
			->orderBy( $args['orderBy'] )
			->limit( absint( $args['limit'] ), absint( $args['offset'] ) )
			->run()
			->models( 'AIOSEO\\Plugin\\Pro\\Models\\SeoRevision' );
	}

	/**
	 * Get all revisions belonging to a post/term.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $args Args for {@see getRevisions()}.
	 * @return array       An array of formatted revisions.
	 */
	public function getFormattedRevisions( $args = [] ) {
		$revisions = $this->getRevisions( $args );
		foreach ( $revisions as &$revision ) {
			$revision = $revision->formatRevision();
		}

		return array_values( $revisions );
	}

	/**
	 * Retrieve the amount of revisions for this object.
	 *
	 * @since 4.4.0
	 *
	 * @return int Number of total revisions found.
	 */
	public function getCount() {
		aioseo()->core->db->bustCache( 'aioseo_revisions' );

		return aioseo()->core->db
			->start( 'aioseo_revisions' )
			->where( 'object_id', $this->objectId )
			->where( 'object_type', $this->objectType )
			->count();
	}

	/**
	 * Get this object's newest revision.
	 *
	 * @since 4.4.0
	 *
	 * @return void|ProModels\SeoRevision
	 */
	public function getLatestRevision() {
		$revision = $this->getRevisions( [ 'limit' => 1 ] );
		if ( ! empty( $revision ) ) {
			return current( $revision );
		}
	}

	/**
	 * Can this new revision be created?
	 *
	 * @since 4.4.0
	 *
	 * @param  array $revisionData The revision data.
	 * @return bool                Can it be added.
	 */
	public function canAddRevision( $revisionData ) {
		$latestRevision = $this->getLatestRevision();

		// If this is the first revision, we can add it.
		if ( empty( $latestRevision ) ) {
			return true;
		}

		if ( ! $this->isRevisionDataDifferent( $latestRevision->getRevisionData(), $revisionData ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Adds a revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $revisionData The revision data.
	 * @param  int   $authorId     The author of this revision.
	 * @return void
	 */
	public function addRevision( $revisionData, $authorId ) {
		$revision                = new ProModels\SeoRevision();
		$revision->object_id     = $this->objectId;
		$revision->object_type   = $this->objectType;
		$revision->revision_data = $revisionData;
		$revision->author_id     = $authorId;
		$revision->save();

		$limit        = aioseo()->seoRevisions->getLicenseRevisionsLimit();
		$currentCount = $this->getCount();

		// Delete the oldest revisions if the maximum amount has been reached.
		if ( - 1 < $limit && $currentCount > $limit ) {
			$this->deleteRevisions( [ 'limit' => $currentCount - $limit ] );
		}
	}

	/**
	 * Restore a revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  ProModels\SeoRevision $restoreRevision The revision to restore.
	 * @return bool                                   Whether the revision was restored or not.
	 */
	public function restoreRevision( $restoreRevision ) {
		$aioseoObject = $this->getAioseoObject();
		if (
			! $aioseoObject ||
			! $aioseoObject->exists()
		) {
			return false;
		}

		try {
			$restoreRevisionData = $restoreRevision->getRevisionData();

			// In case this revision data is missing late fields (added after the revision was created), we add them by using their current value.
			// This prevents late fields from being set to a wrong value, we just keep their current value.
			foreach ( array_keys( aioseo()->seoRevisions->getLateEligibleFields() ) as $key ) {
				if (
					! array_key_exists( $key, $restoreRevisionData ) &&
					isset( $aioseoObject->$key )
				) {
					$restoreRevisionData[ $key ] = $aioseoObject->$key;
				}
			}

			// Only add a new revision if the data is different, otherwise just proceed with saving the AIOSEO post/term (this is the same logic WP uses).
			if ( $this->canAddRevision( $restoreRevisionData ) ) {
				// Add a new revision using the revision data the user wants restored.
				$this->addRevision( $restoreRevisionData, get_current_user_id() );
			}

			if ( isset( $restoreRevisionData['schema'] ) && ! is_string( $restoreRevisionData['schema'] ) ) {
				// Allow restoring: 'customGraphs', 'default' and 'graphs'.
				$restoreRevisionData['schema'] = wp_json_encode(
					array_merge(
						json_decode( wp_json_encode( $aioseoObject->schema ), true ),
						[
							'graphs'       => $restoreRevisionData['schema']['graphs'],
							'customGraphs' => $restoreRevisionData['schema']['customGraphs'],
							'default'      => $restoreRevisionData['schema']['default']
						]
					)
				);
			}

			// Update metadata for localization.
			if ( method_exists( $aioseoObject, 'updatePostMeta' ) ) {
				$aioseoObject->updatePostMeta( $this->objectId, $restoreRevisionData );
			} elseif ( method_exists( $aioseoObject, 'updateTermMeta' ) ) {
				$aioseoObject->updateTermMeta( $this->objectId, $restoreRevisionData );
			}

			$aioseoObject->set( $restoreRevisionData );
			$aioseoObject->save();

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get the SEO Revision UI diff.
	 *
	 * @since 4.4.0
	 *
	 * @param  int   $fromId The revision ID to compare from.
	 * @param  int   $toId   The revision ID to come to.
	 * @return array         Associative array of an object's revision fields and their diffs.
	 */
	public function getFieldsDiff( $fromId, $toId ) {
		$fieldsDiff = [];
		$revisionTo = new ProModels\SeoRevision( absint( $toId ) );
		if ( ! $revisionTo->exists() ) {
			return $fieldsDiff;
		}

		$revisionFrom     = new ProModels\SeoRevision( absint( $fromId ) );
		$revisionFromData = $revisionFrom->exists()
			? $revisionFrom->getRevisionData()
			// Fallback for when the left revision is the revision "0" (when the post/term didn't exist).
			: array_map( function () {
				return '';
			}, aioseo()->seoRevisions->getEligibleFields() );
		$revisionFromData = aioseo()->seoRevisions->helpers->formatRevisionData( $revisionFromData, $this );
		$revisionToData   = aioseo()->seoRevisions->helpers->formatRevisionData( $revisionTo->getRevisionData(), $this );

		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		// Init computing the different fields.
		foreach ( aioseo()->seoRevisions->getEligibleFields() as $key => $label ) {
			if ( array_key_exists( $key, aioseo()->seoRevisions->getLateEligibleFields() ) ) {
				$revisionFromData[ $key ] = $revisionFromData[ $key ] ?? null;
				$revisionToData[ $key ]   = $revisionToData[ $key ] ?? null;
			}

			if (
				! array_key_exists( $key, $revisionFromData ) ||
				! array_key_exists( $key, $revisionToData )
			) {
				continue;
			}

			// Before continuing we need to format the content, so later we show data similar to what the user would see if they were editing a post/term.
			$contentFrom = $revisionFrom->exists()
				? aioseo()->seoRevisions->helpers->prepareRevisionDataFieldValue( $key, $revisionFromData[ $key ], $this )
				: '';
			$contentTo   = aioseo()->seoRevisions->helpers->prepareRevisionDataFieldValue( $key, $revisionToData[ $key ], $this );

			$diff = aioseo()->seoRevisions->helpers->renderFieldDiff( $contentFrom, $contentTo, $key );

			// Keep an HTML with both contents in order to show raw data in case there are no differences (in Vue).
			$raw = '<table class="diff"><tbody><tr>';
			$raw .= '<td>' . esc_html( $contentFrom ) . '</td><td>' . esc_html( $contentTo ) . '</td>';
			$raw .= '</tr></tbody>';
			$raw .= '</table>';

			$fieldsDiff[] = [
				'key'   => $key,
				'label' => $label,
				'diff'  => $diff,
				'raw'   => $raw
			];
		}

		return $fieldsDiff;
	}

	/**
	 * Get the AIOSEO object related to this ObjectRevisions instance.
	 *
	 * @since 4.4.0
	 *
	 * @return CommonModels\Post|ProModels\Term|null The AIOSEO post/term object.
	 */
	public function getAioseoObject() {
		switch ( $this->objectType ) {
			case 'post':
				$aioseoObject = CommonModels\Post::getPost( $this->objectId );
				break;
			case 'term':
				$aioseoObject = ProModels\Term::getTerm( $this->objectId );
				break;
			default:
				$aioseoObject = null;
				break;
		}

		return $aioseoObject;
	}

	/**
	 * Get the WP object related to this ObjectRevisions instance.
	 *
	 * @since 4.6.7
	 *
	 * @return \WP_Post|\WP_Term|null The WP post/term object. Null for miscellaneous failure.
	 */
	public function getWpObject() {
		static $wpObject = [];

		switch ( $this->objectType ) {
			case 'post':
				$wpObject[ $this->objectId ] = ! empty( $wpObject[ $this->objectId ] ) ? $wpObject[ $this->objectId ] : get_post( $this->objectId );
				break;
			case 'term':
				$wpObject[ $this->objectId ] = ! empty( $wpObject[ $this->objectId ] ) ? $wpObject[ $this->objectId ] : aioseo()->helpers->getTerm( $this->objectId );
				break;
			default:
				$wpObject[ $this->objectId ] = null;
				break;
		}

		return $wpObject[ $this->objectId ];
	}

	/**
	 * Get the previous revision relative to a given revision ID.
	 *
	 * @since 4.4.0
	 *
	 * @param  int                   $revisionId The revision ID.
	 * @return ProModels\SeoRevision             The relative previous revision object.
	 */
	public function getPreviousRevision( $revisionId ) {
		return aioseo()->core->db
			->start( 'aioseo_revisions' )
			->where( 'object_id', $this->objectId )
			->where( 'object_type', $this->objectType )
			->whereRaw( "id < $revisionId" )
			->orderBy( $this->defaultOrderBy )
			->limit( 1 )
			->run()
			->model( 'AIOSEO\\Plugin\\Pro\\Models\\SeoRevision' );
	}

	/**
	 * Delete all or some revisions belonging to a post/term.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $args Array of options: 'limit'.
	 * @return int         The number of affected rows.
	 */
	public function deleteRevisions( $args = [] ) {
		$args = array_merge( [
			'limit' => 0
		], $args );

		aioseo()->core->db
			->delete( 'aioseo_revisions' )
			->where( 'object_id', absint( $this->objectId ) )
			->where( 'object_type', trim( $this->objectType ) )
			->orderBy( 'id ASC' )
			->limit( absint( $args['limit'] ) )
			->run();

		return aioseo()->core->db->rowsAffected();
	}

	/**
	 * Gets an ObjectRevisions instance from a revision ID.
	 *
	 * @since 4.4.0
	 *
	 * @param  int                   $revisionId The revision ID.
	 * @return ObjectRevisions|false             This class instance or false if the revision was not found.
	 */
	public static function getObjectRevisions( $revisionId ) {
		$revision = new ProModels\SeoRevision( absint( $revisionId ) );
		if ( ! $revision->exists() ) {
			return false;
		}

		return new self( $revision->object_id, $revision->object_type );
	}

	/**
	 * Check if revision data is different.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $oldData An array of data.
	 * @param  array $newData An array of data.
	 * @return bool           Is data different.
	 */
	private function isRevisionDataDifferent( $oldData, $newData ) {
		aioseo()->seoRevisions->helpers->reduceKeyphrases( $oldData );
		aioseo()->seoRevisions->helpers->reduceKeyphrases( $newData );

		aioseo()->seoRevisions->helpers->reduceToColumn( $oldData, 'og_article_tags', 'label' );
		aioseo()->seoRevisions->helpers->reduceToColumn( $newData, 'og_article_tags', 'label' );

		aioseo()->seoRevisions->helpers->reduceToColumn( $oldData, 'keywords', 'label' );
		aioseo()->seoRevisions->helpers->reduceToColumn( $newData, 'keywords', 'label' );

		aioseo()->seoRevisions->helpers->reduceSchema( $oldData );
		aioseo()->seoRevisions->helpers->reduceSchema( $newData );

		if ( ! empty( $oldData['primary_term'] ) && is_array( $oldData['primary_term'] ) ) {
			ksort( $oldData['primary_term'] );
		}

		if ( ! empty( $newData['primary_term'] ) && is_array( $newData['primary_term'] ) ) {
			ksort( $newData['primary_term'] );
		}

		ksort( $oldData );
		ksort( $newData );

		$oldDataHash = md5( wp_json_encode( $oldData ) );
		$newDataHash = md5( wp_json_encode( $newData ) );

		return $oldDataHash !== $newDataHash;
	}
}