<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Api as CommonApi;

/**
 * Route class for the API.
 *
 * @since 4.0.0
 */
class Notifications extends CommonApi\Notifications {
	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function localBusinessOrganizationReminder() {
		return self::reminder( 'local-business-organization' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function newsPublicationNameReminder() {
		return self::reminder( 'news-publication-name' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function migrationLocalBusinessNumberReminder() {
		return self::reminder( 'v3-migration-local-business-number' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function migrationLocalBusinessCountryReminder() {
		return self::reminder( 'v3-migration-local-business-country' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function importLocalBusinessCountryReminder() {
		return self::reminder( 'import-local-business-country' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function importLocalBusinessTypeReminder() {
		return self::reminder( 'import-local-business-type' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function importLocalBusinessNumberReminder() {
		return self::reminder( 'import-local-business-number' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function importLocalBusinessFaxReminder() {
		return self::reminder( 'import-local-business-fax' );
	}

	/**
	 * Extend the start date of a notice.
	 *
	 * @since 4.0.0
	 *
	 * @return \WP_REST_Response The response.
	 */
	public static function importLocalBusinessCurrenciesReminder() {
		return self::reminder( 'import-local-business-currencies' );
	}
}