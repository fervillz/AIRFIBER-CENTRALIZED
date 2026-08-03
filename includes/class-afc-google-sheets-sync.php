<?php

defined( 'ABSPATH' ) || exit;

/**
 * Google Sheets reporting mirror.
 *
 * WordPress remains the payment ledger and MikroTik remains the source for PPP
 * and service state. Google failures are queued and never block a payment.
 */
class AFC_Google_Sheets_Sync {

	const NONCE              = 'afc_integrations';
	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';
	const OPTION_QUEUE       = 'afc_google_sheets_sync_queue';
	const CRON_QUEUE         = 'afc_google_sheets_process_queue';
	const CRON_DAILY         = 'afc_google_sheets_daily_reconcile';
	const MAX_ROWS           = 5000;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_config' ), 131 );
		add_action( 'wp_ajax_afc_google_sync_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_afc_google_prepare_sheet', array( __CLASS__, 'ajax_prepare_sheet' ) );
		add_action( 'wp_ajax_afc_google_sync_customers', array( __CLASS__, 'ajax_sync_customers' ) );
		add_action( 'wp_ajax_afc_google_reconcile', array( __CLASS__, 'ajax_reconcile' ) );
		add_action( 'wp_ajax_afc_google_retry_queue', array( __CLASS__, 'ajax_retry_queue' ) );
		add_action( 'wp_ajax_afc_google_set_auto_sync', array( __CLASS__, 'ajax_set_auto_sync' ) );
		add_action( 'admin_post_afc_google_download_backup', array( __CLASS__, 'download_backup' ) );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'queue_payment' ), 10, 2 );
		add_action( self::CRON_QUEUE, array( __CLASS__, 'process_queue' ) );
		add_action( self::CRON_DAILY, array( __CLASS__, 'daily_reconcile' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_QUEUE );
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	public static function enqueue_config() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_localize_script(
			'afc-integrations',
			'afcGoogleSync',
			array(
				'year'        => (int) current_time( 'Y' ),
				'downloadUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=afc_google_download_backup' ), 'afc_google_download_backup' ),
			)
		);
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
		if ( self::queue() && ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_QUEUE );
		}
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

	private static function save_settings( $changes ) {
		$settings = array_merge( self::settings(), is_array( $changes ) ? $changes : array() );
		update_option( self::OPTION_SETTINGS, $settings, false );
		return $settings;
	}

	private static function connected() {
		$settings = self::settings();
		return ! empty( $settings['connected'] ) && (bool) self::credentials();
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
			return new WP_Error( 'afc_google_api_error', sanitize_text_field( $message ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function a1_title( $title ) {
		return "'" . str_replace( "'", "''", (string) $title ) . "'";
	}

	private static function column_letter( $number ) {
		$result = '';
		while ( $number > 0 ) {
			$number--;
			$result = chr( 65 + ( $number % 26 ) ) . $result;
			$number = (int) floor( $number / 26 );
		}
		return $result;
	}

	private static function metadata() {
		$id = self::spreadsheet_id();
		if ( ! $id ) {
			return new WP_Error( 'afc_google_sheet_missing', __( 'Save and test a Google Spreadsheet ID first.', 'airfiber-centralized' ) );
		}
		return self::google_request(
			'GET',
			'/v4/spreadsheets/' . rawurlencode( $id ),
			null,
			array( 'fields' => 'spreadsheetId,properties.title,sheets(properties(sheetId,title,gridProperties),conditionalFormats)' )
		);
	}

	private static function sheet_map( $metadata ) {
		$map = array();
		foreach ( isset( $metadata['sheets'] ) ? $metadata['sheets'] : array() as $sheet ) {
			if ( ! empty( $sheet['properties']['title'] ) ) {
				$map[ $sheet['properties']['title'] ] = $sheet;
			}
		}
		return $map;
	}

	private static function year_headers() {
		return array(
			'PPP Account', 'Customer Name', 'Phone', 'Address', 'Plan', 'Billing Day', 'Account Status',
			'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
			'Last Payment', 'Amount', 'Method', 'Last Synced', 'Source',
		);
	}

	private static function transaction_headers() {
		return array( 'Payment ID', 'Date & Time', 'PPP Account', 'Customer Name', 'Billing Month', 'Amount', 'Method', 'Recorded By', 'Sync Status', 'WordPress Payment ID' );
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

	private static function read_values( $range ) {
		$id = self::spreadsheet_id();
		$result = self::google_request( 'GET', '/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ), null, array( 'majorDimension' => 'ROWS' ) );
		return is_wp_error( $result ) ? $result : ( isset( $result['values'] ) && is_array( $result['values'] ) ? $result['values'] : array() );
	}

	private static function clear_values( $range ) {
		$id = self::spreadsheet_id();
		return self::google_request( 'POST', '/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ) . ':clear', new stdClass() );
	}

	private static function append_values( $range, $values ) {
		$id = self::spreadsheet_id();
		return self::google_request(
			'POST',
			'/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ) . ':append',
			array( 'range' => $range, 'majorDimension' => 'ROWS', 'values' => $values ),
			array( 'valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS' )
		);
	}

	private static function prepare_sheet( $year = 0, $force = false ) {
		if ( ! self::connected() ) {
			return new WP_Error( 'afc_google_not_connected', __( 'Test the Google Sheets connection before preparing the spreadsheet.', 'airfiber-centralized' ) );
		}
		$year     = $year ? absint( $year ) : (int) current_time( 'Y' );
		$title    = (string) $year;
		$meta     = self::metadata();
		$settings = self::settings();
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$map      = self::sheet_map( $meta );
		$requests = array();
		$created  = false;
		if ( ! isset( $map[ $title ] ) ) {
			$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => $title, 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 24, 'frozenRowCount' => 1 ) ) ) );
			$created = true;
		}
		if ( ! isset( $map['Transactions'] ) ) {
			$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => 'Transactions', 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 10, 'frozenRowCount' => 1 ) ) ) );
			$created = true;
		}
		if ( $requests ) {
			$result = self::google_request( 'POST', '/v4/spreadsheets/' . rawurlencode( self::spreadsheet_id() ) . ':batchUpdate', array( 'requests' => $requests ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$meta = self::metadata();
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			$map = self::sheet_map( $meta );
		}

		$year_sheet = isset( $map[ $title ] ) ? $map[ $title ] : null;
		$tx_sheet   = isset( $map['Transactions'] ) ? $map['Transactions'] : null;
		if ( ! $year_sheet || ! $tx_sheet ) {
			return new WP_Error( 'afc_google_tabs_missing', __( 'The yearly and Transactions tabs could not be created.', 'airfiber-centralized' ) );
		}

		$year_grid = isset( $year_sheet['properties']['gridProperties'] ) ? $year_sheet['properties']['gridProperties'] : array();
		$tx_grid   = isset( $tx_sheet['properties']['gridProperties'] ) ? $tx_sheet['properties']['gridProperties'] : array();
		$grid_small =
			( isset( $year_grid['rowCount'] ) ? (int) $year_grid['rowCount'] : 0 ) < self::MAX_ROWS ||
			( isset( $year_grid['columnCount'] ) ? (int) $year_grid['columnCount'] : 0 ) < 24 ||
			( isset( $tx_grid['rowCount'] ) ? (int) $tx_grid['rowCount'] : 0 ) < self::MAX_ROWS ||
			( isset( $tx_grid['columnCount'] ) ? (int) $tx_grid['columnCount'] : 0 ) < 10;
		$already_prepared = isset( $settings['google_prepared_year'] ) && (int) $settings['google_prepared_year'] === $year;
		if ( ! $force && ! $created && ! $grid_small && $already_prepared ) {
			return array( 'year' => $year, 'yearTab' => $title, 'transactionTab' => 'Transactions', 'prepared' => true );
		}

		$result = self::update_values( self::a1_title( $title ) . '!A1:X1', array( self::year_headers() ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = self::update_values( self::a1_title( 'Transactions' ) . '!A1:J1', array( self::transaction_headers() ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$year_id = (int) $year_sheet['properties']['sheetId'];
		$tx_id   = (int) $tx_sheet['properties']['sheetId'];
		$format  = array();
		$conditional_count = isset( $year_sheet['conditionalFormats'] ) && is_array( $year_sheet['conditionalFormats'] ) ? count( $year_sheet['conditionalFormats'] ) : 0;
		for ( $index = $conditional_count - 1; $index >= 0; $index-- ) {
			$format[] = array( 'deleteConditionalFormatRule' => array( 'sheetId' => $year_id, 'index' => $index ) );
		}

		$sheet_specs = array(
			array( 'id' => $year_id, 'columns' => 24, 'rows' => max( self::MAX_ROWS, isset( $year_grid['rowCount'] ) ? (int) $year_grid['rowCount'] : 0 ) ),
			array( 'id' => $tx_id, 'columns' => 10, 'rows' => max( self::MAX_ROWS, isset( $tx_grid['rowCount'] ) ? (int) $tx_grid['rowCount'] : 0 ) ),
		);
		foreach ( $sheet_specs as $spec ) {
			$format[] = array(
				'updateSheetProperties' => array(
					'properties' => array( 'sheetId' => $spec['id'], 'gridProperties' => array( 'frozenRowCount' => 1, 'rowCount' => $spec['rows'], 'columnCount' => $spec['columns'] ) ),
					'fields'     => 'gridProperties(frozenRowCount,rowCount,columnCount)',
				),
			);
			$format[] = array(
				'repeatCell' => array(
					'range'  => array( 'sheetId' => $spec['id'], 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $spec['columns'] ),
					'cell'   => array( 'userEnteredFormat' => array( 'backgroundColor' => array( 'red' => 0.125, 'green' => 0.42, 'blue' => 0.77 ), 'textFormat' => array( 'foregroundColor' => array( 'red' => 1, 'green' => 1, 'blue' => 1 ), 'bold' => true ), 'horizontalAlignment' => 'CENTER', 'verticalAlignment' => 'MIDDLE' ) ),
					'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
				),
			);
		}
		$format[] = array(
			'setDataValidation' => array(
				'range' => array( 'sheetId' => $year_id, 'startRowIndex' => 1, 'endRowIndex' => self::MAX_ROWS, 'startColumnIndex' => 7, 'endColumnIndex' => 19 ),
				'rule'  => array( 'condition' => array( 'type' => 'ONE_OF_LIST', 'values' => array( array( 'userEnteredValue' => 'PAID' ), array( 'userEnteredValue' => 'DUE' ), array( 'userEnteredValue' => 'EXPIRED' ) ) ), 'strict' => true, 'showCustomUi' => true ),
			),
		);
		$colors = array(
			'PAID'    => array( array( 'red' => 0.84, 'green' => 0.95, 'blue' => 0.86 ), array( 'red' => 0.08, 'green' => 0.42, 'blue' => 0.18 ) ),
			'DUE'     => array( array( 'red' => 1, 'green' => 0.92, 'blue' => 0.73 ), array( 'red' => 0.60, 'green' => 0.34, 'blue' => 0.00 ) ),
			'EXPIRED' => array( array( 'red' => 0.98, 'green' => 0.80, 'blue' => 0.80 ), array( 'red' => 0.68, 'green' => 0.10, 'blue' => 0.10 ) ),
		);
		foreach ( $colors as $value => $palette ) {
			$format[] = array(
				'addConditionalFormatRule' => array(
					'index' => 0,
					'rule'  => array(
						'ranges'      => array( array( 'sheetId' => $year_id, 'startRowIndex' => 1, 'endRowIndex' => self::MAX_ROWS, 'startColumnIndex' => 7, 'endColumnIndex' => 19 ) ),
						'booleanRule' => array( 'condition' => array( 'type' => 'TEXT_EQ', 'values' => array( array( 'userEnteredValue' => $value ) ) ), 'format' => array( 'backgroundColor' => $palette[0], 'textFormat' => array( 'foregroundColor' => $palette[1], 'bold' => true ), 'horizontalAlignment' => 'CENTER' ) ),
					),
				),
			);
		}
		$format[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $year_id, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 24 ), 'properties' => array( 'pixelSize' => 120 ), 'fields' => 'pixelSize' ) );
		$format[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $year_id, 'dimension' => 'COLUMNS', 'startIndex' => 1, 'endIndex' => 4 ), 'properties' => array( 'pixelSize' => 185 ), 'fields' => 'pixelSize' ) );
		$format[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $year_id, 'dimension' => 'COLUMNS', 'startIndex' => 7, 'endIndex' => 19 ), 'properties' => array( 'pixelSize' => 82 ), 'fields' => 'pixelSize' ) );

		$result = self::google_request( 'POST', '/v4/spreadsheets/' . rawurlencode( self::spreadsheet_id() ) . ':batchUpdate', array( 'requests' => $format ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::save_settings( array( 'google_prepared_year' => $year, 'google_last_prepare' => current_time( 'mysql' ) ) );
		return array( 'year' => $year, 'yearTab' => $title, 'transactionTab' => 'Transactions', 'prepared' => true );
	}

	private static function parse_comment( $comment ) {
		$values = array(
			'installed' => '', 'grace' => '', 'paymentMethod' => '', 'paymentAmount' => '', 'paymentDate' => '',
			'name' => '', 'plan' => '', 'cp' => '', 'wifi' => '', 'Address' => '', 'nextDue' => '', 'cutoffDate' => '',
			'dueReminderDate' => '', 'paidThrough' => '',
		);
		$keys = implode( '|', array_map( function ( $key ) { return preg_quote( $key, '/' ); }, array_keys( $values ) ) );
		preg_match_all( '/(?:^|\s)(' . $keys . ')\s*:\s*(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is', trim( (string) $comment ), $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$key = isset( $match[1] ) ? $match[1] : '';
			if ( array_key_exists( $key, $values ) ) {
				$value = trim( preg_replace( '/\s+/', ' ', isset( $match[2] ) ? $match[2] : '' ) );
				$values[ $key ] = 'N/A' === strtoupper( $value ) ? '' : $value;
			}
		}
		return $values;
	}

	private static function router_users() {
		$secrets = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=.id,name,profile,comment,disabled' ) );
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}
		$users = array();
		foreach ( is_array( $secrets ) ? $secrets : array() as $secret ) {
			$username = isset( $secret['name'] ) ? sanitize_text_field( $secret['name'] ) : '';
			if ( ! $username ) {
				continue;
			}
			$details = self::parse_comment( isset( $secret['comment'] ) ? $secret['comment'] : '' );
			$users[] = array(
				'username'       => $username,
				'customer_name'  => sanitize_text_field( $details['name'] ? $details['name'] : $username ),
				'phone'          => sanitize_text_field( $details['cp'] ),
				'address'        => sanitize_textarea_field( $details['Address'] ),
				'plan'           => sanitize_text_field( $details['plan'] ? $details['plan'] : ( isset( $secret['profile'] ) ? $secret['profile'] : '' ) ),
				'actual_profile' => sanitize_text_field( isset( $secret['profile'] ) ? $secret['profile'] : '' ),
				'disabled'       => isset( $secret['disabled'] ) && 'true' === $secret['disabled'],
				'installed'      => sanitize_text_field( $details['installed'] ),
				'next_due'       => sanitize_text_field( $details['nextDue'] ),
				'paid_through'   => sanitize_text_field( $details['paidThrough'] ),
				'payment_date'   => sanitize_text_field( $details['paymentDate'] ),
				'payment_amount' => (float) $details['paymentAmount'],
				'payment_method' => sanitize_text_field( $details['paymentMethod'] ),
			);
		}
		usort( $users, function ( $a, $b ) { return strcasecmp( $a['customer_name'], $b['customer_name'] ); } );
		return $users;
	}

	private static function month_status( $user, $year, $month, $existing ) {
		$existing = strtoupper( trim( (string) $existing ) );
		if ( 'PAID' === $existing ) {
			return 'PAID';
		}
		$prefix = sprintf( '%04d-%02d', $year, $month );
		if ( 0 === strpos( (string) $user['payment_date'], $prefix ) || 0 === strpos( (string) $user['paid_through'], $prefix ) ) {
			return 'PAID';
		}
		if ( $prefix !== current_time( 'Y-m' ) ) {
			return in_array( $existing, array( 'DUE', 'EXPIRED' ), true ) ? $existing : '';
		}
		if ( 0 === strcasecmp( $user['actual_profile'], 'Expired' ) ) {
			return 'EXPIRED';
		}
		if ( $user['next_due'] ) {
			$due = strtotime( $user['next_due'] . ' 23:59:59' );
			if ( $due && $due <= current_time( 'timestamp' ) + ( 7 * DAY_IN_SECONDS ) ) {
				return 'DUE';
			}
		}
		return '';
	}

	private static function customer_rows( $users, $existing_rows, $year ) {
		$existing = array();
		foreach ( $existing_rows as $row ) {
			$key = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			if ( $key ) {
				$existing[ $key ] = array_pad( $row, 24, '' );
			}
		}
		$rows = array();
		foreach ( $users as $user ) {
			$old = isset( $existing[ $user['username'] ] ) ? $existing[ $user['username'] ] : array_fill( 0, 24, '' );
			$billing_date = $user['next_due'] ? $user['next_due'] : $user['installed'];
			$billing_day  = $billing_date && preg_match( '/^\d{4}-\d{2}-(\d{2})$/', $billing_date, $match ) ? (int) $match[1] : '';
			$status       = $user['disabled'] ? 'Disabled' : ( 0 === strcasecmp( $user['actual_profile'], 'Expired' ) ? 'Expired' : 'Active' );
			$row = array( $user['username'], $user['customer_name'], $user['phone'], $user['address'], $user['plan'], $billing_day, $status );
			for ( $month = 1; $month <= 12; $month++ ) {
				$row[] = self::month_status( $user, $year, $month, isset( $old[ 6 + $month ] ) ? $old[ 6 + $month ] : '' );
			}
			$row[] = $user['payment_date'];
			$row[] = $user['payment_amount'] ? $user['payment_amount'] : '';
			$row[] = $user['payment_method'];
			$row[] = current_time( 'mysql' );
			$row[] = 'MikroTik';
			$rows[] = $row;
		}
		return $rows;
	}

	private static function sync_customers( $reconcile = false ) {
		$year = (int) current_time( 'Y' );
		$prepared = self::prepare_sheet( $year, false );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$users = self::router_users();
		if ( is_wp_error( $users ) ) {
			return $users;
		}
		$existing = self::read_values( self::a1_title( (string) $year ) . '!A2:X' . self::MAX_ROWS );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$rows = self::customer_rows( $users, $existing, $year );
		$clear = self::clear_values( self::a1_title( (string) $year ) . '!A2:X' . self::MAX_ROWS );
		if ( is_wp_error( $clear ) ) {
			return $clear;
		}
		if ( $rows ) {
			$result = self::update_values( self::a1_title( (string) $year ) . '!A2:X' . ( count( $rows ) + 1 ), $rows );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		self::write_customer_snapshot( $rows, $year );
		self::save_settings(
			array(
				'google_last_customer_sync' => current_time( 'mysql' ),
				'google_last_sync_count'    => count( $rows ),
				'google_last_sync_type'     => $reconcile ? 'reconcile' : 'customer_sync',
				'google_last_sync_error'    => '',
			)
		);
		return array( 'count' => count( $rows ), 'year' => $year );
	}

	private static function queue() {
		$value = get_option( self::OPTION_QUEUE, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function save_queue( $queue ) {
		update_option( self::OPTION_QUEUE, is_array( $queue ) ? $queue : array(), false );
	}

	private static function backup_directory() {
		$directory = trailingslashit( WP_CONTENT_DIR ) . 'afc-private-backups';
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}
		if ( is_dir( $directory ) ) {
			$files = array(
				'index.php'  => "<?php\n// Silence is golden.\n",
				'.htaccess'  => "Deny from all\n",
				'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
			);
			foreach ( $files as $name => $contents ) {
				$path = trailingslashit( $directory ) . $name;
				if ( ! file_exists( $path ) ) {
					@file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
			}
		}
		return $directory;
	}

	private static function append_payment_backup( $item ) {
		$directory = self::backup_directory();
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return false;
		}
		$year = ! empty( $item['paid_at'] ) ? substr( $item['paid_at'], 0, 4 ) : current_time( 'Y' );
		$path = trailingslashit( $directory ) . 'payments-' . preg_replace( '/\D+/', '', $year ) . '.jsonl';
		return false !== @file_put_contents( $path, wp_json_encode( $item ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private static function write_customer_snapshot( $rows, $year ) {
		$directory = self::backup_directory();
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return;
		}
		$path = trailingslashit( $directory ) . 'customers-' . absint( $year ) . '-' . current_time( 'Y-m-d' ) . '.csv';
		$handle = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return;
		}
		fputcsv( $handle, self::year_headers() );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}
		fclose( $handle );
	}

	public static function queue_payment( $payment_id, $customer_id ) {
		$payment_id  = absint( $payment_id );
		$customer_id = absint( $customer_id );
		if ( ! $payment_id ) {
			return;
		}
		$paid_date = (string) get_post_meta( $payment_id, '_afc_payment_date', true );
		$user_id   = absint( get_post_meta( $payment_id, '_afc_recorded_by', true ) );
		$user      = $user_id ? get_userdata( $user_id ) : null;
		$paid_at   = $paid_date ? $paid_date . ' ' . current_time( 'H:i:s' ) : current_time( 'mysql' );
		$item = array(
			'payment_id'      => sprintf( 'AFC-PAY-%s-%06d', substr( $paid_at, 0, 4 ), $payment_id ),
			'wp_payment_id'   => $payment_id,
			'customer_id'     => $customer_id,
			'ppp_username'    => sanitize_text_field( (string) get_post_meta( $payment_id, '_afc_ppp_username', true ) ),
			'customer_name'   => $customer_id ? get_the_title( $customer_id ) : '',
			'phone'           => $customer_id ? (string) get_post_meta( $customer_id, '_afc_phone', true ) : '',
			'address'         => $customer_id ? (string) get_post_meta( $customer_id, '_afc_address', true ) : '',
			'plan'            => $customer_id ? (string) get_post_meta( $customer_id, '_afc_plan', true ) : '',
			'customer_status' => $customer_id ? (string) get_post_meta( $customer_id, '_afc_customer_status', true ) : '',
			'paid_at'         => $paid_at,
			'amount'          => (float) get_post_meta( $payment_id, '_afc_payment_amount', true ),
			'method'          => sanitize_text_field( (string) get_post_meta( $payment_id, '_afc_payment_method', true ) ),
			'recorded_by'     => $user ? $user->display_name : (string) $user_id,
			'attempts'        => 0,
			'last_error'      => '',
			'queued_at'       => current_time( 'mysql' ),
		);
		self::append_payment_backup( $item );
		$queue = self::queue();
		$queue[ $item['payment_id'] ] = $item;
		self::save_queue( $queue );
		update_post_meta( $payment_id, '_afc_google_sync_status', 'pending' );
		if ( self::auto_sync_enabled() && ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_QUEUE );
		}
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

	private static function ensure_payment_customer_row( $item, $year ) {
		$row = self::find_account_row( $year, $item['ppp_username'] );
		if ( is_wp_error( $row ) || $row ) {
			return $row;
		}
		$base = array_fill( 0, 24, '' );
		$base[0]  = $item['ppp_username'];
		$base[1]  = $item['customer_name'];
		$base[2]  = $item['phone'];
		$base[3]  = $item['address'];
		$base[4]  = $item['plan'];
		$base[6]  = $item['customer_status'] ? ucfirst( $item['customer_status'] ) : 'Active';
		$base[22] = current_time( 'mysql' );
		$base[23] = 'WordPress payment';
		$result = self::append_values( self::a1_title( (string) $year ) . '!A:X', array( $base ) );
		return is_wp_error( $result ) ? $result : self::find_account_row( $year, $item['ppp_username'] );
	}

	private static function transaction_exists( $payment_id ) {
		$values = self::read_values( self::a1_title( 'Transactions' ) . '!A2:A' . self::MAX_ROWS );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		foreach ( $values as $row ) {
			if ( isset( $row[0] ) && (string) $row[0] === (string) $payment_id ) {
				return true;
			}
		}
		return false;
	}

	private static function sync_payment( $item ) {
		$year  = (int) substr( $item['paid_at'], 0, 4 );
		$month = (int) substr( $item['paid_at'], 5, 2 );
		if ( $year < 2000 || $month < 1 || $month > 12 ) {
			return new WP_Error( 'afc_google_payment_date', __( 'The payment date is invalid.', 'airfiber-centralized' ) );
		}
		$prepared = self::prepare_sheet( $year, false );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$row = self::ensure_payment_customer_row( $item, $year );
		if ( is_wp_error( $row ) || ! $row ) {
			return is_wp_error( $row ) ? $row : new WP_Error( 'afc_google_payment_row', __( 'The yearly customer row could not be created.', 'airfiber-centralized' ) );
		}
		$month_column = self::column_letter( 7 + $month );
		$result = self::update_values( self::a1_title( (string) $year ) . '!' . $month_column . $row, array( array( 'PAID' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = self::update_values( self::a1_title( (string) $year ) . '!T' . $row . ':W' . $row, array( array( substr( $item['paid_at'], 0, 10 ), $item['amount'], $item['method'], current_time( 'mysql' ) ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$exists = self::transaction_exists( $item['payment_id'] );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( ! $exists ) {
			$result = self::append_values(
				self::a1_title( 'Transactions' ) . '!A:J',
				array(
					array(
						$item['payment_id'], $item['paid_at'], $item['ppp_username'], $item['customer_name'],
						sprintf( '%04d-%02d', $year, $month ), $item['amount'], $item['method'], $item['recorded_by'], 'SYNCED', $item['wp_payment_id'],
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	public static function process_queue( $force = false ) {
		if ( ! self::connected() || ( ! $force && ! self::auto_sync_enabled() ) ) {
			return;
		}
		$queue = self::queue();
		if ( ! $queue ) {
			return;
		}
		$processed = 0;
		foreach ( $queue as $key => $item ) {
			if ( $processed >= 10 ) {
				break;
			}
			$result = self::sync_payment( $item );
			if ( is_wp_error( $result ) ) {
				$item['attempts']   = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
				$item['last_error'] = $result->get_error_message();
				$item['last_try']   = current_time( 'mysql' );
				$queue[ $key ]      = $item;
				update_post_meta( absint( $item['wp_payment_id'] ), '_afc_google_sync_status', $item['attempts'] >= 3 ? 'failed' : 'pending' );
				update_post_meta( absint( $item['wp_payment_id'] ), '_afc_google_sync_error', $item['last_error'] );
			} else {
				update_post_meta( absint( $item['wp_payment_id'] ), '_afc_google_sync_status', 'synced' );
				update_post_meta( absint( $item['wp_payment_id'] ), '_afc_google_synced_at', current_time( 'mysql' ) );
				delete_post_meta( absint( $item['wp_payment_id'] ), '_afc_google_sync_error' );
				unset( $queue[ $key ] );
			}
			$processed++;
		}
		self::save_queue( $queue );
		self::save_settings( array( 'google_last_queue_run' => current_time( 'mysql' ), 'google_queue_pending' => count( $queue ) ) );
		if ( $queue && self::auto_sync_enabled() && ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_single_event( time() + ( 15 * MINUTE_IN_SECONDS ), self::CRON_QUEUE );
		}
	}

	public static function daily_reconcile() {
		if ( ! self::connected() || ! self::auto_sync_enabled() ) {
			return;
		}
		$result = self::sync_customers( true );
		if ( is_wp_error( $result ) ) {
			self::save_settings( array( 'google_last_sync_error' => $result->get_error_message() ) );
		}
		self::process_queue();
	}

	private static function status() {
		$settings = self::settings();
		$queue    = self::queue();
		$failed   = 0;
		foreach ( $queue as $item ) {
			if ( ! empty( $item['attempts'] ) && (int) $item['attempts'] >= 3 ) {
				$failed++;
			}
		}
		return array(
			'connected'        => self::connected(),
			'year'             => (int) current_time( 'Y' ),
			'preparedYear'     => isset( $settings['google_prepared_year'] ) ? absint( $settings['google_prepared_year'] ) : 0,
			'lastPrepare'      => isset( $settings['google_last_prepare'] ) ? $settings['google_last_prepare'] : '',
			'lastCustomerSync' => isset( $settings['google_last_customer_sync'] ) ? $settings['google_last_customer_sync'] : '',
			'lastSyncCount'    => isset( $settings['google_last_sync_count'] ) ? absint( $settings['google_last_sync_count'] ) : 0,
			'lastQueueRun'     => isset( $settings['google_last_queue_run'] ) ? $settings['google_last_queue_run'] : '',
			'lastError'        => isset( $settings['google_last_sync_error'] ) ? $settings['google_last_sync_error'] : '',
			'pending'          => count( $queue ),
			'failed'           => $failed,
			'autoSync'         => self::auto_sync_enabled(),
			'backupAvailable'  => file_exists( trailingslashit( self::backup_directory() ) . 'payments-' . current_time( 'Y' ) . '.jsonl' ),
		);
	}

	public static function ajax_status() {
		self::authorize();
		wp_send_json_success( self::status() );
	}

	public static function ajax_prepare_sheet() {
		self::authorize();
		$result = self::prepare_sheet( (int) current_time( 'Y' ), true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array_merge( self::status(), array( 'message' => sprintf( __( 'The %d and Transactions tabs are prepared and formatted.', 'airfiber-centralized' ), current_time( 'Y' ) ) ) ) );
	}

	public static function ajax_sync_customers() {
		self::authorize();
		$result = self::sync_customers( false );
		if ( is_wp_error( $result ) ) {
			self::save_settings( array( 'google_last_sync_error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array_merge( self::status(), array( 'message' => sprintf( __( '%d MikroTik PPP account(s) were synchronized to the %d tab.', 'airfiber-centralized' ), $result['count'], $result['year'] ) ) ) );
	}

	public static function ajax_reconcile() {
		self::authorize();
		$result = self::sync_customers( true );
		if ( is_wp_error( $result ) ) {
			self::save_settings( array( 'google_last_sync_error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		self::process_queue( true );
		wp_send_json_success( array_merge( self::status(), array( 'message' => sprintf( __( 'Reconciliation completed for %d PPP account(s), including pending payments.', 'airfiber-centralized' ), $result['count'] ) ) ) );
	}

	public static function ajax_retry_queue() {
		self::authorize();
		$queue = self::queue();
		foreach ( $queue as $key => $item ) {
			$item['attempts']   = 0;
			$item['last_error'] = '';
			$queue[ $key ]      = $item;
		}
		self::save_queue( $queue );
		self::process_queue( true );
		wp_send_json_success( array_merge( self::status(), array( 'message' => __( 'Pending Google Sheet updates were retried.', 'airfiber-centralized' ) ) ) );
	}

	public static function ajax_set_auto_sync() {
		self::authorize();
		$enabled = ! empty( $_POST['enabled'] );
		self::save_settings( array( 'google_auto_sync' => $enabled ? 1 : 0 ) );
		if ( $enabled && self::queue() && ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_QUEUE );
		}
		wp_send_json_success( array_merge( self::status(), array( 'message' => $enabled ? __( 'Automatic Google Sheet synchronization is enabled.', 'airfiber-centralized' ) : __( 'Automatic Google Sheet synchronization is paused. Payments will remain safely queued.', 'airfiber-centralized' ) ) ) );
	}

	public static function download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this backup.', 'airfiber-centralized' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'afc_google_download_backup' );
		$path = trailingslashit( self::backup_directory() ) . 'payments-' . current_time( 'Y' ) . '.jsonl';
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'No payment backup exists for the current year yet.', 'airfiber-centralized' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="airfiber-payments-' . current_time( 'Y' ) . '.jsonl"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
