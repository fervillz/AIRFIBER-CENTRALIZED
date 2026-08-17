<?php

defined( 'ABSPATH' ) || exit;

/**
 * Adds the resizable help/console workspace used by the frontend OLT editor.
 */
class AFC_OLT_Manager_Console_UI {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1046 );
	}

	public static function enqueue_assets() {
		if (
			is_admin() ||
			! current_user_can( 'manage_options' ) ||
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request()
		) {
			return;
		}

		$css_path = AFC_PATH . 'assets/css/olt-manager-console-ui.css';
		$js_path  = AFC_PATH . 'assets/js/olt-manager-console-ui.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : AFC_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : AFC_VERSION;

		wp_enqueue_style(
			'afc-olt-manager-console-ui',
			AFC_URL . 'assets/css/olt-manager-console-ui.css',
			array( 'afc-olt-manager' ),
			$css_ver
		);

		wp_enqueue_script(
			'afc-olt-manager-console-ui',
			AFC_URL . 'assets/js/olt-manager-console-ui.js',
			array( 'jquery', 'afc-olt-manager' ),
			$js_ver,
			true
		);

		/*
		 * Safety net for mixed-cache upgrades: older manager code may still create
		 * the legacy inline <details> log. Relocate only the log node here. The
		 * console UI owns all visibility/animation classes so this fallback can
		 * never bypass the measured slide-up animation.
		 */
		wp_add_inline_script(
			'afc-olt-manager-console-ui',
			<<<'JS'
(function () {
	'use strict';

	function relocateOLTLog() {
		var modal = document.querySelector('[data-afc-olt-modal]');
		if (!modal) return;
		var log = modal.querySelector('[data-afc-olt-test-log]');
		var consoleBody = modal.querySelector('[data-afc-olt-console-body]');
		if (!log || !consoleBody) return;
		if (log.parentNode !== consoleBody) consoleBody.appendChild(log);
	}

	function bootOLTConsoleSafety() {
		var modal = document.querySelector('[data-afc-olt-modal]');
		if (!modal) return;

		modal.addEventListener('click', function (event) {
			if (!event.target.closest('[data-afc-olt-test]')) return;
			window.setTimeout(relocateOLTLog, 0);
			window.setTimeout(relocateOLTLog, 80);
			window.setTimeout(relocateOLTLog, 250);
		}, true);

		var observer = new MutationObserver(relocateOLTLog);
		observer.observe(modal, { childList: true, subtree: true });
		relocateOLTLog();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootOLTConsoleSafety);
	} else {
		bootOLTConsoleSafety();
	}
}());
JS,
			'after'
		);
	}
}
