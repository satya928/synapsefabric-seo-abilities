<?php
/**
 * 301 redirects: the Redirection plugin when active, otherwise our own table.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect manager.
 */
class SFSA_Redirects {

	/**
	 * Hook the front-end redirect handler.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sfsa_redirects';
	}

	/**
	 * Whether the Redirection plugin's API is available.
	 *
	 * @return bool
	 */
	public static function redirection_active() {
		return class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'create' );
	}

	/**
	 * Front-end: redirect when the request matches one of our rows.
	 */
	public static function maybe_redirect() {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$key = SFSA_Analyzer::path_key( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		if ( '' === $key ) {
			return;
		}
		$row = self::find( $key );
		if ( ! $row || SFSA_Analyzer::path_key( $row->target_url ) === $key ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1 WHERE id = %d', $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		wp_safe_redirect( $row->target_url, (int) $row->code, 'SynapseFabric SEO' );
		exit;
	}

	/**
	 * Look up a row by normalised source key.
	 *
	 * @param string $key Source key.
	 * @return object|null
	 */
	public static function find( $key ) {
		global $wpdb;
		SFSA_DB::maybe_install();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_key = %s', $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * All rows (newest first).
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		SFSA_DB::maybe_install();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', absint( $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Map of source key => target url (own table only).
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		$map = array();
		foreach ( self::all( 1000 ) as $row ) {
			$map[ $row->source_key ] = $row->target_url;
		}
		return $map;
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Create a 301 from one URL to another.
	 *
	 * Refuses self-redirects and loops, and re-points existing redirects that ended at the source
	 * so visitors never go through a chain.
	 *
	 * @param string $source_url Old URL.
	 * @param string $target_url New URL.
	 * @param int    $post_id    Source post ID (informational).
	 * @return array|WP_Error {engine:string,id:int,from:string,to:string,code:int}
	 */
	public static function add( $source_url, $target_url, $post_id = 0 ) {
		$source_key = SFSA_Analyzer::path_key( $source_url );
		$target_key = SFSA_Analyzer::path_key( $target_url );
		if ( '' === $source_key || '' === $target_key ) {
			return new WP_Error( 'sfsa_redirect_invalid', __( 'Cannot redirect the home page or an empty URL.', 'synapsefabric-seo-abilities' ) );
		}
		if ( $source_key === $target_key ) {
			return new WP_Error( 'sfsa_redirect_same', __( 'Source and target URLs are the same.', 'synapsefabric-seo-abilities' ) );
		}
		$reverse = self::find( $target_key );
		if ( $reverse && $reverse->target_key === $source_key ) {
			return new WP_Error( 'sfsa_redirect_loop', __( 'That would create a redirect loop.', 'synapsefabric-seo-abilities' ) );
		}

		if ( self::redirection_active() ) {
			$result = self::add_via_redirection( $source_url, $target_url );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		global $wpdb;
		SFSA_DB::maybe_install();
		// Flatten chains: anything that pointed at the source now points at the new target.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'target_key' => $target_key,
				'target_url' => esc_url_raw( $target_url ),
			),
			array( 'target_key' => $source_key )
		);
		$wpdb->delete( self::table(), array( 'source_key' => $source_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'source_key' => $source_key,
				'source_url' => esc_url_raw( $source_url ),
				'target_key' => $target_key,
				'target_url' => esc_url_raw( $target_url ),
				'code'       => 301,
				'post_id'    => absint( $post_id ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'sfsa_redirect_failed', __( 'Could not save the redirect.', 'synapsefabric-seo-abilities' ) );
		}
		return array(
			'engine' => 'sfsa',
			'id'     => (int) $wpdb->insert_id,
			'from'   => $source_url,
			'to'     => $target_url,
			'code'   => 301,
		);
	}

	/**
	 * Create the redirect through the Redirection plugin's PHP API.
	 *
	 * @param string $source_url Old URL.
	 * @param string $target_url New URL.
	 * @return array|WP_Error
	 */
	private static function add_via_redirection( $source_url, $target_url ) {
		try {
			$groups   = ( class_exists( 'Red_Group' ) && method_exists( 'Red_Group', 'get_all' ) ) ? Red_Group::get_all() : array();
			$group_id = ( $groups && isset( $groups[0]['id'] ) ) ? (int) $groups[0]['id'] : 0;
			if ( ! $group_id ) {
				return new WP_Error( 'sfsa_redirection_no_group', 'The Redirection plugin has no redirect group yet.' );
			}
			$item = Red_Item::create(
				array(
					'url'         => '/' . SFSA_Analyzer::path_key( $source_url ) . '/',
					'match_type'  => 'url',
					'action_type' => 'url',
					'action_code' => 301,
					'action_data' => array( 'url' => esc_url_raw( $target_url ) ),
					'group_id'    => $group_id,
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'sfsa_redirection_failed', $e->getMessage() );
		}
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		return array(
			'engine' => 'redirection',
			'id'     => is_object( $item ) && method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0,
			'from'   => $source_url,
			'to'     => $target_url,
			'code'   => 301,
		);
	}
}
