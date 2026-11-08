<?php
/**
 * Independent diagnostics for the Connections compatibility bridge/fallback.
 * No network calls, decrypted credentials or provider changes are involved.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Connections_Autocheck {
    const RUN = 'tc_connections_autocheck_run';
    const DOWNLOAD = 'tc_connections_autocheck_json';
    const NONCE = 'tc_connections_autocheck';
    const PREFIX = 'tc_connections_autocheck_';
    const PAGE = 'taxocommerce-connections-autocheck';

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
        add_action( 'admin_post_' . self::RUN, array( __CLASS__, 'handle_run' ) );
        add_action( 'admin_post_' . self::DOWNLOAD, array( __CLASS__, 'handle_download' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( TAXOCOMMERCE_CONNECTIONS_FILE ), array( __CLASS__, 'plugin_link' ) );
    }

    public static function url() {
        return admin_url( 'admin.php?page=' . self::PAGE );
    }

    public static function menu() {
        // Hidden submenu: the bridge does not add another main WordPress menu.
        add_submenu_page( null, __( 'Autocheck', 'taxocommerce-connections' ),
            __( 'Autocheck', 'taxocommerce-connections' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
    }

    public static function plugin_link( $links ) {
        $links[] = '<a href="' . esc_url( self::url() ) . '">' .
            esc_html__( 'Autocheck', 'taxocommerce-connections' ) . '</a>';
        return $links;
    }

    private static function key() {
        return self::PREFIX . get_current_blog_id() . '_' . get_current_user_id();
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Autocheck.', 'taxocommerce-connections' ) );
        }
    }

    public static function run() {
        $bridge = defined( 'TAXOCOMMERCE_GOOGLE_BING_VERSION' );
        $rows = array();
        $rows[] = array(
            'id' => 'version', 'group' => 'Plugin', 'label' => 'Carga del puente de compatibilidad',
            'status' => defined( 'TAXOCOMMERCE_CONNECTIONS_VERSION' ) ? 'pass' : 'fail',
            'message' => defined( 'TAXOCOMMERCE_CONNECTIONS_VERSION' )
                ? 'Versión del plugin accesible.' : 'No se ha encontrado la versión.',
        );
        $rows[] = array(
            'id' => 'routing', 'group' => 'Enrutamiento', 'label' => 'Conexiones canónicas o autónomas',
            'status' => 'pass',
            'message' => $bridge
                ? 'El puente dirige a Google-Bing/Insights; no duplica la gestión de cuentas.'
                : 'La gestión autónoma del plugin está habilitada.',
        );
        $required = array( 'TaxoCommerce_Connections_Amazon', 'TaxoCommerce_Connections_Pinterest', 'TaxoCommerce_Connections_Google_Bing', 'TaxoCommerce_Connections_Admin' );
        $missing = array();
        if ( ! $bridge ) {
            foreach ( $required as $name ) {
                if ( ! class_exists( $name, false ) ) { $missing[] = $name; }
            }
        }
        $rows[] = array(
            'id' => 'fallback', 'group' => 'Componentes', 'label' => 'Servicios de respaldo',
            'status' => $bridge ? 'skip' : ( $missing ? 'fail' : 'pass' ),
            'message' => $bridge ? 'No aplicable: la gestión está delegada al plugin canónico.'
                : ( $missing ? 'Clases ausentes: ' . implode( ', ', $missing ) : 'Todos los servicios de respaldo están cargados.' ),
        );
        $hooks = array( 'admin_post_taxocommerce_connections_save_amazon', 'admin_post_taxocommerce_connections_save_pinterest' );
        $missing_hooks = array();
        if ( ! $bridge ) {
            foreach ( $hooks as $hook ) {
                if ( false === has_action( $hook ) ) { $missing_hooks[] = $hook; }
            }
        }
        $rows[] = array(
            'id' => 'handlers', 'group' => 'Administración', 'label' => 'Controladores de cuentas registrados',
            'status' => $bridge ? 'skip' : ( $missing_hooks ? 'fail' : 'pass' ),
            'message' => $bridge ? 'Controladores gestionados por el plugin canónico.'
                : ( $missing_hooks ? 'Faltan acciones: ' . implode( ', ', $missing_hooks ) : 'Controles registrados, no ejecutados.' ),
        );
        $rows[] = array(
            'id' => 'external', 'group' => 'UAT', 'label' => 'Conexión OAuth y acceso a proveedores',
            'status' => 'skip',
            'message' => 'Comprobación real pendiente en cada proveedor; no se leen tokens ni se realizan peticiones HTTP.',
        );
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( $rows as $row ) { ++$totals[ $row['status'] ]; }
        return array(
            'schema' => 1, 'plugin' => 'taxocommerce-connections',
            'version' => TAXOCOMMERCE_CONNECTIONS_VERSION,
            'generated_at' => gmdate( 'c' ), 'executed_at' => gmdate( 'c' ), 'totals' => $totals, 'rows' => $rows,
        );
    }

    public static function handle_run() {
        self::guard();
        check_admin_referer( self::NONCE );
        set_transient( self::key(), self::run(), DAY_IN_SECONDS );
        wp_safe_redirect( self::url() );
        exit;
    }

    public static function handle_download() {
        self::guard();
        check_admin_referer( self::NONCE );
        $report = get_transient( self::key() );
        if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
            wp_die( esc_html__( 'Ejecuta el Autocheck antes de descargar el JSON.', 'taxocommerce-connections' ) );
        }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'No se pudo generar el JSON.', 'taxocommerce-connections' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-connections-autocheck-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response, no HTML.
        echo $json;
        exit;
    }

    public static function render() {
        self::guard();
        $report = get_transient( self::key() );
        echo '<div class="wrap"><h1>' . esc_html__( 'Autocheck', 'taxocommerce-connections' ) . '</h1>';
        if ( TaxoCommerce_Connections_Autotest_Code::render_subtabs() ) {
            echo '</div>';
            return;
        }
        echo '<p>' . esc_html__( 'Comprueba la gestión de conexiones sin modificar cuentas ni exponer credenciales.', 'taxocommerce-connections' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN ) . '">';
        wp_nonce_field( self::NONCE );
        submit_button( __( 'Ejecutar Autocheck', 'taxocommerce-connections' ), 'primary', 'submit', false );
        echo '</form>';
        if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
            echo '<p>' . esc_html__( 'Todavía no hay resultados.', 'taxocommerce-connections' ) . '</p></div>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::DOWNLOAD ) . '">';
        wp_nonce_field( self::NONCE );
        submit_button( __( 'Descargar resultados (JSON)', 'taxocommerce-connections' ), 'secondary', 'submit', false );
        echo '<p><strong>' . esc_html__( 'Fecha de ejecución:', 'taxocommerce-connections' ) . '</strong> ' . esc_html( ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i:s T', strtotime( (string) $report['executed_at'] ) ) : gmdate( 'd/m/Y H:i:s', strtotime( (string) $report['executed_at'] ) ) . ' UTC' ) ) . '</p>';
        echo '</form>';
        echo '<p>' . esc_html( sprintf( '%d correctas, %d errores y %d omitidas.',
            (int) $report['totals']['pass'], (int) $report['totals']['fail'], (int) $report['totals']['skip'] ) ) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Comprobación', 'taxocommerce-connections' ) .
            '</th><th>' . esc_html__( 'Estado', 'taxocommerce-connections' ) . '</th><th>' .
            esc_html__( 'Detalle', 'taxocommerce-connections' ) . '</th></tr></thead><tbody>';
        foreach ( $report['rows'] as $row ) {
            echo '<tr><th>' . esc_html( $row['label'] ) . '</th><td>' . esc_html( $row['status'] ) .
                '</td><td>' . esc_html( $row['message'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
