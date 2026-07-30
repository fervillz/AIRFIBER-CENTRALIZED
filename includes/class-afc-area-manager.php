<?php

defined( 'ABSPATH' ) || exit;

class AFC_Area_Manager {

	const MAX_BATCH_SIZE = 25;

	public static function init() {
		add_action( 'wp_ajax_afc_ppp_bulk_assign_area', array( __CLASS__, 'ajax_bulk_assign_area' ) );
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update PPP locations.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	private static function replace_comment_value( $comment, $key, $value ) {
		$keys    = 'installed|grace|paymentMethod|paymentAmount|paymentDate|name|plan|cp|wifi|password|Address';
		$pattern = '/(' . preg_quote( $key, '/' ) . '\s*:\s*)(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is';

		if ( preg_match( $pattern, $comment ) ) {
			return preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $value ) {
					return $matches[1] . $value;
				},
				$comment,
				1
			);
		}

		return rtrim( $comment ) . ( trim( $comment ) ? "\n" : '' ) . $key . ':' . $value;
	}

	private static function get_customer_id( $username ) {
		$customers = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);

		return $customers ? (int) $customers[0] : 0;
	}

	public static function ajax_bulk_assign_area() {
		self::authorize_ajax();

		$assignments = isset( $_POST['assignments'] ) && is_array( $_POST['assignments'] )
			? wp_unslash( $_POST['assignments'] )
			: array();

		if ( empty( $assignments ) ) {
			wp_send_json_error( array( 'message' => __( 'No PPP accounts were supplied for the location update.', 'airfiber-centralized' ) ) );
		}

		if ( count( $assignments ) > self::MAX_BATCH_SIZE ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( 'Update no more than %d PPP accounts per request.', 'airfiber-centralized' ),
						self::MAX_BATCH_SIZE
					),
				)
			);
		}

		$updated  = array();
		$failures = array();

		foreach ( $assignments as $assignment ) {
			$id      = isset( $assignment['id'] ) ? sanitize_text_field( $assignment['id'] ) : '';
			$name    = isset( $assignment['name'] ) ? sanitize_text_field( $assignment['name'] ) : '';
			$comment = isset( $assignment['comment'] ) ? sanitize_textarea_field( $assignment['comment'] ) : '';
			$address = isset( $assignment['address'] ) ? sanitize_text_field( $assignment['address'] ) : '';

			if ( ! $id || ! $name || ! $address || strlen( $address ) > 120 ) {
				$failures[] = array(
					'name'    => $name ? $name : __( 'Unknown account', 'airfiber-centralized' ),
					'message' => __( 'The account ID or new address is invalid.', 'airfiber-centralized' ),
				);
				continue;
			}

			$new_comment = self::replace_comment_value( $comment, 'Address', $address );
			$result      = AFC_MikroTik::run_command(
				array(
					'/ppp/secret/set',
					'=.id=' . $id,
					'=comment=' . $new_comment,
				)
			);

			if ( is_wp_error( $result ) ) {
				$failures[] = array(
					'name'    => $name,
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$customer_id = self::get_customer_id( $name );
			if ( $customer_id ) {
				update_post_meta( $customer_id, '_afc_address', $address );
				update_post_meta( $customer_id, '_afc_mikrotik_comment', $new_comment );
			}

			$updated[] = array(
				'name'    => $name,
				'address' => $address,
			);

			do_action( 'afc_ppp_area_assigned', $name, $address, $customer_id );
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					__( 'Updated %1$d PPP location(s). %2$d failed.', 'airfiber-centralized' ),
					count( $updated ),
					count( $failures )
				),
				'updated'  => $updated,
				'failures' => $failures,
			)
		);
	}
}
