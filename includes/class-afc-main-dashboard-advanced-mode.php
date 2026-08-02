<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the main dashboard and its embedded payment search exclusive to
 * Advanced mode without changing the existing Basic payment experience.
 */
class AFC_Main_Dashboard_Advanced_Mode {

	public static function init() {
		// Load the visibility guard before optional dashboard and CDN assets.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 9 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-frontend-app-stability',
			AFC_URL . 'assets/css/frontend-app-stability.css',
			array(),
			AFC_VERSION
		);

		wp_enqueue_style(
			'afc-main-dashboard-desktop-typography',
			AFC_URL . 'assets/css/main-dashboard-desktop-typography.css',
			array( 'afc-main-dashboard' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-main-dashboard-advanced-mode',
			AFC_URL . 'assets/js/main-dashboard-advanced-mode.js',
			array(),
			AFC_VERSION,
			true
		);
	}
}
