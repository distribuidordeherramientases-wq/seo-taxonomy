<?php
/**
 * Native WordPress administration for the independent content Auditor.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Admin {
    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 70 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_post_taxocommerce_auditor_action', array( __CLASS__, 'handle_action' ) );
        add_action( 'admin_post_taxocommerce_auditor_export', array( __CLASS__, 'handle_export' ) );
    }

    public static function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, Plugin::MENU_SLUG ) ) {
            return;
        }
        wp_enqueue_style(
            'taxocommerce-auditor-admin',
            plugins_url( 'assets/admin.css', dirname( __DIR__ ) . '/taxocommerce-auditor.php' ),
            array(),
            Plugin::VERSION
        );
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar Auditor.', 'taxocommerce-auditor' ), '', array( 'response' => 403 ) );
        }
    }

    public static function register_menu() {
        if ( function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' ) ) {
            add_submenu_page(
                'taxocommerce-core',
                'Auditor de contenidos',
                'Auditor',
                'manage_options',
                Plugin::MENU_SLUG,
                array( __CLASS__, 'render' )
            );
        } else {
            add_menu_page(
                'TaxoCommerce Auditor',
                'Auditor',
                'manage_options',
                Plugin::MENU_SLUG,
                array( __CLASS__, 'render' ),
                'dashicons-visibility',
                62
            );
        }
    }

    private static function state_label( $status ) {
        $labels = array(
            'queued'       => 'En cola',
            'running'      => 'En curso',
            'completed'    => 'Completado',
            'paused'       => 'Pausado',
            'error'        => 'Error',
            'unavailable'  => 'Fuente no disponible',
        );
        return $labels[ $status ] ?? 'Sin ejecutar';
    }

    private static function scope_link( $scope ) {
        return add_query_arg( array( 'page' => Plugin::MENU_SLUG, 'auditor_scope' => $scope ), admin_url( 'admin.php' ) );
    }

    private static function action_form( $scope, $task, $label, $primary = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_auditor_action">';
        echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
        echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
        wp_nonce_field( 'taxocommerce_auditor_action_' . $scope );
        echo '<button type="submit" class="' . esc_attr( $primary ? 'button button-primary' : 'button' ) . '">' . esc_html( $label ) . '</button>';
        echo '</form>';
    }

    private static function filter_form( $scope, $label ) {
        $state = Runner::state( $scope );
        $filters = Runner::sanitize_filters( $scope, (array) ( $state['filters'] ?? array() ) );
        echo '<div class="taxocommerce-auditor-filter-wrap"><p><strong>Auditoría filtrada</strong> · revisión rápida de una muestra o búsqueda concreta.</p>';
        echo '<form class="taxocommerce-auditor-filter" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_auditor_action">';
        echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
        echo '<input type="hidden" name="task" value="start">';
        wp_nonce_field( 'taxocommerce_auditor_action_' . $scope );
        echo '<label>Buscar <input type="text" name="search" value="' . esc_attr( $filters['search'] ) . '" placeholder="Título o ID"></label>';
        if ( 'products' === $scope ) {
            $categories = get_terms( array( 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'number'=>250, 'orderby'=>'name', 'order'=>'ASC' ) );
            if ( is_wp_error( $categories ) ) {
                $categories = array();
            }
            echo '<label>Categoría <select name="category_id"><option value="0">Todas</option>';
            foreach ( $categories as $category ) {
                echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $filters['category_id'], $category->term_id, false ) . '>' . esc_html( $category->name ) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<label>Máximo <select name="rows">';
        foreach ( array(10,25,50,100,250) as $n ) {
            echo '<option value="' . esc_attr( $n ) . '" ' . selected( $filters['rows'], $n, false ) . '>' . esc_html( $n ) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
        echo '</form></div>';
    }

    public static function render() {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only scope selection.
        $selected = isset( $_GET['auditor_scope'] ) ? sanitize_key( wp_unslash( $_GET['auditor_scope'] ) ) : '';
        if ( ! Runner::valid_scope( $selected ) ) {
            $selected = '';
        }

        echo '<div class="wrap"><h1>TaxoCommerce Auditor de contenidos</h1>';
        echo '<p>Auditoría independiente y de solo lectura. Los resultados son señales de revisión humana, no una puntuación de Google. Ninguna auditoría publica, borra, mueve ni redirige contenidos.</p>';
        echo '<p><strong>Fuentes:</strong> WordPress post/term/meta, WooCommerce CRUD, términos y asignaciones del nuevo Vocabulary. No consulta tablas del plugin antiguo.</p>';

        echo '<div class="taxocommerce-auditor-grid">';
        foreach ( Runner::scopes() as $scope => $label ) {
            $state  = Runner::state( $scope );
            $report = Runner::report( $scope );
            $status = (string) ( $state['status'] ?? '' );
            $count = isset( $state['processed'] ) ? absint( $state['processed'] ) : 0;
            $expected = isset( $state['total'] ) ? absint( $state['total'] ) : null;

            echo '<div class="card taxocommerce-auditor-card"><h2>' . esc_html( $label ) . '</h2>';
            echo '<p><strong>Estado:</strong> ' . esc_html( self::state_label( $status ) );
            if ( in_array( $status, array( 'queued', 'running', 'paused' ), true ) ) {
                echo ' · ' . esc_html( (string) $count ) . ' / ' . esc_html( null === $expected ? 'sin recuento disponible' : (string) $expected );
            }
            echo '</p>';

            if ( ! empty( $state['reason'] ) ) {
                echo '<p>' . esc_html( (string) $state['reason'] ) . '</p>';
            }
            if ( $report ) {
                $mode = 'full' === (string) ( $report['run_mode'] ?? '' ) ? 'completa' : 'filtrada';
                echo '<p><strong>Último informe:</strong> ' . esc_html( (string) ( $report['completed_gmt'] ?? '' ) ) . ' UTC';
                echo ' · Modo: <strong>' . esc_html( $mode ) . '</strong>';
                echo ' · Revisados: ' . esc_html( (string) absint( $report['processed'] ?? 0 ) );
                echo ' · Hallazgos: ' . esc_html( (string) absint( $report['finding_count'] ?? 0 ) ) . '</p>';
                $details = self::scope_link( $scope );
                $export = wp_nonce_url(
                    add_query_arg( array( 'action' => 'taxocommerce_auditor_export', 'scope' => $scope ), admin_url( 'admin-post.php' ) ),
                    'taxocommerce_auditor_export_' . $scope
                );
                echo '<p><a class="button" href="' . esc_url( $details ) . '">Ver último informe</a> ';
                echo '<a class="button" href="' . esc_url( $export ) . '">JSON completo</a></p>';
            }

            echo '<div class="taxocommerce-auditor-full"><p><strong>Auditoría completa</strong> · recorre todo el bloque publicado por lotes y genera un JSON sin truncar la cola.</p>';
            self::action_form( $scope, 'start_full', $report ? 'Repetir auditoría completa' : 'Auditar todo', true );
            echo '</div>';
            self::filter_form( $scope, $report ? 'Repetir filtro' : 'Auditar filtro' );
            if ( in_array( $status, array( 'queued', 'running' ), true ) ) {
                self::action_form( $scope, 'step', 'Procesar siguiente lote' );
                self::action_form( $scope, 'pause', 'Pausar' );
            } elseif ( 'paused' === $status ) {
                self::action_form( $scope, 'resume', 'Reanudar' );
            }
            echo '</div>';
        }

        echo '</div>';

        if ( $selected ) {
            self::render_report( $selected );
        }
        echo '</div>';
    }

    private static function edit_url( $type, $id ) {
        if ( 'product_cat' === $type ) {
            $url = get_edit_term_link( $id, 'product_cat' );
            return is_wp_error( $url ) ? '' : (string) $url;
        }
        return (string) get_edit_post_link( $id, '' );
    }

    private static function report_url( $scope, $view ) {
        return add_query_arg(
            array(
                'page'          => Plugin::MENU_SLUG,
                'auditor_scope' => $scope,
                'auditor_view'  => $view,
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function entity_url( $row ) {
        return self::edit_url(
            sanitize_key( (string) ( $row['entity_type'] ?? '' ) ),
            absint( $row['entity_id'] ?? 0 )
        );
    }

    private static function render_report( $scope ) {
        $report = Runner::report( $scope );
        echo '<section class="taxocommerce-auditor-report">';
        echo '<h2>' . esc_html( Runner::scopes()[ $scope ] ) . '</h2>';
        if ( ! $report ) {
            echo '<p>No hay todavía un informe finalizado para este bloque. Ejecuta Auditar y consulta su estado.</p></section>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report view selector.
        $view = isset( $_GET['auditor_view'] ) ? sanitize_key( wp_unslash( $_GET['auditor_view'] ) ) : 'summary';
        $views = array(
            'summary'  => 'Resumen',
            'kpis'     => 'KPIs',
            'quality'  => 'Calidad interna',
            'plan'     => 'Plan priorizado',
            'findings' => 'Hallazgos',
            'context'  => 'Contexto de decisión',
            'history'  => 'Historial',
        );
        if ( 'categories' === $scope ) {
            $views['balance'] = 'Reequilibrio';
        }
        if ( ! isset( $views[ $view ] ) ) {
            $view = 'summary';
        }

        echo '<nav class="nav-tab-wrapper taxocommerce-auditor-tabs" aria-label="Vistas del informe">';
        foreach ( $views as $key => $name ) {
            echo '<a href="' . esc_url( self::report_url( $scope, $key ) ) . '" class="nav-tab' . ( $view === $key ? ' nav-tab-active' : '' ) . '"' . ( $view === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $name ) . '</a>';
        }
        echo '</nav>';

        if ( 'kpis' === $view ) {
            self::render_kpis( $scope, $report );
        } elseif ( 'quality' === $view ) {
            self::render_quality( $report );
        } elseif ( 'plan' === $view ) {
            self::render_plan( $report );
        } elseif ( 'findings' === $view ) {
            self::render_findings( $report );
        } elseif ( 'balance' === $view ) {
            self::render_balance( $report );
        } elseif ( 'context' === $view ) {
            self::render_context( $report );
        } elseif ( 'history' === $view ) {
            self::render_history( $scope );
        } else {
            self::render_summary( $report );
        }
        echo '<p class="taxocommerce-auditor-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ) . '">Volver a los cinco bloques</a></p></section>';
    }

    private static function render_metric( $value, $label ) {
        echo '<div class="taxocommerce-auditor-metric"><strong>' . esc_html( (string) $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
    }

    private static function render_kpi_card( $row ) {
        $row = is_array( $row ) ? $row : array();
        $tone = sanitize_key( (string) ( $row['tone'] ?? 'neutral' ) );
        if ( ! in_array( $tone, array( 'good', 'warn', 'bad', 'neutral' ), true ) ) {
            $tone = 'neutral';
        }
        $value = (string) ( $row['value'] ?? 'N/D' );
        $unit = (string) ( $row['unit'] ?? '' );
        echo '<div class="taxocommerce-auditor-kpi is-' . esc_attr( $tone ) . '">';
        echo '<div class="taxocommerce-auditor-kpi-value"><strong>' . esc_html( $value ) . '</strong>';
        if ( '' !== $unit ) {
            echo '<span>' . esc_html( $unit ) . '</span>';
        }
        echo '</div>';
        echo '<h4>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</h4>';
        if ( ! empty( $row['detail'] ) ) {
            echo '<p>' . esc_html( (string) $row['detail'] ) . '</p>';
        }
        echo '</div>';
    }

    private static function render_kpis( $scope, $report ) {
        $kpis = (array) ( $report['scope_kpis'] ?? Runner::scope_kpis( $scope, $report ) );
        $scope_labels = array(
            'products'   => 'Productos',
            'categories' => 'Categorías',
            'posts'      => 'Posts',
            'pages'      => 'Páginas y landings',
            'faqs'       => 'FAQs',
        );
        $coverage = 'complete_scope' === (string) ( $kpis['coverage'] ?? '' )
            ? 'auditoría completa'
            : 'auditoría filtrada';

        echo '<div class="taxocommerce-auditor-kpi-head">';
        echo '<div><h3>KPIs de ' . esc_html( $scope_labels[ $scope ] ?? $scope ) . '</h3>';
        echo '<p>Indicadores específicos de calidad, integridad y errores a corregir sobre la última ' . esc_html( $coverage ) . '.</p></div>';
        echo '<span class="taxocommerce-auditor-kpi-mode">' . esc_html( $coverage ) . '</span>';
        echo '</div>';

        echo '<h4 class="taxocommerce-auditor-kpi-section">Estado general</h4>';
        echo '<div class="taxocommerce-auditor-kpi-grid">';
        foreach ( (array) ( $kpis['general'] ?? array() ) as $row ) {
            self::render_kpi_card( $row );
        }
        echo '</div>';

        echo '<h4 class="taxocommerce-auditor-kpi-section">Diagnóstico específico</h4>';
        echo '<div class="taxocommerce-auditor-kpi-grid">';
        foreach ( (array) ( $kpis['specific'] ?? array() ) as $row ) {
            self::render_kpi_card( $row );
        }
        if ( empty( $kpis['specific'] ) ) {
            echo '<p>No hay indicadores específicos disponibles para este bloque.</p>';
        }
        echo '</div>';

        echo '<div class="notice notice-info inline"><p>' . esc_html( (string) ( $kpis['note'] ?? '' ) ) . '</p></div>';
    }

    private static function render_summary( $report ) {
        $counts = (array) ( $report['severity_counts'] ?? array() );
        $quality = (array) ( $report['quality'] ?? array() );
        echo '<h3>Resumen del último análisis</h3>';
        echo '<p>Finalizado: <strong>' . esc_html( (string) ( $report['completed_gmt'] ?? '' ) ) . ' UTC</strong> · Duración: ' . esc_html( (string) absint( $report['duration_seconds'] ?? 0 ) ) . ' segundos.</p>';
        echo '<div class="taxocommerce-auditor-metrics">';
        self::render_metric( absint( $report['processed'] ?? 0 ), 'Entidades revisadas' );
        self::render_metric( absint( $report['finding_count'] ?? 0 ), 'Hallazgos' );
        self::render_metric( absint( $counts['critical'] ?? 0 ), 'Críticos' );
        self::render_metric( absint( $counts['high'] ?? 0 ), 'Alta' );
        self::render_metric( absint( $counts['medium'] ?? 0 ), 'Media' );
        self::render_metric( null === ( $quality['average'] ?? null ) ? 'N/D' : (string) $quality['average'], 'Calidad media / 100' );
        echo '</div>';

        if ( absint( $report['excluded'] ?? 0 ) ) {
            echo '<p>Páginas funcionales/corporativas excluidas del diagnóstico editorial: <strong>' . esc_html( (string) absint( $report['excluded'] ) ) . '</strong>.</p>';
        }
        self::render_priority_counts( (array) ( $report['priority_counts'] ?? array() ) );

        $scope = sanitize_key( (string) ( $report['scope'] ?? '' ) );
        $kpis = (array) ( $report['scope_kpis'] ?? ( $scope ? Runner::scope_kpis( $scope, $report ) : array() ) );
        if ( ! empty( $kpis['specific'] ) ) {
            echo '<h3>KPIs específicos</h3><div class="taxocommerce-auditor-kpi-grid is-compact">';
            foreach ( array_slice( (array) $kpis['specific'], 0, 4 ) as $row ) {
                self::render_kpi_card( $row );
            }
            echo '</div>';
            if ( $scope ) {
                echo '<p><a class="button" href="' . esc_url( self::report_url( $scope, 'kpis' ) ) . '">Ver todos los KPIs</a></p>';
            }
        }

        $rules = (array) ( $report['rule_counts'] ?? array() );
        arsort( $rules, SORT_NUMERIC );
        echo '<h3>Reglas con más incidencias</h3>';
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Regla</th><th>Hallazgos</th></tr></thead><tbody>';
        foreach ( array_slice( $rules, 0, 25, true ) as $code => $total ) {
            echo '<tr><td><code>' . esc_html( (string) $code ) . '</code></td><td>' . esc_html( (string) absint( $total ) ) . '</td></tr>';
        }
        if ( ! $rules ) {
            echo '<tr><td colspan="2">Sin incidencias registradas.</td></tr>';
        }
        echo '</tbody></table></div>';

        if ( ! empty( $report['findings_sample_truncated'] ) ) {
            echo '<p><strong>Atención:</strong> se conservan los recuentos completos por regla y prioridad, pero las tareas mostradas son una muestra acotada para evitar informes demasiado pesados.</p>';
        }
        echo '<p>El índice de calidad es una evaluación interna para ordenar revisiones. No equivale a una puntuación de Google ni de Bing.</p>';
    }

    private static function render_priority_counts( $counts ) {
        echo '<h3>Plan de trabajo · prioridades P1–P5</h3>';
        $labels = array(
            'P1' => 'Atender primero / verificar incidencias',
            'P2' => 'Esperar enriquecimiento',
            'P3' => 'Migrar a Solucionador',
            'P4' => 'Revisar',
            'P5' => 'Informativo',
        );
        echo '<div class="taxocommerce-auditor-metrics">';
        foreach ( $labels as $priority => $label ) {
            self::render_metric( absint( $counts[ $priority ] ?? 0 ), $priority . ' · ' . $label );
        }
        echo '</div>';
    }

    private static function render_quality( $report ) {
        $quality = (array) ( $report['quality'] ?? array() );
        echo '<h3>Puntuación interna de calidad</h3><p>Se conservan las cinco dimensiones del Auditor anterior; es una señal de priorización propia, no el algoritmo de un buscador.</p>';
        echo '<p>Entidades puntuadas: ' . esc_html( (string) absint( $quality['scored'] ?? 0 ) );
        echo ' · Media: <strong>' . esc_html( null === ( $quality['average'] ?? null ) ? 'No disponible' : (string) $quality['average'] . '/100' ) . '</strong>.</p>';
        $dimensions = array(
            'utility'         => 'Relevancia y utilidad',
            'trust'           => 'Confianza y rigor',
            'differentiation' => 'Diferenciación / canibalización',
            'architecture'    => 'Arquitectura semántica',
            'technical'       => 'Preparación técnica',
        );
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Dimensión</th><th>Máximo</th></tr></thead><tbody>';
        foreach ( $dimensions as $key => $label ) {
            echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) absint( $quality['dimensions'][ $key ] ?? 0 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        $bands = (array) ( $quality['bands'] ?? array() );
        echo '<div class="taxocommerce-auditor-metrics">';
        foreach ( array( 'fuerte' => 'Fuerte', 'correcta' => 'Correcta', 'mejorable' => 'Mejorable', 'debil' => 'Débil' ) as $key => $label ) {
            self::render_metric( absint( $bands[ $key ] ?? 0 ), $label );
        }
        echo '</div>';

        echo '<h3>Entidades con menor puntuación</h3>';
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Entidad</th><th>Calidad</th><th>Dimensiones</th></tr></thead><tbody>';
        foreach ( (array) ( $quality['lowest_scored_sample'] ?? array() ) as $row ) {
            $url = self::entity_url( $row );
            echo '<tr><td>';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</a>';
            } else {
                echo esc_html( (string) ( $row['title'] ?? '' ) );
            }
            echo ' <code>#' . esc_html( (string) absint( $row['entity_id'] ?? 0 ) ) . '</code></td>';
            echo '<td><strong>' . esc_html( (string) absint( $row['score'] ?? 0 ) ) . '/100</strong> · ' . esc_html( (string) ( $row['band'] ?? '' ) ) . '</td>';
            $parts = array();
            foreach ( (array) ( $row['dimensions'] ?? array() ) as $key => $value ) {
                $parts[] = ( $dimensions[ $key ] ?? $key ) . ': ' . absint( $value );
            }
            echo '<td>' . esc_html( implode( ' · ', $parts ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_plan( $report ) {
        $rows = (array) ( $report['findings_sample'] ?? array() );
        echo '<h3>Plan de revisión priorizado</h3>';
        echo '<p>Cada tarea incluye prioridad, estado, evidencia y recomendación. Una prioridad alta no autoriza a modificar automáticamente contenidos.</p>';
        if ( ! empty( $report['findings_sample_truncated'] ) ) {
            echo '<p>El listado es una muestra priorizada de ' . esc_html( (string) count( $rows ) ) . ' tareas sobre ' . esc_html( (string) absint( $report['finding_count'] ?? 0 ) ) . ' hallazgos. Consulta los contadores completos en Resumen.</p>';
        }
        if ( ! $rows ) {
            echo '<p>Sin hallazgos.</p>';
            return;
        }

        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr>';
        echo '<th>Prioridad</th><th>Entidad</th><th>Problema</th><th>Evidencia</th><th>Recomendación</th><th>Estado</th></tr></thead><tbody>';
        foreach ( array_slice( $rows, 0, 200 ) as $row ) {
            $url = self::entity_url( $row );
            $type = sanitize_key( (string) ( $row['entity_type'] ?? '' ) );
            $id = absint( $row['entity_id'] ?? 0 );
            echo '<tr><td><strong>' . esc_html( (string) ( $row['priority'] ?? 'P5' ) ) . '</strong>';
            echo '<br>' . esc_html( (string) ( $row['severity'] ?? '' ) );
            echo '<br><small>' . esc_html( (string) ( $row['priority_score'] ?? '' ) ) . '</small></td>';
            echo '<td>';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</a>';
            } else {
                echo esc_html( (string) ( $row['title'] ?? '' ) );
            }
            echo '<br><code>' . esc_html( $type . ' #' . $id ) . '</code></td>';
            echo '<td><code>' . esc_html( (string) ( $row['code'] ?? '' ) ) . '</code>';
            echo '<br><small>' . esc_html( (string) ( $row['task_id'] ?? '' ) ) . '</small></td>';
            echo '<td>' . esc_html( (string) ( $row['evidence'] ?? '' ) );
            if ( ! empty( $row['context'] ) ) {
                echo '<details><summary>Datos de contexto</summary><pre>' . esc_html( wp_json_encode( $row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
            }
            echo '</td><td>' . esc_html( (string) ( $row['recommendation'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['status'] ?? 'REVIEW_REQUIRED' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_findings( $report ) {
        echo '<h3>Hallazgos por regla</h3>';
        $rows = (array) ( $report['rule_counts'] ?? array() );
        arsort( $rows, SORT_NUMERIC );
        echo '<p>Esta vista resume la totalidad de incidencias contabilizadas, no solo las que caben en la muestra de tareas.</p>';
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Código de hallazgo</th><th>Incidencias</th></tr></thead><tbody>';
        foreach ( $rows as $code => $count ) {
            echo '<tr><td><code>' . esc_html( (string) $code ) . '</code></td><td>' . esc_html( (string) absint( $count ) ) . '</td></tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="2">Ninguna incidencia.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_balance( $report ) {
        $balance = (array) ( $report['category_rebalance'] ?? array() );
        $summary = (array) ( $balance['summary'] ?? array() );
        echo '<h3>Reequilibrio de categorías</h3>';
        echo '<p>Se conserva la referencia histórica de 5–10 productos por categoría. El tamaño solo señala casos para revisar: <strong>no justifica por sí solo dividir, fusionar ni crear una URL</strong>.</p>';
        echo '<div class="taxocommerce-auditor-metrics">';
        self::render_metric( absint( $summary['review_split'] ?? 0 ), 'Revisar por división' );
        self::render_metric( absint( $summary['split_proposals'] ?? 0 ), 'Propuestas de división' );
        self::render_metric( absint( $summary['review_merge'] ?? 0 ), 'Revisar concentración' );
        self::render_metric( absint( $summary['merge_proposals'] ?? 0 ), 'Propuestas de concentración' );
        echo '</div>';
        echo '<p>La generación de propuestas está activa. La aprobación/aplicación con movimiento de productos y redirecciones 301 sigue deshabilitada en esta migración: requiere una capa transaccional nativa validada.</p>';

        $rows = (array) ( $balance['proposals'] ?? array() );
        if ( ! $rows ) {
            echo '<p>No hay propuestas respaldadas por evidencia semántica suficiente. Las categorías fuera del tamaño de referencia siguen reflejadas como hallazgos para revisión manual.</p>';
            return;
        }
        if ( ! empty( $balance['sample_truncated'] ) ) {
            echo '<p>La tabla contiene una muestra de propuestas, no el total.</p>';
        }
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Categoría origen</th><th>Tipo</th><th>Productos</th><th>Evidencia</th><th>Acción</th></tr></thead><tbody>';
        foreach ( array_slice( $rows, 0, 150 ) as $row ) {
            $id = absint( $row['category_id'] ?? 0 );
            $url = self::edit_url( 'product_cat', $id );
            $proposal = (array) ( $row['proposal'] ?? array() );
            $split = ! empty( $row['split_proposal'] );
            echo '<tr><td><strong>' . esc_html( (string) ( $row['category_name'] ?? '' ) ) . '</strong><br><code>#' . esc_html( (string) $id ) . '</code></td>';
            echo '<td>' . esc_html( $split ? 'División por TIPO' : 'Concentración en hermana' ) . '</td>';
            echo '<td>' . esc_html( (string) absint( $row['products'] ?? 0 ) ) . '</td><td>';
            if ( $split ) {
                foreach ( array_slice( (array) ( $proposal['cohorts'] ?? array() ), 0, 8 ) as $cohort ) {
                    echo '<p><strong>' . esc_html( (string) ( $cohort['label'] ?? '' ) ) . '</strong>: ' . esc_html( (string) absint( $cohort['products'] ?? 0 ) ) . ' productos</p>';
                }
                echo '<small>Ambiguos: ' . esc_html( (string) absint( $proposal['ambiguous_products'] ?? 0 ) ) . '</small>';
            } else {
                echo 'Destino sugerido: <strong>' . esc_html( (string) ( $proposal['target_name'] ?? '' ) ) . '</strong> #' . esc_html( (string) absint( $proposal['target_id'] ?? 0 ) );
                echo '<p>Similitud de nombre: ' . esc_html( (string) ( $proposal['title_similarity'] ?? 'N/D' ) ) . ' · Vocabulary: ' . esc_html( (string) ( $proposal['concept_overlap'] ?? 'N/D' ) ) . '</p>';
            }
            echo '</td><td>';
            if ( $url ) {
                echo '<a class="button" href="' . esc_url( $url ) . '">Abrir categoría</a>';
            }
            echo '<p><small>Revisión; no hay cambios automáticos.</small></p></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_context( $report ) {
        echo '<h3>Contexto de decisión</h3><p>Auditor evalúa consistencia interna y no consulta directamente APIs de buscadores, compras ni analítica.</p>';
        echo '<p>Fuentes nativas disponibles para esta ejecución: <strong>' . esc_html( (string) ( $report['source_model'] ?? 'WordPress / WooCommerce' ) ) . '</strong>.</p>';
        $context = $report['decision_context'] ?? null;
        if ( ! is_array( $context ) || ! $context ) {
            echo '<p><strong>Analista, Ojeador, Dependiente y Comparador:</strong> datos no disponibles para este informe. Ausente no significa cero; no se ha inventado ningún valor.</p>';
        } else {
            echo '<p>Contexto leído exclusivamente de proveedores persistidos mediante el filtro de integración del nuevo Auditor.</p>';
            echo '<pre class="taxocommerce-auditor-json">' . esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
        }
        if ( ! empty( $report['editorial_comparison'] ) ) {
            $map = (array) $report['editorial_comparison'];
            echo '<p>Mapa editorial indexado: ' . esc_html( (string) absint( $map['indexed_posts_pages'] ?? 0 ) ) . ' objetos.';
            echo ! empty( $map['partial'] ) ? ' La comparación es parcial por límite de seguridad.</p>' : '</p>';
        }
        echo '<p>Los enlaces a fuentes y el contraste externo son tareas distintas de la auditoría de contenidos.</p>';
    }

    private static function render_history( $scope ) {
        $history = Runner::history( $scope );
        echo '<h3>Historial de auditorías completadas</h3>';
        if ( ! $history ) {
            echo '<p>No existen ejecuciones completadas todavía.</p>';
            return;
        }
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Fecha UTC</th><th>Duración</th><th>Revisados</th><th>Hallazgos</th><th>P1–P5</th></tr></thead><tbody>';
        foreach ( $history as $row ) {
            $priorities = array();
            foreach ( array( 'P1','P2','P3','P4','P5' ) as $priority ) {
                $priorities[] = $priority . ': ' . absint( $row['priority_counts'][ $priority ] ?? 0 );
            }
            echo '<tr><td>' . esc_html( (string) ( $row['completed_gmt'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) absint( $row['duration_seconds'] ?? 0 ) ) . ' s</td>';
            echo '<td>' . esc_html( (string) absint( $row['processed'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( (string) absint( $row['finding_count'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( implode( ' · ', $priorities ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function handle_action() {
        self::guard();
        $scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
        $task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
        if ( ! Runner::valid_scope( $scope ) ) {
            wp_die( esc_html__( 'Bloque Auditor no reconocido.', 'taxocommerce-auditor' ), '', array( 'response' => 400 ) );
        }
        check_admin_referer( 'taxocommerce_auditor_action_' . $scope );
        if ( in_array( $task, array( 'start', 'start_full' ), true ) ) {
            $filters = Runner::sanitize_filters( $scope, array(
                'mode'        => 'start_full' === $task ? 'full' : 'filtered',
                'search'      => isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '',
                'category_id' => isset( $_POST['category_id'] ) ? wp_unslash( $_POST['category_id'] ) : 0,
                'rows'        => isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : 25,
            ) );
            if ( ! Runner::start( $scope, $filters ) ) {
                wp_die( esc_html__( 'Ya hay una auditoría de este bloque procesándose. Espera a que acabe el lote antes de reiniciarla.', 'taxocommerce-auditor' ), '', array( 'response' => 409 ) );
            }
            // WP-Cron ejecutará el primer lote fuera de esta petición.
        } elseif ( 'step' === $task ) {
            Runner::run_batch( $scope );
        } elseif ( 'pause' === $task ) {
            Runner::pause( $scope );
        } elseif ( 'resume' === $task ) {
            Runner::resume( $scope );
        } else {
            wp_die( esc_html__( 'Acción no reconocida.', 'taxocommerce-auditor' ), '', array( 'response' => 400 ) );
        }
        wp_safe_redirect( self::scope_link( $scope ) );
        exit;
    }

    public static function handle_export() {
        self::guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated immediately after scope.
        $scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : '';
        if ( ! Runner::valid_scope( $scope ) ) {
            wp_die( esc_html__( 'Bloque Auditor no reconocido.', 'taxocommerce-auditor' ), '', array( 'response' => 400 ) );
        }
        check_admin_referer( 'taxocommerce_auditor_export_' . $scope );
        $report = Runner::report( $scope );
        if ( ! $report ) {
            wp_die( esc_html__( 'No hay informe completo que exportar.', 'taxocommerce-auditor' ), '', array( 'response' => 404 ) );
        }
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-auditor-' . $scope . '-' . gmdate( 'Ymd-His' ) . '.json"' );
        if ( ! Export_Store::stream_report( $scope, $report ) ) {
            // Backward compatibility for reports created before the complete
            // JSON spool existed.
            echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
        }
        exit;
    }
}
