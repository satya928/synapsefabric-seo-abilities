<?php
/**
 * Registers abilities with the core Abilities API (exposed via the MCP Adapter).
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ability registrar.
 */
class SFSA_Abilities {

	const NS       = 'synapsefabric-seo';
	const CATEGORY = 'synapsefabric-seo';

	/**
	 * Hook into the Abilities API.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SynapseFabric SEO', 'synapsefabric-seo-abilities' ),
				'description' => __( 'Audit and improve blog content.', 'synapsefabric-seo-abilities' ),
			)
		);
	}

	/**
	 * Register every ability. Write abilities are registered only when enabled in settings.
	 */
	public static function register_abilities() {
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-list-posts.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-get-post.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-find-duplicates.php';

		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-audit-site.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-suggest-internal-links.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-update-post.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-create-draft.php';
		require_once SFSA_DIR . 'includes/abilities/class-sfsa-ability-merge-posts.php';

		SFSA_Ability_List_Posts::register();
		SFSA_Ability_Get_Post::register();
		SFSA_Ability_Find_Duplicates::register();
		SFSA_Ability_Audit_Site::register();
		SFSA_Ability_Suggest_Internal_Links::register();

		// Write abilities exist only once an administrator has enabled them.
		if ( SFSA_Settings::is_write_enabled( 'update-post' ) ) {
			SFSA_Ability_Update_Post::register();
		}
		if ( SFSA_Settings::is_write_enabled( 'create-draft' ) ) {
			SFSA_Ability_Create_Draft::register();
		}
		if ( SFSA_Settings::is_write_enabled( 'merge-posts' ) ) {
			SFSA_Ability_Merge_Posts::register();
		}
	}

	/**
	 * Shared registration wrapper: namespace, category, MCP exposure, REST visibility.
	 *
	 * @param string $slug Ability slug without namespace.
	 * @param array  $args Ability args (label, description, schemas, callbacks, optional annotations).
	 * @param bool   $read_only Whether the ability only reads data.
	 * @param bool   $destructive Whether the ability can overwrite or remove existing data.
	 */
	public static function register( $slug, array $args, $read_only = true, $destructive = false ) {
		$args['category'] = self::CATEGORY;
		$args['meta']     = array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $read_only,
			),
		);
		wp_register_ability( self::NS . '/' . $slug, $args );
	}

	/**
	 * Capability gate for read abilities.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission check for a write ability: it must be enabled in settings and the user must hold the capability.
	 *
	 * @param string $slug Ability slug.
	 * @param string $cap  Capability (meta caps like edit_post take the post ID).
	 * @param mixed  ...$args Extra capability arguments.
	 * @return true|WP_Error
	 */
	public static function write_gate( $slug, $cap, ...$args ) {
		if ( ! SFSA_Settings::is_write_enabled( $slug ) ) {
			return new WP_Error( 'sfsa_write_disabled', __( 'This ability is switched off in Settings > SynapseFabric SEO.', 'synapsefabric-seo-abilities' ) );
		}
		if ( ! current_user_can( $cap, ...$args ) ) {
			return new WP_Error( 'sfsa_forbidden', __( 'Your WordPress user is not allowed to do this.', 'synapsefabric-seo-abilities' ) );
		}
		return true;
	}

	/**
	 * Term names for a post (empty array when none).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	public static function term_names( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'name' ) ) : array();
	}

	/**
	 * Unix timestamp from a GMT MySQL date, or 0 when unset ("0000-00-00 00:00:00").
	 *
	 * @param string $gmt GMT date string.
	 * @return int
	 */
	private static function gmt_ts( $gmt ) {
		return ( '' === (string) $gmt || 0 === strpos( (string) $gmt, '0000' ) ) ? 0 : (int) strtotime( $gmt . ' UTC' );
	}

	/**
	 * Publish date as ISO 8601 UTC. Drafts have no GMT date, so fall back to the modified date, then the local date.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function iso_date( $post ) {
		$ts = self::gmt_ts( $post->post_date_gmt );
		if ( ! $ts ) {
			$ts = self::gmt_ts( $post->post_modified_gmt );
		}
		if ( ! $ts ) {
			$ts = self::gmt_ts( get_gmt_from_date( $post->post_date ) );
		}
		return gmdate( 'c', $ts );
	}

	/**
	 * Last-modified time as a Unix timestamp (UTC), with the same fallbacks as iso_date().
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	public static function modified_ts( $post ) {
		$ts = self::gmt_ts( $post->post_modified_gmt );
		return $ts ? $ts : self::gmt_ts( get_gmt_from_date( $post->post_modified ) );
	}

	/**
	 * Word count that works for non-Latin scripts and ignores markup.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	public static function word_count( $content ) {
		return count( SFSA_Similarity::tokens( $content ) );
	}
}
