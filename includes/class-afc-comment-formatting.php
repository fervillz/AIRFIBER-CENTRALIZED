<?php

defined( 'ABSPATH' ) || exit;

/**
 * Bulk repair tool for legacy PPP comments whose fields were joined together.
 */
class AFC_Comment_Formatting {

	const NONCE = 'afc_comment_formatting';
	const MAX_BATCH = 25;
	const BACKUP_OPTION = 'afc_comment_formatting_backups';

	public static function init() {
		add_action( 'wp_ajax_afc_preview_comment_formatting', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_apply_comment_formatting', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 85 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 85 );
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
			'afc-comment-formatting',
			AFC_URL . 'assets/css/comment-formatting.css',
			array( 'afc-comment-migration-rules' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-comment-formatting',
			AFC_URL . 'assets/js/comment-formatting.js',
			array( 'jquery', 'afc-comment-migration-rules' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-comment-formatting',
			'afcCommentFormatting',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'batchSize' => 20,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to format PPP comments.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function source_form( $comment ) {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $comment ) );
	}

	private static function fetch_secrets() {
		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,comment',
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

	private static function customer_id( $username ) {
		$ids = get_posts(
			array(
				'post_type' => 'afc_customer',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_key' => '_afc_ppp_username',
				'meta_value' => $username,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function save_backups( $items ) {
		if ( ! $items ) {
			return;
		}
		$backups = get_option( self::BACKUP_OPTION, array() );
		$backups = is_array( $backups ) ? array_merge( $backups, $items ) : $items;
		if ( count( $backups ) > 500 ) {
			$backups = array_slice( $backups, -500 );
		}
		update_option( self::BACKUP_OPTION, $backups, false );
	}

	public static function ajax_preview() {
		self::authorize();
		$secrets = self::fetch_secrets();
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}

		$rows = array();
		foreach ( $secrets as $secret ) {
			$id = isset( $secret['.id'] ) ? (string) $secret['.id'] : '';
			$name = isset( $secret['name'] ) ? (string) $secret['name'] : '';
			$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			if ( ! $id || ! $name || '' === trim( $comment ) ) {
				continue;
			}
			$formatted = AFC_Comment_Fields::normalize_comment( $comment );
			if ( self::source_form( $comment ) !== $formatted ) {
				$rows[] = array( 'id' => $id, 'name' => $name );
			}
		}

		wp_send_json_success( array( 'count' => count( $rows ), 'rows' => $rows ) );
	}

	public static function ajax_apply() {
		self::authorize();
		$ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$ids = is_array( $ids ) ? $ids : array( $ids );
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ids ) ) ) );

		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No PPP comments were selected.', 'airfiber-centralized' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( array( 'message' => __( 'Too many PPP comments were sent in one batch.', 'airfiber-centralized' ) ), 400 );
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

		$updated = 0;
		$skipped = 0;
		$failed = array();
		$backups = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				$failed[] = $id;
				continue;
			}
			$secret = $by_id[ $id ];
			$name = isset( $secret['name'] ) ? (string) $secret['name'] : '';
			$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			$new = AFC_Comment_Fields::normalize_comment( $comment );

			if ( self::source_form( $comment ) === $new ) {
				$skipped++;
				continue;
			}

			$result = AFC_MikroTik::run_command(
				array(
					'/ppp/secret/set',
					'=.id=' . $id,
					'=comment=' . $new,
				)
			);
			if ( is_wp_error( $result ) ) {
				$failed[] = $name ? $name : $id;
				continue;
			}

			$backups[] = array(
				'time' => current_time( 'mysql' ),
				'operator' => get_current_user_id(),
				'ppp_id' => $id,
				'ppp_name' => $name,
				'old_comment' => $comment,
				'new_comment' => $new,
			);
			$customer_id = self::customer_id( $name );
			if ( $customer_id ) {
				update_post_meta( $customer_id, '_afc_mikrotik_comment', $new );
			}
			$updated++;
		}

		self::save_backups( $backups );
		wp_send_json_success(
			array(
				'updated' => $updated,
				'skipped' => $skipped,
				'failed' => $failed,
			)
		);
	}
}
