<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class UI {
	public static function button( $label, $args = array() ) {
		$type      = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'button';
		$type      = in_array( $type, array( 'button', 'submit', 'reset' ), true ) ? $type : 'button';
		$variant   = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : 'secondary';
		$size      = isset( $args['size'] ) ? sanitize_key( $args['size'] ) : 'default';
		$icon      = isset( $args['icon'] ) ? sanitize_key( $args['icon'] ) : '';
		$icon_side = isset( $args['icon_position'] ) && 'after' === sanitize_key( $args['icon_position'] ) ? 'after' : 'before';
		$attrs     = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$classes   = array( 'afcn-button', 'afcn-button-' . $variant );

		if ( in_array( $size, array( 'small', 'large' ), true ) ) {
			$classes[] = 'afcn-button-' . $size;
		}
		if ( ! empty( $args['block'] ) ) {
			$classes[] = 'afcn-button-block';
		}
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}
		if ( ! empty( $args['loading'] ) ) {
			$classes[]          = 'is-loading';
			$attrs['aria-busy'] = 'true';
			$attrs['disabled']  = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}

		$content = '<span class="afcn-button-label">' . esc_html( $label ) . '</span>';
		if ( $icon ) {
			$icon_html = '<span class="afcn-button-icon">' . Icon::svg( $icon ) . '</span>';
			$content   = 'after' === $icon_side ? $content . $icon_html : $icon_html . $content;
		}
		if ( isset( $args['count'] ) && '' !== (string) $args['count'] ) {
			$count_variant = isset( $args['count_variant'] ) ? sanitize_key( $args['count_variant'] ) : 'primary';
			$content      .= self::counter( $args['count'], $count_variant, array( 'class' => 'afcn-button-counter' ) );
		}

		return '<button type="' . esc_attr( $type ) . '" class="' . esc_attr( implode( ' ', array_filter( $classes ) ) ) . '"' . self::attrs( $attrs ) . '>' . $content . '</button>';
	}

	public static function field( $name, $label, $args = array() ) {
		$type        = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'text';
		$value       = isset( $args['value'] ) ? (string) $args['value'] : '';
		$placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
		$attrs       = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$classes     = 'afcn-field' . ( ! empty( $args['error'] ) ? ' is-error' : '' );

		if ( ! empty( $args['required'] ) ) {
			$attrs['required'] = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}
		if ( ! empty( $args['autocomplete'] ) ) {
			$attrs['autocomplete'] = sanitize_text_field( (string) $args['autocomplete'] );
		}

		$html  = '<label class="' . esc_attr( $classes ) . '"><span class="afcn-field-label">' . esc_html( $label ) . '</span>';
		$html .= '<input class="afcn-input" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . self::attrs( $attrs ) . '>';
		$html .= self::field_message( $args );
		return $html . '</label>';
	}

	public static function select( $name, $label, $options, $selected = '', $args = array() ) {
		$attrs   = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$classes = 'afcn-field' . ( ! empty( $args['error'] ) ? ' is-error' : '' );
		if ( ! empty( $args['required'] ) ) {
			$attrs['required'] = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}

		$html = '<label class="' . esc_attr( $classes ) . '"><span class="afcn-field-label">' . esc_html( $label ) . '</span><select class="afcn-select" name="' . esc_attr( $name ) . '"' . self::attrs( $attrs ) . '>';
		foreach ( (array) $options as $value => $caption ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $caption ) . '</option>';
		}
		$html .= '</select>' . self::field_message( $args );
		return $html . '</label>';
	}

	public static function textarea( $name, $label, $args = array() ) {
		$value       = isset( $args['value'] ) ? (string) $args['value'] : '';
		$placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
		$rows        = isset( $args['rows'] ) ? max( 2, min( 20, absint( $args['rows'] ) ) ) : 4;
		$attrs       = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$classes     = 'afcn-field' . ( ! empty( $args['error'] ) ? ' is-error' : '' );
		if ( ! empty( $args['required'] ) ) {
			$attrs['required'] = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}

		$html  = '<label class="' . esc_attr( $classes ) . '"><span class="afcn-field-label">' . esc_html( $label ) . '</span>';
		$html .= '<textarea class="afcn-textarea" name="' . esc_attr( $name ) . '" rows="' . esc_attr( $rows ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . self::attrs( $attrs ) . '>' . esc_textarea( $value ) . '</textarea>';
		$html .= self::field_message( $args );
		return $html . '</label>';
	}

	public static function checkbox( $name, $label, $args = array() ) {
		$value = isset( $args['value'] ) ? (string) $args['value'] : '1';
		$attrs = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		if ( ! empty( $args['checked'] ) ) {
			$attrs['checked'] = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}

		$html  = '<label class="afcn-check-control"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . self::attrs( $attrs ) . '>';
		$html .= '<span class="afcn-check-box" aria-hidden="true"></span><span class="afcn-check-copy"><strong>' . esc_html( $label ) . '</strong>';
		if ( ! empty( $args['description'] ) ) {
			$html .= '<small>' . esc_html( (string) $args['description'] ) . '</small>';
		}
		return $html . '</span></label>';
	}

	public static function toggle( $name, $label, $args = array() ) {
		$value = isset( $args['value'] ) ? (string) $args['value'] : '1';
		$attrs = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		if ( ! empty( $args['checked'] ) ) {
			$attrs['checked'] = true;
		}
		if ( ! empty( $args['disabled'] ) ) {
			$attrs['disabled'] = true;
		}

		$html  = '<label class="afcn-switch-control"><input type="checkbox" role="switch" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . self::attrs( $attrs ) . '>';
		$html .= '<span class="afcn-switch-track" aria-hidden="true"><span></span></span><span class="afcn-check-copy"><strong>' . esc_html( $label ) . '</strong>';
		if ( ! empty( $args['description'] ) ) {
			$html .= '<small>' . esc_html( (string) $args['description'] ) . '</small>';
		}
		return $html . '</span></label>';
	}

	public static function pill( $label, $variant = 'neutral', $args = array() ) {
		$variant = sanitize_key( $variant );
		$classes = array( 'afcn-pill', 'afcn-pill-' . $variant );
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}
		$html = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		if ( ! empty( $args['dot'] ) ) {
			$html .= '<span class="afcn-pill-dot" aria-hidden="true"></span>';
		}
		if ( ! empty( $args['icon'] ) ) {
			$html .= Icon::svg( sanitize_key( $args['icon'] ) );
		}
		return $html . '<span>' . esc_html( $label ) . '</span></span>';
	}

	public static function badge( $label, $variant = 'neutral' ) {
		$variant = sanitize_key( $variant );
		return '<span class="afcn-badge afcn-pill afcn-pill-' . esc_attr( $variant ) . '"><span>' . esc_html( $label ) . '</span></span>';
	}

	public static function counter( $value, $variant = 'primary', $args = array() ) {
		$variant = sanitize_key( $variant );
		$display = is_numeric( $value ) && (int) $value > 99 ? '99+' : (string) $value;
		$classes = array( 'afcn-counter', 'afcn-counter-' . $variant );
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}
		return '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">' . esc_html( $display ) . '</span>';
	}

	/**
	 * Compact icon action with one or more superscript indicators.
	 *
	 * Indicators accept array( 'value' => '7', 'variant' => 'warning' ). An
	 * empty value renders a status dot, useful for console health.
	 */
	public static function indicator_button( $icon, $label, $indicators = array(), $args = array() ) {
		$attrs   = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		$classes = array( 'afcn-indicator-action' );
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}
		$attrs['aria-label'] = $label;
		if ( ! empty( $args['title'] ) ) {
			$attrs['title'] = sanitize_text_field( (string) $args['title'] );
		}

		$html = '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '"' . self::attrs( $attrs ) . '>';
		$html .= '<span class="afcn-indicator-action-icon">' . Icon::svg( sanitize_key( $icon ) ) . '</span>';
		$html .= '<span class="afcn-indicator-stack" aria-hidden="true">';

		$indicator_index = 0;
		foreach ( (array) $indicators as $indicator ) {
			if ( ! is_array( $indicator ) ) {
				continue;
			}
			$variant = isset( $indicator['variant'] ) ? sanitize_key( $indicator['variant'] ) : 'neutral';
			$value   = isset( $indicator['value'] ) ? (string) $indicator['value'] : '';
			if ( $indicator_index > 0 ) {
				$html .= '<span class="afcn-indicator-separator">|</span>';
			}
			$html .= '<span class="afcn-indicator-badge afcn-indicator-' . esc_attr( $variant ) . ( '' === $value ? ' is-dot' : '' ) . '">' . esc_html( $value ) . '</span>';
			$indicator_index++;
		}

		return $html . '</span></button>';
	}

	public static function status( $label, $variant = 'neutral', $args = array() ) {
		$variant = sanitize_key( $variant );
		$html    = '<span class="afcn-status afcn-status-' . esc_attr( $variant ) . '"><span class="afcn-status-dot" aria-hidden="true"></span><span>' . esc_html( $label ) . '</span>';
		if ( isset( $args['count'] ) && '' !== (string) $args['count'] ) {
			$html .= self::counter( $args['count'], $variant );
		}
		return $html . '</span>';
	}

	public static function alert( $message, $args = array() ) {
		$variant     = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : 'info';
		$title       = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		$dismissible = ! empty( $args['dismissible'] );
		$icon_map    = array(
			'success' => 'check-circle',
			'warning' => 'alert',
			'danger'  => 'alert',
			'info'    => 'info',
			'neutral' => 'info',
		);
		$icon        = isset( $args['icon'] ) ? sanitize_key( $args['icon'] ) : ( isset( $icon_map[ $variant ] ) ? $icon_map[ $variant ] : 'info' );
		$classes     = array( 'afcn-alert', 'afcn-alert-' . $variant );
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}

		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-afcn-alert role="' . ( 'danger' === $variant ? 'alert' : 'status' ) . '">';
		$html .= '<span class="afcn-alert-icon">' . Icon::svg( $icon ) . '</span><div class="afcn-alert-copy">';
		if ( $title ) {
			$html .= '<strong>' . esc_html( $title ) . '</strong>';
		}
		$html .= '<p>' . esc_html( $message ) . '</p>';
		if ( ! empty( $args['actions'] ) ) {
			$html .= '<div class="afcn-alert-actions">' . (string) $args['actions'] . '</div>';
		}
		$html .= '</div>';
		if ( $dismissible ) {
			$html .= '<button type="button" class="afcn-alert-dismiss" data-afcn-alert-dismiss aria-label="' . esc_attr__( 'Dismiss', 'airfiber-centralized' ) . '">' . Icon::svg( 'x' ) . '</button>';
		}
		return $html . '</div>';
	}

	public static function notice( $message, $variant = 'info' ) {
		return self::alert( $message, array( 'variant' => $variant, 'class' => 'afcn-notice' ) );
	}

	public static function list_items( $items, $args = array() ) {
		$classes = array( 'afcn-list' );
		if ( ! empty( $args['compact'] ) ) {
			$classes[] = 'is-compact';
		}
		if ( ! empty( $args['flush'] ) ) {
			$classes[] = 'is-flush';
		}
		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['class'] );
		}

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}
			$tag      = isset( $item['tag'] ) && in_array( $item['tag'], array( 'a', 'button', 'div' ), true ) ? $item['tag'] : 'div';
			$attrs    = isset( $item['attrs'] ) && is_array( $item['attrs'] ) ? $item['attrs'] : array();
			$classes2 = array( 'afcn-list-item' );
			if ( ! empty( $item['active'] ) ) {
				$classes2[] = 'is-active';
			}
			if ( ! empty( $item['disabled'] ) ) {
				$classes2[]        = 'is-disabled';
				$attrs['disabled'] = 'button' === $tag ? true : null;
				$attrs['aria-disabled'] = 'true';
			}
			if ( 'a' === $tag && ! empty( $item['href'] ) ) {
				$attrs['href'] = esc_url( $item['href'] );
			}
			if ( 'button' === $tag ) {
				$attrs['type'] = 'button';
			}

			$html .= '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes2 ) ) . '"' . self::attrs( $attrs ) . '>';
			if ( ! empty( $item['icon'] ) ) {
				$html .= '<span class="afcn-list-leading">' . Icon::svg( sanitize_key( $item['icon'] ) ) . '</span>';
			}
			$html .= '<span class="afcn-list-copy"><strong>' . esc_html( $item['label'] ) . '</strong>';
			if ( ! empty( $item['meta'] ) ) {
				$html .= '<small>' . esc_html( $item['meta'] ) . '</small>';
			}
			$html .= '</span><span class="afcn-list-trailing">';
			if ( isset( $item['value'] ) && '' !== (string) $item['value'] ) {
				$html .= '<span class="afcn-list-value">' . esc_html( $item['value'] ) . '</span>';
			}
			if ( isset( $item['count'] ) && '' !== (string) $item['count'] ) {
				$html .= self::counter( $item['count'], isset( $item['count_variant'] ) ? $item['count_variant'] : 'neutral' );
			}
			if ( ! empty( $item['pill'] ) && is_array( $item['pill'] ) && isset( $item['pill']['label'] ) ) {
				$html .= self::pill( $item['pill']['label'], isset( $item['pill']['variant'] ) ? $item['pill']['variant'] : 'neutral' );
			}
			$html .= '</span></' . $tag . '>';
		}
		return $html . '</div>';
	}

	public static function detail_list( $items, $args = array() ) {
		$classes = array( 'afcn-detail-list' );
		if ( ! empty( $args['compact'] ) ) {
			$classes[] = 'is-compact';
		}
		$html = '<dl class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		foreach ( (array) $items as $key => $item ) {
			if ( is_array( $item ) ) {
				$label = isset( $item['label'] ) ? $item['label'] : $key;
				$value = isset( $item['value'] ) ? $item['value'] : '';
			} else {
				$label = $key;
				$value = $item;
			}
			$html .= '<div class="afcn-detail-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' === (string) $value ? '—' : $value ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	public static function progress( $value, $args = array() ) {
		$value   = max( 0, min( 100, (float) $value ) );
		$variant = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : 'primary';
		$label   = isset( $args['label'] ) ? (string) $args['label'] : '';
		$html    = '<div class="afcn-progress-block">';
		if ( $label ) {
			$html .= '<div class="afcn-progress-label"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( round( $value ) ) . '%</strong></div>';
		}
		$html .= '<div class="afcn-progress afcn-progress-' . esc_attr( $variant ) . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $value ) . '"><span style="width:' . esc_attr( $value ) . '%"></span></div>';
		return $html . '</div>';
	}

	public static function empty_state( $title, $message = '', $args = array() ) {
		$icon = isset( $args['icon'] ) ? sanitize_key( $args['icon'] ) : 'list';
		$html = '<div class="afcn-empty-state"><span class="afcn-empty-icon">' . Icon::svg( $icon ) . '</span><strong>' . esc_html( $title ) . '</strong>';
		if ( $message ) {
			$html .= '<p>' . esc_html( $message ) . '</p>';
		}
		if ( ! empty( $args['actions'] ) ) {
			$html .= '<div class="afcn-empty-actions">' . (string) $args['actions'] . '</div>';
		}
		return $html . '</div>';
	}

	public static function skeleton( $args = array() ) {
		$lines = isset( $args['lines'] ) ? max( 1, min( 8, absint( $args['lines'] ) ) ) : 3;
		$html  = '<div class="afcn-skeleton" aria-hidden="true">';
		for ( $i = 0; $i < $lines; $i++ ) {
			$html .= '<span style="--afcn-skeleton-width:' . esc_attr( max( 42, 100 - ( $i * 11 ) ) ) . '%"></span>';
		}
		return $html . '</div>';
	}

	public static function menu( $id, $items, $args = array() ) {
		$id      = sanitize_key( (string) $id );
		$label   = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : __( 'Actions', 'airfiber-centralized' );
		$icon    = isset( $args['icon'] ) ? sanitize_key( $args['icon'] ) : 'more';
		$align   = isset( $args['align'] ) && 'left' === sanitize_key( $args['align'] ) ? 'left' : 'right';
		$html    = '<details class="afcn-menu afcn-menu-' . esc_attr( $align ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '><summary class="afcn-icon-button" aria-label="' . esc_attr( $label ) . '">' . Icon::svg( $icon ) . '</summary><div class="afcn-menu-panel" role="menu">';

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['separator'] ) ) {
				$html .= '<span class="afcn-menu-separator" role="separator"></span>';
				continue;
			}
			if ( empty( $item['label'] ) ) {
				continue;
			}
			$tag      = ! empty( $item['href'] ) ? 'a' : 'button';
			$attrs    = isset( $item['attrs'] ) && is_array( $item['attrs'] ) ? $item['attrs'] : array();
			$variant2 = isset( $item['variant'] ) ? sanitize_key( $item['variant'] ) : 'default';
			if ( 'a' === $tag ) {
				$attrs['href'] = esc_url( $item['href'] );
			} else {
				$attrs['type'] = 'button';
			}
			if ( ! empty( $item['disabled'] ) ) {
				$attrs['disabled'] = true;
			}
			$html .= '<' . $tag . ' class="afcn-menu-item afcn-menu-item-' . esc_attr( $variant2 ) . '" role="menuitem"' . self::attrs( $attrs ) . '>';
			if ( ! empty( $item['icon'] ) ) {
				$html .= Icon::svg( sanitize_key( $item['icon'] ) );
			}
			$html .= '<span>' . esc_html( $item['label'] ) . '</span>';
			if ( isset( $item['count'] ) && '' !== (string) $item['count'] ) {
				$html .= self::counter( $item['count'], isset( $item['count_variant'] ) ? $item['count_variant'] : 'neutral' );
			}
			$html .= '</' . $tag . '>';
		}
		return $html . '</div></details>';
	}

	public static function dialog( $id, $title, $body, $args = array() ) {
		$id       = sanitize_key( (string) $id );
		$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
		$size     = isset( $args['size'] ) ? sanitize_key( $args['size'] ) : 'default';
		$size     = in_array( $size, array( 'small', 'default', 'large' ), true ) ? $size : 'default';
		$html     = '<dialog class="afcn-dialog afcn-dialog-' . esc_attr( $size ) . '" id="' . esc_attr( $id ) . '"><div class="afcn-dialog-shell"><div class="afcn-dialog-header"><div><h2>' . esc_html( $title ) . '</h2>';
		if ( $subtitle ) {
			$html .= '<p>' . esc_html( $subtitle ) . '</p>';
		}
		$html .= '</div><button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="' . esc_attr__( 'Close', 'airfiber-centralized' ) . '">' . Icon::svg( 'x' ) . '</button></div><div class="afcn-dialog-body">' . (string) $body . '</div>';
		if ( ! empty( $args['footer'] ) ) {
			$html .= '<div class="afcn-dialog-footer">' . (string) $args['footer'] . '</div>';
		}
		return $html . '</div></dialog>';
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
				'label'         => sanitize_text_field( (string) $definition['label'] ),
				'content'       => isset( $definition['content'] ) ? (string) $definition['content'] : '',
				'disabled'      => ! empty( $definition['disabled'] ),
				'count'         => isset( $definition['count'] ) ? (string) $definition['count'] : '',
				'count_variant' => isset( $definition['count_variant'] ) ? sanitize_key( $definition['count_variant'] ) : 'neutral',
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
			$html .= '><span>' . esc_html( $definition['label'] ) . '</span>';
			if ( '' !== $definition['count'] ) {
				$html .= self::counter( $definition['count'], $definition['count_variant'], array( 'class' => 'afcn-tab-counter' ) );
			}
			$html .= '</button>';
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

	private static function field_message( $args ) {
		if ( ! empty( $args['error'] ) ) {
			return '<small class="afcn-field-message is-error">' . esc_html( (string) $args['error'] ) . '</small>';
		}
		if ( ! empty( $args['help'] ) ) {
			return '<small class="afcn-field-message">' . esc_html( (string) $args['help'] ) . '</small>';
		}
		return '';
	}

	private static function attrs( $attrs ) {
		$html = '';
		foreach ( (array) $attrs as $key => $value ) {
			$key = sanitize_key( str_replace( '_', '-', (string) $key ) );
			if ( '' === $key || null === $value || false === $value ) {
				continue;
			}
			if ( true === $value ) {
				$html .= ' ' . esc_attr( $key );
				continue;
			}
			$html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}
		return $html;
	}

}
