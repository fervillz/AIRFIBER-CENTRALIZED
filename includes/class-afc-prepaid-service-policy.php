<?php

defined( 'ABSPATH' ) || exit;

/**
 * Rolling prepaid service periods for Airfiber PPP customers.
 *
 * Rules:
 * - Missing/legacy cycle settings become 30-day prepaid on the next payment.
 * - An early payment starts after the currently paid service end, preserving
 *   unused days.
 * - A late payment starts a fresh cycle on the payment date.
 * - The normal cutoff is the day after nextDue so the due date remains usable.
 * - Grace remains available for collection/reminder behavior only. It does not
 *   add free internet days. A manually approved promise date is the only
 *   automatic-service extension handled here.
 */
class AFC_Prepaid_Service_Policy {

	const DEFAULT_CYCLE_DAYS = 30;
	const MAX_FUTURE_ANCHOR_DAYS = 365;

	public static function init() {
		// AFC_Billing_Cycles registers its handlers on init priority 1.
		add_action( 'init', array( __CLASS__, 'replace_billing_handlers' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 72 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 72 );
	}

	public static function replace_billing_handlers() {
		remove_action( 'wp_ajax_afc_ppp_quick_payment', array( 'AFC_Billing_Cycles', 'ajax_quick_payment' ) );
		add_action( 'wp_ajax_afc_ppp_quick_payment', array( __CLASS__, 'ajax_quick_payment' ) );

		remove_action( 'wp_ajax_afc_ppp_set_promise_date', array( 'AFC_Billing_Cycles', 'ajax_set_promise_date' ) );
		add_action( 'wp_ajax_afc_ppp_set_promise_date', array( __CLASS__, 'ajax_set_promise_date' ) );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		wp_enqueue_script(
			'afc-prepaid-service-policy',
			AFC_URL . 'assets/js/prepaid-service-policy.js',
			array( 'jquery', 'afc-billing-cycle-actions' ),
			AFC_VERSION,
			true
		);
	}

	private static function authorize_quick_payment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to record payments.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_quick_payment', 'nonce' );
	}

	private static function authorize_cycle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change billing dates.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_billing_cycles', 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] )
			? $details['custom_fields']
			: array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( $canonical, $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function configured_key( $canonical ) {
		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			if ( ! empty( $field['key'] ) && 0 === strcasecmp( $canonical, $field['key'] ) ) {
				return $field['key'];
			}
		return $canonical;
	}

	private static function rows( $result ) {
		if ( ! is_array( $result ) || empty( $result ) ) {
			return array();
		}
		return isset( $result['.id'] ) || isset( $result['name'] ) ? array( $result ) : $result;
	}

	private static function get_current_secret( $id ) {
		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,comment,profile,disabled',
			)
		);
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		foreach ( self::rows( $secrets ) as $secret ) {
			if ( isset( $secret['.id'] ) && (string) $secret['.id'] === (string) $id ) {
				return $secret;
			}
		}
		return new WP_Error( 'afc_ppp_missing', __( 'The PPP account no longer exists in MikroTik.', 'airfiber-centralized' ) );
	}

	private static function get_customer_id( $username ) {
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

	private static function selected_cycle( $details, $requested_cycle ) {
		$requested = (int) $requested_cycle;
		if ( in_array( $requested, array( 15, 30 ), true ) ) {
			return $requested;
		}
		$current = (int) self::custom_value( $details, 'billingCycleDays' );
		return in_array( $current, array( 15, 30 ), true ) ? $current : self::DEFAULT_CYCLE_DAYS;
	}

	private static function service_dates( $details, DateTimeImmutable $payment_date, $cycle ) {
		$existing_next = self::parse_date( self::custom_value( $details, 'nextDue' ) );
		$maximum_anchor = $payment_date->modify( '+' . self::MAX_FUTURE_ANCHOR_DAYS . ' days' );
		$anchor = $payment_date;

		// Preserve already-paid days for early payors. Ignore clearly malformed
		// dates that are more than one year ahead.
		if ( $existing_next && $existing_next >= $payment_date && $existing_next <= $maximum_anchor ) {
			$anchor = $existing_next;
		}

		$next_due = $anchor->modify( '+' . (int) $cycle . ' days' );
		return array(
			'cycle_days'             => (int) $cycle,
			'service_anchor'         => $anchor,
			'paidThrough'            => $anchor,
			'nextDue'                => $next_due,
			'cutoffDate'             => $next_due->modify( '+1 day' ),
			'dueReminderDate'        => $next_due->modify( '-1 day' ),
			'preserved_remaining'    => $anchor > $payment_date,
			'preserved_days'         => max( 0, (int) $payment_date->diff( $anchor )->format( '%a' ) ),
		);
	}

	private static function replace_comment_fields( $comment, $values ) {
		foreach ( $values as $key => $value ) {
			$comment = AFC_Comment_Fields::replace_value( $comment, self::configured_key( $key ), (string) $value );
		}
		return AFC_Comment_Fields::normalize_comment( $comment );
	}

	private static function update_customer_billing_meta( $customer_id, $comment, $date, $method, $amount, $dates, $restored_profile ) {
		if ( ! $customer_id ) {
			return;
		}
		update_post_meta( $customer_id, '_afc_payment_date', $date );
		update_post_meta( $customer_id, '_afc_payment_method', $method );
		update_post_meta( $customer_id, '_afc_payment_amount', $amount );
		update_post_meta( $customer_id, '_afc_mikrotik_comment', $comment );
		update_post_meta( $customer_id, '_afc_comment_field_billingcycledays', $dates['cycle_days'] );
		update_post_meta( $customer_id, '_afc_comment_field_paidthrough', $dates['paidThrough']->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, '_afc_comment_field_nextdue', $dates['nextDue']->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, '_afc_comment_field_cutoffdate', $dates['cutoffDate']->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, '_afc_comment_field_duereminderdate', $dates['dueReminderDate']->format( 'Y-m-d' ) );
		delete_post_meta( $customer_id, '_afc_comment_field_promisedpaydate' );
		if ( $restored_profile ) {
			update_post_meta( $customer_id, '_afc_customer_status', 'active' );
		}
	}

	private static function create_payment_record( $customer_id, $username, $date, $method, $amount, $dates ) {
		$payment_id = wp_insert_post(
			array(
				'post_type'   => 'afc_payment',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Payment - %s - %s', $username, $date ),
			),
			true
		);
		if ( is_wp_error( $payment_id ) ) {
			return 0;
		}

		update_post_meta( $payment_id, '_afc_customer_id', $customer_id );
		update_post_meta( $payment_id, '_afc_ppp_username', $username );
		update_post_meta( $payment_id, '_afc_payment_date', $date );
		update_post_meta( $payment_id, '_afc_payment_amount', $amount );
		update_post_meta( $payment_id, '_afc_payment_method', $method );
		update_post_meta( $payment_id, '_afc_payment_reference', 'gcash' === $method ? 'XXXX' : '' );
		update_post_meta( $payment_id, '_afc_billing_cycle_days', $dates['cycle_days'] );
		update_post_meta( $payment_id, '_afc_service_anchor', $dates['service_anchor']->format( 'Y-m-d' ) );
		update_post_meta( $payment_id, '_afc_paid_through', $dates['paidThrough']->format( 'Y-m-d' ) );
		update_post_meta( $payment_id, '_afc_next_due', $dates['nextDue']->format( 'Y-m-d' ) );
		update_post_meta( $payment_id, '_afc_cutoff_date', $dates['cutoffDate']->format( 'Y-m-d' ) );
		update_post_meta( $payment_id, '_afc_due_reminder_date', $dates['dueReminderDate']->format( 'Y-m-d' ) );
		update_post_meta( $payment_id, '_afc_preserved_remaining_days', $dates['preserved_days'] );
		update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );

		do_action( 'afc_payment_recorded', $payment_id, $customer_id );
		return (int) $payment_id;
	}

	public static function ajax_quick_payment() {
		self::authorize_quick_payment();

		$id         = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$method     = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$cycle_raw  = isset( $_POST['cycle_days'] ) ? absint( wp_unslash( $_POST['cycle_days'] ) ) : 0;
		$has_amount = isset( $_POST['amount'] ) && '' !== trim( (string) wp_unslash( $_POST['amount'] ) );
		$amount     = $has_amount ? (float) wp_unslash( $_POST['amount'] ) : null;

		if ( ! $id || ! $name || ! in_array( $method, array( 'cash', 'gcash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The payment request is incomplete.', 'airfiber-centralized' ) ), 400 );
		}
		if ( $cycle_raw && ! in_array( $cycle_raw, array( 15, 30 ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected billing cycle is invalid.', 'airfiber-centralized' ) ), 400 );
		}
		if ( null !== $amount && $amount < 0 ) {
			wp_send_json_error( array( 'message' => __( 'The payment amount cannot be negative.', 'airfiber-centralized' ) ), 400 );
		}

		$secret = self::get_current_secret( $id );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}

		$current_name = isset( $secret['name'] ) ? sanitize_text_field( $secret['name'] ) : $name;
		$comment      = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details      = AFC_Comment_Fields::parse_comment( $comment );
		$payment_date = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$cycle        = self::selected_cycle( $details, $cycle_raw );
		$dates        = self::service_dates( $details, $payment_date, $cycle );

		if ( null === $amount ) {
			$existing_amount = isset( $details['payment_amount'] ) ? trim( (string) $details['payment_amount'] ) : '';
			$amount = is_numeric( $existing_amount ) ? (float) $existing_amount : 0;
		}

		$new_comment = self::replace_comment_fields(
			$comment,
			array(
				'paymentDate'      => $payment_date->format( 'Y-m-d' ),
				'paymentMethod'    => $method,
				'paymentAmount'    => $amount,
				'billingCycleDays' => $cycle,
				'paidThrough'      => $dates['paidThrough']->format( 'Y-m-d' ),
				'nextDue'         => $dates['nextDue']->format( 'Y-m-d' ),
				'cutoffDate'       => $dates['cutoffDate']->format( 'Y-m-d' ),
				'dueReminderDate' => $dates['dueReminderDate']->format( 'Y-m-d' ),
				'promisedPayDate' => '',
			)
		);

		$saved_plan = isset( $details['plan'] ) ? trim( (string) $details['plan'] ) : '';
		$current_profile = isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '';
		$restore_profile = '' !== $saved_plan && 0 === strcasecmp( $current_profile, 'Expired' );

		$command = array(
			'/ppp/secret/set',
			'=.id=' . $id,
			'=comment=' . $new_comment,
		);
		if ( $restore_profile ) {
			$command[] = '=profile=' . $saved_plan;
		}
		$result = AFC_MikroTik::run_command( $command );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$date        = $payment_date->format( 'Y-m-d' );
		$customer_id = self::get_customer_id( $current_name );
		self::update_customer_billing_meta( $customer_id, $new_comment, $date, $method, $amount, $dates, $restore_profile );
		self::create_payment_record( $customer_id, $current_name, $date, $method, $amount, $dates );

		do_action( 'afc_quick_payment_recorded', $current_name, $method, $date, $customer_id );

		$cycle_label = 15 === $cycle ? '15D' : '30D';
		$message = sprintf(
			__( '%1$s payment recorded for %2$s (%3$s prepaid).', 'airfiber-centralized' ),
			'gcash' === $method ? 'GCash' : 'Cash',
			$current_name,
			$cycle_label
		);
		if ( $dates['preserved_remaining'] ) {
			$message .= ' ' . sprintf(
				__( '%d unused paid day(s) were preserved before the new cycle.', 'airfiber-centralized' ),
				$dates['preserved_days']
			);
		}

		wp_send_json_success(
			array(
				'message'             => $message,
				'date'                => $date,
				'method'              => $method,
				'amount'              => $amount,
				'reference'           => 'gcash' === $method ? 'XXXX' : '',
				'cycleDays'           => $cycle,
				'cycleLabel'          => $cycle_label,
				'serviceAnchor'       => $dates['service_anchor']->format( 'Y-m-d' ),
				'paidThrough'         => $dates['paidThrough']->format( 'Y-m-d' ),
				'nextDue'             => $dates['nextDue']->format( 'Y-m-d' ),
				'cutoffDate'          => $dates['cutoffDate']->format( 'Y-m-d' ),
				'dueReminderDate'     => $dates['dueReminderDate']->format( 'Y-m-d' ),
				'preservedDays'       => $dates['preserved_days'],
				'promiseCleared'      => true,
				'restoredFromExpired' => $restore_profile,
			)
		);
	}

	private static function derive_next_due( $details, DateTimeImmutable $today ) {
		$existing = self::parse_date( self::custom_value( $details, 'nextDue' ) );
		if ( $existing ) {
			return $existing;
		}
		$cycle = self::selected_cycle( $details, 0 );
		$payment_date = self::parse_date( isset( $details['payment_date'] ) ? $details['payment_date'] : '' );
		return $payment_date ? $payment_date->modify( '+' . $cycle . ' days' ) : $today->modify( '+' . $cycle . ' days' );
	}

	public static function ajax_set_promise_date() {
		self::authorize_cycle_action();

		$id          = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$promise_raw = isset( $_POST['promise_date'] ) ? sanitize_text_field( wp_unslash( $_POST['promise_date'] ) ) : '';
		$clear       = ! empty( $_POST['clear'] );

		if ( ! $id || ! $name ) {
			wp_send_json_error( array( 'message' => __( 'The PPP account is incomplete.', 'airfiber-centralized' ) ), 400 );
		}

		$today   = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$promise = $clear ? null : self::parse_date( $promise_raw );
		if ( ! $clear && ! $promise ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid promise date.', 'airfiber-centralized' ) ), 400 );
		}
		if ( $promise && $promise < $today ) {
			wp_send_json_error( array( 'message' => __( 'The promise date cannot be in the past.', 'airfiber-centralized' ) ), 400 );
		}

		$secret = self::get_current_secret( $id );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}

		$current_name = isset( $secret['name'] ) ? sanitize_text_field( $secret['name'] ) : $name;
		$comment      = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details      = AFC_Comment_Fields::parse_comment( $comment );
		$next_due     = self::derive_next_due( $details, $today );
		$normal_cutoff = $next_due->modify( '+1 day' );
		$promise_cutoff = $promise ? $promise->modify( '+1 day' ) : null;
		$effective = $promise_cutoff && $promise_cutoff > $normal_cutoff ? $promise_cutoff : $normal_cutoff;

		$new_comment = self::replace_comment_fields(
			$comment,
			array(
				'promisedPayDate' => $promise ? $promise->format( 'Y-m-d' ) : '',
				'cutoffDate'      => $effective->format( 'Y-m-d' ),
			)
		);

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
			update_post_meta( $customer_id, '_afc_mikrotik_comment', $new_comment );
			update_post_meta( $customer_id, '_afc_comment_field_cutoffdate', $effective->format( 'Y-m-d' ) );
			if ( $promise ) {
				update_post_meta( $customer_id, '_afc_comment_field_promisedpaydate', $promise->format( 'Y-m-d' ) );
			} else {
				delete_post_meta( $customer_id, '_afc_comment_field_promisedpaydate' );
			}
		}

		wp_send_json_success(
			array(
				'message'     => $promise
					? sprintf( __( 'Promise date saved for %s. This is an explicit service extension.', 'airfiber-centralized' ), $current_name )
					: sprintf( __( 'Promise date cleared for %s.', 'airfiber-centralized' ), $current_name ),
				'promiseDate' => $promise ? $promise->format( 'Y-m-d' ) : '',
				'cutoffDate'  => $effective->format( 'Y-m-d' ),
			)
		);
	}
}
