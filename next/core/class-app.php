<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class App {
	public static function render_shell() {
		$token   = Performance_Monitor::start( 'core', 'render' );
		$modules = Module_Manager::navigation();
		$user    = User_Manager::current_user_summary();
		ob_start();
		?>
		<div class="afcn-app" id="afcn-app" data-version="<?php echo esc_attr( AFCN_VERSION ); ?>">
			<header class="afcn-header">
				<a class="afcn-brand" href="<?php echo esc_url( Bootstrap::get_url() ); ?>">
					<span class="afcn-brand-mark" aria-hidden="true">A</span>
					<span class="afcn-brand-copy">
						<strong>Airfiber Centralized</strong>
						<small><?php esc_html_e( 'Fast by design', 'airfiber-centralized' ); ?></small>
					</span>
					<span class="afcn-beta-badge">BETA</span>
				</a>
				<nav class="afcn-nav" aria-label="<?php esc_attr_e( 'Airfiber BETA modules', 'airfiber-centralized' ); ?>">
					<?php foreach ( $modules as $module ) : ?>
						<button type="button" data-afcn-module="<?php echo esc_attr( $module['id'] ); ?>" aria-pressed="false">
							<span><?php echo esc_html( $module['name'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>
				<div class="afcn-header-actions">
					<span class="afcn-user-name"><?php echo esc_html( $user['display_name'] ); ?></span>
					<a class="afcn-button afcn-button-secondary" href="<?php echo esc_url( Bootstrap::classic_url() ); ?>"><?php esc_html_e( 'Back to Classic', 'airfiber-centralized' ); ?></a>
				</div>
			</header>
			<main class="afcn-main" id="afcn-module-stage" aria-live="polite">
				<div class="afcn-loading-state" data-afcn-loading>
					<span class="afcn-spinner" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Preparing Airfiber…', 'airfiber-centralized' ); ?></strong>
				</div>
			</main>
			<div class="afcn-toast-region" id="afcn-toast-region" aria-live="polite"></div>
		</div>
		<?php
		$html = ob_get_clean();
		Performance_Monitor::finish( $token );
		return $html;
	}
}
