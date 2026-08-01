<?php

defined( 'ABSPATH' ) || exit;

trait AFC_PPP_Manager_Core_Trait {
	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function date_for_month( $year, $month, $day ) {
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', (int) $year, (int) $month ), self::timezone() );
		$day   = min( max( 1, (int) $day ), (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $day );
	}

	private static function dates_from_installation( DateTimeImmutable $installed, $grace = 3 ) {
		$billing_day = (int) $installed->format( 'j' );
		$month       = $installed->modify( 'first day of next month' );
		$next_due    = self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
		$cutoff      = $next_due->modify( '+' . ( max( 0, (int) $grace ) + 1 ) . ' days' );
		return array(
			'installed'       => $installed->format( 'Y-m-d' ),
			'paymentDate'     => $installed->format( 'Y-m-d' ),
			'billingDay'      => $billing_day,
			'paidThrough'     => $installed->format( 'Y-m-d' ),
			'nextDue'         => $next_due->format( 'Y-m-d' ),
			'cutoffDate'      => $cutoff->format( 'Y-m-d' ),
			'dueReminderDate' => $next_due->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	private static function custom_value( $details, $key ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $field_key => $value ) {
			if ( 0 === strcasecmp( $key, $field_key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function prepare_user( $secret ) {
		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details = AFC_Comment_Fields::parse_comment( $comment );
		return array(
			'id'                => isset( $secret['.id'] ) ? (string) $secret['.id'] : '',
			'username'          => isset( $secret['name'] ) ? (string) $secret['name'] : '',
			'profile'           => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'service'           => isset( $secret['service'] ) ? (string) $secret['service'] : 'pppoe',
			'disabled'          => isset( $secret['disabled'] ) && 'true' === (string) $secret['disabled'],
			'customer_name'     => isset( $details['name'] ) ? (string) $details['name'] : '',
			'phone'             => isset( $details['cp'] ) ? (string) $details['cp'] : '',
			'address'           => isset( $details['address'] ) ? (string) $details['address'] : '',
			'wifi'              => isset( $details['wifi'] ) ? (string) $details['wifi'] : '',
			'installed'         => isset( $details['installed'] ) ? (string) $details['installed'] : '',
			'grace'             => isset( $details['grace'] ) ? (string) $details['grace'] : '',
			'payment_method'    => isset( $details['payment_method'] ) ? (string) $details['payment_method'] : '',
			'payment_amount'    => isset( $details['payment_amount'] ) ? (string) $details['payment_amount'] : '',
			'payment_date'      => isset( $details['payment_date'] ) ? (string) $details['payment_date'] : '',
			'billing_day'       => self::custom_value( $details, 'billingDay' ),
			'paid_through'      => self::custom_value( $details, 'paidThrough' ),
			'next_due'          => self::custom_value( $details, 'nextDue' ),
			'cutoff_date'       => self::custom_value( $details, 'cutoffDate' ),
			'due_reminder_date' => self::custom_value( $details, 'dueReminderDate' ),
			'comment'           => $comment,
			'remote_address'    => isset( $secret['remote-address'] ) ? (string) $secret['remote-address'] : '',
			'caller_id'         => isset( $secret['caller-id'] ) ? (string) $secret['caller-id'] : '',
		);
	}

	public static function ajax_bootstrap() {
		self::authorize();
		$profiles = self::fetch_profiles();
		$secrets  = self::fetch_secrets();
		if ( is_wp_error( $profiles ) ) {
			wp_send_json_error( array( 'message' => $profiles->get_error_message() ) );
		}
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		$users = array();
		foreach ( $secrets as $secret ) {
			$users[] = self::prepare_user( $secret );
		}
		usort( $users, function ( $first, $second ) {
			return strcasecmp( $first['customer_name'] ?: $first['username'], $second['customer_name'] ?: $second['username'] );
		} );
		wp_send_json_success( array( 'profiles' => $profiles, 'users' => $users, 'current_date' => current_time( 'Y-m-d' ) ) );
	}

	private static function clean_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
		return substr( $name, 0, 120 );
	}

	private static function username_name_token( $name ) {
		$name  = remove_accents( self::clean_name( $name ) );
		$parts = preg_split( '/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		$token = '';
		foreach ( $parts as $part ) {
			$token .= ucfirst( strtolower( $part ) );
		}
		return $token ?: 'Customer';
	}

	private static function plan_token( $profile ) {
		if ( preg_match( '/\d{3,6}/', (string) $profile, $match ) ) {
			return $match[0];
		}
		$token = preg_replace( '/[^A-Za-z0-9]+/', '', remove_accents( (string) $profile ) );
		return $token ?: 'Plan';
	}

	private static function generated_username( $name, DateTimeImmutable $installed, $profile ) {
		$username = self::username_name_token( $name ) . '_' . $installed->format( 'd' ) . '_' . self::plan_token( $profile );
		return substr( $username, 0, 63 );
	}

	private static function normalize_phone( $phone ) {
		$phone = trim( sanitize_text_field( $phone ) );
		return substr( $phone, 0, 40 );
	}

	private static function profile_exists( $profile, $allow_technical = false ) {
		$profiles = self::fetch_profiles();
		if ( is_wp_error( $profiles ) ) {
			return $profiles;
		}
		foreach ( $profiles as $item ) {
			if ( (string) $item['name'] === (string) $profile && ( $allow_technical || $item['basic'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function build_comment( $base, $values ) {
		$comment = (string) $base;
		$map = array(
			'installed'       => 'installed',
			'grace'           => 'grace',
			'paymentMethod'   => 'paymentMethod',
			'paymentAmount'   => 'paymentAmount',
			'paymentDate'     => 'paymentDate',
			'name'            => 'name',
			'plan'            => 'plan',
			'cp'              => 'cp',
			'wifi'            => 'wifi',
			'Address'         => 'Address',
			'billingDay'      => 'billingDay',
			'paidThrough'     => 'paidThrough',
			'nextDue'         => 'nextDue',
			'cutoffDate'      => 'cutoffDate',
			'dueReminderDate' => 'dueReminderDate',
		);
		foreach ( $map as $source => $comment_key ) {
			if ( array_key_exists( $source, $values ) ) {
				$comment = AFC_Comment_Fields::replace_value( $comment, $comment_key, sanitize_text_field( (string) $values[ $source ] ) );
			}
		}
		return AFC_Comment_Fields::normalize_comment( $comment );
	}

	private static function amount_from_profile( $profile, $fallback = '' ) {
		return preg_match( '/\d{3,6}/', (string) $profile, $match ) ? $match[0] : $fallback;
	}

	private static function get_customer_id( $username ) {
		$ids = get_posts( array(
			'post_type'      => 'afc_customer',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_afc_ppp_username',
			'meta_value'     => $username,
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	private static function sync_customer( $data, $old_username = '' ) {
		$customer_id = $old_username ? self::get_customer_id( $old_username ) : 0;
		if ( ! $customer_id ) {
			$customer_id = self::get_customer_id( $data['username'] );
		}
		if ( ! $customer_id ) {
			$customer_id = wp_insert_post( array(
				'post_type'   => 'afc_customer',
				'post_status' => 'publish',
				'post_title'  => $data['customer_name'],
			), true );
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			update_post_meta( $customer_id, '_afc_account_number', 'AIR-' . str_pad( (string) $customer_id, 6, '0', STR_PAD_LEFT ) );
		} else {
			wp_update_post( array( 'ID' => $customer_id, 'post_title' => $data['customer_name'] ) );
		}
		$meta = array(
			'_afc_ppp_username'                    => $data['username'],
			'_afc_mikrotik_id'                     => $data['id'],
			'_afc_plan'                             => $data['profile'],
			'_afc_mikrotik_comment'                 => $data['comment'],
			'_afc_customer_name'                    => $data['customer_name'],
			'_afc_phone'                            => $data['phone'],
			'_afc_installation_date'                => $data['installed'],
			'_afc_grace_days'                       => $data['grace'],
			'_afc_payment_method'                   => $data['payment_method'],
			'_afc_payment_amount'                   => $data['payment_amount'],
			'_afc_payment_date'                     => $data['payment_date'],
			'_afc_wifi_name'                        => $data['wifi'],
			'_afc_address'                          => $data['address'],
			'_afc_comment_field_billingday'         => $data['billing_day'],
			'_afc_comment_field_paidthrough'         => $data['paid_through'],
			'_afc_comment_field_nextdue'             => $data['next_due'],
			'_afc_comment_field_cutoffdate'           => $data['cutoff_date'],
			'_afc_comment_field_duereminderdate'      => $data['due_reminder_date'],
			'_afc_sms_due_reminder_days_before'       => 1,
			'_afc_customer_status'                   => $data['disabled'] ? 'disabled' : 'active',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $customer_id, $key, $value );
		}
		if ( '' === (string) get_post_meta( $customer_id, '_afc_sms_rating_mode', true ) ) {
			update_post_meta( $customer_id, '_afc_sms_rating_mode', 'auto' );
			update_post_meta( $customer_id, '_afc_sms_payer_rating', 3 );
		}
		return (int) $customer_id;
	}

	private static function prepared_data( $secret, $comment ) {
		$copy = $secret;
		$copy['comment'] = $comment;
		return self::prepare_user( $copy );
	}

}
