<?php

defined( 'ABSPATH' ) || exit;

/**
 * When a PPP account is paid for the current month, the yearly report can
 * safely treat every month from installation through the current month as
 * paid. Future months remain blank. MikroTik still supplies the installation
 * and current-payment state; this class only improves the reporting mirror.
 */
class AFC_Google_Sheets_Paid_History {

	const CRON_REFRESH = 'afc_google_paid_history_refresh';

	private static $installation_map = null;

	public static function init() {
		add_filter( 'http_request_args', array( __CLASS__, 'backfill_bulk_rows' ), 30, 2 );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'schedule_refresh' ), 30, 2 );
		add_action( self::CRON_REFRESH, array( __CLASS__, 'refresh_after_payment' ) );
	}

	/**
	 * A payment is queued quickly, then one full customer refresh is coalesced
	 * behind it so the earlier month cells are updated without slowing down the
	 * employee who records the payment.
	 */
	public static function schedule_refresh() {
		if ( ! wp_next_scheduled( self::CRON_REFRESH ) ) {
			wp_schedule_single_event( time() + 45, self::CRON_REFRESH );
		}
	}

	public static function refresh_after_payment() {
		if ( class_exists( 'AFC_Google_Sheets_Sync' ) ) {
			AFC_Google_Sheets_Sync::daily_reconcile();
		}
	}

	private static function yearly_bulk_range( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! preg_match( '#/values/([^/]+)$#', $path, $match ) ) {
			return 0;
		}
		$range = rawurldecode( $match[1] );
		return preg_match( "/^'?(\d{4})'?!A2:X\d+$/", $range, $year_match ) ? (int) $year_match[1] : 0;
	}

	private static function installations() {
		if ( null !== self::$installation_map ) {
			return self::$installation_map;
		}
		self::$installation_map = array();
		if ( ! class_exists( 'AFC_MikroTik' ) || ! class_exists( 'AFC_Comment_Fields' ) ) {
			return self::$installation_map;
		}

		$secrets = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,comment' ) );
		if ( is_wp_error( $secrets ) || ! is_array( $secrets ) ) {
			return self::$installation_map;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}

		foreach ( $secrets as $secret ) {
			$username = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			if ( '' === $username ) {
				continue;
			}
			$details   = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? $secret['comment'] : '' );
			$installed = isset( $details['installed'] ) ? trim( (string) $details['installed'] ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $installed ) ) {
				self::$installation_map[ $username ] = $installed;
			}
		}
		return self::$installation_map;
	}

	/**
	 * The main sync sends complete A:X rows. Detect that request and adjust only
	 * Jan-Dec values. This also works for PPP accounts that are not imported as
	 * WordPress customer posts because installation dates are read from MikroTik.
	 */
	public static function backfill_bulk_rows( $args, $url ) {
		if ( false === strpos( (string) $url, 'https://sheets.googleapis.com/' ) || 'PUT' !== strtoupper( isset( $args['method'] ) ? $args['method'] : 'GET' ) ) {
			return $args;
		}

		$year = self::yearly_bulk_range( $url );
		$current_year = (int) current_time( 'Y' );
		$current_month = (int) current_time( 'n' );
		if ( ! $year || $year !== $current_year || empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
			return $args;
		}

		$payload = json_decode( $args['body'], true );
		if ( ! is_array( $payload ) || empty( $payload['values'] ) || ! is_array( $payload['values'] ) ) {
			return $args;
		}

		$installations = self::installations();
		$current_index = 6 + $current_month;
		$changed = false;
		foreach ( $payload['values'] as &$row ) {
			if ( ! is_array( $row ) || empty( $row[0] ) || 'PAID' !== strtoupper( trim( isset( $row[ $current_index ] ) ? (string) $row[ $current_index ] : '' ) ) ) {
				continue;
			}

			$username = (string) $row[0];
			if ( empty( $installations[ $username ] ) ) {
				continue;
			}
			$installed_year  = (int) substr( $installations[ $username ], 0, 4 );
			$installed_month = (int) substr( $installations[ $username ], 5, 2 );
			if ( $installed_year > $year ) {
				continue;
			}
			$start_month = $installed_year < $year ? 1 : max( 1, min( 12, $installed_month ) );
			for ( $month = $start_month; $month <= $current_month; $month++ ) {
				$index = 6 + $month;
				if ( ! isset( $row[ $index ] ) || 'PAID' !== strtoupper( trim( (string) $row[ $index ] ) ) ) {
					$row[ $index ] = 'PAID';
					$changed = true;
				}
			}
		}
		unset( $row );

		if ( $changed ) {
			$args['body'] = wp_json_encode( $payload );
		}
		return $args;
	}
}
