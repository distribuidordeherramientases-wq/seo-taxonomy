<?php
/**
 * In-plugin user and developer manuals. Read-only; no queries or actions.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Manual {
    private static function section( $id, $title, $html ) {
        echo '<section id="' . esc_attr( $id ) . '" class="tcdb-manual-section"><h2>' . esc_html( $title ) . '</h2>' . wp_kses_post( $html ) . '</section>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para ver este manual.', 'taxocommerce-database' ) );
        }
        echo '<div class="wrap tcdb-manual">';
        echo '<style>
          .tcdb-manual{max-width:1180px}.tcdb-manual h1{font-size:27px;margin:28px 0 15px}
          .tcdb-manual h2{font-size:21px;margin:0 0 14px}.tcdb-manual h3{font-size:16px;margin:20px 0 8px}
          .tcdb-manual p,.tcdb-manual li{font-size:14px;line-height:1.65}
          .tcdb-manual-section{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:18px 0;padding:24px}
          .tcdb-manual-index{background:#fff;border:1px solid #dcdcde;padding:16px 22px;margin-top:16px}
          .tcdb-manual-index a{display:inline-block;margin:5px 15px 5px 0}
          .tcdb-manual-table{overflow:auto;max-width:100%}.tcdb-manual-table table{border-collapse:collapse;width:100%;min-width:660px}
          .tcdb-manual-table th,.tcdb-manual-table td{border:1px solid #dcdcde;padding:10px;text-align:left;vertical-align:top}
          .tcdb-manual-table th{background:#f6f7f7}.tcdb-manual code{overflow-wrap:anywhere}
          @media(max-width:782px){.tcdb-manual-section{padding:16px}.tcdb-manual h1{font-size:23px}}
        </style>';
        echo '<h1>Manual de TaxoCommerce Database</h1>';
        echo '<p>Guías de uso y mantenimiento del plugin. La lectura de estos manuales no ejecuta consultas, limpiezas ni exportaciones.</p>';
        echo '<nav class="tcdb-manual-index" aria-label="Índice de documentación"><strong>Contenido</strong><div>';
        foreach ( array(
            'usuario' => 'Manual de usuario',
            'errores' => 'Errores en datos',
            'limpieza' => 'Limpieza BBDD',
            'backup' => 'Exportar BBDD',
            'tablas' => 'Resumen y explorador',
            'exportar-tablas' => 'Exportar tablas',
            'desarrollador' => 'Manual técnico',
            'arquitectura' => 'Arquitectura',
            'api' => 'Rutas y seguridad',
            'modelo' => 'Modelo de datos',
            'desarrollo' => 'Evolución y pruebas',
        ) as $anchor => $label ) {
            echo '<a href="#' . esc_attr( $anchor ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div></nav>';

        echo '<h1 id="usuario">Manual de usuario final</h1>';
        self::section( 'errores', '1. Errores en datos', '
            <p>Comprueba la integridad de los nodos y relaciones SEO. Los indicadores muestran relaciones analizadas, incidencias, avisos de configuración, nodos sin contenido activo, duplicados y productos con varios ámbitos. Los resultados normales no requieren acción.</p>
            <h3>Acciones de reparación disponibles</h3>
            <div class="tcdb-manual-table"><table><thead><tr><th>Botón o sección</th><th>Qué hace</th><th>Qué debe comprobar el administrador</th></tr></thead><tbody>
            <tr><td>Eliminar relaciones inválidas</td><td>Elimina filas de relaciones SEO cuyos extremos se han validado como inválidos.</td><td>Leer el motivo y revisar las filas antes de confirmar; no elimina productos.</td></tr>
            <tr><td>Eliminar nodos internos obsoletos</td><td>Quita nodos SEO sin objeto WordPress activo y sus relaciones.</td><td>Comprobar que el contenido realmente no debe recuperarse.</td></tr>
            <tr><td>Eliminar copias de relaciones</td><td>Conserva un ejemplar de cada relación duplicada exacta.</td><td>Revisar los duplicados mostrados.</td></tr>
            <tr><td>Eliminar copias de nodos</td><td>Retira nodos repetidos del mismo objeto y rol.</td><td>Confirmar que son duplicados.</td></tr>
            <tr><td>Mover categorías afectadas a la raíz</td><td>Repara categorías con un padre que ya no existe.</td><td>Revisar posteriormente la jerarquía comercial.</td></tr>
            <tr><td>Restablecer objeto SEO</td><td>Usa ID y rol SEO para quitar su nodo y relaciones internas; conserva el contenido WordPress.</td><td>Introducir el ID y rol correctos; realizar backup previo.</td></tr>
            <tr><td>Desbloquear aceptación masiva</td><td>Quita un bloqueo de proveedores, solo si existe.</td><td>Comprobar que no hay otra aceptación en curso.</td></tr>
            </tbody></table></div>
            <p><strong>Comprobaciones informativas:</strong> los avisos de tipos de relación no registrados y de productos con varios ámbitos no reparan automáticamente datos; pueden ser situaciones legítimas que requieren revisión de la configuración.</p>
        ');
        self::section( 'limpieza', '2. Limpieza BBDD', '
            <p>Muestra cuántos registros son candidatos a eliminación y permite ejecutar cada limpieza con su propio botón. Si una fila indica <em>Nada que limpiar</em>, no hay acción disponible. El nivel Bajo/Medio es una orientación, no una garantía de reversibilidad.</p>
            <p><strong>Antes de borrar:</strong> descargar un backup SQL. La limpieza se inicia manualmente, tras el diálogo de confirmación; no existe una tarea en segundo plano con progreso ni un historial de rollback. Los botones no se limitan a 100 registros en total, pero una operación grande puede agotar los recursos o el tiempo del hosting.</p>
            <div class="tcdb-manual-table"><table><thead><tr><th>Botón</th><th>Registros afectados</th></tr></thead><tbody>
            <tr><td>Eliminar revisiones</td><td>Versiones históricas de contenidos (post_type revision).</td></tr>
            <tr><td>Eliminar autoguardados</td><td>Borradores automáticos (auto-draft).</td></tr>
            <tr><td>Vaciar papelera</td><td>Entradas, páginas y productos ya enviados a la papelera; eliminación definitiva.</td></tr>
            <tr><td>Eliminar spam</td><td>Comentarios marcados como spam.</td></tr>
            <tr><td>Eliminar comentarios papelera</td><td>Comentarios enviados a la papelera.</td></tr>
            <tr><td>Eliminar transients expirados</td><td>Datos temporales de WordPress cuya caducidad ha pasado.</td></tr>
            <tr><td>Eliminar postmeta huérfano</td><td>Metadatos sin entrada/producto/página padre.</td></tr>
            <tr><td>Eliminar commentmeta huérfano</td><td>Metadatos sin comentario padre.</td></tr>
            <tr><td>Eliminar termmeta huérfano</td><td>Metadatos sin término padre.</td></tr>
            <tr><td>Eliminar relaciones huérfanas</td><td>Relaciones de términos cuyo objeto ya no existe.</td></tr>
            <tr><td>Eliminar sesiones expiradas</td><td>Sesiones caducadas de WooCommerce.</td></tr>
            <tr><td>Eliminar usermeta huérfano</td><td>Metadatos sin usuario padre.</td></tr>
            <tr><td>Eliminar logs huérfanos</td><td>Registros de Action Scheduler sin acción asociada.</td></tr>
            <tr><td>Purgar logs antiguos</td><td>Logs de acciones completadas/canceladas de más de 30 días; no purga acciones pendientes.</td></tr>
            <tr><td>Eliminar claims huérfanos</td><td>Claims de Action Scheduler sin acciones asociadas y antiguos.</td></tr>
            <tr><td>Eliminar reservas expiradas</td><td>Reservas de stock de WooCommerce caducadas.</td></tr>
            <tr><td>Eliminar meta huérfano</td><td>Metadatos de líneas de pedido sin item padre; no elimina pedidos.</td></tr>
            </tbody></table></div>
            <p>Los botones <strong>Descargar backup SQL completo</strong> y <strong>Descargar solo tablas TaxoCommerce SEO</strong> permiten guardar una copia antes de limpiar.</p>
        ');
        self::section( 'backup', '3. Exportar BBDD', '
            <h3>Descargar backup SQL completo</h3>
            <p>Genera una descarga SQL con estructura y datos de las tablas cuyo nombre empieza por el prefijo activo de WordPress. Puede contener información personal y credenciales; consérvela de forma segura. No incluye archivos subidos al servidor.</p>
            <h3>Descargar solo tablas TaxoCommerce SEO</h3>
            <p>Descarga exclusivamente las tablas que empiezan por el prefijo WordPress seguido de <code>seo_</code>. La lista de tablas detectadas permite verificar el alcance.</p>
            <p><strong>Importante:</strong> la exportación es una descarga manual, no una copia periódica ni una restauración automática. En bases grandes depende de los límites de tiempo y recursos del servidor. Compruebe que el archivo descargado está completo antes de utilizarlo como respaldo.</p>
        ');
        self::section( 'tablas', '4. Resumen tablas y Explorar tablas', '
            <h3>Resumen tablas</h3><p>Presenta inventario de tablas <code>seo_*</code>, filas aproximadas, tamaños, motor y enlace <strong>Explorar</strong>. Ese botón abre la tabla seleccionada sin modificarla.</p>
            <h3>Explorar tablas: controles</h3>
            <div class="tcdb-manual-table"><table><thead><tr><th>Control</th><th>Función</th></tr></thead><tbody>
            <tr><td>Tabla</td><td>Selecciona una tabla interna SEO detectada.</td></tr>
            <tr><td>Buscar en toda la tabla</td><td>Busca el texto indicado en las columnas de la tabla.</td></tr>
            <tr><td>Columna y Valor</td><td>Restringe la consulta al valor de una columna.</td></tr>
            <tr><td>Filas</td><td>Elige 25, 50, 100, 250 o 500 registros por página; no es un límite de borrado.</td></tr>
            <tr><td>Aplicar</td><td>Ejecuta la consulta de lectura con los filtros.</td></tr>
            <tr><td>Limpiar</td><td>Restablece los filtros del explorador; <strong>no borra datos</strong>.</td></tr>
            <tr><td>Encabezados de columna</td><td>Cambian la ordenación ascendente/descendente.</td></tr>
            <tr><td>Paginación</td><td>Permite recorrer resultados sin cargarlos todos en pantalla.</td></tr>
            </tbody></table></div>
        ');
        self::section( 'exportar-tablas', '5. Exportar tablas', '
            <h3>Descargar CSV / Descargar SQL</h3><p>Cada fila ofrece dos botones: CSV para análisis en hojas de cálculo, o SQL para conservar esquema y registros de esa tabla.</p>
            <h3>Todas en CSV / Todas en SQL / CSV + SQL</h3><p>Descargan un archivo ZIP con todas las tablas SEO detectadas, en el formato elegido, e incluyen un manifiesto JSON. Necesitan <code>ZipArchive</code> en PHP y espacio temporal en el servidor.</p>
            <p><strong>Esta copia ZIP no es un backup completo de WordPress.</strong> No incorpora necesariamente otras tablas de WordPress ni los archivos multimedia.</p>
        ');

        echo '<h1 id="desarrollador">Manual técnico para desarrolladores</h1>';
        self::section( 'arquitectura', '6. Arquitectura y ciclo de carga', '
            <p>Plugin independiente de WordPress, mínimo PHP 7.4 y WordPress 6.5. El punto de entrada <code>taxocommerce-database.php</code> declara versión/rutas e incluye tres archivos: Clean DB, SEO Data Table y este manual. No requiere TaxoCommerce Core: con Core aparece como submenú; sin Core registra menú propio.</p>
            <div class="tcdb-manual-table"><table><thead><tr><th>Archivo</th><th>Responsabilidad</th></tr></thead><tbody>
            <tr><td><code>taxocommerce-database.php</code></td><td>Bootstrap, registro de menú, barra de pestañas y enrutamiento.</td></tr>
            <tr><td><code>includes/legacy/seo-database-clean.php</code></td><td>Chequeos SEO, reparaciones, limpiezas WP/WC y SQL completo/parcial.</td></tr>
            <tr><td><code>includes/legacy/seo-database-report.php</code></td><td>Inventario, explorador y CSV/SQL/ZIP de tablas SEO.</td></tr>
            <tr><td><code>includes/class-tcdb-manual.php</code></td><td>Documentación integrada solo lectura.</td></tr>
            <tr><td><code>assets/seo-clean-db.css</code></td><td>Estilos del servicio Clean Database.</td></tr>
            </tbody></table></div>
            <p>Los dos servicios originales utilizan el namespace <code>TCDB_Legacy</code> para evitar choques con el monolito. <code>TCDB_Manual</code> es una clase separada sin queries SQL. No se incorpora Data Layer ni rollback histórico transversal; las reparaciones SEO emplean transacciones SQL locales.</p>
        ');
        self::section( 'api', '7. Enrutamiento, acciones y seguridad', '
            <h3>Pestañas de administración</h3><p>Ruta base: <code>wp-admin/admin.php?page=taxocommerce-database</code>. Limpieza e integridad: <code>tcdb_screen=clean&amp;seo_clean_db_tab=data_errors|database_cleanup|export</code>. Tablas: <code>tcdb_screen=table&amp;section=overview|explorer|export</code>. Manual: <code>tcdb_screen=manual</code>. La navegación es GET de solo lectura.</p>
            <h3>Acciones de limpieza y reparación</h3><p>Los formularios POST usan <code>seo_clean_db_action</code>, nonce <code>seo_clean_db_nonce</code> y <code>check_admin_referer(\'seo_clean_db_action\', \'seo_clean_db_nonce\')</code>. El controlador <code>seo_clean_db_handle_post_actions()</code> despacha a <code>seo_clean_db_handle_data_error_action()</code> o <code>seo_clean_db_handle_database_cleanup_action()</code>. La capacidad requerida es <code>manage_options</code>.</p>
            <h3>Descargas SQL Clean Database</h3><p><code>admin_init</code> ejecuta <code>seo_clean_db_maybe_export_sql()</code> cuando recibe <code>seo_clean_db_export=full|seo</code> y valida el nonce <code>seo_clean_db_export_nonce</code>. <code>seo_clean_db_stream_sql_export()</code> envía el SQL por streaming directamente a la respuesta.</p>
            <h3>Exportación SEO Data Table</h3><p>Los hooks <code>admin_post_tcdb_legacy_data_export_table_csv</code>, <code>admin_post_tcdb_legacy_data_export_table_sql</code> y <code>admin_post_tcdb_legacy_data_export_all</code> exigen permisos y nonces. Las descargas CSV y SQL son incrementales; la exportación global genera un ZIP temporal.</p>
            <p>No cambie nombres de acciones, nonces ni parámetros URL sin mantener compatibilidad: afectan a formularios, descargas, filtros y favoritos ya existentes.</p>
        ');
        self::section( 'modelo', '8. Datos, SQL y límites', '
            <p>El plugin utiliza el objeto global <code>$wpdb</code>, el prefijo de instalación y detecta tablas internamente, sin crear nuevas tablas propias al visualizar el manual.</p>
            <h3>Tablas principales</h3><p><code>{prefix}seo_nodes</code> representa objetos/roles SEO y <code>{prefix}seo_relations</code> representa relaciones. Las tablas de categorías de productos, posts y términos proceden de WordPress/WooCommerce. El explorador selecciona tablas existentes cuyo nombre comienza por <code>{prefix}seo_</code>.</p>
            <h3>Consultas y recursos</h3><p>La exploración usa recuentos y consultas SELECT con filtros, LIMIT/OFFSET y orden validado por columnas detectadas. Los backups SQL recorren tablas en bloques; la exportación ZIP escribe archivos temporales. Las operaciones de limpieza son manuales y síncronas; <strong>no hay cola asíncrona ni control dinámico de CPU</strong>.</p>
            <p>Las reparaciones de relaciones/nodos emplean transacciones locales y borrados en fragmentos SQL. Esos fragmentos no implican un límite total de 100 registros. Algunas eliminaciones genéricas se ejecutan con consultas SQL sobre tablas de WordPress y requieren especial cuidado y backups.</p>
            <h3>Seguridad y privacidad</h3><p>Revisar siempre <code>manage_options</code>, nonces, allowlists de tablas/columnas, sanitización de filtros, escaping HTML y acceso a archivos temporales. Los backups pueden contener datos personales; no guardarlos en rutas públicas. Para grandes tablas, medir memoria, tiempo y carga en staging antes de producción.</p>
        ');
        self::section( 'desarrollo', '9. Evolución, compatibilidad y pruebas', '
            <h3>Cómo incorporar una mejora</h3><ol>
            <li>Inventariar primero las capacidades existentes y su llamada real en la interfaz.</li>
            <li>Añadir o modificar una función sin eliminar botones, rutas, datos ni exportaciones existentes.</li>
            <li>Conservar los nombres públicos de pestañas/parámetros/hook o proporcionar una redirección compatible.</li>
            <li>Actualizar este manual en la misma PR que modifique una funcionalidad.</li>
            <li>Probar la operación con datos representativos en WordPress y WooCommerce de Staging, además de pruebas automatizadas.</li></ol>
            <h3>Pruebas disponibles</h3><p>Ejecutar <code>php taxocommerce-database/tests/legacy-clean-db-smoke.php</code>, <code>legacy-data-table-smoke.php</code> y <code>menu-smoke.php alone|core</code>. El workflow <code>.github/workflows/taxocommerce-database.yml</code> ejecuta comprobaciones con PHP 7.4 y 8.3. PHP lint, Plugin Check y tests smoke no sustituyen las pruebas de las operaciones reales sobre MySQL.</p>
            <h3>Alcance fuera del plugin</h3><p>No integra Data Layer ni histórico de reversión, no instala un servicio de backup programado, no restaura archivos SQL automáticamente y no gestiona las bases de datos de los demás plugins. Para recuperar datos se debe validar y restaurar una copia SQL usando herramientas de administración adecuadas.</p>
        ');
        echo '</div>';
    }
}
