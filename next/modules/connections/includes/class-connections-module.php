<?php

namespace Airfiber\Next\Modules\Connections;

use Airfiber\Next\Bootstrap;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Connection_Health;
use Airfiber\Next\Connection_Store;
use Airfiber\Next\Connector_Registry;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Secret_Store;
use Airfiber\Next\Tooltip;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Connections_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$cards      = array_merge( self::beta_cards(), Legacy_Connections::cards() );
		$types      = self::available_types();
		$can_manage = self::can_manage();
		$counts     = self::counts( $cards );
		$grouped    = self::group_cards( $cards );

		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Connections', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Routers, OLTs, cloud services and other endpoints in one place. Status is cache-first; device checks only run when explicitly requested.', 'airfiber-centralized' ); ?></p>
			</div>
			<div class="afcn-connections-head-actions">
				<form data-afcn-module="connections" data-afcn-action="refresh">
					<button type="submit" class="afcn-button afcn-button-secondary"><?php echo Icon::svg( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Refresh', 'airfiber-centralized' ); ?></button>
				</form>
				<?php if ( $can_manage && $types ) : ?>
					<button type="button" class="afcn-button afcn-button-primary" data-afcn-dialog-open="afcn-add-connection-dialog"><?php echo Icon::svg( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Add Connection', 'airfiber-centralized' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<div class="afcn-connections-browser" data-afcn-connections-browser>
			<div class="afcn-connections-toolbar">
				<nav class="afcn-connection-filters" aria-label="<?php esc_attr_e( 'Filter connections', 'airfiber-centralized' ); ?>">
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
					<input type="search" data-afcn-connection-search placeholder="<?php esc_attr_e( 'Search connections', 'airfiber-centralized' ); ?>">
				</label>
			</div>

			<div class="afcn-connection-groups">
				<?php foreach ( $grouped as $group => $group_cards ) : ?>
					<section class="afcn-connection-group" data-afcn-connection-group>
						<div class="afcn-connection-group-heading">
							<h2><?php echo esc_html( self::group_label( $group ) ); ?></h2>
							<span><?php echo esc_html( count( $group_cards ) ); ?></span>
						</div>
						<div class="afcn-connection-grid">
							<?php foreach ( $group_cards as $card ) : ?>
								<?php self::render_card( $card, $can_manage ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="afcn-connections-empty" data-afcn-connections-empty<?php echo $cards ? ' hidden' : ''; ?>>
				<?php if ( $types ) : ?>
					<?php esc_html_e( 'No connections yet. Add one from an installed connector type.', 'airfiber-centralized' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No connections yet. Connector types will appear here as feature modules are migrated to Airfiber BETA.', 'airfiber-centralized' ); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		if ( $can_manage && $types ) {
			self::render_add_dialog( $types );
		}
		if ( $can_manage ) {
			foreach ( Connection_Store::all() as $record ) {
				self::render_edit_dialog( $record );
			}
		}
		return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( 'refresh' === $action ) {
			return array( 'message' => __( 'Connection cards refreshed from cached state.', 'airfiber-centralized' ) );
		}

		if ( ! self::can_manage() ) {
			return new \WP_Error( 'afcn_connections_forbidden', __( 'You cannot manage Airfiber connections.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		if ( 'create-connection' === $action ) {
			$type     = isset( $payload['connector_type'] ) ? sanitize_key( $payload['connector_type'] ) : '';
			$prepared = self::prepare_record( $type, $payload );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$result = Connection_Store::create( $prepared['record'], $prepared['secrets'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'message' => __( 'Connection created.', 'airfiber-centralized' ) );
		}

		if ( 'update-connection' === $action ) {
			$id       = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$existing = Connection_Store::get( $id );
			if ( ! $existing ) {
				return new \WP_Error( 'afcn_connection_missing', __( 'Connection not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
			}
			$prepared = self::prepare_record( $existing['type'], $payload, $existing );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$result = Connection_Store::update( $id, $prepared['record'], $prepared['secrets'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'message' => __( 'Connection updated.', 'airfiber-centralized' ) );
		}

		if ( 'delete-connection' === $action ) {
			$id     = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$result = Connection_Store::delete( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'message' => __( 'Connection deleted.', 'airfiber-centralized' ) );
		}

		if ( 'test-connection' === $action ) {
			return self::test_connection( isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '' );
		}

		if ( 'probe-connection' === $action ) {
			return self::probe_connection( $payload );
		}

		return new \WP_Error( 'afcn_connections_action', __( 'Unknown connection action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}

	private static function beta_cards() {
		$output = array();
		foreach ( Connection_Store::all() as $id => $record ) {
			$type   = Connector_Registry::get( $record['type'] );
			$health = Connection_Health::get( $id );
			$state  = isset( $health['state'] ) ? $health['state'] : 'unknown';
			if ( 'error' === $state ) {
				$state = 'warning';
			}

			$output[] = array(
				'key'         => $id,
				'id'          => $id,
				'type'        => $record['type'],
				'provider'    => $type ? $type['name'] : $record['type'],
				'group'       => ! empty( $record['group'] ) ? $record['group'] : ( $type && ! empty( $type['group'] ) ? $type['group'] : 'other' ),
				'icon'        => $type && ! empty( $type['icon'] ) ? $type['icon'] : 'plug',
				'name'        => $record['name'],
				'subtitle'    => self::display_value( $record, $type, 'endpoint' ),
				'endpoint'    => isset( $record['endpoint'] ) ? $record['endpoint'] : '',
				'meta'        => self::display_value( $record, $type, 'meta' ),
				'state'       => $state,
				'status'      => self::state_label( $state ),
				'checked_at'  => isset( $health['checked_at'] ) ? absint( $health['checked_at'] ) : 0,
				'latency_ms'  => isset( $health['latency_ms'] ) ? (float) $health['latency_ms'] : 0,
				'source'      => 'beta',
				'readonly'    => false,
				'description' => $type && ! empty( $type['description'] ) ? $type['description'] : __( 'Airfiber BETA connection.', 'airfiber-centralized' ),
				'test_action' => $type && ! empty( $type['test_action'] ) ? $type['test_action'] : '',
			);
		}
		return $output;
	}

	private static function available_types() {
		$output = array();
		foreach ( Connector_Registry::all() as $id => $type ) {
			$module = ! empty( $type['module'] ) ? \Airfiber\Next\Module_Registry::get( $type['module'] ) : null;
			if ( $module && Module_Manager::is_enabled( $type['module'], $module ) ) {
				$output[ $id ] = $type;
			}
		}
		return $output;
	}

	private static function prepare_record( $type_id, $payload, $existing = null, $allow_default_name = false ) {
		$type = Connector_Registry::get( $type_id );
		if ( ! $type ) {
			return new \WP_Error( 'afcn_connector_unknown', __( 'The selected connector type is not available.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$name = isset( $payload['connection_name'] ) ? sanitize_text_field( $payload['connection_name'] ) : '';
		if ( '' === $name && $allow_default_name ) {
			$name = $existing && ! empty( $existing['name'] ) ? $existing['name'] : $type['name'];
		}
		if ( '' === $name ) {
			return new \WP_Error( 'afcn_connection_name', __( 'Connection name is required.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$config   = $existing && isset( $existing['config'] ) && is_array( $existing['config'] ) ? $existing['config'] : array();
		$secrets  = array();
		$endpoint = $existing && isset( $existing['endpoint'] ) ? $existing['endpoint'] : '';
		$fields   = isset( $type['fields'] ) && is_array( $type['fields'] ) ? $type['fields'] : array();
		$is_edit  = $existing && ! empty( $existing['id'] );

		foreach ( $fields as $field ) {
			$key       = $field['key'];
			$has_value = array_key_exists( $key, $payload );

			/* Disabled conditional edit fields are omitted; keep their saved value. */
			if ( ! $has_value && $is_edit && ! empty( $field['show_when'] ) ) {
				continue;
			}

			$raw   = $has_value ? $payload[ $key ] : ( 'checkbox' === $field['type'] ? '0' : '' );
			$value = Connector_Registry::sanitize_field_value( $field, $raw );

			if ( ! empty( $field['secret'] ) ) {
				if ( '' !== (string) $raw ) {
					$secrets[ $key ] = (string) $raw;
				}
				if ( ! empty( $field['required'] ) && '' === (string) $raw && ( ! $is_edit || '' === Secret_Store::get( $existing['id'], $key, '' ) ) ) {
					return new \WP_Error( 'afcn_connection_required', sprintf( __( '%s is required.', 'airfiber-centralized' ), $field['label'] ), array( 'status' => 400 ) );
				}
				continue;
			}

			if ( ! empty( $field['required'] ) && '' === (string) $value ) {
				return new \WP_Error( 'afcn_connection_required', sprintf( __( '%s is required.', 'airfiber-centralized' ), $field['label'] ), array( 'status' => 400 ) );
			}
			$config[ $key ] = $value;
			if ( 'endpoint' === $field['display'] ) {
				$endpoint = $value;
			}
		}

		return array(
			'record' => array(
				'type'     => $type_id,
				'name'     => $name,
				'endpoint' => $endpoint,
				'config'   => $config,
				'position' => $existing && isset( $existing['position'] ) ? (int) $existing['position'] : 100,
			),
			'secrets' => $secrets,
		);
	}

	private static function test_connection( $id ) {
		$record = Connection_Store::get( $id );
		if ( ! $record ) {
			return new \WP_Error( 'afcn_connection_missing', __( 'Connection not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		$type = Connector_Registry::get( $record['type'] );
		if ( ! $type || empty( $type['module'] ) || empty( $type['test_action'] ) ) {
			return new \WP_Error( 'afcn_connection_test_unsupported', __( 'This connector does not expose a connection test yet.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$result = Module_Manager::handle_action( $type['module'], $type['test_action'], array( 'connection_id' => $id ) );
		if ( is_wp_error( $result ) ) {
			Connection_Health::set( $id, array( 'state' => 'warning', 'message' => $result->get_error_message() ) );
			return $result;
		}

		$health = is_array( $result ) ? $result : array();
		Connection_Health::set(
			$id,
			array(
				'state'      => isset( $health['state'] ) ? $health['state'] : 'online',
				'message'    => isset( $health['message'] ) ? $health['message'] : __( 'Connection test completed.', 'airfiber-centralized' ),
				'latency_ms' => isset( $health['latency_ms'] ) ? $health['latency_ms'] : 0,
				'details'    => isset( $health['details'] ) && is_array( $health['details'] ) ? $health['details'] : array(),
			)
		);
		return array( 'message' => isset( $health['message'] ) ? $health['message'] : __( 'Connection test completed.', 'airfiber-centralized' ) );
	}

	/** Test the values currently visible in a dialog without saving them. */
	private static function probe_connection( $payload ) {
		$id       = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
		$existing = $id ? Connection_Store::get( $id ) : null;
		$type_id  = $existing && ! empty( $existing['type'] )
			? $existing['type']
			: ( isset( $payload['connector_type'] ) ? sanitize_key( $payload['connector_type'] ) : '' );
		$type     = Connector_Registry::get( $type_id );

		if ( ! $type || empty( $type['module'] ) || empty( $type['test_action'] ) ) {
			return new \WP_Error( 'afcn_connection_test_unsupported', __( 'This connector does not expose a connection test yet.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$prepared = self::prepare_record( $type_id, $payload, $existing, true );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$record           = $prepared['record'];
		$record['id']     = $id;
		$record['module'] = $type['module'];
		$result           = Module_Manager::handle_action(
			$type['module'],
			$type['test_action'],
			array(
				'connection_id' => $id,
				'record'        => $record,
				'secrets'       => $prepared['secrets'],
				'probe'         => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'connected' => true,
			'reload'    => false,
			'message'   => is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : __( 'Connection succeeded.', 'airfiber-centralized' ),
		);
	}

	private static function render_card( $card, $can_manage ) {
		$state       = in_array( $card['state'], array( 'online', 'offline', 'warning', 'unconfigured', 'unknown' ), true ) ? $card['state'] : 'unknown';
		$filter      = in_array( $state, array( 'online', 'offline', 'warning' ), true ) ? $state : 'unconfigured';
		$search      = strtolower( implode( ' ', array( $card['name'], $card['provider'], $card['subtitle'], $card['endpoint'], $card['meta'], $card['group'] ) ) );
		$status_info = $card['status'];
		if ( ! empty( $card['latency_ms'] ) ) {
			$status_info .= ' · ' . round( $card['latency_ms'], 1 ) . ' ms';
		}
		if ( ! empty( $card['checked_at'] ) ) {
			$status_info .= ' · ' . sprintf( __( 'checked %s ago', 'airfiber-centralized' ), human_time_diff( $card['checked_at'], time() ) );
		}
		?>
		<article class="afcn-card afcn-connection-card is-<?php echo esc_attr( $state ); ?>" data-afcn-connection-card data-afcn-state="<?php echo esc_attr( $filter ); ?>" data-afcn-search="<?php echo esc_attr( $search ); ?>">
			<div class="afcn-connection-card-top">
				<span class="afcn-connection-icon"><?php echo Icon::svg( $card['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="afcn-connection-card-badges">
					<?php
					$provider = '<span class="afcn-connection-provider">' . esc_html( $card['provider'] ) . '</span>';
					echo Tooltip::render( $provider, $card['description'], array( 'direction' => 'down' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					if ( 'classic' === $card['source'] ) :
						?> <span class="afcn-connection-source">CLASSIC</span><?php
					endif;
					?>
				</div>
			</div>
			<h3><?php echo esc_html( $card['name'] ); ?></h3>
			<?php if ( $card['subtitle'] ) : ?><p class="afcn-connection-subtitle"><?php echo esc_html( $card['subtitle'] ); ?></p><?php endif; ?>
			<?php if ( $card['meta'] ) : ?><p class="afcn-connection-meta"><?php echo esc_html( $card['meta'] ); ?></p><?php endif; ?>
			<div class="afcn-connection-card-bottom">
				<?php
				$status_dot = '<span class="afcn-connection-status-dot is-' . esc_attr( $state ) . '" aria-hidden="true"></span><span>' . esc_html( $card['status'] ) . '</span>';
				echo Tooltip::render( $status_dot, $status_info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<div class="afcn-connection-actions">
					<?php if ( 'classic' === $card['source'] ) : ?>
						<?php
						$link = '<a class="afcn-connection-action" href="' . esc_url( Bootstrap::classic_url() ) . '" aria-label="' . esc_attr__( 'Manage in Classic', 'airfiber-centralized' ) . '">' . Icon::svg( 'gear' ) . '</a>';
						echo Tooltip::render( $link, __( 'Manage in Classic', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php elseif ( $can_manage ) : ?>
						<?php if ( ! empty( $card['test_action'] ) ) : ?>
							<form data-afcn-module="connections" data-afcn-action="test-connection"><input type="hidden" name="connection_id" value="<?php echo esc_attr( $card['id'] ); ?>"><?php echo Tooltip::render( '<button type="submit" class="afcn-connection-action" aria-label="' . esc_attr__( 'Test connection', 'airfiber-centralized' ) . '">' . Icon::svg( 'activity' ) . '</button>', __( 'Test connection', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></form>
						<?php endif; ?>
						<?php echo Tooltip::render( '<button type="button" class="afcn-connection-action" data-afcn-dialog-open="afcn-connection-' . esc_attr( $card['id'] ) . '" aria-label="' . esc_attr__( 'Connection settings', 'airfiber-centralized' ) . '">' . Icon::svg( 'gear' ) . '</button>', __( 'Connection settings', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<form data-afcn-module="connections" data-afcn-action="delete-connection" data-afcn-confirm="<?php esc_attr_e( 'Delete this BETA connection?', 'airfiber-centralized' ); ?>"><input type="hidden" name="connection_id" value="<?php echo esc_attr( $card['id'] ); ?>"><?php echo Tooltip::render( '<button type="submit" class="afcn-connection-action is-danger" aria-label="' . esc_attr__( 'Delete connection', 'airfiber-centralized' ) . '">' . Icon::svg( 'trash' ) . '</button>', __( 'Delete connection', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></form>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	private static function render_add_dialog( $types ) {
		$can_probe = self::has_testable_type( $types );
		?>
		<dialog class="afcn-dialog" id="afcn-add-connection-dialog">
			<form method="dialog" class="afcn-dialog-shell" data-afcn-module="connections" data-afcn-action="create-connection" data-afcn-connection-form>
				<div class="afcn-dialog-header"><div><h2><?php esc_html_e( 'Add Connection', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Choose a connector supplied by an active Airfiber module.', 'airfiber-centralized' ); ?></p></div><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div>
				<div class="afcn-dialog-body">
					<div class="afcn-form-grid">
						<label class="afcn-field"><span><?php esc_html_e( 'Connector type', 'airfiber-centralized' ); ?></span><select class="afcn-select" name="connector_type" data-afcn-connector-type required><option value=""><?php esc_html_e( 'Select connector', 'airfiber-centralized' ); ?></option><?php foreach ( $types as $id => $type ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $type['name'] ); ?></option><?php endforeach; ?></select></label>
						<?php echo UI::field( 'connection_name', __( 'Connection name', 'airfiber-centralized' ), array( 'required' => true, 'placeholder' => __( 'e.g. Main Router', 'airfiber-centralized' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php foreach ( $types as $id => $type ) : ?><div class="afcn-connector-fields" data-afcn-connector-fields="<?php echo esc_attr( $id ); ?>" data-afcn-connector-testable="<?php echo ! empty( $type['test_action'] ) ? '1' : '0'; ?>" hidden><?php self::render_fields( $type, array(), false ); ?></div><?php endforeach; ?>
				</div>
				<div class="afcn-dialog-footer">
					<button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
					<?php if ( $can_probe ) : ?><button type="button" class="afcn-button afcn-button-secondary" data-afcn-connection-probe disabled><?php esc_html_e( 'Connect', 'airfiber-centralized' ); ?></button><?php endif; ?>
					<button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Connection', 'airfiber-centralized' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	private static function render_edit_dialog( $record ) {
		$type = Connector_Registry::get( $record['type'] );
		if ( ! $type ) {
			return;
		}
		$health    = Connection_Health::get( $record['id'] );
		$connected = 'online' === ( isset( $health['state'] ) ? $health['state'] : '' );
		?>
		<dialog class="afcn-dialog" id="afcn-connection-<?php echo esc_attr( $record['id'] ); ?>">
			<form method="dialog" class="afcn-dialog-shell" data-afcn-module="connections" data-afcn-action="update-connection" data-afcn-connection-form>
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $record['id'] ); ?>">
				<div class="afcn-dialog-header"><div><h2><?php echo esc_html( $record['name'] ); ?></h2><p><?php echo esc_html( $type['name'] ); ?></p></div><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div>
				<div class="afcn-dialog-body">
					<?php echo UI::field( 'connection_name', __( 'Connection name', 'airfiber-centralized' ), array( 'value' => $record['name'], 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="afcn-connector-fields is-visible" data-afcn-connector-fields="<?php echo esc_attr( $record['type'] ); ?>" data-afcn-connector-testable="<?php echo ! empty( $type['test_action'] ) ? '1' : '0'; ?>"><?php self::render_fields( $type, isset( $record['config'] ) ? $record['config'] : array(), true ); ?></div>
				</div>
				<div class="afcn-dialog-footer">
					<button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
					<?php if ( ! empty( $type['test_action'] ) ) : ?><button type="button" class="afcn-button afcn-button-secondary" data-afcn-connection-probe data-afcn-connected="<?php echo $connected ? '1' : '0'; ?>"><?php echo esc_html( $connected ? __( 'Connected', 'airfiber-centralized' ) : __( 'Connect', 'airfiber-centralized' ) ); ?></button><?php endif; ?>
					<button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Changes', 'airfiber-centralized' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	private static function render_fields( $type, $config, $is_edit ) {
		$fields = isset( $type['fields'] ) && is_array( $type['fields'] ) ? $type['fields'] : array();
		if ( ! $fields ) {
			echo '<p class="afcn-connector-no-fields">' . esc_html__( 'This connector does not require additional fields.', 'airfiber-centralized' ) . '</p>';
			return;
		}
		echo '<div class="afcn-form-grid">';
		foreach ( $fields as $field ) {
			$key         = $field['key'];
			$value       = ! empty( $field['secret'] ) ? '' : ( isset( $config[ $key ] ) ? $config[ $key ] : '' );
			$placeholder = $is_edit && ! empty( $field['secret'] ) ? __( 'Leave blank to keep current value', 'airfiber-centralized' ) : $field['placeholder'];
			$show_when   = isset( $field['show_when'] ) && is_array( $field['show_when'] ) ? $field['show_when'] : array();
			$attributes  = '';
			if ( ! empty( $show_when['field'] ) && isset( $show_when['value'] ) ) {
				$attributes = ' data-afcn-show-when-field="' . esc_attr( $show_when['field'] ) . '" data-afcn-show-when-value="' . esc_attr( $show_when['value'] ) . '"';
			}
			echo '<div class="afcn-connector-field"' . $attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( 'select' === $field['type'] ) {
				echo UI::select( $key, $field['label'], $field['options'], $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'checkbox' === $field['type'] ) {
				echo '<label class="afcn-field afcn-checkbox-field"><span>' . esc_html( $field['label'] ) . '</span><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( '1', (string) $value, false ) . '></label>';
			} else {
				echo UI::field(
					$key,
					$field['label'],
					array(
						'type'        => $field['type'],
						'value'       => $value,
						'placeholder' => $placeholder,
						'required'    => ! empty( $field['required'] ) && ! ( $is_edit && ! empty( $field['secret'] ) ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function has_testable_type( $types ) {
		foreach ( $types as $type ) {
			if ( ! empty( $type['test_action'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function display_value( $record, $type, $display ) {
		if ( 'endpoint' === $display && ! empty( $record['endpoint'] ) ) {
			return $record['endpoint'];
		}
		if ( ! $type || empty( $type['fields'] ) || empty( $record['config'] ) ) {
			return '';
		}
		foreach ( $type['fields'] as $field ) {
			if ( isset( $field['display'] ) && $display === $field['display'] && empty( $field['secret'] ) && isset( $record['config'][ $field['key'] ] ) ) {
				return sanitize_text_field( (string) $record['config'][ $field['key'] ] );
			}
		}
		return '';
	}

	private static function counts( $cards ) {
		$counts = array( 'all' => count( $cards ), 'online' => 0, 'offline' => 0, 'warning' => 0, 'unconfigured' => 0 );
		foreach ( $cards as $card ) {
			$state = isset( $card['state'] ) ? $card['state'] : 'unknown';
			if ( isset( $counts[ $state ] ) ) {
				$counts[ $state ]++;
			} else {
				$counts['unconfigured']++;
			}
		}
		return $counts;
	}

	private static function group_cards( $cards ) {
		$order  = array( 'network' => 10, 'cloud' => 20, 'payments' => 30, 'messaging' => 40, 'storage' => 50, 'other' => 100 );
		$groups = array();
		foreach ( $cards as $card ) {
			$group = ! empty( $card['group'] ) ? sanitize_key( $card['group'] ) : 'other';
			$groups[ $group ][] = $card;
		}
		uksort(
			$groups,
			function ( $left, $right ) use ( $order ) {
				$lp = isset( $order[ $left ] ) ? $order[ $left ] : 90;
				$rp = isset( $order[ $right ] ) ? $order[ $right ] : 90;
				return $lp === $rp ? strcasecmp( $left, $right ) : ( $lp < $rp ? -1 : 1 );
			}
		);
		return $groups;
	}

	private static function group_label( $group ) {
		$labels = array(
			'network'   => __( 'Network', 'airfiber-centralized' ),
			'cloud'     => __( 'Cloud & Integrations', 'airfiber-centralized' ),
			'payments'  => __( 'Payments', 'airfiber-centralized' ),
			'messaging' => __( 'Messaging', 'airfiber-centralized' ),
			'storage'   => __( 'Storage', 'airfiber-centralized' ),
			'other'     => __( 'Other', 'airfiber-centralized' ),
		);
		return isset( $labels[ $group ] ) ? $labels[ $group ] : ucwords( str_replace( '-', ' ', $group ) );
	}

	private static function state_label( $state ) {
		switch ( $state ) {
			case 'online': return __( 'Connected', 'airfiber-centralized' );
			case 'offline': return __( 'Offline', 'airfiber-centralized' );
			case 'warning': return __( 'Needs attention', 'airfiber-centralized' );
			case 'unconfigured': return __( 'Not configured', 'airfiber-centralized' );
			default: return __( 'Not checked', 'airfiber-centralized' );
		}
	}

	private static function can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_CONNECTIONS );
	}
}
