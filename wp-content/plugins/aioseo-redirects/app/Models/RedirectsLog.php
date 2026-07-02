<?php
namespace AIOSEO\Plugin\Addon\Redirects\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Redirects DB Model.
 *
 * @since 1.0.0
 */
class RedirectsLog extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_redirects_logs';

	/**
	 * Fields that should be hidden when serialized.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'request_data' ];

	/**
	 * Return filtered logs.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $args Arguments for the function.
	 * @return array       The DB results.
	 */
	public static function getFiltered( $args = [] ) {
		$args = wp_parse_args( $args, [
			'limit'    => aioseo()->settings->tablePagination['redirectLogs'],
			'offset'   => 0,
			'orderBy'  => 'last_accessed',
			'orderDir' => 'DESC',
			'search'   => '',
			'return'   => ''
		] );

		$logs = aioseo()->core->db->start( 'aioseo_redirects_logs as `l1`' )
			->select( 'l1.id, l1.url, l2.hits, l1.created as last_accessed, l1.request_data, l1.ip, l1.redirect_id' )
			->join(
				'(SELECT MAX(id) as id, count(*) as hits FROM ' . aioseo()->core->db->db->prefix . 'aioseo_redirects_logs GROUP BY `url`) as `l2`',
				'`l2`.`id` = `l1`.`id`',
				'',
				true
			)
			->orderBy( $args['orderBy'] . ' ' . $args['orderDir'] )
			->limit( $args['limit'], $args['offset'] );

		if ( ! empty( $args['search'] ) ) {
			$logs->whereRaw( $args['search'] );
		}

		$logResults = $logs->run()->result();
		if ( ! empty( $logResults ) ) {
			foreach ( $logResults as &$log ) {
				$log->referrers = aioseo()->core->db->start( 'aioseo_redirects_logs' )
					->select( 'referrer' )
					->where( 'redirect_id', $log->redirect_id )
					->where( 'referrer !=', '' )
					->groupBy( 'referrer' )
					->run( true, 'col' )
					->result();
			}
		}

		return $logResults;
	}

	/**
	 * Return log totals.
	 *
	 * @since 1.4.0
	 *
	 * @param  string $search The search string.
	 * @return int            The total count.
	 */
	public static function getTotals( $search = '' ) {
		$query = aioseo()->core->db->start( 'aioseo_redirects_logs as `l1`' )
			->join(
				'(SELECT MAX(id) as id, count(*) as hits FROM ' . aioseo()->core->db->db->prefix . 'aioseo_redirects_logs GROUP BY `url`) as `l2`',
				'`l2`.`id` = `l1`.`id`',
				'',
				true
			);

		if ( ! empty( $search ) ) {
			$query->whereRaw( $search );
		}

		return $query->countDistinct( 'url' );
	}
}