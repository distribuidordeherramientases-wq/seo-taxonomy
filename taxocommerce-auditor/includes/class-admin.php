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
                echo '<p><strong>Último informe completo:</strong> ' . esc_html( (string) ( $report['completed_gmt'] ?? '' ) ) . ' UTC';
                echo ' · Revisados: ' . esc_html( (string) absint( $report['processed'] ?? 0 ) );
                echo ' · Hallazgos: ' . esc_html( (string) absint( $report['finding_count'] ?? 0 ) ) . '</p>';
                $details = self::scope_link( $scope );
                $export = wp_nonce_url(
                    add_query_arg( array( 'action' => 'taxocommerce_auditor_export', 'scope' => $scope ), admin_url( 'admin-post.php' ) ),
                    'taxocommerce_auditor_export_' . $scope
                );
                echo '<p><a class="button" href="' . esc_url( $details ) . '">Ver último informe</a> ';
                echo '<a class="button" href="' . esc_url( $export ) . '">JSON</a></p>';
            }

            self::action_form( $scope, 'start', $report ? 'Repetir auditoría' : 'Auditar', true );
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

    private static function render_report( $scope ) {
        $report = Runner::report( $scope );
        echo '<hr><h2>Último informe: ' . esc_html( Runner::scopes()[ $scope ] ) . '</h2>';
        if ( ! $report ) {
            echo '<p>No hay todavía un informe completo para este bloque.</p>';
            return;
        }
        $counts = (array) ( $report['severity_counts'] ?? array() );
        echo '<p><strong>Entidades analizadas:</strong> ' . esc_html( (string) absint( $report['processed'] ?? 0 ) );
        echo ' · <strong>Excluidas:</strong> ' . esc_html( (string) absint( $report['excluded'] ?? 0 ) );
        echo ' · <strong>Críticos:</strong> ' . esc_html( (string) absint( $counts['critical'] ?? 0 ) );
        echo ' · <strong>Alta:</strong> ' . esc_html( (string) absint( $counts['high'] ?? 0 ) );
        echo ' · <strong>Media:</strong> ' . esc_html( (string) absint( $counts['medium'] ?? 0 ) );
        echo ' · <strong>Baja:</strong> ' . esc_html( (string) absint( $counts['low'] ?? 0 ) ) . '</p>';

        $quality = (array) ( $report['quality'] ?? array() );
        echo '<h3>Calidad interna</h3>';
        echo '<p>Media: <strong>' . esc_html( null === ( $quality['average'] ?? null ) ? 'No disponible' : (string) $quality['average'] . '/100' ) . '</strong>. Puntuación interna de orientación editorial, no puntuación de buscadores.</p>';

        $rows = (array) ( $report['findings_sample'] ?? array() );
        echo '<h3>Plan de revisión priorizado</h3>';
        if ( ! empty( $report['findings_sample_truncated'] ) ) {
            echo '<p>El informe conserva el número total por regla y severidad; la tabla muestra una muestra acotada. No confundir muestra y totalidad.</p>';
        }
        if ( ! $rows ) {
            echo '<p>Sin hallazgos en este bloque.</p>';
            return;
        }
        echo '<div class="taxocommerce-auditor-table"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Entidad</th><th>Regla</th><th>Evidencia</th><th>Recomendación</th></tr></thead><tbody>';
        foreach ( array_slice( $rows, 0, 150 ) as $row ) {
            $type = sanitize_key( (string) ( $row['entity_type'] ?? '' ) );
            $id = absint( $row['entity_id'] ?? 0 );
            $url = self::edit_url( $type, $id );
            echo '<tr><td>' . esc_html( (string) ( $row['priority'] ?? 'P5' ) ) . ' · ' . esc_html( (string) ( $row['severity'] ?? '' ) ) . '</td>';
            echo '<td>';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $row['title'] ?? (string) $id ) ) . '</a>';
            } else {
                echo esc_html( (string) ( $row['title'] ?? (string) $id ) );
            }
            echo '<br><code>' . esc_html( $type . ' #' . $id ) . '</code></td>';
            echo '<td><code>' . esc_html( (string) ( $row['code'] ?? '' ) ) . '</code></td>';
            echo '<td>' . esc_html( (string) ( $row['evidence'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['recommendation'] ?? '' ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ) . '">Volver a los cinco bloques</a></p>';
    }

    public static function handle_action() {
        self::guard();
        $scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
        $task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
        if ( ! Runner::valid_scope( $scope ) ) {
            wp_die( esc_html__( 'Bloque Auditor no reconocido.', 'taxocommerce-auditor' ), '', array( 'response' => 400 ) );
        }
        check_admin_referer( 'taxocommerce_auditor_action_' . $scope );
        if ( 'start' === $task ) {
            Runner::start( $scope );
            Runner::run_batch( $scope );
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
        header( 'Content-Disposition: attachment; filename="taxocommerce_auditor_' . $scope . '.json"' );
        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
        exit;
    }
}
