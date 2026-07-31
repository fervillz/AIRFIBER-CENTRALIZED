<?php

defined( 'ABSPATH' ) || exit;

/**
 * Restricts bulk scheduler migration to missing or legacy schedulers and
 * safely upgrades past-cutoff candidates without immediately expiring users.
 */
class AFC_Scheduler_Migration_Selection {

	const NONCE         = 'afc_schedulers';
	const MAX_BATCH     = 25;
	const SCRIPT_MARKER = 'AFC-MANAGED-SCHEDULER v1';

	public static function init() {
		add_action( 'wp_ajax_afc_scheduler_migration_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 99 );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'afc-scheduler-migration-selection',
			AFC_URL . 'assets/js/scheduler-migration-selection.js',
			array( 'jquery', 'afc-schedulers' ),
			AFC_VERSION,
			true
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to migrate MikroTik schedulers.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function parse_date( $value ) {
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

	private static function scheduled_datetime( DateTimeImmutable $cutoff, $username, $settings, $past_cutoff ) {
		if ( $past_cutoff ) {
			return ( new DateTimeImmutable( current_time( 'Y-m-d H:i:s' ), self::timezone() ) )->modify( '+5 minutes' );
		}

		$parts   = array_map( 'intval', explode( ':', $settings['base_time'] ) );
		$date    = $cutoff->setTime( $parts[0], $parts[1], $parts[2] );
		$stagger = absint( $settings['stagger_seconds'] );
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

	private static function save_backup( $username, $scheduler ) {
		if ( ! $scheduler ) {
			return;
		}
		$backups = get_option( AFC_Schedulers::BACKUP_OPTION, array() );
		$backups = is_array( $backups ) ? $backups : array();
		$backups[] = array(
			'time'      => current_time( 'mysql' ),
			'operator'  => get_current_user_id(),
			'source'    => 'bulk-migration',
			'ppp_name'  => $username,
			'scheduler' => array(
				'id'         => isset( $scheduler['.id'] ) ? (string) $scheduler['.id'] : '',
				'name'       => isset( $scheduler['name'] ) ? (string) $scheduler['name'] : '',
				'start-date' => isset( $scheduler['start-date'] ) ? (string) $scheduler['start-date'] : '',
				'start-time' => isset( $scheduler['start-time'] ) ? (string) $scheduler['start-time'] : '',
				'interval'   => isset( $scheduler['interval'] ) ? (string) $scheduler['interval'] : '',
				'on-event'   => isset( $scheduler['on-event'] ) ? (string) $scheduler['on-event'] : '',
				'disabled'   => isset( $scheduler['disabled'] ) ? (string) $scheduler['disabled'] : '',
				'policy'     => isset( $scheduler['policy'] ) ? (string) $scheduler['policy'] : '',
			),
		);
		if ( count( $backups ) > AFC_Schedulers::BACKUP_LIMIT ) {
			$backups = array_slice( $backups, -AFC_Schedulers::BACKUP_LIMIT );
		}
		update_option( AFC_Schedulers::BACKUP_OPTION, $backups, false );
	}

	private static function migrate_one( $secret, $matches, $settings ) {
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		if ( ! $username ) {
			return new WP_Error( 'afc_scheduler_username_missing', __( 'PPP username is missing.', 'airfiber-centralized' ) );
		}
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'afc_scheduler_duplicate', __( 'Duplicate schedulers use this PPP username.', 'airfiber-centralized' ) );
		}

		$existing = $matches ? $matches[0] : null;
		$event    = $existing && isset( $existing['on-event'] ) ? (string) $existing['on-event'] : '';
		if ( $existing && false !== strpos( $event, self::SCRIPT_MARKER ) ) {
			return new WP_Error( 'afc_scheduler_not_legacy', __( 'This scheduler is already managed by Airfiber and was not changed by bulk migration.', 'airfiber-centralized' ) );
		}

		$details  = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );
		$next_due = self::custom_value( $details, 'nextDue' );
		$cutoff   = self::custom_value( $details, 'cutoffDate' );
		$due_date = self::parse_date( $next_due );
		$cut_date = self::parse_date( $cutoff );
		if ( ! $due_date || ! $cut_date ) {
			return new WP_Error( 'afc_scheduler_missing_dates', __( 'The PPP account needs valid nextDue and cutoffDate fields.', 'airfiber-centralized' ) );
		}

		$today       = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$past_cutoff = $cut_date <= $today;
		$scheduled   = self::scheduled_datetime( $cut_date, $username, $settings, $past_cutoff );
		$script      = self::build_script( $username, $next_due, $cutoff, $settings['expired_profile'] );

		if ( $existing ) {
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
			self::save_backup( $username, $existing );
		}

		$words = array( $existing ? '/system/scheduler/set' : '/system/scheduler/add' );
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
		$words[] = '=disabled=' . ( $past_cutoff ? 'yes' : 'no' );

		$result = AFC_MikroTik::run_command( $words );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'name'       => $username,
			'action'     => $existing ? 'upgraded' : 'created',
			'startDate'  => $scheduled->format( 'Y-m-d' ),
			'startTime'  => $scheduled->format( 'H:i:s' ),
			'disabled'   => $past_cutoff,
			'pastCutoff' => $past_cutoff,
		);
	}

	public static function ajax_apply() {
		self::authorize();
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $raw ) ) ) );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one missing or legacy scheduler.', 'airfiber-centralized' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Process no more than %d schedulers per request.', 'airfiber-centralized' ), self::MAX_BATCH ) ), 400 );
		}

		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		$schedulers = self::fetch_schedulers();
		if ( is_wp_error( $schedulers ) ) {
			wp_send_json_error( array( 'message' => $schedulers->get_error_message() ) );
		}

		$secret_map = array();
		foreach ( $secrets as $secret ) {
			if ( ! empty( $secret['.id'] ) ) {
				$secret_map[ (string) $secret['.id'] ] = $secret;
			}
		}
		$scheduler_map = self::scheduler_map( $schedulers );
		$settings      = AFC_Schedulers::get_settings();
		$updated       = array();
		$failed        = array();
		$disabled      = 0;

		foreach ( $ids as $id ) {
			if ( ! isset( $secret_map[ $id ] ) ) {
				$failed[] = array( 'id' => $id, 'message' => __( 'PPP account no longer exists.', 'airfiber-centralized' ) );
				continue;
			}
			$secret   = $secret_map[ $id ];
			$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
			$matches  = isset( $scheduler_map[ $username ] ) ? $scheduler_map[ $username ] : array();
			$result   = self::migrate_one( $secret, $matches, $settings );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'name' => $username, 'message' => $result->get_error_message() );
				continue;
			}
			if ( ! empty( $result['disabled'] ) ) {
				$disabled++;
			}
			$updated[] = $result;
		}

		wp_send_json_success(
			array(
				'updated'  => $updated,
				'failed'   => $failed,
				'disabled' => $disabled,
				'message'  => sprintf(
					__( 'Migrated %1$d scheduler(s). %2$d were kept disabled because their cutoff date is already past. %3$d failed.', 'airfiber-centralized' ),
					count( $updated ),
					$disabled,
					count( $failed )
				),
			)
		);
	}
}
