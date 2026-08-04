<?php

defined( 'ABSPATH' ) || exit;

/**
 * Small, reusable UI regression fixes shared by the Basic and Advanced apps.
 */
class AFC_UI_Regression_Fixes {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1035 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1035 );
	}

	public static function enqueue_frontend() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	public static function enqueue_admin( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	private static function enqueue() {
		wp_enqueue_style(
			'afc-ui-regression-fixes',
			AFC_URL . 'assets/css/ui-regression-fixes.css',
			array(),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-ui-regression-fixes',
			AFC_URL . 'assets/js/ui-regression-fixes.js',
			array(),
			AFC_VERSION,
			true
		);
	}
}
