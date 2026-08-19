<?php

defined( 'ABSPATH' ) || exit;

/**
 * Creates a safe first scheduler for old PPP users that have an Installed Date
 * but never received nextDue/cutoffDate or a MikroTik scheduler.
 *
 * The installed day becomes the monthly expiry day. We choose the next future
 * occurrence of that day, backfill nextDue and cutoffDate to that date, then
 * create the normal Airfiber managed one-time scheduler. A later payment will
 * move the same scheduler using the regular billing flow.
 */
class AFC_Scheduler_Installed_Fallback {

	const NONCE         = 'afc_schedulers';
	const MAX_BATCH     = 25;
	const SCRIPT_MARKER = 'AFC-MANAGED-SCHEDULER v1';

	public static function init() {
		self::ensure_billing_fields();
		add_action( 'wp_ajax_afc_scheduler_installed_candidates', array( __CLASS__, 'ajax_candidates' ) );
		add_action( 'wp_ajax_afc_scheduler_installed_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 101 );
	}

	private static function ensure_billing_fields() {
		$fields = get_option( AFC_Comment_Fields::OPTION_KEY, array() );
		$fields = is_array( $fields ) ? $fields : array();
		$needed = array(
			'nextdue' => array(
				'key' => 'nextDue', 'label' => __( 'Next Due', 'airfiber-centralized' ), 'type' => 'date', 'default' => '', 'core' => false,
			),
			'cutoffdate' => array(
				'key' => 'cutoffDate', 'label' => __( 'Cutoff Date', 'airfiber-centralized' ), 'type' => 'date', 'default' => '', 'core' => false,
			),
		);
		$found = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				$found[ strtolower( (string) $field['key'] ) ] = true;
			}
		}
		$changed = false;
		foreach ( $needed as $lower => $field ) {
			if ( empty( $found[ $lower ] ) ) {
				$fields[] = $field;
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( AFC_Comment_Fields::OPTION_KEY, AFC_Comment_Fields::sanitize_fields( $fields ), false );
		}
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script(
			'afc-scheduler-installed-fallback',
			AFC_URL . 'assets/js/scheduler-installed-fallback.js',
			array( 'jquery', 'afc-schedulers', 'afc-scheduler-migration-selection' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-scheduler-installed-fallback',
			'afcSchedulerInstalledFallback',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create MikroTik schedulers.', 'airfiber-centralized' ) ), 403 );
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

	private static function raw_comment_value( $comment, $key ) {
		if ( preg_match( '/^' . preg_quote( $key, '/' ) . '\s*:\s*(.*?)\s*$/mi', (string) $comment, $match ) ) {
			$value = trim( (string) $match[1] );
			return 'N/A' === strtoupper( $value ) ? '' : $value;
		}
		return '';
	}

	private static function next_expiry_from_installed( DateTimeImmutable $installed ) {
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		if ( $installed > $today ) {
			return $installed;
		}

		$billing_day = (int) $installed->format( 'j' );
		$month       = $today->modify( 'first day of this month' );
		for ( $offset = 0; $offset < 14; $offset++ ) {
			$base      = 0 === $offset ? $month : $month->modify( '+' . $offset . ' months' );
			$day       = min( $billing_day, (int) $base->format( 't' ) );
			$candidate = $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'm' ), $day );
			// Never create an immediate/past cutoff from historical installation data.
			if ( $candidate > $today ) {
				return $candidate;
			}
		}
		return $today->modify( '+1 month' );
	}

	private static function fetch_secrets() {
		$result = AFC_MikroTik::run_command(
			array( '/ppp/secret/print', '=.proplist=.id,name,profile,comment,disabled' )
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
			array( '/system/scheduler/print', '=.proplist=.id,name,on-event,disabled' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		return is_array( $result ) ? $result : array();
	}

	private static function scheduler_names( $schedulers ) {
		$names = array();
		foreach ( (array) $schedulers as $scheduler ) {
			if ( ! empty( $scheduler['name'] ) ) {
				$names[ (string) $scheduler['name'] ] = true;
			}
		}
		return $names;
	}

	private static function fallback_details( $secret, $scheduler_names ) {
		$id       = isset( $secret['.id'] ) ? (string) $secret['.id'] : '';
		$username = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		$comment  = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		if ( ! $id || ! $username || isset( $scheduler_names[ $username ] ) ) {
			return null;
		}

		$details   = AFC_Comment_Fields::parse_comment( $comment );
		$installed = self::parse_date( isset( $details['installed'] ) ? $details['installed'] : '' );
		$next_due  = self::parse_date( self::raw_comment_value( $comment, 'nextDue' ) );
		$cutoff    = self::parse_date( self::raw_comment_value( $comment, 'cutoffDate' ) );

		// Only fill the true legacy gap: installed exists, but both billing dates do not.
		if ( ! $installed || $next_due || $cutoff ) {
			return null;
		}

		$expiry = self::next_expiry_from_installed( $installed );
		return array(
			'id'        => $id,
			'name'      => $username,
			'customer'  => ! empty( $details['name'] ) ? (string) $details['name'] : $username,
			'installed' => $installed->format( 'Y-m-d' ),
			'expiry'    => $expiry->format( 'Y-m-d' ),
		);
	}

	private static function ros_escape( $value ) {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value );
	}

	private static function build_script( $username, $expiry, $expired_profile ) {
		$user    = self::ros_escape( $username );
		$date    = self::ros_escape( $expiry );
		$profile = self::ros_escape( $expired_profile );
		return '# ' . self::SCRIPT_MARKER . "\r\n"
			. ':local user "' . $user . '"' . "\r\n"
			. ':local expectedDue "' . $date . '"' . "\r\n"
			. ':local expectedCutoff "' . $date . '"' . "\r\n"
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

	private static function scheduled_datetime( DateTimeImmutable $expiry, $username, $settings ) {
		$parts = array_map( 'intval', explode( ':', isset( $settings['base_time'] ) ? $settings['base_time'] : '00:05:00' ) );
		$date  = $expiry->setTime( $parts[0], $parts[1], $parts[2] );
		$stagger = isset( $settings['stagger_seconds'] ) ? absint( $settings['stagger_seconds'] ) : 0;
		if ( $stagger > 0 ) {
			$slots  = max( 1, (int) floor( 3600 / $stagger ) );
			$hash   = (int) sprintf( '%u', crc32( strtolower( $username ) ) );
			$offset = ( $hash % $slots ) * $stagger;
			$date   = $date->modify( '+' . $offset . ' seconds' );
		}
		return $date;
	}

	private static function create_one( $secret, $scheduler_names, $settings ) {
		$info = self::fallback_details( $secret, $scheduler_names );
		if ( ! $info ) {
			return new WP_Error( 'afc_installed_fallback_not_applicable', __( 'This PPP account is no longer an installed-date fallback candidate.', 'airfiber-centralized' ) );
		}

		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$comment = AFC_Comment_Fields::replace_value( $comment, 'nextDue', $info['expiry'] );
		$comment = AFC_Comment_Fields::replace_value( $comment, 'cutoffDate', $info['expiry'] );

		$comment_result = AFC_MikroTik::run_command(
			array( '/ppp/secret/set', '=.id=' . $info['id'], '=comment=' . $comment )
		);
		if ( is_wp_error( $comment_result ) ) {
			return $comment_result;
		}

		$expiry    = self::parse_date( $info['expiry'] );
		$scheduled = self::scheduled_datetime( $expiry, $info['name'], $settings );
		$script    = self::build_script( $info['name'], $info['expiry'], $settings['expired_profile'] );
		$result    = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/add',
				'=name=' . $info['name'],
				'=start-date=' . $scheduled->format( 'Y-m-d' ),
				'=start-time=' . $scheduled->format( 'H:i:s' ),
				'=interval=0s',
				'=on-event=' . $script,
				'=policy=read,write,policy,test',
				'=disabled=no',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'name'      => $info['name'],
			'action'    => 'created',
			'installed' => $info['installed'],
			'expiry'    => $info['expiry'],
			'startDate' => $scheduled->format( 'Y-m-d' ),
			'startTime' => $scheduled->format( 'H:i:s' ),
		);
	}

	public static function ajax_candidates() {
		self::authorize();
		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		$schedulers = self::fetch_schedulers();
		if ( is_wp_error( $schedulers ) ) {
			wp_send_json_error( array( 'message' => $schedulers->get_error_message() ) );
		}
		$names      = self::scheduler_names( $schedulers );
		$candidates = array();
		foreach ( $secrets as $secret ) {
			$info = self::fallback_details( $secret, $names );
			if ( $info ) {
				$candidates[] = $info;
			}
		}
		wp_send_json_success( array( 'candidates' => $candidates, 'count' => count( $candidates ) ) );
	}

	public static function ajax_apply() {
		self::authorize();
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $raw ) ) ) );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one installed-date PPP account.', 'airfiber-centralized' ) ), 400 );
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
		$secret_map = array();
		foreach ( $secrets as $secret ) {
			if ( ! empty( $secret['.id'] ) ) {
				$secret_map[ (string) $secret['.id'] ] = $secret;
			}
		}
		$names    = self::scheduler_names( $schedulers );
		$settings = AFC_Schedulers::get_settings();
		$updated  = array();
		$failed   = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $secret_map[ $id ] ) ) {
				$failed[] = array( 'id' => $id, 'message' => __( 'PPP account no longer exists.', 'airfiber-centralized' ) );
				continue;
			}
			$result = self::create_one( $secret_map[ $id ], $names, $settings );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'message' => $result->get_error_message() );
				continue;
			}
			$names[ $result['name'] ] = true;
			$updated[] = $result;
		}
		wp_send_json_success(
			array(
				'updated' => $updated,
				'failed'  => $failed,
				'message' => sprintf( __( 'Created %1$d scheduler(s) from Installed Date. %2$d failed.', 'airfiber-centralized' ), count( $updated ), count( $failed ) ),
			)
		);
	}
}
