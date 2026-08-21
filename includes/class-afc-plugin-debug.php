<?php
/**
 * Private, short-lived diagnostics for the frontend operations app.
 */
defined( 'ABSPATH' ) || exit;

class AFC_Plugin_Debug {
	const NONCE          = 'afc_plugin_debug';
	const AJAX_ACTION    = 'afc_plugin_debug_log';
	const CLEANUP_HOOK   = 'afc_plugin_debug_cleanup';
	const RETENTION      = DAY_IN_SECONDS;
	const MAX_EVENTS     = 40;
	const MAX_FILE_BYTES = 1048576;
	const MAX_TOTAL_BYTES = 10485760;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
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

		$asset = AFC_PATH . 'assets/js/frontend-debug.js';
		wp_enqueue_script(
			'afc-plugin-debug',
			AFC_URL . 'assets/js/frontend-debug.js',
			array(),
			file_exists( $asset ) ? (string) filemtime( $asset ) : AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-plugin-debug',
			'afcPluginDebug',
			array(
				'enabled'   => true,
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'action'    => self::AJAX_ACTION,
				'maxEvents' => 250,
				'version'   => AFC_VERSION,
			)
		);
	}

	public static function handle_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		$raw    = isset( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : '[]';
		$events = json_decode( $raw, true );
		if ( ! is_array( $events ) ) {
			wp_send_json_error( array( 'message' => 'Invalid diagnostics payload.' ), 400 );
		}

		$events = array_slice( $events, 0, self::MAX_EVENTS );
		self::cleanup();
		$written = self::write_events( $events );
		if ( false === $written ) {
			wp_send_json_error( array( 'message' => 'Diagnostics log is unavailable.' ), 500 );
		}

		wp_send_json_success( array( 'accepted' => $written ) );
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public static function cleanup() {
		$directory = self::directory();
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$cutoff = time() - self::RETENTION;
		$files  = glob( trailingslashit( $directory ) . 'afc-debug-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$kept = array();
		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( is_file( $file ) ) {
				$kept[] = $file;
			}
		}

		usort(
			$kept,
			function ( $left, $right ) {
				return filemtime( $left ) - filemtime( $right );
			}
		);
		$total = array_sum( array_map( 'filesize', $kept ) );
		foreach ( $kept as $file ) {
			if ( $total <= self::MAX_TOTAL_BYTES ) {
				break;
			}
			$size = filesize( $file );
			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$total -= $size;
			}
		}
	}

	private static function write_events( $events ) {
		if ( empty( $events ) ) {
			return 0;
		}

		$directory = self::directory();
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$file = self::log_file();
		if ( file_exists( $file ) && filesize( $file ) >= self::MAX_FILE_BYTES ) {
			return false;
		}

		$handle = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}

		$written = 0;
		if ( flock( $handle, LOCK_EX ) ) {
			foreach ( $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$record = array(
					'time'    => gmdate( 'c' ),
					'user_id' => get_current_user_id(),
					'event'   => self::sanitize_string( isset( $event['event'] ) ? $event['event'] : 'unknown', 80 ),
					'client'  => self::sanitize_value( isset( $event['client'] ) ? $event['client'] : array(), 0, 'client' ),
					'data'    => self::sanitize_value( isset( $event['data'] ) ? $event['data'] : array(), 0, 'data' ),
				);
				$line = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
				if ( is_string( $line ) && fwrite( $handle, $line . PHP_EOL ) ) {
					$written++;
				}
			}
			flock( $handle, LOCK_UN );
		}
		fclose( $handle );
		@chmod( $file, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $written;
	}

	private static function sanitize_value( $value, $depth, $key ) {
		if ( preg_match( '/pass|secret|token|nonce|cookie|authorization|community/i', (string) $key ) ) {
			return '[redacted]';
		}
		if ( $depth > 4 ) {
			return '[depth-limit]';
		}
		if ( is_array( $value ) ) {
			$output = array();
			$count  = 0;
			foreach ( $value as $item_key => $item ) {
				if ( $count++ >= 40 ) {
					$output['_truncated'] = true;
					break;
				}
				$clean_key            = self::sanitize_string( (string) $item_key, 80 );
				$output[ $clean_key ] = self::sanitize_value( $item, $depth + 1, $clean_key );
			}
			return $output;
		}
		if ( is_object( $value ) ) {
			return self::sanitize_value( get_object_vars( $value ), $depth + 1, $key );
		}
		if ( is_string( $value ) ) {
			return self::sanitize_string( $value, 1600 );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_null( $value ) ) {
			return $value;
		}
		return self::sanitize_string( (string) $value, 1600 );
	}

	private static function sanitize_string( $value, $limit ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}
		return substr( $value, 0, $limit );
	}

	private static function directory() {
		return trailingslashit( AFC_PATH ) . 'debug';
	}

	private static function log_file() {
		$bucket = gmdate( 'Ymd-H' );
		$suffix = substr( hash_hmac( 'sha256', $bucket, wp_salt( 'auth' ) ), 0, 16 );
		return trailingslashit( self::directory() ) . 'afc-debug-' . $bucket . '-' . $suffix . '.log';
	}
}
