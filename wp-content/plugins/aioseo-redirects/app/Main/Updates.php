<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updater class.
 *
 * @since 1.0.0
 */
class Updates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'aioseo_run_updates', [ $this, 'runUpdates' ], 1000 );
		add_action( 'aioseo_run_updates', [ $this, 'updateLatestVersion' ], 3000 );
	}

	/**
	 * Runs our migrations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function runUpdates() {
		$lastActiveVersion = aioseoRedirects()->internalOptions->internal->lastActiveVersion;
		// Don't run updates if the last active version is the same as the current version.
		if ( aioseoRedirects()->version === $lastActiveVersion ) {
			return;
		}

		// Try to acquire the lock.
		if ( ! aioseo()->core->db->acquireLock( 'aioseo_redirects_run_updates_lock', 0 ) ) {
			// If we couldn't acquire the lock, exit early without doing anything.
			// This means another process is already running updates.
			return;
		}

		if ( version_compare( $lastActiveVersion, '1.0.0', '<' ) ) {
			$this->addRedirectsTables();
		}

		if ( version_compare( $lastActiveVersion, '1.1.0', '<' ) ) {
			$this->migrateRedirectDefaults();
			$this->addCustomRuleColumn();
			$this->allowNullTargetUrl();
		}

		if ( version_compare( $lastActiveVersion, '1.2.1', '<' ) ) {
			aioseoRedirects()->cache->clearRedirects();
		}

		if ( version_compare( $lastActiveVersion, '1.2.2', '<' ) ) {
			$this->addRedirects404Tables();
		}

		if ( version_compare( $lastActiveVersion, '1.2.3', '<' ) ) {
			$this->redirects404MigrateOptions();
		}

		if ( version_compare( $lastActiveVersion, '1.2.7', '<' ) ) {
			$this->addPostIdColumn();
		}

		if ( version_compare( $lastActiveVersion, '1.2.8', '<' ) ) {
			$this->fixDoubleEncodedSourceUrlMatch();
		}

		// Always clear the cache if the last active version is different from our current.
		// https://github.com/awesomemotive/aioseo/issues/2920
		if ( version_compare( $lastActiveVersion, AIOSEO_REDIRECTION_MANAGER_VERSION, '<' ) ) {
			aioseoRedirects()->cache->clear();
		}
	}

	/**
	 * Updates the latest version after all migrations and updates have run.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function updateLatestVersion() {
		if ( aioseoRedirects()->internalOptions->internal->lastActiveVersion === aioseoRedirects()->version ) {
			return;
		}

		aioseoRedirects()->internalOptions->internal->lastActiveVersion = aioseoRedirects()->version;

		// Let's empty the cache on version changes.
		aioseoRedirects()->cache->clear();

		// Bust the DB cache so we can make sure that everything is fresh.
		aioseo()->core->db->bustCache();
	}

	/**
	 * Adds a column for custom redirect matching rules.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function addCustomRuleColumn() {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		if ( ! aioseo()->core->db->columnExists( 'aioseo_redirects', 'custom_rules' ) ) {
			$tableName = aioseo()->core->db->db->prefix . 'aioseo_redirects';
			aioseo()->core->db->execute(
				"ALTER TABLE {$tableName}
				ADD custom_rules text DEFAULT NULL AFTER query_param"
			);
		}
	}

	/**
	 * Adds a column for custom redirect matching rules.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function allowNullTargetUrl() {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		$tableName = aioseo()->core->db->db->prefix . 'aioseo_redirects';
		aioseo()->core->db->execute(
			"ALTER TABLE {$tableName}
			MODIFY `target_url_hash` varchar(40) DEFAULT NULL"
		);
	}

	/**
	 * Add MySQL tables for redirect 404s.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	private function addRedirects404Tables() {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		$db             = aioseo()->core->db->db;
		$charsetCollate = '';

		if ( ! empty( $db->charset ) ) {
			$charsetCollate .= "DEFAULT CHARACTER SET {$db->charset}";
		}
		if ( ! empty( $db->collate ) ) {
			$charsetCollate .= " COLLATE {$db->collate}";
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_redirects_404' ) ) {
			$tableName = $db->prefix . 'aioseo_redirects_404';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`post_id` bigint(20) unsigned DEFAULT NULL,
					`post_type` varchar(256) DEFAULT NULL,
					`taxonomy` varchar(256) DEFAULT NULL,
					`source_url` text,
					`source_url_hash` varchar(40) NOT NULL,
					`parent_posts` text,
					`parent_terms` text,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY ndx_aioseo_redirects_404_source_url_hash (source_url_hash)
				) {$charsetCollate};"
			);
		}
	}

	/**
	 * Add MySQL tables for redirects.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function addRedirectsTables() {
		// This update requires V4 to be active and since this could run on an activation hook, we need an extra sanity check.
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		$db             = aioseo()->core->db->db;
		$charsetCollate = '';

		if ( ! empty( $db->charset ) ) {
			$charsetCollate .= "DEFAULT CHARACTER SET {$db->charset}";
		}
		if ( ! empty( $db->collate ) ) {
			$charsetCollate .= " COLLATE {$db->collate}";
		}

		// Check for redirects table.
		if ( ! aioseo()->core->db->tableExists( 'aioseo_redirects' ) ) {
			$tableName = $db->prefix . 'aioseo_redirects';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`source_url` text NOT NULL,
					`source_url_hash` varchar(40) NOT NULL,
					`source_url_match` text NOT NULL,
					`source_url_match_hash` varchar(40) NOT NULL,
					`target_url` text NOT NULL,
					`target_url_hash` varchar(40) NOT NULL,
					`type` int(11) unsigned NOT NULL DEFAULT 301,
					`query_param` varchar(40) NOT NULL DEFAULT 'ignore',
					`group` varchar(256) NOT NULL DEFAULT 'manual',
					`regex` tinyint(1) unsigned NOT NULL DEFAULT 0,
					`ignore_slash` tinyint(1) unsigned NOT NULL DEFAULT 1,
					`ignore_case` tinyint(1) unsigned NOT NULL DEFAULT 1,
					`enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY ndx_aioseo_redirects_source_url_hash (source_url_hash),
					KEY ndx_aioseo_redirects_source_url_match_hash (source_url_match_hash),
					KEY ndx_aioseo_redirects_target_url_hash (target_url_hash),
					KEY ndx_aioseo_redirects_type (type),
					KEY ndx_aioseo_redirects_enabled (enabled)
				) {$charsetCollate};"
			);
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_redirects_hits' ) ) {
			$tableName = $db->prefix . 'aioseo_redirects_hits';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`redirect_id` bigint(20) unsigned NOT NULL,
					`count` bigint(20) unsigned NOT NULL DEFAULT 0,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY ndx_aioseo_redirects_hits_redirect_id (redirect_id),
					KEY ndx_aioseo_redirects_hits_count (count)
				) {$charsetCollate};"
			);
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_redirects_logs' ) ) {
			$tableName = $db->prefix . 'aioseo_redirects_logs';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`url` mediumtext NOT NULL,
					`domain` varchar(255) DEFAULT NULL,
					`sent_to` mediumtext DEFAULT NULL,
					`agent` mediumtext,
					`referrer` mediumtext DEFAULT NULL,
					`http_code` int(11) unsigned NOT NULL DEFAULT 0,
					`request_method` varchar(10) DEFAULT NULL,
					`request_data` mediumtext DEFAULT NULL,
					`redirect_by` varchar(50) DEFAULT NULL,
					`redirect_id` bigint(20) unsigned DEFAULT NULL,
					`ip` varchar(45) DEFAULT NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (`id`),
					KEY ndx_aioseo_redirects_logs_created (`created`),
					KEY ndx_aioseo_redirects_logs_redirect_id (`redirect_id`),
					KEY ndx_aioseo_redirects_logs_ip (`ip`)
				) {$charsetCollate};"
			);
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_redirects_404_logs' ) ) {
			$tableName = $db->prefix . 'aioseo_redirects_404_logs';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`url` mediumtext NOT NULL,
					`domain` varchar(255) DEFAULT NULL,
					`agent` mediumtext,
					`referrer` mediumtext DEFAULT NULL,
					`http_code` int(11) unsigned NOT NULL DEFAULT 0,
					`request_method` varchar(10) DEFAULT NULL,
					`request_data` mediumtext DEFAULT NULL,
					`ip` varchar(45) DEFAULT NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (`id`),
					KEY ndx_aioseo_redirects_404_logs_created (`created`),
					KEY ndx_aioseo_redirects_404_logs_ip (`ip`)
				) {$charsetCollate};"
			);
		}
	}

	/**
	 * Migrate redirect default options.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function migrateRedirectDefaults() {
		$dbOptions = json_decode( get_option( aioseoRedirects()->options->optionsName ), true );
		if ( empty( $dbOptions['defaults'] ) ) {
			return;
		}

		if ( isset( $dbOptions['defaults']['ignoreCase'] ) ) {
			aioseoRedirects()->options->redirectDefaults->ignoreCase = $dbOptions['defaults']['ignoreCase'];
		}

		if ( isset( $dbOptions['defaults']['ignoreSlash'] ) ) {
			aioseoRedirects()->options->redirectDefaults->ignoreSlash = $dbOptions['defaults']['ignoreSlash'];
		}

		if ( isset( $dbOptions['defaults']['redirectType'] ) ) {
			aioseoRedirects()->options->redirectDefaults->redirectType = $dbOptions['defaults']['redirectType'];
		}

		if ( isset( $dbOptions['defaults']['queryParam'] ) ) {
			aioseoRedirects()->options->redirectDefaults->queryParam = $dbOptions['defaults']['queryParam'];
		}

		return;
	}

	/**
	 * Migrate new options for advanced 404s.
	 *
	 * @since 1.2.3
	 *
	 * @return void
	 */
	private function redirects404MigrateOptions() {
		// Enable 404 redirects if it was already on.
		$oldOptions = aioseoRedirects()->options->advanced404s->all();
		if (
			! empty( $oldOptions['redirectToHome'] ) ||
			! empty( $oldOptions['redirectToParent'] )
		) {
			aioseoRedirects()->options->advanced404s->enabled = true;

			// Enable 404 default redirects if it was active for Home.
			if ( ! empty( $oldOptions['redirectToHome'] ) ) {
				aioseoRedirects()->options->advanced404s->redirectDefaultEnabled = true;
			}

			aioseoRedirects()->options->save( true );
		}
	}

	/**
	 * Adds a column for custom redirect matching rules.
	 *
	 * @since 1.2.7
	 *
	 * @return void
	 */
	private function addPostIdColumn() {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		if ( ! aioseo()->core->db->columnExists( 'aioseo_redirects', 'post_id' ) ) {
			$tableName = aioseo()->core->db->db->prefix . 'aioseo_redirects';
			aioseo()->core->db->execute(
				"ALTER TABLE {$tableName}
				ADD post_id bigint unsigned DEFAULT NULL AFTER id,
				ADD INDEX ndx_aioseo_redirects_post_id (post_id)"
			);
		}
	}

	/**
	 * Fixes source url matches that got double encoded.
	 *
	 * @since 1.2.8
	 *
	 * @return void
	 */
	private function fixDoubleEncodedSourceUrlMatch() {
		$redirects = aioseo()->core->db->start( 'aioseo_redirects' )
			->whereRaw( 'source_url_match REGEXP "%[0-9a-f]{2}"' )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\Redirects\\Models\\Redirect' );

		foreach ( $redirects as $redirect ) {
			// This will trigger the model's transform which will regenerate source_url_match and hash.
			$redirect->save();
		}
	}
}