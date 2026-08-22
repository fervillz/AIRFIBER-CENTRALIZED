<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class UI {
	public static function button( $label, $args = array() ) {
		$type     = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'button';
		$variant  = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : 'secondary';
		$class    = 'afcn-button afcn-button-' . $variant;
		$attrs    = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$html     = '<button type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '"';
		foreach ( $attrs as $key => $value ) {
			$html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}
		return $html . '>' . esc_html( $label ) . '</button>';
	}

	public static function field( $name, $label, $args = array() ) {
		$type        = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'text';
		$value       = isset( $args['value'] ) ? (string) $args['value'] : '';
		$placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
		$required    = ! empty( $args['required'] ) ? ' required' : '';
		return '<label class="afcn-field"><span>' . esc_html( $label ) . '</span><input class="afcn-input" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . $required . '></label>';
	}

	public static function select( $name, $label, $options, $selected = '' ) {
		$html = '<label class="afcn-field"><span>' . esc_html( $label ) . '</span><select class="afcn-select" name="' . esc_attr( $name ) . '">';
		foreach ( (array) $options as $value => $caption ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $caption ) . '</option>';
		}
		return $html . '</select></label>';
	}

	public static function badge( $label, $variant = 'neutral' ) {
		return '<span class="afcn-badge afcn-badge-' . esc_attr( sanitize_key( $variant ) ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function notice( $message, $variant = 'info' ) {
		return '<div class="afcn-notice afcn-notice-' . esc_attr( sanitize_key( $variant ) ) . '">' . esc_html( $message ) . '</div>';
	}
}
