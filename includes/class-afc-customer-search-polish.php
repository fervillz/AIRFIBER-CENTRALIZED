<?php

defined( 'ABSPATH' ) || exit;

/**
 * Polishes customer search results and calculates live account signals directly
 * from MikroTik result data, even when an account has not yet been imported.
 */
class AFC_Customer_Search_Polish {

	const NONCE = 'afc_customer_search_polish';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 1015 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 1015 );
		add_action( 'wp_ajax_afc_customer_search_signals_v2', array( __CLASS__, 'ajax_signals' ) );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) return;
		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) return;
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		if ( wp_script_is( 'afc-customer-search-polish', 'enqueued' ) ) return;
		wp_enqueue_style( 'afc-customer-search-polish', AFC_URL . 'assets/css/customer-search-polish.css', array( 'afc-customer-actions' ), AFC_VERSION );
		wp_enqueue_script( 'afc-customer-search-polish', AFC_URL . 'assets/js/customer-search-polish.js', array( 'jquery', 'afc-customer-actions', 'afc-ui-icons' ), AFC_VERSION, true );
		wp_localize_script(
			'afc-customer-search-polish',
			'afcCustomerSearchPolish',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'dueSoonDays'  => 3,
				'upcomingDays' => 7,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => __( 'You do not have permission to read customer signals.', 'airfiber-centralized' ) ), 403 );
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return null;
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		return $date && ( ! is_array( $errors ) || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value ? $date : null;
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

	private static function days_between( DateTimeImmutable $from, DateTimeImmutable $to ) {
		return (int) $from->diff( $to )->format( '%r%a' );
	}

	private static function normalize_account( $value ) {
		return substr( sanitize_text_field( trim( (string) $value ) ), 0, 190 );
	}

	private static function phone( $value ) {
		if ( class_exists( 'AFC_SMS_Payer_Ratings' ) ) return AFC_SMS_Payer_Ratings::phone( $value );
		$digits = preg_replace( '/\D+/', '', (string) $value );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) return '+63' . substr( $digits, 1 );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '639' ) ) return '+' . $digits;
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) return '+63' . $digits;
		return '';
	}

	private static function comment_value( $comment, $key ) {
		$comment = str_replace( array( "\r\n", "\r" ), "\n", (string) $comment );
		$pattern = '/(?:^|\n|\s)' . preg_quote( $key, '/' ) . '\s*:\s*(.*?)(?=(?:\n|\s)[A-Za-z][A-Za-z0-9_-]*\s*:|$)/is';
		return preg_match( $pattern, $comment, $match ) ? trim( preg_replace( '/\s+/', ' ', $match[1] ) ) : '';
	}

	private static function customer_ids( $accounts ) {
		global $wpdb;
		if ( ! $accounts ) return array();
		$placeholders = implode( ',', array_fill( 0, count( $accounts ), '%s' ) );
		$sql = "SELECT p.ID, pm.meta_value AS account FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_afc_ppp_username' WHERE p.post_type='afc_customer' AND pm.meta_value IN ({$placeholders}) ORDER BY p.ID DESC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $accounts ) );
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
		$sql = "SELECT j.* FROM {$table} j INNER JOIN (SELECT LOWER(ppp_username) AS account_key, MAX(id) AS max_id FROM {$table} WHERE ppp_username IN ({$placeholders}) GROUP BY LOWER(ppp_username)) latest ON latest.max_id=j.id";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $accounts ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) $out[ strtolower( (string) $row['ppp_username'] ) ] = $row;
		return $out;
	}

	private static function phone_variants( $phone ) {
		$normalized = self::phone( $phone );
		$digits = preg_replace( '/\D+/', '', $normalized );
		if ( ! $normalized || ! $digits ) return array();
		$variants = array( $normalized, $digits );
		if ( 0 === strpos( $digits, '63' ) ) {
			$local = '0' . substr( $digits, 2 );
			$variants[] = $local;
			$variants[] = substr( $local, 1 );
		}
		return array_values( array_unique( array_filter( $variants ) ) );
	}

	private static function latest_incoming( $users ) {
		global $wpdb;
		$variant_map = array();
		$variants = array();
		foreach ( $users as $user ) {
			$account = strtolower( $user['account'] );
			foreach ( self::phone_variants( $user['phone'] ) as $variant ) {
				$variants[] = $variant;
				$variant_map[ $variant ][] = $account;
			}
		}
		$variants = array_values( array_unique( $variants ) );
		if ( ! $variants ) return array();
		$table = $wpdb->prefix . 'afc_sms_incoming';
		$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$sql = "SELECT * FROM {$table} WHERE phone IN ({$placeholders}) ORDER BY id DESC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $variants ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$phone = (string) $row['phone'];
			if ( empty( $variant_map[ $phone ] ) ) continue;
			foreach ( $variant_map[ $phone ] as $account ) {
				if ( ! isset( $out[ $account ] ) ) $out[ $account ] = $row;
			}
		}
		return $out;
	}

	private static function sanitize_users() {
		$raw = isset( $_POST['users'] ) ? wp_unslash( $_POST['users'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array();
		}
		$out = array();
		foreach ( array_slice( (array) $raw, 0, 150 ) as $item ) {
			if ( ! is_array( $item ) ) continue;
			$account = self::normalize_account( isset( $item['account'] ) ? $item['account'] : ( isset( $item['name'] ) ? $item['name'] : '' ) );
			if ( '' === $account ) continue;
			$out[ strtolower( $account ) ] = array(
				'account'        => $account,
				'customer_name'  => isset( $item['customer_name'] ) ? sanitize_text_field( $item['customer_name'] ) : '',
				'phone'          => isset( $item['phone'] ) ? sanitize_text_field( $item['phone'] ) : '',
				'comment'        => isset( $item['comment'] ) ? sanitize_textarea_field( $item['comment'] ) : '',
				'installed'      => isset( $item['installed'] ) ? sanitize_text_field( $item['installed'] ) : '',
				'payment_date'   => isset( $item['payment_date'] ) ? sanitize_text_field( $item['payment_date'] ) : '',
				'actual_profile' => isset( $item['actual_profile'] ) ? sanitize_text_field( $item['actual_profile'] ) : '',
				'profile'        => isset( $item['profile'] ) ? sanitize_text_field( $item['profile'] ) : '',
			);
		}
		return array_values( $out );
	}

	private static function job_state( $job ) {
		$status = $job && isset( $job['status'] ) ? sanitize_key( $job['status'] ) : '';
		$at = '';
		$time = 0;
		foreach ( array( 'delivered_at', 'sent_at', 'submitted_at', 'created_at' ) as $field ) {
			if ( ! empty( $job[ $field ] ) ) {
				$at = (string) $job[ $field ];
				$time = self::timestamp( $at );
				break;
			}
		}
		return array( $status, $at, $time );
	}

	private static function signal( $user, $customer_id, $job, $incoming ) {
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$comment = $user['comment'];
		$next = self::comment_value( $comment, 'nextDue' );
		$cutoff = self::comment_value( $comment, 'cutoffDate' );

		if ( $customer_id ) {
			if ( '' === $next ) $next = trim( (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true ) );
			if ( '' === $cutoff ) $cutoff = trim( (string) get_post_meta( $customer_id, '_afc_comment_field_cutoffdate', true ) );
		}

		$due_date = self::date( $next );
		$cutoff_date = self::date( $cutoff );
		$days_to_due = $due_date ? self::days_between( $today, $due_date ) : null;
		$days_to_cutoff = $cutoff_date ? self::days_between( $today, $cutoff_date ) : null;

		// EXPIRED means one thing only: the current MikroTik PPP profile is
		// literally named Expired. Dates never turn a customer into EXPIRED.
		$expired = 0 === strcasecmp( trim( (string) $user['actual_profile'] ), 'Expired' );

		$due_state = 'unknown';
		if ( $expired ) {
			$due_state = 'expired';
		} elseif ( null !== $days_to_due && $days_to_due <= 0 ) {
			$due_state = 'due';
		} elseif ( null !== $days_to_due && $days_to_due <= 3 ) {
			$due_state = 'soon';
		} elseif ( null !== $days_to_cutoff && $days_to_cutoff >= 0 && $days_to_cutoff <= 3 ) {
			$due_state = 'soon';
		} elseif ( ( null !== $days_to_due && $days_to_due <= 7 ) || ( null !== $days_to_cutoff && $days_to_cutoff >= 0 && $days_to_cutoff <= 7 ) ) {
			$due_state = 'upcoming';
		} elseif ( null !== $days_to_due || ( null !== $days_to_cutoff && $days_to_cutoff >= 0 ) ) {
			$due_state = 'safe';
		}

		$reminder_enabled = $customer_id && '1' === (string) get_post_meta( $customer_id, '_afc_sms_precutoff_enabled', true );
		$reminder_days = $customer_id ? (int) get_post_meta( $customer_id, '_afc_sms_precutoff_days', true ) : 3;
		$reminder_days = max( 1, min( 14, $reminder_days ?: 3 ) );
		$seen_time = $customer_id ? self::timestamp( get_post_meta( $customer_id, '_afc_sms_last_incoming_seen_at', true ) ) : 0;

		list( $job_status, $job_at, $job_time ) = self::job_state( $job );
		$incoming_time = $incoming && ! empty( $incoming['created_at'] ) ? self::timestamp( $incoming['created_at'] ) : 0;
		$incoming_unread = $incoming_time > max( $seen_time, $job_time );
		$target = $reminder_enabled && $cutoff_date ? $cutoff_date->modify( '-' . $reminder_days . ' days' ) : null;
		$reminder_due = $target && $today >= $target && $today < $cutoff_date;
		$reminder_future = $target && $today < $target;
		$job_day = $job_time ? wp_date( 'Y-m-d', $job_time, self::timezone() ) : '';
		$sent_today = $job_day === $today->format( 'Y-m-d' ) && in_array( $job_status, array( 'submitted', 'sent', 'delivered' ), true );

		$sms_state = 'none';
		$sms_label = '';
		if ( $incoming_unread ) {
			$sms_state = 'received';
			$sms_label = 'NEW';
		} elseif ( $job && in_array( $job_status, array( 'queued', 'claimed' ), true ) ) {
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
		} elseif ( $job && in_array( $job_status, array( 'submitted', 'sent', 'delivered' ), true ) ) {
			$sms_state = 'sent';
			$sms_label = $job_time ? wp_date( 'n-j', $job_time, self::timezone() ) : 'SENT';
		}

		$payment = self::date( $user['payment_date'] );
		$install = self::date( $user['installed'] );
		$paid_recent = $payment ? self::days_between( $payment, $today ) >= 0 && self::days_between( $payment, $today ) <= 7 : false;
		$new_install = $install ? self::days_between( $install, $today ) >= 0 && self::days_between( $install, $today ) <= 30 : false;

		return array(
			'account'         => $user['account'],
			'customerId'      => (int) $customer_id,
			'dueState'        => $due_state,
			'daysToDue'       => $days_to_due,
			'daysToCutoff'    => $days_to_cutoff,
			'nextDue'         => $next,
			'cutoffDate'      => $cutoff,
			'smsState'        => $sms_state,
			'smsLabel'        => $sms_label,
			'lastSmsStatus'   => $job_status,
			'lastSmsAt'       => $job_at,
			'incomingUnread'  => $incoming_unread,
			'paidRecent'      => $paid_recent,
			'paymentDate'     => $user['payment_date'],
			'newInstall'      => $new_install,
			'installedDate'   => $user['installed'],
			'reminderEnabled' => $reminder_enabled,
			'reminderDays'    => $reminder_days,
			'serviceState'    => $expired ? 'expired' : 'active',
			'phoneValid'      => (bool) self::phone( $user['phone'] ),
		);
	}

	public static function ajax_signals() {
		self::authorize();
		$users = self::sanitize_users();
		if ( ! $users ) wp_send_json_success( array( 'signals' => array() ) );

		$accounts = array_column( $users, 'account' );
		$customer_ids = self::customer_ids( $accounts );
		$jobs = self::latest_jobs( $accounts );
		$incoming = self::latest_incoming( $users );
		$signals = array();

		foreach ( $users as $user ) {
			$key = strtolower( $user['account'] );
			$customer_id = isset( $customer_ids[ $key ] ) ? $customer_ids[ $key ] : 0;
			$signals[ $user['account'] ] = self::signal(
				$user,
				$customer_id,
				isset( $jobs[ $key ] ) ? $jobs[ $key ] : array(),
				isset( $incoming[ $key ] ) ? $incoming[ $key ] : array()
			);
		}
		wp_send_json_success( array( 'signals' => $signals ) );
	}
}
