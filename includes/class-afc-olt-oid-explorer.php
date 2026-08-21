<?php

defined( 'ABSPATH' ) || exit;

/**
 * Live OID explorer for saved OLT connections.
 *
 * The explorer walks one requested subtree using the OLT's saved SNMP
 * credentials, groups returned OIDs by their immediate child, and returns a
 * compact tree the frontend can drill into. It is intentionally read-only.
 */
class AFC_OLT_OID_Explorer {

	const DEFAULT_ROOT = '1.3.6.1.4.1.37950';

	public static function init() {
		add_action( 'wp_ajax_afc_olt_oid_explorer_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1048 );
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

		$js_path  = AFC_PATH . 'assets/js/olt-oid-explorer.js';
		$css_path = AFC_PATH . 'assets/css/olt-oid-explorer.css';
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : AFC_VERSION;
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : AFC_VERSION;

		wp_enqueue_style(
			'afc-olt-oid-explorer',
			AFC_URL . 'assets/css/olt-oid-explorer.css',
			array( 'afc-olt-manager' ),
			$css_ver
		);
		wp_enqueue_script(
			'afc-olt-oid-explorer',
			AFC_URL . 'assets/js/olt-oid-explorer.js',
			array( 'jquery', 'afc-olt-manager' ),
			$js_ver,
			true
		);
		wp_localize_script(
			'afc-olt-oid-explorer',
			'afcOLTOIDExplorer',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( AFC_OLT_Manager::NONCE ),
				'defaultRoot' => self::DEFAULT_ROOT,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to explore OLT OIDs.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( AFC_OLT_Manager::NONCE, 'nonce' );
	}

	private static function sanitize_oid( $oid ) {
		$oid = ltrim( preg_replace( '/[^0-9.]/', '', sanitize_text_field( $oid ) ), '.' );
		return preg_match( '/^1(?:\.\d+)+$/', $oid ) ? $oid : '';
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
			'timeout_ms'         => 2500,
			'retries'            => 1,
		);
	}

	private static function get_config( $post_id ) {
		$config = get_post_meta( $post_id, AFC_OLT_Manager::CONFIG_META, true );
		return wp_parse_args( is_array( $config ) ? $config : array(), self::config_defaults() );
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

	private static function target( $config ) {
		return 161 === (int) $config['port'] ? $config['host'] : 'udp:' . $config['host'] . ':' . (int) $config['port'];
	}

	private static function clean_warning( $warning ) {
		$warning = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $warning ) );
		$warning = trim( $warning );
		return strlen( $warning ) > 300 ? substr( $warning, 0, 297 ) . '...' : $warning;
	}

	private static function capture_call( $callback, &$warning ) {
		$warning = '';
		set_error_handler(
			function ( $severity, $message ) use ( &$warning ) {
				$warning = AFC_OLT_OID_Explorer::clean_warning( $message );
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

	private static function walk( $config, $root, &$warning ) {
		$warning = '';
		$rows    = AFC_OLT::walk_configured_oid( $config, ltrim( $root, '.' ) );
		if ( is_wp_error( $rows ) ) return $rows;
		return is_array( $rows ) && $rows
			? $rows
			: new WP_Error( 'walk_failed', __( 'The OLT returned no readable OIDs under this branch.', 'airfiber-centralized' ) );
	}

	private static function numeric_oid( $oid ) {
		return trim( preg_replace( '/[^0-9.]/', '', (string) $oid ), '.' );
	}

	private static function clean_value( $value ) {
		$value = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		return strlen( $value ) > 180 ? substr( $value, 0, 177 ) . '...' : $value;
	}

	private static function power_value( $value ) {
		$text = (string) $value;
		if ( preg_match( '/(?:no such|not available|unknown|invalid|inf)/i', $text ) ) return null;
		if ( ! preg_match( '/-?\d+(?:\.\d+)?/', $text, $match ) ) return null;
		$number = (float) $match[0];
		if ( $number <= -500 && $number >= -6000 ) $number /= 100;
		elseif ( $number < -50 && $number > -500 ) $number /= 10;
		return ( $number >= -50 && $number <= -5 ) ? $number : null;
	}

	private static function known_label( $oid ) {
		$map = array(
			'1.3.6.1'                                      => 'internet',
			'1.3.6.1.2.1'                                  => 'mib-2',
			'1.3.6.1.2.1.1'                                => 'system',
			'1.3.6.1.4.1'                                  => 'enterprises',
			'1.3.6.1.4.1.37950'                            => 'VSOL enterprise',
			'1.3.6.1.4.1.37950.1.1.5'                      => 'V1600D family',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1'           => 'OPM diagnostics entry',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.3'         => 'temperature',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.4'         => 'supply voltage',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.5'         => 'TX bias',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6'         => 'TX power',
			'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7'         => 'RX power',
			'1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7'            => 'GPON RX candidate',
		);
		return isset( $map[ $oid ] ) ? $map[ $oid ] : '';
	}

	private static function group_rows( $root, $rows ) {
		$groups = array();
		$prefix = rtrim( $root, '.' ) . '.';

		foreach ( $rows as $raw_oid => $raw_value ) {
			$oid = self::numeric_oid( $raw_oid );
			if ( 0 !== strpos( $oid, $prefix ) ) continue;
			$suffix = substr( $oid, strlen( $prefix ) );
			$parts  = array_values( array_filter( explode( '.', $suffix ), 'strlen' ) );
			if ( ! $parts ) continue;

			$child_number = (string) $parts[0];
			$child_oid    = $prefix . $child_number;
			if ( ! isset( $groups[ $child_number ] ) ) {
				$groups[ $child_number ] = array(
					'number'           => $child_number,
					'oid'              => $child_oid,
					'label'            => self::known_label( $child_oid ),
					'rows'             => 0,
					'signal_rows'      => 0,
					'dbm_rows'         => 0,
					'min_depth'        => 999,
					'max_depth'        => 0,
					'samples'          => array(),
				);
			}

			$depth = count( $parts ) - 1;
			$value = self::clean_value( $raw_value );
			$groups[ $child_number ]['rows']++;
			$groups[ $child_number ]['min_depth'] = min( $groups[ $child_number ]['min_depth'], $depth );
			$groups[ $child_number ]['max_depth'] = max( $groups[ $child_number ]['max_depth'], $depth );
			if ( false !== stripos( $value, 'dbm' ) ) $groups[ $child_number ]['dbm_rows']++;
			if ( null !== self::power_value( $value ) ) $groups[ $child_number ]['signal_rows']++;
			if ( count( $groups[ $child_number ]['samples'] ) < 3 ) {
				$groups[ $child_number ]['samples'][] = array( 'oid' => $oid, 'value' => $value );
			}
		}

		uksort(
			$groups,
			function ( $a, $b ) {
				return (int) $a <=> (int) $b;
			}
		);

		$out = array();
		foreach ( $groups as $group ) {
			$group['rx_like'] = $group['signal_rows'] > 0;
			$group['leafish'] = $group['max_depth'] <= 2;
			if ( 999 === $group['min_depth'] ) $group['min_depth'] = 0;
			$out[] = $group;
			if ( count( $out ) >= 120 ) break;
		}
		return $out;
	}

	public static function ajax_scan() {
		self::authorize();
		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$root    = isset( $_POST['root'] ) ? self::sanitize_oid( wp_unslash( $_POST['root'] ) ) : '';

		if ( ! $post_id || AFC_OLT_Manager::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Save the OLT first, then open OID Explorer.', 'airfiber-centralized' ) ), 400 );
		}
		if ( '' === $root ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid numeric OID branch.', 'airfiber-centralized' ) ), 400 );
		}

		$config = self::get_config( $post_id );
		if ( empty( $config['host'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This OLT does not have an IP address yet.', 'airfiber-centralized' ) ), 400 );
		}

		$started = microtime( true );
		$warning = '';
		$rows    = self::walk( $config, $root, $warning );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error(
				array(
					'message' => $rows->get_error_message(),
					'warning' => $warning,
					'root'    => $root,
				),
				200
			);
		}

		$children = self::group_rows( $root, $rows );
		wp_send_json_success(
			array(
				'root'         => $root,
				'label'        => self::known_label( $root ),
				'rows'         => count( $rows ),
				'children'     => $children,
				'warning'      => $warning,
				'elapsed_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'version'      => '2c' === $config['version'] ? 'SNMPv2c' : 'SNMPv3',
			)
		);
	}
}
