<?php

defined( 'ABSPATH' ) || exit;

/**
 * Presentation-only cleanup for PPP, billing, and scheduler screens.
 * Business logic and existing controls remain unchanged; this layer only
 * reorganizes existing DOM, shortens repetitive copy, and adds progressive
 * disclosure for lower-frequency settings/tools.
 */
class AFC_PPP_Billing_UX {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1200 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1200 );
	}

	public static function enqueue_frontend() {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_admin( $hook_suffix ) {
		if ( ! current_user_can( 'manage_options' ) || 'toplevel_page_airfiber-centralized' !== $hook_suffix ) {
			return;
		}
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		$css = AFC_PATH . 'assets/css/ppp-billing-ux.css';
		$js  = AFC_PATH . 'assets/js/ppp-billing-ux.js';

		wp_enqueue_style(
			'afc-ppp-billing-ux',
			AFC_URL . 'assets/css/ppp-billing-ux.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ppp-billing-ux',
			AFC_URL . 'assets/js/ppp-billing-ux.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : AFC_VERSION,
			true
		);
	}
}
