<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reads the ONU inventory table separately from the normal page request and
 * correlates ONU MAC/description values with MikroTik PPP users.
 */
class AFC_OLT_Inventory {

	const INVENTORY_TRANSIENT = 'afc_olt_onu_inventory_v1';
	const INVENTORY_OPTION    = 'afc_olt_onu_inventory_last_v1';
	const INVENTORY_LOCK      = 'afc_olt_onu_inventory_lock_v1';
	const CACHE_TTL           = 300;
	const IF_DESCR_OID        = '1.3.6.1.2.1.2.2.1.2';

	public static function init() {
		/* Replace the original optical-customer endpoint with the inventory-aware bulk endpoint. */
		remove_action( 'wp_ajax_afc_get_olt_customer_signals', array( 'AFC_OLT', 'ajax_customer_signals' ) );
		add_action( 'wp_ajax_afc_get_olt_customer_signals', array( __CLASS__, 'ajax_customer_signals' ) );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view optical monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
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

		return (string) openssl_decrypt(
			substr( $data, 28 ),
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			substr( $data, 0, 12 ),
			substr( $data, 12, 16 )
		);
	}

	private static function walk_oid( $settings, $oid ) {
		/*
		 * Keep inventory collection on the same transport path as RX polling.
		 * V1600G-family GPON firmware commonly times out on GETBULK but answers
		 * the bounded GETNEXT walker implemented by AFC_OLT reliably.
		 */
		return AFC_OLT::walk_configured_oid( $settings, $oid );
	}

	private static function table_root( $rx_oid ) {
		$parts = array_values( array_filter( explode( '.', trim( (string) $rx_oid, '.' ) ), 'strlen' ) );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		array_pop( $parts );
		return implode( '.', $parts );
	}

	private static function rx_column( $rx_oid ) {
		$parts = array_values( array_filter( explode( '.', trim( (string) $rx_oid, '.' ) ), 'strlen' ) );
		return $parts ? absint( end( $parts ) ) : 0;
	}

	private static function clean_value( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/^(?:STRING|OCTET STRING|HEX-STRING|INTEGER|Gauge32|Counter32|Counter64|Timeticks):\s*/i', '', $value );
		return trim( $value, "\"' \t\n\r\0\x0B" );
	}

	public static function normalize_mac( $value ) {
		$value = self::clean_value( $value );
		$value = preg_replace( '/^0x/i', '', $value );
		$hex   = strtoupper( preg_replace( '/[^a-f0-9]/i', '', $value ) );
		return 12 === strlen( $hex ) ? implode( ':', str_split( $hex, 2 ) ) : '';
	}

	private static function parse_instance( $instance_oid, $root ) {
		$numeric = preg_replace( '/[^0-9.]/', '', (string) $instance_oid );
		$root    = trim( $root, '.' );
		if ( ! preg_match( '/(?:^|\.)' . preg_quote( $root, '/' ) . '\.(\d+)\.(\d+)\.(\d+)$/', $numeric, $matches ) ) {
			return null;
		}
		return array(
			'column' => absint( $matches[1] ),
			'pon'    => absint( $matches[2] ),
			'onu'    => absint( $matches[3] ),
		);
	}

	private static function is_online_value( $value ) {
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/\boffline\b/', $value ) ) {
			return false;
		}
		if ( preg_match( '/\bonline\b/', $value ) ) {
			return true;
		}
		return null;
	}

	private static function looks_like_onu_type( $value ) {
		return (bool) preg_match( '/(?:\b\d+(?:GE|FE)\b|POTS|WiFi|CATV|EPON|GPON|XPON)/i', $value );
	}

	private static function column_profiles( $columns, $rx_column ) {
		$profiles = array();
		foreach ( $columns as $column => $values ) {
			$profile = array(
				'column'      => (int) $column,
				'total'       => count( $values ),
				'mac'         => 0,
				'status'      => 0,
				'type'        => 0,
				'text'        => 0,
				'unique_text' => 0,
			);
			$unique = array();
			foreach ( $values as $value ) {
				$clean = self::clean_value( $value );
				if ( self::normalize_mac( $clean ) ) {
					$profile['mac']++;
				}
				if ( null !== self::is_online_value( $clean ) ) {
					$profile['status']++;
				}
				if ( self::looks_like_onu_type( $clean ) ) {
					$profile['type']++;
				}
				if (
					'' !== $clean &&
					'N/A' !== strtoupper( $clean ) &&
					! is_numeric( $clean ) &&
					! self::normalize_mac( $clean ) &&
					null === self::is_online_value( $clean ) &&
					false === stripos( $clean, 'dBm' ) &&
					false === stripos( $clean, 'mW' )
				) {
					$profile['text']++;
					$unique[ strtolower( $clean ) ] = true;
				}
			}
			$profile['unique_text'] = count( $unique );
			$profile['is_rx']       = (int) $column === (int) $rx_column;
			$profiles[ $column ]     = $profile;
		}
		return $profiles;
	}

	private static function pick_columns( $profiles ) {
		$mac_column         = 0;
		$status_column      = 0;
		$type_column        = 0;
		$description_column = 0;
		$best_description   = -1;

		foreach ( $profiles as $column => $profile ) {
			$total = max( 1, (int) $profile['total'] );
			if ( $profile['mac'] / $total >= 0.55 ) {
				$mac_column = (int) $column;
			}
			if ( $profile['status'] / $total >= 0.55 ) {
				$status_column = (int) $column;
			}
			if ( $profile['type'] / $total >= 0.45 ) {
				$type_column = (int) $column;
			}

			if ( $profile['is_rx'] || $profile['mac'] || $profile['status'] || $profile['type'] ) {
				continue;
			}
			$score = (int) $profile['unique_text'] * 3 + (int) $profile['text'];
			if ( $profile['text'] >= 3 && $score > $best_description ) {
				$best_description   = $score;
				$description_column = (int) $column;
			}
		}

		return array(
			'mac'         => $mac_column,
			'status'      => $status_column,
			'type'        => $type_column,
			'description' => $description_column,
		);
	}

	private static function build_inventory( $walk, $root, $rx_column, $node = array() ) {
		$columns = array();
		$rows    = array();
		$olt_id  = AFC_OLT::normalize_olt_id( isset( $node['id'] ) ? $node['id'] : 'primary' );
		$olt_name = isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : __( 'OLT', 'airfiber-centralized' );
		$technology = isset( $node['technology'] ) ? sanitize_text_field( $node['technology'] ) : '';

		foreach ( $walk as $instance_oid => $raw_value ) {
			$instance = self::parse_instance( $instance_oid, $root );
			if ( ! $instance || $instance['pon'] < 1 || $instance['onu'] < 1 ) {
				continue;
			}
			$key = AFC_OLT::entry_key( $olt_id, $instance['pon'], $instance['onu'] );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'olt_id'      => $olt_id,
					'olt_name'    => $olt_name,
					'technology'  => $technology,
					'pon'         => $instance['pon'],
					'onu'         => $instance['onu'],
					'description' => '',
					'mac'         => '',
					'online'      => null,
					'onu_type'    => '',
				);
			}
			$column = $instance['column'];
			if ( ! isset( $columns[ $column ] ) ) {
				$columns[ $column ] = array();
			}
			$columns[ $column ][ $key ] = $raw_value;
		}

		$profiles = self::column_profiles( $columns, $rx_column );
		$picked   = self::pick_columns( $profiles );

		foreach ( $rows as $key => &$row ) {
			if ( $picked['mac'] && isset( $columns[ $picked['mac'] ][ $key ] ) ) {
				$row['mac'] = self::normalize_mac( $columns[ $picked['mac'] ][ $key ] );
			}
			if ( $picked['status'] && isset( $columns[ $picked['status'] ][ $key ] ) ) {
				$row['online'] = self::is_online_value( self::clean_value( $columns[ $picked['status'] ][ $key ] ) );
			}
			if ( $picked['type'] && isset( $columns[ $picked['type'] ][ $key ] ) ) {
				$row['onu_type'] = self::clean_value( $columns[ $picked['type'] ][ $key ] );
			}
			if ( $picked['description'] && isset( $columns[ $picked['description'] ][ $key ] ) ) {
				$description = self::clean_value( $columns[ $picked['description'] ][ $key ] );
				if ( 'N/A' !== strtoupper( $description ) ) {
					$row['description'] = $description;
				}
			}
		}
		unset( $row );

		return array(
			'entries'      => $rows,
			'columns'      => $picked,
			'profiles'     => $profiles,
			'count'        => count( $rows ),
			'collected_at' => current_time( 'mysql' ),
			'collected_ts' => time(),
			'root_oid'     => $root,
			'source'       => 'live',
			'stale'        => false,
		);
	}

	/** Build an identity inventory from the RX snapshot that was just polled. */
	private static function inventory_from_snapshot( $snapshot, $node ) {
		$rows       = array();
		$olt_id     = AFC_OLT::normalize_olt_id( isset( $node['id'] ) ? $node['id'] : 'primary' );
		$olt_name   = isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : __( 'OLT', 'airfiber-centralized' );
		$technology = isset( $node['technology'] ) ? sanitize_text_field( $node['technology'] ) : '';

		foreach ( isset( $snapshot['entries'] ) ? (array) $snapshot['entries'] : array() as $key => $entry ) {
			$location = AFC_OLT::entry_location( $key, is_array( $entry ) ? $entry : array() );
			if ( $olt_id !== $location['olt_id'] || $location['pon'] < 1 || $location['onu'] < 1 ) continue;
			$entry_key = AFC_OLT::entry_key( $olt_id, $location['pon'], $location['onu'] );
			$rows[ $entry_key ] = array(
				'olt_id'      => $olt_id,
				'olt_name'    => $olt_name,
				'technology'  => $technology,
				'pon'         => $location['pon'],
				'onu'         => $location['onu'],
				'description' => '',
				'mac'         => '',
				'online'      => null,
				'onu_type'    => '',
			);
		}

		return array(
			'entries'        => $rows,
			'columns'        => array(),
			'profiles'       => array(),
			'count'          => count( $rows ),
			'identity_count' => 0,
			'collected_at'   => current_time( 'mysql' ),
			'collected_ts'   => time(),
			'root_oid'       => isset( $node['config']['rx_oid'] ) ? ltrim( (string) $node['config']['rx_oid'], '.' ) : '',
			'source'         => 'rx-snapshot',
			'stale'          => false,
		);
	}

	/** Parse VSOL IF-MIB labels such as "GPON01ONU14 BenchCasas_3_1000". */
	private static function parse_pon_interface_description( $raw_value, $technology = '' ) {
		$value      = self::clean_value( $raw_value );
		$technology = strtoupper( trim( (string) $technology ) );
		$prefix     = in_array( $technology, array( 'GPON', 'EPON' ), true ) ? $technology : '(?:GPON|EPON)';
		if ( ! preg_match( '/^' . $prefix . '\s*0*(\d+)\s*ONU\s*0*(\d+)(?:\s+(.+))?$/i', $value, $matches ) ) {
			return null;
		}
		$pon = absint( $matches[1] );
		$onu = absint( $matches[2] );
		if ( $pon < 1 || $onu < 1 ) return null;
		return array(
			'pon'         => $pon,
			'onu'         => $onu,
			'description' => isset( $matches[3] ) ? trim( $matches[3] ) : '',
		);
	}

	/** Merge each OLT's authoritative PON/ONU names into its current RX rows. */
	private static function enrich_interface_descriptions( $inventory, $settings, $node ) {
		$walk = self::walk_oid( $settings, self::IF_DESCR_OID );
		if ( is_wp_error( $walk ) ) {
			$inventory['identity_error'] = $walk->get_error_message();
			return $inventory;
		}

		$olt_id         = AFC_OLT::normalize_olt_id( isset( $node['id'] ) ? $node['id'] : 'primary' );
		$technology     = isset( $node['technology'] ) ? (string) $node['technology'] : '';
		$identity_count = 0;
		foreach ( $walk as $instance_oid => $raw_value ) {
			$identity = self::parse_pon_interface_description( $raw_value, $technology );
			if ( ! $identity ) continue;
			$key = AFC_OLT::entry_key( $olt_id, $identity['pon'], $identity['onu'] );
			if ( empty( $inventory['entries'][ $key ] ) ) continue;
			if ( '' !== $identity['description'] ) {
				$inventory['entries'][ $key ]['description'] = sanitize_text_field( $identity['description'] );
				$identity_count++;
			}
			$parts = explode( '.', trim( preg_replace( '/[^0-9.]/', '', (string) $instance_oid ), '.' ) );
			$inventory['entries'][ $key ]['if_index']        = $parts ? absint( end( $parts ) ) : 0;
			$inventory['entries'][ $key ]['identity_source'] = 'ifDescr';
		}

		$inventory['identity_count'] = $identity_count;
		$inventory['identity_oid']   = self::IF_DESCR_OID;
		$inventory['columns']['description'] = self::IF_DESCR_OID;
		return $inventory;
	}

	private static function saved_inventory( $message = '' ) {
		$cached = get_transient( self::INVENTORY_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
			$cached['source'] = 'cache';
			return $cached;
		}
		$last = get_option( self::INVENTORY_OPTION, array() );
		if ( is_array( $last ) && ! empty( $last['entries'] ) ) {
			$last['source'] = 'stale';
			$last['stale']  = true;
			$last['error']  = $message;
			return $last;
		}
		return array(
			'entries'      => array(),
			'columns'      => array(),
			'count'        => 0,
			'collected_at' => '',
			'source'       => 'unavailable',
			'stale'        => false,
			'error'        => $message,
		);
	}

	public static function get_inventory( $force = false ) {
		if ( ! AFC_OLT::is_enabled() ) {
			return self::saved_inventory( __( 'OLT monitoring is disabled.', 'airfiber-centralized' ) );
		}
		if ( ! $force ) {
			$cached = get_transient( self::INVENTORY_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached['entries'] ) ) {
				$cached['source'] = 'cache';
				return $cached;
			}
		}
		if ( get_transient( self::INVENTORY_LOCK ) ) {
			return self::saved_inventory( __( 'Another ONU inventory refresh is already running.', 'airfiber-centralized' ) );
		}

		set_transient( self::INVENTORY_LOCK, time(), 210 );
		try {
			$inventory = self::poll_all_nodes();
		} finally {
			delete_transient( self::INVENTORY_LOCK );
		}

		if ( is_wp_error( $inventory ) ) {
			return self::saved_inventory( $inventory->get_error_message() );
		}
		if ( empty( $inventory['entries'] ) ) {
			return self::saved_inventory( __( 'The ONU inventory table returned no usable PON/ONU rows.', 'airfiber-centralized' ) );
		}

		set_transient( self::INVENTORY_TRANSIENT, $inventory, self::CACHE_TTL );
		update_option( self::INVENTORY_OPTION, $inventory, false );
		return $inventory;
	}

	private static function poll_all_nodes() {
		$entries       = array();
		$node_results  = array();
		$success_count = 0;
		$first_columns = array();
		$first_root    = '';

		foreach ( AFC_OLT::monitoring_nodes() as $olt_id => $node ) {
			$result = self::poll_node_inventory( $node );
			if ( is_wp_error( $result ) ) {
				$node_results[ $olt_id ] = array(
					'id'         => $olt_id,
					'name'       => $node['name'],
					'technology' => $node['technology'],
					'available'  => false,
					'count'      => 0,
					'error'      => $result->get_error_message(),
				);
				continue;
			}

			$success_count++;
			$entries = array_replace( $entries, $result['entries'] );
			if ( ! $first_columns ) {
				$first_columns = $result['columns'];
				$first_root    = $result['root_oid'];
			}
			$node_results[ $olt_id ] = array(
				'id'         => $olt_id,
				'name'       => $node['name'],
				'technology' => $node['technology'],
				'available'  => true,
				'count'      => (int) $result['count'],
				'columns'    => $result['columns'],
				'root_oid'   => $result['root_oid'],
				'error'      => '',
			);
		}

		if ( 0 === $success_count ) {
			$messages = array();
			foreach ( $node_results as $node ) {
				if ( ! empty( $node['error'] ) ) {
					$messages[] = $node['name'] . ': ' . $node['error'];
				}
			}
			return new WP_Error( 'afc_olt_all_inventory_walks_failed', implode( ' ', $messages ) );
		}

		$errors = array();
		foreach ( $node_results as $node ) {
			if ( ! empty( $node['error'] ) ) {
				$errors[] = $node['name'] . ': ' . $node['error'];
			}
		}

		return array(
			'entries'        => $entries,
			'columns'        => $first_columns,
			'nodes'          => $node_results,
			'node_count'     => count( $node_results ),
			'available_nodes'=> $success_count,
			'count'          => count( $entries ),
			'collected_at'   => current_time( 'mysql' ),
			'collected_ts'   => time(),
			'root_oid'       => $first_root,
			'source'         => 'live',
			'stale'          => false,
			'partial'        => $success_count < count( $node_results ),
			'error'          => implode( ' ', $errors ),
		);
	}

	public static function poll_node_inventory( $node ) {
		$settings = isset( $node['config'] ) && is_array( $node['config'] ) ? $node['config'] : array();
		if ( empty( $settings['version'] ) || ! AFC_OLT::is_snmp_available( $settings['version'] ) ) {
			return new WP_Error( 'afc_olt_inventory_snmp_missing', __( 'The PHP SNMP extension is not available for this OLT profile.', 'airfiber-centralized' ) );
		}
		/* Reuse the fresh RX snapshot instead of walking every diagnostic column a
		 * second time. This keeps a two-OLT refresh fast and avoids treating
		 * temperature/model fields as customer names. */
		$snapshot  = AFC_OLT::get_snapshot( false );
		$inventory = is_wp_error( $snapshot ) ? array() : self::inventory_from_snapshot( $snapshot, $node );
		if ( empty( $inventory['entries'] ) ) {
			$snapshot = AFC_OLT::poll_node_rx_power( $node );
			if ( is_wp_error( $snapshot ) ) return $snapshot;
			$inventory = self::inventory_from_snapshot( $snapshot, $node );
		}
		$inventory = self::enrich_interface_descriptions( $inventory, $settings, $node );
		return empty( $inventory['entries'] )
			? new WP_Error( 'afc_olt_inventory_empty', __( 'The ONU inventory table returned no usable PON/ONU rows.', 'airfiber-centralized' ) )
			: $inventory;
	}

	/** Refresh one OLT inventory without discarding the saved rows of its peers. */
	public static function refresh_node_inventory( $node ) {
		$result = self::poll_node_inventory( $node );
		if ( is_wp_error( $result ) ) return $result;

		$olt_id = AFC_OLT::normalize_olt_id( isset( $node['id'] ) ? $node['id'] : 'primary' );
		$saved  = get_option( self::INVENTORY_OPTION, array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$entries = isset( $saved['entries'] ) && is_array( $saved['entries'] ) ? $saved['entries'] : array();
		foreach ( $entries as $key => $entry ) {
			$location = AFC_OLT::entry_location( $key, is_array( $entry ) ? $entry : array() );
			if ( $olt_id === $location['olt_id'] ) unset( $entries[ $key ] );
		}
		$entries = array_replace( $entries, $result['entries'] );
		$nodes   = isset( $saved['nodes'] ) && is_array( $saved['nodes'] ) ? $saved['nodes'] : array();
		$nodes[ $olt_id ] = array(
			'id' => $olt_id, 'name' => isset( $node['name'] ) ? $node['name'] : '',
			'technology' => isset( $node['technology'] ) ? $node['technology'] : '',
			'available' => true, 'count' => (int) $result['count'],
			'columns' => $result['columns'], 'root_oid' => $result['root_oid'], 'error' => '',
		);
		$available = count( array_filter( $nodes, function ( $item ) { return ! empty( $item['available'] ); } ) );
		$inventory = array_merge(
			$saved,
			array(
				'entries' => $entries, 'nodes' => $nodes, 'node_count' => count( AFC_OLT::monitoring_nodes() ),
				'available_nodes' => $available, 'count' => count( $entries ),
				'collected_at' => current_time( 'mysql' ), 'collected_ts' => time(),
				'source' => 'node-refresh', 'stale' => false,
			)
		);
		set_transient( self::INVENTORY_TRANSIENT, $inventory, self::CACHE_TTL );
		update_option( self::INVENTORY_OPTION, $inventory, false );
		return $inventory;
	}

	private static function identity_variants( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return array();
		}
		$variants = array();
		$compact  = preg_replace( '/[^a-z0-9]/', '', $value );
		if ( $compact ) {
			$variants[ $compact ] = true;
		}
		$base = preg_replace( '/(?:[_\-. ]+\d+){1,3}$/', '', $value );
		$base = preg_replace( '/[^a-z0-9]/', '', $base );
		if ( $base ) {
			$variants[ $base ] = true;
		}
		return array_keys( $variants );
	}

	private static function similarity( $first, $second ) {
		if ( '' === $first || '' === $second ) {
			return 0;
		}
		$max = max( strlen( $first ), strlen( $second ) );
		if ( 0 === $max ) {
			return 1;
		}
		return max( 0, 1 - ( levenshtein( $first, $second ) / $max ) );
	}

	public static function find_match( $caller_id, $username, $inventory ) {
		$entries = isset( $inventory['entries'] ) && is_array( $inventory['entries'] ) ? $inventory['entries'] : array();
		$mac     = self::normalize_mac( $caller_id );

		if ( $mac ) {
			$matches = array_values( array_filter( $entries, function ( $entry ) use ( $mac ) {
				return ! empty( $entry['mac'] ) && 0 === strcasecmp( $entry['mac'], $mac );
			} ) );
			if ( 1 === count( $matches ) ) {
				$match = $matches[0];
				$match['match_method'] = 'mac';
				$match['confidence']   = 100;
				return $match;
			}
		}

		$user_variants = self::identity_variants( $username );
		if ( ! $user_variants ) {
			return null;
		}

		$exact = array();
		foreach ( $entries as $entry ) {
			if ( empty( $entry['description'] ) ) {
				continue;
			}
			$description_variants = self::identity_variants( $entry['description'] );
			if ( array_intersect( $user_variants, $description_variants ) ) {
				$entry['match_method'] = 'description';
				$entry['confidence']   = 96;
				$exact[] = $entry;
			}
		}
		if ( 1 === count( $exact ) ) {
			return $exact[0];
		}

		$best = null;
		$best_score = 0;
		$second_score = 0;
		foreach ( $entries as $entry ) {
			if ( empty( $entry['description'] ) ) {
				continue;
			}
			$score = 0;
			foreach ( $user_variants as $user_value ) {
				foreach ( self::identity_variants( $entry['description'] ) as $description_value ) {
					$score = max( $score, self::similarity( $user_value, $description_value ) );
				}
			}
			if ( $score > $best_score ) {
				$second_score = $best_score;
				$best_score   = $score;
				$best         = $entry;
			} elseif ( $score > $second_score ) {
				$second_score = $score;
			}
		}

		if ( $best && $best_score >= 0.86 && $best_score - $second_score >= 0.04 ) {
			$best['match_method'] = 'description_fuzzy';
			$best['confidence']   = (int) round( $best_score * 100 );
			return $best;
		}
		return null;
	}

	private static function save_exact_mac_binding( $customer_id, $match ) {
		if ( empty( $match['pon'] ) || empty( $match['onu'] ) || 'mac' !== $match['match_method'] ) {
			return false;
		}
		update_post_meta( $customer_id, '_afc_olt_id', AFC_OLT::normalize_olt_id( isset( $match['olt_id'] ) ? $match['olt_id'] : 'primary' ) );
		update_post_meta( $customer_id, '_afc_olt_pon', absint( $match['pon'] ) );
		update_post_meta( $customer_id, '_afc_olt_onu', absint( $match['onu'] ) );
		if ( ! empty( $match['mac'] ) ) {
			update_post_meta( $customer_id, '_afc_olt_onu_mac', $match['mac'] );
		}
		if ( ! empty( $match['description'] ) ) {
			update_post_meta( $customer_id, '_afc_olt_description', sanitize_text_field( $match['description'] ) );
		}
		update_post_meta( $customer_id, '_afc_olt_match_method', 'mac' );
		return true;
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

		$force     = ! empty( $_POST['refresh'] );
		$snapshot  = AFC_OLT::get_snapshot( $force );
		$inventory = self::get_inventory( $force );
		$signals   = array();
		$matched   = array( 'mac' => 0, 'description' => 0, 'suggested' => 0 );

		foreach ( $customers as $item ) {
			$customer_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
				continue;
			}

			$caller_id = isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : '';
			$username  = isset( $item['username'] ) ? sanitize_text_field( $item['username'] ) : (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			$signal    = AFC_OLT::get_customer_signal( $customer_id, $snapshot );

			if ( empty( $signal['mapped'] ) ) {
				$match = self::find_match( $caller_id, $username, $inventory );
				if ( $match && 'mac' === $match['match_method'] && self::save_exact_mac_binding( $customer_id, $match ) ) {
					$signal = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
					$signal['auto_matched'] = true;
					$signal['match_method'] = 'mac';
					$signal['confidence']   = 100;
					$matched['mac']++;
				} elseif ( $match ) {
					$signal['suggested'] = array(
						'olt_id'       => isset( $match['olt_id'] ) ? AFC_OLT::normalize_olt_id( $match['olt_id'] ) : 'primary',
						'olt_name'     => isset( $match['olt_name'] ) ? (string) $match['olt_name'] : '',
						'pon'          => (int) $match['pon'],
						'onu'          => (int) $match['onu'],
						'mac'          => isset( $match['mac'] ) ? $match['mac'] : '',
						'description'  => isset( $match['description'] ) ? $match['description'] : '',
						'match_method' => $match['match_method'],
						'confidence'   => $match['confidence'],
					);
					$matched['suggested']++;
					if ( 'description' === $match['match_method'] ) {
						$matched['description']++;
					}
				}
			}

			$key = ! empty( $signal['pon'] ) && ! empty( $signal['onu'] )
				? AFC_OLT::entry_key( isset( $signal['olt_id'] ) ? $signal['olt_id'] : 'primary', $signal['pon'], $signal['onu'] )
				: '';
			if ( $key && ! empty( $inventory['entries'][ $key ] ) ) {
				$onu = $inventory['entries'][ $key ];
				$signal['description'] = isset( $onu['description'] ) ? $onu['description'] : '';
				$signal['onu_type']    = isset( $onu['onu_type'] ) ? $onu['onu_type'] : '';
				$signal['onu_online']  = isset( $onu['online'] ) ? $onu['online'] : null;
				if ( ! empty( $onu['mac'] ) ) {
					$signal['onu_mac'] = $onu['mac'];
				}
				if ( false === $signal['onu_online'] ) {
					$signal['rx_power'] = null;
					$signal['status']   = 'offline';
				}
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
				'matched'   => $matched,
			)
		);
	}
}
