<?php
/**
 * Plugin Name: Airfiber - Centralized
 * Description: Customer, billing, payment, installation, notification, and MikroTik management for Airfiber.
 * Version: 1.6.2
 * Author: Airfiber
 * Text Domain: airfiber-centralized
 */

defined( 'ABSPATH' ) || exit;

define( 'AFC_VERSION', '1.6.2' );
define( 'AFC_FILE', __FILE__ );
define( 'AFC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFC_URL', plugin_dir_url( __FILE__ ) );

require_once AFC_PATH . 'includes/class-afc-post-types.php';
require_once AFC_PATH . 'includes/class-afc-mikrotik.php';
require_once AFC_PATH . 'includes/class-afc-comment-fields.php';
require_once AFC_PATH . 'includes/class-afc-comment-formatting.php';
require_once AFC_PATH . 'includes/class-afc-comment-migration.php';
require_once AFC_PATH . 'includes/class-afc-comment-migration-rules.php';
require_once AFC_PATH . 'includes/class-afc-comment-center.php';
require_once AFC_PATH . 'includes/class-afc-schedulers.php';
require_once AFC_PATH . 'includes/class-afc-scheduler-migration-selection.php';
require_once AFC_PATH . 'includes/class-afc-ppp-users.php';
require_once AFC_PATH . 'includes/class-afc-comment-aliases.php';
require_once AFC_PATH . 'includes/class-afc-area-manager.php';
require_once AFC_PATH . 'includes/class-afc-admin.php';
require_once AFC_PATH . 'includes/class-afc-collection-print.php';
require_once AFC_PATH . 'includes/class-afc-admin-mode.php';
require_once AFC_PATH . 'includes/class-afc-basic-payments.php';
require_once AFC_PATH . 'includes/class-afc-quick-payments.php';
require_once AFC_PATH . 'includes/class-afc-billing-cycles.php';
require_once AFC_PATH . 'includes/class-afc-frontend-page.php';
require_once AFC_PATH . 'includes/class-afc-pwa.php';

function afc_boot_plugin() {
	AFC_Post_Types::init();
	AFC_MikroTik::init();
	AFC_Comment_Fields::init();
	AFC_Comment_Formatting::init();
	AFC_Comment_Migration::init();
	AFC_Comment_Migration_Rules::init();
	AFC_Comment_Center::init();
	AFC_Schedulers::init();
	AFC_Scheduler_Migration_Selection::init();
	AFC_PPP_Users::init();
	AFC_Comment_Aliases::init();
	AFC_Area_Manager::init();
	AFC_Frontend_Page::init();
	AFC_PWA::init();

	if ( is_admin() ) {
		AFC_Admin::init();
		AFC_Collection_Print::init();
		AFC_Admin_Mode::init();
		AFC_Basic_Payments::init();
		AFC_Quick_Payments::init();
	}

	// Billing cycle setup contains translated labels and must run on init or
	// later. It still runs before AJAX actions and asset enqueue hooks fire.
	add_action( 'init', array( 'AFC_Billing_Cycles', 'init' ), 1 );

	do_action( 'afc_loaded' );
}
add_action( 'plugins_loaded', 'afc_boot_plugin' );

function afc_activate_plugin() {
	AFC_Post_Types::register();
	AFC_Frontend_Page::activate();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'afc_activate_plugin' );

function afc_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'afc_deactivate_plugin' );
