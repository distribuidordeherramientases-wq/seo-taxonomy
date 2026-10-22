<?php
/**
 * Editor PHP administrativo: solo archivos de la biblioteca propia.
 * Las plantillas integradas en el ZIP se pueden consultar y duplicar;
 * nunca se sobreescriben durante una actualizacion.
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

    private static function safe_name($input) {
        $name = sanitize_file_name((string)$input);
        if (!TCT_Router::valid_filename($name)) {
            return '';
        }
        // Nombres internos no editables, ni disponibles como plantillas.
        if (in_array($name, array('index.php','template-helpers.php'), true)) {
            return '';
        }
        return $name;
    }

    public static function source($name) {
        if ($name === '') {
            return '';
        }
        return TCT_Router::path($name);
    }

    public static function prepare_directory() {
        $dir = TCT_Router::library_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('tct_dir', 'No se pudo crear la carpeta de plantillas.');
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return new WP_Error('tct_not_writable', 'La carpeta de plantillas no es escribible.');
        }
        // Defensa en profundidad para servidores Apache e IIS.
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

    public static function save() {
        if (!self::can_edit()) {
            wp_die('El editor PHP requiere permisos de edicion de plugins y que WordPress permita editar archivos.');
        }
        check_admin_referer('tct_save_code');
        $name = isset($_POST['filename']) ? self::safe_name(wp_unslash($_POST['filename'])) : '';
        $source = isset($_POST['source_filename']) ? self::safe_name(wp_unslash($_POST['source_filename'])) : '';
        $raw = isset($_POST['code']) ? wp_unslash($_POST['code']) : '';
        $code = is_string($raw) ? $raw : '';
        // Recuperacion en caso de validacion fallida: un error PHP no debe
        // provocar que el usuario pierda los cambios escritos en el editor.
        if (strlen($code) <= self::MAX_SIZE) {
            set_transient('tct_editor_pending_' . get_current_user_id(), array(
                'filename' => $name, 'source' => $source, 'code' => $code
            ), 10 * MINUTE_IN_SECONDS);
        }
        if ($name === '') {
            self::redirect('Nombre PHP no valido.');
        }
        $valid = self::validate_php($code);
        if (is_wp_error($valid)) {
            // No se almacena ni se evalua codigo invalido.
            self::redirect($valid->get_error_message(), $source);
        }

        $folder = self::prepare_directory();
        if (is_wp_error($folder)) {
            self::redirect($folder->get_error_message(), $source);
        }
        if (is_file(TCT_DIR . 'templates/' . $name)) {
            self::redirect('No es posible sobrescribir un archivo integrado; guardalo con otro nombre.', $source);
        }
        $dest = $folder . $name;
        if (is_link($dest)) {
            self::redirect('Los enlaces simbolicos estan prohibidos.', $source);
        }
        $exists = is_file($dest);
        if ($exists && empty($_POST['confirm_replace'])) {
            self::redirect('El archivo ya existe. Marca reemplazar para confirmar.', $source);
        }
        if ($exists) {
            $backup = $folder . $name . '.' . gmdate('YmdHis') . '.' . wp_generate_password(6, false, false) . '.bak';
            if (!copy($dest, $backup)) {
                self::redirect('Error creando copia de seguridad. Sin cambios.', $source);
            }
        }
        $temp = tempnam($folder, 'tct-');
        if (!$temp) {
            self::redirect('No se pudo crear el archivo temporal.', $source);
        }
        $length = file_put_contents($temp, $code, LOCK_EX);
        if (false === $length || $length !== strlen($code)) {
            @unlink($temp);
            self::redirect('Escritura incompleta; el original no se ha modificado.', $source);
        }
        @chmod($temp, 0640);
        if (!rename($temp, $dest)) {
            @unlink($temp);
            self::redirect('Error al sustituir el archivo. Conservado el original.', $source);
        }
        delete_transient('tct_editor_pending_' . get_current_user_id());
        self::redirect('Plantilla guardada y sintaxis PHP validada.', $name);
    }

    private static function redirect($notice, $source = '') {
        $url = TCT_Request_Admin::admin_url('editor', array('tct_notice' => $notice));
        if ($source !== '') {
            $url = add_query_arg('tct_source', $source, $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function original_for_request($request_key) {
        $definitions = TCT_Request_Registry::definitions();
        if (!$request_key || !isset($definitions[$request_key])) {
            return '';
        }
        $ref = (string) $definitions[$request_key][2];
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_\.-]*\.php$#D', $ref) ||
            false !== strpos($ref, '..')) {
            return '';
        }
        $group = (string)$definitions[$request_key][1];
        if (strpos($group, 'WooCommerce -') === 0) {
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

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        $can_edit = self::can_edit();
        $source = isset($_GET['tct_source']) ? self::safe_name(wp_unslash($_GET['tct_source'])) : '';
        $assign = isset($_GET['tct_assign']) ? sanitize_key(wp_unslash($_GET['tct_assign'])) : '';
        $path = self::source($source);
        $original_path = (!$path && $assign) ? self::original_for_request($assign) : '';
        $code = $path ? file_get_contents($path) : ($original_path ? file_get_contents($original_path) : '');
        if (false === $code) {
            $code = '';
        }
        $bundled = $path && strpos($path, trailingslashit(TCT_DIR . 'templates')) === 0;
        $filename = $bundled ? ('custom-' . $source) : $source;
        if (!$filename && $assign && $original_path) {
            $filename = 'custom-' . sanitize_file_name($assign) . '.php';
        }
        if (!$filename) {
            $filename = 'custom-template.php';
            $code = "<?php\n/**\n * Plantilla independiente TaxoCommerce.\n */\ndefined('ABSPATH') || exit;\nget_header();\n?><main class=\"site-main\">";
            $code .= "<?php while (have_posts()) : the_post(); the_title('<h1>', '</h1>'); the_content(); endwhile; ?></main><?php get_footer();\n";
        }

        $pending = get_transient('tct_editor_pending_' . get_current_user_id());
        if (is_array($pending) && isset($_GET['tct_notice']) && isset($pending['code']) &&
            isset($pending['source']) && (string)$pending['source'] === $source) {
            $filename = isset($pending['filename']) ? (string)$pending['filename'] : $filename;
            $code = (string)$pending['code'];
            delete_transient('tct_editor_pending_' . get_current_user_id());
        }
        echo '<div class="wrap"><h1>Editor de archivos de plantilla PHP</h1>';
        echo '<p>Selecciona cualquier archivo de la biblioteca para ver su contenido, duplicarlo o editarlo. ';
        echo 'Los archivos integrados en el plugin son de <strong>solo lectura</strong>: se guardan como una copia independiente en wp-content.</p>';
        echo '<p>Esta pantalla edita codigo ejecutable. El codigo no se publica si no supera el analizador de sintaxis de PHP; aun asi, puede producir errores en tiempo de ejecucion.</p>';
        if ($original_path) {
            echo '<p>Codigo inicial tomado de la plantilla original de WordPress/WooCommerce: <code>' . esc_html(basename($original_path)) . '</code>. Se guardara una copia independiente.</p>';
        }
        if (!$can_edit) {
            echo '<div class="notice notice-warning"><p>La edicion PHP esta deshabilitada por la configuracion de seguridad o por los permisos del usuario. Puedes consultar archivos, pero no guardarlos.</p></div>';
        }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="taxocommerce-templates"><input type="hidden" name="tab" value="editor">';
        echo '<label>Archivo <select name="tct_source"><option value="">— Plantilla nueva —</option>';
        foreach (TCT_Router::available_files(true) as $file) {
            echo '<option value="' . esc_attr($file) . '" ' . selected($source,$file,false) . '>' . esc_html($file) . '</option>';
        }
        echo '</select></label> ';
        submit_button('Abrir archivo','secondary','submit',false);
        echo '</form><hr>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_save_code');
        echo '<input type="hidden" name="action" value="tct_save_code">';
        echo '<input type="hidden" name="source_filename" value="' . esc_attr($source) . '">';
        echo '<p><label for="tct_filename"><strong>Guardar en archivo</strong></label> ';
        echo '<input type="text" name="filename" id="tct_filename" value="' . esc_attr($filename) . '" size="44" required ' . disabled(!$can_edit,true,false) . '></p>';
        if ($bundled) {
            echo '<p class="description">Se guardara una copia nueva: ' . esc_html($filename) . '</p>';
        }
        if ($assign) {
            echo '<p>Asignacion prevista: <code>' . esc_html($assign) . '</code>. Tras guardar el archivo, selecciona el nuevo nombre en Tipos de peticion.</p>';
        }
        echo '<label for="tct_code"><strong>Contenido de la plantilla</strong></label>';
        echo '<textarea name="code" id="tct_code" rows="28" style="width:100%;max-width:1200px;font-family:monospace;font-size:13px;tab-size:4" spellcheck="false" ' . disabled(!$can_edit,true,false) . '>' . esc_textarea($code) . '</textarea>';
        echo '<p><label><input type="checkbox" name="confirm_replace" value="1" ' . disabled(!$can_edit,true,false) . '> Confirmo que deseo sobrescribir el archivo si ya existe (se guardara una copia)</label></p>';
        submit_button('Guardar codigo PHP con validacion','primary','submit',false,$can_edit ? array() : array('disabled'=>'disabled'));
        echo '</form></div>';
    }
}
