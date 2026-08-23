<?php

namespace Airfiber\Next\Modules\Users;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Tooltip;
use Airfiber\Next\UI;
use Airfiber\Next\User_Access;
use Airfiber\Next\User_Manager;

defined( 'ABSPATH' ) || exit;

class Users_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$users          = User_Manager::list_users();
		$roles          = Capabilities::assignable_roles();
		$is_super_admin = User_Manager::is_super_admin();
		$modules        = User_Access::assignable_modules( false );
		$mu_modules     = $is_super_admin ? User_Access::assignable_modules( true ) : array();
		$mu_modules     = array_filter(
			$mu_modules,
			function ( $module ) {
				return ! empty( $module['system'] );
			}
		);

		$data = array(
			'users'   => $users,
			'modules' => array_keys( $modules ),
		);

		ob_start();
		?>
		<div data-afcn-users>
			<div class="afcn-page-head">
				<div>
					<div class="afcn-users-title-row">
						<h1 class="afcn-page-title"><?php esc_html_e( 'Airfiber Users', 'airfiber-centralized' ); ?></h1>
						<?php
						$view_button = '<button type="button" class="afcn-users-view-toggle" data-afcn-users-view-toggle aria-label="' . esc_attr__( 'Show list', 'airfiber-centralized' ) . '"><span class="afcn-users-list-icon">' . Icon::svg( 'list' ) . '</span><span class="afcn-users-grid-icon">' . Icon::svg( 'grid' ) . '</span></button>';
						echo Tooltip::render( $view_button, __( 'Toggle cards / list', 'airfiber-centralized' ), array( 'direction' => 'down' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
					<p class="afcn-page-description"><?php esc_html_e( 'Roles control authority. Menu visibility can further limit which normal Airfiber modules a user sees and can open.', 'airfiber-centralized' ); ?></p>
				</div>
				<button type="button" class="afcn-button afcn-button-primary" data-afcn-dialog-open="afcn-add-user-dialog"><?php esc_html_e( 'Add User', 'airfiber-centralized' ); ?></button>
			</div>

			<?php if ( empty( $users ) ) : ?>
				<div class="afcn-user-empty"><?php esc_html_e( 'No Airfiber users found.', 'airfiber-centralized' ); ?></div>
			<?php else : ?>
				<div class="afcn-user-grid" data-afcn-user-grid>
					<?php foreach ( $users as $user ) : ?>
						<?php self::render_user_card( $user ); ?>
					<?php endforeach; ?>
				</div>

				<div class="afcn-user-list" data-afcn-user-list hidden>
					<?php foreach ( $users as $user ) : ?>
						<?php self::render_user_list_row( $user ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<script type="application/json" data-afcn-users-data><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>

			<dialog class="afcn-dialog" id="afcn-add-user-dialog">
				<form data-afcn-action="create-user" data-afcn-module="users">
					<div class="afcn-dialog-header">
						<h2><?php esc_html_e( 'Add Airfiber User', 'airfiber-centralized' ); ?></h2>
						<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button>
					</div>
					<div class="afcn-dialog-body">
						<div class="afcn-form-grid">
							<?php echo UI::field( 'username', __( 'Username', 'airfiber-centralized' ), array( 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::field( 'display_name', __( 'Display name', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::field( 'email', __( 'Email', 'airfiber-centralized' ), array( 'type' => 'email', 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::select( 'role', __( 'Role', 'airfiber-centralized' ), $roles, 'airfiber_operator' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div style="grid-column:1/-1"><?php echo UI::field( 'password', __( 'Password', 'airfiber-centralized' ), array( 'type' => 'password', 'placeholder' => __( 'Leave blank to generate a strong password', 'airfiber-centralized' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						</div>
						<div class="afcn-user-access-section">
							<h3><?php esc_html_e( 'Menu visibility', 'airfiber-centralized' ); ?></h3>
							<p class="afcn-user-access-help"><?php esc_html_e( 'All normal modules are visible by default. Uncheck anything this user should not see or open.', 'airfiber-centralized' ); ?></p>
							<?php self::render_access_options( $modules, array_keys( $modules ), false, false ); ?>
							<input type="hidden" name="visible_modules" value="<?php echo esc_attr( implode( ',', array_keys( $modules ) ) ); ?>">
						</div>
					</div>
					<div class="afcn-dialog-footer">
						<button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
						<button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Create User', 'airfiber-centralized' ); ?></button>
					</div>
				</form>
			</dialog>

			<dialog class="afcn-dialog" id="afcn-edit-user-dialog">
				<form data-afcn-action="update-user" data-afcn-module="users">
					<input type="hidden" name="user_id" value="">
					<div class="afcn-dialog-header">
						<h2><?php esc_html_e( 'User access', 'airfiber-centralized' ); ?></h2>
						<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button>
					</div>
					<div class="afcn-dialog-body">
						<p class="afcn-user-edit-note" data-afcn-user-edit-note></p>
						<div class="afcn-form-grid">
							<?php echo UI::field( 'display_name', __( 'Display name', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::field( 'email', __( 'Email', 'airfiber-centralized' ), array( 'type' => 'email' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::select( 'role', __( 'Role', 'airfiber-centralized' ), $roles, 'airfiber_operator' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo UI::field( 'password', __( 'New password', 'airfiber-centralized' ), array( 'type' => 'password', 'placeholder' => __( 'Leave blank to keep current password', 'airfiber-centralized' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>

						<div class="afcn-user-access-section">
							<h3><?php esc_html_e( 'Menu visibility', 'airfiber-centralized' ); ?></h3>
							<p class="afcn-user-access-help"><?php esc_html_e( 'Visibility is an extra restriction on top of the user role.', 'airfiber-centralized' ); ?></p>
							<?php self::render_access_options( $modules, array(), false, false ); ?>
							<input type="hidden" name="visible_modules" value="">
						</div>

						<?php if ( $is_super_admin && $mu_modules ) : ?>
							<div class="afcn-user-access-section" data-afcn-user-mu-section hidden>
								<h3><?php esc_html_e( 'Core / MU', 'airfiber-centralized' ); ?></h3>
								<p class="afcn-user-access-help"><?php esc_html_e( 'Super Admin Core access is always enabled and cannot be disabled here.', 'airfiber-centralized' ); ?></p>
								<?php self::render_access_options( $mu_modules, array_keys( $mu_modules ), true, true ); ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="afcn-dialog-footer">
						<button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
						<button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Access', 'airfiber-centralized' ); ?></button>
					</div>
				</form>
			</dialog>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_user_card( $user ) {
		$badge_class = self::role_badge_class( $user );
		$icon        = ! empty( $user['is_super_admin'] ) ? 'shield' : 'user';
		$tooltip     = trim( $user['username'] . ' · ' . $user['email'] );
		?>
		<article class="afcn-user-card afcn-card">
			<div class="afcn-user-card-head">
				<div class="afcn-user-card-icon" aria-hidden="true"><?php echo Icon::svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<span class="afcn-user-role-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $user['role_label'] ); ?></span>
			</div>
			<h3 class="afcn-user-card-title">
				<?php echo Tooltip::render( '<span>' . esc_html( $user['display_name'] ) . '</span>', $tooltip, array( 'direction' => 'down' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</h3>
			<div class="afcn-user-card-subtitle">@<?php echo esc_html( $user['username'] ); ?></div>
			<div class="afcn-user-card-actions">
				<?php echo self::edit_control( $user['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::delete_control( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="afcn-user-card-meta"><span><?php echo esc_html( $user['email'] ); ?></span></div>
		</article>
		<?php
	}

	private static function render_user_list_row( $user ) {
		$badge_class = self::role_badge_class( $user );
		$icon        = ! empty( $user['is_super_admin'] ) ? 'shield' : 'user';
		?>
		<div class="afcn-user-list-row afcn-card">
			<div class="afcn-user-list-name">
				<div class="afcn-user-list-icon" aria-hidden="true"><?php echo Icon::svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="afcn-user-list-copy"><strong><?php echo esc_html( $user['display_name'] ); ?></strong><small>@<?php echo esc_html( $user['username'] ); ?></small></div>
			</div>
			<div class="afcn-user-list-email"><?php echo esc_html( $user['email'] ); ?></div>
			<div class="afcn-user-list-role"><span class="afcn-user-role-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $user['role_label'] ); ?></span></div>
			<div class="afcn-user-list-actions">
				<?php echo self::edit_control( $user['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::delete_control( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
	}

	private static function edit_control( $user_id ) {
		$label  = __( 'Edit access', 'airfiber-centralized' );
		$button = '<button type="button" class="afcn-user-action" data-afcn-user-edit="' . esc_attr( $user_id ) . '" aria-label="' . esc_attr( $label ) . '">' . Icon::svg( 'edit' ) . '</button>';
		return Tooltip::render( $button, $label );
	}

	private static function delete_control( $user ) {
		if ( ! empty( $user['is_super_admin'] ) || ! empty( $user['is_wp_admin'] ) || get_current_user_id() === (int) $user['id'] ) {
			return '';
		}
		$label  = __( 'Delete user', 'airfiber-centralized' );
		$button = '<button type="submit" class="afcn-user-action is-danger" aria-label="' . esc_attr( $label ) . '">' . Icon::svg( 'trash' ) . '</button>';
		$html   = '<form data-afcn-action="delete-user" data-afcn-module="users" data-afcn-confirm="' . esc_attr__( 'Delete this Airfiber user?', 'airfiber-centralized' ) . '">';
		$html  .= '<input type="hidden" name="user_id" value="' . esc_attr( $user['id'] ) . '">';
		$html  .= Tooltip::render( $button, $label );
		$html  .= '</form>';
		return $html;
	}

	private static function role_badge_class( $user ) {
		if ( ! empty( $user['is_super_admin'] ) ) {
			return 'is-super';
		}
		if ( 'airfiber_admin' === $user['role_key'] ) {
			return 'is-admin';
		}
		return 'is-operator';
	}

	private static function render_access_options( $modules, $checked_ids, $locked = false, $mu = false ) {
		if ( empty( $modules ) ) {
			echo '<p class="afcn-user-access-help">' . esc_html__( 'No normal modules are currently available.', 'airfiber-centralized' ) . '</p>';
			return;
		}
		?>
		<div class="afcn-user-access-grid">
			<?php foreach ( $modules as $id => $module ) : ?>
				<label class="afcn-user-access-option<?php echo $locked ? ' is-locked' : ''; ?>">
					<input type="checkbox" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $checked_ids, true ) ); ?> <?php disabled( $locked ); ?> <?php echo $mu ? 'data-afcn-access-mu' : 'data-afcn-access-module'; ?>>
					<span><?php echo esc_html( $module['name'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( 'create-user' === $action ) {
			$result = User_Manager::create_user( $payload );
			if ( is_wp_error( $result ) ) { return $result; }
			$result['message'] = __( 'Airfiber user created.', 'airfiber-centralized' );
			return $result;
		}
		if ( 'update-user' === $action ) {
			$user_id = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0;
			$result  = User_Manager::update_user( $user_id, $payload );
			if ( is_wp_error( $result ) ) { return $result; }
			$result['message'] = __( 'Airfiber user updated.', 'airfiber-centralized' );
			return $result;
		}
		if ( 'delete-user' === $action ) {
			$user_id = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0;
			$result  = User_Manager::delete_user( $user_id );
			if ( is_wp_error( $result ) ) { return $result; }
			$result['message'] = __( 'Airfiber user deleted.', 'airfiber-centralized' );
			return $result;
		}
		return new \WP_Error( 'afcn_unknown_action', __( 'Unknown user action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
