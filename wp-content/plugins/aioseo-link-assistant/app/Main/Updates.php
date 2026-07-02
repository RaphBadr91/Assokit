<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles migrations after an update.
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
		$lastActiveVersion = aioseoLinkAssistant()->internalOptions->internal->lastActiveVersion;

		// Don't run updates if the last active version is the same as the current version.
		if ( aioseoLinkAssistant()->version === $lastActiveVersion ) {
			return;
		}

		// Try to acquire the lock.
		if ( ! aioseo()->core->db->acquireLock( 'aioseo_link_assistant_run_updates_lock', 0 ) ) {
			// If we couldn't acquire the lock, exit early without doing anything.
			// This means another process is already running updates.
			return;
		}

		if ( version_compare( $lastActiveVersion, '1.0.0', '<' ) ) {
			$this->addInitialTables();
			$this->addInitialColumns();

			// Ensure user has the right capabilities.
			aioseo()->access->addCapabilities();

			// Set the initial minimum scan dates.
			aioseoLinkAssistant()->internalOptions->internal->minimumLinkScanDate       = date( 'Y-m-d H:i:s', time() );
			aioseoLinkAssistant()->internalOptions->internal->minimumSuggestionScanDate = date( 'Y-m-d H:i:s', time() );
		}

		if ( version_compare( $lastActiveVersion, '1.0.1', '<' ) ) {
			// Needs to be reset so that internal links on "www" sites are correctly indexed.
			aioseoLinkAssistant()->internalOptions->internal->minimumLinkScanDate = date( 'Y-m-d H:i:s', time() );
		}

		if ( version_compare( $lastActiveVersion, '1.0.3', '<' ) ) {
			$this->removePostRelationshipsTable();
		}

		// Always clear the cache if the last active version is different from our current.
		// https://github.com/awesomemotive/aioseo/issues/2920
		if ( version_compare( $lastActiveVersion, AIOSEO_LINK_ASSISTANT_VERSION, '<' ) ) {
			aioseoLinkAssistant()->cache->clear();
		}

		if ( version_compare( $lastActiveVersion, '1.1.0', '<' ) ) {
			$this->addAdditionalIndexes();
		}

		if ( version_compare( $lastActiveVersion, '1.1.10', '<' ) ) {
			$this->changeParagraphColumnType();
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
		if ( aioseoLinkAssistant()->internalOptions->internal->lastActiveVersion === aioseoLinkAssistant()->version ) {
			return;
		}

		aioseoLinkAssistant()->internalOptions->internal->lastActiveVersion = aioseoLinkAssistant()->version;

		// Bust the DB cache so we can make sure that everything is fresh.
		aioseo()->core->db->bustCache();
	}

	/**
	 * Creates our custom tables.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function addInitialTables() {
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

		if ( ! aioseo()->core->db->tableExists( 'aioseo_links' ) ) {
			$tableName = $db->prefix . 'aioseo_links';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`post_id` bigint(20) unsigned NOT NULL,
					`linked_post_id` bigint(20) unsigned DEFAULT NULL,
					`internal` tinyint(1) DEFAULT 0 NOT NULL,
					`external` tinyint(1) DEFAULT 0 NOT NULL,
					`affiliate` tinyint(1) DEFAULT 0 NOT NULL,
					`url` text NOT NULL,
					`hostname` text NOT NULL,
					`anchor` text NOT NULL,
					`phrase` text NOT NULL,
					`phrase_html` text NOT NULL,
					`paragraph` mediumtext NOT NULL,
					`paragraph_html` mediumtext NOT NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					KEY ndx_aioseo_links_post_id (post_id),
					KEY ndx_aioseo_links_hostname (hostname(10))
				) {$charsetCollate};"
			);
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_links_suggestions' ) ) {
			$tableName = $db->prefix . 'aioseo_links_suggestions';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`post_id` bigint(20) unsigned NOT NULL,
					`linked_post_id` bigint(20) unsigned NOT NULL,
					`anchor` text NOT NULL,
					`phrase` longtext NOT NULL,
					`phrase_html` longtext NOT NULL,
					`original_phrase_html` longtext NOT NULL,
					`paragraph` longtext NOT NULL,
					`paragraph_html` longtext NOT NULL,
					`dismissed` tinyint(1) DEFAULT 0 NOT NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					KEY ndx_aioseo_links_suggestions_post_id (post_id),
					KEY ndx_aioseo_links_suggestions_linked_post_id (linked_post_id),
					KEY ndx_aioseo_links_suggestions_phrase (phrase(10))
				) {$charsetCollate};"
			);
		}

		if ( ! aioseo()->core->db->tableExists( 'aioseo_links_post_relationships' ) ) {
			$tableName = $db->prefix . 'aioseo_links_post_relationships';

			aioseo()->core->db->execute(
				"CREATE TABLE {$tableName} (
					`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					`post_id` bigint(20) unsigned NOT NULL,
					`suggestion_post_id` bigint(20) unsigned NOT NULL,
					`prioritized` tinyint(1) DEFAULT 0 NOT NULL,
					`created` datetime NOT NULL,
					`updated` datetime NOT NULL,
					PRIMARY KEY (id),
					KEY ndx_aioseo_links_post_relationships_post_id (post_id),
					KEY ndx_aioseo_links_post_relationships_suggestion_post_id (suggestion_post_id),
					UNIQUE KEY ndx_aioseo_links_post_relationships_post_id_suggestion_post_id (post_id, suggestion_post_id)
				) {$charsetCollate};"
			);
		}

		// Reset the cache for the installed tables.
		aioseo()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Adds the initial columns.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function addInitialColumns() {
		if ( ! aioseo()->core->db->tableExists( 'aioseo_posts' ) ) {
			return;
		}

		$tableName = aioseo()->core->db->prefix . 'aioseo_posts';
		if ( ! aioseo()->core->db->columnExists( 'aioseo_posts', 'link_scan_date' ) ) {
			aioseo()->core->db->execute(
				"ALTER TABLE {$tableName}
				ADD link_scan_date datetime DEFAULT NULL AFTER video_scan_date"
			);
		}

		if ( ! aioseo()->core->db->columnExists( 'aioseo_posts', 'link_suggestions_scan_date' ) ) {
			aioseo()->core->db->execute(
				"ALTER TABLE {$tableName}
				ADD link_suggestions_scan_date datetime DEFAULT NULL AFTER link_scan_date"
			);
		}

		// Reset the cache for the installed tables.
		aioseo()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Removes the Post Relationships table that is no longer in use.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	private function removePostRelationshipsTable() {
		if ( ! aioseo()->core->db->tableExists( 'aioseo_links_post_relationships' ) ) {
			return;
		}

		$tableName = aioseo()->core->db->prefix . 'aioseo_links_post_relationships';
		aioseo()->core->db->execute(
			"DROP TABLE {$tableName}"
		);

		// Reset the cache for the installed tables.
		aioseo()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Adds additional indexes to improve performance.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function addAdditionalIndexes() {
		if (
			! aioseo()->core->db->tableExists( 'aioseo_links' ) ||
			aioseo()->core->db->indexExists( 'aioseo_links', 'ndx_aioseo_links_linked_post_id' )
		) {
			return;
		}

		$aioseoLinksTableName = aioseo()->core->db->prefix . 'aioseo_links';

		aioseo()->core->db->execute(
			"ALTER TABLE {$aioseoLinksTableName}
			ADD INDEX ndx_aioseo_links_linked_post_id (linked_post_id)"
		);
	}

	/**
	 * Change the paragraph column type to mediumtext.
	 *
	 * @since 1.1.10
	 *
	 * @return void
	 */
	private function changeParagraphColumnType() {
		if ( aioseo()->core->db->tableExists( 'aioseo_links' ) ) {
			$tableName = aioseo()->core->db->prefix . 'aioseo_links';

			aioseo()->core->db->execute( "ALTER TABLE $tableName CHANGE paragraph paragraph mediumtext NOT NULL" );
			aioseo()->core->db->execute( "ALTER TABLE $tableName CHANGE paragraph_html paragraph_html mediumtext NOT NULL" );
		}
	}
}