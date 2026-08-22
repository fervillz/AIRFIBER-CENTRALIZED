<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Bootstrap {
	const OPTION_PAGE_ID = 'afcn_frontend_page_id';
	const PAGE_SLUG      = 'airfiber-beta';
	const SHORTCODE      = 'airfiber_next_app';
	const MANAGED_META   = '_afcn_managed_frontend_page';
	const OPTION_ENABLED = 'afcn_beta_enabled';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) { return; }
		self::$initialized = true;

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_bootstrap' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'protect_app' ), 1 );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ), 120 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1200 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'afcn_run_task_queue', array( __NAMESPACE__ . '\\Task_Queue', 'run' ) );

		if ( defined( 'AFC_FILE' ) ) { register_activation_hook( AFC_FILE, array( __CLASS__, 'activate' ) ); }
	}

	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) { return; }
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
		if ( isset( $parts[0] ) && 'Modules' === $parts[0] && isset( $parts[1] ) ) {
			$module = sanitize_key( strtolower( $parts[1] ) );
			$path   = AFCN_PATH . 'modules/' . $module . '/includes/' . $file;
		} else {
			$path = AFCN_PATH . 'core/' . $file;
		}
		if ( is_readable( $path ) ) { require_once $path; }
	}

	public static function is_enabled() {
		$enabled = get_option( self::OPTION_ENABLED, '1' );
		return (bool) apply_filters( 'afcn_beta_enabled', '0' !== (string) $enabled );
	}

	public static function activate() {
		Capabilities::ensure_roles();
		Module_Registry::invalidate();
		self::ensure_page();
		flush_rewrite_rules();
	}

	public static function admin_bootstrap() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		Capabilities::ensure_roles();
		if ( self::is_enabled() ) { self::ensure_page(); }
	}

	public static function get_page_id() { return absint( get_option( self::OPTION_PAGE_ID ) ); }

	public static function get_url() {
		$page_id = self::get_page_id();
		if ( ! $page_id && current_user_can( 'manage_options' ) && self::is_enabled() ) {
			Capabilities::ensure_roles();
			$page_id = self::ensure_page();
		}
		return $page_id ? get_permalink( $page_id ) : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	public static function classic_url() {
		return class_exists( 'AFC_Frontend_Page' ) ? \AFC_Frontend_Page::get_url() : admin_url( 'admin.php?page=airfiber-centralized' );
	}

	public static function ensure_page() {
		if ( ! self::is_enabled() ) { return 0; }
		$page_id = self::get_page_id();
		if ( self::is_valid_page( $page_id ) ) { return $page_id; }
		$managed = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::MANAGED_META, 'meta_value' => '1' ) );
		if ( $managed ) {
			$page_id = (int) $managed[0];
		} else {
			$existing = get_page_by_path( self::PAGE_SLUG, OBJECT, 'page' );
			if ( $existing && 'trash' !== $existing->post_status ) {
				$page_id = (int) $existing->ID;
			} else {
				$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => __( 'Airfiber BETA', 'airfiber-centralized' ), 'post_name' => self::PAGE_SLUG, 'post_content' => '[' . self::SHORTCODE . ']', 'comment_status' => 'closed', 'ping_status' => 'closed' ), true );
				if ( is_wp_error( $page_id ) ) { return 0; }
			}
		}
		$page_id = (int) $page_id;
		$page    = get_post( $page_id );
		if ( $page ) {
			$changes = array( 'ID' => $page_id );
			if ( 'publish' !== $page->post_status ) { $changes['post_status'] = 'publish'; }
			if ( ! has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) { $changes['post_content'] = '[' . self::SHORTCODE . ']'; }
			if ( count( $changes ) > 1 ) { wp_update_post( $changes ); }
		}
		update_post_meta( $page_id, self::MANAGED_META, '1' );
		update_option( self::OPTION_PAGE_ID, $page_id, false );
		return $page_id;
	}

	private static function is_valid_page( $page_id ) {
		if ( ! $page_id ) { return false; }
		$page = get_post( $page_id );
		return $page && 'page' === $page->post_type && 'trash' !== $page->post_status;
	}

	public static function is_beta_request() {
		if ( ! self::is_enabled() ) { return false; }
		$page_id = self::get_page_id();
		return $page_id && is_page( $page_id );
	}

	public static function protect_app() {
		if ( ! self::is_beta_request() ) { return; }
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( current_user_can( 'manage_options' ) ) { Capabilities::ensure_roles(); }
		if ( ! User_Manager::can_access() ) { wp_die( esc_html__( 'Your account does not have permission to use Airfiber BETA.', 'airfiber-centralized' ), esc_html__( 'Airfiber Access Required', 'airfiber-centralized' ), array( 'response' => 403 ) ); }
	}

	public static function use_template( $template ) {
		if ( ! self::is_beta_request() ) { return $template; }
		$beta_template = AFCN_PATH . 'templates/app.php';
		return is_readable( $beta_template ) ? $beta_template : $template;
	}

	public static function enqueue_assets() {
		if ( self::is_beta_request() && User_Manager::can_access() ) { Assets::enqueue_core(); return; }
		if ( self::is_enabled() && current_user_can( 'manage_options' ) && class_exists( 'AFC_Frontend_Page' ) && \AFC_Frontend_Page::is_app_request() && wp_script_is( 'afc-frontend-app', 'enqueued' ) ) {
			$url = wp_json_encode( self::get_url() );
			$js  = '(function(){var h=document.querySelector(".afc-frontend-header-actions");if(!h||document.getElementById("afcn-try-beta"))return;var a=document.createElement("a");a.id="afcn-try-beta";a.className="btn btn-sm btn-outline-primary";a.href=' . $url . ';a.textContent="Try BETA";a.setAttribute("aria-label","Open Airfiber BETA");h.insertBefore(a,h.firstChild);})();';
			wp_add_inline_script( 'afc-frontend-app', $js, 'after' );
		}
	}

	public static function register_rest_routes() { if ( self::is_enabled() ) { Rest_Router::register_routes(); } }
	public static function render_shortcode() { return is_user_logged_in() && User_Manager::can_access() ? App::render_shell() : ''; }
}
