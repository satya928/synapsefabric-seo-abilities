<?php
/**
 * Read SEO meta from Yoast SEO or Rank Math.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO plugin meta-key mapping.
 */
class SFSA_SEO_Meta {

	/**
	 * Which SEO plugin is active: 'yoast', 'rank_math' or 'none'.
	 *
	 * @return string
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rank_math';
		}
		return 'none';
	}

	/**
	 * Meta keys per provider.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function keys() {
		return array(
			'yoast'     => array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
				'canonical'     => '_yoast_wpseo_canonical',
			),
			'rank_math' => array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
				'canonical'     => 'rank_math_canonical_url',
			),
		);
	}

	/**
	 * Get SEO meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{provider:string,fields:array<string,string>}
	 */
	public static function get( $post_id ) {
		$provider = self::provider();
		$fields   = array();
		$map      = self::keys();
		if ( isset( $map[ $provider ] ) ) {
			foreach ( $map[ $provider ] as $field => $meta_key ) {
				$fields[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
			}
		}
		return array(
			'provider' => $provider,
			'fields'   => $fields,
		);
	}

	/**
	 * Write SEO fields for a post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $fields  Any of title, description, focus_keyword, canonical.
	 * @return array<string,string>|WP_Error Old values of the fields that were written.
	 */
	public static function set( $post_id, array $fields ) {
		$provider = self::provider();
		$map      = self::keys();
		if ( ! isset( $map[ $provider ] ) ) {
			return new WP_Error( 'sfsa_no_seo_plugin', __( 'No supported SEO plugin (Yoast SEO or Rank Math) is active, so SEO fields cannot be saved.', 'synapsefabric-seo-abilities' ) );
		}
		$old = array();
		foreach ( $fields as $field => $value ) {
			if ( ! isset( $map[ $provider ][ $field ] ) ) {
				continue;
			}
			$value = 'canonical' === $field ? esc_url_raw( $value ) : sanitize_text_field( $value );
			$key   = $map[ $provider ][ $field ];

			$old[ $field ] = (string) get_post_meta( $post_id, $key, true );
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		return $old;
	}
}
