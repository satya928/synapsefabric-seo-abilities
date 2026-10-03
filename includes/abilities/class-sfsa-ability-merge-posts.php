<?php
/**
 * Ability: merge-posts (write).
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Folds a duplicate post into the stronger one and 301-redirects the old URL.
 */
class SFSA_Ability_Merge_Posts {

	/**
	 * Register.
	 */
	public static function register() {
		SFSA_Abilities::register(
			'merge-posts',
			array(
				'label'               => __( 'Merge posts', 'synapsefabric-seo-abilities' ),
				'description'         => __( 'Merge a duplicate (source) post into the post to keep (target): creates a 301 redirect from the source URL to the target, moves the source\'s comments, adds its categories and tags to the target, and sets the source to draft (nothing is deleted). Optionally appends the source content to the target. The target must be published. Returns posts that still link to the old URL so they can be fixed with update-post. Use dry_run first.', 'synapsefabric-seo-abilities' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'The duplicate to retire.',
						),
						'target_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'The published post to keep.',
						),
						'content_mode' => array(
							'type'    => 'string',
							'enum'    => array( 'keep_target', 'append_source' ),
							'default' => 'keep_target',
						),
						'dry_run'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'   => array( 'source_id', 'target_id' ),
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
	 * Permission: enabled and the user can edit both posts.
	 *
	 * @param array|null $input Input.
	 * @return true|WP_Error
	 */
	public static function permission( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$src   = absint( $input['source_id'] ?? 0 );
		$tgt   = absint( $input['target_id'] ?? 0 );
		$gate  = SFSA_Abilities::write_gate( 'merge-posts', 'edit_posts' );
		if ( is_wp_error( $gate ) || ! $src || ! $tgt ) {
			return $gate;
		}
		$gate = SFSA_Abilities::write_gate( 'merge-posts', 'edit_post', $src );
		return is_wp_error( $gate ) ? $gate : SFSA_Abilities::write_gate( 'merge-posts', 'edit_post', $tgt );
	}

	/**
	 * Run the ability.
	 *
	 * @param array|null $input Input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$gate  = self::permission( $input );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$src = get_post( absint( $input['source_id'] ?? 0 ) );
		$tgt = get_post( absint( $input['target_id'] ?? 0 ) );
		foreach ( array( $src, $tgt ) as $p ) {
			if ( ! $p || 'post' !== $p->post_type || 'trash' === $p->post_status ) {
				return new WP_Error( 'sfsa_not_found', __( 'Source or target post not found.', 'synapsefabric-seo-abilities' ), array( 'status' => 404 ) );
			}
		}
		if ( $src->ID === $tgt->ID ) {
			return new WP_Error( 'sfsa_same_post', __( 'Source and target are the same post.', 'synapsefabric-seo-abilities' ) );
		}
		if ( 'publish' !== $tgt->post_status ) {
			return new WP_Error( 'sfsa_target_not_published', __( 'The target must be a published post, otherwise visitors would be redirected to a dead page.', 'synapsefabric-seo-abilities' ) );
		}
		$append = isset( $input['content_mode'] ) && 'append_source' === $input['content_mode'];

		$src_url = get_permalink( $src ); // Captured before the status change alters the permalink.
		$tgt_url = get_permalink( $tgt );
		if ( SFSA_Analyzer::path_key( $src_url ) === SFSA_Analyzer::path_key( $tgt_url ) ) {
			return new WP_Error( 'sfsa_same_url', __( 'Both posts resolve to the same URL.', 'synapsefabric-seo-abilities' ) );
		}

		$comments = get_comments(
			array(
				'post_id' => $src->ID,
				'status'  => 'all',
				'fields'  => 'ids',
			)
		);
		$plan     = array(
			'source'        => array(
				'id'            => (int) $src->ID,
				'title'         => $src->post_title,
				'url'           => $src_url,
				'status_before' => $src->post_status,
			),
			'target'        => array(
				'id'    => (int) $tgt->ID,
				'title' => $tgt->post_title,
				'url'   => $tgt_url,
			),
			'redirect'      => array(
				'from' => $src_url,
				'to'   => $tgt_url,
				'code' => 301,
			),
			'comments'      => count( $comments ),
			'append_source' => $append,
		);
		if ( ! empty( $input['dry_run'] ) ) {
			return array( 'dry_run' => true ) + $plan;
		}

		// 1. Redirect first: if it cannot be created, nothing else has changed.
		$redirect = SFSA_Redirects::add( $src_url, $tgt_url, $src->ID );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}

		$warnings = array();
		if ( 'sfsa' === $redirect['engine'] ) {
			SFSA_Redirects::add( home_url( '/?p=' . $src->ID ), $tgt_url, $src->ID ); // Old short link; best effort.
		}

		// 2. Target content.
		if ( $append ) {
			if ( 0 !== wp_revisions_to_keep( $tgt ) ) {
				wp_save_post_revision( $tgt->ID );
			}
			$heading = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $src->post_title ) . '</h2><!-- /wp:heading -->';
			$res     = wp_update_post(
				wp_slash(
					array(
						'ID'           => $tgt->ID,
						'post_content' => $tgt->post_content . "\n\n" . $heading . "\n\n" . $src->post_content,
					)
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				$warnings[] = 'Could not append source content: ' . $res->get_error_message();
			}
		}

		// 3. Taxonomy terms.
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			$ids = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
			if ( $ids && ! is_wp_error( $ids ) ) {
				wp_set_object_terms( $tgt->ID, array_map( 'intval', $ids ), $tax, true );
			}
		}

		// 4. Comments.
		$moved = 0;
		if ( $comments ) {
			if ( current_user_can( 'moderate_comments' ) ) {
				foreach ( $comments as $cid ) {
					if ( wp_update_comment(
						array(
							'comment_ID'      => $cid,
							'comment_post_ID' => $tgt->ID,
						)
					) ) {
						++$moved;
					}
				}
				wp_update_comment_count( $src->ID );
				wp_update_comment_count( $tgt->ID );
			} else {
				$warnings[] = 'Comments were not moved because your user cannot moderate comments.';
			}
		}

		// 5. Retire the source (draft, not deleted).
		$res = wp_update_post(
			array(
				'ID'          => $src->ID,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			if ( 'sfsa' === $redirect['engine'] ) {
				SFSA_Redirects::delete( $redirect['id'] ); // Do not leave a live redirect on a still-published post.
			}
			return new WP_Error( 'sfsa_draft_failed', 'Could not set the source post to draft: ' . $res->get_error_message() );
		}

		// 6. Posts that still link to the old URL.
		global $wpdb;
		$needle = '%' . $wpdb->esc_like( trim( (string) wp_parse_url( $src_url, PHP_URL_PATH ), '/' ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$linking = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND ID NOT IN (%d, %d) AND post_content LIKE %s LIMIT 50", $src->ID, $tgt->ID, $needle ) );

		SFSA_Log::add(
			'merge-posts',
			'Merged "' . $src->post_title . '" into "' . $tgt->post_title . '"',
			$src->ID,
			array(
				'target_id'      => $tgt->ID,
				'source_status'  => $src->post_status,
				'redirect'       => $redirect,
				'comments_moved' => $moved,
				'appended'       => $append,
			)
		);

		return array(
			'merged'                         => true,
			'source'                         => $plan['source'] + array( 'status_now' => 'draft' ),
			'target'                         => $plan['target'],
			'redirect'                       => $redirect,
			'comments_moved'                 => $moved,
			'source_appended_to_target'      => $append,
			'posts_still_linking_to_old_url' => array_map( 'intval', $linking ),
			'warnings'                       => $warnings,
			'undo'                           => 'Delete the redirect (Settings > SynapseFabric SEO, or Redirection) and republish the source post.',
		);
	}
}
