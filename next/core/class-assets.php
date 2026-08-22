<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Assets {
	public static function enqueue_core() {
		$css = AFCN_PATH . 'assets/css/core.css';
		$js  = AFCN_PATH . 'assets/js/app.js';
		wp_enqueue_style( 'afcn-core', AFCN_URL . 'assets/css/core.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : AFCN_VERSION );
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
		$out = array( 'css' => array(), 'js' => array() );
		foreach ( array( 'css', 'js' ) as $type ) {
			foreach ( (array) $module['assets'][ $type ] as $relative ) {
				$relative = ltrim( str_replace( '..', '', $relative ), '/' );
				$file     = trailingslashit( $module['path'] ) . $relative;
				if ( ! is_readable( $file ) ) {
					continue;
				}
				$out[ $type ][] = array(
					'url'   => trailingslashit( AFCN_URL . 'modules/' . $module['id'] ) . $relative,
					'ver'   => (string) filemtime( $file ),
					'bytes' => (int) filesize( $file ),
				);
			}
		}
		return $out;
	}
}
