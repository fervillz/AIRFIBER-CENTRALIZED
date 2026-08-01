<?php

defined( 'ABSPATH' ) || exit;

trait AFC_PPP_Manager_Create_Trait {
	public static function ajax_create() {
		self::authorize();
		$mode = class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic';
		$name = self::clean_name( isset( $_POST['customer_name'] ) ? wp_unslash( $_POST['customer_name'] ) : '' );
		$phone = self::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		$address = sanitize_textarea_field( isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : '' );
		$profile = sanitize_text_field( isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : '' );
		$installed_value = 'advanced' === $mode && ! empty( $_POST['installed'] ) ? sanitize_text_field( wp_unslash( $_POST['installed'] ) ) : current_time( 'Y-m-d' );
		$installed = self::date( $installed_value );
		if ( ! $name || ! $phone || ! $address || ! $profile || ! $installed ) {
			wp_send_json_error( array( 'message' => __( 'Name, CP number, address, and plan are required.', 'airfiber-centralized' ) ) );
		}
		$exists = self::profile_exists( $profile, 'advanced' === $mode );
		if ( is_wp_error( $exists ) ) {
			wp_send_json_error( array( 'message' => $exists->get_error_message() ) );
		}
		if ( ! $exists ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid MikroTik PPP profile.', 'airfiber-centralized' ) ) );
		}
		$dates = self::dates_from_installation( $installed, 3 );
		$username = 'advanced' === $mode && ! empty( $_POST['username'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['username'] ) ), 0, 63 )
			: self::generated_username( $name, $installed, $profile );
		$duplicate = self::fetch_secret_by_name( $username );
		if ( is_wp_error( $duplicate ) ) {
			wp_send_json_error( array( 'message' => $duplicate->get_error_message() ) );
		}
		if ( $duplicate ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'PPP username %s already exists.', 'airfiber-centralized' ), $username ) ) );
		}
		$password = apply_filters( 'afc_ppp_new_password', wp_generate_password( 12, false, false ), $username, $profile );
		$password = is_scalar( $password ) ? substr( (string) $password, 0, 64 ) : '';
		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'The configured PPP password is invalid. Open PPP Settings and save a password with at least 8 characters.', 'airfiber-centralized' ) ) );
		}
		$amount   = self::amount_from_profile( $profile, '' );
		$comment  = self::build_comment( '', array_merge( $dates, array(
			'grace'         => 3,
			'paymentMethod' => 'cash',
			'paymentAmount' => $amount,
			'name'          => $name,
			'plan'          => $profile,
			'cp'            => $phone,
			'wifi'          => '',
			'Address'       => $address,
		) ) );
		$command = array( '/ppp/secret/add', '=name=' . $username, '=password=' . $password, '=service=pppoe', '=profile=' . $profile, '=comment=' . $comment, '=disabled=no' );
		$result = AFC_MikroTik::run_command( $command );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$secret = self::fetch_secret_by_name( $username );
		if ( ! $secret || is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'The PPP secret was created but could not be read back from MikroTik.', 'airfiber-centralized' ) ) );
		}
		$scheduler = self::sync_scheduler( $username, $dates['nextDue'], $dates['cutoffDate'] );
		if ( is_wp_error( $scheduler ) ) {
			AFC_MikroTik::run_command( array( '/ppp/secret/remove', '=.id=' . (string) $secret['.id'] ) );
			wp_send_json_error( array( 'message' => $scheduler->get_error_message() ) );
		}
		$data = self::prepared_data( $secret, $comment );
		$customer_id = self::sync_customer( $data );
		if ( is_wp_error( $customer_id ) ) {
			wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		}
		do_action( 'afc_ppp_created', $username, $customer_id, $data );
		wp_send_json_success( array(
			'message'  => sprintf( __( '%s was created successfully.', 'airfiber-centralized' ), $username ),
			'user'     => $data,
			'password' => $password,
			'dates'    => $dates,
		) );
	}

}
