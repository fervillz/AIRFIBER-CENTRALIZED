<?php

namespace Airfiber\Next\Modules\Modules;

use Airfiber\Next\Assets;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Circuit_Breaker;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Modules_Module implements Module_Contract {
	public static function render( $context = array() ) {
		$statuses = Module_Manager::statuses();
		ob_start();
		?>
		<div class="afcn-page-head"><div><h1 class="afcn-page-title"><?php esc_html_e( 'Modules', 'airfiber-centralized' ); ?></h1><p class="afcn-page-description"><?php esc_html_e( 'Core reads a compiled manifest registry. Module PHP, CSS, JavaScript and data load only when that module is opened.', 'airfiber-centralized' ); ?></p></div><form data-afcn-action="refresh-registry" data-afcn-module="modules"><button type="submit" class="afcn-button afcn-button-secondary"><?php esc_html_e( 'Refresh Registry', 'airfiber-centralized' ); ?></button></form></div>
		<div class="afcn-module-list">
		<?php foreach ( $statuses as $id => $status ) : $module=$status['meta'];$health=$status['health'];$assets=Assets::module_manifest($module);$bytes=0;foreach(array_merge($assets['css'],$assets['js']) as $asset){$bytes+=isset($asset['bytes'])?(int)$asset['bytes']:0;}$variant='success';if('warning'===$health['status']){$variant='warning';}if(in_array($health['status'],array('degraded','quarantined'),true)){$variant='danger';} ?>
			<div class="afcn-module-row"><div><h3><?php echo esc_html($module['name']); ?> <?php if($module['system']){echo UI::badge('CORE','info');} // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3><p><?php echo esc_html($module['description']); ?></p><div class="afcn-health-metrics"><span>v<?php echo esc_html($module['version']); ?></span><span><?php echo esc_html(size_format($bytes)); ?> optional assets</span><span>p50 <?php echo esc_html($health['p50_ms']); ?> ms</span><span>p95 <?php echo esc_html($health['p95_ms']); ?> ms</span><?php if($health['external_p95_ms']>0):?><span>external p95 <?php echo esc_html($health['external_p95_ms']); ?> ms</span><?php endif;?><span><?php echo esc_html($health['max_queries']); ?> max queries</span><?php if($health['failures']>0):?><span><?php echo esc_html($health['failures']); ?> runtime failures</span><?php endif;?></div><?php if(!empty($health['recommendation'])&&'healthy'!==$health['status']):?><p style="margin-top:7px"><?php echo esc_html($health['recommendation']); ?></p><?php endif;?></div><div><?php echo UI::badge(strtoupper($health['status']),$variant); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><div class="afcn-form-actions" style="margin:0">
			<?php if(!$module['system']):?><form data-afcn-action="toggle" data-afcn-module="modules"><input type="hidden" name="module_id" value="<?php echo esc_attr($id); ?>"><input type="hidden" name="enabled" value="<?php echo $status['enabled']?'0':'1'; ?>"><button type="submit" class="afcn-button afcn-button-secondary afcn-button-small"><?php echo $status['enabled']?esc_html__('Disable','airfiber-centralized'):esc_html__('Enable','airfiber-centralized'); ?></button></form><?php endif; ?>
			<?php if($health['violations']>0||$health['failures']>0):?><form data-afcn-action="reset-health" data-afcn-module="modules"><input type="hidden" name="module_id" value="<?php echo esc_attr($id); ?>"><button type="submit" class="afcn-button afcn-button-secondary afcn-button-small"><?php esc_html_e('Reset health','airfiber-centralized'); ?></button></form><?php endif; ?>
			</div></div>
		<?php endforeach; ?>
		</div><?php return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_MODULES ) ) { return new \WP_Error( 'afcn_forbidden', __( 'You cannot manage modules.', 'airfiber-centralized' ), array( 'status' => 403 ) ); }
		$module_id = isset( $payload['module_id'] ) ? sanitize_key( $payload['module_id'] ) : '';
		if ( 'toggle' === $action ) {
			$enabled = ! empty( $payload['enabled'] ) && '0' !== (string) $payload['enabled'];
			$result  = Module_Manager::set_enabled( $module_id, $enabled );
			if ( is_wp_error( $result ) ) { return $result; }
			$result['message'] = $enabled ? __( 'Module enabled.', 'airfiber-centralized' ) : __( 'Module disabled.', 'airfiber-centralized' );
			$result['refresh_nav'] = true;
			return $result;
		}
		if ( 'reset-health' === $action ) { Circuit_Breaker::reset( $module_id ); return array( 'message' => __( 'Module health state reset.', 'airfiber-centralized' ) ); }
		if ( 'refresh-registry' === $action ) { Module_Registry::invalidate(); Module_Registry::all( true ); return array( 'message' => __( 'Module registry refreshed from disk.', 'airfiber-centralized' ), 'refresh_nav' => true ); }
		return new \WP_Error( 'afcn_unknown_action', __( 'Unknown module action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
