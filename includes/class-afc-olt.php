<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only optical monitoring for the primary EPON OLT.
 */
class AFC_OLT {

	const OPTION_KEY           = 'afc_olt_settings';
	const LAST_STATUS_KEY      = 'afc_olt_last_status';
	const LAST_SNAPSHOT_KEY    = 'afc_olt_last_snapshot';
	const SNAPSHOT_TRANSIENT   = 'afc_olt_rx_snapshot';
	const MAC_TRANSIENT        = 'afc_olt_learned_macs';
	const MAC_CACHE_TTL        = 900;
	const RX_POWER_OID         = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7';
	const LEARNED_MAC_OID      = '1.3.6.1.4.1.37950.1.1.5.10.3.2.1.3';
	const LEARNED_MAC_PORT_OID = '1.3.6.1.4.1.37950.1.1.5.10.3.2.1.5';
	const SAVED_SECRET_MASK    = 'airfiber-saved-secret';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_afc_test_olt', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_afc_save_olt_binding', array( __CLASS__, 'ajax_save_binding' ) );
	}

	public static function register_settings() {
		register_setting(
			'afc_olt',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function defaults() {
		return array(
			'enabled'          => 0,
			'name'             => 'Primary EPON OLT',
			'host'             => '10.13.88.5',
			'port'             => 161,
			'version'          => '3',
			'community'        => '',
			'security_name'    => 'airfiber-monitor',
			'auth_protocol'    => 'SHA',
			'auth_passphrase'  => '',
			'privacy_protocol' => 'DES',
			'privacy_passphrase' => '',
			'rx_oid'           => self::RX_POWER_OID,
			'warning_dbm'      => -24,
			'critical_dbm'     => -27,
			'cache_ttl'        => 300,
			'timeout_ms'       => 2500,
			'retries'          => 1,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_settings();
		$host    = isset( $input['host'] ) ? trim( sanitize_text_field( $input['host'] ) ) : '';
		$host    = preg_replace( '#^(?:https?://|udp:)#i', '', $host );
		$host    = preg_replace( '/[^a-z0-9.\-:]/i', '', trim( $host, "/ \t\n\r\0\x0B" ) );
		$port    = isset( $input['port'] ) ? absint( $input['port'] ) : 161;
		$version = isset( $input['version'] ) && '2c' === $input['version'] ? '2c' : '3';
		$rx_oid  = isset( $input['rx_oid'] ) ? trim( sanitize_text_field( $input['rx_oid'] ) ) : self::RX_POWER_OID;
		$rx_oid  = ltrim( preg_replace( '/[^0-9.]/', '', $rx_oid ), '.' );

		if ( $port < 1 || $port > 65535 ) {
			$port = 161;
		}
		if ( ! preg_match( '/^1(?:\.\d+)+$/', $rx_oid ) ) {
			$rx_oid = self::RX_POWER_OID;
		}

		$warning = isset( $input['warning_dbm'] ) ? (float) $input['warning_dbm'] : -24;
		$critical = isset( $input['critical_dbm'] ) ? (float) $input['critical_dbm'] : -27;
		if ( $warning > 0 || $warning < -50 || $critical > 0 || $critical < -50 || $critical >= $warning ) {
			$warning  = -24;
			$critical = -27;
			add_settings_error(
				self::OPTION_KEY,
				'afc_olt_thresholds',
				__( 'Optical thresholds were reset to -24 dBm warning and -27 dBm critical because the submitted values were invalid.', 'airfiber-centralized' ),
				'warning'
			);
		}

		$community = self::preserve_or_encrypt_secret( $input, 'community', $current );
		$auth      = self::preserve_or_encrypt_secret( $input, 'auth_passphrase', $current );
		$privacy   = self::preserve_or_encrypt_secret( $input, 'privacy_passphrase', $current );

		delete_transient( self::SNAPSHOT_TRANSIENT );
		delete_transient( self::MAC_TRANSIENT );

		return array(
			'enabled'            => empty( $input['enabled'] ) ? 0 : 1,
			'name'               => isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : 'Primary EPON OLT',
			'host'               => $host,
			'port'               => $port,
			'version'            => $version,
			'community'          => $community,
			'security_name'      => isset( $input['security_name'] ) ? sanitize_text_field( $input['security_name'] ) : '',
			'auth_protocol'      => 'SHA',
			'auth_passphrase'    => $auth,
			'privacy_protocol'   => 'DES',
			'privacy_passphrase' => $privacy,
			'rx_oid'             => $rx_oid,
			'warning_dbm'        => $warning,
			'critical_dbm'       => $critical,
			'cache_ttl'          => min( 900, max( 60, isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : 300 ) ),
			'timeout_ms'         => min( 10000, max( 500, isset( $input['timeout_ms'] ) ? absint( $input['timeout_ms'] ) : 2500 ) ),
			'retries'            => min( 2, isset( $input['retries'] ) ? absint( $input['retries'] ) : 1 ),
		);
	}

	private static function preserve_or_encrypt_secret( $input, $key, $current ) {
		$value = isset( $input[ $key ] ) ? (string) wp_unslash( $input[ $key ] ) : '';
		if ( '' === $value || self::SAVED_SECRET_MASK === $value ) {
			return isset( $current[ $key ] ) ? $current[ $key ] : '';
		}

		return self::encrypt_secret( $value );
	}

	private static function encryption_key() {
		$source = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $source, true );
	}

	private static function encrypt_secret( $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'afc_olt_openssl_missing',
				__( 'The OLT secret was not saved because OpenSSL is unavailable on this server.', 'airfiber-centralized' )
			);
			return '';
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt(
			$secret,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $cipher ) {
			return '';
		}

		return 'gcm:' . base64_encode( $iv . $tag . $cipher );
	}

	private static function decrypt_secret( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$data = base64_decode( substr( $stored, 4 ), true );
		if ( false === $data || strlen( $data ) < 29 ) {
			return '';
		}

		return (string) openssl_decrypt(
			substr( $data, 28 ),
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			substr( $data, 0, 12 ),
			substr( $data, 12, 16 )
		);
	}

	public static function has_saved_secret( $key ) {
		$settings = self::get_settings();
		return ! empty( $settings[ $key ] );
	}

	public static function is_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	public static function is_snmp_available( $version = '' ) {
		if ( '' === $version ) {
			$settings = self::get_settings();
			$version  = $settings['version'];
		}

		return '2c' === $version ? function_exists( 'snmp2_real_walk' ) : function_exists( 'snmp3_real_walk' );
	}

	public static function get_snapshot( $force = false ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'afc_olt_disabled', __( 'OLT monitoring is not enabled.', 'airfiber-centralized' ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::SNAPSHOT_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
				$cached['source'] = 'cache';
				return $cached;
			}
		}

		$snapshot = self::poll_rx_power();
		if ( ! is_wp_error( $snapshot ) ) {
			$settings = self::get_settings();
			set_transient( self::SNAPSHOT_TRANSIENT, $snapshot, (int) $settings['cache_ttl'] );
			update_option( self::LAST_SNAPSHOT_KEY, $snapshot, false );
			return $snapshot;
		}

		$last = get_option( self::LAST_SNAPSHOT_KEY, array() );
		if ( is_array( $last ) && ! empty( $last['entries'] ) ) {
			$last['source'] = 'stale';
			$last['stale']  = true;
			$last['error']  = $snapshot->get_error_message();
			return $last;
		}

		return $snapshot;
	}

	private static function poll_rx_power() {
		$settings = self::get_settings();

		if ( ! self::is_snmp_available( $settings['version'] ) ) {
			return new WP_Error(
				'afc_olt_snmp_extension_missing',
				__( 'The PHP SNMP extension is not installed on the WordPress server.', 'airfiber-centralized' )
			);
		}
		if ( empty( $settings['host'] ) ) {
			return new WP_Error( 'afc_olt_host_missing', __( 'Enter the OLT IP address first.', 'airfiber-centralized' ) );
		}

		$oid  = ltrim( $settings['rx_oid'], '.' );
		$walk = self::walk_oid( $settings, $oid );
		if ( is_wp_error( $walk ) ) {
			if ( in_array( $walk->get_error_code(), array( 'afc_olt_community_missing', 'afc_olt_v3_credentials_missing' ), true ) ) {
				return $walk;
			}
			return new WP_Error( 'afc_olt_walk_failed', __( 'The OLT did not return an SNMP RX-power table. Check routing, UDP/161, credentials, and the configured OID.', 'airfiber-centralized' ) );
		}

		$entries = array();
		foreach ( $walk as $instance_oid => $raw_value ) {
			$indexes = self::extract_indexes( $instance_oid, $oid );
			$power   = self::parse_rx_power( $raw_value );
			/* V1600D firmware commonly returns 0 as an empty/offline placeholder. */
			if ( ! $indexes || null === $power || abs( $power ) < 0.005 ) {
				continue;
			}

			$key             = $indexes['pon'] . ':' . $indexes['onu'];
			$entries[ $key ] = array(
				'pon'      => $indexes['pon'],
				'onu'      => $indexes['onu'],
				'rx_power' => round( $power, 2 ),
				'status'   => self::classify_power( $power, $settings ),
			);
		}

		if ( empty( $entries ) ) {
			return new WP_Error( 'afc_olt_empty_walk', __( 'SNMP responded, but no usable non-zero RX-power readings were found under the configured OID.', 'airfiber-centralized' ) );
		}

		$learned_macs = self::get_learned_macs( $settings );

		return array(
			'entries'      => $entries,
			'learned_macs' => $learned_macs,
			'count'        => count( $entries ),
			'collected_at' => current_time( 'mysql' ),
			'collected_ts' => time(),
			'source'       => 'live',
			'stale'        => false,
			'error'        => '',
		);
	}

	private static function get_learned_macs( $settings ) {
		$cached = get_transient( self::MAC_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$last = get_option( self::LAST_SNAPSHOT_KEY, array() );
		if (
			is_array( $last ) &&
			! empty( $last['learned_macs'] ) &&
			! empty( $last['collected_ts'] ) &&
			time() - (int) $last['collected_ts'] < 3600
		) {
			set_transient( self::MAC_TRANSIENT, $last['learned_macs'], self::MAC_CACHE_TTL );
			return $last['learned_macs'];
		}

		$learned_macs = array();
		$mac_walk     = self::walk_oid( $settings, self::LEARNED_MAC_OID );
		$port_walk    = self::walk_oid( $settings, self::LEARNED_MAC_PORT_OID );
		if ( ! is_wp_error( $mac_walk ) && ! is_wp_error( $port_walk ) ) {
			$ports_by_row = array();
			foreach ( $port_walk as $instance_oid => $raw_value ) {
				$row = self::extract_row_index( $instance_oid );
				if ( '' !== $row ) {
					$ports_by_row[ $row ] = $raw_value;
				}
			}

			foreach ( $mac_walk as $instance_oid => $raw_value ) {
				$row     = self::extract_row_index( $instance_oid );
				$indexes = isset( $ports_by_row[ $row ] ) ? self::parse_onu_port( $ports_by_row[ $row ] ) : null;
				$mac     = self::normalize_mac( $raw_value );
				if ( ! $indexes || ! $mac ) {
					continue;
				}
				$location = array( 'pon' => $indexes['pon'], 'onu' => $indexes['onu'] );
				if ( ! isset( $learned_macs[ $mac ] ) ) {
					$learned_macs[ $mac ] = array();
				}
				if ( ! in_array( $location, $learned_macs[ $mac ], true ) ) {
					$learned_macs[ $mac ][] = $location;
				}
			}
		}

		set_transient( self::MAC_TRANSIENT, $learned_macs, self::MAC_CACHE_TTL );
		return $learned_macs;
	}

	private static function walk_oid( $settings, $oid ) {
		$target  = 161 === (int) $settings['port'] ? $settings['host'] : 'udp:' . $settings['host'] . ':' . (int) $settings['port'];
		$timeout = (int) $settings['timeout_ms'] * 1000;
		$retries = (int) $settings['retries'];

		if ( '2c' === $settings['version'] ) {
			$community = self::decrypt_secret( $settings['community'] );
			if ( '' === $community ) {
				return new WP_Error( 'afc_olt_community_missing', __( 'Save the read-only SNMP community first.', 'airfiber-centralized' ) );
			}
			$walk = @snmp2_real_walk( $target, $community, $oid, $timeout, $retries );
		} else {
			$auth_passphrase    = self::decrypt_secret( $settings['auth_passphrase'] );
			$privacy_passphrase = self::decrypt_secret( $settings['privacy_passphrase'] );
			if ( empty( $settings['security_name'] ) || '' === $auth_passphrase || '' === $privacy_passphrase ) {
				return new WP_Error( 'afc_olt_v3_credentials_missing', __( 'Save the SNMPv3 username, authentication passphrase, and privacy passphrase first.', 'airfiber-centralized' ) );
			}
			$walk = @snmp3_real_walk(
				$target,
				$settings['security_name'],
				'authPriv',
				'SHA',
				$auth_passphrase,
				'DES',
				$privacy_passphrase,
				$oid,
				$timeout,
				$retries
			);
		}

		return false === $walk || ! is_array( $walk )
			? new WP_Error( 'afc_olt_snmp_walk_failed', __( 'The SNMP walk failed.', 'airfiber-centralized' ) )
			: $walk;
	}

	private static function extract_indexes( $instance_oid, $base_oid ) {
		$numeric = preg_replace( '/[^0-9.]/', '', (string) $instance_oid );
		$base    = trim( $base_oid, '.' );
		$suffix  = '';

		if ( preg_match( '/(?:^|\.)' . preg_quote( $base, '/' ) . '\.(\d+)\.(\d+)$/', $numeric, $matches ) ) {
			$suffix = $matches;
		} elseif ( preg_match( '/\.(\d+)\.(\d+)$/', $numeric, $matches ) ) {
			$suffix = $matches;
		}

		if ( ! $suffix ) {
			return null;
		}

		return array( 'pon' => absint( $suffix[1] ), 'onu' => absint( $suffix[2] ) );
	}

	private static function extract_row_index( $instance_oid ) {
		$numeric = preg_replace( '/[^0-9.]/', '', (string) $instance_oid );
		if ( ! preg_match( '/\.(\d+)$/', $numeric, $matches ) ) {
			return '';
		}

		return (string) absint( $matches[1] );
	}

	private static function parse_onu_port( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(?:HEX-STRING|STRING|OCTET STRING):\s*(.+)$/i', $value, $matches ) ) {
			$value = $matches[1];
		}
		$value = trim( $value, "\"' " );

		if ( preg_match( '/E?PON\s*\d*\/(\d+)\s*:\s*(\d+)/i', $value, $matches ) ) {
			return array( 'pon' => absint( $matches[1] ), 'onu' => absint( $matches[2] ) );
		}
		if ( preg_match( '/PON\s*(\d+)\D+ONU\s*(\d+)/i', $value, $matches ) ) {
			return array( 'pon' => absint( $matches[1] ), 'onu' => absint( $matches[2] ) );
		}

		return null;
	}

	private static function normalize_mac( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(?:HEX-STRING|STRING|OCTET STRING):\s*(.+)$/i', $value, $matches ) ) {
			$value = $matches[1];
		}
		$value = preg_replace( '/^0x/i', '', trim( $value, "\"' " ) );
		$hex = strtoupper( preg_replace( '/[^a-f0-9]/i', '', $value ) );

		return 12 === strlen( $hex ) ? implode( ':', str_split( $hex, 2 ) ) : '';
	}

	public static function suggest_binding( $caller_id, $snapshot ) {
		if ( is_wp_error( $snapshot ) || empty( $snapshot['learned_macs'] ) ) {
			return null;
		}
		$mac = self::normalize_mac( $caller_id );
		if ( ! $mac || empty( $snapshot['learned_macs'][ $mac ] ) || 1 !== count( $snapshot['learned_macs'][ $mac ] ) ) {
			return null;
		}

		return $snapshot['learned_macs'][ $mac ][0];
	}

	private static function parse_rx_power( $raw_value ) {
		$value = trim( (string) $raw_value );
		if ( '' === $value || false !== stripos( $value, 'no such' ) ) {
			return null;
		}
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $value, $matches ) ) {
			return null;
		}

		$power = (float) $matches[0];
		if ( abs( $power ) > 100 && abs( $power ) <= 10000 ) {
			$power /= 100;
		}

		return $power <= 5 && $power >= -60 ? $power : null;
	}

	private static function classify_power( $power, $settings ) {
		if ( $power <= (float) $settings['critical_dbm'] ) {
			return 'critical';
		}
		if ( $power <= (float) $settings['warning_dbm'] ) {
			return 'warning';
		}
		return 'good';
	}

	public static function get_customer_signal( $customer_id, $snapshot ) {
		$customer_id = absint( $customer_id );
		$pon         = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
		$onu         = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
		$onu_mac     = (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true );

		$result = array(
			'mapped'       => $pon > 0 && $onu > 0,
			'pon'          => $pon,
			'onu'          => $onu,
			'onu_mac'      => $onu_mac,
			'rx_power'     => null,
			'status'       => 'unmapped',
			'collected_at' => '',
			'stale'        => false,
			'message'      => '',
		);

		if ( ! $result['mapped'] ) {
			return $result;
		}
		if ( is_wp_error( $snapshot ) ) {
			$result['status']  = 'unavailable';
			$result['message'] = $snapshot->get_error_message();
			return $result;
		}

		$result['collected_at'] = isset( $snapshot['collected_at'] ) ? $snapshot['collected_at'] : '';
		$result['stale']        = ! empty( $snapshot['stale'] );
		$result['message']      = isset( $snapshot['error'] ) ? $snapshot['error'] : '';
		$key                    = $pon . ':' . $onu;
		if ( empty( $snapshot['entries'][ $key ] ) ) {
			$result['status'] = $result['stale'] ? 'stale' : 'offline';
			return $result;
		}

		$entry              = $snapshot['entries'][ $key ];
		$result['rx_power'] = $entry['rx_power'];
		$result['status']   = $result['stale'] ? 'stale' : $entry['status'];
		return $result;
	}

	public static function snapshot_summary( $snapshot ) {
		if ( is_wp_error( $snapshot ) ) {
			return array(
				'enabled'      => self::is_enabled(),
				'available'    => false,
				'stale'        => false,
				'count'        => 0,
				'collected_at' => '',
				'message'      => $snapshot->get_error_message(),
			);
		}

		return array(
			'enabled'      => true,
			'available'    => true,
			'stale'        => ! empty( $snapshot['stale'] ),
			'count'        => isset( $snapshot['count'] ) ? (int) $snapshot['count'] : count( $snapshot['entries'] ),
			'collected_at' => isset( $snapshot['collected_at'] ) ? $snapshot['collected_at'] : '',
			'message'      => isset( $snapshot['error'] ) ? $snapshot['error'] : '',
		);
	}

	public static function test_connection() {
		$result = self::poll_rx_power();
		if ( ! is_wp_error( $result ) ) {
			$settings = self::get_settings();
			set_transient( self::SNAPSHOT_TRANSIENT, $result, (int) $settings['cache_ttl'] );
			update_option( self::LAST_SNAPSHOT_KEY, $result, false );
		}
		return $result;
	}

	private static function authorize_admin_ajax( $nonce_action, $nonce_field = 'nonce' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage OLT monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( $nonce_action, $nonce_field );
	}

	public static function ajax_test_connection() {
		self::authorize_admin_ajax( 'afc_test_olt_ajax' );
		$result = self::test_connection();

		if ( is_wp_error( $result ) ) {
			$status = array(
				'status'  => 'error',
				'message' => $result->get_error_message(),
				'time'    => current_time( 'mysql' ),
			);
			update_option( self::LAST_STATUS_KEY, $status, false );
			wp_send_json_error( $status );
		}

		$status = array(
			'status'  => 'success',
			'message' => sprintf( __( 'Connected successfully and received %d ONU RX-power reading(s).', 'airfiber-centralized' ), (int) $result['count'] ),
			'count'   => (int) $result['count'],
			'time'    => current_time( 'mysql' ),
		);
		update_option( self::LAST_STATUS_KEY, $status, false );
		wp_send_json_success( $status );
	}

	public static function ajax_save_binding() {
		self::authorize_admin_ajax( 'afc_ppp_users' );

		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$clear       = ! empty( $_POST['clear'] );
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The customer record is invalid.', 'airfiber-centralized' ) ) );
		}

		if ( $clear ) {
			delete_post_meta( $customer_id, '_afc_olt_id' );
			delete_post_meta( $customer_id, '_afc_olt_pon' );
			delete_post_meta( $customer_id, '_afc_olt_onu' );
			delete_post_meta( $customer_id, '_afc_olt_onu_mac' );
			wp_send_json_success( array( 'message' => __( 'The ONU mapping was removed.', 'airfiber-centralized' ) ) );
		}

		$pon = isset( $_POST['pon'] ) ? absint( $_POST['pon'] ) : 0;
		$onu = isset( $_POST['onu'] ) ? absint( $_POST['onu'] ) : 0;
		if ( $pon < 1 || $pon > 16 || $onu < 1 || $onu > 256 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid PON number (1-16) and ONU ID (1-256).', 'airfiber-centralized' ) ) );
		}

		$conflicts = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => array( $customer_id ),
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_afc_olt_pon', 'value' => $pon ),
					array( 'key' => '_afc_olt_onu', 'value' => $onu ),
				),
			)
		);
		if ( $conflicts ) {
			wp_send_json_error( array( 'message' => __( 'That PON/ONU is already assigned to another customer.', 'airfiber-centralized' ) ) );
		}

		$onu_mac = isset( $_POST['onu_mac'] ) ? strtoupper( preg_replace( '/[^a-f0-9]/i', '', wp_unslash( $_POST['onu_mac'] ) ) ) : '';
		if ( '' !== $onu_mac && 12 !== strlen( $onu_mac ) ) {
			wp_send_json_error( array( 'message' => __( 'The optional ONU MAC address must contain exactly 12 hexadecimal characters.', 'airfiber-centralized' ) ) );
		}
		if ( $onu_mac ) {
			$onu_mac = implode( ':', str_split( $onu_mac, 2 ) );
		}

		update_post_meta( $customer_id, '_afc_olt_id', 'primary' );
		update_post_meta( $customer_id, '_afc_olt_pon', $pon );
		update_post_meta( $customer_id, '_afc_olt_onu', $onu );
		update_post_meta( $customer_id, '_afc_olt_onu_mac', $onu_mac );

		wp_send_json_success( array( 'message' => sprintf( __( 'Customer mapped to PON %1$d / ONU %2$d.', 'airfiber-centralized' ), $pon, $onu ) ) );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'airfiber-centralized' ) );
		}

		$settings    = self::get_settings();
		$last_status = get_option( self::LAST_STATUS_KEY, array() );
		include AFC_PATH . 'templates/admin/olt-settings.php';
	}
}
