<?php
/**
 * Registro de rutas y resolucion de plantillas del nuevo modulo.
 * No carga archivos ni funciones del plugin monolitico.
 */
defined('ABSPATH') || exit;

final class TCT_Router {
    const CATALOG_VERSION = '0.3.0';

    /**
     * Rutas confirmadas en template-loader.php / template-manager.php y en
     * el gestor antiguo. Las filas adicionales de seo_templates tambien se
     * muestran; este inventario no sustituye los registros existentes.
     *
     * Formato: nombre, archivo sugerido, tipo, modo de asignacion.
     */
    public static function catalog() {
        return array(
            'cluster'              => array('Cluster', 'template-cluster.php', 'page', 'multiple'),
            'hub_primary'          => array('Hub primario', 'template-hub-primary.php', 'page', 'multiple'),
            'hub_secondary'        => array('Hub secundario', 'template-hub-secondary.php', 'page', 'multiple'),
            'landing'              => array('Landing Page', 'template-landing.php', 'page', 'multiple'),
            'noticias-landings'    => array('Noticias landings', 'template-soluciones.php', 'page', 'single'),
            'corporate_page'       => array('Pagina corporativa', 'template-corp_page.php', 'page', 'multiple'),
            'trnplate_blog'        => array('Plantilla de noticias del blog', 'template-blog.php', 'page', 'single'),
            'shop'                 => array('Plantilla para pagina de tienda', 'template-shop.php', 'page', 'single'),
            'front_page'           => array('Portada estatica', 'template-front.php', 'system', 'automatic'),
            'blog_index'           => array('Indice del blog', 'template-blog.php', 'content', 'automatic'),
            'taxonomy_category'    => array('Categorias del blog', 'template-blog-category.php', 'taxonomy', 'automatic'),
            'thankyou'             => array('Pedido recibido', 'template-thankyou.php', 'system', 'automatic'),
            'checkout'             => array('Finalizar compra', 'template-checkout.php', 'system', 'automatic'),
            'cart'                 => array('Carrito', 'template-cart.php', 'system', 'automatic'),
            'search'               => array('Busqueda', 'search.php', 'system', 'automatic'),
            'taxonomy_product_cat' => array('Categoria de producto', 'template-category.php', 'taxonomy', 'automatic'),
            'single_post'          => array('Entrada individual', 'template-post.php', 'content', 'automatic'),
            'single_product'       => array('Producto individual', 'template-product.php', 'content', 'automatic'),
            'myaccount'            => array('Mi cuenta', 'template-myaccount.php', 'system', 'automatic'),
            'error_404'            => array('Pagina 404', 'template-404.php', 'system', 'automatic'),
            '404'                  => array('Pagina 404 (alias legacy)', 'template-404.php', 'system', 'automatic'),
            'category'             => array('Categoria de producto (alias legacy)', 'template-category.php', 'taxonomy', 'automatic'),
            'product'              => array('Producto (alias legacy)', 'template-product.php', 'content', 'automatic'),
            'login'                => array('Acceso WordPress (registro legacy)', 'template-login.php', 'system', 'automatic'),
            'register'             => array('Registro WordPress (registro legacy)', 'template-register.php', 'system', 'automatic'),
            'lost_password'        => array('Recuperar contrasena (registro legacy)', 'template-lost-password.php', 'system', 'automatic'),
            'email_processing'     => array('Email pedido procesando (legacy)', 'email-processing.php', 'email', 'automatic'),
            'email_completed'      => array('Email pedido completado (legacy)', 'email-completed.php', 'email', 'automatic'),
            'email_refunded'       => array('Email pedido reembolsado (legacy)', 'email-refunded.php', 'email', 'automatic'),
            'email_cancelled'      => array('Email pedido cancelado (legacy)', 'email-cancelled.php', 'email', 'automatic')
        );
    }

    public static function seed_catalog() {
        if (!TaxoCommerce_Templates::installed()) {
            return;
        }
        if (self::CATALOG_VERSION === get_option('tct_catalog_version')) {
            return;
        }

        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $existing = array_fill_keys((array) $wpdb->get_col("SELECT template_key FROM {$table}"), true);
        $ok = true;
        foreach (self::catalog() as $key => $row) {
            if (isset($existing[$key])) {
                continue;
            }
            $saved = $wpdb->insert($table, array(
                'template_key'            => $key,
                'template_name'           => $row[0],
                // A new installation exposes catalog suggestions without
                // assigning or activating a public template automatically.
                // Existing rows are skipped above, preserving legacy sites.
                'template_file'           => '',
                'template_type'           => $row[2],
                'assignment_mode'         => $row[3],
                'is_assignable'          => ('automatic' === $row[3]) ? 0 : 1,
                'is_active'              => 0,
                'is_public'              => 1,
                'device_variants_enabled' => 0,
                'created_at'              => current_time('mysql'),
                'updated_at'              => current_time('mysql')
            ));
            if (false === $saved) {
                $ok = false;
            }
        }
        if ($ok) {
            update_option('tct_catalog_version', self::CATALOG_VERSION, false);
        }
    }

    public static function rows() {
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        return (array) $wpdb->get_results(
            "SELECT template_key, template_name, template_file, template_type,
                    assignment_mode, is_assignable, is_active, is_public,
                    device_variants_enabled
             FROM {$table} ORDER BY display_order ASC, template_name ASC, id ASC"
        );
    }

    public static function page_roles() {
        $roles = array();
        foreach (self::catalog() as $key => $data) {
            if ('automatic' !== $data[3]) {
                $roles[$key] = $data[0];
            }
        }
        if (!TaxoCommerce_Templates::installed()) {
            return $roles;
        }
        foreach (self::rows() as $row) {
            if ((int) $row->is_assignable && in_array($row->assignment_mode, array('single', 'multiple'), true)) {
                $roles[$row->template_key] = $row->template_name;
            }
        }
        return $roles;
    }

    public static function library_dir() {
        // Persiste fuera de plugins: las actualizaciones y despliegues FTP
        // no deben borrar las plantillas subidas por el administrador.
        return trailingslashit(WP_CONTENT_DIR) . 'taxocommerce-templates-library/';
    }

    public static function available_files($include_variants = false) {
        $result = array();
        foreach (array(TCT_DIR . 'templates/', self::library_dir()) as $directory) {
            foreach ((array) glob($directory . '*.php') as $path) {
                $name = basename($path);
                if (!self::valid_filename($name) || !is_file($path) || is_link($path)) {
                    continue;
                }
                if (!$include_variants && (preg_match('/-(mobile|desktop)\.php$/i', $name) ||
                        in_array($name, array('index.php', 'template-helpers.php'), true))) {
                    continue;
                }
                $result[$name] = $name;
            }
        }
        natcasesort($result);
        return $result;
    }

    public static function valid_filename($file) {
        return is_string($file) && strlen($file) <= 191 &&
            (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.php$/D', $file) &&
            false === strpos($file, '..');
    }

    public static function path($file) {
        if (!self::valid_filename($file)) {
            return '';
        }
        foreach (array(self::library_dir(), TCT_DIR . 'templates/') as $directory) {
            $path = $directory . $file;
            if (is_file($path) && is_readable($path) && !is_link($path)) {
                return $path;
            }
        }
        return '';
    }

    public static function variant_name($file, $device) {
        if (!self::valid_filename($file) || !in_array($device, array('mobile', 'desktop'), true)) {
            return '';
        }
        $stem = substr($file, 0, -4);
        if (preg_match('/-(mobile|desktop)$/i', $stem)) {
            return '';
        }
        return $stem . '-' . $device . '.php';
    }

    public static function is_dispatcher($path) {
        if (!$path) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = fread($handle, 8192);
        fclose($handle);
        return is_string($head) && false !== strpos($head, 'dht_template_device_variant_file(');
    }

    public static function plan($row, $device) {
        $primary = self::path($row->template_file);
        $dispatcher = self::is_dispatcher($primary);
        $enabled = $dispatcher || (int) $row->device_variants_enabled === 1;
        $variant = self::variant_name($row->template_file, $device);
        $variant_path = $variant ? self::path($variant) : '';
        $other_device = ('mobile' === $device) ? 'desktop' : 'mobile';
        $other = self::variant_name($row->template_file, $other_device);
        $other_path = $other ? self::path($other) : '';
        $effective = $primary;
        $source = 'principal';
        if ($enabled && $variant_path) {
            $effective = $variant_path;
            $source = $device;
        } elseif ($enabled && $dispatcher) {
            // Un dispatcher legacy no renderiza por si solo.
            $effective = $other_path;
            $source = $other_path ? $other_device . ' (respaldo)' : 'sin archivo utilizable';
        }
        return array(
            'principal' => $primary, 'dispatcher' => $dispatcher, 'enabled' => $enabled,
            'variant' => $variant, 'variant_path' => $variant_path, 'effective' => $effective,
            'source' => $source
        );
    }

    public static function route_key() {
        if (is_front_page() && !is_home()) {
            return 'front_page';
        }
        if (is_home()) {
            return 'blog_index';
        }
        if (is_category()) {
            return 'taxonomy_category';
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return 'thankyou';
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return 'checkout';
        }
        if (function_exists('is_cart') && is_cart()) {
            return 'cart';
        }
        if (is_search()) {
            return 'search';
        }
        if (function_exists('is_shop') && is_shop()) {
            return 'shop';
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return 'myaccount';
        }
        if (is_tax('product_cat')) {
            return 'taxonomy_product_cat';
        }
        if (is_singular('post')) {
            return 'single_post';
        }
        if (is_singular('page')) {
            $id = absint(get_queried_object_id());
            if (('dependiente' === get_post_field('post_name', $id)) ||
                ($id && $id === absint(get_option('seo_dependiente_page_id', 0)))) {
                return '';
            }
            return TaxoCommerce_Templates::page_role($id);
        }
        if (is_singular('product')) {
            return 'single_product';
        }
        if (is_404()) {
            return 'error_404';
        }
        return '';
    }

    public static function resolve($original) {
        if (!get_option(TaxoCommerce_Templates::ENABLE_OPTION, 0) || !TaxoCommerce_Templates::installed()) {
            return $original;
        }

        // Mientras el piloto internacional no se haya migrado, no reemplazar
        // su ruta especializada si el sistema antiguo sigue ejecutandose.
        $legacy_file = basename((string) $original);
        if (in_array($legacy_file, array('template-pilot-vevor.php', 'template-category-fr.php',
            'template-product-fr.php'), true)) {
            return $original;
        }

        $key = self::route_key();
        if (!$key) {
            return $original;
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT template_file, is_active, device_variants_enabled
             FROM {$table} WHERE template_key = %s LIMIT 1",
            $key
        ));
        if (!$row || !(int) $row->is_active) {
            return $original;
        }
        $plan = self::plan($row, wp_is_mobile() ? 'mobile' : 'desktop');
        if (!$plan['effective']) {
            return $original;
        }
        $GLOBALS['tct_template_route'] = $key;
        $GLOBALS['tct_template_file'] = basename($plan['effective']);
        return $plan['effective'];
    }
}
