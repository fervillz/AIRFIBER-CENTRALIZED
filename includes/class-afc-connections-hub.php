<?php

defined( 'ABSPATH' ) || exit;

/**
 * Unified Advanced-mode library for Airfiber connections.
 *
 * OLT, MikroTik and Google Sheets keep their existing owners/settings screens.
 * This class only supplies a compact shared card registry and persists card order.
 */
class AFC_Connections_Hub {

	const NONCE        = 'afc_connections_hub';
	const OPTION_ORDER = 'afc_connections_card_order';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1400 );
		add_action( 'wp_ajax_afc_connections_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_afc_connections_save_order', array( __CLASS__, 'ajax_save_order' ) );
	}

	public static function enqueue_assets() {
		if (
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request() ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		$css = AFC_PATH . 'assets/css/connections-hub.css';
		$js  = AFC_PATH . 'assets/js/connections-hub.js';

		wp_enqueue_style(
			'afc-connections-hub',
			AFC_URL . 'assets/css/connections-hub.css',
			array( 'afc-advanced-workspace' ),
			file_exists( $css ) ? (string) filemtime( $css ) : AFC_VERSION
		);

		wp_enqueue_script(
			'afc-connections-hub',
			AFC_URL . 'assets/js/connections-hub.js',
			array( 'afc-advanced-workspace' ),
			file_exists( $js ) ? (string) filemtime( $js ) : AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-connections-hub',
			'afcConnectionsHub',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'cards'   => self::ordered_cards(),
				'labels'  => array(
					'title'    => __( 'Connections', 'airfiber-centralized' ),
					'subtitle' => __( 'Devices & integrations', 'airfiber-centralized' ),
				),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage connections.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function olt_cards() {
		if ( ! class_exists( 'AFC_OLT_Manager' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => AFC_OLT_Manager::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$cards = array();
		foreach ( $posts as $post ) {
			$config = get_post_meta( $post->ID, AFC_OLT_Manager::CONFIG_META, true );
			$device = get_post_meta( $post->ID, AFC_OLT_Manager::DEVICE_META, true );
			$config = is_array( $config ) ? $config : array();
			$device = is_array( $device ) ? $device : array();
			$state  = 'draft';
			if ( 'publish' === $post->post_status ) {
				if ( get_post_meta( $post->ID, AFC_OLT_Manager::DISCONNECTED_META, true ) ) {
					$state = 'offline';
				} elseif ( isset( $device['test_status'] ) && 'success' === $device['test_status'] ) {
					$state = 'online';
				} else {
					$state = 'error';
				}
			}

			$cards[] = array(
				'key'      => 'olt:' . (int) $post->ID,
				'type'     => 'olt',
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'subtitle' => ! empty( $device['name'] ) ? sanitize_text_field( $device['name'] ) : ( ! empty( $config['host'] ) ? sanitize_text_field( $config['host'] ) : __( 'OLT not configured', 'airfiber-centralized' ) ),
				'meta'     => ! empty( $config['host'] ) ? sanitize_text_field( $config['host'] ) : '',
				'state'    => $state,
				'status'   => 'online' === $state ? __( 'Connected', 'airfiber-centralized' ) : ( 'offline' === $state ? __( 'Disconnected', 'airfiber-centralized' ) : ( 'draft' === $state ? __( 'Draft', 'airfiber-centralized' ) : __( 'Needs attention', 'airfiber-centralized' ) ) ),
				'added'    => get_post_time( 'U', true, $post ),
			);
		}
		return $cards;
	}

	private static function mikrotik_card() {
		if ( ! class_exists( 'AFC_MikroTik' ) ) {
			return null;
		}
		$stored = get_option( AFC_MikroTik::OPTION_KEY, null );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$settings = AFC_MikroTik::get_settings();
		$status   = get_option( 'afc_mikrotik_last_status', array() );
		$state    = 'draft';
		if ( ! empty( $status['status'] ) ) {
			$state = 'success' === $status['status'] ? 'online' : 'error';
		}

		return array(
			'key'      => 'mikrotik:primary',
			'type'     => 'mikrotik',
			'id'       => 'primary',
			'title'    => ! empty( $settings['name'] ) ? sanitize_text_field( $settings['name'] ) : __( 'Main Router', 'airfiber-centralized' ),
			'subtitle' => ! empty( $settings['host'] ) ? sanitize_text_field( $settings['host'] ) . ':' . absint( $settings['port'] ) : __( 'Router not configured', 'airfiber-centralized' ),
			'meta'     => ! empty( $settings['username'] ) ? sanitize_text_field( $settings['username'] ) : '',
			'state'    => $state,
			'status'   => 'online' === $state ? __( 'Connected', 'airfiber-centralized' ) : ( 'error' === $state ? __( 'Needs attention', 'airfiber-centralized' ) : __( 'Not tested', 'airfiber-centralized' ) ),
			'added'    => 0,
		);
	}

	private static function sheet_card() {
		if ( ! class_exists( 'AFC_Integrations' ) ) {
			return null;
		}
		$settings    = get_option( AFC_Integrations::OPTION_SETTINGS, array() );
		$credentials = get_option( AFC_Integrations::OPTION_CREDENTIALS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( empty( $settings ) && empty( $credentials ) ) {
			return null;
		}

		$connected = ! empty( $settings['connected'] );
		$error     = ! empty( $settings['last_error'] );
		$state     = $connected ? 'online' : ( $error ? 'error' : 'draft' );
		$title     = ! empty( $settings['sheet_title'] ) ? sanitize_text_field( $settings['sheet_title'] ) : __( 'Primary Google Sheet', 'airfiber-centralized' );

		return array(
			'key'      => 'sheet:primary',
			'type'     => 'sheet',
			'id'       => 'primary',
			'title'    => $title,
			'subtitle' => ! empty( $settings['service_email'] ) ? sanitize_email( $settings['service_email'] ) : __( 'Google Sheets', 'airfiber-centralized' ),
			'meta'     => ! empty( $settings['last_success'] ) ? sprintf( __( 'Last test %s', 'airfiber-centralized' ), sanitize_text_field( $settings['last_success'] ) ) : '',
			'state'    => $state,
			'status'   => $connected ? __( 'Connected', 'airfiber-centralized' ) : ( $error ? __( 'Needs attention', 'airfiber-centralized' ) : __( 'Not tested', 'airfiber-centralized' ) ),
			'added'    => 0,
		);
	}

	private static function cards() {
		$cards = self::olt_cards();
		$mikrotik = self::mikrotik_card();
		$sheet = self::sheet_card();
		if ( $mikrotik ) {
			$cards[] = $mikrotik;
		}
		if ( $sheet ) {
			$cards[] = $sheet;
		}
		return $cards;
	}

	private static function ordered_cards() {
		$cards = self::cards();
		$saved = get_option( self::OPTION_ORDER, array() );
		$saved = is_array( $saved ) ? array_values( array_filter( array_map( 'sanitize_text_field', $saved ) ) ) : array();
		$map   = array();
		foreach ( $cards as $card ) {
			$map[ $card['key'] ] = $card;
		}

		$ordered = array();
		foreach ( $saved as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$ordered[] = $map[ $key ];
				unset( $map[ $key ] );
			}
		}
		foreach ( $cards as $card ) {
			if ( isset( $map[ $card['key'] ] ) ) {
				$ordered[] = $card;
				unset( $map[ $card['key'] ] );
			}
		}
		return $ordered;
	}

	public static function ajax_status() {
		self::authorize();
		wp_send_json_success( array( 'cards' => self::ordered_cards() ) );
	}

	public static function ajax_save_order() {
		self::authorize();
		$raw = isset( $_POST['order'] ) ? json_decode( wp_unslash( $_POST['order'] ), true ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		$allowed = array();
		foreach ( self::cards() as $card ) {
			$allowed[ $card['key'] ] = true;
		}
		$order = array();
		foreach ( $raw as $key ) {
			$key = sanitize_text_field( $key );
			if ( isset( $allowed[ $key ] ) && ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		update_option( self::OPTION_ORDER, $order, false );
		wp_send_json_success( array( 'order' => $order ) );
	}
}
