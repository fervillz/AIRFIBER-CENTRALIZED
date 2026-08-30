<?php

namespace Airfiber\Next\Modules\Routers;

use Airfiber\Next\Secret_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Small bounded RouterOS API client for explicit read-only module requests.
 *
 * It deliberately exposes no generic browser-supplied command execution. The
 * Routers module owns a fixed allow-list of safe sentences and property lists.
 */
class RouterOS_API_Client {

	public static function test( $record, $secrets = array() ) {
		$result = self::request(
			$record,
			$secrets,
			array(
				'identity' => array(
					'words' => array( '/system/identity/print', '=.proplist=name' ),
					'limit' => 1,
				),
				'resource' => array(
					'words' => array( '/system/resource/print', '=.proplist=version,uptime,board-name,architecture-name,cpu,cpu-count,cpu-load,free-memory,total-memory' ),
					'limit' => 1,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$identity = self::first_row( $result, 'identity' );
		$resource = self::first_row( $result, 'resource' );
		if ( ! $resource ) {
			return new \WP_Error( 'afcn_routeros_empty', __( 'RouterOS connected but returned no system resource data.', 'airfiber-centralized' ) );
		}

		$name    = isset( $identity['name'] ) && '' !== $identity['name'] ? $identity['name'] : __( 'MikroTik router', 'airfiber-centralized' );
		$version = isset( $resource['version'] ) && '' !== $resource['version'] ? $resource['version'] : __( 'unknown version', 'airfiber-centralized' );
		return array(
			'state'   => 'online',
			'message' => sprintf( __( 'Connected to %1$s on RouterOS %2$s.', 'airfiber-centralized' ), $name, $version ),
			'details' => array(
				'identity'     => $name,
				'version'      => $version,
				'board_name'   => isset( $resource['board-name'] ) ? $resource['board-name'] : '',
				'architecture' => isset( $resource['architecture-name'] ) ? $resource['architecture-name'] : '',
				'cpu'          => isset( $resource['cpu'] ) ? $resource['cpu'] : '',
				'cpu_count'    => isset( $resource['cpu-count'] ) ? $resource['cpu-count'] : '',
				'cpu_load'     => isset( $resource['cpu-load'] ) ? $resource['cpu-load'] : '',
				'uptime'       => isset( $resource['uptime'] ) ? $resource['uptime'] : '',
				'free_memory'  => isset( $resource['free-memory'] ) ? $resource['free-memory'] : '',
				'total_memory' => isset( $resource['total-memory'] ) ? $resource['total-memory'] : '',
			),
		);
	}

	/**
	 * Run a server-defined group of read-only sentences over one authenticated
	 * socket. Each result is bounded before it can enter WordPress or the browser.
	 */
	public static function request( $record, $secrets, $requests ) {
		$context = self::connection_context( $record, $secrets );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$socket = self::open_socket( $context );
		if ( is_wp_error( $socket ) ) {
			return $socket;
		}

		if ( ! self::write_sentence( $socket, array( '/login', '=name=' . $context['username'], '=password=' . $context['password'] ) ) ) {
			fclose( $socket );
			return new \WP_Error( 'afcn_routeros_write', __( 'The router connection closed while signing in.', 'airfiber-centralized' ) );
		}
		$login = self::read_response( $socket, 1, false );
		if ( is_wp_error( $login ) ) {
			fclose( $socket );
			return $login;
		}

		$output = array();
		foreach ( (array) $requests as $key => $request ) {
			$key   = sanitize_key( $key );
			$words = isset( $request['words'] ) && is_array( $request['words'] ) ? $request['words'] : array();
			if ( ! $key || ! $words || 0 !== strpos( (string) $words[0], '/' ) ) {
				continue;
			}
			$limit     = isset( $request['limit'] ) ? max( 1, min( 250, (int) $request['limit'] ) ) : 100;
			$keep_last = ! empty( $request['keep_last'] );
			if ( ! self::write_sentence( $socket, array_map( 'strval', $words ) ) ) {
				fclose( $socket );
				return new \WP_Error( 'afcn_routeros_write', __( 'The router connection closed while sending the read request.', 'airfiber-centralized' ) );
			}
			$response = self::read_response( $socket, $limit, $keep_last );
			if ( is_wp_error( $response ) ) {
				fclose( $socket );
				return $response;
			}
			$output[ $key ] = $response;
		}

		fclose( $socket );
		return $output;
	}

	private static function connection_context( $record, $secrets ) {
		$record  = is_array( $record ) ? $record : array();
		$config  = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		$host    = isset( $config['host'] ) ? trim( (string) $config['host'] ) : '';
		$protocol = isset( $config['protocol'] ) && 'api-ssl' === $config['protocol'] ? 'api-ssl' : 'api';
		$port    = isset( $config['port'] ) ? (int) $config['port'] : 0;
		$port    = $port >= 1 && $port <= 65535 ? $port : ( 'api-ssl' === $protocol ? 8729 : 8728 );
		$username = isset( $config['username'] ) ? trim( (string) $config['username'] ) : '';
		$password = isset( $secrets['password'] ) && '' !== (string) $secrets['password'] ? (string) $secrets['password'] : '';

		if ( '' === $password && ! empty( $record['id'] ) ) {
			$password = Secret_Store::get( $record['id'], 'password', '' );
		}
		if ( '' === $host || '' === $username || '' === $password ) {
			return new \WP_Error( 'afcn_routeros_credentials', __( 'Enter the router host, username, and password first.', 'airfiber-centralized' ) );
		}

		$timeout = isset( $config['timeout_ms'] ) && is_numeric( $config['timeout_ms'] ) ? (int) $config['timeout_ms'] : 5000;
		return array(
			'host'       => $host,
			'port'       => $port,
			'protocol'   => $protocol,
			'username'   => $username,
			'password'   => $password,
			'verify_ssl' => ! empty( $config['verify_ssl'] ) && '0' !== (string) $config['verify_ssl'],
			'timeout_ms' => max( 1000, min( 10000, $timeout ) ),
		);
	}

	private static function open_socket( $context ) {
		$ssl       = 'api-ssl' === $context['protocol'];
		$host      = $context['host'];
		$dial_host = false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ? '[' . $host . ']' : $host;
		$stream    = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => $ssl && $context['verify_ssl'],
					'verify_peer_name' => $ssl && $context['verify_ssl'],
					'peer_name'        => $host,
					'SNI_enabled'      => true,
				),
			)
		);
		$target    = ( $ssl ? 'tls://' : 'tcp://' ) . $dial_host . ':' . $context['port'];
		$timeout   = $context['timeout_ms'] / 1000;
		$socket    = @stream_socket_client( $target, $error_number, $error_message, $timeout, STREAM_CLIENT_CONNECT, $stream );
		if ( ! is_resource( $socket ) ) {
			$message = $error_message ? sanitize_text_field( $error_message ) : __( 'Connection failed.', 'airfiber-centralized' );
			return new \WP_Error( 'afcn_routeros_socket', sprintf( __( 'Could not reach the router: %s', 'airfiber-centralized' ), $message ) );
		}

		$seconds      = max( 1, (int) floor( $context['timeout_ms'] / 1000 ) );
		$microseconds = ( $context['timeout_ms'] % 1000 ) * 1000;
		stream_set_timeout( $socket, $seconds, $microseconds );
		return $socket;
	}

	private static function first_row( $responses, $key ) {
		return isset( $responses[ $key ]['rows'][0] ) && is_array( $responses[ $key ]['rows'][0] ) ? $responses[ $key ]['rows'][0] : array();
	}

	private static function write_sentence( $socket, $words ) {
		foreach ( $words as $word ) {
			$word = (string) $word;
			if ( ! self::write_all( $socket, self::encode_length( strlen( $word ) ) . $word ) ) {
				return false;
			}
		}
		return self::write_all( $socket, chr( 0 ) );
	}

	private static function write_all( $socket, $data ) {
		$written = 0;
		$length  = strlen( $data );
		while ( $written < $length ) {
			$chunk = fwrite( $socket, substr( $data, $written ) );
			if ( false === $chunk || 0 === $chunk ) {
				return false;
			}
			$written += $chunk;
		}
		return true;
	}

	private static function encode_length( $length ) {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		if ( $length < 0x4000 ) {
			return pack( 'n', $length | 0x8000 );
		}
		if ( $length < 0x200000 ) {
			return chr( ( $length >> 16 ) | 0xC0 ) . pack( 'n', $length & 0xFFFF );
		}
		if ( $length < 0x10000000 ) {
			return pack( 'N', $length | 0xE0000000 );
		}
		return chr( 0xF0 ) . pack( 'N', $length );
	}

	private static function read_response( $socket, $max_rows, $keep_last ) {
		$rows      = array();
		$current   = array();
		$truncated = false;

		while ( ! feof( $socket ) ) {
			$word = self::read_word( $socket );
			if ( false === $word ) {
				$meta = stream_get_meta_data( $socket );
				return new \WP_Error( ! empty( $meta['timed_out'] ) ? 'afcn_routeros_timeout' : 'afcn_routeros_closed', ! empty( $meta['timed_out'] ) ? __( 'The router request timed out.', 'airfiber-centralized' ) : __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' ) );
			}
			if ( '' === $word ) {
				$type = isset( $current[0] ) ? $current[0] : '';
				if ( '!trap' === $type || '!fatal' === $type ) {
					$message = isset( $current['message'] ) ? sanitize_text_field( $current['message'] ) : __( 'RouterOS rejected the request.', 'airfiber-centralized' );
					return new \WP_Error( 'afcn_routeros_trap', $message );
				}
				if ( '!re' === $type ) {
					unset( $current[0] );
					$current = self::sanitize_row( $current );
					if ( count( $rows ) < $max_rows ) {
						$rows[] = $current;
					} elseif ( $keep_last ) {
						array_shift( $rows );
						$rows[] = $current;
						$truncated = true;
					} else {
						$truncated = true;
					}
				}
				if ( '!done' === $type ) {
					return array( 'rows' => $rows, 'truncated' => $truncated );
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

		return new \WP_Error( 'afcn_routeros_closed', __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' ) );
	}

	private static function sanitize_row( $row ) {
		$output = array();
		foreach ( array_slice( (array) $row, 0, 60, true ) as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$output[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
		}
		return $output;
	}

	private static function read_word( $socket ) {
		$first = self::read_exact( $socket, 1 );
		if ( false === $first ) {
			return false;
		}
		$byte = ord( $first );
		if ( 0 === $byte ) {
			return '';
		}
		if ( $byte < 0x80 ) {
			$length = $byte;
		} elseif ( $byte < 0xC0 ) {
			$tail = self::read_exact( $socket, 1 );
			if ( false === $tail ) { return false; }
			$length = ( ( $byte & 0x3F ) << 8 ) + ord( $tail );
		} elseif ( $byte < 0xE0 ) {
			$tail = self::read_exact( $socket, 2 );
			if ( false === $tail ) { return false; }
			$data   = unpack( 'n', $tail );
			$length = ( ( $byte & 0x1F ) << 16 ) + $data[1];
		} elseif ( $byte < 0xF0 ) {
			$tail = self::read_exact( $socket, 3 );
			if ( false === $tail ) { return false; }
			$data   = unpack( 'N', chr( $byte & 0x0F ) . $tail );
			$length = $data[1];
		} else {
			$tail = self::read_exact( $socket, 4 );
			if ( false === $tail ) { return false; }
			$data   = unpack( 'N', $tail );
			$length = $data[1];
		}
		return $length > 0 ? self::read_exact( $socket, $length ) : '';
	}

	private static function read_exact( $socket, $length ) {
		$data = '';
		while ( strlen( $data ) < $length && ! feof( $socket ) ) {
			$chunk = fread( $socket, $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$data .= $chunk;
		}
		return strlen( $data ) === $length ? $data : false;
	}
}
