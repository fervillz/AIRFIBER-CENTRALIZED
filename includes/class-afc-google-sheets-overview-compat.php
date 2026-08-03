<?php

defined( 'ABSPATH' ) || exit;

/**
 * Migrates the original Excel-style yearly tab into a human-friendly Overview
 * and gives the sync engine a clean hidden yearly data tab. This lets the base
 * Google sync keep its simple row-1 machine table while preserving the visual
 * dashboard the user originally received.
 */
class AFC_Google_Sheets_Overview_Compat {

	const NONCE              = 'afc_integrations';
	const OPTION_SETTINGS    = 'afc_integrations_settings';
	const OPTION_CREDENTIALS = 'afc_google_sheets_credentials';
	const MAX_ROWS           = 5000;

	public static function init() {
		add_action( 'wp_ajax_afc_google_prepare_sheet', array( __CLASS__, 'before_prepare' ), 2 );
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
		$value = isset( $settings['spreadsheet_id'] ) ? (string) $settings['spreadsheet_id'] : '';
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
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$now = time();
		$token_uri = isset( $credentials['token_uri'] ) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token';
		$header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::base64url(
			wp_json_encode(
				array(
					'iss' => $credentials['client_email'],
					'scope' => 'https://www.googleapis.com/auth/spreadsheets',
					'aud' => $token_uri,
					'iat' => $now - 30,
					'exp' => $now + 3500,
				)
			)
		);
		$unsigned = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'afc_google_sign_failed', __( 'The Google authentication request could not be signed.', 'airfiber-centralized' ) );
		}
		$response = wp_remote_post(
			$token_uri,
			array(
				'timeout' => 25,
				'body' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion' => $unsigned . '.' . self::base64url( $signature ),
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

	private static function request( $method, $path, $body = null, $query = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = 'https://sheets.googleapis.com' . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method' => strtoupper( $method ),
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type' => 'application/json; charset=utf-8',
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
			return new WP_Error( 'afc_google_overview_failed', sanitize_text_field( $message ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function update_values( $range, $values ) {
		$id = self::spreadsheet_id();
		return self::request(
			'PUT',
			'/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ),
			array( 'range' => $range, 'majorDimension' => 'ROWS', 'values' => $values ),
			array( 'valueInputOption' => 'USER_ENTERED' )
		);
	}

	private static function clear_values( $range ) {
		$id = self::spreadsheet_id();
		return self::request( 'POST', '/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ) . ':clear', new stdClass() );
	}

	private static function read_values( $range ) {
		$id = self::spreadsheet_id();
		$result = self::request( 'GET', '/v4/spreadsheets/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ) );
		return is_wp_error( $result ) ? $result : ( isset( $result['values'] ) ? $result['values'] : array() );
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

	private static function unique_title( $base, $map ) {
		if ( ! isset( $map[ $base ] ) ) {
			return $base;
		}
		for ( $number = 2; $number < 100; $number++ ) {
			$title = $base . ' ' . $number;
			if ( ! isset( $map[ $title ] ) ) {
				return $title;
			}
		}
		return $base . ' ' . time();
	}

	private static function is_machine_year_tab( $year ) {
		$values = self::read_values( "'" . $year . "'!A1:B2" );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		return isset( $values[0][0], $values[0][1] ) && 'PPP Account' === $values[0][0] && 'Customer Name' === $values[0][1];
	}

	private static function is_machine_transactions_tab() {
		$values = self::read_values( "'Transactions'!A1:B2" );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		return isset( $values[0][0], $values[0][1] ) && 'Payment ID' === $values[0][0] && 'Date & Time' === $values[0][1];
	}

	private static function build_overview( $sheet, $year ) {
		$id = self::spreadsheet_id();
		$sheet_id = (int) $sheet['properties']['sheetId'];
		$cleanup = array();
		foreach ( isset( $sheet['merges'] ) ? $sheet['merges'] : array() as $range ) {
			$cleanup[] = array( 'unmergeCells' => array( 'range' => $range ) );
		}
		foreach ( isset( $sheet['tables'] ) ? $sheet['tables'] : array() as $table ) {
			if ( ! empty( $table['tableId'] ) ) {
				$cleanup[] = array( 'deleteTable' => array( 'tableId' => (string) $table['tableId'] ) );
			}
		}
		$conditional_count = isset( $sheet['conditionalFormats'] ) && is_array( $sheet['conditionalFormats'] ) ? count( $sheet['conditionalFormats'] ) : 0;
		for ( $index = $conditional_count - 1; $index >= 0; $index-- ) {
			$cleanup[] = array( 'deleteConditionalFormatRule' => array( 'sheetId' => $sheet_id, 'index' => $index ) );
		}
		if ( $cleanup ) {
			$result = self::request( 'POST', '/v4/spreadsheets/' . rawurlencode( $id ) . ':batchUpdate', array( 'requests' => $cleanup ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$result = self::clear_values( "'" . str_replace( "'", "''", $year . ' Overview' ) . "'!A1:T" . self::MAX_ROWS );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$current_month = date_i18n( 'M', current_time( 'timestamp' ) );
		$rows = array(
			array( 'Airfiber Payment Register — ' . $year ),
			array( 'This is the easy-to-read report. Airfiber updates it automatically from the hidden ' . $year . ' data tab.' ),
			array( 'Selected Month', $current_month, '', 'Total Customers', '=COUNTA(A7:A' . self::MAX_ROWS . ')', '', 'Paid', '=COUNTIF(INDEX(F7:Q' . self::MAX_ROWS . ',0,MATCH($B$3,$F$6:$Q$6,0)),"PAID")', '', 'Due', '=COUNTIF(INDEX(F7:Q' . self::MAX_ROWS . ',0,MATCH($B$3,$F$6:$Q$6,0)),"DUE")', '', 'Expired', '=COUNTIF(INDEX(F7:Q' . self::MAX_ROWS . ',0,MATCH($B$3,$F$6:$Q$6,0)),"EXPIRED")', '', 'Collected This Year', '=SUM(S7:S' . self::MAX_ROWS . ')' ),
			array( 'Legend', 'PAID', 'DUE', 'EXPIRED', 'Blank / future' ),
			array(),
			array( 'PPP Account', 'Customer Name', 'Plan', 'Billing Day', 'Account Status', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Last Payment', 'Amount', 'Method' ),
			array( '=ARRAYFORMULA(IF(\'' . $year . '\'!A2:A' . self::MAX_ROWS . '="","",{\'' . $year . '\'!A2:B' . self::MAX_ROWS . ',\'' . $year . '\'!E2:G' . self::MAX_ROWS . ',\'' . $year . '\'!H2:S' . self::MAX_ROWS . ',\'' . $year . '\'!T2:V' . self::MAX_ROWS . '}))' ),
		);
		$result = self::update_values( "'" . str_replace( "'", "''", $year . ' Overview' ) . "'!A1:T7", $rows );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$navy = array( 'red' => 0.055, 'green' => 0.22, 'blue' => 0.38 );
		$white = array( 'red' => 1, 'green' => 1, 'blue' => 1 );
		$light_blue = array( 'red' => 0.89, 'green' => 0.94, 'blue' => 0.99 );
		$format = array(
			array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sheet_id, 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 20, 'frozenRowCount' => 6 ), 'hidden' => false ), 'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount),hidden' ) ),
			array( 'mergeCells' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 20 ), 'mergeType' => 'MERGE_ALL' ) ),
			array( 'mergeCells' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 1, 'endRowIndex' => 2, 'startColumnIndex' => 0, 'endColumnIndex' => 20 ), 'mergeType' => 'MERGE_ALL' ) ),
			array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 20 ), 'cell' => array( 'userEnteredFormat' => array( 'backgroundColor' => $navy, 'textFormat' => array( 'foregroundColor' => $white, 'bold' => true, 'fontSize' => 15 ), 'verticalAlignment' => 'MIDDLE' ) ), 'fields' => 'userEnteredFormat' ) ),
			array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 1, 'endRowIndex' => 2, 'startColumnIndex' => 0, 'endColumnIndex' => 20 ), 'cell' => array( 'userEnteredFormat' => array( 'backgroundColor' => $light_blue, 'textFormat' => array( 'foregroundColor' => array( 'red' => 0.38, 'green' => 0.47, 'blue' => 0.58 ), 'italic' => true ) ) ), 'fields' => 'userEnteredFormat' ) ),
			array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 5, 'endRowIndex' => 6, 'startColumnIndex' => 0, 'endColumnIndex' => 20 ), 'cell' => array( 'userEnteredFormat' => array( 'backgroundColor' => $navy, 'textFormat' => array( 'foregroundColor' => $white, 'bold' => true ), 'horizontalAlignment' => 'CENTER', 'verticalAlignment' => 'MIDDLE' ) ), 'fields' => 'userEnteredFormat' ) ),
			array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => 2, 'endRowIndex' => 3, 'startColumnIndex' => 1, 'endColumnIndex' => 2 ), 'rule' => array( 'condition' => array( 'type' => 'ONE_OF_LIST', 'values' => array_map( function ( $month ) { return array( 'userEnteredValue' => $month ); }, array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ) ) ), 'strict' => true, 'showCustomUi' => true ) ) ),
			array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sheet_id, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 20 ), 'properties' => array( 'pixelSize' => 112 ), 'fields' => 'pixelSize' ) ),
			array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sheet_id, 'dimension' => 'COLUMNS', 'startIndex' => 1, 'endIndex' => 2 ), 'properties' => array( 'pixelSize' => 190 ), 'fields' => 'pixelSize' ) ),
			array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sheet_id, 'dimension' => 'COLUMNS', 'startIndex' => 5, 'endIndex' => 17 ), 'properties' => array( 'pixelSize' => 82 ), 'fields' => 'pixelSize' ) ),
		);
		$colors = array(
			'PAID' => array( array( 'red' => 0.84, 'green' => 0.95, 'blue' => 0.86 ), array( 'red' => 0.08, 'green' => 0.42, 'blue' => 0.18 ) ),
			'DUE' => array( array( 'red' => 1, 'green' => 0.92, 'blue' => 0.73 ), array( 'red' => 0.60, 'green' => 0.34, 'blue' => 0 ) ),
			'EXPIRED' => array( array( 'red' => 0.98, 'green' => 0.80, 'blue' => 0.80 ), array( 'red' => 0.68, 'green' => 0.10, 'blue' => 0.10 ) ),
		);
		foreach ( $colors as $value => $palette ) {
			$format[] = array( 'addConditionalFormatRule' => array( 'index' => 0, 'rule' => array( 'ranges' => array( array( 'sheetId' => $sheet_id, 'startRowIndex' => 6, 'endRowIndex' => self::MAX_ROWS, 'startColumnIndex' => 5, 'endColumnIndex' => 17 ) ), 'booleanRule' => array( 'condition' => array( 'type' => 'TEXT_EQ', 'values' => array( array( 'userEnteredValue' => $value ) ) ), 'format' => array( 'backgroundColor' => $palette[0], 'textFormat' => array( 'foregroundColor' => $palette[1], 'bold' => true ), 'horizontalAlignment' => 'CENTER' ) ) ) ) );
		}
		return self::request( 'POST', '/v4/spreadsheets/' . rawurlencode( $id ) . ':batchUpdate', array( 'requests' => $format ) );
	}

	public static function before_prepare() {
		self::authorize();
		$id = self::spreadsheet_id();
		if ( ! $id || ! self::credentials() ) {
			return;
		}
		$year = (string) current_time( 'Y' );
		$meta = self::request(
			'GET',
			'/v4/spreadsheets/' . rawurlencode( $id ),
			null,
			array( 'fields' => 'sheets(properties(sheetId,title,hidden,gridProperties),merges,tables(tableId),conditionalFormats)' )
		);
		if ( is_wp_error( $meta ) ) {
			wp_send_json_error( array( 'message' => $meta->get_error_message() ) );
		}
		$map = self::sheet_map( $meta );
		$requests = array();

		if ( isset( $map[ $year ] ) ) {
			$machine = self::is_machine_year_tab( $year );
			if ( is_wp_error( $machine ) ) {
				wp_send_json_error( array( 'message' => $machine->get_error_message() ) );
			}
			if ( ! $machine ) {
				$overview_title = isset( $map[ $year . ' Overview' ] ) ? self::unique_title( $year . ' Legacy Backup', $map ) : $year . ' Overview';
				$requests[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => (int) $map[ $year ]['properties']['sheetId'], 'title' => $overview_title, 'hidden' => false ), 'fields' => 'title,hidden' ) );
				$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => $year, 'hidden' => true, 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 24 ) ) ) );
			}
		} else {
			$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => $year, 'hidden' => true, 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 24 ) ) ) );
		}

		if ( isset( $map['Transactions'] ) ) {
			$machine_transactions = self::is_machine_transactions_tab();
			if ( is_wp_error( $machine_transactions ) ) {
				wp_send_json_error( array( 'message' => $machine_transactions->get_error_message() ) );
			}
			if ( ! $machine_transactions ) {
				$archive_title = self::unique_title( 'Transactions Legacy Backup', $map );
				$requests[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => (int) $map['Transactions']['properties']['sheetId'], 'title' => $archive_title, 'hidden' => false ), 'fields' => 'title,hidden' ) );
				$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => 'Transactions', 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 10 ) ) ) );
			}
		} else {
			$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => 'Transactions', 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 10 ) ) ) );
		}

		if ( ! isset( $map[ $year . ' Overview' ] ) && ( ! isset( $map[ $year ] ) || self::is_machine_year_tab( $year ) ) ) {
			$requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => $year . ' Overview', 'hidden' => false, 'gridProperties' => array( 'rowCount' => self::MAX_ROWS, 'columnCount' => 20 ) ) ) );
		}

		if ( $requests ) {
			$result = self::request( 'POST', '/v4/spreadsheets/' . rawurlencode( $id ) . ':batchUpdate', array( 'requests' => $requests ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		$meta = self::request(
			'GET',
			'/v4/spreadsheets/' . rawurlencode( $id ),
			null,
			array( 'fields' => 'sheets(properties(sheetId,title,hidden,gridProperties),merges,tables(tableId),conditionalFormats)' )
		);
		if ( is_wp_error( $meta ) ) {
			wp_send_json_error( array( 'message' => $meta->get_error_message() ) );
		}
		$map = self::sheet_map( $meta );
		if ( empty( $map[ $year . ' Overview' ] ) ) {
			wp_send_json_error( array( 'message' => __( 'The yearly Overview tab could not be created.', 'airfiber-centralized' ) ) );
		}
		$result = self::build_overview( $map[ $year . ' Overview' ], $year );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$settings = self::settings();
		$settings['google_overview_tab'] = $year . ' Overview';
		$settings['google_data_tab_hidden'] = 1;
		update_option( self::OPTION_SETTINGS, $settings, false );
	}
}
