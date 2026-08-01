<?php

defined( 'ABSPATH' ) || exit;

/**
 * Small UX improvements shared by the Basic payment screen, PPP editor, and
 * billing-field migration. Also provides an explicit, confirmed PPP delete.
 */
class AFC_PPP_Operations_UX {

	const NONCE = 'afc_ppp_operations_ux';

	public static function init() {
		add_action( 'wp_ajax_afc_ppp_delete_account', array( __CLASS__, 'ajax_delete_account' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 95 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 95 );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		wp_enqueue_style(
			'afc-ppp-operations-ux',
			AFC_URL . 'assets/css/ppp-operations-ux.css',
			array(),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ppp-operations-ux',
			AFC_URL . 'assets/js/ppp-operations-ux.js',
			array( 'jquery' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-ppp-operations-ux',
			'afcPPPOperationsUX',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to delete PPP accounts.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function rows( $result ) {
		if ( ! is_array( $result ) || empty( $result ) ) {
			return array();
		}
		return isset( $result['.id'] ) || isset( $result['name'] ) ? array( $result ) : $result;
	}

	private static function find_secret( $id, $username ) {
		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,service,comment',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( self::rows( $result ) as $secret ) {
			if ( isset( $secret['.id'], $secret['name'] ) && (string) $secret['.id'] === $id && (string) $secret['name'] === $username ) {
				return $secret;
			}
		}
		return new WP_Error( 'afc_ppp_delete_missing', __( 'The selected PPP account no longer exists or changed before deletion.', 'airfiber-centralized' ) );
	}

	private static function disconnect_active_sessions( $username ) {
		$result = AFC_MikroTik::run_command(
			array(
				'/ppp/active/print',
				'?name=' . $username,
				'=.proplist=.id,name',
			)
		);
		if ( is_wp_error( $result ) ) {
			return array( 'count' => 0, 'warnings' => array( $result->get_error_message() ) );
		}
		$count    = 0;
		$warnings = array();
		foreach ( self::rows( $result ) as $active ) {
			if ( empty( $active['.id'] ) || empty( $active['name'] ) || (string) $active['name'] !== $username ) {
				continue;
			}
			$removed = AFC_MikroTik::run_command( array( '/ppp/active/remove', '=.id=' . (string) $active['.id'] ) );
			if ( is_wp_error( $removed ) ) {
				$warnings[] = $removed->get_error_message();
			} else {
				$count++;
			}
		}
		return array( 'count' => $count, 'warnings' => $warnings );
	}

	private static function remove_matching_schedulers( $username ) {
		$result = AFC_MikroTik::run_command(
			array(
				'/system/scheduler/print',
				'?name=' . $username,
				'=.proplist=.id,name',
			)
		);
		if ( is_wp_error( $result ) ) {
			return array( 'count' => 0, 'warnings' => array( $result->get_error_message() ) );
		}
		$count    = 0;
		$warnings = array();
		foreach ( self::rows( $result ) as $scheduler ) {
			if ( empty( $scheduler['.id'] ) || empty( $scheduler['name'] ) || (string) $scheduler['name'] !== $username ) {
				continue;
			}
			$removed = AFC_MikroTik::run_command( array( '/system/scheduler/remove', '=.id=' . (string) $scheduler['.id'] ) );
			if ( is_wp_error( $removed ) ) {
				$warnings[] = $removed->get_error_message();
			} else {
				$count++;
			}
		}
		return array( 'count' => $count, 'warnings' => $warnings );
	}

	private static function trash_linked_customers( $username ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			if ( 'trash' === get_post_status( $id ) || wp_trash_post( $id ) ) {
				$count++;
			}
		}
		return $count;
	}

	private static function cancel_queued_sms( $username ) {
		global $wpdb;
		$table = $wpdb->prefix . 'afc_sms_jobs';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $found ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', last_detail = %s, updated_at = %s, cancelled_at = %s WHERE ppp_username = %s AND status = 'queued'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'PPP account deleted before Android claimed the message.',
				$now,
				$now,
				$username
			)
		);
	}

	public static function ajax_delete_account() {
		self::authorize();

		$id           = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$username     = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';

		if ( '' === $id || '' === $username || ! hash_equals( $username, $confirmation ) ) {
			wp_send_json_error( array( 'message' => __( 'Type the exact PPP username to confirm deletion.', 'airfiber-centralized' ) ) );
		}

		$secret = self::find_secret( $id, $username );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}

		$active = self::disconnect_active_sessions( $username );
		$remove = AFC_MikroTik::run_command( array( '/ppp/secret/remove', '=.id=' . $id ) );
		if ( is_wp_error( $remove ) ) {
			wp_send_json_error( array( 'message' => $remove->get_error_message() ) );
		}

		$schedulers = self::remove_matching_schedulers( $username );
		$trashed    = self::trash_linked_customers( $username );
		$cancelled  = self::cancel_queued_sms( $username );
		$warnings   = array_merge( $active['warnings'], $schedulers['warnings'] );

		$message = sprintf(
			/* translators: 1: PPP username, 2: active sessions, 3: schedulers. */
			__( '%1$s was deleted. %2$d active session(s) disconnected and %3$d matching scheduler(s) removed.', 'airfiber-centralized' ),
			$username,
			(int) $active['count'],
			(int) $schedulers['count']
		);
		if ( $trashed ) {
			$message .= ' ' . sprintf( __( '%d linked customer record(s) moved to Trash.', 'airfiber-centralized' ), $trashed );
		}
		if ( $cancelled ) {
			$message .= ' ' . sprintf( __( '%d queued SMS job(s) cancelled.', 'airfiber-centralized' ), $cancelled );
		}
		if ( $warnings ) {
			$message .= ' ' . __( 'Some cleanup steps returned warnings; review MikroTik after the page reloads.', 'airfiber-centralized' );
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'warnings' => array_slice( array_unique( $warnings ), 0, 10 ),
			)
		);
	}
}
