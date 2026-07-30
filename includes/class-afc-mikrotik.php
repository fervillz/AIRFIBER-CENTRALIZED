<?php

defined( 'ABSPATH' ) || exit;

class AFC_MikroTik {

	const OPTION_KEY = 'afc_mikrotik_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'afc_mikrotik',
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
			'name'       => 'Main Router',
			'host'       => '',
			'username'   => '',
			'password'   => '',
			'protocol'   => 'https',
			'port'       => 443,
			'verify_ssl' => 0,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function sanitize_settings( $input ) {
		$current  = self::get_settings();
		$protocol = isset( $input['protocol'] ) && 'http' === $input['protocol'] ? 'http' : 'https';
		$host     = isset( $input['host'] ) ? trim( sanitize_text_field( $input['host'] ) ) : '';
		$host     = preg_replace( '#^https?://#i', '', $host );
		$host     = trim( $host, "/ \t\n\r\0\x0B" );
		$host     = preg_replace( '/[^a-z0-9.\-:]/i', '', $host );
		$port     = isset( $input['port'] ) ? absint( $input['port'] ) : 0;

		if ( $port < 1 || $port > 65535 ) {
			$port = 'https' === $protocol ? 443 : 80;
		}

		$password = $current['password'];
		if ( ! empty( $input['password'] ) ) {
			$password = self::encrypt_password( wp_unslash( $input['password'] ) );
		}

		return array(
			'name'       => isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : 'Main Router',
			'host'       => $host,
			'username'   => isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '',
			'password'   => $password,
			'protocol'   => $protocol,
			'port'       => $port,
			'verify_ssl' => empty( $input['verify_ssl'] ) ? 0 : 1,
		);
	}

	private static function encryption_key() {
		$source = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $source, true );
	}

	private static function encrypt_password( $password ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'afc_openssl_missing',
				__( 'The password was not saved because OpenSSL is unavailable on this server.', 'airfiber-centralized' )
			);
			return '';
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt(
			$password,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return 'gcm:' . base64_encode( $iv . $tag . $cipher );
	}

	private static function decrypt_password( $stored ) {
		if ( 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) {
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

	public static function test_connection() {
		$settings = self::get_settings();
		$password = self::decrypt_password( $settings['password'] );

		if ( empty( $settings['host'] ) || empty( $settings['username'] ) || '' === $password ) {
			return new WP_Error( 'afc_missing_credentials', __( 'Enter the router IP, username, and password first.', 'airfiber-centralized' ) );
		}

		$url = sprintf(
			'%s://%s:%d/rest/system/resource',
			$settings['protocol'],
			$settings['host'],
			$settings['port']
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $settings['username'] . ':' . $password ),
				),
				'timeout'   => 10,
				'sslverify' => (bool) $settings['verify_ssl'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error(
				'afc_mikrotik_http_error',
				sprintf( __( 'Router returned HTTP %d. Check the protocol, port, username, and password.', 'airfiber-centralized' ), $status )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'afc_invalid_response', __( 'The router responded, but the response was not valid RouterOS JSON.', 'airfiber-centralized' ) );
		}

		return $body;
	}

	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test this connection.', 'airfiber-centralized' ) );
		}

		check_admin_referer( 'afc_test_mikrotik' );
		$result = self::test_connection();

		if ( is_wp_error( $result ) ) {
			$type    = 'error';
			$message = $result->get_error_message();
		} else {
			$type    = 'success';
			$version = isset( $result['version'] ) ? $result['version'] : __( 'unknown version', 'airfiber-centralized' );
			$message = sprintf( __( 'Connected successfully to RouterOS %s.', 'airfiber-centralized' ), $version );
		}

		set_transient(
			'afc_mikrotik_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=airfiber-mikrotik' ) );
		exit;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'airfiber-centralized' ) );
		}

		$settings = self::get_settings();
		$notice   = get_transient( 'afc_mikrotik_notice_' . get_current_user_id() );
		delete_transient( 'afc_mikrotik_notice_' . get_current_user_id() );

		include AFC_PATH . 'templates/admin/mikrotik-settings.php';
	}
}
