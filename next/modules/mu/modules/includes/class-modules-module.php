<?php

namespace Airfiber\Next\Modules\Modules;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Circuit_Breaker;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\Module_Trash;
use Airfiber\Next\Module_Updates;
use Airfiber\Next\Tooltip;

defined( 'ABSPATH' ) || exit;

class Modules_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$statuses = self::decorate_statuses( Module_Manager::statuses() );
		$counts   = self::counts( $statuses );
		$filters  = array(
			'all'           => __( 'All', 'airfiber-centralized' ),
			'active'        => __( 'Active', 'airfiber-centralized' ),
			'inactive'      => __( 'Inactive', 'airfiber-centralized' ),
			'update'        => __( 'Update Available', 'airfiber-centralized' ),
			'auto-disabled' => __( 'Auto-updates Disabled', 'airfiber-centralized' ),
			'trash'         => __( 'Trash', 'airfiber-centralized' ),
			'mu'            => __( 'MU', 'airfiber-centralized' ),
		);

		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Modules', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Installable modules stay separate from must-use Core components. Module code and optional assets still load only when needed.', 'airfiber-centralized' ); ?></p>
			</div>
			<form data-afcn-action="refresh-registry" data-afcn-module="modules">
				<button type="submit" class="afcn-button afcn-button-secondary"><?php esc_html_e( 'Refresh Registry', 'airfiber-centralized' ); ?></button>
			</form>
		</div>

		<div class="afcn-plugin-browser" data-afcn-module-browser>
			<div class="afcn-plugin-toolbar">
				<nav class="afcn-plugin-filters" aria-label="<?php esc_attr_e( 'Filter modules', 'airfiber-centralized' ); ?>">
					<?php foreach ( $filters as $filter_id => $label ) : ?>
						<button type="button" class="afcn-plugin-filter<?php echo 'all' === $filter_id ? ' is-active' : ''; ?>" data-afcn-module-filter="<?php echo esc_attr( $filter_id ); ?>">
							<?php echo esc_html( $label ); ?>
							<span class="afcn-plugin-filter-count">(<?php echo esc_html( isset( $counts[ $filter_id ] ) ? $counts[ $filter_id ] : 0 ); ?>)</span>
						</button>
					<?php endforeach; ?>
				</nav>

				<label class="afcn-plugin-search">
					<?php echo Icon::svg( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="search" data-afcn-module-search placeholder="<?php esc_attr_e( 'Search installed modules', 'airfiber-centralized' ); ?>">
				</label>
			</div>

			<div class="afcn-module-grid">
				<?php foreach ( $statuses as $id => $status ) : ?>
					<?php self::render_card( $id, $status ); ?>
				<?php endforeach; ?>
			</div>

			<div class="afcn-module-empty" data-afcn-module-empty hidden>
				<?php esc_html_e( 'No modules match this view.', 'airfiber-centralized' ); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function decorate_statuses( $statuses ) {
		foreach ( $statuses as $id => &$status ) {
			$module                         = $status['meta'];
			$status['trashed']              = empty( $module['system'] ) && Module_Trash::is_trashed( $id );
			$status['supports_updates']     = Module_Updates::supports_updates( $module );
			$status['update_available']     = empty( $module['system'] ) && Module_Updates::has_update( $module );
			$status['auto_update_enabled']  = $status['supports_updates'] ? Module_Updates::auto_update_enabled( $id ) : false;
			$status['groups']               = self::groups_for( $status );
		}
		unset( $status );
		return $statuses;
	}

	private static function groups_for( $status ) {
		$module = $status['meta'];
		if ( ! empty( $module['system'] ) ) {
			return array( 'mu' );
		}
		if ( ! empty( $status['trashed'] ) ) {
			return array( 'trash' );
		}

		$groups = array( 'all', ! empty( $status['enabled'] ) ? 'active' : 'inactive' );
		if ( ! empty( $status['update_available'] ) ) {
			$groups[] = 'update';
		}
		if ( ! empty( $status['supports_updates'] ) && empty( $status['auto_update_enabled'] ) ) {
			$groups[] = 'auto-disabled';
		}
		return $groups;
	}

	private static function counts( $statuses ) {
		$counts = array(
			'all'           => 0,
			'active'        => 0,
			'inactive'      => 0,
			'update'        => 0,
			'auto-disabled' => 0,
			'trash'         => 0,
			'mu'            => 0,
		);

		foreach ( $statuses as $status ) {
			foreach ( $status['groups'] as $group ) {
				if ( isset( $counts[ $group ] ) ) {
					$counts[ $group ]++;
				}
			}
		}
		return $counts;
	}

	private static function render_card( $id, $status ) {
		$module   = $status['meta'];
		$health   = $status['health'];
		$is_mu    = ! empty( $module['system'] );
		$trashed  = ! empty( $status['trashed'] );
		$enabled  = ! empty( $status['enabled'] );
		$groups   = implode( ' ', $status['groups'] );
		$search   = strtolower( $module['name'] . ' ' . $module['description'] . ' ' . $id );
		$classes  = array( 'afcn-module-card' );

		if ( $is_mu ) {
			$classes[] = 'is-mu';
		} elseif ( $trashed ) {
			$classes[] = 'is-trashed';
		} elseif ( ! $enabled ) {
			$classes[] = 'is-inactive';
		}

		$health_label = sprintf(
			/* translators: 1: health state, 2: p50 milliseconds, 3: p95 milliseconds, 4: max queries */
			__( '%1$s · p50 %2$s ms · p95 %3$s ms · %4$s max queries', 'airfiber-centralized' ),
			ucfirst( $health['status'] ),
			$health['p50_ms'],
			$health['p95_ms'],
			$health['max_queries']
		);
		?>
		<article class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-afcn-module-card data-afcn-groups="<?php echo esc_attr( $groups ); ?>" data-afcn-search="<?php echo esc_attr( $search ); ?>">
			<div class="afcn-module-card-head">
				<?php
				$health_dot = '<span class="afcn-module-card-health is-' . esc_attr( sanitize_html_class( $health['status'] ) ) . '" aria-hidden="true"></span>';
				echo Tooltip::render( $health_dot, $health_label, array( 'direction' => 'down' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<div class="afcn-module-card-badges">
					<?php if ( $is_mu ) : ?><span class="afcn-module-card-badge is-mu"><?php esc_html_e( 'MU', 'airfiber-centralized' ); ?></span><?php endif; ?>
					<?php if ( ! empty( $status['update_available'] ) ) : ?><span class="afcn-module-card-badge is-update"><?php esc_html_e( 'Update', 'airfiber-centralized' ); ?></span><?php endif; ?>
				</div>
			</div>

			<h3><?php echo esc_html( $module['name'] ); ?></h3>
			<p class="afcn-module-card-description"><?php echo esc_html( $module['description'] ); ?></p>
			<div class="afcn-module-card-meta">
				<span>v<?php echo esc_html( $module['version'] ); ?></span>
				<span><?php echo $is_mu ? esc_html__( 'Core', 'airfiber-centralized' ) : ( $trashed ? esc_html__( 'Trash', 'airfiber-centralized' ) : ( $enabled ? esc_html__( 'Active', 'airfiber-centralized' ) : esc_html__( 'Inactive', 'airfiber-centralized' ) ) ); ?></span>
			</div>

			<div class="afcn-module-card-actions" aria-label="<?php echo esc_attr( sprintf( __( '%s actions', 'airfiber-centralized' ), $module['name'] ) ); ?>">
				<?php
				if ( $is_mu ) {
					if ( ! empty( $module['settings'] ) ) {
						echo self::settings_control( $module['settings'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				} elseif ( $trashed ) {
					echo self::action_form( $id, 'restore', 'restore', __( 'Restore', 'airfiber-centralized' ), array(), 'is-success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					if ( $enabled ) {
						echo self::action_form( $id, 'toggle', 'x', __( 'Deactivate', 'airfiber-centralized' ), array( 'enabled' => '0' ), 'is-danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo self::action_form( $id, 'toggle', 'check', __( 'Activate', 'airfiber-centralized' ), array( 'enabled' => '1' ), 'is-success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}

					if ( ! empty( $module['settings'] ) ) {
						echo self::settings_control( $module['settings'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}

					if ( ! $enabled ) {
						echo self::action_form( $id, 'trash', 'trash', __( 'Move to Trash', 'airfiber-centralized' ), array(), 'is-danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}
				?>
			</div>
		</article>
		<?php
	}

	private static function action_form( $module_id, $action, $icon, $label, $fields = array(), $class = '' ) {
		$button  = '<button type="submit" class="afcn-module-action ' . esc_attr( $class ) . '" aria-label="' . esc_attr( $label ) . '">' . Icon::svg( $icon ) . '</button>';
		$html    = '<form data-afcn-action="' . esc_attr( $action ) . '" data-afcn-module="modules">';
		$html   .= '<input type="hidden" name="module_id" value="' . esc_attr( $module_id ) . '">';
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$html .= Tooltip::render( $button, $label );
		$html .= '</form>';
		return $html;
	}

	private static function settings_control( $module_id ) {
		$label  = __( 'Settings', 'airfiber-centralized' );
		$button = '<button type="button" class="afcn-module-action" data-afcn-open-module="' . esc_attr( $module_id ) . '" aria-label="' . esc_attr( $label ) . '">' . Icon::svg( 'gear' ) . '</button>';
		return Tooltip::render( $button, $label );
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_MODULES ) ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot manage modules.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$module_id = isset( $payload['module_id'] ) ? sanitize_key( $payload['module_id'] ) : '';

		if ( 'toggle' === $action ) {
			$enabled = ! empty( $payload['enabled'] ) && '0' !== (string) $payload['enabled'];
			$result  = Module_Manager::set_enabled( $module_id, $enabled );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['message']     = $enabled ? __( 'Module activated.', 'airfiber-centralized' ) : __( 'Module deactivated.', 'airfiber-centralized' );
			$result['refresh_nav'] = true;
			return $result;
		}

		if ( 'trash' === $action ) {
			$module = Module_Registry::get( $module_id );
			if ( ! $module ) {
				return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
			}
			if ( ! empty( $module['system'] ) ) {
				return new \WP_Error( 'afcn_mu_module', __( 'Core MU components cannot be moved to Trash.', 'airfiber-centralized' ), array( 'status' => 400 ) );
			}
			if ( Module_Manager::is_enabled( $module_id, $module ) ) {
				return new \WP_Error( 'afcn_module_active', __( 'Deactivate this module before moving it to Trash.', 'airfiber-centralized' ), array( 'status' => 409 ) );
			}
			$result = Module_Trash::trash( $module_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'message' => __( 'Module moved to Trash.', 'airfiber-centralized' ) );
		}

		if ( 'restore' === $action ) {
			Module_Trash::restore( $module_id );
			return array( 'message' => __( 'Module restored. It remains inactive until you activate it.', 'airfiber-centralized' ) );
		}

		if ( 'reset-health' === $action ) {
			Circuit_Breaker::reset( $module_id );
			return array( 'message' => __( 'Module health state reset.', 'airfiber-centralized' ) );
		}

		if ( 'refresh-registry' === $action ) {
			Module_Registry::invalidate();
			Module_Registry::all( true );
			return array(
				'message'     => __( 'Module registry refreshed from disk.', 'airfiber-centralized' ),
				'refresh_nav' => true,
			);
		}

		return new \WP_Error( 'afcn_unknown_action', __( 'Unknown module action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
