<?php
/**
 * Cooperative CPU guard for long-running TaxoCommerce Images jobs.
 *
 * Uses a real two-sample /proc/stat utilization delta or an explicit hosting
 * integration. Load average is never interpreted as CPU percentage.
 *
 * @package TaxoCommerceImages
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_CPU {

	const LIMIT_OPTION  = 'taxocommerce_images_cpu_limit';
	const SAMPLE_OPTION = 'taxocommerce_images_cpu_sample';
	const LAST_OPTION   = 'taxocommerce_images_cpu_last';
	const DEFAULT_LIMIT = 50;
	const RETRY_SECONDS = 10;

	public static function boot() {
		add_action( 'admin_post_taxocommerce_images_cpu_limit', array( __CLASS__, 'save_limit' ) );
	}

	public static function limit() {
		$value = get_option( self::LIMIT_OPTION, self::DEFAULT_LIMIT );
		return max( 1, min( 100, absint( $value ) ) );
	}

	public static function set_limit( $raw ) {
		if ( ! is_scalar( $raw ) || ! preg_match( '/^\d{1,3}$/', trim( (string) $raw ) ) ) {
			return new WP_Error( 'taxocommerce_images_cpu_invalid_limit', __( 'Introduce un porcentaje de CPU entero entre 1 y 100.', 'taxocommerce-images' ) );
		}
		$value = (int) $raw;
		if ( $value < 1 || $value > 100 ) {
			return new WP_Error( 'taxocommerce_images_cpu_invalid_limit', __( 'El umbral de CPU debe estar entre 1 y 100 %.', 'taxocommerce-images' ) );
		}
		update_option( self::LIMIT_OPTION, $value, false );
		return $value;
	}

	public static function save_limit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'taxocommerce-images' ) );
		}
		check_admin_referer( 'taxocommerce_images_cpu_limit', 'taxocommerce_images_nonce' );
		$raw = isset( $_POST['cpu_limit'] ) && is_scalar( $_POST['cpu_limit'] )
			? wp_unslash( $_POST['cpu_limit'] )
			: '';
		$result = self::set_limit( $raw );
		$url = add_query_arg(
			array(
				'page' => 'taxocommerce-images',
				'tab' => 'settings',
				'tc_result' => is_wp_error( $result ) ? 'error' : 'ok',
				'tc_message' => is_wp_error( $result )
					? $result->get_error_message()
					: __( 'Límite de CPU guardado.', 'taxocommerce-images' ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Return available=true only for a real utilization percentage.
	 *
	 * @return array
	 */
	public static function measure() {
		$provided = apply_filters( 'taxocommerce_images_cpu_percent', null );
		if ( is_numeric( $provided ) && (float) $provided >= 0 && (float) $provided <= 100 ) {
			return array(
				'available' => true,
				'percent' => round( (float) $provided, 1 ),
				'source' => 'hosting_filter',
				'reason' => '',
				'at' => gmdate( 'c' ),
			);
		}

		$sample = self::proc_stat_sample();
		if ( null === $sample ) {
			return array(
				'available' => false,
				'percent' => null,
				'source' => 'unavailable',
				'reason' => __( 'No se puede leer /proc/stat. Hace falta una métrica compatible con el hosting.', 'taxocommerce-images' ),
				'at' => gmdate( 'c' ),
			);
		}

		$before = get_option( self::SAMPLE_OPTION, array() );
		update_option( self::SAMPLE_OPTION, $sample, false );
		$elapsed = $sample['time'] - (int) ( $before['time'] ?? 0 );
		if ( $elapsed < 1 || $elapsed > 300 || ! isset( $before['total'], $before['idle'] ) ) {
			return array(
				'available' => false,
				'percent' => null,
				'source' => 'proc_stat_host',
				'reason' => __( 'Preparando la primera muestra de CPU; se necesita una segunda lectura.', 'taxocommerce-images' ),
				'at' => gmdate( 'c' ),
				'warmup' => true,
			);
		}

		$delta_total = $sample['total'] - (float) $before['total'];
		$delta_idle  = $sample['idle'] - (float) $before['idle'];
		if ( $delta_total <= 0 || $delta_idle < 0 || $delta_idle > $delta_total ) {
			return array(
				'available' => false,
				'percent' => null,
				'source' => 'proc_stat_host',
				'reason' => __( 'Los contadores de CPU no proporcionan una muestra válida.', 'taxocommerce-images' ),
				'at' => gmdate( 'c' ),
			);
		}

		return array(
			'available' => true,
			'percent' => round( 100 * ( $delta_total - $delta_idle ) / $delta_total, 1 ),
			'source' => 'proc_stat_host',
			'reason' => '',
			'at' => gmdate( 'c' ),
		);
	}

	public static function decision( $context = '' ) {
		$sample = self::measure();
		$limit = self::limit();
		$allowed = ! empty( $sample['available'] ) && (float) $sample['percent'] < $limit;
		if ( ! $allowed ) {
			$message = empty( $sample['available'] )
				? __( 'Esperando una lectura de CPU válida.', 'taxocommerce-images' )
				: sprintf(
					/* translators: 1: measured CPU percent; 2: configured limit. */
					__( 'CPU %1$s %%: límite %2$d %% alcanzado.', 'taxocommerce-images' ),
					(string) $sample['percent'],
					$limit
				);
		} else {
			$message = sprintf(
				/* translators: 1: measured CPU percent; 2: configured limit. */
				__( 'CPU %1$s %%: por debajo del límite de %2$d %%.', 'taxocommerce-images' ),
				(string) $sample['percent'],
				$limit
			);
		}
		$result = array_merge(
			$sample,
			array(
				'allowed' => $allowed,
				'limit' => $limit,
				'retry_after' => self::RETRY_SECONDS,
				'context' => sanitize_key( (string) $context ),
				'message' => $message,
			)
		);
		update_option( self::LAST_OPTION, $result, false );
		return $result;
	}

	public static function last() {
		$value = get_option( self::LAST_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function render_control() {
		$sample = self::last();
		?>
		<div class="card" style="max-width:none;margin:14px 0;padding:14px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="taxocommerce_images_cpu_limit">
				<?php wp_nonce_field( 'taxocommerce_images_cpu_limit', 'taxocommerce_images_nonce' ); ?>
				<label for="taxocommerce-images-cpu-limit"><strong><?php esc_html_e( 'Límite máximo de CPU para Imágenes (%)', 'taxocommerce-images' ); ?></strong></label>
				<input id="taxocommerce-images-cpu-limit" name="cpu_limit" type="number" min="1" max="100" step="1" required style="width:85px" value="<?php echo esc_attr( (string) self::limit() ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar límite', 'taxocommerce-images' ); ?></button>
			</form>
			<p class="description">
				<?php
				echo esc_html(
					'CPU: ' .
					( isset( $sample['percent'] ) && null !== $sample['percent'] ? (string) $sample['percent'] . ' %' : 'sin medición' ) .
					'; fuente: ' . (string) ( $sample['source'] ?? 'pendiente' ) . '. ' .
					(string) ( $sample['reason'] ?? '' )
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Si no existe una métrica fiable o se alcanza el límite, el proceso largo se pausa entre lotes y vuelve a comprobar. El cursor y los resultados ya guardados permanecen intactos.', 'taxocommerce-images' ); ?></p>
		</div>
		<?php
	}

	private static function proc_stat_sample() {
		$handle = @fopen( '/proc/stat', 'r' );
		if ( false === $handle ) {
			return null;
		}
		$line = @fgets( $handle, 512 );
		@fclose( $handle );
		if ( ! is_string( $line ) || ! preg_match( '/^cpu\s+((?:\d+\s+){7}\d+)/', trim( $line ), $matches ) ) {
			return null;
		}
		$parts = preg_split( '/\s+/', trim( $matches[1] ) );
		if ( count( $parts ) !== 8 ) {
			return null;
		}
		$counters = array_map( 'floatval', $parts );
		return array(
			'total' => array_sum( $counters ),
			'idle' => $counters[3] + $counters[4],
			'time' => time(),
		);
	}
}
