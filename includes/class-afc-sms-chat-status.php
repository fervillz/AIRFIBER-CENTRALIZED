<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps delivery state compact inside the SMS conversation timeline.
 */
class AFC_SMS_Chat_Status {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 48 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-sms-chat-status',
			AFC_URL . 'assets/css/sms-chat-status.css',
			array( 'afc-sms-center', 'afc-sms-web-replies' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-sms-chat-status',
			AFC_URL . 'assets/js/sms-chat-status.js',
			array( 'afc-sms-center', 'afc-sms-web-replies' ),
			AFC_VERSION,
			true
		);
	}
}
