<?php
/**
 * Template file editor and persistent library manager.
 *
 * Bundled plugin templates are read-only. Administrator-created templates live
 * in wp-content/taxocommerce-templates-library so plugin updates never remove
 * them. The editor validates PHP syntax before replacing executable files.
 */
defined('ABSPATH') || exit;

final class TCT_Template_Editor {
    const MAX_SIZE = 1048576;

    public static function can_edit() {
        return current_user_can('manage_options') &&
            current_user_can('edit_plugins') &&
            (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) &&
            function_exists('wp_is_file_mod_allowed') &&
            wp_is_file_mod_allowed('code_edit');
    }

    private static function safe_source_name($input) {
        $name = sanitize_file_name((string)$input);
        return TCT_Router::valid_filename($name) ? $name : '';
    }

    private static function safe_target_name($input) {
        $name = self::safe_source_name($input);
        if ($name === '' || in_array($name, array('index.php','template-helpers.php'), true)) {
            return '';
        }
        return $name;
    }

    public static function source($name) {
        $name = self::safe_source_name($name);
        return $name === '' ? '' : TCT_Router::path($name);
    }

    public static function prepare_directory() {
        $dir = TCT_Router::library_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('tct_dir', 'No se pudo crear la carpeta de plantillas.');
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return new WP_Error('tct_not_writable', 'La carpeta de plantillas no es escribible.');
        }

        $deny = $dir . '.htaccess';
        if (!file_exists($deny) && false === file_put_contents($deny, "Require all denied\nDeny from all\n")) {
            return new WP_Error('tct_no_deny', 'No se pudo crear la proteccion de acceso .htaccess.');
        }

        $webconfig = $dir . 'web.config';
        $config = '<?xml version="1.0" encoding="utf-8"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>';
        if (!file_exists($webconfig) && false === file_put_contents($webconfig, $config)) {
            return new WP_Error('tct_no_webconfig', 'No se pudo crear la proteccion de acceso IIS.');
        }

        $index = $dir . 'index.php';
        if (!file_exists($index) && false === file_put_contents($index, "<?php\n// Silence is golden.\n")) {
            return new WP_Error('tct_no_index', 'No se pudo crear index.php.');
        }

        return $dir;
    }

    public static function validate_php($content) {
        if (!is_string($content) || strlen($content) > self::MAX_SIZE || trim($content) === '') {
            return new WP_Error('tct_size', 'El codigo esta vacio o supera 1 MB.');
        }
        if (!preg_match('/^\s*<\?php\b/', $content)) {
            return new WP_Error('tct_start_tag', 'El archivo debe comenzar con <?php.');
        }
        if (!defined('TOKEN_PARSE')) {
            return new WP_Error('tct_php_parser', 'El servidor no admite comprobacion de sintaxis.');
        }
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $error) {
            return new WP_Error('tct_parse', 'Error de sintaxis PHP: ' . $error->getMessage());
        }
        return true;
    }

    private static function assign_saved_file($request_key, $filename) {
        $request_key = sanitize_key((string)$request_key);
        if ($request_key === '') {
            return true;
        }
        if (!TaxoCommerce_Templates::installed()) {
            return new WP_Error('tct_assign_tables', 'La plantilla se guardo, pero no se pudo asociar porque faltan las tablas de Templates.');
        }

        $definitions = TCT_Request_Registry::definitions();
        if (!isset($definitions[$request_key])) {
            return new WP_Error('tct_assign_request', 'La plantilla se guardo, pero la peticion de destino ya no existe.');
        }
        if (!TCT_Router::path($filename)) {
            return new WP_Error('tct_assign_file', 'La plantilla se guardo, pero el archivo no esta disponible para asociarlo.');
        }

        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE template_key=%s LIMIT 1",
            $request_key
        ));
        $now = current_time('mysql');

        if ($id) {
            $saved = $wpdb->update(
                $table,
                array(
                    'template_file' => $filename,
                    'is_active'     => 1,
                    'updated_at'    => $now,
                ),
                array('id'=>(int)$id)
            );
        } else {
            $group = (string)$definitions[$request_key][1];
            $type = (0 === strpos($group, 'WooCommerce - correos')) ? 'email' :
                ((0 === strpos($group, 'WooCommerce')) ? 'woocommerce' : 'system');
            $saved = $wpdb->insert(
                $table,
                array(
                    'template_key'     => $request_key,
                    'template_name'    => (string)$definitions[$request_key][0],
                    'template_file'    => $filename,
                    'template_type'    => $type,
                    'assignment_mode'  => 'automatic',
                    'is_assignable'    => 0,
                    'is_public'        => 1,
                    'is_active'        => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                )
            );
        }

        return false === $saved
            ? new WP_Error('tct_assign_save', 'La plantilla se guardo, pero no pudo asociarse a la peticion.')
            : true;
    }

    public static function save() {
        if (!self::can_edit()) {
            wp_die('El editor PHP requiere permisos de edicion de plugins y que WordPress permita editar archivos.');
        }
        check_admin_referer('tct_save_code');

        $name = isset($_POST['filename']) ? self::safe_target_name(wp_unslash($_POST['filename'])) : '';
        $source = isset($_POST['source_filename']) ? self::safe_source_name(wp_unslash($_POST['source_filename'])) : '';
        $assign = isset($_POST['assign_request']) ? sanitize_key(wp_unslash($_POST['assign_request'])) : '';
        $raw = isset($_POST['code']) ? wp_unslash($_POST['code']) : '';
        $code = is_string($raw) ? $raw : '';

        if (strlen($code) <= self::MAX_SIZE) {
            set_transient('tct_editor_pending_' . get_current_user_id(), array(
                'filename' => $name,
                'source'   => $source,
                'assign'   => $assign,
                'code'     => $code,
            ), 10 * MINUTE_IN_SECONDS);
        }

        if ($name === '') {
            self::redirect('Nombre PHP no valido.', $source, $assign);
        }

        $valid = self::validate_php($code);
        if (is_wp_error($valid)) {
            self::redirect($valid->get_error_message(), $source, $assign);
        }

        $folder = self::prepare_directory();
        if (is_wp_error($folder)) {
            self::redirect($folder->get_error_message(), $source, $assign);
        }

        if (is_file(TCT_DIR . 'templates/' . $name)) {
            self::redirect('No es posible sobrescribir un archivo integrado; guardalo con otro nombre.', $source, $assign);
        }

        $dest = $folder . $name;
        if (is_link($dest)) {
            self::redirect('Los enlaces simbolicos estan prohibidos.', $source, $assign);
        }

        $exists = is_file($dest);
        if ($exists && empty($_POST['confirm_replace'])) {
            self::redirect('El archivo ya existe. Marca reemplazar para confirmar.', $source, $assign);
        }

        if ($exists) {
            $backup = $folder . $name . '.' . gmdate('YmdHis') . '.' . wp_generate_password(6, false, false) . '.bak';
            if (!copy($dest, $backup)) {
                self::redirect('Error creando copia de seguridad. Sin cambios.', $source, $assign);
            }
        }

        $temp = tempnam($folder, 'tct-');
        if (!$temp) {
            self::redirect('No se pudo crear el archivo temporal.', $source, $assign);
        }

        $length = file_put_contents($temp, $code, LOCK_EX);
        if (false === $length || $length !== strlen($code)) {
            @unlink($temp);
            self::redirect('Escritura incompleta; el original no se ha modificado.', $source, $assign);
        }

        @chmod($temp, 0640);
        if (!rename($temp, $dest)) {
            @unlink($temp);
            self::redirect('Error al sustituir el archivo. Conservado el original.', $source, $assign);
        }

        $assigned = self::assign_saved_file($assign, $name);
        delete_transient('tct_editor_pending_' . get_current_user_id());

        if (is_wp_error($assigned)) {
            self::redirect($assigned->get_error_message(), $name, $assign);
        }

        self::redirect(
            $assign !== ''
                ? 'Plantilla guardada, sintaxis PHP validada y archivo asociado a la peticion.'
                : 'Plantilla guardada y sintaxis PHP validada.',
            $name,
            $assign
        );
    }

    public static function upload() {
        if (!self::can_edit() || !current_user_can('update_plugins')) {
            wp_die('La subida de PHP requiere permisos para editar plugins y que WordPress permita editar codigo.');
        }
        check_admin_referer('tct_upload_template');

        $assign = isset($_POST['assign_request']) ? sanitize_key(wp_unslash($_POST['assign_request'])) : '';
        $files = isset($_FILES['template_file']) ? $_FILES['template_file'] : null;

        if (!is_array($files) || !isset($files['error']) || UPLOAD_ERR_OK !== (int)$files['error']) {
            self::redirect('Error en la subida del archivo.', '', $assign);
        }

        $name = isset($files['name']) ? self::safe_target_name($files['name']) : '';
        if ($name === '' || empty($files['tmp_name']) || !is_uploaded_file($files['tmp_name'])) {
            self::redirect('Nombre o subida no valida. Solo archivos PHP.', '', $assign);
        }

        $size = isset($files['size']) ? (int)$files['size'] : 0;
        if ($size < 8 || $size > self::MAX_SIZE) {
            self::redirect('Archivo vacio o mayor de 1 MB.', '', $assign);
        }

        $content = file_get_contents($files['tmp_name']);
        $valid = self::validate_php($content);
        if (is_wp_error($valid)) {
            self::redirect($valid->get_error_message(), '', $assign);
        }

        $directory = self::prepare_directory();
        if (is_wp_error($directory)) {
            self::redirect($directory->get_error_message(), '', $assign);
        }

        if (is_file(TCT_DIR . 'templates/' . $name)) {
            self::redirect('El nombre coincide con una plantilla integrada. Usa otro nombre.', $name, $assign);
        }

        $target = $directory . $name;
        if (is_link($target)) {
            self::redirect('No se permiten enlaces simbolicos.', '', $assign);
        }

        if (is_file($target)) {
            if (empty($_POST['replace'])) {
                self::redirect('El archivo ya existe. Marca reemplazar para confirmarlo.', $name, $assign);
            }
            $backup = $target . '.' . gmdate('YmdHis') . '.' . wp_generate_password(6, false, false) . '.bak';
            if (!copy($target, $backup)) {
                self::redirect('No se pudo guardar copia de seguridad.', $name, $assign);
            }
        }

        if (!move_uploaded_file($files['tmp_name'], $target)) {
            self::redirect('No se pudo guardar el archivo en la biblioteca.', '', $assign);
        }
        @chmod($target, 0640);

        $assigned = self::assign_saved_file($assign, $name);
        if (is_wp_error($assigned)) {
            self::redirect($assigned->get_error_message(), $name, $assign);
        }

        self::redirect(
            $assign !== '' ? 'Archivo subido y asociado a la peticion.' : 'Archivo incorporado a la biblioteca.',
            $name,
            $assign
        );
    }

    private static function redirect($notice, $source = '', $assign = '') {
        $args = array('tct_notice'=>$notice);
        if ($source !== '') {
            $args['tct_source'] = $source;
        }
        if ($assign !== '') {
            $args['tct_assign'] = $assign;
        }
        wp_safe_redirect(TCT_Request_Admin::admin_url('editor', $args));
        exit;
    }

    public static function original_for_request($request_key) {
        $definitions = TCT_Request_Registry::definitions();
        if (!$request_key || !isset($definitions[$request_key])) {
            return '';
        }

        $ref = (string)$definitions[$request_key][2];
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_\.-]*\.php$#D', $ref) || false !== strpos($ref, '..')) {
            return '';
        }

        $group = (string)$definitions[$request_key][1];
        if (0 === strpos($group, 'WooCommerce -')) {
            if (function_exists('WC') && WC() && method_exists(WC(), 'plugin_path')) {
                $path = trailingslashit(WC()->plugin_path()) . 'templates/' . $ref;
                if (is_file($path) && is_readable($path)) {
                    return $path;
                }
            }
            return '';
        }

        if (function_exists('get_stylesheet_directory') && function_exists('get_template_directory')) {
            foreach (array(get_stylesheet_directory(), get_template_directory()) as $dir) {
                $path = trailingslashit($dir) . $ref;
                if (is_file($path) && is_readable($path)) {
                    return $path;
                }
            }
        }

        return '';
    }

    private static function assignments_by_file() {
        $map = array();
        if (!TaxoCommerce_Templates::installed()) {
            return $map;
        }

        foreach (TCT_Router::rows() as $row) {
            $file = basename((string)$row->template_file);
            if ($file === '') {
                continue;
            }
            if (!isset($map[$file])) {
                $map[$file] = array();
            }
            $map[$file][] = array(
                'key'  => (string)$row->template_key,
                'name' => (string)$row->template_name,
                'type' => (string)$row->template_type,
            );
        }

        return $map;
    }

    private static function file_origin($path) {
        return 0 === strpos((string)$path, trailingslashit(TCT_Router::library_dir()))
            ? 'Biblioteca propia'
            : 'Integrada en plugin';
    }

    private static function file_kind($name, $assignments) {
        if ('template-helpers.php' === $name) {
            return 'Helpers';
        }
        if ('index.php' === $name) {
            return 'Proteccion';
        }
        if (preg_match('/-(mobile|desktop)\.php$/i', $name, $matches)) {
            return 'Variante ' . strtolower($matches[1]);
        }
        if (!empty($assignments[$name])) {
            $types = array_values(array_unique(array_filter(wp_list_pluck($assignments[$name], 'type'))));
            if ($types) {
                return implode(', ', $types);
            }
        }
        return 'Plantilla';
    }

    private static function download_url($name) {
        return wp_nonce_url(
            admin_url('admin-post.php?action=tct_download_template&file=' . rawurlencode($name)),
            'tct_download_' . $name
        );
    }

    private static function duplicate_name($name) {
        $stem = preg_replace('/\.php$/i', '', basename((string)$name));
        $stem = preg_replace('/^custom-/', '', $stem);
        return sanitize_file_name('custom-' . $stem . '-copy.php');
    }

    private static function variant_parent($name) {
        if (!preg_match('/^(.*)-(mobile|desktop)\.php$/i', $name, $matches)) {
            return array('', '');
        }
        $parent = $matches[1] . '.php';
        return array(TCT_Router::valid_filename($parent) ? $parent : '', strtolower($matches[2]));
    }

    private static function render_library($source) {
        $assignments = self::assignments_by_file();
        $files = TCT_Router::available_files(true);
        $search = isset($_GET['tct_file_search']) ? sanitize_text_field(wp_unslash($_GET['tct_file_search'])) : '';

        echo '<section class="tct-editor-library">';
        echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap">';
        echo '<div><h2>Biblioteca de archivos</h2><p>Incluye las plantillas integradas de solo lectura y la biblioteca persistente de <code>wp-content/taxocommerce-templates-library/</code>.</p></div>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="taxocommerce-templates"><input type="hidden" name="tab" value="editor">';
        echo '<input type="search" name="tct_file_search" value="' . esc_attr($search) . '" placeholder="Buscar archivo o asignacion"> ';
        submit_button('Buscar','secondary','submit',false);
        echo '</form></div>';

        echo '<div style="overflow:auto"><table class="widefat striped" style="min-width:1050px">';
        echo '<thead><tr><th>Archivo</th><th>Origen</th><th>Tipo</th><th>Asignado a</th><th>Tamaño</th><th>Modificado</th><th>Acciones</th></tr></thead><tbody>';

        $shown = 0;
        foreach ($files as $name) {
            $path = TCT_Router::path($name);
            if (!$path) {
                continue;
            }

            $assigned = isset($assignments[$name]) ? $assignments[$name] : array();
            $haystack = $name . ' ' . implode(' ', wp_list_pluck($assigned, 'key')) . ' ' . implode(' ', wp_list_pluck($assigned, 'name'));
            if ($search !== '' && false === stripos($haystack, $search)) {
                continue;
            }

            $shown++;
            $origin = self::file_origin($path);
            $kind = self::file_kind($name, $assignments);
            $bundled = 'Integrada en plugin' === $origin;
            $is_internal = in_array($name, array('index.php','template-helpers.php'), true);
            list($variant_parent, $variant_device) = self::variant_parent($name);

            echo '<tr><td><strong><code>' . esc_html($name) . '</code></strong>';
            if ($source === $name) {
                echo '<br><span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span> abierto';
            }
            echo '</td><td>' . esc_html($origin) . '</td><td>' . esc_html($kind) . '</td><td>';
            if (!$assigned) {
                echo '—';
            } else {
                foreach ($assigned as $item) {
                    echo '<div><code>' . esc_html($item['key']) . '</code> · ' . esc_html($item['name']) . '</div>';
                }
            }
            echo '</td><td>' . esc_html(size_format((int)filesize($path), 1)) . '</td>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i', (int)filemtime($path))) . '</td><td>';

            $open = TCT_Request_Admin::admin_url('editor', array('tct_source'=>$name));
            echo '<a href="' . esc_url($open) . '">' . ($bundled || $is_internal ? 'Ver / copiar' : 'Editar') . '</a>';

            if (!$is_internal) {
                echo ' · <a href="' . esc_url(self::download_url($name)) . '">Descargar</a>';
                $duplicate = TCT_Request_Admin::admin_url('editor', array(
                    'tct_source'=>$name,
                    'tct_target'=>self::duplicate_name($name),
                ));
                echo ' · <a href="' . esc_url($duplicate) . '">Duplicar</a>';
            }

            if ($variant_parent !== '' && TCT_Router::path($variant_parent)) {
                echo '<br><small>Secundaria de <code>' . esc_html($variant_parent) . '</code> (' . esc_html($variant_device) . ')</small>';
            } elseif (!$is_internal && !preg_match('/^email-/i', $name)) {
                foreach (array('mobile'=>'Telefono','desktop'=>'Ordenador') as $device=>$label) {
                    $variant = TCT_Router::variant_name($name, $device);
                    if (!$variant) {
                        continue;
                    }
                    $variant_path = TCT_Router::path($variant);
                    $args = $variant_path
                        ? array('tct_source'=>$variant)
                        : array('tct_source'=>$name,'tct_target'=>$variant);
                    echo '<br><a href="' . esc_url(TCT_Request_Admin::admin_url('editor', $args)) . '">' .
                        esc_html(($variant_path ? 'Editar ' : 'Crear ') . $label) . '</a>';
                }
            }
            echo '</td></tr>';
        }

        if (!$shown) {
            echo '<tr><td colspan="7">No hay archivos que coincidan con el filtro.</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function render_upload($assign, $can_edit) {
        echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:20px 0">';
        echo '<h2>Subir plantilla PHP</h2>';
        echo '<p>La subida se guarda en la biblioteca persistente. Si reemplazas un archivo propio existente, antes se crea una copia <code>.bak</code>.</p>';

        if (!$can_edit || !current_user_can('update_plugins')) {
            echo '<div class="notice notice-warning inline"><p>La subida esta deshabilitada por permisos o por la configuracion de seguridad de WordPress.</p></div>';
            echo '</section>';
            return;
        }

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_upload_template');
        echo '<input type="hidden" name="action" value="tct_upload_template">';
        if ($assign !== '') {
            echo '<input type="hidden" name="assign_request" value="' . esc_attr($assign) . '">';
            echo '<p>Al completar la subida se asociara a <code>' . esc_html($assign) . '</code>.</p>';
        }
        echo '<input type="file" name="template_file" accept=".php" required> ';
        echo '<label><input type="checkbox" name="replace" value="1"> Reemplazar archivo propio existente y crear backup</label> ';
        submit_button('Subir a la biblioteca','secondary','submit',false);
        echo '</form></section>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }

        $can_edit = self::can_edit();
        $source = isset($_GET['tct_source']) ? self::safe_source_name(wp_unslash($_GET['tct_source'])) : '';
        $target = isset($_GET['tct_target']) ? self::safe_target_name(wp_unslash($_GET['tct_target'])) : '';
        $assign = isset($_GET['tct_assign']) ? sanitize_key(wp_unslash($_GET['tct_assign'])) : '';

        $path = self::source($source);
        $original_path = (!$path && $assign) ? self::original_for_request($assign) : '';
        $code = $path ? file_get_contents($path) : ($original_path ? file_get_contents($original_path) : '');
        if (false === $code) {
            $code = '';
        }

        $bundled = $path && 0 === strpos($path, trailingslashit(TCT_DIR . 'templates'));
        $filename = $target;
        if (!$filename) {
            $filename = $bundled ? ('custom-' . $source) : $source;
        }
        if (!$filename && $assign && $original_path) {
            $filename = 'custom-' . sanitize_file_name($assign) . '.php';
        }
        if (!$filename) {
            $filename = 'custom-template.php';
            $code = "<?php\n/**\n * Plantilla independiente TaxoCommerce.\n */\ndefined('ABSPATH') || exit;\nget_header();\n?><main class=\"site-main\"><?php while (have_posts()) : the_post(); the_title('<h1>', '</h1>'); the_content(); endwhile; ?></main><?php get_footer();\n";
        }

        $pending = get_transient('tct_editor_pending_' . get_current_user_id());
        if (is_array($pending) && isset($_GET['tct_notice']) && isset($pending['code']) &&
            isset($pending['source']) && (string)$pending['source'] === $source) {
            $filename = isset($pending['filename']) && $pending['filename'] !== '' ? (string)$pending['filename'] : $filename;
            $assign = isset($pending['assign']) ? sanitize_key((string)$pending['assign']) : $assign;
            $code = (string)$pending['code'];
            delete_transient('tct_editor_pending_' . get_current_user_id());
        }

        echo '<div class="wrap tct-template-editor"><h1>Editor y biblioteca de plantillas</h1>';
        echo '<p>El gestor antiguo de archivos queda centralizado aqui: puedes consultar todas las plantillas, subir archivos, duplicarlos, descargar una copia, crear variantes y editar el PHP propio.</p>';
        echo '<p>Los archivos integrados en el plugin son de <strong>solo lectura</strong>. Cualquier cambio se guarda como copia persistente fuera de la carpeta del plugin.</p>';

        if (!$can_edit) {
            echo '<div class="notice notice-warning"><p>La edicion PHP esta deshabilitada por la configuracion de seguridad o por los permisos del usuario. Puedes consultar y descargar archivos, pero no guardarlos.</p></div>';
        }

        self::render_library($source);
        self::render_upload($assign, $can_edit);

        echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin-top:20px">';
        echo '<h2>Editor de codigo PHP</h2>';

        if ($original_path) {
            echo '<p>Codigo inicial tomado de la plantilla original de WordPress/WooCommerce: <code>' . esc_html(basename($original_path)) . '</code>.</p>';
        } elseif ($path) {
            echo '<p>Origen abierto: <code>' . esc_html($source) . '</code> · ' . esc_html(self::file_origin($path)) . '.</p>';
        }

        if ($target !== '') {
            echo '<p>La copia se guardara como <code>' . esc_html($target) . '</code>.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_save_code');
        echo '<input type="hidden" name="action" value="tct_save_code">';
        echo '<input type="hidden" name="source_filename" value="' . esc_attr($source) . '">';
        if ($assign !== '') {
            echo '<input type="hidden" name="assign_request" value="' . esc_attr($assign) . '">';
            echo '<p>Destino: <code>' . esc_html($assign) . '</code>. Al guardar correctamente, el archivo quedara asociado a esa peticion.</p>';
        }
        echo '<p><label for="tct_filename"><strong>Guardar en archivo</strong></label> ';
        echo '<input type="text" name="filename" id="tct_filename" value="' . esc_attr($filename) . '" size="44" required ' . disabled(!$can_edit,true,false) . '></p>';

        if ($bundled && $target === '') {
            echo '<p class="description">La plantilla integrada no se sobrescribe; se guardara como <code>' . esc_html($filename) . '</code>.</p>';
        }

        echo '<label for="tct_code"><strong>Contenido de la plantilla</strong></label>';
        echo '<textarea name="code" id="tct_code" rows="30" style="width:100%;font-family:monospace;font-size:13px;tab-size:4" spellcheck="false" ' .
            disabled(!$can_edit,true,false) . '>' . esc_textarea($code) . '</textarea>';
        echo '<p><label><input type="checkbox" name="confirm_replace" value="1" ' . disabled(!$can_edit,true,false) . '> Confirmo que deseo sobrescribir el archivo propio si ya existe; se guardara una copia de seguridad.</label></p>';
        submit_button('Guardar PHP validado','primary','submit',false,$can_edit ? array() : array('disabled'=>'disabled'));
        echo '</form></section></div>';
    }
}
