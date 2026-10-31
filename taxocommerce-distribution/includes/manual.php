<?php
/**
 * Integrated user and technical documentation for the standalone Distribution plugin.
 *
 * No generation or external requests are triggered by this screen.
 */
defined( 'ABSPATH' ) || exit;

function taxocommerce_distribution_render_manual() {
    taxocommerce_distribution_guard();
    ?>
    <section class="tcdist-manual" aria-label="<?php esc_attr_e( 'Documentación de TaxoCommerce Distribution', 'taxocommerce-distribution' ); ?>">
        <style>
            .tcdist-manual { max-width: 1160px; margin: 14px 0 32px; }
            .tcdist-manual h1 { font-size: 25px; margin: 0 0 14px; line-height: 1.4; }
            .tcdist-manual h2 { font-size: 19px; margin: 22px 0 10px; }
            .tcdist-manual h3 { font-size: 16px; margin: 18px 0 8px; }
            .tcdist-manual p, .tcdist-manual li { font-size: 14px; line-height: 1.65; }
            .tcdist-manual code { overflow-wrap: anywhere; }
            .tcdist-manual-index, .tcdist-manual-guide { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 22px 26px; margin: 18px 0; }
            .tcdist-manual-index a { display: inline-block; margin: 5px 24px 5px 0; }
            .tcdist-manual-guide { scroll-margin-top: 46px; }
            .tcdist-manual-table { overflow-x: auto; }
            .tcdist-manual-table table { width: 100%; min-width: 650px; border-collapse: collapse; }
            .tcdist-manual-table th, .tcdist-manual-table td { border: 1px solid #dcdcde; text-align: left; vertical-align: top; padding: 11px; }
            .tcdist-manual-table th { background: #f6f7f7; }
            @media (max-width: 782px) {
                .tcdist-manual-index, .tcdist-manual-guide { padding: 16px; }
                .tcdist-manual h1 { font-size: 23px; }
            }
        </style>
        <h1 id="tcdist-manual-top"><?php esc_html_e( 'Manual de TaxoCommerce Distribution', 'taxocommerce-distribution' ); ?></h1>
        <p><?php esc_html_e( 'Guía de uso y referencia técnica del plugin independiente de publicación de feeds comerciales y sitemaps XML.', 'taxocommerce-distribution' ); ?></p>
        <nav class="tcdist-manual-index" aria-label="<?php esc_attr_e( 'Índice de documentación', 'taxocommerce-distribution' ); ?>">
            <strong><?php esc_html_e( 'Contenido', 'taxocommerce-distribution' ); ?></strong><p>
            <a href="#tcdist-manual-usuario"><?php esc_html_e( 'Manual de usuario', 'taxocommerce-distribution' ); ?></a> ·
            <a href="#tcdist-manual-tecnico"><?php esc_html_e( 'Manual técnico', 'taxocommerce-distribution' ); ?></a> ·
            <a href="#tcdist-manual-diagnostico"><?php esc_html_e( 'Diagnóstico y mantenimiento', 'taxocommerce-distribution' ); ?></a>
            </p>
        </nav>

        <article class="tcdist-manual-guide" id="tcdist-manual-usuario">
        <h1><?php esc_html_e( 'Manual de usuario', 'taxocommerce-distribution' ); ?></h1>
        <p><?php esc_html_e( 'Destinatarios: responsables de catálogo, operaciones y administración de WooCommerce. Explica qué revisar antes, durante y después de publicar un feed o sitemap.', 'taxocommerce-distribution' ); ?></p>
        <h3><?php esc_html_e( '1. Acceso y pestañas', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Abre TaxoCommerce Core > Distribution; si Core no está instalado, utiliza Distribution en el menú principal. El plugin cuenta con Overview, Commercial Feeds, Sitemaps, Automation, Manual y Autocheck. Requiere los permisos de gestión de WooCommerce configurados para Distribution, o permisos de administrador.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '1.1. Secuencia inicial segura', 'taxocommerce-distribution' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'Confirma el entorno (STAGING o PRO) y que WooCommerce esté activo. Comprueba si el módulo SEO monolítico sigue siendo propietario de las tareas automáticas.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Revisa Overview y Automation antes de modificar canales. Un aviso de archivo no generado no implica que el motor haya fallado.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Abre Commercial Feeds, selecciona canales, país e idioma y guarda; esta operación puede retirar el fichero de un canal desactivado.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Inicia la generación manual solo cuando no haya otra activa; sigue el estado de lotes y comprueba los enlaces publicados.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Comprueba Sitemaps y, si procede, regenera el índice; valida el XML publicado antes de facilitar su URL a buscadores.', 'taxocommerce-distribution' ); ?></li>
        </ol>

        <h3><?php esc_html_e( '2. Overview: estado general', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Consulta el estado de la generación comercial, candidatos procesados, productos publicados, canales activos, estado del índice de sitemaps, URL publicadas y ficheros existentes. Estos indicadores no lanzan ninguna generación.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '3. Commercial Feeds: configuración y generación', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Selecciona los canales Google Merchant (XML), Microsoft/Bing Shopping (TXT), Pinterest (CSV), catálogo universal CSV y catálogo JSON. Configura país e idioma de dos letras, regeneración tras sincronización de proveedores y la hora de actualización diaria. Guarda los ajustes antes de iniciar el proceso.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'El botón Start generation inicia un trabajo de lotes con regulación adaptativa: muestra candidatos, procesados, publicados, excluidos, presión del servidor y última actividad. Stop generation cancela el trabajo actual sin sustituir el último fichero público válido por uno parcial. Revisa los motivos de exclusión y los errores antes de repetir.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Los ficheros tienen URLs estables. Google, Microsoft y Pinterest los descargan desde tu web; el plugin no envía archivos a esas plataformas. Las actualizaciones automáticas comerciales se programan solamente cuando el entorno efectivo es producción y Distribution posee la automatización.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '4. Sitemaps: comprobación y regeneración', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Consulta el índice público /sitemap.xml y sus ficheros hijos: productos, categorías de producto, entradas, páginas, clústeres y hubs. El informe indica validación XML, URLs, cobertura de auditoría, tamaño y fecha de modificación cuando existen datos.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Regenerate and validate sitemaps ejecuta una generación real. El motor agrupa productos en bloques, valida el XML y publica de manera atómica para evitar exponer archivos incompletos. Ejecuta esta operación solo cuando proceda; Autocheck no la activa.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '5. Automation: supervisar el trabajo', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Consulta si la automatización comercial corresponde al plugin independiente o al módulo antiguo, la regeneración diaria, el último lote y la propiedad de los sitemaps. El worker funciona por ventanas con cursor y bloqueo; no es necesario dejar abierta la pantalla para publicar.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '6. Migración y entornos', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Si está activo el plugin SEO monolítico, su automatización conserva la prioridad temporal para evitar workers duplicados; Distribution mantiene la generación manual para pruebas. STAGING usa sus propios productos y URLs: no registres sus feeds en las plataformas comerciales.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '7. Autocheck: verificación sin riesgos', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Abre Autocheck y pulsa Ejecutar autocheck. Comprueba carga, componentes, hooks, opciones, propiedad de automatización y presencia de archivos publicados mediante lecturas. No genera feeds ni sitemaps, no programa procesos, no consulta servicios externos ni modifica ficheros o base de datos. La ausencia de una publicación se muestra como aviso, no como error de código.', 'taxocommerce-distribution' ); ?></p>

        </article>
        <article class="tcdist-manual-guide" id="tcdist-manual-tecnico">
        <h1><?php esc_html_e( 'Manual técnico para futuros desarrolladores', 'taxocommerce-distribution' ); ?></h1>
        <p><?php esc_html_e( 'Destinatarios: desarrolladores que deban mantener, auditar o ampliar el plugin independiente sin duplicar el monolito. Describe las API existentes y los puntos de extensión comprobados en el código.', 'taxocommerce-distribution' ); ?></p>
        <h2><?php esc_html_e( 'Mapa de código y responsabilidades', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Los siguientes archivos constituyen el contrato de mantenimiento. Los nombres tcdist_* son propios del plugin independiente y no deben sustituirse por llamadas al monolito.', 'taxocommerce-distribution' ); ?></p>
        <div class="tcdist-manual-table"><table>
            <thead><tr><th><?php esc_html_e( 'Archivo', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Responsabilidad', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Puntos de entrada', 'taxocommerce-distribution' ); ?></th></tr></thead>
            <tbody>
                <tr><td><code>taxocommerce-distribution.php</code></td><td><?php esc_html_e( 'Bootstrap, versión, coexistencia, migración inicial de opciones, registro en Core, activación y desactivación.', 'taxocommerce-distribution' ); ?></td><td><code>taxocommerce_distribution_boot()</code>, <code>taxocommerce_distribution_migrate_legacy_settings()</code></td></tr>
                <tr><td><code>includes/admin.php</code></td><td><?php esc_html_e( 'Identidad y navegación propia; pantallas Overview, Sitemaps y Automation; acción de regeneración de XML.', 'taxocommerce-distribution' ); ?></td><td><code>taxocommerce_distribution_render_page()</code>, <code>taxocommerce_distribution_guard()</code></td></tr>
                <tr><td><code>includes/feeds/core.php</code></td><td><?php esc_html_e( 'Ajustes, selección de productos, estado, lotes adaptativos, histórico, locks, programación y publicación.', 'taxocommerce-distribution' ); ?></td><td><code>tcdist_feed_start_build()</code>, <code>tcdist_feed_run_manager_slice()</code>, <code>tcdist_feed_stop_build()</code></td></tr>
                <tr><td><code>includes/feeds/channels.php</code></td><td><?php esc_html_e( 'Definición de formatos, columnas, transformación y serialización de cada canal comercial.', 'taxocommerce-distribution' ); ?></td><td><code>tcdist_feed_channels()</code>, <code>tcdist_feed_channel_append_record()</code>, <code>tcdist_feed_channel_finalize()</code></td></tr>
                <tr><td><code>includes/feeds/admin.php</code></td><td><?php esc_html_e( 'Formularios, controles iniciar/detener, AJAX de seguimiento y enlaces de archivos.', 'taxocommerce-distribution' ); ?></td><td><code>tcdist_feed_admin_save_settings()</code>, <code>tcdist_feed_admin_regenerate()</code>, <code>tcdist_feed_admin_ajax_tick()</code></td></tr>
                <tr><td><code>includes/worker.php</code></td><td><?php esc_html_e( 'Ventanas de proceso mediante WP-Cron, reanudación y supervisión.', 'taxocommerce-distribution' ); ?></td><td><code>tcdist_distribution_feed_worker_tick()</code>, <code>tcdist_process_nudge()</code></td></tr>
                <tr><td><code>includes/sitemaps/engine.php</code></td><td><?php esc_html_e( 'Reescrituras y rutas XML, fuentes de URLs, grupos, manifiesto, publicación y validación.', 'taxocommerce-distribution' ); ?></td><td><code>tcdist_sitemap_create_sitemaps()</code>, <code>tcdist_sitemap_maybe_serve_sitemap()</code>, <code>tcdist_sitemap_validate_xml_file()</code></td></tr>
                <tr><td><code>includes/manual.php</code></td><td><?php esc_html_e( 'Documentación de usuario y desarrollador, renderizada sin escribir datos.', 'taxocommerce-distribution' ); ?></td><td><code>taxocommerce_distribution_render_manual()</code></td></tr>
                <tr><td><code>includes/autocheck.php</code></td><td><?php esc_html_e( 'Diagnósticos de solo lectura; no inicializa almacenamiento, workers ni generadores.', 'taxocommerce-distribution' ); ?></td><td><code>taxocommerce_distribution_autocheck_run()</code></td></tr>
            </tbody>
        </table></div>

        <h3><?php esc_html_e( '8. Arquitectura y enrutado', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Entrada: taxocommerce-distribution.php. includes/admin.php registra la administración con el slug taxocommerce-distribution y el selector tcdist_tab. includes/feeds/core.php, channels.php y admin.php definen estado, formatos, generación y acciones. includes/worker.php ejecuta las ventanas programadas. includes/sitemaps/engine.php sirve, valida y publica XML. Manual y Autocheck son pantallas propias y no alteran los motores.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '9. Canales y almacenamiento', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Los feeds se publican bajo wp-content/uploads/seo-system-feeds/ con los nombres google-merchant.xml, microsoft-merchant.txt, pinterest-catalog.csv, catalogo-universal.csv y catalogo-universal.json. Los sitemaps se almacenan bajo wp-content/uploads/seo-sitemaps/ y se sirven desde URLs estables del dominio principal, incluido /sitemap.xml.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'La publicación comercial utiliza archivos temporales y reemplazo atómico. El sitemap valida estructura XML antes de publicar. No coloques los ficheros generados dentro del ZIP del plugin ni cambies sus rutas públicas sin plan de migración.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '10. Opciones, hooks y ejecución', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Opciones: tcdist_commercial_feeds_settings_v1, tcdist_commercial_feeds_state_v1, tcdist_commercial_feeds_history_v1, tcdist_worker_state, tcdist_worker_components y tcdist_activation_version. La migración inicial copia ajustes e historial desde seo_ie_commercial_feeds_* solo cuando aún no existen opciones propias; no borra los originales.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Worker: taxocommerce_distribution_feed_worker. Hooks de feeds: tcdist_feed_daily_refresh, tcdist_feed_build_batch y tcdist_feed_queued_refresh, registrados según propiedad de automatización. Los sitemaps utilizan tcdist_sitemap_regenerate_sitemaps_event. Se escuchan cambios de productos y categorías para planificar la siguiente regeneración cuando Distribution es el responsable.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Los controladores admin-post_tcdist_feed_save_settings, admin-post_tcdist_feed_regenerate, admin-post_tcdist_feed_stop y admin-post_tcdist_sitemap_regenerate realizan acciones operativas con comprobación de permisos y nonce. No deben ejecutarse desde Autocheck.', 'taxocommerce-distribution' ); ?></p>

        <h2><?php esc_html_e( 'Contrato de datos y eventos', 'taxocommerce-distribution' ); ?></h2>
        <h3><?php esc_html_e( '10.1. Persistencia WordPress', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Distribution no necesita una nueva tabla para sus feeds: guarda ajustes, cursor y resultados en wp_options. Utiliza siempre los identificadores constantes de feeds, no cadenas duplicadas. tcdist_commercial_feeds_settings_v1 guarda los canales, país, idioma, actualización diaria y sincronización. tcdist_commercial_feeds_state_v1 guarda el ciclo de generación, cursor, recuentos y estado. tcdist_commercial_feeds_history_v1 conserva el historial.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'El supervisor escribe tcdist_worker_state y tcdist_worker_components. El motor sitemap guarda metadatos de reescritura, generación y manifiesto según sus funciones propias. Las opciones antiguas seo_ie_commercial_feeds_settings_v1 y seo_ie_commercial_feeds_history_v1 son solo fuente de migración: se copian si aún no hay opciones nuevas y no se borran las originales.', 'taxocommerce-distribution' ); ?></p>
        <h3><?php esc_html_e( '10.2. Ciclo de un feed', 'taxocommerce-distribution' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'La administración valida capacidades/nonce y llama a tcdist_feed_start_build() para crear un estado de ejecución.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'tcdist_process_nudge() programa el hook de worker si es necesario; el proceso lee estado y espera hasta not_before cuando existe una pausa adaptativa.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'tcdist_feed_run_manager_slice() procesa lotes acotados, actualiza cursor y contadores. El worker usa una ventana de hasta 45 segundos y reagenda si sigue running.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'channels.php serializa cada registro canónico al formato requerido y el motor finaliza y publica solo al terminar. Al detener se evita publicar archivos parciales.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Al completar, revisa el estado, histórico, recuentos y fichero; un resultado sin productos exige analizar exclusiones antes de considerarlo fallo.', 'taxocommerce-distribution' ); ?></li>
        </ol>
        <h3><?php esc_html_e( '10.3. Mapa de hooks', 'taxocommerce-distribution' ); ?></h3>
        <div class="tcdist-manual-table"><table>
            <thead><tr><th><?php esc_html_e( 'Hook', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Responsabilidad', 'taxocommerce-distribution' ); ?></th></tr></thead><tbody>
                <tr><td><code>plugins_loaded</code> (50)</td><td><code>taxocommerce_distribution_boot()</code></td></tr>
                <tr><td><code>taxocommerce_distribution_feed_worker</code></td><td><code>tcdist_distribution_feed_worker_tick()</code></td></tr>
                <tr><td><code>tcdist_feed_daily_refresh</code>, <code>tcdist_feed_build_batch</code>, <code>tcdist_feed_queued_refresh</code></td><td><?php esc_html_e( 'Refresco diario, compatibilidad del lote y regeneración diferida; solo se registran con propiedad de automatización.', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><td><code>woocommerce_new_product</code>, <code>woocommerce_update_product</code>, <code>woocommerce_delete_product</code></td><td><?php esc_html_e( 'Avisan de cambios para una regeneración diferida, sin ejecutar una publicación por cada producto.', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><td><code>admin_post_tcdist_feed_save_settings</code>, <code>admin_post_tcdist_feed_regenerate</code>, <code>admin_post_tcdist_feed_stop</code>, <code>wp_ajax_tcdist_feed_tick</code></td><td><?php esc_html_e( 'Controles protegidos de ajustes, ejecución y seguimiento AJAX.', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><td><code>template_redirect</code>, <code>robots_txt</code>, <code>query_vars</code></td><td><?php esc_html_e( 'Servicio y anuncio de sitemaps cuando el motor independiente es propietario.', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><td><code>tcdist_sitemap_regenerate_sitemaps_event</code></td><td><?php esc_html_e( 'Regeneración silenciosa programada de sitemaps.', 'taxocommerce-distribution' ); ?></td></tr>
            </tbody></table></div>

        <h3><?php esc_html_e( '11. Propiedad de procesos y protección de datos', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Las constantes TCDIST_LEGACY_FEED_ACTIVE y TCDIST_LEGACY_SITEMAP_ACTIVE detectan coexistencia. Durante ella, no se registran workers automáticos duplicados para los mismos ficheros. Para estudiar el estado consulta las opciones persistidas y WP-Cron sin forzar disparos. El proceso trabaja con lotes, cursores, exclusiones y bloqueos para limitar consumo.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Autocheck utiliza funciones WordPress de lectura y comprobaciones del sistema de archivos. No llama a tcdist_feed_storage(), porque esta función puede crear un directorio o index.html al inicializar el almacenamiento. Tampoco llama al generador de XML ni al worker.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '11.1. Seguridad y contratos de rutas', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'La pantalla propia utiliza page=taxocommerce-distribution y tcdist_tab; no reutiliza el slug administrativo del monolito. Los controladores que cambian ajustes, inician trabajos o regeneran sitemaps utilizan admin-post, la capacidad requerida y check_admin_referer. El pulso AJAX usa nonce y comprueba permisos.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Los feeds se escriben en uploads/seo-system-feeds. tcdist_feed_storage() puede crear el directorio e index.html: NUNCA se debe invocar desde una comprobación de solo lectura. Sitemaps se almacenan en uploads/seo-sitemaps; el enrutado público normaliza los nombres permitidos y sirve URLs del dominio actual. Reescrituras y sitemap de WordPress Core solo se sustituyen si el motor independiente es propietario.', 'taxocommerce-distribution' ); ?></p>
        <h3><?php esc_html_e( '11.2. Coexistencia y riesgo de doble escritura', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'taxocommerce_distribution_boot() se ejecuta en plugins_loaded prioridad 50; comprueba funciones legadas y define TCDIST_LEGACY_FEED_ACTIVE / TCDIST_LEGACY_SITEMAP_ACTIVE. Si el monolito conserva el servicio automático, no se registra el runtime automático duplicado del feed ni las rutas propias de sitemap. Una generación manual de Distribution debe rechazarse mientras haya una generación legada running, porque ambas pueden apuntar al mismo fichero público.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'No elimines comprobaciones de coexistencia, locks, cursores ni publicación atómica al refactorizar. Evita modificar los ficheros de salida con acceso directo o repetir hooks al registrar una extensión.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '12. Validación y despliegue', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Mantén las pruebas de desarrollo en tests/ dentro de GitHub, pero fuera del ZIP de WordPress. Ejecuta PHP lint, smoke de regresión, autocheck aislado y Plugin Check. Actualiza CHANGELOG.md y readme.txt sin alterar el histórico changelog.txt. Prueba en STAGING; producción requiere autorización expresa.', 'taxocommerce-distribution' ); ?></p>

        <h2><?php esc_html_e( 'Procedimiento para futuros desarrolladores', 'taxocommerce-distribution' ); ?></h2>
        <h3><?php esc_html_e( '12.1. Cómo desarrollar una mejora sin romper lo existente', 'taxocommerce-distribution' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'Localiza la funcionalidad y la función responsable en el mapa de código. Comprueba los hooks, opciones, rutas y dependencias antes de editar.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Documenta el cambio en un issue y trabaja en una rama de GitHub. Evita cambios en archivos del monolito; Distribution debe seguir siendo autónomo.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Preserva el registro canónico de productos, las cinco salidas comerciales existentes, los filtros, los formatos, el cursor, los locks y la publicación atómica.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Usa los permisos y nonces de WordPress, valida entradas y escapa salidas; mantén el aislamiento entre PRO y STAGING y el control de propiedad cuando coexista el monolito.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Añade pruebas de regresión que cubran el caso nuevo y los casos anteriores. Las pruebas de desarrollo permanecen en tests/ y deben excluirse del paquete de WordPress.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Actualiza el Manual de usuario y este Manual técnico, CHANGELOG.md y readme.txt en la misma PR; conserva changelog.txt como histórico.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Valida PHP 7.4/8.4, smoke, Plugin Check, coexistencia y ausencia de efectos secundarios. Integra y comprueba STAGING; producción requiere autorización expresa.', 'taxocommerce-distribution' ); ?></li>
        </ol>
        <h3><?php esc_html_e( '12.2. Comandos de prueba y límites', 'taxocommerce-distribution' ); ?></h3>
        <p><code>php taxocommerce-distribution/tests/distribution-smoke.php</code></p>
        <p><code>php taxocommerce-distribution/tests/manual-autocheck-smoke.php</code></p>
        <p><?php esc_html_e( 'La CI .github/workflows/taxocommerce-distribution.yml ejecuta estas pruebas y valida el paquete. El Autocheck no prueba la exactitud de los precios exportados, el comportamiento real del cron, el XML desde HTTP o la aceptación por Google/Microsoft/Pinterest: esos contratos requieren pruebas funcionales específicas en STAGING.', 'taxocommerce-distribution' ); ?></p>
        <p><strong><?php esc_html_e( 'Fuera de alcance:', 'taxocommerce-distribution' ); ?></strong> <?php esc_html_e( 'No hay envío directo a Merchant Center, repositorio externo de feeds, migración destructiva de opciones legadas ni restauración automática de versiones anteriores.', 'taxocommerce-distribution' ); ?></p>
        <p><a href="#tcdist-manual-top"><?php esc_html_e( 'Volver al índice', 'taxocommerce-distribution' ); ?></a></p>
        </article>
        <article class="tcdist-manual-guide" id="tcdist-manual-diagnostico">
        <h1><?php esc_html_e( 'Diagnóstico y problemas habituales', 'taxocommerce-distribution' ); ?></h1>
        <h3><?php esc_html_e( '13. Interpretación de incidencias', 'taxocommerce-distribution' ); ?></h3>
        <ul style="list-style:disc;padding-left:22px">
            <li><?php esc_html_e( 'Un feed no aparece: revisa el estado, canales activados, exclusiones, worker y permisos del directorio uploads. No vuelvas a iniciar procesos simultáneos.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'El sitemap falta o no es válido: comprueba el informe de Sitemaps, rutas de publicación, reescrituras y propiedad del monolito. Regenera solo después de verificar el motivo.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'STAGING muestra un host incorrecto: revisa el entorno efectivo y WP_ENVIRONMENT_TYPE; los feeds de STAGING no deben anunciar URLs de PRO.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Autocheck muestra avisos: un archivo aún no generado o un cron sin programar no equivale necesariamente a un fallo técnico. Contrasta con Automation y la configuración de producción.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Con monolito activo: evita duplicar tareas que publican los mismos nombres de archivo. Distribución manual y automática tienen propietarios distintos durante la migración.', 'taxocommerce-distribution' ); ?></li>
        </ul>
        <p><a href="#tcdist-manual-top"><?php esc_html_e( 'Volver al índice', 'taxocommerce-distribution' ); ?></a></p>
        </article>
    </section>
    <?php
}
