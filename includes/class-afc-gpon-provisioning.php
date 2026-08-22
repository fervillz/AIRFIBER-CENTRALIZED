<?php

defined( 'ABSPATH' ) || exit;

/**
 * Guarded VSOL GPON ONU inspection and provisioning.
 *
 * SNMP remains read-only. Configuration uses the same authenticated HTTPS
 * actions as the V1600 web interface, constrained to this allow-list.
 */
class AFC_GPON_Provisioning {

	const NONCE        = 'afc_gpon_provisioning';
	const PREVIEW_TTL  = 600;
	const AUDIT_OPTION = 'afc_gpon_provision_audit_v1';

	private static $legacy_tls_host = '';

	private static $allowed_paths = array(
		'/action/main',
		'/action/rtc',
		'/action/gpononumanualadd',
		'/action/gpononuauthinfo',
		'/action/gpononudetail',
		'/action/onuOverview',
		'/action/gpononuTr069',
		'/action/gpononuSeparTcont',
		'/action/gpononuSeparGemport',
		'/action/gpononuSeparService',
		'/action/gpononuSeparServicePort',
	);

	public static function init() {
		add_action( 'wp_ajax_afc_gpon_onu_settings', array( __CLASS__, 'ajax_settings' ) );
		add_action( 'wp_ajax_afc_gpon_provision_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_gpon_provision_execute', array( __CLASS__, 'ajax_execute' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1060 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1060 );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ), 80 );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal' ), 80 );
	}

	private static function is_app_screen( $hook_suffix = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( is_admin() ) {
			return 'toplevel_page_airfiber-centralized' === (string) $hook_suffix;
		}
		return class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request();
	}

	public static function enqueue_assets( $hook_suffix = '' ) {
		if ( ! self::is_app_screen( $hook_suffix ) ) {
			return;
		}

		$css = AFC_PATH . 'assets/css/gpon-onu-settings.css';
		$js  = AFC_PATH . 'assets/js/gpon-onu-settings.js';
		wp_enqueue_style(
			'afc-gpon-onu-settings',
			AFC_URL . 'assets/css/gpon-onu-settings.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : AFC_VERSION
		);
		wp_enqueue_script(
			'afc-gpon-onu-settings',
			AFC_URL . 'assets/js/gpon-onu-settings.js',
			array( 'jquery' ),
			file_exists( $js ) ? (string) filemtime( $js ) : AFC_VERSION,
			true
		);

		$nodes = array();
		foreach ( AFC_OLT::monitoring_nodes() as $reference => $node ) {
			if ( 'GPON' !== strtoupper( isset( $node['technology'] ) ? $node['technology'] : '' ) ) {
				continue;
			}
			$nodes[] = array(
				'id'   => (string) $reference,
				'name' => isset( $node['name'] ) ? $node['name'] : __( 'GPON OLT', 'airfiber-centralized' ),
				'host' => isset( $node['config']['host'] ) ? $node['config']['host'] : '',
			);
		}

		wp_localize_script(
			'afc-gpon-onu-settings',
			'afcGPONProvisioning',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'nodes'   => $nodes,
			)
		);
	}

	public static function render_modal() {
		static $rendered = false;
		if ( $rendered || ! self::is_app_screen( isset( $GLOBALS['hook_suffix'] ) ? $GLOBALS['hook_suffix'] : '' ) ) {
			return;
		}
		$rendered = true;
		include AFC_PATH . 'templates/frontend-gpon-onu-settings.php';
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage GPON ONUs.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function normalize_serial( $serial ) {
		$serial = preg_replace( '/[^a-z0-9]/i', '', trim( (string) $serial ) );
		if ( strlen( $serial ) < 8 || strlen( $serial ) > 32 ) {
			return '';
		}
		return strtoupper( substr( $serial, 0, 4 ) ) . strtolower( substr( $serial, 4 ) );
	}

	private static function sanitize_vlans( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\s,;]+/', (string) $value );
		}
		$out = array();
		foreach ( $parts as $part ) {
			$vlan = absint( $part );
			if ( $vlan >= 1 && $vlan <= 4093 ) {
				$out[ $vlan ] = $vlan;
			}
		}
		return array_slice( array_values( $out ), 0, 12 );
	}

	private static function customer( $customer_id ) {
		$customer_id = absint( $customer_id );
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
			return new WP_Error( 'afc_customer_missing', __( 'Import or select a valid PPP customer first.', 'airfiber-centralized' ) );
		}
		return get_post( $customer_id );
	}

	private static function resolve_target( $customer_id, $input = array() ) {
		$customer = self::customer( $customer_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$stored_olt = (string) get_post_meta( $customer_id, '_afc_olt_id', true );
		$reference  = isset( $input['olt_id'] ) && '' !== (string) $input['olt_id']
			? AFC_OLT::normalize_olt_id( $input['olt_id'] )
			: AFC_OLT::normalize_olt_id( $stored_olt );
		$node = AFC_OLT::monitoring_node( $reference );
		if ( ! $node || 'GPON' !== strtoupper( isset( $node['technology'] ) ? $node['technology'] : '' ) ) {
			return new WP_Error( 'afc_gpon_node_missing', __( 'Choose a published GPON OLT connection.', 'airfiber-centralized' ) );
		}

		$stored_pon = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
		$stored_onu = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
		$pon = isset( $input['pon'] ) && '' !== (string) $input['pon'] ? absint( $input['pon'] ) : $stored_pon;
		$onu = isset( $input['onu'] ) && '' !== (string) $input['onu'] ? absint( $input['onu'] ) : $stored_onu;
		if ( $pon < 1 || $pon > 128 ) {
			return new WP_Error( 'afc_gpon_pon_invalid', __( 'Enter a valid GPON PON number.', 'airfiber-centralized' ) );
		}

		return array(
			'customer'   => $customer,
			'customer_id'=> (int) $customer_id,
			'olt_id'     => $reference,
			'node'       => $node,
			'pon'        => $pon,
			'onu'        => $onu,
			'mapped'     => $stored_onu > 0 && $stored_pon === $pon && $stored_onu === $onu && AFC_OLT::normalize_olt_id( $stored_olt ) === $reference,
		);
	}

	private static function credentials( $node ) {
		$config = isset( $node['config'] ) && is_array( $node['config'] ) ? $node['config'] : array();
		$source = isset( $config['management_credential_source'] ) ? $config['management_credential_source'] : 'router';
		if ( 'custom' !== $source ) {
			return AFC_MikroTik::get_internal_credentials();
		}

		$username = isset( $config['management_username'] ) ? trim( (string) $config['management_username'] ) : '';
		$password = ! empty( $config['management_password'] )
			? AFC_OLT_Manager::decrypt_managed_secret( $config['management_password'] )
			: '';
		if ( '' === $username || '' === $password ) {
			return new WP_Error( 'afc_gpon_credentials_missing', __( 'The dedicated OLT web login is incomplete. Open Connections and update this OLT.', 'airfiber-centralized' ) );
		}
		return array( 'username' => $username, 'password' => $password );
	}

	public static function curl_legacy_tls( $handle, $parsed_args, $url ) {
		if ( ! self::$legacy_tls_host || self::$legacy_tls_host !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return;
		}
		if ( defined( 'CURLOPT_SSL_CIPHER_LIST' ) ) {
			@curl_setopt( $handle, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:@SECLEVEL=0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function remote_request( $host, $path, $args ) {
		$plain_path = strtok( $path, '?' );
		if ( ! in_array( $plain_path, self::$allowed_paths, true ) ) {
			return new WP_Error( 'afc_gpon_action_blocked', __( 'The requested OLT action is not allow-listed.', 'airfiber-centralized' ) );
		}
		self::$legacy_tls_host = $host;
		add_action( 'http_api_curl', array( __CLASS__, 'curl_legacy_tls' ), 10, 3 );
		try {
			return wp_remote_request( 'https://' . $host . $path, $args );
		} finally {
			remove_action( 'http_api_curl', array( __CLASS__, 'curl_legacy_tls' ), 10 );
			self::$legacy_tls_host = '';
		}
	}

	private static function open_session( $node ) {
		$config = isset( $node['config'] ) ? $node['config'] : array();
		$host   = isset( $config['host'] ) ? trim( (string) $config['host'] ) : '';
		if ( ! preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
			return new WP_Error( 'afc_gpon_host_invalid', __( 'The saved OLT host is invalid.', 'airfiber-centralized' ) );
		}
		$credentials = self::credentials( $node );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$response = self::remote_request(
			$host,
			'/action/main',
			array(
				'method'      => 'POST',
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
				'body'        => array(
					'user'              => $credentials['username'],
					'pass'              => $credentials['password'],
					'verification_code' => '',
					'button'            => 'Login',
					'who'               => 100,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'afc_gpon_login_network', sprintf( __( 'Could not open the OLT management session: %s', 'airfiber-centralized' ), $response->get_error_message() ) );
		}
		$status  = wp_remote_retrieve_response_code( $response );
		$cookies = wp_remote_retrieve_cookies( $response );
		$body    = trim( wp_remote_retrieve_body( $response ) );
		$decoded = json_decode( $body, true );
		if ( $status < 200 || $status >= 400 || ! is_array( $decoded ) || ! self::vendor_success( $decoded ) ) {
			return new WP_Error( 'afc_gpon_login_failed', __( 'The OLT rejected the management login or did not create a session.', 'airfiber-centralized' ) );
		}
		/* V1600 firmware authenticates the management source address and may not
		 * emit a Set-Cookie header. Preserve cookies when present, but treat the
		 * successful vendor response as authoritative. */
		return array( 'host' => $host, 'cookies' => $cookies );
	}

	private static function request_json( $session, $path, $data = array(), $method = 'POST' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 35,
			'redirection' => 0,
			'sslverify'   => false,
			'headers'     => array( 'Accept' => 'application/json, text/plain, */*' ),
		);
		if ( ! empty( $session['cookies'] ) ) {
			$args['cookies'] = $session['cookies'];
		}
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
			$args['body'] = $data;
		} elseif ( $data ) {
			$path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . http_build_query( $data, '', '&' );
		}

		$response = self::remote_request( $session['host'], $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = trim( wp_remote_retrieve_body( $response ) );
		if ( $status < 200 || $status >= 400 ) {
			return new WP_Error( 'afc_gpon_http_error', sprintf( __( 'The OLT returned HTTP %d.', 'airfiber-centralized' ), $status ) );
		}
		if ( '' === $body ) {
			return new WP_Error( 'afc_gpon_session_expired', __( 'The OLT returned an empty response. Its login session may have expired.', 'airfiber-centralized' ) );
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : new WP_Error( 'afc_gpon_invalid_json', __( 'The OLT returned an unreadable management response.', 'airfiber-centralized' ) );
	}

	private static function clean_output( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $child_key => $child ) {
				$out[ $child_key ] = self::clean_output( $child, (string) $child_key );
			}
			return $out;
		}
		if ( preg_match( '/(?:pass|password|secret|community|credential)/i', $key ) ) {
			return '' === (string) $value ? '' : 'Saved on OLT';
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private static function response_data( $response ) {
		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
	}

	private static function onu_number_from_row( $row ) {
		foreach ( array( 'onuid', 'onu_id', 'onuId', 'ONU ID' ) as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}
			$value = (string) $row[ $key ];
			if ( preg_match( '/(?:^|[:\/])(\d+)$/', $value, $match ) ) {
				return (int) $match[1];
			}
			if ( ctype_digit( $value ) ) {
				return (int) $value;
			}
		}
		return 0;
	}

	private static function onu_group( $rows, $onu ) {
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && self::onu_number_from_row( $row ) === (int) $onu ) {
				return $row;
			}
		}
		return array();
	}

	private static function list_from_group( $response, $group_key, $child_key, $onu ) {
		$data  = self::response_data( $response );
		$group = self::onu_group( isset( $data[ $group_key ] ) ? $data[ $group_key ] : array(), $onu );
		return isset( $group[ $child_key ] ) && is_array( $group[ $child_key ] ) ? array_values( $group[ $child_key ] ) : array();
	}

	private static function read_onu( $session, $pon, $onu ) {
		$base = array( 'slotid' => 0, 'slot_select' => 0, 'ponid' => $pon, 'pon_select' => $pon, 'portid' => $pon, 'onuid' => $onu, 'pon_cfg_type' => 0 );
		$calls = array(
			'detail'       => array( '/action/gpononudetail', array_merge( $base, array( 'who' => 100 ) ) ),
			'overview'     => array( '/action/onuOverview', $base ),
			'tr069'        => array( '/action/gpononuTr069', $base ),
			'tcont_raw'    => array( '/action/gpononuSeparTcont', array( 'who' => 100, 'slotid' => 0, 'slot_select' => 0, 'pon_select' => $pon, 'portid' => $pon, 'pon_cfg_type' => 0 ) ),
			'gemport_raw'  => array( '/action/gpononuSeparGemport', array( 'who' => 100, 'slotid' => 0, 'portid' => $pon, 'pon_cfg_type' => 0 ) ),
			'service_raw'  => array( '/action/gpononuSeparService', array( 'who' => 100, 'slotid' => 0, 'slot_select' => 0, 'pon_select' => $pon, 'portid' => $pon, 'pon_cfg_type' => 0 ) ),
			'service_port_raw' => array( '/action/gpononuSeparServicePort', array( 'who' => 100, 'slotid' => 0, 'portid' => $pon, 'pon_cfg_type' => 0 ) ),
		);

		$raw    = array();
		$errors = array();
		foreach ( $calls as $key => $call ) {
			$result = self::request_json( $session, $call[0], $call[1] );
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				$raw[ $key ] = array();
			} else {
				$raw[ $key ] = $result;
			}
		}

		$detail = array();
		foreach ( isset( $raw['detail']['data']['onu_detail_info'] ) ? (array) $raw['detail']['data']['onu_detail_info'] : array() as $item ) {
			if ( ! empty( $item['property'] ) ) {
				$detail[ sanitize_key( $item['property'] ) ] = isset( $item['value'] ) ? $item['value'] : '';
			}
		}

		return array(
			'detail'        => self::clean_output( $detail ),
			'overview'      => self::clean_output( self::response_data( $raw['overview'] ) ),
			'tr069'         => self::clean_output( self::response_data( $raw['tr069'] ) ),
			'tconts'        => self::clean_output( self::list_from_group( $raw['tcont_raw'], 'tcont_info_list', 'target_tcont_list', $onu ) ),
			'gemports'      => self::clean_output( self::list_from_group( $raw['gemport_raw'], 'gemport_info_list', 'target_gemport_list', $onu ) ),
			'services'      => self::clean_output( self::list_from_group( $raw['service_raw'], 'gemport_info_list', 'target_service_list', $onu ) ),
			'service_ports' => self::clean_output( self::list_from_group( $raw['service_port_raw'], 'separ_serviceport_list', 'service_port_list', $onu ) ),
			'read_errors'   => self::clean_output( $errors ),
		);
	}

	private static function next_id( $rows, $keys, $minimum = 1, $maximum = 255 ) {
		$used = array();
		foreach ( (array) $rows as $row ) {
			foreach ( $keys as $key ) {
				if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
					$used[ (int) $row[ $key ] ] = true;
				}
			}
		}
		for ( $id = $minimum; $id <= $maximum; $id++ ) {
			if ( empty( $used[ $id ] ) ) {
				return $id;
			}
		}
		return 0;
	}

	private static function first_id( $rows, $keys ) {
		foreach ( (array) $rows as $row ) {
			foreach ( $keys as $key ) {
				if ( isset( $row[ $key ] ) && absint( $row[ $key ] ) > 0 ) {
					return absint( $row[ $key ] );
				}
			}
		}
		return 0;
	}

	private static function row_vlan( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return absint( $row[ $key ] );
			}
		}
		return 0;
	}

	private static function normalized_row_value( $row, $keys ) {
		$wanted = array_map(
			static function ( $key ) {
				return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) );
			},
			$keys
		);
		foreach ( (array) $row as $key => $value ) {
			$normalized = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) );
			if ( in_array( $normalized, $wanted, true ) ) {
				return $value;
			}
		}
		return null;
	}

	private static function row_serial( $row ) {
		$value = self::normalized_row_value( $row, array( 'sn', 'onu_sn', 'onusn', 'gponsn', 'serial', 'serial_number' ) );
		return null === $value ? '' : self::normalize_serial( $value );
	}

	private static function find_onu_by_serial( $value, $serial ) {
		if ( ! is_array( $value ) ) {
			return 0;
		}
		$row_serial = self::row_serial( $value );
		if ( $row_serial && 0 === strcasecmp( $row_serial, $serial ) ) {
			$onu = self::onu_number_from_row( $value );
			return $onu > 0 ? $onu : -1;
		}
		foreach ( $value as $child ) {
			$found = self::find_onu_by_serial( $child, $serial );
			if ( 0 !== $found ) {
				return $found;
			}
		}
		return 0;
	}

	private static function find_serial_by_onu( $value, $onu ) {
		if ( ! is_array( $value ) ) {
			return '';
		}
		if ( self::onu_number_from_row( $value ) === (int) $onu ) {
			$serial = self::row_serial( $value );
			if ( $serial ) {
				return $serial;
			}
		}
		foreach ( $value as $child ) {
			$serial = self::find_serial_by_onu( $child, $onu );
			if ( $serial ) {
				return $serial;
			}
		}
		return '';
	}

	private static function settings_serial( $settings ) {
		return self::find_serial_in_settings( $settings );
	}

	private static function find_serial_in_settings( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$serial = self::find_serial_in_settings( $child, (string) $child_key );
				if ( $serial ) {
					return $serial;
				}
			}
			return '';
		}
		$normalized = strtolower( preg_replace( '/[^a-z0-9]/i', '', $key ) );
		if ( in_array( $normalized, array( 'sn', 'onusn', 'gponsn', 'serial', 'serialnumber' ), true ) ) {
			return self::normalize_serial( $value );
		}
		return '';
	}

	private static function service_vlans( $services, $gem_id ) {
		$found = array();
		foreach ( (array) $services as $row ) {
			$row_gem = absint( self::normalized_row_value( $row, array( 'gemid', 'gemport', 'gemportid' ) ) );
			if ( $row_gem && $row_gem !== (int) $gem_id ) {
				continue;
			}
			foreach ( (array) $row as $key => $value ) {
				$normalized = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) );
				if ( false === strpos( $normalized, 'vlan' ) || false !== strpos( $normalized, 'mode' ) ) {
					continue;
				}
				if ( preg_match_all( '/\d+/', (string) $value, $matches ) ) {
					foreach ( $matches[0] as $match ) {
						$vlan = absint( $match );
						if ( $vlan >= 1 && $vlan <= 4093 ) {
							$found[ $vlan ] = $vlan;
						}
					}
				}
			}
		}
		return array_values( $found );
	}

	private static function unique_service_name( $services, $gem_id, $vlans ) {
		$base = substr( 'afc_' . absint( $gem_id ) . '_' . implode( '_', $vlans ), 0, 55 );
		$used = array();
		foreach ( (array) $services as $row ) {
			$name = self::normalized_row_value( $row, array( 'servicename', 'service_name', 'name' ) );
			if ( null !== $name ) {
				$used[ strtolower( (string) $name ) ] = true;
			}
		}
		$name = $base;
		for ( $suffix = 2; isset( $used[ strtolower( $name ) ] ); $suffix++ ) {
			$name = substr( $base, 0, 59 ) . '_' . $suffix;
		}
		return $name;
	}

	private static function make_step( $key, $label, $action, $summary, $path = '', $data = array() ) {
		return compact( 'key', 'label', 'action', 'summary', 'path', 'data' );
	}

	private static function build_plan( $target, $serial, $vlans, $session ) {
		$pon = (int) $target['pon'];
		$onu = (int) $target['onu'];
		$manual = array();
		if ( $onu < 1 ) {
			$manual = self::request_json( $session, '/action/gpononumanualadd', array( 'portid' => $pon ), 'GET' );
			if ( is_wp_error( $manual ) ) {
				return $manual;
			}
			$onu = absint( isset( $manual['data']['manual_add_onuid'] ) ? $manual['data']['manual_add_onuid'] : 0 );
		}
		if ( $onu < 1 || $onu > 128 ) {
			return new WP_Error( 'afc_gpon_onu_invalid', __( 'The OLT did not provide a usable ONU ID. Enter one manually.', 'airfiber-centralized' ) );
		}

		$existing_onu = ! empty( $target['mapped'] );
		if ( ! $existing_onu ) {
			$auth = self::request_json(
				$session,
				'/action/gpononuauthinfo',
				array( 'slotid' => 0, 'slot_select' => 0, 'portid' => $pon, 'ponid' => $pon, 'pon_select' => $pon, 'who' => 100, 'pon_cfg_type' => 0 )
			);
			if ( is_wp_error( $auth ) ) {
				return new WP_Error( 'afc_gpon_inventory_unverified', sprintf( __( 'Airfiber could not verify the ONU serial inventory, so no provisioning plan was created: %s', 'airfiber-centralized' ), $auth->get_error_message() ) );
			}
			$found_onu = self::find_onu_by_serial( $auth, $serial );
			if ( -1 === $found_onu ) {
				return new WP_Error( 'afc_gpon_existing_onu_unknown', __( 'This serial already exists on the selected PON, but the OLT did not return its ONU ID. Map it first instead of adding it again.', 'airfiber-centralized' ) );
			}
			if ( $found_onu > 0 ) {
				$onu = $found_onu;
				$existing_onu = true;
			} else {
				$occupied_serial = self::find_serial_by_onu( $auth, $onu );
				if ( $occupied_serial && 0 !== strcasecmp( $occupied_serial, $serial ) ) {
					return new WP_Error( 'afc_gpon_onu_occupied', sprintf( __( 'PON %1$d / ONU %2$d is already assigned to serial %3$s.', 'airfiber-centralized' ), $pon, $onu, $occupied_serial ) );
				}
			}
		}

		$current = $existing_onu ? self::read_onu( $session, $pon, $onu ) : array(
			'tconts' => array(), 'gemports' => array(), 'services' => array(), 'service_ports' => array(), 'read_errors' => array(),
		);
		if ( $existing_onu ) {
			$line_errors = array_intersect_key(
				isset( $current['read_errors'] ) ? $current['read_errors'] : array(),
				array_flip( array( 'tcont_raw', 'gemport_raw', 'service_raw', 'service_port_raw' ) )
			);
			if ( $line_errors ) {
				return new WP_Error( 'afc_gpon_line_unverified', __( 'Airfiber could not read every current TCONT, GEM, service, and service-port table. Provisioning was blocked to prevent duplicate line configuration.', 'airfiber-centralized' ) );
			}
		}
		if ( $existing_onu && ! empty( $target['mapped'] ) ) {
			$mapped_serial = self::settings_serial( $current );
			if ( $mapped_serial && 0 !== strcasecmp( $mapped_serial, $serial ) ) {
				return new WP_Error( 'afc_gpon_serial_mismatch', sprintf( __( 'The mapped ONU reports serial %1$s, not %2$s. Provisioning was blocked.', 'airfiber-centralized' ), $mapped_serial, $serial ) );
			}
		}
		$steps = array();
		if ( $existing_onu ) {
			$steps[] = self::make_step( 'onu', __( 'Register ONU', 'airfiber-centralized' ), 'reuse', sprintf( __( 'Serial %1$s already exists as PON %2$d / ONU %3$d.', 'airfiber-centralized' ), $serial, $pon, $onu ) );
		} else {
			$steps[] = self::make_step(
				'onu',
				__( 'Register ONU', 'airfiber-centralized' ),
				'create',
				sprintf( __( 'Add %1$s to PON %2$d as ONU %3$d.', 'airfiber-centralized' ), $serial, $pon, $onu ),
				'/action/gpononumanualadd',
				array(
					'slot_select' => 0, 'slotid' => 0, 'port_select' => $pon, 'portid' => $pon, 'onuid' => $onu,
					'authmode' => 0, 'gponsn' => $serial, 'onutype' => 'default', 's_linePro' => 'N/A', 's_srvPro' => 'N/A',
					's_alarmPro' => 'N/A', 's_priPro' => 'N/A', 's_fmtPro' => 'N/A', 'pon_cfg_type' => 0, 'who' => 0,
				)
			);
		}

		$tcont_id = self::first_id( $current['tconts'], array( 'tcont_id', 'tcontid', 'Tcont ID' ) );
		if ( $tcont_id ) {
			$steps[] = self::make_step( 'tcont', __( 'TCONT', 'airfiber-centralized' ), 'reuse', sprintf( __( 'Reuse existing TCONT %d.', 'airfiber-centralized' ), $tcont_id ) );
		} else {
			$tcont_id = self::next_id( $current['tconts'], array( 'tcont_id', 'tcontid' ) );
			if ( ! $tcont_id ) {
				return new WP_Error( 'afc_gpon_tcont_full', __( 'No free TCONT ID is available.', 'airfiber-centralized' ) );
			}
			$steps[] = self::make_step( 'tcont', __( 'TCONT', 'airfiber-centralized' ), 'create', sprintf( __( 'Create TCONT %1$d using DBA profile default1.', 'airfiber-centralized' ), $tcont_id ), '/action/gpononuSeparTcont', array(
				'who' => 0, 'slotid' => 0, 'slot_select' => 0, 'pon_select' => $pon, 'portid' => $pon, 'onulist' => $onu,
				'pon_cfg_type' => 0, 'tcontid' => $tcont_id, 'tcontname' => 'tcont_' . $tcont_id, 'dbaprofile' => 'default1',
			) );
		}

		$gem_id = self::first_id( $current['gemports'], array( 'gemport', 'gemport_id', 'gemid', 'Gemport ID' ) );
		if ( $gem_id ) {
			$steps[] = self::make_step( 'gemport', __( 'GEM port', 'airfiber-centralized' ), 'reuse', sprintf( __( 'Reuse existing GEM port %d.', 'airfiber-centralized' ), $gem_id ) );
		} else {
			$gem_id = self::next_id( $current['gemports'], array( 'gemport', 'gemport_id', 'gemid' ) );
			if ( ! $gem_id ) {
				return new WP_Error( 'afc_gpon_gemport_full', __( 'No free GEM port ID is available.', 'airfiber-centralized' ) );
			}
			$steps[] = self::make_step( 'gemport', __( 'GEM port', 'airfiber-centralized' ), 'create', sprintf( __( 'Create GEM port %1$d on TCONT %2$d.', 'airfiber-centralized' ), $gem_id, $tcont_id ), '/action/gpononuSeparGemport', array(
				'who' => 0, 'slotid' => 0, 'pon_select' => $pon, 'pon' => $pon, 'portid' => $pon, 'onulist' => $onu,
				'pon_cfg_type' => 0, 'gemid' => $gem_id, 'tcontid' => $tcont_id, 'gemname' => 'gem_' . $gem_id,
				'cos' => 'N/A', 'dstraffic' => 'default', 'uqmapid' => 'N/A', 'dqmapid' => 'N/A', 'gemstate' => 1, 'Commit' => 'Commit',
			) );
		}

		$existing_service_vlans = self::service_vlans( $current['services'], $gem_id );
		$missing_service_vlans  = array_values( array_diff( $vlans, $existing_service_vlans ) );
		if ( empty( $missing_service_vlans ) ) {
			$steps[] = self::make_step( 'service', __( 'Tagged service', 'airfiber-centralized' ), 'reuse', sprintf( __( 'GEM %1$d already carries VLANs %2$s.', 'airfiber-centralized' ), $gem_id, implode( ', ', $vlans ) ) );
		} else {
			$service_name = self::unique_service_name( $current['services'], $gem_id, $missing_service_vlans );
			$steps[] = self::make_step( 'service', __( 'Tagged service', 'airfiber-centralized' ), 'create', sprintf( __( 'Create %1$s on GEM %2$d for VLANs %3$s.', 'airfiber-centralized' ), $service_name, $gem_id, implode( ', ', $missing_service_vlans ) ), '/action/gpononuSeparService', array(
				'who' => 0, 'slotid' => 0, 'slot_select' => 0, 'pon_select' => $pon, 'portid' => $pon, 'onulist' => $onu,
				'pon_cfg_type' => 0, 'servicename' => $service_name, 'gemid' => $gem_id, 'vlanmode' => 1,
				'vlanlist' => implode( ',', $missing_service_vlans ), 'coslist' => 'N/A', 'portMode' => 0, 'portNum' => '', 'Commit' => 'Commit',
			) );
		}

		$service_port_rows = $current['service_ports'];
		foreach ( $vlans as $vlan ) {
			$exists = false;
			foreach ( $current['service_ports'] as $row ) {
				$user_vlan = self::row_vlan( $row, array( 'beginVid', 'Vlan', 'bvid', 'innerVid', 'userVid' ) );
				$translated = self::row_vlan( $row, array( 'SVlan', 'tvid', 'outerVid', 'translateVid' ) );
				if ( $user_vlan === $vlan && ( 0 === $translated || $translated === $vlan ) ) {
					$exists = true;
					break;
				}
			}
			if ( $exists ) {
				$steps[] = self::make_step( 'service-port-' . $vlan, __( 'Service port', 'airfiber-centralized' ), 'reuse', sprintf( __( 'VLAN %d already has a matching service port.', 'airfiber-centralized' ), $vlan ) );
				continue;
			}
			$service_port = self::next_id( $service_port_rows, array( 'servicePort', 'service_port', 'srvid' ), 1, 128 );
			if ( ! $service_port ) {
				return new WP_Error( 'afc_gpon_service_port_full', __( 'No free service-port ID is available.', 'airfiber-centralized' ) );
			}
			$service_port_rows[] = array( 'srvid' => $service_port );
			$steps[] = self::make_step( 'service-port-' . $vlan, __( 'Service port', 'airfiber-centralized' ), 'create', sprintf( __( 'Create service port %1$d: VLAN %2$d → %2$d.', 'airfiber-centralized' ), $service_port, $vlan ), '/action/gpononuSeparServicePort', array(
				'who' => 0, 'slotid' => 0, 'slot_select' => 0, 'pon_select' => $pon, 'portid' => $pon, 'onulist' => $onu,
				'pon_cfg_type' => 0, 'srvid' => $service_port, 'servicemode' => 0, 'gemid' => $gem_id,
				'description' => 'Airfiber VLAN ' . $vlan, 'bvid' => $vlan, 'tvid' => $vlan, 'tsvid' => 'N/A', 'Commit' => 'Commit',
			) );
		}

		return array(
			'customer_id' => (int) $target['customer_id'], 'olt_id' => (string) $target['olt_id'], 'olt_name' => $target['node']['name'],
			'pon' => $pon, 'onu' => $onu, 'serial' => $serial, 'vlans' => $vlans, 'steps' => $steps,
			'confirmation' => 'PROVISION ' . $serial, 'created_at' => time(),
		);
	}

	private static function public_plan( $plan ) {
		$copy = $plan;
		foreach ( $copy['steps'] as &$step ) {
			unset( $step['path'], $step['data'] );
		}
		return $copy;
	}

	private static function vendor_success( $response ) {
		return is_array( $response ) && ( ! isset( $response['retcode'] ) || '0' === (string) $response['retcode'] );
	}

	private static function vendor_message( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		foreach ( array( 'msg', 'message', 'result' ) as $key ) {
			if ( isset( $response['data'][ $key ] ) && is_scalar( $response['data'][ $key ] ) ) {
				return sanitize_text_field( (string) $response['data'][ $key ] );
			}
		}
		return __( 'The OLT rejected this step.', 'airfiber-centralized' );
	}

	private static function record_audit( $plan, $status, $results ) {
		$audit = get_option( self::AUDIT_OPTION, array() );
		$audit = is_array( $audit ) ? $audit : array();
		$audit[] = array(
			'time' => current_time( 'mysql' ), 'user_id' => get_current_user_id(), 'customer_id' => $plan['customer_id'],
			'olt_id' => $plan['olt_id'], 'pon' => $plan['pon'], 'onu' => $plan['onu'], 'serial' => $plan['serial'],
			'vlans' => $plan['vlans'], 'status' => $status, 'results' => self::clean_output( $results ),
		);
		update_option( self::AUDIT_OPTION, array_slice( $audit, -100 ), false );
	}

	public static function ajax_settings() {
		self::authorize();
		$target = self::resolve_target( isset( $_POST['customer_id'] ) ? $_POST['customer_id'] : 0, $_POST );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
		}
		if ( $target['onu'] < 1 ) {
			wp_send_json_success( array( 'mapped' => false, 'target' => array( 'olt_id' => $target['olt_id'], 'pon' => $target['pon'], 'onu' => 0 ) ) );
		}
		$session = self::open_session( $target['node'] );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}
		$settings = self::read_onu( $session, $target['pon'], $target['onu'] );
		wp_send_json_success( array(
			'mapped' => true,
			'target' => array( 'olt_id' => $target['olt_id'], 'olt_name' => $target['node']['name'], 'pon' => $target['pon'], 'onu' => $target['onu'] ),
			'settings' => $settings,
		) );
	}

	public static function ajax_preview() {
		self::authorize();
		$target = self::resolve_target( isset( $_POST['customer_id'] ) ? $_POST['customer_id'] : 0, $_POST );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
		}
		$serial = self::normalize_serial( isset( $_POST['serial'] ) ? wp_unslash( $_POST['serial'] ) : '' );
		$vlans  = self::sanitize_vlans( isset( $_POST['vlans'] ) ? wp_unslash( $_POST['vlans'] ) : '' );
		if ( ! $serial ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid GPON serial number. Airfiber will format the first four characters in uppercase.', 'airfiber-centralized' ) ), 400 );
		}
		if ( empty( $vlans ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one VLAN between 1 and 4093.', 'airfiber-centralized' ) ), 400 );
		}
		$session = self::open_session( $target['node'] );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}
		$plan = self::build_plan( $target, $serial, $vlans, $session );
		if ( is_wp_error( $plan ) ) {
			wp_send_json_error( array( 'message' => $plan->get_error_message() ), 502 );
		}
		$token = wp_generate_uuid4();
		set_transient( 'afc_gpon_preview_' . get_current_user_id() . '_' . $token, $plan, self::PREVIEW_TTL );
		$public = self::public_plan( $plan );
		$public['token'] = $token;
		wp_send_json_success( array( 'plan' => $public ) );
	}

	public static function ajax_execute() {
		self::authorize();
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^a-z0-9-]/i', '', wp_unslash( $_POST['token'] ) ) : '';
		$key   = 'afc_gpon_preview_' . get_current_user_id() . '_' . $token;
		$plan  = $token ? get_transient( $key ) : false;
		if ( ! is_array( $plan ) ) {
			wp_send_json_error( array( 'message' => __( 'This provisioning preview expired. Generate a new preview.', 'airfiber-centralized' ) ), 409 );
		}
		$confirmation = isset( $_POST['confirmation'] ) ? trim( wp_unslash( $_POST['confirmation'] ) ) : '';
		if ( ! hash_equals( $plan['confirmation'], $confirmation ) ) {
			wp_send_json_error( array( 'message' => __( 'The serial-specific confirmation does not match.', 'airfiber-centralized' ) ), 400 );
		}
		$target = self::resolve_target( $plan['customer_id'], array( 'olt_id' => $plan['olt_id'], 'pon' => $plan['pon'], 'onu' => $plan['onu'] ) );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
		}
		$session = self::open_session( $target['node'] );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}
		$fresh_plan = self::build_plan( $target, $plan['serial'], $plan['vlans'], $session );
		if ( is_wp_error( $fresh_plan ) ) {
			wp_send_json_error( array( 'message' => $fresh_plan->get_error_message() ), 409 );
		}
		$plan = $fresh_plan;

		$results = array();
		foreach ( $plan['steps'] as $step ) {
			if ( 'create' !== $step['action'] ) {
				$results[] = array( 'key' => $step['key'], 'status' => 'reused', 'message' => $step['summary'] );
				continue;
			}
			$response = self::request_json( $session, $step['path'], $step['data'] );
			if ( is_wp_error( $response ) || ! self::vendor_success( $response ) ) {
				$message = self::vendor_message( $response );
				$results[] = array( 'key' => $step['key'], 'status' => 'failed', 'message' => $message );
				self::record_audit( $plan, 'failed', $results );
				wp_send_json_error( array( 'message' => sprintf( __( 'Provisioning stopped at %1$s: %2$s', 'airfiber-centralized' ), $step['label'], $message ), 'results' => $results ), 502 );
			}
			$results[] = array( 'key' => $step['key'], 'status' => 'created', 'message' => $step['summary'] );
			if ( 'onu' === $step['key'] ) {
				sleep( 1 );
			}
		}

		update_post_meta( $plan['customer_id'], '_afc_olt_id', AFC_OLT::normalize_olt_id( $plan['olt_id'] ) );
		update_post_meta( $plan['customer_id'], '_afc_olt_pon', (int) $plan['pon'] );
		update_post_meta( $plan['customer_id'], '_afc_olt_onu', (int) $plan['onu'] );
		update_post_meta( $plan['customer_id'], '_afc_olt_onu_serial', $plan['serial'] );
		update_post_meta( $plan['customer_id'], '_afc_olt_vlans', implode( ',', $plan['vlans'] ) );
		delete_transient( $key );
		self::record_audit( $plan, 'success', $results );

		if ( class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			AFC_OLT_Refresh_Manager::schedule_connection_inventory_refresh( $plan['olt_id'] );
			if ( ! wp_next_scheduled( AFC_OLT_Refresh_Manager::CRON_HOOK, array( 'provision' ) ) ) {
				wp_schedule_single_event( time() + 2, AFC_OLT_Refresh_Manager::CRON_HOOK, array( 'provision' ) );
			}
		}

		wp_send_json_success( array(
			'message' => __( 'GPON ONU provisioning completed. The customer mapping was saved and an RX refresh was queued.', 'airfiber-centralized' ),
			'results' => $results,
			'target'  => array( 'olt_id' => $plan['olt_id'], 'pon' => $plan['pon'], 'onu' => $plan['onu'], 'serial' => $plan['serial'], 'vlans' => $plan['vlans'] ),
		) );
	}
}
