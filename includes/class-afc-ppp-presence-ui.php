<?php

defined( 'ABSPATH' ) || exit;

/**
 * Live MikroTik details for Basic and Advanced customer search results.
 *
 * The normal page load can still hydrate from AFC_PPP_Users. After a user types
 * at least three characters and pauses, the reusable AFC_Search_Ajax helper
 * requests fresh ONLINE/OFFLINE, due/expired state and PPP comment details for
 * only the accounts currently visible in search results.
 */
class AFC_PPP_Presence_UI {

	const NONCE     = 'afc_ppp_presence';
	const TRANSIENT = 'afc_ppp_presence_snapshot_v1';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1038 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1038 );
		add_action( 'wp_ajax_afc_ppp_presence_snapshot', array( __CLASS__, 'ajax_snapshot' ) );
		add_action( 'wp_ajax_afc_ppp_presence_check', array( __CLASS__, 'ajax_check_accounts' ) );
		add_action( 'wp_ajax_afc_ppp_search_live_details', array( __CLASS__, 'ajax_live_details' ) );
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
			array( 'jquery', 'afc-search-ajax' ),
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

	private static function requested_accounts() {
		if ( class_exists( 'AFC_Search_Ajax' ) ) {
			return AFC_Search_Ajax::requested_accounts( 'accounts', 50 );
		}

		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, 50 ) as $account ) {
			$account = substr( sanitize_text_field( trim( (string) $account ) ), 0, 190 );
			if ( $account ) $accounts[ strtolower( $account ) ] = $account;
		}
		return $accounts;
	}

	private static function active_sessions() {
		$active = AFC_MikroTik::run_command(
			array(
				'/ppp/active/print',
				'=.proplist=name,address,caller-id,uptime,service',
			)
		);
		if ( is_wp_error( $active ) ) return $active;
		if ( isset( $active['name'] ) ) $active = array( $active );

		$sessions = array();
		foreach ( (array) $active as $session ) {
			$name = isset( $session['name'] ) ? trim( (string) $session['name'] ) : '';
			if ( '' !== $name ) $sessions[ strtolower( $name ) ] = $session;
		}
		return $sessions;
	}

	private static function active_names() {
		$sessions = self::active_sessions();
		if ( is_wp_error( $sessions ) ) return $sessions;
		$names = array();
		foreach ( $sessions as $normalized => $session ) {
			$names[ $normalized ] = isset( $session['name'] ) ? $session['name'] : $normalized;
		}
		return $names;
	}

	private static function snapshot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) return $cached;
		}

		$active_names = self::active_names();
		if ( is_wp_error( $active_names ) ) return $active_names;
		$names = array_values( $active_names );
		$snapshot = array(
			'active'     => $names,
			'count'      => count( $names ),
			'checked_at' => current_time( 'mysql' ),
		);
		set_transient( self::TRANSIENT, $snapshot, 15 );
		return $snapshot;
	}

	private static function field_labels() {
		$labels = array();
		$fields = array();
		if ( class_exists( 'AFC_Comment_Fields' ) ) {
			$fields = array_merge(
				AFC_Comment_Fields::core_fields(),
				AFC_Comment_Fields::suggested_fields(),
				AFC_Comment_Fields::get_custom_fields()
			);
		}
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) ) continue;
			$labels[ strtolower( $field['key'] ) ] = ! empty( $field['label'] ) ? $field['label'] : $field['key'];
		}
		$labels['addr'] = __( 'Address', 'airfiber-centralized' );
		return $labels;
	}

	private static function humanize_key( $key ) {
		$key = preg_replace( '/([a-z])([A-Z])/', '$1 $2', (string) $key );
		$key = str_replace( array( '_', '-' ), ' ', $key );
		return ucwords( strtolower( trim( $key ) ) );
	}

	/** Parse all key:value pieces, even fields not yet configured in WordPress. */
	private static function comment_pairs( $comment ) {
		$comment = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $comment ) );
		if ( '' === $comment ) return array();

		$matches = array();
		preg_match_all(
			'/(?:^|\s)([A-Za-z][A-Za-z0-9_-]{0,39})\s*:\s*(.*?)(?=(?:\s+[A-Za-z][A-Za-z0-9_-]{0,39}\s*:)|$)/s',
			$comment,
			$matches,
			PREG_SET_ORDER
		);

		$labels = self::field_labels();
		$pairs  = array();
		foreach ( $matches as $match ) {
			$key   = trim( $match[1] );
			$value = trim( preg_replace( '/\s+/', ' ', $match[2] ) );
			$lower = strtolower( $key );
			if ( 'password' === $lower ) $value = '••••••';
			$pairs[] = array(
				'key'   => $key,
				'label' => isset( $labels[ $lower ] ) ? $labels[ $lower ] : self::humanize_key( $key ),
				'value' => $value,
			);
		}
		return $pairs;
	}

	private static function pair_value( $pairs, $key ) {
		foreach ( $pairs as $pair ) {
			if ( 0 === strcasecmp( (string) $pair['key'], (string) $key ) ) return trim( (string) $pair['value'] );
		}
		return '';
	}

	private static function redacted_comment( $pairs, $original ) {
		if ( $pairs ) {
			$lines = array();
			foreach ( $pairs as $pair ) $lines[] = $pair['key'] . ': ' . $pair['value'];
			return implode( "\n", $lines );
		}
		return preg_replace( '/(password\s*:\s*)[^\r\n]*/i', '$1••••••', (string) $original );
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return null;
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function due_state( $profile, $next_due, $cutoff ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today    = new DateTimeImmutable( current_time( 'Y-m-d' ), $timezone );
		$due      = self::parse_date( $next_due );
		$cut      = self::parse_date( $cutoff );
		$days     = $due ? (int) $today->diff( $due )->format( '%r%a' ) : null;

		if ( 0 === strcasecmp( trim( (string) $profile ), 'expired' ) || ( $cut && $cut < $today ) ) {
			return array( 'state' => 'expired', 'days' => $days );
		}
		if ( $due ) {
			if ( $days <= 0 ) return array( 'state' => 'due', 'days' => $days );
			if ( $days <= 7 ) return array( 'state' => 'soon', 'days' => $days );
			return array( 'state' => 'safe', 'days' => $days );
		}
		if ( $cut ) {
			$cut_days = (int) $today->diff( $cut )->format( '%r%a' );
			if ( $cut_days <= 0 ) return array( 'state' => 'due', 'days' => $cut_days );
			if ( $cut_days <= 7 ) return array( 'state' => 'soon', 'days' => $cut_days );
			return array( 'state' => 'safe', 'days' => $cut_days );
		}
		return array( 'state' => 'unknown', 'days' => null );
	}

	public static function ajax_snapshot() {
		self::authorize();
		$force    = isset( $_POST['refresh'] ) && '1' === (string) $_POST['refresh'];
		$snapshot = self::snapshot( $force );
		if ( is_wp_error( $snapshot ) ) wp_send_json_error( array( 'message' => $snapshot->get_error_message() ) );
		wp_send_json_success( $snapshot );
	}

	public static function ajax_check_accounts() {
		self::authorize();
		$accounts = self::requested_accounts();
		if ( ! $accounts ) wp_send_json_success( array( 'states' => array(), 'checked_at' => current_time( 'mysql' ) ) );

		$active_names = self::active_names();
		if ( is_wp_error( $active_names ) ) wp_send_json_error( array( 'message' => $active_names->get_error_message() ) );
		$states = array();
		foreach ( $accounts as $normalized => $original ) $states[ $original ] = isset( $active_names[ $normalized ] );
		wp_send_json_success( array( 'states' => $states, 'checked_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * Fresh search enrichment: active sessions + current secret comments.
	 * Only requested/visible accounts are returned to the browser.
	 */
	public static function ajax_live_details() {
		self::authorize();
		$accounts = self::requested_accounts();
		if ( ! $accounts ) wp_send_json_success( array( 'accounts' => array(), 'checked_at' => current_time( 'mysql' ) ) );

		$sessions = self::active_sessions();
		if ( is_wp_error( $sessions ) ) wp_send_json_error( array( 'message' => $sessions->get_error_message() ) );

		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=name,profile,comment,disabled,remote-address,caller-id',
			)
		);
		if ( is_wp_error( $secrets ) ) wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		if ( isset( $secrets['name'] ) ) $secrets = array( $secrets );

		$secret_map = array();
		foreach ( (array) $secrets as $secret ) {
			$name = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			if ( '' === $name ) continue;
			$normalized = strtolower( $name );
			if ( isset( $accounts[ $normalized ] ) ) $secret_map[ $normalized ] = $secret;
		}

		$result = array();
		foreach ( $accounts as $normalized => $original ) {
			$secret  = isset( $secret_map[ $normalized ] ) ? $secret_map[ $normalized ] : array();
			$session = isset( $sessions[ $normalized ] ) ? $sessions[ $normalized ] : array();
			$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			$pairs   = self::comment_pairs( $comment );
			$next_due = self::pair_value( $pairs, 'nextDue' );
			$cutoff   = self::pair_value( $pairs, 'cutoffDate' );
			$profile  = isset( $secret['profile'] ) ? (string) $secret['profile'] : '';
			$due      = self::due_state( $profile, $next_due, $cutoff );

			$result[ $original ] = array(
				'account'       => $original,
				'exists'        => ! empty( $secret ),
				'online'        => ! empty( $session ),
				'profile'       => $profile,
				'disabled'      => isset( $secret['disabled'] ) && 'true' === (string) $secret['disabled'],
				'nextDue'       => $next_due,
				'cutoffDate'    => $cutoff,
				'dueState'      => $due['state'],
				'daysToDue'     => $due['days'],
				'ip'            => isset( $session['address'] ) ? (string) $session['address'] : '',
				'uptime'        => isset( $session['uptime'] ) ? (string) $session['uptime'] : '',
				'service'       => isset( $session['service'] ) ? (string) $session['service'] : '',
				'callerId'      => isset( $session['caller-id'] ) ? (string) $session['caller-id'] : ( isset( $secret['caller-id'] ) ? (string) $secret['caller-id'] : '' ),
				'commentFields' => $pairs,
				'comment'       => self::redacted_comment( $pairs, $comment ),
			);
		}

		wp_send_json_success(
			array(
				'accounts'   => $result,
				'checked_at' => current_time( 'mysql' ),
			)
		);
	}
}
