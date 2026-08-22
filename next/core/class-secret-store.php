<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted storage for connection credentials.
 *
 * Secrets never live in module manifests, module options, task payloads or
 * connection records. Encryption is derived from the site's WordPress salts.
 */
class Secret_Store {
	const OPTION_SECRETS = 'afcn_connection_secrets_v1';

	public static function set_many( $connection_id, $secrets ) {
		$connection_id = self::clean_id( $connection_id );
		if ( ! $connection_id || ! is_array( $secrets ) ) {
			return new \WP_Error( 'afcn_secret_input', __( 'Invalid connection secret data.', 'airfiber-centralized' ) );
		}

		$store = self::raw_store();
		$row   = isset( $store[ $connection_id ] ) && is_array( $store[ $connection_id ] ) ? $store[ $connection_id ] : array();

		foreach ( $secrets as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key ) {
				continue;
			}
			if ( null === $value || '' === (string) $value ) {
				continue;
			}

			$encrypted = self::encrypt( (string) $value );
			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}
			$row[ $key ] = $encrypted;
		}

		$store[ $connection_id ] = $row;
		update_option( self::OPTION_SECRETS, $store, false );
		return true;
	}

	public static function get( $connection_id, $key, $default = '' ) {
		$connection_id = self::clean_id( $connection_id );
		$key           = sanitize_key( $key );
		$store         = self::raw_store();
		if ( ! $connection_id || ! $key || empty( $store[ $connection_id ][ $key ] ) ) {
			return $default;
		}
		$value = self::decrypt( $store[ $connection_id ][ $key ] );
		return is_wp_error( $value ) ? $default : $value;
	}

	public static function all_for( $connection_id ) {
		$connection_id = self::clean_id( $connection_id );
		$store         = self::raw_store();
		if ( ! $connection_id || empty( $store[ $connection_id ] ) || ! is_array( $store[ $connection_id ] ) ) {
			return array();
		}

		$output = array();
		foreach ( $store[ $connection_id ] as $key => $encrypted ) {
			$value = self::decrypt( $encrypted );
			if ( ! is_wp_error( $value ) ) {
				$output[ sanitize_key( $key ) ] = $value;
			}
		}
		return $output;
	}

	public static function delete( $connection_id, $key = '' ) {
		$connection_id = self::clean_id( $connection_id );
		$key           = sanitize_key( $key );
		$store         = self::raw_store();
		if ( ! $connection_id || ! isset( $store[ $connection_id ] ) ) {
			return true;
		}

		if ( $key ) {
			unset( $store[ $connection_id ][ $key ] );
			if ( empty( $store[ $connection_id ] ) ) {
				unset( $store[ $connection_id ] );
			}
		} else {
			unset( $store[ $connection_id ] );
		}

		update_option( self::OPTION_SECRETS, $store, false );
		return true;
	}

	private static function raw_store() {
		$value = get_option( self::OPTION_SECRETS, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function clean_id( $value ) {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value ) );
		return substr( $value, 0, 80 );
	}

	private static function key() {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|airfiber-next-connection-secrets';
		return hash( 'sha256', $material, true );
	}

	private static function encrypt( $plaintext ) {
		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				return 's1:' . base64_encode( $nonce . $cipher );
			} catch ( \Throwable $error ) {
				Debug_Logger::warning( 'Connection secret encryption failed.', array( 'engine' => 'sodium' ) );
			}
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $cipher && $tag ) {
					return 'o1:' . base64_encode( $iv . $tag . $cipher );
				}
			} catch ( \Throwable $error ) {
				Debug_Logger::warning( 'Connection secret encryption failed.', array( 'engine' => 'openssl' ) );
			}
		}

		return new \WP_Error( 'afcn_secret_crypto', __( 'This server does not provide a supported encryption engine for connection credentials.', 'airfiber-centralized' ) );
	}

	private static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		$key     = self::key();

		if ( 0 === strpos( $encoded, 's1:' ) ) {
			$raw = base64_decode( substr( $encoded, 3 ), true );
			if ( false === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) || ! defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
				return new \WP_Error( 'afcn_secret_decrypt', __( 'The connection credential could not be decrypted.', 'airfiber-centralized' ) );
			}
			$nonce_size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $raw ) <= $nonce_size ) {
				return new \WP_Error( 'afcn_secret_decrypt', __( 'The connection credential could not be decrypted.', 'airfiber-centralized' ) );
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, $nonce_size ), substr( $raw, 0, $nonce_size ), $key );
			return false === $plain ? new \WP_Error( 'afcn_secret_decrypt', __( 'The connection credential could not be decrypted.', 'airfiber-centralized' ) ) : $plain;
		}

		if ( 0 === strpos( $encoded, 'o1:' ) ) {
			$raw = base64_decode( substr( $encoded, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 || ! function_exists( 'openssl_decrypt' ) ) {
				return new \WP_Error( 'afcn_secret_decrypt', __( 'The connection credential could not be decrypted.', 'airfiber-centralized' ) );
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? new \WP_Error( 'afcn_secret_decrypt', __( 'The connection credential could not be decrypted.', 'airfiber-centralized' ) ) : $plain;
		}

		return new \WP_Error( 'afcn_secret_format', __( 'The connection credential uses an unknown storage format.', 'airfiber-centralized' ) );
	}
}
