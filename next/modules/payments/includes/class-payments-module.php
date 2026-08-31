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

	public static function render( $context = array() ) {
		$routers = self::payment_routers();

		ob_start();
		?>
		<div class="afcn-payments" data-afcn-payments-root>
			<div class="afcn-payments-head">
				<h1 class="afcn-page-title"><?php esc_html_e( 'Payments', 'airfiber-centralized' ); ?></h1>
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
				<div class="afcn-payment-search-meta" data-afcn-payment-search-meta aria-live="polite"></div>
			</div>

			<div class="afcn-payment-flash" data-afcn-payment-flash aria-live="polite"></div>
			<div class="afcn-payment-results" data-afcn-payment-results role="listbox" aria-label="<?php esc_attr_e( 'Customer search results', 'airfiber-centralized' ); ?>" hidden></div>

			<?php echo self::payment_dialog(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_query( $query, $payload = array() ) {
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

		$details = self::parse_comment( isset( $secret['comment'] ) ? $secret['comment'] : '' );
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

	private static function payment_dialog() {
		ob_start();
		?>
		<dialog class="afcn-dialog afcn-payment-quick-dialog" id="afcn-payment-dialog">
			<div class="afcn-dialog-shell">
				<div class="afcn-dialog-header">
					<div>
						<h2 data-afcn-payment-dialog-name><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></h2>
					</div>
					<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"><?php echo \Airfiber\Next\Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<div class="afcn-dialog-body">
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
					<div class="afcn-payment-quick-meta">
						<span><?php esc_html_e( 'Payment date:', 'airfiber-centralized' ); ?> <strong><?php echo esc_html( current_time( 'Y-m-d' ) ); ?></strong></span>
						<span><?php esc_html_e( 'GCash reference:', 'airfiber-centralized' ); ?> <strong>XXXX</strong></span>
					</div>
					<small class="afcn-payment-hold-hint"><?php esc_html_e( 'Hold CASH or GCash to change the payment amount.', 'airfiber-centralized' ); ?></small>
					<div class="afcn-payment-amount-override" data-afcn-payment-amount-override hidden>
						<label>
							<span><?php esc_html_e( 'Payment amount', 'airfiber-centralized' ); ?></span>
							<div><b>₱</b><input type="number" min="0" step="0.01" inputmode="decimal" data-afcn-payment-amount placeholder="<?php esc_attr_e( 'Enter amount', 'airfiber-centralized' ); ?>"></div>
						</label>
						<div class="afcn-payment-override-actions">
							<button type="button" class="afcn-button afcn-button-primary" data-afcn-payment-amount-apply><?php esc_html_e( 'Use Amount', 'airfiber-centralized' ); ?></button>
							<button type="button" class="afcn-button afcn-button-secondary" data-afcn-payment-amount-cancel><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
						</div>
					</div>
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
