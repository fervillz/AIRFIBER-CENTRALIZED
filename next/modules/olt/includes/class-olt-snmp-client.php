<?php

namespace Airfiber\Next\Modules\Olt;

use Airfiber\Next\Secret_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Small read-only SNMP client for the first native OLT slice.
 *
 * The transport behavior intentionally mirrors the proven Classic OLT path.
 * Some GPON firmware answers GET/GETNEXT reliably but stalls on GETBULK walks,
 * so GPON SNMPv2c uses a bounded GETNEXT walker before real_walk().
 */
class Olt_SNMP_Client {
	const SYS_NAME_OID          = '1.3.6.1.2.1.1.5.0';
	const SYS_DESCR_OID         = '1.3.6.1.2.1.1.1.0';
	const EPON_RX_OID           = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7';
	const GPON_RX_OID           = '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7';
	const GETNEXT_ROW_LIMIT     = 4096;
	const GETNEXT_FAILURE_LIMIT = 5;

	public static function test( $record, $secret_overrides = array() ) {
		if ( ! is_array( $record ) ) {
			return new \WP_Error( 'afcn_olt_connection_missing', __( 'The OLT connection record is invalid.', 'airfiber-centralized' ) );
		}

		$config = self::config( $record );
		if ( '' === $config['host'] ) {
			return new \WP_Error( 'afcn_olt_host_missing', __( 'Enter the OLT IP address or hostname first.', 'airfiber-centralized' ) );
		}

		$connection_id = isset( $record['id'] ) ? sanitize_text_field( (string) $record['id'] ) : '';
		$credentials   = self::credentials( $connection_id, $config['version'], $config['security_name'], $secret_overrides );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$name_result        = self::get( $config, $credentials, self::SYS_NAME_OID );
		$description_result = self::get( $config, $credentials, self::SYS_DESCR_OID );
		$rx_values          = self::walk( $config, $credentials, $config['rx_oid'] );
		if ( is_wp_error( $rx_values ) ) {
			return $rx_values;
		}

		$valid = 0;
		foreach ( $rx_values as $value ) {
			if ( preg_match( '/(-?\d+(?:\.\d+)?)\s*(?:dBm)?\b/i', (string) $value, $match ) ) {
				$power = (float) $match[1];
				if ( $power >= -60 && $power < -1 ) {
					$valid++;
				}
			}
		}

		$name        = is_wp_error( $name_result ) ? '' : self::clean_string( $name_result );
		$description = is_wp_error( $description_result ) ? '' : self::clean_string( $description_result );
		$device      = '' !== $name ? $name : $config['host'];

		return array(
			'state'   => 'online',
			'message' => sprintf(
				/* translators: 1: OLT name/host, 2: number of valid optical readings. */
				__( 'Connected to %1$s. %2$d optical readings detected.', 'airfiber-centralized' ),
				$device,
				$valid
			),
			'details' => array(
				'device_name' => $name,
				'description' => $description,
				'onu_count'   => count( $rx_values ),
				'valid_count' => $valid,
				'technology'  => $config['technology'],
			),
		);
	}

	private static function config( $record ) {
		$config     = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		$technology = isset( $config['technology'] ) && 'EPON' === strtoupper( (string) $config['technology'] ) ? 'EPON' : 'GPON';
		$host       = isset( $record['endpoint'] ) && '' !== trim( (string) $record['endpoint'] ) ? (string) $record['endpoint'] : ( isset( $config['host'] ) ? (string) $config['host'] : '' );
		$host       = preg_replace( '#^(?:https?://|udp:)#i', '', trim( sanitize_text_field( $host ) ) );
		$host       = preg_replace( '/[^a-z0-9.\-:]/i', '', trim( $host, "/ \t\n\r\0\x0B" ) );
		$port       = isset( $config['port'] ) ? absint( $config['port'] ) : 161;
		$port       = $port >= 1 && $port <= 65535 ? $port : 161;
		$version    = isset( $config['version'] ) && '2c' === (string) $config['version'] ? '2c' : '3';
		$rx_oid     = isset( $config['rx_oid'] ) ? ltrim( preg_replace( '/[^0-9.]/', '', (string) $config['rx_oid'] ), '.' ) : '';
		if ( ! preg_match( '/^1(?:\.\d+)+$/', $rx_oid ) ) {
			$rx_oid = 'EPON' === $technology ? self::EPON_RX_OID : self::GPON_RX_OID;
		}

		return array(
			'host'          => $host,
			'port'          => $port,
			'version'       => $version,
			'security_name' => ! empty( $config['security_name'] ) ? sanitize_text_field( $config['security_name'] ) : 'airfiber-monitor',
			'technology'    => $technology,
			'rx_oid'        => $rx_oid,
			'timeout_ms'    => min( 10000, max( 500, isset( $config['timeout_ms'] ) && is_numeric( $config['timeout_ms'] ) ? (int) $config['timeout_ms'] : 2500 ) ),
			'retries'       => min( 2, max( 0, isset( $config['retries'] ) && is_numeric( $config['retries'] ) ? (int) $config['retries'] : 1 ) ),
		);
	}

	private static function credentials( $connection_id, $version, $security_name, $overrides = array() ) {
		if ( '2c' === $version ) {
			$community = self::secret_value( $connection_id, 'community', $overrides );
			if ( '' === $community ) {
				return new \WP_Error( 'afcn_olt_community_missing', __( 'Enter the read-only SNMPv2c community first.', 'airfiber-centralized' ) );
			}
			return array( 'community' => $community );
		}

		$auth    = self::secret_value( $connection_id, 'auth_passphrase', $overrides );
		$privacy = self::secret_value( $connection_id, 'privacy_passphrase', $overrides );
		if ( '' === $security_name || '' === $auth || '' === $privacy ) {
			return new \WP_Error( 'afcn_olt_credentials_missing', __( 'Enter the SNMPv3 username, SHA passphrase and DES privacy passphrase first.', 'airfiber-centralized' ) );
		}
		return array(
			'security_name' => $security_name,
			'auth'          => $auth,
			'privacy'       => $privacy,
		);
	}

	private static function secret_value( $connection_id, $key, $overrides ) {
		if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && '' !== (string) $overrides[ $key ] ) {
			return (string) $overrides[ $key ];
		}
		return $connection_id ? Secret_Store::get( $connection_id, $key, '' ) : '';
	}

	private static function get( $config, $credentials, $oid ) {
		$target  = self::target( $config );
		$timeout = $config['timeout_ms'] * 1000;
		$retries = $config['retries'];

		if ( '2c' === $config['version'] ) {
			if ( ! function_exists( 'snmp2_get' ) ) {
				return new \WP_Error( 'afcn_olt_snmp_missing', __( 'PHP SNMPv2 support is not available on this server.', 'airfiber-centralized' ) );
			}
			$value = @snmp2_get( $target, $credentials['community'], $oid, $timeout, $retries );
		} else {
			if ( ! function_exists( 'snmp3_get' ) ) {
				return new \WP_Error( 'afcn_olt_snmp_missing', __( 'PHP SNMPv3 support is not available on this server.', 'airfiber-centralized' ) );
			}
			$value = @snmp3_get( $target, $credentials['security_name'], 'authPriv', 'SHA', $credentials['auth'], 'DES', $credentials['privacy'], $oid, $timeout, $retries );
		}

		return false === $value ? new \WP_Error( 'afcn_olt_snmp_get_failed', __( 'The OLT did not answer the SNMP identity request.', 'airfiber-centralized' ) ) : $value;
	}

	private static function walk( $config, $credentials, $oid ) {
		$target  = self::target( $config );
		$timeout = $config['timeout_ms'] * 1000;
		$retries = $config['retries'];

		if ( function_exists( 'snmp_set_oid_output_format' ) && defined( 'SNMP_OID_OUTPUT_NUMERIC' ) ) {
			@snmp_set_oid_output_format( SNMP_OID_OUTPUT_NUMERIC );
		}

		/* Match Classic: GPON v2c firmware may time out on GETBULK but answer GETNEXT. */
		$getnext_error = null;
		if ( '2c' === $config['version'] && 'GPON' === $config['technology'] ) {
			$getnext = self::walk_getnext( $config, $credentials, $oid );
			if ( ! is_wp_error( $getnext ) && $getnext ) {
				return $getnext;
			}
			$getnext_error = $getnext;
		}

		$rows = false;
		if ( '2c' === $config['version'] ) {
			if ( function_exists( 'snmp2_real_walk' ) ) {
				$rows = @snmp2_real_walk( $target, $credentials['community'], $oid, $timeout, $retries );
			}
		} elseif ( function_exists( 'snmp3_real_walk' ) ) {
			$rows = @snmp3_real_walk( $target, $credentials['security_name'], 'authPriv', 'SHA', $credentials['auth'], 'DES', $credentials['privacy'], $oid, $timeout, $retries );
		}

		if ( false !== $rows && is_array( $rows ) && $rows ) {
			return $rows;
		}

		if ( ! ( '2c' === $config['version'] && 'GPON' === $config['technology'] ) ) {
			$getnext = self::walk_getnext( $config, $credentials, $oid );
			if ( ! is_wp_error( $getnext ) && $getnext ) {
				return $getnext;
			}
			$getnext_error = $getnext;
		}

		return is_wp_error( $getnext_error )
			? $getnext_error
			: new \WP_Error( 'afcn_olt_snmp_walk_failed', __( 'The OLT answered, but the configured RX OID could not be read.', 'airfiber-centralized' ) );
	}

	private static function walk_getnext( $config, $credentials, $oid ) {
		if ( ! class_exists( '\\SNMP' ) ) {
			return new \WP_Error( 'afcn_olt_snmp_walk_failed', __( 'The PHP SNMP session API is not available on this server.', 'airfiber-centralized' ) );
		}

		$timeout_us = min( 10000000, max( 500000, (int) $config['timeout_ms'] * 1000 ) );
		$session    = null;

		try {
			if ( '2c' === $config['version'] ) {
				$session = new \SNMP( \SNMP::VERSION_2c, $config['host'] . ':' . $config['port'], $credentials['community'], $timeout_us, 0 );
			} else {
				$session = new \SNMP( \SNMP::VERSION_3, $config['host'] . ':' . $config['port'], $credentials['security_name'], $timeout_us, 0 );
				$session->setSecurity( 'authPriv', 'SHA', $credentials['auth'], 'DES', $credentials['privacy'] );
			}

			if ( defined( 'SNMP_OID_OUTPUT_NUMERIC' ) ) {
				$session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
			}

			$root                 = trim( preg_replace( '/[^0-9.]/', '', (string) $oid ), '.' );
			$current              = $root;
			$rows                 = array();
			$consecutive_failures = 0;

			for ( $iteration = 0; $iteration < self::GETNEXT_ROW_LIMIT; $iteration++ ) {
				set_error_handler( function () { return true; } );
				try {
					$result = $session->getnext( array( $current ) );
				} catch ( \Throwable $error ) {
					$result = false;
				}
				restore_error_handler();

				if ( ! is_array( $result ) || ! $result ) {
					$consecutive_failures++;
					if ( $consecutive_failures < self::GETNEXT_FAILURE_LIMIT ) {
						continue;
					}
					return new \WP_Error( 'afcn_olt_snmp_getnext_failed', __( 'The OLT stopped responding before the SNMP table was complete.', 'airfiber-centralized' ) );
				}

				$next_oid = trim( preg_replace( '/[^0-9.]/', '', (string) array_key_first( $result ) ), '.' );
				if ( '' === $next_oid || $next_oid === $current ) {
					return new \WP_Error( 'afcn_olt_snmp_getnext_stalled', __( 'The OLT returned an invalid SNMP table cursor.', 'airfiber-centralized' ) );
				}
				if ( 0 !== strpos( $next_oid, $root . '.' ) ) {
					return $rows;
				}

				$rows[ $next_oid ]   = reset( $result );
				$current              = $next_oid;
				$consecutive_failures = 0;
			}

			return new \WP_Error( 'afcn_olt_snmp_getnext_limit', __( 'The SNMP table exceeded the safe polling limit.', 'airfiber-centralized' ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'afcn_olt_snmp_getnext_failed', __( 'The SNMP GETNEXT fallback failed.', 'airfiber-centralized' ) );
		} finally {
			if ( $session instanceof \SNMP ) {
				$session->close();
			}
		}
	}

	private static function target( $config ) {
		return 161 === (int) $config['port'] ? $config['host'] : 'udp:' . $config['host'] . ':' . (int) $config['port'];
	}

	private static function clean_string( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/^(?:STRING|OCTET STRING):\s*/i', '', $value );
		return trim( $value, "\"' \t\r\n" );
	}
}
