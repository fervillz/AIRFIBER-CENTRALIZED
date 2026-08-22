<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Module_Manager {
	const OPTION_ENABLED = 'afcn_enabled_modules_v1';

	public static function navigation() {
		$items = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( ! self::is_enabled( $id, $module ) || ! self::dependencies_met( $module ) || ! self::user_can( $module ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $id,
				'name'     => $module['name'],
				'icon'     => $module['icon'],
				'position' => $module['position'],
			);
		}
		return $items;
	}

	public static function bootstrap_payload() {
		return array(
			'version' => AFCN_VERSION,
			'user'    => User_Manager::current_user_summary(),
			'modules' => self::navigation(),
		);
	}

	public static function is_enabled( $id, $module = null ) {
		$module = $module ? $module : Module_Registry::get( $id );
		if ( ! $module ) {
			return false;
		}
		if ( ! empty( $module['system'] ) ) {
			return true;
		}
		$enabled = get_option( self::OPTION_ENABLED, array() );
		if ( isset( $enabled[ $id ] ) ) {
			return (bool) $enabled[ $id ];
		}
		return ! empty( $module['default_enabled'] );
	}

	public static function set_enabled( $id, $enabled ) {
		$id      = sanitize_key( $id );
		$module  = Module_Registry::get( $id );
		$enabled = (bool) $enabled;
		if ( ! $module ) {
			return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $module['system'] ) ) {
			return new \WP_Error( 'afcn_system_module', __( 'Core system modules cannot be disabled.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_MODULES ) ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot manage modules.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$current = self::is_enabled( $id, $module );
		if ( $current === $enabled ) {
			return array( 'module' => $id, 'enabled' => $enabled );
		}

		if ( ! $enabled ) {
			$loaded = self::load( $id );
			if ( ! is_wp_error( $loaded ) && method_exists( $loaded['class'], 'deactivate' ) ) {
				$result = call_user_func( array( $loaded['class'], 'deactivate' ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$map        = get_option( self::OPTION_ENABLED, array() );
		$map[ $id ] = $enabled;
		update_option( self::OPTION_ENABLED, $map, false );

		if ( $enabled ) {
			$loaded = self::load( $id );
			if ( is_wp_error( $loaded ) ) {
				$map[ $id ] = false;
				update_option( self::OPTION_ENABLED, $map, false );
				return $loaded;
			}
			if ( method_exists( $loaded['class'], 'activate' ) ) {
				$result = call_user_func( array( $loaded['class'], 'activate' ) );
				if ( is_wp_error( $result ) ) {
					$map[ $id ] = false;
					update_option( self::OPTION_ENABLED, $map, false );
					return $result;
				}
			}
		}

		do_action( 'afcn_module_state_changed', $id, $enabled );
		Event_Bus::dispatch( 'module_state_changed', $id, $enabled );
		return array( 'module' => $id, 'enabled' => $enabled );
	}

	public static function dependencies_met( $module ) {
		$requires = isset( $module['requires'] ) && is_array( $module['requires'] ) ? $module['requires'] : array();
		if ( isset( $requires['core'] ) && is_string( $requires['core'] ) && '' !== $requires['core'] ) {
			$minimum = ltrim( $requires['core'], '>=' );
			if ( $minimum && version_compare( AFCN_VERSION, $minimum, '<' ) ) {
				return false;
			}
		}
		if ( isset( $requires['modules'] ) && is_array( $requires['modules'] ) ) {
			foreach ( $requires['modules'] as $dependency ) {
				$dependency = sanitize_key( $dependency );
				if ( ! $dependency || ! self::is_enabled( $dependency ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function user_can( $module ) {
		$capability = isset( $module['capability'] ) ? $module['capability'] : Capabilities::ACCESS;
		return current_user_can( 'manage_options' ) || current_user_can( $capability );
	}

	public static function load( $id ) {
		$id     = sanitize_key( $id );
		$module = Module_Registry::get( $id );
		if ( ! $module ) {
			return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_enabled( $id, $module ) ) {
			return new \WP_Error( 'afcn_module_disabled', __( 'This module is disabled.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		if ( ! self::dependencies_met( $module ) ) {
			return new \WP_Error( 'afcn_dependency_missing', __( 'This module has an unavailable dependency.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}
		if ( ! self::user_can( $module ) ) {
			return new \WP_Error( 'afcn_module_forbidden', __( 'You do not have permission to use this module.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		if ( empty( $module['system'] ) && Circuit_Breaker::is_quarantined( $id ) ) {
			$state = Circuit_Breaker::state( $id );
			return new \WP_Error( 'afcn_module_quarantined', isset( $state['message'] ) ? $state['message'] : __( 'This module was quarantined after repeated performance failures.', 'airfiber-centralized' ), array( 'status' => 503 ) );
		}

		$token = Performance_Monitor::start( $id, 'bootstrap' );
		$class = $module['class'];
		$ok    = class_exists( $class );
		Performance_Monitor::finish( $token );
		if ( ! $ok || ! is_subclass_of( $class, __NAMESPACE__ . '\\Module_Contract' ) ) {
			return new \WP_Error( 'afcn_module_class', __( 'The module class could not be loaded.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}
		return array( 'meta' => $module, 'class' => $class );
	}

	public static function render( $id ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$token  = Performance_Monitor::start( $id, 'render' );
		$html   = call_user_func( array( $loaded['class'], 'render' ), array( 'module' => $loaded['meta'] ) );
		$sample = Performance_Monitor::finish( $token );
		$assets = Assets::module_manifest( $loaded['meta'] );
		Performance_Monitor::record_assets( $id, $assets );
		return array(
			'id'      => $id,
			'name'    => $loaded['meta']['name'],
			'html'    => (string) $html,
			'assets'  => $assets,
			'health'  => Module_Health::summary( $id ),
			'timing'  => $sample,
		);
	}

	public static function handle_action( $id, $action, $payload ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$action = sanitize_key( $action );
		$token  = Performance_Monitor::start( $id, 'action' );
		$result = call_user_func( array( $loaded['class'], 'handle_action' ), $action, is_array( $payload ) ? $payload : array() );
		Performance_Monitor::finish( $token );
		return $result;
	}

	public static function statuses() {
		$output = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			$output[ $id ] = array(
				'meta'         => $module,
				'enabled'      => self::is_enabled( $id, $module ),
				'dependencies' => self::dependencies_met( $module ),
				'health'       => Module_Health::summary( $id ),
			);
		}
		return $output;
	}
}
