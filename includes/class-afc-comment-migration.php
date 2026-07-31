<?php

defined( 'ABSPATH' ) || exit;

/**
 * Safely previews and applies calculated billing fields to MikroTik PPP comments.
 * Existing non-empty values are never overwritten by this migration.
 */
class AFC_Comment_Migration {

	const NONCE         = 'afc_comment_migration';
	const MAX_BATCH     = 25;
	const EARLY_DAYS    = 5;
	const BACKUP_OPTION = 'afc_comment_migration_backups';
	const BACKUP_LIMIT  = 500;

	public static function init() {
		add_action( 'wp_ajax_afc_preview_comment_migration', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_apply_comment_migration', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 45 );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update PPP comments.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-comment-migration',
			AFC_URL . 'assets/css/comment-migration.css',
			array( 'afc-comment-fields' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-comment-migration',
			AFC_URL . 'assets/js/comment-migration.js',
			array( 'jquery', 'afc-comment-fields' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-comment-migration',
			'afcCommentMigration',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'batchSize' => 20,
				'labels'    => array(
					'previewing'  => __( 'Reading PPP comments…', 'airfiber-centralized' ),
					'applying'    => __( 'Applying billing fields…', 'airfiber-centralized' ),
					'failed'      => __( 'The migration request failed.', 'airfiber-centralized' ),
					'noSelection' => __( 'Select at least one safe PPP account.', 'airfiber-centralized' ),
				),
			)
		);
	}

	private static function required_field_map() {
		$required = array(
			'billingday' => 'billingDay',
			'paidthrough' => 'paidThrough',
			'nextdue' => 'nextDue',
			'cutoffdate' => 'cutoffDate',
		);
		$configured = array();

		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			$lower = strtolower( $field['key'] );
			if ( isset( $required[ $lower ] ) ) {
				$configured[ $lower ] = $field['key'];
			}
		}

		return $configured;
	}

	private static function missing_schema_fields() {
		$configured = self::required_field_map();
		$missing    = array();
		foreach ( array( 'billingday', 'paidthrough', 'nextdue', 'cutoffdate' ) as $key ) {
			if ( ! isset( $configured[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
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

	private static function date_for_month( $year, $month, $billing_day ) {
		$first = new DateTimeImmutable(
			sprintf( '%04d-%02d-01', (int) $year, (int) $month ),
			self::timezone()
		);
		$day = min( max( 1, (int) $billing_day ), (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $day );
	}

	private static function due_in_relative_month( DateTimeImmutable $base, $offset, $billing_day ) {
		$month = $base->modify( 'first day of this month' )->modify( (int) $offset . ' month' );
		return self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
	}

	private static function next_due( DateTimeImmutable $paid_through, $billing_day ) {
		$next_month = $paid_through->modify( 'first day of next month' );
		return self::date_for_month( (int) $next_month->format( 'Y' ), (int) $next_month->format( 'm' ), $billing_day );
	}

	private static function infer_paid_through( DateTimeImmutable $payment_date, $billing_day, $grace ) {
		$candidates = array();
		foreach ( array( -1, 0, 1 ) as $offset ) {
			$due    = self::due_in_relative_month( $payment_date, $offset, $billing_day );
			$early  = $due->modify( '-' . self::EARLY_DAYS . ' days' );
			$latest = $due->modify( '+' . $grace . ' days' );
			if ( $payment_date >= $early && $payment_date <= $latest ) {
				$candidates[] = array(
					'date'     => $due,
					'distance' => abs( $due->getTimestamp() - $payment_date->getTimestamp() ),
				);
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			function ( $first, $second ) {
				return $first['distance'] <=> $second['distance'];
			}
		);

		return $candidates[0]['date'];
	}

	private static function configured_value( $custom_fields, $lookup, $canonical ) {
		if ( ! isset( $lookup[ strtolower( $canonical ) ] ) ) {
			return '';
		}
		$key = $lookup[ strtolower( $canonical ) ];
		return isset( $custom_fields[ $key ] ) ? trim( (string) $custom_fields[ $key ] ) : '';
	}

	private static function analyze_secret( $secret ) {
		$id      = isset( $secret['.id'] ) ? (string) $secret['.id'] : '';
		$name    = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details = AFC_Comment_Fields::parse_comment( $comment );
		$lookup  = self::required_field_map();
		$custom  = isset( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		$errors  = array();
		$updates = array();

		$existing = array(
			'billingDay'  => self::configured_value( $custom, $lookup, 'billingDay' ),
			'paidThrough' => self::configured_value( $custom, $lookup, 'paidThrough' ),
			'nextDue'     => self::configured_value( $custom, $lookup, 'nextDue' ),
			'cutoffDate'  => self::configured_value( $custom, $lookup, 'cutoffDate' ),
		);

		$installed    = self::parse_date( isset( $details['installed'] ) ? $details['installed'] : '' );
		$payment_date = self::parse_date( isset( $details['payment_date'] ) ? $details['payment_date'] : '' );
		$grace_raw    = isset( $details['grace'] ) ? trim( (string) $details['grace'] ) : '';
		$grace        = '' === $grace_raw ? 6 : (int) $grace_raw;
		if ( '' !== $grace_raw && ! preg_match( '/^\d+$/', $grace_raw ) ) {
			$errors[] = __( 'Grace days must be a whole number.', 'airfiber-centralized' );
		} elseif ( $grace < 0 || $grace > 60 ) {
			$errors[] = __( 'Grace days must be between 0 and 60.', 'airfiber-centralized' );
		}

		$billing_day = 0;
		if ( '' !== $existing['billingDay'] ) {
			if ( ctype_digit( $existing['billingDay'] ) && (int) $existing['billingDay'] >= 1 && (int) $existing['billingDay'] <= 31 ) {
				$billing_day = (int) $existing['billingDay'];
			} else {
				$errors[] = __( 'Existing billingDay is invalid.', 'airfiber-centralized' );
			}
		} elseif ( $installed ) {
			$billing_day = (int) $installed->format( 'j' );
			$updates[ $lookup['billingday'] ] = (string) $billing_day;
		} else {
			$errors[] = __( 'Installed date is missing or invalid.', 'airfiber-centralized' );
		}

		$paid_through = null;
		if ( '' !== $existing['paidThrough'] ) {
			$paid_through = self::parse_date( $existing['paidThrough'] );
			if ( ! $paid_through ) {
				$errors[] = __( 'Existing paidThrough date is invalid.', 'airfiber-centralized' );
			}
		} elseif ( $billing_day && $payment_date ) {
			$paid_through = self::infer_paid_through( $payment_date, $billing_day, max( 0, $grace ) );
			if ( $paid_through ) {
				$updates[ $lookup['paidthrough'] ] = $paid_through->format( 'Y-m-d' );
			} else {
				$errors[] = sprintf(
					/* translators: 1: early days, 2: grace days. */
					__( 'Payment date is outside the allowed window of %1$d days early through %2$d grace days.', 'airfiber-centralized' ),
					self::EARLY_DAYS,
					max( 0, $grace )
				);
			}
		} else {
			$errors[] = __( 'Payment date is missing or invalid.', 'airfiber-centralized' );
		}

		$next_due = null;
		if ( '' !== $existing['nextDue'] ) {
			$next_due = self::parse_date( $existing['nextDue'] );
			if ( ! $next_due ) {
				$errors[] = __( 'Existing nextDue date is invalid.', 'airfiber-centralized' );
			}
		} elseif ( $paid_through && $billing_day ) {
			$next_due = self::next_due( $paid_through, $billing_day );
			$updates[ $lookup['nextdue'] ] = $next_due->format( 'Y-m-d' );
		}

		if ( '' !== $existing['cutoffDate'] ) {
			if ( ! self::parse_date( $existing['cutoffDate'] ) ) {
				$errors[] = __( 'Existing cutoffDate is invalid.', 'airfiber-centralized' );
			}
		} elseif ( $next_due && $grace >= 0 && $grace <= 60 ) {
			$updates[ $lookup['cutoffdate'] ] = $next_due->modify( '+' . ( $grace + 1 ) . ' days' )->format( 'Y-m-d' );
		}

		$status  = 'safe';
		$message = __( 'Ready to add missing billing fields.', 'airfiber-centralized' );
		if ( $errors ) {
			$status  = 'review';
			$message = implode( ' ', array_unique( $errors ) );
		} elseif ( empty( $updates ) ) {
			$status  = 'complete';
			$message = __( 'All billing fields already exist.', 'airfiber-centralized' );
		}

		$calculated = $existing;
		foreach ( $updates as $actual_key => $value ) {
			$canonical = array_search( $actual_key, $lookup, true );
			if ( false !== $canonical ) {
				$canonical_map = array(
					'billingday'  => 'billingDay',
					'paidthrough' => 'paidThrough',
					'nextdue'     => 'nextDue',
					'cutoffdate'  => 'cutoffDate',
				);
				$calculated[ $canonical_map[ $canonical ] ] = $value;
			}
		}

		return array(
			'id'          => $id,
			'name'        => $name,
			'customer'    => ! empty( $details['name'] ) ? $details['name'] : $name,
			'profile'     => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'status'      => $status,
			'message'     => $message,
			'installed'   => isset( $details['installed'] ) ? $details['installed'] : '',
			'paymentDate' => isset( $details['payment_date'] ) ? $details['payment_date'] : '',
			'grace'       => $grace_raw,
			'existing'    => $existing,
			'calculated'  => $calculated,
			'updates'     => $updates,
			'comment'     => $comment,
		);
	}

	private static function fetch_secrets() {
		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled',
			)
		);
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}
		return is_array( $secrets ) ? $secrets : array();
	}

	private static function schema_error() {
		$missing = self::missing_schema_fields();
		if ( $missing ) {
			$labels = array(
				'billingday'  => 'billingDay',
				'paidthrough' => 'paidThrough',
				'nextdue'     => 'nextDue',
				'cutoffdate'  => 'cutoffDate',
			);
			$missing = array_map( function ( $key ) use ( $labels ) { return $labels[ $key ]; }, $missing );
			return new WP_Error(
				'afc_missing_billing_schema',
				sprintf(
					/* translators: %s: comma-separated field names. */
					__( 'Add and save these Comment Fields first: %s.', 'airfiber-centralized' ),
					implode( ', ', $missing )
				)
			);
		}

		$expected = array(
			'billingday'  => 'number',
			'paidthrough' => 'date',
			'nextdue'     => 'date',
			'cutoffdate'  => 'date',
		);
		$invalid = array();
		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			$lower = strtolower( $field['key'] );
			if ( isset( $expected[ $lower ] ) && $expected[ $lower ] !== $field['type'] ) {
				$invalid[] = $field['key'] . ' (' . $expected[ $lower ] . ')';
			}
		}
		if ( $invalid ) {
			return new WP_Error(
				'afc_invalid_billing_schema',
				sprintf(
					/* translators: %s: fields and required types. */
					__( 'Correct these Comment Field types before previewing: %s.', 'airfiber-centralized' ),
					implode( ', ', $invalid )
				)
			);
		}

		return null;
	}

	public static function ajax_preview() {
		self::authorize();
		$schema_error = self::schema_error();
		if ( $schema_error ) {
			wp_send_json_error( array( 'message' => $schema_error->get_error_message() ), 400 );
		}

		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}

		$rows   = array();
		$counts = array( 'safe' => 0, 'complete' => 0, 'review' => 0 );
		foreach ( $secrets as $secret ) {
			$row = self::analyze_secret( $secret );
			if ( ! $row['id'] || ! $row['name'] ) {
				continue;
			}
			$counts[ $row['status'] ]++;
			unset( $row['comment'] ); // Never expose Wi-Fi passwords embedded in comments to the browser.
			$rows[] = $row;
		}

		usort(
			$rows,
			function ( $first, $second ) {
				$order = array( 'safe' => 0, 'review' => 1, 'complete' => 2 );
				return $order[ $first['status'] ] <=> $order[ $second['status'] ]
					?: strcasecmp( $first['customer'], $second['customer'] );
			}
		);

		wp_send_json_success(
			array(
				'rows'   => $rows,
				'counts' => $counts,
				'total'  => count( $rows ),
			)
		);
	}

	private static function customer_id( $username ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function save_backups( $new_backups ) {
		if ( empty( $new_backups ) ) {
			return;
		}
		$backups = get_option( self::BACKUP_OPTION, array() );
		$backups = is_array( $backups ) ? $backups : array();
		$backups = array_merge( $backups, $new_backups );
		if ( count( $backups ) > self::BACKUP_LIMIT ) {
			$backups = array_slice( $backups, -self::BACKUP_LIMIT );
		}
		update_option( self::BACKUP_OPTION, $backups, false );
	}

	public static function ajax_apply() {
		self::authorize();
		$schema_error = self::schema_error();
		if ( $schema_error ) {
			wp_send_json_error( array( 'message' => $schema_error->get_error_message() ), 400 );
		}

		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['ids'] ) ) ) ) )
			: array();
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No PPP accounts were selected.', 'airfiber-centralized' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Apply no more than %d accounts per request.', 'airfiber-centralized' ), self::MAX_BATCH ) ), 400 );
		}

		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}

		$by_id = array();
		foreach ( $secrets as $secret ) {
			if ( ! empty( $secret['.id'] ) ) {
				$by_id[ (string) $secret['.id'] ] = $secret;
			}
		}

		$updated = array();
		$skipped = array();
		$failed  = array();
		$backups = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				$failed[] = array( 'id' => $id, 'name' => '', 'message' => __( 'PPP account no longer exists.', 'airfiber-centralized' ) );
				continue;
			}

			$row = self::analyze_secret( $by_id[ $id ] );
			if ( 'review' === $row['status'] ) {
				$skipped[] = array( 'id' => $id, 'name' => $row['name'], 'message' => $row['message'] );
				continue;
			}
			if ( 'complete' === $row['status'] || empty( $row['updates'] ) ) {
				$skipped[] = array( 'id' => $id, 'name' => $row['name'], 'message' => __( 'Already complete.', 'airfiber-centralized' ) );
				continue;
			}

			$new_comment = $row['comment'];
			foreach ( $row['updates'] as $key => $value ) {
				$new_comment = AFC_Comment_Fields::replace_value( $new_comment, $key, $value );
			}

			$result = AFC_MikroTik::run_command(
				array(
					'/ppp/secret/set',
					'=.id=' . $id,
					'=comment=' . $new_comment,
				)
			);
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'name' => $row['name'], 'message' => $result->get_error_message() );
				continue;
			}

			$backups[] = array(
				'time'        => current_time( 'mysql' ),
				'operator'    => get_current_user_id(),
				'ppp_id'      => $id,
				'ppp_name'    => $row['name'],
				'old_comment' => $row['comment'],
				'new_comment' => $new_comment,
			);

			$customer_id = self::customer_id( $row['name'] );
			if ( $customer_id ) {
				update_post_meta( $customer_id, '_afc_mikrotik_comment', $new_comment );
				foreach ( $row['updates'] as $key => $value ) {
					update_post_meta( $customer_id, '_afc_comment_field_' . sanitize_key( $key ), $value );
				}
			}

			$updated[] = array(
				'id'         => $id,
				'name'       => $row['name'],
				'customer'   => $row['customer'],
				'fields'     => $row['updates'],
				'calculated' => $row['calculated'],
			);
		}

		self::save_backups( $backups );

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Updated %1$d account(s), skipped %2$d, failed %3$d.', 'airfiber-centralized' ),
					count( $updated ),
					count( $skipped ),
					count( $failed )
				),
				'updated' => $updated,
				'skipped' => $skipped,
				'failed'  => $failed,
			)
		);
	}
}
