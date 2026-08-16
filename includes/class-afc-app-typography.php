<?php

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared Airfiber typography scale after feature-specific styles so
 * every screen uses the same readable 16 / 14 / 13 pixel hierarchy.
 */
class AFC_App_Typography {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 999 );
	}

	public static function enqueue_frontend() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-source-serif-4',
			'https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400&display=swap',
			array(),
			null
		);

		self::enqueue( array( 'afc-source-serif-4' ) );
	}

	public static function enqueue_admin( $hook_suffix ) {
		if ( ! current_user_can( 'manage_options' ) || false === strpos( (string) $hook_suffix, 'airfiber' ) ) {
			return;
		}

		self::enqueue();
	}

	private static function enqueue( $dependencies = array() ) {
		wp_enqueue_style(
			'afc-app-typography',
			AFC_URL . 'assets/css/app-typography.css',
			$dependencies,
			AFC_VERSION
		);
	}
}
