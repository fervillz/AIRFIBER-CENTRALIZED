<?php

namespace Airfiber\Next\Modules\Payments;

use Airfiber\Next\Secret_Store;
defined( 'ABSPATH' ) || exit;

/**
 * Narrow RouterOS transport owned by Payments.
 *
 * Reads reuse the Router module's bounded client. The only write exposed here
 * is replacing the comment on one already-verified PPP secret.
 */
class Payments_RouterOS_Client {

	/**
	 * Read a bounded PPP-secret inventory for the Payments search index.
	 *
	 * Raw comments exist only for this request and are never persisted by this
	 * transport. The Payments module converts them to a safe index before cache.
	 */
	public static function inventory( $record ) {
		$context = self::connection_context( $record );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$socket = self::open_socket( $context );
		if ( is_wp_error( $socket ) ) {
			return $socket;
		}

		$login = self::login( $socket, $context );
		if ( is_wp_error( $login ) ) {
			fclose( $socket );
			return $login;
		}

		$ok = self::write_sentence(
			$socket,
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled,remote-address',
			)
		);
		if ( ! $ok ) {
			fclose( $socket );
			return new \WP_Error( 'afcn_payment_router_write', __( 'The router connection closed while reading PPP accounts.', 'airfiber-centralized' ) );
		}

		$result = self::read_rows( $socket, 5000 );
		fclose( $socket );
		return $result;
	}

	public static function secret( $record, $account ) {
		$account = substr( sanitize_text_field( (string) $account ), 0, 120 );
		if ( '' === $account ) {
			return new \WP_Error( 'afcn_payment_account', __( 'The PPP account is missing.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$context = self::connection_context( $record );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$socket = self::open_socket( $context );
		if ( is_wp_error( $socket ) ) {
			return $socket;
		}

		$login = self::login( $socket, $context );
		if ( is_wp_error( $login ) ) {
			fclose( $socket );
			return $login;
		}

		$ok = self::write_sentence(
			$socket,
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled,remote-address',
				'?name=' . $account,
			)
		);
		if ( ! $ok ) {
			fclose( $socket );
			return new \WP_Error( 'afcn_payment_router_write', __( 'The router connection closed while verifying the PPP account.', 'airfiber-centralized' ) );
		}

		$result = self::read_rows( $socket, 2 );
		fclose( $socket );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( (array) $result['rows'] as $row ) {
			if ( isset( $row['name'] ) && (string) $row['name'] === $account ) {
				return $row;
			}
		}
		return new \WP_Error( 'afcn_payment_account_missing', __( 'The PPP account no longer exists on this router.', 'airfiber-centralized' ), array( 'status' => 404 ) );
	}

	public static function set_payment_comment( $record, $secret_id, $comment ) {
		$secret_id = substr( sanitize_text_field( (string) $secret_id ), 0, 120 );
		$comment   = substr( (string) $comment, 0, 8000 );
		if ( '' === $secret_id ) {
			return new \WP_Error( 'afcn_payment_secret', __( 'The PPP secret id is missing.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$context = self::connection_context( $record );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$socket = self::open_socket( $context );
		if ( is_wp_error( $socket ) ) {
			return $socket;
		}

		$login = self::login( $socket, $context );
		if ( is_wp_error( $login ) ) {
			fclose( $socket );
			return $login;
		}

		$ok = self::write_sentence(
			$socket,
			array(
				'/ppp/secret/set',
				'=.id=' . $secret_id,
				'=comment=' . $comment,
			)
		);
		if ( ! $ok ) {
			fclose( $socket );
			return new \WP_Error( 'afcn_payment_router_write', __( 'The router connection closed while recording the payment.', 'airfiber-centralized' ) );
		}

		$result = self::read_done( $socket );
		fclose( $socket );
		return is_wp_error( $result ) ? $result : true;
	}

	private static function connection_context( $record ) {
		$record   = is_array( $record ) ? $record : array();
		$config   = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		$host     = isset( $config['host'] ) ? trim( (string) $config['host'] ) : '';
		$protocol = isset( $config['protocol'] ) && 'api-ssl' === $config['protocol'] ? 'api-ssl' : 'api';
		$port     = isset( $config['port'] ) ? (int) $config['port'] : 0;
		$port     = $port >= 1 && $port <= 65535 ? $port : ( 'api-ssl' === $protocol ? 8729 : 8728 );
		$username = isset( $config['username'] ) ? trim( (string) $config['username'] ) : '';
		$password = ! empty( $record['id'] ) ? Secret_Store::get( $record['id'], 'password', '' ) : '';

		if ( '' === $host || '' === $username || '' === $password ) {
			return new \WP_Error( 'afcn_payment_router_credentials', __( 'The router connection is missing its host, username, or password.', 'airfiber-centralized' ) );
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
		$target  = ( $ssl ? 'tls://' : 'tcp://' ) . $dial_host . ':' . $context['port'];
		$timeout = $context['timeout_ms'] / 1000;
		$socket  = @stream_socket_client( $target, $error_number, $error_message, $timeout, STREAM_CLIENT_CONNECT, $stream );
		if ( ! is_resource( $socket ) ) {
			$message = $error_message ? sanitize_text_field( $error_message ) : __( 'Connection failed.', 'airfiber-centralized' );
			return new \WP_Error( 'afcn_payment_router_socket', sprintf( __( 'Could not reach the router: %s', 'airfiber-centralized' ), $message ) );
		}

		$seconds      = max( 1, (int) floor( $context['timeout_ms'] / 1000 ) );
		$microseconds = ( $context['timeout_ms'] % 1000 ) * 1000;
		stream_set_timeout( $socket, $seconds, $microseconds );
		return $socket;
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

	private static function login( $socket, $context ) {
		if ( ! self::write_sentence( $socket, array( '/login', '=name=' . $context['username'], '=password=' . $context['password'] ) ) ) {
			return new \WP_Error( 'afcn_payment_router_write', __( 'The router connection closed while signing in.', 'airfiber-centralized' ) );
		}
		return self::read_done( $socket );
	}

	private static function read_rows( $socket, $max_rows ) {
		$rows      = array();
		$current   = array();
		$truncated = false;
		$max_rows  = max( 1, min( 5000, (int) $max_rows ) );

		while ( ! feof( $socket ) ) {
			$word = self::read_word( $socket );
			if ( false === $word ) {
				$meta = stream_get_meta_data( $socket );
				return new \WP_Error(
					! empty( $meta['timed_out'] ) ? 'afcn_payment_router_timeout' : 'afcn_payment_router_closed',
					! empty( $meta['timed_out'] ) ? __( 'The router request timed out.', 'airfiber-centralized' ) : __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' )
				);
			}

			if ( '' === $word ) {
				$type = isset( $current[0] ) ? $current[0] : '';
				if ( '!trap' === $type || '!fatal' === $type ) {
					$message = isset( $current['message'] ) ? sanitize_text_field( $current['message'] ) : __( 'RouterOS rejected the payment request.', 'airfiber-centralized' );
					return new \WP_Error( 'afcn_payment_router_trap', $message );
				}
				if ( '!re' === $type ) {
					unset( $current[0] );
					if ( count( $rows ) >= $max_rows ) {
						$truncated = true;
						return array( 'rows' => $rows, 'truncated' => $truncated );
					}
					$rows[] = self::sanitize_payment_row( $current );
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

		return new \WP_Error( 'afcn_payment_router_closed', __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' ) );
	}

	private static function sanitize_payment_row( $row ) {
		$allowed = array( '.id', 'name', 'profile', 'comment', 'disabled', 'remote-address' );
		$output  = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $row[ $key ] ) || is_array( $row[ $key ] ) || is_object( $row[ $key ] ) ) {
				continue;
			}
			if ( 'comment' === $key ) {
				$output[ $key ] = substr( sanitize_textarea_field( (string) $row[ $key ] ), 0, 8000 );
			} else {
				$output[ $key ] = substr( sanitize_text_field( (string) $row[ $key ] ), 0, 500 );
			}
		}
		return $output;
	}

	private static function read_done( $socket ) {
		$current = array();
		while ( ! feof( $socket ) ) {
			$word = self::read_word( $socket );
			if ( false === $word ) {
				$meta = stream_get_meta_data( $socket );
				return new \WP_Error(
					! empty( $meta['timed_out'] ) ? 'afcn_payment_router_timeout' : 'afcn_payment_router_closed',
					! empty( $meta['timed_out'] ) ? __( 'The router request timed out.', 'airfiber-centralized' ) : __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' )
				);
			}
			if ( '' === $word ) {
				$type = isset( $current[0] ) ? $current[0] : '';
				if ( '!trap' === $type || '!fatal' === $type ) {
					$message = isset( $current['message'] ) ? sanitize_text_field( $current['message'] ) : __( 'RouterOS rejected the payment update.', 'airfiber-centralized' );
					return new \WP_Error( 'afcn_payment_router_trap', $message );
				}
				if ( '!done' === $type ) {
					return true;
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
		return new \WP_Error( 'afcn_payment_router_closed', __( 'The router closed the connection unexpectedly.', 'airfiber-centralized' ) );
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
