<?php

namespace Airfiber\Next\Modules\Users;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\UI;
use Airfiber\Next\User_Manager;

defined( 'ABSPATH' ) || exit;

class Users_Module implements Module_Contract {
	public static function render( $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_USERS ) ) {
			return UI::notice( __( 'You do not have permission to manage Airfiber users.', 'airfiber-centralized' ), 'error' );
		}
		$users = User_Manager::list_users();
		ob_start();
		?>
		<div class="afcn-page-head">
			<div><h1 class="afcn-page-title"><?php esc_html_e( 'Users', 'airfiber-centralized' ); ?></h1><p class="afcn-page-description"><?php esc_html_e( 'Airfiber users use WordPress authentication underneath, with focused Airfiber roles and capabilities.', 'airfiber-centralized' ); ?></p></div>
			<button type="button" class="afcn-button afcn-button-primary" data-afcn-dialog-open="afcn-add-user-dialog"><?php esc_html_e( 'Add User', 'airfiber-centralized' ); ?></button>
		</div>
		<div class="afcn-card afcn-table-card"><div class="afcn-table-wrap"><table class="afcn-table"><thead><tr><th><?php esc_html_e( 'Name', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Email', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Role', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Actions', 'airfiber-centralized' ); ?></th></tr></thead><tbody>
		<?php foreach ( $users as $user ) : ?>
			<tr><td><strong><?php echo esc_html( $user['name'] ); ?></strong><small><?php echo esc_html( $user['username'] ); ?></small></td><td><?php echo esc_html( $user['email'] ); ?></td><td><?php echo esc_html( User_Manager::role_label( $user['role'] ) ); ?></td><td><div class="afcn-row-actions"><button type="button" class="afcn-button afcn-button-secondary afcn-button-small" data-afcn-dialog-open="afcn-user-<?php echo esc_attr( $user['id'] ); ?>"><?php esc_html_e( 'Edit', 'airfiber-centralized' ); ?></button><?php if ( (int) $user['id'] !== get_current_user_id() ) : ?><form data-afcn-module="users" data-afcn-action="delete-user" data-afcn-confirm="<?php esc_attr_e( 'Delete this Airfiber user?', 'airfiber-centralized' ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>"><button class="afcn-button afcn-button-danger afcn-button-small" type="submit"><?php esc_html_e( 'Delete', 'airfiber-centralized' ); ?></button></form><?php endif; ?></div></td></tr>
			<?php self::render_edit_dialog( $user ); ?>
		<?php endforeach; ?>
		</tbody></table></div></div>
		<?php self::render_add_dialog(); ?>
		<?php
		return ob_get_clean();
	}

	private static function render_add_dialog() {
		?>
		<dialog class="afcn-dialog" id="afcn-add-user-dialog"><form method="dialog" class="afcn-dialog-shell" data-afcn-module="users" data-afcn-action="create-user"><div class="afcn-dialog-header"><div><h2><?php esc_html_e( 'Add Airfiber User', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Creates a normal WordPress user with an Airfiber role.', 'airfiber-centralized' ); ?></p></div><button type="button" class="afcn-dialog-close" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div><div class="afcn-dialog-body"><div class="afcn-form-grid"><?php echo UI::field( 'username', __( 'Username', 'airfiber-centralized' ), '', array( 'required' => true ) ); echo UI::field( 'email', __( 'Email', 'airfiber-centralized' ), '', array( 'type' => 'email', 'required' => true ) ); echo UI::field( 'display_name', __( 'Display name', 'airfiber-centralized' ) ); echo UI::select( 'role', __( 'Airfiber role', 'airfiber-centralized' ), User_Manager::role_options(), 'airfiber_operator' ); ?></div></div><div class="afcn-dialog-footer"><button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Create User', 'airfiber-centralized' ); ?></button></div></form></dialog>
		<?php
	}

	private static function render_edit_dialog( $user ) {
		?>
		<dialog class="afcn-dialog" id="afcn-user-<?php echo esc_attr( $user['id'] ); ?>"><form method="dialog" class="afcn-dialog-shell" data-afcn-module="users" data-afcn-action="update-user"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>"><div class="afcn-dialog-header"><div><h2><?php echo esc_html( $user['name'] ); ?></h2><p><?php echo esc_html( $user['email'] ); ?></p></div><button type="button" class="afcn-dialog-close" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>">×</button></div><div class="afcn-dialog-body"><div class="afcn-form-grid"><?php echo UI::field( 'display_name', __( 'Display name', 'airfiber-centralized' ), $user['name'] ); echo UI::field( 'email', __( 'Email', 'airfiber-centralized' ), $user['email'], array( 'type' => 'email', 'required' => true ) ); echo UI::select( 'role', __( 'Airfiber role', 'airfiber-centralized' ), User_Manager::role_options(), $user['role'] ); ?></div></div><div class="afcn-dialog-footer"><button type="button" class="afcn-button afcn-button-secondary" data-afcn-dialog-close><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save User', 'airfiber-centralized' ); ?></button></div></form></dialog>
		<?php
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_USERS ) ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot manage Airfiber users.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		if ( 'create-user' === $action ) { return User_Manager::create_user( $payload ); }
		if ( 'update-user' === $action ) { return User_Manager::update_user( $payload ); }
		if ( 'delete-user' === $action ) { return User_Manager::delete_user( isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0 ); }
		return new \WP_Error( 'afcn_unknown_action', __( 'Unknown user action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
