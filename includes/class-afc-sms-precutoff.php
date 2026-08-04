<?php

defined( 'ABSPATH' ) || exit;

/**
 * Per-customer pre-cutoff SMS reminders, compact account signals and API-ready
 * customer actions. SMS jobs still flow through the existing Android gateway.
 */
class AFC_SMS_PreCutoff {

	const NONCE          = 'afc_sms_precutoff';
	const CRON_HOOK      = 'afc_sms_precutoff_scan';
	const REST_NAMESPACE = 'airfiber/v1';
	const OPTION_MESSAGE = 'afc_sms_precutoff_message';

	const META_ENABLED       = '_afc_sms_precutoff_enabled';
	const META_DAYS          = '_afc_sms_precutoff_days';
	const META_LAST_CUTOFF   = '_afc_sms_precutoff_last_queued_cutoff';
	const META_LAST_QUEUED   = '_afc_sms_precutoff_last_queued_at';
	const META_INCOMING_SEEN = '_afc_sms_last_incoming_seen_at';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ), 25 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 1002 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 1002 );

		add_action( 'wp_ajax_afc_customer_signals', array( __CLASS__, 'ajax_signals' ) );
		add_action( 'wp_ajax_afc_precutoff_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_precutoff_send_now', array( __CLASS__, 'ajax_send_now' ) );
		add_action( 'wp_ajax_afc_precutoff_mark_seen', array( __CLASS__, 'ajax_mark_seen' ) );

		add_action( 'afc_payment_recorded', array( __CLASS__, 'cancel_after_payment' ), 35, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 180, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		while ( $time = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $time, self::CRON_HOOK );
		}
	}

	private static function can_render_frontend() {
		return class_exists( 'AFC_Frontend_Page' )
			&& AFC_Frontend_Page::is_app_request()
			&& current_user_can( 'manage_options' );
	}

	public static function enqueue_frontend_assets() {
		if ( ! self::can_render_frontend() ) {
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
		if ( wp_script_is( 'afc-customer-actions', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-ui-icons',
			AFC_URL . 'assets/css/ui-icons.css',
			array(),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ui-icons',
			AFC_URL . 'assets/js/ui-icons.js',
			array(),
			AFC_VERSION,
			true
		);

		wp_enqueue_style(
			'afc-customer-actions',
			AFC_URL . 'assets/css/customer-actions.css',
			array( 'afc-ui-icons' ),
			AFC_VERSION
		);

		$dependencies = array( 'jquery', 'afc-ui-icons' );
		if ( wp_script_is( 'afc-ppp-users', 'registered' ) ) {
			$dependencies[] = 'afc-ppp-users';
		}
		wp_enqueue_script(
			'afc-customer-actions',
			AFC_URL . 'assets/js/customer-actions.js',
			$dependencies,
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-customer-actions',
			'afcCustomerActions',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'defaultDays' => 3,
				'restUrl'     => rest_url( self::REST_NAMESPACE . '/customer-signals' ),
				'labels'      => array(
					'accountOptions' => __( 'Account options', 'airfiber-centralized' ),
					'smsReminder'   => __( 'SMS before cutoff', 'airfiber-centralized' ),
					'sendNow'       => __( 'Send SMS now', 'airfiber-centralized' ),
					'save'          => __( 'Save reminder', 'airfiber-centralized' ),
					'saving'        => __( 'Saving…', 'airfiber-centralized' ),
					'queued'        => __( 'SMS queued.', 'airfiber-centralized' ),
				),
			)
		);
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage customer reminders.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		return $date && ( ! is_array( $errors ) || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function normalize_account( $value ) {
		return substr( sanitize_text_field( trim( (string) $value ) ), 0, 190 );
	}

	private static function phone( $value ) {
		if ( class_exists( 'AFC_SMS_Payer_Ratings' ) ) {
			return AFC_SMS_Payer_Ratings::phone( $value );
		}
		$digits = preg_replace( '/\D+/', '', (string) $value );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) return '+63' . substr( $digits, 1 );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '639' ) ) return '+' . $digits;
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) return '+63' . $digits;
		return '';
	}

	private static function truth( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function customer_id( $account ) {
		global $wpdb;
		$account = self::normalize_account( $account );
		if ( '' === $account ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_afc_ppp_username' WHERE p.post_type='afc_customer' AND pm.meta_value=%s ORDER BY p.ID DESC LIMIT 1",
				$account
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function request_user() {
		return array(
			'account'        => self::normalize_account( isset( $_POST['account'] ) ? wp_unslash( $_POST['account'] ) : '' ),
			'customer_name'  => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
			'phone'          => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'comment'        => isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '',
			'installed'      => isset( $_POST['installed'] ) ? sanitize_text_field( wp_unslash( $_POST['installed'] ) ) : '',
			'payment_date'   => isset( $_POST['payment_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_date'] ) ) : '',
			'payment_amount' => isset( $_POST['payment_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_amount'] ) ) : '',
			'profile'        => isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '',
			'actual_profile' => isset( $_POST['actual_profile'] ) ? sanitize_text_field( wp_unslash( $_POST['actual_profile'] ) ) : '',
		);
	}

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, (string) $canonical ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function ensure_customer( $user ) {
		$account = isset( $user['account'] ) ? self::normalize_account( $user['account'] ) : '';
		if ( '' === $account ) {
			return new WP_Error( 'afc_precutoff_missing_account', __( 'Choose a valid PPP account.', 'airfiber-centralized' ) );
		}
		$customer_id = self::customer_id( $account );
		if ( ! $customer_id ) {
			$customer_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => ! empty( $user['customer_name'] ) ? $user['customer_name'] : $account,
				),
				true
			);
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			update_post_meta( $customer_id, '_afc_ppp_username', $account );
			update_post_meta( $customer_id, '_afc_account_number', 'AIR-' . str_pad( (string) $customer_id, 6, '0', STR_PAD_LEFT ) );
		}

		$details = class_exists( 'AFC_Comment_Fields' )
			? AFC_Comment_Fields::parse_comment( isset( $user['comment'] ) ? $user['comment'] : '' )
			: array();
		$name    = ! empty( $user['customer_name'] ) ? $user['customer_name'] : ( isset( $details['name'] ) ? $details['name'] : '' );
		$phone   = ! empty( $user['phone'] ) ? $user['phone'] : ( isset( $details['cp'] ) ? $details['cp'] : '' );
		$next    = self::custom_value( $details, 'nextDue' );
		$cutoff  = self::custom_value( $details, 'cutoffDate' );
		$paid    = self::custom_value( $details, 'paidThrough' );

		$meta = array(
			'_afc_customer_name'             => $name,
			'_afc_phone'                     => $phone,
			'_afc_mikrotik_comment'          => isset( $user['comment'] ) ? $user['comment'] : '',
			'_afc_installation_date'         => ! empty( $user['installed'] ) ? $user['installed'] : ( isset( $details['installed'] ) ? $details['installed'] : '' ),
			'_afc_payment_date'              => ! empty( $user['payment_date'] ) ? $user['payment_date'] : ( isset( $details['payment_date'] ) ? $details['payment_date'] : '' ),
			'_afc_payment_amount'            => ! empty( $user['payment_amount'] ) ? $user['payment_amount'] : ( isset( $details['payment_amount'] ) ? $details['payment_amount'] : '' ),
			'_afc_plan'                      => isset( $user['profile'] ) ? $user['profile'] : '',
			'_afc_customer_status'           => 0 === strcasecmp( isset( $user['actual_profile'] ) ? $user['actual_profile'] : '', 'Expired' ) ? 'expired' : 'active',
			'_afc_comment_field_nextdue'     => $next,
			'_afc_comment_field_cutoffdate'  => $cutoff,
			'_afc_comment_field_paidthrough' => $paid,
		);
		foreach ( $meta as $key => $value ) {
			if ( '' !== (string) $value ) {
				update_post_meta( $customer_id, $key, $value );
			}
		}
		return (int) $customer_id;
	}

	private static function customer_field( $customer_id, $meta_key, $comment_key = '' ) {
		$value = trim( (string) get_post_meta( $customer_id, $meta_key, true ) );
		if ( '' !== $value || '' === $comment_key || ! class_exists( 'AFC_Comment_Fields' ) ) {
			return $value;
		}
		$details = AFC_Comment_Fields::parse_comment( (string) get_post_meta( $customer_id, '_afc_mikrotik_comment', true ) );
		return self::custom_value( $details, $comment_key );
	}

	private static function default_message() {
		return __( 'Hi {name}, friendly reminder: your internet service cutoff is on {cutoff_date}. Please settle your account before cutoff. Thank you.', 'airfiber-centralized' );
	}

	private static function message_template() {
		$message = trim( (string) get_option( self::OPTION_MESSAGE, '' ) );
		$message = '' !== $message ? $message : self::default_message();
		return (string) apply_filters( 'afc_sms_precutoff_message_template', $message );
	}

	private static function customer_data( $customer_id ) {
		$account = (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
		$name    = trim( (string) get_post_meta( $customer_id, '_afc_customer_name', true ) );
		if ( '' === $name ) {
			$name = get_the_title( $customer_id );
		}
		$cutoff = self::customer_field( $customer_id, '_afc_comment_field_cutoffdate', 'cutoffDate' );
		$next   = self::customer_field( $customer_id, '_afc_comment_field_nextdue', 'nextDue' );
		return array(
			'customer_id'    => (int) $customer_id,
			'account'        => $account,
			'name'           => $name ? $name : $account,
			'phone'          => self::phone( get_post_meta( $customer_id, '_afc_phone', true ) ),
			'cutoff'         => $cutoff,
			'next_due'       => $next,
			'amount'         => (string) get_post_meta( $customer_id, '_afc_payment_amount', true ),
			'installed'      => (string) get_post_meta( $customer_id, '_afc_installation_date', true ),
			'payment_date'   => (string) get_post_meta( $customer_id, '_afc_payment_date', true ),
			'status'         => (string) get_post_meta( $customer_id, '_afc_customer_status', true ),
			'enabled'        => '1' === (string) get_post_meta( $customer_id, self::META_ENABLED, true ),
			'days'           => max( 1, min( 14, (int) get_post_meta( $customer_id, self::META_DAYS, true ) ?: 3 ) ),
			'do_not_text'    => self::truth( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) || self::truth( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) ),
			'contact_paused' => '1' === (string) get_post_meta( $customer_id, '_afc_sms_contact_paused', true ),
		);
	}

	private static function apply_tokens( $template, $data ) {
		$cutoff = self::date( $data['cutoff'] );
		$due    = self::date( $data['next_due'] );
		$settings = (array) get_option( 'afc_sms_template_settings', array() );
		return strtr(
			(string) $template,
			array(
				'{name}'           => (string) $data['name'],
				'{ppp}'            => (string) $data['account'],
				'{phone}'          => (string) $data['phone'],
				'{due_date}'       => $due ? wp_date( 'M j, Y', $due->getTimestamp(), self::timezone() ) : (string) $data['next_due'],
				'{cutoff_date}'    => $cutoff ? wp_date( 'M j, Y', $cutoff->getTimestamp(), self::timezone() ) : (string) $data['cutoff'],
				'{amount}'         => (string) ( $data['amount'] ? $data['amount'] : 'regular monthly bill' ),
				'{payment_number}' => isset( $settings['payment_number'] ) ? (string) $settings['payment_number'] : '',
				'{days_before}'    => (string) $data['days'],
			)
		);
	}

	private static function queue_job( $customer_id, $force = false, $source = 'precutoff-auto' ) {
		global $wpdb;
		$data   = self::customer_data( $customer_id );
		$cutoff = self::date( $data['cutoff'] );
		$today  = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );

		if ( ! $cutoff ) {
			return new WP_Error( 'afc_precutoff_no_cutoff', __( 'This customer has no valid cutoff date.', 'airfiber-centralized' ) );
		}
		if ( ! $data['phone'] ) {
			return new WP_Error( 'afc_precutoff_no_phone', __( 'This customer has no valid Philippine mobile number.', 'airfiber-centralized' ) );
		}
		if ( $data['do_not_text'] ) {
			return new WP_Error( 'afc_precutoff_optout', __( 'This customer is marked Do Not Text.', 'airfiber-centralized' ) );
		}
		if ( ! $force && ( ! $data['enabled'] || $data['contact_paused'] ) ) {
			return new WP_Error( 'afc_precutoff_paused', __( 'Automatic SMS reminders are not active for this customer.', 'airfiber-centralized' ) );
		}

		$target = $cutoff->modify( '-' . $data['days'] . ' days' );
		if ( ! $force && ( $today < $target || $today >= $cutoff ) ) {
			return new WP_Error( 'afc_precutoff_not_due', __( 'The pre-cutoff reminder is not due today.', 'airfiber-centralized' ) );
		}

		$jobs   = $wpdb->prefix . 'afc_sms_jobs';
		$events = $wpdb->prefix . 'afc_sms_events';
		$key    = $force
			? 'precutoff-manual|' . strtolower( $data['account'] ) . '|' . current_time( 'YmdHis' ) . '|' . wp_generate_password( 5, false, false )
			: 'precutoff-auto|' . strtolower( $data['account'] ) . '|' . $cutoff->format( 'Y-m-d' ) . '|' . $data['days'];
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$jobs} WHERE dedupe_key=%s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return array( 'queued' => false, 'duplicate' => true, 'job_id' => $existing );
		}

		$now     = current_time( 'mysql' );
		$message = self::apply_tokens( self::message_template(), $data );
		$inserted = $wpdb->insert(
			$jobs,
			array(
				'dedupe_key'    => $key,
				'customer_id'   => $customer_id,
				'ppp_username'  => $data['account'],
				'customer_name' => $data['name'],
				'phone'         => $data['phone'],
				'message'       => $message,
				'reminder_type' => $source,
				'status'        => 'queued',
				'device_id'     => '',
				'last_detail'   => $force ? 'Queued manually from customer actions.' : sprintf( 'Queued automatically %d day(s) before cutoff.', $data['days'] ),
				'created_by'    => $force ? get_current_user_id() : 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'afc_precutoff_queue_failed', __( 'The SMS could not be added to the gateway queue.', 'airfiber-centralized' ) );
		}

		$job_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$events,
			array(
				'job_id'     => $job_id,
				'device_id'  => '',
				'status'     => 'queued',
				'detail'     => $force ? 'Queued manually.' : sprintf( 'Pre-cutoff reminder for %s.', $cutoff->format( 'Y-m-d' ) ),
				'event_time' => $now,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		update_post_meta( $customer_id, self::META_LAST_CUTOFF, $cutoff->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, self::META_LAST_QUEUED, $now );
		return array( 'queued' => true, 'duplicate' => false, 'job_id' => $job_id, 'message' => $message );
	}

	public static function run_scheduled_scan() {
		global $wpdb;
		$rules = class_exists( 'AFC_SMS_Payer_Ratings' ) ? AFC_SMS_Payer_Ratings::rules() : array();
		$hour  = (int) current_time( 'G' );
		$start = isset( $rules['send_start_hour'] ) ? (int) $rules['send_start_hour'] : 9;
		$end   = isset( $rules['send_end_hour'] ) ? (int) $rules['send_end_hour'] : 18;
		if ( $hour < $start || $hour >= $end ) {
			return;
		}

		$max_scan = isset( $rules['max_per_scan'] ) ? max( 1, min( 100, (int) $rules['max_per_scan'] ) ) : 30;
		$max_day  = isset( $rules['max_per_day'] ) ? max( 1, min( 300, (int) $rules['max_per_day'] ) ) : 100;
		$jobs     = $wpdb->prefix . 'afc_sms_jobs';
		$today    = current_time( 'Y-m-d' ) . ' 00:00:00';
		$sent_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$jobs} WHERE reminder_type IN ('precutoff-auto','precutoff-manual') AND created_at >= %s",
				$today
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$limit = min( $max_scan, max( 0, $max_day - $sent_today ) );
		if ( $limit < 1 ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_ENABLED,
				'meta_value'     => '1',
			)
		);
		if ( ! $ids ) {
			return;
		}
		self::refresh_customer_dates_from_router( $ids );
		$queued = 0;
		foreach ( $ids as $customer_id ) {
			if ( $queued >= $limit ) break;
			$result = self::queue_job( (int) $customer_id, false, 'precutoff-auto' );
			if ( is_wp_error( $result ) ) {
				if ( ! in_array( $result->get_error_code(), array( 'afc_precutoff_not_due', 'afc_precutoff_paused', 'afc_precutoff_no_cutoff', 'afc_precutoff_no_phone', 'afc_precutoff_optout' ), true ) ) {
					do_action( 'afc_sms_precutoff_error', (int) $customer_id, $result );
				}
				continue;
			}
			if ( ! empty( $result['queued'] ) ) $queued++;
		}
	}

	private static function refresh_customer_dates_from_router( $customer_ids ) {
		if ( ! class_exists( 'AFC_MikroTik' ) || ! class_exists( 'AFC_Comment_Fields' ) ) {
			return;
		}
		$result = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		$ids_by_account = array();
		foreach ( $customer_ids as $customer_id ) {
			$account = (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			if ( $account ) $ids_by_account[ strtolower( $account ) ] = (int) $customer_id;
		}
		foreach ( $result as $secret ) {
			$account = isset( $secret['name'] ) ? strtolower( trim( (string) $secret['name'] ) ) : '';
			if ( ! $account || ! isset( $ids_by_account[ $account ] ) ) continue;
			$customer_id = $ids_by_account[ $account ];
			$comment     = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			$details     = AFC_Comment_Fields::parse_comment( $comment );
			$fields      = array(
				'_afc_mikrotik_comment'         => $comment,
				'_afc_comment_field_nextdue'    => self::custom_value( $details, 'nextDue' ),
				'_afc_comment_field_cutoffdate' => self::custom_value( $details, 'cutoffDate' ),
				'_afc_phone'                    => isset( $details['cp'] ) ? $details['cp'] : '',
				'_afc_customer_name'            => isset( $details['name'] ) ? $details['name'] : '',
				'_afc_payment_date'             => isset( $details['payment_date'] ) ? $details['payment_date'] : '',
				'_afc_payment_amount'           => isset( $details['payment_amount'] ) ? $details['payment_amount'] : '',
				'_afc_installation_date'        => isset( $details['installed'] ) ? $details['installed'] : '',
				'_afc_customer_status'          => 0 === strcasecmp( isset( $secret['profile'] ) ? $secret['profile'] : '', 'Expired' ) ? 'expired' : 'active',
			);
			foreach ( $fields as $key => $value ) {
				if ( '' !== (string) $value ) update_post_meta( $customer_id, $key, $value );
			}
		}
	}

	private static function parse_accounts_request() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $raw );
		}
		$accounts = array();
		foreach ( (array) $raw as $value ) {
			$account = self::normalize_account( $value );
			if ( $account ) $accounts[ strtolower( $account ) ] = $account;
		}
		return array_slice( array_values( $accounts ), 0, 250 );
	}

	private static function customer_rows( $accounts ) {
		global $wpdb;
		if ( ! $accounts ) return array();
		$placeholders = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$sql = "SELECT p.ID, pm.meta_value AS account FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_afc_ppp_username' WHERE p.post_type='afc_customer' AND pm.meta_value IN ({$placeholders}) ORDER BY p.ID DESC";
		$prepared = $wpdb->prepare( $sql, $accounts ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		foreach ( $rows as $row ) {
			$key = strtolower( (string) $row->account );
			if ( ! isset( $out[ $key ] ) ) $out[ $key ] = (int) $row->ID;
		}
		if ( $out ) update_meta_cache( 'post', array_values( $out ) );
		return $out;
	}

	private static function latest_jobs( $accounts ) {
		global $wpdb;
		if ( ! $accounts ) return array();
		$table = $wpdb->prefix . 'afc_sms_jobs';
		$placeholders = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$sql = "SELECT j.* FROM {$table} j INNER JOIN (SELECT ppp_username, MAX(id) AS max_id FROM {$table} WHERE ppp_username IN ({$placeholders}) GROUP BY ppp_username) x ON x.max_id=j.id";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $accounts ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		foreach ( $rows as $row ) $out[ strtolower( (string) $row['ppp_username'] ) ] = $row;
		return $out;
	}

	private static function latest_incoming( $phones ) {
		global $wpdb;
		$phones = array_values( array_unique( array_filter( $phones ) ) );
		if ( ! $phones ) return array();
		$variants = array();
		foreach ( $phones as $phone ) {
			$normalized = self::phone( $phone );
			$digits     = preg_replace( '/\D+/', '', $normalized );
			if ( ! $normalized || ! $digits ) continue;
			$variants[] = $normalized;
			$variants[] = $digits;
			if ( 0 === strpos( $digits, '63' ) ) {
				$local = '0' . substr( $digits, 2 );
				$variants[] = $local;
				$variants[] = substr( $local, 1 );
			}
		}
		$variants = array_values( array_unique( array_filter( $variants ) ) );
		if ( ! $variants ) return array();
		$table = $wpdb->prefix . 'afc_sms_incoming';
		$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$sql = "SELECT * FROM {$table} WHERE phone IN ({$placeholders}) ORDER BY id DESC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $variants ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		foreach ( $rows as $row ) {
			$key = self::phone( $row['phone'] );
			if ( $key && ! isset( $out[ $key ] ) ) $out[ $key ] = $row;
		}
		return $out;
	}

	private static function timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return 0;
		try {
			return ( new DateTimeImmutable( $value, self::timezone() ) )->getTimestamp();
		} catch ( Exception $exception ) {
			return 0;
		}
	}

	private static function signal( $account, $customer_id, $job, $incoming ) {
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		if ( ! $customer_id ) {
			return array(
				'account' => $account, 'customerId' => 0, 'reminderEnabled' => false, 'reminderDays' => 3,
				'smsState' => 'none', 'smsLabel' => '', 'dueState' => 'unknown', 'daysToCutoff' => null,
				'cutoffDate' => '', 'nextDue' => '', 'lastSmsAt' => '', 'lastSmsStatus' => '', 'incomingUnread' => false,
				'paidRecent' => false, 'paymentDate' => '', 'newInstall' => false, 'installedDate' => '', 'serviceState' => '', 'phoneValid' => false,
			);
		}
		$data    = self::customer_data( $customer_id );
		$cutoff  = self::date( $data['cutoff'] );
		$days_to = $cutoff ? (int) $today->diff( $cutoff )->format( '%r%a' ) : null;
		$due_state = 'unknown';
		if ( 'expired' === strtolower( $data['status'] ) || ( null !== $days_to && $days_to < 0 ) ) $due_state = 'expired';
		elseif ( null !== $days_to && $days_to <= 3 ) $due_state = 'soon';
		elseif ( null !== $days_to && $days_to <= 7 ) $due_state = 'upcoming';
		elseif ( null !== $days_to ) $due_state = 'safe';

		$job_time = 0;
		$job_at   = '';
		$status   = '';
		if ( $job ) {
			$status = isset( $job['status'] ) ? sanitize_key( $job['status'] ) : '';
			foreach ( array( 'delivered_at', 'sent_at', 'submitted_at', 'created_at' ) as $key ) {
				if ( ! empty( $job[ $key ] ) ) { $job_at = $job[ $key ]; $job_time = self::timestamp( $job_at ); break; }
			}
		}
		$incoming_time = $incoming && ! empty( $incoming['created_at'] ) ? self::timestamp( $incoming['created_at'] ) : 0;
		$seen_time     = self::timestamp( get_post_meta( $customer_id, self::META_INCOMING_SEEN, true ) );
		$incoming_unread = $incoming_time > max( $seen_time, $job_time );

		$sms_state = 'none';
		$sms_label = '';
		$target = $data['enabled'] && $cutoff ? $cutoff->modify( '-' . $data['days'] . ' days' ) : null;
		$reminder_due = $target && $today >= $target && $today < $cutoff;
		$reminder_future = $target && $today < $target;
		$job_day = $job_time ? wp_date( 'Y-m-d', $job_time, self::timezone() ) : '';
		$sent_today = $job_day === $today->format( 'Y-m-d' ) && in_array( $status, array( 'submitted', 'sent', 'delivered' ), true );
		if ( $incoming_unread ) {
			$sms_state = 'received';
			$sms_label = 'NEW';
		} elseif ( $job && in_array( $status, array( 'queued', 'claimed' ), true ) ) {
			$sms_state = 'queued';
			$sms_label = 'TODAY';
		} elseif ( $sent_today ) {
			$sms_state = 'sent';
			$sms_label = $job_time ? wp_date( 'n-j', $job_time, self::timezone() ) : 'SENT';
		} elseif ( $reminder_due ) {
			$sms_state = 'due';
			$sms_label = 'TODAY';
		} elseif ( $reminder_future ) {
			$sms_state = 'scheduled';
			$sms_label = $target->format( 'n-j' );
		} elseif ( $job && in_array( $status, array( 'submitted', 'sent', 'delivered' ), true ) ) {
			$sms_state = 'sent';
			$sms_label = $job_time ? wp_date( 'n-j', $job_time, self::timezone() ) : 'SENT';
		}

		$payment = self::date( $data['payment_date'] );
		$install = self::date( $data['installed'] );
		$paid_recent = $payment ? (int) $payment->diff( $today )->format( '%r%a' ) >= 0 && (int) $payment->diff( $today )->format( '%a' ) <= 7 : false;
		$new_install = $install ? (int) $install->diff( $today )->format( '%r%a' ) >= 0 && (int) $install->diff( $today )->format( '%a' ) <= 30 : false;

		return array(
			'account'          => $account,
			'customerId'       => $customer_id,
			'reminderEnabled'  => $data['enabled'],
			'reminderDays'     => $data['days'],
			'reminderDate'     => $cutoff ? $cutoff->modify( '-' . $data['days'] . ' days' )->format( 'Y-m-d' ) : '',
			'smsState'         => $sms_state,
			'smsLabel'         => $sms_label,
			'dueState'         => $due_state,
			'daysToCutoff'     => $days_to,
			'cutoffDate'       => $data['cutoff'],
			'nextDue'          => $data['next_due'],
			'lastSmsAt'        => $job_at,
			'lastSmsStatus'    => $status,
			'incomingUnread'   => $incoming_unread,
			'paidRecent'       => $paid_recent,
			'paymentDate'      => $data['payment_date'],
			'newInstall'       => $new_install,
			'installedDate'    => $data['installed'],
			'serviceState'     => $data['status'],
			'phoneValid'       => (bool) $data['phone'],
			'contactPaused'    => $data['contact_paused'],
			'doNotText'        => $data['do_not_text'],
		);
	}

	public static function signals_for_accounts( $accounts ) {
		$accounts = array_values( array_filter( array_map( array( __CLASS__, 'normalize_account' ), (array) $accounts ) ) );
		$customer_ids = self::customer_rows( $accounts );
		$jobs         = self::latest_jobs( $accounts );
		$phones       = array();
		foreach ( $customer_ids as $customer_id ) $phones[] = self::phone( get_post_meta( $customer_id, '_afc_phone', true ) );
		$incoming = self::latest_incoming( $phones );
		$out = array();
		foreach ( $accounts as $account ) {
			$key = strtolower( $account );
			$customer_id = isset( $customer_ids[ $key ] ) ? $customer_ids[ $key ] : 0;
			$phone = $customer_id ? self::phone( get_post_meta( $customer_id, '_afc_phone', true ) ) : '';
			$out[ $account ] = self::signal(
				$account,
				$customer_id,
				isset( $jobs[ $key ] ) ? $jobs[ $key ] : array(),
				$phone && isset( $incoming[ $phone ] ) ? $incoming[ $phone ] : array()
			);
		}
		return $out;
	}

	public static function ajax_signals() {
		self::authorize_ajax();
		$accounts = self::parse_accounts_request();
		wp_send_json_success( array( 'signals' => self::signals_for_accounts( $accounts ) ) );
	}

	public static function ajax_save() {
		self::authorize_ajax();
		$user = self::request_user();
		$customer_id = self::ensure_customer( $user );
		if ( is_wp_error( $customer_id ) ) wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'];
		$days    = isset( $_POST['days'] ) ? max( 1, min( 14, absint( $_POST['days'] ) ) ) : 3;
		update_post_meta( $customer_id, self::META_ENABLED, $enabled ? '1' : '0' );
		update_post_meta( $customer_id, self::META_DAYS, $days );

		$queued  = false;
		$message = __( 'Automatic pre-cutoff SMS is disabled for this customer.', 'airfiber-centralized' );
		if ( $enabled ) {
			$data = self::customer_data( $customer_id );
			$message = sprintf( __( 'Automatic SMS will be sent %d day(s) before cutoff.', 'airfiber-centralized' ), $days );
			if ( ! self::date( $data['cutoff'] ) ) {
				$message = sprintf( __( 'Reminder saved for %d day(s) before cutoff. Add a valid cutoff date before it can send.', 'airfiber-centralized' ), $days );
			} elseif ( ! $data['phone'] ) {
				$message = sprintf( __( 'Reminder saved for %d day(s) before cutoff. Add a valid mobile number before it can send.', 'airfiber-centralized' ), $days );
			} elseif ( $data['do_not_text'] ) {
				$message = __( 'Reminder settings were saved, but this customer is marked Do Not Text.', 'airfiber-centralized' );
			} elseif ( $data['contact_paused'] ) {
				$message = __( 'Reminder settings were saved, but automatic SMS is currently paused for this customer.', 'airfiber-centralized' );
			} else {
				$result = self::queue_job( $customer_id, false, 'precutoff-auto' );
				if ( ! is_wp_error( $result ) ) {
					$queued = ! empty( $result['queued'] );
					if ( $queued ) $message = __( 'Reminder saved and today’s SMS was added to the gateway queue.', 'airfiber-centralized' );
				} elseif ( ! in_array( $result->get_error_code(), array( 'afc_precutoff_not_due', 'afc_precutoff_paused' ), true ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
			}
		}
		$signals = self::signals_for_accounts( array( $user['account'] ) );
		wp_send_json_success(
			array(
				'message' => $message,
				'queued'  => $queued,
				'signal'  => isset( $signals[ $user['account'] ] ) ? $signals[ $user['account'] ] : array(),
			)
		);
	}

	public static function ajax_send_now() {
		self::authorize_ajax();
		$user = self::request_user();
		$customer_id = self::ensure_customer( $user );
		if ( is_wp_error( $customer_id ) ) wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		$result = self::queue_job( $customer_id, true, 'precutoff-manual' );
		if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		$signals = self::signals_for_accounts( array( $user['account'] ) );
		wp_send_json_success( array( 'message' => __( 'The SMS was added to the Android gateway queue.', 'airfiber-centralized' ), 'jobId' => $result['job_id'], 'signal' => $signals[ $user['account'] ] ) );
	}

	public static function ajax_mark_seen() {
		self::authorize_ajax();
		$account = self::normalize_account( isset( $_POST['account'] ) ? wp_unslash( $_POST['account'] ) : '' );
		$customer_id = self::customer_id( $account );
		if ( $customer_id ) update_post_meta( $customer_id, self::META_INCOMING_SEEN, current_time( 'mysql' ) );
		wp_send_json_success( array( 'seen' => (bool) $customer_id ) );
	}

	public static function cancel_after_payment( $payment_id, $customer_id ) {
		unset( $payment_id );
		global $wpdb;
		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) return;
		$jobs   = $wpdb->prefix . 'afc_sms_jobs';
		$events = $wpdb->prefix . 'afc_sms_events';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$jobs} WHERE customer_id=%d AND reminder_type IN ('precutoff-auto','precutoff-manual') AND status='queued'", $customer_id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now = current_time( 'mysql' );
		foreach ( (array) $rows as $row ) {
			$updated = $wpdb->update(
				$jobs,
				array( 'status' => 'cancelled', 'last_detail' => 'Cancelled because payment was recorded.', 'cancelled_at' => $now, 'updated_at' => $now ),
				array( 'id' => (int) $row->id, 'status' => 'queued' ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( $updated ) {
				$wpdb->insert( $events, array( 'job_id' => (int) $row->id, 'device_id' => '', 'status' => 'cancelled', 'detail' => 'Cancelled because payment was recorded.', 'event_time' => $now, 'created_at' => $now ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
			}
		}
	}

	public static function rest_permission( $request ) {
		$allowed = current_user_can( 'manage_options' );
		return (bool) apply_filters( 'afc_customer_api_permission', $allowed, $request );
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/customer-signals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_signals' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/customers/(?P<ppp>[A-Za-z0-9._@-]+)/precutoff',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/customers/(?P<ppp>[A-Za-z0-9._@-]+)/sms',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_send' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			)
		);
	}

	public static function rest_signals( WP_REST_Request $request ) {
		$accounts = $request->get_param( 'accounts' );
		if ( is_string( $accounts ) ) $accounts = preg_split( '/[\r\n,]+/', $accounts );
		return rest_ensure_response( array( 'signals' => self::signals_for_accounts( array_slice( (array) $accounts, 0, 250 ) ) ) );
	}

	public static function rest_save( WP_REST_Request $request ) {
		$account = self::normalize_account( $request['ppp'] );
		$customer_id = self::customer_id( $account );
		if ( ! $customer_id ) return new WP_Error( 'afc_customer_missing', __( 'Customer not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		$days = max( 1, min( 14, absint( $request->get_param( 'days' ) ?: 3 ) ) );
		update_post_meta( $customer_id, self::META_ENABLED, $enabled ? '1' : '0' );
		update_post_meta( $customer_id, self::META_DAYS, $days );
		return rest_ensure_response( array( 'signal' => self::signals_for_accounts( array( $account ) )[ $account ] ) );
	}

	public static function rest_send( WP_REST_Request $request ) {
		$account = self::normalize_account( $request['ppp'] );
		$customer_id = self::customer_id( $account );
		if ( ! $customer_id ) return new WP_Error( 'afc_customer_missing', __( 'Customer not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		$result = self::queue_job( $customer_id, true, 'precutoff-manual' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
