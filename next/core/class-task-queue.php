<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight durable background queue. Jobs should carry identifiers, not
 * large datasets or secrets. WP-Cron is the first runner; a dedicated runner
 * can consume the same queue later without changing module code.
 */
class Task_Queue {
	const HOOK          = 'afcn_run_task_queue';
	const OPTION        = 'afcn_task_queue_v1';
	const LOCK          = 'afcn_task_queue_lock';
	const MAX_TASKS     = 200;
	const MAX_PAYLOAD   = 16384;
	const MAX_ATTEMPTS  = 3;
	const BATCH_SIZE    = 5;

	public static function enqueue( $module, $action, $payload = array(), $delay = 0 ) {
		$module = sanitize_key( $module );
		$action = sanitize_key( $action );
		$meta   = Module_Registry::get( $module );
		if ( ! $meta || ! Module_Manager::is_enabled( $module, $meta ) ) {
			return new \WP_Error( 'afcn_task_module', __( 'The background task module is unavailable.', 'airfiber-centralized' ) );
		}
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'afcn_task_payload', __( 'Background task payload must be an array.', 'airfiber-centralized' ) );
		}
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PAYLOAD ) {
			return new \WP_Error( 'afcn_task_payload_size', __( 'Background task payload is too large. Queue identifiers and fetch the data later.', 'airfiber-centralized' ) );
		}

		$queue = self::queue();
		if ( count( $queue ) >= self::MAX_TASKS ) {
			return new \WP_Error( 'afcn_task_queue_full', __( 'The Airfiber background queue is full.', 'airfiber-centralized' ) );
		}

		$id      = wp_generate_uuid4();
		$delay   = max( 0, (int) $delay );
		$queue[] = array(
			'id'           => $id,
			'module'       => $module,
			'action'       => $action,
			'payload'      => $payload,
			'status'       => 'pending',
			'attempts'     => 0,
			'created_at'   => time(),
			'available_at' => time() + $delay,
			'last_error'   => '',
		);
		self::save( $queue );
		self::schedule_next( max( 1, $delay ) );
		return array( 'task_id' => $id, 'queued' => true );
	}

	public static function run() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 55 );

		$queue     = self::queue();
		$processed = 0;
		$now       = time();

		foreach ( $queue as $index => $task ) {
			if ( $processed >= self::BATCH_SIZE ) {
				break;
			}
			if ( ! is_array( $task ) || 'pending' !== ( $task['status'] ?? '' ) || (int) ( $task['available_at'] ?? 0 ) > $now ) {
				continue;
			}

			$processed++;
			$queue[ $index ]['status']   = 'running';
			$queue[ $index ]['attempts'] = (int) ( $task['attempts'] ?? 0 ) + 1;
			self::save( $queue );

			$result = Module_Manager::handle_background(
				$task['module'] ?? '',
				$task['action'] ?? '',
				isset( $task['payload'] ) && is_array( $task['payload'] ) ? $task['payload'] : array()
			);

			if ( ! is_wp_error( $result ) ) {
				unset( $queue[ $index ] );
				self::save( $queue );
				continue;
			}

			$attempts = (int) $queue[ $index ]['attempts'];
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				Debug_Logger::error(
					'Background task abandoned after repeated failure.',
					array( 'module' => $task['module'] ?? '', 'action' => $task['action'] ?? '', 'code' => $result->get_error_code() )
				);
				unset( $queue[ $index ] );
			} else {
				$queue[ $index ]['status']       = 'pending';
				$queue[ $index ]['last_error']   = sanitize_text_field( $result->get_error_message() );
				$queue[ $index ]['available_at'] = time() + ( 60 * ( 2 ** ( $attempts - 1 ) ) );
			}
			self::save( $queue );
		}

		delete_transient( self::LOCK );
		$queue = self::queue();
		if ( ! empty( $queue ) ) {
			$earliest = null;
			foreach ( $queue as $task ) {
				if ( ! is_array( $task ) || 'pending' !== ( $task['status'] ?? '' ) ) {
					continue;
				}
				$available = max( time() + 1, (int) ( $task['available_at'] ?? time() + 1 ) );
				$earliest  = null === $earliest ? $available : min( $earliest, $available );
			}
			if ( null !== $earliest ) {
				self::schedule_next( max( 1, $earliest - time() ) );
			}
		}
	}

	public static function stats() {
		$queue = self::queue();
		$out   = array( 'pending' => 0, 'running' => 0, 'total' => count( $queue ) );
		foreach ( $queue as $task ) {
			$status = isset( $task['status'] ) ? sanitize_key( $task['status'] ) : 'pending';
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ]++;
			}
		}
		return $out;
	}

	private static function queue() {
		$queue = get_option( self::OPTION, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	private static function save( $queue ) {
		$queue = array_values( is_array( $queue ) ? $queue : array() );
		update_option( self::OPTION, array_slice( $queue, 0, self::MAX_TASKS ), false );
	}

	private static function schedule_next( $delay ) {
		$next = wp_next_scheduled( self::HOOK );
		$time = time() + max( 1, (int) $delay );
		if ( ! $next || $next > $time + 5 ) {
			wp_schedule_single_event( $time, self::HOOK );
		}
	}
}
