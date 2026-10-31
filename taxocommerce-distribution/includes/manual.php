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
    <section class="tcdist-manual" style="max-width:1100px;">
        <h2 id="tcdist-manual-top"><?php esc_html_e( 'Manual de TaxoCommerce Distribution', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Guía de uso y referencia técnica del plugin independiente de publicación de feeds comerciales y sitemaps XML.', 'taxocommerce-distribution' ); ?></p>
        <p>
            <a href="#tcdist-manual-usuario"><?php esc_html_e( 'Manual de usuario', 'taxocommerce-distribution' ); ?></a> ·
            <a href="#tcdist-manual-tecnico"><?php esc_html_e( 'Manual técnico', 'taxocommerce-distribution' ); ?></a> ·
            <a href="#tcdist-manual-diagnostico"><?php esc_html_e( 'Diagnóstico', 'taxocommerce-distribution' ); ?></a>
        </p>

        <h2 id="tcdist-manual-usuario"><?php esc_html_e( 'Manual de usuario', 'taxocommerce-distribution' ); ?></h2>
        <h3><?php esc_html_e( '1. Acceso y pestañas', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Abre TaxoCommerce Core > Distribution; si Core no está instalado, utiliza Distribution en el menú principal. El plugin cuenta con Overview, Commercial Feeds, Sitemaps, Automation, Manual y Autocheck. Requiere los permisos de gestión de WooCommerce configurados para Distribution, o permisos de administrador.', 'taxocommerce-distribution' ); ?></p>

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

        <h2 id="tcdist-manual-tecnico"><?php esc_html_e( 'Manual técnico', 'taxocommerce-distribution' ); ?></h2>
        <h3><?php esc_html_e( '8. Arquitectura y enrutado', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Entrada: taxocommerce-distribution.php. includes/admin.php registra la administración con el slug taxocommerce-distribution y el selector tcdist_tab. includes/feeds/core.php, channels.php y admin.php definen estado, formatos, generación y acciones. includes/worker.php ejecuta las ventanas programadas. includes/sitemaps/engine.php sirve, valida y publica XML. Manual y Autocheck son pantallas propias y no alteran los motores.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '9. Canales y almacenamiento', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Los feeds se publican bajo wp-content/uploads/seo-system-feeds/ con los nombres google-merchant.xml, microsoft-merchant.txt, pinterest-catalog.csv, catalogo-universal.csv y catalogo-universal.json. Los sitemaps se almacenan bajo wp-content/uploads/seo-sitemaps/ y se sirven desde URLs estables del dominio principal, incluido /sitemap.xml.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'La publicación comercial utiliza archivos temporales y reemplazo atómico. El sitemap valida estructura XML antes de publicar. No coloques los ficheros generados dentro del ZIP del plugin ni cambies sus rutas públicas sin plan de migración.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '10. Opciones, hooks y ejecución', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Opciones: tcdist_commercial_feeds_settings_v1, tcdist_commercial_feeds_state_v1, tcdist_commercial_feeds_history_v1, tcdist_worker_state, tcdist_worker_components y tcdist_activation_version. La migración inicial copia ajustes e historial desde seo_ie_commercial_feeds_* solo cuando aún no existen opciones propias; no borra los originales.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Worker: taxocommerce_distribution_feed_worker. Hooks de feeds: tcdist_feed_daily_refresh, tcdist_feed_build_batch y tcdist_feed_queued_refresh, registrados según propiedad de automatización. Los sitemaps utilizan tcdist_sitemap_regenerate_sitemaps_event. Se escuchan cambios de productos y categorías para planificar la siguiente regeneración cuando Distribution es el responsable.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Los controladores admin-post_tcdist_feed_save_settings, admin-post_tcdist_feed_regenerate, admin-post_tcdist_feed_stop y admin-post_tcdist_sitemap_regenerate realizan acciones operativas con comprobación de permisos y nonce. No deben ejecutarse desde Autocheck.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '11. Propiedad de procesos y protección de datos', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Las constantes TCDIST_LEGACY_FEED_ACTIVE y TCDIST_LEGACY_SITEMAP_ACTIVE detectan coexistencia. Durante ella, no se registran workers automáticos duplicados para los mismos ficheros. Para estudiar el estado consulta las opciones persistidas y WP-Cron sin forzar disparos. El proceso trabaja con lotes, cursores, exclusiones y bloqueos para limitar consumo.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Autocheck utiliza funciones WordPress de lectura y comprobaciones del sistema de archivos. No llama a tcdist_feed_storage(), porque esta función puede crear un directorio o index.html al inicializar el almacenamiento. Tampoco llama al generador de XML ni al worker.', 'taxocommerce-distribution' ); ?></p>

        <h3><?php esc_html_e( '12. Validación y despliegue', 'taxocommerce-distribution' ); ?></h3>
        <p><?php esc_html_e( 'Mantén las pruebas de desarrollo en tests/ dentro de GitHub, pero fuera del ZIP de WordPress. Ejecuta PHP lint, smoke de regresión, autocheck aislado y Plugin Check. Actualiza CHANGELOG.md y readme.txt sin alterar el histórico changelog.txt. Prueba en STAGING; producción requiere autorización expresa.', 'taxocommerce-distribution' ); ?></p>

        <h2 id="tcdist-manual-diagnostico"><?php esc_html_e( 'Diagnóstico y problemas habituales', 'taxocommerce-distribution' ); ?></h2>
        <h3><?php esc_html_e( '13. Interpretación de incidencias', 'taxocommerce-distribution' ); ?></h3>
        <ul style="list-style:disc;padding-left:22px">
            <li><?php esc_html_e( 'Un feed no aparece: revisa el estado, canales activados, exclusiones, worker y permisos del directorio uploads. No vuelvas a iniciar procesos simultáneos.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'El sitemap falta o no es válido: comprueba el informe de Sitemaps, rutas de publicación, reescrituras y propiedad del monolito. Regenera solo después de verificar el motivo.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'STAGING muestra un host incorrecto: revisa el entorno efectivo y WP_ENVIRONMENT_TYPE; los feeds de STAGING no deben anunciar URLs de PRO.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Autocheck muestra avisos: un archivo aún no generado o un cron sin programar no equivale necesariamente a un fallo técnico. Contrasta con Automation y la configuración de producción.', 'taxocommerce-distribution' ); ?></li>
            <li><?php esc_html_e( 'Con monolito activo: evita duplicar tareas que publican los mismos nombres de archivo. Distribución manual y automática tienen propietarios distintos durante la migración.', 'taxocommerce-distribution' ); ?></li>
        </ul>
        <p><a href="#tcdist-manual-top"><?php esc_html_e( 'Volver al índice', 'taxocommerce-distribution' ); ?></a></p>
    </section>
    <?php
}
