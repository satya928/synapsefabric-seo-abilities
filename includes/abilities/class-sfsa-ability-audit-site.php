<?php
/**
 * Ability: audit-site.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide content health audit.
 */
class SFSA_Ability_Audit_Site {

	/**
	 * Issue types: severity (1 = most important) and how to fix.
	 *
	 * @return array<string,array{severity:int,fix:string}>
	 */
	public static function issue_types() {
		return array(
			'unresolved_internal_link' => array(
				'severity' => 1,
				'fix'      => 'Internal link points at a URL that matches no post, page or archive. Use update-post to repoint or remove it.',
			),
			'missing_meta_description' => array(
				'severity' => 1,
				'fix'      => 'Write a 120-155 character description with update-post (seo.description).',
			),
			'thin_content'             => array(
				'severity' => 2,
				'fix'      => 'Expand with original, useful detail, or merge into a stronger post with merge-posts.',
			),
			'orphan_post'              => array(
				'severity' => 2,
				'fix'      => 'No other post links here. Use suggest-internal-links, then add links with update-post.',
			),
			'link_to_redirected_url'   => array(
				'severity' => 2,
				'fix'      => 'Link goes through a redirect. Use update-post to link straight to the final URL.',
			),
			'title_length'             => array(
				'severity' => 3,
				'fix'      => 'Aim for about 30-60 characters so the title is not cut off in search results.',
			),
			'meta_description_length'  => array(
				'severity' => 3,
				'fix'      => 'Aim for roughly 70-160 characters.',
			),
			'images_missing_alt'       => array(
				'severity' => 3,
				'fix'      => 'Add descriptive alt text to every content image with update-post.',
			),
			'no_internal_links'        => array(
				'severity' => 3,
				'fix'      => 'Add 2-4 relevant internal links (see suggest-internal-links).',
			),
			'no_subheadings'           => array(
				'severity' => 3,
				'fix'      => 'Long post without H2/H3 headings: break it into scannable sections.',
			),
			'uncategorized'            => array(
				'severity' => 4,
				'fix'      => 'Assign a meaningful category.',
			),
			'no_featured_image'        => array(
				'severity' => 4,
				'fix'      => 'Add a featured image in the editor.',
			),
			'stale_content'            => array(
				'severity' => 4,
				'fix'      => 'Not updated in a long time: refresh facts, dates and links.',
			),
		);
	}

	/**
	 * Register.
	 */
	public static function register() {
		SFSA_Abilities::register(
			'audit-site',
			array(
				'label'               => __( 'Audit site content', 'synapsefabric-seo-abilities' ),
				'description'         => __( 'Scans published posts for SEO and content-health problems: thin content, missing or badly sized meta descriptions and titles, images without alt text, orphan posts, unresolved or redirected internal links, missing headings, stale posts. Returns counts, example posts per problem and a prioritised fix list. Read-only. Duplicates are found by find-duplicates.', 'synapsefabric-seo-abilities' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 1000,
							'default'     => 500,
							'description' => 'Max posts to scan (newest first).',
						),
						'thin_words'   => array(
							'type'        => 'integer',
							'minimum'     => 50,
							'default'     => 300,
							'description' => 'Posts with fewer words are thin.',
						),
						'stale_months' => array(
							'type'    => 'integer',
							'minimum' => 3,
							'default' => 18,
						),
						'max_per_type' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'default'     => 15,
							'description' => 'Max example posts listed per problem type.',
						),
						'only'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Restrict to these problem types.',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( 'SFSA_Abilities', 'can_read' ),
			)
		);
	}

	/**
	 * Run the audit.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public static function execute( $input = null ) {
		$input        = is_array( $input ) ? $input : array();
		$limit        = min( 1000, max( 1, (int) ( $input['limit'] ?? 500 ) ) );
		$thin_words   = max( 50, (int) ( $input['thin_words'] ?? 300 ) );
		$stale_months = max( 3, (int) ( $input['stale_months'] ?? 18 ) );
		$max_per_type = min( 200, max( 1, (int) ( $input['max_per_type'] ?? 15 ) ) );
		$types        = self::issue_types();
		$only         = isset( $input['only'] ) && is_array( $input['only'] ) ? array_intersect( array_map( 'sanitize_key', $input['only'] ), array_keys( $types ) ) : array_keys( $types );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'perm'           => 'editable',
			)
		);
		$posts = $query->posts;

		// Lookup tables for link checks.
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$known = array(); // path key => post id (or 0 for non-post pages/terms).
		foreach ( $posts as $p ) {
			$known[ SFSA_Analyzer::path_key( get_permalink( $p ) ) ] = (int) $p->ID;
			$known[ 'p:' . $p->ID ]                                  = (int) $p->ID;
		}
		foreach ( get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- whole-site audit needs every page URL.
				'fields'      => 'ids',
			)
		) as $pid ) {
			$known[ SFSA_Analyzer::path_key( get_permalink( $pid ) ) ] = 0;
			$known[ 'p:' . $pid ]                                      = 0;
		}
		foreach ( get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'offset'        => $limit,
			)
		) as $pid ) {
			$known[ SFSA_Analyzer::path_key( get_permalink( $pid ) ) ] = (int) $pid; // Posts beyond the scan limit still exist.
		}
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			foreach ( (array) get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			) as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$known[ SFSA_Analyzer::path_key( $link ) ] = 0;
				}
			}
		}
		$redirects = SFSA_Redirects::map();

		$seo         = SFSA_SEO_Meta::provider();
		$inbound     = array();
		$issues      = array_fill_keys( array_keys( $types ), array() );
		$stale_ts    = strtotime( '-' . $stale_months . ' months' );
		$default_cat = (int) get_option( 'default_category' );

		foreach ( $posts as $post ) {
			$id       = (int) $post->ID;
			$title    = get_the_title( $post );
			$url      = get_permalink( $post );
			$analysis = SFSA_Analyzer::analyze( $post->post_content );
			$words    = SFSA_Abilities::word_count( $post->post_content );
			$add      = function ( $type, $detail ) use ( &$issues, $id, $title, $url ) {
				$issues[ $type ][] = array(
					'id'     => $id,
					'title'  => $title,
					'url'    => $url,
					'detail' => $detail,
				);
			};

			if ( $words < $thin_words ) {
				$add( 'thin_content', $words . ' words' );
			}

			// SEO title and description.
			$meta      = SFSA_SEO_Meta::get( $id )['fields'];
			$seo_title = isset( $meta['title'] ) ? $meta['title'] : '';
			$seo_desc  = isset( $meta['description'] ) ? $meta['description'] : '';
			$desc_src  = 'none' === $seo ? $post->post_excerpt : $seo_desc;
			$eff_title = ( '' !== $seo_title && false === strpos( $seo_title, '%' ) ) ? $seo_title : $title;
			$title_len = mb_strlen( $eff_title );
			if ( $title_len > 60 || $title_len < 20 ) {
				$add( 'title_length', $title_len . ' characters' );
			}
			if ( '' === trim( $desc_src, " \t\n\r\0\x0B\f" ) ) {
				$add( 'missing_meta_description', 'none' === $seo ? 'No SEO plugin detected; excerpt is empty' : 'Empty' );
			} elseif ( false === strpos( $desc_src, '%' ) ) {
				$len = mb_strlen( $desc_src );
				if ( $len < 70 || $len > 160 ) {
					$add( 'meta_description_length', $len . ' characters' );
				}
			}

			// Images.
			$no_alt = count(
				array_filter(
					$analysis['images'],
					function ( $i ) {
						return ! $i['has_alt'];
					}
				)
			);
			if ( $no_alt ) {
				$add( 'images_missing_alt', $no_alt . ' of ' . count( $analysis['images'] ) . ' images' );
			}
			if ( ! has_post_thumbnail( $id ) ) {
				$add( 'no_featured_image', '' );
			}

			// Headings.
			if ( $words >= 600 && 0 === $analysis['headings']['h2'] + $analysis['headings']['h3'] ) {
				$add( 'no_subheadings', $words . ' words, no H2/H3' );
			}

			// Taxonomy and freshness.
			$cats = wp_get_post_categories( $id );
			if ( ! $cats || ( 1 === count( $cats ) && (int) $cats[0] === $default_cat ) ) {
				$add( 'uncategorized', '' );
			}
			if ( SFSA_Abilities::modified_ts( $post ) < $stale_ts ) {
				$add( 'stale_content', 'Last updated ' . gmdate( 'Y-m-d', SFSA_Abilities::modified_ts( $post ) ) );
			}

			// Links.
			$internal = 0;
			foreach ( $analysis['links'] as $link ) {
				if ( ! SFSA_Analyzer::is_internal( $link['href'], $host ) ) {
					continue;
				}
				++$internal;
				$key = SFSA_Analyzer::path_key( $link['href'] );
				if ( '' === $key ) {
					continue; // Home page.
				}
				if ( isset( $redirects[ $key ] ) ) {
					$add( 'link_to_redirected_url', $link['href'] . ' -> ' . $redirects[ $key ] );
					$key = SFSA_Analyzer::path_key( $redirects[ $key ] );
				}
				if ( isset( $known[ $key ] ) ) {
					if ( $known[ $key ] && $known[ $key ] !== $id ) {
						$inbound[ $known[ $key ] ] = true;
					}
					continue;
				}
				if ( ! preg_match( '#(^|/)(wp-content|wp-json|wp-admin|feed|author|category|tag)(/|$)|\.[a-z0-9]{2,5}$#i', $key ) ) {
					$add( 'unresolved_internal_link', $link['href'] );
				}
			}
			if ( 0 === $internal ) {
				$add( 'no_internal_links', '' );
			}
		}

		foreach ( $posts as $post ) {
			if ( ! isset( $inbound[ $post->ID ] ) && count( $posts ) > 1 ) {
				$issues['orphan_post'][] = array(
					'id'     => (int) $post->ID,
					'title'  => get_the_title( $post ),
					'url'    => get_permalink( $post ),
					'detail' => 'No inbound internal links from other scanned posts',
				);
			}
		}

		$summary = array();
		$report  = array();
		foreach ( $types as $type => $info ) {
			if ( ! in_array( $type, $only, true ) || empty( $issues[ $type ] ) ) {
				continue;
			}
			$summary[ $type ] = count( $issues[ $type ] );
			$report[ $type ]  = array(
				'count'    => count( $issues[ $type ] ),
				'severity' => $info['severity'],
				'fix'      => $info['fix'],
				'examples' => array_slice( $issues[ $type ], 0, $max_per_type ),
			);
		}
		uasort(
			$report,
			function ( $a, $b ) {
				return array( $a['severity'], -$a['count'] ) <=> array( $b['severity'], -$b['count'] );
			}
		);

		return array(
			'scanned'    => count( $posts ),
			'truncated'  => (int) $query->found_posts > count( $posts ),
			'seo_plugin' => $seo,
			'summary'    => $summary,
			'issues'     => $report,
			'note'       => 'Severity 1 is most important. For duplicate or cannibalising posts run find-duplicates.',
		);
	}
}
