<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Module_Manager {
	const OPTION_ENABLED = 'afcn_enabled_modules_v1';

	public static function navigation() {
		$items = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( Module_Trash::is_trashed( $id ) || ! self::is_enabled( $id, $module ) || ! self::dependencies_met( $module ) || ! self::user_can( $module ) ) {
				continue;
			}
			$items[] = array( 'id' => $id, 'name' => $module['name'], 'icon' => $module['icon'], 'position' => $module['position'] );
		}
		return $items;
	}

	public static function bootstrap_payload() {
		return array( 'version' => AFCN_VERSION, 'user' => User_Manager::current_user_summary(), 'modules' => self::navigation() );
	}

	public static function is_enabled( $id, $module = null ) {
		$module = $module ? $module : Module_Registry::get( $id );
		if ( ! $module ) { return false; }
		if ( ! empty( $module['system'] ) ) { return true; }
		if ( Module_Trash::is_trashed( $id ) ) { return false; }
		$enabled = get_option( self::OPTION_ENABLED, array() );
		return isset( $enabled[ $id ] ) ? (bool) $enabled[ $id ] : ! empty( $module['default_enabled'] );
	}

	public static function set_enabled( $id, $enabled ) {
		$id      = sanitize_key( $id );
		$module  = Module_Registry::get( $id );
		$enabled = (bool) $enabled;
		if ( ! $module ) { return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ), array( 'status' => 404 ) ); }
		if ( ! empty( $module['system'] ) ) { return new \WP_Error( 'afcn_system_module', __( 'Core MU modules cannot be disabled.', 'airfiber-centralized' ), array( 'status' => 400 ) ); }
		if ( $enabled && Module_Trash::is_trashed( $id ) ) { return new \WP_Error( 'afcn_module_trashed', __( 'Restore this module from Trash before activating it.', 'airfiber-centralized' ), array( 'status' => 409 ) ); }
		if ( ! Capabilities::is_super_admin_user() && ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_MODULES ) ) { return new \WP_Error( 'afcn_forbidden', __( 'You cannot manage modules.', 'airfiber-centralized' ), array( 'status' => 403 ) ); }
		if ( isset( $module['capability'] ) && Capabilities::SUPER_ADMIN === $module['capability'] && ! Capabilities::is_super_admin_user() ) {
			return new \WP_Error( 'afcn_super_admin_module', __( 'This developer module is reserved for the Airfiber Super Admin.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$current = self::is_enabled( $id, $module );
		if ( $current === $enabled ) { return array( 'module' => $id, 'enabled' => $enabled ); }

		if ( ! $enabled ) {
			$loaded = self::load( $id );
			if ( ! is_wp_error( $loaded ) && method_exists( $loaded['class'], 'deactivate' ) ) {
				$result = self::run( $id, 'deactivate', $loaded, array( $loaded['class'], 'deactivate' ) );
				if ( is_wp_error( $result ) ) { return $result; }
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
				$result = self::run( $id, 'activate', $loaded, array( $loaded['class'], 'activate' ) );
				if ( is_wp_error( $result ) ) {
					$map[ $id ] = false;
					update_option( self::OPTION_ENABLED, $map, false );
					return $result;
				}
			}
		}

		Audit_Log::record( $enabled ? 'module_enabled' : 'module_disabled', $id );
		do_action( 'afcn_module_state_changed', $id, $enabled );
		Event_Bus::dispatch( 'module_state_changed', $id, $enabled );
		return array( 'module' => $id, 'enabled' => $enabled );
	}

	public static function dependencies_met( $module ) {
		$requires = isset( $module['requires'] ) && is_array( $module['requires'] ) ? $module['requires'] : array();
		if ( isset( $requires['core'] ) && is_string( $requires['core'] ) && '' !== $requires['core'] ) {
			$minimum = ltrim( $requires['core'], '>=' );
			if ( $minimum && version_compare( AFCN_VERSION, $minimum, '<' ) ) { return false; }
		}
		if ( isset( $requires['modules'] ) && is_array( $requires['modules'] ) ) {
			foreach ( $requires['modules'] as $dependency ) {
				$dependency = sanitize_key( $dependency );
				if ( ! $dependency || ! self::is_enabled( $dependency ) ) { return false; }
			}
		}
		return true;
	}

	public static function user_can( $module ) {
		if ( ! is_array( $module ) ) {
			return false;
		}

		$capability = isset( $module['capability'] ) ? $module['capability'] : Capabilities::ACCESS;
		if ( Capabilities::SUPER_ADMIN === $capability ) {
			return Capabilities::is_super_admin_user();
		}
		if ( Capabilities::is_super_admin_user() ) {
			return true;
		}

		$allowed = current_user_can( 'manage_options' ) || current_user_can( $capability );
		if ( ! $allowed ) {
			return false;
		}

		return User_Access::can_view_module( get_current_user_id(), $module );
	}

	public static function load( $id, $check_user = true ) {
		$id     = sanitize_key( $id );
		$module = Module_Registry::get( $id );
		if ( ! $module ) { return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ), array( 'status' => 404 ) ); }
		if ( empty( $module['system'] ) && Module_Trash::is_trashed( $id ) ) { return new \WP_Error( 'afcn_module_trashed', __( 'This module is in Trash.', 'airfiber-centralized' ), array( 'status' => 410 ) ); }
		if ( ! self::is_enabled( $id, $module ) ) { return new \WP_Error( 'afcn_module_disabled', __( 'This module is disabled.', 'airfiber-centralized' ), array( 'status' => 403 ) ); }
		if ( ! self::dependencies_met( $module ) ) { return new \WP_Error( 'afcn_dependency_missing', __( 'This module has an unavailable dependency.', 'airfiber-centralized' ), array( 'status' => 409 ) ); }
		if ( $check_user && ! self::user_can( $module ) ) { return new \WP_Error( 'afcn_module_forbidden', __( 'You do not have permission to use this module.', 'airfiber-centralized' ), array( 'status' => 403 ) ); }
		if ( empty( $module['system'] ) && Circuit_Breaker::is_quarantined( $id ) ) {
			$state = Circuit_Breaker::state( $id );
			return new \WP_Error( 'afcn_module_quarantined', $state['message'] ?: __( 'This module was quarantined after repeated failures.', 'airfiber-centralized' ), array( 'status' => 503 ) );
		}

		$token = Performance_Monitor::start( $id, 'bootstrap' );
		$class = $module['class'];
		try {
			$ok = class_exists( $class );
		} catch ( \Throwable $error ) {
			Performance_Monitor::finish( $token, array( 'failed' => true ) );
			Circuit_Breaker::record_failure( $id, 'bootstrap', $error );
			return new \WP_Error( 'afcn_module_bootstrap_failed', __( 'The module failed while starting.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}
		Performance_Monitor::finish( $token );
		if ( ! $ok || ! is_subclass_of( $class, __NAMESPACE__ . '\\Module_Contract' ) ) {
			return new \WP_Error( 'afcn_module_class', __( 'The module class could not be loaded.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}
		return array( 'meta' => $module, 'class' => $class );
	}

	public static function render( $id ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) { return $loaded; }
		$result = self::run( $id, 'render', $loaded, array( $loaded['class'], 'render' ), array( 'module' => $loaded['meta'] ) );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( ! is_scalar( $result ) && null !== $result ) { return new \WP_Error( 'afcn_module_render_type', __( 'The module returned invalid page content.', 'airfiber-centralized' ), array( 'status' => 500 ) ); }
		$assets = Assets::module_manifest( $loaded['meta'] );
		Performance_Monitor::record_assets( $id, $assets );
		return array( 'id' => $id, 'name' => $loaded['meta']['name'], 'html' => (string) $result, 'assets' => $assets, 'health' => Module_Health::summary( $id ) );
	}

	public static function render_chunk( $id, $chunk, $payload = array() ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) { return $loaded; }
		if ( ! method_exists( $loaded['class'], 'render_chunk' ) ) { return new \WP_Error( 'afcn_chunk_unsupported', __( 'This module does not expose lazy chunks.', 'airfiber-centralized' ), array( 'status' => 404 ) ); }
		$chunk  = sanitize_key( $chunk );
		$result = self::run( $id, 'render', $loaded, array( $loaded['class'], 'render_chunk' ), $chunk, is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $result ) ) { return $result; }
		$assets = Assets::module_manifest( $loaded['meta'] );
		Performance_Monitor::record_assets( $id, $assets );
		return array( 'id' => $id, 'chunk' => $chunk, 'html' => (string) $result, 'assets' => $assets );
	}

	public static function handle_query( $id, $query, $payload = array() ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) { return $loaded; }
		if ( ! method_exists( $loaded['class'], 'handle_query' ) ) { return new \WP_Error( 'afcn_query_unsupported', __( 'This module does not expose data queries.', 'airfiber-centralized' ), array( 'status' => 404 ) ); }
		return self::run( $id, 'query', $loaded, array( $loaded['class'], 'handle_query' ), sanitize_key( $query ), is_array( $payload ) ? $payload : array() );
	}

	public static function handle_action( $id, $action, $payload ) {
		$loaded = self::load( $id );
		if ( is_wp_error( $loaded ) ) { return $loaded; }
		return self::run( $id, 'action', $loaded, array( $loaded['class'], 'handle_action' ), sanitize_key( $action ), is_array( $payload ) ? $payload : array() );
	}

	public static function handle_background( $id, $action, $payload ) {
		$loaded = self::load( $id, false );
		if ( is_wp_error( $loaded ) ) { return $loaded; }
		if ( ! method_exists( $loaded['class'], 'handle_background' ) ) { return new \WP_Error( 'afcn_background_unsupported', __( 'This module does not expose background tasks.', 'airfiber-centralized' ) ); }
		return self::run( $id, 'background', $loaded, array( $loaded['class'], 'handle_background' ), sanitize_key( $action ), is_array( $payload ) ? $payload : array() );
	}

	public static function statuses() {
		$output = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( ! Capabilities::is_super_admin_user() && isset( $module['capability'] ) && Capabilities::SUPER_ADMIN === $module['capability'] ) {
				continue;
			}
			$output[ $id ] = array(
				'meta'         => $module,
				'enabled'      => self::is_enabled( $id, $module ),
				'trashed'      => empty( $module['system'] ) && Module_Trash::is_trashed( $id ),
				'dependencies' => self::dependencies_met( $module ),
				'health'       => Module_Health::summary( $id ),
			);
		}
		return $output;
	}

	private static function run( $id, $phase, $loaded, $callback ) {
		$args  = array_slice( func_get_args(), 4 );
		$token = Performance_Monitor::start( $id, $phase );
		try {
			$result = call_user_func_array( $callback, $args );
		} catch ( \Throwable $error ) {
			Performance_Monitor::finish( $token, array( 'failed' => true ) );
			Circuit_Breaker::record_failure( $id, $phase, $error );
			return new \WP_Error( 'afcn_module_runtime_failure', __( 'The module encountered an error and was isolated from the rest of Airfiber.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}
		Performance_Monitor::finish( $token );
		return $result;
	}
}
