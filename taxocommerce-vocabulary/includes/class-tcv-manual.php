<?php
/** In-plugin user and technical documentation for the current Vocabulary service. */
defined( 'ABSPATH' ) || exit;

final class TCV_Manual {
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar este manual.', 'taxocommerce-vocabulary' ) );
        }
        ?>
        <div class="tcv-panel tcv-manual">
            <h2>Manual de TaxoCommerce Vocabulary <small>v<?php echo esc_html( TAXOCOMMERCE_VOCABULARY_VERSION ); ?></small></h2>
            <p>Dos guías integradas: uso de las pantallas y mantenimiento técnico de este plugin independiente. No se realizan operaciones por abrir el manual.</p>
            <p><a href="#tcv-user-guide">Manual de usuario</a> · <a href="#tcv-tech-guide">Manual técnico</a></p>

            <section id="tcv-user-guide">
                <h2>1. Manual de usuario</h2>
                <h3>1.1 Para qué sirve Vocabulary</h3>
                <p>Vocabulary mantiene el vocabulario semántico común, los atributos técnicos canónicos y sus relaciones con productos y categorías de WooCommerce. Los cinco grupos del clasificador son TIPO, ROL, APLICACIÓN, PLATAFORMA y SUBTIPO. Una <strong>propuesta</strong> de clasificación no es una asignación automática.</p>
                <h3>1.2 Acceso y estructura</h3>
                <p>En WordPress, abre <strong>TaxoCommerce Core → Vocabulary</strong> si Core está instalado, o el menú propio <strong>Vocabulary</strong> en caso contrario. Las pestañas existentes siguen siendo Etiquetas, Atributos, Clasificador, Importar / Exportar y Control; se añaden Auto Test y Manual.</p>
                <h3>1.3 Etiquetas: altas y diccionario</h3>
                <p>Consulta los contadores de términos activos por grupo. Para añadir una etiqueta, escoge TIPO, ROL, APLICACIÓN, PLATAFORMA o SUBTIPO, introduce el nombre visible y pulsa <strong>Dar de alta</strong>. La tabla de diccionario muestra grupo, nombre, slug, origen y estado; por rendimiento presenta <strong>hasta 300 filas</strong>. No interpreta el listado como un inventario global completo.</p>
                <h3>1.4 Atributos técnicos</h3>
                <p>La pestaña Atributos permite consultar definiciones canónicas, tipo, unidad, número de términos, aliases y estado. Estos atributos <strong>no se convierten automáticamente</strong> en atributos nativos de WooCommerce.</p>
                <h3>1.5 Clasificador</h3>
                <p>Selecciona búsqueda, categoría, cobertura, prioridad y un límite de 10, 25, 50 o 100 filas. <strong>Analizar filtro</strong> examina un conjunto acotado de productos y registra propuestas, estado y confianza. La pantalla permite descargar el JSON del último análisis. También dispone de análisis puntual por ID de categoría y una tabla de propuestas recientes.</p>
                <p>Las prioridades ayudan a localizar grupos ausentes. Revisa cada propuesta y sus datos de origen antes de tomar decisiones editoriales: el clasificador no modifica por sí solo los valores canónicos de productos ni categorías.</p>
                <h3>1.6 Importar / Exportar: CSV</h3>
                <p><strong>Etiquetas:</strong> exporta/importa términos de los cinco grupos y correspondencias TIPO → ROL. <strong>Atributos:</strong> intercambia definiciones, términos controlados y aliases, pero no valores ya asignados a productos. Descarga primero una copia de referencia y revisa el formato de columnas antes de importar.</p>
                <h3>1.7 Catálogo portable: JSON</h3>
                <p>El JSON portable puede incluir maestros, semántica de productos, semántica de categorías, atributos asignados a productos y etiquetas WooCommerce de productos, según las casillas seleccionadas. No es un backup completo de WordPress: <strong>no incluye pedidos, usuarios, imágenes ni todos los datos de productos</strong>. Los IDs numéricos son informativos; la importación intenta identificar los objetos por claves portables.</p>
                <p>Antes de importar: (1) exporta una copia, (2) selecciona <strong>MERGE</strong> para incorporar o actualizar sin retirar asignaciones locales, o <strong>MIRROR</strong> para igualar las asignaciones de los objetos incluidos, (3) pulsa <strong>Simular importación</strong>, revisa conflictos y objetos no resueltos, (4) sube de nuevo el mismo archivo con el mismo modo y pulsa <strong>Aplicar archivo simulado</strong>. MIRROR puede reemplazar asignaciones existentes; <strong>no borra maestros canónicos ausentes del archivo</strong>.</p>
                <h3>1.8 Control de integridad</h3>
                <p>La pestaña Control informa si existen las tablas y cuántas filas contienen. Estos recuentos <strong>no equivalen a una auditoría de calidad</strong> de todas las relaciones ni demuestran que una importación sea recuperable.</p>
                <h3>1.9 Auto Test</h3>
                <p>Pulsa <strong>Ejecutar Auto Test</strong> para comprobar arranque, rutas, conexión MySQL, tablas, columnas, lecturas limitadas, servicios del clasificador e intercambio de datos. El informe indica Correcto, Error o No ejecutado; conserva dos ejecuciones por usuario durante 30 días para comparar antes y después de una actualización.</p>
                <p>Una tabla existente pero vacía no suspende la prueba. Las operaciones que modifican el catálogo o generan descargas reales aparecen como <strong>No ejecutado</strong> deliberadamente. Si faltan tablas o columnas, no intentes corregirlas desde Auto Test: realiza una copia de seguridad y revisa la instalación.</p>
                <h3>1.10 Secuencia recomendada</h3>
                <p>Comprueba Control y Auto Test; exporta primero una copia portable; revisa los grupos de Etiquetas y Atributos; filtra y analiza pocos productos; examina las propuestas; utiliza la simulación obligatoria antes de importar. Repite Auto Test después de actualizar el plugin.</p>
            </section>

            <section id="tcv-tech-guide">
                <h2>2. Manual técnico</h2>
                <h3>2.1 Componentes y arranque</h3>
                <p><code>taxocommerce-vocabulary.php</code> declara la versión, carga los componentes y registra la instalación de esquema mediante <code>register_activation_hook</code>. En <code>plugins_loaded</code> se ejecuta <code>TCV_Schema::maybe_upgrade()</code>, se registran <code>TCV_IO</code> y <code>TCV_Portable</code> y, solo en administración, <code>TCV_Admin</code>. El módulo se anuncia a Core mediante <code>taxocommerce_core_register_modules</code>.</p>
                <p><code>includes/class-tcv-schema.php</code> gestiona tablas; <code>class-tcv-classifier.php</code> produce propuestas; <code>class-tcv-io.php</code> procesa CSV; <code>class-tcv-portable.php</code> intercambia JSON; <code>class-tcv-admin.php</code> renderiza las pestañas y acciones. <code>class-tcv-auto-test.php</code> y <code>class-tcv-manual.php</code> añaden los dos paneles nuevos.</p>
                <h3>2.2 Modelo de datos existente</h3>
                <div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Clave en TCV_Schema::tables()</th><th>Tabla (prefijo WordPress variable)</th><th>Responsabilidad</th></tr></thead><tbody>
                <?php
                $tables = array(
                    'vocabulary'=>'seo_vocabulary · términos semánticos',
                    'objects'=>'seo_object_vocabulary · asociaciones a objetos',
                    'type_role'=>'seo_type_role_map · correspondencia TIPO/ROL',
                    'attributes'=>'sql_atributos · definiciones técnicas',
                    'terms'=>'sql_atributos_terminos · términos técnicos',
                    'aliases'=>'sql_atributos_aliases · equivalencias/aliases',
                    'product_att'=>'sql_product_atributos · valores asociados a productos',
                    'proposals'=>'seo_classifier_proposals · propuestas de productos',
                    'cat_proposals'=>'seo_classifier_category_proposals · propuestas de categorías',
                );
                foreach ( $tables as $key => $description ) {
                    echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( $description ) . '</td><td>Tabla existente del esquema Vocabulary</td></tr>';
                }
                ?>
                </tbody></table></div>
                <p>Se reutilizan los nombres históricos y el prefijo de la instalación. La versión de esquema <code>TCV_Schema::DB_VERSION</code> es independiente de la versión publicada del plugin. No se han añadido tablas para el Auto Test ni para el Manual.</p>
                <h3>2.3 Contratos del clasificador</h3>
                <p><code>TCV_Classifier::GROUPS</code> establece los cinco grupos; <code>sanitize_filters()</code> normaliza búsqueda, categoría, cobertura, prioridad y máximo de filas. <code>analyze_filtered_products()</code>, <code>analyze_product()</code> y <code>analyze_category()</code> registran propuestas, sin aplicar asignaciones de forma automática. El último informe está en la opción <code>taxocommerce_vocabulary_classifier_last_report</code>.</p>
                <h3>2.4 Rutas y seguridad</h3>
                <p>La interfaz de administración usa la capacidad <code>manage_options</code>; determinados controladores también aceptan <code>manage_woocommerce</code>. Las acciones <code>admin_post_tcv_*</code> validan sus nonces (consultar cada controlador antes de modificar permisos): <code>tcv_create_term</code>, <code>tcv_analyze_filtered</code>, <code>tcv_analyze_product</code>, <code>tcv_analyze_category</code>, <code>tcv_export_classifier_json</code>, <code>tcv_export_labels</code>, <code>tcv_export_attributes</code>, <code>tcv_import</code>, <code>tcv_export_portable_json</code> y <code>tcv_import_portable_json</code>. Los datos se validan antes de acceder a consultas y archivos.</p>
                <h3>2.5 Importaciones y portabilidad</h3>
                <p>La clase <code>TCV_Portable</code> define el esquema <code>taxocommerce_vocabulary_portable</code>, verifica formato y huella del documento de simulación y resuelve identidades de productos/categorías según el destino. Las modalidades MERGE y MIRROR tienen semánticas distintas: no se deben fusionar ni omitir la simulación. La importación de maestros CSV y el JSON portable tienen rutas separadas. Se recomienda respaldar el catálogo y ensayar importaciones con una instalación STAGING.</p>
                <h3>2.6 Auto Test: alcance y persistencia</h3>
                <p>El endpoint <code>admin_post_tcv_run_auto_test</code> exige <code>manage_options</code> y nonce <code>tcv_auto_nonce</code>. Los resultados se guardan como transitorios por usuario con clave <code>tcv_auto_test_{user_id}</code>, durante 30 días; solo retiene las dos últimas ejecuciones. La batería realiza SELECT y consultas acotadas, sin <code>dbDelta</code>, INSERT, UPDATE, DELETE, envío de archivos ni descargas. Las tablas ausentes se señalan como Error; las lecturas dependientes se marcan No ejecutado cuando falta su tabla.</p>
                <h3>2.7 Límites y pruebas</h3>
                <p>Un resultado Correcto del Auto Test significa que una funcionalidad está disponible y responde, no que las etiquetas sean adecuadas ni que MIRROR sea inocuo para todos los catálogos. No valida integridad transaccional de una importación real ni sustituye las pruebas funcionales con datos de staging.</p>
                <p>La validación automatizada de <code>.github/workflows/ci-taxocommerce-vocabulary.yml</code> comprueba sintaxis PHP 7.4/8.4, almacenamiento canónico, menús, Auto Test y manual. Antes de publicar: contrastar <code>Version</code> con <code>Stable tag</code>, construir el ZIP sin <code>tests/</code> ni artefactos de despliegue y ejecutar WordPress Plugin Check sobre ese paquete.</p>
            </section>
        </div>
        <?php
    }
}
