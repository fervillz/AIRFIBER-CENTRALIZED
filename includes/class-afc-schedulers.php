<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reads, previews, creates, updates, and safely synchronizes MikroTik PPP
 * cutoff schedulers. One scheduler is managed per PPP username.
 */
class AFC_Schedulers {

	const OPTION_KEY       = 'afc_scheduler_settings';
	const BACKUP_OPTION    = 'afc_scheduler_backups';
	const LAST_SYNC_OPTION = 'afc_scheduler_last_sync';
	const NONCE            = 'afc_schedulers';
	const MAX_BATCH        = 25;
	const BACKUP_LIMIT     = 500;
	const SCRIPT_MARKER    = 'AFC-MANAGED-SCHEDULER v1';

	private static $promise_shutdown_registered = false;
	private static $synced_this_request = array();

	public static function init() {
		add_action( 'wp_ajax_afc_scheduler_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_scheduler_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_afc_scheduler_adjust', array( __CLASS__, 'ajax_adjust' ) );
		add_action( 'wp_ajax_afc_scheduler_save_settings', array( __CLASS__, 'ajax_save_settings' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 98 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_frontend_panel' ) );

		// The payment record is created after the PPP comment was successfully
		// updated. Synchronize the scheduler before the AJAX response is returned.
		add_action( 'afc_payment_recorded', array( __CLASS__, 'sync_recorded_payment' ), 20, 2 );
		add_action( 'afc_quick_payment_recorded', array( __CLASS__, 'sync_quick_payment_fallback' ), 20, 4 );

		// The promise endpoint exits with wp_send_json(). Register a shutdown sync
		// before its handler runs so the newly written cutoffDate is used.
		add_action( 'wp_ajax_afc_ppp_set_promise_date', array( __CLASS__, 'register_promise_shutdown_sync' ), 1 );
	}

	public static function defaults() {
		return array(
			'base_time'          => '00:05:00',
			'stagger_seconds'    => 10,
			'expired_profile'    => 'Expired',
			'auto_sync_payments' => 1,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	private static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$time     = isset( $input['base_time'] ) ? sanitize_text_field( $input['base_time'] ) : $defaults['base_time'];
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time ) ) {
			$time = $defaults['base_time'];
		}

		$stagger = isset( $input['stagger_seconds'] ) ? absint( $input['stagger_seconds'] ) : $defaults['stagger_seconds'];
		$stagger = min( 60, $stagger );

		$profile = isset( $input['expired_profile'] ) ? sanitize_text_field( $input['expired_profile'] ) : $defaults['expired_profile'];
		$profile = trim( preg_replace( '/[\r\n\t]+/', ' ', $profile ) );
		if ( '' === $profile ) {
			$profile = $defaults['expired_profile'];
		}

		return array(
			'base_time'          => $time,
			'stagger_seconds'    => $stagger,
			'expired_profile'    => substr( $profile, 0, 80 ),
			'auto_sync_payments' => empty( $input['auto_sync_payments'] ) ? 0 : 1,
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage MikroTik schedulers.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function parse_iso_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function normalize_router_date( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$date  = self::parse_iso_date( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		if ( preg_match( '/^([a-z]{3})\/(\d{1,2})\/(\d{4})$/', $value, $match ) ) {
			$months = array(
				'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
				'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
				'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
			);
			if ( isset( $months[ $match[1] ] ) ) {
				$iso = sprintf( '%04d-%02d-%02d', (int) $match[3], $months[ $match[1] ], (int) $match[2] );
				return self::parse_iso_date( $iso ) ? $iso : '';
			}
		}

		return '';
	}

	private static function normalize_time( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{1,2}):(\d{2}):(\d{2})$/', $value, $match ) ) {
			$hour = (int) $match[1];
			if ( $hour <= 23 && (int) $match[2] <= 59 && (int) $match[3] <= 59 ) {
				return sprintf( '%02d:%02d:%02d', $hour, (int) $match[2], (int) $match[3] );
			}
		}
		return '';
	}

	private static function router_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'yes', '1' ), true );
	}

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] )
			? $details['custom_fields']
			: array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( $canonical, $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function ros_escape( $value ) {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value );
	}

	private static function build_script( $username, $expected_due, $expected_cutoff, $expired_profile ) {
		$user    = self::ros_escape( $username );
		$due     = self::ros_escape( $expected_due );
		$cutoff  = self::ros_escape( $expected_cutoff );
		$profile = self::ros_escape( $expired_profile );

		return '# ' . self::SCRIPT_MARKER . "\r\n"
			. ':local user "' . $user . '"' . "\r\n"
			. ':local expectedDue "' . $due . '"' . "\r\n"
			. ':local expectedCutoff "' . $cutoff . '"' . "\r\n"
			. ':local expiredProfile "' . $profile . '"' . "\r\n"
			. ':local job [/system scheduler find where name=$user]' . "\r\n"
			. ':local s [/ppp secret find where name=$user]' . "\r\n"
			. ':if ([:len $s] = 0) do={' . "\r\n"
			. '    :log warning ("AFC cutoff skipped; PPP missing: " . $user)' . "\r\n"
			. '    :if ([:len $job] > 0) do={ /system scheduler disable $job }' . "\r\n"
			. '    :return' . "\r\n"
			. '}' . "\r\n"
			. ':local comment [/ppp secret get $s comment]' . "\r\n"
			. ':if ([:find $comment ("nextDue:" . $expectedDue)] = nil) do={' . "\r\n"
			. '    :log info ("AFC stale cutoff skipped; nextDue changed for " . $user)' . "\r\n"
			. '    :if ([:len $job] > 0) do={ /system scheduler disable $job }' . "\r\n"
			. '    :return' . "\r\n"
			. '}' . "\r\n"
			. ':if ([:find $comment ("cutoffDate:" . $expectedCutoff)] = nil) do={' . "\r\n"
			. '    :log info ("AFC stale cutoff skipped; cutoffDate changed for " . $user)' . "\r\n"
			. '    :if ([:len $job] > 0) do={ /system scheduler disable $job }' . "\r\n"
			. '    :return' . "\r\n"
			. '}' . "\r\n"
			. '/ppp secret set $s profile=$expiredProfile' . "\r\n"
			. ':local active [/ppp active find where name=$user]' . "\r\n"
			. ':if ([:len $active] > 0) do={ /ppp active remove $active }' . "\r\n"
			. ':log warning ("AFC expired unpaid PPP account: " . $user)' . "\r\n"
			. ':if ([:len $job] > 0) do={ /system scheduler disable $job }';
	}

	private static function scheduled_datetime( $cutoff_date, $username, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$date     = self::parse_iso_date( $cutoff_date );
		if ( ! $date ) {
			return null;
		}

		$time_parts = array_map( 'intval', explode( ':', $settings['base_time'] ) );
		$date       = $date->setTime( $time_parts[0], $time_parts[1], $time_parts[2] );
		$stagger    = absint( $settings['stagger_seconds'] );
		if ( $stagger > 0 ) {
			$slots  = max( 1, (int) floor( 3600 / $stagger ) );
			$hash   = (int) sprintf( '%u', crc32( strtolower( $username ) ) );
			$offset = ( $hash % $slots ) * $stagger;
			$date   = $date->modify( '+' . $offset . ' seconds' );
		}
		return $date;
	}

	private static function fetch_secrets() {
		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		return is_array( $result ) ? $result : array();
	}

	private static function fetch_secret_by_name( $username ) {
		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'?name=' . $username,
				'=.proplist=.id,name,profile,comment,disabled',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			return $result;
		}
		foreach ( (array) $result as $secret ) {
			if ( isset( $secret['name'] ) && (string) $secret['name'] === (string) $username ) {
				return $secret;
			}
		}
		return new WP_Error( 'afc_scheduler_ppp_missing', __( 'The PPP account no longer exists.', 'airfiber-centralized' ) );
	}

	private static function fetch_schedulers() {
		$result = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/print',
				'=.proplist=.id,name,start-date,start-time,interval,on-event,disabled,run-count,next-run,policy',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		return is_array( $result ) ? $result : array();
	}

	private static function fetch_schedulers_by_name( $username ) {
		$result = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/print',
				'?name=' . $username,
				'=.proplist=.id,name,start-date,start-time,interval,on-event,disabled,run-count,next-run,policy',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		$matches = array();
		foreach ( (array) $result as $scheduler ) {
			if ( isset( $scheduler['name'] ) && (string) $scheduler['name'] === (string) $username ) {
				$matches[] = $scheduler;
			}
		}
		return $matches;
	}

	private static function scheduler_map( $schedulers ) {
		$map = array();
		foreach ( (array) $schedulers as $scheduler ) {
			if ( empty( $scheduler['name'] ) ) {
				continue;
			}
			$name = (string) $scheduler['name'];
			if ( ! isset( $map[ $name ] ) ) {
				$map[ $name ] = array();
			}
			$map[ $name ][] = $scheduler;
		}
		return $map;
	}

	private static function customer_name( $details, $username ) {
		return ! empty( $details['name'] ) ? (string) $details['name'] : $username;
	}

	private static function analyze_secret( $secret, $scheduler_map, $settings ) {
		$id       = isset( $secret['.id'] ) ? (string) $secret['.id'] : '';
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		$comment  = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details  = AFC_Comment_Fields::parse_comment( $comment );
		$next_due = self::custom_value( $details, 'nextDue' );
		$cutoff   = self::custom_value( $details, 'cutoffDate' );
		$promise  = self::custom_value( $details, 'promisedPayDate' );
		$due_date = self::parse_iso_date( $next_due );
		$cut_date = self::parse_iso_date( $cutoff );
		$expected = $cut_date ? self::scheduled_datetime( $cutoff, $username, $settings ) : null;
		$matches  = isset( $scheduler_map[ $username ] ) ? $scheduler_map[ $username ] : array();
		$today    = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );

		$row = array(
			'pppId'             => $id,
			'name'              => $username,
			'customer'          => self::customer_name( $details, $username ),
			'profile'           => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'nextDue'           => $next_due,
			'cutoffDate'        => $cutoff,
			'promisedPayDate'   => $promise,
			'expectedDate'      => $expected ? $expected->format( 'Y-m-d' ) : '',
			'expectedTime'      => $expected ? $expected->format( 'H:i:s' ) : '',
			'schedulerId'       => '',
			'currentDate'       => '',
			'currentTime'       => '',
			'currentInterval'   => '',
			'runCount'          => 0,
			'nextRun'           => '',
			'disabled'          => false,
			'managed'           => false,
			'needsSync'         => false,
			'selectable'        => false,
			'selectedByDefault' => false,
			'status'            => 'invalid',
			'message'           => '',
		);

		if ( ! $id || ! $username ) {
			$row['message'] = __( 'PPP account data is incomplete.', 'airfiber-centralized' );
			return $row;
		}
		if ( ! $due_date || ! $cut_date ) {
			$row['message'] = __( 'nextDue or cutoffDate is missing or invalid.', 'airfiber-centralized' );
			return $row;
		}
		if ( count( $matches ) > 1 ) {
			$row['status']  = 'duplicate';
			$row['message'] = __( 'More than one scheduler has this PPP username. Resolve the duplicates in MikroTik first.', 'airfiber-centralized' );
			return $row;
		}

		if ( empty( $matches ) ) {
			if ( $cut_date < $today ) {
				$row['status']     = 'overdue';
				$row['selectable'] = true;
				$row['message']    = __( 'The cutoff date is already past. Review before scheduling an immediate cutoff.', 'airfiber-centralized' );
				return $row;
			}
			$row['status']            = 'missing';
			$row['needsSync']         = true;
			$row['selectable']        = true;
			$row['selectedByDefault'] = true;
			$row['message']           = __( 'No scheduler exists. Airfiber can create it.', 'airfiber-centralized' );
			return $row;
		}

		$scheduler             = $matches[0];
		$row['schedulerId']     = isset( $scheduler['.id'] ) ? (string) $scheduler['.id'] : '';
		$row['currentDate']     = self::normalize_router_date( isset( $scheduler['start-date'] ) ? $scheduler['start-date'] : '' );
		$row['currentTime']     = self::normalize_time( isset( $scheduler['start-time'] ) ? $scheduler['start-time'] : '' );
		$row['currentInterval'] = isset( $scheduler['interval'] ) ? (string) $scheduler['interval'] : '';
		$row['runCount']        = isset( $scheduler['run-count'] ) ? absint( $scheduler['run-count'] ) : 0;
		$row['nextRun']         = isset( $scheduler['next-run'] ) ? (string) $scheduler['next-run'] : '';
		$row['disabled']        = self::router_bool( isset( $scheduler['disabled'] ) ? $scheduler['disabled'] : '' );
		$event                  = isset( $scheduler['on-event'] ) ? (string) $scheduler['on-event'] : '';
		$row['managed']         = false !== strpos( $event, self::SCRIPT_MARKER );

		$expected_script = self::build_script( $username, $next_due, $cutoff, $settings['expired_profile'] );
		$zero_interval   = in_array( strtolower( trim( $row['currentInterval'] ) ), array( '', '0', '0s', '00:00:00' ), true );
		$script_matches  = hash_equals( hash( 'sha256', $expected_script ), hash( 'sha256', $event ) );
		$date_matches    = $row['currentDate'] === $row['expectedDate'];
		$time_matches    = $row['currentTime'] === $row['expectedTime'];

		if ( $cut_date < $today ) {
			$row['status']     = 'overdue';
			$row['selectable'] = true;
			$row['needsSync']  = true;
			$row['message']    = __( 'The cutoff date is already past. Syncing requires explicit immediate-cutoff confirmation.', 'airfiber-centralized' );
			return $row;
		}

		if ( ! $row['managed'] ) {
			$row['status']            = 'legacy';
			$row['needsSync']         = true;
			$row['selectable']        = true;
			$row['selectedByDefault'] = true;
			$row['message']           = __( 'Existing scheduler found, but it uses the old unsafe event script.', 'airfiber-centralized' );
			return $row;
		}

		if ( ! $date_matches || ! $time_matches || ! $zero_interval || ! $script_matches ) {
			$row['status']            = 'stale';
			$row['needsSync']         = true;
			$row['selectable']        = true;
			$row['selectedByDefault'] = true;
			$row['message']           = __( 'The scheduler date, time, interval, or safety script does not match the PPP billing comment.', 'airfiber-centralized' );
			return $row;
		}

		if ( $row['disabled'] ) {
			$row['status']     = 'disabled';
			$row['selectable'] = true;
			$row['message']    = __( 'The scheduler is synchronized but currently disabled.', 'airfiber-centralized' );
			return $row;
		}

		$row['status']  = 'healthy';
		$row['message'] = __( 'The scheduler matches nextDue and cutoffDate.', 'airfiber-centralized' );
		return $row;
	}

	private static function scheduler_snapshot( $scheduler ) {
		return array(
			'id'         => isset( $scheduler['.id'] ) ? (string) $scheduler['.id'] : '',
			'name'       => isset( $scheduler['name'] ) ? (string) $scheduler['name'] : '',
			'start-date' => isset( $scheduler['start-date'] ) ? (string) $scheduler['start-date'] : '',
			'start-time' => isset( $scheduler['start-time'] ) ? (string) $scheduler['start-time'] : '',
			'interval'   => isset( $scheduler['interval'] ) ? (string) $scheduler['interval'] : '',
			'on-event'   => isset( $scheduler['on-event'] ) ? (string) $scheduler['on-event'] : '',
			'disabled'   => isset( $scheduler['disabled'] ) ? (string) $scheduler['disabled'] : '',
			'policy'     => isset( $scheduler['policy'] ) ? (string) $scheduler['policy'] : '',
		);
	}

	private static function save_backups( $items ) {
		if ( empty( $items ) ) {
			return;
		}
		$backups = get_option( self::BACKUP_OPTION, array() );
		$backups = is_array( $backups ) ? array_merge( $backups, $items ) : $items;
		if ( count( $backups ) > self::BACKUP_LIMIT ) {
			$backups = array_slice( $backups, -self::BACKUP_LIMIT );
		}
		update_option( self::BACKUP_OPTION, $backups, false );
	}

	private static function scheduler_command( $secret, $existing, $settings, $run_now = false ) {
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		$details  = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );
		$next_due = self::custom_value( $details, 'nextDue' );
		$cutoff   = self::custom_value( $details, 'cutoffDate' );
		$due_date = self::parse_iso_date( $next_due );
		$cut_date = self::parse_iso_date( $cutoff );
		if ( ! $username || ! $due_date || ! $cut_date ) {
			return new WP_Error( 'afc_scheduler_missing_dates', __( 'The PPP account needs valid nextDue and cutoffDate fields.', 'airfiber-centralized' ) );
		}

		$scheduled = self::scheduled_datetime( $cutoff, $username, $settings );
		if ( $run_now ) {
			$scheduled = new DateTimeImmutable( current_time( 'Y-m-d H:i:s' ), self::timezone() );
			$scheduled = $scheduled->modify( '+2 minutes' );
		}

		$script = self::build_script( $username, $next_due, $cutoff, $settings['expired_profile'] );
		$words  = array(
			$existing ? '/system/scheduler/set' : '/system/scheduler/add',
		);
		if ( $existing ) {
			$words[] = '=.id=' . (string) $existing['.id'];
		} else {
			$words[] = '=name=' . $username;
		}
		$words[] = '=start-date=' . $scheduled->format( 'Y-m-d' );
		$words[] = '=start-time=' . $scheduled->format( 'H:i:s' );
		$words[] = '=interval=0s';
		$words[] = '=on-event=' . $script;
		$words[] = '=policy=read,write,policy,test';
		$words[] = '=disabled=no';

		return array(
			'words'      => $words,
			'script'     => $script,
			'nextDue'    => $next_due,
			'cutoffDate' => $cutoff,
			'startDate'  => $scheduled->format( 'Y-m-d' ),
			'startTime'  => $scheduled->format( 'H:i:s' ),
		);
	}

	private static function upsert_scheduler( $secret, $matches, $settings, $run_now = false, $source = 'manual' ) {
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'afc_scheduler_duplicate', __( 'Duplicate schedulers use this PPP username.', 'airfiber-centralized' ) );
		}
		$existing = $matches ? $matches[0] : null;
		$backup   = $existing ? self::scheduler_snapshot( $existing ) : null;

		// Disable the old job first. If the rewrite fails, it remains disabled and
		// cannot expire an advance-paying customer using stale dates.
		if ( $existing && ! self::router_bool( isset( $existing['disabled'] ) ? $existing['disabled'] : '' ) ) {
			$disabled = AFC_MikroTik::run_command(
				array(
					'/system/scheduler/set',
					'=.id=' . (string) $existing['.id'],
					'=disabled=yes',
				)
			);
			if ( is_wp_error( $disabled ) ) {
				return $disabled;
			}
		}

		$command = self::scheduler_command( $secret, $existing, $settings, $run_now );
		if ( is_wp_error( $command ) ) {
			return $command;
		}
		$result = AFC_MikroTik::run_command( $command['words'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $backup ) {
			self::save_backups(
				array(
					array(
						'time'      => current_time( 'mysql' ),
						'operator'  => get_current_user_id(),
						'source'    => $source,
						'ppp_name'  => $username,
						'scheduler' => $backup,
					)
				)
			);
		}

		return array(
			'name'       => $username,
			'action'     => $existing ? 'updated' : 'created',
			'startDate'  => $command['startDate'],
			'startTime'  => $command['startTime'],
			'nextDue'    => $command['nextDue'],
			'cutoffDate' => $command['cutoffDate'],
		);
	}

	public static function sync_account( $username, $source = 'automatic' ) {
		$settings = self::get_settings();
		if ( in_array( $source, array( 'automatic', 'payment', 'promise' ), true ) && empty( $settings['auto_sync_payments'] ) ) {
			return array( 'status' => 'disabled', 'message' => __( 'Automatic scheduler synchronization is disabled.', 'airfiber-centralized' ) );
		}

		$secret = self::fetch_secret_by_name( $username );
		if ( is_wp_error( $secret ) ) {
			self::record_last_sync( $username, $source, $secret );
			return $secret;
		}
		$matches = self::fetch_schedulers_by_name( $username );
		if ( is_wp_error( $matches ) ) {
			self::record_last_sync( $username, $source, $matches );
			return $matches;
		}

		$result = self::upsert_scheduler( $secret, $matches, $settings, false, $source );
		self::record_last_sync( $username, $source, $result );
		return $result;
	}

	private static function record_last_sync( $username, $source, $result ) {
		$data = array(
			'time'     => current_time( 'mysql' ),
			'username' => $username,
			'source'   => $source,
			'status'   => is_wp_error( $result ) ? 'error' : 'success',
			'message'  => is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%s scheduler %s.', $username, isset( $result['action'] ) ? $result['action'] : 'synchronized' ),
		);
		update_option( self::LAST_SYNC_OPTION, $data, false );
	}

	public static function sync_recorded_payment( $payment_id, $customer_id ) {
		$username = get_post_meta( $payment_id, '_afc_ppp_username', true );
		if ( ! $username ) {
			return;
		}
		$result = self::sync_account( (string) $username, 'payment' );
		self::$synced_this_request[ (string) $username ] = true;
		update_post_meta(
			$payment_id,
			'_afc_scheduler_sync',
			is_wp_error( $result )
				? array( 'status' => 'error', 'message' => $result->get_error_message(), 'time' => current_time( 'mysql' ) )
				: array_merge( array( 'status' => 'success', 'time' => current_time( 'mysql' ) ), $result )
		);
	}

	public static function sync_quick_payment_fallback( $username, $method, $date, $customer_id ) {
		$username = (string) $username;
		if ( ! $username || ! empty( self::$synced_this_request[ $username ] ) ) {
			return;
		}
		self::$synced_this_request[ $username ] = true;
		self::sync_account( $username, 'payment' );
	}

	public static function register_promise_shutdown_sync() {
		if ( self::$promise_shutdown_registered ) {
			return;
		}
		$username = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $username ) {
			return;
		}
		self::$promise_shutdown_registered = true;
		register_shutdown_function(
			function () use ( $username ) {
				if ( class_exists( 'AFC_Schedulers' ) ) {
					AFC_Schedulers::sync_account( $username, 'promise' );
				}
			}
		);
	}

	private static function preview_data() {
		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		$schedulers = self::fetch_schedulers();
		if ( is_wp_error( $schedulers ) ) {
			return $schedulers;
		}

		$settings      = self::get_settings();
		$scheduler_map = self::scheduler_map( $schedulers );
		$ppp_names     = array();
		$rows          = array();
		$counts        = array(
			'healthy'   => 0,
			'missing'   => 0,
			'legacy'    => 0,
			'stale'     => 0,
			'disabled'  => 0,
			'overdue'   => 0,
			'invalid'   => 0,
			'duplicate' => 0,
		);

		foreach ( $secrets as $secret ) {
			if ( empty( $secret['name'] ) ) {
				continue;
			}
			$ppp_names[ (string) $secret['name'] ] = true;
			$row = self::analyze_secret( $secret, $scheduler_map, $settings );
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ]++;
			}
			$rows[] = $row;
		}

		$orphans = array();
		foreach ( $schedulers as $scheduler ) {
			$name  = isset( $scheduler['name'] ) ? (string) $scheduler['name'] : '';
			$event = isset( $scheduler['on-event'] ) ? (string) $scheduler['on-event'] : '';
			if ( $name && false !== strpos( $event, self::SCRIPT_MARKER ) && empty( $ppp_names[ $name ] ) ) {
				$orphans[] = array(
					'id'       => isset( $scheduler['.id'] ) ? (string) $scheduler['.id'] : '',
					'name'     => $name,
					'date'     => self::normalize_router_date( isset( $scheduler['start-date'] ) ? $scheduler['start-date'] : '' ),
					'time'     => self::normalize_time( isset( $scheduler['start-time'] ) ? $scheduler['start-time'] : '' ),
					'disabled' => self::router_bool( isset( $scheduler['disabled'] ) ? $scheduler['disabled'] : '' ),
				);
			}
		}

		usort(
			$rows,
			function ( $first, $second ) {
				$order = array( 'missing' => 0, 'legacy' => 1, 'stale' => 2, 'disabled' => 3, 'overdue' => 4, 'invalid' => 5, 'duplicate' => 6, 'healthy' => 7 );
				$sort  = $order[ $first['status'] ] <=> $order[ $second['status'] ];
				return $sort ? $sort : strcasecmp( $first['customer'], $second['customer'] );
			}
		);

		return array(
			'rows'       => $rows,
			'counts'     => $counts,
			'total'      => count( $rows ),
			'orphans'    => $orphans,
			'settings'   => $settings,
			'lastSync'   => get_option( self::LAST_SYNC_OPTION, array() ),
			'backupCount'=> count( (array) get_option( self::BACKUP_OPTION, array() ) ),
		);
	}

	public static function ajax_preview() {
		self::authorize();
		$data = self::preview_data();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		wp_send_json_success( $data );
	}

	private static function posted_ids() {
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $raw ) ) ) );
	}

	private static function find_secret_map( $secrets ) {
		$map = array();
		foreach ( (array) $secrets as $secret ) {
			if ( ! empty( $secret['.id'] ) ) {
				$map[ (string) $secret['.id'] ] = $secret;
			}
		}
		return $map;
	}

	private static function scheduler_operation( $operation, $secret, $matches, $settings, $allow_overdue ) {
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'afc_scheduler_duplicate', __( 'Duplicate schedulers use this PPP username.', 'airfiber-centralized' ) );
		}
		$existing = $matches ? $matches[0] : null;

		if ( 'sync' === $operation ) {
			$details  = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );
			$cutoff   = self::parse_iso_date( self::custom_value( $details, 'cutoffDate' ) );
			$today    = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
			$run_now  = $cutoff && $cutoff < $today;
			if ( $run_now && ! $allow_overdue ) {
				return new WP_Error( 'afc_scheduler_overdue_confirmation', __( 'The cutoff date is past. Immediate cutoff confirmation is required.', 'airfiber-centralized' ) );
			}
			return self::upsert_scheduler( $secret, $matches, $settings, $run_now, 'bulk' );
		}

		if ( ! $existing ) {
			return new WP_Error( 'afc_scheduler_missing', __( 'No scheduler exists for this PPP account.', 'airfiber-centralized' ) );
		}
		if ( 'disable' === $operation || 'enable' === $operation ) {
			$result = AFC_MikroTik::run_command(
				array(
					'/system/scheduler/set',
					'=.id=' . (string) $existing['.id'],
					'=disabled=' . ( 'disable' === $operation ? 'yes' : 'no' ),
				)
			);
			return is_wp_error( $result ) ? $result : array( 'name' => $username, 'action' => 'disable' === $operation ? 'disabled' : 'enabled' );
		}
		if ( 'delete' === $operation ) {
			self::save_backups(
				array(
					array(
						'time'      => current_time( 'mysql' ),
						'operator'  => get_current_user_id(),
						'source'    => 'delete',
						'ppp_name'  => $username,
						'scheduler' => self::scheduler_snapshot( $existing ),
					)
				)
			);
			$result = AFC_MikroTik::run_command(
				array(
					'/system/scheduler/remove',
					'=.id=' . (string) $existing['.id'],
				)
			);
			return is_wp_error( $result ) ? $result : array( 'name' => $username, 'action' => 'deleted' );
		}

		return new WP_Error( 'afc_scheduler_invalid_operation', __( 'Unknown scheduler operation.', 'airfiber-centralized' ) );
	}

	public static function ajax_apply() {
		self::authorize();
		$ids           = self::posted_ids();
		$operation     = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'sync';
		$allow_overdue = ! empty( $_POST['allow_overdue'] );
		if ( ! in_array( $operation, array( 'sync', 'disable', 'enable', 'delete' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The scheduler operation is invalid.', 'airfiber-centralized' ) ), 400 );
		}
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one PPP account.', 'airfiber-centralized' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Process no more than %d accounts per request.', 'airfiber-centralized' ), self::MAX_BATCH ) ), 400 );
		}

		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		$schedulers = self::fetch_schedulers();
		if ( is_wp_error( $schedulers ) ) {
			wp_send_json_error( array( 'message' => $schedulers->get_error_message() ) );
		}
		$secret_map    = self::find_secret_map( $secrets );
		$scheduler_map = self::scheduler_map( $schedulers );
		$settings      = self::get_settings();
		$updated       = array();
		$failed        = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $secret_map[ $id ] ) ) {
				$failed[] = array( 'id' => $id, 'message' => __( 'PPP account no longer exists.', 'airfiber-centralized' ) );
				continue;
			}
			$secret   = $secret_map[ $id ];
			$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
			$matches  = isset( $scheduler_map[ $username ] ) ? $scheduler_map[ $username ] : array();
			$result   = self::scheduler_operation( $operation, $secret, $matches, $settings, $allow_overdue );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'name' => $username, 'message' => $result->get_error_message() );
			} else {
				$updated[] = $result;
			}
		}

		wp_send_json_success(
			array(
				'updated' => $updated,
				'failed'  => $failed,
				'message' => sprintf( __( 'Completed %1$d scheduler action(s); %2$d failed.', 'airfiber-centralized' ), count( $updated ), count( $failed ) ),
			)
		);
	}

	public static function ajax_adjust() {
		self::authorize();
		$scheduler_id = isset( $_POST['scheduler_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduler_id'] ) ) : '';
		$date_raw     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$time_raw     = isset( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '';
		$date         = self::parse_iso_date( $date_raw );
		$time         = self::normalize_time( $time_raw );
		$today        = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		if ( ! $scheduler_id || ! $date || ! $time ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid scheduler date and time.', 'airfiber-centralized' ) ), 400 );
		}
		if ( $date < $today ) {
			wp_send_json_error( array( 'message' => __( 'A manually adjusted scheduler date cannot be in the past.', 'airfiber-centralized' ) ), 400 );
		}
		$result = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/set',
				'=.id=' . $scheduler_id,
				'=start-date=' . $date->format( 'Y-m-d' ),
				'=start-time=' . $time,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Scheduler date and time updated.', 'airfiber-centralized' ) ) );
	}

	public static function ajax_save_settings() {
		self::authorize();
		$input = array(
			'base_time'          => isset( $_POST['base_time'] ) ? wp_unslash( $_POST['base_time'] ) : '',
			'stagger_seconds'    => isset( $_POST['stagger_seconds'] ) ? wp_unslash( $_POST['stagger_seconds'] ) : 0,
			'expired_profile'    => isset( $_POST['expired_profile'] ) ? wp_unslash( $_POST['expired_profile'] ) : '',
			'auto_sync_payments' => ! empty( $_POST['auto_sync_payments'] ) ? 1 : 0,
		);
		$settings = self::sanitize_settings( $input );
		update_option( self::OPTION_KEY, $settings, false );
		wp_send_json_success( array( 'message' => __( 'Scheduler settings saved. Sync schedulers to apply the new timing.', 'airfiber-centralized' ), 'settings' => $settings ) );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_style(
			'afc-schedulers',
			AFC_URL . 'assets/css/schedulers.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-schedulers',
			AFC_URL . 'assets/js/schedulers.js',
			array( 'jquery', 'afc-frontend-app', 'afc-admin-mode' ),
			AFC_VERSION,
			true
		);
		$settings = self::get_settings();
		wp_localize_script(
			'afc-schedulers',
			'afcSchedulers',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'batchSize'     => 20,
				'settings'      => $settings,
				'backupCount'   => count( (array) get_option( self::BACKUP_OPTION, array() ) ),
				'scriptPreview' => self::build_script( 'PPP_USERNAME', 'YYYY-MM-DD', 'YYYY-MM-DD', $settings['expired_profile'] ),
				'labels'        => array(
					'nav' => __( 'Schedulers', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function render_frontend_panel() {
		$settings = self::get_settings();
		?>
		<section class="afc-frontend-panel afc-advanced-only afc-scheduler-panel" data-afc-panel="schedulers" aria-hidden="true" hidden>
			<div class="afc-scheduler-shell" id="afc-scheduler-center">
				<header class="afc-scheduler-header">
					<div>
						<span class="afc-scheduler-kicker"><?php esc_html_e( 'Advanced MikroTik automation', 'airfiber-centralized' ); ?></span>
						<h1><?php esc_html_e( 'PPP Scheduler Center', 'airfiber-centralized' ); ?></h1>
						<p><?php esc_html_e( 'Read existing schedulers, replace unsafe legacy events, generate missing jobs, and keep every cutoff synchronized with payments and promise dates.', 'airfiber-centralized' ); ?></p>
					</div>
					<button type="button" class="btn btn-primary" data-afc-scheduler-refresh><?php esc_html_e( 'Read MikroTik Schedulers', 'airfiber-centralized' ); ?></button>
				</header>

				<nav class="afc-scheduler-mega" aria-label="<?php esc_attr_e( 'Scheduler Center sections', 'airfiber-centralized' ); ?>">
					<button type="button" class="is-active" data-afc-scheduler-view="overview" aria-pressed="true"><span>⌂</span><strong><?php esc_html_e( 'Overview', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Status and safety', 'airfiber-centralized' ); ?></small></button>
					<button type="button" data-afc-scheduler-view="accounts" aria-pressed="false"><span>≡</span><strong><?php esc_html_e( 'PPP Schedulers', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Read and adjust', 'airfiber-centralized' ); ?></small></button>
					<button type="button" data-afc-scheduler-view="bulk" aria-pressed="false"><span>↻</span><strong><?php esc_html_e( 'Bulk Actions', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Generate or sync', 'airfiber-centralized' ); ?></small></button>
					<button type="button" data-afc-scheduler-view="settings" aria-pressed="false"><span>⚙</span><strong><?php esc_html_e( 'Settings & Script', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Timing and event', 'airfiber-centralized' ); ?></small></button>
				</nav>

				<div class="afc-scheduler-notice" data-afc-scheduler-notice aria-live="polite"></div>

				<section class="afc-scheduler-view is-active" data-afc-scheduler-panel="overview">
					<div class="afc-scheduler-hero">
						<div><span class="afc-scheduler-kicker"><?php esc_html_e( 'One job per PPP username', 'airfiber-centralized' ); ?></span><h2><?php esc_html_e( 'No temporary scheduler is needed for advance payments', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Airfiber disables the existing job while updating it, moves it to the latest cutoffDate, and rewrites its safety checks. A stale job cannot expire a customer after nextDue or cutoffDate changes.', 'airfiber-centralized' ); ?></p></div>
						<div class="afc-scheduler-auto-status"><span></span><strong><?php echo empty( $settings['auto_sync_payments'] ) ? esc_html__( 'Payment auto-sync is off', 'airfiber-centralized' ) : esc_html__( 'Payment auto-sync is on', 'airfiber-centralized' ); ?></strong></div>
					</div>
					<div class="afc-scheduler-summary" data-afc-scheduler-summary>
						<div><strong>—</strong><span><?php esc_html_e( 'PPP users', 'airfiber-centralized' ); ?></span></div>
						<div class="is-healthy"><strong>—</strong><span><?php esc_html_e( 'Healthy', 'airfiber-centralized' ); ?></span></div>
						<div class="is-missing"><strong>—</strong><span><?php esc_html_e( 'Missing', 'airfiber-centralized' ); ?></span></div>
						<div class="is-sync"><strong>—</strong><span><?php esc_html_e( 'Need sync', 'airfiber-centralized' ); ?></span></div>
						<div class="is-review"><strong>—</strong><span><?php esc_html_e( 'Review', 'airfiber-centralized' ); ?></span></div>
					</div>
					<div class="afc-scheduler-overview-grid">
						<article><span>↻</span><h3><?php esc_html_e( 'Read live status', 'airfiber-centralized' ); ?></h3><p><?php esc_html_e( 'Compare every PPP comment with the scheduler of the same name.', 'airfiber-centralized' ); ?></p><button type="button" class="btn btn-primary" data-afc-scheduler-refresh><?php esc_html_e( 'Read MikroTik', 'airfiber-centralized' ); ?></button></article>
						<article><span>＋</span><h3><?php esc_html_e( 'Generate missing jobs', 'airfiber-centralized' ); ?></h3><p><?php esc_html_e( 'Missing, legacy, and stale schedulers are selected automatically after reading.', 'airfiber-centralized' ); ?></p><button type="button" class="btn btn-outline-primary" data-afc-scheduler-go="bulk"><?php esc_html_e( 'Open Bulk Actions', 'airfiber-centralized' ); ?></button></article>
						<article><span>✓</span><h3><?php esc_html_e( 'Payment-safe updates', 'airfiber-centralized' ); ?></h3><p><?php esc_html_e( 'Cash, GCash, 15D, 30D, monthly payments, and promise dates move the same scheduler forward.', 'airfiber-centralized' ); ?></p><button type="button" class="btn btn-outline-secondary" data-afc-scheduler-go="settings"><?php esc_html_e( 'View Safety Script', 'airfiber-centralized' ); ?></button></article>
					</div>
					<div class="afc-scheduler-last-sync" data-afc-scheduler-last-sync hidden></div>
				</section>

				<section class="afc-scheduler-view" data-afc-scheduler-panel="accounts" hidden>
					<div class="afc-scheduler-section-head"><div><span class="afc-scheduler-kicker"><?php esc_html_e( 'Live router data', 'airfiber-centralized' ); ?></span><h2><?php esc_html_e( 'PPP Schedulers', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Search, filter, manually adjust a date or time, or run one scheduler action.', 'airfiber-centralized' ); ?></p></div></div>
					<div class="afc-scheduler-toolbar">
						<label><span><?php esc_html_e( 'Search', 'airfiber-centralized' ); ?></span><input type="search" data-afc-scheduler-search placeholder="<?php esc_attr_e( 'Customer or PPP username', 'airfiber-centralized' ); ?>"></label>
						<label><span><?php esc_html_e( 'Status', 'airfiber-centralized' ); ?></span><select data-afc-scheduler-filter><option value="all"><?php esc_html_e( 'All statuses', 'airfiber-centralized' ); ?></option><option value="healthy"><?php esc_html_e( 'Healthy', 'airfiber-centralized' ); ?></option><option value="missing"><?php esc_html_e( 'Missing', 'airfiber-centralized' ); ?></option><option value="legacy"><?php esc_html_e( 'Legacy script', 'airfiber-centralized' ); ?></option><option value="stale"><?php esc_html_e( 'Needs sync', 'airfiber-centralized' ); ?></option><option value="disabled"><?php esc_html_e( 'Disabled', 'airfiber-centralized' ); ?></option><option value="overdue"><?php esc_html_e( 'Past cutoff', 'airfiber-centralized' ); ?></option><option value="invalid"><?php esc_html_e( 'Missing billing data', 'airfiber-centralized' ); ?></option></select></label>
						<button type="button" class="btn btn-outline-primary" data-afc-scheduler-refresh><?php esc_html_e( 'Refresh', 'airfiber-centralized' ); ?></button>
					</div>
					<div class="afc-scheduler-table-wrap"><table class="afc-scheduler-table"><thead><tr><th><?php esc_html_e( 'Customer / PPP', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Billing source', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Scheduler', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Status', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Actions', 'airfiber-centralized' ); ?></th></tr></thead><tbody data-afc-scheduler-body><tr><td colspan="5" class="afc-scheduler-empty"><?php esc_html_e( 'Read MikroTik schedulers to begin.', 'airfiber-centralized' ); ?></td></tr></tbody></table></div>
				</section>

				<section class="afc-scheduler-view" data-afc-scheduler-panel="bulk" hidden>
					<div class="afc-scheduler-section-head"><div><span class="afc-scheduler-kicker"><?php esc_html_e( 'Safe batch processing', 'airfiber-centralized' ); ?></span><h2><?php esc_html_e( 'Bulk Scheduler Actions', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Missing, legacy, and stale schedulers are selected automatically. Past cutoff accounts require separate confirmation.', 'airfiber-centralized' ); ?></p></div></div>
					<div class="afc-scheduler-bulk-bar">
						<label><input type="checkbox" data-afc-scheduler-select-safe checked> <?php esc_html_e( 'Select missing, legacy, and stale', 'airfiber-centralized' ); ?></label>
						<strong data-afc-scheduler-selected-count>0 selected</strong>
						<div><button type="button" class="btn btn-success" data-afc-scheduler-bulk="sync" disabled><?php esc_html_e( 'Generate / Sync Selected', 'airfiber-centralized' ); ?></button><button type="button" class="btn btn-outline-secondary" data-afc-scheduler-bulk="disable" disabled><?php esc_html_e( 'Disable', 'airfiber-centralized' ); ?></button><button type="button" class="btn btn-outline-secondary" data-afc-scheduler-bulk="enable" disabled><?php esc_html_e( 'Enable', 'airfiber-centralized' ); ?></button></div>
					</div>
					<div class="afc-scheduler-overdue-confirm" data-afc-scheduler-overdue-confirm hidden><label><input type="checkbox" data-afc-scheduler-allow-overdue> <?php esc_html_e( 'I understand selected past-cutoff accounts will be scheduled to expire in about 2 minutes.', 'airfiber-centralized' ); ?></label></div>
					<div class="afc-scheduler-progress" data-afc-scheduler-progress hidden><div><span data-afc-scheduler-progress-label></span><strong data-afc-scheduler-progress-count></strong></div><div><span data-afc-scheduler-progress-bar></span></div></div>
					<div class="afc-scheduler-bulk-list" data-afc-scheduler-bulk-list><p><?php esc_html_e( 'Read MikroTik schedulers first.', 'airfiber-centralized' ); ?></p></div>
				</section>

				<section class="afc-scheduler-view" data-afc-scheduler-panel="settings" hidden>
					<div class="afc-scheduler-section-head"><div><span class="afc-scheduler-kicker"><?php esc_html_e( 'Managed scheduler defaults', 'airfiber-centralized' ); ?></span><h2><?php esc_html_e( 'Settings & Safety Script', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Changing these settings does not rewrite existing jobs until you synchronize them.', 'airfiber-centralized' ); ?></p></div></div>
					<div class="afc-scheduler-settings-grid">
						<form data-afc-scheduler-settings-form>
							<label><span><?php esc_html_e( 'Base cutoff time', 'airfiber-centralized' ); ?></span><input type="time" step="1" name="base_time" value="<?php echo esc_attr( $settings['base_time'] ); ?>"></label>
							<label><span><?php esc_html_e( 'Stagger seconds', 'airfiber-centralized' ); ?></span><input type="number" min="0" max="60" name="stagger_seconds" value="<?php echo esc_attr( $settings['stagger_seconds'] ); ?>"><small><?php esc_html_e( 'Uses a stable username-based offset within roughly one hour, avoiding hundreds of scripts at the same second.', 'airfiber-centralized' ); ?></small></label>
							<label><span><?php esc_html_e( 'Expired PPP profile', 'airfiber-centralized' ); ?></span><input type="text" name="expired_profile" value="<?php echo esc_attr( $settings['expired_profile'] ); ?>"></label>
							<label class="afc-scheduler-switch"><input type="checkbox" name="auto_sync_payments" value="1" <?php checked( ! empty( $settings['auto_sync_payments'] ) ); ?>><span><?php esc_html_e( 'Automatically create or move the scheduler after payments and promise-date changes', 'airfiber-centralized' ); ?></span></label>
							<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Scheduler Settings', 'airfiber-centralized' ); ?></button>
						</form>
						<article class="afc-scheduler-script-card"><header><div><small><?php esc_html_e( 'Managed on-event', 'airfiber-centralized' ); ?></small><h3><?php esc_html_e( 'Advance-payment safety checks', 'airfiber-centralized' ); ?></h3></div><span><?php esc_html_e( 'One-time · interval 0s', 'airfiber-centralized' ); ?></span></header><p><?php esc_html_e( 'The event expires a PPP account only when both nextDue and cutoffDate still match the cycle embedded in the scheduler. Any payment or promise change makes an old event stale and harmless.', 'airfiber-centralized' ); ?></p><pre data-afc-scheduler-script-preview></pre></article>
					</div>
					<div class="afc-scheduler-safety-grid"><article><strong><?php esc_html_e( 'Payment', 'airfiber-centralized' ); ?></strong><span><?php esc_html_e( 'Disables the old job, writes the payment dates, then moves and enables the same scheduler.', 'airfiber-centralized' ); ?></span></article><article><strong><?php esc_html_e( 'Promise date', 'airfiber-centralized' ); ?></strong><span><?php esc_html_e( 'Moves the same scheduler to the effective promised cutoff.', 'airfiber-centralized' ); ?></span></article><article><strong><?php esc_html_e( 'Sync failure', 'airfiber-centralized' ); ?></strong><span><?php esc_html_e( 'An existing job remains disabled instead of running with stale dates.', 'airfiber-centralized' ); ?></span></article><article><strong><?php esc_html_e( 'Execution', 'airfiber-centralized' ); ?></strong><span><?php esc_html_e( 'Sets profile to Expired and removes the active PPP session; it does not remove PPPoE interfaces.', 'airfiber-centralized' ); ?></span></article></div>
				</section>
			</div>
		</section>
		<?php
	}
}
