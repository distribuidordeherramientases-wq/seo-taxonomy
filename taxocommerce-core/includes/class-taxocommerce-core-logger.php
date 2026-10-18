<?php
/**
 * Core logger.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small bounded diagnostic logger.
 */
final class TaxoCommerce_Core_Logger {

	const OPTION_NAME = 'taxocommerce_core_log_entries';

	/**
	 * Adds an informational entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function info( $message, $context = array() ) {
		$this->add( 'info', $message, $context );
	}

	/**
	 * Adds a warning entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function warning( $message, $context = array() ) {
		$this->add( 'warning', $message, $context );
	}

	/**
	 * Adds an error entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function error( $message, $context = array() ) {
		$this->add( 'error', $message, $context );
	}

	/**
	 * Returns entries, newest first.
	 *
	 * @return array
	 */
	public function all() {
		$entries = get_option( self::OPTION_NAME, array() );
		return is_array( $entries ) ? array_reverse( $entries ) : array();
	}

	/**
	 * Clears entries.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Adds one bounded entry.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function add( $level, $message, $context ) {
		$level = sanitize_key( (string) $level );
		if ( ! in_array( $level, array( 'info', 'warning', 'error' ), true ) ) {
			$level = 'info';
		}

		$entries = get_option( self::OPTION_NAME, array() );
		$entries = is_array( $entries ) ? $entries : array();
		$limit   = absint( get_option( 'taxocommerce_core_log_limit', 100 ) );
		$limit   = min( 500, max( 20, $limit ) );

		$entries[] = array(
			'time'    => current_time( 'mysql', true ),
			'level'   => $level,
			'message' => sanitize_text_field( (string) $message ),
			'context' => $this->sanitize_context( $context ),
		);

		if ( count( $entries ) > $limit ) {
			$entries = array_slice( $entries, -1 * $limit );
		}

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Sanitizes context recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sanitize_context( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( (string) $key ) ] = $this->sanitize_context( $item );
			}
			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}
}
