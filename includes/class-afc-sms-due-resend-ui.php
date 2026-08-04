<?php

defined( 'ABSPATH' ) || exit;

/**
 * Small Basic-and-Advanced UI bridge for manually resending a due SMS.
 */
class AFC_SMS_Due_Resend_UI {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1009 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'afc-customer-due-resend',
			AFC_URL . 'assets/js/customer-due-resend.js',
			array( 'afc-customer-actions' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-customer-due-resend',
			'afcCustomerDueResend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( AFC_SMS_PreCutoff::NONCE ),
			)
		);
	}
}
