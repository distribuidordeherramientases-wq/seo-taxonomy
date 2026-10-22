<?php
/**
 * Focused, responsive WordPress administration dashboard for TaxoCommerce Core.
 *
 * Presentation only: no data migration, background jobs or stored state.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Core home screen without mixing UI with plugin service logic.
 */
final class TaxoCommerce_Core_Dashboard {

	/**
	 * Render the Core dashboard with live module/service counts.
	 *
	 * @param array $modules     Registered modules.
	 * @param bool  $legacy      Whether TaxoCommerce SEO is loaded.
	 * @param bool  $woocommerce Whether WooCommerce is loaded.
	 * @return void
	 */
	public static function render( $modules, $legacy, $woocommerce ) {
		$modules     = is_array( $modules ) ? $modules : array();
		$legacy      = (bool) $legacy;
		$woocommerce = (bool) $woocommerce;

		$metrics = array(
			array(
				'icon'  => 'dashicons-admin-generic',
				'label' => __( 'Versión del Core', 'taxocommerce-core' ),
				'value' => TAXOCOMMERCE_CORE_VERSION,
				'tone'  => 'neutral',
			),
			array(
				'icon'  => 'dashicons-cart',
				'label' => __( 'WooCommerce', 'taxocommerce-core' ),
				'value' => $woocommerce ? __( 'Detectado', 'taxocommerce-core' ) : __( 'No detectado', 'taxocommerce-core' ),
				'tone'  => $woocommerce ? 'success' : 'warning',
			),
			array(
				'icon'  => 'dashicons-chart-bar',
				'label' => __( 'TaxoCommerce SEO actual', 'taxocommerce-core' ),
				'value' => $legacy ? __( 'Detectado · convivencia', 'taxocommerce-core' ) : __( 'No detectado', 'taxocommerce-core' ),
				'tone'  => $legacy ? 'info' : 'muted',
			),
			array(
				'icon'  => 'dashicons-admin-plugins',
				'label' => __( 'Extensiones registradas', 'taxocommerce-core' ),
				'value' => number_format_i18n( count( $modules ) ),
				'tone'  => 'neutral',
			),
		);

		$navigation = array(
			array(
				'title'       => __( 'Contenidos', 'taxocommerce-core' ),
				'icon'        => 'dashicons-text-page',
				'description' => __( 'Edita productos, categorías de WooCommerce, entradas y páginas desde un entorno unificado.', 'taxocommerce-core' ),
				'state'       => __( 'Piloto funcional', 'taxocommerce-core' ),
				'tone'        => 'success',
				'slug'        => TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
			),
			array(
				'title'       => __( 'Jerarquía SEO', 'taxocommerce-core' ),
				'icon'        => 'dashicons-networking',
				'description' => __( 'Gestiona la arquitectura Cluster → Hub primario → Hub secundario → Categoría → Producto.', 'taxocommerce-core' ),
				'state'       => __( 'Modelo nativo del Core', 'taxocommerce-core' ),
				'tone'        => 'info',
				'slug'        => 'taxocommerce-core-hierarchy',
			),
			array(
				'title'       => __( 'Gestor de menús', 'taxocommerce-core' ),
				'icon'        => 'dashicons-menu-alt3',
				'description' => __( 'Organiza la navegación del sitio a partir de la jerarquía SEO y de los menús de WordPress.', 'taxocommerce-core' ),
				'state'       => __( 'Migrado al Core', 'taxocommerce-core' ),
				'tone'        => 'info',
				'slug'        => 'taxocommerce-core-menu',
			),
			array(
				'title'       => __( 'Extensiones', 'taxocommerce-core' ),
				'icon'        => 'dashicons-admin-plugins',
				'description' => __( 'Descubre y administra los módulos de la familia TaxoCommerce de forma independiente.', 'taxocommerce-core' ),
				'state'       => __( 'Sistema modular', 'taxocommerce-core' ),
				'tone'        => 'purple',
				'slug'        => 'taxocommerce-core-modules',
			),

		);

		$migration = array(
			array(
				'name' => __( 'Contenidos', 'taxocommerce-core' ),
				'old'  => __( 'Edición de productos, categorías, entradas y páginas distribuida entre WordPress, WooCommerce y TaxoCommerce.', 'taxocommerce-core' ),
				'state'=> __( 'Piloto funcional', 'taxocommerce-core' ),
				'tone' => 'success',
			),
			array(
				'name' => __( 'Jerarquía SEO', 'taxocommerce-core' ),
				'old'  => __( 'Cluster → Hub primario → Hub secundario → Categoría → Producto y sus relaciones.', 'taxocommerce-core' ),
				'state'=> __( 'Modelo nativo del Core', 'taxocommerce-core' ),
				'tone' => 'info',
			),
			array(
				'name' => __( 'Gestor de menús', 'taxocommerce-core' ),
				'old'  => __( 'Menu Manager, jerarquía SEO y menús nativos de WordPress.', 'taxocommerce-core' ),
				'state'=> __( 'Migrado al Core', 'taxocommerce-core' ),
				'tone' => 'info',
			),
			array(
				'name' => __( 'Extensiones', 'taxocommerce-core' ),
				'old'  => __( 'Imágenes, Vocabulary, Auditor, Mercado, Proveedores y otros módulos del monolito.', 'taxocommerce-core' ),
				'state'=> __( 'Sistema modular', 'taxocommerce-core' ),
				'tone' => 'purple',
			),

		);
		?>
		<div class="wrap tcc-dashboard" id="taxocommerce-core-dashboard">
			<header class="tcc-hero" aria-labelledby="tcc-dashboard-title">
				<div class="tcc-hero__identity">
					<div class="tcc-hero__emblem" aria-hidden="true"><span class="dashicons dashicons-products"></span></div>
					<div class="tcc-hero__copy">
						<p class="tcc-eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
						<h1 id="tcc-dashboard-title"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></h1>
						<p><?php esc_html_e( 'El núcleo modular para gestionar la arquitectura, los contenidos y las operaciones de tu tienda WooCommerce.', 'taxocommerce-core' ); ?></p>
						<p class="tcc-hero__sub"><?php esc_html_e( 'Diseñado para convivir con TaxoCommerce SEO mientras migras cada función de forma controlada.', 'taxocommerce-core' ); ?></p>
					</div>
				</div>
				<div class="tcc-hero__art" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
			</header>

			<section class="tcc-metrics" aria-label="<?php esc_attr_e( 'Estado del sistema', 'taxocommerce-core' ); ?>">
				<?php foreach ( $metrics as $metric ) : ?>
					<div class="tcc-metric">
						<span class="tcc-metric__icon dashicons <?php echo esc_attr( $metric['icon'] ); ?>" aria-hidden="true"></span>
						<div class="tcc-metric__content">
							<span class="tcc-metric__label"><?php echo esc_html( $metric['label'] ); ?></span>
							<?php if ( 'neutral' === $metric['tone'] ) : ?>
								<strong class="tcc-metric__value"><?php echo esc_html( $metric['value'] ); ?></strong>
							<?php else : ?>
								<span class="tcc-status tcc-status--<?php echo esc_attr( $metric['tone'] ); ?>"><?php echo esc_html( $metric['value'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="tcc-section" aria-labelledby="tcc-modules-title">
				<div class="tcc-section__header">
					<div>
						<h2 id="tcc-modules-title"><?php esc_html_e( 'Módulos del Core', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'Accede a las funciones disponibles desde un panel organizado y coherente.', 'taxocommerce-core' ); ?></p>
					</div>
				</div>
				<div class="tcc-module-grid">
					<?php foreach ( $navigation as $module ) : ?>
						<article class="tcc-module">
							<span class="tcc-module__icon dashicons <?php echo esc_attr( $module['icon'] ); ?>" aria-hidden="true"></span>
							<h3><?php echo esc_html( $module['title'] ); ?></h3>
							<p class="tcc-module__description"><?php echo esc_html( $module['description'] ); ?></p>
							<div class="tcc-module__footer">
								<span class="tcc-status tcc-status--<?php echo esc_attr( $module['tone'] ); ?>"><?php echo esc_html( $module['state'] ); ?></span>
								<a class="tcc-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $module['slug'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Abrir %s', 'taxocommerce-core' ), $module['title'] ) ); ?>"><?php esc_html_e( 'Abrir', 'taxocommerce-core' ); ?> <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="tcc-migration" aria-labelledby="tcc-migration-title">
				<div class="tcc-migration__header">
					<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
					<h2 id="tcc-migration-title"><?php esc_html_e( 'Mapa de migración', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'Correspondencias del plugin actual con el nuevo Core. Los estados indican el avance real de cada módulo.', 'taxocommerce-core' ); ?></p>
				</div>
				<div class="tcc-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Tabla de migración del Core', 'taxocommerce-core' ); ?>" tabindex="0">
					<table class="tcc-migration__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Nuevo Core', 'taxocommerce-core' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Equivalente en el plugin actual', 'taxocommerce-core' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Estado de migración', 'taxocommerce-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $migration as $item ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $item['name'] ); ?></th>
									<td><?php echo esc_html( $item['old'] ); ?></td>
									<td><span class="tcc-migration__status tcc-migration__status--<?php echo esc_attr( $item['tone'] ); ?>"><?php echo esc_html( $item['state'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}
}
