<?php

defined( 'ABSPATH' ) || exit;

/**
 * Makes pre-cutoff reminder preparation independent from visitor-driven
 * WP-Cron. The authenticated Android queue request can trigger a throttled
 * reminder scan during the configured sending window, then receives a compact
 * diagnostic explaining why the queue is empty.
 */
class AFC_SMS_PreCutoff_Runtime {

	const OPTION_LAST_SCAN = 'afc_sms_precutoff_runtime_last_scan';
	const TRANSIENT_LOCK   = 'afc_sms_precutoff_runtime_lock';

	private static $queue_diagnostic = array();

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'before_rest_callbacks' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_rest_callbacks' ), 10, 3 );
	}

	private static function is_queue_request( $request ) {
		return $request instanceof WP_REST_Request
			&& '/airfiber/v1/sms/queue' === (string) $request->get_route();
	}

	private static function rules() {
		return class_exists( 'AFC_SMS_Payer_Ratings' )
			? AFC_SMS_Payer_Ratings::rules()
			: array(
				'send_start_hour' => 9,
				'send_end_hour'   => 18,
			);
	}

	private static function window_state() {
		$rules = self::rules();
		$hour  = (int) current_time( 'G' );
		$start = isset( $rules['send_start_hour'] ) ? max( 0, min( 23, (int) $rules['send_start_hour'] ) ) : 9;
		$end   = isset( $rules['send_end_hour'] ) ? max( 0, min( 23, (int) $rules['send_end_hour'] ) ) : 18;

		if ( $start < $end ) {
			$inside = $hour >= $start && $hour < $end;
		} elseif ( $start > $end ) {
			$inside = $hour >= $start || $hour < $end;
		} else {
			$inside = false;
		}

		return array(
			'hour'          => $hour,
			'start'         => $start,
			'end'           => $end,
			'within_window' => $inside,
			'label'         => sprintf( '%02d:00–%02d:00', $start, $end ),
		);
	}

	private static function enabled_customer_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'afc_customer'
				AND pm.meta_key = %s
				AND pm.meta_value = '1'",
				AFC_SMS_PreCutoff::META_ENABLED
			)
		);
	}

	private static function queued_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'afc_sms_jobs';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function diagnostic( $status, $message, $window, $enabled, $before, $after, $source ) {
		return array(
			'status'            => sanitize_key( $status ),
			'message'           => (string) $message,
			'source'            => sanitize_key( $source ),
			'checked_at'        => current_time( 'mysql' ),
			'within_window'     => ! empty( $window['within_window'] ),
			'window'            => (string) $window['label'],
			'window_start_hour' => (int) $window['start'],
			'window_end_hour'   => (int) $window['end'],
			'enabled_customers' => (int) $enabled,
			'queued_before'     => (int) $before,
			'queued_after'      => (int) $after,
			'queued_new'        => max( 0, (int) $after - (int) $before ),
		);
	}

	public static function prepare_queue( $source = 'gateway-poll' ) {
		$window = self::window_state();
		$enabled = self::enabled_customer_count();
		$before  = self::queued_count();

		if ( ! $window['within_window'] ) {
			$result = self::diagnostic(
				'outside-window',
				sprintf( 'Automatic reminder preparation runs during %s. The current server hour is %02d:00.', $window['label'], $window['hour'] ),
				$window,
				$enabled,
				$before,
				$before,
				$source
			);
			update_option( self::OPTION_LAST_SCAN, $result, false );
			return $result;
		}

		if ( $enabled < 1 ) {
			$result = self::diagnostic(
				'no-enabled-customers',
				'No customer currently has SMS before cutoff enabled.',
				$window,
				0,
				$before,
				$before,
				$source
			);
			update_option( self::OPTION_LAST_SCAN, $result, false );
			return $result;
		}

		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			$last = get_option( self::OPTION_LAST_SCAN, array() );
			if ( is_array( $last ) && $last ) {
				$last['status']  = 'recently-checked';
				$last['message'] = 'Reminder preparation was already checked recently. The existing queue was returned.';
				$last['source']  = sanitize_key( $source );
				return $last;
			}
			return self::diagnostic( 'recently-checked', 'Reminder preparation was already checked recently.', $window, $enabled, $before, $before, $source );
		}

		set_transient( self::TRANSIENT_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		if ( class_exists( 'AFC_SMS_PreCutoff' ) ) {
			AFC_SMS_PreCutoff::run_scheduled_scan();
		}

		$after  = self::queued_count();
		$new    = max( 0, $after - $before );
		$status = $new > 0 ? 'queued' : 'checked';
		$message = $new > 0
			? sprintf( '%d pre-cutoff reminder%s added to the Android queue.', $new, 1 === $new ? ' was' : 's were' )
			: sprintf( 'Reminder scan completed. %d enabled customer%s were checked, but none are due for a new reminder now.', $enabled, 1 === $enabled ? '' : 's' );

		$result = self::diagnostic( $status, $message, $window, $enabled, $before, $after, $source );
		update_option( self::OPTION_LAST_SCAN, $result, false );
		return $result;
	}

	public static function before_rest_callbacks( $response, $handler, $request ) {
		if ( self::is_queue_request( $request ) && ! is_wp_error( $response ) ) {
			self::$queue_diagnostic = self::prepare_queue( 'gateway-poll' );
		}
		return $response;
	}

	public static function after_rest_callbacks( $response, $handler, $request ) {
		if ( ! self::is_queue_request( $request ) || is_wp_error( $response ) ) {
			return $response;
		}

		$response = rest_ensure_response( $response );
		$data     = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$data['data'] = array();
		}
		$data['data']['reminder_scan'] = self::$queue_diagnostic
			? self::$queue_diagnostic
			: (array) get_option( self::OPTION_LAST_SCAN, array() );
		$response->set_data( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
