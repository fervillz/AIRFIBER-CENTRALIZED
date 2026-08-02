<?php

defined( 'ABSPATH' ) || exit;

/**
 * Adds a compact operational inbox to the Scheduler Center overview.
 */
class AFC_Scheduler_Insights {

	const NONCE = 'afc_schedulers';
	const LIMIT = 8;

	public static function init() {
		add_action( 'wp_ajax_afc_scheduler_insights', array( __CLASS__, 'ajax_insights' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 99 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-scheduler-insights',
			AFC_URL . 'assets/css/scheduler-insights.css',
			array( 'afc-schedulers' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-scheduler-insights',
			AFC_URL . 'assets/js/scheduler-insights.js',
			array( 'afc-schedulers' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-scheduler-insights',
			'afcSchedulerInsights',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'labels'  => array(
					'title'         => __( 'Attention & recent activity', 'airfiber-centralized' ),
					'description'   => __( 'A compact inbox for upcoming dues, scheduled cutoffs, expired accounts, and the latest recorded payments.', 'airfiber-centralized' ),
					'due'           => __( 'Due soon', 'airfiber-centralized' ),
					'scheduled'     => __( 'Scheduled', 'airfiber-centralized' ),
					'expired'       => __( 'Expired', 'airfiber-centralized' ),
					'payments'      => __( 'Payments', 'airfiber-centralized' ),
					'loading'       => __( 'Reading live scheduler activity…', 'airfiber-centralized' ),
					'refresh'       => __( 'Refresh activity', 'airfiber-centralized' ),
					'failed'        => __( 'Scheduler activity could not be loaded.', 'airfiber-centralized' ),
					'emptyDue'      => __( 'No accounts are due within the next 7 days.', 'airfiber-centralized' ),
					'emptySchedule' => __( 'No active upcoming cutoff schedulers were found.', 'airfiber-centralized' ),
					'emptyExpired'  => __( 'No PPP accounts currently use the expired profile.', 'airfiber-centralized' ),
					'emptyPayments' => __( 'No recorded payment dates were found in PPP comments.', 'airfiber-centralized' ),
				),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view scheduler activity.', 'airfiber-centralized' ) ), 403 );
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

	private static function normalize_router_date( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( self::parse_date( $value ) ) {
			return $value;
		}

		if ( preg_match( '/^([a-z]{3})\/(\d{1,2})\/(\d{4})$/', $value, $match ) ) {
			$months = array(
				'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
				'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
				'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
			);
			if ( isset( $months[ $match[1] ] ) ) {
				$date = sprintf( '%04d-%02d-%02d', (int) $match[3], $months[ $match[1] ], (int) $match[2] );
				return self::parse_date( $date ) ? $date : '';
			}
		}
		return '';
	}

	private static function router_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'yes', '1' ), true );
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

	private static function normalize_rows( $result, $single_key ) {
		if ( isset( $result[ $single_key ] ) ) {
			return array( $result );
		}
		return is_array( $result ) ? $result : array();
	}

	private static function fetch_secrets() {
		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled',
			)
		);
		return is_wp_error( $result ) ? $result : self::normalize_rows( $result, 'name' );
	}

	private static function fetch_schedulers() {
		$result = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/print',
				'=.proplist=.id,name,start-date,start-time,disabled,run-count,next-run',
			)
		);
		return is_wp_error( $result ) ? $result : self::normalize_rows( $result, 'name' );
	}

	private static function scheduler_map( $schedulers ) {
		$map = array();
		foreach ( $schedulers as $scheduler ) {
			$name = isset( $scheduler['name'] ) ? trim( (string) $scheduler['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$key = strtolower( $name );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $scheduler;
			}
		}
		return $map;
	}

	private static function day_difference( $from, $to ) {
		return (int) $from->diff( $to )->format( '%r%a' );
	}

	private static function base_item( $secret, $scheduler ) {
		$username = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
		$details  = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );

		return array(
			'pppId'            => isset( $secret['.id'] ) ? (string) $secret['.id'] : '',
			'name'             => $username,
			'customer'         => ! empty( $details['name'] ) ? (string) $details['name'] : $username,
			'profile'          => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'nextDue'          => self::custom_value( $details, 'nextDue' ),
			'cutoffDate'       => self::custom_value( $details, 'cutoffDate' ),
			'promisedPayDate'  => self::custom_value( $details, 'promisedPayDate' ),
			'paymentDate'      => isset( $details['payment_date'] ) ? trim( (string) $details['payment_date'] ) : '',
			'paymentAmount'    => isset( $details['payment_amount'] ) ? trim( (string) $details['payment_amount'] ) : '',
			'paymentMethod'    => isset( $details['payment_method'] ) ? trim( (string) $details['payment_method'] ) : '',
			'schedulerExists'  => ! empty( $scheduler ),
			'schedulerDate'    => $scheduler ? self::normalize_router_date( isset( $scheduler['start-date'] ) ? $scheduler['start-date'] : '' ) : '',
			'schedulerTime'    => $scheduler && isset( $scheduler['start-time'] ) ? (string) $scheduler['start-time'] : '',
			'schedulerDisabled'=> $scheduler ? self::router_bool( isset( $scheduler['disabled'] ) ? $scheduler['disabled'] : '' ) : false,
			'runCount'         => $scheduler && isset( $scheduler['run-count'] ) ? absint( $scheduler['run-count'] ) : 0,
			'nextRun'          => $scheduler && isset( $scheduler['next-run'] ) ? (string) $scheduler['next-run'] : '',
		);
	}

	private static function sort_ascending( &$items, $key ) {
		usort(
			$items,
			function ( $first, $second ) use ( $key ) {
				return strcmp( isset( $first[ $key ] ) ? $first[ $key ] : '', isset( $second[ $key ] ) ? $second[ $key ] : '' );
			}
		);
	}

	private static function sort_descending( &$items, $key ) {
		usort(
			$items,
			function ( $first, $second ) use ( $key ) {
				return strcmp( isset( $second[ $key ] ) ? $second[ $key ] : '', isset( $first[ $key ] ) ? $first[ $key ] : '' );
			}
		);
	}

	public static function ajax_insights() {
		self::authorize();

		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		$schedulers = self::fetch_schedulers();
		if ( is_wp_error( $schedulers ) ) {
			wp_send_json_error( array( 'message' => $schedulers->get_error_message() ) );
		}

		$settings        = AFC_Schedulers::get_settings();
		$expired_profile = isset( $settings['expired_profile'] ) ? trim( (string) $settings['expired_profile'] ) : 'Expired';
		$scheduler_map   = self::scheduler_map( $schedulers );
		$today           = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$groups          = array(
			'due'       => array(),
			'scheduled' => array(),
			'expired'   => array(),
			'payments'  => array(),
		);

		foreach ( $secrets as $secret ) {
			$username  = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			$scheduler = $username && isset( $scheduler_map[ strtolower( $username ) ] ) ? $scheduler_map[ strtolower( $username ) ] : null;
			$item      = self::base_item( $secret, $scheduler );
			if ( '' === $item['name'] ) {
				continue;
			}

			$due_date = self::parse_date( $item['nextDue'] );
			if ( $due_date ) {
				$days = self::day_difference( $today, $due_date );
				if ( $days >= 0 && $days <= 7 && 0 !== strcasecmp( $item['profile'], $expired_profile ) ) {
					$due_item              = $item;
					$due_item['daysUntil'] = $days;
					$groups['due'][]       = $due_item;
				}
			}

			$scheduler_date = self::parse_date( $item['schedulerDate'] );
			if ( $item['schedulerExists'] && ! $item['schedulerDisabled'] && $scheduler_date && $scheduler_date >= $today ) {
				$scheduled_item              = $item;
				$scheduled_item['daysUntil'] = self::day_difference( $today, $scheduler_date );
				$groups['scheduled'][]       = $scheduled_item;
			}

			if ( 0 === strcasecmp( $item['profile'], $expired_profile ) ) {
				$cutoff_date                     = self::parse_date( $item['cutoffDate'] );
				$expired_item                    = $item;
				$expired_item['daysSinceCutoff'] = $cutoff_date ? abs( min( 0, self::day_difference( $today, $cutoff_date ) ) ) : null;
				$groups['expired'][]             = $expired_item;
			}

			$payment_date = self::parse_date( $item['paymentDate'] );
			if ( $payment_date ) {
				$payment_item            = $item;
				$payment_item['daysAgo'] = max( 0, - self::day_difference( $today, $payment_date ) );
				$groups['payments'][]    = $payment_item;
			}
		}

		self::sort_ascending( $groups['due'], 'nextDue' );
		self::sort_ascending( $groups['scheduled'], 'schedulerDate' );
		self::sort_descending( $groups['expired'], 'cutoffDate' );
		self::sort_descending( $groups['payments'], 'paymentDate' );

		$counts = array();
		foreach ( $groups as $key => $items ) {
			$counts[ $key ] = count( $items );
			$groups[ $key ] = array_slice( $items, 0, self::LIMIT );
		}

		wp_send_json_success(
			array(
				'groups'         => $groups,
				'counts'         => $counts,
				'expiredProfile' => $expired_profile,
				'generatedAt'    => current_time( 'mysql' ),
			)
		);
	}
}
