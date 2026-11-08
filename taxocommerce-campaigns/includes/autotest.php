<?php
/** Read-only, explicitly invoked diagnostics for Campaigns. */
defined('ABSPATH') || exit;
function tccampaigns_autotest_results() {
    global $wpdb;
    $checks = array();
    $add = static function($group, $name, $pass, $detail, $optional = false) use (&$checks) {
        $checks[] = array('group'=>$group,'name'=>$name,'status'=>$pass?'Correcto':($optional?'Aviso':'Error'),'detail'=>$detail);
    };
    foreach (array('tccampaigns_tables','tccampaigns_get','tccampaigns_reschedule','tccampaigns_apply','tccampaigns_restore','tccampaigns_calendar_render','tccampaigns_render_tab','tccampaigns_render_banners','tccampaigns_register_public_template') as $fn) {
        $add('Componentes', $fn, function_exists($fn), 'Función PHP registrada.');
    }
    $tables = tccampaigns_tables();
    foreach (array('campaigns','products') as $key) {
        $table = $tables[$key];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema existence check for independent custom tables; cache could hide migration state.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        $add('Base de datos', $key, $found === $table, 'Existencia de tabla; sin leer ni cambiar campañas.');
    }
    $version = (int) get_option(TCCAMPAIGNS_DB_OPTION, 0);
    $add('Base de datos', 'Versión de esquema', $version >= TCCAMPAIGNS_DB_VERSION, 'Esquema registrado: ' . $version . '; requerido: ' . TCCAMPAIGNS_DB_VERSION . '.');
    $add('WordPress', 'WooCommerce', class_exists('WooCommerce'), 'Necesario para administrar precios promocionales.');
    $add('WordPress', 'Programador disponible', function_exists('as_schedule_single_action') || function_exists('wp_schedule_single_event'), 'Se comprueba la API disponible, no las ejecuciones reales.');
    $add('Integraciones opcionales', 'TaxoCommerce Social', function_exists('tcsocial_network_get_providers'), 'Solo necesario para proveedores sociales.', true);
    $add('Integraciones opcionales', 'TaxoCommerce Templates', function_exists('seo_templates_get') || function_exists('seo_templates_render') || defined('TAXOCOMMERCE_TEMPLATES_VERSION'), 'Solo para las plantillas públicas de campañas.', true);
    return $checks;
}
function tccampaigns_render_autotest() {
    if ( \TaxoCommerce_Campaigns_Autotest_Code::render_subtabs() ) { return; }
    if (!current_user_can('manage_options')) return;
    echo '<section class="tcc-card">' . '<h2>' . esc_html__( 'Autocheck de TaxoCommerce Campaigns', 'taxocommerce-campaigns' ) . '</h2>';
    echo '<p>' . esc_html__( 'Comprobaciones de disponibilidad y esquema en modo lectura. No ejecutan campañas, no modifican precios, no crean tablas ni solicitan servicios externos.', 'taxocommerce-campaigns' ) . '</p>';
    echo '<form method="post" action="' . esc_url(tccampaigns_admin_tab_url('autotest')) . '">';
    wp_nonce_field('tccampaigns_autotest');
    echo '<input type="hidden" name="tccampaigns_run_autotest" value="1">';
    submit_button( __( 'Ejecutar Autocheck', 'taxocommerce-campaigns' ),'primary','submit',false);
    echo '</form>';
    if (!isset($_POST['tccampaigns_run_autotest'])) { echo '</section>'; return; }
    check_admin_referer('tccampaigns_autotest');
    $checks = tccampaigns_autotest_results();
        $autocheck_totals = array();
        foreach ( $checks as $autocheck_item ) {
            $autocheck_status = (string) ( $autocheck_item['status'] ?? 'unknown' );
            $autocheck_totals[ $autocheck_status ] = ( $autocheck_totals[ $autocheck_status ] ?? 0 ) + 1;
        }
        $autocheck_report = array(
            'schema' => 1,
            'plugin' => 'taxocommerce-campaigns',
            'version' => defined( 'TAXOCOMMERCE_CAMPAIGNS_VERSION' ) ? constant( 'TAXOCOMMERCE_CAMPAIGNS_VERSION' ) : '',
            'generated_at' => gmdate( 'c' ), 'executed_at' => gmdate( 'c' ),
            'totals' => $autocheck_totals,
            'rows' => $checks,
        );
        if ( function_exists( 'set_transient' ) && function_exists( 'get_current_blog_id' ) && function_exists( 'get_current_user_id' ) ) {
            set_transient( 'taxocommerce_campaigns_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id(), $autocheck_report, HOUR_IN_SECONDS );
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_campaigns_autocheck_json">';
        wp_nonce_field( 'taxocommerce_campaigns_autocheck_json' );
        submit_button( __( 'Descargar resultados (JSON)', 'taxocommerce-campaigns' ), 'secondary', 'submit', false );
        echo '<p><strong>' . esc_html__( 'Fecha de ejecución:', 'taxocommerce-campaigns' ) . '</strong> ' . esc_html( ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i:s T', strtotime( (string) $autocheck_report['executed_at'] ) ) : gmdate( 'd/m/Y H:i:s', strtotime( (string) $autocheck_report['executed_at'] ) ) . ' UTC' ) ) . '</p>';
        echo '</form>';
    echo '<table class="widefat striped" style="margin-top:16px">' . '<thead>' . '<tr>' . '<th>' . esc_html__( 'Grupo', 'taxocommerce-campaigns' ) . '</th>' . '<th>' . esc_html__( 'Comprobación', 'taxocommerce-campaigns' ) . '</th>' . '<th>' . esc_html__( 'Estado', 'taxocommerce-campaigns' ) . '</th>' . '<th>' . esc_html__( 'Detalle', 'taxocommerce-campaigns' ) . '</th>' . '</tr>' . '</thead>' . '<tbody>';
    foreach ($checks as $check) {
        echo '<tr><td>' . esc_html($check['group']) . '</td><td><code>' . esc_html($check['name']) . '</code></td><td><strong>' . esc_html($check['status']) . '</strong></td><td>' . esc_html($check['detail']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
}


/**
 * Download the current user's most recent explicit diagnostic run.
 * Never execute an import, export, connection or worker during download.
 */
function taxocommerce_campaigns_autocheck_export_json() {
    if ( ! ( current_user_can( 'manage_options' ) ) ) {
        wp_die( esc_html__( 'Sin permisos para descargar el Autocheck.', 'taxocommerce-campaigns' ) );
    }
    check_admin_referer( 'taxocommerce_campaigns_autocheck_json' );
    $report = get_transient( 'taxocommerce_campaigns_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id() );
    if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
        wp_die( esc_html__( 'Ejecuta el Autocheck antes de descargar el JSON.', 'taxocommerce-campaigns' ) );
    }
    $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
    if ( ! is_string( $json ) ) {
        wp_die( esc_html__( 'No se pudo generar el informe JSON.', 'taxocommerce-campaigns' ) );
    }
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Disposition: attachment; filename="taxocommerce-campaigns-autocheck-' . gmdate( 'Ymd-His' ) . '.json"' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The response is a JSON attachment.
    echo $json;
    exit;
}
if ( function_exists( 'add_action' ) ) {
    add_action( 'admin_post_taxocommerce_campaigns_autocheck_json', 'taxocommerce_campaigns_autocheck_export_json' );
}
