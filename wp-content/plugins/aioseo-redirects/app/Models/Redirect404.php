<?php
namespace AIOSEO\Plugin\Addon\Redirects\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;
use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * The Redirects DB Model.
 *
 * @since 1.2.2
 */
class Redirect404 extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.2.2
	 *
	 * @var string
	 */
	protected $table = 'aioseo_redirects_404';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 1.2.2
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'post_id' ];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 1.2.2
	 *
	 * @var array
	 */
	protected $booleanFields = [];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 1.2.2
	 *
	 * @var array
	 */
	protected $appends = [];

	/**
	 * Fields that should be encoded/decoded on save/get.
	 *
	 * @since 1.2.2
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'parent_posts', 'parent_terms' ];

	/**
	 * Transforms needed data.
	 *
	 * @since 1.2.2
	 *
	 * @param  array $data The data array to transform.
	 * @return array       The transformed data.
	 */
	protected function transform( $data, $set = false ) {
		$data = parent::transform( $data, $set );

		// Create source hash.
		$data['source_url_hash'] = Utils\Request::getUrlHash( $data['source_url'] );

		return $data;
	}

	/**
	 * Lookup a redirect by url.
	 *
	 * @since 1.2.2
	 *
	 * @param  string      $url The redirect url.
	 * @return Redirect404      The redirect object.
	 */
	public static function getRedirectByUrl( $url ) {
		return aioseo()->core->db
			->start( 'aioseo_redirects_404' )
			->where( 'source_url_hash', Utils\Request::getUrlHash( $url ) )
			->run()
			->model( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect404' );
	}

	/**
	 * Lookup a redirect by post id.
	 *
	 * @since 1.2.2
	 *
	 * @param  int         $postId The post id.
	 * @return Redirect404         The redirect object.
	 */
	public static function getRedirectByPostId( $postId ) {
		return aioseo()->core->db
			->start( 'aioseo_redirects_404' )
			->where( 'post_id', $postId )
			->run()
			->model( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect404' );
	}
}