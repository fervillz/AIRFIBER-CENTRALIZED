<?php

defined( 'ABSPATH' ) || exit;

/**
 * Ensures payment-search status icons remain visible and can load live PPP data
 * even when the hidden Operations table has not finished rendering.
 */
class AFC_Customer_Search_Icons_Hotfix {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1025 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1025 );
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
		wp_enqueue_script(
			'afc-customer-search-icons-hotfix',
			AFC_URL . 'assets/js/customer-search-icons-hotfix.js',
			array( 'jquery', 'afc-customer-search-polish', 'afc-ppp-users' ),
			AFC_VERSION,
			true
		);
	}
}
