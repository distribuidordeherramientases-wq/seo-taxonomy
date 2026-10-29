<?php
/**
 * On-demand, read-only functional checks for TaxoCommerce Images.
 *
 * Stores only a user-specific 24-hour report transient. No image files,
 * attachment metadata, job state, catalog content or database tables are changed.
 *
 * @package TaxoCommerceImages
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Images_Auto_Test {
	const ACTION = 'taxocommerce_images_run_auto_test';
	const NONCE = 'taxocommerce_images_auto_test_run';
	const REPORT_PREFIX = 'taxocommerce_images_autotest_';

	public static function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	private static function allowed() {
		return current_user_can( (string) apply_filters( 'taxocommerce_images_manage_capability', 'manage_options' ) );
	}

	public static function handle_run() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Sin permisos para ejecutar Auto Test.', 'taxocommerce-images' ) );
		}
		check_admin_referer( self::NONCE, 'tci_autotest_nonce' );
		set_transient( self::REPORT_PREFIX . get_current_user_id(), self::run_suite(), DAY_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-images&tab=auto-test' ) );
		exit;
	}

	public static function cases() {
		return array(
			'runtime'           => array( 'Images', 'Arranque y servicios' ),
			'hooks'             => array( 'Images', 'Acciones administrativas registradas' ),
			'formats'           => array( 'Inventario', 'Recuento de formatos de Media' ),
			'media'             => array( 'Inventario', 'Lectura paginada de Media' ),
			'attachment'        => array( 'Inventario', 'Archivo y metadatos de una imagen (muestra)' ),
			'audit'             => array( 'Anomalías', 'Registro y lectura del auditor' ),
			'labels'            => array( 'Etiquetas', 'Clasificación ALT, títulos y dimensiones (muestra)' ),
			'references'        => array( 'Sin uso y duplicadas', 'Comprobación conservadora de referencias' ),
			'assignment'        => array( 'Asignación', 'Ámbitos y consulta de imagen asignada' ),
			'product_policy'    => array( 'Asignación', 'No tomar imágenes de otro producto (muestra)' ),
			'thumbnails'        => array( 'Miniaturas', 'Aplicación de exclusiones sin escribir' ),
			'settings'          => array( 'Ajustes', 'Configuración y reglas de calidad' ),
			'load'              => array( 'Carga', 'Regulador independiente 1–5' ),
			'background'        => array( 'Segundo plano', 'Workers, controles y estados guardados' ),
			'transforms'        => array( 'Optimización y WebP', 'Motores y endpoints disponibles' ),
			'core'              => array( 'Extensiones', 'Integración opcional con Core' ),
			'write_uat'         => array( 'Operaciones de escritura', 'Asignar, borrar, optimizar, convertir y regenerar de verdad' ),
		);
	}

	private static function outcome( $status, $message ) {
		return array( 'status' => $status, 'message' => (string) $message );
	}
	private static function ok( $message ) { return self::outcome( 'pass', $message ); }
	private static function bad( $message ) { return self::outcome( 'fail', $message ); }
	private static function skip( $message ) { return self::outcome( 'skip', $message ); }

	/** Run tests independently: one failure or exception never hides the others. */
	public static function run_suite( $cases = null ) {
		$cases = null === $cases ? self::cases() : $cases;
		$start = microtime( true );
		$rows = array();
		$totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
		foreach ( $cases as $id => $labels ) {
			$began = microtime( true );
			try {
				$method = array( __CLASS__, 'check_' . $id );
				$result = is_callable( $method ) ? call_user_func( $method ) : self::bad( 'Test sin implementación: ' . $id );
				if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) ||
					! in_array( $result['status'], array( 'pass', 'fail', 'skip' ), true ) ) {
					$result = self::bad( 'Resultado inválido del test.' );
				}
			} catch ( Throwable $error ) {
				$result = self::bad( get_class( $error ) . ': ' . $error->getMessage() );
			}
			$result['id'] = $id;
			$result['group'] = $labels[0];
			$result['label'] = $labels[1];
			$result['ms'] = (int) round( ( microtime( true ) - $began ) * 1000 );
			$rows[] = $result;
			++$totals[ $result['status'] ];
		}
		return array(
			'version' => defined( 'TAXOCOMMERCE_IMAGES_VERSION' ) ? TAXOCOMMERCE_IMAGES_VERSION : '',
			'date' => current_time( 'mysql' ),
			'ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'totals' => $totals,
			'rows' => $rows,
		);
	}

	private static function sample_image() {
		$query = TaxoCommerce_Images_Inventory::media( 1, 1 );
		return isset( $query->posts[0] ) ? absint( $query->posts[0]->ID ) : 0;
	}

	private static function check_runtime() {
		if ( ! defined( 'TAXOCOMMERCE_IMAGES_VERSION' ) ||
			! class_exists( 'TaxoCommerce_Images' ) ||
			! class_exists( 'TaxoCommerce_Images_Background' ) ||
			! class_exists( 'TaxoCommerce_Images_Inventory' ) ||
			! class_exists( 'TaxoCommerce_Images_Assignment' ) ) {
			return self::bad( 'Faltan componentes del plugin Images.' );
		}
		return is_object( TaxoCommerce_Images::instance() )
			? self::ok( 'Images ' . TAXOCOMMERCE_IMAGES_VERSION . '; instancia y servicios accesibles.' )
			: self::bad( 'No se puede crear la instancia del plugin.' );
	}

	private static function check_hooks() {
		$actions = array(
			'admin_post_taxocommerce_images_start_audit',
			'admin_post_taxocommerce_images_save_metadata',
			'admin_post_taxocommerce_images_assign_one',
			'admin_post_taxocommerce_images_assign_media',
			'admin_post_taxocommerce_images_assign_upload',
			'admin_post_taxocommerce_images_save_thumbnail_policy',
			'wp_ajax_taxocommerce_images_maintenance',
			'wp_ajax_taxocommerce_images_background_control',
			'admin_post_' . self::ACTION,
		);
		$missing = array();
		foreach ( $actions as $name ) {
			if ( ! has_action( $name ) ) { $missing[] = $name; }
		}
		return $missing ? self::bad( 'Sin registrar: ' . implode( ', ', $missing ) )
			: self::ok( count( $actions ) . ' endpoints administrativos registrados (sin invocar escrituras).' );
	}

	private static function check_formats() {
		$counts = TaxoCommerce_Images_Inventory::formats();
		$required = array( 'jpeg', 'png', 'webp', 'avif', 'other', 'total', 'convertible' );
		foreach ( $required as $key ) {
			if ( ! isset( $counts[ $key ] ) || ! is_numeric( $counts[ $key ] ) || $counts[ $key ] < 0 ) {
				return self::bad( 'Recuento no válido: ' . $key );
			}
		}
		$total = $counts['jpeg'] + $counts['png'] + $counts['webp'] + $counts['avif'] + $counts['other'];
		if ( (int) $total !== (int) $counts['total'] ||
			(int) $counts['convertible'] !== (int) ( $counts['jpeg'] + $counts['png'] ) ) {
			return self::bad( 'Los totales de formatos no coinciden con sus componentes.' );
		}
		return self::ok( (int) $counts['total'] . ' imágenes locales; ' . (int) $counts['convertible'] . ' JPG/PNG convertibles (lectura).' );
	}

	private static function check_media() {
		$query = TaxoCommerce_Images_Inventory::media( 1, 2 );
		if ( ! $query instanceof WP_Query || ! is_array( $query->posts ) || count( $query->posts ) > 2 ) {
			return self::bad( 'La consulta paginada de Media no devuelve el formato esperado.' );
		}
		foreach ( $query->posts as $post ) {
			if ( 'attachment' !== get_post_type( $post ) || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
				return self::bad( 'La consulta de Media contiene un elemento que no es una imagen.' );
			}
		}
		return self::ok( count( $query->posts ) . ' registros consultados; límite de página respetado.' );
	}

	private static function check_attachment() {
		$id = self::sample_image();
		if ( ! $id ) {
			return self::skip( 'No hay imágenes locales para comprobar archivos y metadatos.' );
		}
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		if ( false !== $meta && ! is_array( $meta ) ) {
			return self::bad( 'El sistema devuelve metadatos de imagen con un tipo inválido (ID ' . $id . ').' );
		}
		$exists = is_string( $file ) && is_file( $file );
		return self::ok( 'Media #' . $id . ': lectura de metadatos correcta; archivo local ' . ( $exists ? 'presente' : 'ausente o no accesible (revisar en Anomalías)' ) . '. No es una auditoría completa.' );
	}

	private static function check_audit() {
		$labels = TaxoCommerce_Images_Audit::labels();
		$audit = new TaxoCommerce_Images_Audit();
		$state = $audit->state();
		if ( ! is_array( $labels ) || ! isset( $labels['missing_alt'], $labels['missing_file'], $labels['missing_sizes'] ) || ! is_array( $state ) ) {
			return self::bad( 'Etiquetas o estado del auditor incorrectos.' );
		}
		return self::ok( count( $labels ) . ' anomalías definidas; estado del auditor accesible (sin iniciar análisis).' );
	}

	private static function check_labels() {
		$id = self::sample_image();
		if ( ! $id ) { return self::skip( 'Sin imágenes locales para comprobar etiquetas.' ); }
		$flags = TaxoCommerce_Images_Maintenance::label_flags( $id );
		if ( ! is_array( $flags ) ) { return self::bad( 'La clasificación de etiquetas no devuelve una lista.' ); }
		return self::ok( 'Media #' . $id . ': clasificador ejecutado; ' . count( $flags ) . ' observaciones de datos (no se corrigen automáticamente).' );
	}

	private static function check_references() {
		if ( true !== TaxoCommerce_Images_Inventory::is_referenced( 0 ) ) {
			return self::bad( 'Una imagen inexistente se clasifica inseguramente como borrable.' );
		}
		$id = self::sample_image();
		if ( ! $id ) { return self::ok( 'Protección de referencias inexistentes activa; no hay imagen de muestra.' ); }
		return is_bool( TaxoCommerce_Images_Inventory::is_referenced( $id ) )
			? self::ok( 'Media #' . $id . ': comprobación de uso devuelve una decisión; no se elimina nada.' )
			: self::bad( 'La comprobación de referencias no devuelve un valor booleano.' );
	}

	private static function check_assignment() {
		$scopes = TaxoCommerce_Images_Assignment::scopes();
		$category = taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
		if ( ! isset( $scopes['page'], $scopes['post'], $scopes['product'], $scopes[ $category ] ) ) {
			return self::bad( 'Falta un ámbito de asignación (páginas, entradas, categorías o productos).' );
		}
		if ( ! is_bool( TaxoCommerce_Images_Assignment::has_image( 'page', 0 ) ) ) {
			return self::bad( 'No se puede consultar la asignación de imágenes.' );
		}
		return self::ok( 'Ámbitos y lectura de imagen asignada operativos. No se asigna ninguna imagen.' );
	}

	private static function check_product_policy() {
		if ( ! post_type_exists( 'product' ) ) {
			return self::skip( 'WooCommerce no está activo: la política de productos no aplica.' );
		}
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC',
			'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
		if ( ! $ids ) { return self::skip( 'No hay productos para comprobar las sugerencias.' ); }
		$id = absint( $ids[0] );
		$gallery = array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) );
		$candidates = TaxoCommerce_Images_Assignment::candidates( 'product', $id, 4 );
		if ( ! is_array( $candidates ) ) { return self::bad( 'Las sugerencias del producto no devuelven una lista.' ); }
		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['id'] ) || ! in_array( absint( $candidate['id'] ), $gallery, true ) ) {
				return self::bad( 'Producto #' . $id . ': una sugerencia no pertenece a su galería.' );
			}
		}
		return self::ok( 'Producto #' . $id . ': ' . count( $candidates ) . ' sugerencias verificadas contra su propia galería (muestra).' );
	}

	private static function check_thumbnails() {
		$disabled = TaxoCommerce_Images_Thumbnails::disabled_sizes();
		if ( ! is_array( $disabled ) ) { return self::bad( 'La configuración de miniaturas no es una lista.' ); }
		$input = array( 'thumbnail' => array( 'width' => 150 ), 'medium' => array( 'width' => 300 ) );
		$actual = TaxoCommerce_Images_Thumbnails::filter_sizes( $input, array(), 0 );
		$expected = $input;
		foreach ( $disabled as $size ) { unset( $expected[ $size ] ); }
		return $expected === $actual
			? self::ok( 'Las exclusiones de miniaturas se aplican correctamente a una lista simulada, sin modificar Media.' )
			: self::bad( 'El filtro de miniaturas no respeta las exclusiones configuradas.' );
	}

	private static function check_settings() {
		$settings = TaxoCommerce_Images_Actions::settings();
		if ( ! is_array( $settings ) || ! isset( $settings['quality'], $settings['max_width'], $settings['max_height'] ) ) {
			return self::bad( 'Opciones de optimización incompletas.' );
		}
		$actions = new TaxoCommerce_Images_Actions();
		$png = $actions->filter_quality( 85, 'image/png' );
		$jpeg = $actions->filter_quality( 85, 'image/jpeg' );
		$expected = empty( $settings['enforce_quality'] ) ? 85 : max( 40, min( 95, absint( $settings['quality'] ) ) );
		return 85 === $png && $expected === $jpeg
			? self::ok( 'Lectura de opciones y filtro de calidad JPG comprobados; PNG no alterado.' )
			: self::bad( 'El filtro de calidad no cumple los ajustes guardados.' );
	}

	private static function check_load() {
		$modes = array( 'audit', 'labels', 'cleanup', 'optimize', 'webp', 'assignment_product' );
		foreach ( $modes as $mode ) {
			$priority = TaxoCommerce_Images_Load::priority( $mode );
			if ( $priority < 1 || $priority > 5 || TaxoCommerce_Images_Load::batch_size( $mode ) < 1 ) {
				return self::bad( 'Regulador inválido: ' . $mode );
			}
		}
		return 20 === TaxoCommerce_Images_Load::target_cpu( 1 ) && 90 === TaxoCommerce_Images_Load::target_cpu( 5 )
			? self::ok( 'Velocidades 1–5 y tamaños de lote independientes disponibles; no se cambia la carga del servidor.' )
			: self::bad( 'Los límites de carga no corresponden a las velocidades definidas.' );
	}

	private static function check_background() {
		$modes = TaxoCommerce_Images_Background::modes();
		$required = array( 'audit', 'labels', 'cleanup', 'optimize', 'webp', 'assignment_product' );
		if ( ! has_action( TaxoCommerce_Images_Background::HOOK ) || ! has_action( 'wp_ajax_taxocommerce_images_background_control' ) ) {
			return self::bad( 'No se ha registrado el worker o su control AJAX.' );
		}
		foreach ( $required as $mode ) {
			if ( ! in_array( $mode, $modes, true ) || ! is_array( TaxoCommerce_Images_Background::state( $mode ) ) ) {
				return self::bad( 'Estado del proceso no disponible: ' . $mode );
			}
		}
		return self::ok( count( $modes ) . ' modos de trabajo registrados y estados legibles. No se inicia ni altera ninguna cola.' );
	}

	private static function check_transforms() {
		$missing = array();
		foreach ( array( 'wp_ajax_tci_webp_batch', 'wp_ajax_tci_optimizer_batch' ) as $hook ) {
			if ( ! has_action( $hook ) ) { $missing[] = $hook; }
		}
		if ( ! function_exists( 'tci_webp_ajax_batch' ) || ! function_exists( 'tci_images_optimizer_ajax_batch' ) ) {
			$missing[] = 'motor de conversión u optimización';
		}
		return $missing ? self::bad( 'No disponibles: ' . implode( ', ', $missing ) )
			: self::ok( 'Handlers de optimización y WebP presentes. La conversión de archivos reales queda para UAT.' );
	}

	private static function check_core() {
		if ( ! defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
			return self::skip( 'Images funciona sin Core; integración opcional no aplicable.' );
		}
		return has_action( 'taxocommerce_core_register_modules' )
			? self::ok( 'Registro de Images en la familia Core disponible.' )
			: self::bad( 'Core está activo pero no aparece el registro de Images.' );
	}

	private static function check_write_uat() {
		return self::skip( 'No se escriben archivos, metadatos, asignaciones ni colas en este chequeo. Probar operaciones destructivas o conversiones reales en staging.' );
	}

	public static function render() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Acceso denegado a Auto Test.', 'taxocommerce-images' ) );
		}
		$report = get_transient( self::REPORT_PREFIX . get_current_user_id() );
		$totals = is_array( $report ) && isset( $report['totals'], $report['rows'] ) ? $report['totals'] : null;
		?>
		<section id="tci-auto-test">
			<h2><?php esc_html_e( 'Auto Test', 'taxocommerce-images' ); ?></h2>
			<p><?php esc_html_e( 'Comprueba las funciones principales de Images tras cada actualización. Solo lee datos y guarda temporalmente el resultado de este usuario.', 'taxocommerce-images' ); ?></p>
			<style>
			#tci-auto-test .tc-at-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:20px 0}
			#tci-auto-test .tc-at-summary{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0}
			#tci-auto-test .tc-at-summary span{padding:12px 18px;border:1px solid #dcdcde;background:#fff;border-radius:5px}
			#tci-auto-test .tc-at-row{display:flex;gap:12px;align-items:flex-start;padding:12px 15px;border:1px solid #dcdcde;border-bottom:0;background:#fff;max-width:1050px}
			#tci-auto-test .tc-at-row:last-child{border-bottom:1px solid #dcdcde}
			#tci-auto-test .tc-at-dot{display:inline-block;flex:0 0 13px;width:13px;height:13px;margin-top:4px;border-radius:50%;background:#777}
			#tci-auto-test .tc-at-dot--pass{background:#188038}#tci-auto-test .tc-at-dot--fail{background:#c5221f}
			#tci-auto-test .tc-at-row strong{display:block}#tci-auto-test .tc-at-row p{margin:4px 0;overflow-wrap:anywhere}
			#tci-auto-test .tc-at-row--fail p{color:#b32d2e;font-weight:600}
			#tci-auto-test .tc-at-small{font-size:12px;color:#50575e}
			</style>
			<form class="tc-at-actions" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE, 'tci_autotest_nonce' ); ?>
				<?php submit_button( __( 'Ejecutar Auto Test', 'taxocommerce-images' ), 'primary', 'submit', false ); ?>
				<span><?php esc_html_e( 'Sin cambios en archivos, catálogo, metadatos o colas de imágenes.', 'taxocommerce-images' ); ?></span>
			</form>
			<?php if ( null === $totals ) : ?>
				<p><?php esc_html_e( 'No existen resultados todavía. Ejecuta el chequeo para ver el estado de Images.', 'taxocommerce-images' ); ?></p>
			<?php else : ?>
				<p><strong><?php echo esc_html( $report['date'] ); ?></strong> · <?php echo esc_html( $report['version'] ); ?> · <?php echo esc_html( absint( $report['ms'] ) . ' ms' ); ?></p>
				<div class="tc-at-summary">
					<span><strong style="color:#188038"><?php echo esc_html( absint( $totals['pass'] ) ); ?></strong> <?php esc_html_e( 'Correctas', 'taxocommerce-images' ); ?></span>
					<span><strong style="color:#c5221f"><?php echo esc_html( absint( $totals['fail'] ) ); ?></strong> <?php esc_html_e( 'Errores', 'taxocommerce-images' ); ?></span>
					<span><strong><?php echo esc_html( absint( $totals['skip'] ) ); ?></strong> <?php esc_html_e( 'No ejecutadas', 'taxocommerce-images' ); ?></span>
				</div>
				<?php foreach ( $report['rows'] as $row ) : ?>
					<div class="tc-at-row tc-at-row--<?php echo esc_attr( $row['status'] ); ?>">
						<span class="tc-at-dot tc-at-dot--<?php echo esc_attr( $row['status'] ); ?>" aria-hidden="true"></span>
						<div><strong><?php echo esc_html( $row['group'] . ' · ' . $row['label'] ); ?> <span class="tc-at-small"><?php echo esc_html( $row['id'] ); ?></span></strong>
							<p><?php echo esc_html( $row['message'] ); ?></p>
							<span class="tc-at-small"><?php echo esc_html( absint( $row['ms'] ) . ' ms' ); ?></span></div>
					</div>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Verde confirma la comprobación descrita. Gris indica no aplicable o sin ejecutar. Las muestras no sustituyen una auditoría completa ni las pruebas de escritura de staging.', 'taxocommerce-images' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
