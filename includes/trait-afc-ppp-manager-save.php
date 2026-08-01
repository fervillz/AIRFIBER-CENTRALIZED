<?php

defined( 'ABSPATH' ) || exit;

trait AFC_PPP_Manager_Save_Trait {
	public static function ajax_save() {
		self::authorize();
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$secret = self::fetch_secret_by_id( $id );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}
		$old = self::prepare_user( $secret );
		$mode = class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic';
		$name = self::clean_name( isset( $_POST['customer_name'] ) ? wp_unslash( $_POST['customer_name'] ) : $old['customer_name'] );
		$phone = self::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : $old['phone'] );
		$address = sanitize_textarea_field( isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : $old['address'] );
		$profile = sanitize_text_field( isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : $old['profile'] );
		$installed_value = $old['installed'];
		if ( 'advanced' === $mode && isset( $_POST['installed'] ) ) {
			$installed_value = sanitize_text_field( wp_unslash( $_POST['installed'] ) );
		} elseif ( 'basic' === $mode && ! empty( $_POST['change_installed'] ) && isset( $_POST['installed'] ) ) {
			$installed_value = sanitize_text_field( wp_unslash( $_POST['installed'] ) );
		}
		$installed = self::date( $installed_value );
		if ( ! $name || ! $phone || ! $address || ! $profile || ! $installed ) {
			wp_send_json_error( array( 'message' => __( 'Name, CP number, address, plan, and installation date must be valid.', 'airfiber-centralized' ) ) );
		}
		$exists = self::profile_exists( $profile, 'advanced' === $mode );
		if ( is_wp_error( $exists ) || ! $exists ) {
			wp_send_json_error( array( 'message' => is_wp_error( $exists ) ? $exists->get_error_message() : __( 'Choose a valid MikroTik PPP profile.', 'airfiber-centralized' ) ) );
		}
		$old_grace = preg_match( '/^\d+$/', (string) $old['grace'] ) ? (int) $old['grace'] : 3;
		$grace     = 'advanced' === $mode && isset( $_POST['grace'] ) ? max( 0, min( 30, absint( $_POST['grace'] ) ) ) : $old_grace;
		$installation_changed = $installed->format( 'Y-m-d' ) !== (string) $old['installed'];
		if ( $installation_changed ) {
			$dates = self::dates_from_installation( $installed, $grace );
		} else {
			$dates = array(
				'installed'       => $installed->format( 'Y-m-d' ),
				'paymentDate'     => $old['payment_date'],
				'billingDay'      => $old['billing_day'] ?: (int) $installed->format( 'j' ),
				'paidThrough'     => $old['paid_through'],
				'nextDue'         => $old['next_due'],
				'cutoffDate'      => $old['cutoff_date'],
				'dueReminderDate' => $old['due_reminder_date'],
			);
		}
		if ( 'advanced' === $mode ) {
			foreach ( array( 'paymentDate' => 'payment_date', 'paidThrough' => 'paid_through', 'nextDue' => 'next_due', 'cutoffDate' => 'cutoff_date', 'dueReminderDate' => 'due_reminder_date' ) as $date_key => $post_key ) {
				if ( isset( $_POST[ $post_key ] ) ) {
					$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
					if ( self::date( $value ) ) {
						$dates[ $date_key ] = $value;
					}
				}
			}
			if ( isset( $_POST['billing_day'] ) ) {
				$dates['billingDay'] = max( 1, min( 31, absint( $_POST['billing_day'] ) ) );
			}
		}
		$billing_ready = self::date( $dates['paymentDate'] ) && self::date( $dates['paidThrough'] ) && self::date( $dates['nextDue'] ) && self::date( $dates['cutoffDate'] ) && self::date( $dates['dueReminderDate'] );
		$new_username = 'advanced' === $mode && ! empty( $_POST['username'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['username'] ) ), 0, 63 )
			: self::generated_username( $name, $installed, $profile );
		if ( $new_username !== $old['username'] ) {
			$duplicate = self::fetch_secret_by_name( $new_username );
			if ( is_wp_error( $duplicate ) ) {
				wp_send_json_error( array( 'message' => $duplicate->get_error_message() ) );
			}
			if ( $duplicate ) {
				wp_send_json_error( array( 'message' => sprintf( __( 'PPP username %s already exists.', 'airfiber-centralized' ), $new_username ) ) );
			}
		}
		if ( ! $billing_ready && ( 'advanced' === $mode || $installation_changed || $new_username !== $old['username'] || $profile !== $old['profile'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The billing dates are incomplete. Repair them in Advanced mode before changing the name, plan, installation date, or PPP username.', 'airfiber-centralized' ) ) );
		}
		$base = 'advanced' === $mode && isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : $old['comment'];
		$amount = 'advanced' === $mode && isset( $_POST['payment_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_amount'] ) ) : self::amount_from_profile( $profile, $old['payment_amount'] );
		$method = 'advanced' === $mode && isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : ( $old['payment_method'] ?: 'cash' );
		$wifi   = 'advanced' === $mode && isset( $_POST['wifi'] ) ? sanitize_text_field( wp_unslash( $_POST['wifi'] ) ) : $old['wifi'];
		$comment_values = array(
			'grace'         => $grace,
			'paymentMethod' => $method,
			'paymentAmount' => $amount,
			'name'          => $name,
			'plan'          => $profile,
			'cp'            => $phone,
			'wifi'          => $wifi,
			'Address'       => $address,
		);
		if ( $billing_ready ) {
			$comment_values = array_merge( $dates, $comment_values );
		}
		$comment        = self::build_comment( $base, $comment_values );
		$router_profile = 0 === strcasecmp( $old['actual_profile'], 'Expired' ) ? $old['actual_profile'] : $profile;
		$command        = array( '/ppp/secret/set', '=.id=' . $id, '=name=' . $new_username, '=profile=' . $router_profile, '=comment=' . $comment );
		if ( 'advanced' === $mode && ! empty( $_POST['new_password'] ) ) {
			$command[] = '=password=' . sanitize_text_field( wp_unslash( $_POST['new_password'] ) );
		}
		$result = AFC_MikroTik::run_command( $command );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( $billing_ready ) {
			$scheduler = self::sync_scheduler( $new_username, $dates['nextDue'], $dates['cutoffDate'], $old['username'] );
			if ( is_wp_error( $scheduler ) ) {
				AFC_MikroTik::run_command( array( '/ppp/secret/set', '=.id=' . $id, '=name=' . $old['username'], '=profile=' . $old['actual_profile'], '=comment=' . $old['comment'] ) );
				wp_send_json_error( array( 'message' => $scheduler->get_error_message() ) );
			}
		}
		$updated_secret = self::fetch_secret_by_name( $new_username );
		if ( ! $updated_secret || is_wp_error( $updated_secret ) ) {
			wp_send_json_error( array( 'message' => __( 'The PPP account was updated but could not be reloaded.', 'airfiber-centralized' ) ) );
		}
		$data = self::prepared_data( $updated_secret, $comment );
		$customer_id = self::sync_customer( $data, $old['username'] );
		if ( is_wp_error( $customer_id ) ) {
			wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		}
		do_action( 'afc_ppp_details_updated', $new_username, $old['username'], $customer_id, $data );
		wp_send_json_success( array(
			'message'          => __( 'PPP account, billing dates, scheduler, and SMS reminder date were updated.', 'airfiber-centralized' ),
			'user'             => $data,
			'username_changed' => $new_username !== $old['username'],
			'old_username'     => $old['username'],
		) );
	}

}
