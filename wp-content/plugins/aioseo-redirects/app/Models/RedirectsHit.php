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
class RedirectsHit extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_redirects_hits';

	/**
	 * Fields that should be hidden when serialized.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Get a redirect hit.
	 *
	 * @since 1.2.1
	 *
	 * @param  int          $redirectId The redirect id.
	 * @return RedirectsHit             A model of the redirect hit.
	 */
	public static function getRedirectHit( $redirectId ) {
		$hit = aioseo()->core->db->start( 'aioseo_redirects_hits' )
			->where( 'redirect_id', $redirectId )
			->run()
			->model( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\RedirectsHit' );

		if ( ! $hit->exists() ) {
			$hit->redirect_id = $redirectId;
			$hit->count       = 0;
		}

		return $hit;
	}
}