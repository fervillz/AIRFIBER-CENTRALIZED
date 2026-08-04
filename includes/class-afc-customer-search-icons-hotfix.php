<?php

defined( 'ABSPATH' ) || exit;

/**
 * Payment-search account signals.
 *
 * The dashboard cards and the payment search both use the live MikroTik PPP
 * secret as the source of truth. WordPress is used only to enrich the live
 * account state with SMS queue/history information.
 */
class AFC_Customer_Search_Icons_Hotfix {

	const NONCE = 'afc_live_account_signals';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ), 1025 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1025 );
		add_action( 'wp_ajax_afc_live_account_signals', array( __CLASS__, 'ajax_live_signals' ) );
	}

	public static function enqueue_frontend() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	public static function enqueue_admin( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue();
	}

	private static function enqueue() {
		/* The older search-polish script calculated the same badges through a
		 * separate path. Keep its CSS, but let this single live source own the JS. */
		wp_dequeue_script( 'afc-customer-search-polish' );

		wp_enqueue_script(
			'afc-customer-search-icons-hotfix',
			AFC_URL . 'assets/js/customer-search-icons-hotfix.js',
			array( 'jquery' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-customer-search-icons-hotfix',
			'afcCustomerSearchIcons',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to read live account signals.', 'airfiber-centralized' ) ), 403 );
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

	private static function days_between( DateTimeImmutable $from, DateTimeImmutable $to ) {
		return (int) $from->diff( $to )->format( '%r%a' );
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

	private static function normalize_accounts() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $raw );
		}

		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, 150 ) as $value ) {
			$account = substr( sanitize_text_field( trim( (string) $value ) ), 0, 190 );
			if ( '' !== $account ) {
				$accounts[ strtolower( $account ) ] = $account;
			}
		}
		return $accounts;
	}

	private static function rows( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return array();
		}
		if ( isset( $result['name'] ) ) {
			return array( $result );
		}
		return $result;
	}

	private static function expired_profile() {
		$settings = class_exists( 'AFC_Schedulers' ) ? AFC_Schedulers::get_settings() : array();
		return ! empty( $settings['expired_profile'] ) ? trim( (string) $settings['expired_profile'] ) : 'Expired';
	}

	private static function base_signal( $account ) {
		return array(
			'account'          => $account,
			'source'           => 'mikrotik',
			'dueState'         => 'unknown',
			'daysToDue'        => null,
			'daysToCutoff'     => null,
			'nextDue'          => '',
			'cutoffDate'       => '',
			'serviceState'     => 'unknown',
			'smsState'         => 'none',
			'smsLabel'         => '',
			'lastSmsAt'        => '',
			'lastSmsStatus'    => '',
			'incomingUnread'   => false,
			'paidRecent'       => false,
			'paymentDate'      => '',
			'newInstall'       => false,
			'installedDate'    => '',
			'reminderEnabled'  => false,
			'reminderDays'     => 3,
			'reminderDate'     => '',
			'phoneValid'       => false,
			'contactPaused'    => false,
			'doNotText'        => false,
		);
	}

	private static function signal_from_secret( $secret, $expired_profile ) {
		$account = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
		$signal  = self::base_signal( $account );
		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details = class_exists( 'AFC_Comment_Fields' ) ? AFC_Comment_Fields::parse_comment( $comment ) : array();
		$today   = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );

		$next_due    = self::custom_value( $details, 'nextDue' );
		$cutoff      = self::custom_value( $details, 'cutoffDate' );
		$due_date    = self::date( $next_due );
		$cutoff_date = self::date( $cutoff );
		$days_due    = $due_date ? self::days_between( $today, $due_date ) : null;
		$days_cutoff = $cutoff_date ? self::days_between( $today, $cutoff_date ) : null;
		$profile     = isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '';
		$expired     = 0 === strcasecmp( $profile, $expired_profile ) || ( null !== $days_cutoff && $days_cutoff < 0 );

		$due_state = 'unknown';
		if ( $expired ) {
			$due_state = 'expired';
		} elseif ( null !== $days_due && $days_due <= 0 ) {
			$due_state = 'due';
		} elseif ( null !== $days_due && $days_due <= 3 ) {
			$due_state = 'soon';
		} elseif ( null !== $days_due && $days_due <= 7 ) {
			$due_state = 'upcoming';
		} elseif ( null !== $days_due ) {
			$due_state = 'safe';
		} elseif ( null !== $days_cutoff && $days_cutoff <= 3 ) {
			$due_state = 'soon';
		} elseif ( null !== $days_cutoff && $days_cutoff <= 7 ) {
			$due_state = 'upcoming';
		} elseif ( null !== $days_cutoff ) {
			$due_state = 'safe';
		}

		$payment_value = isset( $details['payment_date'] ) ? trim( (string) $details['payment_date'] ) : '';
		$install_value = isset( $details['installed'] ) ? trim( (string) $details['installed'] ) : '';
		$payment_date  = self::date( $payment_value );
		$install_date  = self::date( $install_value );
		$payment_age   = $payment_date ? - self::days_between( $today, $payment_date ) : null;
		$install_age   = $install_date ? - self::days_between( $today, $install_date ) : null;

		$signal['dueState']       = $due_state;
		$signal['daysToDue']      = $days_due;
		$signal['daysToCutoff']   = $days_cutoff;
		$signal['nextDue']        = $next_due;
		$signal['cutoffDate']     = $cutoff;
		$signal['serviceState']   = $expired ? 'expired' : 'active';
		$signal['paidRecent']     = null !== $payment_age && $payment_age >= 0 && $payment_age <= 7;
		$signal['paymentDate']    = $payment_value;
		$signal['newInstall']     = null !== $install_age && $install_age >= 0 && $install_age <= 30;
		$signal['installedDate']  = $install_value;
		$signal['phoneValid']     = ! empty( $details['cp'] );
		return $signal;
	}

	private static function merge_sms( $signal, $sms ) {
		foreach ( array(
			'smsState', 'smsLabel', 'lastSmsAt', 'lastSmsStatus', 'incomingUnread',
			'reminderEnabled', 'reminderDays', 'reminderDate', 'phoneValid',
			'contactPaused', 'doNotText',
		) as $key ) {
			if ( array_key_exists( $key, $sms ) ) {
				$signal[ $key ] = $sms[ $key ];
			}
		}
		return $signal;
	}

	public static function ajax_live_signals() {
		self::authorize();
		$accounts = self::normalize_accounts();
		if ( ! $accounts ) {
			wp_send_json_success( array( 'signals' => array(), 'source' => 'mikrotik' ) );
		}

		$secrets = self::rows(
			AFC_MikroTik::run_command(
				array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' )
			)
		);
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}

		$signals         = array();
		$expired_profile = self::expired_profile();
		foreach ( $accounts as $account ) {
			$signals[ $account ] = self::base_signal( $account );
		}

		foreach ( $secrets as $secret ) {
			$account = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			$key     = strtolower( $account );
			if ( '' === $account || ! isset( $accounts[ $key ] ) ) {
				continue;
			}
			$signals[ $accounts[ $key ] ] = self::signal_from_secret( $secret, $expired_profile );
		}

		if ( class_exists( 'AFC_SMS_PreCutoff' ) ) {
			$sms_signals = AFC_SMS_PreCutoff::signals_for_accounts( array_values( $accounts ) );
			foreach ( $signals as $account => $signal ) {
				if ( isset( $sms_signals[ $account ] ) ) {
					$signals[ $account ] = self::merge_sms( $signal, $sms_signals[ $account ] );
				}
			}
		}

		wp_send_json_success(
			array(
				'signals'   => $signals,
				'source'    => 'mikrotik',
				'generated' => current_time( 'mysql' ),
			)
		);
	}
}
