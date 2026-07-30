<?php

defined( 'ABSPATH' ) || exit;

class AFC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_afc_test_mikrotik', array( 'AFC_MikroTik', 'handle_test_connection' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Airfiber Centralized', 'airfiber-centralized' ),
			__( 'Airfiber', 'airfiber-centralized' ),
			'manage_options',
			'airfiber-centralized',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-cloud',
			3
		);

		add_submenu_page(
			'airfiber-centralized',
			__( 'MikroTik PPP Users', 'airfiber-centralized' ),
			__( 'PPP Users', 'airfiber-centralized' ),
			'manage_options',
			'airfiber-ppp-users',
			array( 'AFC_PPP_Users', 'render_page' )
		);

		add_submenu_page(
			'airfiber-centralized',
			__( 'MikroTik Settings', 'airfiber-centralized' ),
			__( 'MikroTik', 'airfiber-centralized' ),
			'manage_options',
			'airfiber-mikrotik',
			array( 'AFC_MikroTik', 'render_settings_page' )
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		$airfiber_pages = array(
			'toplevel_page_airfiber-centralized',
			'airfiber_page_airfiber-ppp-users',
			'airfiber_page_airfiber-mikrotik',
		);

		if ( ! in_array( $hook_suffix, $airfiber_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-tabler',
			'https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css',
			array(),
			'1.4.0'
		);

		wp_enqueue_style(
			'afc-admin-compat',
			AFC_URL . 'assets/css/admin-compat.css',
			array( 'afc-tabler' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-tabler',
			'https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js',
			array(),
			'1.4.0',
			true
		);

		if ( 'airfiber_page_airfiber-mikrotik' === $hook_suffix ) {
			wp_enqueue_script(
				'afc-mikrotik-settings',
				AFC_URL . 'assets/js/mikrotik-settings.js',
				array( 'jquery' ),
				AFC_VERSION,
				true
			);

			wp_localize_script(
				'afc-mikrotik-settings',
				'afcMikroTik',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'afc_test_mikrotik_ajax' ),
					'testing' => __( 'Testing connection...', 'airfiber-centralized' ),
					'button'  => __( 'Test Saved Connection', 'airfiber-centralized' ),
				)
			);
		}

		if ( 'airfiber_page_airfiber-ppp-users' === $hook_suffix ) {
			wp_enqueue_script(
				'afc-ppp-users',
				AFC_URL . 'assets/js/ppp-users.js',
				array( 'jquery' ),
				AFC_VERSION,
				true
			);

			wp_localize_script(
				'afc-ppp-users',
				'afcPPP',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'afc_ppp_users' ),
					'loading'     => __( 'Loading PPP users from MikroTik...', 'airfiber-centralized' ),
					'importing'   => __( 'Importing selected users...', 'airfiber-centralized' ),
					'noSelection' => __( 'Select at least one PPP user to import.', 'airfiber-centralized' ),
				)
			);
		}
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'airfiber-centralized' ) );
		}

		$counts = array(
			'customers' => wp_count_posts( 'afc_customer' )->publish ?? 0,
			'payments'  => wp_count_posts( 'afc_payment' )->publish ?? 0,
			'due_soon'  => 0,
			'expired'   => 0,
		);

		include AFC_PATH . 'templates/admin/dashboard.php';
	}
}
