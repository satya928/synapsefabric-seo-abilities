<?php
/**
 * Activity log: every change made through an ability is recorded.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audit trail.
 */
class SFSA_Log {

	/**
	 * Record an action.
	 *
	 * @param string $ability Ability slug.
	 * @param string $summary One-line description.
	 * @param int    $post_id Main post affected.
	 * @param array  $details Extra data (old values etc.), stored as JSON.
	 */
	public static function add( $ability, $summary, $post_id = 0, array $details = array() ) {
		global $wpdb;
		SFSA_DB::maybe_install();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'sfsa_log',
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'ability'    => substr( sanitize_key( $ability ), 0, 64 ),
				'post_id'    => absint( $post_id ),
				'summary'    => mb_substr( sanitize_text_field( $summary ), 0, 255 ),
				'details'    => wp_json_encode( $details ),
			)
		);
	}

	/**
	 * Most recent entries.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		SFSA_DB::maybe_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfsa_log ORDER BY id DESC LIMIT %d", absint( $limit ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
