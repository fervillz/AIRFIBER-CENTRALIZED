<?php

defined( 'ABSPATH' ) || exit;

/**
 * Converted Excel workbooks can retain native Google "table" objects. Google
 * does not allow the prepare request to apply data validation to a table header
 * row, even when the requested grid range starts below row one. Remove only the
 * table objects from Airfiber-managed tabs before the normal preparation runs;
 * cell values remain in place and Airfiber reapplies its own formatting.
 */
class AFC_Google_Sheets_Table_Compat {

	const NONCE              = 'afc_integrations';
	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';

	public static function init() {
		add_action( 'wp_ajax_afc_google_prepare_sheet', array( __CLASS__, 'before_prepare' ), 1 );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to prepare Google Sheets.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function settings() {
		$value = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $value ) ? $value : array();
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
			return new WP_Error( 'afc_google_missing', __( 'The Google credential is missing. Save and test the connection first.', 'airfiber-centralized' ) );
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

	private static function request( $method, $url, $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 60,
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
			return new WP_Error( 'afc_google_table_cleanup_failed', sanitize_text_field( $message ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function before_prepare() {
		self::authorize();
		$id = self::spreadsheet_id();
		if ( ! $id || ! self::credentials() ) {
			return;
		}

		$fields = rawurlencode( 'sheets(properties(sheetId,title),tables(tableId,name,range))' );
		$meta   = self::request(
			'GET',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $id ) . '?fields=' . $fields
		);
		if ( is_wp_error( $meta ) ) {
			wp_send_json_error( array( 'message' => $meta->get_error_message() ) );
		}

		$managed = array( (string) current_time( 'Y' ), 'Transactions' );
		$requests = array();
		foreach ( isset( $meta['sheets'] ) && is_array( $meta['sheets'] ) ? $meta['sheets'] : array() as $sheet ) {
			$title = isset( $sheet['properties']['title'] ) ? (string) $sheet['properties']['title'] : '';
			if ( ! in_array( $title, $managed, true ) ) {
				continue;
			}
			foreach ( isset( $sheet['tables'] ) && is_array( $sheet['tables'] ) ? $sheet['tables'] : array() as $table ) {
				if ( ! empty( $table['tableId'] ) ) {
					$requests[] = array( 'deleteTable' => array( 'tableId' => (string) $table['tableId'] ) );
				}
			}
		}

		if ( ! $requests ) {
			return;
		}

		$result = self::request(
			'POST',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $id ) . ':batchUpdate',
			array( 'requests' => $requests )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Google API error message. */
						__( 'Airfiber found an imported Google table that blocks preparation, but could not remove the table wrapper: %s', 'airfiber-centralized' ),
						$result->get_error_message()
					),
				)
			);
		}
	}
}
