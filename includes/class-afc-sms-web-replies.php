<?php

defined( 'ABSPATH' ) || exit;

/**
 * Queues direct replies from the web SMS conversation view.
 */
class AFC_SMS_Web_Replies {

	const NONCE_ACTION = 'afc_sms_center';

	public static function init() {
		add_action( 'wp_ajax_afc_sms_queue_reply', array( __CLASS__, 'ajax_queue_reply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 44 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-sms-web-replies',
			AFC_URL . 'assets/css/sms-web-replies.css',
			array( 'afc-sms-center' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-sms-web-replies',
			AFC_URL . 'assets/js/sms-web-replies.js',
			array( 'afc-sms-center' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-sms-web-replies',
			'afcSmsWebReplies',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send SMS replies.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'afc_sms_' . $name;
	}

	private static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) {
			return '+63' . substr( $digits, 1 );
		}
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '639' ) ) {
			return '+' . $digits;
		}
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) {
			return '+63' . $digits;
		}
		return '';
	}

	private static function truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function customer_id_by_ppp( $username ) {
		if ( '' === $username ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function customer_id_by_phone( $phone ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $customer_id ) {
			$stored = self::normalize_phone( get_post_meta( $customer_id, '_afc_phone', true ) );
			if ( $stored && $stored === $phone ) {
				return (int) $customer_id;
			}
		}
		return 0;
	}

	private static function latest_job_context( $phone, $username ) {
		global $wpdb;
		$table = self::table( 'jobs' );
		if ( $username ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT customer_id, ppp_username, customer_name FROM {$table} WHERE ppp_username = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$username
				)
			);
			if ( $row ) {
				return $row;
			}
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT customer_id, ppp_username, customer_name FROM {$table} WHERE phone = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$phone
			)
		);
	}

	private static function customer_context( $phone, $username, $posted_name ) {
		$job         = self::latest_job_context( $phone, $username );
		$customer_id = self::customer_id_by_ppp( $username );
		if ( ! $customer_id && $job && ! empty( $job->customer_id ) ) {
			$customer_id = (int) $job->customer_id;
		}
		if ( ! $customer_id ) {
			$customer_id = self::customer_id_by_phone( $phone );
		}

		if ( $customer_id && (
			self::truthy( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) ||
			self::truthy( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) )
		) ) {
			return new WP_Error( 'afc_sms_reply_opt_out', __( 'This customer is marked Do Not Text.', 'airfiber-centralized' ) );
		}

		if ( ! $username && $customer_id ) {
			$username = trim( (string) get_post_meta( $customer_id, '_afc_ppp_username', true ) );
		}
		if ( ! $username && $job ) {
			$username = trim( (string) $job->ppp_username );
		}

		$name = trim( (string) $posted_name );
		if ( $customer_id ) {
			$saved_name = trim( (string) get_post_meta( $customer_id, '_afc_customer_name', true ) );
			$name       = $saved_name ?: get_the_title( $customer_id );
		}
		if ( ! $name && $job ) {
			$name = trim( (string) $job->customer_name );
		}
		if ( ! $name ) {
			$name = $phone;
		}

		return array(
			'customer_id'   => $customer_id,
			'ppp_username'  => $username,
			'customer_name' => $name,
		);
	}

	private static function prepare_job( $job ) {
		return array(
			'id'            => (int) $job->id,
			'ppp_username'  => (string) $job->ppp_username,
			'customer_name' => (string) $job->customer_name,
			'phone'         => (string) $job->phone,
			'message'       => (string) $job->message,
			'reminder_type' => (string) $job->reminder_type,
			'status'        => (string) $job->status,
			'device_id'     => (string) $job->device_id,
			'last_detail'   => (string) $job->last_detail,
			'created_at'    => (string) $job->created_at,
			'updated_at'    => (string) $job->updated_at,
			'can_cancel'    => 'queued' === $job->status,
		);
	}

	public static function ajax_queue_reply() {
		self::authorize();

		$phone    = self::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		$username = isset( $_POST['ppp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['ppp_username'] ) ) : '';
		$name     = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $phone ) {
			wp_send_json_error( array( 'message' => __( 'This conversation does not have a valid Philippine mobile number.', 'airfiber-centralized' ) ), 400 );
		}
		if ( '' === trim( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Type a reply first.', 'airfiber-centralized' ) ), 400 );
		}
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 1200 ) : substr( $message, 0, 1200 );

		$context = self::customer_context( $phone, $username, $name );
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ), 400 );
		}

		global $wpdb;
		$now        = current_time( 'mysql' );
		$dedupe_key = 'web-reply|' . strtolower( $phone ) . '|' . gmdate( 'YmdHis' ) . '|' . strtolower( wp_generate_password( 8, false, false ) );
		$inserted   = $wpdb->insert(
			self::table( 'jobs' ),
			array(
				'dedupe_key'    => $dedupe_key,
				'customer_id'   => absint( $context['customer_id'] ),
				'ppp_username'  => $context['ppp_username'],
				'customer_name' => $context['customer_name'],
				'phone'         => $phone,
				'message'       => $message,
				'reminder_type' => 'web-reply',
				'status'        => 'queued',
				'last_detail'   => 'Queued as a web reply from SMS Center.',
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'The reply could not be added to the SMS queue.', 'airfiber-centralized' ) ) );
		}

		$job_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			self::table( 'events' ),
			array(
				'job_id'     => $job_id,
				'device_id'  => '',
				'status'     => 'queued',
				'detail'     => 'Queued as a web reply from SMS Center.',
				'event_time' => $now,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'jobs' ) . ' WHERE id = %d', $job_id ) );
		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Reply queued for %1$s (%2$s).', 'airfiber-centralized' ), $context['customer_name'], $phone ),
				'job'     => $job ? self::prepare_job( $job ) : array(),
			)
		);
	}
}
