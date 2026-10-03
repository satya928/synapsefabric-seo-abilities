<?php
/**
 * Removes all plugin data on uninstall.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'sfsa_settings' );
delete_site_option( 'sfsa_settings' );

// Custom tables: redirects (merge-posts) and the activity log.
foreach ( array( 'sfsa_redirects', 'sfsa_log' ) as $sfsa_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$sfsa_table}" );
}
delete_option( 'sfsa_db_version' );
