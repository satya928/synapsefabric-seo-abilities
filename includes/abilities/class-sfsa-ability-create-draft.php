<?php
/**
 * Ability: create-draft (write).
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a new post as a draft, never published.
 */
class SFSA_Ability_Create_Draft {

	/**
	 * Register.
	 */
	public static function register() {
		SFSA_Abilities::register(
			'create-draft',
			array(
				'label'               => __( 'Create draft post', 'synapsefabric-seo-abilities' ),
				'description'         => __( 'Create a new post as a DRAFT (it can never be published through this ability; a human publishes it in WordPress). Content should be block markup or HTML. Categories and tags are names; existing categories are reused. Refuses an exact duplicate of an existing title unless allow_duplicate_title is true. Returns edit and preview links.', 'synapsefabric-seo-abilities' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'                 => array( 'type' => 'string' ),
						'content'               => array( 'type' => 'string' ),
						'excerpt'               => array( 'type' => 'string' ),
						'categories'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'tags'                  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'seo'                   => array(
							'type'       => 'object',
							'properties' => array(
								'title'         => array( 'type' => 'string' ),
								'description'   => array( 'type' => 'string' ),
								'focus_keyword' => array( 'type' => 'string' ),
							),
						),
						'allow_duplicate_title' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			false,
			false
		);
	}

	/**
	 * Permission.
	 *
	 * @return true|WP_Error
	 */
	public static function permission() {
		return SFSA_Abilities::write_gate( 'create-draft', 'edit_posts' );
	}

	/**
	 * Run the ability.
	 *
	 * @param array|null $input Input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$gate  = self::permission();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$title = sanitize_text_field( $input['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'sfsa_title_required', __( 'A title is required.', 'synapsefabric-seo-abilities' ) );
		}

		if ( empty( $input['allow_duplicate_title'] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash','auto-draft') AND LOWER(post_title) = LOWER(%s) LIMIT 5", $title ) );
			if ( $existing ) {
				return new WP_Error( 'sfsa_duplicate_title', __( 'A post with this exact title already exists.', 'synapsefabric-seo-abilities' ), array( 'existing_ids' => array_map( 'intval', $existing ) ) );
			}
		}

		$seo_in = isset( $input['seo'] ) && is_array( $input['seo'] ) ? array_intersect_key( $input['seo'], array_flip( array( 'title', 'description', 'focus_keyword' ) ) ) : array();
		if ( $seo_in && 'none' === SFSA_SEO_Meta::provider() ) {
			return new WP_Error( 'sfsa_no_seo_plugin', __( 'No supported SEO plugin (Yoast SEO or Rank Math) is active, so SEO fields cannot be saved. Resend without "seo".', 'synapsefabric-seo-abilities' ) );
		}

		$content = (string) ( $input['content'] ?? '' );
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'post',
					'post_status'  => 'draft', // Fixed: never published from here.
					'post_title'   => $title,
					'post_content' => wp_kses_post( $content ), // Always filtered, even for admins: AI-supplied HTML is untrusted.
					'post_excerpt' => sanitize_textarea_field( $input['excerpt'] ?? '' ),
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$warnings = array();
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$cat_ids = array();
			foreach ( $input['categories'] as $name ) {
				$name = sanitize_text_field( $name );
				$term = '' !== $name ? term_exists( $name, 'category' ) : null;
				if ( $term ) {
					$cat_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				} elseif ( '' !== $name && current_user_can( 'manage_categories' ) ) {
					$new = wp_insert_term( $name, 'category' );
					if ( ! is_wp_error( $new ) ) {
						$cat_ids[] = (int) $new['term_id'];
					}
				} elseif ( '' !== $name ) {
					$warnings[] = sprintf( 'Category "%s" does not exist and your user cannot create categories.', $name );
				}
			}
			if ( $cat_ids ) {
				wp_set_post_categories( $post_id, $cat_ids );
			}
		}
		if ( ! empty( $input['tags'] ) && is_array( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $input['tags'] ) );
		}
		if ( $seo_in ) {
			SFSA_SEO_Meta::set( $post_id, $seo_in );
		}

		SFSA_Log::add( 'create-draft', 'Created draft "' . $title . '"', $post_id );

		return array(
			'id'          => (int) $post_id,
			'status'      => 'draft',
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => get_preview_post_link( $post_id ),
			'warnings'    => $warnings,
		);
	}
}
