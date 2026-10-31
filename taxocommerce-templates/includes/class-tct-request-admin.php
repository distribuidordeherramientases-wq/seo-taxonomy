<?php
/**
 * Configuracion del catalogo general de peticiones.
 */
defined('ABSPATH') || exit;

final class TCT_Request_Admin {
    public static function admin_url($tab = 'requests', $extra = array()) {
        return add_query_arg(array_merge(array(
            'page' => 'taxocommerce-templates',
            'tab'  => $tab
        ), $extra), admin_url('admin.php'));
    }

    public static function lookup() {
        $rows = TCT_Router::rows();
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[$row->template_key] = $row;
        }
        return $mapped;
    }

    public static function redirect($message, $tab = 'requests') {
        wp_safe_redirect(self::admin_url($tab, array('tct_notice' => $message)));
        exit;
    }

    public static function save_request() {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('tct_save_request');

        if (!TaxoCommerce_Templates::installed()) {
            self::redirect('La tabla de plantillas no existe.');
        }

        $key  = isset($_POST['request_key']) ? sanitize_key(wp_unslash($_POST['request_key'])) : '';
        $file = isset($_POST['template_file']) ? sanitize_file_name(wp_unslash($_POST['template_file'])) : '';
        $defs = TCT_Request_Registry::definitions();
        if (!isset($defs[$key])) {
            self::redirect('Tipo de peticion desconocido.');
        }
        if ($file !== '' && !TCT_Router::path($file)) {
            self::redirect('El archivo no existe en la biblioteca.');
        }

        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE template_key = %s LIMIT 1", $key
        ));
        $now = current_time('mysql');
        if ($current) {
            // No se modifican tipos, asignaciones, nombres ni relaciones antiguas.
            $saved = $wpdb->update($table, array(
                'template_file' => $file,
                'is_active' => $file !== '' ? 1 : 0,
                'updated_at' => $now
            ), array('id' => (int)$current));
        } else {
            $group = $defs[$key][1];
            $type = (strpos($group, 'WooCommerce - correos') === 0) ? 'email' :
                    ((strpos($group, 'WooCommerce') === 0) ? 'woocommerce' : 'system');
            $saved = $wpdb->insert($table, array(
                'template_key' => $key,
                'template_name' => $defs[$key][0],
                'template_file' => $file,
                'template_type' => $type,
                'assignment_mode' => 'automatic',
                'is_assignable' => 0,
                'is_public' => 1,
                'is_active' => $file !== '' ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now
            ));
        }
        self::redirect(false === $saved ? 'No se ha podido guardar la asignacion.' :
            ($file === '' ? 'Ruta devuelta al tema / WordPress.' : 'Archivo asociado a esta peticion.'));
    }

    public static function render($embedded = false) {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        $definitions = TCT_Request_Registry::definitions();
        $current = self::lookup();
        $files = TCT_Router::available_files();
        $groups = array();
        foreach ($definitions as $key => $def) {
            $group = isset($def[1]) ? $def[1] : 'Otras peticiones';
            if (!isset($groups[$group])) {
                $groups[$group] = array();
            }
            $groups[$group][$key] = $def;
        }

        // Las rutas propias de otros modulos mantienen sus asignaciones aunque
        // no pertenezcan a un tipo de ejecucion WordPress estandar.
        $selected_group = isset($_GET['tct_group']) ? sanitize_text_field(wp_unslash($_GET['tct_group'])) : 'WordPress';
        if (!isset($groups[$selected_group])) {
            $selected_group = (string) key($groups);
        }
        $search = isset($_GET['tct_search']) ? sanitize_text_field(wp_unslash($_GET['tct_search'])) : '';
        $page = isset($_GET['tct_paged']) ? max(1,absint($_GET['tct_paged'])) : 1;

        $matching = array();
        foreach ($groups[$selected_group] as $key => $def) {
            if ($search && stripos($key . ' ' . $def[0] . ' ' . $def[2], $search) === false) {
                continue;
            }
            $matching[$key] = $def;
        }
        $page_size = 35;
        $total = count($matching);
        $visible = array_slice($matching, ($page-1) * $page_size, $page_size, true);

        if (!$embedded) {
            echo '<div class="wrap"><h1>Tipos de peticion y plantillas</h1>';
        }
        echo '<p>Elige el tipo de contenido o la plantilla interna que debe ejecutarse. ';
        echo '<strong>Sin asignacion:</strong> WordPress y el tema mantienen intacta su jerarquia habitual.</p>';
        echo '<p>Las rutas por roles (clúster, hubs y landings) se gestionan en la sección <strong>Asociar rol y archivo</strong> de este gestor.</p>';
        echo '<p>Las acciones de WooCommerce, como agregar un producto al carrito, no son paginas por si mismas. Se ofrecen las plantillas de interfaz correspondientes (boton, ficha, minicarrito y avisos) cuando WooCommerce las registra como archivos PHP.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:18px 0">';
        echo '<input type="hidden" name="page" value="taxocommerce-templates"><input type="hidden" name="tab" value="requests">';
        echo '<label for="tct_group">Familia</label><select name="tct_group" id="tct_group">';
        foreach ($groups as $group => $entries) {
            echo '<option value="' . esc_attr($group) . '" ' . selected($selected_group,$group,false) . '>' .
                esc_html($group . ' (' . count($entries) . ')') . '</option>';
        }
        echo '</select><input type="search" name="tct_search" value="' . esc_attr($search) . '" placeholder="Buscar tipo, ruta o plantilla">';
        submit_button('Ver tipos','secondary','submit',false);
        echo '</form><p><strong>' . (int)$total . '</strong> tipo(s) en esta seleccion. ';
        echo '<a href="' . esc_url(self::admin_url('editor')) . '">Crear o editar un archivo PHP</a></p>';

        echo '<table class="widefat striped"><thead><tr><th>Peticion</th><th>Referencia del sistema</th><th>Archivo asignado / respaldo</th></tr></thead><tbody>';
        if (!$visible) {
            echo '<tr><td colspan="3">No se han encontrado tipos.</td></tr>';
        }
        foreach ($visible as $key => $def) {
            $row = isset($current[$key]) ? $current[$key] : null;
            $selected = $row ? (string)$row->template_file : '';
            $exists = $selected !== '' && TCT_Router::path($selected);
            echo '<tr><td><strong>' . esc_html($def[0]) . '</strong><br><code>' . esc_html($key) . '</code></td>';
            echo '<td><code>' . esc_html($def[2]) . '</code></td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tct_save_request');
            echo '<input type="hidden" name="action" value="tct_save_request"><input type="hidden" name="request_key" value="' . esc_attr($key) . '">';
            echo '<select name="template_file" style="max-width:220px;width:100%">';
            echo '<option value="">— Predeterminada: WordPress / tema —</option>';
            if ($selected !== '' && !isset($files[$selected])) {
                echo '<option value="' . esc_attr($selected) . '" selected disabled>' . esc_html($selected . ' (archivo pendiente)') . '</option>';
            }
            foreach ($files as $name) {
                echo '<option value="' . esc_attr($name) . '" ' . selected($selected,$name,false) . '>' . esc_html($name) . '</option>';
            }
            echo '</select> ';
            submit_button('Guardar','secondary','submit',false);
            echo '</form>';
            if ($selected !== '') {
                echo '<small>' . ($exists ? 'Archivo disponible' : 'Sin archivo: se mantiene el respaldo del tema') . '</small> ';
                echo '<a href="' . esc_url(self::admin_url('editor',array('tct_source'=>$exists ? $selected : '', 'tct_assign'=>$key))) . '">Editar codigo PHP</a>';
            } else {
                echo '<small>Sin asignar: usa la plantilla del tema, si existe; en ultimo termino, index.</small> ';
                echo '<a href="' . esc_url(self::admin_url('editor',array('tct_assign'=>$key))) . '">Crear desde archivo original / editor de texto</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $last = (int)ceil($total / $page_size);
        if ($last > 1) {
            echo '<p>';
            for ($p=1; $p <= $last; $p++) {
                $url = self::admin_url('requests',array(
                    'tct_group'=>$selected_group, 'tct_search'=>$search, 'tct_paged'=>$p
                ));
                echo ($p === $page) ? ' <strong>' . (int)$p . '</strong> ' :
                    ' <a href="' . esc_url($url) . '">' . (int)$p . '</a> ';
            }
            echo '</p>';
        }
        echo '<p class="description">Las plantillas WooCommerce por bloques (HTML) se editan con el Editor del sitio; este gestor asigna archivos PHP clasicos.</p>';
        if (!$embedded) {
            echo '</div>';
        }
    }
}
