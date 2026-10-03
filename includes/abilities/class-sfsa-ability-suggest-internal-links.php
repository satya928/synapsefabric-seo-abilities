<?php
/**
 * Ability: suggest-internal-links.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Related posts and anchor-text suggestions.
 */
class SFSA_Ability_Suggest_Internal_Links {

	/**
	 * Register.
	 */
	public static function register() {
		SFSA_Abilities::register(
			'suggest-internal-links',
			array(
				'label'               => __( 'Suggest internal links', 'synapsefabric-seo-abilities' ),
				'description'         => __( 'For one post, returns related posts ranked by topical similarity, and where a link could go: link_from_this (links to add inside this post, with the exact anchor phrase already in the text and its sentence) and link_to_this (other posts where an existing phrase could link to this post). Suggestions only; nothing is changed. Posts already linked are skipped.', 'synapsefabric-seo-abilities' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 25,
							'default' => 8,
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( 'SFSA_Ability_Get_Post', 'permission' ),
			)
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array|null $input Input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$id    = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;
		$limit = is_array( $input ) ? min( 25, max( 1, (int) ( $input['limit'] ?? 8 ) ) ) : 8;
		$post  = $id ? get_post( $id ) : null;
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'sfsa_not_found', __( 'Post not found.', 'synapsefabric-seo-abilities' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'sfsa_forbidden', __( 'You cannot access this post.', 'synapsefabric-seo-abilities' ), array( 'status' => 403 ) );
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- needs the whole corpus to rank by similarity.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$posts = array();
		$docs  = array();
		foreach ( $query->posts as $p ) {
			$posts[ $p->ID ] = $p;
			$docs[ $p->ID ]  = array(
				'title'   => $p->post_title,
				'content' => $p->post_content,
			);
		}
		if ( ! isset( $docs[ $post->ID ] ) ) { // Draft or private source post.
			$posts[ $post->ID ] = $post;
			$docs[ $post->ID ]  = array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
			);
		}

		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$linked = function ( $html ) use ( $host ) {
			$keys = array();
			foreach ( SFSA_Analyzer::analyze( $html )['links'] as $l ) {
				if ( SFSA_Analyzer::is_internal( $l['href'], $host ) ) {
					$keys[ SFSA_Analyzer::path_key( $l['href'] ) ] = true;
				}
			}
			return $keys;
		};
		$kw     = function ( $p ) {
			return SFSA_SEO_Meta::get( $p->ID )['fields']['focus_keyword'] ?? '';
		};

		$self_key    = SFSA_Analyzer::path_key( get_permalink( $post ) );
		$my_links    = $linked( $post->post_content );
		$link_from   = array();
		$link_to     = array();
		$already     = array();
		$self_target = array(
			'title'   => $post->post_title,
			'keyword' => $kw( $post ),
		);

		foreach ( SFSA_Linker::related( $docs, $post->ID, $limit ) as $rid => $score ) {
			$other     = $posts[ $rid ];
			$other_key = SFSA_Analyzer::path_key( get_permalink( $other ) );
			$entry     = array(
				'id'    => (int) $rid,
				'title' => get_the_title( $other ),
				'url'   => get_permalink( $other ),
				'score' => $score,
			);

			if ( isset( $my_links[ $other_key ] ) ) {
				$already[] = (int) $rid;
			} else {
				$match       = SFSA_Linker::find_anchor(
					$post->post_content,
					array(
						'title'   => $other->post_title,
						'keyword' => $kw( $other ),
					)
				);
				$link_from[] = $entry + ( $match ? array(
					'anchor'    => $match['anchor'],
					'context'   => $match['context'],
					'placement' => 'inline',
				) : array(
					'anchor'    => $other->post_title,
					'context'   => '',
					'placement' => 'related-reading list (no matching phrase in text)',
				) );
			}

			if ( 'publish' === $post->post_status && ! isset( $linked( $other->post_content )[ $self_key ] ) ) {
				$match = SFSA_Linker::find_anchor( $other->post_content, $self_target );
				if ( $match ) {
					$link_to[] = $entry + array(
						'anchor'  => $match['anchor'],
						'context' => $match['context'],
					);
				}
			}
		}

		return array(
			'post_id'        => (int) $post->ID,
			'title'          => get_the_title( $post ),
			'link_from_this' => $link_from,
			'link_to_this'   => $link_to,
			'already_linked' => $already,
		);
	}
}
