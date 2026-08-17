<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reusable debounced search enrichment endpoint.
 *
 * Frontend search tools send only the currently-visible account names after
 * the user stops typing. Providers are batch-oriented so future live data can
 * be added without creating another one-off AJAX endpoint.
 */
class AFC_Search_Ajaxify {

	const NONCE        = 'afc_search_ajaxify';
	const MAX_ACCOUNTS = 20;

	private static $providers = array();

	public static function init() {
		self::register_provider( 'ppp-live', array( __CLASS__, 'provide_ppp_live' ) );

		add_action( 'wp_ajax_afc_search_ajaxify', array( __CLASS__, 'ajax_query' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1039 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1039 );
	}

	public static function register_provider( $name, $callback ) {
		$name = sanitize_key( $name );
		if ( $name && is_callable( $callback ) ) {
			self::$providers[ $name ] = $callback;
		}
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
		if ( wp_script_is( 'afc-search-ajaxify', 'enqueued' ) ) return;

		wp_enqueue_style( 'afc-search-ajaxify', AFC_URL . 'assets/css/search-ajaxify.css', array(), AFC_VERSION );
		wp_enqueue_style( 'afc-search-ajaxify-tooltip-compact', AFC_URL . 'assets/css/search-ajaxify-tooltip-compact.css', array( 'afc-search-ajaxify' ), AFC_VERSION );
		wp_enqueue_script( 'afc-search-ajaxify', AFC_URL . 'assets/js/search-ajaxify.js', array( 'jquery' ), AFC_VERSION, true );
		wp_localize_script(
			'afc-search-ajaxify',
			'afcSearchAjaxify',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'minChars' => 3,
				'delayMs'  => 1000,
				'maxItems' => self::MAX_ACCOUNTS,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to read live customer data.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function requested_accounts() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) return array();

		$out = array();
		foreach ( array_slice( $raw, 0, self::MAX_ACCOUNTS ) as $account ) {
			$account = trim( sanitize_text_field( $account ) );
			if ( '' !== $account ) $out[ strtolower( $account ) ] = $account;
		}
		return array_values( $out );
	}

	private static function requested_providers() {
		$raw = isset( $_POST['providers'] ) ? wp_unslash( $_POST['providers'] ) : array( 'ppp-live' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array( $raw );
		}
		if ( ! is_array( $raw ) ) $raw = array( 'ppp-live' );

		$providers = array();
		foreach ( $raw as $name ) {
			$name = sanitize_key( $name );
			if ( $name && isset( self::$providers[ $name ] ) ) $providers[] = $name;
		}
		return array_values( array_unique( $providers ) );
	}

	public static function ajax_query() {
		self::authorize();
		$accounts = self::requested_accounts();
		$providers = self::requested_providers();
		if ( ! $accounts ) wp_send_json_success( array( 'records' => array(), 'checked_at' => current_time( 'mysql' ) ) );

		$context = array(
			'query'      => isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '',
			'request_id' => isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '',
		);

		$records = array();
		foreach ( $accounts as $account ) $records[ strtolower( $account ) ] = array( 'account' => $account );

		$errors = array();
		foreach ( $providers as $provider ) {
			$result = call_user_func( self::$providers[ $provider ], $accounts, $context );
			if ( is_wp_error( $result ) ) {
				$errors[ $provider ] = $result->get_error_message();
				continue;
			}
			foreach ( (array) $result as $account_key => $data ) {
				$key = strtolower( trim( (string) $account_key ) );
				if ( isset( $records[ $key ] ) && is_array( $data ) ) $records[ $key ] = array_merge( $records[ $key ], $data );
			}
		}

		$records = apply_filters( 'afc_search_ajaxify_records', $records, $accounts, $providers, $context );
		wp_send_json_success(
			array(
				'records'    => $records,
				'providers'  => $providers,
				'errors'     => $errors,
				'checked_at' => current_time( 'mysql' ),
				'request_id' => $context['request_id'],
			)
		);
	}

	private static function normalize_router_rows( $rows, $single_key = 'name' ) {
		if ( is_wp_error( $rows ) ) return $rows;
		if ( isset( $rows[ $single_key ] ) ) $rows = array( $rows );
		return is_array( $rows ) ? $rows : array();
	}

	private static function parse_comment_pairs( $comment ) {
		$comment = str_replace( array( "\r\n", "\r" ), "\n", (string) $comment );
		$pattern = '/(?:^|\s)([A-Za-z][A-Za-z0-9_-]{0,39})\s*:\s*(.*?)(?=(?:\s+[A-Za-z][A-Za-z0-9_-]{0,39}\s*:)|$)/s';
		preg_match_all( $pattern, trim( $comment ), $matches, PREG_SET_ORDER );
		$pairs = array();
		foreach ( $matches as $match ) {
			$key = trim( (string) $match[1] );
			$value = trim( preg_replace( '/\s+/', ' ', (string) $match[2] ) );
			if ( '' !== $key ) $pairs[ $key ] = 'N/A' === strtoupper( $value ) ? '' : $value;
		}
		return $pairs;
	}

	private static function field_labels() {
		$labels = array();
		if ( class_exists( 'AFC_Comment_Fields' ) ) {
			foreach ( array_merge( AFC_Comment_Fields::get_fields(), AFC_Comment_Fields::suggested_fields() ) as $field ) {
				if ( ! empty( $field['key'] ) ) $labels[ strtolower( $field['key'] ) ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['key'];
			}
		}
		$labels += array(
			'address'       => __( 'Address', 'airfiber-centralized' ),
			'addr'          => __( 'Address', 'airfiber-centralized' ),
			'nextdue'       => __( 'Next Due', 'airfiber-centralized' ),
			'cutoffdate'    => __( 'Cutoff Date', 'airfiber-centralized' ),
			'paymentdate'   => __( 'Payment Date', 'airfiber-centralized' ),
			'paymentamount' => __( 'Payment Amount', 'airfiber-centralized' ),
			'paymentmethod' => __( 'Payment Method', 'airfiber-centralized' ),
		);
		return $labels;
	}

	private static function comment_field( $pairs, $wanted ) {
		$wanted = strtolower( (string) $wanted );
		foreach ( $pairs as $key => $value ) {
			if ( strtolower( (string) $key ) === $wanted ) return trim( (string) $value );
		}
		return '';
	}

	private static function formatted_comment_fields( $pairs ) {
		$labels = self::field_labels();
		$order = array( 'name', 'plan', 'cp', 'address', 'addr', 'installed', 'paymentdate', 'paymentamount', 'paymentmethod', 'nextdue', 'cutoffdate', 'grace', 'wifi' );
		$weight = array_flip( $order );
		$rows = array();
		foreach ( $pairs as $key => $value ) {
			$lower = strtolower( (string) $key );
			$value = trim( (string) $value );
			if ( '' === $value ) continue;
			$sensitive = 'password' === $lower || false !== strpos( $lower, 'password' );
			$rows[] = array(
				'key'       => (string) $key,
				'label'     => isset( $labels[ $lower ] ) ? $labels[ $lower ] : ucwords( preg_replace( '/[_-]+/', ' ', (string) $key ) ),
				'value'     => $sensitive ? '••••••••' : $value,
				'sensitive' => $sensitive,
				'weight'    => isset( $weight[ $lower ] ) ? (int) $weight[ $lower ] : 100,
			);
		}
		usort( $rows, function ( $a, $b ) {
			if ( $a['weight'] === $b['weight'] ) return strcasecmp( $a['label'], $b['label'] );
			return $a['weight'] - $b['weight'];
		} );
		foreach ( $rows as &$row ) unset( $row['weight'] );
		unset( $row );
		return $rows;
	}

	private static function date_value( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $value ) ) return null;
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$parts = array_map( 'intval', explode( '-', $value ) );
		try {
			return new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $parts[0], $parts[1], $parts[2] ), $timezone );
		} catch ( Exception $error ) {
			return null;
		}
	}

	private static function due_state( $profile, $next_due, $cutoff ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ) . ' 00:00:00', $timezone );
		$due = self::date_value( $next_due );
		$cut = self::date_value( $cutoff );
		$days = $due ? (int) $today->diff( $due )->format( '%r%a' ) : null;
		$cut_days = $cut ? (int) $today->diff( $cut )->format( '%r%a' ) : null;

		// Expired is a router state, not a date calculation. Only the actual
		// MikroTik PPP secret profile named "Expired" may produce EXPIRED.
		$expired = 0 === strcasecmp( trim( (string) $profile ), 'expired' );

		if ( $expired ) $state = 'expired';
		elseif ( null !== $days && $days <= 0 ) $state = 'due';
		elseif ( null !== $days && $days <= 3 ) $state = 'soon';
		elseif ( null !== $days && $days <= 7 ) $state = 'upcoming';
		elseif ( null !== $days ) $state = 'safe';
		else $state = 'unknown';

		return array(
			'state'          => $state,
			'expired'        => $expired,
			'days_to_due'    => $days,
			'days_to_cutoff' => $cut_days,
		);
	}

	public static function provide_ppp_live( $accounts, $context = array() ) {
		$wanted = array();
		foreach ( $accounts as $account ) $wanted[ strtolower( trim( (string) $account ) ) ] = true;

		$secrets = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=.id,name,profile,comment,disabled,remote-address,caller-id' ) );
		$secrets = self::normalize_router_rows( $secrets );
		if ( is_wp_error( $secrets ) ) return $secrets;

		$active = AFC_MikroTik::run_command( array( '/ppp/active/print', '=.proplist=name,address,caller-id,uptime,service' ) );
		$active = self::normalize_router_rows( $active );
		if ( is_wp_error( $active ) ) return $active;

		$active_map = array();
		foreach ( $active as $session ) {
			$name = isset( $session['name'] ) ? strtolower( trim( (string) $session['name'] ) ) : '';
			if ( $name && isset( $wanted[ $name ] ) ) $active_map[ $name ] = $session;
		}

		$result = array();
		foreach ( $secrets as $secret ) {
			$account = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			$key = strtolower( $account );
			if ( ! $account || ! isset( $wanted[ $key ] ) ) continue;

			$pairs = self::parse_comment_pairs( isset( $secret['comment'] ) ? $secret['comment'] : '' );
			$next_due = self::comment_field( $pairs, 'nextDue' );
			$cutoff = self::comment_field( $pairs, 'cutoffDate' );
			$profile = isset( $secret['profile'] ) ? (string) $secret['profile'] : '';
			$due = self::due_state( $profile, $next_due, $cutoff );
			$session = isset( $active_map[ $key ] ) ? $active_map[ $key ] : array();
			$caller_id = isset( $session['caller-id'] ) && '' !== trim( (string) $session['caller-id'] )
				? (string) $session['caller-id']
				: ( isset( $secret['caller-id'] ) ? (string) $secret['caller-id'] : '' );

			$result[ $key ] = array(
				'found'          => true,
				'online'         => ! empty( $session ),
				'profile'        => $profile,
				'disabled'       => isset( $secret['disabled'] ) && 'true' === strtolower( (string) $secret['disabled'] ),
				'next_due'       => $next_due,
				'cutoff_date'    => $cutoff,
				'due_state'      => $due['state'],
				'expired'        => $due['expired'],
				'days_to_due'    => $due['days_to_due'],
				'days_to_cutoff' => $due['days_to_cutoff'],
				'customer_name'  => self::comment_field( $pairs, 'name' ),
				'phone'          => self::comment_field( $pairs, 'cp' ),
				'address'        => self::comment_field( $pairs, 'Address' ) ?: self::comment_field( $pairs, 'addr' ),
				'comment_fields' => self::formatted_comment_fields( $pairs ),
				'session'        => array(
					'address'   => isset( $session['address'] ) ? (string) $session['address'] : '',
					'caller_id' => $caller_id,
					'uptime'    => isset( $session['uptime'] ) ? (string) $session['uptime'] : '',
					'service'   => isset( $session['service'] ) ? (string) $session['service'] : '',
				),
			);
		}

		foreach ( $accounts as $account ) {
			$key = strtolower( trim( (string) $account ) );
			if ( ! isset( $result[ $key ] ) ) {
				$result[ $key ] = array(
					'found'          => false,
					'online'         => isset( $active_map[ $key ] ),
					'profile'        => '',
					'disabled'       => false,
					'next_due'       => '',
					'cutoff_date'    => '',
					'due_state'      => 'unknown',
					'expired'        => false,
					'days_to_due'    => null,
					'days_to_cutoff' => null,
					'comment_fields' => array(),
					'session'        => array(),
				);
			}
		}
		return $result;
	}
}
