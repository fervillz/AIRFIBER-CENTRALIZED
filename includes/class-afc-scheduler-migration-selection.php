<?php

defined( 'ABSPATH' ) || exit;

/**
 * Restricts the Scheduler Center's bulk migration to accounts that either do
 * not have a scheduler yet or still use the legacy event script.
 */
class AFC_Scheduler_Migration_Selection {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 99 );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'afc-scheduler-migration-selection',
			AFC_URL . 'assets/js/scheduler-migration-selection.js',
			array( 'jquery', 'afc-schedulers' ),
			AFC_VERSION,
			true
		);
	}
}
