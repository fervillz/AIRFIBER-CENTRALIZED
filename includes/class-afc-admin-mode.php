<?php

defined( 'ABSPATH' ) || exit;

/**
 * Per-user Basic / Advanced presentation mode for the Airfiber admin app.
 */
class AFC_Admin_Mode {

	const META_KEY = '_afc_admin_mode';

	public static function init() {
		add_filter( 'admin_body_class', array( __CLASS__, 'add_body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_afc_set_admin_mode', array( __CLASS__, 'ajax_set_mode' ) );
	}

	private static function is_airfiber_request() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( 'airfiber-centralized', 'airfiber-mikrotik' ), true );
	}

	private static function is_airfiber_hook( $hook_suffix ) {
		return in_array(
			$hook_suffix,
			array(
				'toplevel_page_airfiber-centralized',
				'airfiber_page_airfiber-mikrotik',
			),
			true
		);
	}

	public static function current_mode() {
		$mode = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return in_array( $mode, array( 'basic', 'advanced' ), true ) ? $mode : 'basic';
	}

	public static function add_body_class( $classes ) {
		if ( ! self::is_airfiber_request() ) {
			return $classes;
		}

		return trim( $classes . ' afc-admin-mode-' . self::current_mode() );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::is_airfiber_hook( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-admin-mode',
			AFC_URL . 'assets/css/admin-mode.css',
			array( 'afc-admin-compat' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-admin-mode',
			AFC_URL . 'assets/js/admin-mode.js',
			array( 'jquery' ),
			AFC_VERSION,
			true
		);

		$initial_mode = class_exists( 'AFC_Ajaxify' ) && class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request()
			? AFC_Ajaxify::initial_mode()
			: self::current_mode();

		wp_localize_script(
			'afc-admin-mode',
			'afcAdminMode',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'afc_admin_mode' ),
				'mode'    => $initial_mode,
				'labels'  => array(
					'basicDescription'    => __( 'Daily tools for collections, payments and service actions.', 'airfiber-centralized' ),
					'advancedDescription' => __( 'Full MikroTik, importing, repair and developer controls.', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function ajax_set_mode() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this view.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( 'afc_admin_mode', 'nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'basic', 'advanced' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected admin mode is invalid.', 'airfiber-centralized' ) ), 400 );
		}

		update_user_meta( get_current_user_id(), self::META_KEY, $mode );
		wp_send_json_success( array( 'mode' => $mode ) );
	}
}
