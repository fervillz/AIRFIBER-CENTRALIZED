<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shows live PPP session presence in customer payment search results.
 *
 * The status uses the existing AFC_PPP_Users response. That response already
 * compares /ppp/secret with /ppp/active, so this UI does not create another
 * router request or another database source of truth.
 */
class AFC_PPP_Presence_UI {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1038 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1038 );
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
		if ( wp_script_is( 'afc-ppp-presence-ui', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-ppp-presence-ui',
			AFC_URL . 'assets/css/ppp-presence-ui.css',
			array(),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-ppp-presence-ui',
			AFC_URL . 'assets/js/ppp-presence-ui.js',
			array( 'jquery' ),
			AFC_VERSION,
			true
		);
	}
}
