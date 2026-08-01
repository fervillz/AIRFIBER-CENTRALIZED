<?php

defined( 'ABSPATH' ) || exit;

trait AFC_PPP_Manager_Router_Trait {
	private static function fetch_profiles() {
		$result = AFC_MikroTik::run_command( array( '/ppp/profile/print', '=.proplist=.id,name,rate-limit,comment' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		$profiles = array();
		foreach ( (array) $result as $profile ) {
			$name = isset( $profile['name'] ) ? trim( (string) $profile['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$profiles[] = array(
				'id'         => isset( $profile['.id'] ) ? (string) $profile['.id'] : '',
				'name'       => $name,
				'rate_limit' => isset( $profile['rate-limit'] ) ? (string) $profile['rate-limit'] : '',
				'comment'    => isset( $profile['comment'] ) ? (string) $profile['comment'] : '',
				'basic'      => ! in_array( strtolower( $name ), array( 'default', 'default-encryption', 'expired' ), true ),
			);
		}
		usort( $profiles, function ( $first, $second ) { return strnatcasecmp( $first['name'], $second['name'] ); } );
		return $profiles;
	}

	private static function fetch_secrets() {
		$result = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=.id,name,password,service,profile,comment,disabled,remote-address,caller-id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		return is_array( $result ) ? $result : array();
	}

	private static function fetch_secret_by_id( $id ) {
		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		foreach ( $secrets as $secret ) {
			if ( isset( $secret['.id'] ) && (string) $secret['.id'] === (string) $id ) {
				return $secret;
			}
		}
		return new WP_Error( 'afc_ppp_missing', __( 'The PPP account no longer exists in MikroTik.', 'airfiber-centralized' ) );
	}

	private static function fetch_secret_by_name( $username ) {
		$result = AFC_MikroTik::run_command( array( '/ppp/secret/print', '?name=' . $username, '=.proplist=.id,name,password,service,profile,comment,disabled,remote-address,caller-id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) && (string) $result['name'] === (string) $username ) {
			return $result;
		}
		foreach ( (array) $result as $secret ) {
			if ( isset( $secret['name'] ) && (string) $secret['name'] === (string) $username ) {
				return $secret;
			}
		}
		return null;
	}

	private static function ros_escape( $value ) {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value );
	}

	private static function build_scheduler_script( $username, $next_due, $cutoff, $expired_profile ) {
		$user    = self::ros_escape( $username );
		$due     = self::ros_escape( $next_due );
		$cutoff  = self::ros_escape( $cutoff );
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

	private static function scheduler_datetime( $cutoff, $username ) {
		$date = self::date( $cutoff );
		if ( ! $date ) {
			return null;
		}
		$settings = class_exists( 'AFC_Schedulers' ) ? AFC_Schedulers::get_settings() : array( 'base_time' => '00:05:00', 'stagger_seconds' => 10 );
		$parts = array_map( 'intval', explode( ':', isset( $settings['base_time'] ) ? $settings['base_time'] : '00:05:00' ) );
		$date  = $date->setTime( $parts[0], $parts[1], $parts[2] );
		$stagger = isset( $settings['stagger_seconds'] ) ? absint( $settings['stagger_seconds'] ) : 10;
		if ( $stagger ) {
			$slots  = max( 1, (int) floor( 3600 / $stagger ) );
			$hash   = (int) sprintf( '%u', crc32( strtolower( $username ) ) );
			$date   = $date->modify( '+' . ( ( $hash % $slots ) * $stagger ) . ' seconds' );
		}
		return $date;
	}

	private static function fetch_schedulers( $username ) {
		$result = AFC_MikroTik::run_command( array( '/system/scheduler/print', '?name=' . $username, '=.proplist=.id,name,on-event,disabled' ) );
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

	private static function sync_scheduler( $username, $next_due, $cutoff, $old_username = '' ) {
		$settings = class_exists( 'AFC_Schedulers' ) ? AFC_Schedulers::get_settings() : array( 'expired_profile' => 'Expired' );
		$expired  = isset( $settings['expired_profile'] ) ? $settings['expired_profile'] : 'Expired';
		$date     = self::scheduler_datetime( $cutoff, $username );
		if ( ! $date ) {
			return new WP_Error( 'afc_invalid_cutoff', __( 'The cutoff date is invalid.', 'airfiber-centralized' ) );
		}
		$old_matches = array();
		if ( $old_username && $old_username !== $username ) {
			$old_matches = self::fetch_schedulers( $old_username );
			if ( is_wp_error( $old_matches ) ) {
				return $old_matches;
			}
			foreach ( $old_matches as $old_scheduler ) {
				$old_event = isset( $old_scheduler['on-event'] ) ? (string) $old_scheduler['on-event'] : '';
				if ( false === strpos( $old_event, self::SCRIPT_MARKER ) ) {
					return new WP_Error( 'afc_old_unmanaged_scheduler', __( 'The old PPP username has a custom scheduler. Rename it manually from Advanced mode first.', 'airfiber-centralized' ) );
				}
			}
		}
		$matches = self::fetch_schedulers( $username );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'afc_duplicate_scheduler', __( 'More than one scheduler already uses this PPP username.', 'airfiber-centralized' ) );
		}
		if ( $matches && false === strpos( isset( $matches[0]['on-event'] ) ? $matches[0]['on-event'] : '', self::SCRIPT_MARKER ) ) {
			return new WP_Error( 'afc_unmanaged_scheduler', __( 'This account has a custom scheduler. Review it in Advanced mode before changing billing dates.', 'airfiber-centralized' ) );
		}
		$script = self::build_scheduler_script( $username, $next_due, $cutoff, $expired );
		$words = array( $matches ? '/system/scheduler/set' : '/system/scheduler/add' );
		if ( $matches ) {
			$words[] = '=.id=' . (string) $matches[0]['.id'];
		} else {
			$words[] = '=name=' . $username;
		}
		$words[] = '=start-date=' . $date->format( 'Y-m-d' );
		$words[] = '=start-time=' . $date->format( 'H:i:s' );
		$words[] = '=interval=0s';
		$words[] = '=on-event=' . $script;
		$words[] = '=policy=read,write,policy,test';
		$words[] = '=disabled=no';
		$result = AFC_MikroTik::run_command( $words );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $old_username && $old_username !== $username ) {
			foreach ( $old_matches as $scheduler ) {
				AFC_MikroTik::run_command( array( '/system/scheduler/remove', '=.id=' . (string) $scheduler['.id'] ) );
			}
		}
		return true;
	}

}
