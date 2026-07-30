<?php

defined( 'ABSPATH' ) || exit;

class AFC_Post_Types {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		self::register_customer();
		self::register_payment();
	}

	private static function register_customer() {
		register_post_type(
			'afc_customer',
			array(
				'labels' => array(
					'name'          => __( 'Customers', 'airfiber-centralized' ),
					'singular_name' => __( 'Customer', 'airfiber-centralized' ),
					'add_new_item'  => __( 'Add Customer', 'airfiber-centralized' ),
					'edit_item'     => __( 'Edit Customer', 'airfiber-centralized' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'airfiber-centralized',
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-groups',
			)
		);
	}

	private static function register_payment() {
		register_post_type(
			'afc_payment',
			array(
				'labels' => array(
					'name'          => __( 'Payments', 'airfiber-centralized' ),
					'singular_name' => __( 'Payment', 'airfiber-centralized' ),
					'add_new_item'  => __( 'Record Payment', 'airfiber-centralized' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'airfiber-centralized',
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-money-alt',
			)
		);
	}
}

