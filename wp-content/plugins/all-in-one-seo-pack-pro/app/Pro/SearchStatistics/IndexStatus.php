<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models\SearchStatistics as SearchStatisticsModels;
use AIOSEO\Plugin\Common\SearchStatistics as CommonSearchStatistics;

/**
 * Index Status class.
 *
 * @since 4.8.2
 */
class IndexStatus extends CommonSearchStatistics\IndexStatus {
	/**
	 * The action hook to execute when the event is run.
	 *
	 * @since 4.8.2
	 *
	 * @var string
	 */
	private $actionHook = 'aioseo_search_statistics_fetch_object_index_status';

	/**
	 * Class constructor.
	 *
	 * @since 4.8.2
	 */
	public function __construct() {
		// No need to run any of this during a WP AJAX request or REST API request.
		if ( wp_doing_ajax() || aioseo()->helpers->isRestApiRequest() ) {
			return;
		}

		add_action( $this->actionHook, [ $this, 'cronTrigger' ] );

		// No need to keep trying scheduling unless on admin.
		add_action( 'admin_init', [ $this, 'maybeSchedule' ], 20 );
	}

	/**
	 * The index status cron callback.
	 * Hooked into `{@see self::$actionHook}` action hook.
	 *
	 * @since 4.8.2
	 *
	 * @return void
	 */
	public function cronTrigger() {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'index-status' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return;
		}

		$quotaKey = 'search_statistics_inspection_results_cron_quota';
		$quota    = intval( aioseo()->core->cache->get( $quotaKey ) ?? 1600 );
		if ( 1 > $quota ) {
			return;
		}

		$results                 = aioseo()->searchStatistics->urlInspection->fetchInspectionResults();
		$countPathsWithoutResult = count( $results['pathsWithoutResult'] );
		if (
			empty( $results['error'] ) &&
			$countPathsWithoutResult > 0
		) {
			aioseo()->core->cache->update( $quotaKey, max( 0, $quota - $countPathsWithoutResult ), aioseo()->searchStatistics->helpers->getNext8Am() - time() );
		}

		if ( empty( $results['pathsWithoutResult'] ) ) {
			// If all found paths have results, schedule the next run for an hour from now.
			aioseo()->actionScheduler->scheduleSingle( $this->actionHook, HOUR_IN_SECONDS, [], true );
		} else {
			// If paths without results were found, schedule the next run for 60 seconds from now.
			aioseo()->actionScheduler->scheduleSingle( $this->actionHook, 60, [], true );
		}
	}

	/**
	 * Maybe schedule fetching the index status data.
	 *
	 * @since 4.8.2
	 *
	 * @return void
	 */
	public function maybeSchedule() {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'index-status' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return;
		}

		aioseo()->actionScheduler->scheduleSingle( $this->actionHook, 10 );
	}

	/**
	 * Retrieves the overview.
	 *
	 * @since 4.8.2
	 *
	 * @return array The overview.
	 */
	public function getOverview() {
		$aioTable            = aioseo()->core->db->db->prefix . 'aioseo_search_statistics_objects';
		$wpTable             = aioseo()->core->db->db->prefix . 'posts';
		$objectSubtypesArray = aioseo()->helpers->getPublicPostTypes( true );
		$placeholders        = implode( ',', array_fill( 0, count( $objectSubtypesArray ), '%s' ) );

		// This query needs to match the one on {@see SearchStatisticsModels\WpObject::getObjects()}.
		$results = aioseo()->core->db->output( 'ARRAY_A' )
									->execute( aioseo()->core->db->db->prepare(
										"SELECT COUNT(*) as count, aio.coverage_state as coverageState
									    FROM $aioTable as aio
									    INNER JOIN $wpTable as wp ON aio.object_id = wp.ID
									    WHERE aio.object_type = 'post'
									    AND aio.object_subtype IN ($placeholders)
									    GROUP BY coverageState
									    ORDER BY count DESC",
										...$objectSubtypesArray
									), true )
									->result();

		return [
			'post' => [
				'results' => array_map( function ( $v ) {
					return empty( $v['coverageState'] ) ? [
						'count'         => $v['count'],
						'coverageState' => 'empty', // This value works as a slug for Vue.
					] : $v;
				}, $results ),
				'total'   => array_sum( array_column( $results, 'count' ) ),
			]
		];
	}

	/**
	 * Retrieves all the objects, formatted.
	 *
	 * @since 4.8.2
	 *
	 * @param  array $args The arguments.
	 * @return array       The formatted objects.
	 */
	public function getFormattedObjects( $args = [] ) {
		static $staticOutput = [];

		$staticKey = aioseo()->helpers->createHash( $args );
		if ( isset( $staticOutput[ $staticKey ] ) ) {
			return $staticOutput[ $staticKey ];
		}

		$objects = SearchStatisticsModels\WpObject::getObjects( $args );
		foreach ( $objects['rows'] as &$row ) {
			$row = SearchStatisticsModels\WpObject::parseObject( $row );
		}

		$staticOutput[ $staticKey ] = [
			'paginated' => [
				'rows'   => $objects['rows'],
				'totals' => $objects['totals'],
			]
		];

		return $staticOutput[ $staticKey ];
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.8.2
	 *
	 * @return array The data for Vue.
	 */
	public function getVueData() {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'index-status' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return [
				'objects'  => parent::getFormattedObjects(),
				'overview' => parent::getOverview(),
				'options'  => parent::getUiOptions()
			];
		}

		// For connected sites we'll fetch the data through Vue/AJAX.
		return [
			'options' => $this->getUiOptions(),
		];
	}

	/**
	 * Retrieves options ideally only for Vue usage on the front-end.
	 *
	 * @since 4.8.2
	 *
	 * @return array The options.
	 */
	protected function getUiOptions() {
		$objects = aioseo()->core->db->start( 'aioseo_search_statistics_objects as aio' )
									->select( 'DISTINCT aio.object_subtype' )
									->where( 'aio.object_type', 'post' )
									->run()
									->result();

		$postTypeOptions = [
			[
				'label' => __( 'All Post Types', 'aioseo-pro' ),
				'value' => ''
			]
		];

		foreach ( $objects as $object ) {
			$objectPostType = get_post_type_object( $object->object_subtype ?? '' );
			if ( ! is_object( $objectPostType ) ) {
				continue;
			}

			$postTypeOptions[] = [
				'label' => $objectPostType->labels->singular_name,
				'value' => $objectPostType->name
			];
		}

		$parentUiOptions = parent::getUiOptions();

		$additionalFilters = array_merge( $parentUiOptions['table']['additionalFilters'], [
			'postTypeOptions' => [
				'name'    => 'postType',
				'options' => $postTypeOptions
			]
		] );

		return [
			'table' => [
				'additionalFilters' => $additionalFilters
			]
		];
	}
}