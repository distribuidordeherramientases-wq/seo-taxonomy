<?php
/**
 * Read-only technical documentation for TaxoCommerce Core.
 *
 * This screen documents existing code and never performs maintenance tasks.
 *
 * @package TaxoCommerceCore
 */
defined( 'ABSPATH' ) || exit;

/** Technical manual for administrators and plugin maintainers. */
final class TaxoCommerce_Core_Technical_Guide {

	public static function render() {
		if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'No tienes permisos para consultar este manual.', 'taxocommerce-core' ) );
		}

		$module_example = <<<'PHP'
add_action( 'taxocommerce_core_register_modules', function ( $core ) {
    $core->register_module(
        'mi_extension',
        array(
            'name'        => 'Mi extensión',
            'version'     => '1.0.0',
            'status'      => 'active',
            'description' => 'Funciones de la extensión.',
            'admin_url'   => admin_url( 'admin.php?page=mi-extension' ),
        )
    );
} );
PHP;
		?>
		<div class="tcc-guide-layout tcc-technical-guide">
			<nav class="tcc-guide-index" aria-label="<?php esc_attr_e( 'Índice del manual técnico', 'taxocommerce-core' ); ?>">
				<strong><?php esc_html_e( 'Manual técnico', 'taxocommerce-core' ); ?></strong>
				<ol>
					<li><a href="#tc-tech-overview"><?php esc_html_e( 'Alcance y requisitos', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-architecture"><?php esc_html_e( 'Arquitectura y arranque', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-data"><?php esc_html_e( 'Datos y tablas', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-countries"><?php esc_html_e( 'Países', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-routes"><?php esc_html_e( 'Pantallas y operaciones', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-modules"><?php esc_html_e( 'API de extensiones', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-security"><?php esc_html_e( 'Seguridad', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-cache"><?php esc_html_e( 'Caché y diagnósticos', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-deployment"><?php esc_html_e( 'Pruebas y despliegue', 'taxocommerce-core' ); ?></a></li>
					<li><a href="#tc-tech-support"><?php esc_html_e( 'Mantenimiento', 'taxocommerce-core' ); ?></a></li>
				</ol>
			</nav>

			<main class="tcc-guide-article">
				<section id="tc-tech-overview">
					<h2><?php esc_html_e( '1. Alcance y requisitos', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'TaxoCommerce Core coordina la edición de contenidos, los roles y las relaciones SEO, los menús nativos y la gestión de extensiones. Los módulos independientes, como Database, Images o Suppliers, conservan su propia lógica y su ciclo de vida.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Compatibilidad declarada', 'taxocommerce-core' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'WordPress 6.5 o posterior; PHP 7.4 o posterior, según la cabecera del plugin.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'WooCommerce es necesario para las funciones relativas a productos y categorías de producto, pero no para arrancar el núcleo de Core.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'Los datos editoriales siguen en las estructuras nativas de WordPress y WooCommerce; Core añade metadatos y relaciones SEO.', 'taxocommerce-core' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Versión de Core cargada:', 'taxocommerce-core' ); ?> <strong><?php echo esc_html( TAXOCOMMERCE_CORE_VERSION ); ?></strong></p>
				</section>

				<section id="tc-tech-architecture">
					<h2><?php esc_html_e( '2. Arquitectura y arranque', 'taxocommerce-core' ); ?></h2>
					<h3><?php esc_html_e( 'Componentes principales', 'taxocommerce-core' ); ?></h3>
					<div class="tcc-guide-table-scroll">
						<table class="tcc-guide-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Ruta', 'taxocommerce-core' ); ?></th><th scope="col"><?php esc_html_e( 'Responsabilidad', 'taxocommerce-core' ); ?></th></tr></thead>
							<tbody>
								<tr><th scope="row"><code>taxocommerce-core.php</code></th><td><?php esc_html_e( 'Cabecera, constantes, carga de clases y activación.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core.php</code></th><td><?php esc_html_e( 'Coordinador, permisos, rutas administrativas y catálogo de extensiones.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core-content-manager.php</code></th><td><?php esc_html_e( 'Pantalla y guardado de contenidos canónicos.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core-hierarchy*.php</code></th><td><?php esc_html_e( 'Roles SEO, edición de relaciones y esquema SQL.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core-menu*.php</code></th><td><?php esc_html_e( 'Menús de WordPress y sincronización por país.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core-country.php</code></th><td><?php esc_html_e( 'Normalización y lectura del país de cada contenido.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>includes/class-taxocommerce-core-auto-test.php</code></th><td><?php esc_html_e( 'Comprobaciones funcionales no destructivas.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>assets/</code> y <code>tests/</code></th><td><?php esc_html_e( 'Estilos y scripts administrativos; pruebas de regresión.', 'taxocommerce-core' ); ?></td></tr>
							</tbody>
						</table>
					</div>
					<h3><?php esc_html_e( 'Ciclo de inicialización', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'La función taxocommerce_core_boot() se ejecuta en plugins_loaded, prioridad 20. Inicializa el singleton TaxoCommerce_Core, registra servicios y hooks y notifica a las extensiones. Las páginas administrativas se registran en admin_menu.', 'taxocommerce-core' ); ?></p>
					<p><?php esc_html_e( 'Al activar Core se preparan las tablas SEO mediante dbDelta y se guarda la versión. En administración, el gestor de jerarquía comprueba los esquemas existentes y añade el campo de país cuando corresponde, conservando los registros.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-data">
					<h2><?php esc_html_e( '3. Persistencia y modelo de datos', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'El prefijo SQL es el configurado por WordPress mediante $wpdb->prefix; no debe suponerse que es wp_.', 'taxocommerce-core' ); ?></p>
					<div class="tcc-guide-table-scroll">
						<table class="tcc-guide-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Estructura', 'taxocommerce-core' ); ?></th><th scope="col"><?php esc_html_e( 'Uso', 'taxocommerce-core' ); ?></th></tr></thead>
							<tbody>
								<tr><th scope="row"><code>{prefix}seo_nodes</code></th><td><?php esc_html_e( 'Rol SEO de cada objeto: object_type, object_id, seo_role, status y country_code; incluye fechas y campos editoriales opcionales.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>{prefix}seo_relations</code></th><td><?php esc_html_e( 'Enlaces entre nodos: source_type/id, target_type/id, relation_type, country_code y fecha.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>posts / postmeta</code></th><td><?php esc_html_e( 'Páginas, entradas y productos WooCommerce; metadatos editoriales y de país.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>terms / termmeta</code></th><td><?php esc_html_e( 'Categorías de producto (product_cat) y su país.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>nav_menu_item</code></th><td><?php esc_html_e( 'Elementos nativos de menú; Core identifica los generados automáticamente mediante metadatos.', 'taxocommerce-core' ); ?></td></tr>
								<tr><th scope="row"><code>options / transients</code></th><td><?php esc_html_e( 'Versión, ajustes de menú, logs acotados y cachés de informes.', 'taxocommerce-core' ); ?></td></tr>
							</tbody>
						</table>
					</div>
					<h3><?php esc_html_e( 'Relaciones estructurales', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Los tipos de relación incluyen cluster_to_primary, hub_primary_to_hub_secondary, hub_secondary_to_category y los enlaces desde hubs secundarios hacia landings y entradas. La pertenencia de productos a categorías sigue siendo una relación propia de WooCommerce.', 'taxocommerce-core' ); ?></p>
					<p><?php esc_html_e( 'La actualización de una relación valida el rol, la existencia de los extremos y la pertenencia al mismo mercado. Las escrituras de relaciones estructurales se agrupan mediante transacciones SQL; no deben modificarse directamente sin conocer estas reglas.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-countries">
					<h2><?php esc_html_e( '4. Gestión técnica de países', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'Los países reconocidos por Core son ES, US, FR, DE y NL. El dato canónico escrito en postmeta o termmeta utiliza la clave _seo_pais_origen; las relaciones y los nodos SEO pueden guardar además country_code.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Lectura y compatibilidad', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'En páginas, entradas y productos, la lectura intenta primero _seo_pais_origen, después dht_pilot_market, _seo_locale y determinados slugs internacionales. Para categorías se comprueba también la cadena de ascendientes. Como compatibilidad con contenidos antiguos, el lector puede devolver ES por defecto.', 'taxocommerce-core' ); ?></p>
					<p><strong><?php esc_html_e( 'Importante:', 'taxocommerce-core' ); ?></strong> <?php esc_html_e( 'que el lector devuelva ES no demuestra que el metadato de país esté guardado explícitamente. Para auditar asignaciones reales hay que comprobar los metadatos almacenados, no solo el valor calculado por Core.', 'taxocommerce-core' ); ?></p>
					<p><?php esc_html_e( 'No deben crearse relaciones jerárquicas entre países diferentes. Cambiar el país de un contenido no lo traduce ni transforma sus URLs de forma automática.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-routes">
					<h2><?php esc_html_e( '5. Rutas administrativas y operaciones', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'Core usa las páginas nativas de WordPress registradas mediante add_menu_page y add_submenu_page. La pestaña de documentación se sirve en admin.php?page=taxocommerce-core-user-guide; las demás pantallas tienen sus propios slugs.', 'taxocommerce-core' ); ?></p>
					<div class="tcc-guide-table-scroll">
						<table class="tcc-guide-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Pantalla', 'taxocommerce-core' ); ?></th><th scope="col"><?php esc_html_e( 'Slug', 'taxocommerce-core' ); ?></th></tr></thead>
							<tbody>
								<tr><th scope="row"><?php esc_html_e( 'Inicio', 'taxocommerce-core' ); ?></th><td><code>taxocommerce-core</code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Content', 'taxocommerce-core' ); ?></th><td><code>taxocommerce-core-content</code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'SEO Hierarchy', 'taxocommerce-core' ); ?></th><td><code>taxocommerce-core-hierarchy</code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Menu Manager', 'taxocommerce-core' ); ?></th><td><code>taxocommerce-core-menu</code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></th><td><code>admin.php?page=taxocommerce-core&amp;tab=auto-test</code></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'User Guide', 'taxocommerce-core' ); ?></th><td><code>taxocommerce-core-user-guide</code></td></tr>
							</tbody>
						</table>
					</div>
					<h3><?php esc_html_e( 'Escrituras y consultas', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Las operaciones que modifican contenido, relaciones, menús o extensiones usan acciones autenticadas admin_post_*. Los buscadores internos pueden emplear wp_ajax_*. La documentación, los inventarios y los informes se consultan desde pantallas de administración.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-modules">
					<h2><?php esc_html_e( '6. API y registro de extensiones', 'taxocommerce-core' ); ?></h2>
					<p><?php esc_html_e( 'Core ofrece taxocommerce_core() como acceso al coordinador. Los plugins activos pueden registrarse en el hook taxocommerce_core_register_modules; al completar el arranque se emite taxocommerce_core_loaded.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Ejemplo de integración', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Ejemplo para el archivo principal de una extensión independiente que se carga antes del arranque de Core:', 'taxocommerce-core' ); ?></p>
					<pre class="tcc-guide-code"><code><?php echo esc_html( $module_example ); ?></code></pre>
					<p><?php esc_html_e( 'register_module() acepta un identificador y metadatos como name, version, status, description y admin_url. El registro no instala la extensión ni registra automáticamente su pantalla: cada plugin sigue siendo responsable de sus propios hooks y permisos.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Gestión de paquetes', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'El catálogo interno permite identificar extensiones instaladas, activas o disponibles. Para instalar un paquete conocido puede usarse un ZIP validado y alojado en uploads/taxocommerce-core/plugins o un ZIP cargado por un administrador autorizado. WordPress ejecuta la instalación; Core no fusiona el código de la extensión con el suyo.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-security">
					<h2><?php esc_html_e( '7. Permisos y seguridad', 'taxocommerce-core' ); ?></h2>
					<h3><?php esc_html_e( 'Capacidades', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Las pantallas de Core requieren por defecto manage_options, configurable mediante el filtro taxocommerce_core_manage_capability. Las operaciones de instalación exigen adicionalmente install_plugins y las de activación o desactivación, activate_plugins.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Protección de operaciones', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Las acciones de modificación validan permisos y nonces de WordPress. Las entradas se normalizan y validan; las salidas se escapan y las redirecciones administrativas usan wp_safe_redirect. El manual es de solo lectura y no incluye formularios de mantenimiento.', 'taxocommerce-core' ); ?></p>
					<p><?php esc_html_e( 'Nunca insertes contraseñas, tokens, claves de servicio ni secretos de despliegue en este manual o en los mensajes del registro técnico.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-cache">
					<h2><?php esc_html_e( '8. Caché, logs y diagnóstico', 'taxocommerce-core' ); ?></h2>
					<h3><?php esc_html_e( 'Informes con caché', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'El inventario de contenidos usa el transient taxocommerce_core_content_inventory_v1 y el informe de arquitectura, taxocommerce_core_architecture_report_v1. Ambos tienen una duración de 15 minutos y un botón de actualización protegido.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Auto Test', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Se ejecuta bajo demanda y comprueba lecturas, países, roles, relaciones, menús y módulos. Guarda el último resultado por usuario durante un día en un transient; no publica ni modifica contenidos reales. Una prueba omitida no equivale a una prueba correcta.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Registro técnico', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'TaxoCommerce_Core_Logger guarda entradas de nivel info, warning y error en la opción taxocommerce_core_log_entries. El límite configurable se restringe entre 20 y 500 entradas para evitar un crecimiento indefinido.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-deployment">
					<h2><?php esc_html_e( '9. Validación, versiones y despliegue', 'taxocommerce-core' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Trabaja sobre una rama de Git y modifica solo los archivos del plugin afectados. Ajusta la versión declarada en la cabecera y en TAXOCOMMERCE_CORE_VERSION.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'Ejecuta las comprobaciones de sintaxis PHP y las pruebas de regresión situadas en .github/tests/taxocommerce-core/.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'Crea una pull request hacia staging y revisa sus comprobaciones antes de fusionar.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'El workflow .github/workflows/deploy-taxocommerce-core-staging.yml valida Core y realiza el despliegue FTPS a staging cuando se cumplen sus condiciones y están disponibles sus credenciales.', 'taxocommerce-core' ); ?></li>
						<li><?php esc_html_e( 'Comprueba el resultado en WordPress staging antes de planificar una subida independiente a producción. Fusionar una rama no confirma por sí solo el despliegue.', 'taxocommerce-core' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Entre las pruebas existentes están user-guide-smoke.php, extensions-dashboard-smoke.php, architecture-report-smoke.php, content-inventory-smoke.php, menu-editor-smoke.php y auto-test-smoke.php. No sustituyen una revisión funcional de los cambios de escritura.', 'taxocommerce-core' ); ?></p>
				</section>

				<section id="tc-tech-support">
					<h2><?php esc_html_e( '10. Mantenimiento y resolución de incidencias', 'taxocommerce-core' ); ?></h2>
					<h3><?php esc_html_e( 'El menú de un país no aparece', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Revisa el país elegido, los nodos y relaciones SEO, el menú nativo correspondiente y su asignación a una ubicación del tema. Crear una propuesta no equivale a activarla.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Los recuentos parecen antiguos', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Comprueba la fecha del inventario y actualízalo desde la propia pantalla; las vistas resumidas pueden conservar datos en caché durante 15 minutos.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Falla un test o falta una extensión', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'Consulta el detalle de Auto Test y distingue fallos de pruebas omitidas. Para extensiones, verifica que el plugin independiente esté instalado y activo y que el usuario tenga los permisos necesarios.', 'taxocommerce-core' ); ?></p>
					<h3><?php esc_html_e( 'Desinstalación y conservación de datos', 'taxocommerce-core' ); ?></h3>
					<p><?php esc_html_e( 'uninstall.php elimina únicamente algunas opciones propias de Core. No borra el contenido canónico de WordPress o WooCommerce ni elimina por defecto las tablas seo_nodes y seo_relations. Haz copias de seguridad verificadas antes de migraciones o mantenimientos estructurales.', 'taxocommerce-core' ); ?></p>
					<p><?php esc_html_e( 'Este manual describe el código de Core; las operaciones internas de cada plugin adicional se documentan en su propio módulo.', 'taxocommerce-core' ); ?></p>
				</section>
			</main>
		</div>
		<?php
	}
}
