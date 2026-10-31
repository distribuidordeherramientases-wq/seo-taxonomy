<?php
/**
 * Manuals for the standalone TaxoCommerce Campaigns module.
 *
 * Keep this documentation aligned with the real public interface and hooks.
 * Rendering the manual must never start migrations, campaigns or social jobs.
 *
 * @package TaxoCommerceCampaigns
 */
defined( 'ABSPATH' ) || exit;

/**
 * Render the independent user and developer manuals.
 *
 * @return void
 */
function tccampaigns_render_manual() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <section class="tcc-card tcc-manual">
        <h2 id="tcc-manual-start">Manual de TaxoCommerce Campaigns</h2>
        <p>Versión <?php echo esc_html( TAXOCOMMERCE_CAMPAIGNS_VERSION ); ?>. Documentación del <strong>plugin independiente</strong> de campañas comerciales, no del antiguo plugin monolítico.</p>
        <p><strong>Índice:</strong>
            <a href="#tcc-manual-user">Manual de usuario</a> ·
            <a href="#tcc-manual-tech">Manual técnico para desarrolladores</a> ·
            <a href="#tcc-manual-help">Diagnóstico y mantenimiento</a>
        </p>
        <p class="description">Esta página es únicamente informativa: consultarla no crea campañas, no modifica precios, no ejecuta tareas programadas y no publica ofertas.</p>

        <h2 id="tcc-manual-user">Manual de usuario</h2>
        <h3 id="tcc-user-access">1. Acceso y organización</h3>
        <p>En WordPress abre <strong>TaxoCommerce &gt; Campaigns</strong> si está activo Core; sin Core, busca <strong>TaxoCommerce Campaigns</strong> en el menú principal. Se necesita el permiso de administrador <code>manage_options</code>. El módulo contiene las pestañas
            <a href="<?php echo esc_url( tccampaigns_admin_tab_url( 'campaigns' ) ); ?>">Campañas</a>,
            <a href="<?php echo esc_url( tccampaigns_admin_tab_url( 'calendar' ) ); ?>">Calendario</a>,
            <a href="<?php echo esc_url( tccampaigns_admin_tab_url( 'banners' ) ); ?>">Banners / Publicación</a>,
            <a href="<?php echo esc_url( tccampaigns_admin_tab_url( 'autotest' ) ); ?>">Autotest</a> y Manual.
        </p>
        <p>La pantalla Campañas agrupa las campañas en <strong>En curso, Próximas, Finalizadas y Deshabilitadas</strong>. Desde cada tarjeta puedes abrir su edición. El botón <strong>Nueva campaña</strong> inicia el alta y la zona inferior permite importar o exportar.</p>

        <h3 id="tcc-user-create">2. Crear una campaña</h3>
        <ol>
            <li>En <strong>Campañas</strong>, pulsa <strong>Nueva campaña</strong>.</li>
            <li>Indica nombre y edición. Revisa tipo comercial (<strong>Calendario, Táctica / puntual o Producto estrella</strong>), fechas de inicio y fin, recurrencia y plantilla creativa.</li>
            <li>Decide si debe quedar habilitada. El final debe ser posterior al inicio; comprueba la zona horaria configurada en WordPress.</li>
            <li>Guarda la campaña y después incorpora sus productos. Revisa que el precio de campaña y el orden de cada producto sean correctos.</li>
            <li>Verifica la tarjeta de la campaña y su periodo en Calendario antes de poner en marcha una promoción real.</li>
        </ol>
        <p>Los valores de recurrencia que conserva el módulo son <code>none</code>, <code>annual</code> y <code>manual</code>. La recurrencia es un dato del registro comercial: <strong>no presupongas que creará automáticamente una edición nueva</strong> sin comprobar el comportamiento programado.</p>

        <h3 id="tcc-user-products">3. Productos, ofertas y conflictos</h3>
        <p>Desde la edición de una campaña puedes buscar y añadir productos WooCommerce, cambiar el precio promocional y su posición, o quitar asociaciones. Antes de habilitar campañas simultáneas, revisa los avisos de <strong>solapamiento</strong>: el mismo producto no debe recibir dos campañas activas cuyas fechas coincidan.</p>
        <p>Al comenzar una campaña, el motor guarda una instantánea de las condiciones de oferta previas de cada producto y aplica el precio y las fechas promocionales; al finalizar, restaura esos datos. No edites manualmente la misma oferta desde otro plugin mientras una campaña la controle. Antes de vaciar o eliminar una campaña activa, verifica que las ofertas aplicadas se han restaurado.</p>

        <h3 id="tcc-user-import">4. Importación y exportación</h3>
        <p>En <strong>Importar / Exportar campañas</strong>, el formato de intercambio recomendado es <strong>JSON</strong>, que contiene el registro completo. <strong>CSV</strong> permite editar el archivo en una hoja de cálculo y diferencia las filas <code>campaign</code> y <code>product</code>. Los CSV antiguos de calendario tienen compatibilidad de importación.</p>
        <p>Para importar, elige un archivo <strong>.json</strong> o <strong>.csv</strong> (límite del importador: <strong>2 MB</strong>) y pulsa <strong>Importar / actualizar</strong>. La actualización identifica las campañas mediante <code>campaign_key</code>. Sin marcar <strong>Sustituir completamente los productos</strong>, se fusionan y actualizan las filas recibidas sin eliminar las ausentes. Si marcas la casilla, la sustitución afecta a los productos de las campañas con filas <code>product</code> en el archivo. Exporta primero una copia y ensaya la operación en STAGING.</p>

        <h3 id="tcc-user-calendar">5. Calendario comercial</h3>
        <p>La pestaña <strong>Calendario</strong> permite navegar por meses y consultar los periodos de las campañas, su estado y el número de productos. Cuando TaxoCommerce Social está disponible, se muestran también las publicaciones comerciales planificadas. Este calendario <strong>no sustituye</strong> el calendario editorial de posts y noticias del plugin Social.</p>

        <h3 id="tcc-user-banners">6. Banners y ofertas públicas</h3>
        <p>La pestaña <strong>Banners / Publicación</strong> describe la integración opcional con TaxoCommerce Templates (plantilla <code>template-campaign.php</code>). También puedes insertar campañas en el contenido mediante <code>[taxocommerce_campaign]</code>, <code>[taxocommerce_campaign key="mi-campana"]</code> o <code>[oferta]</code>. El shortcode admite los atributos <code>id</code>, <code>key</code> y <code>limit</code> (máximo de 24 productos por campaña). No hay shortcodes dinámicos del tipo <code>[oferta01]</code>.</p>
        <p>El bloque público presenta campañas habilitadas dentro de sus fechas y productos visibles y disponibles. No crea entradas ni descarga automáticamente las imágenes externas a Medios.</p>

        <h3 id="tcc-user-social">7. Publicación comercial en redes</h3>
        <p>En <strong>Banners / Publicación</strong> puedes guardar la hora comercial y las plantillas de texto para cada red. Están disponibles variables como <code>{producto}</code>, <code>{campana}</code>, <code>{precio_regular}</code>, <code>{precio_oferta}</code>, <code>{descuento_pct}</code>, <code>{fecha_inicio}</code>, <code>{fecha_fin}</code>, <code>{url}</code>, <code>{categorias}</code> e <code>{imagen}</code>.</p>
        <p>La gestión comercial de campañas pertenece a Campaigns; las conexiones y el envío a cada red dependen del plugin opcional <strong>TaxoCommerce Social</strong>. Comprueba la conexión del proveedor antes de programar publicaciones; guardar una plantilla no implica publicar automáticamente.</p>

        <h3 id="tcc-user-autotest">8. Autotest: qué comprueba</h3>
        <p>Abre <a href="<?php echo esc_url( tccampaigns_admin_tab_url( 'autotest' ) ); ?>">Autotest</a> y pulsa <strong>Ejecutar comprobación</strong>. Los grupos comprueban carga de funciones, existencia de tablas, versión del esquema, WooCommerce, disponibilidad del programador e integraciones opcionales. Los resultados se muestran como <strong>Correcto</strong>, <strong>Aviso</strong> o <strong>Error</strong>. Un aviso de Social o Templates no significa que el motor comercial esté averiado.</p>
        <p>Autotest <strong>no aplica ni restaura precios, no ejecuta tareas pendientes, no consulta servicios externos y no prueba una venta real</strong>. Para validar el ciclo comercial completo utiliza campañas y productos de prueba en STAGING.</p>
    </section>

    <section class="tcc-card tcc-manual">
        <h2 id="tcc-manual-tech">Manual técnico para desarrolladores</h2>
        <p>Referencia de arquitectura, persistencia e integraciones existentes. Antes de añadir código, revisa estos contratos y evita duplicar funcionalidades del monolito o del resto de plugins TaxoCommerce.</p>

        <h3 id="tcc-tech-requirements">9. Requisitos y límites del módulo</h3>
        <p>El archivo principal es <code>taxocommerce-campaigns/taxocommerce-campaigns.php</code>. Requiere <strong>WordPress 6.7 o superior</strong> y <strong>PHP 7.4 o superior</strong> según la cabecera del plugin; depende funcionalmente de WooCommerce para los productos y precios promocionales. <strong>TaxoCommerce Core es opcional</strong> para el menú y el registro de extensiones; <strong>Social</strong> aporta publicación externa y <strong>Templates</strong> las vistas públicas específicas.</p>

        <h3 id="tcc-tech-files">10. Mapa de archivos y responsabilidades</h3>
        <ul>
            <li><code>taxocommerce-campaigns.php</code>: cabecera, constantes, carga de módulos, registro en Core y compatibilidad con funciones anteriores.</li>
            <li><code>includes/campaigns.php</code>: modelo, tablas, reglas de campaña, acciones administrativas, precios, importación/exportación y renderizado del gestor.</li>
            <li><code>includes/calendar.php</code>: lectura y presentación del calendario comercial mensual.</li>
            <li><code>includes/social-publication.php</code>: plantillas, planificación y ejecución de publicaciones comerciales a través de los proveedores disponibles.</li>
            <li><code>includes/campaign-images.php</code>: resolución y composición de imágenes de las creatividades de campaña.</li>
            <li><code>includes/shortcodes.php</code>: exposición pública de campañas por <code>[taxocommerce_campaign]</code> y <code>[oferta]</code>.</li>
            <li><code>includes/admin.php</code>: menú, rutas, pestañas y formulario de ajustes comerciales.</li>
            <li><code>includes/manual.php</code> y <code>includes/autotest.php</code>: documentación y diagnóstico de solo lectura.</li>
        </ul>
        <p>El menú se registra mediante <code>admin_menu</code> con capacidad <code>manage_options</code>. La ruta es <code>admin.php?page=taxocommerce-campaigns</code>; las pestañas usan el argumento <code>tab</code>. La familia se registra con <code>taxocommerce_core_register_modules</code> y <code>taxocommerce_core_family_extensions</code>.</p>

        <h3 id="tcc-tech-schema">11. Modelo de datos y migraciones</h3>
        <p><code>tccampaigns_tables()</code> devuelve los nombres reales usando <code>$wpdb-&gt;prefix</code>. Se reutilizan <code>{prefix}seo_marketing_campaigns</code> (cabecera comercial, clave y serie, tipo, edición, fechas, activación y recurrencia) y <code>{prefix}seo_marketing_campaign_products</code> (productos asociados, precio, posición, instantánea y estados de aplicación/restauración).</p>
        <p>La identidad portable es <code>campaign_key</code>; una misma campaña se relaciona con sus productos por <code>campaign_id</code>. La tabla de productos tiene una clave única para <code>(campaign_id, product_id)</code>. Los campos <code>snapshot_taken</code>, <code>original_sale_price</code>, <code>original_sale_from</code>, <code>original_sale_to</code>, <code>applied</code> y <code>restored_at</code> protegen la restauración. No cambies su significado ni reinicies instantáneas sin una migración y una prueba de recuperación.</p>
        <p>El número de esquema se define con <code>TCCAMPAIGNS_DB_VERSION</code> y se guarda en <code>seo_marketing_campaigns_db_version</code> (constante <code>TCCAMPAIGNS_DB_OPTION</code>). <code>tccampaigns_maybe_install_tables()</code> utiliza <code>dbDelta()</code> y rellena columnas de identidad antiguas. La activación también registra la plantilla pública si corresponde. <strong>No ejecutes estos procesos desde Manual o Autotest.</strong></p>
        <p>El registro opcional de la franja pública usa <code>{prefix}seo_templates</code> cuando existe y la clave <code>campaign_strip</code>. La opción <code>tccampaigns_template_registry_version</code> evita repetir el alta. Estas entidades y sus datos anteriores deben conservarse al separar el monolito.</p>

        <h3 id="tcc-tech-scheduler">12. Ciclo de vida y tareas programadas</h3>
        <p><code>tccampaigns_reschedule($campaign_id)</code> cancela la planificación anterior con <code>tccampaigns_unschedule()</code>, valida fechas y estado, aplica una promoción ya iniciada o crea un evento de inicio. Programa también la restauración después del fin, usando Action Scheduler cuando está disponible y WP-Cron como alternativa.</p>
        <p>Los hooks son <code>tccampaigns_apply_event</code> y <code>tccampaigns_restore_event</code>; el grupo de Action Scheduler es <code>taxocommerce-campaigns</code>. <code>tccampaigns_apply()</code> comprueba vigencia, guarda la situación anterior del producto y actualiza la oferta de WooCommerce. <code>tccampaigns_restore()</code> recorre las asociaciones con instantánea aplicada y delega en <code>tccampaigns_restore_product_row()</code>. Los productos padre variables no reciben directamente estas modificaciones de precio.</p>
        <p>En la transición desde el monolito, <code>tccampaigns_claim_schedule_when_legacy_is_off()</code> reasigna la programación solo cuando no existe el gestor legado; conserva el indicador <code>tccampaigns_schedule_owner_v1</code>. <strong>No actives dos motores que modifiquen las mismas campañas</strong>, y no fuerces la recuperación de trabajos en PRO sin pruebas previas.</p>

        <h3 id="tcc-tech-admin">13. Controladores administrativos y seguridad</h3>
        <p>El archivo <code>includes/campaigns.php</code> registra los handlers <code>admin_post_tccampaigns_save</code>, <code>admin_post_tccampaigns_add_products</code>, <code>admin_post_tccampaigns_update_products</code>, <code>admin_post_tccampaigns_clear_products</code>, <code>admin_post_tccampaigns_delete</code>, <code>admin_post_tccampaigns_duplicate</code>, <code>admin_post_tccampaigns_import</code> y <code>admin_post_tccampaigns_export</code>. <code>includes/admin.php</code> registra <code>admin_post_tccampaigns_settings</code>.</p>
        <p>Las operaciones comprueban permisos <code>manage_options</code> y usan nonces específicos. Mantén la normalización de fechas y precios, la sanitización de archivos y entradas y el escape de salidas HTML. Las consultas con datos variables deben prepararse mediante <code>$wpdb-&gt;prepare()</code>; revisa la compatibilidad de los identificadores SQL al mantener versiones de WordPress.</p>
        <p>El Autotest requiere un POST con nonce <code>tccampaigns_autotest</code> y solo realiza verificaciones de carga, opciones y lectura de metadatos de tablas. No debe invocar <code>tccampaigns_apply()</code>, <code>tccampaigns_restore()</code>, <code>tccampaigns_reschedule()</code> ni rutinas de instalación.</p>

        <h3 id="tcc-tech-io">14. Contratos de importación y exportación</h3>
        <p><code>tccampaigns_export_records()</code> prepara los datos y <code>tccampaigns_handle_export()</code> genera JSON con <code>schema_version</code>, <code>kind</code>, <code>timezone</code> y <code>records</code>, o CSV separado por punto y coma con <code>record_type</code>. Los tipos de fila <code>campaign</code> y <code>product</code> se conservan para round-trip y versiones anteriores.</p>
        <p><code>tccampaigns_handle_import()</code> reconoce JSON y CSV, valida tamaño y referencias de productos y actualiza mediante <code>campaign_key</code>. La opción <code>replace_products</code> cambia la semántica de fusión a sustitución para las campañas correspondientes; no la alteres sin pruebas de compatibilidad con archivos históricos.</p>

        <h3 id="tcc-tech-integrations">15. Integraciones y API pública</h3>
        <p><code>tccampaigns_get_public_active()</code> expone campañas habilitadas y productos aptos para mostrar. <code>tccampaigns_public_template_is_enabled()</code> consulta la activación de la franja en Templates; <code>tccampaigns_banner_shortcode()</code> presenta los resultados del shortcode. Los filtros y adaptadores heredados se registran en el bootstrap solo donde no haya una función anterior cargada; no elimines esa compatibilidad sin analizar las plantillas consumidoras.</p>
        <p>Las opciones sociales propias son <code>tccampaigns_social_publication_time</code> y <code>tccampaigns_social_templates_v1</code>. La publicación usa los hooks <code>admin_post_tccampaigns_social_plan</code>, <code>admin_post_tccampaigns_social_cancel</code> y <code>tccampaigns_social_publish_event</code>. Su estado se conserva en metadatos con prefijos <code>_seo_social_campaign_schedule_</code> y <code>_seo_social_campaign_published_</code>. Respeta la distinción entre programación comercial de Campaigns y calendario editorial de Social.</p>

        <h3 id="tcc-tech-qa">16. Mantenimiento, pruebas y despliegue</h3>
        <ol>
            <li>Revisa el Issue de GitHub y el código actual del plugin independiente antes de cualquier cambio; conserva tablas, hooks, shortcodes y opciones existentes.</li>
            <li>Valida sintaxis PHP (por ejemplo, <code>php -l taxocommerce-campaigns/includes/manual.php</code>) y el resto de archivos afectados.</li>
            <li>Ejecuta Plugin Check contra el ZIP del plugin individual y comprueba que los directorios de pruebas y los metadatos de FTP no se distribuyan.</li>
            <li>En STAGING, revisa navegación, permisos y Autotest; verifica alta y edición de campañas, conflictos de productos, importación/exportación, planificación, aplicación y restauración sobre productos de prueba.</li>
            <li>Documenta el cambio en <code>CHANGELOG.md</code> y vincula el PR con el Issue. Mantén <code>changelog.txt</code> histórico; antes de WordPress.org sincroniza el changelog público de <code>readme.txt</code>.</li>
        </ol>
        <p>La fusión en GitHub no demuestra que el FTP haya desplegado ni que WordPress ejecute la versión nueva. Comprueba el workflow, los archivos instalados y el resultado funcional antes de cerrar el Issue. <strong>Producción requiere autorización expresa.</strong></p>
    </section>

    <section class="tcc-card tcc-manual">
        <h2 id="tcc-manual-help">Diagnóstico y resolución de incidencias</h2>
        <h3>17. Problemas frecuentes</h3>
        <ul>
            <li><strong>No se ven las pestañas:</strong> verifica que el servidor contiene la versión de <code>taxocommerce-campaigns/includes/admin.php</code> con Manual y Autotest, y que <code>taxocommerce-campaigns.php</code> carga sus archivos. Diferencia STAGING de PRO.</li>
            <li><strong>No se aplica una oferta:</strong> comprueba estado habilitado, fechas, producto existente, solapamientos y las acciones programadas de Action Scheduler/WP-Cron.</li>
            <li><strong>No se restaura un precio:</strong> revisa las instantáneas y el estado <code>applied</code> antes de modificar manualmente el producto; comprueba si otro plugin también gestiona el precio.</li>
            <li><strong>Faltan tablas:</strong> consulta Autotest y el valor de versión de esquema. Haz copia de seguridad antes de corregir una instalación o migración.</li>
            <li><strong>Una importación falla:</strong> valida extensión JSON/CSV, tamaño (máximo 2 MB), <code>campaign_key</code>, identidad de los productos y la semántica de <code>replace_products</code>.</li>
            <li><strong>No se muestra un banner:</strong> revisa habilitación y vigencia de la campaña, visibilidad y stock de los productos, la clave del shortcode y, si procede, la activación de la plantilla en Templates.</li>
            <li><strong>No se publica en redes:</strong> verifica TaxoCommerce Social, proveedor, permisos, credenciales y cola comercial; Autotest no comprueba las API de las redes.</li>
        </ul>
    </section>
    <?php
}
