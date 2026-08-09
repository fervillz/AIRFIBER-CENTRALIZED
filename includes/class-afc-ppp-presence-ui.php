<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shows live PPP session presence in customer payment search results.
 *
 * The initial page can use AFC_PPP_Users' active flag from the normal PPP load.
 * Search results then get a small targeted refresh after the user pauses typing,
 * so ONLINE/OFFLINE reflects the current /ppp/active state without reloading all
 * PPP secret details again.
 */
class AFC_PPP_Presence_UI {

	const NONCE     = 'afc_ppp_presence';
	const TRANSIENT = 'afc_ppp_presence_snapshot_v1';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1038 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1038 );
		add_action( 'wp_ajax_afc_ppp_presence_snapshot', array( __CLASS__, 'ajax_snapshot' ) );
		add_action( 'wp_ajax_afc_ppp_presence_check', array( __CLASS__, 'ajax_check_accounts' ) );
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
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'minSearch'     => 3,
				'searchDelayMs' => 1000,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view PPP presence.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function active_names() {
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
				$names[ strtolower( $name ) ] = $name;
			}
		}
		return $names;
	}

	private static function snapshot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$active_names = self::active_names();
		if ( is_wp_error( $active_names ) ) {
			return $active_names;
		}

		$names = array_values( $active_names );
		$snapshot = array(
			'active'     => $names,
			'count'      => count( $names ),
			'checked_at' => current_time( 'mysql' ),
		);
		set_transient( self::TRANSIENT, $snapshot, 15 );
		return $snapshot;
	}

	private static function requested_accounts() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, 50 ) as $account ) {
			$account = substr( sanitize_text_field( trim( (string) $account ) ), 0, 190 );
			if ( '' === $account ) {
				continue;
			}
			$accounts[ strtolower( $account ) ] = $account;
		}
		return $accounts;
	}

	public static function ajax_snapshot() {
		self::authorize();
		$force    = isset( $_POST['refresh'] ) && '1' === (string) $_POST['refresh'];
		$snapshot = self::snapshot( $force );
		if ( is_wp_error( $snapshot ) ) {
			wp_send_json_error( array( 'message' => $snapshot->get_error_message() ) );
		}
		wp_send_json_success( $snapshot );
	}

	/**
	 * Fresh presence check for only the accounts currently shown by a search.
	 *
	 * One lightweight /ppp/active query is made after the 1-second client-side
	 * debounce. We do not re-fetch /ppp/secret here. Anything requested but not
	 * present in PPP Active is returned as OFFLINE.
	 */
	public static function ajax_check_accounts() {
		self::authorize();
		$accounts = self::requested_accounts();
		if ( ! $accounts ) {
			wp_send_json_success(
				array(
					'states'     => array(),
					'checked_at' => current_time( 'mysql' ),
				)
			);
		}

		$active_names = self::active_names();
		if ( is_wp_error( $active_names ) ) {
			wp_send_json_error( array( 'message' => $active_names->get_error_message() ) );
		}

		$states = array();
		foreach ( $accounts as $normalized => $original ) {
			$states[ $original ] = isset( $active_names[ $normalized ] );
		}

		wp_send_json_success(
			array(
				'states'     => $states,
				'checked_at' => current_time( 'mysql' ),
			)
		);
	}
}
