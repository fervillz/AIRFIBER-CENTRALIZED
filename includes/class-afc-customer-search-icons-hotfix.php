<?php

defined( 'ABSPATH' ) || exit;

/**
 * Ensures payment-search status icons load immediately and remain visible even
 * when the hidden Operations table has not rendered yet.
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
			array( 'jquery' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-customer-search-icons-hotfix',
			'afcCustomerSearchIcons',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( AFC_Customer_Search_Polish::NONCE ),
				'pppNonce' => wp_create_nonce( 'afc_ppp_users' ),
			)
		);
	}
}
