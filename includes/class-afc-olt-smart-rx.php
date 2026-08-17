<?php

defined( 'ABSPATH' ) || exit;

/**
 * Smarter OLT connection test with automatic VSOL RX OID discovery.
 *
 * This takes over the frontend OLT manager test AJAX action at priority 1,
 * before the legacy AFC_OLT_Manager callback runs. It keeps the existing OLT
 * record format, but can probe known GPON/EPON optical tables and save the
 * working RX OID automatically.
 */
class AFC_OLT_Smart_RX {

	const GPON_RX_OID        = '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7';
	const GPON_OPM_ENTRY_OID = '1.3.6.1.4.1.37950.1.1.6.1.1.3.1';
	const EPON_RX_OID        = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7';
	const EPON_OPM_ENTRY_OID = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1';
	const SYS_NAME_OID       = '1.3.6.1.2.1.1.5.0';
	const SYS_DESCR_OID      = '1.3.6.1.2.1.1.1.0';

	private static $started_at = 0.0;
	private static $diagnostics = array();

	public static function init() {
		add_action( 'wp_ajax_afc_olt_manager_test', array( __CLASS__, 'ajax_test' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1047 );
	}

	public static function enqueue_assets() {
		if (
			is_admin() ||
			! current_user_can( 'manage_options' ) ||
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request()
		) {
			return;
		}

		$path = AFC_PATH . 'assets/js/olt-smart-rx.js';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : AFC_VERSION;

		wp_enqueue_script(
			'afc-olt-smart-rx',
			AFC_URL . 'assets/js/olt-smart-rx.js',
			array( 'jquery', 'afc-olt-manager' ),
			$ver,
			true
		);
		wp_localize_script(
			'afc-olt-smart-rx',
			'afcOLTSmartRX',
			array(
				'defaultRxOid' => self::GPON_RX_OID,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to test OLT connections.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( AFC_OLT_Manager::NONCE, 'nonce' );
	}

	private static function log( $message, $tone = 'neutral' ) {
		$elapsed = self::$started_at > 0 ? (int) round( ( microtime( true ) - self::$started_at ) * 1000 ) : 0;
		self::$diagnostics[] = array(
			'elapsed_ms' => $elapsed,
			'message'    => sanitize_text_field( $message ),
			'tone'       => in_array( $tone, array( 'neutral', 'success', 'warning', 'error' ), true ) ? $tone : 'neutral',
		);
	}

	private static function config_defaults() {
		return array(
			'host'               => '',
			'port'               => 161,
			'version'            => '3',
			'community'          => '',
			'security_name'      => '',
			'auth_passphrase'    => '',
			'privacy_passphrase' => '',
			'rx_oid'             => self::GPON_RX_OID,
			'timeout_ms'         => 2500,
			'retries'            => 1,
			'warning_dbm'        => -24,
			'critical_dbm'       => -27,
			'cache_ttl'          => 300,
			'technology'         => 'GPON',
		);
	}

	private static function get_config( $post_id ) {
		$config = get_post_meta( $post_id, AFC_OLT_Manager::CONFIG_META, true );
		return wp_parse_args( is_array( $config ) ? $config : array(), self::config_defaults() );
	}

	private static function get_device( $post_id ) {
		$device = get_post_meta( $post_id, AFC_OLT_Manager::DEVICE_META, true );
		return wp_parse_args(
			is_array( $device ) ? $device : array(),
			array(
				'name'        => '',
				'description' => '',
				'test_status' => '',
				'message'     => '',
				'tested_at'   => '',
				'onu_count'   => 0,
				'valid_count' => 0,
			)
		);
	}

	private static function encryption_key() {
		$source = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $source, true );
	}

	private static function decrypt_secret( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) return '';
		$data = base64_decode( substr( $stored, 4 ), true );
		if ( false === $data || strlen( $data ) < 29 ) return '';
		return (string) openssl_decrypt(
			substr( $data, 28 ),
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			substr( $data, 0, 12 ),
			substr( $data, 12, 16 )
		);
	}

	private static function snmp_target( $config ) {
		return 161 === (int) $config['port'] ? $config['host'] : 'udp:' . $config['host'] . ':' . (int) $config['port'];
	}

	private static function clean_warning( $warning ) {
		$warning = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $warning ) );
		$warning = trim( $warning );
		return strlen( $warning ) > 240 ? substr( $warning, 0, 237 ) . '...' : $warning;
	}

	private static function friendly_warning( $warning ) {
		$warning = strtolower( (string) $warning );
		if ( '' === trim( $warning ) ) return '';
		if ( false !== strpos( $warning, 'unknown user' ) || false !== strpos( $warning, 'unknownusername' ) ) {
			return __( 'The OLT does not recognize this SNMPv3 monitoring username. Check the User Table entry and spelling.', 'airfiber-centralized' );
		}
		if ( false !== strpos( $warning, 'authentication failure' ) || false !== strpos( $warning, 'wrong digest' ) || false !== strpos( $warning, 'authorization error' ) ) {
			return __( 'The OLT rejected SNMPv3 authentication. Recheck the monitoring username and Login password (HMAC-SHA).', 'airfiber-centralized' );
		}
		if ( false !== strpos( $warning, 'decryption' ) || false !== strpos( $warning, 'privacy' ) || false !== strpos( $warning, 'decrypt' ) ) {
			return __( 'SNMPv3 privacy/encryption failed. Recheck the Encryption password and CBC-DES setting.', 'airfiber-centralized' );
		}
		if ( false !== strpos( $warning, 'no access' ) || false !== strpos( $warning, 'not in view' ) || false !== strpos( $warning, 'no such object' ) ) {
			return __( 'The SNMP login reached the OLT, but its read view may not permit this OID.', 'airfiber-centralized' );
		}
		if ( false !== strpos( $warning, 'timeout' ) || false !== strpos( $warning, 'no response' ) ) {
			return __( 'No SNMP reply arrived. Check UDP 161 and any Remote Server/manager IP restriction on the OLT.', 'airfiber-centralized' );
		}
		return '';
	}

	private static function capture_snmp_call( $callback, &$warning ) {
		$warning = '';
		set_error_handler(
			function ( $severity, $message ) use ( &$warning ) {
				$warning = AFC_OLT_Smart_RX::clean_warning( $message );
				return true;
			}
		);

		try {
			$result = call_user_func( $callback );
		} catch ( Throwable $error ) {
			$warning = self::clean_warning( $error->getMessage() );
			$result  = false;
		}

		restore_error_handler();
		return $result;
	}

	private static function snmp_get( $config, $oid ) {
		$target  = self::snmp_target( $config );
		$timeout = max( 500, (int) $config['timeout_ms'] ) * 1000;
		$retries = max( 0, (int) $config['retries'] );
		$warning = '';

		if ( '2c' === $config['version'] ) {
			if ( ! function_exists( 'snmp2_get' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv2 support is not available on this server.', 'airfiber-centralized' ) );
			$community = self::decrypt_secret( $config['community'] );
			if ( '' === $community ) return new WP_Error( 'community_missing', __( 'Save the read-only SNMP community first.', 'airfiber-centralized' ) );
			$value = self::capture_snmp_call(
				function () use ( $target, $community, $oid, $timeout, $retries ) {
					return snmp2_get( $target, $community, $oid, $timeout, $retries );
				},
				$warning
			);
		} else {
			if ( ! function_exists( 'snmp3_get' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv3 support is not available on this server.', 'airfiber-centralized' ) );
			$auth    = self::decrypt_secret( $config['auth_passphrase'] );
			$privacy = self::decrypt_secret( $config['privacy_passphrase'] );
			if ( '' === $config['security_name'] || '' === $auth || '' === $privacy ) return new WP_Error( 'credentials_missing', __( 'Save the SNMPv3 monitoring username, login password and encryption password first.', 'airfiber-centralized' ) );
			$value = self::capture_snmp_call(
				function () use ( $target, $config, $auth, $privacy, $oid, $timeout, $retries ) {
					return snmp3_get( $target, $config['security_name'], 'authPriv', 'SHA', $auth, 'DES', $privacy, $oid, $timeout, $retries );
				},
				$warning
			);
		}

		if ( false === $value ) {
			return new WP_Error(
				'snmp_get_failed',
				__( 'The OLT did not return the requested SNMP identity value.', 'airfiber-centralized' ),
				array( 'warning' => $warning )
			);
		}

		return $value;
	}

	private static function snmp_walk( $config, $oid ) {
		$target  = self::snmp_target( $config );
		$timeout = max( 500, (int) $config['timeout_ms'] ) * 1000;
		$retries = max( 0, (int) $config['retries'] );
		$warning = '';
		$oid     = ltrim( $oid, '.' );

		if ( function_exists( 'snmp_set_oid_output_format' ) && defined( 'SNMP_OID_OUTPUT_NUMERIC' ) ) {
			snmp_set_oid_output_format( SNMP_OID_OUTPUT_NUMERIC );
		}

		if ( '2c' === $config['version'] ) {
			if ( ! function_exists( 'snmp2_real_walk' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv2 support is not available on this server.', 'airfiber-centralized' ) );
			$community = self::decrypt_secret( $config['community'] );
			if ( '' === $community ) return new WP_Error( 'community_missing', __( 'Save the read-only SNMP community first.', 'airfiber-centralized' ) );
			$rows = self::capture_snmp_call(
				function () use ( $target, $community, $oid, $timeout, $retries ) {
					return snmp2_real_walk( $target, $community, $oid, $timeout, $retries );
				},
				$warning
			);
		} else {
			if ( ! function_exists( 'snmp3_real_walk' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv3 support is not available on this server.', 'airfiber-centralized' ) );
			$auth    = self::decrypt_secret( $config['auth_passphrase'] );
			$privacy = self::decrypt_secret( $config['privacy_passphrase'] );
			if ( '' === $config['security_name'] || '' === $auth || '' === $privacy ) return new WP_Error( 'credentials_missing', __( 'Save the SNMPv3 monitoring username, login password and encryption password first.', 'airfiber-centralized' ) );
			$rows = self::capture_snmp_call(
				function () use ( $target, $config, $auth, $privacy, $oid, $timeout, $retries ) {
					return snmp3_real_walk( $target, $config['security_name'], 'authPriv', 'SHA', $auth, 'DES', $privacy, $oid, $timeout, $retries );
				},
				$warning
			);
		}

		if ( false === $rows || ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error(
				'snmp_walk_failed',
				__( 'No rows were returned from this SNMP table.', 'airfiber-centralized' ),
				array( 'warning' => $warning )
			);
		}

		return $rows;
	}

	private static function clean_snmp_string( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/^(?:STRING|OCTET STRING):\s*/i', '', $value );
		return trim( $value, "\"' \t\r\n" );
	}

	private static function numeric_power( $value ) {
		$text = trim( (string) $value );
		if ( preg_match( '/(?:no such|not available|unknown|invalid|inf)/i', $text ) ) return null;
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $text, $matches ) ) return null;

		$number = (float) $matches[0];
		if ( $number <= -500 && $number >= -6000 ) $number /= 100;
		elseif ( $number < -50 && $number > -500 ) $number /= 10;

		return $number;
	}

	private static function analyse_rx_rows( $rows ) {
		$total     = is_array( $rows ) ? count( $rows ) : 0;
		$plausible = 0;
		$dbm       = 0;

		foreach ( (array) $rows as $value ) {
			$text = (string) $value;
			if ( false !== stripos( $text, 'dbm' ) ) $dbm++;
			$power = self::numeric_power( $text );
			if ( null !== $power && $power >= -50 && $power <= -5 ) $plausible++;
		}

		return array(
			'total'     => $total,
			'plausible' => $plausible,
			'dbm'       => $dbm,
			'valid'     => $total > 0 && $plausible > 0,
		);
	}

	private static function probe_oid( $config, $oid, $label ) {
		self::log( sprintf( __( 'Testing %1$s RX table %2$s.', 'airfiber-centralized' ), $label, $oid ) );
		$walk = self::snmp_walk( $config, $oid );
		if ( is_wp_error( $walk ) ) {
			$data = $walk->get_error_data();
			self::log( sprintf( __( '%s RX table returned no usable rows.', 'airfiber-centralized' ), $label ), 'warning' );
			if ( is_array( $data ) && ! empty( $data['warning'] ) ) {
				self::log( 'SNMP: ' . $data['warning'], 'warning' );
				$friendly = self::friendly_warning( $data['warning'] );
				if ( $friendly ) self::log( $friendly, 'warning' );
			}
			return null;
		}

		$analysis = self::analyse_rx_rows( $walk );
		if ( ! $analysis['valid'] ) {
			self::log(
				sprintf(
					__( '%1$s returned %2$d row(s), but none looked like ONU RX power.', 'airfiber-centralized' ),
					$label,
					$analysis['total']
				),
				'warning'
			);
			return null;
		}

		self::log(
			sprintf(
				__( '%1$s RX table matched: %2$d row(s), %3$d plausible RX value(s).', 'airfiber-centralized' ),
				$label,
				$analysis['total'],
				$analysis['plausible']
			),
			'success'
		);

		return array(
			'oid'      => $oid,
			'rows'     => $walk,
			'analysis' => $analysis,
			'label'    => $label,
		);
	}

	private static function discover_column( $config, $parent_oid, $label ) {
		self::log( sprintf( __( 'Scanning the %s optical diagnostics table for an RX-like column.', 'airfiber-centralized' ), $label ) );
		$walk = self::snmp_walk( $config, $parent_oid );
		if ( is_wp_error( $walk ) ) {
			$data = $walk->get_error_data();
			self::log( sprintf( __( 'Could not scan the %s optical diagnostics table.', 'airfiber-centralized' ), $label ), 'warning' );
			if ( is_array( $data ) && ! empty( $data['warning'] ) ) {
				self::log( 'SNMP: ' . $data['warning'], 'warning' );
				$friendly = self::friendly_warning( $data['warning'] );
				if ( $friendly ) self::log( $friendly, 'warning' );
			}
			return null;
		}

		$groups = array();
		$prefix = trim( $parent_oid, '.' ) . '.';
		foreach ( $walk as $instance_oid => $value ) {
			$numeric_oid = trim( preg_replace( '/[^0-9.]/', '', (string) $instance_oid ), '.' );
			$position    = strpos( $numeric_oid, $prefix );
			if ( false === $position ) continue;
			$suffix = substr( $numeric_oid, $position + strlen( $prefix ) );
			$parts  = array_values( array_filter( explode( '.', $suffix ), 'strlen' ) );
			if ( count( $parts ) < 2 || ! ctype_digit( (string) $parts[0] ) ) continue;
			$column = (string) $parts[0];
			if ( ! isset( $groups[ $column ] ) ) $groups[ $column ] = array();
			$groups[ $column ][] = $value;
		}

		$best = null;
		foreach ( $groups as $column => $values ) {
			$analysis = self::analyse_rx_rows( $values );
			if ( ! $analysis['valid'] ) continue;
			$score = ( $analysis['plausible'] * 10 ) + ( $analysis['dbm'] * 3 );
			if ( null === $best || $score > $best['score'] ) {
				$best = array(
					'oid'      => $parent_oid . '.' . $column,
					'analysis' => $analysis,
					'score'    => $score,
				);
			}
		}

		if ( ! $best ) {
			self::log( sprintf( __( 'The %s diagnostics table was readable, but no RX-like column was found.', 'airfiber-centralized' ), $label ), 'warning' );
			return null;
		}

		self::log( sprintf( __( 'Possible RX column discovered at %s. Validating it now.', 'airfiber-centralized' ), $best['oid'] ), 'success' );
		return self::probe_oid( $config, $best['oid'], $label . ' discovered' );
	}

	private static function device_family( $name, $description ) {
		$text = strtolower( trim( $name . ' ' . $description ) );
		if ( false !== strpos( $text, 'v1600g' ) || false !== strpos( $text, 'gpon' ) ) return 'VSOL GPON';
		if ( false !== strpos( $text, 'v1600d' ) || false !== strpos( $text, 'epon' ) ) return 'VSOL EPON';
		if ( false !== strpos( $text, 'vsol' ) ) return 'VSOL';
		return 'OLT';
	}

	private static function detect_rx( $config, $name, $description ) {
		$probe_config               = $config;
		$probe_config['timeout_ms'] = min( 1300, max( 500, (int) $config['timeout_ms'] ) );
		$probe_config['retries']    = 0;
		$family                     = self::device_family( $name, $description );

		$candidates = array();
		if ( 'VSOL EPON' === $family ) {
			$candidates[ self::EPON_RX_OID ] = 'VSOL EPON';
			$candidates[ self::GPON_RX_OID ] = 'VSOL GPON';
		} else {
			$candidates[ self::GPON_RX_OID ] = 'VSOL GPON';
			$candidates[ self::EPON_RX_OID ] = 'VSOL EPON';
		}
		if ( ! empty( $config['rx_oid'] ) && ! isset( $candidates[ $config['rx_oid'] ] ) ) {
			$candidates[ $config['rx_oid'] ] = 'configured';
		}

		foreach ( $candidates as $oid => $label ) {
			$match = self::probe_oid( $probe_config, $oid, $label );
			if ( $match ) return $match;
		}

		self::log( __( 'Known RX OIDs did not match. Starting targeted optical-table discovery.', 'airfiber-centralized' ), 'warning' );
		$parents = array(
			self::GPON_OPM_ENTRY_OID => 'VSOL GPON',
			self::EPON_OPM_ENTRY_OID => 'VSOL EPON',
		);
		foreach ( $parents as $parent => $label ) {
			$match = self::discover_column( $probe_config, $parent, $label );
			if ( $match ) return $match;
		}

		return null;
	}

	private static function sync_primary_legacy( $post_id, $config ) {
		if ( ! class_exists( 'AFC_OLT' ) || ! get_post_meta( $post_id, AFC_OLT_Manager::PRIMARY_META, true ) ) return;
		$post = get_post( $post_id );
		if ( ! $post ) return;

		$config['enabled'] = 'publish' === $post->post_status && ! get_post_meta( $post_id, AFC_OLT_Manager::DISCONNECTED_META, true ) ? 1 : 0;
		$config['name']    = $post->post_title;
		unset( $config['technology'] );
		update_option( AFC_OLT::OPTION_KEY, $config, false );

		if ( defined( 'AFC_OLT::SNAPSHOT_TRANSIENT' ) ) delete_transient( AFC_OLT::SNAPSHOT_TRANSIENT );
		if ( defined( 'AFC_OLT::MAC_TRANSIENT' ) ) delete_transient( AFC_OLT::MAC_TRANSIENT );
		if ( defined( 'AFC_OLT::POLL_LOCK_TRANSIENT' ) ) delete_transient( AFC_OLT::POLL_LOCK_TRANSIENT );
	}

	private static function state_after_test( $post_id, $success ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) return 'draft';
		if ( get_post_meta( $post_id, AFC_OLT_Manager::DISCONNECTED_META, true ) ) return 'offline';
		return $success ? 'online' : 'error';
	}

	private static function error_response( $post_id, $error, $device = null ) {
		$device = is_array( $device ) ? $device : self::get_device( $post_id );
		$device['test_status'] = 'error';
		$device['tested_at']   = current_time( 'mysql' );
		$device['message']     = is_wp_error( $error ) ? $error->get_error_message() : __( 'Connection test failed.', 'airfiber-centralized' );
		update_post_meta( $post_id, AFC_OLT_Manager::DEVICE_META, $device );

		wp_send_json_error(
			array(
				'message'     => $device['message'],
				'error_code'  => is_wp_error( $error ) ? $error->get_error_code() : 'connection_failed',
				'device'      => $device,
				'state'       => self::state_after_test( $post_id, false ),
				'diagnostics' => self::$diagnostics,
			)
		);
	}

	public static function ajax_test() {
		self::authorize();
		self::$started_at  = microtime( true );
		self::$diagnostics = array();

		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $post_id || AFC_OLT_Manager::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Save the OLT draft before testing.', 'airfiber-centralized' ) ), 400 );
		}

		$config = self::get_config( $post_id );
		if ( empty( $config['host'] ) ) {
			self::log( __( 'No OLT IP address is saved.', 'airfiber-centralized' ), 'error' );
			self::error_response( $post_id, new WP_Error( 'host_missing', __( 'Enter the OLT IP address or hostname first.', 'airfiber-centralized' ) ) );
		}

		$version = '2c' === $config['version'] ? 'SNMPv2c' : 'SNMPv3';
		self::log( sprintf( __( 'Connecting to %1$s:%2$d using %3$s.', 'airfiber-centralized' ), $config['host'], (int) $config['port'], $version ) );

		$name_result        = self::snmp_get( $config, self::SYS_NAME_OID );
		$description_result = self::snmp_get( $config, self::SYS_DESCR_OID );
		$identity_failed    = is_wp_error( $name_result ) && is_wp_error( $description_result );
		$identity_warning   = '';

		if ( $identity_failed ) {
			$name_data = $name_result->get_error_data();
			$desc_data = $description_result->get_error_data();
			if ( is_array( $name_data ) && ! empty( $name_data['warning'] ) ) $identity_warning = $name_data['warning'];
			elseif ( is_array( $desc_data ) && ! empty( $desc_data['warning'] ) ) $identity_warning = $desc_data['warning'];

			self::log( __( 'Standard OLT identity OIDs are not readable. Airfiber will not stop here; it will now probe the VSOL vendor RX tables directly.', 'airfiber-centralized' ), 'warning' );
			if ( $identity_warning ) {
				self::log( 'SNMP: ' . $identity_warning, 'warning' );
				$friendly = self::friendly_warning( $identity_warning );
				if ( $friendly ) self::log( $friendly, 'warning' );
			}
		} else {
			self::log( __( 'SNMP authentication succeeded; at least one standard OLT identity value is readable.', 'airfiber-centralized' ), 'success' );
		}

		$device_name = is_wp_error( $name_result ) ? '' : self::clean_snmp_string( $name_result );
		$description = is_wp_error( $description_result ) ? '' : self::clean_snmp_string( $description_result );
		if ( $device_name ) self::log( sprintf( __( 'OLT reports its name as %s.', 'airfiber-centralized' ), $device_name ), 'success' );
		if ( $description ) self::log( sprintf( __( 'Device description: %s', 'airfiber-centralized' ), $description ) );

		$match = self::detect_rx( $config, $device_name, $description );
		if ( ! $match ) {
			if ( $identity_failed ) {
				self::log( __( 'Neither the standard identity OIDs nor the known VSOL RX tables were readable.', 'airfiber-centralized' ), 'error' );
				self::error_response(
					$post_id,
					new WP_Error( 'snmp_access_failed', __( 'Airfiber could not read any SNMP data from this OLT. This points to SNMPv3 credentials, access/view permissions, Remote Server restrictions, or UDP 161 rather than the RX OID itself.', 'airfiber-centralized' ) )
				);
			}

			self::log( __( 'SNMP works, but no ONU RX-power table could be identified automatically.', 'airfiber-centralized' ), 'error' );
			self::error_response(
				$post_id,
				new WP_Error( 'rx_oid_not_found', __( 'SNMP connection is working, but Airfiber could not find this firmware\'s ONU RX-power table automatically.', 'airfiber-centralized' ) )
			);
		}

		$old_oid          = isset( $config['rx_oid'] ) ? $config['rx_oid'] : '';
		$config['rx_oid'] = $match['oid'];
		$oid_changed      = $old_oid !== $match['oid'];
		update_post_meta( $post_id, AFC_OLT_Manager::CONFIG_META, $config );
		self::sync_primary_legacy( $post_id, $config );
		delete_post_meta( $post_id, AFC_OLT_Manager::DISCONNECTED_META );

		if ( $identity_failed ) {
			self::log( __( 'The vendor RX table is readable even though the standard identity OIDs are blocked. Monitoring can still continue.', 'airfiber-centralized' ), 'success' );
		}
		if ( $oid_changed ) {
			self::log( sprintf( __( 'Detected RX OID %s and saved it to this OLT automatically.', 'airfiber-centralized' ), $match['oid'] ), 'success' );
		} else {
			self::log( __( 'The saved RX OID is correct; no configuration change was needed.', 'airfiber-centralized' ), 'success' );
		}

		$device                = self::get_device( $post_id );
		$device['name']        = $device_name ? $device_name : $device['name'];
		$device['description'] = $description ? $description : $device['description'];
		$device['test_status'] = 'success';
		$device['tested_at']   = current_time( 'mysql' );
		$device['onu_count']   = (int) $match['analysis']['total'];
		$device['valid_count'] = (int) $match['analysis']['plausible'];
		$device['rx_oid']      = $match['oid'];
		$device['rx_family']   = $match['label'];
		$device['message']     = sprintf(
			__( 'Connected successfully. Airfiber found the RX table automatically: %1$d ONU row(s), %2$d readable RX value(s).', 'airfiber-centralized' ),
			$device['onu_count'],
			$device['valid_count']
		);
		update_post_meta( $post_id, AFC_OLT_Manager::DEVICE_META, $device );

		wp_send_json_success(
			array(
				'message'         => $device['message'],
				'device'          => $device,
				'state'           => self::state_after_test( $post_id, true ),
				'diagnostics'     => self::$diagnostics,
				'detected_rx_oid' => $match['oid'],
				'oid_changed'     => $oid_changed,
			)
		);
	}
}
