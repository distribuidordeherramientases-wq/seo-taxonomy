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
	public static function render( $modules, $legacy, $woocommerce, $extensions_renderer = null ) {
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
				'title'       => __( 'User Guide', 'taxocommerce-core' ),
				'icon'        => 'dashicons-book',
				'description' => __( 'Aprende para qué sirve Core y cómo utilizar los contenidos, la jerarquía SEO, los menús y las extensiones.', 'taxocommerce-core' ),
				'state'       => __( 'Manual de usuario', 'taxocommerce-core' ),
				'tone'        => 'info',
				'slug'        => TaxoCommerce_Core_User_Guide::PAGE_SLUG,
			),
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

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Navegación de TaxoCommerce Core', 'taxocommerce-core' ); ?>">
				<a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core' ) ); ?>"><?php esc_html_e( 'Overview', 'taxocommerce-core' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TaxoCommerce_Core_User_Guide::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'User Guide', 'taxocommerce-core' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core&tab=auto-test' ) ); ?>"><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></a>
			</nav>

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
								<a class="tcc-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $module['slug'] ) ); ?>" aria-label="<?php
                                    /* translators: %s: title of the module displayed on the dashboard. */
                                    echo esc_attr( sprintf( __( 'Abrir %s', 'taxocommerce-core' ), $module['title'] ) );
                                ?>"><?php esc_html_e( 'Abrir', 'taxocommerce-core' ); ?> <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>

			<?php if ( is_callable( $extensions_renderer ) ) : ?>
				<?php call_user_func( $extensions_renderer ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
