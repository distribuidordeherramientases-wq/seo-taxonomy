<?php
/**
 * Complete functional backup / restore for TaxoCommerce Dependiente.
 *
 * This is intentionally separate from portable knowledge transfer:
 * - knowledge transfer merges reusable semantic knowledge;
 * - backup restores the complete persistent state owned by Dependiente.
 *
 * The backup is streamed as NDJSON so large snapshots do not need to be held
 * in PHP memory. It preserves table primary keys because lesson items and
 * quality checks reference snapshot IDs.
 *
 * @package TaxoCommerceDependiente
 */

defined( 'ABSPATH' ) || exit;

final class TCD_Backup {
    const SCHEMA_NAME = 'taxocommerce_dependiente_backup';
    const SCHEMA_VERSION = 1;
    const LAST_REPORT = 'tcd_backup_last_report';
    const MAX_BYTES = 536870912; // 512 MiB.

    public static function boot() {
        add_action( 'admin_post_tcd_backup_export', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_tcd_backup_analyze', array( __CLASS__, 'analyze_upload' ) );
        add_action( 'admin_post_tcd_backup_restore', array( __CLASS__, 'restore_upload' ) );
    }

    public static function tables() {
        return array(
            'learning_knowledge' => array(
                'table' => TCD_Academy::knowledge_table(),
                'label' => 'Conocimiento moderno L1-L10',
                'description' => 'Resultados aprendidos por lección, estado y contexto auditable.',
            ),
            'learning_snapshots' => array(
                'table' => TCD_Academy::snapshots_table(),
                'label' => 'Fotos fijas de lecciones',
                'description' => 'Snapshots sellados que definen exactamente qué estudió cada lección.',
            ),
            'learning_items' => array(
                'table' => TCD_Academy::items_table(),
                'label' => 'Material exacto estudiado',
                'description' => 'Elementos incluidos en cada snapshot y su estado de aprendizaje.',
            ),
            'quality_checks' => array(
                'table' => TCD_Intelligence::quality_table(),
                'label' => 'Quality gates y auditoría',
                'description' => 'Resultados Top1/Top3/Top8, umbrales, fallos y diagnóstico por lección.',
            ),
            'portable_rules' => array(
                'table' => TCD_Knowledge_Transfer::rules_table(),
                'label' => 'Reglas semánticas',
                'description' => 'Reglas portables activas, importadas y generadas por el aprendizaje moderno.',
            ),
            'l9_memory' => array(
                'table' => TCD_Knowledge_Transfer::l9_table(),
                'label' => 'Memoria L9',
                'description' => 'Señales lingüísticas ponderadas que relacionan necesidades con productos.',
            ),
            'legacy_lessons' => array(
                'table' => TCD_Knowledge_Transfer::legacy_lessons_table(),
                'label' => 'Histórico antiguo',
                'description' => 'Evidencia conservada de lecciones completadas en el Dependiente anterior.',
            ),
            'feedback_events' => array(
                'table' => TCD_Feedback::events_table(),
                'label' => 'Feedback anónimo bruto',
                'description' => 'Búsquedas, clics y aclaraciones retenidos por Dependiente. Incluye texto de consulta y hashes de sesión, nunca IP, nombre ni correo.',
            ),
            'feedback_learning' => array(
                'table' => TCD_Feedback::learning_table(),
                'label' => 'Feedback consolidado',
                'description' => 'Evidencia agregada staged/validated/active que alimenta M11/M12.',
            ),
        );
    }

    public static function options() {
        return array(
            TCD_Academy::STATE => array(
                'label' => 'Estado del aprendizaje',
                'description' => 'Run, lección, fase, cursores y progreso L1-L10.',
            ),
            TCD_Intelligence::INTERPRETER_STATE => array(
                'label' => 'Estado del Intérprete M11',
                'description' => 'Estado y métricas de la validación del Intérprete.',
            ),
            TCD_Maintenance::STATE => array(
                'label' => 'Estado de mantenimiento M12',
                'description' => 'Cursores y contadores de actualización incremental.',
            ),
            TCD_Load::PRIORITY_OPTION => array(
                'label' => 'Prioridad de trabajo',
                'description' => 'Prioridad 1-5 elegida por el administrador.',
            ),
            TCD_Knowledge_Transfer::BRAIN_OPTION => array(
                'label' => 'Brain ID',
                'description' => 'Identidad persistente del cerebro de Dependiente.',
            ),
        );
    }

    public static function excluded_items() {
        return array(
            array(
                'item' => 'learning.log y tcd_learning_log',
                'reason' => 'Diagnóstico temporal. No es conocimiento ni estado necesario para restaurar Dependiente.',
            ),
            array(
                'item' => 'locks, stop markers y transitorios',
                'reason' => 'Protecciones de ejecución local. Se regeneran y nunca deben trasladarse entre instalaciones.',
            ),
            array(
                'item' => 'cron / Action Scheduler',
                'reason' => 'Planificación runtime de WordPress. Se reconstruye después de restaurar.',
            ),
            array(
                'item' => 'tcd_adaptive_batch y tcd_load_last_sample',
                'reason' => 'Ajuste automático de carga y última muestra de rendimiento; se recalculan.',
            ),
            array(
                'item' => 'versiones internas de esquema y últimos informes de import/export',
                'reason' => 'Metadatos técnicos de la instalación destino; los instaladores los gestionan.',
            ),
            array(
                'item' => 'productos, categorías, posts, páginas, FAQs y Vocabulary',
                'reason' => 'Son fuentes externas a Dependiente y pertenecen a WooCommerce/WordPress u otros módulos.',
            ),
        );
    }

    public static function render_admin_section() {
        $counts = self::current_counts();
        $report = self::last_report();
        $state = TCD_Academy::state();
        $busy = 'running' === (string) ( $state['status'] ?? '' );
        ?>
        <section id="tcd-backup" style="padding:18px;background:#fff;border:1px solid #c3c4c7">
            <h2 style="margin-top:0"><?php esc_html_e( 'Backup / Restaurar Dependiente', 'taxocommerce-dependiente' ); ?></h2>
            <p><?php esc_html_e( 'Copia funcional completa del estado persistente que pertenece a Dependiente. Sirve como backup, traslado WordPress → WordPress o sustituto del antiguo clonador PRO → STAGING. La restauración conserva los IDs internos para no romper la relación entre snapshots, material estudiado y quality gates.', 'taxocommerce-dependiente' ); ?></p>
            <div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Diferencia con Conocimiento / Importar–Exportar:', 'taxocommerce-dependiente' ); ?></strong> <?php esc_html_e( 'el conocimiento portable fusiona reglas y memoria reutilizable; este backup restaura el estado completo de Dependiente y sustituye sus tablas propias por las de la copia.', 'taxocommerce-dependiente' ); ?></p></div>

            <h3><?php esc_html_e( 'Qué queda protegido por el backup', 'taxocommerce-dependiente' ); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Protegido', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Bloque', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Filas actuales', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Qué recupera', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( self::tables() as $key => $meta ) : ?>
                    <tr>
                        <td><strong>✓</strong></td>
                        <td><strong><?php echo esc_html( $meta['label'] ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $counts['tables'][ $key ] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( $meta['description'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ( self::options() as $name => $meta ) : ?>
                    <tr>
                        <td><strong>✓</strong></td>
                        <td><strong><?php echo esc_html( $meta['label'] ); ?></strong><br><code><?php echo esc_html( $name ); ?></code></td>
                        <td><?php echo empty( $counts['options'][ $name ] ) ? '—' : esc_html__( 'Sí', 'taxocommerce-dependiente' ); ?></td>
                        <td><?php echo esc_html( $meta['description'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin:16px 0">
                <a class="button button-primary" href="<?php echo esc_url( self::export_url() ); ?>"><?php esc_html_e( 'Descargar backup completo', 'taxocommerce-dependiente' ); ?></a>
                <span class="description"><?php esc_html_e( 'Formato streaming NDJSON; no necesita cargar todo el backup en memoria.', 'taxocommerce-dependiente' ); ?></span>
            </p>

            <details style="margin:14px 0">
                <summary><strong><?php esc_html_e( 'Qué NO se incluye y por qué', 'taxocommerce-dependiente' ); ?></strong></summary>
                <table class="widefat striped" style="margin-top:10px">
                    <thead><tr><th><?php esc_html_e( 'No incluido', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Motivo', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( self::excluded_items() as $row ) : ?>
                        <tr><td><code><?php echo esc_html( $row['item'] ); ?></code></td><td><?php echo esc_html( $row['reason'] ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;margin-top:18px">
                <div>
                    <h3><?php esc_html_e( '1. Analizar backup', 'taxocommerce-dependiente' ); ?></h3>
                    <p><?php esc_html_e( 'Lee toda la copia sin escribir nada y muestra exactamente las tablas, opciones y número de filas que se restaurarían.', 'taxocommerce-dependiente' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="tcd_backup_analyze">
                        <?php wp_nonce_field( 'tcd_backup_analyze' ); ?>
                        <p><input type="file" name="backup_file" accept=".jsonl,.ndjson,application/x-ndjson,application/json" required></p>
                        <?php submit_button( __( 'Analizar backup sin modificar datos', 'taxocommerce-dependiente' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>
                <div>
                    <h3><?php esc_html_e( '2. Restaurar copia exacta', 'taxocommerce-dependiente' ); ?></h3>
                    <p><?php esc_html_e( 'Sustituye únicamente las tablas y estados propios de Dependiente. No fusiona: para fusionar conocimiento utiliza la pestaña de conocimiento portable.', 'taxocommerce-dependiente' ); ?></p>
                    <?php if ( $busy ) : ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e( 'Dependiente está aprendiendo. Detén el proceso antes de restaurar un backup.', 'taxocommerce-dependiente' ); ?></p></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="tcd_backup_restore">
                        <?php wp_nonce_field( 'tcd_backup_restore' ); ?>
                        <p><input type="file" name="backup_file" accept=".jsonl,.ndjson,application/x-ndjson,application/json" required <?php disabled( $busy ); ?>></p>
                        <p><label><input type="checkbox" name="confirm_restore" value="1" required <?php disabled( $busy ); ?>> <strong><?php esc_html_e( 'Confirmo que quiero sustituir el estado actual de Dependiente por el contenido de esta copia.', 'taxocommerce-dependiente' ); ?></strong></label></p>
                        <p class="description"><?php esc_html_e( 'La restauración borra primero las 9 tablas propias incluidas en el backup y las repuebla conservando sus IDs. No toca datos fuente de WooCommerce/WordPress.', 'taxocommerce-dependiente' ); ?></p>
                        <?php submit_button( __( 'Restaurar backup completo', 'taxocommerce-dependiente' ), 'primary', 'submit', false, $busy ? array( 'disabled' => 'disabled' ) : array() ); ?>
                    </form>
                </div>
            </div>

            <?php if ( $report ) : ?>
                <hr style="margin:24px 0">
                <h3><?php esc_html_e( 'Último análisis / restauración', 'taxocommerce-dependiente' ); ?></h3>
                <?php if ( ! empty( $report['error'] ) ) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html( $report['error'] ); ?></p></div>
                <?php else : ?>
                    <p>
                        <strong><?php esc_html_e( 'Formato:', 'taxocommerce-dependiente' ); ?></strong> <code><?php echo esc_html( (string) ( $report['schema'] ?? '' ) ); ?></code>
                        · <strong><?php esc_html_e( 'Versión origen:', 'taxocommerce-dependiente' ); ?></strong> <?php echo esc_html( (string) ( $report['source_version'] ?? '—' ) ); ?>
                        · <strong><?php esc_html_e( 'Generado:', 'taxocommerce-dependiente' ); ?></strong> <?php echo esc_html( (string) ( $report['generated_at'] ?? '—' ) ); ?>
                    </p>
                    <?php if ( empty( $report['safe_to_restore'] ) ) : ?>
                        <div class="notice notice-error inline"><p><?php esc_html_e( 'La copia no ha superado la validación completa. No debe restaurarse.', 'taxocommerce-dependiente' ); ?></p></div>
                    <?php else : ?>
                        <div class="notice notice-success inline"><p><?php esc_html_e( 'Copia válida: la estructura y los recuentos coinciden con el manifiesto.', 'taxocommerce-dependiente' ); ?></p></div>
                    <?php endif; ?>
                    <table class="widefat striped" style="max-width:1100px">
                        <thead><tr><th><?php esc_html_e( 'Bloque', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Actual', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'En backup', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Al restaurar', 'taxocommerce-dependiente' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( self::tables() as $key => $meta ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $meta['label'] ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td>
                                <td><?php echo esc_html( number_format_i18n( absint( $counts['tables'][ $key ] ?? 0 ) ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( absint( $report['counts'][ $key ] ?? 0 ) ) ); ?></td>
                                <td><?php esc_html_e( 'Sustituir tabla completa conservando IDs', 'taxocommerce-dependiente' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ( self::options() as $name => $meta ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $meta['label'] ); ?></strong><br><code><?php echo esc_html( $name ); ?></code></td>
                                <td><?php echo empty( $counts['options'][ $name ] ) ? '—' : esc_html__( 'Presente', 'taxocommerce-dependiente' ); ?></td>
                                <td><?php echo empty( $report['options'][ $name ] ) ? '—' : esc_html__( 'Presente', 'taxocommerce-dependiente' ); ?></td>
                                <td><?php esc_html_e( 'Sustituir valor', 'taxocommerce-dependiente' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( ! empty( $report['restored'] ) ) : ?>
                        <p><strong><?php esc_html_e( 'Restauración completada:', 'taxocommerce-dependiente' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $report['restored_rows'] ?? 0 ) ) ); ?> <?php esc_html_e( 'filas restauradas.', 'taxocommerce-dependiente' ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $report['warnings'] ) ) : ?>
                        <ul style="list-style:disc;padding-left:22px">
                            <?php foreach ( (array) $report['warnings'] as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function export_url() {
        return wp_nonce_url(
            add_query_arg( 'action', 'tcd_backup_export', admin_url( 'admin-post.php' ) ),
            'tcd_backup_export'
        );
    }

    public static function export() {
        self::assert_admin();
        check_admin_referer( 'tcd_backup_export' );
        self::ensure_schema();

        $counts = self::current_counts();
        $manifest = array(
            'record' => 'manifest',
            'schema' => array(
                'name' => self::SCHEMA_NAME,
                'version' => self::SCHEMA_VERSION,
            ),
            'generated_at' => gmdate( 'c' ),
            'source' => array(
                'home_url' => home_url( '/' ),
                'site_url' => site_url( '/' ),
                'dependiente_version' => TAXOCOMMERCE_DEPENDIENTE_VERSION,
                'wordpress_version' => get_bloginfo( 'version' ),
                'brain_id' => (string) get_option( TCD_Knowledge_Transfer::BRAIN_OPTION, '' ),
            ),
            'tables' => array(),
            'options' => array(),
            'excluded' => self::excluded_items(),
        );

        foreach ( self::tables() as $key => $meta ) {
            $manifest['tables'][ $key ] = array(
                'label' => $meta['label'],
                'rows' => absint( $counts['tables'][ $key ] ?? 0 ),
                'restore' => 'replace_preserve_ids',
            );
        }
        foreach ( self::options() as $name => $meta ) {
            $manifest['options'][ $name ] = array(
                'label' => $meta['label'],
                'present' => ! empty( $counts['options'][ $name ] ),
                'restore' => 'replace',
            );
        }

        $filename = 'dependiente_backup_' . gmdate( 'Ymd_His' ) . '.jsonl';
        nocache_headers();
        header( 'Content-Type: application/x-ndjson; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

        self::emit_line( $manifest );

        global $wpdb;
        foreach ( self::tables() as $dataset => $meta ) {
            $table = $meta['table'];
            $last = 0;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT 250',
                        $table,
                        $last
                    ),
                    ARRAY_A
                );
                foreach ( (array) $rows as $row ) {
                    $last = absint( $row['id'] ?? 0 );
                    self::emit_line( array(
                        'record' => 'row',
                        'dataset' => $dataset,
                        'data' => $row,
                    ) );
                }
            } while ( count( (array) $rows ) === 250 );
        }

        foreach ( self::options() as $name => $meta ) {
            $exists = false !== get_option( $name, false );
            self::emit_line( array(
                'record' => 'option',
                'name' => $name,
                'present' => $exists,
                'value' => $exists ? get_option( $name ) : null,
            ) );
        }

        self::emit_line( array(
            'record' => 'end',
            'generated_at' => gmdate( 'c' ),
            'table_rows' => array_sum( array_map( 'absint', $counts['tables'] ) ),
        ) );
        exit;
    }

    private static function emit_line( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON Lines HTTP download is encoded by wp_json_encode; HTML escaping corrupts backup data.
        echo ( false === $json ? '{"record":"encoding_error"}' : $json ) . "\n";
    }

    public static function analyze_upload() {
        self::assert_admin();
        check_admin_referer( 'tcd_backup_analyze' );
        $path = self::uploaded_path();
        if ( is_wp_error( $path ) ) {
            self::save_report( array( 'error' => $path->get_error_message() ) );
            self::redirect();
        }

        $report = self::scan_file( $path );
        self::save_report( $report );
        self::redirect();
    }

    public static function restore_upload() {
        self::assert_admin();
        check_admin_referer( 'tcd_backup_restore' );

        if ( empty( $_POST['confirm_restore'] ) ) {
            self::save_report( array( 'error' => 'Debes confirmar explícitamente la restauración completa.' ) );
            self::redirect();
        }

        $state = TCD_Academy::state();
        if ( 'running' === (string) ( $state['status'] ?? '' ) ) {
            self::save_report( array( 'error' => 'Dependiente está aprendiendo. Detén el proceso antes de restaurar.' ) );
            self::redirect();
        }

        $path = self::uploaded_path();
        if ( is_wp_error( $path ) ) {
            self::save_report( array( 'error' => $path->get_error_message() ) );
            self::redirect();
        }

        $report = self::scan_file( $path );
        if ( empty( $report['safe_to_restore'] ) ) {
            if ( empty( $report['error'] ) ) {
                $report['error'] = 'El backup no ha superado la validación completa.';
            }
            self::save_report( $report );
            self::redirect();
        }

        $result = self::restore_file( $path );
        if ( is_wp_error( $result ) ) {
            $report['error'] = $result->get_error_message();
            self::save_report( $report );
            self::redirect();
        }

        $report['restored'] = true;
        $report['restored_rows'] = absint( $result['rows'] ?? 0 );
        $report['warnings'] = array_values( array_unique( array_merge(
            (array) ( $report['warnings'] ?? array() ),
            (array) ( $result['warnings'] ?? array() )
        ) ) );
        self::save_report( $report );
        self::redirect();
    }

    private static function restore_file( $path ) {
        global $wpdb;
        self::ensure_schema();

        $tables = self::tables();
        $options = self::options();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Administrator-validated JSONL backup is parsed in bounded records with a closed PHP temp-file handle.
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'tcd_backup_open', 'No se ha podido abrir el backup.' );
        }

        $rows_restored = 0;
        $warnings = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $tables as $dataset => $meta ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
                $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $meta['table'] ) );
                if ( false === $deleted ) {
                    throw new Exception( 'No se pudo vaciar ' . $dataset . ': ' . $wpdb->last_error );
                }
            }
            foreach ( array_keys( $options ) as $name ) {
                delete_option( $name );
            }

            $line_no = 0;
            while ( false !== ( $line = fgets( $handle ) ) ) {
                $line_no++;
                $line = trim( $line );
                if ( '' === $line ) continue;
                $record = json_decode( $line, true );
                if ( ! is_array( $record ) ) {
                    throw new Exception( 'JSON no válido en la línea ' . $line_no . '.' );
                }

                $type = sanitize_key( (string) ( $record['record'] ?? '' ) );
                if ( 'row' === $type ) {
                    $dataset = sanitize_key( (string) ( $record['dataset'] ?? '' ) );
                    if ( ! isset( $tables[ $dataset ] ) || ! is_array( $record['data'] ?? null ) ) {
                        throw new Exception( 'Dataset no reconocido en la línea ' . $line_no . '.' );
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
                    $ok = $wpdb->insert( $tables[ $dataset ]['table'], $record['data'] );
                    if ( false === $ok ) {
                        throw new Exception( 'Error restaurando ' . $dataset . ' en la línea ' . $line_no . ': ' . $wpdb->last_error );
                    }
                    $rows_restored++;
                } elseif ( 'option' === $type ) {
                    $name = (string) ( $record['name'] ?? '' );
                    if ( isset( $options[ $name ] ) && ! empty( $record['present'] ) ) {
                        update_option( $name, $record['value'] ?? null, false );
                    }
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
            $wpdb->query( 'ROLLBACK' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Administrator-validated JSONL backup is parsed in bounded records with a closed PHP temp-file handle.
            fclose( $handle );
            return new WP_Error( 'tcd_backup_restore_failed', $e->getMessage() );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Administrator-validated JSONL backup is parsed in bounded records with a closed PHP temp-file handle.
        fclose( $handle );

        // Runtime guards and schedules are deliberately local to the destination.
        delete_option( TCD_Academy::LOCK );
        delete_option( 'tcd_learning_stop' );
        delete_option( 'tcd_feedback_dirty' );
        wp_clear_scheduled_hook( TCD_Academy::CRON_HOOK );
        wp_clear_scheduled_hook( TCD_Maintenance::CRON_HOOK );

        $restored_state = TCD_Academy::state();
        if ( 'running' === (string) ( $restored_state['status'] ?? '' ) ) {
            TCD_Academy::save( array(
                'status' => 'stopped',
                'last_message' => 'Backup restaurado. El aprendizaje estaba en ejecución en el origen y se ha dejado detenido por seguridad; puedes reanudarlo manualmente.',
            ) );
            $warnings[] = 'El backup se creó con el aprendizaje en ejecución; se ha restaurado detenido para evitar arrancar trabajo automáticamente.';
        }

        $maintenance = TCD_Maintenance::state();
        if ( 'active' === (string) ( $maintenance['status'] ?? '' ) ) {
            TCD_Maintenance::activate();
        }

        // Recalculate consolidated feedback on the next M12 pass.
        if ( absint( self::current_counts()['tables']['feedback_events'] ?? 0 ) > 0 ) {
            update_option( 'tcd_feedback_dirty', 1, false );
        }

        return array(
            'rows' => $rows_restored,
            'warnings' => $warnings,
        );
    }

    private static function scan_file( $path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Administrator-validated JSONL backup is parsed in bounded records with a closed PHP temp-file handle.
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return array( 'error' => 'No se ha podido abrir el backup.', 'safe_to_restore' => false );
        }

        $tables = self::tables();
        $options = self::options();
        $counts = array_fill_keys( array_keys( $tables ), 0 );
        $option_presence = array_fill_keys( array_keys( $options ), false );
        $option_seen = array_fill_keys( array_keys( $options ), false );
        $manifest = array();
        $warnings = array();
        $errors = array();
        $unknown = 0;
        $end_seen = false;
        $end_rows = null;
        $line_no = 0;

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $line_no++;
            if ( 1 === $line_no ) {
                $line = preg_replace( '/^\xEF\xBB\xBF/', '', $line );
            }
            $line = trim( $line );
            if ( '' === $line ) continue;

            $record = json_decode( $line, true );
            if ( ! is_array( $record ) ) {
                $errors[] = 'JSON no válido en la línea ' . $line_no . '.';
                break;
            }

            $type = sanitize_key( (string) ( $record['record'] ?? '' ) );
            if ( 'manifest' === $type ) {
                if ( $manifest ) {
                    $errors[] = 'El backup contiene más de un manifiesto.';
                    continue;
                }
                $manifest = $record;
                continue;
            }

            if ( 'row' === $type ) {
                $dataset = sanitize_key( (string) ( $record['dataset'] ?? '' ) );
                if ( ! isset( $tables[ $dataset ] ) || ! is_array( $record['data'] ?? null ) ) {
                    $unknown++;
                    continue;
                }
                $counts[ $dataset ]++;
                continue;
            }

            if ( 'option' === $type ) {
                $name = (string) ( $record['name'] ?? '' );
                if ( isset( $options[ $name ] ) ) {
                    if ( ! empty( $option_seen[ $name ] ) ) {
                        $errors[] = 'La opción ' . $name . ' aparece más de una vez en el backup.';
                    }
                    $option_seen[ $name ] = true;
                    $option_presence[ $name ] = ! empty( $record['present'] );
                } else {
                    $unknown++;
                }
                continue;
            }

            if ( 'end' === $type ) {
                if ( $end_seen ) {
                    $errors[] = 'El backup contiene más de un marcador final.';
                }
                $end_seen = true;
                $end_rows = absint( $record['table_rows'] ?? 0 );
                continue;
            }

            $unknown++;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Administrator-validated JSONL backup is parsed in bounded records with a closed PHP temp-file handle.
        fclose( $handle );

        if ( ! $manifest ) {
            $errors[] = 'Falta el manifiesto del backup.';
        }

        $schema = (array) ( $manifest['schema'] ?? array() );
        if ( self::SCHEMA_NAME !== (string) ( $schema['name'] ?? '' ) ) {
            $errors[] = 'El archivo no es un backup completo de TaxoCommerce Dependiente.';
        }
        if ( self::SCHEMA_VERSION !== absint( $schema['version'] ?? 0 ) ) {
            $errors[] = 'Versión de formato de backup no compatible.';
        }

        $expected = (array) ( $manifest['tables'] ?? array() );
        foreach ( $tables as $key => $meta ) {
            if ( ! isset( $expected[ $key ] ) ) {
                $errors[] = 'El manifiesto no contiene el bloque obligatorio ' . $key . '.';
                continue;
            }
            $expected_rows = absint( $expected[ $key ]['rows'] ?? 0 );
            if ( $expected_rows !== absint( $counts[ $key ] ?? 0 ) ) {
                $errors[] = sprintf(
                    'Recuento incoherente en %s: manifiesto %d, archivo %d.',
                    $key,
                    $expected_rows,
                    absint( $counts[ $key ] ?? 0 )
                );
            }
        }

        $manifest_options = (array) ( $manifest['options'] ?? array() );
        foreach ( $options as $name => $meta ) {
            if ( ! isset( $manifest_options[ $name ] ) ) {
                $errors[] = 'El manifiesto no declara la opción obligatoria ' . $name . '.';
                continue;
            }
            if ( empty( $option_seen[ $name ] ) ) {
                $errors[] = 'Falta el registro de la opción obligatoria ' . $name . '.';
                continue;
            }
            $manifest_present = ! empty( $manifest_options[ $name ]['present'] );
            if ( $manifest_present !== ! empty( $option_presence[ $name ] ) ) {
                $errors[] = 'La presencia de la opción ' . $name . ' no coincide con el manifiesto.';
            }
        }

        if ( ! $end_seen ) {
            $errors[] = 'Falta el marcador final del backup; el archivo puede estar truncado.';
        } elseif ( absint( $end_rows ) !== array_sum( array_map( 'absint', $counts ) ) ) {
            $errors[] = 'El total de filas del marcador final no coincide con el contenido leído.';
        }

        if ( $unknown ) {
            $errors[] = sprintf( 'Se han encontrado %d registros desconocidos.', $unknown );
        }

        $source = (array) ( $manifest['source'] ?? array() );
        $source_version = (string) ( $source['dependiente_version'] ?? '' );
        if ( $source_version && version_compare( $source_version, TAXOCOMMERCE_DEPENDIENTE_VERSION, '>' ) ) {
            $errors[] = 'El backup procede de una versión de Dependiente más nueva que la instalada. Actualiza Dependiente antes de restaurar para evitar pérdida de columnas o estado.';
        }

        return array(
            'schema' => (string) ( $schema['name'] ?? '' ) . ' v' . absint( $schema['version'] ?? 0 ),
            'source_version' => $source_version,
            'generated_at' => (string) ( $manifest['generated_at'] ?? '' ),
            'counts' => $counts,
            'options' => $option_presence,
            'warnings' => $warnings,
            'errors' => $errors,
            'safe_to_restore' => empty( $errors ),
        );
    }

    private static function uploaded_path() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Solo se llama desde los handlers analyze_upload/restore con check_admin_referer y permisos.
        if ( empty( $_FILES['backup_file'] ) || ! is_array( $_FILES['backup_file'] ) ) {
            return new WP_Error( 'tcd_backup_missing', 'No se ha recibido ningún archivo de backup.' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Native upload structure validated below.
        $file = $_FILES['backup_file'];
        $error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
        $tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

        if ( UPLOAD_ERR_OK !== $error || ! $size || $size > self::MAX_BYTES || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
            return new WP_Error( 'tcd_backup_invalid', 'El archivo no es válido o supera 512 MB.' );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return $tmp;
    }

    private static function ensure_schema() {
        TCD_Academy::install();
        TCD_Knowledge_Transfer::install();
        TCD_Feedback::install();
        TCD_Intelligence::install();
    }

    private static function current_counts() {
        global $wpdb;
        $tables = array();
        foreach ( self::tables() as $key => $meta ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup/export and restore require an exact current view of plugin-owned tables and transactional writes; no safe read cache.
            $tables[ $key ] = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $meta['table'] ) ) );
        }

        $options = array();
        foreach ( self::options() as $name => $meta ) {
            $options[ $name ] = false !== get_option( $name, false );
        }

        return array(
            'tables' => $tables,
            'options' => $options,
        );
    }

    private static function last_report() {
        $report = get_option( self::LAST_REPORT, array() );
        return is_array( $report ) ? $report : array();
    }

    private static function save_report( $report ) {
        update_option( self::LAST_REPORT, is_array( $report ) ? $report : array(), false );
    }

    private static function redirect() {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'taxocommerce-dependiente',
                    'tab' => 'backup',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private static function assert_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permisos insuficientes.' );
        }
    }
}
