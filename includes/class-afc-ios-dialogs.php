<?php

defined( 'ABSPATH' ) || exit;

/**
 * Applies the shared iOS-style dialog surface and the minimal Basic Add PPP
 * wizard without changing the complete Advanced PPP editor.
 */
class AFC_IOS_Dialogs {

	public static function init() {
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

		wp_enqueue_script(
			'afc-basic-ppp-wizard',
			AFC_URL . 'assets/js/basic-ppp-wizard.js',
			array( 'jquery', 'afc-ppp-manager' ),
			AFC_VERSION,
			true
		);
	}
}
