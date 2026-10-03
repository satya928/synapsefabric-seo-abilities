<?php
/**
 * Ability: update-post (write).
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit title, content, excerpt and SEO meta of an existing post.
 */
class SFSA_Ability_Update_Post {

	/**
	 * Register.
	 */
	public static function register() {
		SFSA_Abilities::register(
			'update-post',
			array(
				'label'               => __( 'Update post', 'synapsefabric-seo-abilities' ),
				'description'         => __( 'Update the title, content (block markup), excerpt and SEO fields (title, description, focus_keyword, canonical) of an existing post. Only fields you pass are changed. Status and URL slug never change, so a draft stays a draft. The previous state is saved as a revision and the change is logged. Use dry_run to preview. Call get-post first and send back complete content, not a fragment.', 'synapsefabric-seo-abilities' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'title'   => array( 'type' => 'string' ),
						'content' => array(
							'type'        => 'string',
							'description' => 'Complete new post content (replaces the old content).',
						),
						'excerpt' => array( 'type' => 'string' ),
						'seo'     => array(
							'type'       => 'object',
							'properties' => array(
								'title'         => array( 'type' => 'string' ),
								'description'   => array( 'type' => 'string' ),
								'focus_keyword' => array( 'type' => 'string' ),
								'canonical'     => array( 'type' => 'string' ),
							),
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Report what would change without saving.',
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			false,
			true
		);
	}

	/**
	 * Permission: ability enabled and user may edit this post.
	 *
	 * @param array|null $input Input.
	 * @return true|WP_Error
	 */
	public static function permission( $input = null ) {
		$id = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;
		return $id ? SFSA_Abilities::write_gate( 'update-post', 'edit_post', $id ) : SFSA_Abilities::write_gate( 'update-post', 'edit_posts' );
	}

	/**
	 * Run the ability.
	 *
	 * @param array|null $input Input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$post  = $id ? get_post( $id ) : null;
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'sfsa_not_found', __( 'Post not found.', 'synapsefabric-seo-abilities' ), array( 'status' => 404 ) );
		}
		$gate = self::permission( $input );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$update = array( 'ID' => $post->ID );
		$old    = array();
		if ( isset( $input['title'] ) && sanitize_text_field( $input['title'] ) !== $post->post_title ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
			$old['title']         = $post->post_title;
		}
		if ( isset( $input['content'] ) ) {
			$content = wp_kses_post( (string) $input['content'] ); // Always filtered, even for admins: AI-supplied HTML is untrusted.
			if ( $content !== $post->post_content ) {
				$update['post_content'] = $content;
				$old['content_words']   = SFSA_Abilities::word_count( $post->post_content );
			}
		}
		if ( isset( $input['excerpt'] ) && sanitize_textarea_field( $input['excerpt'] ) !== $post->post_excerpt ) {
			$update['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
			$old['excerpt']         = $post->post_excerpt;
		}

		$seo_in = isset( $input['seo'] ) && is_array( $input['seo'] ) ? array_intersect_key( $input['seo'], array_flip( array( 'title', 'description', 'focus_keyword', 'canonical' ) ) ) : array();
		if ( $seo_in && 'none' === SFSA_SEO_Meta::provider() ) {
			return new WP_Error( 'sfsa_no_seo_plugin', __( 'No supported SEO plugin (Yoast SEO or Rank Math) is active, so SEO fields cannot be saved.', 'synapsefabric-seo-abilities' ) );
		}

		if ( count( $update ) < 2 && ! $seo_in ) {
			return new WP_Error( 'sfsa_nothing_to_do', __( 'Nothing to change: pass at least one field that differs from the current post.', 'synapsefabric-seo-abilities' ) );
		}

		$changed = array_values( array_diff( array_keys( $update ), array( 'ID' ) ) );
		$changed = array_map(
			function ( $k ) {
				return str_replace( 'post_', '', $k );
			},
			$changed
		);
		if ( $seo_in ) {
			$changed[] = 'seo:' . implode( ',', array_keys( $seo_in ) );
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run'      => true,
				'id'           => (int) $post->ID,
				'would_change' => $changed,
			);
		}

		$revisions_on = 0 !== wp_revisions_to_keep( $post );
		$revision_id  = 0;
		if ( $revisions_on && count( $update ) > 1 ) {
			$revision_id = (int) wp_save_post_revision( $post->ID ); // Snapshot the state before our change.
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( $seo_in ) {
			$old_seo = SFSA_SEO_Meta::set( $post->ID, $seo_in );
			if ( is_wp_error( $old_seo ) ) {
				return $old_seo;
			}
			$old['seo'] = $old_seo;
		}

		SFSA_Log::add(
			'update-post',
			'Updated ' . implode( ', ', $changed ) . ' on "' . $post->post_title . '"',
			$post->ID,
			array(
				'previous'    => $old,
				'revision_id' => $revision_id,
			)
		);

		$out = array(
			'id'              => (int) $post->ID,
			'status'          => get_post_status( $post->ID ),
			'url'             => get_permalink( $post->ID ),
			'changed'         => $changed,
			'revision_saved'  => $revision_id > 0,
			'previous_values' => $old,
		);
		if ( ! $revisions_on ) {
			$out['warning'] = __( 'Post revisions are disabled on this site, so the previous content cannot be restored from WordPress.', 'synapsefabric-seo-abilities' );
		}
		return $out;
	}
}
