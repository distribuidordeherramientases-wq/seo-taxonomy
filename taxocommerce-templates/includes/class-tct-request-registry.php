<?php
/**
 * Inventario extensible de solicitudes que admiten plantillas.
 *
 * Mantiene separados el enrutamiento PHP de WordPress, los roles editoriales
 * de TaxoCommerce y los fragmentos de plantillas de WooCommerce.
 */
defined('ABSPATH') || exit;

final class TCT_Request_Registry {
    const VERSION = '0.3.0';

    public static function definitions($with_woocommerce_files = true) {
        $defs = array(
            'front_page'        => array('Portada estatica', 'WordPress', 'front-page.php'),
            'blog_index'        => array('Indice de entradas / blog', 'WordPress', 'home.php'),
            'privacy_policy'    => array('Pagina de privacidad', 'WordPress', 'privacy-policy.php'),
            'page'              => array('Pagina generica', 'WordPress', 'page.php'),
            'single_post'       => array('Entrada (post)', 'WordPress', 'single-post.php'),
            'single'            => array('Entrada generica', 'WordPress', 'single.php'),
            'singular'          => array('Contenido singular generico', 'WordPress', 'singular.php'),
            'attachment'        => array('Adjunto', 'WordPress', 'attachment.php'),
            'image_attachment'  => array('Adjunto de imagen', 'WordPress', 'image.php'),
            'embed'             => array('Contenido incrustado', 'WordPress', 'embed.php'),
            'taxonomy_category' => array('Categoria del blog', 'WordPress', 'category.php'),
            'taxonomy_post_tag' => array('Etiqueta del blog', 'WordPress', 'tag.php'),
            'taxonomy'          => array('Taxonomia generica', 'WordPress', 'taxonomy.php'),
            'author'            => array('Archivo de autor', 'WordPress', 'author.php'),
            'date'              => array('Archivo por fecha', 'WordPress', 'date.php'),
            'archive'           => array('Archivo generico', 'WordPress', 'archive.php'),
            'search'            => array('Resultados de busqueda', 'WordPress', 'search.php'),
            'error_404'         => array('Pagina no encontrada (404)', 'WordPress', '404.php'),
            'index'             => array('Plantilla final de respaldo', 'WordPress', 'index.php'),
            'comments'          => array('Comentarios de entradas', 'WordPress - fragmentos', 'comments.php'),
            'paged'             => array('Listado paginado', 'WordPress', 'paged.php'),
            'shop'              => array('Archivo / tienda WooCommerce', 'WooCommerce - paginas', 'archive-product.php'),
            'taxonomy_product_cat' => array('Categoria de producto', 'WooCommerce - paginas', 'taxonomy-product_cat.php'),
            'taxonomy_product_tag' => array('Etiqueta de producto', 'WooCommerce - paginas', 'taxonomy-product_tag.php'),
            'single_product'    => array('Ficha de producto', 'WooCommerce - paginas', 'single-product.php'),
            'cart'              => array('Pagina carrito', 'WooCommerce - paginas', 'pagina del carrito'),
            'checkout'          => array('Pagina finalizar compra', 'WooCommerce - paginas', 'pagina de pago'),
            'thankyou'          => array('Pagina pedido recibido', 'WooCommerce - paginas', 'endpoint order-received'),
            'myaccount'         => array('Pagina mi cuenta', 'WooCommerce - paginas', 'pagina de cuenta')
        );

        if (function_exists('get_post_types')) {
            foreach (get_post_types(array('public' => true), 'objects') as $type) {
                $slug = sanitize_key($type->name);
                if (!$slug) {
                    continue;
                }
                $title = isset($type->labels->singular_name) ? $type->labels->singular_name : $slug;
                $defs['single_type_' . $slug] = array('Ficha: ' . $title, 'Tipos de contenido', 'single-' . $slug . '.php');
                if (!empty($type->has_archive)) {
                    $defs['archive_type_' . $slug] = array('Archivo: ' . $title, 'Tipos de contenido', 'archive-' . $slug . '.php');
                }
            }
        }

        if (function_exists('get_taxonomies')) {
            foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
                $slug = sanitize_key($taxonomy->name);
                if (!$slug) {
                    continue;
                }
                $title = isset($taxonomy->labels->name) ? $taxonomy->labels->name : $slug;
                $defs['taxonomy_type_' . $slug] = array('Taxonomia: ' . $title, 'Taxonomias registradas', 'taxonomy-' . $slug . '.php');
            }
        }

        if ($with_woocommerce_files && function_exists('WC') && WC() && method_exists(WC(), 'plugin_path')) {
            $root = trailingslashit(WC()->plugin_path()) . 'templates/';
            if (is_dir($root)) {
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                    );
                    $count = 0;
                    foreach ($iterator as $item) {
                        if (++$count > 1500) {
                            break;
                        }
                        if (!$item->isFile() || !$item->isReadable() || strtolower($item->getExtension()) !== 'php') {
                            continue;
                        }
                        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($root)));
                        $key = self::woocommerce_key($relative);
                        $group = (strpos($relative, 'emails/') === 0) ? 'WooCommerce - correos' : 'WooCommerce - fragmentos';
                        $defs[$key] = array($relative, $group, $relative);
                    }
                } catch (UnexpectedValueException $exception) {
                    // El catalogo general funciona aunque una ruta WC no se pueda explorar.
                }
            }
        }

        /**
         * Los modulos externos pueden declarar rutas propias:
         * key => array(nombre, grupo, referencia original).
         * Enrutarlas requiere registrar su propio filtro para esa ejecucion.
         */
        $defs = apply_filters('tct_request_definitions', $defs);
        return is_array($defs) ? $defs : array();
    }

    public static function woocommerce_key($relative) {
        return 'woo_file_' . substr(md5((string)$relative), 0, 20);
    }

    private static function add(&$keys, $value) {
        $value = sanitize_key((string)$value);
        if ($value !== '' && !in_array($value, $keys, true)) {
            $keys[] = $value;
        }
    }

    public static function role_for_object($type, $id) {
        if (!$id || !TaxoCommerce_Templates::installed()) {
            return '';
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::nodes();
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT seo_role FROM {$table}
             WHERE object_type = %s AND object_id = %d AND status = 1
             ORDER BY id DESC LIMIT 1",
            sanitize_key($type), absint($id)
        ));
    }

    public static function candidates() {
        $keys = array();

        if (is_404()) {
            self::add($keys, 'error_404');
            self::add($keys, '404');
        } elseif (function_exists('is_embed') && is_embed()) {
            self::add($keys, 'embed');
            self::add($keys, 'single');
        } elseif (is_front_page()) {
            self::add($keys, 'front_page');
            if (is_home()) {
                self::add($keys, 'blog_index');
            } else {
                $id = absint(get_queried_object_id());
                self::add($keys, self::role_for_object('page', $id));
                self::add($keys, 'page');
                self::add($keys, 'singular');
            }
        } elseif (is_home()) {
            self::add($keys, 'blog_index');
        } elseif (function_exists('is_order_received_page') && is_order_received_page()) {
            self::add($keys, 'thankyou');
            self::add($keys, 'checkout');
            self::add($keys, 'page');
        } elseif (function_exists('is_checkout') && is_checkout()) {
            self::add($keys, 'checkout');
            self::add($keys, 'page');
        } elseif (function_exists('is_cart') && is_cart()) {
            self::add($keys, 'cart');
            self::add($keys, 'page');
        } elseif (function_exists('is_account_page') && is_account_page()) {
            self::add($keys, 'myaccount');
            self::add($keys, 'page');
        } elseif (is_search()) {
            self::add($keys, 'search');
        } elseif (function_exists('is_shop') && is_shop()) {
            self::add($keys, 'shop');
            self::add($keys, 'archive_type_product');
            self::add($keys, 'archive');
        } elseif (is_category()) {
            $term = get_queried_object();
            if ($term && isset($term->term_id)) {
                self::add($keys, self::role_for_object('category', (int)$term->term_id));
            }
            self::add($keys, 'taxonomy_type_category');
            self::add($keys, 'taxonomy_category');
            self::add($keys, 'taxonomy');
            self::add($keys, 'archive');
        } elseif (is_tag()) {
            self::add($keys, 'taxonomy_type_post_tag');
            self::add($keys, 'taxonomy_post_tag');
            self::add($keys, 'taxonomy');
            self::add($keys, 'archive');
        } elseif (is_tax()) {
            $term = get_queried_object();
            $tax = $term && isset($term->taxonomy) ? sanitize_key($term->taxonomy) : '';
            if ($term && isset($term->term_id)) {
                self::add($keys, self::role_for_object($tax, (int)$term->term_id));
            }
            if ('product_cat' === $tax) {
                self::add($keys, 'taxonomy_product_cat');
                self::add($keys, 'category');
            } elseif ('product_tag' === $tax) {
                self::add($keys, 'taxonomy_product_tag');
            }
            if ($tax !== '') {
                self::add($keys, 'taxonomy_type_' . $tax);
            }
            self::add($keys, 'taxonomy');
            self::add($keys, 'archive');
        } elseif (is_attachment()) {
            $mime = (string)get_post_mime_type(get_queried_object_id());
            if (strpos($mime, 'image/') === 0) {
                self::add($keys, 'image_attachment');
            }
            self::add($keys, 'attachment');
            self::add($keys, 'singular');
        } elseif (is_singular()) {
            $id = absint(get_queried_object_id());
            $type = sanitize_key(get_post_type($id));
            if ($type === 'page' && (
                ('dependiente' === get_post_field('post_name', $id)) ||
                ($id && $id === absint(get_option('seo_dependiente_page_id', 0)))
            )) {
                return array(); // El Dependiente mantiene su propio controlador.
            }
            if ('page' === $type && function_exists('is_privacy_policy') && is_privacy_policy()) {
                self::add($keys, 'privacy_policy');
            }
            self::add($keys, self::role_for_object($type, $id));
            if ('page' === $type) {
                self::add($keys, 'single_type_page');
                self::add($keys, 'page');
            } else {
                if ('post' === $type) {
                    self::add($keys, 'single_post');
                } elseif ('product' === $type) {
                    self::add($keys, 'single_product');
                    self::add($keys, 'product');
                }
                self::add($keys, 'single_type_' . $type);
                self::add($keys, 'single');
            }
            self::add($keys, 'singular');
        } elseif (is_author()) {
            self::add($keys, 'author');
            self::add($keys, 'archive');
        } elseif (is_date()) {
            self::add($keys, 'date');
            self::add($keys, 'archive');
        } elseif (is_post_type_archive()) {
            self::add($keys, 'archive_type_' . get_query_var('post_type'));
            self::add($keys, 'archive');
        } elseif (is_archive()) {
            self::add($keys, 'archive');
        }

        if (function_exists('is_paged') && is_paged()) {
            self::add($keys, 'paged');
        }
        self::add($keys, 'index');
        return apply_filters('tct_request_candidates', $keys);
    }

    public static function usable_plan($row) {
        if (!$row || !(int)$row->is_active || !isset($row->template_file) || !$row->template_file) {
            return '';
        }
        $plan = TCT_Router::plan($row, wp_is_mobile() ? 'mobile' : 'desktop');
        return !empty($plan['effective']) ? $plan['effective'] : '';
    }

    public static function resolve($original) {
        if (!get_option(TaxoCommerce_Templates::ENABLE_OPTION, 0) || !TaxoCommerce_Templates::installed()) {
            return $original;
        }
        $legacy = basename((string)$original);
        if (in_array($legacy, array('template-pilot-vevor.php', 'template-category-fr.php', 'template-product-fr.php'), true)) {
            return $original;
        }
        $candidates = self::candidates();
        if (!is_array($candidates) || !$candidates) {
            return $original;
        }

        $candidates = array_values(array_unique(array_filter(array_map('sanitize_key', $candidates))));
        if (!$candidates) {
            return $original;
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $slots = implode(', ', array_fill(0, count($candidates), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT template_key,template_file,is_active,device_variants_enabled
             FROM {$table} WHERE template_key IN ({$slots}) AND is_active = 1", $candidates
        ));
        $by_key = array();
        foreach ((array)$rows as $row) {
            $by_key[$row->template_key] = $row;
        }
        foreach ($candidates as $key) {
            if (!isset($by_key[$key])) {
                continue;
            }
            $path = self::usable_plan($by_key[$key]);
            if ($path !== '') {
                $GLOBALS['tct_template_route'] = $key;
                $GLOBALS['tct_template_file'] = basename($path);
                return $path;
            }
        }
        return $original; // Respeta la plantilla resuelta por el tema.
    }

    public static function resolve_comments($original) {
        if (!get_option(TaxoCommerce_Templates::ENABLE_OPTION, 0) || !TaxoCommerce_Templates::installed()) {
            return $original;
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT template_file,is_active,device_variants_enabled FROM {$table} WHERE template_key = %s LIMIT 1",
            'comments'
        ));
        $custom = self::usable_plan($row);
        return $custom !== '' ? $custom : $original;
    }

    public static function resolve_woocommerce_part($original, $slug, $name = '') {
        $slug = trim((string)$slug);
        $name = trim((string)$name);
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_-]*$#D', $slug) ||
            ($name !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) ||
            false !== strpos($slug, '..')) {
            return $original;
        }
        $file = $slug . ($name ? '-' . $name : '') . '.php';
        return self::resolve_woocommerce_partial($original, $file);
    }

    public static function resolve_woocommerce_partial($path, $template_name, $args = array(), $template_path = '', $default_path = '') {
        if (!get_option(TaxoCommerce_Templates::ENABLE_OPTION, 0) || !TaxoCommerce_Templates::installed()) {
            return $path;
        }
        $relative = str_replace('\\', '/', (string)$template_name);
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_\.-]*\.php$#D', $relative) || false !== strpos($relative, '..')) {
            return $path;
        }
        global $wpdb;
        $table = TaxoCommerce_Templates::templates();
        $key = self::woocommerce_key($relative);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT template_file,is_active,device_variants_enabled
             FROM {$table} WHERE template_key = %s AND is_active = 1 LIMIT 1", $key
        ));
        $override = self::usable_plan($row);
        return $override !== '' ? $override : $path;
    }
}
