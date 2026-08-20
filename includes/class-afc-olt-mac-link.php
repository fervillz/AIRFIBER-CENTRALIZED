<?php

defined( 'ABSPATH' ) || exit;

/**
 * Correlates MikroTik PPP caller-id MACs with OLT ONU locations.
 * Normal page requests use stored readings only; live OLT work is delegated to
 * AFC_OLT_Refresh_Manager.
 */
class AFC_OLT_MAC_Link {

	public static function init() {
		remove_action( 'wp_ajax_afc_get_olt_customer_signals', array( 'AFC_OLT_Inventory', 'ajax_customer_signals' ) );
		add_action( 'wp_ajax_afc_get_olt_customer_signals', array( __CLASS__, 'ajax_customer_signals' ) );
		add_filter( 'afc_search_ajaxify_records', array( __CLASS__, 'enrich_search_records' ), 20, 4 );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view optical monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	public static function stored_snapshot() {
		if ( class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			return AFC_OLT_Refresh_Manager::stored_snapshot();
		}
		$last = get_option( AFC_OLT::LAST_SNAPSHOT_KEY, array() );
		return is_array( $last ) && ! empty( $last['entries'] ) ? $last : new WP_Error( 'afc_olt_cache_empty', __( 'No saved optical snapshot exists yet.', 'airfiber-centralized' ) );
	}

	public static function stored_inventory() {
		if ( class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			return AFC_OLT_Refresh_Manager::stored_inventory();
		}
		$last = get_option( AFC_OLT_Inventory::INVENTORY_OPTION, array() );
		return is_array( $last ) ? $last : array( 'entries' => array() );
	}

	private static function customer_id_for_username( $username ) {
		$username = trim( (string) $username );
		if ( '' === $username ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function inventory_row( $inventory, $pon, $onu, $olt_id = 'primary' ) {
		$key = AFC_OLT::entry_key( $olt_id, $pon, $onu );
		return ! empty( $inventory['entries'][ $key ] ) && is_array( $inventory['entries'][ $key ] ) ? $inventory['entries'][ $key ] : array();
	}

	private static function exact_mac_match( $caller_id, $snapshot, $inventory ) {
		$mac = AFC_OLT_Inventory::normalize_mac( $caller_id );
		if ( '' === $mac ) {
			return null;
		}

		$inventory_matches = array();
		foreach ( isset( $inventory['entries'] ) ? (array) $inventory['entries'] : array() as $entry ) {
			if ( ! empty( $entry['mac'] ) && 0 === strcasecmp( AFC_OLT_Inventory::normalize_mac( $entry['mac'] ), $mac ) ) {
				$inventory_matches[] = $entry;
			}
		}
		if ( 1 === count( $inventory_matches ) ) {
			$match                 = $inventory_matches[0];
			$match['mac']          = $mac;
			$match['match_method'] = 'mac';
			$match['confidence']   = 100;
			$match['match_source'] = 'onu_inventory';
			return $match;
		}

		if ( ! is_wp_error( $snapshot ) && ! empty( $snapshot['learned_macs'] ) ) {
			$locations = array();
			foreach ( (array) $snapshot['learned_macs'] as $snapshot_mac => $items ) {
				if ( 0 === strcasecmp( AFC_OLT_Inventory::normalize_mac( $snapshot_mac ), $mac ) ) {
					$locations = is_array( $items ) ? $items : array();
					break;
			}
			}
			$unique = array();
			foreach ( $locations as $location ) {
				$olt_id = AFC_OLT::normalize_olt_id( isset( $location['olt_id'] ) ? $location['olt_id'] : 'primary' );
				$pon = isset( $location['pon'] ) ? absint( $location['pon'] ) : 0;
				$onu = isset( $location['onu'] ) ? absint( $location['onu'] ) : 0;
				if ( $pon > 0 && $onu > 0 ) {
					$unique[ AFC_OLT::entry_key( $olt_id, $pon, $onu ) ] = array( 'olt_id' => $olt_id, 'pon' => $pon, 'onu' => $onu );
				}
			}
			if ( 1 === count( $unique ) ) {
				$location = reset( $unique );
				$match    = array_merge(
					array(
						'olt_id'      => $location['olt_id'],
						'pon'         => $location['pon'],
						'onu'         => $location['onu'],
						'mac'         => $mac,
						'description' => '',
						'online'      => null,
						'onu_type'    => '',
					),
					self::inventory_row( $inventory, $location['pon'], $location['onu'], $location['olt_id'] )
				);
				$match['mac']          = $mac;
				$match['match_method'] = 'mac';
				$match['confidence']   = 100;
				$match['match_source'] = 'learned_mac';
				return $match;
			}
		}
		return null;
	}

	private static function binding_conflict( $customer_id, $olt_id, $pon, $onu ) {
		$olt_id = AFC_OLT::normalize_olt_id( $olt_id );
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( absint( $customer_id ) ),
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_afc_olt_id', 'value' => $olt_id ),
					array( 'key' => '_afc_olt_pon', 'value' => absint( $pon ) ),
					array( 'key' => '_afc_olt_onu', 'value' => absint( $onu ) ),
				),
			)
		);
		return ! empty( $ids );
	}

	private static function save_exact_mac_binding( $customer_id, $match ) {
		$customer_id = absint( $customer_id );
		$olt_id      = AFC_OLT::normalize_olt_id( isset( $match['olt_id'] ) ? $match['olt_id'] : 'primary' );
		$pon         = isset( $match['pon'] ) ? absint( $match['pon'] ) : 0;
		$onu         = isset( $match['onu'] ) ? absint( $match['onu'] ) : 0;
		if ( ! $customer_id || $pon < 1 || $onu < 1 || 'mac' !== ( isset( $match['match_method'] ) ? $match['match_method'] : '' ) || self::binding_conflict( $customer_id, $olt_id, $pon, $onu ) ) {
			return false;
		}
		update_post_meta( $customer_id, '_afc_olt_id', $olt_id );
		update_post_meta( $customer_id, '_afc_olt_pon', $pon );
		update_post_meta( $customer_id, '_afc_olt_onu', $onu );
		if ( ! empty( $match['mac'] ) ) {
			update_post_meta( $customer_id, '_afc_olt_onu_mac', AFC_OLT_Inventory::normalize_mac( $match['mac'] ) );
		}
		if ( ! empty( $match['description'] ) ) {
			update_post_meta( $customer_id, '_afc_olt_description', sanitize_text_field( $match['description'] ) );
		}
		update_post_meta( $customer_id, '_afc_olt_match_method', 'mac' );
		update_post_meta( $customer_id, '_afc_olt_match_source', isset( $match['match_source'] ) ? sanitize_key( $match['match_source'] ) : 'mac' );
		return true;
	}

	private static function merge_inventory_details( $signal, $inventory ) {
		if ( empty( $signal['pon'] ) || empty( $signal['onu'] ) ) {
			return $signal;
		}
		$onu = self::inventory_row( $inventory, $signal['pon'], $signal['onu'], isset( $signal['olt_id'] ) ? $signal['olt_id'] : 'primary' );
		if ( ! $onu ) {
			return $signal;
		}
		$signal['description'] = isset( $onu['description'] ) ? (string) $onu['description'] : ( isset( $signal['description'] ) ? $signal['description'] : '' );
		$signal['onu_type']    = isset( $onu['onu_type'] ) ? (string) $onu['onu_type'] : '';
		$signal['onu_online']  = array_key_exists( 'online', $onu ) ? $onu['online'] : null;
		if ( ! empty( $onu['mac'] ) ) {
			$signal['onu_mac'] = AFC_OLT_Inventory::normalize_mac( $onu['mac'] );
		}
		if ( false === $signal['onu_online'] ) {
			$signal['rx_power']     = null;
			$signal['signal_valid'] = false;
			$signal['status']       = 'offline';
		}
		return $signal;
	}

	public static function signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, $allow_suggestion = true ) {
		$signal = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
		if ( empty( $signal['mapped'] ) ) {
			$match = self::exact_mac_match( $caller_id, $snapshot, $inventory );
			if ( $match && self::save_exact_mac_binding( $customer_id, $match ) ) {
				$signal                 = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
				$signal['auto_matched'] = true;
				$signal['match_method'] = 'mac';
				$signal['match_source'] = isset( $match['match_source'] ) ? $match['match_source'] : 'mac';
				$signal['confidence']   = 100;
			} elseif ( $allow_suggestion ) {
				$suggestion = AFC_OLT_Inventory::find_match( $caller_id, $username, $inventory );
				if ( $suggestion ) {
					if ( 'mac' === ( isset( $suggestion['match_method'] ) ? $suggestion['match_method'] : '' ) && self::save_exact_mac_binding( $customer_id, $suggestion ) ) {
						$signal                 = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
						$signal['auto_matched'] = true;
						$signal['match_method'] = 'mac';
						$signal['match_source'] = 'onu_inventory';
						$signal['confidence']   = 100;
					} else {
						$signal['suggested'] = array(
							'olt_id'       => isset( $suggestion['olt_id'] ) ? AFC_OLT::normalize_olt_id( $suggestion['olt_id'] ) : 'primary',
							'olt_name'     => isset( $suggestion['olt_name'] ) ? (string) $suggestion['olt_name'] : '',
							'pon'          => isset( $suggestion['pon'] ) ? (int) $suggestion['pon'] : 0,
							'onu'          => isset( $suggestion['onu'] ) ? (int) $suggestion['onu'] : 0,
							'mac'          => isset( $suggestion['mac'] ) ? $suggestion['mac'] : '',
							'description'  => isset( $suggestion['description'] ) ? $suggestion['description'] : '',
							'match_method' => isset( $suggestion['match_method'] ) ? $suggestion['match_method'] : 'description',
							'confidence'   => isset( $suggestion['confidence'] ) ? (int) $suggestion['confidence'] : 0,
						);
					}
				}
			}
		}
		$signal = self::merge_inventory_details( $signal, $inventory );
		$signal['signal_valid'] = in_array( isset( $signal['status'] ) ? $signal['status'] : '', array( 'good', 'warning', 'critical' ), true ) && isset( $signal['rx_power'] ) && null !== $signal['rx_power'];
		if ( ! empty( $snapshot['collected_ts'] ) ) {
			$signal['refreshed_ts'] = (int) $snapshot['collected_ts'];
			$signal['refreshed_at'] = isset( $snapshot['collected_at'] ) ? $snapshot['collected_at'] : '';
		}
		$signal['source'] = isset( $snapshot['source'] ) ? $snapshot['source'] : 'record';
		return $signal;
	}

	public static function persist_signal( $customer_id, $signal ) {
		$customer_id = absint( $customer_id );
		if ( ! $customer_id || ! is_array( $signal ) ) {
			return false;
		}
		if ( empty( $signal['refreshed_ts'] ) ) {
			$signal['refreshed_ts'] = time();
		}
		if ( empty( $signal['refreshed_at'] ) ) {
			$signal['refreshed_at'] = current_time( 'mysql' );
		}
		update_post_meta( $customer_id, AFC_OLT_Refresh_Manager::CUSTOMER_SIGNAL, $signal );
		return true;
	}

	public static function signal_from_stored( $customer_id, $caller_id = '', $username = '', $allow_suggestion = true ) {
		$stored = get_post_meta( absint( $customer_id ), AFC_OLT_Refresh_Manager::CUSTOMER_SIGNAL, true );
		if ( is_array( $stored ) && ! empty( $stored['mapped'] ) ) {
			return $stored;
		}
		$snapshot  = self::stored_snapshot();
		$inventory = self::stored_inventory();
		return self::signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, $allow_suggestion );
	}

	public static function store_all_customer_signals( $snapshot, $inventory ) {
		if ( is_wp_error( $snapshot ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$count = 0;
		foreach ( $ids as $customer_id ) {
			$username  = (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			$caller_id = (string) get_post_meta( $customer_id, '_afc_caller_id', true );
			if ( '' === $username ) {
				continue;
			}
			$signal = self::signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, true );
			if ( self::persist_signal( $customer_id, $signal ) ) {
				$count++;
			}
		}
		return $count;
	}

	public static function enrich_search_records( $records, $accounts, $providers, $context ) {
		if ( ! class_exists( 'AFC_OLT' ) || ! class_exists( 'AFC_OLT_Inventory' ) || ! AFC_OLT::is_enabled() ) {
			return $records;
		}
		foreach ( $records as $key => &$record ) {
			if ( empty( $record['found'] ) ) {
				continue;
			}
			$username    = isset( $record['account'] ) ? (string) $record['account'] : (string) $key;
			$customer_id = self::customer_id_for_username( $username );
			if ( ! $customer_id ) {
				continue;
			}

			$caller_id = ! empty( $record['session']['caller_id'] ) ? (string) $record['session']['caller_id'] : (string) get_post_meta( $customer_id, '_afc_caller_id', true );
			if ( '' !== trim( $caller_id ) ) {
				/* Keep the latest RouterOS caller-id so scheduled refreshes can map without a live PPP lookup. */
				update_post_meta( $customer_id, '_afc_caller_id', sanitize_text_field( $caller_id ) );
			}

			$saved = get_post_meta( $customer_id, AFC_OLT_Refresh_Manager::CUSTOMER_SIGNAL, true );
			if ( is_array( $saved ) && ! empty( $saved['mapped'] ) ) {
				$record['optical'] = $saved;
				continue;
			}

			/*
			 * Older refreshes may have persisted an unmapped placeholder. Do not let
			 * that placeholder permanently hide a later exact MAC match. Rebuild only
			 * from the saved OLT snapshot/inventory; this does not contact the OLT.
			 */
			$signal = self::signal_from_stored( $customer_id, $caller_id, $username, true );
			$record['optical'] = $signal;
			if ( is_array( $signal ) && ! empty( $signal['mapped'] ) ) {
				self::persist_signal( $customer_id, $signal );
			}
		}
		unset( $record );
		return $records;
	}

	public static function ajax_customer_signals() {
		self::authorize();
		$customers = array();
		if ( isset( $_POST['customers'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['customers'] ), true );
			if ( is_array( $decoded ) ) {
				$customers = array_slice( $decoded, 0, 1000 );
			}
		}

		$force = ! empty( $_POST['refresh'] );
		if ( $force && class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			$bundle    = AFC_OLT_Refresh_Manager::refresh_full( 'ppp-manual' );
			$snapshot  = $bundle['snapshot'];
			$inventory = $bundle['inventory'];
		} else {
			$snapshot  = self::stored_snapshot();
			$inventory = self::stored_inventory();
		}

		$signals = array();
		foreach ( $customers as $item ) {
			$customer_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
				continue;
			}

			$caller_id = isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : (string) get_post_meta( $customer_id, '_afc_caller_id', true );
			$username  = isset( $item['username'] ) ? sanitize_text_field( $item['username'] ) : (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			if ( '' !== trim( $caller_id ) ) {
				update_post_meta( $customer_id, '_afc_caller_id', $caller_id );
			}

			$saved = get_post_meta( $customer_id, AFC_OLT_Refresh_Manager::CUSTOMER_SIGNAL, true );
			if ( is_array( $saved ) && ! empty( $saved['mapped'] ) ) {
				$signals[ (string) $customer_id ] = $saved;
				continue;
			}

			$signal = self::signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, true );
			$signals[ (string) $customer_id ] = $signal;
			if ( is_array( $signal ) && ! empty( $signal['mapped'] ) ) {
				self::persist_signal( $customer_id, $signal );
			}
		}

		wp_send_json_success(
			array(
				'signals' => $signals,
				'summary' => AFC_OLT::snapshot_summary( $snapshot ),
				'last'    => class_exists( 'AFC_OLT_Refresh_Manager' ) ? AFC_OLT_Refresh_Manager::get_last_refresh() : array(),
			)
		);
	}
}
