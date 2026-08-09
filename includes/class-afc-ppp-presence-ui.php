<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shows live PPP session presence in customer payment search results.
 *
 * The UI still consumes the active flag from AFC_PPP_Users when available,
 * but also exposes a tiny cached /ppp/active snapshot so Basic mode can show
 * presence even when the heavy Operations table has not loaded yet.
 */
class AFC_PPP_Presence_UI {

	const NONCE     = 'afc_ppp_presence';
	const TRANSIENT = 'afc_ppp_presence_snapshot_v1';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1038 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1038 );
		add_action( 'wp_ajax_afc_ppp_presence_snapshot', array( __CLASS__, 'ajax_snapshot' ) );
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

		wp_localize_script(
			'afc-ppp-presence-ui',
			'afcPppPresence',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view PPP presence.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function snapshot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$active = AFC_MikroTik::run_command(
			array(
				'/ppp/active/print',
				'=.proplist=name',
			)
		);
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		if ( isset( $active['name'] ) ) {
			$active = array( $active );
		}

		$names = array();
		foreach ( (array) $active as $session ) {
			$name = isset( $session['name'] ) ? trim( (string) $session['name'] ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$snapshot = array(
			'active'     => array_values( array_unique( $names ) ),
			'count'      => count( array_unique( $names ) ),
			'checked_at' => current_time( 'mysql' ),
		);
		set_transient( self::TRANSIENT, $snapshot, 15 );
		return $snapshot;
	}

	public static function ajax_snapshot() {
		self::authorize();
		$force = isset( $_POST['refresh'] ) && '1' === (string) $_POST['refresh'];
		$snapshot = self::snapshot( $force );
		if ( is_wp_error( $snapshot ) ) {
			wp_send_json_error( array( 'message' => $snapshot->get_error_message() ) );
		}
		wp_send_json_success( $snapshot );
	}
}
