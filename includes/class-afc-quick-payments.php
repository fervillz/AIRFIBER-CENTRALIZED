<?php

defined( 'ABSPATH' ) || exit;

/**
 * Fast, amount-free payment actions for non-technical administrators.
 */
class AFC_Quick_Payments {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_afc_ppp_quick_payment', array( __CLASS__, 'ajax_quick_payment' ) );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'afc-quick-payments',
			AFC_URL . 'assets/css/quick-payments.css',
			array( 'afc-basic-payments' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-quick-payments',
			AFC_URL . 'assets/js/quick-payments.js',
			array( 'jquery', 'afc-ppp-users', 'afc-basic-payments' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-quick-payments',
			'afcQuickPayments',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'afc_quick_payment' ),
				'currentDate' => current_time( 'Y-m-d' ),
			)
		);
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to record payments.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( 'afc_quick_payment', 'nonce' );
	}

	private static function replace_comment_value( $comment, $key, $value ) {
		return AFC_Comment_Fields::replace_value( $comment, $key, $value );
	}

	private static function get_current_secret( $id ) {
		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,comment,profile',
			)
		);

		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}

		foreach ( $secrets as $secret ) {
			if ( isset( $secret['.id'] ) && (string) $secret['.id'] === (string) $id ) {
				return $secret;
			}
		}

		return new WP_Error( 'afc_ppp_missing', __( 'The PPP account no longer exists in MikroTik.', 'airfiber-centralized' ) );
	}

	private static function get_customer_id( $username ) {
		$customers = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);

		return $customers ? (int) $customers[0] : 0;
	}

	public static function ajax_quick_payment() {
		self::authorize_ajax();

		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';

		if ( ! $id || ! $name || ! in_array( $method, array( 'cash', 'gcash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The payment request is incomplete.', 'airfiber-centralized' ) ), 400 );
		}

		$secret = self::get_current_secret( $id );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}

		$current_name = isset( $secret['name'] ) ? sanitize_text_field( $secret['name'] ) : $name;
		$comment      = isset( $secret['comment'] ) ? $secret['comment'] : '';
		$date         = current_time( 'Y-m-d' );
		$new_comment  = self::replace_comment_value( $comment, 'paymentDate', $date );
		$new_comment  = self::replace_comment_value( $new_comment, 'paymentMethod', $method );

		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/set',
				'=.id=' . $id,
				'=comment=' . $new_comment,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$customer_id = self::get_customer_id( $current_name );
		if ( $customer_id ) {
			update_post_meta( $customer_id, '_afc_payment_date', $date );
			update_post_meta( $customer_id, '_afc_payment_method', $method );
			update_post_meta( $customer_id, '_afc_mikrotik_comment', $new_comment );
		}

		$payment_id = wp_insert_post(
			array(
				'post_type'   => 'afc_payment',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Payment - %s - %s', $current_name, $date ),
			),
			true
		);

		if ( ! is_wp_error( $payment_id ) ) {
			update_post_meta( $payment_id, '_afc_customer_id', $customer_id );
			update_post_meta( $payment_id, '_afc_ppp_username', $current_name );
			update_post_meta( $payment_id, '_afc_payment_date', $date );
			update_post_meta( $payment_id, '_afc_payment_amount', 0 );
			update_post_meta( $payment_id, '_afc_payment_method', $method );
			update_post_meta( $payment_id, '_afc_payment_reference', 'gcash' === $method ? 'XXXX' : '' );
			update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );
			do_action( 'afc_payment_recorded', $payment_id, $customer_id );
		}

		do_action( 'afc_quick_payment_recorded', $current_name, $method, $date, $customer_id );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: payment method, 2: PPP account. */
					__( '%1$s payment recorded for %2$s.', 'airfiber-centralized' ),
					'gcash' === $method ? 'GCash' : 'Cash',
					$current_name
				),
				'date'      => $date,
				'method'    => $method,
				'reference' => 'gcash' === $method ? 'XXXX' : '',
			)
		);
	}
}
