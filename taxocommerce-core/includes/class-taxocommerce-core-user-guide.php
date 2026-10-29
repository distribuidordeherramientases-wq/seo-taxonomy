<?php
/**
 * Read-only user and technical guides for TaxoCommerce Core.
 *
 * @package TaxoCommerceCore
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_User_Guide {
	const PAGE_SLUG = 'taxocommerce-core-user-guide';

	private static function link( $slug, $label, $params = array() ) {
		$args = array_merge( array( 'page' => $slug ), $params );
		return '<a href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function render() {
		if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'No tienes permisos para consultar este manual.', 'taxocommerce-core' ) );
		}
		// Read-only navigation; no nonce is necessary for switching documents.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manual selection.
		$manual = isset( $_GET['manual'] ) && is_string( $_GET['manual'] ) ? sanitize_key( wp_unslash( $_GET['manual'] ) ) : 'user';
		$manual = 'technical' === $manual ? 'technical' : 'user';
		$base_url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap tcc-family-screen tcc-user-guide">
			<header class="tcc-page-header">
				<span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-book"></span></span>
				<div class="tcc-page-header__content">
					<p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
					<h1><?php esc_html_e( 'User Guide', 'taxocommerce-core' ); ?></h1>
					<p><?php esc_html_e( 'Documentación de Core: manual de usuario y manual técnico, en una misma sección.', 'taxocommerce-core' ); ?></p>
				</div>
			</header>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Navegación de TaxoCommerce Core', 'taxocommerce-core' ); ?>">
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core' ) ); ?>"><?php esc_html_e( 'Overview', 'taxocommerce-core' ); ?></a>
				<a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'User Guide', 'taxocommerce-core' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( add_query_arg( array( 'page' => 'taxocommerce-core', 'tab' => 'auto-test' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></a>
			</nav>
			<nav class="tcc-guide-manual-tabs" aria-label="<?php esc_attr_e( 'Tipo de manual', 'taxocommerce-core' ); ?>">
				<a class="tcc-guide-manual-tab<?php echo 'user' === $manual ? ' is-active' : ''; ?>" <?php echo 'user' === $manual ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'User Manual', 'taxocommerce-core' ); ?></a>
				<a class="tcc-guide-manual-tab<?php echo 'technical' === $manual ? ' is-active' : ''; ?>" <?php echo 'technical' === $manual ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'manual', 'technical', $base_url ) ); ?>"><?php esc_html_e( 'Technical Manual', 'taxocommerce-core' ); ?></a>
			</nav>
			<?php if ( 'technical' === $manual ) : ?>
				<?php TaxoCommerce_Core_Technical_Guide::render(); ?>
			<?php else : ?>
			<div class="tcc-guide-layout">
				<nav class="tcc-guide-index" aria-label="<?php esc_attr_e( 'Índice del manual', 'taxocommerce-core' ); ?>">
					<strong><?php esc_html_e( 'En esta guía', 'taxocommerce-core' ); ?></strong>
					<ol>
						<li><a href="#tc-guide-intro"><?php esc_html_e( '¿Para qué sirve?', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-start"><?php esc_html_e( 'Primeros pasos', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-content"><?php esc_html_e( 'Contenidos', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-hierarchy"><?php esc_html_e( 'Jerarquía SEO', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-menus"><?php esc_html_e( 'Gestor de menús', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-test"><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-extensions"><?php esc_html_e( 'Extensiones', 'taxocommerce-core' ); ?></a></li>
						<li><a href="#tc-guide-help"><?php esc_html_e( 'Preguntas habituales', 'taxocommerce-core' ); ?></a></li>
					</ol>
				</nav>
				<main class="tcc-guide-article">
					<section id="tc-guide-intro">
						<h2><?php esc_html_e( '1. ¿Para qué sirve TaxoCommerce Core?', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'TaxoCommerce Core organiza los contenidos, la estructura SEO y los menús de una tienda WordPress con WooCommerce. También muestra los módulos de la familia TaxoCommerce que puedes gestionar por separado.', 'taxocommerce-core' ); ?></p>
						<p><?php esc_html_e( 'Trabaja sobre los productos, páginas, entradas y categorías reales de WordPress y WooCommerce: no crea una segunda copia independiente de tu catálogo.', 'taxocommerce-core' ); ?></p>
					</section>
					<section id="tc-guide-start">
						<h2><?php esc_html_e( '2. Primeros pasos', 'taxocommerce-core' ); ?></h2>
						<ol>
							<li><?php echo wp_kses_post( sprintf( /* translators: %s: link to the Core dashboard. */ __( 'Abre %s para ver el estado general y los accesos a cada módulo.', 'taxocommerce-core' ), self::link( 'taxocommerce-core', 'TaxoCommerce Core' ) ) ); ?></li>
							<li><?php esc_html_e( 'Entra en Content y revisa los contenidos y sus países antes de hacer cambios.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Si vas a organizar la navegación por temas, configura primero SEO Hierarchy y después Menu Manager.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Ejecuta Auto Test para comprobar el funcionamiento básico sin cambiar contenidos.', 'taxocommerce-core' ); ?></li>
						</ol>
					</section>
					<section id="tc-guide-content">
						<h2><?php esc_html_e( '3. Content: gestionar contenidos', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'Desde Content puedes consultar y editar productos, categorías de producto, entradas y páginas. La vista de resumen presenta el inventario; Anomalías muestra problemas detectados en los contenidos.', 'taxocommerce-core' ); ?></p>
						<h3><?php esc_html_e( 'Cómo editar un contenido', 'taxocommerce-core' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'Selecciona Productos, Categorías de producto, Posts o Páginas.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Localiza el elemento y abre su editor.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Revisa el texto, el estado y el país del contenido; guarda únicamente los cambios deseados.', 'taxocommerce-core' ); ?></li>
						</ol>
						<h3><?php esc_html_e( 'Países e internacionalización', 'taxocommerce-core' ); ?></h3>
						<p><?php esc_html_e( 'El selector de país distingue ES, US, FR, DE y NL. Elegir un país identifica el mercado del contenido, pero no traduce textos ni cambia automáticamente su URL o sus relaciones SEO. Comprueba el país antes de guardar contenidos internacionales.', 'taxocommerce-core' ); ?></p>
						<p><?php echo wp_kses_post( self::link( 'taxocommerce-core-content', __( 'Abrir Content', 'taxocommerce-core' ) ) ); ?></p>
					</section>
					<section id="tc-guide-hierarchy">
						<h2><?php esc_html_e( '4. SEO Hierarchy: organizar la arquitectura', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'La jerarquía relaciona las páginas que presentan grandes áreas temáticas con las categorías y los productos de la tienda.', 'taxocommerce-core' ); ?></p>
						<div class="tcc-guide-chain" role="img" aria-label="<?php esc_attr_e( 'Cluster, Hub primario, Hub secundario, Categoría, Producto', 'taxocommerce-core' ); ?>"><?php esc_html_e( 'Cluster → Hub primario → Hub secundario → Categoría → Producto', 'taxocommerce-core' ); ?></div>
						<h3><?php esc_html_e( 'Cómo utilizarla', 'taxocommerce-core' ); ?></h3>
						<p><?php esc_html_e( 'Selecciona el país, asigna un papel SEO a las páginas y revisa las relaciones entre niveles. Guarda los cambios de forma controlada. Un contenido no debe relacionarse con otro de un país diferente.', 'taxocommerce-core' ); ?></p>
						<p><?php echo wp_kses_post( self::link( 'taxocommerce-core-hierarchy', __( 'Abrir SEO Hierarchy', 'taxocommerce-core' ) ) ); ?></p>
					</section>
					<section id="tc-guide-menus">
						<h2><?php esc_html_e( '5. Menu Manager: construir la navegación', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'Menu Manager permite crear un menú de WordPress a partir de la jerarquía SEO y añadir páginas, entradas, categorías y enlaces personalizados manualmente.', 'taxocommerce-core' ); ?></p>
						<h3><?php esc_html_e( 'Crear y publicar un menú', 'taxocommerce-core' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'Selecciona el país del menú.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Revisa la propuesta y pulsa Crear menú desde la jerarquía o Sincronizar propuesta cuando proceda.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Añade los elementos manuales que necesites. Se conservan al sincronizar.', 'taxocommerce-core' ); ?></li>
							<li><?php esc_html_e( 'Selecciona una ubicación del tema y pulsa Activar en esta ubicación si deseas mostrarlo en la web.', 'taxocommerce-core' ); ?></li>
						</ol>
						<p><?php esc_html_e( 'Abrir esta pantalla no crea ni publica un menú. Los elementos generados automáticamente se organizan desde SEO Hierarchy. Puedes restaurar las ubicaciones anteriores si se ha guardado una copia válida.', 'taxocommerce-core' ); ?></p>
						<p><?php echo wp_kses_post( self::link( 'taxocommerce-core-menu', __( 'Abrir Menu Manager', 'taxocommerce-core' ) ) ); ?></p>
					</section>
					<section id="tc-guide-test">
						<h2><?php esc_html_e( '6. Auto Test: comprobar el sistema', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'Auto Test ejecuta comprobaciones bajo demanda de módulos, lectura de contenidos, países, jerarquía y menús. Presenta resultados correctos, fallidos o no ejecutados, con información para localizar incidencias.', 'taxocommerce-core' ); ?></p>
						<p><?php esc_html_e( 'Las pruebas automáticas son de lectura: no sustituyen una prueba real de edición, publicación o sincronización en un entorno de pruebas.', 'taxocommerce-core' ); ?></p>
						<p><?php echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'taxocommerce-core', 'tab' => 'auto-test' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Abrir Auto Test', 'taxocommerce-core' ) . '</a>'; ?></p>
					</section>
					<section id="tc-guide-extensions">
						<h2><?php esc_html_e( '7. Extensions: ampliar TaxoCommerce', 'taxocommerce-core' ); ?></h2>
						<p><?php esc_html_e( 'En la parte inferior de la portada aparecen las extensiones independientes con su estado, versión y controles disponibles según tus permisos. Por ejemplo, Images, Suppliers, Database, Vocabulary o System Check se instalan y gestionan como plugins separados.', 'taxocommerce-core' ); ?></p>
						<p><?php esc_html_e( 'Una extensión no instalada no añade automáticamente sus funciones a Core. Antes de instalar o activar un ZIP, comprueba que corresponda al plugin indicado y que sea compatible con tu instalación.', 'taxocommerce-core' ); ?></p>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core#tcc-extensions' ) ); ?>"><?php esc_html_e( 'Ver Extensions', 'taxocommerce-core' ); ?></a></p>
					</section>
					<section id="tc-guide-help">
						<h2><?php esc_html_e( '8. Preguntas habituales', 'taxocommerce-core' ); ?></h2>
						<h3><?php esc_html_e( '¿Core cambia las fichas automáticamente?', 'taxocommerce-core' ); ?></h3>
						<p><?php esc_html_e( 'No. El manual y las recomendaciones editoriales no modifican fichas. El editor guarda únicamente cuando realizas la acción correspondiente.', 'taxocommerce-core' ); ?></p>
						<h3><?php esc_html_e( '¿Necesito todos los plugins TaxoCommerce?', 'taxocommerce-core' ); ?></h3>
						<p><?php esc_html_e( 'No. Core funciona por separado y cada extensión aporta funciones adicionales cuando está instalada y activa.', 'taxocommerce-core' ); ?></p>
						<h3><?php esc_html_e( '¿Por qué no veo una función mencionada en otra extensión?', 'taxocommerce-core' ); ?></h3>
						<p><?php esc_html_e( 'Puede pertenecer a otro plugin, estar desactivada o requerir permisos específicos. Revisa el estado de la extensión en la portada de Core.', 'taxocommerce-core' ); ?></p>
					</section>
				</main>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
