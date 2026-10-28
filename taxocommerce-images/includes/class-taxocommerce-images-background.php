<?php
/**
 * Persistent, browser-independent processing for TaxoCommerce Images.
 *
 * Uses native WordPress cron, not Action Scheduler. For unattended execution
 * without site traffic configure a real hosting cron to invoke wp-cron.php.
 *
 * @package TaxoCommerceImages
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_Background {
	const OPTION = 'taxocommerce_images_background_jobs';
	const HOOK   = 'taxocommerce_images_background_tick';

	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'wp_ajax_taxocommerce_images_background_control', array( __CLASS__, 'ajax' ) );
		add_action( 'init', array( __CLASS__, 'watchdog' ), 99 );
	}

	public static function modes() {
		$modes = array( 'audit', 'labels', 'cleanup', 'optimize', 'webp' );
		foreach ( array_keys( TaxoCommerce_Images_Assignment::scopes() ) as $scope ) {
			$modes[] = 'assignment_' . $scope;
		}
		return $modes;
	}

	private static function all() {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	public static function state( $mode ) {
		$jobs = self::all();
		return isset( $jobs[ $mode ] ) && is_array( $jobs[ $mode ] ) ? $jobs[ $mode ] : array();
	}

	private static function save( $mode, $job ) {
		$jobs = self::all();
		$jobs[ $mode ] = $job;
		update_option( self::OPTION, $jobs, false );
	}

	/**
	 * Register a job after its existing scan state has been initialized.
	 * Only optimize/webp use their own ID snapshot and results.
	 */
	public static function start( $mode, $actor_id ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, self::modes(), true ) ) {
			return new WP_Error( 'tci_background_mode', __( 'Proceso de imágenes no válido.', 'taxocommerce-images' ) );
		}
		$actor_id = absint( $actor_id );
		if ( ! $actor_id || ! user_can( $actor_id, 'manage_options' )
			|| ( in_array( $mode, array( 'optimize', 'webp' ), true ) && ! user_can( $actor_id, 'upload_files' ) ) ) {
			return new WP_Error( 'tci_background_access', __( 'Administrador sin permisos para ejecutar el proceso.', 'taxocommerce-images' ) );
		}
		$old = self::state( $mode );
		if ( 'running' === ( $old['status'] ?? '' ) ) {
			return $old;
		}
		global $wpdb;
		$total = 0;
		$max = 0;
		if ( in_array( $mode, array( 'optimize', 'webp' ), true ) ) {
			$mimes = 'webp' === $mode
				? array( 'image/jpeg', 'image/png' )
				: array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
			$marks = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
			$sql = "SELECT COUNT(*) AS total, COALESCE(MAX(ID),0) AS max_id FROM %i WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ($marks)";
			$rows = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $wpdb->posts ), $mimes ) ), ARRAY_A );
			$total = absint( $rows['total'] ?? 0 );
			$max = absint( $rows['max_id'] ?? 0 );
		}
		// A paused scan resumes from its saved cursor; completed/stopped
		// mutation jobs require an explicit fresh start.
		$resume = 'paused' === ( $old['status'] ?? '' );
		$job = $resume ? $old : array(
			'mode' => $mode,
			'actor' => $actor_id,
			'status' => 'running',
			'cursor' => 0,
			'total' => $total,
			'max_id' => $max,
			'processed' => 0,
			'changed' => 0,
			'errors' => array(),
			'started_at' => current_time( 'mysql' ),
			'last_tick' => 0,
		);
		$job['actor'] = $actor_id;
		$job['status'] = 'running';
		$job['next_run'] = time();
		self::save( $mode, $job );
		self::schedule( 0 );
		return $job;
	}

	private static function schedule( $delay ) {
		$at = time() + max( 0, (int) $delay );
		$existing = wp_next_scheduled( self::HOOK );
		if ( $existing && $existing <= $at ) {
			return;
		}
		if ( $existing ) {
			wp_unschedule_event( $existing, self::HOOK );
		}
		wp_schedule_single_event( $at, self::HOOK );
		if ( $at <= time() && function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron( time() );
		}
	}

	/** Re-arm a missing cron event if another WordPress request is available. */
	public static function watchdog() {
		$soonest = null;
		foreach ( self::all() as $job ) {
			if ( 'running' !== ( $job['status'] ?? '' ) ) {
				continue;
			}
			$next = absint( $job['next_run'] ?? 0 );
			$soonest = null === $soonest ? $next : min( $soonest, $next );
		}
		if ( null !== $soonest && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule( max( 0, $soonest - time() ) );
		}
	}

	/**
	 * One bounded chunk per cron tick. The actor is checked again before
	 * executing and the former current user is always restored.
	 */
	public static function tick() {
		if ( ! TaxoCommerce_Images_Maintenance::lock( 'background_worker' ) ) {
			self::schedule( 15 );
			return;
		}
		$previous_user = get_current_user_id();
		try {
			$jobs = self::all();
			$selected = '';
			$soonest = null;
			foreach ( $jobs as $mode => $job ) {
				if ( 'running' !== ( $job['status'] ?? '' ) ) {
					continue;
				}
				$next = absint( $job['next_run'] ?? 0 );
				if ( $next <= time() && ( '' === $selected || $next < absint( $jobs[ $selected ]['next_run'] ?? 0 ) ) ) {
					$selected = $mode;
				}
				$soonest = null === $soonest ? $next : min( $soonest, $next );
			}
			if ( '' === $selected ) {
				if ( null !== $soonest ) {
					self::schedule( max( 1, $soonest - time() ) );
				}
				return;
			}
			$job = $jobs[ $selected ];
			$actor = absint( $job['actor'] ?? 0 );
			if ( ! user_can( $actor, 'manage_options' )
				|| ( in_array( $selected, array( 'optimize', 'webp' ), true ) && ! user_can( $actor, 'upload_files' ) ) ) {
				$job['status'] = 'error';
				$job['message'] = __( 'El administrador ya no dispone de permisos.', 'taxocommerce-images' );
				self::save( $selected, $job );
				return;
			}
			wp_set_current_user( $actor );
			try {
				$result = self::chunk( $selected, $job );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
				$job = $result;
				$job['failures'] = 0;
			} catch ( Throwable $error ) {
				$job['failures'] = absint( $job['failures'] ?? 0 ) + 1;
				$job['message'] = sanitize_text_field( $error->getMessage() );
				if ( $job['failures'] >= 3 ) {
					$job['status'] = 'error';
				}
			}
			$job['last_tick'] = time();
			$delay = max( 1, (int) ceil( TaxoCommerce_Images_Load::delay_ms( $selected ) / 1000 ) );
			$job['next_run'] = time() + $delay;
			self::save( $selected, $job );
			$next = null;
			foreach ( self::all() as $item ) {
				if ( 'running' !== ( $item['status'] ?? '' ) ) {
					continue;
				}
				$when = absint( $item['next_run'] ?? time() );
				$next = null === $next ? $when : min( $next, $when );
			}
			if ( null !== $next ) {
				self::schedule( max( 1, $next - time() ) );
			}
		} finally {
			wp_set_current_user( $previous_user );
			TaxoCommerce_Images_Maintenance::unlock( 'background_worker' );
		}
	}

	private static function chunk( $mode, $job ) {
		if ( 'audit' === $mode ) {
			$audit = new TaxoCommerce_Images_Audit();
			$state = $audit->run_background_batch();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$job['processed'] = absint( $state['scanned'] ?? 0 );
			$job['total'] = absint( $state['total'] ?? 0 );
			if ( 'completed' === ( $state['status'] ?? '' ) ) {
				$job['status'] = 'complete';
			}
			return $job;
		}
		if ( in_array( $mode, array( 'labels', 'cleanup' ), true ) ) {
			if ( ! TaxoCommerce_Images_Maintenance::lock( $mode ) ) {
				return new WP_Error( 'tci_busy', 'El proceso ya está ejecutándose.' );
			}
			try {
				$measurement = TaxoCommerce_Images_Load::begin();
				$before = TaxoCommerce_Images_Maintenance::state( $mode );
				$state = TaxoCommerce_Images_Maintenance::scan( $mode );
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				TaxoCommerce_Images_Load::finish( $mode, $measurement, max( 0, absint( $state['scanned'] ?? 0 ) - absint( $before['scanned'] ?? 0 ) ) );
				$job['processed'] = absint( $state['scanned'] ?? 0 );
				$job['total'] = absint( $state['total'] ?? 0 );
				if ( 'complete' === ( $state['status'] ?? '' ) ) {
					$job['status'] = 'complete';
				}
			} finally {
				TaxoCommerce_Images_Maintenance::unlock( $mode );
			}
			return $job;
		}
		if ( 0 === strpos( $mode, 'assignment_' ) ) {
			$scope = substr( $mode, strlen( 'assignment_' ) );
			$state = TaxoCommerce_Images_Assignment::run_background_batch( $scope );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$job['processed'] = absint( $state['seen'] ?? 0 );
			$job['total'] = absint( $state['total'] ?? 0 );
			$job['changed'] = absint( $state['assigned'] ?? 0 );
			if ( 'complete' === ( $state['status'] ?? '' ) ) {
				$job['status'] = 'complete';
			}
			return $job;
		}
		if ( ! in_array( $mode, array( 'optimize', 'webp' ), true ) ) {
			return new WP_Error( 'tci_mode', 'Modo no compatible.' );
		}
		if ( ! TaxoCommerce_Images_Maintenance::lock( 'mutation' ) ) {
			return new WP_Error( 'tci_mutation_busy', 'Otro trabajo está modificando las imágenes.' );
		}
		try {
			global $wpdb;
			$mimes = 'webp' === $mode
				? array( 'image/jpeg', 'image/png' )
				: array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
			$marks = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
			$sql = "SELECT ID FROM %i WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ($marks) AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT 1";
			$params = array_merge( array( $wpdb->posts ), $mimes, array( absint( $job['cursor'] ?? 0 ), absint( $job['max_id'] ?? 0 ) ) );
			$id = absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
			if ( ! $id ) {
				$job['status'] = 'complete';
				return $job;
			}
			$measure = TaxoCommerce_Images_Load::begin();
			$item = 'webp' === $mode ? tci_webp_process_attachment( $id ) : tci_optimizer_process_attachment( $id );
			TaxoCommerce_Images_Load::finish( $mode, $measure, 1 );
			$job['cursor'] = $id;
			$job['processed'] = absint( $job['processed'] ?? 0 ) + 1;
			$job['changed'] = absint( $job['changed'] ?? 0 )
				+ absint( $item[ 'webp' === $mode ? 'converted' : 'optimized' ] ?? 0 );
			if ( ! empty( $item['errors'] ) ) {
				$job['errors'] = array_slice( array_merge( (array) ( $job['errors'] ?? array() ), (array) $item['errors'] ), -30 );
			}
			return $job;
		} finally {
			TaxoCommerce_Images_Maintenance::unlock( 'mutation' );
		}
	}

	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}
		check_ajax_referer( 'taxocommerce_images_background', 'nonce' );
		$mode = isset( $_POST['mode'] ) && is_scalar( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$operation = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'status';
		if ( ! in_array( $mode, self::modes(), true ) || ! in_array( $operation, array( 'status', 'start', 'pause', 'resume', 'stop' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Operación no válida.' ), 400 );
		}
		$job = self::state( $mode );
		if ( 'start' === $operation ) {
			if ( ! in_array( $mode, array( 'optimize', 'webp' ), true ) ) {
				wp_send_json_error( array( 'message' => 'Inicia este análisis desde su botón habitual.' ), 400 );
			}
			$job = self::start( $mode, get_current_user_id() );
		} elseif ( in_array( $operation, array( 'pause', 'resume', 'stop' ), true ) && $job ) {
			if ( 'resume' === $operation && in_array( $job['status'], array( 'paused', 'error' ), true ) ) {
				$job = self::start( $mode, get_current_user_id() );
			} elseif ( 'pause' === $operation && 'running' === $job['status'] ) {
				$job['status'] = 'paused';
				self::save( $mode, $job );
			} elseif ( 'stop' === $operation ) {
				$job['status'] = 'stopped';
				self::save( $mode, $job );
			}
		}
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}
		$job['cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$job['stalled'] = 'running' === ( $job['status'] ?? '' ) && ! empty( $job['last_tick'] ) && ( time() - (int) $job['last_tick'] > 240 );
		wp_send_json_success( array( 'job' => $job ) );
	}

	public static function render_panel( $mode ) {
		$job = self::state( $mode );
		$status = isset( $job['status'] ) ? sanitize_key( $job['status'] ) : 'not-started';
		echo '<div class="tci-background-panel" data-tci-bg-mode="' . esc_attr( $mode ) . '">';
		echo '<p><strong>' . esc_html__( 'Ejecución en segundo plano:', 'taxocommerce-images' ) . '</strong> <span data-tci-bg-status>' . esc_html( $status ) . '</span></p>';
		echo '<p class="description" data-tci-bg-progress>' . esc_html__( 'Este trabajo continuará en el servidor sin mantener esta pestaña abierta, siempre que WordPress Cron esté operativo.', 'taxocommerce-images' ) . '</p>';
		echo '<button type="button" class="button" data-tci-bg-control="pause">' . esc_html__( 'Pausar servidor', 'taxocommerce-images' ) . '</button> ';
		echo '<button type="button" class="button" data-tci-bg-control="resume">' . esc_html__( 'Reanudar', 'taxocommerce-images' ) . '</button> ';
		echo '<button type="button" class="button" data-tci-bg-control="stop">' . esc_html__( 'Detener', 'taxocommerce-images' ) . '</button>';
		echo '</div>';
	}
}
