<?php

defined( 'ABSPATH' ) || exit;

/**
 * Exposes OLT RX data for PPP accounts, including accounts that have not yet
 * been imported as afc_customer posts.
 *
 * PPP-to-ONU identity links and the latest RX reading are persisted in the
 * WordPress database. Browser/device requests use the saved link first, then
 * reuse the latest saved OLT snapshot to refresh the reading without requiring
 * another live SNMP walk.
 */
class AFC_OLT_PPP_Signals {

	const SCHEMA_OPTION  = 'afc_olt_ppp_links_schema_v1';
	const SCHEMA_VERSION = '1';

	public static function init() {
		self::ensure_schema();
		add_action( 'wp_ajax_afc_get_olt_ppp_signals', array( __CLASS__, 'ajax_signals' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 40 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 40 );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'afc_olt_ppp_links';
	}

	public static function ensure_schema() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ppp_username varchar(191) NOT NULL,
			ppp_mac varchar(17) NOT NULL DEFAULT '',
			olt_mac varchar(17) NOT NULL DEFAULT '',
			pon smallint(5) unsigned NOT NULL DEFAULT 0,
			onu smallint(5) unsigned NOT NULL DEFAULT 0,
			description varchar(255) NOT NULL DEFAULT '',
			match_method varchar(32) NOT NULL DEFAULT '',
			confidence smallint(5) unsigned NOT NULL DEFAULT 0,
			rx_power varchar(16) NOT NULL DEFAULT '',
			signal_status varchar(32) NOT NULL DEFAULT 'unavailable',
			collected_at varchar(19) NOT NULL DEFAULT '',
			matched_at varchar(19) NOT NULL DEFAULT '',
			updated_at varchar(19) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY ppp_username (ppp_username),
			KEY ppp_mac (ppp_mac),
			KEY olt_mac (olt_mac),
			KEY olt_location (pon,onu)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_script();
	}

	public static function enqueue_frontend_assets() {
		if (
			! current_user_can( 'manage_options' ) ||
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request()
		) {
			return;
		}
		self::enqueue_script();
	}

	private static function enqueue_script() {
		wp_enqueue_script(
			'afc-ppp-optical-search-results',
			AFC_URL . 'assets/js/ppp-optical-search-results.js',
			array( 'jquery', 'afc-ppp-optical-async' ),
			AFC_VERSION,
			true
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view optical monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	private static function sanitize_accounts() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, 1000 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$username = isset( $item['username'] ) ? sanitize_text_field( $item['username'] ) : '';
			if ( '' === $username ) {
				continue;
			}
			$key = strtolower( $username );
			$accounts[ $key ] = array(
				'username'    => $username,
				'caller_id'   => isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : '',
				'customer_id' => isset( $item['customer_id'] ) ? absint( $item['customer_id'] ) : 0,
				'imported'    => ! empty( $item['imported'] ),
			);
		}
		return array_values( $accounts );
	}

	private static function normalize_mac( $value ) {
		return class_exists( 'AFC_OLT_Inventory' ) ? AFC_OLT_Inventory::normalize_mac( $value ) : '';
	}

	private static function stored_link( $username, $caller_id = '' ) {
		global $wpdb;
		self::ensure_schema();

		$table    = self::table_name();
		$username = trim( (string) $username );
		$link     = null;

		if ( '' !== $username ) {
			$link = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE ppp_username = %s LIMIT 1", $username ),
				ARRAY_A
			);
		}

		if ( ! $link ) {
			$ppp_mac = self::normalize_mac( $caller_id );
			if ( $ppp_mac ) {
				$link = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE ppp_mac = %s ORDER BY id DESC LIMIT 1", $ppp_mac ),
					ARRAY_A
				);
			}
		}

		return is_array( $link ) ? $link : null;
	}

	private static function empty_signal( $status = 'unmatched' ) {
		return array(
			'mapped'       => false,
			'detected'     => false,
			'temporary'    => false,
			'persisted'    => false,
			'pon'          => 0,
			'onu'          => 0,
			'onu_mac'      => '',
			'description'  => '',
			'onu_type'     => '',
			'onu_online'   => null,
			'rx_power'     => null,
			'status'       => $status,
			'collected_at' => '',
			'stale'        => false,
			'message'      => '',
			'match_method' => '',
			'confidence'   => 0,
			'source'       => 'database',
		);
	}

	private static function signal_from_link_row( $link ) {
		$signal = self::empty_signal( ! empty( $link['signal_status'] ) ? sanitize_key( $link['signal_status'] ) : 'unavailable' );
		$rx     = isset( $link['rx_power'] ) ? trim( (string) $link['rx_power'] ) : '';

		$signal['mapped']       = ! empty( $link['pon'] ) && ! empty( $link['onu'] );
		$signal['detected']     = $signal['mapped'];
		$signal['persisted']    = $signal['mapped'];
		$signal['temporary']    = false;
		$signal['pon']          = isset( $link['pon'] ) ? absint( $link['pon'] ) : 0;
		$signal['onu']          = isset( $link['onu'] ) ? absint( $link['onu'] ) : 0;
		$signal['onu_mac']      = isset( $link['olt_mac'] ) ? (string) $link['olt_mac'] : '';
		$signal['description']  = isset( $link['description'] ) ? (string) $link['description'] : '';
		$signal['match_method'] = isset( $link['match_method'] ) ? sanitize_key( $link['match_method'] ) : 'database';
		$signal['confidence']   = isset( $link['confidence'] ) ? absint( $link['confidence'] ) : 0;
		$signal['rx_power']     = '' !== $rx && is_numeric( $rx ) ? round( (float) $rx, 2 ) : null;
		$signal['collected_at'] = isset( $link['collected_at'] ) ? (string) $link['collected_at'] : '';
		$signal['source']       = 'database';
		return $signal;
	}

	private static function signal_for_location( $match, $snapshot, $inventory, $temporary = true ) {
		$pon    = isset( $match['pon'] ) ? absint( $match['pon'] ) : 0;
		$onu    = isset( $match['onu'] ) ? absint( $match['onu'] ) : 0;
		$signal = self::empty_signal( 'unavailable' );

		if ( ! $pon || ! $onu ) {
			return $signal;
		}

		$signal['mapped']       = true;
		$signal['detected']     = true;
		$signal['temporary']    = (bool) $temporary;
		$signal['persisted']    = ! $temporary;
		$signal['pon']          = $pon;
		$signal['onu']          = $onu;
		$signal['match_method'] = isset( $match['match_method'] ) ? sanitize_key( $match['match_method'] ) : '';
		$signal['confidence']   = isset( $match['confidence'] ) ? absint( $match['confidence'] ) : 0;
		$signal['source']       = $temporary ? 'detected' : 'database';

		$key = $pon . ':' . $onu;
		if ( ! empty( $inventory['entries'][ $key ] ) ) {
			$entry                 = $inventory['entries'][ $key ];
			$signal['description'] = isset( $entry['description'] ) ? (string) $entry['description'] : '';
			$signal['onu_type']    = isset( $entry['onu_type'] ) ? (string) $entry['onu_type'] : '';
			$signal['onu_online']  = array_key_exists( 'online', $entry ) ? $entry['online'] : null;
			$signal['onu_mac']     = isset( $entry['mac'] ) ? self::normalize_mac( $entry['mac'] ) : '';
		}

		if ( is_wp_error( $snapshot ) ) {
			$signal['message'] = $snapshot->get_error_message();
			return $signal;
		}

		$signal['collected_at'] = isset( $snapshot['collected_at'] ) ? (string) $snapshot['collected_at'] : '';
		$signal['stale']        = ! empty( $snapshot['stale'] );
		$signal['message']      = isset( $snapshot['error'] ) ? (string) $snapshot['error'] : '';

		if ( empty( $snapshot['entries'][ $key ] ) ) {
			$signal['status'] = $signal['stale'] ? 'stale' : 'offline';
			return $signal;
		}

		$entry = $snapshot['entries'][ $key ];
		if ( empty( $entry['signal_valid'] ) || ! isset( $entry['rx_power'] ) || null === $entry['rx_power'] ) {
			$signal['status'] = $signal['stale'] ? 'stale' : 'invalid';
			return $signal;
		}

		$signal['rx_power'] = round( (float) $entry['rx_power'], 2 );
		$signal['status']   = $signal['stale'] ? 'stale' : ( isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'good' );

		if ( false === $signal['onu_online'] ) {
			$signal['rx_power'] = null;
			$signal['status']   = 'offline';
		}

		return $signal;
	}

	private static function trusted_match( $match ) {
		if ( ! is_array( $match ) || empty( $match['pon'] ) || empty( $match['onu'] ) ) {
			return false;
		}
		$method     = isset( $match['match_method'] ) ? sanitize_key( $match['match_method'] ) : '';
		$confidence = isset( $match['confidence'] ) ? absint( $match['confidence'] ) : 0;
		return 'mac' === $method || 'description' === $method || ( 'description_fuzzy' === $method && $confidence >= 86 );
	}

	private static function persisted_values( $account, $signal, $existing = null ) {
		$rx = isset( $signal['rx_power'] ) && null !== $signal['rx_power'] && is_numeric( $signal['rx_power'] )
			? number_format( (float) $signal['rx_power'], 2, '.', '' )
			: '';
		$now = current_time( 'mysql' );

		return array(
			'ppp_username' => sanitize_text_field( $account['username'] ),
			'ppp_mac'      => self::normalize_mac( isset( $account['caller_id'] ) ? $account['caller_id'] : '' ),
			'olt_mac'      => self::normalize_mac( isset( $signal['onu_mac'] ) ? $signal['onu_mac'] : '' ),
			'pon'          => isset( $signal['pon'] ) ? absint( $signal['pon'] ) : 0,
			'onu'          => isset( $signal['onu'] ) ? absint( $signal['onu'] ) : 0,
			'description'  => isset( $signal['description'] ) ? sanitize_text_field( $signal['description'] ) : '',
			'match_method' => isset( $signal['match_method'] ) ? sanitize_key( $signal['match_method'] ) : 'database',
			'confidence'   => isset( $signal['confidence'] ) ? absint( $signal['confidence'] ) : 0,
			'rx_power'     => $rx,
			'signal_status'=> isset( $signal['status'] ) ? sanitize_key( $signal['status'] ) : 'unavailable',
			'collected_at' => isset( $signal['collected_at'] ) ? substr( sanitize_text_field( $signal['collected_at'] ), 0, 19 ) : '',
			'matched_at'   => $existing && ! empty( $existing['matched_at'] ) ? (string) $existing['matched_at'] : $now,
			'updated_at'   => $now,
		);
	}

	private static function link_changed( $existing, $values ) {
		if ( ! $existing ) {
			return true;
		}
		foreach ( array( 'ppp_username', 'ppp_mac', 'olt_mac', 'pon', 'onu', 'description', 'match_method', 'confidence', 'rx_power', 'signal_status', 'collected_at' ) as $key ) {
			if ( (string) ( isset( $existing[ $key ] ) ? $existing[ $key ] : '' ) !== (string) ( isset( $values[ $key ] ) ? $values[ $key ] : '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function persist_link( $account, $signal, $existing = null ) {
		if ( empty( $account['username'] ) || empty( $signal['mapped'] ) || empty( $signal['pon'] ) || empty( $signal['onu'] ) ) {
			return false;
		}

		global $wpdb;
		self::ensure_schema();
		$existing = $existing ? $existing : self::stored_link( $account['username'], isset( $account['caller_id'] ) ? $account['caller_id'] : '' );
		$values   = self::persisted_values( $account, $signal, $existing );

		if ( ! self::link_changed( $existing, $values ) ) {
			return true;
		}

		if ( $existing && ! empty( $existing['id'] ) ) {
			$values['id'] = absint( $existing['id'] );
		}

		$formats = array();
		foreach ( $values as $key => $value ) {
			$formats[] = in_array( $key, array( 'id', 'pon', 'onu', 'confidence' ), true ) ? '%d' : '%s';
		}

		return false !== $wpdb->replace( self::table_name(), $values, $formats );
	}

	private static function signal_from_saved_link( $account, $link, $snapshot, $inventory ) {
		$stored = self::signal_from_link_row( $link );
		$match  = array(
			'pon'          => $stored['pon'],
			'onu'          => $stored['onu'],
			'match_method' => $stored['match_method'],
			'confidence'   => $stored['confidence'],
		);
		$fresh = self::signal_for_location( $match, $snapshot, $inventory, false );

		if ( is_wp_error( $snapshot ) ) {
			$stored['message'] = $snapshot->get_error_message();
			return $stored;
		}

		if ( empty( $fresh['onu_mac'] ) ) {
			$fresh['onu_mac'] = $stored['onu_mac'];
		}
		if ( empty( $fresh['description'] ) ) {
			$fresh['description'] = $stored['description'];
		}
		$fresh['persisted'] = true;
		$fresh['temporary'] = false;
		$fresh['source']    = 'database';
		self::persist_link( $account, $fresh, $link );
		return $fresh;
	}

	private static function customer_signal( $account, $snapshot, $inventory ) {
		$customer_id = isset( $account['customer_id'] ) ? absint( $account['customer_id'] ) : 0;
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
			return null;
		}

		if ( class_exists( 'AFC_OLT_MAC_Link' ) ) {
			$signal = AFC_OLT_MAC_Link::signal_from_stored(
				$customer_id,
				isset( $account['caller_id'] ) ? $account['caller_id'] : '',
				$account['username'],
				true
			);
		} else {
			$signal = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
		}

		if ( ! is_array( $signal ) || empty( $signal['mapped'] ) ) {
			return $signal;
		}

		$match = array(
			'pon'          => isset( $signal['pon'] ) ? $signal['pon'] : 0,
			'onu'          => isset( $signal['onu'] ) ? $signal['onu'] : 0,
			'match_method' => ! empty( $signal['match_method'] ) ? $signal['match_method'] : 'customer',
			'confidence'   => ! empty( $signal['confidence'] ) ? $signal['confidence'] : 100,
		);
		$fresh = self::signal_for_location( $match, $snapshot, $inventory, false );
		if ( empty( $fresh['onu_mac'] ) && ! empty( $signal['onu_mac'] ) ) {
			$fresh['onu_mac'] = $signal['onu_mac'];
		}
		if ( empty( $fresh['description'] ) && ! empty( $signal['description'] ) ) {
			$fresh['description'] = $signal['description'];
		}
		$fresh['persisted'] = true;
		$fresh['source']    = 'database';
		return $fresh;
	}

	private static function stored_sources( $force ) {
		if ( $force && class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			$bundle = AFC_OLT_Refresh_Manager::refresh_full( 'ppp-link-manual' );
			return array( $bundle['snapshot'], $bundle['inventory'] );
		}

		$snapshot = class_exists( 'AFC_OLT_MAC_Link' )
			? AFC_OLT_MAC_Link::stored_snapshot()
			: AFC_OLT::get_snapshot( false );
		$inventory = class_exists( 'AFC_OLT_MAC_Link' )
			? AFC_OLT_MAC_Link::stored_inventory()
			: AFC_OLT_Inventory::get_inventory( false );
		return array( $snapshot, $inventory );
	}

	public static function ajax_signals() {
		self::authorize();

		$accounts = self::sanitize_accounts();
		if ( ! $accounts ) {
			wp_send_json_success( array( 'signals' => array(), 'storage' => 'database' ) );
		}

		list( $snapshot, $inventory ) = self::stored_sources( ! empty( $_POST['refresh'] ) );
		$signals = array();

		foreach ( $accounts as $account ) {
			$username = $account['username'];
			$link     = self::stored_link( $username, $account['caller_id'] );

			if ( $link ) {
				$signal = self::signal_from_saved_link( $account, $link, $snapshot, $inventory );
			} else {
				$signal = self::customer_signal( $account, $snapshot, $inventory );

				if ( ! $signal || empty( $signal['mapped'] ) ) {
					$match  = AFC_OLT_Inventory::find_match( $account['caller_id'], $username, $inventory );
					$signal = $match
						? self::signal_for_location( $match, $snapshot, $inventory, true )
						: self::empty_signal( 'unmatched' );
				}

				if ( ! empty( $signal['mapped'] ) ) {
					$match = array(
						'pon'          => $signal['pon'],
						'onu'          => $signal['onu'],
						'match_method' => isset( $signal['match_method'] ) ? $signal['match_method'] : '',
						'confidence'   => isset( $signal['confidence'] ) ? $signal['confidence'] : 0,
					);
					$trusted = ! empty( $account['imported'] ) || self::trusted_match( $match );
					if ( $trusted && self::persist_link( $account, $signal ) ) {
						$signal['persisted'] = true;
						$signal['temporary'] = false;
						$signal['source']    = 'database';
					}
				}
			}

			$signal['imported'] = ! empty( $account['imported'] );
			$signals[ strtolower( $username ) ] = $signal;
		}

		wp_send_json_success(
			array(
				'signals' => $signals,
				'summary' => AFC_OLT::snapshot_summary( $snapshot ),
				'storage' => 'database',
			)
		);
	}
}
