<?php

defined( 'ABSPATH' ) || exit;

/**
 * Correlates MikroTik PPP caller-id MACs with OLT ONU locations.
 *
 * Important: normal search enrichment is cache-only. Live SNMP polling remains
 * isolated to the dedicated optical AJAX endpoint, where one snapshot is shared
 * by all requested customers.
 */
class AFC_OLT_MAC_Link {

	public static function init() {
		/* Replace the inventory endpoint with the MAC-aware bulk implementation. */
		remove_action( 'wp_ajax_afc_get_olt_customer_signals', array( 'AFC_OLT_Inventory', 'ajax_customer_signals' ) );
		add_action( 'wp_ajax_afc_get_olt_customer_signals', array( __CLASS__, 'ajax_customer_signals' ) );

		/* Add cached optical data to Basic/Advanced customer search results. */
		add_filter( 'afc_search_ajaxify_records', array( __CLASS__, 'enrich_search_records' ), 20, 4 );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view optical monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	private static function cached_snapshot() {
		$cached = get_transient( AFC_OLT::SNAPSHOT_TRANSIENT );
		if (
			is_array( $cached ) &&
			isset( $cached['format'] ) &&
			(int) AFC_OLT::SNAPSHOT_FORMAT === (int) $cached['format'] &&
			! empty( $cached['entries'] )
		) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$last = get_option( AFC_OLT::LAST_SNAPSHOT_KEY, array() );
		if (
			is_array( $last ) &&
			isset( $last['format'] ) &&
			(int) AFC_OLT::SNAPSHOT_FORMAT === (int) $last['format'] &&
			! empty( $last['entries'] )
		) {
			$last['source'] = 'stale';
			$last['stale']  = true;
			return $last;
		}

		return new WP_Error( 'afc_olt_cache_empty', __( 'Optical data has not been collected yet.', 'airfiber-centralized' ) );
	}

	private static function cached_inventory() {
		$cached = get_transient( AFC_OLT_Inventory::INVENTORY_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$last = get_option( AFC_OLT_Inventory::INVENTORY_OPTION, array() );
		if ( is_array( $last ) && ! empty( $last['entries'] ) ) {
			$last['source'] = 'stale';
			$last['stale']  = true;
			return $last;
		}

		return array(
			'entries'      => array(),
			'count'        => 0,
			'collected_at' => '',
			'source'       => 'unavailable',
			'stale'        => false,
		);
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

	private static function inventory_row( $inventory, $pon, $onu ) {
		$key = absint( $pon ) . ':' . absint( $onu );
		return ! empty( $inventory['entries'][ $key ] ) && is_array( $inventory['entries'][ $key ] )
			? $inventory['entries'][ $key ]
			: array();
	}

	private static function exact_mac_match( $caller_id, $snapshot, $inventory ) {
		$mac = AFC_OLT_Inventory::normalize_mac( $caller_id );
		if ( '' === $mac ) {
			return null;
		}

		/* Prefer the ONU inventory's own MAC column when this firmware exposes it. */
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

		/*
		 * VSOL also exposes learned MAC -> PON/ONU locations. The user's MikroTik
		 * PPP caller-id has been confirmed to match this ONU MAC in practice, so a
		 * unique exact MAC location is safe to auto-link.
		 */
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
				$pon = isset( $location['pon'] ) ? absint( $location['pon'] ) : 0;
				$onu = isset( $location['onu'] ) ? absint( $location['onu'] ) : 0;
				if ( $pon > 0 && $onu > 0 ) {
					$unique[ $pon . ':' . $onu ] = array( 'pon' => $pon, 'onu' => $onu );
				}
			}

			if ( 1 === count( $unique ) ) {
				$location = reset( $unique );
				$match    = array_merge(
					array(
						'pon'          => $location['pon'],
						'onu'          => $location['onu'],
						'mac'          => $mac,
						'description'  => '',
						'online'       => null,
						'onu_type'     => '',
					),
					self::inventory_row( $inventory, $location['pon'], $location['onu'] )
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

	private static function binding_conflict( $customer_id, $pon, $onu ) {
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
					array( 'key' => '_afc_olt_pon', 'value' => absint( $pon ) ),
					array( 'key' => '_afc_olt_onu', 'value' => absint( $onu ) ),
				),
			)
		);
		return ! empty( $ids );
	}

	private static function save_exact_mac_binding( $customer_id, $match ) {
		$customer_id = absint( $customer_id );
		$pon         = isset( $match['pon'] ) ? absint( $match['pon'] ) : 0;
		$onu         = isset( $match['onu'] ) ? absint( $match['onu'] ) : 0;
		if ( ! $customer_id || $pon < 1 || $onu < 1 || 'mac' !== ( isset( $match['match_method'] ) ? $match['match_method'] : '' ) ) {
			return false;
		}
		if ( self::binding_conflict( $customer_id, $pon, $onu ) ) {
			return false;
		}

		update_post_meta( $customer_id, '_afc_olt_id', 'primary' );
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
		$onu = self::inventory_row( $inventory, $signal['pon'], $signal['onu'] );
		if ( ! $onu ) {
			return $signal;
		}

		$signal['description'] = isset( $onu['description'] ) ? (string) $onu['description'] : '';
		$signal['onu_type']    = isset( $onu['onu_type'] ) ? (string) $onu['onu_type'] : '';
		$signal['onu_online']  = array_key_exists( 'online', $onu ) ? $onu['online'] : null;
		if ( ! empty( $onu['mac'] ) ) {
			$signal['onu_mac'] = AFC_OLT_Inventory::normalize_mac( $onu['mac'] );
		}
		if ( false === $signal['onu_online'] ) {
			$signal['rx_power'] = null;
			$signal['status']   = 'offline';
		}
		return $signal;
	}

	private static function signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, $allow_suggestion = true ) {
		$signal = AFC_OLT::get_customer_signal( $customer_id, $snapshot );

		if ( empty( $signal['mapped'] ) ) {
			$match = self::exact_mac_match( $caller_id, $snapshot, $inventory );
			if ( $match && self::save_exact_mac_binding( $customer_id, $match ) ) {
				$signal                  = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
				$signal['auto_matched']  = true;
				$signal['match_method']  = 'mac';
				$signal['match_source']  = isset( $match['match_source'] ) ? $match['match_source'] : 'mac';
				$signal['confidence']    = 100;
			} elseif ( $allow_suggestion ) {
				$suggestion = AFC_OLT_Inventory::find_match( $caller_id, $username, $inventory );
				if ( $suggestion ) {
					if ( 'mac' === ( isset( $suggestion['match_method'] ) ? $suggestion['match_method'] : '' ) && self::save_exact_mac_binding( $customer_id, $suggestion ) ) {
						$signal                  = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
						$signal['auto_matched']  = true;
						$signal['match_method']  = 'mac';
						$signal['match_source']  = 'onu_inventory';
						$signal['confidence']    = 100;
					} else {
						$signal['suggested'] = array(
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

		return self::merge_inventory_details( $signal, $inventory );
	}

	public static function enrich_search_records( $records, $accounts, $providers, $context ) {
		if ( ! class_exists( 'AFC_OLT' ) || ! class_exists( 'AFC_OLT_Inventory' ) || ! AFC_OLT::is_enabled() ) {
			return $records;
		}

		/* Cache-only: customer search must never wait for a live SNMP walk. */
		$snapshot  = self::cached_snapshot();
		$inventory = self::cached_inventory();

		foreach ( $records as $key => &$record ) {
			if ( empty( $record['found'] ) ) {
				continue;
			}
			$username    = isset( $record['account'] ) ? (string) $record['account'] : (string) $key;
			$customer_id = self::customer_id_for_username( $username );
			if ( ! $customer_id ) {
				continue;
			}
			$caller_id = ! empty( $record['session']['caller_id'] ) ? (string) $record['session']['caller_id'] : '';
			$record['optical'] = self::signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, true );
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

		/* Exactly one RX snapshot + one inventory snapshot for the whole batch. */
		$force     = ! empty( $_POST['refresh'] );
		$snapshot  = AFC_OLT::get_snapshot( $force );
		$inventory = AFC_OLT_Inventory::get_inventory( $force );
		$signals   = array();
		$matched   = array( 'mac' => 0, 'suggested' => 0 );

		foreach ( $customers as $item ) {
			$customer_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
				continue;
			}

			$caller_id = isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : '';
			$username  = isset( $item['username'] ) ? sanitize_text_field( $item['username'] ) : (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			$before    = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
			$signal    = self::signal_for_customer( $customer_id, $caller_id, $username, $snapshot, $inventory, true );
			$after     = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );

			if ( ! $before && $after && ! empty( $signal['auto_matched'] ) ) {
				$matched['mac']++;
			}
			if ( ! empty( $signal['suggested'] ) ) {
				$matched['suggested']++;
			}
			$signals[ (string) $customer_id ] = $signal;
		}

		wp_send_json_success(
			array(
				'signals'   => $signals,
				'summary'   => AFC_OLT::snapshot_summary( $snapshot ),
				'inventory' => array(
					'count'        => isset( $inventory['count'] ) ? (int) $inventory['count'] : 0,
					'collected_at' => isset( $inventory['collected_at'] ) ? $inventory['collected_at'] : '',
					'columns'      => isset( $inventory['columns'] ) ? $inventory['columns'] : array(),
					'stale'        => ! empty( $inventory['stale'] ),
					'error'        => isset( $inventory['error'] ) ? $inventory['error'] : '',
				),
				'matched' => $matched,
			)
		);
	}
}
