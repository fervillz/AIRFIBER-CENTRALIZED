<?php

defined( 'ABSPATH' ) || exit;

class AFC_SMS_Payer_Ratings {
	const OPTION_RULES = 'afc_sms_payor_rules';
	const NONCE_ACTION = 'afc_sms_payor_ratings';
	const CRON_HOOK = 'afc_sms_due_reminder_scan';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 43 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render' ), 21 );
		add_action( 'wp_ajax_afc_sms_payors_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_afc_sms_payor_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_sms_payor_rules_save', array( __CLASS__, 'ajax_rules' ) );
		add_action( 'wp_ajax_afc_sms_due_scan_now', array( __CLASS__, 'ajax_scan' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
	}

	public static function unschedule() {
		while ( $time = wp_next_scheduled( self::CRON_HOOK ) ) wp_unschedule_event( $time, self::CRON_HOOK );
	}

	private static function app() {
		return class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request() && current_user_can( 'manage_options' );
	}

	public static function assets() {
		if ( ! self::app() ) return;
		wp_enqueue_style( 'afc-sms-payer-ratings', AFC_URL . 'assets/css/sms-payer-ratings.css', array( 'afc-sms-center' ), AFC_VERSION );
		wp_enqueue_script( 'afc-sms-payer-ratings', AFC_URL . 'assets/js/sms-payer-ratings.js', array( 'afc-sms-center' ), AFC_VERSION, true );
		wp_localize_script( 'afc-sms-payer-ratings', 'afcSmsPayerRatings', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE_ACTION ), 'rules' => self::rules() ) );
	}

	public static function render() {
		if ( self::app() ) include AFC_PATH . 'templates/admin/sms-payer-ratings.php';
	}

	private static function auth() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_list() {
		self::auth();
		wp_send_json_success( array( 'customers' => self::customers(), 'rules' => self::rules(), 'templates' => class_exists( 'AFC_SMS_Templates' ) ? AFC_SMS_Templates::enabled_template_options( 'due' ) : array() ) );
	}

	public static function ajax_save() {
		self::auth();
		$id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		if ( ! $id || 'afc_customer' !== get_post_type( $id ) ) wp_send_json_error( array( 'message' => 'Customer not found.' ) );
		$rating = isset( $_POST['rating'] ) ? max( 0, min( 5, absint( $_POST['rating'] ) ) ) : 3;
		$rating_mode = isset( $_POST['rating_mode'] ) && 'manual' === sanitize_key( wp_unslash( $_POST['rating_mode'] ) ) ? 'manual' : 'auto';
		$template_mode = isset( $_POST['template_mode'] ) ? sanitize_key( wp_unslash( $_POST['template_mode'] ) ) : 'inherit';
		if ( ! in_array( $template_mode, array( 'inherit', 'fixed', 'random_due', 'random_all' ), true ) ) $template_mode = 'inherit';
		update_post_meta( $id, '_afc_sms_rating_mode', $rating_mode );
		update_post_meta( $id, '_afc_sms_payer_rating', $rating );
		update_post_meta( $id, '_afc_sms_contact_paused', isset( $_POST['contact_paused'] ) && '1' === (string) $_POST['contact_paused'] ? '1' : '0' );
		update_post_meta( $id, '_afc_sms_contact_note', isset( $_POST['contact_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['contact_note'] ) ) : '' );
		update_post_meta( $id, '_afc_sms_template_mode', $template_mode );
		update_post_meta( $id, '_afc_sms_template_id', isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0 );
		wp_send_json_success( array( 'message' => 'Customer reminder policy saved.', 'customer' => self::customer( $id ) ) );
	}

	public static function ajax_rules() {
		self::auth();
		$r = self::rules();
		$r['automation_enabled'] = isset( $_POST['automation_enabled'] ) && '1' === (string) $_POST['automation_enabled'] ? 1 : 0;
		$r['send_start_hour'] = isset( $_POST['send_start_hour'] ) ? max( 0, min( 23, absint( $_POST['send_start_hour'] ) ) ) : 9;
		$r['send_end_hour'] = isset( $_POST['send_end_hour'] ) ? max( 0, min( 23, absint( $_POST['send_end_hour'] ) ) ) : 18;
		$r['max_per_scan'] = isset( $_POST['max_per_scan'] ) ? max( 1, min( 100, absint( $_POST['max_per_scan'] ) ) ) : 30;
		$r['max_per_day'] = isset( $_POST['max_per_day'] ) ? max( 1, min( 300, absint( $_POST['max_per_day'] ) ) ) : 100;
		$r['pause_on_reply'] = isset( $_POST['pause_on_reply'] ) && '1' === (string) $_POST['pause_on_reply'] ? 1 : 0;
		foreach ( range( 0, 5 ) as $n ) {
			$k = 'rating_' . $n;
			$r['profiles'][ $n ]['first_delay_days'] = isset( $_POST[ $k . '_first' ] ) ? max( 0, min( 30, absint( $_POST[ $k . '_first' ] ) ) ) : $r['profiles'][ $n ]['first_delay_days'];
			$r['profiles'][ $n ]['repeat_days'] = isset( $_POST[ $k . '_repeat' ] ) ? max( 1, min( 30, absint( $_POST[ $k . '_repeat' ] ) ) ) : $r['profiles'][ $n ]['repeat_days'];
			$r['profiles'][ $n ]['max_7_days'] = isset( $_POST[ $k . '_max7' ] ) ? max( 1, min( 7, absint( $_POST[ $k . '_max7' ] ) ) ) : $r['profiles'][ $n ]['max_7_days'];
			$r['profiles'][ $n ]['max_30_days'] = isset( $_POST[ $k . '_max30' ] ) ? max( 1, min( 30, absint( $_POST[ $k . '_max30' ] ) ) ) : $r['profiles'][ $n ]['max_30_days'];
		}
		update_option( self::OPTION_RULES, $r, false );
		wp_send_json_success( array( 'message' => 'Due reminder rules saved.', 'rules' => $r ) );
	}

	public static function ajax_scan() {
		self::auth();
		$x = self::scan( true );
		wp_send_json_success( array( 'message' => sprintf( 'Due scan complete: %d queued, %d reviewed, %d skipped.', $x['queued'], $x['reviewed'], $x['skipped'] ), 'result' => $x ) );
	}

	public static function run_scheduled_scan() { self::scan( false ); }

	public static function defaults() {
		return array(
			'automation_enabled' => 0, 'send_start_hour' => 9, 'send_end_hour' => 18, 'max_per_scan' => 30, 'max_per_day' => 100, 'pause_on_reply' => 1,
			'profiles' => array(
				0 => array( 'label' => 'Manual review', 'first_delay_days' => 0, 'repeat_days' => 1, 'max_7_days' => 4, 'max_30_days' => 10 ),
				1 => array( 'label' => 'Frequently late', 'first_delay_days' => 0, 'repeat_days' => 2, 'max_7_days' => 3, 'max_30_days' => 8 ),
				2 => array( 'label' => 'Often late', 'first_delay_days' => 0, 'repeat_days' => 2, 'max_7_days' => 3, 'max_30_days' => 6 ),
				3 => array( 'label' => 'Standard', 'first_delay_days' => 1, 'repeat_days' => 3, 'max_7_days' => 2, 'max_30_days' => 4 ),
				4 => array( 'label' => 'Reliable', 'first_delay_days' => 2, 'repeat_days' => 3, 'max_7_days' => 2, 'max_30_days' => 3 ),
				5 => array( 'label' => 'Excellent payor', 'first_delay_days' => 4, 'repeat_days' => 4, 'max_7_days' => 1, 'max_30_days' => 2 ),
			),
		);
	}

	public static function rules() {
		$d = self::defaults();
		$r = wp_parse_args( (array) get_option( self::OPTION_RULES, array() ), $d );
		foreach ( range( 0, 5 ) as $n ) $r['profiles'][ $n ] = wp_parse_args( isset( $r['profiles'][ $n ] ) ? $r['profiles'][ $n ] : array(), $d['profiles'][ $n ] );
		return $r;
	}

	private static function suggested( $count, $ontime, $late_days ) {
		if ( $count < 1 ) return 3;
		$ratio = $ontime / $count;
		$avg = $late_days / $count;
		if ( $ratio >= .9 && $avg <= .5 ) return 5;
		if ( $ratio >= .75 && $avg <= 1.5 ) return 4;
		if ( $ratio >= .55 && $avg <= 3 ) return 3;
		if ( $ratio >= .35 && $avg <= 6 ) return 2;
		return $avg <= 10 ? 1 : 0;
	}

	public static function profile( $id ) {
		$count = (int) get_post_meta( $id, '_afc_sms_payments_observed', true );
		$ontime = (int) get_post_meta( $id, '_afc_sms_ontime_payments', true );
		$late = (int) get_post_meta( $id, '_afc_sms_total_days_late', true );
		$suggested = self::suggested( $count, $ontime, $late );
		$mode = 'manual' === (string) get_post_meta( $id, '_afc_sms_rating_mode', true ) ? 'manual' : 'auto';
		$stored = get_post_meta( $id, '_afc_sms_payer_rating', true );
		$rating = 'auto' === $mode || '' === (string) $stored ? $suggested : max( 0, min( 5, (int) $stored ) );
		$rule = self::rules()['profiles'][ $rating ];
		return array( 'rating' => $rating, 'rating_mode' => $mode, 'suggested_rating' => $suggested, 'rating_label' => $rule['label'], 'rule' => $rule,
			'contact_paused' => '1' === (string) get_post_meta( $id, '_afc_sms_contact_paused', true ), 'contact_note' => (string) get_post_meta( $id, '_afc_sms_contact_note', true ),
			'last_reply_at' => (string) get_post_meta( $id, '_afc_sms_last_reply_at', true ), 'last_reminder_at' => (string) get_post_meta( $id, '_afc_sms_last_reminder_at', true ),
			'last_days_late' => (int) get_post_meta( $id, '_afc_sms_last_payment_days_late', true ), 'payments_observed' => $count, 'ontime_payments' => $ontime, 'total_days_late' => $late,
			'template_mode' => (string) get_post_meta( $id, '_afc_sms_template_mode', true ) ?: 'inherit', 'template_id' => (int) get_post_meta( $id, '_afc_sms_template_id', true ) );
	}

	public static function record_payment( $id, $due_value, $paid_value ) {
		$due = self::date( $due_value ); $paid = self::date( $paid_value );
		if ( ! $id || ! $due || ! $paid ) return;
		$days = (int) $due->diff( $paid )->format( '%r%a' );
		$count = (int) get_post_meta( $id, '_afc_sms_payments_observed', true ) + 1;
		$ontime = (int) get_post_meta( $id, '_afc_sms_ontime_payments', true ) + ( $days <= 0 ? 1 : 0 );
		$late = (int) get_post_meta( $id, '_afc_sms_total_days_late', true ) + max( 0, $days );
		$rating = self::suggested( $count, $ontime, $late );
		update_post_meta( $id, '_afc_sms_payments_observed', $count ); update_post_meta( $id, '_afc_sms_ontime_payments', $ontime ); update_post_meta( $id, '_afc_sms_total_days_late', $late );
		update_post_meta( $id, '_afc_sms_last_payment_days_late', $days ); update_post_meta( $id, '_afc_sms_suggested_rating', $rating ); update_post_meta( $id, '_afc_sms_contact_paused', '0' ); update_post_meta( $id, '_afc_sms_contact_note', '' );
		if ( 'manual' !== (string) get_post_meta( $id, '_afc_sms_rating_mode', true ) ) { update_post_meta( $id, '_afc_sms_payer_rating', $rating ); update_post_meta( $id, '_afc_sms_rating_mode', 'auto' ); }
	}

	public static function record_reply( $phone, $time = '' ) {
		$phone = self::phone( $phone ); if ( ! $phone ) return;
		foreach ( self::ids() as $id ) if ( self::phone( get_post_meta( $id, '_afc_phone', true ) ) === $phone ) {
			update_post_meta( $id, '_afc_sms_last_reply_at', $time ?: current_time( 'mysql' ) );
			if ( self::rules()['pause_on_reply'] ) { update_post_meta( $id, '_afc_sms_contact_paused', '1' ); update_post_meta( $id, '_afc_sms_contact_note', 'Automatic due reminders paused because the customer replied.' ); }
		}
	}

	private static function ids() { return get_posts( array( 'post_type' => 'afc_customer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ); }

	private static function customer( $id ) {
		$name = trim( (string) get_post_meta( $id, '_afc_customer_name', true ) ); if ( ! $name ) $name = get_the_title( $id );
		return array_merge( array( 'customer_id' => (int) $id, 'name' => $name, 'ppp_username' => (string) get_post_meta( $id, '_afc_ppp_username', true ), 'phone' => self::phone( get_post_meta( $id, '_afc_phone', true ) ),
			'next_due' => (string) get_post_meta( $id, '_afc_comment_field_nextdue', true ), 'payment_date' => (string) get_post_meta( $id, '_afc_payment_date', true ), 'amount' => (string) get_post_meta( $id, '_afc_payment_amount', true ),
			'do_not_text' => self::truth( get_post_meta( $id, '_afc_do_not_text', true ) ) || self::truth( get_post_meta( $id, '_afc_sms_opt_out', true ) ) ), self::profile( $id ) );
	}

	private static function customers() {
		$x = array(); foreach ( self::ids() as $id ) $x[] = self::customer( $id );
		usort( $x, function ( $a, $b ) { return $a['rating'] === $b['rating'] ? strcasecmp( $a['name'], $b['name'] ) : $b['rating'] <=> $a['rating']; } ); return $x;
	}

	public static function scan( $manual = false ) {
		global $wpdb; $r = self::rules(); $out = array( 'queued' => 0, 'reviewed' => 0, 'skipped' => 0, 'reasons' => array() );
		if ( ! $manual && ! $r['automation_enabled'] ) return $out;
		$hour = (int) current_time( 'G' ); if ( ! $manual && ( $hour < $r['send_start_hour'] || $hour >= $r['send_end_hour'] ) ) return $out;
		if ( ! class_exists( 'AFC_SMS_Templates' ) ) return $out;
		$jobs = $wpdb->prefix . 'afc_sms_jobs'; $events = $wpdb->prefix . 'afc_sms_events'; $today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::tz() );
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs} WHERE reminder_type='due-auto' AND created_at >= %s", $today->format( 'Y-m-d 00:00:00' ) ) );
		$limit = min( $r['max_per_scan'], max( 0, $r['max_per_day'] - $today_count ) ); if ( $limit < 1 ) return $out;
		foreach ( self::ids() as $id ) {
			$out['reviewed']++; if ( $out['queued'] >= $limit ) break; $c = self::customer( $id );
			if ( $c['do_not_text'] || $c['contact_paused'] || ! $c['phone'] || ! $c['ppp_username'] ) { $out['skipped']++; continue; }
			$due = self::date( $c['next_due'] ); if ( ! $due || $due > $today ) { $out['skipped']++; continue; }
			$over = (int) $due->diff( $today )->format( '%r%a' ); $p = $r['profiles'][ $c['rating'] ]; if ( $over < $p['first_delay_days'] ) { $out['skipped']++; continue; }
			$hist = $wpdb->get_results( $wpdb->prepare( "SELECT created_at FROM {$jobs} WHERE customer_id=%d AND reminder_type='due-auto' AND created_at >= %s ORDER BY id DESC", $id, $due->format( 'Y-m-d 00:00:00' ) ) );
			if ( $hist ) { $last = new DateTimeImmutable( $hist[0]->created_at, self::tz() ); if ( (int) $last->diff( new DateTimeImmutable( current_time( 'mysql' ), self::tz() ) )->format( '%a' ) < $p['repeat_days'] ) { $out['skipped']++; continue; } }
			$n7 = 0; $n30 = 0; $now = current_time( 'timestamp' ); foreach ( (array) $hist as $h ) { $t = strtotime( $h->created_at ); if ( $t >= $now - 7 * DAY_IN_SECONDS ) $n7++; if ( $t >= $now - 30 * DAY_IN_SECONDS ) $n30++; }
			if ( $n7 >= $p['max_7_days'] || $n30 >= $p['max_30_days'] ) { $out['skipped']++; continue; }
			$lib = AFC_SMS_Templates::get_settings(); $mode = $c['template_mode']; $tid = $c['template_id']; if ( 'inherit' === $mode ) { $mode = $lib['default_mode']; $tid = $lib['default_template_id']; }
			if ( in_array( $mode, array( 'manual', 'random_due', 'random_all' ), true ) ) $mode = 'random_category';
			$tpl = AFC_SMS_Templates::choose_template( 'due', $mode, $tid ); if ( ! $tpl ) { $out['skipped']++; continue; }
			$msg = AFC_SMS_Templates::apply_tokens( $tpl['body'], array( 'name' => $c['name'], 'ppp' => $c['ppp_username'], 'phone' => $c['phone'], 'due_date' => $c['next_due'], 'amount' => $c['amount'] ?: 'regular monthly bill', 'payment_number' => $lib['payment_number'] ) );
			$key = 'due-auto|' . strtolower( $c['ppp_username'] ) . '|' . $c['next_due'] . '|' . $today->format( 'Ymd' ); $time = current_time( 'mysql' );
			$inserted = $wpdb->insert(
				$jobs,
				array( 'dedupe_key' => $key, 'customer_id' => $id, 'ppp_username' => $c['ppp_username'], 'customer_name' => $c['name'], 'phone' => $c['phone'], 'message' => $msg, 'reminder_type' => 'due-auto', 'status' => 'queued', 'last_detail' => 'Queued by payor-rating due policy.', 'created_by' => 0, 'created_at' => $time, 'updated_at' => $time ),
				array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' )
			);
			if ( ! $inserted ) { $out['skipped']++; continue; }
			$wpdb->insert( $events, array( 'job_id' => $wpdb->insert_id, 'device_id' => '', 'status' => 'queued', 'detail' => sprintf( 'Queued for %d-star payor, %d day(s) overdue.', $c['rating'], $over ), 'event_time' => $time, 'created_at' => $time ), array( '%d','%s','%s','%s','%s','%s' ) );
			update_post_meta( $id, '_afc_sms_last_reminder_at', $time ); $out['queued']++;
		}
		return $out;
	}

	private static function date( $v ) { $v = trim( (string) $v ); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) return null; $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $v, self::tz() ); return $d && $d->format( 'Y-m-d' ) === $v ? $d : null; }
	private static function tz() { return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ); }
	public static function phone( $v ) { $d = preg_replace( '/\D+/', '', (string) $v ); if ( 11 === strlen( $d ) && 0 === strpos( $d, '09' ) ) return '+63' . substr( $d, 1 ); if ( 12 === strlen( $d ) && 0 === strpos( $d, '639' ) ) return '+' . $d; if ( 10 === strlen( $d ) && 0 === strpos( $d, '9' ) ) return '+63' . $d; return ''; }
	private static function truth( $v ) { return in_array( strtolower( trim( (string) $v ) ), array( '1','yes','true','on' ), true ); }
}
