<?php

defined( 'ABSPATH' ) || exit;

/**
 * Adds the resizable help/console workspace used by the frontend OLT editor.
 */
class AFC_OLT_Manager_Console_UI {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1046 );
	}

	public static function enqueue_assets() {
		if (
			is_admin() ||
			! current_user_can( 'manage_options' ) ||
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request()
		) {
			return;
		}

		wp_enqueue_style(
			'afc-olt-manager-console-ui',
			AFC_URL . 'assets/css/olt-manager-console-ui.css',
			array( 'afc-olt-manager' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-olt-manager-console-ui',
			AFC_URL . 'assets/js/olt-manager-console-ui.js',
			array( 'jquery', 'afc-olt-manager' ),
			AFC_VERSION,
			true
		);
	}
}
