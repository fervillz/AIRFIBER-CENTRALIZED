<?php

defined( 'ABSPATH' ) || exit;

/**
 * Payment-behaviour ratings and respectful automated due-reminder rules.
 */
class AFC_SMS_Payer_Ratings {

	const OPTION_RULES = 'afc_sms_payor_rules';
	const NONCE_ACTION = 'afc_sms_payor_ratings';
	const CRON_HOOK    = 'afc_sms_due_reminder_scan';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule_scan' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 43 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_manager' ), 21 );

		add_action( 'wp_ajax_afc_sms_payors_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_afc_sms_payor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_sms_payor_rules_save', array( __CLASS__, 'ajax_save_rules' ) );
		add_action( 'wp_ajax_afc_sms_due_scan_now', array( __CLASS__, 'ajax_scan_now' ) );
	}

	public static function schedule_scan() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	private static function can_render() {
		return class_exists( 'AFC_Frontend_Page' )
			&& AFC_Frontend_Page::is_app_request()
			&& current_user_can( 'manage_options' );
	}

	public static function enqueue_assets() {
		if ( ! self::can_render() ) {
			return;
		}
		wp_enqueue_style(
			'afc-sms-payer-ratings',
			AFC_URL . 'assets/css/sms-payer-ratings.css',
			array( 'afc-sms-center' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-sms-payer-ratings',
			AFC_URL . 'assets/js/sms-payer-ratings.js',
			array( 'afc-sms-center' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-sms-payer-ratings',
			'afcSmsPayerRatings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'rules'   => self::get_rules(),
			)
		);
	}

	public static function render_manager() {
		if ( ! self::can_render() ) {
			return;
		}
		include AFC_PATH . 'templates/admin/sms-payer-ratings.php';
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage payor ratings.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_list() {
		self::authorize_ajax();
		wp_send_json_success(
			array(
				'customers' => self::customer_rows(),
				'rules'     => self::get_rules(),
				'templates' => class_exists( 'AFC_SMS_Templates' ) ? AFC_SMS_Templates::enabled_template_options() : array(),
			)
		);
	}

	public static function ajax_save() {
		self::authorize_ajax();
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		if ( ! $customer_id || 'afc_customer' !== get_post_type( $customer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected customer could not be found.', 'airfiber-centralized' ) ) );
		}

		$rating      = isset( $_POST['rating'] ) ? max( 0, min( 5, absint( $_POST['rating'] ) ) ) : 3;
		$rating_mode = isset( $_POST['rating_mode'] ) ? sanitize_key( wp_unslash( $_POST['rating_mode'] ) ) : 'manual';
		if ( ! in_array( $rating_mode, array( 'manual', 'auto' ), true ) ) {
			$rating_mode = 'manual';
		}
		$template_mode = isset( $_POST['template_mode'] ) ? sanitize_key( wp_unslash( $_POST['template_mode'] ) ) : 'inherit';
		if ( ! in_array( $template_mode, array( 'inherit', 'fixed', 'random_due', 'random_all' ), true ) ) {
			$template_mode = 'inherit';
		}

		update_post_meta( $customer_id, '_afc_sms_rating_mode', $rating_mode );
		update_post_meta( $customer_id, '_afc_sms_payer_rating', $rating );
		update_post_meta( $customer_id, '_afc_sms_contact_paused', isset( $_POST['contact_paused'] ) && '1' === (string) $_POST['contact_paused'] ? '1' : '0' );
		update_post_meta( $customer_id, '_afc_sms_contact_note', isset( $_POST['contact_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['contact_note'] ) ) : '' );
		update_post_meta( $customer_id, '_afc_sms_template_mode', $template_mode );
		update_post_meta( $customer_id, '_afc_sms_template_id', isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0 );

		$profile = self::profile( $customer_id );
		wp_send_json_success(
			array(
				'message'  => __( 'Payment behaviour and reminder preference saved.', 'airfiber-centralized' ),
				'customer' => self::customer_row( $customer_id, $profile ),
			)
		);
	}

	public static function ajax_save_rules() {
		self::authorize_ajax();
		$current = self::get_rules();
		$current['automation_enabled'] = isset( $_POST['automation_enabled'] ) && '1' === (string) $_POST['automation_enabled'] ? 1 : 0;
		$current['send_start_hour']    = isset( $_POST['send_start_hour'] ) ? max( 0, min( 23, absint( $_POST['send_start_hour'] ) ) ) : 9;
		$current['send_end_hour']      = isset( $_POST['send_end_hour'] ) ? max( 0, min( 23, absint( $_POST['send_end_hour'] ) ) : 18;
		$current['max_per_scan']       = isset( $_POST['max_per_scan'] ) ? max( 1, min( 100, absint( $_POST['max_per_scan'] ) ) ) : 30;
		$current['max_per_day']        = isset( $_POST['max_per_day'] ) ? max( 1, min( 300, absint( $_POST['max_per_day'] ) ) ) : 100;
		$current['pause_on_reply']     = isset( $_POST['pause_on_reply'] ) && '1' === (string) $_POST['pause_on_reply'] ? 1 : 0;

		foreach ( range( 0, 5 ) as $rating ) {
			$key = 'rating_' . $rating;
			$existing = isset( $current['profiles'][ $rating ] ) ? $current['profiles'][ $rating ] : array();
			$current['profiles'][ $rating ] = array(
				'label'            => isset( $existing['label'] ) ? $existing['label'] : 'Rating ' . $rating,
				'first_delay_days' => isset( $_POST[ $key . '_first' ] ) ? max( 0, min( 30, absint( $_POST[ $key . '_first' ] ) ) ) : 0,
				'repeat_days'      => isset( $_POST[ $key . '_repeat' ] ) ? max( 1, min( 30, absint( $_POST[ $key . '_repeat' ] ) ) ) : 3,
				'max_7_days'       => isset( $_POST[ $key . '_max7' ] ) ? max( 1, min( 7, absint( $_POST[ $key . '_max7' ] ) ) ) : 2,
				'max_30_days'      => isset( $_POST[ $key . '_max30' ] ) ? max( 1, min( 30, absint( $_POST[ $key . '_max30' ] ) ) ) : 4,
				'tone'             => isset( $existing['tone'] ) ? $existing['tone'] : 'standard',
			);
		}

		update_option( self::OPTION_RULES, $current, false );
		wp_send_json_success( array( 'message' => __( 'Due reminder rules saved.', 'airfiber-centralized' ), 'rules' => $current ) );
	}

	public static function ajax_scan_now() {
		self::authorize_ajax();
		$result = self::scan_due_reminders( true );
		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Due scan complete: %1$d queued, %2$d reviewed, %3$d skipped.', 'airfiber-centralized' ),
					$result['queued'],
					$result['reviewed'],
					$result['skipped']
				),
				'result' => $result,
			)
		);
	}

	public static function run_scheduled_scan() {
		self::scan_due_reminders( false );
	}

	public static function default_rules() {
		return array(
			'automation_enabled' => 0,
			'send_start_hour'    => 9,
			'send_end_hour'      => 18,
			'max_per_scan'       => 30,
			'max_per_day'        => 100,
			'pause_on_reply'     => 1,
			'profiles'           => array(
				0 => array( 'label' => 'Manual review', 'first_delay_days' => 0, 'repeat_days' => 1, 'max_7_days' => 4, 'max_30_days' => 10, 'tone' => 'firm' ),
				1 => array( 'label' => 'Frequently late', 'first_delay_days' => 0, 'repeat_days' => 2, 'max_7_days' => 3, 'max_30_days' => 8, 'tone' => 'firm' ),
				2 => array( 'label' => 'Often late', 'first_delay_days' => 0, 'repeat_days' => 2, 'max_7_days' => 3, 'max_30_days' => 6, 'tone' => 'firm' ),
				3 => array( 'label' => 'Standard', 'first_delay_days' => 1, 'repeat_days' => 3, 'max_7_days' => 2, 'max_30_days' => 4, 'tone' => 'standard' ),
				4 => array( 'label' => 'Reliable', 'first_delay_days' => 2, 'repeat_days' => 3, 'max_7_days' => 2, 'max_30_days' => 3, 'tone' => 'polite' ),
				5 => array( 'label' => 'Excellent payor', 'first_delay_days' => 4, 'repeat_days' => 4, 'max_7_days' => 1, 'max_30_days' => 2, 'tone' => 'gentle' ),
			),
		);
	}

	public static function get_rules() {
		$stored = get_option( self::OPTION_RULES, array() );
		$rules  = wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_rules() );
		$defaults = self::default_rules();
		$rules['profiles'] = isset( $rules['profiles'] ) && is_array( $rules['profiles'] ) ? $rules['profiles'] : array();
		foreach ( range( 0, 5 ) as $rating ) {
			$rules['profiles'][ $rating ] = wp_parse_args(
				isset( $rules['profiles'][ $rating ] ) && is_array( $rules['profiles'][ $rating ] ) ? $rules['profiles'][ $rating ] : array(),
				$defaults['profiles'][ $rating ]
			);
		}
		return $rules;
	}

	public static function profile( $customer_id ) {
		$observed  = (int) get_post_meta( $customer_id, '_afc_sms_payments_observed', true );
		$on_time   = (int) get_post_meta( $customer_id, '_afc_sms_ontime_payments', true );
		$total_late = (int) get_post_meta( $customer_id, '_afc_sms_total_days_late', true );
		$suggested = self::suggested_rating( $observed, $on_time, $total_late );
		$mode      = (string) get_post_meta( $customer_id, '_afc_sms_rating_mode', true );
		$mode      = in_array( $mode, array( 'manual', 'auto' ), true ) ? $mode : 'auto';
		$stored    = get_post_meta( $customer_id, '_afc_sms_payer_rating', true );
		$rating    = '' === (string) $stored ? $suggested : max( 0, min( 5, (int) $stored ) );
		if ( 'auto' === $mode ) {
			$rating = $suggested;
		}
		$rules = self::get_rules();
		return array(
			'rating'             => $rating,
			'rating_mode'        => $mode,
			'suggested_rating'   => $suggested,
			'rating_label'       => $rules['profiles'][ $rating ]['label'],
			'rule'               => $rules['profiles'][ $rating ],
			'contact_paused'     => '1' === (string) get_post_meta( $customer_id, '_afc_sms_contact_paused', true ),
			'contact_note'       => (string) get_post_meta( $customer_id, '_afc_sms_contact_note', true ),
			'last_reply_at'      => (string) get_post_meta( $customer_id, '_afc_sms_last_reply_at', true ),
			'last_reminder_at'   => (string) get_post_meta( $customer_id, '_afc_sms_last_reminder_at', true ),
			'last_days_late'     => (int) get_post_meta( $customer_id, '_afc_sms_last_payment_days_late', true ),
			'payments_observed'  => $observed,
			'ontime_payments'    => $on_time,
			'total_days_late'    => $total_late,
			'template_mode'      => (string) get_post_meta( $customer_id, '_afc_sms_template_mode', true ) ?: 'inherit',
			'template_id'        => (int) get_post_meta( $customer_id, '_afc_sms_template_id', true ),
		);
	}

	private static function suggested_rating( $observed, $on_time, $total_late ) {
		if ( $observed < 1 ) {
			return 3;
		}
		$ratio = $on_time / max( 1, $observed );
		$average_late = $total_late / max( 1, $observed );
		if ( $ratio >= 0.9 && $average_late <= 0.5 ) return 5;
		if ( $ratio >= 0.75 && $average_late <= 1.5 ) return 4;
		if ( $ratio >= 0.55 && $average_late <= 3 ) return 3;
		if ( $ratio >= 0.35 && $average_late <= 6 ) return 2;
		if ( $average_late <= 10 ) return 1;
		return 0;
	}

	public static function record_payment( $customer_id, $due_date, $payment_date ) {
		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) return;
		$due = self::parse_date( $due_date );
		$paid = self::parse_date( $payment_date );
		if ( ! $due || ! $paid ) return;
		$days_late = (int) $due->diff( $paid )->format( '%r%a' );
		$observed = (int) get_post_meta( $customer_id, '_afc_sms_payments_observed', true ) + 1;
		$on_time = (int) get_post_meta( $customer_id, '_afc_sms_ontime_payments', true ) + ( $days_late <= 0 ? 1 : 0 );
		$total_late = (int) get_post_meta( $customer_id, '_afc_sms_total_days_late', true ) + max( 0, $days_late );
		$suggested = self::suggested_rating( $observed, $on_time, $total_late );
		update_post_meta( $customer_id, '_afc_sms_payments_observed', $observed );
		update_post_meta( $customer_id, '_afc_sms_ontime_payments', $on_time );
		update_post_meta( $customer_id, '_afc_sms_total_days_late', $total_late );
		update_post_meta( $customer_id, '_afc_sms_last_payment_days_late', $days_late );
		update_post_meta( $customer_id, '_afc_sms_suggested_rating', $suggested );
		update_post_meta( $customer_id, '_afc_sms_contact_paused', '0' );
		if ( 'manual' !== (string) get_post_meta( $customer_id, '_afc_sms_rating_mode', true ) ) {
			update_post_meta( $customer_id, '_afc_sms_payer_rating', $suggested );
			update_post_meta( $customer_id, '_afc_sms_rating_mode', 'auto' );
		}
	}

	public static function record_reply( $phone, $received_at = '' ) {
		$normalized = self::normalize_phone( $phone );
		if ( ! $normalized ) return;
		foreach ( self::customer_ids() as $customer_id ) {
			if ( self::normalize_phone( get_post_meta( $customer_id, '_afc_phone', true ) ) !== $normalized ) continue;
			update_post_meta( $customer_id, '_afc_sms_last_reply_at', $received_at ? $received_at : current_time( 'mysql' ) );
			if ( ! empty( self::get_rules()['pause_on_reply'] ) ) {
				update_post_meta( $customer_id, '_afc_sms_contact_paused', '1' );
				update_post_meta( $customer_id, '_afc_sms_contact_note', 'Automatic due reminders paused because the customer replied.' );
			}
		}
	}

	private static function customer_ids() {
		return get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	private static function customer_rows() {
		$rows = array();
		foreach ( self::customer_ids() as $customer_id ) {
			$rows[] = self::customer_row( $customer_id, self::profile( $customer_id ) );
		}
		usort( $rows, function ( $a, $b ) {
			if ( $a['rating'] !== $b['rating'] ) return $b['rating'] <=> $a['rating'];
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return $rows;
	}

	private static function customer_row( $customer_id, $profile ) {
		$name = trim( (string) get_post_meta( $customer_id, '_afc_customer_name', true ) );
		if ( ! $name ) $name = get_the_title( $customer_id );
		return array_merge(
			array(
				'customer_id'  => (int) $customer_id,
				'name'         => $name,
				'ppp_username' => (string) get_post_meta( $customer_id, '_afc_ppp_username', true ),
				'phone'        => self::normalize_phone( get_post_meta( $customer_id, '_afc_phone', true ) ),
				'next_due'     => (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true ),
				'payment_date' => (string) get_post_meta( $customer_id, '_afc_payment_date', true ),
				'amount'       => (string) get_post_meta( $customer_id, '_afc_payment_amount', true ),
				'do_not_text'  => self::truthy( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) || self::truthy( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) ),
			),
			$profile
		);
	}

	public static function scan_due_reminders( $manual = false ) {
		global $wpdb;
		$rules = self::get_rules();
		$result = array( 'queued' => 0, 'reviewed' => 0, 'skipped' => 0, 'reasons' => array() );
		if ( ! $manual && empty( $rules['automation_enabled'] ) ) {
			$result['reasons'][] = 'Automation is disabled.';
			return $result;
		}
		$hour = (int) current_time( 'G' );
		if ( ! $manual && ( $hour < (int) $rules['send_start_hour'] || $hour >= (int) $rules['send_end_hour'] ) ) {
			$result['reasons'][] = 'Outside configured sending hours.';
			return $result;
		}
		if ( ! class_exists( 'AFC_SMS_Templates' ) ) {
			$result['reasons'][] = 'Message library is unavailable.';
			return $result;
		}

		$jobs_table = $wpdb->prefix . 'afc_sms_jobs';
		$events_table = $wpdb->prefix . 'afc_sms_events';
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$today_start = $today->format( 'Y-m-d 00:00:00' );
		$already_today = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE reminder_type = 'due-auto' AND created_at >= %s", $today_start )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$remaining_today = max( 0, (int) $rules['max_per_day'] - $already_today );
		$scan_limit = min( (int) $rules['max_per_scan'], $remaining_today );
		if ( $scan_limit < 1 ) {
			$result['reasons'][] = 'Daily automatic reminder cap reached.';
			return $result;
		}

		foreach ( self::customer_ids() as $customer_id ) {
			$result['reviewed']++;
			if ( $result['queued'] >= $scan_limit ) break;
			$row = self::customer_row( $customer_id, self::profile( $customer_id ) );
			if ( $row['do_not_text'] || $row['contact_paused'] || ! $row['phone'] || ! $row['ppp_username'] ) {
				$result['skipped']++;
				continue;
			}
			$due = self::parse_date( $row['next_due'] );
			if ( ! $due || $due > $today ) {
				$result['skipped']++;
				continue;
			}
			$days_overdue = (int) $due->diff( $today )->format( '%r%a' );
			$policy = $rules['profiles'][ $row['rating'] ];
			if ( $days_overdue < (int) $policy['first_delay_days'] ) {
				$result['skipped']++;
				continue;
			}

			$since_due = $due->format( 'Y-m-d 00:00:00' );
			$history = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at FROM {$jobs_table} WHERE customer_id = %d AND reminder_type = 'due-auto' AND created_at >= %s ORDER BY id DESC",
					$customer_id,
					$since_due
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$last = $history ? self::parse_datetime( $history[0]->created_at ) : null;
			if ( $last && (int) $last->diff( new DateTimeImmutable( current_time( 'mysql' ), self::timezone() ) )->format( '%a' ) < (int) $policy['repeat_days'] ) {
				$result['skipped']++;
				continue;
			}
			$last_7 = 0;
			$last_30 = 0;
			$now_ts = current_time( 'timestamp' );
			foreach ( (array) $history as $sent ) {
				$sent_ts = strtotime( $sent->created_at );
				if ( $sent_ts >= $now_ts - 7 * DAY_IN_SECONDS ) $last_7++;
				if ( $sent_ts >= $now_ts - 30 * DAY_IN_SECONDS ) $last_30++;
			}
			if ( $last_7 >= (int) $policy['max_7_days'] || $last_30 >= (int) $policy['max_30_days'] ) {
				$result['skipped']++;
				continue;
			}

			$library = AFC_SMS_Templates::get_settings();
			$mode = $row['template_mode'];
			$template_id = $row['template_id'];
			if ( 'inherit' === $mode ) {
				$mode = isset( $library['default_mode'] ) ? $library['default_mode'] : 'random_category';
				$template_id = isset( $library['default_template_id'] ) ? (int) $library['default_template_id'] : 0;
			}
			if ( 'random_due' === $mode ) $mode = 'random_category';
			if ( 'manual' === $mode ) $mode = 'random_category';
			$template = AFC_SMS_Templates::choose_template( 'due', $mode, $template_id );
			if ( ! $template ) {
				$result['skipped']++;
				continue;
			}
			$message = AFC_SMS_Templates::apply_tokens(
				$template['body'],
				array(
					'name'           => $row['name'],
					'ppp'            => $row['ppp_username'],
					'phone'          => $row['phone'],
					'due_date'       => $row['next_due'],
					'amount'         => $row['amount'] ? $row['amount'] : 'regular monthly bill',
					'payment_number' => isset( $library['payment_number'] ) ? $library['payment_number'] : '09978230630',
				)
			);
			$dedupe = 'due-auto|' . strtolower( $row['ppp_username'] ) . '|' . $row['next_due'] . '|' . $today->format( 'Ymd' );
			$inserted = $wpdb->insert(
				$jobs_table,
				array(
					'dedupe_key'    => $dedupe,
					'customer_id'   => $customer_id,
					'ppp_username'  => $row['ppp_username'],
					'customer_name' => $row['name'],
					'phone'         => $row['phone'],
					'message'       => $message,
					'reminder_type' => 'due-auto',
					'status'        => 'queued',
					'last_detail'   => 'Queued by payor-rating due policy.',
					'created_by'    => 0,
					'created_at'    => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( ! $inserted ) {
				$result['skipped']++;
				continue;
			}
			$job_id = (int) $wpdb->insert_id;
			$wpdb->insert(
				$events_table,
				array(
					'job_id'     => $job_id,
					'device_id'  => '',
					'status'     => 'queued',
					'detail'     => sprintf( 'Queued automatically for %d-star payor, %d day(s) overdue.', $row['rating'], $days_overdue ),
					'event_time' => current_time( 'mysql' ),
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			update_post_meta( $customer_id, '_afc_sms_last_reminder_at', current_time( 'mysql' ) );
			$result['queued']++;
		}
		return $result;
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return null;
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function parse_datetime( $value ) {
		try {
			return new DateTimeImmutable( (string) $value, self::timezone() );
		} catch ( Exception $error ) {
			return null;
		}
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) return '+63' . substr( $digits, 1 );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '639' ) ) return '+' . $digits;
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) return '+63' . $digits;
		return '';
	}

	private static function truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}
}
