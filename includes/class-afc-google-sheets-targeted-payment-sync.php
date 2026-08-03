<?php

defined( 'ABSPATH' ) || exit;

/**
 * Updates only the paid customer's Jan-Dec cells after a payment.
 *
 * The normal Google payment queue still owns the transaction row, last-payment
 * fields, retry status, and current-month update. This class replaces the old
 * per-payment full reconciliation with one targeted yearly-row update.
 */
class AFC_Google_Sheets_Targeted_Payment_Sync {

	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';
	const CRON_TARGETED      = 'afc_google_paid_history_targeted';
	const MAX_ROWS           = 5000;

	public static function init() {
		// Version 2.3.4 scheduled a full PPP reconciliation after every payment.
		// Remove it and replace it with a single-row update.
		remove_action( 'afc_payment_recorded', array( 'AFC_Google_Sheets_Paid_History', 'schedule_refresh' ), 30 );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'schedule' ), 31, 2 );
		add_action( self::CRON_TARGETED, array( __CLASS__, 'sync_payment_row' ), 10, 3 );
	}

	public static function schedule( $payment_id, $customer_id ) {
		$payment_id  = absint( $payment_id );
		$customer_id = absint( $customer_id );
		if ( ! $payment_id || ! self::auto_sync_enabled() ) {
			return;
		}

		$args = array( $payment_id, $customer_id, 0 );
		if ( ! wp_next_scheduled( self::CRON_TARGETED, $args ) ) {
			// The main payment queue starts after ten seconds. Give it time to create
			// a missing yearly customer row before filling that row's history.
			wp_schedule_single_event( time() + 25, self::CRON_TARGETED, $args );
		}
	}

	private static function retry( $payment_id, $customer_id, $attempt, $message ) {
		$payment_id = absint( $payment_id );
		$attempt     = absint( $attempt );
		if ( $payment_id ) {
			update_post_meta( $payment_id, '_afc_google_history_sync_error', sanitize_text_field( $message ) );
		}
		if ( $attempt >= 3 || ! self::auto_sync_enabled() ) {
			return;
		}

		$next_attempt = $attempt + 1;
		$args         = array( $payment_id, absint( $customer_id ), $next_attempt );
		if ( ! wp_next_scheduled( self::CRON_TARGETED, $args ) ) {
			$delay = 2 === $next_attempt ? 5 * MINUTE_IN_SECONDS : 2 * MINUTE_IN_SECONDS;
			wp_schedule_single_event( time() + $delay, self::CRON_TARGETED, $args );
		}
	}

	public static function sync_payment_row( $payment_id, $customer_id = 0, $attempt = 0 ) {
		$payment_id  = absint( $payment_id );
		$customer_id = absint( $customer_id );
		$attempt     = absint( $attempt );
		if ( ! $payment_id || ! self::connected() || ! self::auto_sync_enabled() ) {
			return;
		}

		$username = sanitize_text_field( (string) get_post_meta( $payment_id, '_afc_ppp_username', true ) );
		if ( ! $username && $customer_id ) {
			$username = sanitize_text_field( (string) get_post_meta( $customer_id, '_afc_ppp_username', true ) );
		}
		$paid_date = self::valid_date( get_post_meta( $payment_id, '_afc_payment_date', true ) );
		if ( ! $username || ! $paid_date ) {
			self::retry( $payment_id, $customer_id, $attempt, __( 'The PPP username or payment date is missing.', 'airfiber-centralized' ) );
			return;
		}

		$year = (int) substr( $paid_date, 0, 4 );
		$row  = self::find_account_row( $year, $username );
		if ( is_wp_error( $row ) ) {
			self::retry( $payment_id, $customer_id, $attempt, $row->get_error_message() );
			return;
		}
		if ( ! $row ) {
			self::retry( $payment_id, $customer_id, $attempt, __( 'The PPP account row is not ready in Google Sheets yet.', 'airfiber-centralized' ) );
			return;
		}

		$account = self::account_state( $username, $customer_id, $paid_date );
		if ( is_wp_error( $account ) ) {
			self::retry( $payment_id, $customer_id, $attempt, $account->get_error_message() );
			return;
		}

		$range = self::paid_month_range( $account, $year );
		if ( ! $range[0] || ! $range[1] ) {
			self::retry( $payment_id, $customer_id, $attempt, __( 'The installation or paid-through date could not be resolved.', 'airfiber-centralized' ) );
			return;
		}

		$month_values = self::read_values( self::a1_title( (string) $year ) . '!H' . $row . ':S' . $row );
		if ( is_wp_error( $month_values ) ) {
			self::retry( $payment_id, $customer_id, $attempt, $month_values->get_error_message() );
			return;
		}
		$months = isset( $month_values[0] ) && is_array( $month_values[0] ) ? array_values( $month_values[0] ) : array();
		$months = array_pad( array_slice( $months, 0, 12 ), 12, '' );
		for ( $month = $range[0]; $month <= $range[1]; $month++ ) {
			$months[ $month - 1 ] = 'PAID';
		}

		$result = self::update_values(
			self::a1_title( (string) $year ) . '!H' . $row . ':S' . $row,
			array( $months )
		);
		if ( is_wp_error( $result ) ) {
			self::retry( $payment_id, $customer_id, $attempt, $result->get_error_message() );
			return;
		}

		delete_post_meta( $payment_id, '_afc_google_history_sync_error' );
		update_post_meta( $payment_id, '_afc_google_history_synced_at', current_time( 'mysql' ) );
	}

	private static function settings() {
		$value = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function connected() {
		$settings = self::settings();
		return ! empty( $settings['connected'] ) && (bool) self::credentials() && (bool) self::spreadsheet_id();
	}

	private static function auto_sync_enabled() {
		$settings = self::settings();
		return ! isset( $settings['google_auto_sync'] ) || ! empty( $settings['google_auto_sync'] );
	}

	private static function spreadsheet_id() {
		$settings = self::settings();
		$value    = isset( $settings['spreadsheet_id'] ) ? (string) $settings['spreadsheet_id'] : '';
		return preg_match( '/^[A-Za-z0-9_-]{20,}$/', $value ) ? $value : '';
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	private static function credentials() {
		$stored = get_option( self::OPTION_CREDENTIALS, array() );
		if ( ! is_array( $stored ) || empty( $stored['iv'] ) || empty( $stored['tag'] ) || empty( $stored['cipher'] ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$json = openssl_decrypt(
			base64_decode( $stored['cipher'], true ),
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			base64_decode( $stored['iv'], true ),
			base64_decode( $stored['tag'], true )
		);
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	private static function base64url( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function access_token() {
		$credentials = self::credentials();
		if ( ! $credentials || empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
			return new WP_Error( 'afc_google_missing', __( 'The Google credential is missing.', 'airfiber-centralized' ) );
		}

		$cache_key = 'afc_gsheet_token_' . substr( md5( $credentials['client_email'] ), 0, 20 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$now       = time();
		$token_uri = isset( $credentials['token_uri'] ) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token';
		$header    = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims    = self::base64url(
			wp_json_encode(
				array(
					'iss'   => $credentials['client_email'],
					'scope' => 'https://www.googleapis.com/auth/spreadsheets',
					'aud'   => $token_uri,
					'iat'   => $now - 30,
					'exp'   => $now + 3500,
				)
			)
		);
		$unsigned  = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'afc_google_sign_failed', __( 'The Google authentication request could not be signed.', 'airfiber-centralized' ) );
		}

		$response = wp_remote_post(
			$token_uri,
			array(
				'timeout' => 25,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $unsigned . '.' . self::base64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google rejected the service-account credential.', 'airfiber-centralized' );
			return new WP_Error( 'afc_google_token_failed', sanitize_text_field( $message ) );
		}
		set_transient( $cache_key, (string) $body['access_token'], 3300 );
		return (string) $body['access_token'];
	}

	private static function google_request( $method, $path, $body = null, $query = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = 'https://sheets.googleapis.com' . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( __( 'Google Sheets returned HTTP %d.', 'airfiber-centralized' ), $code );
			return new WP_Error( 'afc_google_targeted_error', sanitize_text_field( $message ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function a1_title( $title ) {
		return "'" . str_replace( "'", "''", (string) $title ) . "'";
	}

	private static function read_values( $range ) {
		$id = self::spreadsheet_id();
		$result = self::google_request(
			'GET',
			'/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ),
			null,
			array( 'majorDimension' => 'ROWS' )
		);
		return is_wp_error( $result ) ? $result : ( isset( $result['values'] ) && is_array( $result['values'] ) ? $result['values'] : array() );
	}

	private static function update_values( $range, $values ) {
		$id = self::spreadsheet_id();
		return self::google_request(
			'PUT',
			'/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ),
			array( 'range' => $range, 'majorDimension' => 'ROWS', 'values' => $values ),
			array( 'valueInputOption' => 'USER_ENTERED' )
		);
	}

	private static function find_account_row( $year, $username ) {
		$values = self::read_values( self::a1_title( (string) $year ) . '!A2:A' . self::MAX_ROWS );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		foreach ( $values as $index => $row ) {
			if ( isset( $row[0] ) && (string) $row[0] === (string) $username ) {
				return $index + 2;
			}
		}
		return 0;
	}

	private static function valid_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	private static function raw_comment_date( $comment, $key ) {
		$key = preg_quote( (string) $key, '/' );
		return preg_match( '/(?:^|\s)' . $key . '\s*:\s*(\d{4}-\d{2}-\d{2})(?=\s|$)/i', (string) $comment, $match )
			? self::valid_date( $match[1] )
			: '';
	}

	private static function custom_value( $details, $key ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $field_key => $value ) {
			if ( 0 === strcasecmp( (string) $field_key, (string) $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function latest_date( $dates ) {
		$latest = '';
		foreach ( $dates as $date ) {
			$date = self::valid_date( $date );
			if ( $date && ( ! $latest || $date > $latest ) ) {
				$latest = $date;
			}
		}
		return $latest;
	}

	private static function account_state( $username, $customer_id, $paid_date ) {
		$secret = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'?name=' . $username,
				'=.proplist=name,profile,comment,disabled',
			)
		);
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		if ( isset( $secret['name'] ) ) {
			$matches = array( $secret );
		} else {
			$matches = is_array( $secret ) ? $secret : array();
		}
		$secret = array();
		foreach ( $matches as $candidate ) {
			if ( isset( $candidate['name'] ) && (string) $candidate['name'] === (string) $username ) {
				$secret = $candidate;
				break;
			}
		}

		$comment      = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details      = class_exists( 'AFC_Comment_Fields' ) ? AFC_Comment_Fields::parse_comment( $comment ) : array();
		$installed    = self::valid_date( isset( $details['installed'] ) ? $details['installed'] : '' );
		$payment_date = self::valid_date( isset( $details['payment_date'] ) ? $details['payment_date'] : '' );
		$paid_through = self::valid_date( self::custom_value( $details, 'paidThrough' ) );
		$next_due     = self::valid_date( self::custom_value( $details, 'nextDue' ) );
		if ( ! $installed && $customer_id ) {
			$installed = self::valid_date( get_post_meta( $customer_id, '_afc_installation_date', true ) );
		}
		if ( ! $paid_through ) {
			$paid_through = self::raw_comment_date( $comment, 'paidThrough' );
		}
		if ( ! $next_due ) {
			$next_due = self::raw_comment_date( $comment, 'nextDue' );
		}

		$profile  = isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '';
		$disabled = isset( $secret['disabled'] ) && 'true' === strtolower( (string) $secret['disabled'] );
		$expired  = $disabled || 0 === strcasecmp( $profile, 'Expired' );
		$candidates = array( $paid_date, $payment_date, $paid_through );
		if ( ! $expired && $next_due ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$next = DateTimeImmutable::createFromFormat( '!Y-m-d', $next_due, $timezone );
			if ( $next ) {
				$candidates[] = $next->modify( 'first day of this month' )->modify( '-1 month' )->format( 'Y-m-d' );
			}
		}

		return array(
			'installed'    => $installed,
			'coverage_end' => self::latest_date( $candidates ),
		);
	}

	private static function paid_month_range( $account, $year ) {
		if ( empty( $account['installed'] ) || empty( $account['coverage_end'] ) ) {
			return array( 0, 0 );
		}
		$installed_year  = (int) substr( $account['installed'], 0, 4 );
		$installed_month = (int) substr( $account['installed'], 5, 2 );
		$coverage_year   = (int) substr( $account['coverage_end'], 0, 4 );
		$coverage_month  = (int) substr( $account['coverage_end'], 5, 2 );
		if ( $installed_year > $year || $coverage_year < $year ) {
			return array( 0, 0 );
		}
		$start = $installed_year < $year ? 1 : max( 1, min( 12, $installed_month ) );
		$end   = $coverage_year > $year ? 12 : max( 1, min( 12, $coverage_month ) );
		return $end >= $start ? array( $start, $end ) : array( 0, 0 );
	}
}
