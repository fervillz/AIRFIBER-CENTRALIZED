<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Assets {
	public static function enqueue_core() {
		Performance_Monitor::migrate_metrics();

		$css            = AFCN_PATH . 'assets/css/core.css';
		$interactions   = AFCN_PATH . 'assets/css/ui-interactions.css';
		$module_manager = AFCN_PATH . 'assets/css/module-manager.css';
		$browser        = AFCN_PATH . 'assets/css/browser.css';
		$card_order_css = AFCN_PATH . 'assets/css/card-order.css';
		$status_js      = AFCN_PATH . 'assets/js/ui-status.js';
		$js             = AFCN_PATH . 'assets/js/app.js';
		$browser_js     = AFCN_PATH . 'assets/js/browser.js';
		$card_order_js  = AFCN_PATH . 'assets/js/card-order.js';
		$utility_js     = AFCN_PATH . 'assets/js/utility.js';

		wp_enqueue_style(
			'afcn-source-serif-4',
			'https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'afcn-core', AFCN_URL . 'assets/css/core.css', array( 'afcn-source-serif-4' ), file_exists( $css ) ? (string) filemtime( $css ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-ui-interactions', AFCN_URL . 'assets/css/ui-interactions.css', array( 'afcn-core' ), file_exists( $interactions ) ? (string) filemtime( $interactions ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-module-manager', AFCN_URL . 'assets/css/module-manager.css', array( 'afcn-ui-interactions' ), file_exists( $module_manager ) ? (string) filemtime( $module_manager ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-browser', AFCN_URL . 'assets/css/browser.css', array( 'afcn-module-manager' ), file_exists( $browser ) ? (string) filemtime( $browser ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-card-order', AFCN_URL . 'assets/css/card-order.css', array( 'afcn-browser' ), file_exists( $card_order_css ) ? (string) filemtime( $card_order_css ) : AFCN_VERSION );
		wp_enqueue_script( 'afcn-ui-status', AFCN_URL . 'assets/js/ui-status.js', array(), file_exists( $status_js ) ? (string) filemtime( $status_js ) : AFCN_VERSION, true );
		wp_enqueue_script( 'afcn-app', AFCN_URL . 'assets/js/app.js', array( 'afcn-ui-status' ), file_exists( $js ) ? (string) filemtime( $js ) : AFCN_VERSION, true );
		wp_enqueue_script( 'afcn-browser', AFCN_URL . 'assets/js/browser.js', array( 'afcn-app' ), file_exists( $browser_js ) ? (string) filemtime( $browser_js ) : AFCN_VERSION, true );
		wp_enqueue_script( 'afcn-card-order', AFCN_URL . 'assets/js/card-order.js', array( 'afcn-browser' ), file_exists( $card_order_js ) ? (string) filemtime( $card_order_js ) : AFCN_VERSION, true );

		// Utility drawers are a Super-Admin shell feature. Normal customer/admin
		// sessions do not download this runtime at all.
		if ( Capabilities::is_super_admin_user() && is_readable( $utility_js ) ) {
			wp_enqueue_script( 'afcn-utility', AFCN_URL . 'assets/js/utility.js', array( 'afcn-app' ), (string) filemtime( $utility_js ), true );
		}

		wp_localize_script(
			'afcn-app',
			'afcnApp',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'airfiber-next/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultModule' => 'dashboard',
				'classicUrl'    => esc_url_raw( Bootstrap::classic_url() ),
				'userId'        => get_current_user_id(),
				'version'       => AFCN_VERSION,
				'labels'        => array(
					'loading' => __( 'Loading…', 'airfiber-centralized' ),
					'failed'  => __( 'This module could not be loaded.', 'airfiber-centralized' ),
					'saved'   => __( 'Saved.', 'airfiber-centralized' ),
				),
			)
		);
	}

	/**
	 * Return only the assets needed by the module being rendered.
	 *
	 * Modules may declare assets in module.json, but convention-based
	 * assets/<module-id>.css and assets/<module-id>.js are also discovered here.
	 * Discovery happens only after the module is requested, so unrelated pages
	 * remain free from feature CSS/JS.
	 */
	public static function module_manifest( $module ) {
		$out       = array( 'css' => array(), 'js' => array() );
		$base_real = self::module_base_path( $module );
		if ( ! $base_real ) {
			return $out;
		}

		// Normalize before containment checks. realpath() returns backslashes on
		// Windows while WordPress paths commonly contain forward slashes; comparing
		// those raw strings caused valid lazy assets to be rejected on Windows hosts.
		$base_real = trailingslashit( wp_normalize_path( $base_real ) );
		$url_path  = self::module_url_path( $module );

		foreach ( array( 'css', 'js' ) as $type ) {
			foreach ( self::asset_paths( $module, $type ) as $relative ) {
				$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
				if ( false !== strpos( $relative, '..' ) || strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) !== $type ) {
					continue;
				}

				$file = realpath( $base_real . $relative );
				if ( ! $file ) {
					continue;
				}

				$file_normalized = wp_normalize_path( $file );
				if ( 0 !== strpos( $file_normalized, $base_real ) || ! is_readable( $file ) ) {
					continue;
				}

				$modified = filemtime( $file );
				$version  = AFCN_VERSION . '-' . ( false !== $modified ? (string) $modified : '0' );

				$out[ $type ][] = array(
					'url'   => trailingslashit( AFCN_URL . $url_path ) . $relative,
					'ver'   => $version,
					'bytes' => (int) filesize( $file ),
				);
			}
		}

		return $out;
	}

	private static function asset_paths( $module, $type ) {
		$declared = array();
		if ( isset( $module['assets'][ $type ] ) && is_array( $module['assets'][ $type ] ) ) {
			$declared = $module['assets'][ $type ];
		}

		$id = isset( $module['id'] ) ? sanitize_key( $module['id'] ) : '';
		if ( $id ) {
			$declared[] = 'assets/' . $id . '.' . $type;
		}

		return array_values( array_unique( array_filter( $declared ) ) );
	}

	private static function module_base_path( $module ) {
		if ( ! empty( $module['path'] ) ) {
			$real = realpath( $module['path'] );
			if ( $real ) {
				return $real;
			}
		}

		$id = isset( $module['id'] ) ? sanitize_key( $module['id'] ) : '';
		if ( ! $id ) {
			return false;
		}

		$is_mu = ! empty( $module['system'] ) || ( isset( $module['source'] ) && 'mu' === $module['source'] );
		$path  = AFCN_PATH . 'modules/' . ( $is_mu ? 'mu/' : '' ) . $id;
		return realpath( $path );
	}

	private static function module_url_path( $module ) {
		if ( ! empty( $module['url_path'] ) ) {
			return trim( wp_normalize_path( $module['url_path'] ), '/' );
		}

		$id    = isset( $module['id'] ) ? sanitize_key( $module['id'] ) : '';
		$is_mu = ! empty( $module['system'] ) || ( isset( $module['source'] ) && 'mu' === $module['source'] );
		return 'modules/' . ( $is_mu ? 'mu/' : '' ) . $id;
	}
}
