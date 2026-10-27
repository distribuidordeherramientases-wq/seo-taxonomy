<?php
/**
 * Adaptive work-pressure controller for TaxoCommerce Images.
 *
 * The controller is fully local to Images: it owns its priority, adaptive
 * batch sizes and request samples and never reads or changes another plugin's
 * workload settings.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_Load {

	const PRIORITY_OPTION  = 'taxocommerce_images_work_priority';
	const BATCH_PREFIX     = 'taxocommerce_images_adaptive_batch_';
	const SAMPLE_PREFIX    = 'taxocommerce_images_load_sample_';
	const DEFAULT_PRIORITY = 3;

	/**
	 * Current Images priority, 1 (soft) to 5 (maximum).
	 *
	 * @return int
	 */
	public static function priority( $process = 'generic' ) {
		$process = self::process_key( $process );
		$stored = get_option( self::PRIORITY_OPTION, self::DEFAULT_PRIORITY );
		if ( is_array( $stored ) ) {
			$value = isset( $stored[ $process ] ) ? $stored[ $process ] : ( $stored['default'] ?? self::DEFAULT_PRIORITY );
		} else {
			// Backward compatibility with the former single Images-wide priority.
			$value = $stored;
		}
		return max( 1, min( 5, absint( $value ) ) );
	}

	/**
	 * Store a new Images-only priority and reset adaptive batches.
	 *
	 * @param mixed $raw Submitted priority.
	 * @return int|WP_Error
	 */
	public static function set_priority( $raw, $process = 'generic' ) {
		if ( ! is_scalar( $raw ) || ! preg_match( '/^[1-5]$/', trim( (string) $raw ) ) ) {
			return new WP_Error( 'taxocommerce_images_priority_invalid', __( 'La prioridad debe estar entre 1 y 5.', 'taxocommerce-images' ) );
		}

		$process  = self::process_key( $process );
		$priority = (int) $raw;
		$stored   = get_option( self::PRIORITY_OPTION, self::DEFAULT_PRIORITY );
		if ( ! is_array( $stored ) ) {
			$legacy = max( 1, min( 5, absint( $stored ) ) );
			$stored = array( 'default' => $legacy );
		}
		$stored[ $process ] = $priority;
		update_option( self::PRIORITY_OPTION, $stored, false );
		update_option( self::batch_option( $process ), self::initial_batch( $process, $priority ), false );

		return $priority;
	}

	/**
	 * Human label matching Dependiente's control semantics.
	 *
	 * @param int|null $priority Priority.
	 * @return string
	 */
	public static function label( $priority = null ) {
		$priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
		$labels = array(
			1 => __( 'Suave', 'taxocommerce-images' ),
			2 => __( 'Baja', 'taxocommerce-images' ),
			3 => __( 'Normal', 'taxocommerce-images' ),
			4 => __( 'Alta', 'taxocommerce-images' ),
			5 => __( 'Máxima', 'taxocommerce-images' ),
		);
		return $labels[ $priority ];
	}

	/**
	 * Target request-local CPU percentage.
	 *
	 * @param int|null $priority Priority.
	 * @return int
	 */
	public static function target_cpu( $priority = null ) {
		$priority = null === $priority ? self::priority() : max( 1, min( 5, absint( $priority ) ) );
		$targets = array( 1 => 20, 2 => 35, 3 => 50, 4 => 70, 5 => 90 );
		return $targets[ $priority ];
	}

	/**
	 * Pause between AJAX lots.
	 *
	 * @param int|null $priority Priority.
	 * @return int Milliseconds.
	 */
	public static function delay_ms( $process = 'generic', $priority = null ) {
		$priority = null === $priority ? self::priority( $process ) : max( 1, min( 5, absint( $priority ) ) );
		$delays = array( 1 => 15000, 2 => 8000, 3 => 4000, 4 => 2000, 5 => 1000 );
		return $delays[ $priority ];
	}

	/**
	 * Current adaptive batch for one Images process.
	 *
	 * Cleanup hashes files and therefore starts/caps lower than metadata labels.
	 *
	 * @param string $process Process.
	 * @return int
	 */
	public static function batch_size( $process ) {
		$process = self::process_key( $process );
		$current = absint( get_option( self::batch_option( $process ), 0 ) );

		if ( $current < 1 ) {
			$current = self::initial_batch( $process );
			update_option( self::batch_option( $process ), $current, false );
		}

		return min( $current, self::max_batch( $process ) );
	}

	/**
	 * Last request-local load sample.
	 *
	 * @param string $process Process.
	 * @return array
	 */
	public static function last_sample( $process ) {
		$sample = get_option( self::sample_option( $process ), array() );
		return is_array( $sample ) ? $sample : array();
	}

	/**
	 * Begin request-local measurement.
	 *
	 * @return array
	 */
	public static function begin() {
		return array(
			'wall'    => microtime( true ),
			'cpu'     => self::cpu_seconds(),
			'queries' => function_exists( 'get_num_queries' ) ? get_num_queries() : 0,
		);
	}

	/**
	 * Finish measurement and adapt the next lot.
	 *
	 * @param string $process Process.
	 * @param array  $start Begin sample.
	 * @param int    $items Processed items.
	 * @return array
	 */
	public static function finish( $process, $start, $items ) {
		$process = self::process_key( $process );
		$start   = is_array( $start ) ? $start : array();

		$elapsed   = max( 0.0001, microtime( true ) - (float) ( $start['wall'] ?? microtime( true ) ) );
		$cpu_start = isset( $start['cpu'] ) && is_numeric( $start['cpu'] ) ? (float) $start['cpu'] : null;
		$cpu_end   = self::cpu_seconds();
		$cpu       = null;

		if ( null !== $cpu_start && null !== $cpu_end && $cpu_end >= $cpu_start ) {
			$cpu = min( 100, max( 0, 100 * ( $cpu_end - $cpu_start ) / $elapsed ) );
		}

		$memory_peak    = memory_get_peak_usage( true );
		$memory_limit   = self::memory_limit_bytes();
		$memory_percent = $memory_limit > 0 ? min( 100, 100 * $memory_peak / $memory_limit ) : null;
		$query_now      = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;
		$queries        = max( 0, absint( $query_now ) - absint( $start['queries'] ?? 0 ) );

		$priority = self::priority( $process );
		$current  = self::batch_size( $process );
		$next     = $current;
		$target   = self::target_cpu( $priority );

		$wall_target = array( 1 => 3.0, 2 => 4.5, 3 => 6.0, 4 => 8.0, 5 => 11.0 )[ $priority ];
		$reduce = $elapsed >= $wall_target
			|| ( null !== $memory_percent && $memory_percent >= 75 )
			|| ( null !== $cpu && $cpu > ( $target + 10 ) );

		if ( $reduce ) {
			$next = self::previous_level( $process, $current );
		} elseif (
			null !== $cpu
			&& $cpu < max( 5, $target - 15 )
			&& ( null === $memory_percent || $memory_percent < 60 )
			&& $elapsed < 10
		) {
			$next = self::next_level( $process, $current );
		}

		update_option( self::batch_option( $process ), $next, false );

		$sample = array(
			'process'        => $process,
			'priority'       => $priority,
			'label'          => self::label( $priority ),
			'items'          => absint( $items ),
			'batch'          => $current,
			'next_batch'     => $next,
			'elapsed'        => round( $elapsed, 3 ),
			'cpu_percent'    => null === $cpu ? null : round( $cpu, 1 ),
			'memory_peak'    => $memory_peak,
			'memory_percent' => null === $memory_percent ? null : round( $memory_percent, 1 ),
			'queries'        => $queries,
			'at'             => gmdate( 'c' ),
		);

		update_option( self::sample_option( $process ), $sample, false );
		return $sample;
	}

	/**
	 * Initial batch by process and priority.
	 *
	 * @param string   $process Process.
	 * @param int|null $priority Priority.
	 * @return int
	 */
	private static function initial_batch( $process, $priority = null ) {
		$process  = self::process_key( $process );
		$priority = null === $priority ? self::priority( $process ) : max( 1, min( 5, absint( $priority ) ) );

		if ( 'cleanup' === $process ) {
			$values = array( 1 => 1, 2 => 2, 3 => 3, 4 => 5, 5 => 8 );
		} elseif ( 0 === strpos( $process, 'assignment_' ) ) {
			$values = array( 1 => 1, 2 => 2, 3 => 4, 4 => 7, 5 => 12 );
		} else {
			$values = array( 1 => 5, 2 => 10, 3 => 20, 4 => 30, 5 => 40 );
		}
		return $values[ $priority ];
	}

	/**
	 * Maximum batch by process and priority.
	 *
	 * @param string   $process Process.
	 * @param int|null $priority Priority.
	 * @return int
	 */
	private static function max_batch( $process, $priority = null ) {
		$process  = self::process_key( $process );
		$priority = null === $priority ? self::priority( $process ) : max( 1, min( 5, absint( $priority ) ) );

		if ( 'cleanup' === $process ) {
			$values = array( 1 => 3, 2 => 5, 3 => 10, 4 => 15, 5 => 25 );
		} elseif ( 0 === strpos( $process, 'assignment_' ) ) {
			$values = array( 1 => 2, 2 => 4, 3 => 8, 4 => 15, 5 => 25 );
		} else {
			$values = array( 1 => 10, 2 => 20, 3 => 35, 4 => 50, 5 => 75 );
		}
		return $values[ $priority ];
	}

	/**
	 * Available discrete batch ladder for one process.
	 *
	 * @param string $process Process.
	 * @return int[]
	 */
	private static function levels( $process ) {
		$process = self::process_key( $process );
		if ( 'cleanup' === $process ) {
			return array( 1, 2, 3, 5, 8, 10, 15, 20, 25 );
		}
		if ( 0 === strpos( $process, 'assignment_' ) ) {
			return array( 1, 2, 3, 4, 5, 7, 8, 10, 12, 15, 20, 25 );
		}
		return array( 5, 10, 15, 20, 25, 30, 35, 40, 50, 60, 75 );
	}

	private static function next_level( $process, $current ) {
		$maximum = self::max_batch( $process );
		foreach ( self::levels( $process ) as $level ) {
			if ( $level > $current && $level <= $maximum ) {
				return $level;
			}
		}
		return min( $current, $maximum );
	}

	private static function previous_level( $process, $current ) {
		$target = max( 1, (int) floor( $current / 2 ) );
		$chosen = self::levels( $process )[0];
		foreach ( self::levels( $process ) as $level ) {
			if ( $level > $target ) {
				break;
			}
			$chosen = $level;
		}
		return $chosen;
	}

	private static function batch_option( $process ) {
		return self::BATCH_PREFIX . self::process_key( $process );
	}

	private static function sample_option( $process ) {
		return self::SAMPLE_PREFIX . self::process_key( $process );
	}

	private static function process_key( $process ) {
		$key = sanitize_key( (string) $process );
		return '' === $key ? 'generic' : $key;
	}

	private static function cpu_seconds() {
		if ( ! function_exists( 'getrusage' ) ) {
			return null;
		}
		$usage = getrusage();
		if ( ! is_array( $usage ) ) {
			return null;
		}
		$user   = (float) ( $usage['ru_utime.tv_sec'] ?? 0 ) + ( (float) ( $usage['ru_utime.tv_usec'] ?? 0 ) / 1000000 );
		$system = (float) ( $usage['ru_stime.tv_sec'] ?? 0 ) + ( (float) ( $usage['ru_stime.tv_usec'] ?? 0 ) / 1000000 );
		return $user + $system;
	}

	private static function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (float) $raw;
		if ( 'g' === $unit ) {
			$value *= 1024 * 1024 * 1024;
		} elseif ( 'm' === $unit ) {
			$value *= 1024 * 1024;
		} elseif ( 'k' === $unit ) {
			$value *= 1024;
		}
		return max( 0, (int) $value );
	}
}
