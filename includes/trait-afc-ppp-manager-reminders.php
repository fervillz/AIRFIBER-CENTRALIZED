<?php

defined( 'ABSPATH' ) || exit;

trait AFC_PPP_Manager_Reminders_Trait {
	public static function refresh_reminder_after_payment( $payment_id, $customer_id ) {
		unset( $payment_id );
		self::refresh_customer_reminder( $customer_id );
	}

	public static function refresh_reminder_after_quick_payment( $username, $method, $date, $customer_id ) {
		unset( $username, $method, $date );
		self::refresh_customer_reminder( $customer_id );
	}

	private static function refresh_customer_reminder( $customer_id ) {
		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) {
			return;
		}
		$next_due = (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true );
		$date = self::date( $next_due );
		$username = (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
		if ( ! $date || ! $username ) {
			return;
		}
		$reminder = $date->modify( '-1 day' )->format( 'Y-m-d' );
		$secret = self::fetch_secret_by_name( $username );
		if ( ! $secret || is_wp_error( $secret ) ) {
			return;
		}
		$comment = AFC_Comment_Fields::replace_value( isset( $secret['comment'] ) ? $secret['comment'] : '', 'dueReminderDate', $reminder );
		AFC_MikroTik::run_command( array( '/ppp/secret/set', '=.id=' . (string) $secret['.id'], '=comment=' . $comment ) );
		update_post_meta( $customer_id, '_afc_comment_field_duereminderdate', $reminder );
		update_post_meta( $customer_id, '_afc_mikrotik_comment', $comment );
	}

	public static function run_pre_due_scan() {
		if ( ! class_exists( 'AFC_SMS_Payer_Ratings' ) || ! class_exists( 'AFC_SMS_Templates' ) ) {
			return;
		}
		$rules = AFC_SMS_Payer_Ratings::rules();
		if ( empty( $rules['automation_enabled'] ) ) {
			return;
		}
		$hour = (int) current_time( 'G' );
		if ( $hour < (int) $rules['send_start_hour'] || $hour >= (int) $rules['send_end_hour'] ) {
			return;
		}
		global $wpdb;
		$jobs   = $wpdb->prefix . 'afc_sms_jobs';
		$events = $wpdb->prefix . 'afc_sms_events';
		$today  = current_time( 'Y-m-d' );
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs} WHERE reminder_type IN ('due-auto','due-pre') AND created_at >= %s", $today . ' 00:00:00' ) );
		$remaining = max( 0, (int) $rules['max_per_day'] - $today_count );
		if ( $remaining < 1 ) {
			return;
		}
		$ids = get_posts( array( 'post_type' => 'afc_customer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_afc_comment_field_duereminderdate', 'meta_value' => $today ) );
		$queued = 0;
		foreach ( $ids as $customer_id ) {
			if ( $queued >= min( (int) $rules['max_per_scan'], $remaining ) ) {
				break;
			}
			$profile = AFC_SMS_Payer_Ratings::profile( $customer_id );
			if ( $profile['rating'] >= 4 || $profile['contact_paused'] ) {
				continue;
			}
			$do_not_text = get_post_meta( $customer_id, '_afc_do_not_text', true );
			$opt_out     = get_post_meta( $customer_id, '_afc_sms_opt_out', true );
			if ( in_array( strtolower( trim( (string) $do_not_text ) ), array( '1', 'yes', 'true', 'on' ), true ) || in_array( strtolower( trim( (string) $opt_out ) ), array( '1', 'yes', 'true', 'on' ), true ) ) {
				continue;
			}
			$username = (string) get_post_meta( $customer_id, '_afc_ppp_username', true );
			$name = (string) get_post_meta( $customer_id, '_afc_customer_name', true );
			$phone = AFC_SMS_Payer_Ratings::phone( get_post_meta( $customer_id, '_afc_phone', true ) );
			$next_due = (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true );
			$amount = (string) get_post_meta( $customer_id, '_afc_payment_amount', true );
			if ( ! $username || ! $phone || ! $next_due ) {
				continue;
			}
			$key = 'due-pre|' . strtolower( $username ) . '|' . $next_due;
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$jobs} WHERE dedupe_key = %s LIMIT 1", $key ) ) ) {
				continue;
			}
			$library = AFC_SMS_Templates::get_settings();
			$mode = $library['default_mode'];
			if ( 'manual' === $mode || 'random_all' === $mode ) {
				$mode = 'random_category';
			}
			$template = AFC_SMS_Templates::choose_template( 'due', $mode, $library['default_template_id'] );
			if ( ! $template ) {
				continue;
			}
			$message = AFC_SMS_Templates::apply_tokens( $template['body'], array(
				'name' => $name ?: $username,
				'ppp' => $username,
				'phone' => $phone,
				'due_date' => $next_due,
				'amount' => $amount ?: 'regular monthly bill',
				'payment_number' => $library['payment_number'],
			) );
			$now = current_time( 'mysql' );
			$inserted = $wpdb->insert( $jobs, array(
				'dedupe_key' => $key,
				'customer_id' => $customer_id,
				'ppp_username' => $username,
				'customer_name' => $name ?: $username,
				'phone' => $phone,
				'message' => $message,
				'reminder_type' => 'due-pre',
				'status' => 'queued',
				'last_detail' => 'Queued one day before due date for a new/standard account.',
				'created_by' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			), array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' ) );
			if ( ! $inserted ) {
				continue;
			}
			$wpdb->insert( $events, array(
				'job_id' => $wpdb->insert_id,
				'device_id' => '',
				'status' => 'queued',
				'detail' => 'Queued one day before the due date.',
				'event_time' => $now,
				'created_at' => $now,
			), array( '%d','%s','%s','%s','%s','%s' ) );
			$queued++;
		}
	}
}
