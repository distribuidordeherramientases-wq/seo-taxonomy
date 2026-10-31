<?php
/** User and technical documentation integrated into TaxoCommerce Auditor. */
namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Manual {
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para consultar este manual.', 'taxocommerce-auditor' ) );
        }
        ?>
        <section class="card taxocommerce-auditor-manual" style="max-width:1180px">
            <h2>Manual de TaxoCommerce Auditor · v<?php echo esc_html( Plugin::VERSION ); ?></h2>
            <p>Documentación integrada de la versión independiente: guía de uso y referencia técnica. Consultarla no ejecuta auditorías ni modifica el catálogo.</p>
            <p><a href="#tca-manual-user">Manual de usuario</a> · <a href="#tca-manual-tech">Manual técnico</a></p>

            <div id="tca-manual-user">
                <h2>1. Manual de usuario</h2>
                <h3>1.1 Objetivo y acceso</h3>
                <p>Auditor detecta incidencias de calidad editorial y estructura, propone prioridades de revisión y presenta informes. <strong>No corrige, publica, elimina, mueve ni redirige productos, categorías, entradas, páginas ni preguntas frecuentes.</strong> Su fuente principal es WordPress/WooCommerce; otros módulos aportan contexto local cuando está disponible.</p>
                <p>Acceso: en el administrador de WordPress, <strong>TaxoCommerce Core → Auditor</strong> cuando Core está instalado, o menú propio <strong>Auditor</strong> en caso contrario. El administrador debe contar con permiso <code>manage_options</code>.</p>
                <h3>1.2 Los cinco ámbitos</h3>
                <div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Pestaña</th><th>Qué revisa</th></tr></thead><tbody>
                    <tr><td>Productos</td><td>Títulos, descripciones, extractos, SKU, categorías, atributos, vocabulario, duplicidad y coherencia técnica.</td></tr>
                    <tr><td>Categorías y arquitectura</td><td>Descripción, jerarquía, estructura, cobertura y propuestas de revisión para dividir o concentrar categorías con evidencia.</td></tr>
                    <tr><td>Posts</td><td>Títulos, slugs, contenido y posible solapamiento con otras entradas o landings.</td></tr>
                    <tr><td>Páginas y landings</td><td>Contenido, títulos, estructura, jerarquía y roles SEO disponibles en metadatos locales.</td></tr>
                    <tr><td>FAQs</td><td>Pregunta, respuesta, titularidad, destino en producto/categoría y coherencia. El módulo FAQs es opcional.</td></tr>
                </tbody></table></div>
                <p>Las pestañas <strong>Auto Test</strong> y <strong>Manual</strong> se muestran después de los cinco ámbitos. No son ámbitos de contenido ni generan informes de auditoría.</p>
                <h3>1.3 Auditoría filtrada y auditoría completa</h3>
                <p>Selecciona un ámbito. En <strong>Auditoría filtrada</strong> puedes buscar por título o ID; en Productos también por categoría. El límite seleccionable es 10, 25, 50, 100 o 250 elementos. <strong>Auditar filtro</strong> revisa el subconjunto solicitado. <strong>Auditar todo</strong> crea una auditoría completa por lotes del ámbito elegido; el informe solo se considera representativo del conjunto cuando termina sin interrupciones.</p>
                <p>Si el proceso está en cola o ejecución, muestra estado, progreso y botones <strong>Procesar siguiente lote</strong> y <strong>Pausar</strong>; una auditoría pausada puede <strong>Reanudarse</strong>. Las ejecuciones largas dependen de WP-Cron. No repitas el arranque mientras esté procesándose el mismo ámbito.</p>
                <h3>1.4 Cómo leer los KPIs</h3>
                <p>Los indicadores agrupan entidades revisadas, hallazgos, severidad, puntuación y nivel de calidad. Las cinco dimensiones internas de evaluación son <strong>utilidad (25), confianza (20), diferenciación (20), arquitectura (20) y técnica (15)</strong>. Las bandas orientativas son fuerte (85–100), correcta (70–84), mejorable (55–69) y débil (menos de 55). Si no hay evidencia suficiente, la calidad puede aparecer como no disponible; no debe interpretarse como cero.</p>
                <h3>1.5 Vistas de informe</h3>
                <p>Una vez finalizada la auditoría, accede a <strong>Resumen, Calidad interna, Plan priorizado, Hallazgos, Contexto de decisión e Historial</strong>. En Categorías aparece además <strong>Reequilibrio</strong>, con propuestas de división/concentración basadas en evidencia local. El plan indica qué revisar primero y qué necesita contrastar fuentes; <strong>una sugerencia no es una orden de modificación automática</strong>.</p>
                <p>En los informes completos puedes descargar <strong>JSON completo</strong>. La tabla mostrada en pantalla está acotada para no sobrecargar el navegador, pero los resultados completos se almacenan como JSONL y se transmiten al exportar. Si no existe un informe completo anterior, la opción de exportación no está disponible.</p>
                <h3>1.6 Prioridades y fuentes</h3>
                <p>Las prioridades P1–P5 y los estados de revisión indican urgencia, evidencia necesaria y acciones pendientes. El contexto puede mostrar información local de Insights, Suppliers, Dependiente u otras extensiones si ya estaba guardada: <strong>Auditor no consulta Google, Bing ni APIs externas</strong> al hacer el análisis.</p>
                <h3>1.7 Auto Test</h3>
                <p>La pestaña <strong>Auto Test</strong> revisa disponibilidad de módulos, rutas, cinco ámbitos, calidad, prioridades, filtros, lector de estados, historial y exportación. Pulsa <strong>Ejecutar Auto Test</strong> antes y después de actualizar. El resultado incluye 16 pruebas agrupadas, estados Correcto, Error y No ejecutado, versión, tiempo de ejecución y comparación con la anterior. Se conservan los dos últimos informes de cada usuario durante 30 días.</p>
                <p>No inicia auditorías, no consulta APIs externas, no genera archivos JSONL y no modifica contenidos. Un ámbito sin informe previo no equivale a error técnico. Las operaciones reales que podrían modificar opciones, lanzar procesos o producir archivos aparecen como <strong>No ejecutado</strong>.</p>
                <h3>1.8 Secuencia recomendada</h3>
                <p>Primero ejecuta Auto Test. Después selecciona un ámbito y comienza por una muestra filtrada. Revisa los indicadores, los hallazgos y las prioridades; contrasta los avisos críticos antes de editar en WordPress. Si necesitas un diagnóstico global, ejecuta la auditoría completa, comprueba que finaliza y descarga su JSON.</p>
            </div>

            <div id="tca-manual-tech">
                <h2>2. Manual técnico</h2>
                <h3>2.1 Punto de entrada y arquitectura</h3>
                <p><code>taxocommerce-auditor.php</code> declara el plugin y la clase <code>TaxoCommerce\Auditor\Plugin</code>. En <code>plugins_loaded</code> se registra <code>Runner</code>, <code>Admin</code> y <code>Auto_Test</code>; Core puede asociarlo como módulo mediante <code>taxocommerce_core_register_modules</code>. No depende del arranque del monolito ni de sus clases globales.</p>
                <p>Los archivos <code>includes/class-rules.php</code>, <code>class-editorial-specialist.php</code>, <code>class-category-review.php</code> y <code>class-priority.php</code> definen la inspección y la priorización; <code>class-faq-source.php</code> adapta FAQs; <code>class-runner.php</code> controla lotes/estados; <code>class-export-store.php</code> gestiona JSONL; <code>class-decision-context.php</code> solo lee evidencias locales; <code>class-admin.php</code> mantiene la interfaz. <code>class-auto-test.php</code> y <code>class-manual.php</code> sirven únicamente a las nuevas pestañas.</p>
                <h3>2.2 Estado, opciones y lotes</h3>
                <p><code>Runner::scopes()</code> proporciona los cinco ámbitos. Por ámbito se guardan opciones <code>taxocommerce_auditor_state_{scope}</code>, <code>taxocommerce_auditor_report_{scope}</code> y <code>taxocommerce_auditor_history_{scope}</code>. La ejecución usa el hook <code>taxocommerce_auditor_process_batch</code>, lotes de <code>Runner::BATCH_SIZE</code> (40) y un bloqueo transitorio <code>taxocommerce_auditor_lock_{scope}</code> para impedir solapamientos. <code>Runner::ensure_scheduled()</code> recupera impulsos de cron perdidos sin reiniciar el progreso.</p>
                <p>Los resultados completos no se cargan íntegramente como una opción: se escriben como <strong>JSON Lines</strong> en <code>wp-content/uploads/taxocommerce-auditor/</code>. La memoria de la interfaz retiene una muestra acotada. La ruta de almacenamiento debe tener permisos de escritura para las auditorías reales, pero Auto Test <strong>no intenta crearla ni escribir en ella</strong>.</p>
                <h3>2.3 Fuentes y dependencias</h3>
                <p>Se utilizan APIs nativas de WordPress (<code>WP_Query</code>, posts, taxonomías y metadatos), WooCommerce (<code>wc_get_product</code>) y el adaptador FAQ cuando existe <code>{prefix}seo_faq</code> o un CPT FAQ admitido. El contexto de otros plugins es opcional y se obtiene de datos locales ya persistidos, nunca de peticiones remotas. No añadas nuevas tablas ni acoples Auditor a archivos del monolito.</p>
                <h3>2.4 Acciones administrativas y permisos</h3>
                <p><code>Admin::register()</code> registra <code>admin_post_taxocommerce_auditor_action</code> y <code>admin_post_taxocommerce_auditor_export</code>. El primero admite tareas <code>start</code>, <code>start_full</code>, <code>step</code>, <code>pause</code> y <code>resume</code>; el segundo transmite el JSON de un ámbito con informe existente. Ambos requieren <code>manage_options</code> y un nonce asociado a cada ámbito. La navegación por <code>auditor_scope</code> y <code>auditor_view</code> solo selecciona pantallas; los identificadores se validan con las listas permitidas.</p>
                <h3>2.5 Auto Test: contrato y almacenamiento</h3>
                <p><code>admin_post_taxocommerce_auditor_auto_test</code> exige <code>manage_options</code> y nonce <code>taxocommerce_auditor_auto_nonce</code>. Ejecuta <code>Auto_Test::run_suite()</code> y almacena solo los dos últimos resultados en el transitorio por usuario <code>taxocommerce_auditor_auto_test_{user_id}</code>, con caducidad de 30 días. No se añaden tablas, opciones permanentes ni procesos programados.</p>
                <p>Sus 16 verificaciones se limitan a contratos de clases, acciones registradas, disponibilidad WooCommerce, cinco ámbitos, bandas y prioridades, saneamiento de filtros, APIs del runner, almacenamiento y lectura de estados/informes/historial. No llama a <code>Runner::start()</code>, <code>Runner::run_batch()</code>, <code>Export_Store::begin()</code> ni <code>Export_Store::stream_report()</code>. La última comprobación se marca No ejecutado intencionadamente.</p>
                <h3>2.6 Diagnóstico y seguridad</h3>
                <p>Un Auto Test satisfactorio significa que los componentes y contratos están disponibles; no sustituye una auditoría completa de prueba ni verifica que se puedan exportar y recuperar datos desde el filesystem real. Para anomalías: verifica permisos, estado WP-Cron, instalación de WooCommerce, registro de las rutas, espacio del almacenamiento JSONL y errores PHP. Si falla una fuente opcional de FAQs, consulta las dependencias antes de modificar código.</p>
                <h3>2.7 Pruebas de regresión y distribución</h3>
                <p>El repositorio contiene <code>taxocommerce-auditor/tests/smoke.php</code> (paridad de las reglas y los cinco ámbitos) y <code>tests/auto-test-manual-smoke.php</code> (contratos del nuevo Auto Test y manuales). Ejecuta ambas pruebas con PHP 7.4 y 8.4, más la comprobación de sintaxis, antes del merge. El workflow de publicación excluye <code>tests/</code>, archivos de desarrollo y el estado FTP del ZIP; el archivo <code>readme.txt</code> se mantiene y debe reflejar la versión del encabezado PHP.</p>
            </div>
        </section>
        <?php
    }
}
