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
			'host'       => '10.13.88.1',
			'username'   => 'admin',
			'password'   => '',
			'protocol'   => 'api',
			'port'       => 8728,
			'verify_ssl' => 0,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function sanitize_settings( $input ) {
		$current  = self::get_settings();
		$protocol = isset( $input['protocol'] ) && 'api-ssl' === $input['protocol'] ? 'api-ssl' : 'api';
		$host     = isset( $input['host'] ) ? trim( sanitize_text_field( $input['host'] ) ) : '';
		$host     = preg_replace( '#^https?://#i', '', $host );
		$host     = trim( $host, "/ \t\n\r\0\x0B" );
		$host     = preg_replace( '/[^a-z0-9.\-:]/i', '', $host );
		$port     = isset( $input['port'] ) ? absint( $input['port'] ) : 0;

		if ( $port < 1 || $port > 65535 ) {
			$port = 'api-ssl' === $protocol ? 8729 : 8728;
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

		return self::api_command( $settings, $password, array( '/system/resource/print' ) );
	}

	private static function api_command( $settings, $password, $command ) {
		$ssl     = 'api-ssl' === $settings['protocol'];
		$context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => $ssl && (bool) $settings['verify_ssl'],
					'verify_peer_name' => $ssl && (bool) $settings['verify_ssl'],
				),
			)
		);
		$target  = ( $ssl ? 'tls://' : 'tcp://' ) . $settings['host'] . ':' . $settings['port'];
		$socket  = @stream_socket_client( $target, $error_number, $error_message, 10, STREAM_CLIENT_CONNECT, $context );

		if ( ! is_resource( $socket ) ) {
			return new WP_Error(
				'afc_mikrotik_socket_error',
				sprintf( __( 'Could not reach the router: %s', 'airfiber-centralized' ), $error_message )
			);
		}

		stream_set_timeout( $socket, 10 );
		self::write_sentence( $socket, array( '/login', '=name=' . $settings['username'], '=password=' . $password ) );
		$login = self::read_response( $socket );

		if ( is_wp_error( $login ) ) {
			fclose( $socket );
			return $login;
		}

		self::write_sentence( $socket, $command );
		$response = self::read_response( $socket );
		fclose( $socket );

		return $response;
	}

	private static function write_sentence( $socket, $words ) {
		foreach ( $words as $word ) {
			$length = strlen( $word );
			if ( $length < 0x80 ) {
				$encoded = chr( $length );
			} elseif ( $length < 0x4000 ) {
				$encoded = pack( 'n', $length | 0x8000 );
			} elseif ( $length < 0x200000 ) {
				$encoded = chr( ( $length >> 16 ) | 0xC0 ) . pack( 'n', $length & 0xFFFF );
			} elseif ( $length < 0x10000000 ) {
				$encoded = pack( 'N', $length | 0xE0000000 );
			} else {
				$encoded = chr( 0xF0 ) . pack( 'N', $length );
			}
			fwrite( $socket, $encoded . $word );
		}
		fwrite( $socket, chr( 0 ) );
	}

	private static function read_response( $socket ) {
		$rows    = array();
		$current = array();

		while ( ! feof( $socket ) ) {
			$word = self::read_word( $socket );
			if ( false === $word ) {
				return new WP_Error( 'afc_mikrotik_timeout', __( 'The router connection timed out.', 'airfiber-centralized' ) );
			}
			if ( '' === $word ) {
				if ( isset( $current[0] ) && '!trap' === $current[0] ) {
					$message = isset( $current['message'] ) ? $current['message'] : __( 'RouterOS rejected the request.', 'airfiber-centralized' );
					return new WP_Error( 'afc_mikrotik_trap', $message );
				}
				if ( isset( $current[0] ) && '!re' === $current[0] ) {
					unset( $current[0] );
					$rows[] = $current;
				}
				if ( isset( $current[0] ) && '!done' === $current[0] ) {
					return 1 === count( $rows ) ? $rows[0] : $rows;
				}
				$current = array();
				continue;
			}
			if ( 0 === strpos( $word, '=' ) ) {
				$parts = explode( '=', substr( $word, 1 ), 2 );
				$current[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : '';
			} else {
				$current[] = $word;
			}
		}

		return new WP_Error( 'afc_mikrotik_closed', __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' ) );
	}

	private static function read_word( $socket ) {
		$first = fread( $socket, 1 );
		if ( '' === $first || false === $first ) {
			return false;
		}

		$byte = ord( $first );
		if ( 0 === $byte ) {
			return '';
		}
		if ( $byte < 0x80 ) {
			$length = $byte;
		} elseif ( $byte < 0xC0 ) {
			$length = ( ( $byte & 0x3F ) << 8 ) + ord( fread( $socket, 1 ) );
		} elseif ( $byte < 0xE0 ) {
			$tail   = unpack( 'n', fread( $socket, 2 ) );
			$length = ( ( $byte & 0x1F ) << 16 ) + $tail[1];
		} elseif ( $byte < 0xF0 ) {
			$tail   = unpack( 'N', chr( $byte & 0x0F ) . fread( $socket, 3 ) );
			$length = $tail[1];
		} else {
			$tail   = unpack( 'N', fread( $socket, 4 ) );
			$length = $tail[1];
		}

		$data = '';
		while ( strlen( $data ) < $length && ! feof( $socket ) ) {
			$chunk = fread( $socket, $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$data .= $chunk;
		}
		return $data;
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
