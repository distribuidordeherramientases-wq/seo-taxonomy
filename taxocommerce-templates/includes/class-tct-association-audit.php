<?php
/**
 * Auditoria no destructiva de asociaciones de plantillas antiguas y modernas.
 *
 * Fuente de verdad: seo_templates, seo_nodes y seo_mail_templates, conservadas
 * en la misma base de datos del sitio. No presupone que un archivo registrado
 * sea ejecutable por el modulo independiente.
 */
defined('ABSPATH') || exit;

final class TCT_Association_Audit {
    public static function legacy_directory() {
        $directories = array();
        if (defined('SEO_SYSTEM_PATH')) {
            $directories[] = trailingslashit(SEO_SYSTEM_PATH) . 'seo-system/templates/';
        }
        if (defined('WP_PLUGIN_DIR')) {
            $directories[] = trailingslashit(WP_PLUGIN_DIR) . 'seo-taxonomy/seo-system/templates/';
            $directories[] = trailingslashit(WP_PLUGIN_DIR) . 'taxocommerce-seo/seo-system/templates/';
        }
        foreach (array_unique($directories) as $candidate) {
            if (is_dir($candidate) && is_readable($candidate)) {
                return trailingslashit($candidate);
            }
        }
        return '';
    }

    public static function legacy_path($file) {
        $dir = self::legacy_directory();
        if (!$dir || !TCT_Router::valid_filename($file)) {
            return '';
        }
        $path = $dir . $file;
        return is_file($path) && is_readable($path) && !is_link($path) ? $path : '';
    }

    public static function mail_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_mail_templates';
    }

    private static function table_exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s', $wpdb->esc_like($table)
        ));
    }

    public static function grouped_nodes() {
        global $wpdb;
        $table = TaxoCommerce_Templates::nodes();
        $rows = (array)$wpdb->get_results(
            "SELECT seo_role, object_type, COUNT(*) AS total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active_total
             FROM {$table} GROUP BY seo_role, object_type
             ORDER BY seo_role ASC, object_type ASC"
        );
        $by_key = array();
        foreach ($rows as $row) {
            $key = (string)$row->seo_role;
            if (!isset($by_key[$key])) {
                $by_key[$key] = array();
            }
            $by_key[$key][$row->object_type] = array(
                'active' => (int)$row->active_total,
                'total' => (int)$row->total
            );
        }
        return $by_key;
    }

    private static function assignment_detail($entries) {
        $parts = array();
        $count = 0;
        foreach ($entries as $type => $values) {
            if ($values['active'] > 0) {
                $parts[] = $type . ': ' . $values['active'];
                $count += $values['active'];
            }
        }
        return array('count' => $count, 'summary' => implode(', ', $parts));
    }

    public static function templates_report() {
        if (!TaxoCommerce_Templates::installed()) {
            return array();
        }
        $nodes = self::grouped_nodes();
        $rows = TCT_Router::rows();
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            $key = (string)$row->template_key;
            $seen[$key] = true;
            $file = (string)$row->template_file;
            $modern_path = $file ? TCT_Router::path($file) : '';
            $old_path = $file ? self::legacy_path($file) : '';
            $group = isset($nodes[$key]) ? $nodes[$key] : array();
            $assigned = self::assignment_detail($group);
            $variants = array();
            $missing_variants = array();
            if ($file && ((int)$row->device_variants_enabled === 1 || TCT_Router::is_dispatcher($old_path))) {
                foreach (array('mobile','desktop') as $device) {
                    $variant = TCT_Router::variant_name($file, $device);
                    if (!$variant) {
                        continue;
                    }
                    $modern_variant = TCT_Router::path($variant);
                    $old_variant = self::legacy_path($variant);
                    $variants[] = array(
                        'device' => $device,
                        'file' => $variant,
                        'modern' => $modern_variant !== '',
                        'legacy' => $old_variant !== ''
                    );
                    if (!$modern_variant && $old_variant) {
                        $missing_variants[] = $variant;
                    }
                }
            }

            if ($file === '') {
                $status = 'Sin archivo asignado';
            } elseif ($modern_path) {
                $status = $missing_variants ? 'Principal disponible, faltan variantes' : 'Archivo disponible en nuevo modulo';
            } elseif ($old_path) {
                $status = 'Solo existe en el plugin antiguo';
            } else {
                $status = 'Archivo no encontrado';
            }

            $result[$key] = array(
                'key' => $key,
                'name' => (string)$row->template_name,
                'file' => $file,
                'type' => (string)$row->template_type,
                'mode' => (string)$row->assignment_mode,
                'active' => (int)$row->is_active === 1,
                'is_assignable' => (int)$row->is_assignable === 1,
                'object_count' => $assigned['count'],
                'object_types' => $assigned['summary'],
                'modern' => (bool)$modern_path,
                'legacy' => (bool)$old_path,
                'variants' => $variants,
                'missing_variants' => $missing_variants,
                'status' => $status,
                'orphan' => false
            );
        }

        // Nunca se pierden silenciosamente roles antiguos de seo_nodes, aunque
        // alguien haya borrado la definicion correspondiente de seo_templates.
        foreach ($nodes as $key => $group) {
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $assigned = self::assignment_detail($group);
            if ($assigned['count'] < 1) {
                continue;
            }
            $result[$key] = array(
                'key' => $key,
                'name' => 'Rol existente sin ficha de plantilla',
                'file' => '',
                'type' => '',
                'mode' => '',
                'active' => false,
                'is_assignable' => false,
                'object_count' => $assigned['count'],
                'object_types' => $assigned['summary'],
                'modern' => false,
                'legacy' => false,
                'variants' => array(),
                'missing_variants' => array(),
                'status' => 'Rol huerfano: falta seo_templates',
                'orphan' => true
            );
        }
        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    public static function mail_report($templates = array()) {
        $table = self::mail_table();
        if (!self::table_exists($table)) {
            return array();
        }
        global $wpdb;
        $rows = (array)$wpdb->get_results(
            "SELECT mail_key, mail_name, template_key, is_enabled,
                    default_template, default_plain_template
             FROM {$table} ORDER BY mail_key"
        );
        $out = array();
        foreach ($rows as $mail) {
            $key = (string)$mail->template_key;
            $definition = isset($templates[$key]) ? $templates[$key] : null;
            $out[] = array(
                'mail_key' => (string)$mail->mail_key,
                'name' => (string)$mail->mail_name,
                'template_key' => $key,
                'template_file' => $definition ? $definition['file'] : '',
                'enabled' => (int)$mail->is_enabled === 1,
                'has_template' => (bool)$definition,
                'modern' => $definition && $definition['modern'],
                'legacy' => $definition && $definition['legacy'],
                'default_template' => (string)$mail->default_template,
                'default_plain_template' => (string)$mail->default_plain_template
            );
        }
        return $out;
    }

    public static function summary($templates, $mails) {
        $count = array(
            'registered' => 0,
            'objects' => 0,
            'present_new' => 0,
            'missing_new' => 0,
            'present_old_only' => 0,
            'orphan_roles' => 0,
            'email_enabled' => 0,
            'email_unavailable' => 0
        );
        foreach ($templates as $row) {
            $count['registered']++;
            $count['objects'] += $row['object_count'];
            if ($row['orphan']) {
                $count['orphan_roles']++;
                continue;
            }
            if ($row['modern']) {
                $count['present_new']++;
            } elseif ($row['file'] !== '') {
                $count['missing_new']++;
                if ($row['legacy']) {
                    $count['present_old_only']++;
                }
            }
        }
        foreach ($mails as $mail) {
            if ($mail['enabled']) {
                $count['email_enabled']++;
                if (!$mail['modern']) {
                    $count['email_unavailable']++;
                }
            }
        }
        return $count;
    }

    /**
     * La presencia de las filas en la base de datos no acredita la
     * compatibilidad funcional de los PHP heredados.
     */
    public static function takeover_blockers() {
        $rows = self::templates_report();
        $mails = self::mail_report($rows);
        $native = TCT_Request_Registry::definitions(false);
        $legacy_loader_keys = array_fill_keys(array(
            'cluster', 'hub_primary', 'hub_secondary', 'landing',
            'corporate_page', 'shop', 'front_page', 'blog_index',
            'taxonomy_category', 'thankyou', 'checkout', 'cart',
            'search', 'taxonomy_product_cat', 'single_post',
            'single_product', 'myaccount', 'error_404'
        ), true);
        $blockers = array();
        foreach ($rows as $row) {
            if ($row['orphan'] && $row['object_count'] > 0) {
                $blockers[] = $row['key'] . ': hay objetos vinculados pero falta la ficha del rol.';
                continue;
            }
            if (!$row['active'] || $row['file'] === '') {
                continue;
            }
            // Claves optativas que se sembraron en 0.3.0, sin objetos ni
            // mecanismo de carga activo, no deben impedir la sustitucion.
            // Los correos habilitados se comprueban independientemente.
            if (!$row['modern'] && $row['object_count'] === 0 &&
                !isset($native[$row['key']]) &&
                !isset($legacy_loader_keys[$row['key']])) {
                continue;
            }
            if (!$row['modern']) {
                $blockers[] = $row['key'] . ': falta ' . $row['file'] . ' en el modulo nuevo.';
            }
            foreach ($row['missing_variants'] as $variant) {
                $blockers[] = $row['key'] . ': falta secundaria ' . $variant . '.';
            }
        }
        foreach ($mails as $mail) {
            if ($mail['enabled'] && !$mail['modern']) {
                $blockers[] = 'Correo ' . $mail['mail_key'] . ': plantilla heredada no disponible en el modulo nuevo.';
            }
        }
        return array_values(array_unique($blockers));
    }

    public static function takeover_report() {
        $blockers = self::takeover_blockers();
        return array(
            'file_blockers' => $blockers,
            'files_present' => count($blockers) === 0,
            // Una comprobacion de existencia NO implica equivalencia visual o
            // funcional, sobre todo con dependencias antiguas y assets externos.
            'functional_equivalence_verified' => false
        );
    }

    public static function sample_objects($role, $limit = 6) {
        global $wpdb;
        $limit = min(10, max(1, absint($limit)));
        $nodes = TaxoCommerce_Templates::nodes();
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT object_type, object_id FROM {$nodes}
             WHERE seo_role = %s AND status = 1
             ORDER BY object_type ASC, object_id ASC LIMIT %d",
            $role, $limit
        ));
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        check_admin_referer('tct_export_associations');
        if (!TaxoCommerce_Templates::installed()) {
            wp_die('Faltan tablas.');
        }
        $rows = self::templates_report();
        $mails = self::mail_report($rows);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="taxocommerce_asociaciones.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die('No se pudo generar el CSV.');
        }
        // Evitar formulas de hoja de calculo al abrir nombres de DB en CSV.
        $safe = static function($value) {
            $text = (string)$value;
            return preg_match('/^[\s]*[=+@-]/u', $text) ? "'" . $text : $text;
        };
        fputcsv($output, array('origen','clave','nombre','archivo','tipo','modo',
            'activo','objetos_asignados','tipos_objeto','archivo_nuevo','archivo_antiguo','variantes_pendientes','estado'));
        foreach ($rows as $item) {
            fputcsv($output, array_map($safe, array(
                'seo_templates', $item['key'], $item['name'], $item['file'],
                $item['type'], $item['mode'], $item['active'] ? 1 : 0,
                $item['object_count'], $item['object_types'],
                $item['modern'] ? 1 : 0, $item['legacy'] ? 1 : 0,
                implode('|', $item['missing_variants']), $item['status']
            )));
        }
        foreach ($mails as $mail) {
            fputcsv($output, array_map($safe, array(
                'seo_mail_templates', $mail['mail_key'], $mail['name'],
                $mail['template_file'], 'email', 'automatic',
                $mail['enabled'] ? 1 : 0, 0, $mail['template_key'],
                $mail['modern'] ? 1 : 0, $mail['legacy'] ? 1 : 0,
                '', $mail['enabled'] && !$mail['modern'] ? 'Correo pendiente de archivo nuevo' : 'Registrado'
            )));
        }
        fclose($output);
        exit;
    }

    public static function restore_missing_roles() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        check_admin_referer('tct_restore_missing_roles');
        if (!TaxoCommerce_Templates::installed()) {
            wp_die('Faltan las tablas de asociaciones.');
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $all = self::grouped_nodes();
        $existing = array_fill_keys((array)$wpdb->get_col(
            "SELECT template_key FROM {$table}"), true);
        $created = 0;
        foreach ($all as $key => $groups) {
            if ($key === '' || isset($existing[$key]) ||
                sanitize_key($key) !== $key || strlen($key) > 80) {
                continue;
            }
            $assigned = self::assignment_detail($groups);
            if ($assigned['count'] < 1) {
                continue;
            }
            $is_page = !empty($groups['page']['active']);
            $now = current_time('mysql');
            $ok = $wpdb->insert($table, array(
                'template_key' => $key,
                'template_name' => ucwords(str_replace(array('_','-'), ' ', $key)),
                'template_file' => '',
                'template_type' => $is_page ? 'page' : 'content',
                'assignment_mode' => $is_page ? 'multiple' : 'automatic',
                'is_assignable' => $is_page ? 1 : 0,
                'is_active' => 0,
                'is_public' => 1,
                'created_at' => $now,
                'updated_at' => $now
            ));
            if (false !== $ok) {
                $created++;
                $existing[$key] = true;
            }
        }
        wp_safe_redirect(add_query_arg(array(
            'page' => 'taxocommerce-templates',
            'tab' => 'audit',
            'tct_notice' => sprintf('Roles reparados sin modificar asignaciones: %d.', $created)
        ), admin_url('admin.php')));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        echo '<div class="wrap"><h1>Auditoria de asociaciones antiguas y modernas</h1>';
        if (!TaxoCommerce_Templates::installed()) {
            echo '<p>Tablas de asociaciones no disponibles.</p></div>';
            return;
        }
        $rows = self::templates_report();
        $mails = self::mail_report($rows);
        $totals = self::summary($rows, $mails);
        $legacy = self::legacy_directory();
        echo '<p>Los dos gestores utilizan las mismas filas de <code>seo_templates</code> y <code>seo_nodes</code> en ESTA instalacion.';
        echo ' No se cambian los roles ni los objetos ya asignados. Esta auditoria comprueba si la biblioteca del nuevo modulo dispone de sus archivos.</p>';
        echo '<p><strong>Origen de archivos antiguos:</strong> ' . ($legacy ? esc_html($legacy) : 'No se ha localizado el directorio antiguo') . '</p>';
        echo '<p><strong>Registradas:</strong> ' . (int)$totals['registered'] .
            ' · <strong>Objetos asociados activos:</strong> ' . (int)$totals['objects'] .
            ' · <strong>Archivos nuevos disponibles:</strong> ' . (int)$totals['present_new'] .
            ' · <strong>Archivos pendientes:</strong> ' . (int)$totals['missing_new'] .
            ' · <strong>Solo en plugin antiguo:</strong> ' . (int)$totals['present_old_only'] .
            ' · <strong>Roles sin registro:</strong> ' . (int)$totals['orphan_roles'] . '</p>';
        $takeover = self::takeover_report();
        echo '<h2>Comprobacion de cambio de plugin</h2>';
        if (!$takeover['files_present']) {
            echo '<div class="notice notice-error inline"><p><strong>No desactives todavia el gestor antiguo:</strong> hay ' .
                (int)count($takeover['file_blockers']) . ' incidencias de archivos o relaciones pendientes.</p>';
            echo '<details><summary>Ver incidencias de sustitucion</summary><ul>';
            foreach ($takeover['file_blockers'] as $blocker) {
                echo '<li>' . esc_html($blocker) . '</li>';
            }
            echo '</ul></details></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>Se han localizado todos los archivos activos registrados, ';
            echo 'pero no se ha verificado la equivalencia funcional ni los recursos. Mantener el motor nuevo desactivado.</p></div>';
        }
        echo '<div class="notice notice-warning inline"><p>Una asociacion guardada NO confirma que su PHP funcione sin el plugin antiguo. ';
        echo 'Las funciones, assets y dependencias deben migrarse y probarse antes de activar el motor de plantillas.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        wp_nonce_field('tct_export_associations');
        echo '<input type="hidden" name="action" value="tct_export_associations">';
        submit_button('Exportar todas las asociaciones CSV','secondary','submit',false);
        echo '</form> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        wp_nonce_field('tct_restore_missing_roles');
        echo '<input type="hidden" name="action" value="tct_restore_missing_roles">';
        submit_button('Recrear fichas de roles huerfanos (sin alterar relaciones)','secondary','submit',false);
        echo '</form>';

        echo '<h2>Asociaciones y archivos</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Rol / tipo</th><th>Archivo asignado</th><th>Objetos</th><th>Antiguo</th><th>Nuevo</th><th>Estado</th></tr></thead><tbody>';
        foreach ($rows as $item) {
            echo '<tr><td><strong>' . esc_html($item['name']) . '</strong><br><code>' . esc_html($item['key']) . '</code></td>';
            echo '<td><code>' . esc_html($item['file'] ? $item['file'] : '(sin asignar)') . '</code></td>';
            echo '<td>' . (int)$item['object_count'];
            if ($item['object_types'] !== '') {
                echo '<br><small>' . esc_html($item['object_types']) . '</small>';
            }
            if ($item['object_count'] > 0) {
                echo '<details><summary>Ejemplos de objetos</summary>';
                foreach (self::sample_objects($item['key']) as $obj) {
                    $name = $obj->object_type . ' #' . (int)$obj->object_id;
                    if (in_array($obj->object_type, array('page','post','product'), true)) {
                        $title = get_the_title((int)$obj->object_id);
                        if ($title) {
                            $name = $title . ' (' . $obj->object_type . ' #' . (int)$obj->object_id . ')';
                        }
                    } elseif (function_exists('get_term')) {
                        $term = get_term((int)$obj->object_id);
                        if ($term && !is_wp_error($term)) {
                            $name = $term->name . ' (' . $obj->object_type . ' #' . (int)$obj->object_id . ')';
                        }
                    }
                    echo '<div>' . esc_html($name) . '</div>';
                }
                echo '</details>';
            }
            echo '</td><td>' . ($item['legacy'] ? 'Si' : 'No') . '</td>';
            echo '<td>' . ($item['modern'] ? 'Si' : 'No') . '</td>';
            echo '<td>' . esc_html($item['status']);
            if ($item['missing_variants']) {
                echo '<br><small>' . esc_html(implode(', ', $item['missing_variants'])) . '</small>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Correos WooCommerce del gestor antiguo</h2>';
        if (!$mails) {
            echo '<p>No existen asignaciones antiguas de correo en esta base de datos.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Correo</th><th>Clave de plantilla</th><th>Archivo</th><th>Activo</th><th>Disponible en nuevo</th></tr></thead><tbody>';
            foreach ($mails as $mail) {
                echo '<tr><td>' . esc_html($mail['name']) . '<br><code>' . esc_html($mail['mail_key']) . '</code></td>';
                echo '<td><code>' . esc_html($mail['template_key']) . '</code></td><td>' . esc_html($mail['template_file']) . '</td>';
                echo '<td>' . ($mail['enabled'] ? 'Si' : 'No') . '</td><td>' . ($mail['modern'] ? 'Si' : 'No') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">Diagnostico local: no supone que STAGING tenga los mismos datos que produccion si la clonacion esta desactualizada.</p>';
        echo '</div>';
    }
}
