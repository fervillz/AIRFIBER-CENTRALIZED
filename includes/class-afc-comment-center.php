<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compact Comment Center navigation for schema, billing migration, formatting,
 * and safety information inside the Advanced frontend application.
 */
class AFC_Comment_Center {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 96 );
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-comment-center',
			AFC_URL . 'assets/css/comment-center.css',
			array( 'afc-comment-formatting' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-comment-center',
			AFC_URL . 'assets/js/comment-center.js',
			array(
				'jquery',
				'afc-comment-fields',
				'afc-comment-migration',
				'afc-comment-migration-rules',
				'afc-comment-formatting',
			),
			AFC_VERSION,
			true
		);

		$custom_fields = AFC_Comment_Fields::get_custom_fields();
		$required      = array( 'billingday', 'paidthrough', 'nextdue', 'cutoffdate' );
		$configured    = array();
		foreach ( $custom_fields as $field ) {
			$key = isset( $field['key'] ) ? strtolower( (string) $field['key'] ) : '';
			if ( in_array( $key, $required, true ) ) {
				$configured[] = $key;
			}
		}

		$migration_backups = get_option( AFC_Comment_Migration::BACKUP_OPTION, array() );
		$format_backups    = get_option( AFC_Comment_Formatting::BACKUP_OPTION, array() );

		wp_localize_script(
			'afc-comment-center',
			'afcCommentCenter',
			array(
				'customFieldCount'     => count( $custom_fields ),
				'requiredCount'        => count( array_unique( $configured ) ),
				'requiredTotal'        => count( $required ),
				'migrationBackupCount' => is_array( $migration_backups ) ? count( $migration_backups ) : 0,
				'formatBackupCount'    => is_array( $format_backups ) ? count( $format_backups ) : 0,
				'labels'               => array(
					'overview' => __( 'Overview', 'airfiber-centralized' ),
					'schema'   => __( 'Field Schema', 'airfiber-centralized' ),
					'billing'  => __( 'Billing Fields', 'airfiber-centralized' ),
					'repair'   => __( 'Comment Repair', 'airfiber-centralized' ),
					'safety'   => __( 'Safety & Backups', 'airfiber-centralized' ),
				),
			)
		);
	}
}
