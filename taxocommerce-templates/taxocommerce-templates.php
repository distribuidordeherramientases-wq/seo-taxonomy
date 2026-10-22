<?php
/**
 * Plugin Name: TaxoCommerce Templates
 * Description: Roles editoriales, registro de plantillas y asignación automática, independiente de TaxoCommerce Core.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: taxocommerce-templates
 */
defined('ABSPATH') || exit;
define('TCT_VERSION','0.2.0');
define('TCT_FILE',__FILE__);
define('TCT_DIR',plugin_dir_path(__FILE__));
define('TCT_URL',plugin_dir_url(__FILE__));
require_once TCT_DIR . 'includes/class-tct-router.php';

final class TaxoCommerce_Templates {
    const ROLE_OPTION = 'taxocommerce_templates_custom_roles';
    const ENABLE_OPTION = 'taxocommerce_templates_render_enabled';

    public static function nodes() { global $wpdb; return $wpdb->prefix.'seo_nodes'; }
    public static function templates() { global $wpdb; return $wpdb->prefix.'seo_templates'; }
    public static function base_roles() {
        return TCT_Router::page_roles();
    }
    public static function roles() {
        $custom=get_option(self::ROLE_OPTION,array());
        return array_merge(self::base_roles(),is_array($custom)?$custom:array());
    }
    public static function installed() {
        global $wpdb;
        foreach(array(self::nodes(),self::templates()) as $table) {
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) return false;
        }
        return true;
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        $nodes=self::nodes(); $templates=self::templates();
        // The schema remains compatible with existing Core / SEO Taxonomy data.
        dbDelta("CREATE TABLE {$nodes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            seo_role VARCHAR(80) NOT NULL,
            keywords LONGTEXT NULL,
            title VARCHAR(255) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_object_role (object_type,object_id,seo_role),
            KEY object_lookup (object_type,object_id),
            KEY seo_role (seo_role),
            KEY status (status)
        ) {$charset};");
        dbDelta("CREATE TABLE {$templates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(191) NOT NULL,
            template_name VARCHAR(191) NOT NULL,
            template_file VARCHAR(191) NOT NULL,
            template_content LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            template_type VARCHAR(30) NOT NULL DEFAULT 'page',
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            is_assignable TINYINT(1) NOT NULL DEFAULT 0,
            assignment_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
            display_order INT(11) NOT NULL DEFAULT 0,
            description TEXT NULL,
            device_variants_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY template_key (template_key),
            KEY is_active (is_active),
            KEY template_type (template_type)
        ) {$charset};");
        add_option(self::ENABLE_OPTION,0,'',false);
        TCT_Router::seed_catalog();
    }
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'),65);
        add_action('admin_post_tct_save_role',array(__CLASS__,'save_role'));
        add_action('admin_post_tct_save_template',array(__CLASS__,'save_template'));
        add_action('admin_post_tct_settings',array(__CLASS__,'settings'));
        add_action('admin_post_tct_save_page_role',array(__CLASS__,'save_page_role'));
        add_action('admin_post_tct_upload_template',array(__CLASS__,'upload_template'));
        add_action('admin_post_tct_save_device_mode',array(__CLASS__,'save_device_mode'));
        add_action('admin_post_tct_assign_page',array(__CLASS__,'assign_page'));
        add_action('admin_init',array('TCT_Router','seed_catalog'));
        add_action('add_meta_boxes_page',array(__CLASS__,'page_metabox'));
        add_action('save_post_page',array(__CLASS__,'save_metabox'),25,2);
        add_filter('template_include',array('TCT_Router','resolve'),PHP_INT_MAX);
        add_action('taxocommerce_core_register_modules',array(__CLASS__,'register_core'));
        if(function_exists('taxocommerce_core')&&did_action('taxocommerce_core_loaded')) self::register_core(taxocommerce_core());
    }
    public static function register_core($core) {
        if(is_object($core)&&method_exists($core,'register_module')) {
            $core->register_module('templates',array('name'=>'TaxoCommerce Templates','version'=>TCT_VERSION,'status'=>'active','description'=>'Roles y plantillas independientes.','admin_url'=>admin_url('admin.php?page=taxocommerce-templates')));
        }
    }
    public static function menu() {
        $parent=function_exists('taxocommerce_core')?'taxocommerce-core':null;
        if($parent) add_submenu_page($parent,'TaxoCommerce Templates','Plantillas','manage_options','taxocommerce-templates',array(__CLASS__,'screen'));
        else add_menu_page('TaxoCommerce Templates','Templates','manage_options','taxocommerce-templates',array(__CLASS__,'screen'),'dashicons-layout',62);
    }
    private static function guard($nonce) {
        if(!current_user_can('manage_options')) wp_die('Permisos insuficientes.');
        check_admin_referer($nonce);
        if(!self::installed()) wp_die('Tablas no disponibles. Desactiva y reactiva el plugin para instalarlas.');
    }
    private static function redirect($notice) {
        wp_safe_redirect(add_query_arg(array('page'=>'taxocommerce-templates','tct_notice'=>$notice),admin_url('admin.php')));exit;
    }
    public static function page_role($id) {
        global $wpdb;$table=self::nodes();
        return (string)$wpdb->get_var($wpdb->prepare("SELECT seo_role FROM {$table} WHERE object_type='page' AND object_id=%d AND status=1 ORDER BY id DESC LIMIT 1",absint($id)));
    }
    public static function set_role($id,$role) {
        global $wpdb;$id=absint($id);$role=sanitize_key($role);
        if('page'!==get_post_type($id)||($role&&!isset(self::roles()[$role]))) return new WP_Error('tct_invalid_role','Página o rol no válido.');
        $table=self::nodes();$current=self::page_role($id);
        if($current===$role) return true;
        // Roles used in live hierarchy must not be silently reassigned.
        $relations=$wpdb->prefix.'seo_relations';
        if($current&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($relations)))===$relations) {
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$relations} WHERE (source_id=%d AND source_type IN ('cluster','hub_primary','hub_secondary','landing_page')) OR (target_id=%d AND target_type IN ('cluster','hub_primary','hub_secondary','landing_page'))",$id,$id));
            if($count>0) return new WP_Error('tct_hierarchy_linked','La página tiene relaciones jerárquicas: desvincúlala en Core antes de cambiar su rol.');
        }
        $now=current_time('mysql');$wpdb->query('START TRANSACTION');
        $disabled=$wpdb->update($table,array('status'=>0,'updated_at'=>$now),array('object_type'=>'page','object_id'=>$id));
        $saved=true;
        if(false!==$disabled&&$role) {
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE object_type='page' AND object_id=%d AND seo_role=%s LIMIT 1",$id,$role));
            $saved=$existing?
                false!==$wpdb->update($table,array('status'=>1,'updated_at'=>$now),array('id'=>(int)$existing)):
                false!==$wpdb->insert($table,array('object_type'=>'page','object_id'=>$id,'seo_role'=>$role,'status'=>1,'created_at'=>$now,'updated_at'=>$now));
        }
        if(false===$disabled||!$saved){$wpdb->query('ROLLBACK');return new WP_Error('tct_save_failed','No se pudo guardar el rol.');}
        $wpdb->query('COMMIT');
        if($role) update_post_meta($id,'_taxocommerce_seo_role',$role);
        else delete_post_meta($id,'_taxocommerce_seo_role');
        return true;
    }
    public static function page_metabox() {
        add_meta_box('tct-role','TaxoCommerce: rol',array(__CLASS__,'metabox_content'),'page','side');
    }
    public static function metabox_content($post) {
        $role=self::installed()?self::page_role($post->ID):'';
        echo '<p><label for="tct_role">Tipo de página</label></p><select id="tct_role" name="tct_role"><option value="">Sin rol</option>';
        foreach(self::roles() as $key=>$name)echo '<option value="'.esc_attr($key).'" '.selected($role,$key,false).'>'.esc_html($name).'</option>';
        echo '</select><p class="description">Se aplica a todas las páginas del mismo rol.</p>';
        wp_nonce_field('tct_page_role','tct_page_role_nonce');
        // Save only during the native post save and after all validation.
    }
    public static function save_metabox($id,$post) {
        if(!isset($_POST['tct_page_role_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tct_page_role_nonce'])),'tct_page_role'))return;
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||wp_is_post_revision($id)||!current_user_can('edit_page',$id)||!self::installed())return;
        $role=isset($_POST['tct_role'])?sanitize_key(wp_unslash($_POST['tct_role'])):'';
        self::set_role($id,$role);
    }
    public static function save_page_role() {
        self::guard('tct_page_role_admin');
        $id=isset($_POST['page_id'])?absint($_POST['page_id']):0;
        $role=isset($_POST['role'])?sanitize_key(wp_unslash($_POST['role'])):'';
        $result=self::set_role($id,$role);
        self::redirect(is_wp_error($result)?$result->get_error_message():'Rol actualizado.');
    }
    public static function save_role() {
        self::guard('tct_save_role');
        $role=isset($_POST['role'])?sanitize_key(wp_unslash($_POST['role'])):'';
        $name=isset($_POST['name'])?sanitize_text_field(wp_unslash($_POST['name'])):'';
        if(!$role||!$name||strlen($role)>80)self::redirect('Rol o nombre inválido.');
        $custom=get_option(self::ROLE_OPTION,array());if(!is_array($custom))$custom=array();
        if(isset(self::base_roles()[$role]))self::redirect('Este rol pertenece a la estructura principal.');
        $custom[$role]=$name;update_option(self::ROLE_OPTION,$custom,false);
        self::redirect('Rol creado.');
    }
    public static function save_template() {
        self::guard('tct_save_template');
        global $wpdb;
        $role=isset($_POST['role'])?sanitize_key(wp_unslash($_POST['role'])):'';
        $file=isset($_POST['file'])?sanitize_file_name(wp_unslash($_POST['file'])):'';
        if(!$role||!isset(self::roles()[$role])||!preg_match('/^[a-zA-Z0-9._-]+\.php$/D',$file))self::redirect('Rol o plantilla no válidos.');
        $path=TCT_Router::path($file);
        if(!$path)self::redirect('El archivo no existe en la biblioteca instalada.');
        $table=self::templates();
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE template_key=%s",$role));
        $fields=array('template_name'=>self::roles()[$role],'template_file'=>$file,'is_active'=>1,'updated_at'=>current_time('mysql'));
        $saved=$exists ? $wpdb->update($table,$fields,array('id'=>(int)$exists)) : $wpdb->insert($table,array_merge(array('template_key'=>$role),$fields));
        self::redirect(false===$saved?'No se pudo guardar la asignación.':'Plantilla asociada.');
    }
    public static function settings() {
        self::guard('tct_settings');
        update_option(self::ENABLE_OPTION,!empty($_POST['enable'])?1:0,false);
        self::redirect('Configuración guardada.');
    }
    public static function available_files() {
        return TCT_Router::available_files();
    }

    public static function save_device_mode() {
        self::guard('tct_save_device_mode');
        global $wpdb;
        $key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
        $table = self::templates();
        $row = $wpdb->get_row($wpdb->prepare("SELECT template_file FROM {$table} WHERE template_key=%s", $key));
        if (!$row) {
            self::redirect('Ruta desconocida.');
        }
        $plan = TCT_Router::plan((object)array('template_file'=>$row->template_file, 'device_variants_enabled'=>0), 'mobile');
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        if (!$enabled && $plan['dispatcher']) {
            self::redirect('La principal es un dispatcher y necesita sus variantes.');
        }
        $saved = $wpdb->update($table, array('device_variants_enabled'=>$enabled, 'updated_at'=>current_time('mysql')), array('template_key'=>$key));
        self::redirect(false === $saved ? 'Error guardando el modo por dispositivo.' : 'Modo por dispositivo actualizado.');
    }

    public static function assign_page() {
        self::guard('tct_assign_page');
        $id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $key = isset($_POST['template_key']) ? sanitize_key(wp_unslash($_POST['template_key'])) : '';
        if (!$id || !$key || !isset(self::roles()[$key])) {
            self::redirect('Pagina o rol incorrecto.');
        }
        global $wpdb;
        $table = self::templates();
        $mode = $wpdb->get_var($wpdb->prepare(
            "SELECT assignment_mode FROM {$table} WHERE template_key=%s AND is_assignable=1", $key
        ));
        if (!in_array($mode, array('multiple','single'), true)) {
            self::redirect('Esta ruta se asigna automaticamente.');
        }
        if ('single' === $mode) {
            $nodes = self::nodes();
            $occupied = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$nodes} WHERE seo_role=%s AND object_type='page' AND object_id<>%d AND status=1",
                $key, $id
            ));
            if ($occupied) {
                self::redirect('Esta ruta ya tiene una pagina. Desasignala antes.');
            }
        }
        $result = self::set_role($id,$key);
        self::redirect(is_wp_error($result) ? $result->get_error_message() : 'Pagina asignada correctamente.');
    }

    public static function upload_template() {
        if (!current_user_can('manage_options') || !current_user_can('update_plugins')) {
            wp_die('Permisos insuficientes para subir codigo PHP.');
        }
        self::guard('tct_upload_template');
        $files = isset($_FILES['template_file']) ? $_FILES['template_file'] : null;
        if (!is_array($files) || !isset($files['error']) || UPLOAD_ERR_OK !== (int) $files['error']) {
            self::redirect('Error en la subida del archivo.');
        }
        $name = isset($files['name']) ? sanitize_file_name($files['name']) : '';
        if (!TCT_Router::valid_filename($name) || empty($files['tmp_name']) ||
            !is_uploaded_file($files['tmp_name'])) {
            self::redirect('Nombre o subida no valida. Solo PHP.');
        }
        $size = isset($files['size']) ? (int) $files['size'] : 0;
        if ($size < 8 || $size > 1024 * 1024) {
            self::redirect('Archivo vacio o mayor de 1 MB.');
        }
        $handle = fopen($files['tmp_name'], 'rb');
        $head = $handle ? fread($handle, 256) : '';
        if ($handle) {
            fclose($handle);
        }
        if (!is_string($head) || !preg_match('/^\s*<\?php\b/', $head)) {
            self::redirect('El archivo debe contener codigo PHP valido.');
        }
        $directory = TCT_Router::library_dir();
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            self::redirect('No se pudo crear la biblioteca de plantillas.');
        }
        // Evitar acceso directo por HTTP a los scripts en hosts Apache.
        $deny = $directory . '.htaccess';
        if (!file_exists($deny)) {
            file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
        $index = $directory . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $target = $directory . $name;
        if (is_link($target)) {
            self::redirect('No se permiten enlaces simbolicos.');
        }
        if (is_file(TCT_DIR . 'templates/' . $name)) {
            self::redirect('El nombre coincide con una plantilla integrada. Usa otro nombre.');
        }
        if (is_file($target)) {
            if (empty($_POST['replace'])) {
                self::redirect('El archivo ya existe. Marca reemplazar para confirmarlo.');
            }
            $backup = $target . '.' . gmdate('YmdHis') . '.bak';
            if (!copy($target,$backup)) {
                self::redirect('No se pudo guardar copia de seguridad.');
            }
        }
        if (!move_uploaded_file($files['tmp_name'],$target)) {
            self::redirect('No se pudo guardar el archivo en la biblioteca.');
        }
        @chmod($target,0640);
        self::redirect('Archivo incorporado a la biblioteca. Ya puedes asociarlo.');
    }

    public static function screen() {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado.');
        }
        if (!self::installed()) {
            echo '<div class="wrap"><h1>TaxoCommerce Templates</h1><p>Faltan tablas; reactiva el modulo.</p></div>';
            return;
        }
        global $wpdb;
        $rows = TCT_Router::rows();
        $files = TCT_Router::available_files();
        $node_table = self::nodes();
        $associations = $wpdb->get_results("SELECT object_id, seo_role FROM {$node_table} WHERE object_type='page' AND status=1");
        $by_role = array();
        $occupied = array();
        foreach ((array)$associations as $entry) {
            $by_role[$entry->seo_role][] = (int)$entry->object_id;
            $occupied[(int)$entry->object_id] = $entry->seo_role;
        }
        $pages = get_posts(array('post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids'));
        echo '<div class="wrap"><h1>Gestor de Plantillas</h1>';
        echo '<p>Rutas migradas del gestor original. No se incluyen ni ejecutan archivos PHP del plugin antiguo.</p>';
        if (isset($_GET['tct_notice'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['tct_notice']))) . '</p></div>';
        }
        $enabled = (bool)get_option(self::ENABLE_OPTION,0);
        echo '<h2>Disponibilidad y activacion</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_settings');
        echo '<input type="hidden" name="action" value="tct_settings"><label><input type="checkbox" name="enable" value="1" ' . checked($enabled,true,false) . '> Activar el enrutador del nuevo modulo</label>';
        echo '<p class="description">Desactivado por defecto; activa solo cuando todos los archivos de las rutas utilizadas esten migrados y probados.</p>';
        submit_button('Guardar activacion','secondary');
        echo '</form><h2>Archivos de plantilla</h2>';
        echo '<p>Sube archivos .php propios. Quedan en wp-content/taxocommerce-templates-library/, fuera de los despliegues del plugin.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_upload_template');
        echo '<input type="hidden" name="action" value="tct_upload_template"><input type="file" name="template_file" accept=".php" required> ';
        echo '<label><input type="checkbox" name="replace" value="1"> Reemplazar archivo existente (con copia)</label> ';
        submit_button('Subir plantilla','secondary','submit',false);
        echo '</form>';
        echo '<h2>Asignacion a paginas y rutas automaticas</h2>';
        echo '<p>Las rutas automaticas se resuelven por tipo de peticion. Las rutas asignables admiten paginas existentes; las variantes no son roles independientes.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">';
        foreach ($rows as $row) {
            $key = $row->template_key;
            $principal = TCT_Router::path($row->template_file);
            $is_assignable = (int)$row->is_assignable && in_array($row->assignment_mode,array('single','multiple'),true);
            echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:15px;min-width:0">';
            echo '<h3 style="margin:0 0 5px">' . esc_html($row->template_name) . '</h3>';
            echo '<p><code>' . esc_html($key) . '</code> · ' . esc_html($row->template_type) . ' · ';
            echo $is_assignable ? esc_html('Asignable: ' . $row->assignment_mode) : 'Automatica';
            echo '</p><p><strong>Principal:</strong> <code>' . esc_html($row->template_file) . '</code> — ';
            echo $principal ? 'Archivo disponible' : '<strong style="color:#a00">Archivo pendiente</strong>';
            echo '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tct_save_template');
            echo '<input type="hidden" name="action" value="tct_save_template"><input type="hidden" name="role" value="' . esc_attr($key) . '">';
            echo '<select name="file" style="max-width:100%"><option value="">Elegir archivo principal</option>';
            foreach ($files as $file) {
                echo '<option value="' . esc_attr($file) . '" ' . selected($file,$row->template_file,false) . '>' . esc_html($file) . '</option>';
            }
            echo '</select> ';
            submit_button('Asociar archivo','secondary','submit',false);
            echo '</form>';
            if ('email' !== $row->template_type) {
                $mobile = TCT_Router::plan($row,'mobile');
                $desktop = TCT_Router::plan($row,'desktop');
                echo '<details style="margin-top:10px"><summary>Variantes telefono / ordenador</summary>';
                echo '<p>Telefono: <code>' . esc_html($mobile['variant']) . '</code> — ' . esc_html($mobile['source']) . '</p>';
                echo '<p>Ordenador: <code>' . esc_html($desktop['variant']) . '</code> — ' . esc_html($desktop['source']) . '</p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('tct_save_device_mode');
                echo '<input type="hidden" name="action" value="tct_save_device_mode"><input type="hidden" name="template_key" value="' . esc_attr($key) . '">';
                echo '<label><input type="checkbox" name="enabled" value="1" ' . checked($mobile['enabled'],true,false) . ($mobile['dispatcher']?' disabled':'') . '> Usar variantes</label> ';
                submit_button('Guardar','secondary','submit',false);
                echo '</form></details>';
            }
            if ($is_assignable) {
                echo '<h4>Paginas asignadas</h4>';
                $assigned = isset($by_role[$key]) ? $by_role[$key] : array();
                if (!$assigned) {
                    echo '<p>Ninguna pagina asignada.</p>';
                }
                foreach ($assigned as $id) {
                    echo '<p>' . esc_html(get_the_title($id)) . ' <code>#' . esc_html($id) . '</code></p>';
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('tct_assign_page');
                echo '<input type="hidden" name="action" value="tct_assign_page"><input type="hidden" name="template_key" value="' . esc_attr($key) . '">';
                echo '<select name="page_id" required style="max-width:100%"><option value="">Seleccionar pagina libre</option>';
                foreach ($pages as $id) {
                    if (isset($occupied[$id])) {
                        continue;
                    }
                    echo '<option value="' . esc_attr($id) . '">' . esc_html(get_the_title($id)) . ' (#' . esc_html($id) . ')</option>';
                }
                echo '</select> ';
                submit_button('Asignar pagina','secondary','submit',false);
                echo '</form>';
            }
            echo '</section>';
        }
        echo '</div>';
        echo '<h2>Crear un rol adicional</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tct_save_role');
        echo '<input type="hidden" name="action" value="tct_save_role"><input name="role" placeholder="clave_rol" required> <input name="name" placeholder="Nombre del rol" required> ';
        submit_button('Crear rol','secondary','submit',false);
        echo '</form></div>';
    }

}
register_activation_hook(__FILE__,array('TaxoCommerce_Templates','activate'));
add_action('plugins_loaded',array('TaxoCommerce_Templates','boot'),30);
