<?php

defined( 'ABSPATH' ) || exit;

/**
 * Persistent OLT refresh policy.
 *
 * Normal page visits are record/cache-only. Live OLT reads happen only when:
 * - a scheduled slot runs,
 * - the user presses the Optical refresh control, or
 * - the user presses the refresh control on one customer result.
 */
class AFC_OLT_Refresh_Manager {

	const SCHEDULE_OPTION    = 'afc_olt_refresh_schedule_v1';
	const SCHEDULE_HASH      = 'afc_olt_refresh_schedule_hash_v1';
	const LAST_REFRESH       = 'afc_olt_last_full_refresh_v1';
	const CUSTOMER_SIGNAL    = '_afc_olt_last_signal';
	const CRON_HOOK          = 'afc_olt_scheduled_refresh';
	const CONNECTION_INVENTORY_HOOK = 'afc_olt_connection_inventory_refresh';
	const NONCE              = 'afc_olt_refresh_manager';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 30 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_refresh' ), 10, 1 );
		add_action( self::CONNECTION_INVENTORY_HOOK, array( __CLASS__, 'refresh_connection_inventory' ), 10, 1 );
		add_action( 'wp_ajax_afc_save_olt_refresh_schedule', array( __CLASS__, 'ajax_save_schedule' ) );
		add_action( 'wp_ajax_afc_refresh_customer_optical', array( __CLASS__, 'ajax_refresh_customer' ) );
		add_action( 'wp_ajax_afc_flush_olt_runtime_cache', array( __CLASS__, 'ajax_flush_runtime_cache' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1048 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1048 );
	}

	public static function defaults() {
		return array(
			'enabled' => 1,
			'times'   => array( '01:00', '06:00', '12:00', '18:00' ),
		);
	}

	public static function get_schedule_settings() {
		$saved = get_option( self::SCHEDULE_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = wp_parse_args( $saved, self::defaults() );
		$out['enabled'] = empty( $out['enabled'] ) ? 0 : 1;
		$out['times']   = self::sanitize_times( isset( $out['times'] ) ? $out['times'] : array() );
		if ( empty( $out['times'] ) ) {
			$out['times'] = self::defaults()['times'];
		}
		return $out;
	}

	private static function sanitize_times( $times ) {
		if ( is_string( $times ) ) {
			$times = preg_split( '/[\s,]+/', $times );
		}
		$clean = array();
		foreach ( (array) $times as $time ) {
			$time = trim( sanitize_text_field( (string) $time ) );
			if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $matches ) ) {
				$hour   = (int) $matches[1];
				$minute = (int) $matches[2];
				if ( $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 ) {
					$clean[ sprintf( '%02d:%02d', $hour, $minute ) ] = true;
				}
			}
		}
		$times = array_keys( $clean );
		sort( $times, SORT_STRING );
		return array_slice( $times, 0, 8 );
	}

	private static function schedule_hash( $settings ) {
		return md5( wp_json_encode( $settings ) . '|' . wp_timezone_string() );
	}

	private static function next_timestamp( $clock ) {
		$parts  = array_map( 'intval', explode( ':', $clock, 2 ) );
		$hour   = isset( $parts[0] ) ? $parts[0] : 0;
		$minute = isset( $parts[1] ) ? $parts[1] : 0;
		$zone   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now    = new DateTimeImmutable( 'now', $zone );
		$target = $now->setTime( $hour, $minute, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::SCHEDULE_HASH );
	}

	public static function ensure_schedule() {
		$settings = self::get_schedule_settings();
		$hash     = self::schedule_hash( $settings );
		if ( get_option( self::SCHEDULE_HASH, '' ) === $hash ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( ! empty( $settings['enabled'] ) ) {
			foreach ( $settings['times'] as $clock ) {
				wp_schedule_event( self::next_timestamp( $clock ), 'daily', self::CRON_HOOK, array( $clock ) );
			}
		}
		update_option( self::SCHEDULE_HASH, $hash, false );
	}

	public static function cron_refresh( $clock = '' ) {
		$settings = self::get_schedule_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		self::refresh_full( 'schedule:' . sanitize_text_field( (string) $clock ) );
	}

	public static function stored_snapshot() {
		$cached = get_transient( AFC_OLT::SNAPSHOT_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['entries'] ) && isset( $cached['format'] ) && (int) $cached['format'] === (int) AFC_OLT::SNAPSHOT_FORMAT ) {
			$cached['source'] = 'cache';
			return $cached;
		}
		$last = get_option( AFC_OLT::LAST_SNAPSHOT_KEY, array() );
		if ( is_array( $last ) && ! empty( $last['entries'] ) && isset( $last['format'] ) && (int) $last['format'] === (int) AFC_OLT::SNAPSHOT_FORMAT ) {
			$last['source'] = 'record';
			$last['stale']  = false;
			return $last;
		}
		return new WP_Error( 'afc_olt_no_saved_snapshot', __( 'No saved OLT reading is available yet. Press refresh in Optical.', 'airfiber-centralized' ) );
	}

	public static function stored_inventory() {
		$cached = get_transient( AFC_OLT_Inventory::INVENTORY_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
			$cached['source'] = 'cache';
			return $cached;
		}
		$last = get_option( AFC_OLT_Inventory::INVENTORY_OPTION, array() );
		if ( is_array( $last ) && ! empty( $last['entries'] ) ) {
			$last['source'] = 'record';
			$last['stale']  = false;
			return $last;
		}
		return array( 'entries' => array(), 'count' => 0, 'source' => 'unavailable', 'stale' => false );
	}

	public static function get_last_refresh() {
		$last = get_option( self::LAST_REFRESH, array() );
		return is_array( $last ) ? $last : array();
	}

	public static function refresh_full( $source = 'manual' ) {
		/* Two OLTs plus their inventory tables can exceed the normal web limit. */
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 210 );
		$snapshot  = AFC_OLT::get_snapshot( true );
		$inventory = AFC_OLT_Inventory::get_inventory( true );
		$count     = self::persist_snapshot( $snapshot, $source, $inventory );

		return array(
			'snapshot'  => $snapshot,
			'inventory' => $inventory,
			'count'     => $count,
			'last'      => self::get_last_refresh(),
		);
	}

	/**
	 * Persist a shared RX snapshot into every mapped customer record. Connection
	 * popup refreshes use this with their already-completed OLT walk, while full
	 * refreshes pass the newly collected inventory as well.
	 */
	public static function persist_snapshot( $snapshot, $source = 'manual', $inventory = null ) {
		if ( is_wp_error( $snapshot ) || ! is_array( $snapshot ) || empty( $snapshot['entries'] ) ) return 0;
		if ( null === $inventory ) $inventory = self::stored_inventory();
		$inventory = is_array( $inventory ) ? $inventory : array( 'entries' => array() );
		$count = class_exists( 'AFC_OLT_MAC_Link' ) ? AFC_OLT_MAC_Link::store_all_customer_signals( $snapshot, $inventory ) : 0;
		update_option(
			self::LAST_REFRESH,
			array(
				'refreshed_ts' => time(), 'refreshed_at' => current_time( 'mysql' ),
				'source' => sanitize_text_field( (string) $source ), 'customers' => (int) $count,
				'onu_rows' => isset( $snapshot['count'] ) ? (int) $snapshot['count'] : count( $snapshot['entries'] ),
				'olt_nodes' => isset( $snapshot['available_nodes'] ) ? (int) $snapshot['available_nodes'] : 1,
			),
			false
		);
		return $count;
	}

	public static function schedule_connection_inventory_refresh( $olt_id ) {
		$olt_id = AFC_OLT::normalize_olt_id( $olt_id );
		if ( ! wp_next_scheduled( self::CONNECTION_INVENTORY_HOOK, array( $olt_id ) ) ) {
			wp_schedule_single_event( time() + 1, self::CONNECTION_INVENTORY_HOOK, array( $olt_id ) );
		}
	}

	public static function refresh_connection_inventory( $olt_id ) {
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 210 );
		$olt_id = AFC_OLT::normalize_olt_id( $olt_id );
		$node   = AFC_OLT::monitoring_node( $olt_id );
		if ( ! $node ) return;
		$inventory = AFC_OLT_Inventory::refresh_node_inventory( $node );
		if ( is_wp_error( $inventory ) ) return;
		$snapshot = self::stored_snapshot();
		if ( ! is_wp_error( $snapshot ) ) self::persist_snapshot( $snapshot, 'connection-inventory:' . $olt_id, $inventory );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to refresh OLT readings.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function customer_id_for_account( $account ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => trim( (string) $account ),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function decrypt_secret( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$data = base64_decode( substr( $stored, 4 ), true );
		if ( false === $data || strlen( $data ) < 29 ) {
			return '';
		}
		$source = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		$key    = hash( 'sha256', $source, true );
		return (string) openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
	}

	private static function get_one_raw( $olt_id, $pon, $onu ) {
		$node = AFC_OLT::monitoring_node( $olt_id );
		if ( ! $node || empty( $node['config'] ) ) {
			return new WP_Error( 'afc_olt_node_unavailable', __( 'The OLT assigned to this customer is not active.', 'airfiber-centralized' ) );
		}
		$settings = $node['config'];
		$oid      = trim( $settings['rx_oid'], '.' ) . '.' . absint( $pon ) . '.' . absint( $onu );
		$target   = 161 === (int) $settings['port'] ? $settings['host'] : 'udp:' . $settings['host'] . ':' . (int) $settings['port'];
		$timeout  = (int) $settings['timeout_ms'] * 1000;
		$retries  = (int) $settings['retries'];

		if ( '2c' === $settings['version'] ) {
			$community = self::decrypt_secret( $settings['community'] );
			if ( '' === $community || ! function_exists( 'snmp2_get' ) ) {
				return new WP_Error( 'afc_olt_single_snmp_unavailable', __( 'SNMPv2c single-value reads are unavailable.', 'airfiber-centralized' ) );
			}
			$value = @snmp2_get( $target, $community, $oid, $timeout, $retries );
		} else {
			$auth = self::decrypt_secret( $settings['auth_passphrase'] );
			$priv = self::decrypt_secret( $settings['privacy_passphrase'] );
			if ( '' === $auth || '' === $priv || empty( $settings['security_name'] ) || ! function_exists( 'snmp3_get' ) ) {
				return new WP_Error( 'afc_olt_single_snmp_unavailable', __( 'SNMPv3 single-value reads are unavailable.', 'airfiber-centralized' ) );
			}
			$value = @snmp3_get( $target, $settings['security_name'], 'authPriv', 'SHA', $auth, 'DES', $priv, $oid, $timeout, $retries );
		}

		return false === $value ? new WP_Error( 'afc_olt_single_read_failed', __( 'The OLT did not return this ONU RX value.', 'airfiber-centralized' ) ) : $value;
	}

	private static function parse_single_reading( $raw, $settings ) {
		$value = trim( (string) $raw );
		$power = null;
		if ( preg_match( '/(-?\d+(?:\.\d+)?)\s*dBm\b/i', $value, $matches ) ) {
			$power = (float) $matches[1];
		} elseif ( preg_match( '/-?\d+(?:\.\d+)?/', $value, $matches ) ) {
			$number = (float) $matches[0];
			if ( $number <= -100 && $number >= -600 ) {
				$number /= 10;
			} elseif ( $number < -600 && $number >= -6000 ) {
				$number /= 100;
			}
			$power = $number;
		}

		$valid  = null !== $power && $power < -1 && $power >= -60;
		$status = 'invalid';
		if ( $valid ) {
			$status = $power <= (float) $settings['critical_dbm'] ? 'critical' : ( $power <= (float) $settings['warning_dbm'] ? 'warning' : 'good' );
		}
		return array( 'power' => $valid ? round( $power, 2 ) : null, 'status' => $status, 'valid' => $valid, 'raw' => $value );
	}

	private static function patch_snapshot_entry( $signal, $raw ) {
		$last = get_option( AFC_OLT::LAST_SNAPSHOT_KEY, array() );
		if ( ! is_array( $last ) || empty( $last['entries'] ) || empty( $signal['pon'] ) || empty( $signal['onu'] ) ) {
			return;
		}
		$olt_id = AFC_OLT::normalize_olt_id( isset( $signal['olt_id'] ) ? $signal['olt_id'] : 'primary' );
		$node   = AFC_OLT::monitoring_node( $olt_id );
		$key    = AFC_OLT::entry_key( $olt_id, $signal['pon'], $signal['onu'] );
		$last['entries'][ $key ] = array(
			'olt_id'       => $olt_id,
			'olt_name'     => $node ? $node['name'] : '',
			'technology'   => $node ? $node['technology'] : '',
			'pon'          => absint( $signal['pon'] ),
			'onu'          => absint( $signal['onu'] ),
			'rx_power'     => $signal['rx_power'],
			'raw_rx'       => $signal['rx_power'],
			'raw_rx_text'  => (string) $raw,
			'rx_scale'     => 1,
			'signal_valid' => ! empty( $signal['signal_valid'] ),
			'status'       => $signal['status'],
		);
		$valid = 0;
		$invalid = 0;
		foreach ( $last['entries'] as $entry ) {
			! empty( $entry['signal_valid'] ) ? $valid++ : $invalid++;
		}
		$last['valid_count']   = $valid;
		$last['invalid_count'] = $invalid;
		update_option( AFC_OLT::LAST_SNAPSHOT_KEY, $last, false );
		$settings = $node && ! empty( $node['config'] ) ? $node['config'] : AFC_OLT::get_settings();
		set_transient( AFC_OLT::SNAPSHOT_TRANSIENT, $last, (int) $settings['cache_ttl'] );
	}

	public static function ajax_refresh_customer() {
		self::authorize();
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$account     = isset( $_POST['account'] ) ? sanitize_text_field( wp_unslash( $_POST['account'] ) ) : '';
		if ( ! $customer_id && $account ) {
			$customer_id = self::customer_id_for_account( $account );
		}
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Customer record was not found.', 'airfiber-centralized' ) ), 404 );
		}

		$pon = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
		$onu = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
		if ( ( $pon < 1 || $onu < 1 ) && class_exists( 'AFC_OLT_MAC_Link' ) ) {
			AFC_OLT_MAC_Link::signal_from_stored( $customer_id, (string) get_post_meta( $customer_id, '_afc_caller_id', true ), (string) get_post_meta( $customer_id, '_afc_ppp_username', true ), true );
			$pon = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
			$onu = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
		}
		if ( $pon < 1 || $onu < 1 ) {
			wp_send_json_error( array( 'message' => __( 'This customer is not mapped to an ONU yet.', 'airfiber-centralized' ) ), 409 );
		}

		$olt_id = AFC_OLT::normalize_olt_id( get_post_meta( $customer_id, '_afc_olt_id', true ) );
		$node   = AFC_OLT::monitoring_node( $olt_id );
		$raw    = self::get_one_raw( $olt_id, $pon, $onu );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( array( 'message' => $raw->get_error_message() ) );
		}
		$settings = $node && ! empty( $node['config'] ) ? $node['config'] : AFC_OLT::get_settings();
		$reading  = self::parse_single_reading( $raw, $settings );
		$signal   = array(
			'mapped'       => true,
			'olt_id'       => $olt_id,
			'olt_name'     => $node ? $node['name'] : '',
			'pon'          => $pon,
			'onu'          => $onu,
			'onu_mac'      => (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true ),
			'description'  => (string) get_post_meta( $customer_id, '_afc_olt_description', true ),
			'rx_power'     => $reading['power'],
			'signal_valid' => $reading['valid'],
			'status'       => $reading['status'],
			'collected_at' => current_time( 'mysql' ),
			'refreshed_at' => current_time( 'mysql' ),
			'refreshed_ts' => time(),
			'source'       => 'single',
			'stale'        => false,
			'message'      => '',
		);
		update_post_meta( $customer_id, self::CUSTOMER_SIGNAL, $signal );
		self::patch_snapshot_entry( $signal, $raw );
		wp_send_json_success( array( 'optical' => $signal ) );
	}

	public static function ajax_save_schedule() {
		self::authorize();
		$enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
		$times   = isset( $_POST['times'] ) ? wp_unslash( $_POST['times'] ) : array();
		if ( is_string( $times ) ) {
			$decoded = json_decode( $times, true );
			$times   = is_array( $decoded ) ? $decoded : $times;
		}
		$times = self::sanitize_times( $times );
		if ( empty( $times ) ) {
			$times = self::defaults()['times'];
		}
		$settings = array( 'enabled' => $enabled, 'times' => $times );
		update_option( self::SCHEDULE_OPTION, $settings, false );
		delete_option( self::SCHEDULE_HASH );
		self::ensure_schedule();
		wp_send_json_success( array( 'schedule' => $settings, 'message' => __( 'OLT refresh schedule saved.', 'airfiber-centralized' ) ) );
	}

	public static function ajax_flush_runtime_cache() {
		self::authorize();
		delete_transient( AFC_OLT::SNAPSHOT_TRANSIENT );
		delete_transient( AFC_OLT::MAC_TRANSIENT );
		delete_transient( AFC_OLT::POLL_LOCK_TRANSIENT );
		delete_transient( AFC_OLT_Inventory::INVENTORY_TRANSIENT );
		delete_transient( AFC_OLT_Inventory::INVENTORY_LOCK );
		wp_send_json_success( array( 'message' => __( 'Runtime OLT cache cleared. Saved readings were kept.', 'airfiber-centralized' ) ) );
	}

	public static function enqueue_assets( $hook_suffix = '' ) {
		$is_admin = is_admin() && 'toplevel_page_airfiber-centralized' === $hook_suffix;
		$is_app   = ! is_admin() && class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request();
		if ( ! current_user_can( 'manage_options' ) || ( ! $is_admin && ! $is_app ) ) {
			return;
		}
		wp_enqueue_style( 'afc-olt-refresh-controls', AFC_URL . 'assets/css/olt-refresh-controls.css', array(), AFC_VERSION );
		wp_enqueue_script( 'afc-olt-refresh-controls', AFC_URL . 'assets/js/olt-refresh-controls.js', array( 'jquery', 'afc-search-ajaxify' ), AFC_VERSION, true );
		wp_localize_script(
			'afc-olt-refresh-controls',
			'afcOLTRefresh',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'schedule'     => self::get_schedule_settings(),
				'lastRefresh'  => self::get_last_refresh(),
				'timezone'     => wp_timezone_string(),
			)
		);
	}
}
