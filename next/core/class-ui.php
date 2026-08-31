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

	/**
	 * Render a shared accessible tabs component.
	 *
	 * Tab content is trusted internal module markup, equivalent to the existing
	 * drill-down actions/content contract. Labels and identifiers are escaped.
	 */
	public static function tabs( $id, $tabs, $args = array() ) {
		$id       = sanitize_key( (string) $id );
		$position = isset( $args['position'] ) ? sanitize_key( $args['position'] ) : 'top';
		$position = in_array( $position, array( 'top', 'bottom', 'left', 'right' ), true ) ? $position : 'top';
		$label    = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : __( 'Tabs', 'airfiber-centralized' );
		$tabs     = is_array( $tabs ) ? $tabs : array();

		$normalized = array();
		foreach ( $tabs as $key => $definition ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_string( $definition ) ) {
				$definition = array( 'label' => $definition, 'content' => '' );
			}
			if ( ! is_array( $definition ) || empty( $definition['label'] ) ) {
				continue;
			}
			$normalized[ $key ] = array(
				'label'    => sanitize_text_field( (string) $definition['label'] ),
				'content'  => isset( $definition['content'] ) ? (string) $definition['content'] : '',
				'disabled' => ! empty( $definition['disabled'] ),
			);
		}
		if ( ! $normalized ) {
			return '';
		}

		$active = isset( $args['active'] ) ? sanitize_key( (string) $args['active'] ) : '';
		if ( ! isset( $normalized[ $active ] ) || ! empty( $normalized[ $active ]['disabled'] ) ) {
			$active = '';
			foreach ( $normalized as $key => $definition ) {
				if ( empty( $definition['disabled'] ) ) {
					$active = $key;
					break;
				}
			}
		}
		if ( '' === $active ) {
			$active = array_key_first( $normalized );
		}

		$orientation = in_array( $position, array( 'left', 'right' ), true ) ? 'vertical' : 'horizontal';
		$html        = '<div class="afcn-tabs afcn-tabs-' . esc_attr( $position ) . '" data-afcn-tabs data-afcn-tabs-position="' . esc_attr( $position ) . '"';
		if ( $id ) {
			$html .= ' id="' . esc_attr( $id ) . '"';
		}
		$html .= '><div class="afcn-tabs-list" role="tablist" aria-label="' . esc_attr( $label ) . '" aria-orientation="' . esc_attr( $orientation ) . '">';

		foreach ( $normalized as $key => $definition ) {
			$is_active = $key === $active;
			$tab_id    = $id ? $id . '-tab-' . $key : '';
			$panel_id  = $id ? $id . '-panel-' . $key : '';
			$html     .= '<button type="button" class="afcn-tab' . ( $is_active ? ' is-active' : '' ) . '" role="tab" data-afcn-tab="' . esc_attr( $key ) . '" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" tabindex="' . ( $is_active ? '0' : '-1' ) . '"';
			if ( $tab_id ) {
				$html .= ' id="' . esc_attr( $tab_id ) . '"';
			}
			if ( $panel_id ) {
				$html .= ' aria-controls="' . esc_attr( $panel_id ) . '"';
			}
			if ( $definition['disabled'] ) {
				$html .= ' disabled aria-disabled="true"';
			}
			$html .= '>' . esc_html( $definition['label'] ) . '</button>';
		}
		$html .= '</div><div class="afcn-tabs-panels">';

		foreach ( $normalized as $key => $definition ) {
			$is_active = $key === $active;
			$tab_id    = $id ? $id . '-tab-' . $key : '';
			$panel_id  = $id ? $id . '-panel-' . $key : '';
			$html     .= '<section class="afcn-tab-panel' . ( $is_active ? ' is-active' : '' ) . '" role="tabpanel" data-afcn-tab-panel="' . esc_attr( $key ) . '" tabindex="0"';
			if ( $panel_id ) {
				$html .= ' id="' . esc_attr( $panel_id ) . '"';
			}
			if ( $tab_id ) {
				$html .= ' aria-labelledby="' . esc_attr( $tab_id ) . '"';
			}
			if ( ! $is_active ) {
				$html .= ' hidden';
			}
			$html .= '>' . $definition['content'] . '</section>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Compact in-page drill-down header for a selected primary card.
	 *
	 * Actions are internal trusted markup prepared by the calling module.
	 */
	public static function drilldown_head( $context, $title, $meta = '', $actions = '' ) {
		$html  = '<div class="afcn-drilldown-head">';
		$html .= '<div class="afcn-drilldown-copy">';
		if ( '' !== trim( (string) $context ) ) {
			$html .= '<span class="afcn-drilldown-context">' . esc_html( (string) $context ) . '</span>';
		}
		$html .= '<h1 class="afcn-drilldown-title" title="' . esc_attr( (string) $title ) . '">' . esc_html( (string) $title ) . '</h1>';
		if ( '' !== trim( (string) $meta ) ) {
			$html .= '<p class="afcn-drilldown-meta">' . esc_html( (string) $meta ) . '</p>';
		}
		$html .= '</div>';
		if ( '' !== trim( (string) $actions ) ) {
			$html .= '<div class="afcn-drilldown-actions">' . $actions . '</div>';
		}
		return $html . '</div>';
	}

	public static function notice( $message, $variant = 'info' ) {
		return '<div class="afcn-notice afcn-notice-' . esc_attr( sanitize_key( $variant ) ) . '">' . esc_html( $message ) . '</div>';
	}
}
