<?php

defined( 'ABSPATH' ) || exit;

/**
 * Clean, grouped navigation and reusable UI helpers for Advanced mode.
 *
 * Existing feature modules keep ownership of their data and actions. This
 * workspace only organizes their frontend panels, so Basic mode remains
 * untouched and future modules can register themselves through one filter.
 */
class AFC_Advanced_Workspace {

	const REST_NAMESPACE = 'airfiber/v1';
	const REST_ROUTE     = '/workspace';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 999 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function groups() {
		return array(
			array(
				'id'          => 'overview',
				'label'       => __( 'Overview', 'airfiber-centralized' ),
				'description' => __( 'Daily work and items that need attention.', 'airfiber-centralized' ),
			),
			array(
				'id'          => 'customers',
				'label'       => __( 'Customers & Billing', 'airfiber-centralized' ),
				'description' => __( 'Payments, PPP accounts, collection and cutoff automation.', 'airfiber-centralized' ),
			),
			array(
				'id'          => 'communication',
				'label'       => __( 'Communication', 'airfiber-centralized' ),
				'description' => __( 'Customer messages and delivery tools.', 'airfiber-centralized' ),
			),
			array(
				'id'          => 'system',
				'label'       => __( 'System & Connections', 'airfiber-centralized' ),
				'description' => __( 'Router access, external services and technical settings.', 'airfiber-centralized' ),
			),
		);
	}

	public static function panel_registry() {
		$panels = array(
			'dashboard' => array(
				'group'       => 'overview',
				'title'       => __( 'Dashboard', 'airfiber-centralized' ),
				'short'       => __( 'Daily overview', 'airfiber-centralized' ),
				'description' => __( 'Payments, subscriber activity, service attention, SMS and router health in one compact starting page.', 'airfiber-centralized' ),
				'icon'        => '⌂',
				'order'       => 10,
			),
			'operations' => array(
				'group'       => 'customers',
				'title'       => __( 'Customers & Billing', 'airfiber-centralized' ),
				'short'       => __( 'Payments and PPP', 'airfiber-centralized' ),
				'description' => __( 'Record payments, create or edit PPP accounts, review customer service state and prepare collection lists.', 'airfiber-centralized' ),
				'icon'        => '₱',
				'order'       => 20,
			),
			'schedulers' => array(
				'group'       => 'customers',
				'title'       => __( 'Billing Automation', 'airfiber-centralized' ),
				'short'       => __( 'Due dates and cutoffs', 'airfiber-centralized' ),
				'description' => __( 'Review due accounts and safely synchronize one MikroTik cutoff scheduler per PPP username.', 'airfiber-centralized' ),
				'icon'        => '↻',
				'order'       => 30,
			),
			'sms' => array(
				'group'       => 'communication',
				'title'       => __( 'SMS', 'airfiber-centralized' ),
				'short'       => __( 'Customer messages', 'airfiber-centralized' ),
				'description' => __( 'Read conversations, approve outgoing messages and check the Android gateway without mixing setup details into daily messaging.', 'airfiber-centralized' ),
				'icon'        => '✉',
				'order'       => 40,
			),
			'mikrotik' => array(
				'group'       => 'system',
				'title'       => __( 'MikroTik', 'airfiber-centralized' ),
				'short'       => __( 'Router connection', 'airfiber-centralized' ),
				'description' => __( 'Manage the saved RouterOS API connection and review the latest connection test.', 'airfiber-centralized' ),
				'icon'        => '⌁',
				'order'       => 50,
			),
			'integrations' => array(
				'group'       => 'system',
				'title'       => __( 'Integrations', 'airfiber-centralized' ),
				'short'       => __( 'Sheets, messaging and API', 'airfiber-centralized' ),
				'description' => __( 'Connect external services one at a time. Each integration has its own focused submenu, status and setup area.', 'airfiber-centralized' ),
				'icon'        => '◇',
				'order'       => 60,
			),
		);

		/**
		 * Other modules can add Advanced panels without editing the workspace.
		 * Each item supports group, title, short, description, icon and order.
		 */
		return apply_filters( 'afc_advanced_workspace_panels', $panels );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-advanced-workspace',
			AFC_URL . 'assets/css/advanced-workspace.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-advanced-workspace',
			AFC_URL . 'assets/js/advanced-workspace.js',
			array( 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-advanced-workspace',
			'afcAdvancedWorkspace',
			array(
				'groups'      => self::groups(),
				'panels'      => self::panel_registry(),
				'version'     => AFC_VERSION,
				'apiEndpoint' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
				'labels'      => array(
					'advanced'     => __( 'Advanced workspace', 'airfiber-centralized' ),
					'findTool'     => __( 'Find a tool…', 'airfiber-centralized' ),
					'about'        => __( 'About this page', 'airfiber-centralized' ),
					'sections'     => __( 'Page sections', 'airfiber-centralized' ),
					'close'        => __( 'Close', 'airfiber-centralized' ),
					'noTools'      => __( 'No matching tools.', 'airfiber-centralized' ),
					'menu'         => __( 'Menu', 'airfiber-centralized' ),
					'collapseMenu' => __( 'Collapse menu', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_workspace' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function rest_workspace() {
		return rest_ensure_response(
			array(
				'version' => AFC_VERSION,
				'groups'  => self::groups(),
				'panels'  => self::panel_registry(),
				'links'   => array(
					'app' => class_exists( 'AFC_Frontend_Page' ) ? AFC_Frontend_Page::get_url() : '',
				),
			)
		);
	}
}
