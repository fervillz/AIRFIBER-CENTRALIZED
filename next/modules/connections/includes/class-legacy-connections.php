<?php

namespace Airfiber\Next\Modules\Connections;

use Airfiber\Next\Connection_Health;
use Airfiber\Next\Connection_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only bridge for connection cards that still belong to Airfiber Classic.
 *
 * This does not copy credentials or move ownership. It lets BETA show the
 * user's existing OLT, MikroTik and Google Sheets connections while those
 * feature modules are migrated one by one.
 */
class Legacy_Connections {
	const OPTION_ORDER = 'afc_connections_card_order';

	public static function cards() {
		$cards  = self::olt_cards();
		$router = self::mikrotik_card();
		$sheet  = self::sheet_card();

		if ( $router ) {
			$cards[] = $router;
		}
		if ( $sheet ) {
			$cards[] = $sheet;
		}

		return self::ordered( $cards );
	}

	private static function olt_cards() {
		if ( ! class_exists( '\\AFC_OLT_Manager' ) ) {
			return array();
		}

		$native_hosts = self::verified_native_olt_hosts();
		$posts        = get_posts(
			array(
				'post_type'      => \AFC_OLT_Manager::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 100,
				'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$cards = array();
		foreach ( $posts as $post ) {
			$config = get_post_meta( $post->ID, \AFC_OLT_Manager::CONFIG_META, true );
			$device = get_post_meta( $post->ID, \AFC_OLT_Manager::DEVICE_META, true );
			$config = is_array( $config ) ? $config : array();
			$device = is_array( $device ) ? $device : array();
			$state  = 'unconfigured';

			$technology = isset( $config['technology'] ) && 'EPON' === strtoupper( $config['technology'] ) ? 'EPON' : 'GPON';
			$host       = ! empty( $config['host'] ) ? sanitize_text_field( $config['host'] ) : '';
			$host_key   = self::host_key( $host );

			// Keep Classic visible until the same native OLT has passed a real test.
			if ( $host_key && isset( $native_hosts[ $host_key ] ) ) {
				continue;
			}

			if ( 'publish' === $post->post_status ) {
				if ( get_post_meta( $post->ID, \AFC_OLT_Manager::DISCONNECTED_META, true ) ) {
					$state = 'offline';
				} elseif ( isset( $device['test_status'] ) && 'success' === $device['test_status'] ) {
					$state = 'online';
				} else {
					$state = 'warning';
				}
			}

			$subtitle = ! empty( $device['name'] ) ? sanitize_text_field( $device['name'] ) : ( $host ? $host : __( 'OLT not configured', 'airfiber-centralized' ) );

			$cards[] = array(
				'key'         => 'olt:' . (int) $post->ID,
				'id'          => 'classic_olt_' . (int) $post->ID,
				'type'        => 'classic-olt',
				'provider'    => $technology . ' OLT',
				'group'       => 'network',
				'icon'        => 'server',
				'name'        => sanitize_text_field( $post->post_title ),
				'subtitle'    => $subtitle,
				'endpoint'    => $host,
				'meta'        => $technology,
				'state'       => $state,
				'status'      => self::state_label( $state ),
				'checked_at'  => 0,
				'latency_ms'  => 0,
				'source'      => 'classic',
				'readonly'    => true,
				'description' => __( 'Managed by the existing Airfiber Classic OLT settings until a verified native BETA OLT connection replaces this endpoint.', 'airfiber-centralized' ),
			);
		}

		return $cards;
	}

	/**
	 * A native OLT only replaces its Classic card after an explicit successful
	 * health test. Failed/unverified native setup therefore cannot hide a working
	 * Classic migration fallback.
	 */
	private static function verified_native_olt_hosts() {
		$output = array();
		foreach ( Connection_Store::for_module( 'olt' ) as $id => $record ) {
			if ( 'olt-snmp' !== ( isset( $record['type'] ) ? $record['type'] : '' ) ) {
				continue;
			}
			$health = Connection_Health::get( $id );
			if ( 'online' !== ( isset( $health['state'] ) ? $health['state'] : '' ) ) {
				continue;
			}
			$host = self::host_key( isset( $record['endpoint'] ) ? $record['endpoint'] : '' );
			if ( $host ) {
				$output[ $host ] = true;
			}
		}
		return $output;
	}

	private static function host_key( $host ) {
		$host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
		$host = preg_replace( '#^(?:https?://|udp:)#i', '', $host );
		$host = preg_replace( '/:\d+$/', '', $host );
		return trim( $host, '/ ' );
	}

	private static function mikrotik_card() {
		if ( ! class_exists( '\\AFC_MikroTik' ) ) {
			return null;
		}

		$stored = get_option( \AFC_MikroTik::OPTION_KEY, null );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$settings = \AFC_MikroTik::get_settings();
		$status   = get_option( 'afc_mikrotik_last_status', array() );
		$state    = 'unconfigured';
		if ( ! empty( $status['status'] ) ) {
			$state = 'success' === $status['status'] ? 'online' : 'warning';
		}

		$host = ! empty( $settings['host'] ) ? sanitize_text_field( $settings['host'] ) : '';
		$port = ! empty( $settings['port'] ) ? absint( $settings['port'] ) : 0;

		return array(
			'key'         => 'mikrotik:primary',
			'id'          => 'classic_mikrotik_primary',
			'type'        => 'classic-mikrotik',
			'provider'    => 'MikroTik',
			'group'       => 'network',
			'icon'        => 'router',
			'name'        => ! empty( $settings['name'] ) ? sanitize_text_field( $settings['name'] ) : __( 'Main Router', 'airfiber-centralized' ),
			'subtitle'    => $host ? $host . ( $port ? ':' . $port : '' ) : __( 'Router not configured', 'airfiber-centralized' ),
			'endpoint'    => $host,
			'meta'        => ! empty( $settings['username'] ) ? sanitize_text_field( $settings['username'] ) : '',
			'state'       => $state,
			'status'      => self::state_label( $state ),
			'checked_at'  => 0,
			'latency_ms'  => 0,
			'source'      => 'classic',
			'readonly'    => true,
			'description' => __( 'Managed by the existing Airfiber Classic MikroTik settings until the MikroTik module is migrated to BETA.', 'airfiber-centralized' ),
		);
	}

	private static function sheet_card() {
		if ( ! class_exists( '\\AFC_Integrations' ) ) {
			return null;
		}

		$settings    = get_option( \AFC_Integrations::OPTION_SETTINGS, array() );
		$credentials = get_option( \AFC_Integrations::OPTION_CREDENTIALS, array() );
		$settings    = is_array( $settings ) ? $settings : array();
		if ( empty( $settings ) && empty( $credentials ) ) {
			return null;
		}

		$connected = ! empty( $settings['connected'] );
		$error     = ! empty( $settings['last_error'] );
		$state     = $connected ? 'online' : ( $error ? 'warning' : 'unconfigured' );

		return array(
			'key'         => 'sheet:primary',
			'id'          => 'classic_sheet_primary',
			'type'        => 'classic-google-sheets',
			'provider'    => 'Google Sheets',
			'group'       => 'cloud',
			'icon'        => 'cloud',
			'name'        => ! empty( $settings['sheet_title'] ) ? sanitize_text_field( $settings['sheet_title'] ) : __( 'Primary Google Sheet', 'airfiber-centralized' ),
			'subtitle'    => ! empty( $settings['service_email'] ) ? sanitize_email( $settings['service_email'] ) : __( 'Google Sheets', 'airfiber-centralized' ),
			'endpoint'    => '',
			'meta'        => ! empty( $settings['last_success'] ) ? sprintf( __( 'Last test %s', 'airfiber-centralized' ), sanitize_text_field( $settings['last_success'] ) ) : '',
			'state'       => $state,
			'status'      => self::state_label( $state ),
			'checked_at'  => 0,
			'latency_ms'  => 0,
			'source'      => 'classic',
			'readonly'    => true,
			'description' => __( 'Managed by the existing Airfiber Classic Google Sheets integration until that integration is migrated to BETA.', 'airfiber-centralized' ),
		);
	}

	private static function ordered( $cards ) {
		$saved = get_option( self::OPTION_ORDER, array() );
		$saved = is_array( $saved ) ? array_values( array_filter( array_map( 'sanitize_text_field', $saved ) ) ) : array();
		$map   = array();
		foreach ( $cards as $card ) {
			$map[ $card['key'] ] = $card;
		}

		$output = array();
		foreach ( $saved as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$output[] = $map[ $key ];
				unset( $map[ $key ] );
			}
		}
		foreach ( $cards as $card ) {
			if ( isset( $map[ $card['key'] ] ) ) {
				$output[] = $card;
				unset( $map[ $card['key'] ] );
			}
		}
		return $output;
	}

	private static function state_label( $state ) {
		switch ( $state ) {
			case 'online':
				return __( 'Connected', 'airfiber-centralized' );
			case 'offline':
				return __( 'Offline', 'airfiber-centralized' );
			case 'warning':
				return __( 'Needs attention', 'airfiber-centralized' );
			case 'unconfigured':
				return __( 'Not configured', 'airfiber-centralized' );
			default:
				return __( 'Unknown', 'airfiber-centralized' );
		}
	}
}
