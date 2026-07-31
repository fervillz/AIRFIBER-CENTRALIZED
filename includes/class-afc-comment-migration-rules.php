<?php

defined( 'ABSPATH' ) || exit;

/**
 * Production billing rules for the PPP comment migration.
 *
 * This replaces the original conservative migration endpoints. A valid payment
 * date is assigned to the nearest monthly billing date. Grace controls cutoff
 * timing only; it does not decide which billing cycle a payment covers.
 */
class AFC_Comment_Migration_Rules {

	const DEFAULT_GRACE = 6;
	const MAX_GRACE     = 6;
	const MAX_BATCH     = 25;
	const BACKUP_LIMIT  = 500;

	public static function init() {
		remove_action( 'wp_ajax_afc_preview_comment_migration', array( 'AFC_Comment_Migration', 'ajax_preview' ) );
		remove_action( 'wp_ajax_afc_apply_comment_migration', array( 'AFC_Comment_Migration', 'ajax_apply' ) );

		add_action( 'wp_ajax_afc_preview_comment_migration', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_apply_comment_migration', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_fixes' ), 46 );
	}

	public static function enqueue_fixes() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-comment-migration-rules',
			AFC_URL . 'assets/css/comment-migration-rules.css',
			array( 'afc-comment-migration' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-comment-migration-rules',
			AFC_URL . 'assets/js/comment-migration-rules.js',
			array( 'jquery', 'afc-comment-migration' ),
			AFC_VERSION,
			true
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update PPP comments.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( AFC_Comment_Migration::NONCE, 'nonce' );
	}

	private static function required_field_map() {
		$required = array(
			'billingday'  => 'billingDay',
			'paidthrough' => 'paidThrough',
			'nextdue'     => 'nextDue',
			'cutoffdate'  => 'cutoffDate',
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

	private static function schema_error() {
		$configured = self::required_field_map();
		$required   = array(
			'billingday'  => array( 'label' => 'billingDay', 'type' => 'number' ),
			'paidthrough' => array( 'label' => 'paidThrough', 'type' => 'date' ),
			'nextdue'     => array( 'label' => 'nextDue', 'type' => 'date' ),
			'cutoffdate'  => array( 'label' => 'cutoffDate', 'type' => 'date' ),
		);
		$missing    = array();
		$types      = array();

		foreach ( $required as $key => $definition ) {
			if ( ! isset( $configured[ $key ] ) ) {
				$missing[] = $definition['label'];
			}
		}

		if ( $missing ) {
			return new WP_Error(
				'afc_missing_billing_schema',
				sprintf(
					/* translators: %s: comma-separated field names. */
					__( 'Add and save these Comment Fields first: %s.', 'airfiber-centralized' ),
					implode( ', ', $missing )
				)
			);
		}

		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			$lower = strtolower( $field['key'] );
			if ( isset( $required[ $lower ] ) && $required[ $lower ]['type'] !== $field['type'] ) {
				$types[] = $field['key'] . ' (' . $required[ $lower ]['type'] . ')';
			}
		}

		if ( $types ) {
			return new WP_Error(
				'afc_invalid_billing_schema',
				sprintf(
					/* translators: %s: field names and required types. */
					__( 'Correct these Comment Field types before previewing: %s.', 'airfiber-centralized' ),
					implode( ', ', $types )
				)
			);
		}

		return null;
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
		$month = $paid_through->modify( 'first day of next month' );

		return self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
	}

	/**
	 * Assign a payment to the nearest monthly billing date.
	 *
	 * A payment may be early, on time, inside grace, or late after cutoff. It
	 * still pays one billing cycle. If two cycles are exactly equally distant,
	 * choose the later cycle so the migration does not under-credit a customer.
	 */
	private static function infer_paid_through( DateTimeImmutable $payment_date, $billing_day ) {
		$candidates = array();

		foreach ( array( -1, 0, 1 ) as $offset ) {
			$due = self::due_in_relative_month( $payment_date, $offset, $billing_day );
			$candidates[] = array(
				'date'     => $due,
				'distance' => abs( $due->getTimestamp() - $payment_date->getTimestamp() ),
			);
		}

		usort(
			$candidates,
			function ( $first, $second ) {
				if ( $first['distance'] === $second['distance'] ) {
					return $second['date']->getTimestamp() <=> $first['date']->getTimestamp();
				}

				return $first['distance'] <=> $second['distance'];
			}
		);

		return $candidates[0]['date'];
	}

	private static function payment_cycle_message( DateTimeImmutable $payment_date, DateTimeImmutable $paid_through ) {
		$seconds = $payment_date->getTimestamp() - $paid_through->getTimestamp();
		$days    = (int) round( abs( $seconds ) / DAY_IN_SECONDS );

		if ( 0 === $days ) {
			return __( 'Payment is on the billing date.', 'airfiber-centralized' );
		}

		return $seconds < 0
			? sprintf( __( 'Payment is mapped %d day(s) early to the nearest billing date.', 'airfiber-centralized' ), $days )
			: sprintf( __( 'Payment is mapped %d day(s) late to the nearest billing date.', 'airfiber-centralized' ), $days );
	}

	private static function configured_value( $custom_fields, $lookup, $canonical ) {
		$lookup_key = strtolower( $canonical );
		if ( ! isset( $lookup[ $lookup_key ] ) ) {
			return '';
		}

		$key = $lookup[ $lookup_key ];

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
		$codes   = array();
		$updates = array();
		$notes   = array();

		$existing = array(
			'billingDay'  => self::configured_value( $custom, $lookup, 'billingDay' ),
			'paidThrough' => self::configured_value( $custom, $lookup, 'paidThrough' ),
			'nextDue'     => self::configured_value( $custom, $lookup, 'nextDue' ),
			'cutoffDate'  => self::configured_value( $custom, $lookup, 'cutoffDate' ),
		);

		$installed_raw = isset( $details['installed'] ) ? trim( (string) $details['installed'] ) : '';
		$payment_raw   = isset( $details['payment_date'] ) ? trim( (string) $details['payment_date'] ) : '';
		$grace_raw     = isset( $details['grace'] ) ? trim( (string) $details['grace'] ) : '';
		$installed     = self::parse_date( $installed_raw );
		$payment_date  = self::parse_date( $payment_raw );
		$grace         = self::DEFAULT_GRACE;

		if ( '' !== $grace_raw ) {
			if ( ! preg_match( '/^\d+$/', $grace_raw ) ) {
				$errors[] = __( 'Grace is missing or invalid. Use a whole number from 0 to 6.', 'airfiber-centralized' );
				$codes[]  = 'invalid_grace';
			} else {
				$grace = (int) $grace_raw;
				if ( $grace < 0 || $grace > self::MAX_GRACE ) {
					$errors[] = __( 'Grace must be 6 days or less.', 'airfiber-centralized' );
					$codes[]  = 'invalid_grace';
				}
			}
		} else {
			$notes[] = __( 'Grace is blank, so the existing-customer default of 6 days is used.', 'airfiber-centralized' );
		}

		$billing_day = 0;
		if ( '' !== $existing['billingDay'] ) {
			if ( ctype_digit( $existing['billingDay'] ) && (int) $existing['billingDay'] >= 1 && (int) $existing['billingDay'] <= 31 ) {
				$billing_day = (int) $existing['billingDay'];
			} else {
				$errors[] = __( 'Existing billingDay is invalid; it must be from 1 to 31.', 'airfiber-centralized' );
				$codes[]  = 'invalid_billing_day';
			}
		} elseif ( $installed ) {
			$billing_day = (int) $installed->format( 'j' );
			$updates[ $lookup['billingday'] ] = (string) $billing_day;
		} else {
			$errors[] = __( 'Installed date is missing or invalid, so the billing day cannot be calculated.', 'airfiber-centralized' );
			$codes[]  = 'missing_installed';
		}

		$paid_through = null;
		if ( '' !== $existing['paidThrough'] ) {
			$paid_through = self::parse_date( $existing['paidThrough'] );
			if ( ! $paid_through ) {
				$errors[] = __( 'Existing paidThrough is not a valid YYYY-MM-DD date.', 'airfiber-centralized' );
				$codes[]  = 'invalid_paid_through';
			}
		} elseif ( $billing_day && $payment_date ) {
			$paid_through = self::infer_paid_through( $payment_date, $billing_day );
			$updates[ $lookup['paidthrough'] ] = $paid_through->format( 'Y-m-d' );
			$notes[] = self::payment_cycle_message( $payment_date, $paid_through );
		} elseif ( ! $payment_date ) {
			$errors[] = __( 'Payment date is missing or invalid, so Airfiber cannot identify the latest paid billing month.', 'airfiber-centralized' );
			$codes[]  = 'missing_payment_date';
		}

		$next_due = null;
		if ( '' !== $existing['nextDue'] ) {
			$next_due = self::parse_date( $existing['nextDue'] );
			if ( ! $next_due ) {
				$errors[] = __( 'Existing nextDue is not a valid YYYY-MM-DD date.', 'airfiber-centralized' );
				$codes[]  = 'invalid_next_due';
			}
		} elseif ( $paid_through && $billing_day ) {
			$next_due = self::next_due( $paid_through, $billing_day );
			$updates[ $lookup['nextdue'] ] = $next_due->format( 'Y-m-d' );
		}

		if ( '' !== $existing['cutoffDate'] ) {
			if ( ! self::parse_date( $existing['cutoffDate'] ) ) {
				$errors[] = __( 'Existing cutoffDate is not a valid YYYY-MM-DD date.', 'airfiber-centralized' );
				$codes[]  = 'invalid_cutoff_date';
			}
		} elseif ( $next_due && $grace >= 0 && $grace <= self::MAX_GRACE ) {
			$updates[ $lookup['cutoffdate'] ] = $next_due->modify( '+' . ( $grace + 1 ) . ' days' )->format( 'Y-m-d' );
		}

		$status  = 'safe';
		$message = $notes ? implode( ' ', array_unique( $notes ) ) : __( 'Ready to add missing billing fields.', 'airfiber-centralized' );
		if ( $errors ) {
			$status  = 'review';
			$message = implode( ' ', array_unique( $errors ) );
		} elseif ( empty( $updates ) ) {
			$status  = 'complete';
			$message = __( 'All billing fields already exist.', 'airfiber-centralized' );
		}

		$calculated = $existing;
		$canonical_map = array(
			'billingday'  => 'billingDay',
			'paidthrough' => 'paidThrough',
			'nextdue'     => 'nextDue',
			'cutoffdate'  => 'cutoffDate',
		);
		foreach ( $updates as $actual_key => $value ) {
			$canonical = array_search( $actual_key, $lookup, true );
			if ( false !== $canonical && isset( $canonical_map[ $canonical ] ) ) {
				$calculated[ $canonical_map[ $canonical ] ] = $value;
			}
		}

		return array(
			'id'           => $id,
			'name'         => $name,
			'customer'     => ! empty( $details['name'] ) ? $details['name'] : $name,
			'profile'      => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'status'       => $status,
			'message'      => $message,
			'review_codes' => array_values( array_unique( $codes ) ),
			'installed'    => $installed_raw,
			'paymentDate'  => $payment_raw,
			'grace'        => $grace_raw,
			'graceUsed'    => $grace,
			'existing'     => $existing,
			'calculated'   => $calculated,
			'updates'      => $updates,
			'comment'      => $comment,
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

	private static function review_summary( $rows ) {
		$labels = array(
			'missing_installed'    => __( 'Missing/invalid installed date', 'airfiber-centralized' ),
			'missing_payment_date' => __( 'Missing/invalid payment date', 'airfiber-centralized' ),
			'invalid_grace'        => __( 'Invalid grace value', 'airfiber-centralized' ),
			'invalid_billing_day'  => __( 'Invalid billing day', 'airfiber-centralized' ),
			'invalid_paid_through' => __( 'Invalid paidThrough date', 'airfiber-centralized' ),
			'invalid_next_due'     => __( 'Invalid nextDue date', 'airfiber-centralized' ),
			'invalid_cutoff_date'  => __( 'Invalid cutoffDate', 'airfiber-centralized' ),
		);
		$summary = array();

		foreach ( $rows as $row ) {
			if ( 'review' !== $row['status'] ) {
				continue;
			}
			foreach ( $row['review_codes'] as $code ) {
				if ( ! isset( $summary[ $code ] ) ) {
					$summary[ $code ] = array(
						'code'  => $code,
						'label' => isset( $labels[ $code ] ) ? $labels[ $code ] : $code,
						'count' => 0,
					);
				}
				$summary[ $code ]['count']++;
			}
		}

		usort(
			$summary,
			function ( $first, $second ) {
				return $second['count'] <=> $first['count'];
			}
		);

		return array_values( $summary );
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
			unset( $row['comment'] );
			$rows[] = $row;
		}

		usort(
			$rows,
			function ( $first, $second ) {
				$order = array( 'safe' => 0, 'review' => 1, 'complete' => 2 );
				$result = $order[ $first['status'] ] <=> $order[ $second['status'] ];

				return $result ? $result : strcasecmp( $first['customer'], $second['customer'] );
			}
		);

		wp_send_json_success(
			array(
				'rows'           => $rows,
				'counts'         => $counts,
				'total'          => count( $rows ),
				'review_summary' => self::review_summary( $rows ),
				'rules'          => array(
					'defaultGrace' => self::DEFAULT_GRACE,
					'maxGrace'     => self::MAX_GRACE,
					'paymentRule'  => 'nearest_due_date',
				),
			)
		);
	}

	private static function sanitize_ids() {
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', $raw )
				)
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
		if ( ! $new_backups ) {
			return;
		}

		$backups = get_option( AFC_Comment_Migration::BACKUP_OPTION, array() );
		$backups = is_array( $backups ) ? $backups : array();
		$backups = array_merge( $backups, $new_backups );
		if ( count( $backups ) > self::BACKUP_LIMIT ) {
			$backups = array_slice( $backups, -self::BACKUP_LIMIT );
		}
		update_option( AFC_Comment_Migration::BACKUP_OPTION, $backups, false );
	}

	public static function ajax_apply() {
		self::authorize();
		$schema_error = self::schema_error();
		if ( $schema_error ) {
			wp_send_json_error( array( 'message' => $schema_error->get_error_message() ), 400 );
		}

		$ids = self::sanitize_ids();
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No PPP accounts were received. Refresh the preview and try again.', 'airfiber-centralized' ) ), 400 );
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
			if ( 'complete' === $row['status'] || ! $row['updates'] ) {
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
