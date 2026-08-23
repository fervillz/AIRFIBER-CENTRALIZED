<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class App {
	public static function render_shell() {
		$token      = Performance_Monitor::start( 'core', 'render' );
		$navigation = self::navigation_tree( Module_Manager::navigation() );
		$user       = User_Manager::current_user_summary();
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
					<?php foreach ( $navigation as $item ) : ?>
						<div class="afcn-nav-item<?php echo empty( $item['children'] ) ? '' : ' has-children'; ?>">
							<button type="button" data-afcn-module="<?php echo esc_attr( $item['id'] ); ?>" aria-pressed="false">
								<span><?php echo esc_html( $item['name'] ); ?></span>
								<?php if ( ! empty( $item['children'] ) ) : ?><span class="afcn-nav-caret" aria-hidden="true">⌄</span><?php endif; ?>
							</button>
							<?php if ( ! empty( $item['children'] ) ) : ?>
								<div class="afcn-nav-submenu" role="menu">
									<?php foreach ( $item['children'] as $child ) : ?>
										<?php if ( 'drawer' === $child['presentation'] ) : ?>
											<button type="button" role="menuitem" data-afcn-utility-module="<?php echo esc_attr( $child['id'] ); ?>">
												<?php echo Icon::svg( $child['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<span><?php echo esc_html( $child['name'] ); ?></span>
											</button>
										<?php else : ?>
											<button type="button" role="menuitem" data-afcn-module="<?php echo esc_attr( $child['id'] ); ?>" aria-pressed="false">
												<?php echo Icon::svg( $child['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<span><?php echo esc_html( $child['name'] ); ?></span>
											</button>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
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

			<aside class="afcn-utility-drawer" id="afcn-utility-drawer" aria-hidden="true" aria-label="<?php esc_attr_e( 'Airfiber tools', 'airfiber-centralized' ); ?>">
				<div class="afcn-utility-drawer-header">
					<div>
						<small><?php esc_html_e( 'Super Admin', 'airfiber-centralized' ); ?></small>
						<strong data-afcn-utility-title><?php esc_html_e( 'Tools', 'airfiber-centralized' ); ?></strong>
					</div>
					<button type="button" class="afcn-icon-button" data-afcn-utility-close aria-label="<?php esc_attr_e( 'Close tools', 'airfiber-centralized' ); ?>"><?php echo Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<div class="afcn-utility-drawer-body" data-afcn-utility-stage></div>
			</aside>

			<div class="afcn-toast-region" id="afcn-toast-region" aria-live="polite"></div>
		</div>
		<?php
		$html = ob_get_clean();
		Performance_Monitor::finish( $token );
		return $html;
	}

	private static function navigation_tree( $items ) {
		$flat = array();
		foreach ( (array) $items as $item ) {
			$id   = isset( $item['id'] ) ? sanitize_key( $item['id'] ) : '';
			$meta = $id ? Module_Registry::get( $id ) : null;
			if ( ! $id || ! $meta ) {
				continue;
			}
			$flat[ $id ] = array(
				'id'           => $id,
				'name'         => $item['name'],
				'icon'         => $item['icon'],
				'position'     => $item['position'],
				'parent'       => isset( $meta['parent'] ) ? sanitize_key( $meta['parent'] ) : '',
				'presentation' => isset( $meta['presentation'] ) ? sanitize_key( $meta['presentation'] ) : 'page',
				'children'     => array(),
			);
		}

		$tree = array();
		foreach ( $flat as $id => $item ) {
			$parent = $item['parent'];
			if ( $parent && isset( $flat[ $parent ] ) && $parent !== $id ) {
				$flat[ $parent ]['children'][] = $item;
				continue;
			}
			$tree[ $id ] = $item;
		}

		foreach ( $tree as $id => $item ) {
			if ( isset( $flat[ $id ]['children'] ) ) {
				$tree[ $id ]['children'] = $flat[ $id ]['children'];
			}
		}

		return array_values( $tree );
	}
}
