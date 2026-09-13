<?php
/**
 * Gestor de plantillas de correo de WooCommerce.
 *
 * Este archivo se carga desde template-manager.php y añade la pestaña
 * "Correos WooCommerce". Detecta dinámicamente las clases de correo,
 * permite asignar plantillas PHP registradas en wp_seo_templates y cede
 * el control a WooCommerce cuando una asignación está desactivada.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   RUTAS Y UTILIDADES
========================================================= */

function seo_mail_template_dir() {
    if (function_exists('seo_template_dir')) {
        return trailingslashit(seo_template_dir());
    }

    return trailingslashit(WP_PLUGIN_DIR . '/seo-taxonomy/seo-system/templates/');
}

function seo_mail_admin_url(array $args = []) {
    $url = admin_url('admin.php?page=seo-templates&tab=correos');

    if (!empty($args)) {
        $url = add_query_arg($args, $url);
    }

    return $url;
}

function seo_mail_redirect($message, $type = 'success') {
    $url = seo_mail_admin_url([
        'seo_mail_notice'      => $message,
        'seo_mail_notice_type' => $type,
    ]);

    wp_safe_redirect($url);
    exit;
}



function seo_mail_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'seo_mail_templates';
}

function seo_mail_templates_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'seo_templates';
}

function seo_mail_table_exists($table) {
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    )) === $table;
}

/* =========================================================
   TABLA DE ASIGNACIONES DE CORREO
========================================================= */

function seo_mail_ensure_schema() {
    global $wpdb;

    if (function_exists('seo_tm_ensure_schema')) {
        $templates_schema = seo_tm_ensure_schema();

        if (is_wp_error($templates_schema)) {
            return $templates_schema;
        }
    }

    $templates_table = seo_mail_templates_table_name();
    if (!seo_mail_table_exists($templates_table)) {
        return new WP_Error('missing_templates_table', 'No existe la tabla ' . $templates_table . '.');
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = seo_mail_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        mail_key varchar(191) NOT NULL,
        mail_name varchar(191) NOT NULL,
        email_class varchar(191) DEFAULT NULL,
        default_template varchar(191) DEFAULT NULL,
        default_plain_template varchar(191) DEFAULT NULL,
        default_template_base varchar(255) DEFAULT NULL,
        template_key varchar(191) DEFAULT NULL,
        is_enabled tinyint(1) NOT NULL DEFAULT 0,
        last_seen_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY mail_key (mail_key),
        KEY template_key (template_key)
    ) {$charset_collate};";

    dbDelta($sql);

    if (!seo_mail_table_exists($table)) {
        return new WP_Error('mail_schema_failed', 'No se pudo crear la tabla ' . $table . '.');
    }

    return true;
}

/* =========================================================
   DETECCIÓN DINÁMICA DE CORREOS DE WOOCOMMERCE
========================================================= */

function seo_mail_woocommerce_available() {
    return function_exists('WC') && class_exists('WooCommerce');
}

function seo_mail_get_woocommerce_emails() {
    if (!seo_mail_woocommerce_available()) {
        return [];
    }

    $mailer = WC()->mailer();
    if (!$mailer || !method_exists($mailer, 'get_emails')) {
        return [];
    }

    $results = [];

    foreach ($mailer->get_emails() as $class_key => $email) {
        if (!is_object($email)) continue;

        $mail_key = isset($email->id) ? sanitize_key((string) $email->id) : '';
        if ($mail_key === '') continue;

        $title = '';
        if (method_exists($email, 'get_title')) {
            $title = (string) $email->get_title();
        } elseif (isset($email->title)) {
            $title = (string) $email->title;
        }

        if ($title === '') {
            $title = ucwords(str_replace('_', ' ', $mail_key));
        }

        $description = method_exists($email, 'get_description')
            ? wp_strip_all_tags((string) $email->get_description())
            : '';

        $template_html  = isset($email->template_html) ? (string) $email->template_html : '';
        $template_plain = isset($email->template_plain) ? (string) $email->template_plain : '';
        $template_base  = isset($email->template_base) ? trailingslashit((string) $email->template_base) : '';

        /*
         * Si el filtro de este módulo ya sustituyó template_base, conservamos
         * como "original" el valor guardado en la tabla durante la detección previa.
         */
        if ($template_base === seo_mail_template_dir()) {
            $saved = seo_mail_get_assignment($mail_key);

            if ($saved) {
                $template_html  = (string) $saved->default_template;
                $template_plain = (string) $saved->default_plain_template;
            }
        }

        $results[$mail_key] = [
            'mail_key'               => $mail_key,
            'mail_name'              => $title,
            'description'            => $description,
            'email_class'            => is_string($class_key) ? $class_key : get_class($email),
            'default_template'       => $template_html,
            'default_plain_template' => $template_plain,
            'default_template_base'  => $template_base,
            'object'                 => $email,
        ];
    }

    uasort($results, static function ($a, $b) {
        return strcasecmp($a['mail_name'], $b['mail_name']);
    });

    return $results;
}

function seo_mail_get_assignment($mail_key) {
    global $wpdb;

    $table = seo_mail_table_name();
    if (!seo_mail_table_exists($table)) return null;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE mail_key = %s LIMIT 1",
        sanitize_key($mail_key)
    ));
}

function seo_mail_legacy_template_map() {
    return apply_filters('seo_mail_legacy_template_map', [
        'customer_processing_order' => 'email_processing',
        'customer_completed_order'  => 'email_completed',
        'cancelled_order'            => 'email_cancelled',
        'customer_refunded_order'   => 'email_refunded',
    ]);
}

function seo_mail_template_key_exists($template_key) {
    global $wpdb;

    $table = seo_mail_templates_table_name();

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE template_key = %s",
        sanitize_key($template_key)
    ));
}

function seo_mail_sync_woocommerce_emails() {
    global $wpdb;

    $schema = seo_mail_ensure_schema();
    if (is_wp_error($schema)) return $schema;

    if (!seo_mail_woocommerce_available()) {
        return new WP_Error('woocommerce_missing', 'WooCommerce no está activo.');
    }

    $table      = seo_mail_table_name();
    $emails     = seo_mail_get_woocommerce_emails();
    $legacy_map = seo_mail_legacy_template_map();
    $now        = current_time('mysql');

    foreach ($emails as $mail_key => $email_data) {
        $existing = seo_mail_get_assignment($mail_key);

        $default_template       = $email_data['default_template'];
        $default_plain_template = $email_data['default_plain_template'];
        $default_template_base  = $email_data['default_template_base'];

        if ($existing) {
            if ($default_template === '' && !empty($existing->default_template)) {
                $default_template = (string) $existing->default_template;
            }

            if ($default_plain_template === '' && !empty($existing->default_plain_template)) {
                $default_plain_template = (string) $existing->default_plain_template;
            }

            if ($default_template_base === seo_mail_template_dir() && !empty($existing->default_template_base)) {
                $default_template_base = (string) $existing->default_template_base;
            }

            $wpdb->update(
                $table,
                [
                    'mail_name'              => $email_data['mail_name'],
                    'email_class'            => $email_data['email_class'],
                    'default_template'       => $default_template,
                    'default_plain_template' => $default_plain_template,
                    'default_template_base'  => $default_template_base,
                    'last_seen_at'           => $now,
                ],
                ['mail_key' => $mail_key],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%s']
            );
        } else {
            $template_key = null;

            if (isset($legacy_map[$mail_key]) && seo_mail_template_key_exists($legacy_map[$mail_key])) {
                $template_key = $legacy_map[$mail_key];
            }

            $wpdb->insert(
                $table,
                [
                    'mail_key'               => $mail_key,
                    'mail_name'              => $email_data['mail_name'],
                    'email_class'            => $email_data['email_class'],
                    'default_template'       => $default_template,
                    'default_plain_template' => $default_plain_template,
                    'default_template_base'  => $default_template_base,
                    'template_key'           => $template_key,
                    'is_enabled'             => 0,
                    'last_seen_at'            => $now,
                    'updated_at'              => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }
    }

    return true;
}

function seo_mail_get_assignments() {
    global $wpdb;

    $table = seo_mail_table_name();
    if (!seo_mail_table_exists($table)) return [];

    return $wpdb->get_results(
        "SELECT * FROM {$table}
         ORDER BY mail_name ASC, mail_key ASC"
    );
}

/* =========================================================
   CATÁLOGO DE PLANTILLAS DE EMAIL
========================================================= */

function seo_mail_get_email_templates($only_available = false) {
    global $wpdb;

    $table = seo_mail_templates_table_name();
    if (!seo_mail_table_exists($table)) return [];

    $where = "template_type = 'email'";

    if ($only_available) {
        $where .= ' AND is_public = 1';
    }

    return $wpdb->get_results(
        "SELECT * FROM {$table}
         WHERE {$where}
         ORDER BY display_order ASC, template_name ASC, template_key ASC"
    );
}

function seo_mail_get_email_template($template_key) {
    global $wpdb;

    $table = seo_mail_templates_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE template_key = %s
           AND template_type = 'email'
         LIMIT 1",
        sanitize_key($template_key)
    ));
}



/* =========================================================
   VALIDACIÓN Y BACKUPS
========================================================= */

function seo_mail_validate_php_upload(array $uploaded_file) {
    if (empty($uploaded_file['tmp_name']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
        return new WP_Error('invalid_upload', 'No se ha recibido un archivo válido.');
    }

    if (!empty($uploaded_file['error'])) {
        return new WP_Error('upload_error', 'Se produjo un error durante la subida.');
    }

    $extension = strtolower(pathinfo((string) $uploaded_file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'php') {
        return new WP_Error('invalid_extension', 'Solo se permiten archivos PHP.');
    }

    $content = file_get_contents($uploaded_file['tmp_name']);

    if ($content === false || trim($content) === '') {
        return new WP_Error('empty_file', 'El archivo está vacío o no se puede leer.');
    }

    if (strpos($content, '&lt;') !== false || strpos($content, '&gt;') !== false) {
        return new WP_Error('escaped_php', 'El archivo parece contener PHP escapado como HTML.');
    }

    if (strpos($content, '<?php') === false) {
        return new WP_Error('missing_php', 'La plantilla debe contener una apertura <?php.');
    }

    return true;
}

function seo_mail_create_backup($file_path) {
    if (!file_exists($file_path)) {
        return new WP_Error('missing_file', 'No existe el archivo que se quiere respaldar.');
    }

    $info        = pathinfo($file_path);
    $backup_name = $info['filename'] . '_mail_backup_' . date_i18n('Ymd_His') . '.php';
    $backup_path = trailingslashit($info['dirname']) . $backup_name;

    if (!copy($file_path, $backup_path)) {
        return new WP_Error('backup_failed', 'No se pudo crear el backup.');
    }

    return $backup_name;
}

/* =========================================================
   GUARDAR ASIGNACIONES Y ESTADOS
========================================================= */

function seo_mail_handle_save_assignments() {
    global $wpdb;

    if (empty($_POST['seo_mail_save_assignments'])) return;

    check_admin_referer('seo_mail_save_assignments', 'seo_mail_assignments_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar correos.');
    }

    $table      = seo_mail_table_name();
    $posted     = isset($_POST['mail_config']) && is_array($_POST['mail_config'])
        ? wp_unslash($_POST['mail_config'])
        : [];
    $assignments = seo_mail_get_assignments();
    $valid_keys = [];

    foreach (seo_mail_get_email_templates(true) as $template) {
        $valid_keys[] = (string) $template->template_key;
    }

    foreach ($assignments as $assignment) {
        $mail_key = (string) $assignment->mail_key;
        $row      = isset($posted[$mail_key]) && is_array($posted[$mail_key]) ? $posted[$mail_key] : [];

        $template_key = isset($row['template_key']) ? sanitize_key($row['template_key']) : '';
        $is_enabled   = !empty($row['is_enabled']) ? 1 : 0;

        if ($template_key === '' || !in_array($template_key, $valid_keys, true)) {
            $template_key = null;
            $is_enabled   = 0;
        }

        $wpdb->update(
            $table,
            [
                'template_key' => $template_key,
                'is_enabled'   => $is_enabled,
                'updated_at'   => current_time('mysql'),
            ],
            ['mail_key' => $mail_key],
            ['%s', '%d', '%s'],
            ['%s']
        );
    }

    seo_mail_redirect('Asignación de correos guardada correctamente.');
}

function seo_mail_handle_save_library() {
    global $wpdb;

    if (empty($_POST['seo_mail_save_library'])) return;

    check_admin_referer('seo_mail_save_library', 'seo_mail_library_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar plantillas de correo.');
    }

    $table  = seo_mail_templates_table_name();
    $posted = isset($_POST['email_templates']) && is_array($_POST['email_templates'])
        ? wp_unslash($_POST['email_templates'])
        : [];

    foreach (seo_mail_get_email_templates(false) as $template) {
        $key = (string) $template->template_key;
        $row = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : [];

        $name          = isset($row['template_name']) ? sanitize_text_field($row['template_name']) : (string) $template->template_name;
        $description   = isset($row['description']) ? sanitize_textarea_field($row['description']) : '';
        $display_order = isset($row['display_order']) ? (int) $row['display_order'] : 0;
        $is_public     = !empty($row['is_public']) ? 1 : 0;
        $is_active     = !empty($row['is_active']) ? 1 : 0;

        if (!$is_public) {
            $is_active = 0;
        }

        $wpdb->update(
            $table,
            [
                'template_name'  => $name,
                'description'    => $description,
                'display_order'  => $display_order,
                'is_public'      => $is_public,
                'is_active'      => $is_active,
                'template_type'  => 'email',
                'is_assignable'  => 0,
                'assignment_mode'=> 'automatic',
                'updated_at'     => current_time('mysql'),
            ],
            ['template_key' => $key],
            ['%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s'],
            ['%s']
        );
    }

    seo_mail_redirect('Biblioteca de correos actualizada correctamente.');
}

function seo_mail_handle_replace_template() {
    global $wpdb;

    if (empty($_POST['seo_mail_replace_template'])) return;

    check_admin_referer('seo_mail_replace_template', 'seo_mail_replace_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para reemplazar plantillas.');
    }

    $template_key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template     = seo_mail_get_email_template($template_key);

    if (!$template) {
        seo_mail_redirect('La plantilla de correo no existe.', 'error');
    }

    $validation = seo_mail_validate_php_upload($_FILES['template_file'] ?? []);
    if (is_wp_error($validation)) {
        seo_mail_redirect($validation->get_error_message(), 'error');
    }

    $file_path = seo_mail_template_dir() . basename((string) $template->template_file);

    if (!file_exists($file_path)) {
        seo_mail_redirect('El archivo actual no existe. Registra o crea primero el archivo.', 'error');
    }

    if (!is_writable($file_path)) {
        seo_mail_redirect('El archivo actual no tiene permisos de escritura.', 'error');
    }

    $backup = seo_mail_create_backup($file_path);
    if (is_wp_error($backup)) {
        seo_mail_redirect($backup->get_error_message(), 'error');
    }

    if (!move_uploaded_file($_FILES['template_file']['tmp_name'], $file_path)) {
        seo_mail_redirect('No se pudo guardar la nueva plantilla. El backup se conserva.', 'error');
    }

    $wpdb->update(
        seo_mail_templates_table_name(),
        [
            'template_content' => null,
            'updated_at'       => current_time('mysql'),
        ],
        ['template_key' => $template_key],
        ['%s', '%s'],
        ['%s']
    );

    seo_mail_redirect('Plantilla reemplazada. Backup creado: ' . $backup . '.');
}

function seo_mail_handle_upload_new_template() {
    global $wpdb;

    if (empty($_POST['seo_mail_upload_new_template'])) return;

    check_admin_referer('seo_mail_upload_new_template', 'seo_mail_new_template_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para crear plantillas.');
    }

    $template_key  = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
    $description   = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

    if ($template_key === '' || $template_name === '') {
        seo_mail_redirect('La clave y el nombre de la plantilla son obligatorios.', 'error');
    }

    if (seo_mail_template_key_exists($template_key)) {
        seo_mail_redirect('Ya existe una plantilla con esa clave.', 'error');
    }

    $validation = seo_mail_validate_php_upload($_FILES['template_file'] ?? []);
    if (is_wp_error($validation)) {
        seo_mail_redirect($validation->get_error_message(), 'error');
    }

    $directory = seo_mail_template_dir();

    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        seo_mail_redirect('No se pudo crear la carpeta de plantillas.', 'error');
    }

    $file_name = sanitize_file_name((string) $_FILES['template_file']['name']);
    $file_name = basename($file_name);
    $file_path = $directory . $file_name;

    if (file_exists($file_path)) {
        seo_mail_redirect('Ya existe un archivo con ese nombre. Usa Reemplazar plantilla.', 'error');
    }

    if (!move_uploaded_file($_FILES['template_file']['tmp_name'], $file_path)) {
        seo_mail_redirect('No se pudo guardar el archivo subido.', 'error');
    }

    $inserted = $wpdb->insert(
        seo_mail_templates_table_name(),
        [
            'template_key'    => $template_key,
            'template_name'   => $template_name,
            'template_file'   => $file_name,
            'template_content'=> null,
            'updated_at'      => current_time('mysql'),
            'is_active'       => 1,
            'template_type'   => 'email',
            'is_public'       => 1,
            'is_assignable'   => 0,
            'assignment_mode' => 'automatic',
            'display_order'   => 0,
            'description'     => $description,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']
    );

    if (!$inserted) {
        @unlink($file_path);
        seo_mail_redirect('No se pudo registrar la plantilla: ' . $wpdb->last_error, 'error');
    }

    seo_mail_redirect('Nueva plantilla de correo registrada correctamente.');
}

function seo_mail_handle_register_existing_file() {
    global $wpdb;

    if (empty($_POST['seo_mail_register_existing'])) return;

    check_admin_referer('seo_mail_register_existing', 'seo_mail_register_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para registrar archivos.');
    }

    $template_key  = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
    $template_file = isset($_POST['template_file']) ? basename(sanitize_file_name(wp_unslash($_POST['template_file']))) : '';
    $description   = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

    if ($template_key === '' || $template_name === '' || $template_file === '') {
        seo_mail_redirect('Completa la clave, el nombre y el archivo.', 'error');
    }

    if (seo_mail_template_key_exists($template_key)) {
        seo_mail_redirect('Ya existe una plantilla con esa clave.', 'error');
    }

    if (strtolower(pathinfo($template_file, PATHINFO_EXTENSION)) !== 'php') {
        seo_mail_redirect('Solo se pueden registrar archivos PHP.', 'error');
    }

    if (!file_exists(seo_mail_template_dir() . $template_file)) {
        seo_mail_redirect('El archivo seleccionado no existe.', 'error');
    }

    $wpdb->insert(
        seo_mail_templates_table_name(),
        [
            'template_key'     => $template_key,
            'template_name'    => $template_name,
            'template_file'    => $template_file,
            'template_content' => null,
            'updated_at'       => current_time('mysql'),
            'is_active'        => 1,
            'template_type'    => 'email',
            'is_public'        => 1,
            'is_assignable'    => 0,
            'assignment_mode'  => 'automatic',
            'display_order'    => 0,
            'description'      => $description,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']
    );

    seo_mail_redirect('Archivo existente registrado como plantilla de correo.');
}

function seo_mail_handle_copy_woocommerce_template() {
    global $wpdb;

    if (empty($_POST['seo_mail_copy_default'])) return;

    check_admin_referer('seo_mail_copy_default', 'seo_mail_copy_nonce');

    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para crear plantillas.');
    }

    $mail_key   = isset($_POST['seo_mail_copy_default']) ? sanitize_key(wp_unslash($_POST['seo_mail_copy_default'])) : '';
    $assignment = seo_mail_get_assignment($mail_key);

    if (!$assignment || empty($assignment->default_template)) {
        seo_mail_redirect('No se ha podido localizar la plantilla HTML original de WooCommerce.', 'error');
    }

    if (!seo_mail_woocommerce_available()) {
        seo_mail_redirect('WooCommerce no está activo.', 'error');
    }

    $source_base = !empty($assignment->default_template_base)
        ? trailingslashit((string) $assignment->default_template_base)
        : trailingslashit(WC()->plugin_path()) . 'templates/';
    $source = $source_base . ltrim((string) $assignment->default_template, '/');

    if (!file_exists($source)) {
        seo_mail_redirect('No existe la plantilla original en WooCommerce: ' . $assignment->default_template, 'error');
    }

    $directory = seo_mail_template_dir();
    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        seo_mail_redirect('No se pudo crear la carpeta de plantillas.', 'error');
    }

    $filename     = 'email-' . sanitize_file_name(str_replace('_', '-', $mail_key)) . '.php';
    $destination  = $directory . $filename;
    $template_key = 'mail_' . $mail_key;

    if (file_exists($destination)) {
        $registered = $wpdb->get_var($wpdb->prepare(
            "SELECT template_key FROM " . seo_mail_templates_table_name() . " WHERE template_file = %s LIMIT 1",
            $filename
        ));

        if ($registered) {
            $wpdb->update(
                seo_mail_table_name(),
                [
                    'template_key' => $registered,
                    'updated_at'   => current_time('mysql'),
                ],
                ['mail_key' => $mail_key],
                ['%s', '%s'],
                ['%s']
            );

            seo_mail_redirect('El archivo ya existía y se ha asignado al correo. Activa Gestiona el plugin cuando quieras utilizarlo.', 'info');
        }

        seo_mail_redirect('Ya existe el archivo ' . $filename . ', pero todavía no está registrado.', 'warning');
    }

    if (!copy($source, $destination)) {
        seo_mail_redirect('No se pudo copiar la plantilla original de WooCommerce.', 'error');
    }

    if (seo_mail_template_key_exists($template_key)) {
        $template_key .= '_' . wp_rand(1000, 9999);
    }

    $wpdb->insert(
        seo_mail_templates_table_name(),
        [
            'template_key'     => $template_key,
            'template_name'    => 'Email: ' . (string) $assignment->mail_name,
            'template_file'    => $filename,
            'template_content' => null,
            'updated_at'       => current_time('mysql'),
            'is_active'        => 1,
            'template_type'    => 'email',
            'is_public'        => 1,
            'is_assignable'    => 0,
            'assignment_mode'  => 'automatic',
            'display_order'    => 0,
            'description'      => 'Copia de la plantilla HTML original de WooCommerce para ' . (string) $assignment->mail_name . '.',
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']
    );

    $wpdb->update(
        seo_mail_table_name(),
        [
            'template_key' => $template_key,
            'is_enabled'   => 0,
            'updated_at'   => current_time('mysql'),
        ],
        ['mail_key' => $mail_key],
        ['%s', '%d', '%s'],
        ['%s']
    );

    seo_mail_redirect('Plantilla original copiada y asignada. Revísala y activa Gestiona el plugin para utilizarla.');
}

/* =========================================================
   DESCARGA SEGURA
========================================================= */

function seo_mail_download_template() {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para descargar plantillas.');
    }

    $template_key = isset($_GET['template_key']) ? sanitize_key(wp_unslash($_GET['template_key'])) : '';
    check_admin_referer('seo_mail_download_' . $template_key);

    $template = seo_mail_get_email_template($template_key);

    if (!$template) {
        wp_die('La plantilla no existe.');
    }

    $file_path = seo_mail_template_dir() . basename((string) $template->template_file);

    if (!file_exists($file_path) || !is_readable($file_path)) {
        wp_die('El archivo no existe o no se puede leer.');
    }

    nocache_headers();
    header('Content-Type: application/x-httpd-php; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($file_path));

    readfile($file_path);
    exit;
}
add_action('admin_post_seo_mail_download_template', 'seo_mail_download_template');

/* =========================================================
   INICIALIZACIÓN DEL ADMINISTRADOR
========================================================= */

function seo_mail_admin_init() {
    if (!is_admin() || !current_user_can('manage_options')) return;

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

    if ($page !== 'seo-templates' || $tab !== 'correos') return;

    $schema = seo_mail_ensure_schema();
    if (is_wp_error($schema)) return;

    if (seo_mail_woocommerce_available()) {
        seo_mail_sync_woocommerce_emails();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    seo_mail_handle_save_assignments();
    seo_mail_handle_save_library();
    seo_mail_handle_replace_template();
    seo_mail_handle_upload_new_template();
    seo_mail_handle_register_existing_file();
    seo_mail_handle_copy_woocommerce_template();
}
add_action('admin_init', 'seo_mail_admin_init');

/* =========================================================
   CARGADOR REAL DE PLANTILLAS DE CORREO
========================================================= */

function seo_mail_get_active_mappings() {
    global $wpdb;

    static $cache = null;
    if ($cache !== null) return $cache;

    $mail_table      = seo_mail_table_name();
    $templates_table = seo_mail_templates_table_name();

    if (!seo_mail_table_exists($mail_table) || !seo_mail_table_exists($templates_table)) {
        $cache = [];
        return $cache;
    }

    $rows = $wpdb->get_results(
        "SELECT mail.mail_key,
                mail.template_key,
                templates.template_file
         FROM {$mail_table} AS mail
         INNER JOIN {$templates_table} AS templates
                 ON templates.template_key = mail.template_key
         WHERE mail.is_enabled = 1
           AND templates.template_type = 'email'
           AND templates.is_public = 1
           AND templates.is_active = 1"
    );

    $cache = [];

    foreach ($rows as $row) {
        $file_path = seo_mail_template_dir() . basename((string) $row->template_file);

        if (!file_exists($file_path) || !is_readable($file_path)) continue;

        $cache[(string) $row->mail_key] = [
            'template_key'  => (string) $row->template_key,
            'template_file' => basename((string) $row->template_file),
        ];
    }

    return $cache;
}

/**
 * WooCommerce construye sus clases de correo y permite filtrarlas antes de
 * utilizarlas. Cambiamos template_html y template_base únicamente para los
 * correos que el administrador haya activado. Si no hay asignación válida,
 * el objeto queda intacto y WooCommerce/tema siguen teniendo el control.
 */
function seo_mail_apply_email_templates($emails) {
    if (!is_array($emails)) return $emails;

    $mappings = seo_mail_get_active_mappings();
    if (empty($mappings)) return $emails;

    foreach ($emails as $key => $email) {
        if (!is_object($email) || empty($email->id)) continue;

        $mail_key = sanitize_key((string) $email->id);
        if (!isset($mappings[$mail_key])) continue;

        $email->template_html = $mappings[$mail_key]['template_file'];
        $email->template_base = seo_mail_template_dir();

        $emails[$key] = $email;
    }

    return $emails;
}
add_filter('woocommerce_email_classes', 'seo_mail_apply_email_templates', 999);