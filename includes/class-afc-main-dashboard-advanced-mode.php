<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the main dashboard and its payment tools exclusive to Advanced mode
 * without changing the existing Basic payment experience.
 */
class AFC_Main_Dashboard_Advanced_Mode {

	const PAYMENT_NONCE = 'afc_dashboard_payment_tool';

	public static function init() {
		// Load the visibility guard before optional dashboard and CDN assets.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 9 );
		add_action( 'wp_ajax_afc_dashboard_today_collection', array( __CLASS__, 'ajax_today_collection' ) );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-frontend-app-stability',
			AFC_URL . 'assets/css/frontend-app-stability.css',
			array(),
			AFC_VERSION
		);

		wp_enqueue_style(
			'afc-main-dashboard-desktop-typography',
			AFC_URL . 'assets/css/main-dashboard-desktop-typography.css',
			array( 'afc-main-dashboard' ),
			AFC_VERSION
		);

		wp_enqueue_style(
			'afc-main-dashboard-payment-tool',
			AFC_URL . 'assets/css/main-dashboard-payment-tool.css',
			array( 'afc-main-dashboard', 'afc-main-dashboard-desktop-typography' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-main-dashboard-advanced-mode',
			AFC_URL . 'assets/js/main-dashboard-advanced-mode.js',
			array(),
			AFC_VERSION,
			true
		);

		wp_enqueue_script(
			'afc-main-dashboard-payment-tool',
			AFC_URL . 'assets/js/main-dashboard-payment-tool.js',
			array( 'jquery', 'afc-main-dashboard', 'afc-ppp-users', 'afc-main-dashboard-advanced-mode' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-main-dashboard-payment-tool',
			'afcDashboardPaymentTool',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::PAYMENT_NONCE ),
			)
		);
	}

	private static function authorize_payment_tool() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view payment totals.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::PAYMENT_NONCE, 'nonce' );
	}

	public static function ajax_today_collection() {
		self::authorize_payment_tool();

		$today = current_time( 'Y-m-d' );
		$ids   = get_posts(
			array(
				'post_type'      => 'afc_payment',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_afc_payment_date',
						'value'   => $today,
						'compare' => '=',
					),
				),
			)
		);

		$total  = 0.0;
		$cash   = 0.0;
		$gcash  = 0.0;
		$other  = 0.0;
		$latest = null;

		foreach ( $ids as $index => $payment_id ) {
			$amount = (float) get_post_meta( $payment_id, '_afc_payment_amount', true );
			$method = strtolower( trim( (string) get_post_meta( $payment_id, '_afc_payment_method', true ) ) );
			$total += $amount;

			if ( 'cash' === $method ) {
				$cash += $amount;
			} elseif ( 'gcash' === $method ) {
				$gcash += $amount;
			} else {
				$other += $amount;
			}

			if ( 0 === $index ) {
				$customer_id = absint( get_post_meta( $payment_id, '_afc_customer_id', true ) );
				$account     = (string) get_post_meta( $payment_id, '_afc_ppp_username', true );
				$customer    = $customer_id ? get_the_title( $customer_id ) : '';
				$latest      = array(
					'customer' => $customer ? $customer : $account,
					'account'  => $account,
					'amount'   => $amount,
					'method'   => $method,
				);
			}
		}

		wp_send_json_success(
			array(
				'date'   => $today,
				'count'  => count( $ids ),
				'total'  => round( $total, 2 ),
				'cash'   => round( $cash, 2 ),
				'gcash'  => round( $gcash, 2 ),
				'other'  => round( $other, 2 ),
				'latest' => $latest,
			)
		);
	}
}
