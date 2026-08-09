<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for light, search-driven AJAX refreshes.
 *
 * Front-end modules register themselves with window.AFCSearchAjax and get the
 * same 3-character / debounce / stale-response behavior. Server handlers can
 * reuse requested_accounts() instead of reimplementing request sanitation.
 */
class AFC_Search_Ajax {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1036 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1036 );
	}

	public static function enqueue_frontend() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	public static function enqueue_admin( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	private static function enqueue() {
		if ( wp_script_is( 'afc-search-ajax', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'afc-search-ajax',
			AFC_URL . 'assets/js/search-ajax.js',
			array(),
			AFC_VERSION,
			true
		);
	}

	/**
	 * Read a JSON/array list of account names from an AJAX request.
	 * Keys are lower-case for quick matching; values preserve RouterOS case.
	 */
	public static function requested_accounts( $field = 'accounts', $limit = 50 ) {
		$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, max( 1, min( 100, (int) $limit ) ) ) as $account ) {
			$account = substr( sanitize_text_field( trim( (string) $account ) ), 0, 190 );
			if ( '' === $account ) {
				continue;
			}
			$accounts[ strtolower( $account ) ] = $account;
		}
		return $accounts;
	}
}
