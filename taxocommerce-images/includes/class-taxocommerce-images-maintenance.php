<?php
/**
 * Local image metadata and cleanup, without remote HTTP checks.
 *
 * @package TaxoCommerceImages
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_Maintenance {
	const BATCH = 25;
	const LOCK = 'taxocommerce_images_mutation_lock';
	private static $token = '';

	public static function boot() {
		add_action( 'wp_ajax_taxocommerce_images_maintenance', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_taxocommerce_images_label_save', array( __CLASS__, 'save_labels' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// The same administrator must not convert and delete the same file concurrently.
		add_action( 'wp_ajax_tci_webp_batch', array( __CLASS__, 'protect_legacy_job' ), 0 );
		add_action( 'wp_ajax_tci_optimizer_batch', array( __CLASS__, 'protect_legacy_job' ), 0 );
	}
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'taxocommerce-images' ) ) { return; }
		wp_enqueue_script( 'taxocommerce-images-maintenance', plugin_dir_url( TAXOCOMMERCE_IMAGES_FILE ) . 'assets/maintenance.js', array(), TAXOCOMMERCE_IMAGES_VERSION, true );
		wp_localize_script( 'taxocommerce-images-maintenance', 'taxocommerceImagesMaintenance', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'taxocommerce_images_maintenance' ) ) );
	}
	public static function protect_legacy_job() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 ); }
		$is_webp = doing_action( 'wp_ajax_tci_webp_batch' );
		check_ajax_referer( $is_webp ? 'tci_webp_run' : 'tci_optimizer_run', 'nonce' );
		if ( ! self::lock() ) { wp_send_json_error( array( 'message' => 'Otro proceso está modificando imágenes.' ), 409 ); }
		register_shutdown_function( array( __CLASS__, 'unlock' ) );
	}
	public static function lock() {
		global $wpdb;
		$token = wp_generate_uuid4();
		$value = array( 'token' => $token, 'time' => time() );
		if ( add_option( self::LOCK, $value, '', false ) ) { self::$token = $token; return true; }
		$old = get_option( self::LOCK );
		if ( is_array( $old ) && (int) ( $old['time'] ?? 0 ) < time() - 900 ) {
			// Compare-and-delete: an old worker cannot remove another worker's lease.
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $old ) ), array( '%s', '%s' ) );
			wp_cache_delete( self::LOCK, 'options' );
			if ( $deleted && add_option( self::LOCK, $value, '', false ) ) { self::$token = $token; return true; }
		}
		return false;
	}
	public static function unlock() {
		global $wpdb;
		$old = get_option( self::LOCK );
		if ( self::$token && is_array( $old ) && hash_equals( self::$token, (string) ( $old['token'] ?? '' ) ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $old ) ), array( '%s', '%s' ) );
			wp_cache_delete( self::LOCK, 'options' );
		}
		self::$token = '';
	}
	private static function option( $mode ) { return 'taxocommerce_images_' . $mode . '_report_v2'; }
	public static function state( $mode ) {
		$value = get_option( self::option( $mode ), array() );
		return is_array( $value ) ? $value : array();
	}
	private static function save_state( $mode, $state ) { update_option( self::option( $mode ), $state, false ); }
	public static function ids_after( $after, $max, $limit ) {
		$filter = static function ( $where, $query ) use ( $after, $max ) {
			global $wpdb;
			if ( $query->get( 'taxocommerce_images_scan' ) ) { $where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $after, $max ); }
			return $where;
		};
		add_filter( 'posts_where', $filter, 10, 2 );
		try {
			$query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'taxocommerce_images_scan' => true ) );
		} finally { remove_filter( 'posts_where', $filter, 10 ); }
		return array_map( 'absint', $query->posts );
	}
	public static function begin( $mode ) {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $mode, array( 'labels', 'cleanup' ), true ) ) { return new WP_Error( 'permission', 'Sin permisos.' ); }
		$q = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
		$state = array( 'run' => wp_generate_uuid4(), 'last' => 0, 'max' => $q->posts ? absint( $q->posts[0] ) : 0, 'total' => (int) $q->found_posts, 'scanned' => 0, 'status' => 'running', 'counts' => array(), 'removed' => 0, 'saved_bytes' => 0, 'message' => '' );
		self::save_state( $mode, $state );
		return $state;
	}
	private static function row_key( $mode ) { return '_taxocommerce_images_' . $mode . '_result'; }
	public static function record( $mode, $id, $row ) {
		$state = self::state( $mode );
		$old = get_post_meta( $id, self::row_key( $mode ), true );
		if ( is_array( $old ) && ( $old['run'] ?? '' ) === $state['run'] ) {
			foreach ( (array) ( $old['flags'] ?? array( $old['status'] ) ) as $flag ) { $state['counts'][ $flag ] = max( 0, (int) ( $state['counts'][ $flag ] ?? 0 ) - 1 ); }
		}
		$row['run'] = $state['run'];
		foreach ( (array) ( $row['flags'] ?? array( $row['status'] ) ) as $flag ) { $state['counts'][ $flag ] = (int) ( $state['counts'][ $flag ] ?? 0 ) + 1; }
		update_post_meta( $id, self::row_key( $mode ), $row );
		update_post_meta( $id, '_taxocommerce_images_' . $mode . '_run', $state['run'] );
		update_post_meta( $id, '_taxocommerce_images_' . $mode . '_status', $row['status'] );
		self::save_state( $mode, $state );
	}
	public static function label_flags( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$flags = array();
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$decorative = (bool) get_post_meta( $id, '_taxocommerce_images_decorative', true );
		$title = trim( (string) get_post_field( 'post_title', $id ) );
		if ( '' === $alt && ! $decorative ) { $flags[] = 'missing_alt'; }
		if ( '' !== $alt && $decorative ) { $flags[] = 'decorative_alt'; }
		$file = get_attached_file( $id );
		$stem = is_string( $file ) ? pathinfo( $file, PATHINFO_FILENAME ) : '';
		if ( '' === $title ) { $flags[] = 'missing_title'; }
		elseif ( preg_match( '/^(?:img|dsc|image|foto)?[-_ ]*[0-9]+$/i', $title ) || ( '' !== $stem && sanitize_title( $title ) === sanitize_title( $stem ) ) ) { $flags[] = 'review_title'; }
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) { $flags[] = 'missing_dimensions'; }
		if ( ! is_string( $file ) || ! is_file( $file ) ) { $flags[] = 'missing_file'; }
		return $flags;
	}
	public static function scan( $mode ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Sin permisos.' ); }
		$state = self::state( $mode );
		if ( ! $state || 'running' !== $state['status'] ) { return $state; }
		$ids = self::ids_after( $state['last'], $state['max'], 'cleanup' === $mode ? 5 : self::BATCH );
		$usage = array();
		if ( 'cleanup' === $mode && $ids ) {
			$posts = array_map( 'get_post', $ids );
			$usage = tci_refs_build_usage_report( $posts );
		}
		foreach ( $ids as $id ) {
			if ( 'labels' === $mode ) {
				$flags = self::label_flags( $id );
				self::record( $mode, $id, array( 'status' => $flags ? 'review' : 'ok', 'flags' => $flags ? $flags : array( 'ok' ) ) );
			} else {
				$row = array( 'status' => empty( $usage[ $id ] ) ? 'unused' : 'used', 'duplicate' => 0, 'message' => '', 'bytes' => tci_refs_get_attachment_size( $id ) );
				$file = get_attached_file( $id );
				if ( ! self::local_file( $file ) ) { $row['status'] = 'review'; $row['message'] = 'Archivo no local, ausente o ilegible.'; }
				else {
					$digest = hash_file( 'sha256', $file );
					if ( false === $digest ) { $row['status'] = 'review'; $row['message'] = 'No se pudo calcular la huella.'; }
					else {
						$matches = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'post__not_in' => array( $id ), 'meta_query' => array( array( 'key' => '_taxocommerce_images_cleanup_run', 'value' => $state['run'] ), array( 'key' => '_taxocommerce_images_digest', 'value' => $digest ) ) ) );
						update_post_meta( $id, '_taxocommerce_images_digest', $digest );
						if ( $matches ) {
							$row['duplicate'] = absint( $matches[0] ); $row['status'] = 'duplicate';
							$canonical = get_post_meta( $matches[0], self::row_key( 'cleanup' ), true );
							if ( is_array( $canonical ) && 'unused' === $canonical['status'] ) { $canonical['status'] = 'keeper'; self::record( 'cleanup', $matches[0], $canonical ); }
						}
					}
				}
				self::record( $mode, $id, $row );
			}
			$state = self::state( $mode ); $state['last'] = $id; ++$state['scanned']; self::save_state( $mode, $state );
		}
		$state = self::state( $mode );
		if ( ! $ids || $state['last'] >= $state['max'] ) { $state['status'] = 'complete'; }
		self::save_state( $mode, $state );
		return $state;
	}
	public static function local_file( $file ) {
		$uploads = wp_get_upload_dir(); $root = realpath( $uploads['basedir'] );
		$real = is_string( $file ) ? realpath( $file ) : false;
		return $root && $real && is_file( $real ) && is_readable( $real ) && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) );
	}
	public static function rows( $mode, $statuses = array(), $page = 1, $limit = 25 ) {
		$state = self::state( $mode );
		$args = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $limit, 'paged' => max( 1, absint( $page ) ), 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => array( array( 'key' => '_taxocommerce_images_' . $mode . '_run', 'value' => $state['run'] ?? '-' ) ) );
		if ( $statuses ) { $args['meta_query'][] = array( 'key' => '_taxocommerce_images_' . $mode . '_status', 'value' => $statuses, 'compare' => 'IN' ); }
		return new WP_Query( $args );
	}
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 ); }
		check_ajax_referer( 'taxocommerce_images_maintenance', 'nonce' );
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! in_array( $mode, array( 'labels', 'cleanup' ), true ) || ! in_array( $operation, array( 'start', 'scan' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Operación no válida.' ), 400 );
		}
		if ( ! self::lock() ) { wp_send_json_error( array( 'message' => 'Otro lote está en curso.' ), 409 ); }
		try {
			if ( 'start' === $operation ) { self::begin( $mode ); }
			$state = self::scan( $mode );
			$result = is_wp_error( $state )
				? $state
				: array(
					'done'   => ( $state['status'] ?? '' ) === 'complete',
					'paused' => false,
					'state'  => $state,
				);
		} catch ( Throwable $error ) { $result = new WP_Error( 'exception', sanitize_text_field( $error->getMessage() ) ); }
		finally { self::unlock(); }
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( $result );
	}
	public static function save_labels() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Sin permisos.', 'taxocommerce-images' ) ); }
		check_admin_referer( 'taxocommerce_images_label_save', 'taxocommerce_images_nonce' );
		$id = isset( $_POST['attachment_id'] ) && is_scalar( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'Imagen no válida.', 'taxocommerce-images' ) ); }
		$title = isset( $_POST['image_title'] ) && is_string( $_POST['image_title'] ) ? sanitize_text_field( wp_unslash( $_POST['image_title'] ) ) : '';
		$alt = isset( $_POST['image_alt'] ) && is_string( $_POST['image_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['image_alt'] ) ) : '';
		$decorative = ! empty( $_POST['decorative'] );
		$updated = wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $title ) ), true );
		if ( is_wp_error( $updated ) ) { wp_die( esc_html( $updated->get_error_message() ) ); }
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $decorative ? '' : $alt ) );
		update_post_meta( $id, '_taxocommerce_images_decorative', $decorative ? 1 : 0 );
		if ( self::state( 'labels' ) ) { $flags = self::label_flags( $id ); self::record( 'labels', $id, array( 'status' => $flags ? 'review' : 'ok', 'flags' => $flags ? $flags : array( 'ok' ) ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=labels' ) ); exit;
	}
	public static function render( $mode ) {
		$state = self::state( $mode );
		$labels = array( 'missing_alt' => 'Sin ALT informativo', 'decorative_alt' => 'Decorativa con ALT', 'missing_title' => 'Sin título', 'review_title' => 'Revisar título descriptivo', 'missing_dimensions' => 'Sin dimensiones registradas', 'missing_file' => 'Archivo ausente', 'ok' => 'Sin incidencias', 'unused' => 'Sin referencias conocidas', 'duplicate' => 'Duplicadas exactas', 'keeper' => 'Conservadas hasta consolidar', 'used' => 'En uso', 'review' => 'Revisión necesaria' );
		?>
		<section data-tci-maintenance="<?php echo esc_attr( $mode ); ?>">
		<h2><?php echo esc_html( 'labels' === $mode ? 'Etiquetas y atributos de imágenes' : 'Liberar espacio: sin uso y duplicadas' ); ?></h2>
		<p><?php echo esc_html( 'labels' === $mode ? 'Revisa ALT, título y dimensiones de Media, sin peticiones HTTP. Leyenda y descripción son opcionales. No se rellena texto genérico automáticamente. Marcar decorativa expresa la decisión del administrador; el contenido HTML ya publicado se revisa aparte.' : 'Analiza toda la biblioteca sin límite de 8 MB para comparar archivos. Informa de originales idénticos y medios sin referencias conocidas. Borrado y consolidación automáticos no habilitados en esta entrega; no se elimina ninguna imagen durante el análisis.' ); ?></p>
		<div class="tcc-stat-grid">
		<?php foreach ( (array) ( $state['counts'] ?? array() ) as $flag => $count ) : ?>
		<div class="card"><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> <?php echo esc_html( $labels[ $flag ] ?? $flag ); ?></div>
		<?php endforeach; ?>
		</div>
		<p><?php echo esc_html( 'Analizadas: ' . (int) ( $state['scanned'] ?? 0 ) . ' / ' . (int) ( $state['total'] ?? 0 ) . '. Los contadores incluyen todos los lotes examinados, no solo las 25 filas visibles.' ); ?></p>
		<p><button class="button button-primary" data-tci-operation="start">Analizar todas</button> <button class="button" data-tci-operation="scan" <?php disabled( ! $state || 'running' !== $state['status'] ); ?>>Continuar</button> <button class="button" data-tci-pause>Pausar</button>
		<?php if ( 'cleanup' === $mode ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>">Revisar en Media</a><?php endif; ?></p>
		<p data-tci-progress role="status" aria-live="polite"><?php echo esc_html( 'Eliminadas: ' . (int) ( $state['removed'] ?? 0 ) . '. Espacio liberado comprobado: ' . size_format( (int) ( $state['saved_bytes'] ?? 0 ) ) ); ?></p>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page = isset( $_GET['report_page'] ) && is_scalar( $_GET['report_page'] ) ? max( 1, absint( $_GET['report_page'] ) ) : 1;
		$q = self::rows( $mode, array(), $page );
		?>
		<table class="widefat striped"><thead><tr><th>Imagen</th><th>Resultado</th><th>Acciones</th></tr></thead><tbody>
		<?php foreach ( $q->posts as $post ) : $id = $post->ID; $row = get_post_meta( $id, self::row_key( $mode ), true ); ?>
		<tr><td><?php echo wp_kses_post( wp_get_attachment_image( $id, array( 60, 60 ) ) ); ?> <?php echo esc_html( get_the_title( $id ) . ' #' . $id ); ?></td><td>
		<?php foreach ( (array) ( $row['flags'] ?? array( $row['status'] ?? '' ) ) as $flag ) { echo esc_html( ( $labels[ $flag ] ?? $flag ) . ' ' ); } ?>
		<?php if ( ! empty( $row['duplicate'] ) ) { echo esc_html( ' → #' . $row['duplicate'] ); } ?>
		<?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td><td>
		<?php if ( 'labels' === $mode ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="taxocommerce_images_label_save"><input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php wp_nonce_field( 'taxocommerce_images_label_save', 'taxocommerce_images_nonce' ); ?>
		<label>Título <input name="image_title" value="<?php echo esc_attr( $post->post_title ); ?>"></label>
		<label>ALT <input name="image_alt" value="<?php echo esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ); ?>"></label>
		<label><input type="checkbox" name="decorative" value="1" <?php checked( (bool) get_post_meta( $id, '_taxocommerce_images_decorative', true ) ); ?>>Decorativa: ALT vacío</label>
		<button class="button">Guardar</button></form>
		<?php endif; ?>
		<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Editar en Media</a></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'taxocommerce-images', 'tab' => 'labels' === $mode ? 'labels' : 'references', 'report_page' => '%#%' ), admin_url( 'admin.php' ) ), 'current' => $page, 'total' => $q->max_num_pages ) ) ); ?>
		</section>
		<?php
	}
}
