<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Module_Registry {
	const OPTION_CACHE = 'afcn_module_registry_v1';
	const CACHE_SCHEMA = 8;

	private static $modules = null;

	public static function all( $refresh = false ) {
		if ( null !== self::$modules && ! $refresh ) {
			return self::$modules;
		}

		if ( ! $refresh ) {
			$cached = get_option( self::OPTION_CACHE, array() );
			if (
				is_array( $cached )
				&& isset( $cached['schema'], $cached['core_version'], $cached['modules'] )
				&& self::CACHE_SCHEMA === (int) $cached['schema']
				&& (string) AFCN_VERSION === (string) $cached['core_version']
				&& is_array( $cached['modules'] )
			) {
				self::$modules = $cached['modules'];
				return self::$modules;
			}
		}

		$modules = self::discover_from_disk();
		update_option(
			self::OPTION_CACHE,
			array(
				'schema'       => self::CACHE_SCHEMA,
				'core_version' => AFCN_VERSION,
				'built_at'     => time(),
				'modules'      => $modules,
			),
			false
		);
		self::$modules = $modules;
		return $modules;
	}

	public static function get( $id ) {
		$id      = sanitize_key( $id );
		$modules = self::all();
		return isset( $modules[ $id ] ) ? $modules[ $id ] : null;
	}

	public static function invalidate() {
		self::$modules = null;
		delete_option( self::OPTION_CACHE );
		if ( class_exists( __NAMESPACE__ . '\\Connector_Registry', false ) ) {
			Connector_Registry::invalidate();
		}
	}

	private static function discover_from_disk() {
		$modules = array();
		$sources = array(
			array(
				'type'    => 'mu',
				'pattern' => AFCN_PATH . 'modules/mu/*/module.json',
			),
			array(
				'type'    => 'module',
				'pattern' => AFCN_PATH . 'modules/*/module.json',
			),
		);

		foreach ( $sources as $source ) {
			$files = glob( $source['pattern'] );
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$raw = file_get_contents( $file );
				if ( false === $raw ) {
					continue;
				}

				$data = json_decode( $raw, true );
				if ( ! is_array( $data ) ) {
					Debug_Logger::warning( 'Invalid module manifest JSON.', array( 'file' => basename( dirname( $file ) ) ) );
					continue;
				}

				$folder = sanitize_key( basename( dirname( $file ) ) );
				$module = self::normalize( $data, $folder, $source['type'], $file );
				if ( is_wp_error( $module ) ) {
					Debug_Logger::warning( 'Module manifest rejected.', array( 'module' => $folder, 'reason' => $module->get_error_message() ) );
					continue;
				}

				if ( isset( $modules[ $module['id'] ] ) ) {
					Debug_Logger::warning( 'Duplicate module id ignored.', array( 'module' => $module['id'], 'source' => $source['type'] ) );
					continue;
				}

				$modules[ $module['id'] ] = $module;
			}
		}

		uasort(
			$modules,
			function ( $left, $right ) {
				if ( $left['position'] === $right['position'] ) {
					return strcasecmp( $left['name'], $right['name'] );
				}
				return $left['position'] < $right['position'] ? -1 : 1;
			}
		);

		return $modules;
	}

	private static function normalize( $data, $folder, $source, $file ) {
		$id    = isset( $data['id'] ) && '' !== (string) $data['id'] ? sanitize_key( $data['id'] ) : $folder;
		$name  = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$class = isset( $data['class'] ) && '' !== (string) $data['class']
			? ltrim( sanitize_text_field( $data['class'] ), '\\' )
			: Module_Naming::default_class( $folder );

		if ( $id !== $folder ) {
			return new \WP_Error( 'afcn_manifest_id', 'Manifest id must match its folder name when explicitly supplied.' );
		}
		if ( '' === $name ) {
			return new \WP_Error( 'afcn_manifest_required', 'Manifest name is required.' );
		}

		$expected_class = Module_Naming::namespace_prefix( $folder );
		if ( 0 !== strpos( $class, $expected_class ) ) {
			return new \WP_Error( 'afcn_manifest_class', 'Module class must live inside its Airfiber Next module namespace.' );
		}

		$assets       = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();
		$real         = realpath( $file );
		$is_mu        = 'mu' === $source;
		$presentation = isset( $data['presentation'] ) ? sanitize_key( $data['presentation'] ) : 'page';
		if ( ! in_array( $presentation, array( 'page', 'drawer' ), true ) ) {
			$presentation = 'page';
		}

		return array(
			'id'              => $id,
			'name'            => $name,
			'description'     => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
			'version'         => isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '0.0.0',
			'class'           => $class,
			'position'        => isset( $data['position'] ) ? (int) $data['position'] : 100,
			'icon'            => isset( $data['icon'] ) ? sanitize_key( $data['icon'] ) : 'box',
			'capability'      => isset( $data['capability'] ) ? sanitize_key( $data['capability'] ) : Capabilities::ACCESS,
			'parent'          => isset( $data['parent'] ) ? sanitize_key( $data['parent'] ) : '',
			'presentation'    => $presentation,
			'system'          => $is_mu,
			'source'          => $is_mu ? 'mu' : 'module',
			'default_enabled' => ! isset( $data['default_enabled'] ) || (bool) $data['default_enabled'],
			'settings'        => isset( $data['settings'] ) ? sanitize_key( $data['settings'] ) : '',
			'updates'         => ! empty( $data['updates'] ),
			'assets'          => array(
				'css' => isset( $assets['css'] ) && is_array( $assets['css'] ) ? array_values( array_map( 'sanitize_text_field', $assets['css'] ) ) : array(),
				'js'  => isset( $assets['js'] ) && is_array( $assets['js'] ) ? array_values( array_map( 'sanitize_text_field', $assets['js'] ) ) : array(),
			),
			'slots'           => self::normalize_slots( isset( $data['slots'] ) ? $data['slots'] : array() ),
			'connectors'      => self::normalize_connectors( isset( $data['connectors'] ) ? $data['connectors'] : array(), isset( $data['icon'] ) ? $data['icon'] : 'plug' ),
			'requires'        => isset( $data['requires'] ) && is_array( $data['requires'] ) ? $data['requires'] : array(),
			'events'          => isset( $data['events'] ) && is_array( $data['events'] ) ? array_values( array_map( 'sanitize_key', $data['events'] ) ) : array(),
			'path'            => dirname( $real ? $real : $file ),
			'url_path'        => $is_mu ? 'modules/mu/' . $folder : 'modules/' . $folder,
		);
	}

	private static function normalize_slots( $slots ) {
		if ( ! is_array( $slots ) ) {
			return array();
		}

		$output = array();
		foreach ( array_slice( $slots, 0, 20, true ) as $slot => $definition ) {
			$slot = Module_Slots::normalize_slot_id( $slot );
			if ( '' === $slot ) {
				continue;
			}

			if ( is_string( $definition ) ) {
				$definition = array( 'chunk' => $definition );
			}
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$chunk = isset( $definition['chunk'] ) ? sanitize_key( $definition['chunk'] ) : '';
			if ( '' === $chunk ) {
				continue;
			}

			$output[ $slot ] = array(
				'chunk'    => $chunk,
				'priority' => isset( $definition['priority'] ) ? (int) $definition['priority'] : 50,
				'span'     => isset( $definition['span'] ) ? max( 1, min( 12, (int) $definition['span'] ) ) : 4,
			);
		}
		return $output;
	}

	private static function normalize_connectors( $connectors, $fallback_icon ) {
		if ( ! is_array( $connectors ) ) {
			return array();
		}

		$output = array();
		foreach ( array_slice( $connectors, 0, 20 ) as $connector ) {
			if ( ! is_array( $connector ) ) {
				continue;
			}
			$id   = isset( $connector['id'] ) ? sanitize_key( $connector['id'] ) : '';
			$name = isset( $connector['name'] ) ? sanitize_text_field( $connector['name'] ) : '';
			if ( ! $id || ! $name ) {
				continue;
			}

			$output[] = array(
				'id'          => $id,
				'name'        => $name,
				'description' => isset( $connector['description'] ) ? sanitize_text_field( $connector['description'] ) : '',
				'group'       => isset( $connector['group'] ) ? sanitize_key( $connector['group'] ) : 'other',
				'icon'        => isset( $connector['icon'] ) ? sanitize_key( $connector['icon'] ) : sanitize_key( $fallback_icon ),
				'test_action' => isset( $connector['test_action'] ) ? sanitize_key( $connector['test_action'] ) : '',
				'fields'      => self::normalize_connector_fields( isset( $connector['fields'] ) ? $connector['fields'] : array() ),
			);
		}
		return $output;
	}

	private static function normalize_connector_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$allowed_types    = array( 'text', 'password', 'number', 'email', 'url', 'select', 'checkbox' );
		$allowed_displays = array( '', 'endpoint', 'meta' );
		$output           = array();

		foreach ( array_slice( $fields, 0, 30 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key   = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
			$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
			$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			if ( ! $key || ! $label ) {
				continue;
			}
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'text';
			}

			$display = isset( $field['display'] ) ? sanitize_key( $field['display'] ) : '';
			if ( ! in_array( $display, $allowed_displays, true ) ) {
				$display = '';
			}

			$options = array();
			if ( 'select' === $type && isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( array_slice( $field['options'], 0, 50, true ) as $value => $caption ) {
					$options[ sanitize_text_field( (string) $value ) ] = sanitize_text_field( (string) $caption );
				}
			}

			$show_when = array();
			if ( isset( $field['show_when'] ) && is_array( $field['show_when'] ) ) {
				$controller = isset( $field['show_when']['field'] ) ? sanitize_key( $field['show_when']['field'] ) : '';
				$value      = isset( $field['show_when']['value'] ) ? sanitize_text_field( (string) $field['show_when']['value'] ) : '';
				if ( $controller && '' !== $value && $controller !== $key ) {
					$show_when = array(
						'field' => $controller,
						'value' => $value,
					);
				}
			}

			$output[] = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $type,
				'required'    => ! empty( $field['required'] ),
				'secret'      => ! empty( $field['secret'] ) || 'password' === $type,
				'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
				'display'     => $display,
				'options'     => $options,
				'show_when'   => $show_when,
			);
		}

		return $output;
	}
}
