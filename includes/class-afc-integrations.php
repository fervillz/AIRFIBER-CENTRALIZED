<?php

defined( 'ABSPATH' ) || exit;

/**
 * Advanced-only external integrations. Google service-account credentials are
 * encrypted before storage and are never written into the plugin repository.
 */
class AFC_Integrations {

	const NONCE              = 'afc_integrations';
	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';
	const DEFAULT_SHEET_ID   = '1oIfyeRCE0u_aviBNv7p9WbuFmjLjzvBZ';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 130 );
		add_action( 'wp_ajax_afc_integrations_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_afc_integrations_save_google', array( __CLASS__, 'ajax_save_google' ) );
		add_action( 'wp_ajax_afc_integrations_test_google', array( __CLASS__, 'ajax_test_google' ) );
		add_action( 'wp_ajax_afc_integrations_remove_google', array( __CLASS__, 'ajax_remove_google' ) );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-integrations',
			AFC_URL . 'assets/css/integrations.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-integrations',
			AFC_URL . 'assets/js/integrations.js',
			array( 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-integrations',
			'afcIntegrations',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'spreadsheetId' => self::spreadsheet_id(),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage integrations.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function settings() {
		$value = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function spreadsheet_id() {
		$settings = self::settings();
		$value    = isset( $settings['spreadsheet_id'] ) ? (string) $settings['spreadsheet_id'] : self::DEFAULT_SHEET_ID;
		return preg_match( '/^[A-Za-z0-9_-]{20,}$/', $value ) ? $value : self::DEFAULT_SHEET_ID;
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	private static function encrypt_credentials( $json ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'afc_google_crypto_missing', __( 'OpenSSL is required to store the Google credential securely.', 'airfiber-centralized' ) );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $json, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return new WP_Error( 'afc_google_encrypt_failed', __( 'The Google credential could not be encrypted.', 'airfiber-centralized' ) );
		}
		return array(
			'v'      => 1,
			'iv'     => base64_encode( $iv ),
			'tag'    => base64_encode( $tag ),
			'cipher' => base64_encode( $cipher ),
		);
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
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	private static function validate_credential( $data ) {
		if ( ! is_array( $data ) || 'service_account' !== ( $data['type'] ?? '' ) ) {
			return new WP_Error( 'afc_google_invalid_type', __( 'Choose a valid Google service-account JSON file.', 'airfiber-centralized' ) );
		}
		if ( empty( $data['client_email'] ) || ! is_email( $data['client_email'] ) ) {
			return new WP_Error( 'afc_google_invalid_email', __( 'The JSON file does not contain a valid service-account email.', 'airfiber-centralized' ) );
		}
		if ( empty( $data['private_key'] ) || false === strpos( $data['private_key'], 'BEGIN PRIVATE KEY' ) ) {
			return new WP_Error( 'afc_google_invalid_key', __( 'The JSON file does not contain a valid private key.', 'airfiber-centralized' ) );
		}
		$token_uri = $data['token_uri'] ?? 'https://oauth2.googleapis.com/token';
		if ( 0 !== strpos( $token_uri, 'https://' ) ) {
			return new WP_Error( 'afc_google_invalid_token_uri', __( 'The credential token endpoint is invalid.', 'airfiber-centralized' ) );
		}
		return true;
	}

	private static function base64url( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function access_token( $credentials ) {
		$now       = time();
		$token_uri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
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
		$unsigned = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'afc_google_sign_failed', __( 'The Google authentication request could not be signed.', 'airfiber-centralized' ) );
		}
		$assertion = $unsigned . '.' . self::base64url( $signature );
		$response  = wp_remote_post(
			$token_uri,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
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
		return (string) $body['access_token'];
	}

	private static function google_sheet_error_message( $body, $credentials ) {
		$message = isset( $body['error']['message'] )
			? sanitize_text_field( $body['error']['message'] )
			: __( 'Google could not open the spreadsheet. Share it with the service-account email as Editor.', 'airfiber-centralized' );
		$lower = strtolower( $message );

		if ( false !== strpos( $lower, 'office file' ) || false !== strpos( $lower, 'not supported for this document' ) ) {
			return sprintf(
				/* translators: %s: Google service-account email. */
				__( 'Google authentication worked, but this Drive file is still an Excel/Office file. Open it in Google Sheets, choose File → Save as Google Sheets, share the newly created Google Sheet with %s as Editor, paste its new Spreadsheet ID here, save, and test again.', 'airfiber-centralized' ),
				sanitize_email( $credentials['client_email'] ?? '' )
			);
		}

		return $message;
	}

	private static function test_google() {
		$credentials = self::credentials();
		if ( ! $credentials ) {
			return new WP_Error( 'afc_google_missing', __( 'Upload the Google service-account JSON file first.', 'airfiber-centralized' ) );
		}
		$token = self::access_token( $credentials );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$id       = self::spreadsheet_id();
		$endpoint = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $id ) . '?fields=spreadsheetId,properties.title,sheets.properties.title';
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['spreadsheetId'] ) ) {
			return new WP_Error( 'afc_google_sheet_failed', self::google_sheet_error_message( $body, $credentials ) );
		}
		$tabs = array();
		foreach ( $body['sheets'] ?? array() as $sheet ) {
			if ( ! empty( $sheet['properties']['title'] ) ) {
				$tabs[] = sanitize_text_field( $sheet['properties']['title'] );
			}
		}
		$settings = self::settings();
		$settings['sheet_title']       = sanitize_text_field( $body['properties']['title'] ?? '' );
		$settings['last_success']      = current_time( 'mysql' );
		$settings['last_error']        = '';
		$settings['connected']         = 1;
		$settings['service_email']     = sanitize_email( $credentials['client_email'] );
		$settings['spreadsheet_id']    = $id;
		$settings['spreadsheet_tabs']  = array_slice( $tabs, 0, 30 );
		update_option( self::OPTION_SETTINGS, $settings, false );
		return $settings;
	}

	private static function public_status() {
		$settings    = self::settings();
		$credentials = self::credentials();
		return array(
			'hasCredential' => (bool) $credentials,
			'connected'     => ! empty( $settings['connected'] ),
			'spreadsheetId' => self::spreadsheet_id(),
			'serviceEmail'  => $credentials ? sanitize_email( $credentials['client_email'] ) : ( $settings['service_email'] ?? '' ),
			'sheetTitle'    => $settings['sheet_title'] ?? '',
			'tabs'          => $settings['spreadsheet_tabs'] ?? array(),
			'lastSuccess'   => $settings['last_success'] ?? '',
			'lastError'     => $settings['last_error'] ?? '',
		);
	}

	public static function ajax_status() {
		self::authorize();
		wp_send_json_success( self::public_status() );
	}

	public static function ajax_save_google() {
		self::authorize();
		$spreadsheet_id = isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', $spreadsheet_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid editable Google Spreadsheet ID.', 'airfiber-centralized' ) ) );
		}

		$settings = self::settings();
		$settings['spreadsheet_id'] = $spreadsheet_id;
		$settings['connected']      = 0;
		$settings['last_error']     = '';
		update_option( self::OPTION_SETTINGS, $settings, false );

		if ( ! empty( $_FILES['credential']['tmp_name'] ) ) {
			$file = $_FILES['credential']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 65536 ) {
				wp_send_json_error( array( 'message' => __( 'The credential upload failed or the JSON file is unexpectedly large.', 'airfiber-centralized' ) ) );
			}
			$json = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = json_decode( (string) $json, true );
			$valid = self::validate_credential( $data );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
			}
			$encrypted = self::encrypt_credentials( wp_json_encode( $data ) );
			if ( is_wp_error( $encrypted ) ) {
				wp_send_json_error( array( 'message' => $encrypted->get_error_message() ) );
			}
			update_option( self::OPTION_CREDENTIALS, $encrypted, false );
			$settings['service_email'] = sanitize_email( $data['client_email'] );
			update_option( self::OPTION_SETTINGS, $settings, false );
		} elseif ( ! self::credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Select the airfiber-google-sheets-service-account.json file.', 'airfiber-centralized' ) ) );
		}

		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'Google Sheets settings were saved securely.', 'airfiber-centralized' ) ) ) );
	}

	public static function ajax_test_google() {
		self::authorize();
		$result = self::test_google();
		if ( is_wp_error( $result ) ) {
			$settings = self::settings();
			$settings['connected']  = 0;
			$settings['last_error'] = sanitize_text_field( $result->get_error_message() );
			update_option( self::OPTION_SETTINGS, $settings, false );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'status' => self::public_status() ) );
		}
		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'Google Sheets is connected and the spreadsheet is accessible.', 'airfiber-centralized' ) ) ) );
	}

	public static function ajax_remove_google() {
		self::authorize();
		delete_option( self::OPTION_CREDENTIALS );
		$settings = self::settings();
		$settings['connected']     = 0;
		$settings['service_email'] = '';
		$settings['sheet_title']   = '';
		$settings['last_success']  = '';
		$settings['last_error']    = '';
		update_option( self::OPTION_SETTINGS, $settings, false );
		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'The stored Google credential was removed.', 'airfiber-centralized' ) ) ) );
	}
}
