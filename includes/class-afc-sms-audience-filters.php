<?php

defined( 'ABSPATH' ) || exit;

/**
 * Unified SMS audience/filter data for the Advanced SMS Center.
 *
 * The left SMS list is driven by one live snapshot: MikroTik PPP details,
 * WordPress reminder preferences and the SMS gateway job history.
 */
class AFC_SMS_Audience_Filters {

	const NONCE           = 'afc_sms_audience_filters';
	const TRANSIENT_STATE = 'afc_sms_audience_state_v1';

	public static function init() {
		// The library and payor manager now live inside the SMS panel so Ajaxify
		// keeps their markup with the panel instead of dropping sibling overlays.
		remove_action( 'afc_frontend_app_content', array( 'AFC_SMS_Templates', 'render_library' ), 20 );
		remove_action( 'afc_frontend_app_content', array( 'AFC_SMS_Payer_Ratings', 'render' ), 21 );

		add_action( 'wp_ajax_afc_sms_audience_state', array( __CLASS__, 'ajax_state' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1012 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-sms-audience-filters',
			AFC_URL . 'assets/css/sms-audience-filters.css',
			array( 'afc-sms-center' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-sms-audience-filters',
			AFC_URL . 'assets/js/sms-audience-filters.js',
			array( 'afc-sms-center' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-sms-audience-filters',
			'afcSmsAudienceFilters',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to view SMS audiences.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function truth( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
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

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, (string) $canonical ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function imported_customers() {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$map = array();
		foreach ( $ids as $customer_id ) {
			$account = trim( (string) get_post_meta( $customer_id, '_afc_ppp_username', true ) );
			if ( '' === $account ) continue;
			$name    = trim( (string) get_post_meta( $customer_id, '_afc_customer_name', true ) );
			$profile = class_exists( 'AFC_SMS_Payer_Ratings' ) ? AFC_SMS_Payer_Ratings::profile( $customer_id ) : array( 'rating' => 3 );
			$map[ strtolower( $account ) ] = array(
				'customer_id'      => (int) $customer_id,
				'account'          => $account,
				'name'             => $name ? $name : get_the_title( $customer_id ),
				'phone_raw'        => (string) get_post_meta( $customer_id, '_afc_phone', true ),
				'profile'          => (string) get_post_meta( $customer_id, '_afc_plan', true ),
				'next_due'         => (string) get_post_meta( $customer_id, '_afc_comment_field_nextdue', true ),
				'cutoff'           => (string) get_post_meta( $customer_id, '_afc_comment_field_cutoffdate', true ),
				'reminder_enabled' => '1' === (string) get_post_meta( $customer_id, AFC_SMS_PreCutoff::META_ENABLED, true ),
				'reminder_days'    => max( 1, min( 14, (int) get_post_meta( $customer_id, AFC_SMS_PreCutoff::META_DAYS, true ) ?: 3 ) ),
				'do_not_text'      => self::truth( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) || self::truth( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) ),
				'contact_paused'   => '1' === (string) get_post_meta( $customer_id, '_afc_sms_contact_paused', true ),
				'payor_rating'     => isset( $profile['rating'] ) ? (int) $profile['rating'] : 3,
			);
		}
		return $map;
	}

	private static function live_users( $imported ) {
		$users  = array();
		$source = 'mikrotik';
		$notice = '';
		$secrets = class_exists( 'AFC_MikroTik' )
			? AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=.id,name,profile,comment,disabled' ) )
			: new WP_Error( 'afc_sms_audience_no_router', 'MikroTik integration is unavailable.' );

		if ( is_wp_error( $secrets ) ) {
			$source = 'wordpress';
			$notice = $secrets->get_error_message();
		} else {
			if ( isset( $secrets['name'] ) ) $secrets = array( $secrets );
			foreach ( (array) $secrets as $secret ) {
				$account = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
				if ( '' === $account ) continue;
				$key      = strtolower( $account );
				$wp       = isset( $imported[ $key ] ) ? $imported[ $key ] : array();
				$comment  = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
				$details  = class_exists( 'AFC_Comment_Fields' ) ? AFC_Comment_Fields::parse_comment( $comment ) : array();
				$name     = ! empty( $details['name'] ) ? $details['name'] : ( isset( $wp['name'] ) ? $wp['name'] : $account );
				$phoneRaw = ! empty( $details['cp'] ) ? $details['cp'] : ( isset( $wp['phone_raw'] ) ? $wp['phone_raw'] : '' );
				$nextDue  = self::custom_value( $details, 'nextDue' );
				$cutoff   = self::custom_value( $details, 'cutoffDate' );
				$users[ $key ] = array_merge(
					$wp,
					array(
						'customer_id'      => isset( $wp['customer_id'] ) ? (int) $wp['customer_id'] : 0,
						'account'          => $account,
						'name'             => $name,
						'phone_raw'        => $phoneRaw,
						'profile'          => isset( $secret['profile'] ) ? (string) $secret['profile'] : ( isset( $wp['profile'] ) ? $wp['profile'] : '' ),
						'next_due'         => $nextDue ? $nextDue : ( isset( $wp['next_due'] ) ? $wp['next_due'] : '' ),
						'cutoff'           => $cutoff ? $cutoff : ( isset( $wp['cutoff'] ) ? $wp['cutoff'] : '' ),
						'disabled'         => isset( $secret['disabled'] ) && 'true' === (string) $secret['disabled'],
						'reminder_enabled' => ! empty( $wp['reminder_enabled'] ),
						'reminder_days'    => isset( $wp['reminder_days'] ) ? (int) $wp['reminder_days'] : 3,
						'do_not_text'      => ! empty( $wp['do_not_text'] ),
						'contact_paused'   => ! empty( $wp['contact_paused'] ),
						'payor_rating'     => isset( $wp['payor_rating'] ) ? (int) $wp['payor_rating'] : 3,
					)
				);
			}
		}

		if ( ! $users ) {
			$users = $imported;
		}
		return array( 'users' => $users, 'source' => $source, 'notice' => $notice );
	}

	private static function job_maps() {
		global $wpdb;
		$table = $wpdb->prefix . 'afc_sms_jobs';
		$rows  = (array) $wpdb->get_results(
			"SELECT id,dedupe_key,ppp_username,phone,reminder_type,status,created_at,updated_at FROM {$table} ORDER BY id DESC LIMIT 1500",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_account = array();
		$by_phone   = array();
		foreach ( $rows as $row ) {
			$key = strtolower( trim( (string) $row['ppp_username'] ) );
			if ( $key ) {
				if ( ! isset( $by_account[ $key ] ) ) {
					$by_account[ $key ] = array( 'latest' => $row, 'queued' => 0, 'delivered' => 0, 'sent' => 0, 'jobs' => array() );
				}
				$by_account[ $key ]['jobs'][] = $row;
				if ( 'queued' === $row['status'] ) $by_account[ $key ]['queued']++;
				if ( 'delivered' === $row['status'] ) $by_account[ $key ]['delivered']++;
				if ( in_array( $row['status'], array( 'sent', 'delivered' ), true ) ) $by_account[ $key ]['sent']++;
			}
			$phone = self::phone( $row['phone'] );
			if ( $phone ) $by_phone[ substr( preg_replace( '/\D+/', '', $phone ), -10 ) ] = true;
		}

		$incoming = $wpdb->prefix . 'afc_sms_incoming';
		$reply_phones = (array) $wpdb->get_col( "SELECT phone FROM {$incoming} ORDER BY id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $reply_phones as $phone ) {
			$normalized = self::phone( $phone );
			if ( $normalized ) $by_phone[ substr( preg_replace( '/\D+/', '', $normalized ), -10 ) ] = true;
		}
		return array( 'account' => $by_account, 'phone' => $by_phone );
	}

	private static function gateway() {
		global $wpdb;
		$id = absint( get_option( AFC_SMS_Center::OPTION_DEVICE_ID ) );
		if ( ! $id ) return array( 'state' => 'not-configured', 'detail' => 'No Android gateway is connected.', 'last_seen' => '' );
		$table = $wpdb->prefix . 'afc_sms_devices';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT device_id,state,detail,last_seen,updated_at FROM {$table} WHERE id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : array( 'state' => 'not-configured', 'detail' => 'Gateway record is missing.', 'last_seen' => '' );
	}

	private static function prepared_for_current_cutoff( $user, $jobs ) {
		if ( empty( $user['reminder_enabled'] ) || ! empty( $user['do_not_text'] ) || ! empty( $user['contact_paused'] ) || empty( $user['phone'] ) ) {
			return array( false, '', '' );
		}
		$cutoff = self::date( $user['cutoff'] );
		if ( ! $cutoff ) return array( false, '', '' );
		$today  = self::date( current_time( 'Y-m-d' ) );
		$target = $cutoff->modify( '-' . max( 1, min( 14, (int) $user['reminder_days'] ) ) . ' days' );
		if ( $today >= $cutoff ) return array( false, '', '' );

		foreach ( (array) $jobs as $job ) {
			if ( 0 === strpos( (string) $job['reminder_type'], 'precutoff-' ) && false !== strpos( (string) $job['dedupe_key'], $cutoff->format( 'Y-m-d' ) ) ) {
				return array( false, '', '' );
			}
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$label = $target <= $today ? 'Ready today' : 'Prepared for ' . wp_date( 'M j', $target->getTimestamp(), $timezone );
		return array( true, $target->format( 'Y-m-d' ), $label );
	}

	private static function build_state() {
		$cached = get_transient( self::TRANSIENT_STATE );
		if ( is_array( $cached ) ) return $cached;

		$imported = self::imported_customers();
		$live     = self::live_users( $imported );
		$jobs     = self::job_maps();
		$today    = self::date( current_time( 'Y-m-d' ) );
		$users    = array();
		$counts   = array( 'all' => 0, 'queued' => 0, 'delivered' => 0, 'sent' => 0, 'due-soon' => 0, 'prepared' => 0 );

		foreach ( $live['users'] as $key => $user ) {
			$account = isset( $user['account'] ) ? (string) $user['account'] : (string) $key;
			$phone   = self::phone( isset( $user['phone_raw'] ) ? $user['phone_raw'] : '' );
			$user['phone'] = $phone;
			$jobInfo = isset( $jobs['account'][ strtolower( $account ) ] ) ? $jobs['account'][ strtolower( $account ) ] : array( 'latest' => array(), 'queued' => 0, 'delivered' => 0, 'sent' => 0, 'jobs' => array() );
			$due     = self::date( isset( $user['next_due'] ) ? $user['next_due'] : '' );
			$days    = $due && $today ? (int) $today->diff( $due )->format( '%r%a' ) : null;
			$dueSoon = null !== $days && $days >= 0 && $days <= 7;
			list( $prepared, $preparedDate, $preparedLabel ) = self::prepared_for_current_cutoff( $user, $jobInfo['jobs'] );
			$digits = $phone ? substr( preg_replace( '/\D+/', '', $phone ), -10 ) : '';
			$hasConversation = ! empty( $jobInfo['jobs'] ) || ( $digits && isset( $jobs['phone'][ $digits ] ) );
			$latest = isset( $jobInfo['latest'] ) ? $jobInfo['latest'] : array();
			$item = array(
				'account'          => $account,
				'name'             => ! empty( $user['name'] ) ? (string) $user['name'] : $account,
				'phone'            => $phone,
				'profile'          => isset( $user['profile'] ) ? (string) $user['profile'] : '',
				'disabled'         => ! empty( $user['disabled'] ),
				'doNotText'        => ! empty( $user['do_not_text'] ),
				'contactPaused'    => ! empty( $user['contact_paused'] ),
				'payorRating'      => isset( $user['payor_rating'] ) ? (int) $user['payor_rating'] : 3,
				'nextDue'          => isset( $user['next_due'] ) ? (string) $user['next_due'] : '',
				'cutoffDate'       => isset( $user['cutoff'] ) ? (string) $user['cutoff'] : '',
				'daysToDue'        => $days,
				'dueSoon'          => $dueSoon,
				'prepared'         => $prepared,
				'preparedDate'     => $preparedDate,
				'preparedLabel'    => $preparedLabel,
				'reminderEnabled'  => ! empty( $user['reminder_enabled'] ),
				'reminderDays'     => isset( $user['reminder_days'] ) ? (int) $user['reminder_days'] : 3,
				'queuedCount'      => (int) $jobInfo['queued'],
				'deliveredCount'   => (int) $jobInfo['delivered'],
				'sentCount'        => (int) $jobInfo['sent'],
				'latestStatus'     => isset( $latest['status'] ) ? (string) $latest['status'] : '',
				'latestType'       => isset( $latest['reminder_type'] ) ? (string) $latest['reminder_type'] : '',
				'latestAt'         => isset( $latest['updated_at'] ) ? (string) $latest['updated_at'] : '',
				'hasConversation'  => $hasConversation,
				'conversationKey'  => $digits ? 'phone:' . $digits : 'ppp:' . strtolower( $account ),
			);
			$users[] = $item;
			$counts['all']++;
			if ( $item['queuedCount'] ) $counts['queued']++;
			if ( $item['deliveredCount'] ) $counts['delivered']++;
			if ( $item['sentCount'] ) $counts['sent']++;
			if ( $item['dueSoon'] ) $counts['due-soon']++;
			if ( $item['prepared'] ) $counts['prepared']++;
		}

		usort( $users, function ( $a, $b ) { return strcasecmp( $a['name'] . ' ' . $a['account'], $b['name'] . ' ' . $b['account'] ); } );
		$state = array(
			'users'   => array_values( $users ),
			'counts'  => $counts,
			'gateway' => self::gateway(),
			'source'  => $live['source'],
			'notice'  => $live['notice'],
			'updated' => current_time( 'mysql' ),
		);
		set_transient( self::TRANSIENT_STATE, $state, 30 );
		return $state;
	}

	public static function ajax_state() {
		self::authorize();
		$refresh = isset( $_POST['refresh'] ) && '1' === (string) $_POST['refresh'];
		if ( $refresh ) delete_transient( self::TRANSIENT_STATE );
		wp_send_json_success( self::build_state() );
	}
}
