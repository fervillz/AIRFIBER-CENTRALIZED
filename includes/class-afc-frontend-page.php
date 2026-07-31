<?php

defined( 'ABSPATH' ) || exit;

/**
 * Creates and manages the protected frontend Airfiber application page.
 */
class AFC_Frontend_Page {

	const OPTION_PAGE_ID = 'afc_frontend_page_id';
	const PAGE_SLUG      = 'airfiber';
	const SHORTCODE      = 'airfiber_app';
	const MANAGED_META   = '_afc_managed_frontend_page';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_app' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_repair_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'require_login' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_app_template' ), 99 );
		add_filter( 'display_post_states', array( __CLASS__, 'add_page_state' ), 10, 2 );
	}

	/**
	 * Creates the page during plugin activation.
	 *
	 * @return int Created or existing page ID.
	 */
	public static function activate() {
		return self::ensure_page();
	}

	/**
	 * Recreates the page if a plugin update is installed without reactivation,
	 * or if the managed page was removed.
	 */
	public static function maybe_repair_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page_id = absint( get_option( self::OPTION_PAGE_ID ) );
		if ( ! self::is_valid_page( $page_id ) ) {
			self::ensure_page();
		}
	}

	/**
	 * Finds or creates the Airfiber page without overwriting unrelated content.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	public static function ensure_page() {
		$page_id = absint( get_option( self::OPTION_PAGE_ID ) );
		if ( self::is_valid_page( $page_id ) ) {
			return $page_id;
		}

		$managed_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::MANAGED_META,
				'meta_value'     => '1',
			)
		);

		if ( $managed_pages ) {
			$page_id = (int) $managed_pages[0];
			self::prepare_page( $page_id );
			update_option( self::OPTION_PAGE_ID, $page_id, false );
			return $page_id;
		}

		$slug_page = get_page_by_path( self::PAGE_SLUG, OBJECT, 'page' );
		if ( $slug_page && self::can_adopt_page( $slug_page ) ) {
			$page_id = (int) $slug_page->ID;
			self::prepare_page( $page_id );
			update_post_meta( $page_id, self::MANAGED_META, '1' );
			update_option( self::OPTION_PAGE_ID, $page_id, false );
			return $page_id;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Airfiber', 'airfiber-centralized' ),
				'post_name'      => self::PAGE_SLUG,
				'post_content'   => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$page_id = (int) $page_id;
		update_post_meta( $page_id, self::MANAGED_META, '1' );
		update_option( self::OPTION_PAGE_ID, $page_id, false );

		return $page_id;
	}

	private static function is_valid_page( $page_id ) {
		if ( ! $page_id ) {
			return false;
		}

		$page = get_post( $page_id );
		return $page && 'page' === $page->post_type && 'trash' !== $page->post_status;
	}

	private static function can_adopt_page( $page ) {
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return false;
		}

		$content = trim( (string) $page->post_content );
		return '' === $content || has_shortcode( $content, self::SHORTCODE );
	}

	private static function prepare_page( $page_id ) {
		$page = get_post( $page_id );
		if ( ! $page ) {
			return;
		}

		$changes = array( 'ID' => $page_id );
		if ( 'publish' !== $page->post_status ) {
			$changes['post_status'] = 'publish';
		}
		if ( ! has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
			$changes['post_content'] = '[' . self::SHORTCODE . ']';
		}
		if ( count( $changes ) > 1 ) {
			wp_update_post( $changes );
		}

		update_post_meta( $page_id, self::MANAGED_META, '1' );
	}

	public static function get_page_id() {
		return absint( get_option( self::OPTION_PAGE_ID ) );
	}

	public static function get_url() {
		$page_id = self::get_page_id();
		return $page_id ? get_permalink( $page_id ) : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	public static function is_app_request() {
		$page_id = self::get_page_id();
		return $page_id && is_page( $page_id );
	}

	public static function require_login() {
		if ( self::is_app_request() && ! is_user_logged_in() ) {
			auth_redirect();
		}
	}

	public static function enqueue_assets() {
		if ( ! self::is_app_request() ) {
			return;
		}

		wp_enqueue_style(
			'afc-frontend-app',
			AFC_URL . 'assets/css/frontend-app.css',
			array(),
			AFC_VERSION
		);
	}

	public static function use_app_template( $template ) {
		if ( ! self::is_app_request() ) {
			return $template;
		}

		$app_template = AFC_PATH . 'templates/frontend-app.php';
		return file_exists( $app_template ) ? $app_template : $template;
	}

	public static function add_page_state( $states, $post ) {
		if ( $post && (int) $post->ID === self::get_page_id() ) {
			$states['afc_frontend_app'] = __( 'Airfiber App', 'airfiber-centralized' );
		}
		return $states;
	}

	public static function render_app() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$mode = class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic';

		ob_start();
		?>
		<div class="afc-frontend-shell" id="afc-frontend-app" data-afc-mode="<?php echo esc_attr( $mode ); ?>">
			<header class="afc-frontend-header">
				<div class="afc-frontend-brand">
					<span class="afc-frontend-mark" aria-hidden="true">A</span>
					<div>
						<strong><?php esc_html_e( 'Airfiber Centralized', 'airfiber-centralized' ); ?></strong>
						<span><?php esc_html_e( 'Private operations app', 'airfiber-centralized' ); ?></span>
					</div>
				</div>
				<span class="afc-frontend-mode"><?php echo esc_html( ucfirst( $mode ) ); ?> <?php esc_html_e( 'mode', 'airfiber-centralized' ); ?></span>
			</header>

			<main class="afc-frontend-content">
				<?php if ( has_action( 'afc_frontend_app_content' ) ) : ?>
					<?php do_action( 'afc_frontend_app_content', $mode ); ?>
				<?php else : ?>
					<section class="afc-frontend-welcome">
						<span class="afc-frontend-kicker"><?php esc_html_e( 'Frontend foundation installed', 'airfiber-centralized' ); ?></span>
						<h1><?php esc_html_e( 'Your Airfiber app page is ready.', 'airfiber-centralized' ); ?></h1>
						<p><?php esc_html_e( 'The plugin created this protected page automatically. Existing Basic and Advanced tools will be moved here module by module.', 'airfiber-centralized' ); ?></p>
						<div class="afc-frontend-actions">
							<a class="afc-frontend-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=airfiber-centralized' ) ); ?>"><?php esc_html_e( 'Open Current Dashboard', 'airfiber-centralized' ); ?></a>
							<a class="afc-frontend-secondary" href="<?php echo esc_url( wp_logout_url( self::get_url() ) ); ?>"><?php esc_html_e( 'Sign Out', 'airfiber-centralized' ); ?></a>
						</div>
					</section>
				<?php endif; ?>
			</main>
		</div>
		<?php
		return ob_get_clean();
	}
}
