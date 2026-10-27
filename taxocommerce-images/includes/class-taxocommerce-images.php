<?php
/**
 * Main administration for TaxoCommerce Images.
 *
 * @package TaxoCommerceImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the standalone image-management extension.
 */
final class TaxoCommerce_Images {

	/**
	 * Singleton.
	 *
	 * @var TaxoCommerce_Images|null
	 */
	private static $instance = null;

	/**
	 * Audit service.
	 *
	 * @var TaxoCommerce_Images_Audit
	 */
	private $audit;

	/**
	 * Write actions.
	 *
	 * @var TaxoCommerce_Images_Actions
	 */
	private $actions;

	/**
	 * Returns singleton.
	 *
	 * @return TaxoCommerce_Images
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot plugin.
	 *
	 * @return void
	 */
	public function boot() {
		$this->audit   = new TaxoCommerce_Images_Audit();
		$this->actions = new TaxoCommerce_Images_Actions();

		$this->audit->register_hooks();
		$this->actions->register_hooks();

		add_action( 'taxocommerce_core_register_modules', array( $this, 'register_with_core' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_family_admin_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'family_admin_body_class' ) );
		add_filter( 'plugin_action_links_' . TAXOCOMMERCE_IMAGES_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'taxocommerce_core_content_summary_kpis', array( $this, 'add_core_product_kpi' ), 20, 2 );
	}

	/**
	 * Use the common family theme even when this extension runs standalone.
	 * If Core is active it owns the shared stylesheet and body class.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_family_admin_styles( $hook ) {
		if ( false === strpos( (string) $hook, 'taxocommerce-images' ) ) {
			return;
		}
		wp_enqueue_script(
			'taxocommerce-images-legacy-jobs',
			plugin_dir_url( TAXOCOMMERCE_IMAGES_FILE ) . 'assets/legacy-jobs.js',
			array(),
			TAXOCOMMERCE_IMAGES_VERSION,
			true
		);
		wp_localize_script(
			'taxocommerce-images-legacy-jobs',
			'taxocommerceImagesJobs',
			array(
				'ajax' => admin_url( 'admin-ajax.php' ),
				'optimizerNonce' => wp_create_nonce( 'tci_optimizer_run' ),
				'webpNonce' => wp_create_nonce( 'tci_webp_run' ),
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'summary';
		if ( 'assignment' === $tab ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'taxocommerce-images-media-picker',
				plugin_dir_url( TAXOCOMMERCE_IMAGES_FILE ) . 'assets/assignment-media.js',
				array( 'media-editor' ),
				TAXOCOMMERCE_IMAGES_VERSION,
				true
			);
		}
		if ( defined( 'TAXOCOMMERCE_CORE_VERSION' ) || false === strpos( (string) $hook, 'taxocommerce-images' ) ) {
			return;
		}
		wp_enqueue_style(
			'taxocommerce-family-admin',
			plugin_dir_url( TAXOCOMMERCE_IMAGES_FILE ) . 'assets/admin.css',
			array(),
			TAXOCOMMERCE_IMAGES_VERSION
		);
	}

	/**
	 * Isolate the shared appearance from unrelated WordPress plugin screens.
	 *
	 * @param string $classes Admin body class string.
	 * @return string
	 */
	public function family_admin_body_class( $classes ) {
		if ( defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
			return $classes;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, 'taxocommerce-images' )
			? $classes . ' tcc-family-admin'
			: $classes;
	}

	/**
	 * Register extension in Core.
	 *
	 * @param object $core Core registry.
	 * @return void
	 */
	public function register_with_core( $core ) {
		if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
			$core->register_module(
				'images',
				array(
					'name'        => 'TaxoCommerce Images',
					'version'     => TAXOCOMMERCE_IMAGES_VERSION,
					'status'      => 'active',
					'description' => 'Gestión, auditoría, miniaturas, metadatos y optimización de imágenes.',
					'admin_url'   => admin_url( 'admin.php?page=taxocommerce-images' ),
				)
			);
		}
	}

	/**
	 * Register inside Core when available, otherwise as standalone menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		$capability = (string) apply_filters( 'taxocommerce_images_manage_capability', 'manage_options' );

		if ( defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
			add_submenu_page(
				'taxocommerce-core',
				__( 'Images', 'taxocommerce-images' ),
				__( 'Images', 'taxocommerce-images' ),
				$capability,
				'taxocommerce-images',
				array( $this, 'render' )
			);
		} else {
			add_menu_page(
				__( 'TaxoCommerce Images', 'taxocommerce-images' ),
				__( 'TaxoCommerce Images', 'taxocommerce-images' ),
				$capability,
				'taxocommerce-images',
				array( $this, 'render' ),
				'dashicons-format-image',
				59
			);
		}
	}

	/**
	 * Plugin-list shortcut.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=taxocommerce-images' ) ) . '">' . esc_html__( 'Abrir', 'taxocommerce-images' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Add image KPI to Core product overview.
	 *
	 * @param array  $cards Content KPI cards.
	 * @param string $content_type Content type.
	 * @return array
	 */
	public function add_core_product_kpi( $cards, $content_type ) {
		if ( 'products' === $content_type && post_type_exists( 'product' ) ) {
			$cards[ __( 'Productos sin imagen', 'taxocommerce-images' ) ] = $this->products_without_featured_image_count();
		}
		return $cards;
	}

	/**
	 * Main administration screen.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'summary'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tabs = array(
			'summary'    => __( 'Inventario', 'taxocommerce-images' ),
			'audit'      => __( 'Anomalías', 'taxocommerce-images' ),
			'assignment' => __( 'Asignación', 'taxocommerce-images' ),
			'references' => __( 'Sin uso y duplicadas', 'taxocommerce-images' ),
			'labels'     => __( 'Etiquetas y atributos', 'taxocommerce-images' ),
			'library'    => __( 'Media local', 'taxocommerce-images' ),
			'thumbnails' => __( 'Miniaturas', 'taxocommerce-images' ),
			'settings'   => __( 'Ajustes', 'taxocommerce-images' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'summary';
		}
		?>
		<div class="wrap tcc-family-screen">
			<header class="tcc-page-header">
				<span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
				<div class="tcc-page-header__content">
					<p class="tcc-page-header__eyebrow"><?php esc_html_e( 'Familia TaxoCommerce', 'taxocommerce-images' ); ?></p>
					<h1><?php echo esc_html__( 'TaxoCommerce Images', 'taxocommerce-images' ); ?></h1>
					<p><?php echo esc_html__( 'Media de WordPress es la fuente de verdad. La importación y exportación de archivos pertenece a TaxoCommerce Import-Export.', 'taxocommerce-images' ); ?></p>
				</div>
			</header>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Secciones de imágenes', 'taxocommerce-images' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-images', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php $this->render_notice(); ?>
			<?php
			switch ( $tab ) {
				case 'audit':
					$this->render_audit();
					break;
				case 'assignment':
					$this->render_assignment();
					break;
				case 'labels':
					$this->render_labels();
					break;
				case 'references':
					$this->render_references();
					break;
				case 'library':
					$this->render_library();
					break;
				case 'thumbnails':
					$this->render_thumbnails();
					break;
				case 'settings':
					$this->render_settings();
					break;
				default:
					$this->render_summary();
					break;
			}
			?>
		</div>
		<?php
	}


	/**
	 * Four independently paged reports, restricted to related categories.
	 * Every action is a real nonce-verified, administrator-only handler.
	 *
	 * @return void
	 */
	private function render_assignment() {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Productos: solo se propone una imagen de su propia galería. Nunca la de otro producto. Cada grupo de asignación tiene su propio regulador 1–5 y puede ejecutarse en paralelo con los demás; los lotes son internos y adaptativos.', 'taxocommerce-images' ) . '</p></div>';
		?>
		<h2><?php esc_html_e( 'Asignación de imágenes por categorías relacionadas', 'taxocommerce-images' ); ?></h2>
		<p><?php esc_html_e( 'Primero se propone la imagen asociada a la categoría; después, imágenes de productos o entradas pertenecientes a esa categoría. No se utiliza una imagen aleatoria de Media ni se descargan imágenes de proveedores.', 'taxocommerce-images' ); ?></p>
		<p><?php esc_html_e( 'Puedes asignar individualmente una imagen sugerida, elegir una imagen existente en Media, lanzar una pasada automática completa por grupo o subir un archivo desde tu equipo. La pasada automática se divide en lotes internos regulados y puede pausarse o reanudarse sin un control central.', 'taxocommerce-images' ); ?></p>
		<?php
		foreach ( TaxoCommerce_Images_Assignment::scopes() as $scope => $heading ) {
			$param = 'tc_assign_' . sanitize_key( $scope ) . '_page';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only read-only navigation page.
			$current = isset( $_GET[ $param ] ) && is_scalar( $_GET[ $param ] )
				? max( 1, absint( wp_unslash( $_GET[ $param ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
				: 1;
			$report = TaxoCommerce_Images_Assignment::missing( $scope, $current );
			$process = 'assignment_' . sanitize_key( $scope );
			$priority = TaxoCommerce_Images_Load::priority( $process );
			$sample = TaxoCommerce_Images_Load::last_sample( $process );
			$assignment_state = TaxoCommerce_Images_Assignment::assignment_state( $scope );
			?>
			<section class="tci-assign-section" id="tc-assign-<?php echo esc_attr( $scope ); ?>">
				<h2><?php echo esc_html( $heading ); ?> <small>(<?php echo esc_html( number_format_i18n( absint( $report['total'] ) ) ); ?> <?php esc_html_e( 'sin imagen', 'taxocommerce-images' ); ?>)</small></h2>
				<?php if ( 'product' === $scope && ! post_type_exists( 'product' ) ) : ?>
					<p><?php esc_html_e( 'No hay un tipo de producto registrado. Este apartado se activa con WooCommerce.', 'taxocommerce-images' ); ?></p>
				<?php elseif ( empty( $report['total'] ) ) : ?>
					<p><?php esc_html_e( 'No hay elementos sin imagen asignada.', 'taxocommerce-images' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tci-load-control" style="margin:12px 0;">
						<input type="hidden" name="action" value="taxocommerce_images_save_priority">
						<input type="hidden" name="mode" value="<?php echo esc_attr( $process ); ?>">
						<?php wp_nonce_field( 'taxocommerce_images_save_priority', 'taxocommerce_images_nonce' ); ?>
						<label for="tci-priority-<?php echo esc_attr( $process ); ?>"><strong><?php
						/* translators: %s: assignment group name, for example Pages or Products. */
						echo esc_html( sprintf( __( 'Prioridad de %s', 'taxocommerce-images' ), $heading ) );
						?></strong></label>
						<div class="tci-load-control__slider">
							<span><?php esc_html_e( '1 · Suave', 'taxocommerce-images' ); ?></span>
							<input id="tci-priority-<?php echo esc_attr( $process ); ?>" name="priority" type="range" min="1" max="5" step="1" value="<?php echo esc_attr( (string) $priority ); ?>" data-tci-priority>
							<span><?php esc_html_e( '5 · Máxima', 'taxocommerce-images' ); ?></span>
							<output data-tci-priority-output><strong><?php echo esc_html( $priority . ' · ' . TaxoCommerce_Images_Load::label( $priority ) ); ?></strong></output>
						</div>
						<p class="description"><?php esc_html_e( 'Este control solo afecta a este grupo. Si otros procesos cargan el servidor, el tiempo real de sus peticiones hará que reduzca automáticamente el siguiente lote.', 'taxocommerce-images' ); ?></p>
						<button type="submit" class="button"><?php esc_html_e( 'Guardar prioridad', 'taxocommerce-images' ); ?></button>
						<?php if ( $sample ) : ?>
							<span class="description"><?php
							/* translators: 1: processed items, 2: elapsed seconds, 3: next adaptive batch size. */
							echo esc_html( sprintf( __( ' Último lote: %1$d elementos · %2$s s · siguiente lote %3$d.', 'taxocommerce-images' ), absint( $sample['items'] ?? 0 ), number_format_i18n( (float) ( $sample['elapsed'] ?? 0 ), 2 ), absint( $sample['next_batch'] ?? 0 ) ) );
							?></span>
						<?php endif; ?>
					</form>
					<div class="tci-assign-bulk" data-tci-assignment="<?php echo esc_attr( $scope ); ?>">
						<button type="button" class="button button-primary" data-tci-assignment-operation="start"><?php esc_html_e( 'Asignar automáticamente todo el grupo', 'taxocommerce-images' ); ?></button>
						<button type="button" class="button" data-tci-assignment-operation="scan" <?php disabled( empty( $assignment_state ) || 'running' !== ( $assignment_state['status'] ?? '' ) ); ?>><?php esc_html_e( 'Continuar', 'taxocommerce-images' ); ?></button>
						<button type="button" class="button" data-tci-assignment-pause><?php esc_html_e( 'Pausar', 'taxocommerce-images' ); ?></button>
						<span class="description"><?php esc_html_e( 'Solo se asigna si hay un candidato de la categoría relacionada; no se cambia ninguna imagen existente.', 'taxocommerce-images' ); ?></span>
						<p data-tci-assignment-progress role="status" aria-live="polite">
							<?php
							if ( $assignment_state ) {
								echo esc_html( sprintf(
									/* translators: 1: reviewed objects, 2: assigned images, 3: remaining objects. */
									__( 'Última pasada: %1$d revisados · %2$d asignados · %3$d pendientes.', 'taxocommerce-images' ),
									absint( $assignment_state['seen'] ?? 0 ),
									absint( $assignment_state['assigned'] ?? 0 ),
									absint( $assignment_state['remaining'] ?? $report['total'] )
								) );
							} else {
								esc_html_e( 'No hay una pasada automática iniciada.', 'taxocommerce-images' );
							}
							?>
						</p>
						<noscript>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="taxocommerce_images_assign_auto">
								<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
								<?php wp_nonce_field( 'taxocommerce_images_assign_auto', 'taxocommerce_images_nonce' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Procesar siguiente lote', 'taxocommerce-images' ); ?></button>
							</form>
						</noscript>
					</div>
					<div class="tci-assign-scroll">
					<table class="widefat striped">
						<thead>
							<tr><th><?php esc_html_e( 'Elemento sin imagen', 'taxocommerce-images' ); ?></th><th><?php esc_html_e( 'Imágenes relacionadas y asignación', 'taxocommerce-images' ); ?></th></tr>
						</thead>
						<tbody>
						<?php foreach ( $report['items'] as $item ) : ?>
							<?php
							$id = absint( $item['id'] );
							$related = TaxoCommerce_Images_Assignment::related_terms( $scope, $id );
							$candidates = TaxoCommerce_Images_Assignment::candidates( $scope, $id, 6 );
							$names = array();
							foreach ( $related as $taxonomy => $ids ) {
								foreach ( $ids as $term_id ) {
									$term = get_term( $term_id, $taxonomy );
									if ( $term instanceof WP_Term ) {
										$names[] = $term->name;
									}
								}
							}
							$edit_url = in_array( $scope, array( 'category', 'product_cat' ), true )
								? get_edit_term_link( $id, $scope )
								: get_edit_post_link( $id );
							?>
							<tr>
								<td class="tci-assign-object">
									<strong><?php echo esc_html( (string) $item['title'] ); ?></strong> <code>#<?php echo esc_html( (string) $id ); ?></code>
									<?php if ( $edit_url ) : ?>
										<p><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Editar elemento', 'taxocommerce-images' ); ?></a></p>
									<?php endif; ?>
									<p class="description">
										<?php if ( $names ) : ?>
											<?php esc_html_e( 'Categorías:', 'taxocommerce-images' ); ?> <?php echo esc_html( implode( ', ', $names ) ); ?>
										<?php else : ?>
											<?php esc_html_e( 'Sin categoría relacionada verificable.', 'taxocommerce-images' ); ?>
										<?php endif; ?>
									</p>
								</td>
								<td>
									<?php if ( $candidates ) : ?>
										<div class="tci-assign-candidates">
										<?php foreach ( $candidates as $candidate ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tci-assign-candidate">
												<input type="hidden" name="action" value="taxocommerce_images_assign_one">
												<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
												<input type="hidden" name="object_id" value="<?php echo esc_attr( (string) $id ); ?>">
												<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) absint( $candidate['id'] ) ); ?>">
												<?php wp_nonce_field( 'taxocommerce_images_assign_one', 'taxocommerce_images_nonce' ); ?>
												<?php echo wp_get_attachment_image( absint( $candidate['id'] ), 'thumbnail', false, array( 'loading' => 'lazy', 'class' => 'tci-assign-preview' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image HTML generated by the WordPress Media API. ?>
												<small><?php echo esc_html( $candidate['label'] ); ?></small>
												<button type="submit" class="button button-secondary"><?php esc_html_e( 'Asignar esta imagen', 'taxocommerce-images' ); ?></button>
											</form>
										<?php endforeach; ?>
										</div>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'No hay imágenes válidas en las categorías relacionadas. Puedes elegir una existente de Media o subir una propia.', 'taxocommerce-images' ); ?></p>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tci-assign-media" data-tci-media-picker>
										<input type="hidden" name="action" value="taxocommerce_images_assign_media">
										<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
										<input type="hidden" name="object_id" value="<?php echo esc_attr( (string) $id ); ?>">
										<input type="hidden" name="attachment_id" value="" data-tci-media-id>
										<?php wp_nonce_field( 'taxocommerce_images_assign_media', 'taxocommerce_images_nonce' ); ?>
										<button type="button" class="button button-secondary" data-tci-media-open><?php esc_html_e( 'Buscar en Media', 'taxocommerce-images' ); ?></button>
										<span class="tci-assign-media-selection" data-tci-media-selection><?php esc_html_e( 'Ninguna imagen seleccionada.', 'taxocommerce-images' ); ?></span>
										<button type="submit" class="button" data-tci-media-submit disabled><?php esc_html_e( 'Asignar desde Media', 'taxocommerce-images' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="tci-assign-upload">
										<input type="hidden" name="action" value="taxocommerce_images_assign_upload">
										<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
										<input type="hidden" name="object_id" value="<?php echo esc_attr( (string) $id ); ?>">
										<?php wp_nonce_field( 'taxocommerce_images_assign_upload', 'taxocommerce_images_nonce' ); ?>
										<label for="tci-upload-<?php echo esc_attr( $scope . '-' . $id ); ?>"><?php echo esc_html( sprintf(
										/* translators: %s: maximum WordPress upload size. */
										__( 'Subir imagen desde el equipo (límite: %s)', 'taxocommerce-images' ),
										size_format( wp_max_upload_size() )
									) ); ?></label>
										<input id="tci-upload-<?php echo esc_attr( $scope . '-' . $id ); ?>" type="file" name="manual_image" accept="image/*" required>
										<button type="submit" class="button"><?php esc_html_e( 'Subir y asignar', 'taxocommerce-images' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php if ( $report['pages'] > 1 ) : ?>
						<div class="tci-assign-pagination">
						<?php
						$base = admin_url( 'admin.php' );
						$args = array( 'page' => 'taxocommerce-images', 'tab' => 'assignment', $param => $current - 1 );
						if ( $current > 1 ) :
							?>
							<a class="button" href="<?php echo esc_url( add_query_arg( $args, $base ) . '#tc-assign-' . $scope ); ?>"><?php esc_html_e( 'Anterior', 'taxocommerce-images' ); ?></a>
							<?php
						endif;
						?>
						<span><?php echo esc_html( sprintf(
							/* translators: 1: current page; 2: total number of pages. */
							__( 'Página %1$d de %2$d', 'taxocommerce-images' ),
							$current,
							absint( $report['pages'] )
						) ); ?></span>
						<?php if ( $current < $report['pages'] ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-images', 'tab' => 'assignment', $param => $current + 1 ), $base ) . '#tc-assign-' . $scope ); ?>"><?php esc_html_e( 'Siguiente', 'taxocommerce-images' ); ?></a>
						<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</section>
			<?php
		}
	}

	/**
	 * Summary dashboard.
	 *
	 * @return void
	 */
	private function render_summary() {
		echo '<h2>' . esc_html__( 'Todos los elementos sin imagen asignada', 'taxocommerce-images' ) . '</h2><div class="tcc-stat-grid">';
		foreach ( TaxoCommerce_Images_Assignment::scopes() as $scope => $label ) {
			$pending = TaxoCommerce_Images_Assignment::missing( $scope, 1, 1 );
			echo '<div class="card"><strong>' . esc_html( number_format_i18n( $pending['total'] ) ) . '</strong> ' . esc_html( $label ) . '</div>';
		}
		echo '</div>';
		$formats = TaxoCommerce_Images_Inventory::formats();
		?>
		<h2><?php esc_html_e( 'Formatos en Media local', 'taxocommerce-images' ); ?></h2>
		<div class="tcc-stat-grid">
			<?php foreach ( array( 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WebP', 'avif' => 'AVIF', 'other' => __( 'Otros', 'taxocommerce-images' ) ) as $type => $label ) : ?>
				<div class="card"><strong style="display:block;font-size:24px;"><?php echo esc_html( number_format_i18n( $formats[ $type ] ) ); ?></strong><?php echo esc_html( $label ); ?></div>
			<?php endforeach; ?>
		</div>
		<h2><?php esc_html_e( 'Optimización de Media', 'taxocommerce-images' ); ?></h2>
		<p><?php esc_html_e( 'Conservamos la recompresión y conversión WebP del sistema anterior. Los lotes pequeños solo comienzan tras confirmación; la conversión modifica archivos y enlaces y requiere copia de seguridad.', 'taxocommerce-images' ); ?></p>
		<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
			<button type="button" class="button button-primary" id="tci-images-optimizer-run"><?php esc_html_e( 'Optimizar originales y miniaturas', 'taxocommerce-images' ); ?></button>
			<button type="button" class="button" id="tci-images-webp-run" <?php disabled( empty( $formats['convertible'] ) ); ?>><?php esc_html_e( 'Convertir JPG/PNG a WebP', 'taxocommerce-images' ); ?></button>
		</div>
		<p id="tci-images-jobs-status" role="status" aria-live="polite"></p>
		<?php
		$total_images = $this->image_count();
		$audit        = $this->audit->state();
		$summary      = isset( $audit['summary'] ) && is_array( $audit['summary'] ) ? $audit['summary'] : array();
		$cards        = array(
			__( 'Imágenes en Media', 'taxocommerce-images' )       => $total_images,
			__( 'Productos sin imagen destacada', 'taxocommerce-images' )    => $this->products_without_featured_image_count(),
			__( 'Sin ALT', 'taxocommerce-images' )                 => $summary['missing_alt'] ?? '—',
			__( 'Sin asociación padre', 'taxocommerce-images' )    => $summary['unattached'] ?? '—',
			__( 'Archivo físico ausente', 'taxocommerce-images' )  => $summary['missing_file'] ?? '—',
			__( 'Dimensiones excesivas', 'taxocommerce-images' )   => $summary['oversized_dims'] ?? '—',
			__( 'Miniaturas incompletas', 'taxocommerce-images' )  => $summary['missing_sizes'] ?? '—',
		);
		?>
		<h2><?php echo esc_html__( 'Estado de imágenes', 'taxocommerce-images' ); ?></h2>
		<div class="tcc-stat-grid">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="card"><strong style="display:block;font-size:24px;"><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php if ( empty( $audit ) ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html__( 'Ejecuta la Auditoría para calcular ALT ausentes, imágenes sin relación padre, archivos perdidos, tamaños excesivos y miniaturas incompletas.', 'taxocommerce-images' ); ?></p></div>
		<?php else : ?>
			<?php
			/* translators: 1: audit date, 2: images checked, 3: total images. */
			$audit_status_format = __( 'Última auditoría: %1$s · %2$d/%3$d imágenes revisadas.', 'taxocommerce-images' );
			?>
			<p><?php echo esc_html( sprintf( $audit_status_format, (string) ( $audit['updated_at'] ?? '—' ), absint( $audit['scanned'] ?? 0 ), absint( $audit['total'] ?? 0 ) ) ); ?></p>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Criterios del nuevo sistema', 'taxocommerce-images' ); ?></h2>
		<ul style="list-style:disc;margin-left:24px;">
			<li><?php echo esc_html__( 'Mantener título, ALT, leyenda y descripción gestionables desde un único lugar.', 'taxocommerce-images' ); ?></li>
			<li><?php echo esc_html__( 'Detectar imágenes grandes antes de optimizarlas; las reducciones manuales crean copia de seguridad.', 'taxocommerce-images' ); ?></li>
			<li><?php echo esc_html__( 'Usar las miniaturas registradas por WordPress y poder regenerarlas.', 'taxocommerce-images' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Audit tab.
	 *
	 * @return void
	 */
	private function render_audit() {
		$state = $this->audit->state();
		?>
		<h2><?php echo esc_html__( 'Auditoría de Biblioteca de Medios', 'taxocommerce-images' ); ?></h2>
		<p><?php echo esc_html__( 'La auditoría comprueba archivos originales, metadatos y miniaturas reales por lotes de 75. Cada lote guarda el cursor y libera la petición antes de continuar.', 'taxocommerce-images' ); ?></p>

		<?php if ( empty( $state ) ) : ?>
			<?php $this->audit_button( 'taxocommerce_images_start_audit', 'taxocommerce_images_start_audit', __( 'Iniciar auditoría', 'taxocommerce-images' ), true ); ?>
		<?php else : ?>
			<p><strong><?php echo esc_html__( 'Estado:', 'taxocommerce-images' ); ?></strong> <?php echo esc_html( 'completed' === ( $state['status'] ?? '' ) ? __( 'Completada', 'taxocommerce-images' ) : __( 'En curso', 'taxocommerce-images' ) ); ?> · <?php echo esc_html( absint( $state['scanned'] ?? 0 ) . ' / ' . absint( $state['total'] ?? 0 ) ); ?></p>
			<?php if ( 'completed' !== ( $state['status'] ?? '' ) ) : ?>
				<p id="tc-images-audit-progress" aria-live="polite"></p>
				<?php $this->audit_button( 'taxocommerce_images_continue_audit', 'taxocommerce_images_continue_audit', __( 'Continuar siguiente lote', 'taxocommerce-images' ), true ); ?>
			<?php endif; ?>
			<?php $this->audit_button( 'taxocommerce_images_start_audit', 'taxocommerce_images_start_audit', __( 'Reiniciar desde cero', 'taxocommerce-images' ), false ); ?>
			<?php $this->audit_button( 'taxocommerce_images_reset_audit', 'taxocommerce_images_reset_audit', __( 'Borrar informe', 'taxocommerce-images' ), false ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $state['summary'] ) ) : ?>
			<?php
			$labels = array(
				'unattached'      => __( 'Sin asociación padre', 'taxocommerce-images' ),
				'missing_alt'     => __( 'Sin texto ALT', 'taxocommerce-images' ),
				'missing_file'    => __( 'Archivo físico ausente', 'taxocommerce-images' ),
				'oversized_dims'  => __( 'Dimensiones excesivas', 'taxocommerce-images' ),
				'oversized_bytes' => __( 'Peso excesivo', 'taxocommerce-images' ),
				'missing_sizes'   => __( 'Miniaturas incompletas', 'taxocommerce-images' ),
				'missing_metadata' => __( 'Metadatos de dimensiones ausentes', 'taxocommerce-images' ),
				'generic_title'   => __( 'Título igual al nombre de archivo', 'taxocommerce-images' ),
			);
			?>
			<div style="display:flex;flex-wrap:wrap;gap:12px;margin:18px 0;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<div class="card" style="min-width:170px;margin:0;padding:12px 16px;"><strong style="display:block;font-size:24px;"><?php echo esc_html( (string) absint( $state['summary'][ $key ] ?? 0 ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $labels as $key => $label ) : ?>
				<?php $ids = array_slice( array_map( 'absint', (array) ( $state['findings'][ $key ] ?? array() ) ), 0, 100 ); ?>
				<?php if ( ! empty( $ids ) ) : ?>
					<details style="margin:10px 0;">
						<summary><strong><?php echo esc_html( $label ); ?></strong> · <?php echo esc_html( (string) count( $ids ) ); ?> <?php echo esc_html__( 'mostradas', 'taxocommerce-images' ); ?></summary>
						<table class="widefat striped" style="margin-top:8px;">
							<thead><tr><th><?php echo esc_html__( 'Imagen', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Archivo', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Acciones', 'taxocommerce-images' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $ids as $id ) : ?>
								<tr>
									<td><?php echo wp_kses_post( wp_get_attachment_image( $id, array( 60, 60 ) ) ); ?> <strong><?php echo esc_html( get_the_title( $id ) ?: ( '#' . $id ) ); ?></strong></td>
									<td><code><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></code></td>
									<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-images', 'tab' => 'library', 'image_id' => $id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Revisar', 'taxocommerce-images' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( ! empty( $state ) && 'completed' !== ( $state['status'] ?? '' ) ) : ?>
		<script>
		(function () {
			'use strict';
			var progress = document.getElementById('tc-images-audit-progress');
			if (!progress || typeof window.fetch !== 'function') { return; }
			var endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var token = <?php echo wp_json_encode( wp_create_nonce( 'taxocommerce_images_audit' ) ); ?>;
			var remaining = true;
			function step() {
				if (!remaining) { return; }
				var params = new URLSearchParams();
				params.set('action', 'taxocommerce_images_audit_step');
				params.set('nonce', token);
				fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: params })
				.then(function (response) { if (!response.ok) { throw new Error('HTTP ' + response.status); } return response.json(); })
				.then(function (reply) {
					if (!reply.success || !reply.data) { throw new Error('Auditoría en pausa'); }
					progress.textContent = reply.data.scanned + ' / ' + reply.data.total + ' imágenes revisadas';
					remaining = reply.data.status !== 'completed';
					if (remaining) { window.setTimeout(step, 500); } else { window.location.reload(); }
				})
				.catch(function () {
					remaining = false;
					progress.textContent = 'Proceso interrumpido; utiliza Continuar siguiente lote para reanudar.';
				});
			}
			step();
		}());
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * On-demand HTTP availability for images linked from published content.
	 *
	 * @return void
	 */
	private function render_labels() { TaxoCommerce_Images_Maintenance::render( 'labels' ); }

	/**
	 * Deep native-reference report, using the exact detection functions copied
	 * from SEO Images. Candidates never authorize automatic deletion.
	 *
	 * @return void
	 */
	private function render_references() { TaxoCommerce_Images_Maintenance::render( 'cleanup' ); }

	/**
	 * Library and metadata editor.
	 *
	 * @return void
	 */
	private function render_library() {
		$image_id = isset( $_GET['image_id'] ) ? absint( $_GET['image_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		if ( $image_id ) {
			$this->render_image_editor( $image_id );
		}

		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => 40,
				'paged'                  => $page,
				's'                      => $s,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		?>
		<h2><?php echo esc_html__( 'Biblioteca gestionada', 'taxocommerce-images' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0;">
			<input type="hidden" name="page" value="taxocommerce-images">
			<input type="hidden" name="tab" value="library">
			<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="<?php echo esc_attr__( 'Buscar imagen', 'taxocommerce-images' ); ?>">
			<button class="button"><?php echo esc_html__( 'Buscar', 'taxocommerce-images' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Imagen', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Archivo', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Dimensiones / peso', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'ALT', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Asociación', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Acciones', 'taxocommerce-images' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $query->posts ) ) : ?>
				<tr><td colspan="6"><?php echo esc_html__( 'No se han encontrado imágenes.', 'taxocommerce-images' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $query->posts as $image ) : ?>
					<?php
					$metadata = wp_get_attachment_metadata( $image->ID );
					$file     = get_attached_file( $image->ID );
					$bytes    = $file && file_exists( $file ) ? filesize( $file ) : 0;
					$parent   = $image->post_parent ? get_post( $image->post_parent ) : null;
					?>
					<tr>
						<td><?php echo wp_kses_post( wp_get_attachment_image( $image->ID, array( 70, 70 ) ) ); ?><br><strong><?php echo esc_html( get_the_title( $image ) ); ?></strong><br><small>#<?php echo esc_html( (string) $image->ID ); ?></small></td>
						<td><code><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?></code><br><small><?php echo esc_html( $image->post_mime_type ); ?></small></td>
						<td><?php echo esc_html( absint( $metadata['width'] ?? 0 ) . ' × ' . absint( $metadata['height'] ?? 0 ) ); ?><br><?php echo esc_html( $bytes ? size_format( $bytes ) : '—' ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ) ?: '—' ); ?></td>
						<td><?php echo $parent ? esc_html( get_the_title( $parent ) . ' #' . $parent->ID ) : esc_html__( 'Sin post_parent', 'taxocommerce-images' ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-images', 'tab' => 'library', 'image_id' => $image->ID ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Metadatos', 'taxocommerce-images' ); ?></a>
							· <a href="<?php echo esc_url( get_edit_post_link( $image->ID, '' ) ); ?>"><?php echo esc_html__( 'Media', 'taxocommerce-images' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$this->pagination( absint( $query->max_num_pages ), $page, 'library', $s );
		wp_reset_postdata();
	}

	/**
	 * One attachment editor and maintenance actions.
	 *
	 * @param int $image_id Attachment ID.
	 * @return void
	 */
	private function render_image_editor( $image_id ) {
		$image = get_post( $image_id );
		if ( ! $image instanceof WP_Post || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image_id ) ) {
			return;
		}

		$file     = get_attached_file( $image_id );
		$metadata = wp_get_attachment_metadata( $image_id );
		$backup   = get_post_meta( $image_id, TaxoCommerce_Images_Actions::BACKUP_META, true );
		?>
		<div class="card" style="max-width:none;margin:18px 0;">
			<h2><?php echo esc_html__( 'Editar imagen', 'taxocommerce-images' ); ?> #<?php echo esc_html( (string) $image_id ); ?></h2>
			<div style="display:grid;grid-template-columns:minmax(160px,240px) 1fr;gap:20px;">
				<div><?php echo wp_kses_post( wp_get_attachment_image( $image_id, 'medium' ) ); ?><p><code><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?></code></p><p><?php echo esc_html( absint( $metadata['width'] ?? 0 ) . ' × ' . absint( $metadata['height'] ?? 0 ) ); ?></p></div>
				<div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_images_save_metadata">
						<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $image_id ); ?>">
						<?php wp_nonce_field( 'taxocommerce_images_save_metadata', 'taxocommerce_images_nonce' ); ?>
						<table class="form-table" role="presentation">
							<tr><th><label for="tc-img-title"><?php echo esc_html__( 'Título / nombre visible', 'taxocommerce-images' ); ?></label></th><td><input id="tc-img-title" class="large-text" name="title" value="<?php echo esc_attr( $image->post_title ); ?>" required></td></tr>
							<tr><th><label for="tc-img-alt"><?php echo esc_html__( 'Texto ALT', 'taxocommerce-images' ); ?></label></th><td><input id="tc-img-alt" class="large-text" name="alt" value="<?php echo esc_attr( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ); ?>"></td></tr>
							<tr><th><label for="tc-img-caption"><?php echo esc_html__( 'Leyenda', 'taxocommerce-images' ); ?></label></th><td><textarea id="tc-img-caption" class="large-text" rows="3" name="caption"><?php echo esc_textarea( $image->post_excerpt ); ?></textarea></td></tr>
							<tr><th><label for="tc-img-description"><?php echo esc_html__( 'Descripción', 'taxocommerce-images' ); ?></label></th><td><textarea id="tc-img-description" class="large-text" rows="5" name="description"><?php echo esc_textarea( $image->post_content ); ?></textarea></td></tr>
							<tr><th><?php echo esc_html__( 'Nombre físico', 'taxocommerce-images' ); ?></th><td><code><?php echo esc_html( $file ? wp_basename( $file ) : '—' ); ?></code><p class="description"><?php echo esc_html__( 'En esta primera versión se audita pero no se renombra físicamente para no romper URLs y miniaturas existentes.', 'taxocommerce-images' ); ?></p></td></tr>
						</table>
						<?php submit_button( __( 'Guardar metadatos', 'taxocommerce-images' ), 'primary', 'submit', false ); ?>
					</form>

					<hr>
					<div style="display:flex;gap:8px;flex-wrap:wrap;">
						<?php $this->maintenance_form( 'taxocommerce_images_regenerate', 'taxocommerce_images_regenerate', $image_id, __( 'Regenerar miniaturas', 'taxocommerce-images' ) ); ?>
						<?php $this->maintenance_form( 'taxocommerce_images_optimize', 'taxocommerce_images_optimize', $image_id, __( 'Reducir a límites configurados', 'taxocommerce-images' ) ); ?>
						<?php if ( $backup && file_exists( $backup ) ) : ?>
							<?php $this->maintenance_form( 'taxocommerce_images_restore_backup', 'taxocommerce_images_restore_backup', $image_id, __( 'Restaurar original', 'taxocommerce-images' ) ); ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Thumbnails tab.
	 *
	 * @return void
	 */
	private function render_thumbnails() {
		$sizes = wp_get_registered_image_subsizes();
		$disabled = TaxoCommerce_Images_Thumbnails::disabled_sizes();
		?>
		<h2><?php esc_html_e( 'Miniaturas registradas en WordPress', 'taxocommerce-images' ); ?></h2>
		<p><?php esc_html_e( 'La selección solo afecta a nuevas imágenes. No borra miniaturas existentes y puede afectar al diseño de la web si desactivas tamaños usados por el tema o WooCommerce.', 'taxocommerce-images' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="taxocommerce_images_save_thumbnail_policy">
			<?php wp_nonce_field( 'taxocommerce_images_save_thumbnail_policy', 'taxocommerce_images_nonce' ); ?>
			<table class="widefat striped" style="max-width:950px;">
				<thead><tr>
					<th><?php esc_html_e( 'Tamaño', 'taxocommerce-images' ); ?></th>
					<th><?php esc_html_e( 'Ancho', 'taxocommerce-images' ); ?></th>
					<th><?php esc_html_e( 'Alto', 'taxocommerce-images' ); ?></th>
					<th><?php esc_html_e( 'Recorte', 'taxocommerce-images' ); ?></th>
					<th><?php esc_html_e( 'No generar en nuevas subidas', 'taxocommerce-images' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $sizes as $name => $size ) : ?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( (string) absint( $size['width'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) absint( $size['height'] ?? 0 ) ); ?></td>
						<td><?php echo ! empty( $size['crop'] ) ? esc_html__( 'Sí', 'taxocommerce-images' ) : esc_html__( 'No', 'taxocommerce-images' ); ?></td>
						<td><label><input type="checkbox" name="disabled_sizes[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $disabled, true ) ); ?>><span class="screen-reader-text"><?php echo esc_html( $name ); ?></span></label></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Guardar tamaños desactivados', 'taxocommerce-images' ) ); ?>
		</form>
		<p><?php esc_html_e( 'Desde Biblioteca puedes regenerar las miniaturas de un archivo. La auditoría tendrá en cuenta qué tamaños has desactivado.', 'taxocommerce-images' ); ?></p>
		<?php
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private function render_settings() {
		$s = TaxoCommerce_Images_Actions::settings();
		?>
		<h2><?php echo esc_html__( 'Límites y optimización', 'taxocommerce-images' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="taxocommerce_images_save_settings">
			<?php wp_nonce_field( 'taxocommerce_images_save_settings', 'taxocommerce_images_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="tc-max-width"><?php echo esc_html__( 'Ancho máximo recomendado', 'taxocommerce-images' ); ?></label></th><td><input id="tc-max-width" type="number" min="320" max="8000" name="max_width" value="<?php echo esc_attr( (string) absint( $s['max_width'] ) ); ?>"> px</td></tr>
				<tr><th><label for="tc-max-height"><?php echo esc_html__( 'Alto máximo recomendado', 'taxocommerce-images' ); ?></label></th><td><input id="tc-max-height" type="number" min="320" max="8000" name="max_height" value="<?php echo esc_attr( (string) absint( $s['max_height'] ) ); ?>"> px</td></tr>
				<tr><th><label for="tc-max-bytes"><?php echo esc_html__( 'Peso máximo recomendado', 'taxocommerce-images' ); ?></label></th><td><input id="tc-max-bytes" type="number" min="100000" max="30000000" name="max_bytes" value="<?php echo esc_attr( (string) absint( $s['max_bytes'] ) ); ?>"> bytes <span class="description">(<?php echo esc_html( size_format( absint( $s['max_bytes'] ) ) ); ?>)</span></td></tr>
				<tr><th><label for="tc-quality"><?php echo esc_html__( 'Calidad de compresión', 'taxocommerce-images' ); ?></label></th><td><input id="tc-quality" type="number" min="40" max="95" name="quality" value="<?php echo esc_attr( (string) absint( $s['quality'] ) ); ?>"> / 100</td></tr>
				<tr><th><?php echo esc_html__( 'Nuevas subidas', 'taxocommerce-images' ); ?></th><td><label><input type="checkbox" name="enforce_upload_max" value="1" <?php checked( ! empty( $s['enforce_upload_max'] ) ); ?>> <?php echo esc_html__( 'Aplicar el umbral de imagen grande de WordPress usando estos límites.', 'taxocommerce-images' ); ?></label></td></tr>
				<tr><th><?php echo esc_html__( 'Calidad automática', 'taxocommerce-images' ); ?></th><td><label><input type="checkbox" name="enforce_quality" value="1" <?php checked( ! empty( $s['enforce_quality'] ) ); ?>> <?php echo esc_html__( 'Aplicar esta calidad cuando WordPress procese JPEG/WebP/AVIF.', 'taxocommerce-images' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Guardar ajustes', 'taxocommerce-images' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Audit action button.
	 *
	 * @param string $action Action.
	 * @param string $nonce Nonce action.
	 * @param string $label Label.
	 * @param bool   $primary Primary button.
	 * @return void
	 */
	private function audit_button( $action, $nonce, $label, $primary ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $nonce, 'taxocommerce_images_nonce' ); ?>
			<button class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Maintenance form.
	 *
	 * @param string $action Action.
	 * @param string $nonce Nonce action.
	 * @param int    $image_id Image ID.
	 * @param string $label Label.
	 * @return void
	 */
	private function maintenance_form( $action, $nonce, $image_id, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) absint( $image_id ) ); ?>">
			<?php wp_nonce_field( $nonce, 'taxocommerce_images_nonce' ); ?>
			<button class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Product count without a valid featured image.
	 *
	 * @return int
	 */
	private function products_without_featured_image_count() {
		if ( ! post_type_exists( 'product' ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only catalog diagnostic.
					'relation' => 'OR',
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_thumbnail_id',
						'value'   => '0',
						'compare' => '=',
					),
				),
			)
		);

		return absint( $query->found_posts );
	}

	/**
	 * Total local images.
	 *
	 * @return int
	 */
	private function image_count() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		return absint( $query->found_posts );
	}

	/**
	 * Notices.
	 *
	 * @return void
	 */
	private function render_notice() {
		$result  = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		$message = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		if ( $result && $message ) {
			$class = 'ok' === $result ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Pagination.
	 *
	 * @param int    $pages Total pages.
	 * @param int    $current Current.
	 * @param string $tab Tab.
	 * @param string $search Search.
	 * @return void
	 */
	private function pagination( $pages, $current, $tab, $search ) {
		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page' => 'taxocommerce-images',
				'tab'  => $tab,
				's'    => $search,
			),
			admin_url( 'admin.php' )
		);
		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%', $base ),
				'current' => max( 1, absint( $current ) ),
				'total'   => max( 1, absint( $pages ) ),
				'type'    => 'list',
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * Permission guard.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( apply_filters( 'taxocommerce_images_manage_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Images.', 'taxocommerce-images' ) );
		}
	}
}
