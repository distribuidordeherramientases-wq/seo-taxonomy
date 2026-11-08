<?php
/**
 * Read-only, explicitly triggered diagnostic for standalone Templates.
 * Never changes route policies, mappings, theme files or content.
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Autocheck {
    const RUN = 'tct_autocheck_run';
    const JSON = 'tct_autocheck_json';
    const NONCE = 'tct_autocheck';
    const PREFIX = 'tct_autocheck_latest_';

    public static function boot() {
        add_action( 'admin_post_' . self::RUN, array( __CLASS__, 'handle_run' ) );
        add_action( 'admin_post_' . self::JSON, array( __CLASS__, 'handle_json' ) );
    }

    private static function key() {
        return self::PREFIX . get_current_blog_id() . '_' . get_current_user_id();
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Autocheck.', 'taxocommerce-templates' ) );
        }
    }

    public static function run() {
        $start = microtime( true );
        $rows = array();
        $cases = array(
            'components' => array(
                'group' => 'Componentes',
                'name' => 'Clases principales disponibles',
                'classes' => array( 'TaxoCommerce_Templates', 'TCT_Router', 'TCT_Request_Registry', 'TCT_Request_Admin', 'TCT_Template_Editor', 'TCT_Unified_Manager', 'TCT_Routing_Admin', 'TCT_Front_Composer', 'TCT_Association_Audit' ),
            ),
            'routes' => array(
                'group' => 'Administración',
                'name' => 'Acciones de edición, enrutamiento y descarga',
                'hooks' => array( 'admin_post_tct_save_role', 'admin_post_tct_save_template', 'admin_post_tct_settings', 'admin_post_tct_save_route_mapping', 'admin_post_tct_save_code', 'admin_post_tct_front_composer_save', 'admin_post_tct_download_template' ),
            ),
        );
        foreach ( $cases as $id => $definition ) {
            $missing = array();
            foreach ( (array) ( $definition['classes'] ?? array() ) as $class ) {
                if ( ! class_exists( $class, false ) ) { $missing[] = $class; }
            }
            foreach ( (array) ( $definition['hooks'] ?? array() ) as $hook ) {
                if ( false === has_action( $hook ) ) { $missing[] = $hook; }
            }
            $rows[] = array(
                'id' => $id,
                'group' => $definition['group'],
                'label' => $definition['name'],
                'status' => $missing ? 'fail' : 'pass',
                'message' => $missing ? 'Componentes o rutas ausentes: ' . implode( ', ', $missing ) : 'Servicios disponibles; no se han ejecutado.',
            );
        }
        $installed = TaxoCommerce_Templates::installed();
        $rows[] = array(
            'id' => 'schema', 'group' => 'Persistencia', 'label' => 'Tablas de asociaciones disponibles',
            'status' => $installed ? 'pass' : 'skip',
            'message' => $installed ? 'La instalación dispone de sus tablas de relaciones.' : 'Tablas no disponibles; comprobar instalación, sin crear ninguna.',
        );
        $rows[] = array(
            'id' => 'policies', 'group' => 'Seguridad', 'label' => 'Interruptor de políticas y consentimiento',
            'status' => 'pass',
            'message' => TaxoCommerce_Templates::policies_enabled()
                ? 'Las políticas están habilitadas expresamente; no se han cambiado.'
                : 'Las políticas están suspendidas; no se han activado.',
        );
        $rows[] = array(
            'id' => 'real_ua', 'group' => 'UAT', 'label' => 'Edición, asignación y front-end reales',
            'status' => 'skip',
            'message' => 'Prueba manual pendiente: no se guarda ninguna plantilla ni se altera el front-end.',
        );
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( $rows as $row ) { ++$totals[ $row['status'] ]; }
        return array(
            'schema' => 1, 'plugin' => 'taxocommerce-templates', 'version' => TCT_VERSION,
            'generated_at' => gmdate( 'c' ), 'executed_at' => gmdate( 'c' ), 'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $totals, 'rows' => $rows,
        );
    }

    public static function handle_run() {
        self::guard();
        check_admin_referer( self::NONCE );
        set_transient( self::key(), self::run(), DAY_IN_SECONDS );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-templates', 'tab' => 'autocheck' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_json() {
        self::guard();
        check_admin_referer( self::NONCE );
        $report = get_transient( self::key() );
        if ( ! is_array( $report ) || ! isset( $report['totals'], $report['rows'] ) ) {
            wp_die( esc_html__( 'Ejecuta el Autocheck antes de descargar los resultados.', 'taxocommerce-templates' ) );
        }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'No se pudo preparar el JSON.', 'taxocommerce-templates' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-templates-autocheck-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Direct JSON attachment.
        echo $json;
        exit;
    }

    public static function render() {
        if ( \TaxoCommerce_Templates_Autotest_Code::render_subtabs() ) { return; }
        self::guard();
        $report = get_transient( self::key() );
        echo '<section class="wrap"><h2>' . esc_html__( 'Autocheck', 'taxocommerce-templates' ) . '</h2>';
        echo '<p>' . esc_html__( 'Comprobación de solo lectura de los servicios y las rutas de Templates. No activa políticas, no edita plantillas ni cambia asignaciones.', 'taxocommerce-templates' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN ) . '">';
        wp_nonce_field( self::NONCE );
        submit_button( __( 'Ejecutar Autocheck', 'taxocommerce-templates' ), 'primary', 'submit', false );
        echo '</form>';
        if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
            echo '<p>' . esc_html__( 'Todavía no hay resultados.', 'taxocommerce-templates' ) . '</p></section>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::JSON ) . '">';
        wp_nonce_field( self::NONCE );
        submit_button( __( 'Descargar resultados (JSON)', 'taxocommerce-templates' ), 'secondary', 'submit', false );
        echo '<p><strong>' . esc_html__( 'Fecha de ejecución:', 'taxocommerce-templates' ) . '</strong> ' . esc_html( ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i:s T', strtotime( (string) $report['executed_at'] ) ) : gmdate( 'd/m/Y H:i:s', strtotime( (string) $report['executed_at'] ) ) . ' UTC' ) ) . '</p>';
        echo '</form>';
        echo '<p>' . esc_html( sprintf( '%d correctas, %d errores, %d omitidas.',
            (int) $report['totals']['pass'], (int) $report['totals']['fail'], (int) $report['totals']['skip'] ) ) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Comprobación', 'taxocommerce-templates' ) .
             '</th><th>' . esc_html__( 'Estado', 'taxocommerce-templates' ) . '</th><th>' .
             esc_html__( 'Detalle', 'taxocommerce-templates' ) . '</th></tr></thead><tbody>';
        foreach ( $report['rows'] as $row ) {
            echo '<tr><th>' . esc_html( (string) $row['label'] ) . '</th><td>' .
                 esc_html( (string) $row['status'] ) . '</td><td>' . esc_html( (string) $row['message'] ) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
}
