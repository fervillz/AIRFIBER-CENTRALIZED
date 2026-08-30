<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Small reusable search + paging helper for bounded in-memory row sets.
 *
 * Modules own data retrieval and authorization. Core only normalizes a search
 * query, matches it against scalar row values, and returns one page.
 */
class Data_Query {

	public static function apply( $rows, $args = array() ) {
		$rows      = array_values( array_filter( (array) $rows, 'is_array' ) );
		$search    = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$page      = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$page_size = isset( $args['page_size'] ) ? max( 1, min( 100, absint( $args['page_size'] ) ) ) : 20;
		$fields    = isset( $args['search_fields'] ) && is_array( $args['search_fields'] ) ? array_values( array_filter( array_map( 'strval', $args['search_fields'] ) ) ) : array();

		if ( '' !== $search ) {
			$needle = self::normalize( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $needle, $fields ) {
						return false !== strpos( self::row_text( $row, $fields ), $needle );
					}
				)
			);
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $page_size ) );
		$page  = min( $page, $pages );
		$from  = $total ? ( ( $page - 1 ) * $page_size ) + 1 : 0;
		$to    = $total ? min( $total, $from + $page_size - 1 ) : 0;

		return array(
			'rows'       => array_slice( $rows, ( $page - 1 ) * $page_size, $page_size ),
			'pagination' => array(
				'page'      => $page,
				'page_size' => $page_size,
				'pages'     => $pages,
				'total'     => $total,
				'from'      => $from,
				'to'        => $to,
			),
		);
	}

	private static function row_text( $row, $fields ) {
		$values = array();
		if ( $fields ) {
			foreach ( $fields as $field ) {
				if ( array_key_exists( $field, $row ) ) {
					self::collect_values( $row[ $field ], $values, 0 );
				}
			}
		} else {
			foreach ( $row as $value ) {
				self::collect_values( $value, $values, 0 );
			}
		}
		return self::normalize( implode( ' ', $values ) );
	}

	private static function collect_values( $value, &$values, $depth ) {
		if ( $depth > 3 ) {
			return;
		}
		if ( is_scalar( $value ) || null === $value ) {
			$values[] = (string) $value;
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				self::collect_values( $child, $values, $depth + 1 );
			}
		}
	}

	private static function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
