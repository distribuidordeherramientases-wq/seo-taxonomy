<?php
/*
Plugin Name: SEO Menu Manager
Plugin URI: https://www.distribuidordeherramientas.es/
Description: Gestión dinámica y escalable de plantillas SEO, archivos y asignaciones.
Version: 2.1.0
Requires PHP: 7.4
Author: David Perez Martorell
*/

if (!defined('ABSPATH')) exit;

/* =========================================================
   RUTAS Y CONSTANTES DEL GESTOR
========================================================= */

if (!function_exists('seo_template_dir')) {
    function seo_template_dir() {
        return WP_PLUGIN_DIR . '/seo-taxonomy/seo-system/templates/';
    }
}

function seo_tm_admin_url($tab = 'asignar', array $args = []) {
    $url = admin_url('admin.php?page=seo-templates&tab=' . sanitize_key($tab));

    if (!empty($args)) {
        $url = add_query_arg($args, $url);
    }

    return $url;
}

function seo_tm_cart_style_admin_url() {
    return add_query_arg(
        [
            'page' => 'seo-menu-marketing',
            'tab'  => 'style',
        ],
        admin_url('admin.php')
    ) . '#seo-style-cart';
}

function seo_tm_checkout_style_admin_url() {
    return add_query_arg(
        [
            'page' => 'seo-menu-marketing',
            'tab'  => 'style',
        ],
        admin_url('admin.php')
    ) . '#seo-style-checkout';
}

function seo_tm_redirect($tab, $message, $type = 'success') {
    $url = seo_tm_admin_url($tab, [
        'seo_tm_notice'      => $message,
        'seo_tm_notice_type' => $type,
    ]);

    if (!headers_sent()) {
        wp_safe_redirect($url);
        exit;
    }

    echo '<script>window.location.href=' . wp_json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '"></noscript>';
    exit;
}

function seo_tm_render_notice() {
    if (empty($_GET['seo_tm_notice'])) return;

    $message = sanitize_text_field(wp_unslash($_GET['seo_tm_notice']));
    $type    = isset($_GET['seo_tm_notice_type']) ? sanitize_key($_GET['seo_tm_notice_type']) : 'success';

    $allowed = ['success', 'warning', 'error', 'info'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

function seo_tm_template_types() {
    return apply_filters('seo_tm_template_types', [
        'page'     => 'Página',
        'system'   => 'Sistema',
        'content'  => 'Contenido',
        'taxonomy' => 'Taxonomía',
        'email'    => 'Email',
        'other'    => 'Otro',
    ]);
}

function seo_tm_assignment_modes() {
    return apply_filters('seo_tm_assignment_modes', [
        'automatic' => 'Automática / sin página',
        'single'    => 'Una página',
        'multiple'  => 'Varias páginas',
    ]);
}

/**
 * Garantiza que las plantillas automáticas incorporadas al plugin existan
 * también en instalaciones ya creadas, sin exigir una asignación manual.
 *
 * Se usa INSERT IGNORE para no sobrescribir la activación ni el archivo
 * elegidos posteriormente desde el Gestor de Plantillas.
 */
function seo_tm_ensure_builtin_templates() {
    global $wpdb;

    $catalog_version = '1.0.0';

    if (version_compare((string) get_option('seo_tm_builtin_templates_version', '0.0.0'), $catalog_version, '>=')) {
        return;
    }

    $table = $wpdb->prefix . 'seo_templates';

    $table_exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ));

    if ($table_exists !== $table) {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table}
            (template_key, template_name, template_file, is_active)
         VALUES (%s, %s, %s, 1)",
        'blog_index',
        'Blog / Noticias',
        'template-blog.php'
    ));

    /*
     * Las columnas ampliadas no existen en instalaciones muy antiguas.
     * Si ya están disponibles, fijamos únicamente las propiedades
     * estructurales de blog_index; activo/público siguen siendo editables.
     */
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

    if (
        in_array('template_type', $columns, true) &&
        in_array('assignment_mode', $columns, true) &&
        in_array('is_assignable', $columns, true)
    ) {
        $wpdb->query(
            "UPDATE {$table}
             SET template_type = 'content',
                 assignment_mode = 'automatic',
                 is_assignable = 0
             WHERE template_key = 'blog_index'"
        );
    }

    update_option('seo_tm_builtin_templates_version', $catalog_version, false);
}

add_action('plugins_loaded', 'seo_tm_ensure_builtin_templates', 20);

/* =========================================================
   AMPLIACIÓN AUTOMÁTICA DE wp_seo_templates

   No elimina ni renombra columnas existentes.
========================================================= */

function seo_tm_ensure_schema() {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_templates';

    $table_exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ));
    if ($table_exists !== $table) {
        return new WP_Error('missing_templates_table', 'No existe la tabla ' . $table . '.');
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

    $alterations = [
        'template_type'            => "ALTER TABLE {$table} ADD template_type varchar(30) NOT NULL DEFAULT 'page'",
        'is_public'                => "ALTER TABLE {$table} ADD is_public tinyint(1) NOT NULL DEFAULT 1",
        'is_assignable'            => "ALTER TABLE {$table} ADD is_assignable tinyint(1) NOT NULL DEFAULT 0",
        'assignment_mode'          => "ALTER TABLE {$table} ADD assignment_mode varchar(20) NOT NULL DEFAULT 'automatic'",
        'display_order'            => "ALTER TABLE {$table} ADD display_order int(11) NOT NULL DEFAULT 0",
        'description'              => "ALTER TABLE {$table} ADD description text NULL",
        'device_variants_enabled'  => "ALTER TABLE {$table} ADD device_variants_enabled tinyint(1) NOT NULL DEFAULT 0",
    ];

    foreach ($alterations as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $result = $wpdb->query($sql);

            if ($result === false) {
                return new WP_Error(
                    'schema_update_failed',
                    'No se pudo añadir la columna ' . $column . ': ' . $wpdb->last_error
                );
            }
        }
    }

    $schema_version = (string) get_option('seo_tm_schema_version', '0.0.0');

    /*
     * Valores iniciales únicamente para migrar el gestor antiguo.
     * Después de esta primera ejecución, todo se administra desde la tabla.
     */
    if (version_compare($schema_version, '2.0.0', '<')) {

        $wpdb->query("UPDATE {$table} SET is_active = 1 WHERE is_active IS NULL");
        $wpdb->query("UPDATE {$table} SET is_public = 1 WHERE is_public IS NULL");
        $wpdb->query("UPDATE {$table} SET assignment_mode = 'automatic' WHERE assignment_mode = '' OR assignment_mode IS NULL");

        $email_keys = $wpdb->esc_like('email_') . '%';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET template_type = 'email', assignment_mode = 'automatic', is_assignable = 0
             WHERE template_key LIKE %s",
            $email_keys
        ));

        $wpdb->query(
            "UPDATE {$table}
             SET template_type = 'content', assignment_mode = 'automatic', is_assignable = 0
             WHERE template_key LIKE 'single\\_%'"
        );

        $wpdb->query(
            "UPDATE {$table}
             SET template_type = 'content', assignment_mode = 'automatic', is_assignable = 0
             WHERE template_key = 'blog_index'"
        );

        $wpdb->query(
            "UPDATE {$table}
             SET template_type = 'taxonomy', assignment_mode = 'automatic', is_assignable = 0
             WHERE template_key LIKE 'taxonomy\\_%'"
        );

        $automatic_system_keys = [
            'front_page',
            'search',
            'cart',
            'checkout',
            'thankyou',
            'myaccount',
            'error_404',
        ];

        $placeholders = implode(',', array_fill(0, count($automatic_system_keys), '%s'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET template_type = 'system', assignment_mode = 'automatic', is_assignable = 0
             WHERE template_key IN ({$placeholders})",
            ...$automatic_system_keys
        ));

        $legacy_assignable_keys = [
            'cluster',
            'hub_primary',
            'hub_secondary',
            'landing',
            'corporate_page',
        ];

        $placeholders = implode(',', array_fill(0, count($legacy_assignable_keys), '%s'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET template_type = 'page', assignment_mode = 'multiple', is_assignable = 1
             WHERE template_key IN ({$placeholders})",
            ...$legacy_assignable_keys
        ));

        /* Conservar cualquier rol de página que ya estuviera asignado. */
        $nodes_table = $wpdb->prefix . 'seo_nodes';
        $nodes_exists = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($nodes_table)
        ));

        if ($nodes_exists === $nodes_table) {
            $wpdb->query(
                "UPDATE {$table} AS templates
                 INNER JOIN {$nodes_table} AS nodes
                    ON nodes.seo_role = templates.template_key
                   AND nodes.object_type = 'page'
                 SET templates.template_type = 'page',
                     templates.assignment_mode = 'multiple',
                     templates.is_assignable = 1"
            );
        }

        $single_page_keys = ['login', 'register', 'lost_password'];
        $placeholders = implode(',', array_fill(0, count($single_page_keys), '%s'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET template_type = 'page', assignment_mode = 'single'
             WHERE template_key IN ({$placeholders})",
            ...$single_page_keys
        ));

        $schema_version = '2.0.0';
        update_option('seo_tm_schema_version', $schema_version, false);
    }

    /*
     * 2.1.0: las variantes móvil/escritorio dejan de ser filas asignables.
     * Solo se guarda si la plantilla principal desea resolver por dispositivo.
     *
     * Los principales legacy que ya son simples dispatchers se activan
     * automáticamente para mantener exactamente el comportamiento anterior.
     */
    if (version_compare($schema_version, '2.1.0', '<')) {
        $templates = $wpdb->get_results(
            "SELECT template_key, template_file
             FROM {$table}"
        );

        foreach ((array) $templates as $template) {
            $principal_path = trailingslashit(seo_template_dir()) . basename((string) $template->template_file);

            if (seo_template_is_device_dispatcher($principal_path)) {
                $wpdb->update(
                    $table,
                    ['device_variants_enabled' => 1],
                    ['template_key' => $template->template_key],
                    ['%d'],
                    ['%s']
                );
            }
        }

        $schema_version = '2.1.0';
        update_option('seo_tm_schema_version', $schema_version, false);
    }

    return true;
}


/* =========================================================
   CONSULTAS Y UTILIDADES
========================================================= */

function seo_tm_get_templates($where = '', array $params = []) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_templates';

    $sql = "SELECT * FROM {$table}";

    if ($where !== '') {
        $sql .= ' WHERE ' . $where;
    }

    $sql .= ' ORDER BY display_order ASC, template_name ASC, id ASC';

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    return $wpdb->get_results($sql);
}

function seo_tm_get_template_by_key($template_key) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_templates';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE template_key = %s LIMIT 1",
        $template_key
    ));
}

function seo_tm_get_php_files() {
    $directory = trailingslashit(seo_template_dir());

    if (!is_dir($directory)) {
        wp_mkdir_p($directory);
    }

    $files = glob($directory . '*.php');
    if (!is_array($files)) return [];

    $result = [];

    foreach ($files as $file) {
        $name = basename($file);

        /* No ofrecer backups automáticos como plantillas nuevas. */
        if (preg_match('/_[0-9]{8}_[0-9]{6}\.php$/', $name)) {
            continue;
        }

        $result[$name] = $file;
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

    return $result;
}

/**
 * Indica si el archivo principal puede tener variantes por convención.
 */
function seo_tm_template_supports_device_variants($template) {
    if (!$template || empty($template->template_file)) {
        return false;
    }

    if (isset($template->template_type) && (string) $template->template_type === 'email') {
        return false;
    }

    $file_name = basename((string) $template->template_file);

    if (strpos($file_name, 'email-') === 0) {
        return false;
    }

    if (in_array($file_name, ['header.php', 'footer.php', 'faq-form.php', 'template-helpers.php'], true)) {
        return false;
    }

    return seo_template_variant_filename($file_name, 'mobile') !== '';
}

function seo_tm_find_variant_parent_by_filename($file_name) {
    $file_name = basename((string) $file_name);

    if ($file_name === '') {
        return null;
    }

    foreach (seo_tm_get_templates() as $template) {
        if (!seo_tm_template_supports_device_variants($template)) {
            continue;
        }

        foreach (['mobile', 'desktop'] as $variant) {
            if (seo_template_variant_filename($template->template_file, $variant) === $file_name) {
                return [
                    'template' => $template,
                    'variant'  => $variant,
                ];
            }
        }
    }

    return null;
}

function seo_tm_get_device_plan($template, $device) {
    return seo_template_device_render_plan(
        $template,
        $device,
        trailingslashit(seo_template_dir())
    );
}

function seo_tm_device_source_label(array $plan) {
    if ($plan['source'] === 'primary') {
        return 'Principal';
    }

    if ($plan['fallback'] === 'legacy_opposite') {
        return 'Secundaria de respaldo';
    }

    return 'Secundaria';
}

/**
 * Resumen visible dentro de la asignación.
 *
 * La página sigue guardando exclusivamente template_key -> plantilla
 * principal. Este control solo decide cómo se resuelve esa principal al
 * renderizar en móvil y escritorio.
 */
function seo_tm_render_assignment_device_panel($template) {
    if (!seo_tm_template_supports_device_variants($template)) {
        return;
    }

    $mobile        = seo_tm_get_device_plan($template, 'mobile');
    $desktop       = seo_tm_get_device_plan($template, 'desktop');
    $dispatcher    = !empty($mobile['dispatcher']);
    $enabled       = !empty($mobile['variants_enabled']);
    $plugin_active = isset($template->is_active) && (int) $template->is_active === 1;
    $field_name    = 'device_variants_enabled[' . esc_attr($template->template_key) . ']';

    echo '<div class="seo-tm-device-panel">';
    echo '<div class="seo-tm-device-panel-head">';
    echo '<div>';
    echo '<strong>Renderizado por dispositivo</strong>';
    echo '<p class="description">La página solo se asigna a <code>' . esc_html($mobile['principal_file']) . '</code>. Las secundarias nunca se asignan a la página.</p>';
    echo '</div>';
    echo '<span class="seo-tm-badge ' . ($plugin_active && $enabled ? 'is-ok' : 'is-muted') . '">' . (!$plugin_active ? 'Plugin inactivo' : ($enabled ? 'Secundarias activas' : 'Solo principal')) . '</span>';
    echo '</div>';

    if (!$plugin_active) {
        echo '<p class="description"><strong>Ahora mismo el plugin no publica esta plantilla</strong>; WordPress o WooCommerce conserva su plantilla normal hasta que la actives.</p>';
    }

    if ($dispatcher) {
        echo '<input type="hidden" name="' . $field_name . '" value="1">';
        echo '<label><input type="checkbox" checked disabled> Usar plantillas específicas para teléfono y ordenador</label>';
        echo '<p class="description"><strong>Obligatorio actualmente:</strong> la principal es un gestor técnico y no contiene el diseño final. Para poder usar solo la principal, primero reemplázala por una plantilla completa desde «Archivos de plantilla».</p>';
    } else {
        echo '<label><input type="checkbox" name="' . $field_name . '" value="1" ' . checked($enabled, true, false) . '> Usar plantillas específicas para teléfono y ordenador</label>';
        echo '<p class="description">Si una secundaria no existe, se publica automáticamente la principal. Puedes activar el modo antes o después de subir las secundarias.</p>';
    }

    echo '<div class="seo-tm-device-effective-grid">';

    foreach (['mobile' => $mobile, 'desktop' => $desktop] as $device => $plan) {
        $label = $device === 'mobile' ? 'Teléfono' : 'Ordenador';
        $kind  = seo_tm_device_source_label($plan);

        echo '<div class="seo-tm-device-effective">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '<span class="seo-tm-badge ' . ($plan['source'] === 'primary' ? 'is-muted' : 'is-ok') . '">' . esc_html($kind) . '</span>';
        echo '<code>' . esc_html($plan['effective_file']) . '</code>';

        if (!$plan['variant_exists'] && $enabled && !$dispatcher) {
            echo '<small>Secundaria no creada: se usa la principal.</small>';
        } elseif ($plan['fallback'] === 'legacy_opposite') {
            echo '<small>Falta la secundaria de este dispositivo; se usa temporalmente la otra secundaria.</small>';
        }

        echo '</div>';
    }

    echo '</div>';
    echo '<p class="description"><a href="' . esc_url(seo_tm_admin_url('archivos')) . '#seo-tm-template-' . esc_attr($template->template_key) . '">Ver, subir o reemplazar los archivos de esta plantilla</a></p>';
    echo '</div>';
}

function seo_tm_render_variant_file_card($template, $variant, array $plan) {
    $label       = $variant === 'mobile' ? 'Teléfono' : 'Ordenador';
    $file_name     = $plan['variant_file'];
    $file_path     = $plan['variant_path'];
    $file_exists   = !empty($plan['variant_exists']);
    $plugin_active = isset($template->is_active) && (int) $template->is_active === 1;
    $is_effective  = $plugin_active && $plan['source'] === $variant;
    $publish_label = !$plugin_active
        ? 'Plugin inactivo'
        : ($is_effective ? 'Se publica' : 'No se publica');

    echo '<div class="seo-tm-variant-card">';
    echo '<div class="seo-tm-variant-heading">';
    echo '<div><strong>' . esc_html($label) . '</strong><br><code>' . esc_html($file_name) . '</code></div>';
    echo '<div class="seo-tm-badges">';
    echo '<span class="seo-tm-badge ' . ($file_exists ? 'is-ok' : 'is-error') . '">' . ($file_exists ? 'Archivo disponible' : 'Sin archivo') . '</span>';
    echo '<span class="seo-tm-badge ' . ($is_effective ? 'is-ok' : 'is-muted') . '">' . esc_html($publish_label) . '</span>';
    echo '</div>';
    echo '</div>';

    if ($file_exists) {
        $file_size = size_format(filesize($file_path), 1);
        $modified  = date_i18n('d/m/Y H:i', filemtime($file_path));

        echo '<p><strong>Tamaño:</strong> ' . esc_html($file_size) . ' · <strong>Modificado:</strong> ' . esc_html($modified) . '</p>';

        $download_url = wp_nonce_url(
            admin_url(
                'admin-post.php?action=seo_tm_download_template&template_key=' .
                rawurlencode($template->template_key) .
                '&variant=' .
                rawurlencode($variant)
            ),
            'seo_tm_download_' . $template->template_key . '_' . $variant
        );

        echo '<p><a class="button" href="' . esc_url($download_url) . '">Descargar secundaria</a></p>';

        $file_content = file_get_contents($file_path);
        if ($file_content !== false) {
            echo '<details class="seo-tm-code-preview">';
            echo '<summary>Ver contenido de la secundaria</summary>';
            echo '<pre>' . esc_html($file_content) . '</pre>';
            echo '</details>';
        }
    } else {
        echo '<p class="description">No es obligatorio crearla. Mientras no exista, una plantilla principal completa actúa como respaldo.</p>';
    }

    echo '<form method="post" enctype="multipart/form-data" class="seo-tm-variant-upload">';
    wp_nonce_field('seo_tm_replace_variant_file', 'seo_tm_replace_variant_nonce');
    echo '<input type="hidden" name="template_key" value="' . esc_attr($template->template_key) . '">';
    echo '<input type="hidden" name="variant" value="' . esc_attr($variant) . '">';
    echo '<input type="file" name="template_file" accept=".php" required>';
    echo '<p><button type="submit" name="seo_tm_replace_variant_file" class="button">' . ($file_exists ? 'Reemplazar secundaria y crear backup' : 'Crear secundaria') . '</button></p>';
    echo '</form>';
    echo '</div>';
}

function seo_tm_render_device_files_panel($template) {
    if (!seo_tm_template_supports_device_variants($template)) {
        return;
    }

    $mobile       = seo_tm_get_device_plan($template, 'mobile');
    $desktop      = seo_tm_get_device_plan($template, 'desktop');
    $dispatcher   = !empty($mobile['dispatcher']);
    $enabled      = !empty($mobile['variants_enabled']);
    $has_variants = !empty($mobile['variant_exists']) || !empty($desktop['variant_exists']);
    $collapsed    = !$dispatcher && !$enabled && !$has_variants;

    if ($collapsed) {
        echo '<details class="seo-tm-variants-panel seo-tm-variants-collapsed">';
        echo '<summary><strong>Configurar plantillas específicas por dispositivo</strong> <span class="seo-tm-badge is-muted">Opcional</span></summary>';
        echo '<div class="seo-tm-variants-panel-inner">';
    } else {
        echo '<div class="seo-tm-variants-panel">';
    }

    echo '<div class="seo-tm-device-panel-head">';
    echo '<div>';
    echo '<h3>Plantillas específicas por dispositivo</h3>';
    echo '<p class="description">La principal sigue siendo la única plantilla registrada y asignada. Teléfono y ordenador son archivos secundarios asociados automáticamente por nombre.</p>';
    echo '</div>';
    echo '<span class="seo-tm-badge ' . ($enabled ? 'is-ok' : 'is-muted') . '">' . ($enabled ? 'Activadas' : 'Desactivadas') . '</span>';
    echo '</div>';

    if ($dispatcher) {
        echo '<div class="notice notice-info inline"><p><strong>Modo obligatorio.</strong> La plantilla principal actual es un dispatcher técnico. Las secundarias seguirán activas hasta que sustituyas la principal por una plantilla completa.</p></div>';
    } else {
        echo '<form method="post" class="seo-tm-variant-settings">';
        wp_nonce_field('seo_tm_variant_settings', 'seo_tm_variant_settings_nonce');
        echo '<input type="hidden" name="template_key" value="' . esc_attr($template->template_key) . '">';
        echo '<label><input type="checkbox" name="device_variants_enabled" value="1" ' . checked($enabled, true, false) . '> Activar plantilla específica para teléfono y ordenador</label>';
        echo '<p class="description">Desactivado: ambos dispositivos publican la principal. Activado: cada dispositivo usa su secundaria si existe; si falta, usa la principal.</p>';
        echo '<p><button type="submit" name="seo_tm_save_variant_settings" class="button">Guardar modo por dispositivo</button></p>';
        echo '</form>';
    }

    echo '<h4>Resultado efectivo</h4>';
    echo '<div class="seo-tm-device-effective-grid">';

    foreach (['mobile' => $mobile, 'desktop' => $desktop] as $device => $plan) {
        $label = $device === 'mobile' ? 'Teléfono' : 'Ordenador';
        $kind  = seo_tm_device_source_label($plan);

        echo '<div class="seo-tm-device-effective">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '<span class="seo-tm-badge ' . ($plan['source'] === 'primary' ? 'is-muted' : 'is-ok') . '">' . esc_html($kind) . '</span>';
        echo '<code>' . esc_html($plan['effective_file']) . '</code>';

        if ($plan['fallback'] === 'legacy_opposite') {
            echo '<small>Respaldo legacy: falta la secundaria de este dispositivo.</small>';
        } elseif (!$plan['variant_exists'] && $enabled && !$dispatcher) {
            echo '<small>Falta la secundaria: se publica la principal.</small>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="seo-tm-variant-grid">';
    seo_tm_render_variant_file_card($template, 'mobile', $mobile);
    seo_tm_render_variant_file_card($template, 'desktop', $desktop);
    echo '</div>';

    if ($collapsed) {
        echo '</div></details>';
    } else {
        echo '</div>';
    }
}


function seo_tm_validate_php_upload(array $uploaded_file) {
    if (empty($uploaded_file['tmp_name'])) {
        return new WP_Error('missing_file', 'No se ha seleccionado ningún archivo.');
    }

    if (!empty($uploaded_file['error'])) {
        return new WP_Error('upload_error', 'WordPress ha recibido un error durante la subida.');
    }

    if (!is_uploaded_file($uploaded_file['tmp_name'])) {
        return new WP_Error('invalid_upload', 'El archivo subido no es válido.');
    }

    $extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'php') {
        return new WP_Error('invalid_extension', 'Solo se permiten archivos PHP.');
    }

    $content = file_get_contents($uploaded_file['tmp_name']);
    if ($content === false || trim($content) === '') {
        return new WP_Error('empty_file', 'El archivo está vacío o no se puede leer.');
    }

    if (strpos($content, '&lt;') !== false || strpos($content, '&gt;') !== false) {
        return new WP_Error('escaped_php', 'El archivo parece estar escapado como HTML. Sube un PHP real.');
    }

    if (strpos($content, '<?php') === false) {
        return new WP_Error('missing_php_tag', 'La plantilla no contiene una apertura <?php.');
    }

    return true;
}

function seo_tm_create_backup($file_path) {
    if (!file_exists($file_path)) {
        return '';
    }

    $pathinfo    = pathinfo($file_path);
    $backup_name = $pathinfo['filename'] . '_' . date_i18n('Ymd_His') . '.php';
    $backup_path = trailingslashit(dirname($file_path)) . $backup_name;

    if (!copy($file_path, $backup_path)) {
        return new WP_Error('backup_failed', 'No se pudo crear el backup del archivo actual.');
    }

    return $backup_name;
}

function seo_tm_sync_page_template_meta($template_key) {
    /*
     * Compatibilidad: se conserva el nombre de la funcion porque otros
     * manejadores ya la llaman, pero desde ahora solo limpia el metadato
     * legacy. La plantilla publica se resuelve exclusivamente mediante
     * seo_nodes.seo_role + seo_templates en template-loader.php.
     */
    foreach (seo_tm_get_assigned_page_ids($template_key) as $page_id) {
        $page_id = (int) $page_id;

        if ($page_id > 0) {
            delete_post_meta($page_id, '_wp_page_template');
            clean_post_cache($page_id);
        }
    }
}

function seo_tm_get_assigned_page_ids($template_key) {
    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT object_id
         FROM {$nodes_table}
         WHERE object_type = 'page'
           AND seo_role = %s",
        $template_key
    )));
}

function seo_tm_remove_page_template_meta($template_key, $template_file = '') {
    // El argumento se conserva por compatibilidad; el meta ya no es fuente.
    foreach (seo_tm_get_assigned_page_ids($template_key) as $page_id) {
        $page_id = (int) $page_id;

        if ($page_id > 0) {
            delete_post_meta($page_id, '_wp_page_template');
            clean_post_cache($page_id);
        }
    }
}

/**
 * Limpia una sola vez metadatos _wp_page_template creados por versiones
 * anteriores del gestor. Solo afecta a nombres de archivo registrados en
 * seo_templates o presentes en seo-system/templates/.
 */
function seo_tm_cleanup_legacy_plugin_page_template_meta() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    $cleanup_version = '1';

    if (get_option('seo_tm_plugin_template_meta_cleanup') === $cleanup_version) {
        return;
    }

    global $wpdb;

    $files = [];
    $table = $wpdb->prefix . 'seo_templates';
    $table_exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ));

    if ($table_exists === $table) {
        foreach ((array) $wpdb->get_col("SELECT DISTINCT template_file FROM {$table} WHERE template_file <> ''") as $file) {
            $file = basename((string) $file);
            if ($file !== '') {
                $files[$file] = true;
            }
        }
    }

    foreach ((array) glob(trailingslashit(seo_template_dir()) . '*.php') as $file) {
        $name = basename((string) $file);
        if ($name !== '') {
            $files[$name] = true;
        }
    }

    if (!empty($files)) {
        $files = array_keys($files);
        $placeholders = implode(',', array_fill(0, count($files), '%s'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value IN ({$placeholders})",
            ...$files
        );

        foreach ((array) $wpdb->get_col($sql) as $page_id) {
            $page_id = (int) $page_id;
            if ($page_id > 0 && get_post_type($page_id) === 'page') {
                delete_post_meta($page_id, '_wp_page_template');
                clean_post_cache($page_id);
            }
        }
    }

    update_option('seo_tm_plugin_template_meta_cleanup', $cleanup_version, false);
}
add_action('admin_init', 'seo_tm_cleanup_legacy_plugin_page_template_meta', 20);

function seo_tm_clear_page_assignments($template_key, $template_file) {
    global $wpdb;

    seo_tm_remove_page_template_meta($template_key, $template_file);

    $wpdb->delete(
        $wpdb->prefix . 'seo_nodes',
        [
            'object_type' => 'page',
            'seo_role'    => $template_key,
        ],
        ['%s', '%s']
    );
}

/* =========================================================
   DESCARGA SEGURA DE ARCHIVOS
========================================================= */

add_action('admin_post_seo_tm_download_template', 'seo_tm_download_template');

function seo_tm_download_template() {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para descargar plantillas.');
    }

    $template_key = isset($_GET['template_key']) ? sanitize_key(wp_unslash($_GET['template_key'])) : '';
    $variant      = isset($_GET['variant']) ? sanitize_key(wp_unslash($_GET['variant'])) : '';

    if ($template_key === '') {
        wp_die('Plantilla no válida.');
    }

    if ($variant !== '' && !in_array($variant, ['mobile', 'desktop'], true)) {
        wp_die('Variante no válida.');
    }

    if ($variant === '') {
        check_admin_referer('seo_tm_download_' . $template_key);
    } else {
        check_admin_referer('seo_tm_download_' . $template_key . '_' . $variant);
    }

    $template = seo_tm_get_template_by_key($template_key);
    if (!$template) {
        wp_die('La plantilla no existe en la base de datos.');
    }

    $file_name = basename((string) $template->template_file);

    if ($variant !== '') {
        $file_name = seo_template_variant_filename($file_name, $variant);

        if ($file_name === '') {
            wp_die('Esta plantilla no admite variantes por dispositivo.');
        }
    }

    $file_path = trailingslashit(seo_template_dir()) . $file_name;

    if (!is_file($file_path) || !is_readable($file_path)) {
        wp_die('El archivo físico no existe o no se puede leer.');
    }

    nocache_headers();
    header('Content-Type: application/x-httpd-php');
    header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
    header('Content-Length: ' . filesize($file_path));

    readfile($file_path);
    exit;
}


/* =========================================================
   PROCESAR ASIGNACIONES
========================================================= */

function seo_tm_handle_assignments() {
    if (!isset($_POST['seo_tm_save_assignments'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('asignar', 'No tienes permisos para modificar asignaciones.', 'error');
    }

    check_admin_referer('seo_tm_save_assignments', 'seo_tm_assignments_nonce');

    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    $templates = seo_tm_get_templates(
        'is_public = 1 AND is_assignable = 1 AND assignment_mode IN (%s, %s)',
        ['single', 'multiple']
    );

    $template_keys = wp_list_pluck($templates, 'template_key');

    if (!empty($template_keys)) {
        $placeholders = implode(',', array_fill(0, count($template_keys), '%s'));

        $old_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, seo_role
             FROM {$nodes_table}
             WHERE object_type = 'page'
               AND seo_role IN ({$placeholders})",
            ...$template_keys
        ));

        foreach ($old_rows as $old_row) {
            $old_template = seo_tm_get_template_by_key($old_row->seo_role);
            $current_meta = get_post_meta((int) $old_row->object_id, '_wp_page_template', true);

            if ($old_template && $current_meta === basename($old_template->template_file)) {
                delete_post_meta((int) $old_row->object_id, '_wp_page_template');
            }
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$nodes_table}
             WHERE object_type = 'page'
               AND seo_role IN ({$placeholders})",
            ...$template_keys
        ));
    }

    $posted_assignments = isset($_POST['assigned_pages']) && is_array($_POST['assigned_pages'])
        ? wp_unslash($_POST['assigned_pages'])
        : [];

    $posted_device_modes = isset($_POST['device_variants_enabled']) && is_array($_POST['device_variants_enabled'])
        ? wp_unslash($_POST['device_variants_enabled'])
        : [];

    $occupied_in_request = [];
    $saved_count         = 0;

    foreach ($templates as $template) {
        $device_enabled = !empty($posted_device_modes[$template->template_key]) ? 1 : 0;
        $principal_path = trailingslashit(seo_template_dir()) . basename((string) $template->template_file);

        if (!seo_tm_template_supports_device_variants($template)) {
            $device_enabled = 0;
        } elseif (seo_template_is_device_dispatcher($principal_path)) {
            /* Un dispatcher técnico no puede renderizarse solo. */
            $device_enabled = 1;
        }

        $wpdb->update(
            $wpdb->prefix . 'seo_templates',
            ['device_variants_enabled' => $device_enabled],
            ['template_key' => $template->template_key],
            ['%d'],
            ['%s']
        );

        $template->device_variants_enabled = $device_enabled;

        $raw_value = $posted_assignments[$template->template_key] ?? [];

        if ($template->assignment_mode === 'single') {
            if (is_array($raw_value)) {
                $raw_value = reset($raw_value);
            }

            $page_ids = [(int) $raw_value];
        } else {
            $page_ids = is_array($raw_value) ? array_map('intval', $raw_value) : [];
        }

        $page_ids = array_values(array_unique(array_filter($page_ids)));

        foreach ($page_ids as $page_id) {
            if (isset($occupied_in_request[$page_id])) {
                continue;
            }

            if (get_post_type($page_id) !== 'page' || get_post_status($page_id) !== 'publish') {
                continue;
            }

            /* Una página solo puede pertenecer a una plantilla asignable. */
            $wpdb->delete(
                $nodes_table,
                [
                    'object_type' => 'page',
                    'object_id'   => $page_id,
                ],
                ['%s', '%d']
            );

            $inserted = $wpdb->insert(
                $nodes_table,
                [
                    'object_type' => 'page',
                    'object_id'   => $page_id,
                    'seo_role'    => $template->template_key,
                    'status'      => 1,
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql'),
                ],
                ['%s', '%d', '%s', '%d', '%s', '%s']
            );

            if ($inserted !== false) {
                $occupied_in_request[$page_id] = $template->template_key;
                $saved_count++;

                // La asignacion vive en seo_nodes; no escribir _wp_page_template.
                delete_post_meta($page_id, '_wp_page_template');
                clean_post_cache($page_id);
            }
        }
    }

    seo_tm_redirect(
        'asignar',
        sprintf('Asignaciones y modo de renderizado guardados correctamente: %d página(s).', $saved_count),
        'success'
    );
}

/* =========================================================
   PROCESAR BIBLIOTECA DE ARCHIVOS
========================================================= */

function seo_tm_handle_replace_file() {
    if (!isset($_POST['seo_tm_replace_file'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('archivos', 'No tienes permisos para reemplazar archivos.', 'error');
    }

    check_admin_referer('seo_tm_replace_file', 'seo_tm_replace_nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template     = seo_tm_get_template_by_key($template_key);

    if (!$template) {
        seo_tm_redirect('archivos', 'La plantilla no existe en la base de datos.', 'error');
    }

    $uploaded_file = $_FILES['template_file'] ?? [];
    $validation    = seo_tm_validate_php_upload($uploaded_file);

    if (is_wp_error($validation)) {
        seo_tm_redirect('archivos', $validation->get_error_message(), 'error');
    }

    $directory = trailingslashit(seo_template_dir());
    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        seo_tm_redirect('archivos', 'No se pudo crear el directorio de plantillas.', 'error');
    }

    $file_path = $directory . basename($template->template_file);

    if (file_exists($file_path) && !is_writable($file_path)) {
        seo_tm_redirect('archivos', 'El archivo actual no tiene permisos de escritura.', 'error');
    }

    if (!file_exists($file_path) && !is_writable($directory)) {
        seo_tm_redirect('archivos', 'El directorio de plantillas no tiene permisos de escritura.', 'error');
    }

    $backup = seo_tm_create_backup($file_path);
    if (is_wp_error($backup)) {
        seo_tm_redirect('archivos', $backup->get_error_message(), 'error');
    }

    if (!move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
        if ($backup !== '') {
            $backup_path = trailingslashit($directory) . $backup;

            if (is_file($backup_path)) {
                copy($backup_path, $file_path);
            }
        }

        seo_tm_redirect('archivos', 'No se pudo guardar la plantilla subida.', 'error');
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'seo_templates',
        [
            'template_content' => null,
            'updated_at'       => current_time('mysql'),
        ],
        ['template_key' => $template_key],
        [null, '%s'],
        ['%s']
    );

    if (seo_template_is_device_dispatcher($file_path)) {
        $wpdb->update(
            $wpdb->prefix . 'seo_templates',
            ['device_variants_enabled' => 1],
            ['template_key' => $template_key],
            ['%d'],
            ['%s']
        );
    }

    seo_tm_sync_page_template_meta($template_key);

    $message = $backup
        ? 'Plantilla reemplazada. Backup creado: ' . $backup
        : 'Archivo de plantilla creado correctamente.';

    seo_tm_redirect('archivos', $message, 'success');
}

function seo_tm_handle_variant_settings() {
    if (!isset($_POST['seo_tm_save_variant_settings'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('archivos', 'No tienes permisos para configurar variantes.', 'error');
    }

    check_admin_referer('seo_tm_variant_settings', 'seo_tm_variant_settings_nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template     = seo_tm_get_template_by_key($template_key);

    if (!$template) {
        seo_tm_redirect('archivos', 'La plantilla principal no existe en la base de datos.', 'error');
    }

    if (!seo_tm_template_supports_device_variants($template)) {
        seo_tm_redirect('archivos', 'Esta plantilla no admite variantes por dispositivo.', 'error');
    }

    $enabled        = !empty($_POST['device_variants_enabled']) ? 1 : 0;
    $principal_path = trailingslashit(seo_template_dir()) . basename((string) $template->template_file);

    if (seo_template_is_device_dispatcher($principal_path) && $enabled !== 1) {
        seo_tm_redirect(
            'archivos',
            'No se pueden desactivar las secundarias mientras la principal sea un dispatcher técnico. Reemplaza primero la principal por una plantilla completa.',
            'warning'
        );
    }

    global $wpdb;

    $updated = $wpdb->update(
        $wpdb->prefix . 'seo_templates',
        [
            'device_variants_enabled' => $enabled,
            'updated_at'              => current_time('mysql'),
        ],
        ['template_key' => $template_key],
        ['%d', '%s'],
        ['%s']
    );

    if ($updated === false) {
        seo_tm_redirect('archivos', 'No se pudo guardar el modo por dispositivo: ' . $wpdb->last_error, 'error');
    }

    seo_tm_redirect(
        'archivos',
        $enabled
            ? 'Plantillas específicas por dispositivo activadas.'
            : 'Plantillas específicas desactivadas: teléfono y ordenador usarán la principal.',
        'success'
    );
}

function seo_tm_handle_replace_variant_file() {
    if (!isset($_POST['seo_tm_replace_variant_file'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('archivos', 'No tienes permisos para reemplazar variantes.', 'error');
    }

    check_admin_referer('seo_tm_replace_variant_file', 'seo_tm_replace_variant_nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $variant      = isset($_POST['variant']) ? sanitize_key(wp_unslash($_POST['variant'])) : '';

    if (!in_array($variant, ['mobile', 'desktop'], true)) {
        seo_tm_redirect('archivos', 'La variante indicada no es válida.', 'error');
    }

    $template = seo_tm_get_template_by_key($template_key);

    if (!$template) {
        seo_tm_redirect('archivos', 'La plantilla principal no existe en la base de datos.', 'error');
    }

    $target_name = seo_template_variant_filename($template->template_file, $variant);

    if ($target_name === '') {
        seo_tm_redirect('archivos', 'No se puede generar un nombre de secundaria para esta plantilla.', 'error');
    }

    $uploaded_file = $_FILES['template_file'] ?? [];
    $validation    = seo_tm_validate_php_upload($uploaded_file);

    if (is_wp_error($validation)) {
        seo_tm_redirect('archivos', $validation->get_error_message(), 'error');
    }

    $directory = trailingslashit(seo_template_dir());

    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        seo_tm_redirect('archivos', 'No se pudo crear el directorio de plantillas.', 'error');
    }

    $file_path = $directory . $target_name;

    if (file_exists($file_path) && !is_writable($file_path)) {
        seo_tm_redirect('archivos', 'La secundaria actual no tiene permisos de escritura.', 'error');
    }

    if (!file_exists($file_path) && !is_writable($directory)) {
        seo_tm_redirect('archivos', 'El directorio de plantillas no tiene permisos de escritura.', 'error');
    }

    $backup = seo_tm_create_backup($file_path);

    if (is_wp_error($backup)) {
        seo_tm_redirect('archivos', $backup->get_error_message(), 'error');
    }

    if (!move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
        if ($backup !== '') {
            $backup_path = $directory . $backup;

            if (is_file($backup_path)) {
                copy($backup_path, $file_path);
            }
        }

        seo_tm_redirect('archivos', 'No se pudo guardar la plantilla secundaria subida.', 'error');
    }

    global $wpdb;

    $wpdb->update(
        $wpdb->prefix . 'seo_templates',
        ['updated_at' => current_time('mysql')],
        ['template_key' => $template_key],
        ['%s'],
        ['%s']
    );

    $label = $variant === 'mobile' ? 'teléfono' : 'ordenador';
    $message = $backup !== ''
        ? 'Secundaria de ' . $label . ' reemplazada. Backup creado: ' . $backup
        : 'Secundaria de ' . $label . ' creada correctamente.';

    seo_tm_redirect('archivos', $message, 'success');
}


function seo_tm_handle_register_existing_file() {
    if (!isset($_POST['seo_tm_register_existing'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('archivos', 'No tienes permisos para registrar plantillas.', 'error');
    }

    check_admin_referer('seo_tm_register_existing', 'seo_tm_register_existing_nonce');

    global $wpdb;

    $template_key  = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
    $template_file = isset($_POST['template_file']) ? sanitize_file_name(wp_unslash($_POST['template_file'])) : '';
    $template_type = isset($_POST['template_type']) ? sanitize_key(wp_unslash($_POST['template_type'])) : 'page';

    if ($template_key === '' || $template_name === '' || $template_file === '') {
        seo_tm_redirect('archivos', 'Completa la clave, el nombre y el archivo.', 'error');
    }

    if (seo_tm_get_template_by_key($template_key)) {
        seo_tm_redirect('archivos', 'Ya existe una plantilla con esa clave.', 'error');
    }

    $file_path = trailingslashit(seo_template_dir()) . basename($template_file);
    if (!is_file($file_path)) {
        seo_tm_redirect('archivos', 'El archivo seleccionado no existe.', 'error');
    }

    $variant_parent = seo_tm_find_variant_parent_by_filename($template_file);

    if ($variant_parent) {
        $parent_name = isset($variant_parent['template']->template_name)
            ? (string) $variant_parent['template']->template_name
            : (string) $variant_parent['template']->template_key;

        seo_tm_redirect(
            'archivos',
            'Ese archivo ya es una secundaria de «' . $parent_name . '» y no debe registrarse como plantilla independiente.',
            'warning'
        );
    }

    $allowed_types = array_keys(seo_tm_template_types());
    if (!in_array($template_type, $allowed_types, true)) {
        $template_type = 'other';
    }

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'seo_templates',
        [
            'template_key'    => $template_key,
            'template_name'   => $template_name,
            'template_file'   => basename($template_file),
            'template_content'=> null,
            'updated_at'      => current_time('mysql'),
            'is_active'       => 0,
            'template_type'   => $template_type,
            'is_public'       => 0,
            'is_assignable'   => 0,
            'assignment_mode' => 'automatic',
            'display_order'   => 0,
            'description'     => '',
        ],
        ['%s', '%s', '%s', null, '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']
    );

    if ($inserted === false) {
        seo_tm_redirect('archivos', 'No se pudo registrar la plantilla: ' . $wpdb->last_error, 'error');
    }

    seo_tm_redirect('archivos', 'Archivo registrado como plantilla. Configura ahora su disponibilidad.', 'success');
}

function seo_tm_handle_upload_new_template() {
    if (!isset($_POST['seo_tm_upload_new'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('archivos', 'No tienes permisos para añadir plantillas.', 'error');
    }

    check_admin_referer('seo_tm_upload_new', 'seo_tm_upload_new_nonce');

    global $wpdb;

    $template_key  = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
    $template_type = isset($_POST['template_type']) ? sanitize_key(wp_unslash($_POST['template_type'])) : 'page';
    $uploaded_file = $_FILES['template_file'] ?? [];

    if ($template_key === '' || $template_name === '') {
        seo_tm_redirect('archivos', 'Completa la clave y el nombre de la plantilla.', 'error');
    }

    if (seo_tm_get_template_by_key($template_key)) {
        seo_tm_redirect('archivos', 'Ya existe una plantilla con esa clave.', 'error');
    }

    $validation = seo_tm_validate_php_upload($uploaded_file);
    if (is_wp_error($validation)) {
        seo_tm_redirect('archivos', $validation->get_error_message(), 'error');
    }

    $filename = sanitize_file_name(basename($uploaded_file['name']));
    if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'php') {
        seo_tm_redirect('archivos', 'El nombre físico del archivo no es válido.', 'error');
    }

    $variant_parent = seo_tm_find_variant_parent_by_filename($filename);

    if ($variant_parent) {
        $parent_name = isset($variant_parent['template']->template_name)
            ? (string) $variant_parent['template']->template_name
            : (string) $variant_parent['template']->template_key;

        seo_tm_redirect(
            'archivos',
            'Ese archivo corresponde a una secundaria de «' . $parent_name . '». Súbelo desde la tarjeta de su plantilla principal para no crear una asignación independiente.',
            'warning'
        );
    }

    $directory = trailingslashit(seo_template_dir());
    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        seo_tm_redirect('archivos', 'No se pudo crear el directorio de plantillas.', 'error');
    }

    $destination = $directory . $filename;
    if (file_exists($destination)) {
        seo_tm_redirect('archivos', 'Ya existe un archivo con ese nombre. Regístralo como existente o usa otro nombre.', 'error');
    }

    if (!move_uploaded_file($uploaded_file['tmp_name'], $destination)) {
        seo_tm_redirect('archivos', 'No se pudo copiar el archivo al directorio del plugin.', 'error');
    }

    $allowed_types = array_keys(seo_tm_template_types());
    if (!in_array($template_type, $allowed_types, true)) {
        $template_type = 'other';
    }

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'seo_templates',
        [
            'template_key'    => $template_key,
            'template_name'   => $template_name,
            'template_file'   => $filename,
            'template_content'=> null,
            'updated_at'      => current_time('mysql'),
            'is_active'       => 0,
            'template_type'   => $template_type,
            'is_public'       => 0,
            'is_assignable'   => 0,
            'assignment_mode' => 'automatic',
            'display_order'   => 0,
            'description'     => '',
        ],
        ['%s', '%s', '%s', null, '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']
    );

    if ($inserted === false) {
        @unlink($destination);
        seo_tm_redirect('archivos', 'El archivo se subió, pero no se pudo registrar la plantilla: ' . $wpdb->last_error, 'error');
    }

    seo_tm_redirect('archivos', 'Nueva plantilla subida y registrada. Permanece inactiva hasta que la habilites.', 'success');
}

/* =========================================================
   PROCESAR DISPONIBILIDAD Y ACTIVACIÓN
========================================================= */

function seo_tm_handle_registry() {
    if (!isset($_POST['seo_tm_save_registry'])) return;

    if (!current_user_can('manage_options')) {
        seo_tm_redirect('configuracion', 'No tienes permisos para configurar plantillas.', 'error');
    }

    check_admin_referer('seo_tm_save_registry', 'seo_tm_registry_nonce');

    global $wpdb;

    $table     = $wpdb->prefix . 'seo_templates';
    $templates = seo_tm_get_templates();
    $posted    = isset($_POST['templates']) && is_array($_POST['templates'])
        ? wp_unslash($_POST['templates'])
        : [];
    $files     = seo_tm_get_php_files();

    $allowed_types = array_keys(seo_tm_template_types());
    $allowed_modes = array_keys(seo_tm_assignment_modes());

    foreach ($templates as $template) {
        $row      = $posted[$template->template_key] ?? [];
        $old_file = basename((string) $template->template_file);

        $template_name   = isset($row['template_name']) ? sanitize_text_field($row['template_name']) : $template->template_name;
        $template_file   = isset($row['template_file']) ? sanitize_file_name($row['template_file']) : $template->template_file;
        $template_type   = isset($row['template_type']) ? sanitize_key($row['template_type']) : 'other';
        $assignment_mode = isset($row['assignment_mode']) ? sanitize_key($row['assignment_mode']) : 'automatic';
        $display_order   = isset($row['display_order']) ? (int) $row['display_order'] : 0;
        $description     = isset($row['description']) ? sanitize_textarea_field($row['description']) : '';
        $is_public               = !empty($row['is_public']) ? 1 : 0;
        $is_active               = !empty($row['is_active']) ? 1 : 0;
        $is_assignable           = !empty($row['is_assignable']) ? 1 : 0;
        $device_variants_enabled = isset($template->device_variants_enabled)
            ? (int) $template->device_variants_enabled
            : 0;

        if (!in_array($template_type, $allowed_types, true)) {
            $template_type = 'other';
        }

        if (!in_array($assignment_mode, $allowed_modes, true)) {
            $assignment_mode = 'automatic';
        }

        /*
         * Las plantillas single_* representan contenido individual
         * (posts, productos u otros tipos singulares) y se resuelven
         * automáticamente desde template-loader.php. Nunca deben poder
         * convertirse en plantillas de página ni admitir asignaciones
         * manuales desde el gestor.
         */
        if (strpos((string) $template->template_key, 'single_') === 0) {
            $template_type   = 'content';
            $assignment_mode = 'automatic';
            $is_assignable   = 0;
        }

        if ((string) $template->template_key === 'blog_index') {
            $template_type   = 'content';
            $assignment_mode = 'automatic';
            $is_assignable   = 0;
        }

        if (!isset($files[$template_file])) {
            /* Se conserva el archivo actual si el valor enviado no existe. */
            $template_file = $old_file;
        }

        /*
         * Una secundaria conocida no puede convertirse accidentalmente en
         * la plantilla principal de otra fila desde este selector.
         */
        if ($template_file !== $old_file && seo_tm_find_variant_parent_by_filename($template_file)) {
            $template_file = $old_file;
        }

        if (!isset($files[$template_file])) {
            $is_active = 0;
        }

        if (seo_template_variant_filename($template_file, 'mobile') === '') {
            $device_variants_enabled = 0;
        } else {
            $principal_path = trailingslashit(seo_template_dir()) . basename($template_file);

            if (seo_template_is_device_dispatcher($principal_path)) {
                $device_variants_enabled = 1;
            }
        }

        if ($is_public !== 1) {
            $is_active     = 0;
            $is_assignable = 0;
        }

        if ($assignment_mode === 'automatic') {
            $is_assignable = 0;
        }

        /*
         * Si cambia el archivo o se desactiva la plantilla, eliminamos el
         * metadato anterior antes de sincronizar el nuevo estado.
         */
        if ($old_file !== $template_file || $is_active !== 1) {
            seo_tm_remove_page_template_meta($template->template_key, $old_file);
        }

        /*
         * Una plantilla que deja de ser asignable no debe conservar
         * relaciones ocultas en seo_nodes.
         */
        if ($is_assignable !== 1) {
            seo_tm_clear_page_assignments($template->template_key, $old_file);
        }

        $wpdb->update(
            $table,
            [
                'template_name'   => $template_name,
                'template_file'   => $template_file,
                'template_type'   => $template_type,
                'is_public'       => $is_public,
                'is_active'       => $is_active,
                'is_assignable'   => $is_assignable,
                'assignment_mode' => $assignment_mode,
                'display_order'   => $display_order,
                'description'             => $description,
                'device_variants_enabled' => $device_variants_enabled,
                'updated_at'              => current_time('mysql'),
            ],
            ['template_key' => $template->template_key],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s'],
            ['%s']
        );

        seo_tm_sync_page_template_meta($template->template_key);
    }

    seo_tm_redirect('configuracion', 'Disponibilidad y activación guardadas correctamente.', 'success');
}

/* =========================================================
   PÁGINA PRINCIPAL DEL GESTOR
========================================================= */

function seo_templates_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar plantillas.');
    }

    $schema_result = seo_tm_ensure_schema();

    if (is_wp_error($schema_result)) {
        echo '<div class="wrap"><h1>Gestor de Plantillas</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html($schema_result->get_error_message()) . '</p></div>';
        echo '</div>';
        return;
    }

    seo_tm_handle_assignments();
    seo_tm_handle_replace_file();
    seo_tm_handle_variant_settings();
    seo_tm_handle_replace_variant_file();
    seo_tm_handle_register_existing_file();
    seo_tm_handle_upload_new_template();
    seo_tm_handle_registry();

    $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'asignar';
    $allowed_tabs = ['asignar', 'archivos', 'configuracion'];

    if (!in_array($active_tab, $allowed_tabs, true)) {
        $active_tab = 'asignar';
    }

    echo '<div class="wrap seo-tm-wrap">';
    echo '<h1>Gestor de Plantillas</h1>';

    seo_tm_render_notice();

    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
    echo '<a href="' . esc_url(seo_tm_admin_url('asignar')) . '" class="nav-tab ' . ($active_tab === 'asignar' ? 'nav-tab-active' : '') . '">Asignación a páginas</a>';
    echo '<a href="' . esc_url(seo_tm_admin_url('archivos')) . '" class="nav-tab ' . ($active_tab === 'archivos' ? 'nav-tab-active' : '') . '">Archivos de plantilla</a>';
    echo '<a href="' . esc_url(seo_tm_admin_url('configuracion')) . '" class="nav-tab ' . ($active_tab === 'configuracion' ? 'nav-tab-active' : '') . '">Disponibilidad y activación</a>';
    echo '</nav>';

    seo_tm_render_styles();

    if ($active_tab === 'asignar') {
        seo_tm_render_assignments_tab();
    } elseif ($active_tab === 'archivos') {
        seo_tm_render_files_tab();
    } else {
        seo_tm_render_registry_tab();
    }

    echo '</div>';
}

/* =========================================================
   INTERFAZ: ASIGNACIONES
========================================================= */

function seo_tm_render_assignments_tab() {
    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    $templates = seo_tm_get_templates(
        'is_public = 1 AND is_assignable = 1 AND assignment_mode IN (%s, %s)',
        ['single', 'multiple']
    );

    $pages = $wpdb->get_results(
        "SELECT ID, post_title
         FROM {$wpdb->posts}
         WHERE post_type = 'page'
           AND post_status = 'publish'
         ORDER BY post_title ASC"
    );

    $rows = $wpdb->get_results(
        "SELECT object_id, seo_role
         FROM {$nodes_table}
         WHERE object_type = 'page'"
    );

    $assigned_by_role = [];
    $occupied_by_page = [];

    foreach ($rows as $row) {
        $page_id = (int) $row->object_id;
        $assigned_by_role[$row->seo_role][] = $page_id;
        $occupied_by_page[$page_id] = $row->seo_role;
    }

    echo '<h2>Asignación de plantillas a páginas</h2>';
    echo '<p>Cada página se asigna siempre a <strong>una única plantilla principal</strong>. Si activas variantes por dispositivo, teléfono y ordenador se resuelven como archivos secundarios de esa principal, sin crear nuevas asignaciones.</p>';

    if (empty($templates)) {
        echo '<div class="notice notice-warning inline"><p>No hay plantillas asignables. Activa la opción <strong>Asignable</strong> en la pestaña «Disponibilidad y activación».</p></div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('seo_tm_save_assignments', 'seo_tm_assignments_nonce');

    foreach ($templates as $template) {
        $current_ids = $assigned_by_role[$template->template_key] ?? [];
        $file_exists = is_file(trailingslashit(seo_template_dir()) . basename($template->template_file));

        echo '<section class="seo-tm-card">';
        echo '<div class="seo-tm-card-heading">';
        echo '<div>';
        echo '<h2>' . esc_html($template->template_name) . '</h2>';
        echo '<p><code>' . esc_html($template->template_key) . '</code> · ' . esc_html($template->template_file) . '</p>';
        echo '</div>';
        echo '<div class="seo-tm-badges">';
        echo '<span class="seo-tm-badge">' . esc_html($template->assignment_mode === 'single' ? 'Una página' : 'Varias páginas') . '</span>';
        echo '<span class="seo-tm-badge ' . ((int) $template->is_active === 1 ? 'is-ok' : 'is-muted') . '">' . ((int) $template->is_active === 1 ? 'Plugin activo' : 'Gestiona el tema') . '</span>';
        echo '<span class="seo-tm-badge ' . ($file_exists ? 'is-ok' : 'is-error') . '">' . ($file_exists ? 'Archivo disponible' : 'Archivo ausente') . '</span>';
        echo '</div>';
        echo '</div>';

        if (!empty($template->description)) {
            echo '<p>' . esc_html($template->description) . '</p>';
        }

        seo_tm_render_assignment_device_panel($template);

        if ($template->assignment_mode === 'single') {
            $selected = !empty($current_ids) ? (int) reset($current_ids) : 0;

            echo '<label for="seo-tm-' . esc_attr($template->template_key) . '"><strong>Página asignada</strong></label>';
            echo '<select class="regular-text" id="seo-tm-' . esc_attr($template->template_key) . '" name="assigned_pages[' . esc_attr($template->template_key) . ']">';
            echo '<option value="0">— Sin página asignada —</option>';

            foreach ($pages as $page) {
                $page_id    = (int) $page->ID;
                $other_role = $occupied_by_page[$page_id] ?? '';
                $is_current = $other_role === $template->template_key;

                if ($other_role !== '' && !$is_current) {
                    continue;
                }

                echo '<option value="' . esc_attr($page_id) . '" ' . selected($selected, $page_id, false) . '>' . esc_html($page->post_title ?: '(Sin título)') . '</option>';
            }

            echo '</select>';

        } else {
            echo '<div class="seo-tm-columns">';

            echo '<div class="seo-tm-list is-assigned">';
            echo '<h3>Asignadas</h3>';

            $has_assigned = false;
            foreach ($pages as $page) {
                $page_id = (int) $page->ID;

                if (!in_array($page_id, $current_ids, true)) {
                    continue;
                }

                $has_assigned = true;
                echo '<label><input type="checkbox" name="assigned_pages[' . esc_attr($template->template_key) . '][]" value="' . esc_attr($page_id) . '" checked> ' . esc_html($page->post_title ?: '(Sin título)') . '</label>';
            }

            if (!$has_assigned) {
                echo '<p class="description">No hay páginas asignadas.</p>';
            }

            echo '</div>';

            echo '<div class="seo-tm-list">';
            echo '<h3>Disponibles</h3>';

            $has_available = false;
            foreach ($pages as $page) {
                $page_id = (int) $page->ID;

                if (isset($occupied_by_page[$page_id])) {
                    continue;
                }

                $has_available = true;
                echo '<label><input type="checkbox" name="assigned_pages[' . esc_attr($template->template_key) . '][]" value="' . esc_attr($page_id) . '"> ' . esc_html($page->post_title ?: '(Sin título)') . '</label>';
            }

            if (!$has_available) {
                echo '<p class="description">No quedan páginas libres.</p>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</section>';
    }

    echo '<p><button type="submit" name="seo_tm_save_assignments" class="button button-primary button-large">Guardar asignaciones y modo de renderizado</button></p>';
    echo '</form>';
}

/* =========================================================
   INTERFAZ: ARCHIVOS
========================================================= */

function seo_tm_render_files_tab() {
    $templates = seo_tm_get_templates();
    $files     = seo_tm_get_php_files();

    /*
     * Las secundarias pertenecen a su principal y no deben aparecer como
     * archivos "sin registrar" ni convertirse en filas independientes.
     */
    $registered_files = [];

    foreach ($templates as $template) {
        $principal = basename((string) $template->template_file);

        if ($principal !== '') {
            $registered_files[$principal] = true;
        }

        if (seo_tm_template_supports_device_variants($template)) {
            foreach (['mobile', 'desktop'] as $variant) {
                $variant_file = seo_template_variant_filename($principal, $variant);

                if ($variant_file !== '') {
                    $registered_files[$variant_file] = true;
                }
            }
        }
    }

    $unregistered_files = array_values(
        array_diff(array_keys($files), array_keys($registered_files))
    );

    echo '<h2>Biblioteca de archivos de plantilla</h2>';
    echo '<p>La biblioteca está organizada por <strong>plantilla principal</strong>. La principal es la única que se registra y se asigna; las variantes de teléfono y ordenador se muestran dentro de ella y son opcionales salvo cuando la principal actual sea un dispatcher técnico.</p>';
    echo '<p><label for="seo-tm-template-filter"><strong>Buscar plantilla</strong></label><br>';
    echo '<input type="search" id="seo-tm-template-filter" class="regular-text" placeholder="Nombre, clave o archivo..."></p>';

    echo '<div class="seo-tm-grid seo-tm-grid-files" id="seo-tm-template-library">';

    foreach ($templates as $template) {
        $file_path   = trailingslashit(seo_template_dir()) . basename((string) $template->template_file);
        $file_exists = is_file($file_path);
        $file_size   = $file_exists ? size_format(filesize($file_path), 1) : '—';
        $modified    = $file_exists ? date_i18n('d/m/Y H:i', filemtime($file_path)) : '—';

        echo '<section class="seo-tm-card" id="seo-tm-template-' . esc_attr($template->template_key) . '">';
        echo '<div class="seo-tm-card-heading">';
        echo '<div>';
        echo '<h2>' . esc_html($template->template_name) . '</h2>';
        echo '<p><code>' . esc_html($template->template_key) . '</code></p>';
        echo '</div>';
        echo '<div class="seo-tm-badges">';
        echo '<span class="seo-tm-badge is-ok">Plantilla principal</span>';
        echo '<span class="seo-tm-badge ' . ((int) $template->is_active === 1 ? 'is-ok' : 'is-muted') . '">' . ((int) $template->is_active === 1 ? 'Plugin activo' : 'Plugin inactivo') . '</span>';
        echo '<span class="seo-tm-badge ' . ((int) $template->is_assignable === 1 ? 'is-ok' : 'is-muted') . '">' . ((int) $template->is_assignable === 1 ? 'Asignable a páginas' : 'Asignación automática') . '</span>';

        if (seo_tm_template_supports_device_variants($template)) {
            $plan = seo_tm_get_device_plan($template, 'mobile');
            echo '<span class="seo-tm-badge ' . (!empty($plan['variants_enabled']) ? 'is-ok' : 'is-muted') . '">' . (!empty($plan['variants_enabled']) ? 'Por dispositivo' : 'Solo principal') . '</span>';
        }

        echo '</div>';
        echo '</div>';

        echo '<div class="seo-tm-main-file">';
        echo '<h3>Plantilla principal</h3>';
        echo '<p><strong>Archivo:</strong> <code>' . esc_html($template->template_file) . '</code></p>';
        echo '<p><strong>Estado:</strong> <span class="seo-tm-badge ' . ($file_exists ? 'is-ok' : 'is-error') . '">' . ($file_exists ? 'Disponible' : 'No encontrado') . '</span></p>';
        echo '<p><strong>Tamaño:</strong> ' . esc_html($file_size) . ' · <strong>Modificado:</strong> ' . esc_html($modified) . '</p>';
        echo '<p class="description">Este es el archivo que manda: es el único relacionado con <code>template_key</code> y con las páginas. Las secundarias no crean asignaciones nuevas.</p>';

        if ($file_exists) {
            $download_url = wp_nonce_url(
                admin_url('admin-post.php?action=seo_tm_download_template&template_key=' . rawurlencode($template->template_key)),
                'seo_tm_download_' . $template->template_key
            );

            echo '<p><a class="button" href="' . esc_url($download_url) . '">Descargar principal</a></p>';

            $file_content = file_get_contents($file_path);

            if ($file_content !== false) {
                echo '<details class="seo-tm-code-preview">';
                echo '<summary>Ver contenido de la principal</summary>';
                echo '<pre>' . esc_html($file_content) . '</pre>';
                echo '</details>';
            }
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('seo_tm_replace_file', 'seo_tm_replace_nonce');
        echo '<input type="hidden" name="template_key" value="' . esc_attr($template->template_key) . '">';
        echo '<input type="file" name="template_file" accept=".php" required>';
        echo '<p><button type="submit" name="seo_tm_replace_file" class="button button-primary">' . ($file_exists ? 'Reemplazar principal y crear backup' : 'Crear principal faltante') . '</button></p>';
        echo '</form>';
        echo '</div>';

        seo_tm_render_device_files_panel($template);

        if ((string) $template->template_key === 'cart') {
            echo '<div class="seo-tm-cart-style-link">';
            echo '<strong>Estilo del carrito</strong>';
            echo '<p class="description">Colores, dimensiones y previsualización se gestionan desde Marketing → Estilo visual.</p>';
            echo '<a class="button button-secondary" href="' . esc_url(seo_tm_cart_style_admin_url()) . '">Configurar estilo y previsualizar carrito</a>';
            echo '</div>';
        }

        if ((string) $template->template_key === 'checkout') {
            echo '<div class="seo-tm-cart-style-link">';
            echo '<strong>Estilo de finalizar compra</strong>';
            echo '<p class="description">El checkout conserva la lógica nativa de WooCommerce; aquí solo se personaliza su presentación.</p>';
            echo '<a class="button button-secondary" href="' . esc_url(seo_tm_checkout_style_admin_url()) . '">Configurar estilo y previsualizar checkout</a>';
            echo '</div>';
        }

        echo '</section>';
    }

    echo '</div>';

    echo '<script>
        (function () {
            var input = document.getElementById("seo-tm-template-filter");
            var library = document.getElementById("seo-tm-template-library");

            if (!input || !library) return;

            input.addEventListener("input", function () {
                var needle = input.value.toLowerCase().trim();
                var cards = library.children;

                for (var i = 0; i < cards.length; i++) {
                    var card = cards[i];
                    var haystack = (card.textContent || "").toLowerCase();
                    card.style.display = needle === "" || haystack.indexOf(needle) !== -1 ? "" : "none";
                }
            });
        }());
    </script>';

    echo '<hr class="seo-tm-separator">';
    echo '<h2>Añadir una plantilla principal al catálogo</h2>';
    echo '<p class="description">Usa esta zona solo para nuevas plantillas principales. Las variantes móvil/escritorio se suben dentro de la tarjeta de su principal.</p>';

    echo '<div class="seo-tm-columns">';

    echo '<section class="seo-tm-card">';
    echo '<h3>Subir un archivo principal nuevo</h3>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('seo_tm_upload_new', 'seo_tm_upload_new_nonce');
    seo_tm_render_new_template_fields(false, []);
    echo '<p><input type="file" name="template_file" accept=".php" required></p>';
    echo '<p><button type="submit" name="seo_tm_upload_new" class="button button-primary">Subir y registrar principal</button></p>';
    echo '</form>';
    echo '</section>';

    echo '<section class="seo-tm-card">';
    echo '<h3>Registrar un archivo principal que ya existe</h3>';

    if (empty($unregistered_files)) {
        echo '<p>No hay archivos PHP principales sin registrar en el directorio de plantillas.</p>';
    } else {
        echo '<form method="post">';
        wp_nonce_field('seo_tm_register_existing', 'seo_tm_register_existing_nonce');
        seo_tm_render_new_template_fields(true, $unregistered_files);
        echo '<p><button type="submit" name="seo_tm_register_existing" class="button button-primary">Registrar archivo principal</button></p>';
        echo '</form>';
    }

    echo '</section>';
    echo '</div>';
}

function seo_tm_render_new_template_fields($include_file_select, array $unregistered_files) {
    echo '<p><label><strong>Clave única</strong><br><input type="text" class="regular-text" name="template_key" placeholder="ejemplo_plantilla" pattern="[a-z0-9_\-]+" required></label></p>';
    echo '<p><label><strong>Nombre visible</strong><br><input type="text" class="regular-text" name="template_name" required></label></p>';

    if ($include_file_select) {
        echo '<p><label><strong>Archivo</strong><br><select class="regular-text" name="template_file" required>';
        echo '<option value="">— Selecciona un archivo —</option>';

        foreach ($unregistered_files as $file) {
            echo '<option value="' . esc_attr($file) . '">' . esc_html($file) . '</option>';
        }

        echo '</select></label></p>';
    }

    echo '<p><label><strong>Tipo inicial</strong><br><select class="regular-text" name="template_type">';
    foreach (seo_tm_template_types() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p class="description">La nueva plantilla se registra como no pública, no asignable e inactiva. Después se habilita desde «Disponibilidad y activación».</p>';
}

/* =========================================================
   INTERFAZ: DISPONIBILIDAD Y ACTIVACIÓN
========================================================= */

function seo_tm_render_registry_tab() {
    $templates = seo_tm_get_templates();
    $files     = seo_tm_get_php_files();

    $secondary_files = [];
    foreach ($templates as $parent_template) {
        if (!seo_tm_template_supports_device_variants($parent_template)) {
            continue;
        }

        foreach (['mobile', 'desktop'] as $variant) {
            $secondary = seo_template_variant_filename($parent_template->template_file, $variant);

            if ($secondary !== '') {
                $secondary_files[$secondary] = true;
            }
        }
    }

    echo '<h2>Disponibilidad y activación</h2>';
    echo '<p>Esta pestaña decide qué plantillas principales puede utilizar el plugin. <strong>Activa</strong> significa que el plugin sustituye al tema; si se desactiva, WordPress o WooCommerce conservan su plantilla normal. Las secundarias de teléfono/ordenador se gestionan dentro de «Archivos de plantilla» y no aparecen como plantillas independientes.</p>';

    if (empty($templates)) {
        echo '<div class="notice notice-warning inline"><p>No hay plantillas registradas.</p></div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('seo_tm_save_registry', 'seo_tm_registry_nonce');

    echo '<div class="seo-tm-table-wrap">';
    echo '<table class="widefat striped seo-tm-table">';
    echo '<thead><tr>';
    echo '<th>Orden</th>';
    echo '<th>Plantilla</th>';
    echo '<th>Archivo</th>';
    echo '<th>Tipo</th>';
    echo '<th>Modo</th>';
    echo '<th>Usable</th>';
    echo '<th>Activa</th>';
    echo '<th>Asignable</th>';
    echo '<th>Descripción</th>';
    echo '</tr></thead><tbody>';

    foreach ($templates as $template) {
        $prefix = 'templates[' . esc_attr($template->template_key) . ']';
        $current_file = basename($template->template_file);

        echo '<tr>';
        echo '<td><input type="number" class="small-text" name="' . $prefix . '[display_order]" value="' . esc_attr((int) $template->display_order) . '"></td>';

        echo '<td>';
        echo '<input type="text" class="regular-text" name="' . $prefix . '[template_name]" value="' . esc_attr($template->template_name) . '" required>';
        echo '<p><code>' . esc_html($template->template_key) . '</code></p>';
        echo '</td>';

        echo '<td><select name="' . $prefix . '[template_file]">';

        if (!isset($files[$current_file])) {
            echo '<option value="' . esc_attr($current_file) . '" selected>' . esc_html($current_file . ' (no encontrado)') . '</option>';
        }

        foreach ($files as $file_name => $file_path) {
            if (isset($secondary_files[$file_name]) && $file_name !== $current_file) {
                continue;
            }

            echo '<option value="' . esc_attr($file_name) . '" ' . selected($current_file, $file_name, false) . '>' . esc_html($file_name) . '</option>';
        }

        echo '</select></td>';

        echo '<td><select name="' . $prefix . '[template_type]">';
        $types = seo_tm_template_types();
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($template->template_type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td>';

        echo '<td><select name="' . $prefix . '[assignment_mode]">';
        $modes = seo_tm_assignment_modes();
        foreach ($modes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($template->assignment_mode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td>';

        echo '<td><label><input type="checkbox" name="' . $prefix . '[is_public]" value="1" ' . checked((int) $template->is_public, 1, false) . '> Sí</label></td>';
        echo '<td><label><input type="checkbox" name="' . $prefix . '[is_active]" value="1" ' . checked((int) $template->is_active, 1, false) . '> Plugin</label></td>';
        echo '<td><label><input type="checkbox" name="' . $prefix . '[is_assignable]" value="1" ' . checked((int) $template->is_assignable, 1, false) . '> Sí</label></td>';
        echo '<td><textarea name="' . $prefix . '[description]" rows="3">' . esc_textarea((string) $template->description) . '</textarea></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<p class="description">Reglas: una plantilla no usable se desactiva y deja de ser asignable; el modo automático tampoco admite asignaciones manuales.</p>';
    echo '<p><button type="submit" name="seo_tm_save_registry" class="button button-primary button-large">Guardar disponibilidad y activación</button></p>';
    echo '</form>';
}

/* =========================================================
   ESTILOS DEL GESTOR
========================================================= */

function seo_tm_render_styles() {
    echo '<style>
        .seo-tm-wrap code { word-break: break-word; }
        .seo-tm-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(310px,1fr)); gap:18px; }
        .seo-tm-grid-files { grid-template-columns:1fr; }
        .seo-tm-card { background:#fff; border:1px solid #dcdcde; padding:18px; margin:0 0 20px; box-shadow:0 1px 1px rgba(0,0,0,.04); scroll-margin-top:40px; }
        .seo-tm-card h2, .seo-tm-card h3 { margin-top:0; }
        .seo-tm-card-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; }
        .seo-tm-card-heading p { margin-bottom:0; }
        .seo-tm-badges { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:6px; }
        .seo-tm-badge { display:inline-block; border-radius:999px; padding:4px 9px; background:#f0f0f1; font-size:12px; white-space:nowrap; }
        .seo-tm-badge.is-ok { background:#edfaef; color:#116329; }
        .seo-tm-badge.is-muted { background:#f0f0f1; color:#50575e; }
        .seo-tm-badge.is-error { background:#fcf0f1; color:#8a2424; }
        .seo-tm-columns { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; margin-top:16px; }
        .seo-tm-list { border:1px solid #c3c4c7; padding:14px; max-height:360px; overflow:auto; }
        .seo-tm-list.is-assigned { border-color:#46b450; }
        .seo-tm-list label { display:block; margin:0 0 8px; }
        .seo-tm-separator { margin:32px 0; }
        .seo-tm-table-wrap { overflow-x:auto; }
        .seo-tm-table { min-width:1400px; }
        .seo-tm-table th { white-space:nowrap; }
        .seo-tm-table td { vertical-align:top; }
        .seo-tm-table select { max-width:220px; }
        .seo-tm-table textarea { min-width:220px; }
        .seo-tm-code-preview { margin:12px 0; }
        .seo-tm-code-preview summary { cursor:pointer; font-weight:600; }
        .seo-tm-code-preview pre { max-height:360px; overflow:auto; padding:12px; background:#f6f7f7; border:1px solid #ccd0d4; white-space:pre-wrap; font-family:monospace; font-size:12px; }
        .seo-tm-main-file { padding:14px; margin-top:16px; border:1px solid #c3c4c7; background:#f9f9f9; }
        .seo-tm-device-panel { margin:16px 0; padding:14px; border:1px solid #72aee6; background:#f6f7f7; }
        .seo-tm-device-panel-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
        .seo-tm-device-panel-head h3 { margin:0 0 6px; }
        .seo-tm-device-panel-head p { margin-top:4px; }
        .seo-tm-device-effective-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-top:12px; }
        .seo-tm-device-effective { display:grid; grid-template-columns:auto auto 1fr; align-items:center; gap:8px; padding:10px; background:#fff; border:1px solid #dcdcde; }
        .seo-tm-device-effective code { min-width:0; }
        .seo-tm-device-effective small { grid-column:1 / -1; color:#646970; }
        .seo-tm-variants-panel { margin-top:18px; padding-top:18px; border-top:2px solid #dcdcde; }
        details.seo-tm-variants-collapsed > summary { cursor:pointer; padding:8px 0; }
        .seo-tm-variants-panel-inner { margin-top:14px; }
        .seo-tm-variant-settings { margin:12px 0 16px; padding:12px; background:#f6f7f7; border:1px solid #dcdcde; }
        .seo-tm-variant-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:14px; }
        .seo-tm-variant-card { border:1px solid #c3c4c7; padding:14px; background:#fff; }
        .seo-tm-variant-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .seo-tm-variant-upload { margin-top:12px; }
        .seo-tm-cart-style-link { margin-top:18px; padding:14px; border:1px solid #72aee6; background:#f0f6fc; }
        .seo-tm-cart-style-link p { margin:6px 0 10px; }
        @media (max-width:782px) {
            .seo-tm-columns { grid-template-columns:1fr; }
            .seo-tm-device-effective-grid, .seo-tm-variant-grid { grid-template-columns:1fr; }
            .seo-tm-device-panel-head, .seo-tm-variant-heading { display:block; }
            .seo-tm-card-heading { display:block; }
            .seo-tm-badges { justify-content:flex-start; margin-top:12px; }
        }
    </style>';
}