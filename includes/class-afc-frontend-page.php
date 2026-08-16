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
		add_action( 'template_redirect', array( __CLASS__, 'protect_app' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_app_template' ), 99 );
		add_filter( 'display_post_states', array( __CLASS__, 'add_page_state' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
	}

	public static function activate() {
		return self::ensure_page();
	}

	public static function maybe_repair_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page_id = absint( get_option( self::OPTION_PAGE_ID ) );
		if ( ! self::is_valid_page( $page_id ) ) {
			self::ensure_page();
		}
	}

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

	public static function protect_app() {
		if ( ! self::is_app_request() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Your account does not have permission to use the Airfiber operations app.', 'airfiber-centralized' ),
				esc_html__( 'Airfiber Access Required', 'airfiber-centralized' ),
				array( 'response' => 403 )
			);
		}
	}

	public static function hide_admin_bar( $show ) {
		return self::is_app_request() ? false : $show;
	}

	/**
	 * Basic assets remain in the initial response. Advanced assets are registered
	 * normally, then AFC_Ajaxify groups and defers them at the end of enqueue.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( 'AFC_Admin' ) ) {
			AFC_Admin::enqueue_assets( 'toplevel_page_airfiber-centralized' );
			AFC_Admin::enqueue_assets( 'airfiber_page_airfiber-mikrotik' );
			AFC_Admin::enqueue_assets( 'airfiber_page_airfiber-olt' );
		}
		if ( class_exists( 'AFC_Collection_Print' ) ) {
			AFC_Collection_Print::enqueue_assets( 'toplevel_page_airfiber-centralized' );
		}
		if ( class_exists( 'AFC_Admin_Mode' ) ) {
			AFC_Admin_Mode::enqueue_assets( 'toplevel_page_airfiber-centralized' );
		}
		if ( class_exists( 'AFC_Basic_Payments' ) ) {
			AFC_Basic_Payments::enqueue_assets( 'toplevel_page_airfiber-centralized' );
		}
		if ( class_exists( 'AFC_Quick_Payments' ) ) {
			AFC_Quick_Payments::enqueue_assets( 'toplevel_page_airfiber-centralized' );
		}

		wp_enqueue_style(
			'afc-frontend-app',
			AFC_URL . 'assets/css/frontend-app.css',
			array( 'afc-quick-payments', 'afc-collection-print-selection' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-frontend-app',
			AFC_URL . 'assets/js/frontend-app.js',
			array( 'jquery', 'afc-admin-mode', 'afc-quick-payments' ),
			AFC_VERSION,
			true
		);

		$current_user = wp_get_current_user();
		$initial_mode = class_exists( 'AFC_Ajaxify' ) ? AFC_Ajaxify::initial_mode() : 'basic';
		wp_localize_script(
			'afc-frontend-app',
			'afcFrontendApp',
			array(
				'mode'        => $initial_mode,
				'operations'  => __( 'Operations', 'airfiber-centralized' ),
				'mikrotik'    => __( 'MikroTik', 'airfiber-centralized' ),
				'userName'    => $current_user->display_name,
				'logoutUrl'   => wp_logout_url( self::get_url() ),
				'adminUrl'    => admin_url(),
			)
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

	private static function render_operations_panel() {
		include AFC_PATH . 'templates/admin/ppp-users.php';
	}

	public static function render_mikrotik_panel() {
		if ( ! function_exists( 'settings_fields' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		$settings    = AFC_MikroTik::get_settings();
		$notice      = get_transient( 'afc_mikrotik_notice_' . get_current_user_id() );
		$last_status = get_option( 'afc_mikrotik_last_status', array() );
		delete_transient( 'afc_mikrotik_notice_' . get_current_user_id() );

		ob_start();
		include AFC_PATH . 'templates/admin/mikrotik-settings.php';
		$settings_markup = ob_get_clean();

		$settings_markup = str_replace(
			'action="options.php"',
			'action="' . esc_url( admin_url( 'options.php' ) ) . '"',
			$settings_markup
		);

		echo $settings_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_olt_panel() {
		if ( ! function_exists( 'settings_fields' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		$settings             = AFC_OLT::get_settings();
		$last_status          = get_option( AFC_OLT::LAST_STATUS_KEY, array() );
		$afc_olt_frontend_url = self::get_url() . '#optical';
		include AFC_PATH . 'templates/admin/olt-settings.php';
	}

	public static function render_app() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$mode         = class_exists( 'AFC_Ajaxify' ) ? AFC_Ajaxify::initial_mode() : 'basic';
		$current_user = wp_get_current_user();

		ob_start();
		?>
		<div class="afc-frontend-shell" id="afc-frontend-app" data-afc-mode="<?php echo esc_attr( $mode ); ?>">
			<header class="afc-frontend-header">
				<a class="afc-frontend-brand" href="<?php echo esc_url( self::get_url() ); ?>" aria-label="<?php esc_attr_e( 'Airfiber home', 'airfiber-centralized' ); ?>">
					<span class="afc-frontend-mark" aria-hidden="true">A</span>
					<span class="afc-frontend-brand-copy">
						<strong><?php esc_html_e( 'Airfiber Centralized', 'airfiber-centralized' ); ?></strong>
						<small><?php esc_html_e( 'Private operations app', 'airfiber-centralized' ); ?></small>
					</span>
				</a>

				<nav class="afc-frontend-nav" aria-label="<?php esc_attr_e( 'Airfiber sections', 'airfiber-centralized' ); ?>">
					<button type="button" class="is-active" data-afc-app-panel="operations" aria-pressed="true">
						<?php esc_html_e( 'Operations', 'airfiber-centralized' ); ?>
					</button>
					<button type="button" data-afc-app-panel="optical" aria-pressed="false">
						<?php esc_html_e( 'Optical', 'airfiber-centralized' ); ?>
					</button>
					<button type="button" class="afc-advanced-only" data-afc-app-panel="mikrotik" aria-pressed="false">
						<?php esc_html_e( 'MikroTik', 'airfiber-centralized' ); ?>
					</button>
				</nav>

				<div class="afc-frontend-header-actions">
					<div class="afc-frontend-mode-toggle" role="group" aria-label="<?php esc_attr_e( 'Choose Basic or Advanced mode', 'airfiber-centralized' ); ?>">
						<button type="button" data-afc-frontend-mode="basic" aria-pressed="true"><?php esc_html_e( 'Basic', 'airfiber-centralized' ); ?></button>
						<button type="button" data-afc-frontend-mode="advanced" aria-pressed="false"><?php esc_html_e( 'Advanced', 'airfiber-centralized' ); ?></button>
					</div>
					<div class="afc-frontend-user">
						<span><?php echo esc_html( $current_user->display_name ); ?></span>
						<a href="<?php echo esc_url( wp_logout_url( self::get_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'airfiber-centralized' ); ?></a>
					</div>
				</div>
			</header>

			<main class="afc-frontend-content">
				<section class="afc-frontend-panel is-active" data-afc-panel="operations" aria-hidden="false">
					<?php self::render_operations_panel(); ?>
				</section>

				<section class="afc-frontend-panel" data-afc-panel="optical" aria-hidden="true" hidden>
					<?php self::render_olt_panel(); ?>
				</section>

				<?php if ( class_exists( 'AFC_Ajaxify' ) ) : ?>
					<?php AFC_Ajaxify::render_panel_placeholders(); ?>
				<?php else : ?>
					<section class="afc-frontend-panel afc-advanced-only" data-afc-panel="mikrotik" aria-hidden="true" hidden>
						<?php self::render_mikrotik_panel(); ?>
					</section>
					<?php do_action( 'afc_frontend_app_content', $mode ); ?>
				<?php endif; ?>
			</main>
		</div>
		<?php
		return ob_get_clean();
	}
}
