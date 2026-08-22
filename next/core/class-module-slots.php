<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight shared-page extension points.
 *
 * Slot discovery uses compiled module manifest metadata only. A contributing
 * module is not booted until the browser requests its declared lazy chunk.
 */
class Module_Slots {

	/**
	 * Return eligible contributions for a named slot without loading module PHP.
	 */
	public static function contributions( $slot ) {
		$slot = self::normalize_slot_id( $slot );
		if ( '' === $slot ) {
			return array();
		}

		$output = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( empty( $module['slots'][ $slot ] ) || ! is_array( $module['slots'][ $slot ] ) ) {
				continue;
			}
			if ( ! Module_Manager::is_enabled( $id, $module ) || ! Module_Manager::dependencies_met( $module ) || ! Module_Manager::user_can( $module ) ) {
				continue;
			}
			if ( empty( $module['system'] ) && Module_Trash::is_trashed( $id ) ) {
				continue;
			}
			if ( empty( $module['system'] ) && Circuit_Breaker::is_quarantined( $id ) ) {
				continue;
			}

			$definition = $module['slots'][ $slot ];
			$output[]   = array(
				'module'   => $id,
				'name'     => $module['name'],
				'chunk'    => $definition['chunk'],
				'priority' => (int) $definition['priority'],
				'span'     => (int) $definition['span'],
			);
		}

		usort(
			$output,
			function ( $left, $right ) {
				if ( $left['priority'] === $right['priority'] ) {
					return strcasecmp( $left['name'], $right['name'] );
				}
				return $left['priority'] < $right['priority'] ? -1 : 1;
			}
		);

		return $output;
	}

	/**
	 * Render lazy placeholders for a shared slot.
	 *
	 * The returned markup contains only registry metadata. Core JavaScript uses
	 * IntersectionObserver to request each chunk when it approaches the viewport.
	 */
	public static function render( $slot, $args = array() ) {
		$slot          = self::normalize_slot_id( $slot );
		$contributions = self::contributions( $slot );
		if ( '' === $slot || empty( $contributions ) ) {
			return '';
		}

		$args       = is_array( $args ) ? $args : array();
		$grid       = ! empty( $args['grid'] );
		$class_name = 'afcn-slot' . ( $grid ? ' afcn-grid' : '' );
		if ( ! empty( $args['class'] ) ) {
			$class_name .= ' ' . implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( trim( $class_name ) ); ?>" data-afcn-slot="<?php echo esc_attr( $slot ); ?>">
			<?php foreach ( $contributions as $contribution ) : ?>
				<div
					class="afcn-slot-item<?php echo $grid ? ' afcn-col-' . esc_attr( max( 1, min( 12, $contribution['span'] ) ) ) : ''; ?>"
					data-afcn-slot-item
					data-afcn-slot-module="<?php echo esc_attr( $contribution['module'] ); ?>"
					data-afcn-slot-chunk="<?php echo esc_attr( $contribution['chunk'] ); ?>"
					data-afcn-slot-label="<?php echo esc_attr( $contribution['name'] ); ?>"
				>
					<div class="afcn-card afcn-slot-placeholder" aria-busy="true">
						<div class="afcn-card-body">
							<span class="afcn-spinner" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Loading %s', 'airfiber-centralized' ), $contribution['name'] ) ); ?></span>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Keep dotted slot names readable while rejecting unexpected characters.
	 */
	public static function normalize_slot_id( $slot ) {
		$slot = strtolower( trim( (string) $slot ) );
		$slot = preg_replace( '/[^a-z0-9._-]+/', '', $slot );
		return substr( (string) $slot, 0, 80 );
	}
}
