<?php

defined( 'ABSPATH' ) || exit;

/**
 * Simple PPP onboarding and editing for Basic mode, with a complete editor for
 * Advanced mode. MikroTik, WordPress customer, billing dates, cutoff scheduler,
 * and pre-due SMS date are kept synchronized as one operation.
 */
class AFC_PPP_Manager {

	use AFC_PPP_Manager_Router_Trait;
	use AFC_PPP_Manager_Reminders_Trait;
	use AFC_PPP_Manager_Core_Trait;
	use AFC_PPP_Manager_Create_Trait;
	use AFC_PPP_Manager_Save_Trait;

	const NONCE       = 'afc_ppp_manager';
	const CRON_HOOK   = 'afc_ppp_pre_due_reminder_scan';
	const SCRIPT_MARKER = 'AFC-MANAGED-SCHEDULER v1';

	public static function init() {
		add_action( 'wp_ajax_afc_ppp_manager_bootstrap', array( __CLASS__, 'ajax_bootstrap' ) );
		add_action( 'wp_ajax_afc_ppp_manager_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_afc_ppp_manager_save', array( __CLASS__, 'ajax_save' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 76 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 76 );

		add_action( 'init', array( __CLASS__, 'ensure_schema_and_schedule' ), 25 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_pre_due_scan' ) );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'refresh_reminder_after_payment' ), 15, 2 );
		add_action( 'afc_quick_payment_recorded', array( __CLASS__, 'refresh_reminder_after_quick_payment' ), 15, 4 );
	}

	public static function ensure_schema_and_schedule() {
		self::ensure_comment_field();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		while ( $timestamp = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	private static function ensure_comment_field() {
		if ( ! class_exists( 'AFC_Comment_Fields' ) ) {
			return;
		}
		$fields = get_option( AFC_Comment_Fields::OPTION_KEY, array() );
		$fields = is_array( $fields ) ? $fields : array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) && 0 === strcasecmp( 'dueReminderDate', (string) $field['key'] ) ) {
				return;
			}
		}
		$fields[] = array(
			'key'     => 'dueReminderDate',
			'label'   => __( 'Due Reminder Date', 'airfiber-centralized' ),
			'type'    => 'date',
			'default' => '',
		);
		update_option( AFC_Comment_Fields::OPTION_KEY, AFC_Comment_Fields::sanitize_fields( $fields ), false );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		wp_enqueue_style( 'afc-ppp-manager', AFC_URL . 'assets/css/ppp-manager.css', array( 'afc-admin-compat' ), AFC_VERSION );
		wp_enqueue_script( 'afc-ppp-manager', AFC_URL . 'assets/js/ppp-manager.js', array( 'jquery', 'afc-ppp-users' ), AFC_VERSION, true );
		wp_localize_script(
			'afc-ppp-manager',
			'afcPPPManager',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'currentDate' => current_time( 'Y-m-d' ),
				'mode'        => class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic',
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PPP accounts.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

}
