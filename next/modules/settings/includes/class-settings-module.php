<?php

namespace Airfiber\Next\Modules\Settings;

use Airfiber\Next\Audit_Log;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Debug_Logger;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Task_Queue;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Settings_Module implements Module_Contract {
	public static function render( $context = array() ) {
		$budgets=Performance_Monitor::budgets();$events=array_slice(Debug_Logger::recent(),0,12);$audit=Audit_Log::recent(12);$queue=Task_Queue::stats();ob_start(); ?>
		<div class="afcn-page-head"><div><h1 class="afcn-page-title"><?php esc_html_e('Core Settings','airfiber-centralized'); ?></h1><p class="afcn-page-description"><?php esc_html_e('The defaults are intentionally strict. Repeated module-code failures or budget violations are isolated instead of slowing the whole app.','airfiber-centralized'); ?></p></div></div>
		<div class="afcn-grid"><div class="afcn-card afcn-col-8"><div class="afcn-card-header"><h2><?php esc_html_e('Performance budgets','airfiber-centralized'); ?></h2></div><div class="afcn-card-body"><form data-afcn-action="save-performance" data-afcn-module="settings"><div class="afcn-form-grid"><?php foreach($budgets as $key=>$value){echo UI::field($key,ucwords(str_replace('_',' ',$key)),array('type'=>'number','value'=>$value,'required'=>true));} // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><div class="afcn-form-actions"><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e('Save Budgets','airfiber-centralized'); ?></button></div></form></div></div>
		<div class="afcn-card afcn-col-4"><div class="afcn-card-header"><h2><?php esc_html_e('Platform','airfiber-centralized'); ?></h2></div><div class="afcn-card-body"><p><strong>Airfiber Next</strong><br><?php echo esc_html(AFCN_VERSION); ?></p><p><?php echo esc_html(sprintf(__('Background queue: %1$d pending, %2$d running.','airfiber-centralized'),$queue['pending'],$queue['running'])); ?></p></div></div>
		<div class="afcn-card afcn-col-6"><div class="afcn-card-header"><h2><?php esc_html_e('Recent warnings','airfiber-centralized'); ?></h2></div><div class="afcn-card-body"><?php if(empty($events)):?><p class="afcn-page-description"><?php esc_html_e('No recent warnings or errors.','airfiber-centralized'); ?></p><?php else:?><div class="afcn-table-wrap"><table class="afcn-table"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><?php echo esc_html($event['time']); ?></td><td><?php echo UI::badge(strtoupper($event['level']),'error'===$event['level']?'danger':'warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo esc_html($event['message']); ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
		<div class="afcn-card afcn-col-6"><div class="afcn-card-header"><h2><?php esc_html_e('Recent admin activity','airfiber-centralized'); ?></h2></div><div class="afcn-card-body"><?php if(empty($audit)):?><p class="afcn-page-description"><?php esc_html_e('No Airfiber Next administrative changes recorded yet.','airfiber-centralized'); ?></p><?php else:?><div class="afcn-table-wrap"><table class="afcn-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th></tr></thead><tbody><?php foreach($audit as $item):?><tr><td><?php echo esc_html($item['time']); ?></td><td><?php echo esc_html($item['actor']); ?></td><td><?php echo esc_html($item['action']); ?></td><td><?php echo esc_html($item['subject']); ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div></div><?php return ob_get_clean();
	}
	public static function handle_action( $action, $payload = array() ) {if(!current_user_can('manage_options')&&!current_user_can(Capabilities::MANAGE_SETTINGS)){return new \WP_Error('afcn_forbidden',__('You cannot change Core settings.','airfiber-centralized'),array('status'=>403));}if('save-performance'===$action){$budgets=Performance_Monitor::save_budgets($payload);return array('budgets'=>$budgets,'message'=>__('Performance budgets saved.','airfiber-centralized'));}return new \WP_Error('afcn_unknown_action',__('Unknown settings action.','airfiber-centralized'),array('status'=>400));}
}
