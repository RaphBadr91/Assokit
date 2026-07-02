<?php

namespace AIOSEO\Plugin\Pro\Models\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Keyword DB Model.
 *
 * @since 4.7.0
 */
class Keyword extends CommonModels\Model {
	/**
	 * Database table name with no prefix.
	 *
	 * @since 4.7.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_search_statistics_keywords';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id' ];

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	protected $jsonFields = [];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	protected $booleanFields = [ 'favorited' ];

	/**
	 * Fields that should be hidden when serialized.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Column: Name.
	 *
	 * @since 4.7.0
	 *
	 * @var string $name
	 */
	public $name;

	/**
	 * Column: Favorited.
	 *
	 * @since 4.7.0
	 *
	 * @var int $favorited
	 */
	public $favorited;

	/**
	 * Class constructor.
	 *
	 * @since 4.7.0
	 *
	 * @param  mixed $var This can be the primary key of the resource, or it could be an array of data to manufacture a resource without a database query.
	 * @return void
	 */
	public function __construct( $var = null ) {
		parent::__construct( $var );
	}

	/**
	 * Gets the keywords from the database.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $args The arguments.
	 * @return array       The keywords.
	 */
	public static function getKeywords( $args = [] ) {
		$args = array_merge( [
			'ids'               => [],
			'names'             => [],
			'filter'            => 'all',
			'searchTerm'        => '',
			'additionalFilters' => [],
		], array_filter( $args ) );

		$names       = array_map( [ aioseo()->helpers, 'toLowerCase' ], array_unique( $args['names'] ) );
		$filter      = $args['filter'];
		$searchTerm  = esc_sql( sanitize_text_field( $args['searchTerm'] ) );
		$chosenGroup = $args['additionalFilters']['group'] ?? 'all';

		$query = aioseo()->core->db->start( 'aioseo_search_statistics_keywords as k' )
									->select( 'k.id, k.name, k.favorited, k.created, GROUP_CONCAT(g.id) as groupIds, GROUP_CONCAT(g.name) as groupNames' )
									->join( 'aioseo_search_statistics_keyword_relationships as r', 'k.id = r.keyword_id', 'LEFT' )
									->join( 'aioseo_search_statistics_keyword_groups as g', 'r.keyword_group_id = g.id', 'LEFT' )
									->groupBy( 'k.id' )
									->limit( 100 );

		if ( ! empty( $args['ids'] ) ) {
			$query->whereIn( 'k.id', $args['ids'] );
		}

		if ( ! empty( $names ) ) {
			$query->whereIn( 'k.name', $names );
		}

		if ( 'favorited' === $filter ) {
			$query->where( 'k.favorited', 1 );
		}

		if ( $searchTerm ) {
			$query->whereRaw( 'k.name LIKE \'%' . $searchTerm . '%\'' );
		}

		if ( 'all' !== $chosenGroup ) {
			$query->where( 'g.id', $chosenGroup );
		}

		$rows = $query->run()->result();
		foreach ( $rows as $row ) {
			$row->groups = [];
			if ( ! empty( $row->groupIds ) && ! empty( $row->groupNames ) ) {
				// The groupIds and groupNames are comma separated strings, because of the GROUP_CONCAT function above.
				$groupIds   = explode( ',', $row->groupIds );
				$groupNames = explode( ',', $row->groupNames );

				foreach ( $groupIds as $key => $groupId ) {
					$data = (object) [
						'id'    => $groupId,
						'name'  => $groupNames[ $key ],
						'value' => $groupId, // Make it easier to use Vue core components in the UI.
						'label' => $groupNames[ $key ], // Make it easier to use Vue core components in the UI.
					];

					KeywordGroup::parseGroup( $data );

					$row->groups[] = json_decode( wp_json_encode( $data ), true );
				}
			}

			unset( $row->groupIds, $row->groupNames );
		}

		return [
			'rows' => array_values( $rows ),
		];
	}

	/**
	 * Inserts multiple keywords into the database.
	 *
	 * @since 4.7.0
	 *
	 * @param  array[]     $keywords The keywords to insert.
	 * @throws \Exception
	 * @return array|false           The newly inserted keywords. False if no keywords were inserted.
	 */
	public static function bulkInsert( $keywords ) {
		$limit        = aioseo()->searchStatistics->keywordRankTracker->getLicenseKeywordsLimit();
		$currentCount = aioseo()->core->db->start( 'aioseo_search_statistics_keywords' )->count() + count( $keywords );

		if ( $currentCount > $limit ) {
			throw new \Exception(
				// Translators: 1 - The limit of keywords allowed.
				sprintf( esc_html__( 'You have reached the maximum number of %s keywords allowed.', 'aioseo-pro' ), esc_html( $limit ) )
			);
		}

		$currentDate = gmdate( 'Y-m-d H:i:s' );

		$addValues = [];
		foreach ( $keywords as $keyword ) {
			$name = aioseo()->helpers->toLowerCase( sanitize_text_field( $keyword['name'] ) );
			if ( ! empty( $name ) ) {
				$addValues[] = aioseo()->core->db->db->prepare(
					"('%s', '%d', '$currentDate', '$currentDate')",
					$name,
					! empty( $keyword['favorited'] ) ? 1 : 0
				);
			}
		}

		$addValues = implode( ',', $addValues );
		if ( empty( $addValues ) ) {
			return false;
		}

		$tableName = aioseo()->core->db->prefix . 'aioseo_search_statistics_keywords';

		aioseo()->core->db->execute(
			"INSERT INTO $tableName ( `name`, `favorited`, `created`, `updated` )
			 VALUES $addValues
		   	 ON DUPLICATE KEY UPDATE `updated` = VALUES(`updated`)"
		);

		return aioseo()->core->db->start( $tableName, true )
								->select( 'id' )
								->where( 'created', $currentDate )
								->run()
								->result();
	}

	/**
	 * Bulk update keywords.
	 *
	 * @since 4.7.1
	 *
	 * @param  array $ids The keyword IDs.
	 * @param  array $set The values to update.
	 * @return void
	 */
	public static function bulkUpdate( $ids, $set ) {
		aioseo()->core->db
			->update( 'aioseo_search_statistics_keywords' )
			->whereIn( 'id', $ids )
			->set( $set )
			->run();
	}

	/**
	 * Bulk delete keywords.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $ids The keyword IDs.
	 * @return int        The number of rows affected.
	 */
	public static function bulkDelete( $ids ) {
		// Delete the keywords.
		aioseo()->core->db
			->delete( 'aioseo_search_statistics_keywords' )
			->whereIn( 'id', $ids )
			->run();

		$rowsAffected = max( absint( aioseo()->core->db->rowsAffected() ), 0 );

		// Delete the keyword relationships.
		KeywordRelationship::bulkDeleteByKeyword( $ids );

		return $rowsAffected;
	}

	/**
	 * Updates the "favorited" field.
	 * Abstract the process of favoriting to allow for the keyword to be added to our reserved group.
	 *
	 * @since 4.7.0
	 *
	 * @param  boolean $value The value to update the "favorited" field to.
	 * @return void
	 */
	public function updateFavorited( $value ) {
		$this->favorited = $value;
		$this->save();

		$favoriteGroup = KeywordGroup::getFavoriteGroup();
		$groupRow      = current( KeywordGroup::getByNames( [ $favoriteGroup['slug'] ] ) );
		if ( empty( $groupRow ) ) {
			$groupRow       = new KeywordGroup();
			$groupRow->name = $favoriteGroup['slug'];
			$groupRow->save();
		}

		if ( $value ) {
			KeywordRelationship::bulkInsert( [ $this->id ], [ $groupRow->id ] );
		} else {
			KeywordRelationship::deleteByRelationship( $this->id, $groupRow->id );
		}
	}

	/**
	 * Parses a keyword row/object.
	 *
	 * @since 4.7.0
	 *
	 * @param  object $row The row to format.
	 * @return void
	 */
	public static function parseKeyword( $row ) {
		$row->id         = intval( $row->id );
		$row->favorited  = (bool) $row->favorited;
		$row->groups     = array_values( $row->groups ?? [] );
		$row->statistics = null;
		$row->value      = $row->id; // Make it easier to use Vue core components in the UI.
		$row->label      = $row->name; // Make it easier to use Vue core components in the UI.
		$row->tracking   = true; // Used to determine if the keyword is being tracked under the "Edit Post" page.
	}
}