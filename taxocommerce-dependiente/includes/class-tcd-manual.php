<?php
/**
 * Read-only documentation for TaxoCommerce Dependiente (Sales Assistant).
 *
 * The User Guide is deliberately separate from learning, imports, backups
 * and workers. Rendering either manual never changes persisted knowledge.
 *
 * @package TaxoCommerceDependiente
 */
defined( 'ABSPATH' ) || exit;

final class TCD_Manual {

    /** Enqueue documentation styles only for the existing manual tab. */
    public static function boot() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page navigation.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
        $tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( 'taxocommerce-dependiente' !== $page || 'user-guide' !== $tab ) {
            return;
        }
        wp_enqueue_style(
            'tcd-manual',
            plugins_url( 'assets/manual.css', TAXOCOMMERCE_DEPENDIENTE_FILE ),
            array(),
            TAXOCOMMERCE_DEPENDIENTE_VERSION
        );
    }

    private static function url( $manual = 'user' ) {
        return add_query_arg(
            array(
                'page'   => 'taxocommerce-dependiente',
                'tab'    => 'user-guide',
                'manual' => 'technical' === $manual ? 'technical' : 'user',
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function other_tab( $tab ) {
        return add_query_arg(
            array(
                'page' => 'taxocommerce-dependiente',
                'tab'  => sanitize_key( $tab ),
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function sections( $manual ) {
        if ( 'technical' === $manual ) {
            return array(
                'overview'      => __( 'Requisitos y alcance', 'taxocommerce-dependiente' ),
                'components'    => __( 'Arquitectura y componentes', 'taxocommerce-dependiente' ),
                'data'          => __( 'Tablas y persistencia', 'taxocommerce-dependiente' ),
                'learning'      => __( 'Pipeline L1-L10', 'taxocommerce-dependiente' ),
                'interpreter'   => __( 'Intérprete M11', 'taxocommerce-dependiente' ),
                'search'        => __( 'Búsqueda y API', 'taxocommerce-dependiente' ),
                'feedback'      => __( 'Feedback y M12', 'taxocommerce-dependiente' ),
                'performance'   => __( 'Lotes, CPU y cron', 'taxocommerce-dependiente' ),
                'backup'        => __( 'Transferencia y backup', 'taxocommerce-dependiente' ),
                'security'      => __( 'Seguridad y privacidad', 'taxocommerce-dependiente' ),
                'validation'    => __( 'Pruebas y despliegues', 'taxocommerce-dependiente' ),
            );
        }
        return array(
            'what'       => __( 'Qué es Dependiente', 'taxocommerce-dependiente' ),
            'start'      => __( 'Primeros pasos', 'taxocommerce-dependiente' ),
            'learning'   => __( 'Aprendizaje y controles', 'taxocommerce-dependiente' ),
            'lessons'    => __( 'Lecciones L1-L10', 'taxocommerce-dependiente' ),
            'interpreter'=> __( 'Intérprete y mantenimiento', 'taxocommerce-dependiente' ),
            'quality'    => __( 'Resultados y correcciones', 'taxocommerce-dependiente' ),
            'store'      => __( 'Buscador en la tienda', 'taxocommerce-dependiente' ),
            'knowledge'  => __( 'Importar y exportar', 'taxocommerce-dependiente' ),
            'backup'     => __( 'Copias y restauración', 'taxocommerce-dependiente' ),
            'diagnostic' => __( 'Log y Auto Test', 'taxocommerce-dependiente' ),
            'questions'  => __( 'Preguntas habituales', 'taxocommerce-dependiente' ),
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permiso para consultar el manual de Dependiente.', 'taxocommerce-dependiente' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manual selection.
        $requested = isset( $_GET['manual'] ) && is_string( $_GET['manual'] ) ? sanitize_key( wp_unslash( $_GET['manual'] ) ) : 'user';
        $manual = 'technical' === $requested ? 'technical' : 'user';
        $sections = self::sections( $manual );
        $prefix = 'technical' === $manual ? 'tcd-tech-' : 'tcd-user-';
        ?>
        <section class="tcd-manual" aria-labelledby="tcd-manual-title">
            <header class="tcd-manual__header">
                <h2 id="tcd-manual-title"><?php esc_html_e( 'User Guide', 'taxocommerce-dependiente' ); ?></h2>
                <p><?php esc_html_e( 'Documentación integrada del asistente: instrucciones para utilizarlo y referencia para mantener su funcionamiento.', 'taxocommerce-dependiente' ); ?></p>
            </header>
            <nav class="tcd-manual__switch" aria-label="<?php esc_attr_e( 'Tipo de manual', 'taxocommerce-dependiente' ); ?>">
                <a class="tcd-manual__switch-link<?php echo 'user' === $manual ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url( self::url( 'user' ) ); ?>" <?php if ( 'user' === $manual ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'User Manual', 'taxocommerce-dependiente' ); ?>
                </a>
                <a class="tcd-manual__switch-link<?php echo 'technical' === $manual ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url( self::url( 'technical' ) ); ?>" <?php if ( 'technical' === $manual ) : ?>aria-current="page"<?php endif; ?>>
                    <?php esc_html_e( 'Technical Manual', 'taxocommerce-dependiente' ); ?>
                </a>
            </nav>
            <div class="tcd-manual__layout">
                <nav class="tcd-manual__index" aria-label="<?php esc_attr_e( 'Índice del manual', 'taxocommerce-dependiente' ); ?>">
                    <h3><?php esc_html_e( 'En este manual', 'taxocommerce-dependiente' ); ?></h3>
                    <ol>
                        <?php foreach ( $sections as $anchor => $title ) : ?>
                            <li><a href="#<?php echo esc_attr( $prefix . $anchor ); ?>"><?php echo esc_html( $title ); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <article class="tcd-manual__article">
                    <?php if ( 'technical' === $manual ) : ?>
                        <?php self::render_technical(); ?>
                    <?php else : ?>
                        <?php self::render_user(); ?>
                    <?php endif; ?>
                </article>
            </div>
        </section>
        <?php
    }

    private static function render_user() {
        ?>
        <section id="tcd-user-what">
            <h2><?php esc_html_e( '1. Qué es Dependiente', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'TaxoCommerce Dependiente es un asistente de búsqueda para tiendas WooCommerce. Aprende el catálogo y su vocabulario para interpretar necesidades de los compradores y sugerir productos adecuados.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El aprendizaje no cambia los productos, precios ni descripciones. Utiliza datos existentes de WooCommerce y contenidos disponibles de WordPress, FAQs y Vocabulary. El sistema funciona localmente sin requerir un servicio externo de IA para la búsqueda habitual.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-user-start">
            <h2><?php esc_html_e( '2. Primeros pasos', 'taxocommerce-dependiente' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Activa WooCommerce y TaxoCommerce Dependiente. Core es opcional; solo agrupa el acceso a los plugins.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Abre Sales Assistant (Dependiente) y comprueba el estado, la lección actual y los recuentos.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Selecciona una prioridad de trabajo entre 1 (suave) y 5 (máxima). El valor 3 es el predeterminado.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Pulsa Iniciar o reanudar; Dependiente prepara primero la foto fija de cada lección y después aprende y evalúa.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Cuando la búsqueda esté preparada, prueba el shortcode moderno en una página de pruebas antes de sustituir el buscador público.', 'taxocommerce-dependiente' ); ?></li>
            </ol>
            <p><?php esc_html_e( 'El proceso usa tareas programadas de WordPress y puede continuar sin mantener abierta la pantalla. Si WP-Cron no se ejecuta o la web apenas recibe tráfico, la cola puede retrasarse: comprueba el Log de aprendizaje y la planificación del servidor.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-user-learning">
            <h2><?php esc_html_e( '3. Aprendizaje, prioridad y controles', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'Qué significan los botones', 'taxocommerce-dependiente' ); ?></h3>
            <ul>
                <li><strong><?php esc_html_e( 'Iniciar o reanudar:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'continúa la formación conservando el trabajo ya realizado.', 'taxocommerce-dependiente' ); ?></li>
                <li><strong><?php esc_html_e( 'Detener aprendizaje:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'impide nuevos lotes sin borrar la foto fija ni el progreso guardado.', 'taxocommerce-dependiente' ); ?></li>
                <li><strong><?php esc_html_e( 'Revisar correcciones y reevaluar:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'si una lección no supera la calidad, vuelve a comprobar los casos fallidos después de corregirlos.', 'taxocommerce-dependiente' ); ?></li>
                <li><strong><?php esc_html_e( 'Reiniciar aprendizaje completo:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'inicia un nuevo curso y borra datos de aprendizaje generados por Dependiente. No borra los productos originales, pero puede perder progreso y conocimiento generado. Utilízalo solo cuando corresponda y con copia de seguridad.', 'taxocommerce-dependiente' ); ?></li>
            </ul>
            <h3><?php esc_html_e( 'Cómo leer el progreso', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'La tabla de lecciones distingue la creación de la foto fija, los elementos aprendidos, los no aprendidos y el examen. Una foto fija sellada representa el catálogo estudiado en ese momento: no se modifica automáticamente cuando cambia el catálogo en mitad de una lección.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-user-lessons">
            <h2><?php esc_html_e( '4. Las diez lecciones (L1-L10)', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Cada lección aprende una fuente diferente. Sus resultados son independientes y deben superar los controles de calidad definidos antes de continuar.', 'taxocommerce-dependiente' ); ?></p>
            <div class="tcd-manual__table-scroll">
                <table class="tcd-manual__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Lección', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Qué aprende', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Fuente', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ( TCD_Sources::syllabus() as $number => $lesson ) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html( 'L' . absint( $number ) . ' · ' . (string) $lesson['name'] ); ?></th>
                                <td><?php echo esc_html( (string) $lesson['objective'] ); ?></td>
                                <td><?php echo esc_html( (string) $lesson['source'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="tcd-user-interpreter">
            <h2><?php esc_html_e( '5. Intérprete M11 y actualización M12', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'M11: comprensión del lenguaje', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'Después de aprender el catálogo, el Intérprete trabaja con gramática, expresiones, verbos, erratas, sinónimos y contexto. Limpia e interpreta la petición; es Dependiente quien se encarga de buscar los productos. En la pantalla principal puedes consultar el estado del curso lingüístico y desplegar sus fases.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'M12: mantenimiento y feedback', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'M12 actualiza conocimiento cuando cambian productos o contenidos y realiza revisiones incrementales. Las consultas, clics y aclaraciones pueden aportar evidencia, pero un único clic no activa una regla nueva: se exige repetición y validación.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-user-quality">
            <h2><?php esc_html_e( '6. Calidad, auditoría y correcciones', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'En la columna Calidad verás el porcentaje obtenido y el mínimo de la lección, junto con los aciertos Top 1, Top 3 y Top 8. Un resultado por debajo del umbral no equivale a que el proceso esté averiado; indica que la lección aún no ha superado el examen.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'Qué hacer si una lección no aprueba', 'taxocommerce-dependiente' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Abre JSON auditoría y CSV examen; identifica si el problema está en los datos fuente, una ambigüedad real o el propio motor de ranking.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Consulta CSV datos para revisar las anomalías detectadas en la lección.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Corrige los productos, categorías, contenidos o Vocabulary solo cuando el diagnóstico lo justifique.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Pulsa Revisar correcciones y reevaluar. Evita reiniciar las diez lecciones para solucionar un examen fallido.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Si aceptas conscientemente los errores pendientes de tu catálogo, configura el mínimo de la lección en Dependiente. Después de guardar, si el resultado anterior ya alcanza ese mínimo, usa Aceptar la lección y continuar. Los fallos y la puntuación real se conservan.', 'taxocommerce-dependiente' ); ?></li>
            </ol>
        </section>

        <section id="tcd-user-store">
            <h2><?php esc_html_e( '7. Utilizar el buscador en la tienda', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Inserta el shortcode siguiente en una página o bloque de shortcode de WordPress:', 'taxocommerce-dependiente' ); ?></p>
            <pre class="tcd-manual__code"><code>[taxocommerce_dependiente_moderno]</code></pre>
            <p><?php esc_html_e( 'El buscador interpreta una consulta, recupera productos publicados y puede solicitar hasta dos aclaraciones cerradas cuando la intención es ambigua. Si no existe confianza suficiente, no debe presentar un producto concreto como si la recomendación fuera segura.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El shortcode antiguo [taxocommerce_dependiente] puede seguir perteneciendo al plugin anterior en producción. El moderno no sustituye automáticamente al buscador anterior; comprueba primero el comportamiento en staging.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-user-knowledge">
            <h2><?php esc_html_e( '8. Conocimiento: exportar, analizar e importar', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'La pestaña Conocimiento / Importar–Exportar permite descargar conocimiento portable y fusionarlo con otra instalación. Transporta reglas y memoria reutilizable; no es una copia integral de todo el estado.', 'taxocommerce-dependiente' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Descarga el JSON de conocimiento portable si necesitas conservar o trasladar aprendizaje.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Antes de importar otro archivo, usa Analizar compatibilidad para revisar su contenido sin escribir datos.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Detén el aprendizaje, revisa el informe y utiliza Importar y fusionar solo cuando quieras incorporar esas reglas y memoria.', 'taxocommerce-dependiente' ); ?></li>
            </ol>
            <p><a href="<?php echo esc_url( self::other_tab( 'knowledge' ) ); ?>"><?php esc_html_e( 'Abrir Conocimiento / Importar–Exportar', 'taxocommerce-dependiente' ); ?></a></p>
        </section>

        <section id="tcd-user-backup">
            <h2><?php esc_html_e( '9. Backup y restauración', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Backup / Restaurar descarga una copia NDJSON de las tablas y estados propios incluidos en su manifiesto. A diferencia del conocimiento portable, restaurar sustituye las tablas y opciones cubiertas por el backup.', 'taxocommerce-dependiente' ); ?></p>
            <p><strong><?php esc_html_e( 'Antes de restaurar:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'detén el aprendizaje, descarga una copia de Dependiente y realiza una copia verificada de la base de datos de WordPress. Analiza el archivo y revisa la cobertura indicada en la pestaña; no presupongas que incluye los productos originales ni todos los datos de otros módulos.', 'taxocommerce-dependiente' ); ?></p>
            <p><a href="<?php echo esc_url( self::other_tab( 'backup' ) ); ?>"><?php esc_html_e( 'Abrir Backup / Restaurar', 'taxocommerce-dependiente' ); ?></a></p>
        </section>

        <section id="tcd-user-diagnostic">
            <h2><?php esc_html_e( '10. Log de aprendizaje y Auto Test', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'Log de aprendizaje', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'Permite seguir lotes, lecturas, progreso, bloqueos, mensajes y errores. Puedes actualizarlo, activar la actualización periódica, descargarlo o vaciarlo sin borrar el aprendizaje. El archivo de diagnóstico rota automáticamente y está limitado a 1 MiB.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'Auto Test', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'Ejecuta comprobaciones de solo lectura por grupos (arranque, datos, fuentes, calidad, intérprete, búsqueda, mantenimiento y transferencias). Distingue correctamente entre pruebas aprobadas, fallidas, omitidas y no ejecutadas. No reentrena ni restaura copias.', 'taxocommerce-dependiente' ); ?></p>
            <p><a href="<?php echo esc_url( self::other_tab( 'learning-log' ) ); ?>"><?php esc_html_e( 'Abrir Log de aprendizaje', 'taxocommerce-dependiente' ); ?></a>
            <span aria-hidden="true"> · </span>
            <a href="<?php echo esc_url( self::other_tab( 'auto-test' ) ); ?>"><?php esc_html_e( 'Abrir Auto Test', 'taxocommerce-dependiente' ); ?></a></p>
        </section>

        <section id="tcd-user-questions">
            <h2><?php esc_html_e( '11. Preguntas habituales', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( '¿Tengo que dejar abierta la pantalla?', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'No. El trabajo se programa en segundo plano, siempre que WP-Cron o el programador del servidor lo ejecuten.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( '¿Qué ocurre si una lección obtiene menos del mínimo?', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'Revisa el diagnóstico y reevalúa después de las correcciones. Si decides aceptar los errores de origen de tu catálogo, puedes ajustar el mínimo de esa lección entre 0 y 100 %. Debes confirmar expresamente la aceptación si el examen anterior ya alcanza ese mínimo. El resultado real y las incidencias continúan visibles.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( '¿Importar conocimiento es lo mismo que restaurar un backup?', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'No. Importar conocimiento fusiona datos reutilizables; restaurar un backup sustituye el estado incluido en su manifiesto.', 'taxocommerce-dependiente' ); ?></p>
        </section>
        <?php
    }

    private static function render_technical() {
        ?>
        <section id="tcd-tech-overview">
            <h2><?php esc_html_e( '1. Requisitos y alcance técnico', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Dependiente es un plugin independiente de WooCommerce. Requiere WordPress 6.5+, PHP 7.4+ y WooCommerce, según su cabecera. TaxoCommerce Core solo proporciona integración opcional en el menú de administración.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'Versión cargada:', 'taxocommerce-dependiente' ); ?> <strong><?php echo esc_html( TAXOCOMMERCE_DEPENDIENTE_VERSION ); ?></strong></p>
            <p><?php esc_html_e( 'La arquitectura distingue el aprendizaje L1–L10, la comprensión lingüística original del M11 y la actualización incremental M12. El asistente no sobrescribe los contenidos fuente durante el entrenamiento.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-components">
            <h2><?php esc_html_e( '2. Arquitectura y componentes', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'El archivo taxocommerce-dependiente.php carga las clases y arranca los servicios en plugins_loaded con prioridad 30 cuando WooCommerce está disponible. La activación crea las tablas propias y la desactivación elimina las tareas programadas indicadas por el plugin.', 'taxocommerce-dependiente' ); ?></p>
            <div class="tcd-manual__table-scroll">
                <table class="tcd-manual__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Clase', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Responsabilidad', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><code>TCD_Admin</code></th><td><?php esc_html_e( 'Pantallas de administración, estado y controles.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Academy</code></th><td><?php esc_html_e( 'Motor L1–L10, fotos fijas, colas, estados y worker.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Sources</code></th><td><?php esc_html_e( 'Lectores de productos, categorías, contenido editorial, FAQs y Vocabulary.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Intelligence</code></th><td><?php esc_html_e( 'Memoria semántica, ranking, calidad y promoción de reglas.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Load</code></th><td><?php esc_html_e( 'Prioridades 1–5, tamaño adaptativo de lotes y medidas de coste de la petición.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Original_Linguista</code></th><td><?php esc_html_e( 'Formación lingüística del Intérprete original (M11).', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Original_Interprete</code></th><td><?php esc_html_e( 'Normalización e interpretación lingüística de la consulta.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Search</code></th><td><?php esc_html_e( 'Shortcode y endpoints REST públicos.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Feedback</code></th><td><?php esc_html_e( 'Evidencia de consultas, clics y aclaraciones y su consolidación.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Maintenance</code></th><td><?php esc_html_e( 'Actualización incremental (M12).', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Knowledge_Transfer</code> / <code>TCD_Backup</code></th><td><?php esc_html_e( 'Transferencia portable y restauración del estado respaldado.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>TCD_Learning_Log</code> / <code>TCD_Auto_Test</code></th><td><?php esc_html_e( 'Trazabilidad y comprobaciones de salud de solo lectura.', 'taxocommerce-dependiente' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="tcd-tech-data">
            <h2><?php esc_html_e( '3. Persistencia, tablas y opciones', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Las tablas usan el prefijo real de WordPress ($wpdb->prefix). Los sufijos siguientes pertenecen al modelo propio de Dependiente:', 'taxocommerce-dependiente' ); ?></p>
            <div class="tcd-manual__table-scroll">
                <table class="tcd-manual__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Sufijo SQL', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Contenido', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><code>tcd_knowledge</code></th><td><?php esc_html_e( 'Conocimiento L1–L10 por fuente y estado.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_lesson_snapshots</code></th><td><?php esc_html_e( 'Foto fija de cada lección y run, firmas, recuentos y fechas.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_lesson_items</code></th><td><?php esc_html_e( 'Elementos concretos congelados y resultado de aprendizaje.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_quality_checks</code></th><td><?php esc_html_e( 'Resultados, métricas y diagnósticos de exámenes.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_portable_rules</code></th><td><?php esc_html_e( 'Reglas semánticas generadas o importadas.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_l9_memory</code></th><td><?php esc_html_e( 'Señales ponderadas de búsqueda por producto.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_legacy_lessons</code></th><td><?php esc_html_e( 'Evidencia histórica de lecciones del plugin anterior.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_feedback_events</code></th><td><?php esc_html_e( 'Eventos de consulta, clic o aclaración almacenados sin IP.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>tcd_feedback_learning</code></th><td><?php esc_html_e( 'Asociaciones consolidadas con estados de validación.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>seo_interprete_lexicon</code></th><td><?php esc_html_e( 'Expresiones y equivalencias del Intérprete original.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>seo_interprete_lexicon_evidence</code></th><td><?php esc_html_e( 'Procedencia y evidencias de cada equivalencia.', 'taxocommerce-dependiente' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <h3><?php esc_html_e( 'Estado interno', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'Entre las opciones principales están tcd_learning_state, tcd_interpreter_state, tcd_maintenance_state, tcd_work_priority y tcd_brain_id. Los metadatos y las tablas fuente de WooCommerce, seo_faq y Vocabulary pertenecen a sus respectivos sistemas.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-learning">
            <h2><?php esc_html_e( '4. Pipeline de aprendizaje L1–L10', 'taxocommerce-dependiente' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'TCD_Sources obtiene el material de la lección y TCD_Academy construye la foto fija (snapshot).', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Se sella la fuente y se aprende únicamente del material congelado, guardando estados learned y unlearned.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'TCD_Intelligence examina una muestra y calcula Top 1, Top 3, Top 8, fallos y el porcentaje de recuperación.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Las reglas generadas permanecen preparadas hasta superar el umbral configurado; un examen fallido genera diagnóstico y bloquea el avance hasta que se reevalúe o el responsable acepte expresamente un resultado que ya cumpla su nuevo mínimo.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Una reevaluación dirigida conserva el snapshot y puede releer los casos fallidos sin reiniciar las diez lecciones.', 'taxocommerce-dependiente' ); ?></li>
            </ol>
            <h3><?php esc_html_e( 'Umbrales mínimos configurados', 'taxocommerce-dependiente' ); ?></h3>
            <div class="tcd-manual__thresholds" aria-label="<?php esc_attr_e( 'Umbrales de calidad por lección', 'taxocommerce-dependiente' ); ?>">
                <?php foreach ( TCD_Intelligence::quality_thresholds() as $lesson => $threshold ) : ?>
                    <span><?php echo esc_html( 'L' . $lesson . ': ' . number_format_i18n( $threshold, 1 ) . '%' ); ?></span>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e( 'Los valores de fábrica son 94, 88, 88, 86, 85, 92, 87, 90, 88 y 90 %. El administrador configura cada mínimo desde la pantalla principal. Se guardan como opciones del plugin y solo afectan a evaluaciones futuras o a una aceptación manual explícita. La auditoría conserva nota real, casos fallidos, mínimo anterior, mínimo aceptado, fecha y usuario.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-interpreter">
            <h2><?php esc_html_e( '5. Intérprete original M11', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'El port del Intérprete original trabaja sobre un léxico con expresiones, términos canónicos, contexto, confianza y evidencias. TCD_Original_Linguista entrena y valida ese léxico en un curso por fases con estado persistente y worker independiente.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'Contrato de ejecución: TCD_Original_Interprete::interpret() depura y traduce la consulta del comprador a una petición utilizable por Dependiente. No realiza la búsqueda de productos ni formula preguntas por sí mismo. Es TCD_Intelligence y TCD_Search quien puntúa productos y decide si pedir aclaraciones.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'La prueba histórica de localizar exactamente 180 IDs de producto no constituye el control actual de comprensión lingüística y no debe emplearse para bloquear por sí sola el curso original.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-search">
            <h2><?php esc_html_e( '6. Frontend y contrato de búsqueda', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'Shortcode y endpoints REST', 'taxocommerce-dependiente' ); ?></h3>
            <p><code>[taxocommerce_dependiente_moderno]</code></p>
            <div class="tcd-manual__table-scroll">
                <table class="tcd-manual__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Método y ruta', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Función', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><code>GET /wp-json/taxocommerce-dependiente/v1/search</code></th><td><?php esc_html_e( 'Recibe q, session, depth y pistas opcionales; interpreta, devuelve decisión, posibles aclaraciones y resultados. q tiene límite de 180 caracteres.', 'taxocommerce-dependiente' ); ?></td></tr>
                        <tr><th scope="row"><code>POST /wp-json/taxocommerce-dependiente/v1/feedback</code></th><td><?php esc_html_e( 'Acepta el evento click contextualizado con sesión, consulta, producto y profundidad.', 'taxocommerce-dependiente' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <p><?php esc_html_e( 'El frontend usa las rutas REST públicas sin requerir inicio de sesión. La API limita las aclaraciones a profundidad 2. El contrato actual de búsqueda no declara un parámetro de país; no se debe asumir una segmentación internacional garantizada por este endpoint.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El alias [taxocommerce_dependiente] solo se registra para el nuevo motor cuando se cumplen las condiciones de compatibilidad: por ejemplo en staging o si no está cargada la versión antigua.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-feedback">
            <h2><?php esc_html_e( '7. Feedback y mantenimiento M12', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'TCD_Feedback registra señales con un hash de sesión y consolida evidencias sin promocionar un clic aislado. Los estados de asociación incluyen staged, validated y active; solo patrones repetidos con suficiente apoyo pueden influir en el runtime y materializar reglas portables.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'Fuentes incrementales', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'TCD_Maintenance responde a cambios de producto, categoría, posts y páginas y revisa periódicamente FAQs, Vocabulary, asignaciones semánticas y feedback. El mantenimiento se programa dos veces al día cuando se activa, además de reaccionar a hooks de contenido.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'Los eventos de feedback tienen retención configurada de 180 días. El administrador debe comprobar el aviso de privacidad de la tienda y la política de conservación antes de publicar el buscador.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-performance">
            <h2><?php esc_html_e( '8. Carga de procesador, lotes y cron', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'TCD_Load gestiona prioridades 1–5 y adapta el lote según la duración, memoria y CPU consumida por la petición PHP. Esa medida no equivale al porcentaje total de CPU disponible en el alojamiento. La prioridad 5 eleva el techo adaptativo hasta 600 elementos por lote, pero no garantiza ese tamaño en todas las fases.', 'taxocommerce-dependiente' ); ?></p>
            <div class="tcd-manual__table-scroll">
                <table class="tcd-manual__table">
                    <thead><tr><th scope="col"><?php esc_html_e( 'Tarea', 'taxocommerce-dependiente' ); ?></th><th scope="col"><?php esc_html_e( 'Hook', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><?php esc_html_e( 'Aprendizaje L1–L10', 'taxocommerce-dependiente' ); ?></th><td><code>tcd_learning_tick</code></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'Lingüista original', 'taxocommerce-dependiente' ); ?></th><td><code>tcd_original_linguista_worker</code></td></tr>
                        <tr><th scope="row"><?php esc_html_e( 'Mantenimiento M12', 'taxocommerce-dependiente' ); ?></th><td><code>tcd_dependiente_maintenance</code></td></tr>
                    </tbody>
                </table>
            </div>
            <p><?php esc_html_e( 'TCD_Academy conserva un lock de ejecución y un watchdog que reprograma ticks desde administración si detecta inactividad. Cuando está disponible el supervisor de procesos, puede solicitarle trabajo; sin él utiliza la cola de WordPress. No presupongas que abrir o cerrar el navegador determina la ejecución.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El log activo se guarda en uploads/taxocommerce-dependiente-private/learning.log, con archivos de protección de acceso web; rota a un máximo de 1 MiB. El archivo es diagnóstico y no reemplaza los snapshots ni un backup.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-backup">
            <h2><?php esc_html_e( '9. Exportación, importación y cobertura de backup', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'Conocimiento portable frente a restauración', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'TCD_Knowledge_Transfer exporta JSON compatible con el esquema moderno y acepta el formato antiguo seo_dependiente_knowledge v1. Analizar no escribe; importar fusiona reglas y memoria, conservando el conocimiento local ante conflictos.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'TCD_Backup exporta NDJSON por streaming y permite analizar un archivo antes de restaurarlo. La restauración requiere autorización, confirmación y aprendizaje detenido y sustituye las tablas y opciones cubiertas por su manifiesto.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'Límites de cobertura que hay que comprobar', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'El manifiesto TCD_Backup::tables() incluye las nueve tablas tcd_ de aprendizaje, calidad, reglas y feedback. No incluye los contenidos fuente de WordPress/WooCommerce. Las tablas seo_interprete_lexicon y seo_interprete_lexicon_evidence utilizadas por el Intérprete original no aparecen en ese manifiesto: para una recuperación integral del sitio realiza también una copia verificada de MySQL y de los archivos necesarios.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El backup excluye logs, locks, transitorios, cron y ajustes adaptativos temporales. Verifica que los identificadores de producto y Vocabulary de origen y destino correspondan antes de restaurar en otra instalación.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-security">
            <h2><?php esc_html_e( '10. Seguridad, permisos y privacidad', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'El menú administrativo exige manage_woocommerce; las operaciones protegidas admiten, según el controlador, manage_woocommerce o manage_options y nonces de WordPress. La documentación no contiene formularios que escriban estado.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'Las rutas REST públicas procesan consultas sin autenticación y deben quedar sujetas a vigilancia y protección antiautomación razonables. El feedback guarda hashes de sesión en lugar de direcciones IP, nombres o correos, pero las frases buscadas siguen siendo contenido aportado por visitantes y deben tratarse conforme a la política de privacidad.', 'taxocommerce-dependiente' ); ?></p>
            <p><?php esc_html_e( 'El sistema aprende exclusivamente de las fuentes locales que pueda consultar y no requiere inferencia externa por defecto. No incluyas credenciales ni información personal en exportaciones compartidas o tickets.', 'taxocommerce-dependiente' ); ?></p>
        </section>

        <section id="tcd-tech-validation">
            <h2><?php esc_html_e( '11. Auto Test, regresiones y despliegue', 'taxocommerce-dependiente' ); ?></h2>
            <h3><?php esc_html_e( 'Comprobaciones de ejecución', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'TCD_Auto_Test contiene 51 comprobaciones agrupadas en diez bloques, con estados pass, fail, skip y not_run. Los informes se conservan por usuario durante un día mediante transients. La prueba de escrituras reales (UAT) se marca como no ejecutada; Auto Test no lanza reentrenamientos ni restauraciones.', 'taxocommerce-dependiente' ); ?></p>
            <h3><?php esc_html_e( 'Pruebas del repositorio', 'taxocommerce-dependiente' ); ?></h3>
            <p><?php esc_html_e( 'El workflow .github/workflows/ci-taxocommerce-dependiente.yml comprueba sintaxis PHP y ejecuta regresiones de aprendizaje, L9, calidad, Intérprete original, compatibilidad FAQ y Auto Test en PHP 7.4 y 8.4.', 'taxocommerce-dependiente' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Modifica el código en una rama y revisa los cambios funcionales y de versión.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Ejecuta la batería de regresión y revisa el estado de la pull request.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Integra en staging, comprueba que el despliegue se ha realizado y ejecuta pruebas reales allí.', 'taxocommerce-dependiente' ); ?></li>
                <li><?php esc_html_e( 'Despliega a producción únicamente después de la validación y con una copia recuperable.', 'taxocommerce-dependiente' ); ?></li>
            </ol>
            <p><?php esc_html_e( 'La fusión en GitHub no demuestra por sí sola que el servidor WordPress haya sido actualizado. Verifica la versión efectiva, los logs y el funcionamiento de la búsqueda pública.', 'taxocommerce-dependiente' ); ?></p>
        </section>
        <?php
    }
}
