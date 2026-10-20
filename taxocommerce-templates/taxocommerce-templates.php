<?php
/**
 * Plugin Name: TaxoCommerce Templates
 * Description: Roles editoriales, registro de plantillas y asignación automática, independiente de TaxoCommerce Core.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: taxocommerce-templates
 */
defined('ABSPATH') || exit;
define('TCT_VERSION','0.1.0');
define('TCT_FILE',__FILE__);
define('TCT_DIR',plugin_dir_path(__FILE__));
define('TCT_URL',plugin_dir_url(__FILE__));

final class TaxoCommerce_Templates {
    const ROLE_OPTION = 'taxocommerce_templates_custom_roles';
    const ENABLE_OPTION = 'taxocommerce_templates_render_enabled';

    public static function nodes() { global $wpdb; return $wpdb->prefix.'seo_nodes'; }
    public static function templates() { global $wpdb; return $wpdb->prefix.'seo_templates'; }
    public static function base_roles() {
        return array('cluster'=>'Cluster','hub_primary'=>'Hub primario','hub_secondary'=>'Hub secundario','landing'=>'Landing','corporate_page'=>'Página corporativa','guide'=>'Guía','news'=>'Noticias','offer'=>'Oferta');
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
    }
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'),65);
        add_action('admin_post_tct_save_role',array(__CLASS__,'save_role'));
        add_action('admin_post_tct_save_template',array(__CLASS__,'save_template'));
        add_action('admin_post_tct_settings',array(__CLASS__,'settings'));
        add_action('admin_post_tct_save_page_role',array(__CLASS__,'save_page_role'));
        add_action('add_meta_boxes_page',array(__CLASS__,'page_metabox'));
        add_action('save_post_page',array(__CLASS__,'save_metabox'),25,2);
        add_filter('template_include',array(__CLASS__,'resolve'),95);
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
        $path=TCT_DIR.'templates/'.$file;
        if(!is_file($path)||!is_readable($path))self::redirect('El archivo no existe en la biblioteca instalada.');
        $table=self::templates();
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE template_key=%s",$role));
        $fields=array('template_name'=>self::roles()[$role],'template_file'=>$file,'is_active'=>1,'template_type'=>'page','is_public'=>1,'is_assignable'=>1,'assignment_mode'=>'multiple','updated_at'=>current_time('mysql'));
        $saved=$exists ? $wpdb->update($table,$fields,array('id'=>(int)$exists)) : $wpdb->insert($table,array_merge(array('template_key'=>$role),$fields));
        self::redirect(false===$saved?'No se pudo guardar la asignación.':'Plantilla asociada.');
    }
    public static function settings() {
        self::guard('tct_settings');
        update_option(self::ENABLE_OPTION,!empty($_POST['enable'])?1:0,false);
        self::redirect('Configuración guardada.');
    }
    public static function available_files() {
        $files=glob(TCT_DIR.'templates/*.php');$result=array();
        foreach((array)$files as $file){$name=basename($file);if(strpos($name,'template-')===0)$result[$name]=$name;}
        ksort($result);return $result;
    }
    public static function resolve($original) {
        if(!get_option(self::ENABLE_OPTION,0)||!is_singular('page')||!self::installed())return $original;
        global $wpdb;$role=self::page_role(get_queried_object_id());if(!$role)return $original;
        $table=self::templates();
        $file=$wpdb->get_var($wpdb->prepare("SELECT template_file FROM {$table} WHERE template_key=%s AND is_active=1 LIMIT 1",$role));
        if(!$file)return $original;
        $file=basename((string)$file);$path=TCT_DIR.'templates/'.$file;
        // Do not load legacy PHP from the monolithic plugin, even if files remain there.
        return is_readable($path)?$path:$original;
    }
    public static function screen() {
        if(!current_user_can('manage_options'))wp_die('Acceso denegado.');
        global $wpdb;
        echo '<div class="wrap"><h1>TaxoCommerce Templates</h1><p>Roles y plantillas independientes. La jerarquía permanece en Core.</p>';
        if(!self::installed()){echo '<div class="notice notice-error"><p>Faltan tablas. Reactiva Templates.</p></div></div>';return;}
        if(isset($_GET['tct_notice']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['tct_notice']))).'</p></div>';
        $enabled=(bool)get_option(self::ENABLE_OPTION,0);$files=self::available_files();$roles=self::roles();$table=self::templates();
        $templates=$wpdb->get_results("SELECT template_key,template_name,template_file,is_active FROM {$table} ORDER BY template_key");
        $by_key=array();foreach((array)$templates as $t)$by_key[$t->template_key]=$t;
        echo '<h2>Configuración de presentación</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('tct_settings');echo '<input type="hidden" name="action" value="tct_settings"><label><input type="checkbox" name="enable" value="1" '.checked($enabled,true,false).'> Aplicar plantillas de TaxoCommerce Templates en la web</label>';
        echo '<p class="description">Desactivado por defecto: revisa archivos y asignaciones antes de habilitarlo.</p>';submit_button('Guardar configuración');echo '</form>';
        echo '<h2>Roles y asignaciones</h2><table class="widefat striped"><thead><tr><th>Rol</th><th>Plantilla</th><th>Asignar</th></tr></thead><tbody>';
        foreach($roles as $role=>$name){
            $current=isset($by_key[$role])?$by_key[$role]->template_file:'';
            echo '<tr><td><strong>'.esc_html($name).'</strong><br><code>'.esc_html($role).'</code></td><td>'.esc_html($current?:'Sin plantilla').'</td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('tct_save_template');echo '<input type="hidden" name="action" value="tct_save_template"><input type="hidden" name="role" value="'.esc_attr($role).'">';
            echo '<select name="file"><option value="">Seleccionar archivo</option>';
            foreach($files as $file)echo '<option value="'.esc_attr($file).'" '.selected($current,$file,false).'>'.esc_html($file).'</option>';
            echo '</select> ';submit_button('Asignar','secondary','submit',false);echo '</form></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Crear rol</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('tct_save_role');echo '<input type="hidden" name="action" value="tct_save_role"><input name="role" placeholder="clave_rol" required> <input name="name" placeholder="Nombre del rol" required> ';
        submit_button('Crear rol','secondary','submit',false);echo '</form>';
        echo '<h2>Asignar rol a una página</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('tct_page_role_admin');echo '<input type="hidden" name="action" value="tct_save_page_role"><label>ID de página <input type="number" min="1" name="page_id" required></label> <select name="role"><option value="">Sin rol</option>';
        foreach($roles as $role=>$name)echo '<option value="'.esc_attr($role).'">'.esc_html($name).'</option>';
        echo '</select> ';submit_button('Guardar rol','secondary','submit',false);echo '</form>';
        echo '<p class="description">La biblioteca admite archivos PHP dentro de <code>taxocommerce-templates/templates/</code>. No se cargan archivos del plugin antiguo.</p></div>';
    }
}
register_activation_hook(__FILE__,array('TaxoCommerce_Templates','activate'));
add_action('plugins_loaded',array('TaxoCommerce_Templates','boot'),30);
