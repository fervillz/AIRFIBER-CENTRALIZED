<?php
/**
 * Plugin Name: Airfiber - Centralized
 * Description: Customer, billing, payment, installation, notification, and MikroTik management for Airfiber.
 * Version: 2.8.0
 * Author: Airfiber
 * Text Domain: airfiber-centralized
 */

defined( 'ABSPATH' ) || exit;

define( 'AFC_VERSION', '2.8.0' );
define( 'AFC_FILE', __FILE__ );
define( 'AFC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFC_URL', plugin_dir_url( __FILE__ ) );

require_once AFC_PATH . 'includes/class-afc-post-types.php';
require_once AFC_PATH . 'includes/class-afc-mikrotik.php';
require_once AFC_PATH . 'includes/class-afc-olt.php';
require_once AFC_PATH . 'includes/class-afc-comment-fields.php';
require_once AFC_PATH . 'includes/class-afc-comment-formatting.php';
require_once AFC_PATH . 'includes/class-afc-comment-migration.php';
require_once AFC_PATH . 'includes/class-afc-comment-migration-rules.php';
require_once AFC_PATH . 'includes/class-afc-comment-center.php';
require_once AFC_PATH . 'includes/class-afc-schedulers.php';
require_once AFC_PATH . 'includes/class-afc-scheduler-insights.php';
require_once AFC_PATH . 'includes/class-afc-scheduler-migration-selection.php';
require_once AFC_PATH . 'includes/class-afc-ppp-users.php';
require_once AFC_PATH . 'includes/class-afc-search-ajaxify.php';
require_once AFC_PATH . 'includes/trait-afc-ppp-manager-router.php';
require_once AFC_PATH . 'includes/trait-afc-ppp-manager-reminders.php';
require_once AFC_PATH . 'includes/trait-afc-ppp-manager-core.php';
require_once AFC_PATH . 'includes/trait-afc-ppp-manager-create.php';
require_once AFC_PATH . 'includes/trait-afc-ppp-manager-save.php';
require_once AFC_PATH . 'includes/class-afc-ppp-manager.php';
require_once AFC_PATH . 'includes/class-afc-ppp-master-password.php';
require_once AFC_PATH . 'includes/class-afc-ppp-operations-ux.php';
require_once AFC_PATH . 'includes/class-afc-ios-dialogs.php';
require_once AFC_PATH . 'includes/class-afc-comment-aliases.php';
require_once AFC_PATH . 'includes/class-afc-area-manager.php';
require_once AFC_PATH . 'includes/class-afc-admin.php';
require_once AFC_PATH . 'includes/class-afc-collection-print.php';
require_once AFC_PATH . 'includes/class-afc-admin-mode.php';
require_once AFC_PATH . 'includes/class-afc-basic-payments.php';
require_once AFC_PATH . 'includes/class-afc-quick-payments.php';
require_once AFC_PATH . 'includes/class-afc-billing-cycles.php';
require_once AFC_PATH . 'includes/class-afc-prepaid-service-policy.php';
require_once AFC_PATH . 'includes/class-afc-advance-payments.php';
require_once AFC_PATH . 'includes/class-afc-frontend-page.php';
require_once AFC_PATH . 'includes/class-afc-app-typography.php';
require_once AFC_PATH . 'includes/class-afc-integrations.php';
require_once AFC_PATH . 'includes/class-afc-messenger-settings.php';
require_once AFC_PATH . 'includes/class-afc-advanced-workspace.php';
require_once AFC_PATH . 'includes/class-afc-google-sheets-sync.php';
require_once AFC_PATH . 'includes/class-afc-google-sheets-table-compat.php';
require_once AFC_PATH . 'includes/class-afc-google-sheets-overview-compat.php';
require_once AFC_PATH . 'includes/class-afc-google-sheets-paid-history.php';
require_once AFC_PATH . 'includes/class-afc-google-sheets-targeted-payment-sync.php';
require_once AFC_PATH . 'includes/class-afc-main-dashboard.php';
require_once AFC_PATH . 'includes/class-afc-main-dashboard-advanced-mode.php';
require_once AFC_PATH . 'includes/class-afc-sms-center.php';
require_once AFC_PATH . 'includes/class-afc-sms-web-replies.php';
require_once AFC_PATH . 'includes/class-afc-sms-chat-status.php';
require_once AFC_PATH . 'includes/class-afc-sms-templates.php';
require_once AFC_PATH . 'includes/class-afc-sms-inquiry-fields.php';
require_once AFC_PATH . 'includes/class-afc-sms-payer-ratings.php';
require_once AFC_PATH . 'includes/class-afc-sms-payer-hooks.php';
require_once AFC_PATH . 'includes/class-afc-sms-precutoff.php';
require_once AFC_PATH . 'includes/class-afc-sms-precutoff-runtime.php';
require_once AFC_PATH . 'includes/class-afc-sms-due-runtime.php';
require_once AFC_PATH . 'includes/class-afc-sms-due-resend-ui.php';
require_once AFC_PATH . 'includes/class-afc-sms-audience-filters.php';
require_once AFC_PATH . 'includes/class-afc-customer-search-polish.php';
require_once AFC_PATH . 'includes/class-afc-customer-search-icons-hotfix.php';
require_once AFC_PATH . 'includes/class-afc-ui-regression-fixes.php';
require_once AFC_PATH . 'includes/class-afc-ajaxify.php';
require_once AFC_PATH . 'includes/class-afc-pwa.php';

function afc_boot_plugin() {
	AFC_Post_Types::init();
	AFC_MikroTik::init();
	AFC_OLT::init();
	AFC_Comment_Fields::init();
	AFC_Comment_Formatting::init();
	AFC_Comment_Migration::init();
	AFC_Comment_Migration_Rules::init();
	AFC_Comment_Center::init();
	AFC_Schedulers::init();
	AFC_Scheduler_Insights::init();
	AFC_Scheduler_Migration_Selection::init();
	AFC_Advance_Payments::init();
	AFC_PPP_Users::init();
	AFC_Search_Ajaxify::init();
	AFC_PPP_Manager::init();
	AFC_PPP_Master_Password::init();
	AFC_PPP_Operations_UX::init();
	AFC_IOS_Dialogs::init();
	AFC_Comment_Aliases::init();
	AFC_Area_Manager::init();
	AFC_Frontend_Page::init();
	AFC_App_Typography::init();
	AFC_Integrations::init();
	AFC_Messenger_Settings::init();
	AFC_Advanced_Workspace::init();
	AFC_Google_Sheets_Table_Compat::init();
	AFC_Google_Sheets_Overview_Compat::init();
	AFC_Google_Sheets_Paid_History::init();
	AFC_Google_Sheets_Targeted_Payment_Sync::init();
	AFC_Google_Sheets_Sync::init();
	AFC_Main_Dashboard::init();
	AFC_Main_Dashboard_Advanced_Mode::init();
	AFC_SMS_Center::init();
	AFC_SMS_Web_Replies::init();
	AFC_SMS_Chat_Status::init();
	AFC_SMS_Templates::init();
	AFC_SMS_Inquiry_Fields::init();
	AFC_SMS_Payer_Ratings::init();
	AFC_SMS_Payer_Hooks::init();
	AFC_SMS_PreCutoff::init();
	AFC_SMS_PreCutoff_Runtime::init();
	AFC_SMS_Due_Runtime::init();
	AFC_SMS_Due_Resend_UI::init();
	AFC_SMS_Audience_Filters::init();
	AFC_Customer_Search_Polish::init();
	AFC_Customer_Search_Icons_Hotfix::init();
	AFC_UI_Regression_Fixes::init();
	AFC_Ajaxify::init();
	AFC_PWA::init();
	AFC_Prepaid_Service_Policy::init();

	if ( is_admin() ) {
		AFC_Admin::init();
		AFC_Collection_Print::init();
		AFC_Admin_Mode::init();
		AFC_Basic_Payments::init();
		AFC_Quick_Payments::init();
	}

	add_action( 'init', array( 'AFC_Billing_Cycles', 'init' ), 1 );

	do_action( 'afc_loaded' );
}
add_action( 'plugins_loaded', 'afc_boot_plugin' );

function afc_activate_plugin() {
	AFC_Post_Types::register();
	AFC_Frontend_Page::activate();
	AFC_SMS_Center::install();
	AFC_SMS_Templates::install();
	AFC_SMS_Payer_Ratings::schedule();
	AFC_SMS_PreCutoff::schedule();
	AFC_PPP_Manager::ensure_schema_and_schedule();
	AFC_Google_Sheets_Sync::ensure_schedule();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'afc_activate_plugin' );

function afc_deactivate_plugin() {
	AFC_SMS_Payer_Ratings::unschedule();
	AFC_SMS_PreCutoff::unschedule();
	AFC_PPP_Manager::unschedule();
	AFC_Google_Sheets_Sync::deactivate();
	wp_clear_scheduled_hook( AFC_Google_Sheets_Paid_History::CRON_REFRESH );
	wp_clear_scheduled_hook( AFC_Google_Sheets_Targeted_Payment_Sync::CRON_TARGETED );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'afc_deactivate_plugin' );
