<?php

defined( 'ABSPATH' ) || exit;

class AFC_Collection_Print {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'afc-collection-print-selection',
			AFC_URL . 'assets/css/collection-print-selection.css',
			array( 'afc-collection-accordion' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-collection-print-selection',
			AFC_URL . 'assets/js/collection-print-selection.js',
			array( 'jquery', 'afc-collection-area-labels' ),
			AFC_VERSION,
			true
		);

		wp_enqueue_script(
			'afc-collection-zone-card-layout',
			AFC_URL . 'assets/js/collection-zone-card-layout.js',
			array( 'afc-collection-print-selection' ),
			AFC_VERSION,
			true
		);
	}
}
