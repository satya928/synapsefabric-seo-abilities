<?php
/**
 * Settings screen and feature gating for write abilities.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings (Settings > SynapseFabric SEO). Write abilities are off by default.
 */
class SFSA_Settings {

	const OPTION = 'sfsa_settings';
	const PAGE   = 'sfsa-settings';

	/**
	 * Write abilities that can be switched on, slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function write_abilities() {
		return array(
			'update-post'  => __( 'update-post: edit content, title, excerpt and SEO meta (saves a revision)', 'synapsefabric-seo-abilities' ),
			'merge-posts'  => __( 'merge-posts: draft a source post and 301-redirect it to a target', 'synapsefabric-seo-abilities' ),
			'create-draft' => __( 'create-draft: create new posts as drafts only', 'synapsefabric-seo-abilities' ),
		);
	}

	/**
	 * Whether a write ability has been enabled by an administrator.
	 *
	 * @param string $slug Ability slug without namespace.
	 * @return bool
	 */
	public static function is_write_enabled( $slug ) {
		$settings = get_option( self::OPTION, array() );
		return ! empty( $settings['write_abilities'][ $slug ] );
	}

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_sfsa_delete_redirect', array( __CLASS__, 'handle_delete_redirect' ) );
	}

	/**
	 * Add the settings page.
	 */
	public static function add_page() {
		add_options_page(
			__( 'SynapseFabric SEO Abilities', 'synapsefabric-seo-abilities' ),
			__( 'SynapseFabric SEO', 'synapsefabric-seo-abilities' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option (the Settings API adds the nonce and capability check).
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array( 'write_abilities' => array() ),
			)
		);
	}

	/**
	 * Sanitise submitted settings; unknown keys are dropped.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean  = array( 'write_abilities' => array() );
		$input  = is_array( $input ) ? $input : array();
		$posted = isset( $input['write_abilities'] ) && is_array( $input['write_abilities'] ) ? $input['write_abilities'] : array();
		foreach ( array_keys( self::write_abilities() ) as $slug ) {
			$clean['write_abilities'][ $slug ] = ! empty( $posted[ $slug ] );
		}
		return $clean;
	}

	/**
	 * Delete one of our redirects (nonce + capability checked).
	 */
	public static function handle_delete_redirect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'synapsefabric-seo-abilities' ), 403 );
			return;
		}
		$id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		check_admin_referer( 'sfsa_delete_redirect_' . $id );
		SFSA_Redirects::delete( $id );
		SFSA_Log::add( 'settings', 'Deleted redirect #' . $id );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = get_option( self::OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Read-only abilities (list-posts, get-post, find-duplicates, audit-site, suggest-internal-links) are always available to users who can edit posts. Write abilities are off by default; enable only what you need.', 'synapsefabric-seo-abilities' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<fieldset>
					<?php foreach ( self::write_abilities() as $slug => $label ) : ?>
						<p>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( self::OPTION . '[write_abilities][' . $slug . ']' ); ?>"
									value="1" <?php checked( ! empty( $settings['write_abilities'][ $slug ] ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Redirects created by merge-posts', 'synapsefabric-seo-abilities' ); ?></h2>
			<?php if ( SFSA_Redirects::redirection_active() ) : ?>
				<p><?php esc_html_e( 'The Redirection plugin is active; new redirects are created there. Redirects below were made before it was activated.', 'synapsefabric-seo-abilities' ); ?></p>
			<?php endif; ?>
			<?php $redirects = SFSA_Redirects::all( 100 ); ?>
			<?php if ( ! $redirects ) : ?>
				<p><?php esc_html_e( 'None yet.', 'synapsefabric-seo-abilities' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'From', 'synapsefabric-seo-abilities' ); ?></th><th><?php esc_html_e( 'To', 'synapsefabric-seo-abilities' ); ?></th><th><?php esc_html_e( 'Hits', 'synapsefabric-seo-abilities' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $redirects as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->source_url ); ?></td>
							<td><?php echo esc_html( $row->target_url ); ?></td>
							<td><?php echo esc_html( (string) $row->hits ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="sfsa_delete_redirect" />
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
									<?php wp_nonce_field( 'sfsa_delete_redirect_' . $row->id ); ?>
									<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'synapsefabric-seo-abilities' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Activity log', 'synapsefabric-seo-abilities' ); ?></h2>
			<?php $log = SFSA_Log::recent( 50 ); ?>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'No changes have been made through the abilities yet.', 'synapsefabric-seo-abilities' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When (UTC)', 'synapsefabric-seo-abilities' ); ?></th><th><?php esc_html_e( 'User', 'synapsefabric-seo-abilities' ); ?></th><th><?php esc_html_e( 'Ability', 'synapsefabric-seo-abilities' ); ?></th><th><?php esc_html_e( 'What happened', 'synapsefabric-seo-abilities' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<?php $user = get_userdata( (int) $entry->user_id ); ?>
						<tr>
							<td><?php echo esc_html( $entry->created_at ); ?></td>
							<td><?php echo esc_html( $user ? $user->user_login : '-' ); ?></td>
							<td><?php echo esc_html( $entry->ability ); ?></td>
							<td><?php echo esc_html( $entry->summary ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
