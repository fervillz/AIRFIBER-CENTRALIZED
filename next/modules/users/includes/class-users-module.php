<?php

namespace Airfiber\Next\Modules\Users;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\UI;
use Airfiber\Next\User_Manager;

defined( 'ABSPATH' ) || exit;

class Users_Module implements Module_Contract {
	public static function render( $context = array() ) {
		$users = User_Manager::list_users();
		$roles = Capabilities::assignable_roles();
		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Airfiber Users', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Airfiber accounts use WordPress authentication, but Airfiber roles and capabilities control access to the BETA application.', 'airfiber-centralized' ); ?></p>
			</div>
			<button type="button" class="afcn-button afcn-button-primary" data-afcn-dialog-open="afcn-add-user-dialog"><?php esc_html_e( 'Add User', 'airfiber-centralized' ); ?></button>
		</div>
		<div class="afcn-card">
			<div class="afcn-table-wrap">
				<table class="afcn-table">
					<thead><tr><th><?php esc_html_e( 'User', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Email', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Role', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Actions', 'airfiber-centralized' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $users as $user ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $user['display_name'] ); ?></strong><br><small><?php echo esc_html( $user['username'] ); ?></small></td>
							<td><?php echo esc_html( $user['email'] ); ?></td>
							<td>
								<?php if ( $user['is_wp_admin'] ) : echo UI::badge( 'WordPress Administrator', 'info' ); else : ?>
									<form data-afcn-action="update-user" data-afcn-module="users" class="afcn-form-grid" style="grid-template-columns:minmax(130px,1fr) auto;align-items:end">
										<input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>">
										<select class="afcn-select" name="role">
											<?php foreach ( $roles as $role => $label ) : ?><option value="<?php echo esc_attr( $role ); ?>" <?php selected( in_array( $role, $user['roles'], true ) ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
										</select>
										<button type="submit" class="afcn-button afcn-button-secondary afcn-button-small"><?php esc_html_e( 'Save', 'airfiber-centralized' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $user['is_wp_admin'] && get_current_user_id() !== $user['id'] ) : ?>
									<form data-afcn-action="delete-user" data-afcn-module="users" data-afcn-confirm="<?php esc_attr_e( 'Delete this Airfiber user?', 'airfiber-centralized' ); ?>">
										<input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>">
										<button type="submit" class="afcn-button afcn-button-danger afcn-button-small"><?php esc_html_e( 'Delete', 'airfiber-centralized' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<dialog class="afcn-dialog" id="afcn-add-user-dialog">
			<form data-afcn-action="create-user" data-afcn-module="users">
				<div class="afcn-dialog-header"><h2><?php esc_html_e( 'Add Airfiber User', 'airfiber-centralized' ); ?></h2><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div>
				<div class="afcn-dialog-body">
					<div class="afcn-form-grid">
						<?php echo UI::field( 'username', __( 'Username', 'airfiber-centralized' ), array( 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo UI::field( 'display_name', __( 'Display name', 'airfiber-centralized' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo UI::field( 'email', __( 'Email', 'airfiber-centralized' ), array( 'type' => 'email', 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo UI::select( 'role', __( 'Role', 'airfiber-centralized' ), $roles, 'airfiber_operator' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div style="grid-column:1/-1"><?php echo UI::field( 'password', __( 'Password', 'airfiber-centralized' ), array( 'type' => 'password', 'placeholder' => __( 'Leave blank to generate a strong password', 'airfiber-centralized' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					</div>
				</div>
				<div class="afcn-dialog-footer"><button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Create User', 'airfiber-centralized' ); ?></button></div>
			</form>
		</dialog>
		<?php
		return ob_get_clean();
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
