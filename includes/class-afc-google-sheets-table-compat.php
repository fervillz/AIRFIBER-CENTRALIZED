<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility fixes for Google Sheets created from the original Excel file.
 *
 * Converted workbooks can retain native Google table objects. Google does not
 * allow data validation on a table header row, so the table wrappers are
 * removed from Airfiber-managed tabs before preparation. Google also supports
 * fewer CellFormat properties inside conditional rules than normal cell
 * formatting; unsupported alignment keys are stripped before requests leave
 * WordPress.
 */
class AFC_Google_Sheets_Table_Compat {

	const NONCE              = 'afc_integrations';
	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';

	public static function init() {
		add_action( 'wp_ajax_afc_google_prepare_sheet', array( __CLASS__, 'before_prepare' ), 1 );
		add_filter( 'http_request_args', array( __CLASS__, 'sanitize_conditional_formats' ), 20, 2 );
	}

	/**
	 * ConditionalFormatRule.format only accepts background and limited text
	 * styling. Alignment is valid for repeatCell, but Google rejects it inside
	 * addConditionalFormatRule. Keep only the officially supported properties.
	 */
	public static function sanitize_conditional_formats( $args, $url ) {
		if ( false === strpos( (string) $url, 'https://sheets.googleapis.com/' ) || false === strpos( (string) $url, ':batchUpdate' ) ) {
			return $args;
		}
		if ( empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
			return $args;
		}

		$payload = json_decode( $args['body'], true );
		if ( ! is_array( $payload ) || empty( $payload['requests'] ) || ! is_array( $payload['requests'] ) ) {
			return $args;
		}

		$changed = false;
		foreach ( $payload['requests'] as &$request ) {
			if ( empty( $request['addConditionalFormatRule']['rule']['booleanRule']['format'] ) || ! is_array( $request['addConditionalFormatRule']['rule']['booleanRule']['format'] ) ) {
				continue;
			}

			$format = $request['addConditionalFormatRule']['rule']['booleanRule']['format'];
			$clean  = array();
			if ( isset( $format['backgroundColor'] ) ) {
				$clean['backgroundColor'] = $format['backgroundColor'];
			}
			if ( isset( $format['backgroundColorStyle'] ) ) {
				$clean['backgroundColorStyle'] = $format['backgroundColorStyle'];
			}
			if ( ! empty( $format['textFormat'] ) && is_array( $format['textFormat'] ) ) {
				$text_format = array();
				foreach ( array( 'bold', 'italic', 'strikethrough', 'foregroundColor', 'foregroundColorStyle' ) as $key ) {
					if ( array_key_exists( $key, $format['textFormat'] ) ) {
						$text_format[ $key ] = $format['textFormat'][ $key ];
					}
				}
				if ( $text_format ) {
					$clean['textFormat'] = $text_format;
				}
			}

			if ( $clean !== $format ) {
				$request['addConditionalFormatRule']['rule']['booleanRule']['format'] = $clean;
				$changed = true;
			}
		}
		unset( $request );

		if ( $changed ) {
			$args['body'] = wp_json_encode( $payload );
		}
		return $args;
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
					'grant_type' => 'urn:ietf:params:oauth2:grant-type:jwt-bearer',
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

		$managed  = array( (string) current_time( 'Y' ), 'Transactions' );
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
