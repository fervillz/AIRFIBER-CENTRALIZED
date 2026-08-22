<?php

defined( 'ABSPATH' ) || exit;

require_once AFC_PATH . 'includes/class-afc-gpon-standalone.php';

/**
 * Applies the shared iOS-style dialog surface and the minimal Basic Add PPP
 * wizard without changing the complete Advanced PPP editor.
 */
class AFC_IOS_Dialogs {

	public static function init() {
		AFC_GPON_Standalone::init();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 99 );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		wp_enqueue_style(
			'afc-ios-dialogs',
			AFC_URL . 'assets/css/ios-dialogs.css',
			array( 'afc-ppp-manager' ),
			AFC_VERSION
		);

		$add_menu_js = AFC_PATH . 'assets/js/basic-add-menu.js';
		wp_enqueue_script(
			'afc-basic-add-menu',
			AFC_URL . 'assets/js/basic-add-menu.js',
			array( 'jquery', 'afc-ppp-manager' ),
			file_exists( $add_menu_js ) ? (string) filemtime( $add_menu_js ) : AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-basic-add-menu',
			'afcGPONStandalone',
			AFC_GPON_Standalone::frontend_config()
		);

		wp_enqueue_script(
			'afc-basic-ppp-wizard',
			AFC_URL . 'assets/js/basic-ppp-wizard.js',
			array( 'jquery', 'afc-ppp-manager', 'afc-basic-add-menu' ),
			AFC_VERSION,
			true
		);

		wp_enqueue_script(
			'afc-ppp-create-fixes',
			AFC_URL . 'assets/js/ppp-create-fixes.js',
			array( 'jquery', 'afc-ppp-manager', 'afc-basic-ppp-wizard' ),
			AFC_VERSION,
			true
		);
	}
}
