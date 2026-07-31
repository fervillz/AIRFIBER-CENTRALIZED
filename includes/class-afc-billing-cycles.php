<?php

defined( 'ABSPATH' ) || exit;

/**
 * Exact 15/30-day billing cycles, promise dates, and enhanced quick payments.
 */
class AFC_Billing_Cycles {

	const NONCE = 'afc_billing_cycles';

	public static function init() {
		self::ensure_schema_fields();

		// Replace the amount-free quick payment endpoint after the original class
		// has registered it during admin/AJAX requests.
		remove_action( 'wp_ajax_afc_ppp_quick_payment', array( 'AFC_Quick_Payments', 'ajax_quick_payment' ) );
		add_action( 'wp_ajax_afc_ppp_quick_payment', array( __CLASS__, 'ajax_quick_payment' ) );
		add_action( 'wp_ajax_afc_ppp_set_promise_date', array( __CLASS__, 'ajax_set_promise_date' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 70 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 70 );
	}

	private static function ensure_schema_fields() {
		$fields = get_option( AFC_Comment_Fields::OPTION_KEY, array() );
		$fields = is_array( $fields ) ? $fields : array();

		$system = array(
			'billingcycledays' => array(
				'key'     => 'billingCycleDays',
				'label'   => __( 'Billing Cycle Days', 'airfiber-centralized' ),
				'type'    => 'number',
				'default' => '',
				'core'    => false,
			),
			'promisedpaydate'  => array(
				'key'     => 'promisedPayDate',
				'label'   => __( 'Promised Pay Date', 'airfiber-centralized' ),
				'type'    => 'date',
				'default' => '',
				'core'    => false,
			),
		);

		$found = array();
		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$lower = strtolower( (string) $field['key'] );
			if ( isset( $system[ $lower ] ) ) {
				$fields[ $index ] = array_merge( $field, $system[ $lower ] );
				$found[ $lower ]  = true;
			}
		}

		foreach ( $system as $lower => $field ) {
			if ( empty( $found[ $lower ] ) ) {
				$fields[] = $field;
			}
		}

		update_option( AFC_Comment_Fields::OPTION_KEY, AFC_Comment_Fields::sanitize_fields( $fields ), false );
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
		wp_enqueue_style(
			'afc-billing-cycle-actions',
			AFC_URL . 'assets/css/billing-cycle-actions.css',
			array( 'afc-quick-payments' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-billing-cycle-actions',
			AFC_URL . 'assets/js/billing-cycle-actions.js',
			array( 'jquery', 'afc-quick-payments', 'afc-ppp-users' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-billing-cycle-actions',
			'afcBillingCycles',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'currentDate'  => current_time( 'Y-m-d' ),
				'longPressMs'  => 620,
				'currency'     => '₱',
				'labels'       => array(
					'holdHint'      => __( 'Hold an action to change the cycle, amount, or promise date.', 'airfiber-centralized' ),
					'overrideReady' => __( 'Override ready. Tap CASH or GCash to record it.', 'airfiber-centralized' ),
					'promiseSaved'  => __( 'Promise date saved.', 'airfiber-centralized' ),
					'promiseFailed' => __( 'The promise date could not be saved.', 'airfiber-centralized' ),
				),
			)
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
		check_ajax_referer( self::NONCE, 'nonce' );
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

	private static function date_for_month( $year, $month, $billing_day ) {
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', (int) $year, (int) $month ), self::timezone() );
		$day   = min( max( 1, (int) $billing_day ), (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $day );
	}

	private static function next_month_due( DateTimeImmutable $paid_through, $billing_day ) {
		$month = $paid_through->modify( 'first day of next month' );
		return self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
	}

	private static function nearest_monthly_due( DateTimeImmutable $date, $billing_day ) {
		$candidates = array();
		foreach ( array( -1, 0, 1 ) as $offset ) {
			$month = $date->modify( 'first day of this month' )->modify( $offset . ' month' );
			$due   = self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
			$candidates[] = array(
				'date'     => $due,
				'distance' => abs( $due->getTimestamp() - $date->getTimestamp() ),
			);
		}
		usort(
			$candidates,
			function ( $first, $second ) {
				if ( $first['distance'] === $second['distance'] ) {
					return $second['date']->getTimestamp() <=> $first['date']->getTimestamp();
				}
				return $first['distance'] <=> $second['distance'];
			}
		);
		return $candidates[0]['date'];
	}

	private static function configured_key( $canonical ) {
		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			if ( 0 === strcasecmp( $canonical, $field['key'] ) ) {
				return $field['key'];
			}
		}
		return $canonical;
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
		foreach ( (array) $secrets as $secret ) {
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

	private static function grace_details( $details ) {
		$raw = isset( $details['grace'] ) ? trim( (string) $details['grace'] ) : '';
		if ( '' === $raw || ! preg_match( '/^\d+$/', $raw ) ) {
			return array( 'used' => 6, 'normalize' => false, 'legacy_days' => 0 );
		}
		$value = (int) $raw;
		return array(
			'used'        => min( 6, max( 0, $value ) ),
			'normalize'   => $value > 6,
			'legacy_days' => $value > 6 ? $value : 0,
		);
	}

	private static function billing_dates( $details, DateTimeImmutable $payment_date, $requested_cycle ) {
		$current_cycle = (int) self::custom_value( $details, 'billingCycleDays' );
		$cycle         = in_array( (int) $requested_cycle, array( 15, 30 ), true )
			? (int) $requested_cycle
			: ( in_array( $current_cycle, array( 15, 30 ), true ) ? $current_cycle : 0 );
		$grace         = self::grace_details( $details );

		if ( in_array( $cycle, array( 15, 30 ), true ) ) {
			$paid_through = $payment_date;
			$next_due     = $payment_date->modify( '+' . $cycle . ' days' );
			$cutoff       = 15 === $cycle
				? $next_due
				: $next_due->modify( '+' . ( $grace['used'] + 1 ) . ' days' );

			return array(
				'cycle_days'  => $cycle,
				'billing_day' => '',
				'paidThrough' => $paid_through,
				'nextDue'     => $next_due,
				'cutoffDate'  => $cutoff,
				'grace'       => $grace,
			);
		}

		$billing_day = (int) self::custom_value( $details, 'billingDay' );
		if ( $billing_day < 1 || $billing_day > 31 ) {
			$installed = self::parse_date( isset( $details['installed'] ) ? $details['installed'] : '' );
			$billing_day = $installed ? (int) $installed->format( 'j' ) : 0;
		}
		if ( ! $billing_day ) {
			return new WP_Error( 'afc_missing_billing_day', __( 'The installation date or billing day is missing.', 'airfiber-centralized' ) );
		}

		$existing_next = self::parse_date( self::custom_value( $details, 'nextDue' ) );
		$paid_through  = $existing_next ? $existing_next : self::nearest_monthly_due( $payment_date, $billing_day );
		$next_due      = self::next_month_due( $paid_through, $billing_day );
		$cutoff        = $next_due->modify( '+' . ( $grace['used'] + 1 ) . ' days' );

		return array(
			'cycle_days'  => 0,
			'billing_day' => $billing_day,
			'paidThrough' => $paid_through,
			'nextDue'     => $next_due,
			'cutoffDate'  => $cutoff,
			'grace'       => $grace,
		);
	}

	private static function update_customer_billing_meta( $customer_id, $comment, $date, $method, $amount, $dates ) {
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
		delete_post_meta( $customer_id, '_afc_comment_field_promisedpaydate' );
	}

	public static function ajax_quick_payment() {
		self::authorize_quick_payment();

		$id         = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$method     = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$cycle      = isset( $_POST['cycle_days'] ) ? absint( wp_unslash( $_POST['cycle_days'] ) ) : 0;
		$has_amount = isset( $_POST['amount'] ) && '' !== trim( (string) wp_unslash( $_POST['amount'] ) );
		$amount     = $has_amount ? (float) wp_unslash( $_POST['amount'] ) : null;

		if ( ! $id || ! $name || ! in_array( $method, array( 'cash', 'gcash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The payment request is incomplete.', 'airfiber-centralized' ) ), 400 );
		}
		if ( $cycle && ! in_array( $cycle, array( 15, 30 ), true ) ) {
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
		$date_object  = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$dates        = self::billing_dates( $details, $date_object, $cycle );
		if ( is_wp_error( $dates ) ) {
			wp_send_json_error( array( 'message' => $dates->get_error_message() ), 400 );
		}

		if ( null === $amount ) {
			$existing_amount = isset( $details['payment_amount'] ) ? trim( (string) $details['payment_amount'] ) : '';
			$amount = is_numeric( $existing_amount ) ? (float) $existing_amount : 0;
		}

		$new_comment = AFC_Comment_Fields::replace_value( $comment, 'paymentDate', $date_object->format( 'Y-m-d' ) );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'paymentMethod', $method );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'paymentAmount', (string) $amount );

		if ( $dates['cycle_days'] ) {
			$new_comment = AFC_Comment_Fields::replace_value(
				$new_comment,
				self::configured_key( 'billingCycleDays' ),
				(string) $dates['cycle_days']
			);
		}
		if ( $dates['billing_day'] && '' === self::custom_value( $details, 'billingDay' ) ) {
			$new_comment = AFC_Comment_Fields::replace_value(
				$new_comment,
				self::configured_key( 'billingDay' ),
				(string) $dates['billing_day']
			);
		}

		$new_comment = AFC_Comment_Fields::replace_value(
			$new_comment,
			self::configured_key( 'paidThrough' ),
			$dates['paidThrough']->format( 'Y-m-d' )
		);
		$new_comment = AFC_Comment_Fields::replace_value(
			$new_comment,
			self::configured_key( 'nextDue' ),
			$dates['nextDue']->format( 'Y-m-d' )
		);
		$new_comment = AFC_Comment_Fields::replace_value(
			$new_comment,
			self::configured_key( 'cutoffDate' ),
			$dates['cutoffDate']->format( 'Y-m-d' )
		);
		$new_comment = AFC_Comment_Fields::replace_value(
			$new_comment,
			self::configured_key( 'promisedPayDate' ),
			''
		);
		if ( $dates['grace']['normalize'] ) {
			$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'grace', (string) $dates['grace']['used'] );
		}

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

		$date        = $date_object->format( 'Y-m-d' );
		$customer_id = self::get_customer_id( $current_name );
		self::update_customer_billing_meta( $customer_id, $new_comment, $date, $method, $amount, $dates );

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
			update_post_meta( $payment_id, '_afc_payment_amount', $amount );
			update_post_meta( $payment_id, '_afc_payment_method', $method );
			update_post_meta( $payment_id, '_afc_payment_reference', 'gcash' === $method ? 'XXXX' : '' );
			update_post_meta( $payment_id, '_afc_billing_cycle_days', $dates['cycle_days'] );
			update_post_meta( $payment_id, '_afc_paid_through', $dates['paidThrough']->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_next_due', $dates['nextDue']->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_cutoff_date', $dates['cutoffDate']->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );
			do_action( 'afc_payment_recorded', $payment_id, $customer_id );
		}

		do_action( 'afc_quick_payment_recorded', $current_name, $method, $date, $customer_id );

		$cycle_label = 15 === $dates['cycle_days']
			? '15D'
			: ( 30 === $dates['cycle_days'] ? '30D' : 'Monthly' );

		wp_send_json_success(
			array(
				'message'         => sprintf(
					__( '%1$s payment recorded for %2$s (%3$s).', 'airfiber-centralized' ),
					'gcash' === $method ? 'GCash' : 'Cash',
					$current_name,
					$cycle_label
				),
				'date'            => $date,
				'method'          => $method,
				'amount'          => $amount,
				'reference'       => 'gcash' === $method ? 'XXXX' : '',
				'cycleDays'       => $dates['cycle_days'],
				'cycleLabel'      => $cycle_label,
				'paidThrough'     => $dates['paidThrough']->format( 'Y-m-d' ),
				'nextDue'         => $dates['nextDue']->format( 'Y-m-d' ),
				'cutoffDate'      => $dates['cutoffDate']->format( 'Y-m-d' ),
				'promiseCleared'  => true,
				'normalizedGrace' => $dates['grace']['normalize'],
			)
		);
	}

	private static function derive_next_due( $details, DateTimeImmutable $today ) {
		$existing = self::parse_date( self::custom_value( $details, 'nextDue' ) );
		if ( $existing ) {
			return $existing;
		}

		$cycle        = (int) self::custom_value( $details, 'billingCycleDays' );
		$payment_date = self::parse_date( isset( $details['payment_date'] ) ? $details['payment_date'] : '' );
		if ( in_array( $cycle, array( 15, 30 ), true ) && $payment_date ) {
			return $payment_date->modify( '+' . $cycle . ' days' );
		}

		$billing_day = (int) self::custom_value( $details, 'billingDay' );
		if ( $billing_day < 1 || $billing_day > 31 ) {
			$installed   = self::parse_date( isset( $details['installed'] ) ? $details['installed'] : '' );
			$billing_day = $installed ? (int) $installed->format( 'j' ) : 0;
		}
		return $billing_day ? self::nearest_monthly_due( $today, $billing_day ) : null;
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
		if ( ! $next_due ) {
			wp_send_json_error( array( 'message' => __( 'Airfiber cannot calculate the next due date for this account.', 'airfiber-centralized' ) ), 400 );
		}

		$cycle          = (int) self::custom_value( $details, 'billingCycleDays' );
		$grace          = self::grace_details( $details );
		$normal_cutoff  = 15 === $cycle
			? $next_due
			: $next_due->modify( '+' . ( $grace['used'] + 1 ) . ' days' );
		$promise_cutoff = $promise ? $promise->modify( '+1 day' ) : null;
		$effective      = $promise_cutoff && $promise_cutoff > $normal_cutoff ? $promise_cutoff : $normal_cutoff;

		$new_comment = AFC_Comment_Fields::replace_value(
			$comment,
			self::configured_key( 'promisedPayDate' ),
			$promise ? $promise->format( 'Y-m-d' ) : ''
		);
		$new_comment = AFC_Comment_Fields::replace_value(
			$new_comment,
			self::configured_key( 'cutoffDate' ),
			$effective->format( 'Y-m-d' )
		);
		if ( $grace['normalize'] ) {
			$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'grace', (string) $grace['used'] );
		}

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
				'message'         => $promise
					? sprintf( __( 'Promise date saved for %s.', 'airfiber-centralized' ), $current_name )
					: sprintf( __( 'Promise date cleared for %s.', 'airfiber-centralized' ), $current_name ),
				'promiseDate'     => $promise ? $promise->format( 'Y-m-d' ) : '',
				'cutoffDate'      => $effective->format( 'Y-m-d' ),
				'normalizedGrace' => $grace['normalize'],
			)
		);
	}
}
