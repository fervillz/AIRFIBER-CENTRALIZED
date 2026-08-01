<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connect existing payment and incoming-SMS workflows to payor ratings.
 */
class AFC_SMS_Payer_Hooks {

	private static $recording_payment = false;

	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'capture_customer_payment' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'capture_customer_payment' ), 10, 4 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'capture_incoming_reply' ), 10, 3 );
	}

	/**
	 * The payment workflow updates _afc_payment_date before advancing nextDue,
	 * so the existing nextDue value is the due date that was just paid.
	 */
	public static function capture_customer_payment( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::$recording_payment || '_afc_payment_date' !== (string) $meta_key ) {
			return;
		}
		$customer_id = absint( $object_id );
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) || ! class_exists( 'AFC_SMS_Payer_Ratings' ) ) {
			return;
		}
		$paid_date = sanitize_text_field( (string) $meta_value );
		$due_date  = sanitize_text_field( (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true ) );
		if ( ! $due_date ) {
			$due_date = sanitize_text_field( (string) get_post_meta( $customer_id, '_afc_comment_field_paidthrough', true ) );
		}
		if ( ! $due_date || ! $paid_date ) {
			return;
		}
		self::$recording_payment = true;
		AFC_SMS_Payer_Ratings::record_payment( $customer_id, $due_date, $paid_date );
		self::$recording_payment = false;
	}

	/**
	 * Pause automatic reminders after a successfully stored incoming reply.
	 */
	public static function capture_incoming_reply( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || '/airfiber/v1/sms/incoming' !== $request->get_route() || ! class_exists( 'AFC_SMS_Payer_Ratings' ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
		if ( $status >= 200 && $status < 300 ) {
			AFC_SMS_Payer_Ratings::record_reply(
				sanitize_text_field( (string) $request->get_param( 'phone' ) ),
				sanitize_text_field( (string) $request->get_param( 'received_at' ) )
			);
		}
		return $response;
	}
}
