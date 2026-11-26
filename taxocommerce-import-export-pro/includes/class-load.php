<?php
/**
 * Per-job adaptive PHP workload controller.
 *
 * @package TaxoCommerceImportExportPro
 */
namespace TaxoCommerce\ImportExportPro;

defined( 'ABSPATH' ) || exit;

final class Load {
	public static function profiles() {
		return array(
			1 => array( 'initial' => 5, 'max' => 20, 'seconds' => 3, 'delay' => 15 ),
			2 => array( 'initial' => 15, 'max' => 50, 'seconds' => 5, 'delay' => 8 ),
			3 => array( 'initial' => 25, 'max' => 100, 'seconds' => 8, 'delay' => 4 ),
			4 => array( 'initial' => 50, 'max' => 200, 'seconds' => 10, 'delay' => 2 ),
			5 => array( 'initial' => 100, 'max' => 400, 'seconds' => 12, 'delay' => 1 ),
		);
	}

	public static function profile( $speed ) {
		return self::profiles()[ (int) $speed ] ?? self::profiles()[3];
	}

	public static function memory_percent() {
		$raw = strtolower( trim( (string) ini_get( 'memory_limit' ) ) );
		$bytes = (float) $raw;
		$scales = array( 'k' => 1024, 'm' => 1048576, 'g' => 1073741824 );
		$bytes *= $scales[ substr( $raw, -1 ) ] ?? 1;
		return $bytes > 0 ? 100 * memory_get_usage( true ) / $bytes : null;
	}

	private static function cpu() {
		if ( ! function_exists( 'getrusage' ) ) {
			return null;
		}
		$usage = getrusage();
		return is_array( $usage ) ? (float) ( $usage['ru_utime.tv_sec'] ?? 0 ) + (float) ( $usage['ru_utime.tv_usec'] ?? 0 ) / 1000000 + (float) ( $usage['ru_stime.tv_sec'] ?? 0 ) + (float) ( $usage['ru_stime.tv_usec'] ?? 0 ) / 1000000 : null;
	}

	public static function begin( $speed, $previous = array() ) {
		$profile = self::profile( $speed );
		$php_limit = (int) ini_get( 'max_execution_time' );
		$seconds = $php_limit > 0 ? max( 1, min( $profile['seconds'], $php_limit * 0.5 ) ) : $profile['seconds'];
		$size = (int) ( $previous['speed'] ?? 0 ) === (int) $speed ? (int) ( $previous['next'] ?? $profile['initial'] ) : $profile['initial'];
		return array( 'start' => microtime( true ), 'cpu' => self::cpu(), 'budget' => $seconds, 'size' => max( 1, min( $profile['max'], $size ) ), 'speed' => (int) $speed );
	}

	public static function can_continue( $window, $rows ) {
		$memory = self::memory_percent();
		return $rows < $window['size'] && microtime( true ) - $window['start'] < $window['budget'] && ( null === $memory || $memory < 78 );
	}

	public static function adjust( $size, $maximum, $rows, $elapsed, $budget, $memory, $cpu ) {
		if ( ( null !== $memory && $memory >= 75 ) || $elapsed >= $budget || ( null !== $cpu && $cpu >= 95 && $elapsed >= 0.8 * $budget ) ) {
			return max( 1, (int) floor( $size / 2 ) );
		}
		if ( $rows >= $size && $elapsed < $budget * 0.5 && ( null === $memory || $memory < 60 ) ) {
			return min( $maximum, $size * 2 );
		}
		return max( 1, min( $size, $maximum ) );
	}

	public static function finish( $window, $rows ) {
		$elapsed = max( 0.001, microtime( true ) - $window['start'] );
		$end = self::cpu();
		$cpu = null !== $end && null !== $window['cpu'] ? max( 0, min( 100, 100 * ( $end - $window['cpu'] ) / $elapsed ) ) : null;
		$memory = self::memory_percent();
		return array(
			'speed' => $window['speed'], 'rows' => $rows, 'seconds' => round( $elapsed, 3 ),
			'cpu' => null === $cpu ? null : round( $cpu, 1 ), 'memory' => null === $memory ? null : round( $memory, 1 ),
			'next' => self::adjust( $window['size'], self::profile( $window['speed'] )['max'], $rows, $elapsed, $window['budget'], $memory, $cpu ),
		);
	}
}
