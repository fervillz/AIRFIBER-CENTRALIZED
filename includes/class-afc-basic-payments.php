<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mobile-first payment search for non-technical Airfiber administrators.
 */
class AFC_Basic_Payments {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'afc-basic-payments',
			AFC_URL . 'assets/css/basic-payments.css',
			array( 'afc-admin-mode' ),
			AFC_VERSION
		);

		wp_enqueue_style(
			'afc-mobile-payment-search',
			AFC_URL . 'assets/css/mobile-payment-search.css',
			array( 'afc-basic-payments' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-basic-payments',
			AFC_URL . 'assets/js/basic-payments.js',
			array( 'jquery', 'afc-ppp-users', 'afc-admin-mode' ),
			AFC_VERSION,
			true
		);

		wp_enqueue_script(
			'afc-mobile-payment-search',
			AFC_URL . 'assets/js/mobile-payment-search.js',
			array( 'afc-basic-payments' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-basic-payments',
			'afcBasicPayments',
			array(
				'minCharacters' => 3,
				'maxResults'    => 10,
				'labels'        => array(
					'title'        => __( 'Record a payment', 'airfiber-centralized' ),
					'description'  => __( 'Search for a customer, select the correct account, then record today’s payment.', 'airfiber-centralized' ),
					'placeholder'  => __( 'Type customer name or PPP account…', 'airfiber-centralized' ),
					'loading'      => __( 'Loading customers from MikroTik…', 'airfiber-centralized' ),
					'startTyping'  => __( 'Type at least 3 letters to search.', 'airfiber-centralized' ),
					'noResults'    => __( 'No matching customer was found.', 'airfiber-centralized' ),
					'payToday'     => __( 'Pay Today', 'airfiber-centralized' ),
					'cancel'       => __( 'Cancel', 'airfiber-centralized' ),
					'lastPayment'  => __( 'Last payment', 'airfiber-centralized' ),
					'plannedPlan'  => __( 'Plan', 'airfiber-centralized' ),
					'account'      => __( 'PPP account', 'airfiber-centralized' ),
				),
			)
		);
	}
}
