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
					'description' => __( 'Gestión, auditoría, miniaturas, metadatos y optimización de imágenes.', 'taxocommerce-images' ),
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
				__( 'Imágenes', 'taxocommerce-images' ),
				__( 'Imágenes', 'taxocommerce-images' ),
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
	 * Summary dashboard.
	 *
	 * @return void
	 */
	private function render_summary() {
		$formats = TaxoCommerce_Images_Inventory::formats();
		$sources = TaxoCommerce_Images_Inventory::external_sources();
		?>
		<h2><?php esc_html_e( 'Formatos en Media local', 'taxocommerce-images' ); ?></h2>
		<div class="tcc-stat-grid">
			<?php foreach ( array( 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WebP', 'avif' => 'AVIF', 'other' => __( 'Otros', 'taxocommerce-images' ) ) as $type => $label ) : ?>
				<div class="card"><strong style="display:block;font-size:24px;"><?php echo esc_html( number_format_i18n( $formats[ $type ] ) ); ?></strong><?php echo esc_html( $label ); ?></div>
			<?php endforeach; ?>
		</div>
		<h2><?php esc_html_e( 'Fuentes externas (integración opcional)', 'taxocommerce-images' ); ?></h2>
		<p><?php esc_html_e( 'Las imágenes externas no son archivos locales. Su inventario es de solo lectura.', 'taxocommerce-images' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Proveedor', 'taxocommerce-images' ); ?></th><th><?php esc_html_e( 'Imágenes', 'taxocommerce-images' ); ?></th><th><?php esc_html_e( 'Productos', 'taxocommerce-images' ); ?></th><th><?php esc_html_e( 'Sin producto', 'taxocommerce-images' ); ?></th></tr></thead><tbody>
		<?php foreach ( $sources as $source ) : ?>
			<tr><td><?php echo esc_html( $source['name'] ?? '' ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $source['images'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $source['products'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $source['unlinked'] ?? 0 ) ) ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $sources ) : ?><tr><td colspan="4"><?php esc_html_e( 'No hay fuentes externas conectadas.', 'taxocommerce-images' ); ?></td></tr><?php endif; ?>
		</tbody></table>
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
			<p><?php echo esc_html( sprintf( __( 'Última auditoría: %1$s · %2$d/%3$d imágenes revisadas.', 'taxocommerce-images' ), (string) ( $audit['updated_at'] ?? '—' ), absint( $audit['scanned'] ?? 0 ), absint( $audit['total'] ?? 0 ) ) ); ?></p>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Criterios del nuevo sistema', 'taxocommerce-images' ); ?></h2>
		<ul style="list-style:disc;margin-left:24px;">
			<li><?php echo esc_html__( 'No depender de imágenes externas para mostrar normalmente el catálogo.', 'taxocommerce-images' ); ?></li>
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
		<p><?php echo esc_html__( 'La auditoría trabaja por lotes de 150 imágenes para evitar una operación larga. No borra ni modifica archivos.', 'taxocommerce-images' ); ?></p>

		<?php if ( empty( $state ) ) : ?>
			<?php $this->audit_button( 'taxocommerce_images_start_audit', 'taxocommerce_images_start_audit', __( 'Iniciar auditoría', 'taxocommerce-images' ), true ); ?>
		<?php else : ?>
			<p><strong><?php echo esc_html__( 'Estado:', 'taxocommerce-images' ); ?></strong> <?php echo esc_html( 'completed' === ( $state['status'] ?? '' ) ? __( 'Completada', 'taxocommerce-images' ) : __( 'En curso', 'taxocommerce-images' ) ); ?> · <?php echo esc_html( absint( $state['scanned'] ?? 0 ) . ' / ' . absint( $state['total'] ?? 0 ) ); ?></p>
			<?php if ( 'completed' !== ( $state['status'] ?? '' ) ) : ?>
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
		<?php
	}

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
		?>
		<h2><?php echo esc_html__( 'Miniaturas registradas en WordPress', 'taxocommerce-images' ); ?></h2>
		<p><?php echo esc_html__( 'TaxoCommerce Images no inventa un segundo sistema de miniaturas: muestra y regenera las medidas que WordPress, WooCommerce y el tema tienen registradas.', 'taxocommerce-images' ); ?></p>
		<table class="widefat striped" style="max-width:850px;">
			<thead><tr><th><?php echo esc_html__( 'Nombre', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Ancho', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Alto', 'taxocommerce-images' ); ?></th><th><?php echo esc_html__( 'Recorte', 'taxocommerce-images' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $sizes as $name => $size ) : ?>
				<tr><td><code><?php echo esc_html( $name ); ?></code></td><td><?php echo esc_html( (string) absint( $size['width'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) absint( $size['height'] ?? 0 ) ); ?></td><td><?php echo ! empty( $size['crop'] ) ? esc_html__( 'Sí', 'taxocommerce-images' ) : esc_html__( 'No', 'taxocommerce-images' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php echo esc_html__( 'La Auditoría identifica attachments a los que les faltan tamaños que podrían generarse. Desde Biblioteca puedes regenerar las miniaturas de cada imagen.', 'taxocommerce-images' ); ?></p>
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
