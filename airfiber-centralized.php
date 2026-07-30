<?php
/**
 * Plugin Name: Airfiber - Centralized
 * Description: Customer, billing, payment, installation, notification, and MikroTik management for Airfiber.
 * Version: 0.8.3
 * Author: Airfiber
 * Text Domain: airfiber-centralized
 */

defined( 'ABSPATH' ) || exit;

define( 'AFC_VERSION', '0.8.3' );
define( 'AFC_FILE', __FILE__ );
define( 'AFC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFC_URL', plugin_dir_url( __FILE__ ) );

require_once AFC_PATH . 'includes/class-afc-post-types.php';
require_once AFC_PATH . 'includes/class-afc-mikrotik.php';
require_once AFC_PATH . 'includes/class-afc-ppp-users.php';
require_once AFC_PATH . 'includes/class-afc-admin.php';

function afc_boot_plugin() {
	AFC_Post_Types::init();
	AFC_MikroTik::init();
	AFC_PPP_Users::init();

	if ( is_admin() ) {
		AFC_Admin::init();
	}

	do_action( 'afc_loaded' );
}
add_action( 'plugins_loaded', 'afc_boot_plugin' );

function afc_activate_plugin() {
	AFC_Post_Types::register();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'afc_activate_plugin' );

function afc_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'afc_deactivate_plugin' );