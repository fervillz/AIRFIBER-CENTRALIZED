<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Assets {
	public static function enqueue_core() {
		$css            = AFCN_PATH . 'assets/css/core.css';
		$interactions   = AFCN_PATH . 'assets/css/ui-interactions.css';
		$module_manager = AFCN_PATH . 'assets/css/module-manager.css';
		$js             = AFCN_PATH . 'assets/js/app.js';

		wp_enqueue_style(
			'afcn-source-serif-4',
			'https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'afcn-core', AFCN_URL . 'assets/css/core.css', array( 'afcn-source-serif-4' ), file_exists( $css ) ? (string) filemtime( $css ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-ui-interactions', AFCN_URL . 'assets/css/ui-interactions.css', array( 'afcn-core' ), file_exists( $interactions ) ? (string) filemtime( $interactions ) : AFCN_VERSION );
		wp_enqueue_style( 'afcn-module-manager', AFCN_URL . 'assets/css/module-manager.css', array( 'afcn-ui-interactions' ), file_exists( $module_manager ) ? (string) filemtime( $module_manager ) : AFCN_VERSION );
		wp_enqueue_script( 'afcn-app', AFCN_URL . 'assets/js/app.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : AFCN_VERSION, true );
		wp_localize_script(
			'afcn-app',
			'afcnApp',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'airfiber-next/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultModule' => 'dashboard',
				'classicUrl'    => esc_url_raw( Bootstrap::classic_url() ),
				'version'       => AFCN_VERSION,
				'labels'        => array(
					'loading' => __( 'Loading…', 'airfiber-centralized' ),
					'failed'  => __( 'This module could not be loaded.', 'airfiber-centralized' ),
					'saved'   => __( 'Saved.', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function module_manifest( $module ) {
		$out       = array( 'css' => array(), 'js' => array() );
		$base_real = realpath( $module['path'] );
		if ( ! $base_real ) {
			return $out;
		}
		$base_real = trailingslashit( $base_real );
		$url_path  = ! empty( $module['url_path'] ) ? trim( $module['url_path'], '/' ) : 'modules/' . $module['id'];

		foreach ( array( 'css', 'js' ) as $type ) {
			foreach ( (array) $module['assets'][ $type ] as $relative ) {
				$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
				if ( false !== strpos( $relative, '..' ) || strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) !== $type ) {
					continue;
				}
				$file = realpath( $base_real . $relative );
				if ( ! $file || 0 !== strpos( $file, $base_real ) || ! is_readable( $file ) ) {
					continue;
				}
				$out[ $type ][] = array(
					'url'   => trailingslashit( AFCN_URL . $url_path ) . $relative,
					'ver'   => (string) filemtime( $file ),
					'bytes' => (int) filesize( $file ),
				);
			}
		}
		return $out;
	}
}
