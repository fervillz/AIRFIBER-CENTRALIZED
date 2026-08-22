<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Shared tooltip renderer.
 *
 * One API supports plain text tooltips and richer tooltips with an optional
 * action. Default appearance is black and the default motion is fade-up.
 */
class Tooltip {

	private static $counter = 0;

	public static function render( $trigger_html, $text, $args = array() ) {
		$directions = array( 'up', 'down' );
		$variants   = array( 'dark', 'light', 'info', 'success', 'warning', 'danger' );
		$direction  = isset( $args['direction'] ) && in_array( $args['direction'], $directions, true ) ? $args['direction'] : 'up';
		$variant    = isset( $args['variant'] ) && in_array( $args['variant'], $variants, true ) ? $args['variant'] : 'dark';
		$action     = isset( $args['action'] ) && is_array( $args['action'] ) ? $args['action'] : array();

		self::$counter++;
		$id = 'afcn-tooltip-' . self::$counter;

		$html  = '<span class="afcn-tooltip afcn-tooltip-' . esc_attr( $direction ) . ' afcn-tooltip-' . esc_attr( $variant ) . '">';
		$html .= '<span class="afcn-tooltip-trigger" aria-describedby="' . esc_attr( $id ) . '">' . $trigger_html . '</span>';
		$html .= '<span class="afcn-tooltip-panel" id="' . esc_attr( $id ) . '" role="tooltip">';
		$html .= '<span class="afcn-tooltip-text">' . esc_html( $text ) . '</span>';

		if ( ! empty( $action['label'] ) ) {
			$label = sanitize_text_field( $action['label'] );
			if ( ! empty( $action['url'] ) ) {
				$html .= '<a class="afcn-tooltip-action" href="' . esc_url( $action['url'] ) . '">' . esc_html( $label ) . '</a>';
			} else {
				$attrs = isset( $action['attrs'] ) && is_array( $action['attrs'] ) ? $action['attrs'] : array();
				$html .= '<button type="button" class="afcn-tooltip-action"';
				foreach ( $attrs as $key => $value ) {
					$key = sanitize_key( $key );
					if ( ! $key || 0 !== strpos( $key, 'data-' ) ) {
						continue;
					}
					$html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
				}
				$html .= '>' . esc_html( $label ) . '</button>';
			}
		}

		$html .= '</span></span>';
		return $html;
	}
}
