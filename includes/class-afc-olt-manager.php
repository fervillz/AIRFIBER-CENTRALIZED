<?php

defined( 'ABSPATH' ) || exit;

/**
 * Frontend OLT registry and draft/publish configuration workflow.
 *
 * OLT connections are stored as a private CPT so each device has a real
 * WordPress draft/publish lifecycle without exposing a wp-admin editor.
 */
class AFC_OLT_Manager {

	const POST_TYPE   = 'afc_olt_node';
	const NONCE       = 'afc_olt_manager';
	const CONFIG_META = '_afc_olt_config';
	const DEVICE_META = '_afc_olt_device';
	const PRIMARY_META = '_afc_olt_primary';
	const DISCONNECTED_META = '_afc_olt_disconnected';
	const SAVED_SECRET_MASK = 'airfiber-saved-secret';
	const SYS_NAME_OID = '1.3.6.1.2.1.1.5.0';
	const SYS_DESCR_OID = '1.3.6.1.2.1.1.1.0';
	const EPON_RX_OID = '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7';
	const GPON_RX_OID = '1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7';
	const DEFAULT_EPON_HOST = '10.13.88.5';
	const DEFAULT_GPON_HOST = '10.13.88.7';
	const GPON_SEEDED_OPTION = 'afc_olt_seeded_10_13_88_7';
	const GPON_TESTED_OPTION = 'afc_olt_auto_tested_10_13_88_7_v3';
	const GPON_TEST_HOOK = 'afc_olt_test_seeded_gpon';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed_default_gpon' ), 20 );
		add_action( self::GPON_TEST_HOOK, array( __CLASS__, 'test_seeded_gpon' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1045 );

		add_action( 'wp_ajax_afc_olt_manager_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_afc_olt_manager_get', array( __CLASS__, 'ajax_get' ) );
		add_action( 'wp_ajax_afc_olt_manager_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_olt_manager_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_afc_olt_manager_state', array( __CLASS__, 'ajax_state' ) );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'OLTs', 'airfiber-centralized' ),
					'singular_name' => __( 'OLT', 'airfiber-centralized' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capability_type'      => 'post',
				'map_meta_cap'        => true,
			)
		);
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

		wp_enqueue_style(
			'afc-olt-manager',
			AFC_URL . 'assets/css/olt-manager.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-olt-manager',
			AFC_URL . 'assets/js/olt-manager.js',
			array( 'jquery', 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-olt-manager',
			'afcOLTManager',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'autosaveMs'   => 5000,
				'secretMask'   => self::SAVED_SECRET_MASK,
				'defaultRxOid' => self::GPON_RX_OID,
				'gponRxOid'    => self::GPON_RX_OID,
				'eponRxOid'    => self::EPON_RX_OID,
				'testClientTimeoutMs'     => 30000,
				'gponTestClientTimeoutMs' => 75000,
			)
		);
	}

	private static function defaults() {
		return array(
			'host'               => '',
			'port'               => 161,
			'version'            => '3',
			'community'          => '',
			'security_name'      => 'airfiber-monitor',
			'auth_protocol'      => 'SHA',
			'auth_passphrase'    => '',
			'privacy_protocol'   => 'DES',
			'privacy_passphrase' => '',
			'rx_oid'             => self::GPON_RX_OID,
			'warning_dbm'        => -24,
			'critical_dbm'       => -27,
			'cache_ttl'          => 300,
			'timeout_ms'         => 2500,
			'retries'            => 1,
			'technology'         => 'GPON',
		);
	}

	private static function normalize_technology( $technology ) {
		return 'EPON' === strtoupper( sanitize_text_field( $technology ) ) ? 'EPON' : 'GPON';
	}

	private static function credentials_are_configured( $config ) {
		if ( '2c' === $config['version'] ) {
			return ! empty( $config['community'] );
		}

		return ! empty( $config['security_name'] ) && ! empty( $config['auth_passphrase'] ) && ! empty( $config['privacy_passphrase'] );
	}

	private static function inherit_monitoring_credentials( $config, $template_id ) {
		if ( self::credentials_are_configured( $config ) || ! $template_id ) {
			return $config;
		}
		$template = self::get_config( $template_id );
		if ( ! self::credentials_are_configured( $template ) ) {
			return $config;
		}
		foreach ( array( 'port', 'version', 'community', 'security_name', 'auth_protocol', 'auth_passphrase', 'privacy_protocol', 'privacy_passphrase', 'timeout_ms', 'retries' ) as $key ) {
			$config[ $key ] = $template[ $key ];
		}
		return $config;
	}

	/**
	 * Add the known GPON peer once, using the already-encrypted primary OLT
	 * credentials as a starting profile. The background test records its real
	 * online/error state after the plugin update is loaded on WordPress.
	 */
	public static function maybe_seed_default_gpon() {
		self::maybe_import_legacy();
		$seeded_id = absint( get_option( self::GPON_SEEDED_OPTION, 0 ) );
		if ( $seeded_id && $seeded_id === self::primary_id() ) {
			delete_option( self::GPON_SEEDED_OPTION );
			$seeded_id = 0;
		}
		if ( $seeded_id && self::POST_TYPE === get_post_type( $seeded_id ) ) {
			$config = self::get_config( $seeded_id );
			$original_config = $config;
			$config = self::inherit_monitoring_credentials( $config, self::primary_id() );
			$config_changed = false;
			if ( $config !== $original_config ) {
				$config_changed = true;
			}
			if ( self::DEFAULT_GPON_HOST !== $config['host'] ) {
				$config['host']   = self::DEFAULT_GPON_HOST;
				$config_changed   = true;
			}
			if ( 'GPON' !== $config['technology'] ) {
				$config['technology'] = 'GPON';
				$config_changed        = true;
			}
			if ( self::EPON_RX_OID === $config['rx_oid'] ) {
				$config['rx_oid']     = self::GPON_RX_OID;
				$config_changed       = true;
			}
			if ( $config_changed ) {
				update_post_meta( $seeded_id, self::CONFIG_META, $config );
			}
			$device = self::get_device( $seeded_id );
			if (
				'publish' === get_post_status( $seeded_id ) &&
				'success' !== $device['test_status'] &&
				! get_option( self::GPON_TESTED_OPTION, false ) &&
				self::credentials_are_configured( $config ) &&
				! wp_next_scheduled( self::GPON_TEST_HOOK, array( $seeded_id ) )
			) {
				wp_schedule_single_event( time() + 30, self::GPON_TEST_HOOK, array( $seeded_id ) );
			}
			return;
		}

		$nodes         = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$template_id   = 0;
		$existing_gpon = 0;

		foreach ( $nodes as $node ) {
			$config = self::get_config( $node->ID );
			if ( self::DEFAULT_EPON_HOST === $config['host'] ) {
				$template_id = (int) $node->ID;
				$config_changed = false;
				if ( 'EPON' !== $config['technology'] ) {
					$config['technology'] = 'EPON';
					$config_changed        = true;
				}
				if ( self::GPON_RX_OID === $config['rx_oid'] ) {
					$config['rx_oid']     = self::EPON_RX_OID;
					$config_changed       = true;
				}
				if ( $config_changed ) {
					update_post_meta( $node->ID, self::CONFIG_META, $config );
				}
			}
			if ( self::DEFAULT_GPON_HOST === $config['host'] ) {
				$existing_gpon = (int) $node->ID;
				$config_changed = false;
				if ( 'GPON' !== $config['technology'] ) {
					$config['technology'] = 'GPON';
					$config_changed        = true;
				}
				if ( self::EPON_RX_OID === $config['rx_oid'] ) {
					$config['rx_oid']     = self::GPON_RX_OID;
					$config_changed       = true;
				}
				if ( $config_changed ) {
					update_post_meta( $node->ID, self::CONFIG_META, $config );
				}
			}
		}

		if ( $existing_gpon ) {
			update_option( self::GPON_SEEDED_OPTION, $existing_gpon, false );
			$config = self::get_config( $existing_gpon );
			$inherited = self::inherit_monitoring_credentials( $config, $template_id ? $template_id : self::primary_id() );
			if ( $inherited !== $config ) {
				$config = $inherited;
				update_post_meta( $existing_gpon, self::CONFIG_META, $config );
			}
			$device = self::get_device( $existing_gpon );
			if (
				'publish' === get_post_status( $existing_gpon ) &&
				'success' !== $device['test_status'] &&
				! get_option( self::GPON_TESTED_OPTION, false ) &&
				self::credentials_are_configured( $config ) &&
				! wp_next_scheduled( self::GPON_TEST_HOOK, array( $existing_gpon ) )
			) {
				wp_schedule_single_event( time() + 30, self::GPON_TEST_HOOK, array( $existing_gpon ) );
			}
			return;
		}

		if ( ! $template_id ) {
			$template_id = self::primary_id();
		}

		$config               = $template_id ? self::get_config( $template_id ) : self::defaults();
		$config['host']       = self::DEFAULT_GPON_HOST;
		$config['technology'] = 'GPON';
		$config['rx_oid']     = self::GPON_RX_OID;
		$ready                = self::credentials_are_configured( $config );
		$post_id              = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => $ready ? 'publish' : 'draft',
				'post_title'  => __( 'GPON OLT', 'airfiber-centralized' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::CONFIG_META, $config );
		update_post_meta(
			$post_id,
			self::DEVICE_META,
			array(
				'name'        => '',
				'description' => '',
				'test_status' => $ready ? 'error' : '',
				'message'     => $ready
					? __( 'The GPON profile was added and is waiting for its first connection test.', 'airfiber-centralized' )
					: __( 'The GPON OLT was added as a draft. Add its SNMP credentials, then test the connection.', 'airfiber-centralized' ),
				'tested_at'   => '',
				'onu_count'   => 0,
				'valid_count' => 0,
			)
		);
		update_option( self::GPON_SEEDED_OPTION, (int) $post_id, false );

		if ( $ready && ! get_option( self::GPON_TESTED_OPTION, false ) && ! wp_next_scheduled( self::GPON_TEST_HOOK, array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::GPON_TEST_HOOK, array( (int) $post_id ) );
		}
	}

	public static function test_seeded_gpon( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$result = self::run_test( $post_id );
		if ( is_wp_error( $result ) ) {
			self::record_test_error( $post_id, $result );
			return;
		}
		update_option( self::GPON_TESTED_OPTION, current_time( 'mysql' ), false );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage OLT connections.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function get_nodes() {
		self::maybe_import_legacy();
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Return the published OLT profiles used by the monitoring data path.
	 *
	 * The primary profile keeps the stable "primary" reference so existing
	 * customer mappings continue to work. Additional OLTs use their CPT ID,
	 * which prevents identical PON/ONU numbers on two chassis from colliding.
	 */
	public static function monitoring_nodes() {
		$nodes       = array();
		$stored_nodes = self::get_nodes();
		$primary     = self::primary_id();
		if ( ! $primary && $stored_nodes ) {
			foreach ( $stored_nodes as $candidate ) {
				$candidate_config = self::get_config( $candidate->ID );
				if ( self::DEFAULT_EPON_HOST === $candidate_config['host'] ) {
					$primary = (int) $candidate->ID;
					break;
				}
			}
			if ( ! $primary ) {
				$primary = (int) $stored_nodes[0]->ID;
			}
			update_post_meta( $primary, self::PRIMARY_META, '1' );
		}

		foreach ( $stored_nodes as $post ) {
			$post_id = (int) $post->ID;
			if ( 'publish' !== $post->post_status || get_post_meta( $post_id, self::DISCONNECTED_META, true ) ) {
				continue;
			}

			$config          = self::get_config( $post_id );
			$is_primary      = $post_id === $primary;
			$reference       = $is_primary ? 'primary' : (string) $post_id;
			$config['name']  = $post->post_title;
			$config['enabled'] = 1;

			$nodes[ $reference ] = array(
				'id'         => $reference,
				'post_id'    => $post_id,
				'name'       => $post->post_title,
				'technology' => self::normalize_technology( $config['technology'] ),
				'primary'    => $is_primary,
				'config'     => $config,
			);
		}

		return $nodes;
	}

	/**
	 * Resolve one stored customer OLT reference to an active monitoring node.
	 */
	public static function monitoring_node( $reference ) {
		$reference = self::normalize_reference( $reference );
		$nodes     = self::monitoring_nodes();
		return isset( $nodes[ $reference ] ) ? $nodes[ $reference ] : null;
	}

	public static function normalize_reference( $reference ) {
		$reference = trim( (string) $reference );
		return '' === $reference || 'primary' === strtolower( $reference ) ? 'primary' : (string) absint( $reference );
	}

	private static function maybe_import_legacy() {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $existing || ! class_exists( 'AFC_OLT' ) ) return;

		$legacy = get_option( AFC_OLT::OPTION_KEY, null );
		if ( ! is_array( $legacy ) || empty( $legacy['host'] ) ) return;

		$config = wp_parse_args( $legacy, self::defaults() );
		$config['technology'] = 'EPON';
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => ! empty( $legacy['enabled'] ) ? 'publish' : 'draft',
				'post_title'  => ! empty( $legacy['name'] ) ? sanitize_text_field( $legacy['name'] ) : __( 'Primary OLT', 'airfiber-centralized' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) return;

		update_post_meta( $post_id, self::CONFIG_META, $config );
		update_post_meta( $post_id, self::PRIMARY_META, '1' );
		$legacy_status = get_option( AFC_OLT::LAST_STATUS_KEY, array() );
		update_post_meta(
			$post_id,
			self::DEVICE_META,
			array(
				'name'        => '',
				'description' => '',
				'test_status' => isset( $legacy_status['status'] ) && 'success' === $legacy_status['status'] ? 'success' : ( ! empty( $legacy_status ) ? 'error' : '' ),
				'message'     => isset( $legacy_status['message'] ) ? sanitize_text_field( $legacy_status['message'] ) : '',
				'tested_at'   => isset( $legacy_status['time'] ) ? sanitize_text_field( $legacy_status['time'] ) : '',
				'onu_count'   => isset( $legacy_status['count'] ) ? absint( $legacy_status['count'] ) : 0,
			)
		);
	}

	private static function get_config( $post_id ) {
		$config = get_post_meta( $post_id, self::CONFIG_META, true );
		return wp_parse_args( is_array( $config ) ? $config : array(), self::defaults() );
	}

	private static function get_device( $post_id ) {
		$device = get_post_meta( $post_id, self::DEVICE_META, true );
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

	private static function node_state( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) return 'draft';
		if ( 'publish' !== $post->post_status ) return 'draft';
		if ( get_post_meta( $post_id, self::DISCONNECTED_META, true ) ) return 'offline';

		$device = self::get_device( $post_id );
		if ( 'success' === $device['test_status'] ) return 'online';
		if ( 'error' === $device['test_status'] ) return 'error';
		return 'error';
	}

	private static function sanitize_host( $host ) {
		$host = trim( sanitize_text_field( $host ) );
		$host = preg_replace( '#^(?:https?://|udp:)#i', '', $host );
		return preg_replace( '/[^a-z0-9.\-:]/i', '', trim( $host, "/ \t\n\r\0\x0B" ) );
	}

	private static function sanitize_oid( $oid ) {
		$oid = ltrim( preg_replace( '/[^0-9.]/', '', sanitize_text_field( $oid ) ), '.' );
		return preg_match( '/^1(?:\.\d+)+$/', $oid ) ? $oid : self::defaults()['rx_oid'];
	}

	private static function encryption_key() {
		$source = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $source, true );
	}

	private static function encrypt_secret( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret || ! function_exists( 'openssl_encrypt' ) ) return '';
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $cipher ? '' : 'gcm:' . base64_encode( $iv . $tag . $cipher );
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

	private static function secret_value( $input, $key, $current ) {
		$value = isset( $input[ $key ] ) ? (string) wp_unslash( $input[ $key ] ) : '';
		if ( '' === $value || self::SAVED_SECRET_MASK === $value ) {
			return isset( $current[ $key ] ) ? $current[ $key ] : '';
		}
		return self::encrypt_secret( $value );
	}

	private static function sanitize_config( $input, $current = array() ) {
		$input = is_array( $input ) ? $input : array();
		$current = wp_parse_args( is_array( $current ) ? $current : array(), self::defaults() );
		$warning = isset( $input['warning_dbm'] ) ? (float) $input['warning_dbm'] : (float) $current['warning_dbm'];
		$critical = isset( $input['critical_dbm'] ) ? (float) $input['critical_dbm'] : (float) $current['critical_dbm'];
		if ( $warning > 0 || $warning < -50 || $critical > 0 || $critical < -50 || $critical >= $warning ) {
			$warning = -24;
			$critical = -27;
		}
		$port = isset( $input['port'] ) ? absint( $input['port'] ) : (int) $current['port'];
		if ( $port < 1 || $port > 65535 ) $port = 161;

		$technology = isset( $input['technology'] ) ? self::normalize_technology( $input['technology'] ) : self::normalize_technology( $current['technology'] );

		return array(
			'host'               => isset( $input['host'] ) ? self::sanitize_host( $input['host'] ) : $current['host'],
			'port'               => $port,
			'version'            => isset( $input['version'] ) && '2c' === $input['version'] ? '2c' : '3',
			'community'          => self::secret_value( $input, 'community', $current ),
			'security_name'      => isset( $input['security_name'] ) ? sanitize_text_field( $input['security_name'] ) : $current['security_name'],
			'auth_protocol'      => 'SHA',
			'auth_passphrase'    => self::secret_value( $input, 'auth_passphrase', $current ),
			'privacy_protocol'   => 'DES',
			'privacy_passphrase' => self::secret_value( $input, 'privacy_passphrase', $current ),
			'rx_oid'             => isset( $input['rx_oid'] ) ? self::sanitize_oid( $input['rx_oid'] ) : $current['rx_oid'],
			'warning_dbm'        => $warning,
			'critical_dbm'       => $critical,
			'cache_ttl'          => min( 900, max( 60, isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : (int) $current['cache_ttl'] ) ),
			'timeout_ms'         => min( 10000, max( 500, isset( $input['timeout_ms'] ) ? absint( $input['timeout_ms'] ) : (int) $current['timeout_ms'] ) ),
			'retries'            => min( 2, isset( $input['retries'] ) ? absint( $input['retries'] ) : (int) $current['retries'] ),
			'technology'         => $technology,
		);
	}

	private static function save_node( $post_id, $title, $config, $mode ) {
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) $title = __( 'New OLT', 'airfiber-centralized' );
		$status = 'draft';
		if ( $post_id ) {
			$current_post = get_post( $post_id );
			$status = $current_post && 'publish' === $current_post->post_status ? 'publish' : 'draft';
		}
		if ( 'draft' === $mode || 'autosave' === $mode ) $status = 'draft';
		if ( 'publish' === $mode ) $status = 'publish';

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => $status,
			'post_title'  => $title,
		);
		if ( $post_id ) $postarr['ID'] = $post_id;
		$saved_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $saved_id ) ) return $saved_id;

		update_post_meta( $saved_id, self::CONFIG_META, $config );
		if ( 'publish' === $status && ! self::primary_id() ) update_post_meta( $saved_id, self::PRIMARY_META, '1' );
		if ( 'publish' === $status && '' === self::get_device( $saved_id )['test_status'] ) {
			$device = self::get_device( $saved_id );
			$device['test_status'] = 'error';
			$device['message'] = __( 'Published, but the connection has not been tested yet.', 'airfiber-centralized' );
			update_post_meta( $saved_id, self::DEVICE_META, $device );
		}
		self::sync_primary_legacy( $saved_id );
		return (int) $saved_id;
	}

	private static function primary_id() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::PRIMARY_META,
				'meta_value'     => '1',
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function sync_primary_legacy( $post_id ) {
		if ( ! class_exists( 'AFC_OLT' ) || (int) $post_id !== self::primary_id() ) return;
		$post = get_post( $post_id );
		if ( ! $post ) return;
		$config = self::get_config( $post_id );
		$config['enabled'] = 'publish' === $post->post_status && ! get_post_meta( $post_id, self::DISCONNECTED_META, true ) ? 1 : 0;
		$config['name'] = $post->post_title;
		unset( $config['technology'] );
		update_option( AFC_OLT::OPTION_KEY, $config, false );
	}

	private static function public_node( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) return null;
		$config = self::get_config( $post_id );
		$device = self::get_device( $post_id );
		return array(
			'id'          => (int) $post_id,
			'title'       => $post->post_title,
			'post_status' => $post->post_status,
			'state'       => self::node_state( $post_id ),
			'config'      => array(
				'host'               => $config['host'],
				'port'               => (int) $config['port'],
				'version'            => $config['version'],
				'community'          => ! empty( $config['community'] ) ? self::SAVED_SECRET_MASK : '',
				'has_community'      => ! empty( $config['community'] ),
				'security_name'      => $config['security_name'],
				'auth_passphrase'    => ! empty( $config['auth_passphrase'] ) ? self::SAVED_SECRET_MASK : '',
				'has_auth'           => ! empty( $config['auth_passphrase'] ),
				'privacy_passphrase' => ! empty( $config['privacy_passphrase'] ) ? self::SAVED_SECRET_MASK : '',
				'has_privacy'        => ! empty( $config['privacy_passphrase'] ),
				'rx_oid'             => $config['rx_oid'],
				'warning_dbm'        => (float) $config['warning_dbm'],
				'critical_dbm'       => (float) $config['critical_dbm'],
				'cache_ttl'          => (int) $config['cache_ttl'],
				'timeout_ms'         => (int) $config['timeout_ms'],
				'retries'            => (int) $config['retries'],
				'technology'         => self::normalize_technology( $config['technology'] ),
			),
			'device'      => $device,
			'primary'     => (bool) get_post_meta( $post_id, self::PRIMARY_META, true ),
			'disconnected'=> (bool) get_post_meta( $post_id, self::DISCONNECTED_META, true ),
		);
	}

	private static function clean_snmp_string( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/^(?:STRING|OCTET STRING):\s*/i', '', $value );
		return trim( $value, "\"' \t\r\n" );
	}

	private static function snmp_target( $config ) {
		return 161 === (int) $config['port'] ? $config['host'] : 'udp:' . $config['host'] . ':' . (int) $config['port'];
	}

	private static function snmp_get( $config, $oid ) {
		$target = self::snmp_target( $config );
		$timeout = (int) $config['timeout_ms'] * 1000;
		$retries = (int) $config['retries'];
		if ( '2c' === $config['version'] ) {
			if ( ! function_exists( 'snmp2_get' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv2 support is not available on this server.', 'airfiber-centralized' ) );
			$community = self::decrypt_secret( $config['community'] );
			if ( '' === $community ) return new WP_Error( 'community_missing', __( 'Enter and save the read-only SNMP community first.', 'airfiber-centralized' ) );
			$value = @snmp2_get( $target, $community, $oid, $timeout, $retries );
		} else {
			if ( ! function_exists( 'snmp3_get' ) ) return new WP_Error( 'snmp_missing', __( 'PHP SNMPv3 support is not available on this server.', 'airfiber-centralized' ) );
			$auth = self::decrypt_secret( $config['auth_passphrase'] );
			$privacy = self::decrypt_secret( $config['privacy_passphrase'] );
			if ( '' === $config['security_name'] || '' === $auth || '' === $privacy ) return new WP_Error( 'credentials_missing', __( 'Enter and save the SNMPv3 username, SHA passphrase, and DES passphrase first.', 'airfiber-centralized' ) );
			$value = @snmp3_get( $target, $config['security_name'], 'authPriv', 'SHA', $auth, 'DES', $privacy, $oid, $timeout, $retries );
		}
		return false === $value ? new WP_Error( 'snmp_get_failed', __( 'The OLT did not answer the SNMP identity request.', 'airfiber-centralized' ) ) : $value;
	}

	private static function snmp_walk( $config, $oid ) {
		$rows = AFC_OLT::walk_configured_oid( $config, $oid );
		return is_wp_error( $rows )
			? new WP_Error( 'snmp_walk_failed', __( 'The OLT answered, but the configured RX OID could not be read.', 'airfiber-centralized' ) )
			: $rows;
	}

	private static function run_test( $post_id ) {
		$config = self::get_config( $post_id );
		if ( empty( $config['host'] ) ) return new WP_Error( 'host_missing', __( 'Enter the OLT IP address or hostname first.', 'airfiber-centralized' ) );

		$name_result = self::snmp_get( $config, self::SYS_NAME_OID );
		$description_result = self::snmp_get( $config, self::SYS_DESCR_OID );
		$walk = self::snmp_walk( $config, $config['rx_oid'] );
		if ( is_wp_error( $walk ) ) return $walk;

		$valid = 0;
		foreach ( $walk as $value ) {
			if ( preg_match( '/(-?\d+(?:\.\d+)?)\s*(?:dBm)?\b/i', (string) $value, $match ) ) {
				$power = (float) $match[1];
				if ( $power >= -60 && $power < -1 ) $valid++;
			}
		}
		$device_name = is_wp_error( $name_result ) ? '' : self::clean_snmp_string( $name_result );
		$description = is_wp_error( $description_result ) ? '' : self::clean_snmp_string( $description_result );
		$device = self::get_device( $post_id );
		if ( '' !== $device_name ) $device['name'] = $device_name;
		if ( '' !== $description ) $device['description'] = $description;
		$device['test_status'] = 'success';
		$device['tested_at'] = current_time( 'mysql' );
		$device['onu_count'] = count( $walk );
		$device['valid_count'] = $valid;
		$device['message'] = sprintf(
			__( 'Connected successfully. %1$d ONU row(s) returned; %2$d included readable dBm values.', 'airfiber-centralized' ),
			count( $walk ),
			$valid
		);
		update_post_meta( $post_id, self::DEVICE_META, $device );
		delete_post_meta( $post_id, self::DISCONNECTED_META );
		self::sync_primary_legacy( $post_id );
		return $device;
	}

	private static function record_test_error( $post_id, $error ) {
		$device = self::get_device( $post_id );
		$device['test_status'] = 'error';
		$device['tested_at'] = current_time( 'mysql' );
		$device['message'] = is_wp_error( $error ) ? $error->get_error_message() : __( 'Connection test failed.', 'airfiber-centralized' );
		update_post_meta( $post_id, self::DEVICE_META, $device );
		return $device;
	}

	public static function card_html( $post_id ) {
		$node = self::public_node( $post_id );
		if ( ! $node ) return '';
		$state = $node['state'];
		$config = $node['config'];
		$device = $node['device'];
		$action = 'Continue';
		if ( 'online' === $state ) $action = __( 'Disconnect', 'airfiber-centralized' );
		elseif ( 'offline' === $state ) $action = __( 'Reconnect', 'airfiber-centralized' );
		elseif ( 'draft' === $state ) $action = __( 'Continue', 'airfiber-centralized' );
		elseif ( 'error' === $state ) $action = __( 'Continue', 'airfiber-centralized' );
		$device_name = $device['name'] ? $device['name'] : __( 'OLT name not read yet', 'airfiber-centralized' );
		$version_label = '2c' === $config['version'] ? 'SNMPv2c' : 'SNMPv3';
		ob_start();
		?>
		<article class="afc-olt-card is-<?php echo esc_attr( $state ); ?>" data-afc-olt-card="<?php echo esc_attr( $post_id ); ?>" data-afc-olt-state="<?php echo esc_attr( $state ); ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr( sprintf( __( 'Edit %s', 'airfiber-centralized' ), $node['title'] ) ); ?>">
			<span class="afc-olt-card-status" aria-hidden="true"></span>
			<div class="afc-olt-card-center">
				<h3><?php echo esc_html( $node['title'] ); ?></h3>
				<p><span><?php echo esc_html( $config['technology'] ); ?></span><b><?php echo esc_html( $device_name ); ?></b></p>
			</div>
			<div class="afc-olt-card-details">
				<div><span><?php echo esc_html( $config['host'] ? $config['host'] : __( 'No host yet', 'airfiber-centralized' ) ); ?></span><span><?php echo esc_html( $version_label ); ?></span></div>
				<?php if ( ! empty( $device['tested_at'] ) ) : ?><small><?php echo esc_html( sprintf( __( 'Last test %s', 'airfiber-centralized' ), $device['tested_at'] ) ); ?></small><?php endif; ?>
				<button type="button" class="afc-olt-card-action" data-afc-olt-action="<?php echo esc_attr( $state ); ?>" data-afc-olt-id="<?php echo esc_attr( $post_id ); ?>"><?php echo esc_html( $action ); ?></button>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	public static function cards_html() {
		$nodes = self::get_nodes();
		ob_start();
		?>
		<button type="button" class="afc-olt-card afc-olt-add-card" data-afc-olt-add aria-label="<?php esc_attr_e( 'Add OLT', 'airfiber-centralized' ); ?>">
			<span class="afc-olt-add-plus" aria-hidden="true">+</span>
			<small><?php esc_html_e( 'Add OLT', 'airfiber-centralized' ); ?></small>
		</button>
		<?php
		foreach ( $nodes as $node ) echo self::card_html( $node->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return ob_get_clean();
	}

	public static function render_panel() {
		self::maybe_import_legacy();
		include AFC_PATH . 'templates/frontend-olt-manager.php';
	}

	public static function ajax_list() {
		self::authorize();
		wp_send_json_success( array( 'html' => self::cards_html() ) );
	}

	public static function ajax_get() {
		self::authorize();
		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$node = self::public_node( $post_id );
		if ( ! $node ) wp_send_json_error( array( 'message' => __( 'OLT record not found.', 'airfiber-centralized' ) ), 404 );
		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function ajax_save() {
		self::authorize();
		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $post_id && self::POST_TYPE !== get_post_type( $post_id ) ) wp_send_json_error( array( 'message' => __( 'Invalid OLT record.', 'airfiber-centralized' ) ), 400 );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'autosave';
		if ( ! in_array( $mode, array( 'autosave', 'draft', 'publish', 'keep' ), true ) ) $mode = 'autosave';
		$title = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '';
		$raw_config = isset( $_POST['config'] ) ? json_decode( wp_unslash( $_POST['config'] ), true ) : array();
		$current = $post_id ? self::get_config( $post_id ) : self::defaults();
		$config = self::sanitize_config( $raw_config, $current );
		$saved_id = self::save_node( $post_id, $title, $config, $mode );
		if ( is_wp_error( $saved_id ) ) wp_send_json_error( array( 'message' => $saved_id->get_error_message() ), 500 );
		$node = self::public_node( $saved_id );
		wp_send_json_success(
			array(
				'id'      => $saved_id,
				'node'    => $node,
				'message' => 'publish' === $node['post_status'] ? __( 'OLT published.', 'airfiber-centralized' ) : __( 'Draft saved.', 'airfiber-centralized' ),
				'saved_at'=> current_time( 'H:i:s' ),
			)
		);
	}

	public static function ajax_test() {
		self::authorize();
		/* Large GPON optical tables can legitimately take longer than PHP's default limit. */
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 90 );
		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) wp_send_json_error( array( 'message' => __( 'Save the OLT draft before testing.', 'airfiber-centralized' ) ), 400 );
		$result = self::run_test( $post_id );
		if ( is_wp_error( $result ) ) {
			$device = self::record_test_error( $post_id, $result );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'device' => $device, 'state' => self::node_state( $post_id ) ) );
		}
		wp_send_json_success( array( 'message' => $result['message'], 'device' => $result, 'state' => self::node_state( $post_id ) ) );
	}

	public static function ajax_state() {
		self::authorize();
		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$action = isset( $_POST['state_action'] ) ? sanitize_key( $_POST['state_action'] ) : '';
		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) wp_send_json_error( array( 'message' => __( 'OLT record not found.', 'airfiber-centralized' ) ), 404 );
		if ( 'disconnect' === $action ) {
			update_post_meta( $post_id, self::DISCONNECTED_META, '1' );
			self::sync_primary_legacy( $post_id );
			wp_send_json_success( array( 'message' => __( 'OLT disconnected from monitoring.', 'airfiber-centralized' ), 'state' => 'offline' ) );
		}
		if ( 'reconnect' === $action ) {
			delete_post_meta( $post_id, self::DISCONNECTED_META );
			$result = self::run_test( $post_id );
			if ( is_wp_error( $result ) ) {
				$device = self::record_test_error( $post_id, $result );
				wp_send_json_error( array( 'message' => $result->get_error_message(), 'device' => $device, 'state' => 'error' ) );
			}
			wp_send_json_success( array( 'message' => __( 'OLT reconnected.', 'airfiber-centralized' ), 'device' => $result, 'state' => 'online' ) );
		}
		wp_send_json_error( array( 'message' => __( 'Unknown OLT action.', 'airfiber-centralized' ) ), 400 );
	}
}
