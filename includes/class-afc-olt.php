<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only optical monitoring for all configured OLTs.
 */
class AFC_OLT {

	const OPTION_KEY           = 'afc_olt_settings';
	const LAST_STATUS_KEY      = 'afc_olt_last_status';
	const LAST_SNAPSHOT_KEY    = 'afc_olt_last_snapshot';
	const SNAPSHOT_TRANSIENT   = 'afc_olt_rx_snapshot';
	const NODE_SNAPSHOT_TRANSIENT = 'afc_olt_node_snapshot';
	const NODE_SNAPSHOT_OPTION = 'afc_olt_node_snapshot_last';
	const MAC_TRANSIENT        = 'afc_olt_learned_macs';
	const POLL_LOCK_TRANSIENT  = 'afc_olt_poll_lock';
	const SNAPSHOT_FORMAT      = 2;
	const MAC_CACHE_TTL        = 900;
	const GETNEXT_ROW_LIMIT    = 4096;
	const GETNEXT_FAILURE_LIMIT = 5;
	const RX_POWER_OID         = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7';
	const LEARNED_MAC_OID      = '1.3.6.1.4.1.37950.1.1.5.10.3.2.1.3';
	const LEARNED_MAC_PORT_OID = '1.3.6.1.4.1.37950.1.1.5.10.3.2.1.5';
	const SAVED_SECRET_MASK    = 'airfiber-saved-secret';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_afc_test_olt', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_afc_save_olt_binding', array( __CLASS__, 'ajax_save_binding' ) );
		add_action( 'wp_ajax_afc_get_olt_customer_signals', array( __CLASS__, 'ajax_customer_signals' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_async_optical_assets' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_async_optical_assets' ), 30 );
	}

	public static function enqueue_async_optical_assets( $hook_suffix = '' ) {
		$is_admin_ppp = is_admin() && 'toplevel_page_airfiber-centralized' === $hook_suffix;
		$is_frontend  = ! is_admin() && class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request();

		if ( ! current_user_can( 'manage_options' ) || ( ! $is_admin_ppp && ! $is_frontend ) ) {
			return;
		}

		wp_enqueue_script(
			'afc-ppp-optical-async',
			AFC_URL . 'assets/js/ppp-optical-async.js',
			array( 'jquery', 'afc-ppp-users' ),
			AFC_VERSION,
			true
		);
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
			'enabled'            => 0,
			'name'               => 'Primary EPON OLT',
			'host'               => '10.13.88.5',
			'port'               => 161,
			'version'            => '3',
			'community'          => '',
			'security_name'      => 'airfiber-monitor',
			'auth_protocol'      => 'SHA',
			'auth_passphrase'    => '',
			'privacy_protocol'   => 'DES',
			'privacy_passphrase' => '',
			'rx_oid'             => self::RX_POWER_OID,
			'warning_dbm'        => -24,
			'critical_dbm'       => -27,
			'cache_ttl'          => 300,
			'timeout_ms'         => 2500,
			'retries'            => 1,
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

		$warning  = isset( $input['warning_dbm'] ) ? (float) $input['warning_dbm'] : -24;
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
		delete_transient( self::POLL_LOCK_TRANSIENT );

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
		if ( class_exists( 'AFC_OLT_Manager' ) && method_exists( 'AFC_OLT_Manager', 'monitoring_nodes' ) ) {
			$nodes = AFC_OLT_Manager::monitoring_nodes();
			if ( ! empty( $nodes ) ) {
				return true;
			}
		}
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	public static function normalize_olt_id( $olt_id ) {
		$olt_id = trim( (string) $olt_id );
		return '' === $olt_id || 'primary' === strtolower( $olt_id ) ? 'primary' : (string) absint( $olt_id );
	}

	public static function entry_key( $olt_id, $pon, $onu ) {
		$olt_id = self::normalize_olt_id( $olt_id );
		$prefix = 'primary' === $olt_id ? '' : $olt_id . ':';
		return $prefix . absint( $pon ) . ':' . absint( $onu );
	}

	public static function entry_location( $key, $entry = array() ) {
		if ( is_array( $entry ) && ! empty( $entry['pon'] ) && ! empty( $entry['onu'] ) ) {
			return array(
				'olt_id' => self::normalize_olt_id( isset( $entry['olt_id'] ) ? $entry['olt_id'] : 'primary' ),
				'pon'    => absint( $entry['pon'] ),
				'onu'    => absint( $entry['onu'] ),
			);
		}

		$parts = explode( ':', (string) $key );
		if ( 3 === count( $parts ) ) {
			return array( 'olt_id' => self::normalize_olt_id( $parts[0] ), 'pon' => absint( $parts[1] ), 'onu' => absint( $parts[2] ) );
		}
		return array( 'olt_id' => 'primary', 'pon' => isset( $parts[0] ) ? absint( $parts[0] ) : 0, 'onu' => isset( $parts[1] ) ? absint( $parts[1] ) : 0 );
	}

	/**
	 * Return active manager profiles, falling back to the legacy primary option
	 * during upgrades or before the manager has imported it.
	 */
	public static function monitoring_nodes() {
		if ( class_exists( 'AFC_OLT_Manager' ) && method_exists( 'AFC_OLT_Manager', 'monitoring_nodes' ) ) {
			$nodes = AFC_OLT_Manager::monitoring_nodes();
			if ( ! empty( $nodes ) ) {
				return $nodes;
			}
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return array();
		}
		return array(
			'primary' => array(
				'id'         => 'primary',
				'post_id'    => 0,
				'name'       => isset( $settings['name'] ) ? $settings['name'] : __( 'Primary OLT', 'airfiber-centralized' ),
				'technology' => 'EPON',
				'primary'    => true,
				'config'     => $settings,
			),
		);
	}

	public static function monitoring_node( $olt_id ) {
		$olt_id = self::normalize_olt_id( $olt_id );
		$nodes  = self::monitoring_nodes();
		return isset( $nodes[ $olt_id ] ) ? $nodes[ $olt_id ] : null;
	}

	public static function is_snmp_available( $version = '' ) {
		if ( '' === $version ) {
			$settings = self::get_settings();
			$version  = $settings['version'];
		}

		return '2c' === $version ? function_exists( 'snmp2_real_walk' ) : function_exists( 'snmp3_real_walk' );
	}

	private static function is_ppp_list_request() {
		if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) ) {
			return false;
		}

		return 'afc_get_ppp_users' === sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	}

	private static function snapshot_is_current_format( $snapshot ) {
		return is_array( $snapshot ) && isset( $snapshot['format'] ) && self::SNAPSHOT_FORMAT === (int) $snapshot['format'];
	}

	private static function saved_snapshot( $message = '' ) {
		$cached = get_transient( self::SNAPSHOT_TRANSIENT );
		if ( self::snapshot_is_current_format( $cached ) && ! empty( $cached['entries'] ) ) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$last = get_option( self::LAST_SNAPSHOT_KEY, array() );
		if ( self::snapshot_is_current_format( $last ) && ! empty( $last['entries'] ) ) {
			$last['source'] = 'stale';
			$last['stale']  = true;
			if ( $message ) {
				$last['error'] = $message;
			}
			return $last;
		}

		return null;
	}

	public static function get_snapshot( $force = false ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'afc_olt_disabled', __( 'OLT monitoring is not enabled.', 'airfiber-centralized' ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::SNAPSHOT_TRANSIENT );
			if ( self::snapshot_is_current_format( $cached ) && ! empty( $cached['entries'] ) ) {
				$cached['source'] = 'cache';
				return $cached;
			}
		}

		/* Never make the PPP account request wait for SNMP. Optical has its own AJAX request. */
		if ( ! $force && self::is_ppp_list_request() ) {
			$saved = self::saved_snapshot( __( 'Optical readings are loading separately in the background.', 'airfiber-centralized' ) );
			return $saved ? $saved : new WP_Error( 'afc_olt_async_pending', __( 'Optical readings are loading separately in the background.', 'airfiber-centralized' ) );
		}

		/* Prevent several browser requests from walking the OLT at the same time. */
		if ( get_transient( self::POLL_LOCK_TRANSIENT ) ) {
			$saved = self::saved_snapshot( __( 'Another optical refresh is already running; showing the last saved snapshot.', 'airfiber-centralized' ) );
			return $saved ? $saved : new WP_Error( 'afc_olt_refresh_busy', __( 'Another optical refresh is already running. Try again in a few seconds.', 'airfiber-centralized' ) );
		}

		set_transient( self::POLL_LOCK_TRANSIENT, time(), 210 );
		try {
			$snapshot = self::poll_rx_power();
		} finally {
			delete_transient( self::POLL_LOCK_TRANSIENT );
		}

		if ( ! is_wp_error( $snapshot ) ) {
			set_transient( self::SNAPSHOT_TRANSIENT, $snapshot, self::snapshot_cache_ttl() );
			update_option( self::LAST_SNAPSHOT_KEY, $snapshot, false );
			return $snapshot;
		}

		$last = self::saved_snapshot( $snapshot->get_error_message() );
		return $last ? $last : $snapshot;
	}

	private static function snapshot_cache_ttl() {
		$ttl = 300;
		foreach ( self::monitoring_nodes() as $node ) {
			if ( ! empty( $node['config']['cache_ttl'] ) ) {
				$ttl = min( $ttl, max( 60, (int) $node['config']['cache_ttl'] ) );
			}
		}
		return $ttl;
	}

	private static function poll_rx_power() {
		$nodes         = self::monitoring_nodes();
		$entries       = array();
		$learned_macs  = array();
		$node_results  = array();
		$valid_count   = 0;
		$invalid_count = 0;
		$success_count = 0;

		foreach ( $nodes as $olt_id => $node ) {
			$result = self::poll_node_rx_power( $node );
			if ( is_wp_error( $result ) ) {
				$last_node = self::last_node_snapshot( $olt_id );
				if ( $last_node ) {
					foreach ( $last_node['entries'] as $key => $entry ) {
						$entry['stale'] = true;
						$entries[ $key ] = $entry;
					}
					$valid_count   += (int) $last_node['valid_count'];
					$invalid_count += (int) $last_node['invalid_count'];
					foreach ( $last_node['learned_macs'] as $mac => $locations ) {
						if ( ! isset( $learned_macs[ $mac ] ) ) {
							$learned_macs[ $mac ] = array();
						}
						foreach ( (array) $locations as $location ) {
							if ( ! in_array( $location, $learned_macs[ $mac ], true ) ) {
								$learned_macs[ $mac ][] = $location;
							}
						}
					}
				}
				$node_results[ $olt_id ] = array(
					'id'         => $olt_id,
					'name'       => $node['name'],
					'technology' => $node['technology'],
					'available'  => false,
					'stale'      => (bool) $last_node,
					'count'      => $last_node ? (int) $last_node['count'] : 0,
					'error'      => $result->get_error_message(),
				);
				continue;
			}

			$success_count++;
			$entries       = array_replace( $entries, $result['entries'] );
			$valid_count  += (int) $result['valid_count'];
			$invalid_count += (int) $result['invalid_count'];
			foreach ( $result['learned_macs'] as $mac => $locations ) {
				if ( ! isset( $learned_macs[ $mac ] ) ) {
					$learned_macs[ $mac ] = array();
				}
				foreach ( (array) $locations as $location ) {
					if ( ! in_array( $location, $learned_macs[ $mac ], true ) ) {
						$learned_macs[ $mac ][] = $location;
					}
				}
			}
			$node_results[ $olt_id ] = array(
				'id'         => $olt_id,
				'name'       => $node['name'],
				'technology' => $node['technology'],
				'available'  => true,
				'stale'      => false,
				'count'      => (int) $result['count'],
				'valid_count'=> (int) $result['valid_count'],
				'rx_oid'     => $result['rx_oid'],
				'error'      => '',
			);
		}

		if ( 0 === $success_count || empty( $entries ) ) {
			$messages = array();
			foreach ( $node_results as $node ) {
				if ( ! empty( $node['error'] ) ) {
					$messages[] = $node['name'] . ': ' . $node['error'];
				}
			}
			return new WP_Error( 'afc_olt_all_walks_failed', $messages ? implode( ' ', $messages ) : __( 'No active OLT returned an RX-power table.', 'airfiber-centralized' ) );
		}

		$errors = array();
		foreach ( $node_results as $node ) {
			if ( ! empty( $node['error'] ) ) {
				$errors[] = $node['name'] . ': ' . $node['error'];
			}
		}

		return array(
			'format'        => self::SNAPSHOT_FORMAT,
			'entries'       => $entries,
			'learned_macs'  => $learned_macs,
			'nodes'         => $node_results,
			'node_count'    => count( $node_results ),
			'available_nodes' => $success_count,
			'count'         => count( $entries ),
			'valid_count'   => $valid_count,
			'invalid_count' => $invalid_count,
			'rx_oid'        => 1 === count( $node_results ) ? reset( $node_results )['rx_oid'] : '',
			'collected_at'  => current_time( 'mysql' ),
			'collected_ts'  => time(),
			'source'        => 'live',
			'stale'         => false,
			'partial'       => $success_count < count( $node_results ),
			'error'         => implode( ' ', $errors ),
		);
	}

	public static function poll_node_rx_power( $node ) {
		$settings   = isset( $node['config'] ) && is_array( $node['config'] ) ? $node['config'] : array();

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
		return self::build_node_snapshot( $node, $walk, $oid );
	}

	/**
	 * Parse an RX walk already completed by the connection manager and promote it
	 * into the shared multi-OLT snapshot. This avoids walking a slow GPON table a
	 * second time after Retry Connection / Update OLT.
	 */
	public static function refresh_node_from_walk( $node, $walk, $oid = '' ) {
		if ( ! is_array( $walk ) || empty( $walk ) ) {
			return new WP_Error( 'afc_olt_empty_walk', __( 'The OLT returned no ONU RX rows.', 'airfiber-centralized' ) );
		}
		$node_snapshot = self::build_node_snapshot( $node, $walk, $oid );
		if ( is_wp_error( $node_snapshot ) ) {
			return $node_snapshot;
		}
		$combined = self::combine_saved_node_snapshots();
		if ( is_wp_error( $combined ) ) {
			return $combined;
		}
		return self::store_snapshot( $combined );
	}

	private static function build_node_snapshot( $node, $walk, $oid = '' ) {
		$settings   = isset( $node['config'] ) && is_array( $node['config'] ) ? $node['config'] : array();
		$olt_id     = self::normalize_olt_id( isset( $node['id'] ) ? $node['id'] : 'primary' );
		$olt_name   = isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : __( 'OLT', 'airfiber-centralized' );
		$technology = isset( $node['technology'] ) ? sanitize_text_field( $node['technology'] ) : '';
		$oid        = ltrim( $oid ? $oid : ( isset( $settings['rx_oid'] ) ? $settings['rx_oid'] : '' ), '.' );

		$entries       = array();
		$valid_count   = 0;
		$invalid_count = 0;
		$collected_at  = current_time( 'mysql' );
		foreach ( $walk as $instance_oid => $raw_value ) {
			$indexes = self::extract_indexes( $instance_oid, $oid );
			$reading = self::parse_rx_power_reading( $raw_value );
			if ( ! $indexes || null === $reading ) {
				continue;
			}

			$is_valid = ! empty( $reading['valid'] );
			$status   = $is_valid ? self::classify_power( $reading['power'], $settings ) : 'invalid';
			$key      = self::entry_key( $olt_id, $indexes['pon'], $indexes['onu'] );
			$entries[ $key ] = array(
				'olt_id'       => $olt_id,
				'olt_name'     => $olt_name,
				'technology'   => $technology,
				'pon'          => $indexes['pon'],
				'onu'          => $indexes['onu'],
				'rx_power'     => $is_valid ? round( (float) $reading['power'], 2 ) : null,
				'raw_rx'       => $reading['raw'],
				'raw_rx_text'  => $reading['raw_text'],
				'rx_scale'     => $reading['scale'],
				'signal_valid' => $is_valid,
				'status'       => $status,
				'stale'        => false,
				'collected_at' => $collected_at,
			);

			if ( $is_valid ) {
				$valid_count++;
			} else {
				$invalid_count++;
			}
		}

		if ( empty( $entries ) ) {
			return new WP_Error( 'afc_olt_empty_walk', __( 'SNMP responded, but no ONU rows were found under the configured RX-power OID.', 'airfiber-centralized' ) );
		}

		$learned_macs = 'EPON' === strtoupper( $technology ) ? self::get_learned_macs( $settings, $olt_id ) : array();

		$snapshot = array(
			'format'        => self::SNAPSHOT_FORMAT,
			'entries'       => $entries,
			'learned_macs'  => $learned_macs,
			'count'         => count( $entries ),
			'valid_count'   => $valid_count,
			'invalid_count' => $invalid_count,
			'rx_oid'        => $oid,
			'collected_at'  => $collected_at,
			'collected_ts'  => time(),
			'source'        => 'live',
			'stale'         => false,
			'error'         => '',
		);
		self::save_node_snapshot( $olt_id, $snapshot, isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : 300 );
		return $snapshot;
	}

	/**
	 * Rebuild one collision-safe snapshot from the latest saved result of every
	 * active OLT. Secondary OLT keys retain their OLT ID, so PON 1 / ONU 1 on two
	 * chassis can never overwrite one another.
	 */
	private static function combine_saved_node_snapshots() {
		$nodes          = self::monitoring_nodes();
		$entries        = array();
		$learned_macs   = array();
		$node_results   = array();
		$valid_count    = 0;
		$invalid_count  = 0;
		$available      = 0;
		$collected_ts   = 0;
		$collected_at   = '';
		$errors         = array();

		foreach ( $nodes as $olt_id => $node ) {
			$result = self::last_node_snapshot( $olt_id );
			if ( ! $result ) {
				$message = __( 'No saved RX snapshot is available for this OLT yet.', 'airfiber-centralized' );
				$errors[] = $node['name'] . ': ' . $message;
				$node_results[ $olt_id ] = array(
					'id' => $olt_id, 'name' => $node['name'], 'technology' => $node['technology'],
					'available' => false, 'stale' => false, 'count' => 0, 'valid_count' => 0,
					'rx_oid' => '', 'error' => $message,
				);
				continue;
			}

			$available++;
			$entries = array_replace( $entries, (array) $result['entries'] );
			$valid_count += isset( $result['valid_count'] ) ? (int) $result['valid_count'] : 0;
			$invalid_count += isset( $result['invalid_count'] ) ? (int) $result['invalid_count'] : 0;
			foreach ( isset( $result['learned_macs'] ) ? (array) $result['learned_macs'] : array() as $mac => $locations ) {
				if ( ! isset( $learned_macs[ $mac ] ) ) $learned_macs[ $mac ] = array();
				foreach ( (array) $locations as $location ) {
					if ( ! in_array( $location, $learned_macs[ $mac ], true ) ) $learned_macs[ $mac ][] = $location;
				}
			}
			$node_ts = isset( $result['collected_ts'] ) ? (int) $result['collected_ts'] : 0;
			if ( $node_ts >= $collected_ts ) {
				$collected_ts = $node_ts;
				$collected_at = isset( $result['collected_at'] ) ? (string) $result['collected_at'] : $collected_at;
			}
			$node_results[ $olt_id ] = array(
				'id' => $olt_id, 'name' => $node['name'], 'technology' => $node['technology'],
				'available' => true, 'stale' => ! empty( $result['stale'] ),
				'count' => isset( $result['count'] ) ? (int) $result['count'] : count( $result['entries'] ),
				'valid_count' => isset( $result['valid_count'] ) ? (int) $result['valid_count'] : 0,
				'rx_oid' => isset( $result['rx_oid'] ) ? (string) $result['rx_oid'] : '', 'error' => '',
			);
		}

		if ( empty( $entries ) ) {
			return new WP_Error( 'afc_olt_no_saved_nodes', __( 'No active OLT has a saved RX snapshot yet.', 'airfiber-centralized' ) );
		}
		return array(
			'format' => self::SNAPSHOT_FORMAT, 'entries' => $entries, 'learned_macs' => $learned_macs,
			'nodes' => $node_results, 'node_count' => count( $nodes ), 'available_nodes' => $available,
			'count' => count( $entries ), 'valid_count' => $valid_count, 'invalid_count' => $invalid_count,
			'rx_oid' => 1 === count( $node_results ) ? (string) reset( $node_results )['rx_oid'] : '',
			'collected_at' => $collected_at ? $collected_at : current_time( 'mysql' ),
			'collected_ts' => $collected_ts ? $collected_ts : time(), 'source' => 'node-refresh',
			'stale' => false, 'partial' => $available < count( $nodes ), 'error' => implode( ' ', $errors ),
		);
	}

	public static function store_snapshot( $snapshot ) {
		if ( is_wp_error( $snapshot ) || empty( $snapshot['entries'] ) ) return $snapshot;
		set_transient( self::SNAPSHOT_TRANSIENT, $snapshot, self::snapshot_cache_ttl() );
		update_option( self::LAST_SNAPSHOT_KEY, $snapshot, false );
		return $snapshot;
	}

	private static function node_transient_key( $base, $olt_id ) {
		$olt_id = self::normalize_olt_id( $olt_id );
		return 'primary' === $olt_id ? $base : $base . '_' . md5( $olt_id );
	}

	private static function save_node_snapshot( $olt_id, $snapshot, $ttl ) {
		if ( empty( $snapshot['entries'] ) ) {
			return;
		}
		$olt_id = self::normalize_olt_id( $olt_id );
		set_transient( self::node_transient_key( self::NODE_SNAPSHOT_TRANSIENT, $olt_id ), $snapshot, min( 900, max( 60, $ttl ) ) );
		$saved            = get_option( self::NODE_SNAPSHOT_OPTION, array() );
		$saved            = is_array( $saved ) ? $saved : array();
		$saved[ $olt_id ] = $snapshot;
		update_option( self::NODE_SNAPSHOT_OPTION, $saved, false );
	}

	private static function last_node_snapshot( $olt_id ) {
		$olt_id = self::normalize_olt_id( $olt_id );
		$cached = get_transient( self::node_transient_key( self::NODE_SNAPSHOT_TRANSIENT, $olt_id ) );
		if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
			return $cached;
		}
		$saved = get_option( self::NODE_SNAPSHOT_OPTION, array() );
		return is_array( $saved ) && ! empty( $saved[ $olt_id ]['entries'] ) ? $saved[ $olt_id ] : null;
	}

	private static function get_learned_macs( $settings, $olt_id = 'primary' ) {
		$olt_id    = self::normalize_olt_id( $olt_id );
		$cache_key = self::node_transient_key( self::MAC_TRANSIENT, $olt_id );
		$cached    = get_transient( $cache_key );
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
			$filtered = array();
			foreach ( $last['learned_macs'] as $mac => $locations ) {
				foreach ( (array) $locations as $location ) {
					$location_id = self::normalize_olt_id( isset( $location['olt_id'] ) ? $location['olt_id'] : 'primary' );
					if ( $olt_id === $location_id ) {
						$filtered[ $mac ][] = $location;
					}
				}
			}
			if ( $filtered ) {
				set_transient( $cache_key, $filtered, self::MAC_CACHE_TTL );
				return $filtered;
			}
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
				$location = array( 'olt_id' => $olt_id, 'pon' => $indexes['pon'], 'onu' => $indexes['onu'] );
				if ( ! isset( $learned_macs[ $mac ] ) ) {
					$learned_macs[ $mac ] = array();
				}
				if ( ! in_array( $location, $learned_macs[ $mac ], true ) ) {
					$learned_macs[ $mac ][] = $location;
				}
			}
		}

		set_transient( $cache_key, $learned_macs, self::MAC_CACHE_TTL );
		return $learned_macs;
	}

	/**
	 * Walk a configured OID with a bounded GETNEXT fallback for OLT firmware
	 * whose SNMP agent answers GET/GETNEXT but times out on GETBULK walks.
	 */
	public static function walk_configured_oid( $settings, $oid ) {
		return self::walk_oid( $settings, ltrim( (string) $oid, '.' ) );
	}

	private static function walk_oid( $settings, $oid ) {
		$target  = 161 === (int) $settings['port'] ? $settings['host'] : 'udp:' . $settings['host'] . ':' . (int) $settings['port'];
		$timeout = (int) $settings['timeout_ms'] * 1000;
		$retries = (int) $settings['retries'];
		$technology = isset( $settings['technology'] ) ? strtoupper( (string) $settings['technology'] ) : '';

		/*
		 * real_walk() otherwise inherits the process-wide Net-SNMP display mode.
		 * Textual keys such as "SNMPv2-SMI::enterprises..." cannot be matched
		 * safely against a configured numeric OID and broke smart RX discovery.
		 */
		if ( function_exists( 'snmp_set_oid_output_format' ) && defined( 'SNMP_OID_OUTPUT_NUMERIC' ) ) {
			@snmp_set_oid_output_format( SNMP_OID_OUTPUT_NUMERIC );
		}

		/*
		 * V1600G-family GPON agents answer GET/GETNEXT reliably but can leave
		 * Net-SNMP's bulk walk waiting until the web request times out. Use the
		 * existing bounded walker first for their v2c optical tables, then retain
		 * the normal walk as a fallback for unusual firmware.
		 */
		if ( '2c' === $settings['version'] && 'GPON' === $technology ) {
			$getnext_walk = self::walk_oid_getnext( $settings, $oid );
			if ( ! is_wp_error( $getnext_walk ) && $getnext_walk ) {
				return $getnext_walk;
			}
		}

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

		if ( false !== $walk && is_array( $walk ) && $walk ) {
			return $walk;
		}

		return self::walk_oid_getnext( $settings, $oid );
	}

	private static function walk_oid_getnext( $settings, $oid ) {
		if ( ! class_exists( 'SNMP' ) ) {
			return new WP_Error( 'afc_olt_snmp_walk_failed', __( 'The SNMP walk failed.', 'airfiber-centralized' ) );
		}

		$host       = isset( $settings['host'] ) ? (string) $settings['host'] : '';
		$port       = isset( $settings['port'] ) ? (int) $settings['port'] : 161;
		$timeout_us = min( 10000000, max( 500000, (int) $settings['timeout_ms'] * 1000 ) );
		$session    = null;

		try {
			if ( '2c' === $settings['version'] ) {
				$community = self::decrypt_secret( $settings['community'] );
				if ( '' === $community ) {
					return new WP_Error( 'afc_olt_community_missing', __( 'Save the read-only SNMP community first.', 'airfiber-centralized' ) );
				}
				$session = new SNMP( SNMP::VERSION_2c, $host . ':' . $port, $community, $timeout_us, 0 );
			} else {
				$auth_passphrase    = self::decrypt_secret( $settings['auth_passphrase'] );
				$privacy_passphrase = self::decrypt_secret( $settings['privacy_passphrase'] );
				if ( empty( $settings['security_name'] ) || '' === $auth_passphrase || '' === $privacy_passphrase ) {
					return new WP_Error( 'afc_olt_v3_credentials_missing', __( 'Save the SNMPv3 credentials first.', 'airfiber-centralized' ) );
				}
				$session = new SNMP( SNMP::VERSION_3, $host . ':' . $port, $settings['security_name'], $timeout_us, 0 );
				$session->setSecurity( 'authPriv', 'SHA', $auth_passphrase, 'DES', $privacy_passphrase );
			}

			$session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
			$root                       = trim( preg_replace( '/[^0-9.]/', '', (string) $oid ), '.' );
			$current                    = $root;
			$rows                       = array();
			$consecutive_failures       = 0;

			for ( $iteration = 0; $iteration < self::GETNEXT_ROW_LIMIT; $iteration++ ) {
				set_error_handler( function () { return true; } );
				try {
					$result = $session->getnext( array( $current ) );
				} catch ( Throwable $error ) {
					$result = false;
				}
				restore_error_handler();

				if ( ! is_array( $result ) || ! $result ) {
					$consecutive_failures++;
					if ( $consecutive_failures < self::GETNEXT_FAILURE_LIMIT ) {
						continue;
					}
					return new WP_Error( 'afc_olt_snmp_getnext_failed', __( 'The OLT stopped responding before the SNMP table was complete.', 'airfiber-centralized' ) );
				}

				$next_oid = trim( preg_replace( '/[^0-9.]/', '', (string) array_key_first( $result ) ), '.' );
				if ( '' === $next_oid || $next_oid === $current ) {
					return new WP_Error( 'afc_olt_snmp_getnext_stalled', __( 'The OLT returned an invalid SNMP table cursor.', 'airfiber-centralized' ) );
				}
				if ( 0 !== strpos( $next_oid, $root . '.' ) ) {
					return $rows;
				}

				$rows[ $next_oid ]    = reset( $result );
				$current               = $next_oid;
				$consecutive_failures  = 0;
			}

			return new WP_Error( 'afc_olt_snmp_getnext_limit', __( 'The SNMP table exceeded the safe polling limit.', 'airfiber-centralized' ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'afc_olt_snmp_getnext_failed', __( 'The SNMP GETNEXT fallback failed.', 'airfiber-centralized' ) );
		} finally {
			if ( $session instanceof SNMP ) {
				$session->close();
			}
		}
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

	/**
	 * VSOL commonly returns a human-readable SNMP string such as:
	 * STRING: "0.01 mW (-20.56 dBm)"
	 * The dBm value is the receive power we need; the leading mW value must not
	 * be mistaken for dBm. Firmware that returns a plain scaled integer is kept
	 * as a fallback for compatibility.
	 */
	public static function parse_rx_power_reading( $raw_value ) {
		$value = trim( (string) $raw_value );
		if ( '' === $value || preg_match( '/(?:no such|not available|unknown|invalid|infinity|\binf\b)/i', $value ) ) {
			return null;
		}

		/* Prefer the explicitly labelled dBm number anywhere in the SNMP string. */
		if ( preg_match( '/(-?\d+(?:\.\d+)?)\s*dBm\b/i', $value, $matches ) ) {
			$power = (float) $matches[1];
			$valid = $power < -1 && $power >= -60;
			return array(
				'raw'      => $power,
				'raw_text' => $value,
				'power'    => $valid ? $power : null,
				'scale'    => 1,
				'valid'    => $valid,
			);
		}

		/*
		 * Remove the Net-SNMP type prefix before looking for a number. Without
		 * this, values such as "Counter32: 4294965208" are parsed as 32.
		 */
		$numeric_text = preg_replace(
			'/^\s*(?:(?:OCTET\s+STRING|STRING|INTEGER(?:32)?|COUNTER(?:32|64)?|GAUGE(?:32)?|UNSIGNED(?:32)?|OPAQUE|BITS|TIMETICKS|IPADDRESS)\s*:\s*)+/i',
			'',
			$value
		);
		$numeric_text = trim( (string) $numeric_text, "\"' \t\r\n" );

		/* Fallback for firmware that returns only a numeric/scaled numeric value. */
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $numeric_text, $matches ) ) {
			return null;
		}

		$raw    = (float) $matches[0];
		$signed = $raw;

		/*
		 * Some VSOL firmware exposes a negative scaled dBm integer through an
		 * unsigned SNMP type. Decode both common 32-bit and 16-bit wraps.
		 */
		if ( $raw >= 4294957296 && $raw <= 4294967295 ) {
			$signed = $raw - 4294967296;
		} elseif ( $raw >= 55536 && $raw <= 65535 ) {
			$signed = $raw - 65536;
		}

		$power = $signed;
		$scale = 1;
		if ( $signed <= -100 && $signed >= -600 ) {
			$power = $signed / 10;
			$scale = 10;
		} elseif ( $signed < -600 && $signed >= -6000 ) {
			$power = $signed / 100;
			$scale = 100;
		}

		$valid = $power < -1 && $power >= -60;
		return array(
			'raw'      => $raw,
			'raw_text' => $value,
			'power'    => $valid ? $power : null,
			'scale'    => $scale,
			'valid'    => $valid,
		);
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
		$olt_id      = self::normalize_olt_id( get_post_meta( $customer_id, '_afc_olt_id', true ) );
		$node        = self::monitoring_node( $olt_id );
		$pon         = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
		$onu         = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
		$onu_mac     = (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true );

		$result = array(
			'mapped'       => $pon > 0 && $onu > 0,
			'olt_id'       => $olt_id,
			'olt_name'     => $node ? $node['name'] : '',
			'technology'   => $node ? $node['technology'] : '',
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
		$key                    = self::entry_key( $olt_id, $pon, $onu );
		if ( empty( $snapshot['entries'][ $key ] ) ) {
			$result['status'] = $result['stale'] ? 'stale' : 'offline';
			return $result;
		}

		$entry = $snapshot['entries'][ $key ];
		if ( ! empty( $entry['stale'] ) ) {
			$result['stale'] = true;
			if ( ! empty( $entry['collected_at'] ) ) {
				$result['collected_at'] = $entry['collected_at'];
			}
		}
		if ( empty( $entry['signal_valid'] ) || ! isset( $entry['rx_power'] ) || null === $entry['rx_power'] ) {
			$result['status'] = $result['stale'] ? 'stale' : 'invalid';
			return $result;
		}
		$result['rx_power'] = $entry['rx_power'];
		$result['status']   = $result['stale'] ? 'stale' : $entry['status'];
		return $result;
	}

	public static function snapshot_summary( $snapshot ) {
		if ( is_wp_error( $snapshot ) ) {
			return array(
				'enabled'       => self::is_enabled(),
				'available'     => false,
				'stale'         => false,
				'count'         => 0,
				'valid_count'   => 0,
				'invalid_count' => 0,
				'collected_at'  => '',
				'message'       => $snapshot->get_error_message(),
			);
		}

		return array(
			'enabled'       => true,
			'available'     => true,
			'partial'       => ! empty( $snapshot['partial'] ),
			'node_count'    => isset( $snapshot['node_count'] ) ? (int) $snapshot['node_count'] : 1,
			'available_nodes' => isset( $snapshot['available_nodes'] ) ? (int) $snapshot['available_nodes'] : 1,
			'stale'         => ! empty( $snapshot['stale'] ),
			'count'         => isset( $snapshot['count'] ) ? (int) $snapshot['count'] : count( $snapshot['entries'] ),
			'valid_count'   => isset( $snapshot['valid_count'] ) ? (int) $snapshot['valid_count'] : 0,
			'invalid_count' => isset( $snapshot['invalid_count'] ) ? (int) $snapshot['invalid_count'] : 0,
			'collected_at'  => isset( $snapshot['collected_at'] ) ? $snapshot['collected_at'] : '',
			'message'       => isset( $snapshot['error'] ) ? $snapshot['error'] : '',
		);
	}

	public static function test_connection() {
		$result = self::poll_rx_power();
		if ( ! is_wp_error( $result ) ) {
			set_transient( self::SNAPSHOT_TRANSIENT, $result, self::snapshot_cache_ttl() );
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

	public static function ajax_customer_signals() {
		self::authorize_admin_ajax( 'afc_ppp_users' );

		$customers = array();
		if ( isset( $_POST['customers'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['customers'] ), true );
			if ( is_array( $decoded ) ) {
				$customers = array_slice( $decoded, 0, 1000 );
			}
		}

		$force    = ! empty( $_POST['refresh'] );
		$snapshot = self::get_snapshot( $force );
		$signals  = array();

		foreach ( $customers as $item ) {
			$customer_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
				continue;
			}

			$signal = self::get_customer_signal( $customer_id, $snapshot );
			if ( empty( $signal['mapped'] ) ) {
				$caller_id  = isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : '';
				$suggestion = self::suggest_binding( $caller_id, $snapshot );
				if ( $suggestion ) {
					$signal['suggested'] = $suggestion;
				}
			}
			$signals[ (string) $customer_id ] = $signal;
		}

		wp_send_json_success(
			array(
				'signals' => $signals,
				'summary' => self::snapshot_summary( $snapshot ),
			)
		);
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
			'status'        => 'success',
			'message'       => sprintf(
				__( 'Connected successfully and received %1$d ONU row(s): %2$d valid RX reading(s), %3$d invalid RX value(s).', 'airfiber-centralized' ),
				(int) $result['count'],
				(int) $result['valid_count'],
				(int) $result['invalid_count']
			),
			'count'         => (int) $result['count'],
			'valid_count'   => (int) $result['valid_count'],
			'invalid_count' => (int) $result['invalid_count'],
			'time'          => current_time( 'mysql' ),
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

		$olt_id = self::normalize_olt_id( isset( $_POST['olt_id'] ) ? wp_unslash( $_POST['olt_id'] ) : 'primary' );
		$node   = self::monitoring_node( $olt_id );
		$pon    = isset( $_POST['pon'] ) ? absint( $_POST['pon'] ) : 0;
		$onu    = isset( $_POST['onu'] ) ? absint( $_POST['onu'] ) : 0;
		if ( ! $node ) {
			wp_send_json_error( array( 'message' => __( 'Select an active OLT for this customer.', 'airfiber-centralized' ) ) );
		}
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
					array( 'key' => '_afc_olt_id', 'value' => $olt_id ),
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

		update_post_meta( $customer_id, '_afc_olt_id', $olt_id );
		update_post_meta( $customer_id, '_afc_olt_pon', $pon );
		update_post_meta( $customer_id, '_afc_olt_onu', $onu );
		update_post_meta( $customer_id, '_afc_olt_onu_mac', $onu_mac );

		wp_send_json_success( array( 'message' => sprintf( __( 'Customer mapped to %1$s, PON %2$d / ONU %3$d.', 'airfiber-centralized' ), $node['name'], $pon, $onu ) ) );
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
