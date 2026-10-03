<?php
/**
 * Database tables (redirects, activity log).
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
class SFSA_DB {

	const VERSION = '1';

	/**
	 * Create or upgrade tables when the stored schema version is out of date.
	 */
	public static function maybe_install() {
		if ( get_option( 'sfsa_db_version' ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}sfsa_redirects (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_key varchar(191) NOT NULL,
				source_url text NOT NULL,
				target_key varchar(191) NOT NULL,
				target_url text NOT NULL,
				code smallint(3) NOT NULL DEFAULT 301,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_key (source_key),
				KEY target_key (target_key)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}sfsa_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ability varchar(64) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				summary varchar(255) NOT NULL,
				details longtext NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id)
			) $charset;"
		);

		update_option( 'sfsa_db_version', self::VERSION );
	}
}
