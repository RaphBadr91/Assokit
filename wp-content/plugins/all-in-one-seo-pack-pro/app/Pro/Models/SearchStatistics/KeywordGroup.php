<?php

namespace AIOSEO\Plugin\Pro\Models\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Keyword Group DB Model.
 *
 * @since 4.7.0
 */
class KeywordGroup extends CommonModels\Model {
	/**
	 * Database table name with no prefix.
	 *
	 * @since 4.7.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_search_statistics_keyword_groups';

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
	protected $booleanFields = [];

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
	 * Transforms data as needed.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $data The data array to transform.
	 * @return array       The transformed data.
	 */
	protected function transform( $data, $set = false ) {
		$data = parent::transform( $data, $set );

		foreach ( $this->getColumns() as $column => $null ) {
			if ( 'id' === $column ) {
				continue;
			}

			if ( isset( $data[ $column ] ) ) {
				$data[ $column ] = $this->sanitize( $column, $data[ $column ] );
			}
		}

		return $data;
	}

	/**
	 * Gets the keyword groups from the database.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $args The arguments.
	 * @return array       The keyword groups.
	 */
	public static function getGroups( $args = [] ) {
		$args = array_merge( [
			'ids'               => [],
			'filter'            => 'all',
			'searchTerm'        => '',
			'additionalFilters' => [],
		], array_filter( $args ) );

		$searchTerm = esc_sql( sanitize_text_field( $args['searchTerm'] ) );

		$query = aioseo()->core->db->start( 'aioseo_search_statistics_keyword_groups as g' )
									->select( 'g.id, g.name, g.created, GROUP_CONCAT(k.id) as keywordIds, GROUP_CONCAT(k.name) as keywordNames' )
									->join( 'aioseo_search_statistics_keyword_relationships as r', 'g.id = r.keyword_group_id', 'LEFT' )
									->join( 'aioseo_search_statistics_keywords as k', 'r.keyword_id = k.id', 'LEFT' )
									->groupBy( 'g.id' )
									->limit( 100 );

		if ( ! empty( $args['ids'] ) ) {
			$query->whereIn( 'g.id', $args['ids'] );
		}

		if ( $searchTerm ) {
			$query->whereRaw( 'g.name LIKE \'%' . $searchTerm . '%\'' );
		}

		$rows = $query->run()->result();
		foreach ( $rows as $row ) {
			$row->keywords = [];
			if ( ! empty( $row->keywordIds ) && ! empty( $row->keywordNames ) ) {
				// The keywordIds and keywordNames are comma separated strings, because of the GROUP_CONCAT function above.
				$keywordIds   = explode( ',', $row->keywordIds );
				$keywordNames = explode( ',', $row->keywordNames );

				foreach ( $keywordIds as $key => $keywordId ) {
					$row->keywords[] = [
						'id'   => $keywordId,
						'name' => $keywordNames[ $key ],
					];
				}
			}

			$row->keywordsQty = count( $row->keywords );

			unset( $row->keywordIds, $row->keywordNames );
		}

		return [
			'rows' => array_values( $rows ),
		];
	}

	/**
	 * Gets the keyword groups from the database.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $names The names of the keyword groups.
	 * @return array        An array of keyword group models.
	 */
	public static function getByNames( $names ) {
		return aioseo()->core->db
			->start( 'aioseo_search_statistics_keyword_groups' )
			->whereRaw( 'name IN (\'' . implode( '\', \'', array_map( 'esc_sql', $names ) ) . '\')' )
			->run()
			->models( __CLASS__ );
	}

	/**
	 * Bulk delete keyword groups.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $ids The keyword groups IDs.
	 * @return int        The number of rows affected.
	 */
	public static function bulkDelete( $ids ) {
		// Delete the keyword groups.
		aioseo()->core->db
			->delete( 'aioseo_search_statistics_keyword_groups' )
			->whereIn( 'id', $ids )
			->run();

		$rowsAffected = max( absint( aioseo()->core->db->rowsAffected() ), 0 );

		// Delete the keyword relationships.
		KeywordRelationship::bulkDeleteByGroup( $ids );

		return $rowsAffected;
	}

	/**
	 * Parses a keyword group row/object.
	 *
	 * @since 4.7.0
	 *
	 * @param  object $row The row to format.
	 * @return void
	 */
	public static function parseGroup( $row ) {
		$favoriteGroup = self::getFavoriteGroup();
		if ( $favoriteGroup['slug'] === $row->name ) {
			$row->name = $favoriteGroup['label'];
		}

		$row->id         = intval( $row->id );
		$row->statistics = null;
		$row->value      = $row->id; // Make it easier to use Vue core components in the UI.
		$row->label      = $row->name; // Make it easier to use Vue core components in the UI.
	}

	/**
	 * Retrieves the reserved favorite group data.
	 *
	 * @since 4.7.1
	 *
	 * @return array The reserved favorite group data.
	 */
	public static function getFavoriteGroup() {
		return [
			'label' => __( 'Favorited', 'aioseo-pro' ),
			'slug'  => 'favorited', // Not translatable to serve as a unique identifier.
		];
	}

	/**
	 * Sanitize Model field value.
	 *
	 * @since 4.7.0
	 *
	 * @param  string $field Which field to sanitize.
	 * @param  mixed  $value The value to be sanitized.
	 * @return mixed         The sanitized value.
	 */
	public function sanitize( $field, $value ) {
		switch ( $field ) {
			case 'name':
				$value = sanitize_text_field( $value );
				$value = aioseo()->helpers->truncate( $value, aioseo()->searchStatistics->keywordRankTracker->options['input']['group']['maxlength'], false );
				$value = $value ?: null;
				break;
			default:
				break;
		}

		return $value;
	}
}