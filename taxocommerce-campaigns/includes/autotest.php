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
    if (!current_user_can('manage_options')) return;
    echo '<section class="tcc-card"><h2>Autotest de TaxoCommerce Campaigns</h2>';
    echo '<p>Comprobaciones de disponibilidad y esquema en modo lectura. No ejecutan campañas, no modifican precios, no crean tablas ni solicitan servicios externos.</p>';
    echo '<form method="post" action="' . esc_url(tccampaigns_admin_tab_url('autotest')) . '">';
    wp_nonce_field('tccampaigns_autotest');
    echo '<input type="hidden" name="tccampaigns_run_autotest" value="1">';
    submit_button('Ejecutar comprobación','primary','submit',false);
    echo '</form>';
    if (!isset($_POST['tccampaigns_run_autotest'])) { echo '</section>'; return; }
    check_admin_referer('tccampaigns_autotest');
    $checks = tccampaigns_autotest_results();
    echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>Grupo</th><th>Comprobación</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
    foreach ($checks as $check) {
        echo '<tr><td>' . esc_html($check['group']) . '</td><td><code>' . esc_html($check['name']) . '</code></td><td><strong>' . esc_html($check['status']) . '</strong></td><td>' . esc_html($check['detail']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
}
