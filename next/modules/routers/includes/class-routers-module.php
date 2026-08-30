<?php

namespace Airfiber\Next\Modules\Routers;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Connection_Health;
use Airfiber\Next\Connection_Store;
use Airfiber\Next\Data_Query;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Secret_Store;
use Airfiber\Next\Tooltip;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Native read-only MikroTik inventory.
 *
 * Rendering is cache-only. RouterOS is contacted only by an explicit connection
 * test or one explicit scope query from a router detail card.
 */
class Routers_Module implements Module_Contract {
	const MODULE_ID        = 'routers';
	const CONNECTOR_TYPE   = 'mikrotik-routeros';
	const SCOPE_PAGE_SIZE  = 10;
	const SCOPE_CACHE_TTL  = 30;

	public static function render( $context = array() ) {
		$connections = array_slice( Connection_Store::for_module( self::MODULE_ID ), 0, 60, true );
		$browser     = self::browser_snapshot( $connections );
		$counts      = $browser['counts'];
		$health      = $browser['health'];
		$can_manage  = self::can_manage_connections();

		ob_start();
		?>
		<div data-afcn-routers-root>
			<div class="afcn-page-head" data-afcn-router-browser-head>
				<div>
					<h1 class="afcn-page-title"><?php esc_html_e( 'Routers', 'airfiber-centralized' ); ?></h1>
				</div>
				<?php if ( $can_manage ) : ?>
					<button type="button" class="afcn-button afcn-button-primary" data-afcn-dialog-open="afcn-add-router-dialog"><?php echo Icon::svg( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Add Router', 'airfiber-centralized' ); ?></button>
				<?php endif; ?>
			</div>

			<div class="afcn-connections-browser" data-afcn-connections-browser data-afcn-router-browser>
				<div class="afcn-connections-toolbar">
					<nav class="afcn-connection-filters" aria-label="<?php esc_attr_e( 'Filter routers', 'airfiber-centralized' ); ?>">
						<?php
						$filters = array(
							'all'          => __( 'All', 'airfiber-centralized' ),
							'online'       => __( 'Online', 'airfiber-centralized' ),
							'offline'      => __( 'Offline', 'airfiber-centralized' ),
							'warning'      => __( 'Warning', 'airfiber-centralized' ),
							'unconfigured' => __( 'Unconfigured', 'airfiber-centralized' ),
						);
						foreach ( $filters as $filter => $label ) :
							?>
							<button type="button" class="afcn-connection-filter<?php echo 'all' === $filter ? ' is-active' : ''; ?>" data-afcn-connection-filter="<?php echo esc_attr( $filter ); ?>">
								<?php echo esc_html( $label ); ?> <span>(<?php echo esc_html( isset( $counts[ $filter ] ) ? $counts[ $filter ] : 0 ); ?>)</span>
							</button>
						<?php endforeach; ?>
					</nav>
					<label class="afcn-connections-search">
						<?php echo Icon::svg( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="search" data-afcn-connection-search placeholder="<?php esc_attr_e( 'Search routers', 'airfiber-centralized' ); ?>">
					</label>
				</div>

				<div class="afcn-connection-groups" data-afcn-router-card-view>
					<?php if ( $connections ) : ?>
						<section class="afcn-connection-group" data-afcn-connection-group data-afcn-router-library>
							<div class="afcn-connection-group-heading">
								<h2><?php esc_html_e( 'Router Library', 'airfiber-centralized' ); ?></h2>
								<span><?php echo esc_html( count( $connections ) ); ?></span>
							</div>
							<div class="afcn-connection-grid" data-afcn-router-cards>
								<?php foreach ( $connections as $id => $record ) : ?>
									<?php self::render_router_card( $id, $record, $can_manage, isset( $health[ $id ] ) ? $health[ $id ] : array() ); ?>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>
				</div>

				<div class="afcn-connections-empty" data-afcn-connections-empty<?php echo $connections ? ' hidden' : ''; ?>>
					<?php esc_html_e( 'No routers match the current view. Add a router here or from Connections.', 'airfiber-centralized' ); ?>
				</div>
			</div>

			<?php foreach ( $connections as $id => $record ) : ?>
				<?php self::render_router_detail( $id, $record, isset( $health[ $id ] ) ? $health[ $id ] : array() ); ?>
			<?php endforeach; ?>

			<?php if ( $can_manage ) : ?>
				<?php self::render_add_dialog(); ?>
				<?php foreach ( $connections as $id => $record ) : ?>
					<?php self::render_edit_dialog( $id, $record ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( ! self::can_manage_connections() ) {
			return new \WP_Error( 'afcn_routers_forbidden', __( 'You cannot manage router connections.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		if ( 'create-router' === $action ) {
			$prepared = self::prepare_record( $payload );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$result = Connection_Store::create( $prepared['record'], $prepared['secrets'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'message' => __( 'Router added.', 'airfiber-centralized' ), 'refresh_nav' => true );
		}

		if ( 'update-router' === $action ) {
			$id       = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$existing = self::connection( $id );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			$prepared = self::prepare_record( $payload, $existing );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$result = Connection_Store::update( $id, $prepared['record'], $prepared['secrets'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( self::connection_changed( $existing, $prepared['record'] ) || ! empty( $prepared['secrets'] ) ) {
				Connection_Health::clear( $id );
			}
			self::clear_scope_cache( $id );
			return array( 'message' => __( 'Router settings saved.', 'airfiber-centralized' ), 'refresh_nav' => true );
		}

		if ( 'test-router' === $action ) {
			$id     = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$record = self::connection( $id );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$result = self::run_test( $record, array() );
			if ( is_wp_error( $result ) ) {
				Connection_Health::set( $id, array( 'state' => 'warning', 'message' => $result->get_error_message() ) );
				return $result;
			}
			Connection_Health::set( $id, $result );
			return array( 'message' => $result['message'] );
		}

		if ( 'test-connection' === $action ) {
			$test = self::test_context( $payload );
			if ( is_wp_error( $test ) ) {
				return $test;
			}
			return self::run_test( $test['record'], $test['secrets'] );
		}

		return new \WP_Error( 'afcn_routers_action', __( 'Unknown router action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}

	public static function handle_query( $query, $payload = array() ) {
		if ( 'scope' !== $query ) {
			return new \WP_Error( 'afcn_routers_query', __( 'Unknown router query.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_manage_connections() ) {
			return new \WP_Error( 'afcn_routers_read_forbidden', __( 'You cannot read router configuration data.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$id      = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
		$scope   = isset( $payload['scope'] ) ? sanitize_key( $payload['scope'] ) : '';
		$page    = isset( $payload['page'] ) ? max( 1, absint( $payload['page'] ) ) : 1;
		$search  = isset( $payload['search'] ) ? substr( sanitize_text_field( (string) $payload['search'] ), 0, 120 ) : '';
		$refresh = ! empty( $payload['refresh'] ) && '0' !== (string) $payload['refresh'];
		$record  = self::connection( $id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$definitions = self::scope_definitions();
		if ( ! isset( $definitions[ $scope ] ) ) {
			return new \WP_Error( 'afcn_router_scope_unknown', __( 'That router data scope is not available.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $scope, self::enabled_scopes( $record ), true ) ) {
			return new \WP_Error( 'afcn_router_scope_disabled', __( 'Enable this data scope in the router settings first.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$definition = $definitions[ $scope ];
		$dataset    = self::scope_dataset( $id, $record, $scope, $definition, $refresh );
		if ( is_wp_error( $dataset ) ) {
			return $dataset;
		}

		$view = Data_Query::apply(
			$dataset['rows'],
			array(
				'search'    => $search,
				'page'      => $page,
				'page_size' => self::SCOPE_PAGE_SIZE,
			)
		);

		$columns = array();
		foreach ( $definition['columns'] as $key => $label ) {
			$columns[] = array( 'key' => $key, 'label' => $label );
		}

		return array(
			'scope'      => $scope,
			'label'      => $definition['label'],
			'columns'    => $columns,
			'rows'       => $view['rows'],
			'pagination' => $view['pagination'],
			'search'     => $search,
			'truncated'  => ! empty( $dataset['truncated'] ),
			'latency_ms' => isset( $dataset['latency_ms'] ) ? (float) $dataset['latency_ms'] : 0,
			'cache_hit'  => ! empty( $dataset['cache_hit'] ),
			'cached_at'  => isset( $dataset['cached_at'] ) ? absint( $dataset['cached_at'] ) : 0,
		);
	}

	private static function render_router_card( $id, $record, $can_manage, $health = array() ) {
		$health       = is_array( $health ) ? $health : array();
		$state        = self::browser_state( $health );
		$scopes       = self::enabled_scopes( $record );
		$scope_label  = sprintf( _n( '%d selected data scope', '%d selected data scopes', count( $scopes ), 'airfiber-centralized' ), count( $scopes ) );
		$endpoint     = isset( $record['endpoint'] ) ? $record['endpoint'] : '';
		$status_label = self::health_label( $health );
		$status_info  = $status_label;
		if ( ! empty( $health['checked_at'] ) ) {
			$status_info .= ' · ' . sprintf( __( 'checked %s ago', 'airfiber-centralized' ), human_time_diff( absint( $health['checked_at'] ), time() ) );
		}
		$search = strtolower( implode( ' ', array( $record['name'], $endpoint, $scope_label, 'mikrotik routeros', $status_label ) ) );
		?>
		<article class="afcn-card afcn-connection-card afcn-router-card is-<?php echo esc_attr( $state ); ?>" data-afcn-connection-card data-afcn-state="<?php echo esc_attr( $state ); ?>" data-afcn-search="<?php echo esc_attr( $search ); ?>" data-afcn-router-card="<?php echo esc_attr( $id ); ?>" data-afcn-card-key="<?php echo esc_attr( $id ); ?>">
			<div class="afcn-connection-card-top">
				<span class="afcn-connection-icon"><?php echo Icon::svg( 'router' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="afcn-connection-card-badges">
					<span class="afcn-connection-provider"><?php esc_html_e( 'MikroTik RouterOS', 'airfiber-centralized' ); ?></span>
				</div>
			</div>
			<h3 title="<?php echo esc_attr( $record['name'] ); ?>"><?php echo esc_html( $record['name'] ); ?></h3>
			<p class="afcn-connection-subtitle"><?php echo esc_html( $endpoint ); ?></p>
			<p class="afcn-connection-meta"><?php echo esc_html( $scope_label ); ?></p>
			<div class="afcn-connection-card-bottom">
				<?php
				$status_dot = '<span class="afcn-connection-status-dot is-' . esc_attr( $state ) . '" aria-hidden="true"></span><span>' . esc_html( $status_label ) . '</span>';
				echo Tooltip::render( $status_dot, $status_info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<div class="afcn-connection-actions">
					<?php echo Tooltip::render( '<button type="button" class="afcn-connection-action" data-afcn-router-select="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Open router', 'airfiber-centralized' ) . '">' . Icon::svg( 'server' ) . '</button>', __( 'Open router', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( $can_manage ) : ?>
						<?php echo Tooltip::render( '<button type="button" class="afcn-connection-action" data-afcn-dialog-open="afcn-router-' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Router settings', 'airfiber-centralized' ) . '">' . Icon::svg( 'gear' ) . '</button>', __( 'Router settings', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	private static function render_router_detail( $id, $record, $health = array() ) {
		$health   = is_array( $health ) ? $health : Connection_Health::get( $id );
		$details  = isset( $health['details'] ) && is_array( $health['details'] ) ? $health['details'] : array();
		$scopes   = self::enabled_scopes( $record );
		$defs     = self::scope_definitions();
		$endpoint = isset( $record['endpoint'] ) ? $record['endpoint'] : '';

		ob_start();
		?>
		<div class="afcn-router-health-strip" aria-label="<?php esc_attr_e( 'Cached router health', 'airfiber-centralized' ); ?>">
			<?php
			$state       = self::browser_state( $health );
			$status_text = self::health_label( $health );
			$status_tip  = sprintf( __( 'Cached health: %s', 'airfiber-centralized' ), $status_text );
			if ( ! empty( $health['checked_at'] ) ) {
				$status_tip .= ' · ' . sprintf( __( 'checked %s ago', 'airfiber-centralized' ), human_time_diff( absint( $health['checked_at'] ), time() ) );
			}
			$status_trigger = '<span class="afcn-router-health-item is-status"><span class="afcn-connection-status-dot is-' . esc_attr( $state ) . '" aria-hidden="true"></span></span>';
			echo Tooltip::render( $status_trigger, $status_tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $details ) {
				self::render_health_metric( 'update', isset( $details['version'] ) ? $details['version'] : '', __( 'RouterOS version', 'airfiber-centralized' ) );
				self::render_health_metric( 'server', '', __( 'Board', 'airfiber-centralized' ), isset( $details['board_name'] ) ? $details['board_name'] : '' );
				self::render_health_metric( 'activity', isset( $details['cpu_load'] ) ? $details['cpu_load'] . '%' : '', __( 'CPU load', 'airfiber-centralized' ) );
				self::render_health_metric( 'refresh', isset( $details['uptime'] ) ? $details['uptime'] : '', __( 'Uptime', 'airfiber-centralized' ) );
			}
			?>
		</div>
		<form data-afcn-module="routers" data-afcn-action="test-router">
			<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>">
			<button type="submit" class="afcn-button afcn-button-secondary"><?php echo Icon::svg( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Test connection', 'airfiber-centralized' ); ?></button>
		</form>
		<?php if ( self::can_manage_connections() ) : ?>
			<?php echo Tooltip::render( '<button type="button" class="afcn-icon-button" data-afcn-dialog-open="afcn-add-router-dialog" aria-label="' . esc_attr__( 'Add Router', 'airfiber-centralized' ) . '">' . Icon::svg( 'plus' ) . '</button>', __( 'Add Router', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
		<?php
		$actions = ob_get_clean();
		?>
		<section class="afcn-drilldown-view" data-afcn-router-detail="<?php echo esc_attr( $id ); ?>" hidden>
			<?php echo UI::drilldown_head( __( 'Router', 'airfiber-centralized' ), $record['name'], $endpoint, $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( ! $scopes ) : ?>
				<div class="afcn-notice"><strong><?php esc_html_e( 'No data scopes selected.', 'airfiber-centralized' ); ?></strong> <?php esc_html_e( 'Open Settings and choose what this router may expose.', 'airfiber-centralized' ); ?></div>
			<?php else : ?>
				<div class="afcn-router-scope-grid" data-afcn-card-drop-group="router-scopes-<?php echo esc_attr( $id ); ?>">
					<?php foreach ( $scopes as $scope ) : ?>
						<?php $definition = $defs[ $scope ]; ?>
						<article class="afcn-card afcn-router-scope-card" data-afcn-card-key="router-scope-<?php echo esc_attr( $id . '-' . $scope ); ?>">
							<div class="afcn-router-scope-card-head"><span class="afcn-router-scope-icon"><?php echo Icon::svg( $definition['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></div>
							<h3 title="<?php echo esc_attr( $definition['label'] ); ?>"><?php echo esc_html( $definition['label'] ); ?></h3>
							<p title="<?php echo esc_attr( $definition['description'] ); ?>"><?php echo esc_html( $definition['description'] ); ?></p>
							<button type="button" class="afcn-button afcn-button-secondary afcn-button-small" data-afcn-router-scope-load data-afcn-connection-id="<?php echo esc_attr( $id ); ?>" data-afcn-scope="<?php echo esc_attr( $scope ); ?>" data-afcn-scope-label="<?php echo esc_attr( $definition['label'] ); ?>"><?php esc_html_e( 'Load', 'airfiber-centralized' ); ?></button>
						</article>
					<?php endforeach; ?>
				</div>
				<div class="afcn-card afcn-router-scope-results" data-afcn-router-scope-results hidden>
					<div class="afcn-card-header"><h3 data-afcn-router-scope-result-title><?php esc_html_e( 'Router details', 'airfiber-centralized' ); ?></h3></div>
					<div class="afcn-card-body" data-afcn-router-scope-output aria-live="polite"></div>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_add_dialog() {
		?>
		<dialog class="afcn-dialog" id="afcn-add-router-dialog">
			<form method="dialog" class="afcn-dialog-shell" data-afcn-module="routers" data-afcn-action="create-router" data-afcn-dialog-mode="create" data-afcn-router-form>
				<div class="afcn-dialog-header"><div><h2><?php esc_html_e( 'Add Router', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Connect through the read-only RouterOS API.', 'airfiber-centralized' ); ?></p></div><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div>
				<div class="afcn-dialog-body"><?php self::render_form_fields( array(), false ); ?></div>
				<div class="afcn-dialog-footer"><button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Router', 'airfiber-centralized' ); ?></button></div>
			</form>
		</dialog>
		<?php
	}

	private static function render_edit_dialog( $id, $record ) {
		?>
		<dialog class="afcn-dialog" id="afcn-router-<?php echo esc_attr( $id ); ?>">
			<form method="dialog" class="afcn-dialog-shell" data-afcn-module="routers" data-afcn-action="update-router" data-afcn-router-form>
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $id ); ?>">
				<div class="afcn-dialog-header"><div><h2><?php echo esc_html( $record['name'] ); ?></h2><p><?php esc_html_e( 'Router settings and allowed read scopes', 'airfiber-centralized' ); ?></p></div><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div>
				<div class="afcn-dialog-body"><?php self::render_form_fields( $record, true ); ?></div>
				<div class="afcn-dialog-footer"><button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Changes', 'airfiber-centralized' ); ?></button></div>
			</form>
		</dialog>
		<?php
	}

	private static function render_form_fields( $record, $is_edit ) {
		$config = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		$all    = ! $is_edit || ! empty( $config['read_all'] );
		?>
		<div class="afcn-form-grid">
			<?php echo UI::field( 'connection_name', __( 'Router name', 'airfiber-centralized' ), array( 'value' => isset( $record['name'] ) ? $record['name'] : '', 'required' => true, 'placeholder' => __( 'e.g. DESKTOP-P', 'airfiber-centralized' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::field( 'host', __( 'Router IP / Host', 'airfiber-centralized' ), array( 'value' => isset( $config['host'] ) ? $config['host'] : '', 'required' => true, 'placeholder' => '10.13.88.1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::select( 'protocol', __( 'Protocol', 'airfiber-centralized' ), array( 'api' => __( 'API', 'airfiber-centralized' ), 'api-ssl' => __( 'API over TLS', 'airfiber-centralized' ) ), isset( $config['protocol'] ) ? $config['protocol'] : 'api' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::field( 'port', __( 'API port', 'airfiber-centralized' ), array( 'type' => 'number', 'value' => isset( $config['port'] ) ? $config['port'] : '8728', 'placeholder' => '8728' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::field( 'username', __( 'Username', 'airfiber-centralized' ), array( 'value' => isset( $config['username'] ) ? $config['username'] : '', 'required' => true, 'placeholder' => 'airfiber-readonly' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::field( 'password', __( 'Password', 'airfiber-centralized' ), array( 'type' => 'password', 'required' => ! $is_edit, 'placeholder' => $is_edit ? __( 'Leave blank to keep current value', 'airfiber-centralized' ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo UI::field( 'timeout_ms', __( 'Timeout (ms)', 'airfiber-centralized' ), array( 'type' => 'number', 'value' => isset( $config['timeout_ms'] ) ? $config['timeout_ms'] : '5000', 'placeholder' => '5000' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<label class="afcn-field afcn-checkbox-field"><span><?php esc_html_e( 'Verify TLS certificate', 'airfiber-centralized' ); ?></span><input type="checkbox" name="verify_ssl" value="1"<?php checked( ! empty( $config['verify_ssl'] ) ); ?>></label>
		</div>
		<div class="afcn-card" style="margin-top:16px">
			<div class="afcn-card-header"><h3><?php esc_html_e( 'What may Airfiber read?', 'airfiber-centralized' ); ?></h3></div>
			<div class="afcn-card-body">
				<p class="afcn-page-description"><?php esc_html_e( 'These choices only expose bounded read-only views. No router data is loaded until you press Load.', 'airfiber-centralized' ); ?></p>
				<div class="afcn-form-grid" style="margin-top:12px">
					<label class="afcn-field afcn-checkbox-field"><span><?php esc_html_e( 'All available data', 'airfiber-centralized' ); ?></span><input type="checkbox" name="read_all" value="1" data-afcn-router-read-all<?php checked( $all ); ?>></label>
					<?php foreach ( self::scope_definitions() as $scope => $definition ) : ?>
						<label class="afcn-field afcn-checkbox-field"><span><?php echo esc_html( $definition['label'] ); ?></span><input type="checkbox" name="read_<?php echo esc_attr( $scope ); ?>" value="1" data-afcn-router-scope-option<?php checked( $all || ! empty( $config[ 'read_' . $scope ] ) ); ?>></label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function prepare_record( $payload, $existing = null ) {
		$name     = isset( $payload['connection_name'] ) ? sanitize_text_field( $payload['connection_name'] ) : '';
		$host     = isset( $payload['host'] ) ? trim( sanitize_text_field( $payload['host'] ) ) : '';
		$host     = preg_replace( '#^(?:https?://|tcp://|tls://)#i', '', $host );
		$host     = trim( $host, "/ []\t\n\r\0\x0B" );
		$protocol = isset( $payload['protocol'] ) && 'api-ssl' === $payload['protocol'] ? 'api-ssl' : 'api';
		$port     = isset( $payload['port'] ) ? absint( $payload['port'] ) : 0;
		$port     = $port >= 1 && $port <= 65535 ? $port : ( 'api-ssl' === $protocol ? 8729 : 8728 );
		$username = isset( $payload['username'] ) ? sanitize_text_field( $payload['username'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error( 'afcn_router_name', __( 'Router name is required.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( '' === $host || ! preg_match( '/^[a-z0-9][a-z0-9.:-]*$/i', $host ) ) {
			return new \WP_Error( 'afcn_router_host', __( 'Enter a valid router IP address or hostname.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( '' === $username ) {
			return new \WP_Error( 'afcn_router_username', __( 'Router username is required.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$password = isset( $payload['password'] ) ? (string) wp_unslash( $payload['password'] ) : '';
		if ( '' === $password && ( ! $existing || '' === Secret_Store::get( $existing['id'], 'password', '' ) ) ) {
			return new \WP_Error( 'afcn_router_password', __( 'Router password is required.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$timeout = isset( $payload['timeout_ms'] ) && is_numeric( $payload['timeout_ms'] ) ? (int) $payload['timeout_ms'] : 5000;
		$config  = array(
			'host'       => $host,
			'port'       => (string) $port,
			'protocol'   => $protocol,
			'verify_ssl' => empty( $payload['verify_ssl'] ) ? '0' : '1',
			'username'   => $username,
			'timeout_ms' => (string) max( 1000, min( 10000, $timeout ) ),
			'read_all'   => empty( $payload['read_all'] ) ? '0' : '1',
		);
		foreach ( array_keys( self::scope_definitions() ) as $scope ) {
			$config[ 'read_' . $scope ] = empty( $payload[ 'read_' . $scope ] ) ? '0' : '1';
		}

		return array(
			'record' => array(
				'type'     => self::CONNECTOR_TYPE,
				'name'     => $name,
				'endpoint' => $host . ':' . $port,
				'config'   => $config,
				'position' => $existing && isset( $existing['position'] ) ? (int) $existing['position'] : 100,
			),
			'secrets' => '' !== $password ? array( 'password' => $password ) : array(),
		);
	}

	private static function test_context( $payload ) {
		if ( ! empty( $payload['probe'] ) ) {
			$record = isset( $payload['record'] ) && is_array( $payload['record'] ) ? $payload['record'] : array();
			if ( self::CONNECTOR_TYPE !== ( isset( $record['type'] ) ? sanitize_key( $record['type'] ) : '' ) ) {
				return new \WP_Error( 'afcn_router_probe_invalid', __( 'The router probe configuration is invalid.', 'airfiber-centralized' ), array( 'status' => 400 ) );
			}
			$record['id']     = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$record['module'] = self::MODULE_ID;
			$provided         = isset( $payload['secrets'] ) && is_array( $payload['secrets'] ) ? $payload['secrets'] : array();
			$secrets          = isset( $provided['password'] ) && is_scalar( $provided['password'] ) && '' !== (string) $provided['password'] ? array( 'password' => (string) $provided['password'] ) : array();
			return array( 'record' => $record, 'secrets' => $secrets );
		}

		$id     = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
		$record = self::connection( $id );
		return is_wp_error( $record ) ? $record : array( 'record' => $record, 'secrets' => array() );
	}

	private static function run_test( $record, $secrets ) {
		$started = microtime( true );
		$result  = RouterOS_API_Client::test( $record, $secrets );
		$latency = round( ( microtime( true ) - $started ) * 1000, 2 );
		Performance_Monitor::record_external( self::MODULE_ID, $latency, 'RouterOS connection test' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['latency_ms'] = $latency;
		return $result;
	}

	private static function connection( $id ) {
		$record = Connection_Store::get( $id );
		if ( ! $record || self::MODULE_ID !== ( isset( $record['module'] ) ? $record['module'] : '' ) || self::CONNECTOR_TYPE !== ( isset( $record['type'] ) ? $record['type'] : '' ) ) {
			return new \WP_Error( 'afcn_router_missing', __( 'The native router connection could not be found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		return $record;
	}

	private static function enabled_scopes( $record ) {
		$config = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		$all    = ! empty( $config['read_all'] ) && '0' !== (string) $config['read_all'];
		$output = array();
		foreach ( array_keys( self::scope_definitions() ) as $scope ) {
			if ( $all || ( ! empty( $config[ 'read_' . $scope ] ) && '0' !== (string) $config[ 'read_' . $scope ] ) ) {
				$output[] = $scope;
			}
		}

		// Interfaces is the default lightweight router drill-down. Keep it first
		// for new routers while still allowing the Core card-order preference to
		// override the visual order after a user arranges the cards.
		if ( in_array( 'interfaces', $output, true ) ) {
			$output = array_merge( array( 'interfaces' ), array_values( array_diff( $output, array( 'interfaces' ) ) ) );
		}
		return $output;
	}

	private static function scope_dataset( $id, $record, $scope, $definition, $refresh = false ) {
		$cache_key = self::scope_cache_key( $id, $scope );
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
				$cached['cache_hit'] = true;
				return $cached;
			}
		}

		$started = microtime( true );
		$result  = RouterOS_API_Client::request( $record, array(), $definition['requests'] );
		$latency = round( ( microtime( true ) - $started ) * 1000, 2 );
		Performance_Monitor::record_external( self::MODULE_ID, $latency, 'RouterOS ' . $scope . ' read' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows      = array();
		$truncated = false;
		foreach ( $definition['requests'] as $request_key => $request ) {
			$response = isset( $result[ $request_key ] ) ? $result[ $request_key ] : array( 'rows' => array(), 'truncated' => false );
			$label    = isset( $request['label'] ) ? $request['label'] : $definition['label'];
			foreach ( (array) ( isset( $response['rows'] ) ? $response['rows'] : array() ) as $row ) {
				$clean = array( 'section' => $label );
				foreach ( $definition['columns'] as $column => $caption ) {
					if ( 'section' !== $column && isset( $row[ $column ] ) ) {
						$clean[ $column ] = $row[ $column ];
					}
				}
				$rows[] = $clean;
			}
			$truncated = $truncated || ! empty( $response['truncated'] );
		}

		$dataset = array(
			'rows'       => $rows,
			'truncated'  => $truncated,
			'latency_ms' => $latency,
			'cached_at'  => time(),
			'cache_hit'  => false,
		);
		set_transient( $cache_key, $dataset, self::SCOPE_CACHE_TTL );
		return $dataset;
	}

	private static function scope_cache_key( $id, $scope ) {
		return 'afcn_rs_' . md5( (string) $id . '|' . (string) $scope );
	}

	private static function clear_scope_cache( $id ) {
		foreach ( array_keys( self::scope_definitions() ) as $scope ) {
			delete_transient( self::scope_cache_key( $id, $scope ) );
		}
	}


	private static function scope_definitions() {
		return array(
			'ppp' => array(
				'label' => __( 'PPP', 'airfiber-centralized' ), 'icon' => 'users', 'description' => __( 'Safe PPP secret metadata, active sessions and profiles; passwords and comments are excluded.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'name' => __( 'Name', 'airfiber-centralized' ), 'service' => __( 'Service', 'airfiber-centralized' ), 'profile' => __( 'Profile', 'airfiber-centralized' ), 'address' => __( 'Address', 'airfiber-centralized' ), 'caller-id' => __( 'Caller ID', 'airfiber-centralized' ), 'uptime' => __( 'Uptime', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ), 'last-logged-out' => __( 'Last logout', 'airfiber-centralized' ), 'local-address' => __( 'Local address', 'airfiber-centralized' ), 'remote-address' => __( 'Remote address', 'airfiber-centralized' ), 'rate-limit' => __( 'Rate limit', 'airfiber-centralized' ), 'parent-queue' => __( 'Parent queue', 'airfiber-centralized' ), 'queue-type' => __( 'Queue type', 'airfiber-centralized' ), 'only-one' => __( 'Only one', 'airfiber-centralized' ) ),
				'requests' => array(
					'secrets' => array( 'label' => __( 'PPP Secrets', 'airfiber-centralized' ), 'words' => array( '/ppp/secret/print', '=.proplist=.id,name,service,profile,disabled,last-logged-out,last-caller-id' ), 'limit' => 100 ),
					'active' => array( 'label' => __( 'PPP Active', 'airfiber-centralized' ), 'words' => array( '/ppp/active/print', '=.proplist=.id,name,service,caller-id,address,uptime' ), 'limit' => 100 ),
					'profiles' => array( 'label' => __( 'PPP Profiles', 'airfiber-centralized' ), 'words' => array( '/ppp/profile/print', '=.proplist=.id,name,local-address,remote-address,rate-limit,parent-queue,queue-type,only-one' ), 'limit' => 100 ),
				),
			),
			'interfaces' => array(
				'label' => __( 'Interfaces', 'airfiber-centralized' ), 'icon' => 'connections', 'description' => __( 'Interface identity, link state, MTU and MAC data.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'name' => __( 'Name', 'airfiber-centralized' ), 'type' => __( 'Type', 'airfiber-centralized' ), 'running' => __( 'Running', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ), 'actual-mtu' => __( 'MTU', 'airfiber-centralized' ), 'mac-address' => __( 'MAC', 'airfiber-centralized' ), 'last-link-up-time' => __( 'Last link up', 'airfiber-centralized' ), 'last-link-down-time' => __( 'Last link down', 'airfiber-centralized' ) ),
				'requests' => array( 'interfaces' => array( 'label' => __( 'Interfaces', 'airfiber-centralized' ), 'words' => array( '/interface/print', '=.proplist=.id,name,type,running,disabled,actual-mtu,mac-address,last-link-up-time,last-link-down-time' ), 'limit' => 150 ) ),
			),
			'scripts' => array(
				'label' => __( 'System scripts', 'airfiber-centralized' ), 'icon' => 'settings', 'description' => __( 'Script names and execution metadata only. Source code is intentionally never returned.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'name' => __( 'Name', 'airfiber-centralized' ), 'owner' => __( 'Owner', 'airfiber-centralized' ), 'policy' => __( 'Policy', 'airfiber-centralized' ), 'run-count' => __( 'Runs', 'airfiber-centralized' ), 'last-started' => __( 'Last started', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ) ),
				'requests' => array( 'scripts' => array( 'label' => __( 'System Scripts', 'airfiber-centralized' ), 'words' => array( '/system/script/print', '=.proplist=.id,name,owner,policy,run-count,last-started,dont-require-permissions,disabled' ), 'limit' => 100 ) ),
			),
			'firewall' => array(
				'label' => __( 'Firewall', 'airfiber-centralized' ), 'icon' => 'shield', 'description' => __( 'Bounded filter, NAT and mangle rule summaries.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'chain' => __( 'Chain', 'airfiber-centralized' ), 'action' => __( 'Action', 'airfiber-centralized' ), 'protocol' => __( 'Protocol', 'airfiber-centralized' ), 'src-address' => __( 'Source', 'airfiber-centralized' ), 'dst-address' => __( 'Destination', 'airfiber-centralized' ), 'dst-port' => __( 'Port', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ), 'bytes' => __( 'Bytes', 'airfiber-centralized' ), 'packets' => __( 'Packets', 'airfiber-centralized' ), 'comment' => __( 'Comment', 'airfiber-centralized' ) ),
				'requests' => array(
					'filter' => array( 'label' => __( 'Filter', 'airfiber-centralized' ), 'words' => array( '/ip/firewall/filter/print', '=.proplist=.id,chain,action,protocol,src-address,dst-address,dst-port,disabled,bytes,packets,comment' ), 'limit' => 100 ),
					'nat' => array( 'label' => __( 'NAT', 'airfiber-centralized' ), 'words' => array( '/ip/firewall/nat/print', '=.proplist=.id,chain,action,protocol,src-address,dst-address,dst-port,disabled,bytes,packets,comment' ), 'limit' => 100 ),
					'mangle' => array( 'label' => __( 'Mangle', 'airfiber-centralized' ), 'words' => array( '/ip/firewall/mangle/print', '=.proplist=.id,chain,action,protocol,src-address,dst-address,dst-port,disabled,bytes,packets,comment' ), 'limit' => 100 ),
				),
			),
			'netwatch' => array(
				'label' => __( 'Netwatch', 'airfiber-centralized' ), 'icon' => 'activity', 'description' => __( 'Probe targets and status without exposing up/down script bodies.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'host' => __( 'Host', 'airfiber-centralized' ), 'type' => __( 'Type', 'airfiber-centralized' ), 'status' => __( 'Status', 'airfiber-centralized' ), 'interval' => __( 'Interval', 'airfiber-centralized' ), 'timeout' => __( 'Timeout', 'airfiber-centralized' ), 'since' => __( 'Since', 'airfiber-centralized' ), 'comment' => __( 'Comment', 'airfiber-centralized' ) ),
				'requests' => array( 'netwatch' => array( 'label' => __( 'Netwatch', 'airfiber-centralized' ), 'words' => array( '/tool/netwatch/print', '=.proplist=.id,host,type,status,interval,timeout,since,comment' ), 'limit' => 100 ) ),
			),
			'logs' => array(
				'label' => __( 'Logs', 'airfiber-centralized' ), 'icon' => 'list', 'description' => __( 'The newest bounded RouterOS log entries.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'time' => __( 'Time', 'airfiber-centralized' ), 'topics' => __( 'Topics', 'airfiber-centralized' ), 'message' => __( 'Message', 'airfiber-centralized' ) ),
				'requests' => array( 'logs' => array( 'label' => __( 'Logs', 'airfiber-centralized' ), 'words' => array( '/log/print', '=.proplist=.id,time,topics,message' ), 'limit' => 100, 'keep_last' => true ) ),
			),
			'ssh' => array(
				'label' => __( 'SSH', 'airfiber-centralized' ), 'icon' => 'shield', 'description' => __( 'SSH server settings and registered public-key fingerprints; private keys are never read.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'user' => __( 'User', 'airfiber-centralized' ), 'key-type' => __( 'Key type', 'airfiber-centralized' ), 'bits' => __( 'Bits', 'airfiber-centralized' ), 'fingerprint' => __( 'Fingerprint', 'airfiber-centralized' ), 'strong-crypto' => __( 'Strong crypto', 'airfiber-centralized' ), 'forwarding-enabled' => __( 'Forwarding', 'airfiber-centralized' ), 'always-allow-password-login' => __( 'Password login', 'airfiber-centralized' ) ),
				'requests' => array(
					'settings' => array( 'label' => __( 'SSH Settings', 'airfiber-centralized' ), 'words' => array( '/ip/ssh/print', '=.proplist=strong-crypto,host-key-size,forwarding-enabled,always-allow-password-login' ), 'limit' => 1 ),
					'keys' => array( 'label' => __( 'SSH Public Keys', 'airfiber-centralized' ), 'words' => array( '/user/ssh-keys/print', '=.proplist=.id,user,key-type,bits,fingerprint' ), 'limit' => 100 ),
				),
			),
			'services' => array(
				'label' => __( 'Services', 'airfiber-centralized' ), 'icon' => 'server', 'description' => __( 'RouterOS IP service ports and exposure settings.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'name' => __( 'Name', 'airfiber-centralized' ), 'port' => __( 'Port', 'airfiber-centralized' ), 'address' => __( 'Allowed address', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ), 'certificate' => __( 'Certificate', 'airfiber-centralized' ), 'tls-version' => __( 'TLS', 'airfiber-centralized' ) ),
				'requests' => array( 'services' => array( 'label' => __( 'IP Services', 'airfiber-centralized' ), 'words' => array( '/ip/service/print', '=.proplist=.id,name,port,address,disabled,certificate,tls-version' ), 'limit' => 50 ) ),
			),
			'hotspot' => array(
				'label' => __( 'Hotspot', 'airfiber-centralized' ), 'icon' => 'users', 'description' => __( 'Hotspot users and active sessions without passwords.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'name' => __( 'Name', 'airfiber-centralized' ), 'user' => __( 'User', 'airfiber-centralized' ), 'server' => __( 'Server', 'airfiber-centralized' ), 'profile' => __( 'Profile', 'airfiber-centralized' ), 'address' => __( 'Address', 'airfiber-centralized' ), 'mac-address' => __( 'MAC', 'airfiber-centralized' ), 'uptime' => __( 'Uptime', 'airfiber-centralized' ), 'disabled' => __( 'Disabled', 'airfiber-centralized' ) ),
				'requests' => array(
					'users' => array( 'label' => __( 'Hotspot Users', 'airfiber-centralized' ), 'words' => array( '/ip/hotspot/user/print', '=.proplist=.id,name,server,profile,address,mac-address,uptime,disabled' ), 'limit' => 100 ),
					'active' => array( 'label' => __( 'Hotspot Active', 'airfiber-centralized' ), 'words' => array( '/ip/hotspot/active/print', '=.proplist=.id,user,server,address,mac-address,uptime' ), 'limit' => 100 ),
				),
			),
			'neighbors' => array(
				'label' => __( 'Neighbors', 'airfiber-centralized' ), 'icon' => 'connections', 'description' => __( 'MikroTik neighbor discovery identity and platform data.', 'airfiber-centralized' ),
				'columns' => array( 'section' => __( 'Section', 'airfiber-centralized' ), 'interface' => __( 'Interface', 'airfiber-centralized' ), 'address' => __( 'Address', 'airfiber-centralized' ), 'mac-address' => __( 'MAC', 'airfiber-centralized' ), 'identity' => __( 'Identity', 'airfiber-centralized' ), 'platform' => __( 'Platform', 'airfiber-centralized' ), 'version' => __( 'Version', 'airfiber-centralized' ), 'board' => __( 'Board', 'airfiber-centralized' ) ),
				'requests' => array( 'neighbors' => array( 'label' => __( 'Neighbors', 'airfiber-centralized' ), 'words' => array( '/ip/neighbor/print', '=.proplist=.id,interface,address,mac-address,identity,platform,version,board' ), 'limit' => 150 ) ),
			),
		);
	}

	private static function browser_snapshot( $connections ) {
		$counts = array(
			'all'          => count( $connections ),
			'online'       => 0,
			'offline'      => 0,
			'warning'      => 0,
			'unconfigured' => 0,
		);
		$health = array();

		foreach ( $connections as $id => $record ) {
			$current       = Connection_Health::get( $id );
			$health[ $id ] = is_array( $current ) ? $current : array();
			$state         = self::browser_state( $health[ $id ] );
			$counts[ $state ]++;
		}

		return array(
			'counts' => $counts,
			'health' => $health,
		);
	}

	private static function browser_state( $health ) {
		$state = isset( $health['state'] ) ? sanitize_key( $health['state'] ) : 'unknown';
		if ( 'online' === $state || 'offline' === $state || 'warning' === $state ) {
			return $state;
		}
		if ( 'error' === $state ) {
			return 'warning';
		}
		return 'unconfigured';
	}

	private static function connection_changed( $existing, $record ) {
		$left  = wp_json_encode( array( isset( $existing['endpoint'] ) ? $existing['endpoint'] : '', isset( $existing['config'] ) ? $existing['config'] : array() ) );
		$right = wp_json_encode( array( isset( $record['endpoint'] ) ? $record['endpoint'] : '', isset( $record['config'] ) ? $record['config'] : array() ) );
		return $left !== $right;
	}

	private static function can_manage_connections() {
		return Capabilities::is_super_admin_user() || current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_CONNECTIONS );
	}

	private static function render_health_metric( $icon, $value, $label, $tooltip_value = '' ) {
		$value         = sanitize_text_field( (string) $value );
		$tooltip_value = sanitize_text_field( (string) $tooltip_value );
		if ( '' === $value && '' === $tooltip_value ) {
			return;
		}

		$trigger = '<span class="afcn-router-health-item">' . Icon::svg( $icon );
		if ( '' !== $value ) {
			$trigger .= '<span>' . esc_html( $value ) . '</span>';
		}
		$trigger .= '</span>';

		$tooltip = $tooltip_value ? $label . ': ' . $tooltip_value : $label . ': ' . $value;
		echo Tooltip::render( $trigger, $tooltip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function health_label( $health ) {
		$state = isset( $health['state'] ) ? sanitize_key( $health['state'] ) : 'unknown';
		if ( 'online' === $state ) { return __( 'Online', 'airfiber-centralized' ); }
		if ( in_array( $state, array( 'warning', 'error', 'offline' ), true ) ) { return __( 'Needs attention', 'airfiber-centralized' ); }
		return __( 'Not checked', 'airfiber-centralized' );
	}

}
