<?php

namespace Airfiber\Next\Modules\Tools;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;

defined( 'ABSPATH' ) || exit;

class Tools_Module implements Module_Contract {

	public static function render( $context = array() ) {
		if ( ! Capabilities::is_super_admin_user() ) {
			return '<div class="afcn-notice afcn-notice-danger">' . esc_html__( 'Tools are available only to the Airfiber Super Admin.', 'airfiber-centralized' ) . '</div>';
		}

		ob_start();
		?>
		<div class="afcn-tools" data-afcn-tools>
			<div class="afcn-tools-toolbar">
				<div>
					<strong><?php esc_html_e( 'Developer Console', 'airfiber-centralized' ); ?></strong>
					<small><?php esc_html_e( 'Diagnostics and safe runtime optimization.', 'airfiber-centralized' ); ?></small>
				</div>
				<button type="button" class="afcn-tools-clear" data-afcn-tools-clear aria-label="<?php esc_attr_e( 'Clear console', 'airfiber-centralized' ); ?>">
					<?php echo Icon::svg( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>

			<div class="afcn-tools-console" data-afcn-tools-console role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Airfiber developer console', 'airfiber-centralized' ); ?>">
				<div class="afcn-tools-line is-info"><span>AF</span><code><?php esc_html_e( 'Tools console ready. Open a performance warning and click FIX, or use this panel for diagnostics.', 'airfiber-centralized' ); ?></code></div>
			</div>

			<div class="afcn-tools-note">
				<strong><?php esc_html_e( 'Safe by default', 'airfiber-centralized' ); ?></strong>
				<p><?php esc_html_e( 'Automatic optimization can inspect, warm and retest the runtime. It does not rewrite PHP, JavaScript, CSS, database schema, SSH settings, firewall rules or customer data.', 'airfiber-centralized' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Keep the read-only query endpoint available for SDK consumers.
	 *
	 * The console itself uses the action endpoint for diagnostics because every
	 * Airfiber module is required to expose handle_action(), while handle_query()
	 * is an optional SDK capability. That makes the FIX workflow resilient even
	 * when an older/stale runtime does not recognize optional query support yet.
	 */
	public static function handle_query( $query, $payload = array() ) {
		$permission = self::permission_check();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( 'diagnose-performance' === $query ) {
			return self::diagnose_performance( $payload );
		}

		return new \WP_Error( 'afcn_tools_query_unknown', __( 'Unknown Tools query.', 'airfiber-centralized' ), array( 'status' => 404 ) );
	}

	public static function handle_action( $action, $payload = array() ) {
		$permission = self::permission_check();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( 'diagnose-performance' === $action ) {
			return self::diagnose_performance( $payload );
		}

		if ( 'optimize-performance' === $action ) {
			return Performance_Doctor::optimize( isset( $payload['module'] ) ? $payload['module'] : '' );
		}

		return new \WP_Error( 'afcn_tools_action_unknown', __( 'Unknown Tools action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}

	private static function permission_check() {
		if ( ! Capabilities::is_super_admin_user() ) {
			return new \WP_Error( 'afcn_tools_forbidden', __( 'Super Admin access is required.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function diagnose_performance( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		return Performance_Doctor::diagnose(
			isset( $payload['module'] ) ? $payload['module'] : '',
			isset( $payload['phase'] ) ? $payload['phase'] : '',
			isset( $payload['cause'] ) ? $payload['cause'] : ''
		);
	}
}
