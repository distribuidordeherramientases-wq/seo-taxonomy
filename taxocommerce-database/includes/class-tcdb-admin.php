<?php
/**
 * Administrator-facing database console. All navigation is read-only;
 * writes require dedicated nonce-protected, explicit POST endpoints.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Admin {
    const TABS = array(
        'mysql' => 'MySQL',
        'tables' => 'Data Table',
        'backup' => 'Backups',
        'import' => 'Importar CSV',
        'cleanup' => 'Clean DB',
        'operations' => 'Operaciones',
    );

    public static function register_styles( $hook ) {
        if ( false === strpos( (string) $hook, 'taxocommerce-database' ) ) {
            return;
        }
        wp_enqueue_style(
            'taxocommerce-database-admin',
            TAXOCOMMERCE_DATABASE_URL . 'assets/database.css',
            array(),
            TAXOCOMMERCE_DATABASE_VERSION
        );
    }

    private static function q( $key, $fallback = '' ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter.
        $value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $fallback;
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
    }

    private static function form_start( $action, $nonce ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( $nonce );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acceso no autorizado.' );
        }
        $tab = sanitize_key( self::q( 'tcdb_tab', 'mysql' ) );
        if ( ! isset( self::TABS[ $tab ] ) ) {
            $tab = 'mysql';
        }
        echo '<div class="wrap tcdb-wrap">';
        echo '<div class="tcdb-hero"><div><small>TAXOCOMMERCE · MÓDULO INDEPENDIENTE</small>';
        echo '<h1>TaxoCommerce Database</h1>';
        echo '<p>MySQL, exploración de tablas y operaciones manuales de mantenimiento de datos.</p></div>';
        echo '<span class="tcdb-version">v' . esc_html( TAXOCOMMERCE_DATABASE_VERSION ) . '</span></div>';
        echo '<div class="notice notice-info inline"><p><strong>Sin rollback automático.</strong> Los dos mecanismos de reversión de Data Table y Clean DB no se han migrado. Las importaciones CSV solo añaden registros y requieren confirmación.</p></div>';

        echo '<nav class="nav-tab-wrapper tcdb-tabs" aria-label="Herramientas de base de datos">';
        foreach ( self::TABS as $key => $label ) {
            echo '<a href="' . esc_url( TCDB_Storage::url( $key ) ) . '" class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';

        $notice = sanitize_key( self::q( 'tcdb_notice' ) );
        $messages = array(
            'audit_ok' => 'Chequeo MySQL realizado y registrado.',
            'import_ok' => 'CSV importado correctamente. Se añadieron registros nuevos sin sobrescribir datos existentes.',
            'cleanup_ok' => 'Limpieza caducada ejecutada en un lote limitado; revisa los pendientes restantes.',
        );
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }
        if ( 'mysql' === $tab ) {
            self::render_mysql();
        } elseif ( 'tables' === $tab ) {
            self::render_tables();
        } elseif ( 'backup' === $tab ) {
            self::render_backups();
        } elseif ( 'import' === $tab ) {
            self::render_import();
        } elseif ( 'cleanup' === $tab ) {
            self::render_cleanup();
        } else {
            self::render_operations();
        }
        echo '</div>';
    }

    private static function heading( $title, $subtitle ) {
        echo '<div class="tcdb-heading"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $subtitle ) . '</p></div>';
    }

    private static function cards( $cards ) {
        echo '<div class="tcdb-cards">';
        foreach ( $cards as $card ) {
            echo '<div class="tcdb-card"><small>' . esc_html( $card[0] ) . '</small><strong>' . esc_html( (string) $card[1] ) . '</strong><span>' . esc_html( $card[2] ) . '</span></div>';
        }
        echo '</div>';
    }

    private static function render_mysql() {
        self::heading( 'Estado de MySQL', 'Última fotografía manual del servidor y de las tablas del sitio. No se ejecutan chequeos al abrir la pantalla.' );
        self::form_start( 'tcdb_audit', 'tcdb_audit' );
        echo '<button class="button button-primary" type="submit">Ejecutar chequeo MySQL</button></form>';
        $data = get_option( TCDB_Storage::AUDIT_OPTION, array() );
        if ( ! is_array( $data ) || ! isset( $data['at'] ) ) {
            echo '<div class="tcdb-panel"><p>No existe todavía ningún chequeo guardado. Pulsa el botón para analizar la base de datos.</p></div>';
            return;
        }

        self::cards( array(
            array( 'Versión del servidor', $data['version'] ?? 'N/D', 'MySQL / MariaDB' ),
            array( 'Tablas del sitio', number_format_i18n( $data['tables'] ?? 0 ), 'Prefijo WordPress actual' ),
            array( 'Tablas de la familia', number_format_i18n( $data['family'] ?? 0 ), 'TaxoCommerce y antiguas seo_*' ),
            array( 'Datos + índices', size_format( $data['bytes'] ?? 0, 1 ), 'Tamaño aproximado' ),
        ) );
        echo '<p class="tcdb-timestamp">Último chequeo: <strong>' . esc_html( $data['at'] ) . '</strong>. ' . esc_html( $data['note'] ?? '' ) . '</p>';

        echo '<div class="tcdb-columns"><section class="tcdb-panel"><h3>Indicadores del servidor</h3>';
        $labels = array(
            'Threads_connected' => 'Conexiones simultáneas actuales',
            'Max_used_connections' => 'Pico de conexiones',
            'Aborted_connects' => 'Conexiones fallidas',
            'Created_tmp_disk_tables' => 'Tablas temporales en disco',
            'Created_tmp_tables' => 'Temporales totales',
            'Slow_queries' => 'Consultas lentas',
            'Innodb_buffer_pool_reads' => 'Lecturas InnoDB desde disco',
            'Innodb_buffer_pool_read_requests' => 'Solicitudes de lectura InnoDB',
            'Select_full_join' => 'JOIN sin índice',
            'Sort_merge_passes' => 'Pasadas de ordenación',
        );
        echo '<table class="widefat striped"><tbody>';
        foreach ( $labels as $key => $label ) {
            $value = $data['status'][ $key ] ?? null;
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
            echo null === $value ? 'No disponible' : esc_html( number_format_i18n( (float) $value ) );
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">Los contadores globales reflejan actividad acumulada del servidor; no representan solo esta web.</p></section>';

        echo '<section class="tcdb-panel"><h3>Integridad y tareas pendientes</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Control</th><th>Detectados</th></tr></thead><tbody>';
        $checks = array(
            'orphan_page_nodes' => 'Nodos SEO de páginas sin objeto',
            'duplicate_relations' => 'Relaciones SEO duplicadas',
            'as_overdue_pending' => 'Acciones pendientes vencidas (Action Scheduler)',
        );
        foreach ( $checks as $key => $label ) {
            $exists = array_key_exists( $key, (array) ( $data['integrity'] ?? array() ) );
            echo '<tr><th>' . esc_html( $label ) . '</th><td>' . ( $exists ? esc_html( number_format_i18n( $data['integrity'][ $key ] ) ) : 'Tabla no disponible' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $data['no_primary'] ) ) {
            echo '<h4>Tablas propias con más de 1.000 filas sin índice primario</h4><ul>';
            foreach ( $data['no_primary'] as $name ) {
                echo '<li><code>' . esc_html( $name ) . '</code></li>';
            }
            echo '</ul>';
        }
        echo '<p class="description">Este chequeo no elimina registros ni cambia índices. Las reparaciones de relaciones siguen correspondiendo a Core/Auditor.</p></section></div>';

        echo '<section class="tcdb-panel"><h3>Tablas de mayor tamaño</h3><div class="tcdb-scroll"><table class="widefat striped"><thead><tr><th>Tabla</th><th>Datos e índices</th></tr></thead><tbody>';
        foreach ( (array) ( $data['large'] ?? array() ) as $row ) {
            echo '<tr><th><code>' . esc_html( $row['table'] ) . '</code></th><td>' . esc_html( size_format( $row['bytes'], 1 ) ) . '</td></tr>';
        }
        if ( empty( $data['large'] ) ) {
            echo '<tr><td colspan="2">Ninguna tabla supera 5 MB.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $history = get_option( TCDB_Storage::HISTORY_OPTION, array() );
        echo '<section class="tcdb-panel"><h3>Histórico (hasta 20 chequeos)</h3><div class="tcdb-scroll"><table class="widefat striped">';
        echo '<thead><tr><th>Fecha</th><th>Tablas</th><th>TaxoCommerce</th><th>Tamaño</th><th>Action Scheduler vencidas</th></tr></thead><tbody>';
        foreach ( (array) $history as $row ) {
            echo '<tr><td>' . esc_html( $row['at'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $row['tables'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $row['family'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( size_format( $row['bytes'] ?? 0, 1 ) ) . '</td>';
            echo '<td>' . ( null === ( $row['as_overdue_pending'] ?? null ) ? '—' : esc_html( number_format_i18n( $row['as_overdue_pending'] ) ) ) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function render_tables() {
        self::heading( 'Data Table', 'Inventario de tablas del sitio y explorador de solo lectura de las tablas propias TaxoCommerce.' );
        $tables = TCDB_Storage::tables();
        $family = array_filter( $tables, static function ( $row ) { return $row['family']; } );
        $mode = sanitize_key( self::q( 'scope', 'family' ) );
        $display = 'all' === $mode ? $tables : $family;
        $size = 0;
        foreach ( $tables as $meta ) {
            $size += $meta['data'] + $meta['indexes'];
        }
        self::cards( array(
            array( 'Tablas WordPress', count( $tables ), 'Prefijo actual' ),
            array( 'Tablas de la familia', count( $family ), 'Incluye seo_* heredadas' ),
            array( 'Tamaño inventariado', size_format( $size, 1 ), 'Estimación MySQL' ),
        ) );
        echo '<div class="tcdb-links"><a class="button' . ( 'family' === $mode ? ' button-primary' : '' ) . '" href="' . esc_url( TCDB_Storage::url( 'tables', array( 'scope' => 'family' ) ) ) . '">Solo TaxoCommerce</a> ';
        echo '<a class="button' . ( 'all' === $mode ? ' button-primary' : '' ) . '" href="' . esc_url( TCDB_Storage::url( 'tables', array( 'scope' => 'all' ) ) ) . '">Todas las tablas (inventario)</a></div>';

        echo '<section class="tcdb-panel"><h3>Inventario</h3><div class="tcdb-scroll"><table class="widefat striped">';
        echo '<thead><tr><th>Tabla</th><th>Tipo</th><th>Filas estimadas</th><th>Tamaño</th><th>Motor</th><th>Acción</th></tr></thead><tbody>';
        foreach ( $display as $meta ) {
            echo '<tr><th><code>' . esc_html( $meta['name'] ) . '</code></th>';
            echo '<td>' . ( $meta['family'] ? 'TaxoCommerce' : 'WordPress / otras' ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $meta['rows'] ) ) . '</td>';
            echo '<td>' . esc_html( size_format( $meta['data'] + $meta['indexes'], 1 ) ) . '</td>';
            echo '<td>' . esc_html( $meta['engine'] ) . '</td><td>';
            if ( $meta['family'] ) {
                echo '<a href="' . esc_url( TCDB_Storage::url( 'tables', array( 'table' => $meta['name'] ) ) ) . '">Explorar</a>';
            } else {
                echo 'Inventario';
            }
            echo '</td></tr>';
        }
        if ( ! $display ) {
            echo '<tr><td colspan="6">No hay tablas disponibles o el usuario MySQL carece de permisos para leer su inventario.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $table = self::q( 'table' );
        if ( isset( $family[ $table ] ) ) {
            self::render_explorer( $table );
        }
    }

    private static function render_explorer( $table ) {
        global $wpdb;
        $columns = TCDB_Storage::columns( $table );
        $names = array_column( $columns, 'Field' );
        if ( ! $names ) {
            echo '<div class="notice notice-warning inline"><p>No se puede explorar esta tabla.</p></div>';
            return;
        }
        $filter = self::q( 'filter' );
        $column = self::q( 'column' );
        $page = min( 10000, max( 1, (int) self::q( 'paged', '1' ) ) );
        $per_page = (int) self::q( 'per_page', '25' );
        if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) {
            $per_page = 25;
        }
        $where = '';
        if ( '' !== $filter ) {
            if ( in_array( $column, $names, true ) && TCDB_Storage::identifier( $column ) ) {
                $where = $wpdb->prepare(
                    "WHERE CAST(" . chr(96) . $column . chr(96) . " AS CHAR) LIKE %s",
                    '%' . $wpdb->esc_like( $filter ) . '%'
                );
            } else {
                $terms = array();
                $arguments = array();
                foreach ( array_slice( $names, 0, 5 ) as $field ) {
                    if ( TCDB_Storage::identifier( $field ) ) {
                        $terms[] = 'CAST(' . chr(96) . $field . chr(96) . ' AS CHAR) LIKE %s';
                        $arguments[] = '%' . $wpdb->esc_like( $filter ) . '%';
                    }
                }
                if ( $terms ) {
                    $where = $wpdb->prepare( 'WHERE (' . implode( ' OR ', $terms ) . ')', $arguments );
                }
            }
        }
        $order = TCDB_Storage::primary_key( $table );
        $order_clause = TCDB_Storage::identifier( $order ) ? 'ORDER BY ' . chr(96) . $order . chr(96) . ' DESC' : '';
        $offset = ( $page - 1 ) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers selected from actual table schema; WHERE values are prepared.
        $query = $wpdb->prepare(
            "SELECT * FROM " . chr(96) . $table . chr(96) . " {$where} {$order_clause} LIMIT %d OFFSET %d",
            $per_page + 1,
            $offset
        );
        $rows = $wpdb->get_results( $query, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $has_next = count( $rows ) > $per_page;
        $rows = array_slice( $rows, 0, $per_page );

        echo '<section class="tcdb-panel tcdb-explorer"><h3>Explorar <code>' . esc_html( $table ) . '</code></h3>';
        echo '<form method="get" class="tcdb-filter"><input type="hidden" name="page" value="taxocommerce-database"><input type="hidden" name="tcdb_tab" value="tables"><input type="hidden" name="table" value="' . esc_attr( $table ) . '">';
        echo '<label>Buscar<input type="search" name="filter" maxlength="120" value="' . esc_attr( $filter ) . '" placeholder="Texto, ID o código"></label>';
        echo '<label>Columna<select name="column"><option value="">Primeras 5 columnas</option>';
        foreach ( $names as $name ) {
            echo '<option value="' . esc_attr( $name ) . '"' . selected( $name, $column, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Filas<select name="per_page">';
        foreach ( array( 25, 50, 100 ) as $value ) {
            echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $per_page, $value, false ) . '>' . esc_html( (string) $value ) . '</option>';
        }
        echo '</select></label><button class="button button-primary" type="submit">Aplicar</button></form>';

        echo '<div class="tcdb-scroll"><table class="widefat striped"><thead><tr>';
        foreach ( $names as $name ) {
            echo '<th>' . esc_html( $name ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $names as $name ) {
                $value = $row[ $name ] ?? '';
                $text = is_scalar( $value ) ? (string) $value : '';
                $short = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 160 ) : substr( $text, 0, 160 );
                echo '<td title="' . esc_attr( $short ) . '">' . esc_html( $short ) . ( strlen( $text ) > strlen( $short ) ? '…' : '' ) . '</td>';
            }
            echo '</tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="' . esc_attr( (string) count( $names ) ) . '">No se encontraron registros.</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="tcdb-links"><span>Página ' . esc_html( (string) $page ) . ' (sin COUNT(*) pesado)</span>';
        $args = array( 'table' => $table, 'filter' => $filter, 'column' => $column, 'per_page' => $per_page );
        if ( $page > 1 ) {
            echo '<a class="button" href="' . esc_url( TCDB_Storage::url( 'tables', array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">← Anterior</a>';
        }
        if ( $has_next ) {
            echo '<a class="button" href="' . esc_url( TCDB_Storage::url( 'tables', array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">Siguiente →</a>';
        }
        echo '</div></section>';
    }

    private static function render_backups() {
        self::heading( 'Backups y exportaciones', 'Descargas SQL o CSV bajo petición; los archivos no se guardan en el servidor WordPress.' );
        $tables = TCDB_Storage::tables();
        $family = array_filter( $tables, static function ( $meta ) { return $meta['family']; } );
        echo '<div class="tcdb-columns">';
        echo '<section class="tcdb-panel"><h3>Exportar una tabla TaxoCommerce</h3>';
        if ( $family ) {
            self::form_start( 'tcdb_export', 'tcdb_export' );
            echo '<input type="hidden" name="mode" value="table"><label class="tcdb-label">Tabla<select name="table">';
            foreach ( $family as $meta ) {
                echo '<option value="' . esc_attr( $meta['name'] ) . '">' . esc_html( $meta['name'] ) . '</option>';
            }
            echo '</select></label><label class="tcdb-label">Formato<select name="format"><option value="csv">CSV</option><option value="sql">SQL</option></select></label>';
            echo '<p><button class="button button-primary">Descargar tabla</button></p></form>';
        } else {
            echo '<p>No se han detectado tablas de la familia.</p>';
        }
        echo '<p class="description">El CSV conserva los valores originales: no lo abras en Excel sin tener en cuenta posibles fórmulas procedentes de datos externos.</p></section>';

        echo '<section class="tcdb-panel"><h3>Backup de la familia TaxoCommerce</h3>';
        echo '<p>Incluye las tablas propias actuales y las heredadas <code>seo_*</code> del plugin anterior.</p>';
        self::form_start( 'tcdb_export', 'tcdb_export' );
        echo '<input type="hidden" name="mode" value="family"><input type="hidden" name="format" value="sql">';
        echo '<p>' . esc_html( number_format_i18n( count( $family ) ) ) . ' tablas detectadas.</p>';
        echo '<button class="button button-primary" type="submit">Descargar SQL de TaxoCommerce</button></form></section></div>';

        echo '<section class="tcdb-panel"><h3>Backup completo de tablas WordPress</h3>';
        echo '<p>Incluye tablas con el prefijo activo del sitio: WordPress, WooCommerce y TaxoCommerce. <strong>Contiene datos privados y potencialmente credenciales.</strong> Guárdalo fuera de carpetas públicas.</p>';
        self::form_start( 'tcdb_export', 'tcdb_export' );
        echo '<input type="hidden" name="mode" value="all"><input type="hidden" name="format" value="sql">';
        echo '<label class="tcdb-label">Escribe <strong>EXPORTAR</strong> para confirmar<input name="confirm_sensitive" type="text" autocomplete="off" required></label>';
        echo '<p><button class="button" type="submit">Descargar SQL completo</button></p></form>';
        echo '<p class="description"><strong>Limitación:</strong> son exportaciones lógicas sin snapshot transaccional. En bases grandes pueden agotarse los límites PHP del hosting. Para recuperación fiable y copias programadas, utiliza el sistema de backup de Webempresa.</p></section>';
    }

    private static function render_import() {
        self::heading( 'Importar CSV', 'Solo inserción de nuevos registros en tablas propias InnoDB. No es un restaurador SQL ni sobrescribe datos.' );
        $notice = self::q( 'tcdb_notice' );
        $token = 0 === strpos( $notice, 'preview_' ) ? substr( $notice, 8 ) : '';
        $preview = $token ? TCDB_Import::get_preview( $token ) : false;
        if ( $preview ) {
            echo '<section class="tcdb-panel"><h3>Vista previa confirmable</h3>';
            echo '<p><strong>Tabla:</strong> <code>' . esc_html( $preview['table'] ) . '</code> · <strong>Registros nuevos:</strong> ' . esc_html( (string) count( $preview['rows'] ) ) . '</p>';
            echo '<p><strong>Columnas:</strong> ' . esc_html( implode( ', ', $preview['headers'] ) ) . '</p>';
            echo '<div class="tcdb-scroll"><table class="widefat striped"><thead><tr>';
            foreach ( $preview['headers'] as $header ) {
                echo '<th>' . esc_html( $header ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( array_slice( $preview['rows'], 0, 5 ) as $row ) {
                echo '<tr>';
                foreach ( $preview['headers'] as $field ) {
                    $cell = (string) ( $row[ $field ] ?? '' );
                    $short = function_exists( 'mb_substr' ) ? mb_substr( $cell, 0, 100 ) : substr( $cell, 0, 100 );
                    echo '<td>' . esc_html( $short ) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="description">Muestra de cinco registros. IDs y referencias deben corresponder a este entorno. Un duplicado o error detendrá todo el lote; no se alterarán filas preexistentes.</p>';
            self::form_start( 'tcdb_import_apply', 'tcdb_import_apply' );
            echo '<input type="hidden" name="tcdb_token" value="' . esc_attr( $token ) . '">';
            echo '<p><label><input type="checkbox" name="tcdb_backup" value="1" required> Confirmo que tengo un backup reciente y los IDs pertenecen a este WordPress.</label></p>';
            echo '<label class="tcdb-label">Escribe <strong>IMPORTAR</strong> para aplicar<input name="tcdb_confirm" type="text" autocomplete="off" required></label>';
            echo '<p><button class="button button-primary" type="submit">Importar solo nuevos registros</button></p></form></section>';
        }

        $tables = array_filter( TCDB_Storage::tables(), static function ( $meta ) {
            return $meta['family'] && 'InnoDB' === $meta['engine'];
        } );
        echo '<section class="tcdb-panel"><h3>1. Analizar un CSV antes de aplicar cambios</h3>';
        echo '<p>Máximo 1 MB y 250 filas; las columnas deben existir exactamente en la tabla elegida. No se aceptan comandos SQL ni importaciones directas a tablas WordPress/WooCommerce.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="tcdb_import_preview">';
        wp_nonce_field( 'tcdb_import_preview' );
        echo '<label class="tcdb-label">Tabla propia existente<select name="table" required>';
        foreach ( $tables as $meta ) {
            echo '<option value="' . esc_attr( $meta['name'] ) . '">' . esc_html( $meta['name'] ) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="tcdb-label">Archivo CSV UTF-8<input type="file" name="tcdb_csv" accept=".csv,text/csv" required></label>';
        echo '<p><button type="submit" class="button button-primary" ' . ( $tables ? '' : 'disabled' ) . '>Validar y mostrar vista previa</button></p></form>';
        echo '<p class="description">Para importar productos, páginas, categorías o traducciones utiliza el plugin TaxoCommerce Import-Export, que valida las entidades WooCommerce. Esta pantalla se reserva a tablas internas existentes.</p></section>';
    }

    private static function render_cleanup() {
        self::heading( 'Clean DB', 'Diagnóstico de caducidades y limpieza estrictamente manual, acotada a 100 registros por ejecución.' );
        $counts = TCDB_Cleanup::candidates();
        echo '<section class="tcdb-panel"><h3>Limpieza segura de datos caducados</h3><div class="tcdb-scroll">';
        echo '<table class="widefat striped"><thead><tr><th>Control</th><th>Encontrados</th><th>Acción disponible</th></tr></thead><tbody>';
        $types = array(
            'expired_transients' => array( 'Transients de WordPress caducados', 'Eliminar caducados (máximo 100)' ),
            'expired_wc_sessions' => array( 'Sesiones WooCommerce caducadas', 'Eliminar caducadas (máximo 100)' ),
            'as_overdue_pending' => array( 'Acciones pendientes vencidas de Action Scheduler', 'Solo diagnóstico: no se tocan colas, pagos ni pedidos' ),
        );
        foreach ( $types as $key => $info ) {
            echo '<tr><th>' . esc_html( $info[0] ) . '</th><td>';
            echo null === $counts[ $key ] ? 'No disponible' : esc_html( number_format_i18n( $counts[ $key ] ) );
            echo '</td><td>';
            if ( 'as_overdue_pending' === $key ) {
                echo '<span class="description">' . esc_html( $info[1] ) . '</span>';
            } elseif ( $counts[ $key ] > 0 ) {
                self::form_start( 'tcdb_cleanup', 'tcdb_cleanup' );
                echo '<input type="hidden" name="cleanup" value="' . esc_attr( $key ) . '">';
                echo '<label>Escribe LIMPIAR <input type="text" name="tcdb_confirm" autocomplete="off" required class="small-text"></label> ';
                echo '<button class="button" type="submit">' . esc_html( $info[1] ) . '</button></form>';
            } else {
                echo 'Nada que limpiar';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">Los elementos de SEO, relaciones, duplicados, metadatos huérfanos y registros editoriales se auditan desde Core/Auditor. No se copian sus eliminaciones históricas ni sus mecanismos de reversión hasta acordar el modelo de rollback.</p></section>';
    }

    private static function render_operations() {
        self::heading( 'Operaciones registradas', 'Histórico local de chequeos, exportaciones, importaciones y limpiezas de este módulo; sin controles de rollback.' );
        $events = get_option( TCDB_Storage::EVENT_OPTION, array() );
        echo '<section class="tcdb-panel"><div class="tcdb-scroll"><table class="widefat striped">';
        echo '<thead><tr><th>Fecha</th><th>Acción</th><th>Usuario WP (ID)</th><th>Detalle técnico</th></tr></thead><tbody>';
        foreach ( (array) $events as $row ) {
            echo '<tr><td>' . esc_html( $row['at'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['action'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['user'] ?? '' ) ) . '</td>';
            echo '<td><code>' . esc_html( wp_json_encode( $row['details'] ?? array(), JSON_UNESCAPED_UNICODE ) ) . '</code></td></tr>';
        }
        if ( empty( $events ) ) {
            echo '<tr><td colspan="4">Todavía no hay operaciones manuales registradas.</td></tr>';
        }
        echo '</tbody></table></div></section>';
        echo '<p class="description">El antiguo historial de Data Layer permanece en su ubicación original. No se ha importado ni alterado, y no se pueden revertir cambios desde este plugin.</p>';
    }
}
