<?php

namespace Airfiber\Next\Modules\Payments;

use Airfiber\Next\Audit_Log;
use Airfiber\Next\Connection_Store;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Payments_Module implements Module_Contract {

	const MIN_QUERY       = 3;
	const MAX_RESULTS     = 10;
	const INDEX_CACHE_TTL = 90;
	const TRASH_OPTION = 'afcn_payments_trash_v1';

	public static function render( $context = array() ) {
		$routers = self::payment_routers();
		$dashboard = ! empty( $context['dashboard'] );

		ob_start();
		?>
		<div class="afcn-payments<?php echo $dashboard ? ' is-dashboard' : ''; ?>" data-afcn-payments-root>
			<div class="afcn-payments-head">
				<h1 class="afcn-page-title"><?php esc_html_e( 'Record payment', 'airfiber-centralized' ); ?></h1>
				<div class="afcn-payment-heading-actions">
					<a class="afcn-button afcn-payment-add-ppp" href="<?php echo esc_url( \Airfiber\Next\Bootstrap::classic_url() ); ?>" title="<?php esc_attr_e( 'Open the existing Add PPP wizard in Classic Basic', 'airfiber-centralized' ); ?>">+ <?php esc_html_e( 'Add PPP', 'airfiber-centralized' ); ?></a>
					<button type="button" class="afcn-button afcn-button-secondary" data-afcn-trash-open>Trash</button>
				</div>
			</div>

			<?php if ( ! $routers ) : ?>
				<?php
				echo UI::alert(
					__( 'No MikroTik router with PPP access is available. Enable the PPP read scope on a Router connection first.', 'airfiber-centralized' ),
					array(
						'variant' => 'warning',
						'title'   => __( 'Payment search is not ready', 'airfiber-centralized' ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endif; ?>

			<div class="afcn-payment-search-wrap">
				<label class="screen-reader-text" for="afcn-payment-search"><?php esc_html_e( 'Search customers', 'airfiber-centralized' ); ?></label>
				<div class="afcn-payment-search-box">
					<span class="afcn-payment-search-icon" aria-hidden="true"></span>
					<input
						id="afcn-payment-search"
						type="text"
						enterkeyhint="search"
						autocomplete="off"
						autocapitalize="none"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'Type customer name, PPP account, phone or address…', 'airfiber-centralized' ); ?>"
						data-afcn-payment-search
						<?php disabled( ! $routers ); ?>
					>
					<button type="button" class="afcn-payment-search-clear" data-afcn-payment-clear aria-label="<?php esc_attr_e( 'Clear search', 'airfiber-centralized' ); ?>" hidden>×</button>
				</div>
				<div class="afcn-payment-search-meta" data-afcn-payment-search-meta aria-live="polite"><?php esc_html_e( 'Type at least 3 letters to search.', 'airfiber-centralized' ); ?></div>
			</div>

			<div class="afcn-payment-flash" data-afcn-payment-flash aria-live="polite"></div>
			<div class="afcn-payment-results" data-afcn-payment-results role="listbox" aria-label="<?php esc_attr_e( 'Customer search results', 'airfiber-centralized' ); ?>" hidden></div>

			<?php echo self::payment_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::trash_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_chunk( $chunk, $payload = array() ) {
		if ( 'dashboard-operations' !== $chunk ) {
			return new \WP_Error( 'afcn_payments_chunk', __( 'Unknown payment chunk.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		return self::render( array( 'dashboard' => true ) );
	}

	public static function handle_query( $query, $payload = array() ) {
		if ( 'trash-list' === $query ) return self::list_trash();
		if ( 'read-ppp-details' === $query ) return self::read_ppp_details( $payload );
		if ( 'read-ppp-comment' === $query ) {
			return self::read_ppp_comment( $payload );
		}
		if ( 'search' !== $query ) {
			return new \WP_Error( 'afcn_payments_query', __( 'Unknown payment query.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$term = isset( $payload['q'] ) ? trim( sanitize_text_field( (string) $payload['q'] ) ) : '';
		$term = substr( $term, 0, 80 );
		if ( strlen( $term ) < self::MIN_QUERY ) {
			return array(
				'query'          => $term,
				'min_characters' => self::MIN_QUERY,
				'results'        => array(),
				'count'          => 0,
			);
		}

		$routers = self::payment_routers();
		if ( ! $routers ) {
			return new \WP_Error( 'afcn_payments_no_router', __( 'No PPP-enabled MikroTik router is available.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$started       = microtime( true );
		$needle        = self::search_text( $term );
		$results       = array();
		$failed        = 0;
		$cache_hits    = 0;
		$index_reads   = 0;
		$index_limited = false;

		$trash = self::trash_items();
		foreach ( array_slice( $routers, 0, 12, true ) as $connection_id => $record ) {
			$index_started = microtime( true );
			$index         = self::router_search_index( $connection_id, $record );
			$latency       = round( ( microtime( true ) - $index_started ) * 1000, 2 );

			if ( is_wp_error( $index ) ) {
				$failed++;
				continue;
			}
			if ( ! empty( $index['cache_hit'] ) ) {
				$cache_hits++;
			} else {
				$index_reads++;
				Performance_Monitor::record_external( 'payments', $latency, 'RouterOS payment index' );
			}
			if ( ! empty( $index['truncated'] ) ) {
				$index_limited = true;
			}

			foreach ( (array) $index['items'] as $item ) {
				if ( isset( $trash[ self::trash_key( $item['connection_id'], $item['secret_id'] ) ] ) ) continue;
				if ( empty( $item['_search'] ) || false === strpos( $item['_search'], $needle ) ) {
					continue;
				}
				$item['_rank'] = self::rank( $item['customer_name'], $item['account'], $needle );
				$results[]     = $item;
			}
		}

		if ( ! $results && $failed && $failed === count( $routers ) ) {
			return new \WP_Error( 'afcn_payments_search_failed', __( 'The payment search could not reach any configured router.', 'airfiber-centralized' ), array( 'status' => 502 ) );
		}

		usort(
			$results,
			function ( $left, $right ) {
				if ( $left['_rank'] === $right['_rank'] ) {
					return strcasecmp( $left['customer_name'], $right['customer_name'] );
				}
				return $left['_rank'] < $right['_rank'] ? -1 : 1;
			}
		);

		$seen  = array();
		$clean = array();
		foreach ( $results as $result ) {
			$key = $result['connection_id'] . '|' . $result['secret_id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			unset( $result['_rank'], $result['_search'] );
			$clean[] = $result;
			if ( count( $clean ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		return array(
			'query'          => $term,
			'min_characters' => self::MIN_QUERY,
			'results'        => $clean,
			'count'          => count( $clean ),
			'failed_sources' => $failed,
			'latency_ms'     => round( ( microtime( true ) - $started ) * 1000, 2 ),
			'cache_hit'      => 0 === $index_reads && $cache_hits > 0,
			'index_limited'  => $index_limited,
		);
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( 'trash-ppp' === $action ) return self::trash_ppp( $payload );
		if ( 'restore-ppp' === $action ) return self::restore_ppp( $payload );
		if ( 'purge-ppp' === $action ) return self::purge_ppp( $payload );
		if ( 'save-ppp-details' === $action ) return self::save_ppp_details( $payload );
		if ( 'expire-ppp' === $action ) {
			return self::expire_ppp( $payload );
		}
		if ( 'save-ppp-comment' === $action ) {
			return self::save_ppp_comment( $payload );
		}
		if ( 'record-payment' !== $action ) {
			return new \WP_Error( 'afcn_payments_action', __( 'Unknown payment action.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$connection_id = isset( $payload['connection_id'] ) ? sanitize_text_field( (string) $payload['connection_id'] ) : '';
		$secret_id     = isset( $payload['secret_id'] ) ? sanitize_text_field( (string) $payload['secret_id'] ) : '';
		$account       = isset( $payload['account'] ) ? sanitize_text_field( (string) $payload['account'] ) : '';
		$method        = isset( $payload['method'] ) ? sanitize_key( (string) $payload['method'] ) : 'cash';
		$amount        = isset( $payload['amount'] ) && is_numeric( $payload['amount'] ) ? (float) $payload['amount'] : 0;

		if ( ! $connection_id || ! $secret_id || ! $account ) {
			return new \WP_Error( 'afcn_payment_incomplete', __( 'The payment request is incomplete.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $method, array( 'cash', 'gcash' ), true ) ) {
			return new \WP_Error( 'afcn_payment_method', __( 'Choose Cash or GCash.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( $amount < 0 || $amount > 1000000 ) {
			return new \WP_Error( 'afcn_payment_amount', __( 'Enter a valid payment amount.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		if ( isset( self::trash_items()[ self::trash_key( $connection_id, $secret_id ) ] ) ) return new \WP_Error( 'afcn_trashed', __( 'This PPP is in Trash. Restore it first.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		$record = Connection_Store::get( $connection_id );
		if ( ! self::is_payment_router( $record ) ) {
			return new \WP_Error( 'afcn_payment_router', __( 'That router is not available for payment lookup.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$started = microtime( true );
		$secret  = Payments_RouterOS_Client::secret( $record, $account );
		Performance_Monitor::record_external( 'payments', round( ( microtime( true ) - $started ) * 1000, 2 ), 'RouterOS payment verify' );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		if ( empty( $secret['.id'] ) || (string) $secret['.id'] !== (string) $secret_id ) {
			return new \WP_Error( 'afcn_payment_secret_changed', __( 'The selected PPP account changed. Search for the customer again before recording payment.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		// Classic advance billing moves paidThrough/nextDue/cutoffDate and
		// schedules. Standard BETA payments must not silently overwrite a
		// prepaid subscriber's payment fields without advancing those dates.
		$existing_comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		if ( preg_match( '/(?:^|[\\r\\n])\\s*(?:paidThrough|advanceMonths)\\s*:/i', $existing_comment ) ) {
			return new \WP_Error(
				'afcn_payment_advance_required',
				__( 'This customer has advance billing. Use Classic Basic to record the next payment until advance billing is migrated to BETA.', 'airfiber-centralized' ),
				array( 'status' => 409 )
			);
		}
		$details = self::parse_comment( $existing_comment );
		$date    = current_time( 'Y-m-d' );
		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$comment = self::replace_comment_value( $comment, 'paymentDate', $date );
		$comment = self::replace_comment_value( $comment, 'paymentAmount', self::amount_string( $amount ) );
		$comment = self::replace_comment_value( $comment, 'paymentMethod', $method );

		$write_started = microtime( true );
		$updated       = Payments_RouterOS_Client::set_payment_comment( $record, $secret_id, $comment );
		Performance_Monitor::record_external( 'payments', round( ( microtime( true ) - $write_started ) * 1000, 2 ), 'RouterOS record payment' );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		delete_transient( self::index_cache_key( $connection_id, $record ) );

		$user = array(
			'id'             => $secret_id,
			'name'           => $account,
			'customer_name'  => ! empty( $details['name'] ) ? $details['name'] : $account,
			'phone'          => isset( $details['cp'] ) ? $details['cp'] : '',
			'profile'        => ! empty( $details['plan'] ) ? $details['plan'] : ( isset( $secret['profile'] ) ? $secret['profile'] : '' ),
			'actual_profile' => isset( $secret['profile'] ) ? $secret['profile'] : '',
			'comment'        => $comment,
			'payment_date'   => $date,
			'payment_amount' => $amount,
			'payment_method' => $method,
			'address_text'   => isset( $details['address'] ) ? $details['address'] : '',
		);

		$customer_id = self::upsert_customer( $user, $connection_id );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$payment_id = wp_insert_post(
			array(
				'post_type'   => 'afc_payment',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Payment - %s - %s', $account, $date ),
			),
			true
		);
		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		update_post_meta( $payment_id, '_afc_customer_id', $customer_id );
		update_post_meta( $payment_id, '_afc_ppp_username', $account );
		update_post_meta( $payment_id, '_afc_payment_date', $date );
		update_post_meta( $payment_id, '_afc_payment_amount', $amount );
		update_post_meta( $payment_id, '_afc_payment_method', $method );
		update_post_meta( $payment_id, '_afc_payment_reference', 'gcash' === $method ? 'XXXX' : '' );
		update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );
		update_post_meta( $payment_id, '_afcn_router_connection_id', $connection_id );
		update_post_meta( $payment_id, '_afcn_router_secret_id', $secret_id );

		Audit_Log::record(
			'payment_recorded',
			(string) $payment_id,
			array(
				'account'       => $account,
				'connection_id' => $connection_id,
				'amount'        => self::amount_string( $amount ),
				'method'        => $method,
			)
		);
		do_action( 'afc_payment_recorded', $payment_id, $customer_id );

		$expired = isset( $secret['profile'] ) && 0 === strcasecmp( (string) $secret['profile'], 'Expired' );
		return array(
			'message'        => $expired
				? sprintf( __( 'Payment recorded for %s. The service remains expired until it is reconnected separately.', 'airfiber-centralized' ), $account )
				: sprintf( __( 'Payment recorded for %s.', 'airfiber-centralized' ), $account ),
			'payment_id'     => (int) $payment_id,
			'customer_id'    => (int) $customer_id,
			'date'           => $date,
			'amount'         => $amount,
			'method'         => $method,
			'reference'      => 'gcash' === $method ? 'XXXX' : '',
			'service_expired'=> $expired,
		);
	}


	/**
	 * Read/edit are deliberately admin-only. Payment search never exposes raw
	 * PPP comments: they can contain private contact and Wi-Fi information.
	 */
	private static function selected_ppp_secret( $payload, $include_private = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'afcn_ppp_edit_denied', __( 'You cannot edit PPP comments.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		$connection_id = isset( $payload['connection_id'] ) ? sanitize_text_field( (string) $payload['connection_id'] ) : '';
		$secret_id     = isset( $payload['secret_id'] ) ? sanitize_text_field( (string) $payload['secret_id'] ) : '';
		$account       = isset( $payload['account'] ) ? sanitize_text_field( (string) $payload['account'] ) : '';
		if ( ! $connection_id || ! $secret_id || ! $account ) {
			return new \WP_Error( 'afcn_ppp_edit_selection', __( 'Search for the PPP account again.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( isset( self::trash_items()[ self::trash_key( $connection_id, $secret_id ) ] ) ) return new \WP_Error( 'afcn_ppp_trashed', 'This PPP is in Trash. Restore it first.', array( 'status' => 409 ) );
		$record = Connection_Store::get( $connection_id );
		if ( ! self::is_payment_router( $record ) ) {
			return new \WP_Error( 'afcn_ppp_edit_router', __( 'That PPP router is no longer available.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		$secret = Payments_RouterOS_Client::secret( $record, $account, $include_private );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		if ( empty( $secret['.id'] ) || (string) $secret['.id'] !== (string) $secret_id ) {
			return new \WP_Error( 'afcn_ppp_edit_changed', __( 'PPP account changed. Search again before editing.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}
		return array( 'record' => $record, 'secret' => $secret, 'connection_id' => $connection_id, 'secret_id' => $secret_id, 'account' => $account );
	}

	/**
	 * Explicit admin-only service cutoff. Does not change billing or payment dates.
	 * Revalidate the selected PPP id on MikroTik before changing its profile.
	 */
	private static function expire_ppp( $payload ) {
		$selected = self::selected_ppp_secret( $payload );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$profile = isset( $selected['secret']['profile'] ) ? (string) $selected['secret']['profile'] : '';
		if ( 0 === strcasecmp( $profile, 'Expired' ) ) {
			return new \WP_Error( 'afcn_ppp_already_expired', __( 'This PPP account is already expired. Refresh the search.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}
		$updated = Payments_RouterOS_Client::expire_secret( $selected['record'], $selected['secret_id'], $selected['account'] );
		if ( is_wp_error( $updated ) ) {
			delete_transient( self::index_cache_key( $selected['connection_id'], $selected['record'] ) );
			return $updated;
		}
		delete_transient( self::index_cache_key( $selected['connection_id'], $selected['record'] ) );
		Audit_Log::record( 'ppp_manually_expired', $selected['account'], array(
			'connection_id' => $selected['connection_id'],
			'secret_id'     => $selected['secret_id'],
			'old_profile'   => $profile,
		) );
		return array( 'message' => sprintf( __( 'PPP account %s was manually expired and active sessions were disconnected.', 'airfiber-centralized' ), $selected['account'] ), 'status' => 'expired' );
	}


	// Password is returned ONLY on an explicit authorized PPP Edit request.
	// Neither payment-search results nor search transients include credentials.
	private static function editable_ppp_keys() {
		return array( 'name', 'profile', 'password', 'service', 'local-address', 'remote-address', 'caller-id', 'routes', 'only-one', 'limit-bytes-in', 'limit-bytes-out', 'disabled' );
	}
	private static function ppp_snapshot( $secret ) {
		$fields = array();
		foreach ( self::editable_ppp_keys() as $key ) {
			$fields[ $key ] = isset( $secret[ $key ] ) ? (string) $secret[ $key ] : '';
		}
		return $fields;
	}
	private static function read_ppp_details( $payload ) {
		$selected = self::selected_ppp_secret( $payload, true );
		if ( is_wp_error( $selected ) ) return $selected;
		$fields = self::ppp_snapshot( $selected['secret'] );
		$comment = isset( $selected['secret']['comment'] ) ? (string) $selected['secret']['comment'] : '';
		$parsed = self::parse_comment( $comment );
		return array(
			'fields' => $fields,
			'customer_name' => isset( $parsed['name'] ) ? (string) $parsed['name'] : $selected['account'],
			'version' => hash( 'sha256', wp_json_encode( $fields ) . '|' . $comment ),
		);
	}
	private static function save_ppp_details( $payload ) {
		$selected = self::selected_ppp_secret( $payload, true );
		if ( is_wp_error( $selected ) ) return $selected;
		$secret = $selected['secret'];
		$before = self::ppp_snapshot( $secret );
		$old_comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$expected = isset( $payload['version'] ) ? (string) $payload['version'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( hash( 'sha256', wp_json_encode( $before ) . '|' . $old_comment ), $expected ) ) {
			return new \WP_Error( 'afcn_ppp_edit_stale', __( 'PPP details changed on the router. Reload this account before saving.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}
		if ( ! isset( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) return new \WP_Error( 'afcn_ppp_edit_fields', 'No PPP fields supplied.', array( 'status' => 400 ) );
		$input = $payload['fields'];
		$changes = array();
		foreach ( self::editable_ppp_keys() as $key ) {
			if ( ! array_key_exists( $key, $input ) ) continue;
			if ( ! is_scalar( $input[ $key ] ) ) return new \WP_Error( 'afcn_ppp_edit_value', 'Invalid PPP value.', array( 'status' => 400 ) );
			$value = (string) $input[ $key ];
			if ( strlen( $value ) > 500 || false !== strpos( $value, "\0" ) || preg_match( '/[\r\n]/', $value ) ) return new \WP_Error( 'afcn_ppp_edit_length', 'Invalid PPP field length or line break.', array( 'status' => 400 ) );
			if ( 'password' === $key && '' === $value ) return new \WP_Error( 'afcn_ppp_password', 'PPP password must not be empty.', array( 'status' => 400 ) );
			if ( 'name' === $key && '' === trim( $value ) ) return new \WP_Error( 'afcn_ppp_username', 'PPP username must not be empty.', array( 'status' => 400 ) );
			if ( 'disabled' === $key && ! in_array( $value, array( 'true', 'false' ), true ) ) return new \WP_Error( 'afcn_ppp_disabled', 'Invalid PPP enabled state.', array( 'status' => 400 ) );
			if ( 'only-one' === $key && ! in_array( $value, array( '', 'yes', 'no', 'default' ), true ) ) return new \WP_Error( 'afcn_ppp_one', 'Invalid Only One value.', array( 'status' => 400 ) );
			if ( ( 'limit-bytes-in' === $key || 'limit-bytes-out' === $key ) && '' !== $value && ! ctype_digit( $value ) ) return new \WP_Error( 'afcn_ppp_bytes', 'Traffic limits must be non-negative numbers.', array( 'status' => 400 ) );
			if ( $value !== $before[ $key ] ) $changes[ $key ] = $value;
		}
		$customer = isset( $payload['customer_name'] ) ? sanitize_text_field( (string) $payload['customer_name'] ) : '';
		if ( '' === trim( $customer ) || strlen( $customer ) > 150 ) return new \WP_Error( 'afcn_ppp_customer', 'Enter a valid customer name.', array( 'status' => 400 ) );
		$parsed = self::parse_comment( $old_comment );
		$old_customer = isset( $parsed['name'] ) ? (string) $parsed['name'] : $selected['account'];
		$new_comment = $old_comment;
		if ( $customer !== $old_customer ) $new_comment = self::replace_comment_value( $new_comment, 'name', $customer );
		// A changed RouterOS profile must agree with the structured billing plan.
		if ( isset( $changes['profile'] ) ) {
			$new_comment = self::replace_comment_value( $new_comment, 'plan', $changes['profile'] );
		}
		if ( isset( $changes['name'] ) ) {
			$scheduler = self::matching_schedule( $selected['record'], $selected['account'] );
			if ( is_wp_error( $scheduler ) ) return $scheduler;
			// The scheduler's on-event embeds username references; renaming it
			// alone would leave a stale billing cutoff script.
			if ( $scheduler ) return new \WP_Error( 'afcn_ppp_rename_scheduler', 'PPP username cannot be renamed here while a cutoff scheduler exists. Keep the username or migrate its scheduler first.', array( 'status' => 409 ) );
		}
		if ( ! $changes && $new_comment === $old_comment ) return array( 'message' => 'No PPP changes to save.', 'account' => $selected['account'] );
		if ( $new_comment !== $old_comment ) {
			// Write comment first. Do not overwrite current billing dates.
			$done = Payments_RouterOS_Client::set_payment_comment( $selected['record'], $selected['secret_id'], $new_comment );
			if ( is_wp_error( $done ) ) return $done;
		}
		if ( $changes ) {
			$done = Payments_RouterOS_Client::update_secret_fields( $selected['record'], $selected['secret_id'], $changes );
			if ( is_wp_error( $done ) ) {
				return new \WP_Error( 'afcn_ppp_edit_partial', 'PPP field update failed; comment may already have changed. Reopen and inspect the account before retrying.', array( 'status' => 502 ) );
			}
		}
		delete_transient( self::index_cache_key( $selected['connection_id'], $selected['record'] ) );
		Audit_Log::record( 'ppp_details_updated', $selected['account'], array( 'connection_id' => $selected['connection_id'], 'changed_fields' => array_values( array_filter( array_keys( $changes ), function( $key ) { return 'password' !== $key; } ) ) );
		return array( 'message' => 'PPP account updated on MikroTik.', 'account' => isset( $changes['name'] ) ? $changes['name'] : $selected['account'] );
	}

	private static function read_ppp_comment( $payload ) {
		$selected = self::selected_ppp_secret( $payload );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$comment = isset( $selected['secret']['comment'] ) ? (string) $selected['secret']['comment'] : '';
		return array(
			'account'      => $selected['account'],
			'comment'      => $comment,
			'comment_hash' => hash( 'sha256', $comment ),
		);
	}

	private static function save_ppp_comment( $payload ) {
		$selected = self::selected_ppp_secret( $payload );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$expected = isset( $payload['comment_hash'] ) ? (string) $payload['comment_hash'] : '';
		$current  = isset( $selected['secret']['comment'] ) ? (string) $selected['secret']['comment'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( hash( 'sha256', $current ), $expected ) ) {
			return new \WP_Error( 'afcn_ppp_edit_conflict', __( 'This PPP comment changed since you opened it. Close and search again to avoid overwriting newer changes.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$comment = isset( $payload['comment'] ) && is_string( $payload['comment'] ) ? wp_check_invalid_utf8( $payload['comment'] ) : '';
		if ( '' === trim( $comment ) || false !== strpos( $comment, "\0" ) || strlen( $comment ) > 8000 ) {
			return new \WP_Error( 'afcn_ppp_edit_comment', __( 'The PPP comment must be non-empty and no longer than 8000 bytes.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		// Browser textareas use LF, while Classic stores structured comments as
		// CRLF. Preserve that format and never truncate a customer comment.
		$comment = str_replace( array( "\r\n", "\r" ), "\n", $comment );
		$comment = str_replace( "\n", "\r\n", $comment );
		if ( $comment === $current ) {
			return array( 'message' => __( 'No PPP comment changes to save.', 'airfiber-centralized' ) );
		}

		$updated = Payments_RouterOS_Client::set_payment_comment( $selected['record'], $selected['secret_id'], $comment );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		delete_transient( self::index_cache_key( $selected['connection_id'], $selected['record'] ) );
		Audit_Log::record( 'ppp_comment_updated', $selected['account'], array(
			'connection_id' => $selected['connection_id'],
			'secret_id'     => $selected['secret_id'],
		) );
		return array( 'message' => __( 'PPP comment saved to MikroTik.', 'airfiber-centralized' ) );
	}


	// Trash disables RouterOS resources; no PPP passwords or scheduler scripts
	// are copied to WordPress. Permanent deletion requires separate approval.
	private static function trash_items() {
		$items = get_option( self::TRASH_OPTION, array() );
		return is_array( $items ) ? $items : array();
	}
	private static function trash_key( $router, $id ) {
		return hash( 'sha256', (string) $router . '|' . (string) $id );
	}
	private static function was_disabled( $v ) {
		return in_array( strtolower( (string) $v ), array( 'true', 'yes', '1' ), true );
	}
	private static function command( $record, $words, $read = false ) {
		return Payments_RouterOS_Client::command( $record, $words, $read );
	}
	private static function matching_schedule( $record, $account ) {
		$read = self::command( $record, array( '/system/scheduler/print', '=.proplist=.id,name,disabled', '?name=' . $account ), true );
		if ( is_wp_error( $read ) ) return $read;
		$rows = array_values( array_filter( $read['rows'], function ( $row ) use ( $account ) {
			return isset( $row['name'] ) && $row['name'] === $account;
		} ) );
		if ( count( $rows ) > 1 || ! empty( $read['truncated'] ) ) return new \WP_Error( 'afcn_trash_ambiguous', 'Multiple schedulers match this PPP; resolve manually before deleting.', array( 'status' => 409 ) );
		return $rows ? $rows[0] : null;
	}
	private static function save_trash( $key, $row ) {
		$all = self::trash_items();
		$all[ $key ] = $row;
		return update_option( self::TRASH_OPTION, $all, false );
	}
	private static function list_trash() {
		if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'afcn_trash_access', 'You cannot manage PPP Trash.', array( 'status' => 403 ) );
		$items = array();
		foreach ( self::trash_items() as $key => $row ) {
			$items[] = array( 'trash_id' => $key, 'account' => $row['account'], 'router' => $row['router_name'], 'date' => $row['date'], 'status' => $row['status'] );
		}
		return array( 'items' => $items );
	}
	private static function trash_selection( $payload ) {
		if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'afcn_trash_access', 'You cannot manage PPP Trash.', array( 'status' => 403 ) );
		$key = isset( $payload['trash_id'] ) ? sanitize_text_field( $payload['trash_id'] ) : '';
		$all = self::trash_items();
		if ( ! isset( $all[ $key ] ) ) return new \WP_Error( 'afcn_trash_missing', 'Trash item not found.', array( 'status' => 404 ) );
		$row = $all[ $key ];
		$router = Connection_Store::get( $row['connection_id'] );
		if ( ! self::is_payment_router( $router ) ) return new \WP_Error( 'afcn_trash_router', 'The original router is unavailable.', array( 'status' => 409 ) );
		$secret = Payments_RouterOS_Client::secret( $router, $row['account'] );
		if ( is_wp_error( $secret ) ) return $secret;
		if ( ! isset( $secret['.id'] ) || (string) $secret['.id'] !== $row['secret_id'] ) return new \WP_Error( 'afcn_trash_mismatch', 'PPP identity changed. Nothing was modified.', array( 'status' => 409 ) );
		return compact( 'key', 'row', 'router', 'all', 'secret' );
	}
	private static function trash_ppp( $payload ) {
		$selected = self::selected_ppp_secret( $payload );
		if ( is_wp_error( $selected ) ) return $selected;
		$key = self::trash_key( $selected['connection_id'], $selected['secret_id'] );
		if ( isset( self::trash_items()[ $key ] ) ) return new \WP_Error( 'afcn_trash_duplicate', 'Already in Trash.', array( 'status' => 409 ) );
		$router = $selected['record'];
		$schedule = self::matching_schedule( $router, $selected['account'] );
		if ( is_wp_error( $schedule ) ) return $schedule;
		$entry = array(
			'connection_id' => $selected['connection_id'], 'secret_id' => $selected['secret_id'],
			'account' => $selected['account'], 'router_name' => isset( $router['name'] ) ? $router['name'] : 'Router',
			'date' => current_time( 'mysql' ), 'status' => 'pending',
			'secret_disabled' => self::was_disabled( isset( $selected['secret']['disabled'] ) ? $selected['secret']['disabled'] : '' ),
			'schedule_id' => $schedule ? $schedule['.id'] : '',
			'schedule_disabled' => $schedule ? self::was_disabled( isset( $schedule['disabled'] ) ? $schedule['disabled'] : '' ) : true,
		);
		if ( ! self::save_trash( $key, $entry ) ) return new \WP_Error( 'afcn_trash_backup', 'Could not create Trash record. No router changes were made.', array( 'status' => 500 ) );
		if ( $entry['schedule_id'] ) {
			$done = self::command( $router, array( '/system/scheduler/disable', '=.id=' . $entry['schedule_id'] ) );
			if ( is_wp_error( $done ) ) return new \WP_Error( 'afcn_trash_partial', 'Trash record saved, but scheduler disable failed. Inspect Trash.', array( 'status' => 502 ) );
		}
		$done = self::command( $router, array( '/ppp/secret/disable', '=.id=' . $entry['secret_id'] ) );
		if ( is_wp_error( $done ) ) return new \WP_Error( 'afcn_trash_partial', 'Scheduler may be disabled, but PPP disable failed. Inspect Trash.', array( 'status' => 502 ) );
		$active = self::command( $router, array( '/ppp/active/print', '=.proplist=.id,name', '?name=' . $entry['account'] ), true );
		if ( is_wp_error( $active ) ) return new \WP_Error( 'afcn_trash_partial', 'PPP disabled, but active sessions could not be checked.', array( 'status' => 502 ) );
		foreach ( $active['rows'] as $session ) {
			if ( ! isset( $session['.id'], $session['name'] ) || $session['name'] !== $entry['account'] ) continue;
			$done = self::command( $router, array( '/ppp/active/remove', '=.id=' . $session['.id'] ) );
			if ( is_wp_error( $done ) ) return new \WP_Error( 'afcn_trash_partial', 'PPP disabled but an active session may remain.', array( 'status' => 502 ) );
		}
		$entry['status'] = 'trashed';
		self::save_trash( $key, $entry );
		delete_transient( self::index_cache_key( $entry['connection_id'], $router ) );
		Audit_Log::record( 'ppp_trashed', $entry['account'], array( 'connection_id' => $entry['connection_id'] ) );
		return array( 'message' => 'PPP and matching cutoff scheduler moved to Trash (disabled).' );
	}
	private static function restore_ppp( $payload ) {
		$data = self::trash_selection( $payload );
		if ( is_wp_error( $data ) ) return $data;
		$row = $data['row'];
		if ( $row['schedule_id'] ) {
			$schedule = self::matching_schedule( $data['router'], $row['account'] );
			if ( is_wp_error( $schedule ) ) return $schedule;
			if ( ! $schedule || $schedule['.id'] !== $row['schedule_id'] ) return new \WP_Error( 'afcn_restore_schedule', 'The original scheduler changed. Restore cancelled.', array( 'status' => 409 ) );
		}
		if ( ! $row['secret_disabled'] ) {
			$done = self::command( $data['router'], array( '/ppp/secret/enable', '=.id=' . $row['secret_id'] ) );
			if ( is_wp_error( $done ) ) return $done;
		}
		if ( $row['schedule_id'] && ! $row['schedule_disabled'] ) {
			$done = self::command( $data['router'], array( '/system/scheduler/enable', '=.id=' . $row['schedule_id'] ) );
			if ( is_wp_error( $done ) ) return new \WP_Error( 'afcn_restore_partial', 'PPP restored, but scheduler re-enable failed. Check MikroTik.', array( 'status' => 502 ) );
		}
		unset( $data['all'][ $data['key'] ] );
		update_option( self::TRASH_OPTION, $data['all'], false );
		delete_transient( self::index_cache_key( $row['connection_id'], $data['router'] ) );
		Audit_Log::record( 'ppp_restored', $row['account'] );
		return array( 'message' => 'PPP and matching scheduler restored.' );
	}
	private static function purge_ppp( $payload ) {
		$data = self::trash_selection( $payload );
		if ( is_wp_error( $data ) ) return $data;
		$row = $data['row'];
		if ( ! self::was_disabled( isset( $data['secret']['disabled'] ) ? $data['secret']['disabled'] : '' ) ) return new \WP_Error( 'afcn_purge_active', 'PPP is enabled. Permanent deletion cancelled.', array( 'status' => 409 ) );
		if ( $row['schedule_id'] ) {
			$schedule = self::matching_schedule( $data['router'], $row['account'] );
			if ( is_wp_error( $schedule ) ) return $schedule;
			if ( $schedule && ( $schedule['.id'] !== $row['schedule_id'] || ! self::was_disabled( isset( $schedule['disabled'] ) ? $schedule['disabled'] : '' ) ) ) return new \WP_Error( 'afcn_purge_schedule', 'Scheduler changed or enabled. Permanent deletion cancelled.', array( 'status' => 409 ) );
			if ( ! $schedule && 'purging' !== $row['status'] ) return new \WP_Error( 'afcn_purge_schedule_missing', 'Original scheduler is missing. Permanent deletion cancelled.', array( 'status' => 409 ) );
			if ( $schedule ) {
				$row['status'] = 'purging';
				self::save_trash( $data['key'], $row );
				$done = self::command( $data['router'], array( '/system/scheduler/remove', '=.id=' . $row['schedule_id'] ) );
				if ( is_wp_error( $done ) ) return $done;
			}
		}
		$done = self::command( $data['router'], array( '/ppp/secret/remove', '=.id=' . $row['secret_id'] ) );
		if ( is_wp_error( $done ) ) return new \WP_Error( 'afcn_purge_partial', 'Scheduler may have been removed but PPP removal failed. Trash record kept for recovery.', array( 'status' => 502 ) );
		unset( $data['all'][ $data['key'] ] );
		update_option( self::TRASH_OPTION, $data['all'], false );
		delete_transient( self::index_cache_key( $row['connection_id'], $data['router'] ) );
		Audit_Log::record( 'ppp_purged', $row['account'] );
		return array( 'message' => 'PPP and matching scheduler permanently removed from MikroTik.' );
	}
	private static function trash_dialog() {
		ob_start(); ?>
		<dialog class="afcn-dialog" id="afcn-ppp-trash-dialog">
			<div class="afcn-dialog-shell">
				<div class="afcn-dialog-header">
					<div><h2>PPP Trash</h2><p class="afcn-page-description">Restore or permanently remove deleted PPP accounts.</p></div>
					<button type="button" class="afcn-icon-button" data-afcn-trash-close aria-label="Close Trash"><?php echo \Airfiber\Next\Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<div class="afcn-dialog-body"><div data-afcn-trash-status role="status"></div><div data-afcn-trash-list></div></div>
			</div>
		</dialog>
		<?php return ob_get_clean();
	}

	private static function ppp_edit_dialog() {
		ob_start();
		?>
		<dialog class="afcn-dialog afcn-ppp-comment-dialog" id="afcn-ppp-comment-dialog">
			<div class="afcn-dialog-shell">
				<div class="afcn-dialog-header">
					<div>
						<h2><?php esc_html_e( 'Edit PPP comment', 'airfiber-centralized' ); ?></h2>
						<p class="afcn-page-description" data-afcn-ppp-edit-account></p>
					</div>
					<button type="button" class="afcn-icon-button" data-afcn-ppp-edit-close aria-label="<?php esc_attr_e( 'Close editor', 'airfiber-centralized' ); ?>"><?php echo \Airfiber\Next\Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<div class="afcn-dialog-body">
					<p class="afcn-page-description"><?php esc_html_e( 'Edit the original MikroTik comment. Keep existing name, billing, and scheduler keys intact.', 'airfiber-centralized' ); ?></p>
					<div data-afcn-ppp-edit-message role="status"></div>
					<textarea class="afcn-input afcn-ppp-comment-input" rows="12" maxlength="8000" spellcheck="false" data-afcn-ppp-edit-text aria-label="<?php esc_attr_e( 'Raw PPP comment', 'airfiber-centralized' ); ?>"></textarea>
					<div class="afcn-ppp-comment-actions">
						<button type="button" class="afcn-button afcn-button-secondary afcn-ppp-delete-button" data-afcn-ppp-delete>Delete</button>
						<button type="button" class="afcn-button afcn-button-secondary afcn-ppp-expire-button" data-afcn-ppp-edit-expire><?php esc_html_e( 'Manual Expire', 'airfiber-centralized' ); ?></button>
						<button type="button" class="afcn-button afcn-button-secondary" data-afcn-ppp-edit-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
						<button type="button" class="afcn-button afcn-button-primary" data-afcn-ppp-edit-save><?php esc_html_e( 'Save comment', 'airfiber-centralized' ); ?></button>
					</div>
				</div>
			</div>
		</dialog>
		<?php
		return ob_get_clean();
	}

	private static function payment_dialog() {
		ob_start();
		?>
		<dialog class="afcn-dialog afcn-payment-quick-dialog afcn-payment-workspace" id="afcn-payment-dialog">
			<div class="afcn-dialog-shell">
				<div class="afcn-dialog-header">
					<div>
						<h2 data-afcn-payment-dialog-name><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></h2>
						<p class="afcn-page-description" data-afcn-workspace-subtitle></p>
					</div>
					<div class="afcn-workspace-tools" role="toolbar" aria-label="<?php esc_attr_e( 'PPP account actions', 'airfiber-centralized' ); ?>">
						<button type="button" class="afcn-icon-button" data-afcn-workspace-tab="ppp" title="Edit PPP" aria-label="Edit PPP"><?php echo \Airfiber\Next\Icon::svg( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<button type="button" class="afcn-icon-button" data-afcn-workspace-tab="comment" title="Edit original PPP comment" aria-label="Edit PPP comment"><?php echo \Airfiber\Next\Icon::svg( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<button type="button" class="afcn-icon-button afcn-workspace-trash-button" data-afcn-workspace-trash title="Move PPP to Trash" aria-label="Move PPP to Trash"><?php echo \Airfiber\Next\Icon::svg( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"><?php echo \Airfiber\Next\Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
				</div>
				<div class="afcn-dialog-body">
					<div data-afcn-workspace-status role="status" aria-live="polite"></div>
					<section class="afcn-workspace-panel" data-afcn-workspace-panel="payment">
						<div class="afcn-payment-quick-summary">
							<div><span><?php esc_html_e( 'PPP account', 'airfiber-centralized' ); ?></span><strong data-afcn-payment-dialog-account></strong></div>
							<div><span><?php esc_html_e( 'Plan', 'airfiber-centralized' ); ?></span><strong data-afcn-payment-dialog-plan></strong></div>
							<div><span><?php esc_html_e( 'Status', 'airfiber-centralized' ); ?></span><strong data-afcn-payment-dialog-status></strong></div>
						</div>
						<div data-afcn-payment-dialog-message></div>
						<button type="button" class="afcn-payment-quick-action is-cash" data-afcn-payment-quick-method="cash">
							<span class="afcn-payment-quick-spinner" aria-hidden="true"></span>
							<span data-afcn-payment-quick-label><?php esc_html_e( 'CASH', 'airfiber-centralized' ); ?></span>
							<span class="afcn-payment-cycle-pill" data-afcn-payment-cycle-pill>MTH</span>
						</button>
						<div class="afcn-payment-quick-secondary">
							<button type="button" class="afcn-payment-quick-action is-gcash" data-afcn-payment-quick-method="gcash">
								<span class="afcn-payment-quick-spinner" aria-hidden="true"></span>
								<span data-afcn-payment-quick-label><?php esc_html_e( 'GCash', 'airfiber-centralized' ); ?></span>
								<span class="afcn-payment-cycle-pill" data-afcn-payment-cycle-pill>MTH</span>
							</button>
						</div>
						<p class="afcn-page-description"><?php esc_html_e( 'For monthly advance payments, use Classic Basic until BETA supports complete billing-date updates.', 'airfiber-centralized' ); ?></p>
						<div class="afcn-payment-quick-meta">
							<span><?php esc_html_e( 'Payment date:', 'airfiber-centralized' ); ?> <strong><?php echo esc_html( current_time( 'Y-m-d' ) ); ?></strong></span>
							<span><?php esc_html_e( 'GCash reference:', 'airfiber-centralized' ); ?> <strong>XXXX</strong></span>
						</div>
						<small class="afcn-payment-hold-hint"><?php esc_html_e( 'Hold CASH or GCash to change the payment amount.', 'airfiber-centralized' ); ?></small>
						<div class="afcn-payment-amount-override" data-afcn-payment-amount-override hidden>
							<label><span><?php esc_html_e( 'Payment amount', 'airfiber-centralized' ); ?></span>
								<div><b>₱</b><input type="number" min="0" step="0.01" inputmode="decimal" data-afcn-payment-amount placeholder="Enter amount"></div>
							</label>
							<div class="afcn-payment-override-actions">
								<button type="button" class="afcn-button afcn-button-primary" data-afcn-payment-amount-apply>Use Amount</button>
								<button type="button" class="afcn-button afcn-button-secondary" data-afcn-payment-amount-cancel>Cancel</button>
							</div>
						</div>
					</section>
					<section class="afcn-workspace-panel" data-afcn-workspace-panel="ppp" hidden>
						<p class="afcn-page-description">Edit the MikroTik PPP secret. Password is displayed as plain text only for this authorized edit session.</p>
						<div class="afcn-workspace-fields">
							<label>Customer name<input class="afcn-input" data-afcn-ppp-customer autocomplete="off"></label>
							<label>PPP username<input class="afcn-input" data-afcn-ppp-field="name" autocomplete="off"></label>
							<label>Current PPP password<input class="afcn-input" type="text" data-afcn-ppp-field="password" autocomplete="off" spellcheck="false"></label>
							<label>Profile / plan<input class="afcn-input" data-afcn-ppp-field="profile"></label>
							<label>Service<input class="afcn-input" data-afcn-ppp-field="service"></label>
							<label>Local address<input class="afcn-input" data-afcn-ppp-field="local-address"></label>
							<label>Remote address<input class="afcn-input" data-afcn-ppp-field="remote-address"></label>
							<label>Caller ID<input class="afcn-input" data-afcn-ppp-field="caller-id"></label>
							<label>Routes<input class="afcn-input" data-afcn-ppp-field="routes"></label>
							<label>Only one connection<select class="afcn-input" data-afcn-ppp-field="only-one"><option value="">Router default</option><option value="yes">Yes</option><option value="no">No</option><option value="default">Default</option></select></label>
							<label>Limit bytes in<input class="afcn-input" data-afcn-ppp-field="limit-bytes-in" inputmode="numeric"></label>
							<label>Limit bytes out<input class="afcn-input" data-afcn-ppp-field="limit-bytes-out" inputmode="numeric"></label>
							<label>Account status<select class="afcn-input" data-afcn-ppp-field="disabled"><option value="false">Enabled</option><option value="true">Disabled</option></select></label>
						</div>
						<div class="afcn-workspace-footer">
							<button type="button" class="afcn-button afcn-button-secondary" data-afcn-workspace-back>Back</button>
							<button type="button" class="afcn-button afcn-button-primary" data-afcn-workspace-save-ppp>Save PPP</button>
						</div>
					</section>
					<section class="afcn-workspace-panel" data-afcn-workspace-panel="comment" hidden>
						<p class="afcn-page-description">All original PPP comment lines are editable. Changing billing or cutoff keys can affect scheduled service expiration.</p>
						<textarea class="afcn-input afcn-ppp-comment-input" rows="13" maxlength="8000" spellcheck="false" data-afcn-workspace-comment aria-label="Raw editable PPP comment"></textarea>
						<div class="afcn-workspace-footer">
							<button type="button" class="afcn-button afcn-button-secondary" data-afcn-workspace-back>Back</button>
							<button type="button" class="afcn-button afcn-button-primary" data-afcn-workspace-save-comment>Save comment</button>
						</div>
					</section>
				</div>
			</div>
		</dialog>
		<?php
		return ob_get_clean();
	}

	private static function payment_routers() {
		$output = array();
		foreach ( Connection_Store::for_module( 'routers' ) as $id => $record ) {
			if ( self::is_payment_router( $record ) ) {
				$output[ $id ] = $record;
			}
		}
		return $output;
	}

	private static function is_payment_router( $record ) {
		if ( ! is_array( $record ) || empty( $record['id'] ) || ! isset( $record['type'] ) || 'mikrotik-routeros' !== $record['type'] ) {
			return false;
		}
		$config = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		return ( ! empty( $config['read_all'] ) && '0' !== (string) $config['read_all'] )
			|| ( ! empty( $config['read_ppp'] ) && '0' !== (string) $config['read_ppp'] );
	}

	private static function router_search_index( $connection_id, $record ) {
		$cache_key = self::index_cache_key( $connection_id, $record );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['items'] ) && is_array( $cached['items'] ) ) {
			$cached['cache_hit'] = true;
			return $cached;
		}

		$inventory = Payments_RouterOS_Client::inventory( $record );
		if ( is_wp_error( $inventory ) ) {
			return $inventory;
		}

		$items = array();
		foreach ( (array) ( isset( $inventory['rows'] ) ? $inventory['rows'] : array() ) as $row ) {
			$item = self::index_item( $connection_id, $record, $row );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$index = array(
			'items'     => $items,
			'truncated' => ! empty( $inventory['truncated'] ),
			'cache_hit' => false,
		);
		set_transient( $cache_key, $index, self::INDEX_CACHE_TTL );
		return $index;
	}

	private static function index_item( $connection_id, $record, $row ) {
		if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['.id'] ) ) {
			return null;
		}
		if ( isset( $row['disabled'] ) && 'true' === strtolower( (string) $row['disabled'] ) ) {
			return null;
		}

		$details       = self::parse_comment( isset( $row['comment'] ) ? $row['comment'] : '' );
		$account       = sanitize_text_field( $row['name'] );
		$customer_name = ! empty( $details['name'] ) ? sanitize_text_field( $details['name'] ) : $account;
		$phone         = isset( $details['cp'] ) ? sanitize_text_field( $details['cp'] ) : '';
		$address       = isset( $details['address'] ) ? sanitize_text_field( $details['address'] ) : '';
		$profile       = ! empty( $details['plan'] ) ? sanitize_text_field( $details['plan'] ) : ( isset( $row['profile'] ) ? sanitize_text_field( $row['profile'] ) : '' );
		$actual        = isset( $row['profile'] ) ? sanitize_text_field( $row['profile'] ) : '';

		return array(
			'connection_id' => sanitize_text_field( $connection_id ),
			'router_name'   => isset( $record['name'] ) ? sanitize_text_field( $record['name'] ) : __( 'Router', 'airfiber-centralized' ),
			'secret_id'     => sanitize_text_field( $row['.id'] ),
			'account'       => $account,
			'customer_name' => $customer_name,
			'phone'         => $phone,
			'address'       => $address,
			'plan'          => $profile,
			'actual_profile'=> $actual,
			'payment_date'  => isset( $details['payment_date'] ) ? sanitize_text_field( $details['payment_date'] ) : '',
			'payment_amount'=> isset( $details['payment_amount'] ) && is_numeric( $details['payment_amount'] ) ? (float) $details['payment_amount'] : 0,
			'payment_method'=> isset( $details['payment_method'] ) ? sanitize_key( $details['payment_method'] ) : '',
			'billing_cycle_days' => self::billing_cycle_days( $details ),
			'status'        => 0 === strcasecmp( $actual, 'Expired' ) ? 'expired' : 'active',
			'_search'       => self::search_text( implode( ' ', array( $customer_name, $account, $phone, $address ) ) ),
		);
	}

	private static function billing_cycle_days( $details ) {
		if ( isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ) {
			foreach ( $details['custom_fields'] as $key => $value ) {
				if ( 0 === strcasecmp( (string) $key, 'billingCycleDays' ) ) {
					$cycle = (int) $value;
					return in_array( $cycle, array( 15, 30 ), true ) ? $cycle : 0;
				}
			}
		}
		if ( isset( $details['billing_cycle_days'] ) ) {
			$cycle = (int) $details['billing_cycle_days'];
			return in_array( $cycle, array( 15, 30 ), true ) ? $cycle : 0;
		}
		return 0;
	}

	private static function rank( $customer_name, $account, $needle ) {
		$name    = self::search_text( $customer_name );
		$account = self::search_text( $account );
		if ( $name === $needle || $account === $needle ) {
			return 0;
		}
		if ( 0 === strpos( $name, $needle ) || 0 === strpos( $account, $needle ) ) {
			return 1;
		}
		return 2;
	}

	private static function search_text( $value ) {
		$value = strtolower( trim( preg_replace( '/\\s+/', ' ', (string) $value ) ) );
		return $value;
	}

	private static function parse_comment( $comment ) {
		if ( class_exists( '\\AFC_Comment_Fields' ) && is_callable( array( '\\AFC_Comment_Fields', 'parse_comment' ) ) ) {
			return \AFC_Comment_Fields::parse_comment( $comment );
		}

		$values = array(
			'payment_method' => '',
			'payment_amount' => '',
			'payment_date'   => '',
			'name'           => '',
			'plan'           => '',
			'cp'             => '',
			'address'        => '',
			'billing_cycle_days' => '',
		);
		preg_match_all( '/(?:^|\\s)(paymentMethod|paymentAmount|paymentDate|name|plan|cp|Address|billingCycleDays)\\s*:\\s*(.*?)(?=\\s+(?:paymentMethod|paymentAmount|paymentDate|name|plan|cp|Address|billingCycleDays)\\s*:|$)/is', trim( (string) $comment ), $matches, PREG_SET_ORDER );
		$map = array(
			'paymentmethod' => 'payment_method',
			'paymentamount' => 'payment_amount',
			'paymentdate'   => 'payment_date',
			'address'       => 'address',
			'billingcycledays' => 'billing_cycle_days',
		);
		foreach ( $matches as $match ) {
			$key   = strtolower( $match[1] );
			$key   = isset( $map[ $key ] ) ? $map[ $key ] : $key;
			$value = trim( preg_replace( '/\\s+/', ' ', $match[2] ) );
			if ( array_key_exists( $key, $values ) ) {
				$values[ $key ] = 'N/A' === strtoupper( $value ) ? '' : $value;
			}
		}
		return $values;
	}

	private static function replace_comment_value( $comment, $key, $value ) {
		if ( class_exists( '\\AFC_Comment_Fields' ) && is_callable( array( '\\AFC_Comment_Fields', 'replace_value' ) ) ) {
			return \AFC_Comment_Fields::replace_value( $comment, $key, $value );
		}

		$comment = str_replace( array( "\r\n", "\r" ), "\n", (string) $comment );
		$pattern = '/^' . preg_quote( $key, '/' ) . '\\s*:\\s*.*$/mi';
		$line    = $key . ':' . $value;
		if ( preg_match( $pattern, $comment ) ) {
			$comment = preg_replace( $pattern, $line, $comment, 1 );
		} else {
			$comment .= ( '' !== trim( $comment ) ? "\n" : '' ) . $line;
		}
		return str_replace( "\n", "\r\n", trim( $comment ) );
	}

	private static function upsert_customer( $user, $connection_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $user['name'],
			)
		);
		$customer_id = $ids ? (int) $ids[0] : 0;

		if ( ! $customer_id ) {
			$customer_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => $user['customer_name'] ? $user['customer_name'] : $user['name'],
				),
				true
			);
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			update_post_meta( $customer_id, '_afc_account_number', 'AIR-' . str_pad( (string) $customer_id, 6, '0', STR_PAD_LEFT ) );
			update_post_meta( $customer_id, '_afc_needs_details', 1 );
		} elseif ( $user['customer_name'] ) {
			wp_update_post( array( 'ID' => $customer_id, 'post_title' => $user['customer_name'] ) );
		}

		$meta = array(
			'_afc_ppp_username'       => $user['name'],
			'_afc_mikrotik_id'        => $user['id'],
			'_afc_plan'                => $user['profile'],
			'_afc_mikrotik_comment'   => $user['comment'],
			'_afc_customer_name'       => $user['customer_name'],
			'_afc_phone'               => $user['phone'],
			'_afc_payment_method'      => $user['payment_method'],
			'_afc_payment_amount'      => $user['payment_amount'],
			'_afc_payment_date'        => $user['payment_date'],
			'_afc_address'             => $user['address_text'],
			'_afcn_router_connection_id' => $connection_id,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $customer_id, $key, $value );
		}
		return $customer_id;
	}

	private static function index_cache_key( $connection_id, $record ) {
		$updated = isset( $record['updated_at'] ) ? absint( $record['updated_at'] ) : 0;
		return 'afcn_pay_idx_' . md5( sanitize_text_field( $connection_id ) . '|' . $updated );
	}

	private static function amount_string( $amount ) {
		return rtrim( rtrim( number_format( (float) $amount, 2, '.', '' ), '0' ), '.' );
	}
}
