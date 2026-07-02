<?php

namespace AIOSEO\Plugin\Pro\Models\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Keyword Relationship DB Model.
 *
 * @since 4.7.0
 */
class KeywordRelationship extends CommonModels\Model {
	/**
	 * Database table name with no prefix.
	 *
	 * @since 4.7.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_search_statistics_keyword_relationships';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'keyword_id', 'keyword_group_id' ];

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
	 * Column: Keyword ID.
	 *
	 * @since 4.7.0
	 *
	 * @var int $keyword_id
	 */
	public $keyword_id;

	/**
	 * Column: Keyword Group ID.
	 *
	 * @since 4.7.0
	 *
	 * @var int $keyword_group_id
	 */
	public $keyword_group_id;

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
	 * Inserts multiple relationships into the database.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $keywords The keyword IDs.
	 * @param  array $groups   The group IDs.
	 * @return void
	 */
	public static function bulkInsert( $keywords, $groups ) {
		$addValues = [];
		foreach ( (array) $groups as $group ) {
			foreach ( (array) $keywords as $keyword ) {
				$addValues[] = vsprintf(
					"('%d', '%d')",
					[
						(int) $keyword,
						(int) $group,
					]
				);
			}
		}

		$addValues = implode( ',', $addValues );
		if ( ! $addValues ) {
			return;
		}

		$tableName = aioseo()->core->db->prefix . 'aioseo_search_statistics_keyword_relationships';

		aioseo()->core->db->execute(
			"INSERT IGNORE INTO $tableName ( `keyword_id`, `keyword_group_id` )
			 VALUES $addValues"
		);
	}

	/**
	 * Deletes all relationships containing the given keyword IDs.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $ids The keyword IDs.
	 * @return void
	 */
	public static function bulkDeleteByKeyword( $ids ) {
		$ids = array_unique( $ids );
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		$ids       = array_map( 'absint', $ids );
		$ids       = implode( ',', $ids );
		$tableName = aioseo()->core->db->prefix . 'aioseo_search_statistics_keyword_relationships';
		aioseo()->core->db->execute(
			"DELETE FROM `$tableName`
			WHERE `keyword_id` IN ( $ids )"
		);
	}

	/**
	 * Deletes all relationships containing the given keyword group IDs.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $ids The group IDs.
	 * @return void
	 */
	public static function bulkDeleteByGroup( $ids ) {
		$ids = array_unique( $ids );
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		$ids       = array_map( 'absint', $ids );
		$ids       = implode( ',', $ids );
		$tableName = aioseo()->core->db->prefix . 'aioseo_search_statistics_keyword_relationships';
		aioseo()->core->db->execute(
			"DELETE FROM `$tableName`
			WHERE `keyword_group_id` IN ( $ids )"
		);
	}

	/**
	 * Deletes a relationship by keyword and group.
	 *
	 * @since 4.7.0
	 *
	 * @param  int  $keyword The keyword ID.
	 * @param  int  $group   The group ID.
	 * @return void
	 */
	public static function deleteByRelationship( $keyword, $group ) {
		list( $keywordId, $groupId ) = array_map( 'absint', [ $keyword, $group ] );

		$tableName = aioseo()->core->db->prefix . 'aioseo_search_statistics_keyword_relationships';
		aioseo()->core->db->execute(
			"DELETE FROM `$tableName`
			WHERE `keyword_id` = $keywordId AND `keyword_group_id` = $groupId"
		);
	}
}