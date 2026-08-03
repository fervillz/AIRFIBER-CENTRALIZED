<?php

defined( 'ABSPATH' ) || exit;

/**
 * Expands the Google yearly report's PAID history from each account's
 * installation month through its latest paid coverage month.
 *
 * Example: installed in 2024 and paid through July 2026 means Jan-Jul on the
 * 2026 sheet are PAID. An account installed in May 2026 and paid through July
 * means May-Jul are PAID. Future/unpaid months keep their normal state.
 */
class AFC_Google_Sheets_Paid_History {

	const CRON_REFRESH = 'afc_google_paid_history_refresh';

	private static $account_map = null;

	public static function init() {
		add_filter( 'http_request_args', array( __CLASS__, 'backfill_bulk_rows' ), 30, 2 );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'schedule_refresh' ), 30, 2 );
		add_action( self::CRON_REFRESH, array( __CLASS__, 'refresh_after_payment' ) );
	}

	/**
	 * Delay the full refresh so saving a payment remains fast. Multiple payments
	 * recorded close together share one reconciliation run.
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

	private static function valid_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
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

	/** Read legacy/single-line comment values even when a suggested field was not added to the schema. */
	private static function raw_comment_date( $comment, $key ) {
		$key = preg_quote( (string) $key, '/' );
		return preg_match( '/(?:^|\s)' . $key . '\s*:\s*(\d{4}-\d{2}-\d{2})(?=\s|$)/i', (string) $comment, $match )
			? self::valid_date( $match[1] )
			: '';
	}

	private static function latest_date( $dates ) {
		$latest = '';
		foreach ( $dates as $date ) {
			$date = self::valid_date( $date );
			if ( $date && ( ! $latest || $date > $latest ) ) {
				$latest = $date;
			}
		return $latest;
	}

	/**
	 * MikroTik supplies the installation and billing coverage state. For active
	 * accounts, nextDue also proves the previous billing month was paid. For an
	 * expired account, only explicit paymentDate/paidThrough values are trusted.
	 */
	private static function accounts() {
		if ( null !== self::$account_map ) {
			return self::$account_map;
		}
		self::$account_map = array();
		if ( ! class_exists( 'AFC_MikroTik' ) || ! class_exists( 'AFC_Comment_Fields' ) ) {
			return self::$account_map;
		}

		$secrets = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' ) );
		if ( is_wp_error( $secrets ) || ! is_array( $secrets ) ) {
			return self::$account_map;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		foreach ( $secrets as $secret ) {
			$username = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			if ( '' === $username ) {
				continue;
			}

			$comment      = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			$details      = AFC_Comment_Fields::parse_comment( $comment );
			$installed    = self::valid_date( isset( $details['installed'] ) ? $details['installed'] : '' );
			$payment_date = self::valid_date( isset( $details['payment_date'] ) ? $details['payment_date'] : '' );
			$paid_through = self::valid_date( self::custom_value( $details, 'paidThrough' ) );
			$next_due     = self::valid_date( self::custom_value( $details, 'nextDue' ) );
			if ( ! $paid_through ) {
				$paid_through = self::raw_comment_date( $comment, 'paidThrough' );
			}
			if ( ! $next_due ) {
				$next_due = self::raw_comment_date( $comment, 'nextDue' );
			}
			$profile  = isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '';
			$disabled = isset( $secret['disabled'] ) && 'true' === strtolower( (string) $secret['disabled'] );
			$expired  = $disabled || 0 === strcasecmp( $profile, 'Expired' );

			$coverage_candidates = array( $payment_date, $paid_through );
			if ( ! $expired && $next_due ) {
				$next = DateTimeImmutable::createFromFormat( '!Y-m-d', $next_due, $timezone );
				if ( $next ) {
					$coverage_candidates[] = $next->modify( 'first day of this month' )->modify( '-1 month' )->format( 'Y-m-d' );
				}
			}

			self::$account_map[ $username ] = array(
				'installed'    => $installed,
				'coverage_end' => self::latest_date( $coverage_candidates ),
				'expired'      => $expired,
			);
		}
		return self::$account_map;
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

	/**
	 * The main sync sends full A:X rows. Adjust only Jan-Dec before the request
	 * reaches Google; all names, plans, dates, due and expired logic remain owned
	 * by the main synchronization class.
	 */
	public static function backfill_bulk_rows( $args, $url ) {
		if ( false === strpos( (string) $url, 'https://sheets.googleapis.com/' ) || 'PUT' !== strtoupper( isset( $args['method'] ) ? $args['method'] : 'GET' ) ) {
			return $args;
		}

		$year = self::yearly_bulk_range( $url );
		if ( ! $year || empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
			return $args;
		}

		$payload = json_decode( $args['body'], true );
		if ( ! is_array( $payload ) || empty( $payload['values'] ) || ! is_array( $payload['values'] ) ) {
			return $args;
		}

		$accounts = self::accounts();
		$changed  = false;
		foreach ( $payload['values'] as &$row ) {
			if ( ! is_array( $row ) || empty( $row[0] ) || empty( $accounts[ (string) $row[0] ] ) ) {
				continue;
			}

			list( $start_month, $end_month ) = self::paid_month_range( $accounts[ (string) $row[0] ], $year );
			if ( ! $start_month || ! $end_month ) {
				continue;
			}

			for ( $month = $start_month; $month <= $end_month; $month++ ) {
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
