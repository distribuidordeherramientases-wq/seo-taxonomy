<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_Index {
    private static $table_exists = null;
    private static $table_cache = array();

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_index';
    }

    public static function install() {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            product_id BIGINT UNSIGNED NOT NULL,
            title TEXT NOT NULL,
            normalized_title TEXT NOT NULL,
            excerpt LONGTEXT NOT NULL,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            brand_name VARCHAR(255) NOT NULL DEFAULT '',
            brand_slug VARCHAR(191) NOT NULL DEFAULT '',
            categories_json LONGTEXT NOT NULL,
            tags_json LONGTEXT NOT NULL,
            vocabulary_json LONGTEXT NOT NULL,
            attributes_json LONGTEXT NOT NULL,
            commercial_json LONGTEXT NOT NULL,
            search_text LONGTEXT NOT NULL,
            price DECIMAL(20,6) NULL,
            regular_price DECIMAL(20,6) NULL,
            sale_price DECIMAL(20,6) NULL,
            weight DECIMAL(20,6) NULL,
            length DECIMAL(20,6) NULL,
            width DECIMAL(20,6) NULL,
            height DECIMAL(20,6) NULL,
            stock_status VARCHAR(32) NOT NULL DEFAULT '',
            featured TINYINT(1) NOT NULL DEFAULT 0,
            product_type VARCHAR(32) NOT NULL DEFAULT '',
            image_url TEXT NOT NULL,
            permalink TEXT NOT NULL,
            post_modified_gmt DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (product_id),
            KEY brand_slug (brand_slug(100)),
            KEY price (price),
            KEY weight (weight),
            KEY stock_status (stock_status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        self::$table_exists = true;
    }

    public static function table_exists($table = '') {
        global $wpdb;

        $table = $table ? (string) $table : self::table();
        if ($table === self::table() && null !== self::$table_exists) {
            return self::$table_exists;
        }
        if (array_key_exists($table, self::$table_cache)) {
            return self::$table_cache[$table];
        }

        $exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;

        self::$table_cache[$table] = $exists;
        if ($table === self::table()) {
            self::$table_exists = $exists;
        }
        return $exists;
    }

    public static function normalize($value) {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = str_replace(array('€', '$', '£'), array(' euro ', ' dolar ', ' libra '), $value);
        // Treat common technical forms as equivalent: 18V = 18 V, 70kg = 70 kg, M18 = M 18.
        $value = preg_replace('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/u', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    public static function index_batch($page = 1, $limit = 50) {
        if (!class_exists('WooCommerce')) {
            return array('processed' => 0, 'total' => 0, 'pages' => 0, 'page' => 1, 'done' => true);
        }
        if (!self::table_exists()) {
            self::install();
        }

        $page = max(1, absint($page));
        $limit = min(100, max(10, absint($limit)));
        $query = new WP_Query(array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'paged'                  => $page,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'no_found_rows'          => false,
        ));

        $processed = 0;
        foreach ((array) $query->posts as $product_id) {
            if (self::index_product($product_id)) {
                $processed++;
            }
        }

        $pages = max(1, absint($query->max_num_pages));
        return array(
            'processed' => $processed,
            'total'     => absint($query->found_posts),
            'pages'     => $pages,
            'page'      => $page,
            'done'      => $page >= $pages,
        );
    }

    public static function index_product($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if (!$product_id || !class_exists('WooCommerce')) {
            return false;
        }
        if (!self::table_exists()) {
            self::install();
        }

        $post = get_post($product_id);
        $product = wc_get_product($product_id);
        if (!$post || !$product || 'product' !== $post->post_type || 'publish' !== $post->post_status) {
            self::delete_product($product_id);
            return false;
        }
        if ('hidden' === $product->get_catalog_visibility()) {
            self::delete_product($product_id);
            return false;
        }

        $categories = self::get_terms($product_id, 'product_cat');
        $tags = taxonomy_exists('product_tag') ? self::get_terms($product_id, 'product_tag') : array();
        $brand = self::get_brand($product_id);
        $vocabulary = self::get_vocabulary($product_id);
        $attributes = self::get_all_attributes($product);
        $commercial = self::get_commercial_data($product_id);
        $global_unique_id = method_exists($product, 'get_global_unique_id')
            ? trim((string) $product->get_global_unique_id())
            : trim((string) get_post_meta($product_id, '_global_unique_id', true));
        if ($global_unique_id && empty($commercial['global_unique_id'])) {
            $commercial['global_unique_id'] = $global_unique_id;
        }

        if (!$brand['name'] && !empty($commercial['brand'])) {
            $brand['name'] = (string) $commercial['brand'];
            $brand['slug'] = sanitize_title($brand['name']);
        }

        $title = (string) get_the_title($product_id);
        $excerpt = (string) $post->post_excerpt;
        if ('' === trim($excerpt)) {
            $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)), 48, '…');
        }
        $description = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 30000, 'UTF-8');
        } else {
            $description = substr($description, 0, 30000);
        }

        $price = self::nullable_decimal($product->get_price());
        $regular_price = self::nullable_decimal($product->get_regular_price());
        $sale_price = self::nullable_decimal($product->get_sale_price());
        $weight = self::nullable_decimal($product->get_weight());
        $dimensions = $product->get_dimensions(false);
        $length = self::nullable_decimal(isset($dimensions['length']) ? $dimensions['length'] : '');
        $width = self::nullable_decimal(isset($dimensions['width']) ? $dimensions['width'] : '');
        $height = self::nullable_decimal(isset($dimensions['height']) ? $dimensions['height'] : '');

        $search_chunks = array(
            'nombre ' . $title,
            'descripcion ' . $description,
            'extracto ' . $excerpt,
            'referencia sku ' . $product->get_sku() . ' gtin upc ean isbn ' . $global_unique_id,
            'marca fabricante ' . $brand['name'],
            self::terms_search_text('categoria', $categories),
            self::terms_search_text('etiqueta', $tags),
            self::vocabulary_search_text($vocabulary),
            self::attributes_search_text($attributes),
            self::commercial_search_text($commercial),
            'precio ' . $product->get_price() . ' ' . get_woocommerce_currency() . ' euro euros',
            'peso ' . $product->get_weight() . ' ' . get_option('woocommerce_weight_unit', 'kg'),
            'largo longitud ' . (isset($dimensions['length']) ? $dimensions['length'] : '') . ' ' . get_option('woocommerce_dimension_unit', 'cm'),
            'ancho anchura ' . (isset($dimensions['width']) ? $dimensions['width'] : '') . ' ' . get_option('woocommerce_dimension_unit', 'cm'),
            'alto altura ' . (isset($dimensions['height']) ? $dimensions['height'] : '') . ' ' . get_option('woocommerce_dimension_unit', 'cm'),
            'stock disponibilidad ' . $product->get_stock_status(),
            'tipo producto ' . $product->get_type(),
        );

        $data = array(
            'product_id'        => $product_id,
            'title'             => $title,
            'normalized_title'  => self::normalize($title),
            'excerpt'           => $excerpt,
            'sku'               => (string) $product->get_sku(),
            'brand_name'        => (string) $brand['name'],
            'brand_slug'        => (string) $brand['slug'],
            'categories_json'   => wp_json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tags_json'         => wp_json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'vocabulary_json'   => wp_json_encode($vocabulary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attributes_json'   => wp_json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'commercial_json'   => wp_json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'search_text'       => self::normalize(implode(' ', array_filter($search_chunks))),
            'price'             => $price,
            'regular_price'     => $regular_price,
            'sale_price'        => $sale_price,
            'weight'            => $weight,
            'length'            => $length,
            'width'             => $width,
            'height'            => $height,
            'stock_status'      => (string) $product->get_stock_status(),
            'featured'          => $product->is_featured() ? 1 : 0,
            'product_type'      => (string) $product->get_type(),
            'image_url'         => self::product_image_url($product),
            'permalink'         => (string) get_permalink($product_id),
            'post_modified_gmt' => $post->post_modified_gmt ?: null,
            'updated_at'        => current_time('mysql'),
        );

        $result = $wpdb->replace(self::table(), $data);
        return false !== $result;
    }

    public static function delete_product($product_id) {
        global $wpdb;
        $product_id = absint($product_id);
        if (!$product_id || !self::table_exists()) {
            return false;
        }
        return false !== $wpdb->delete(self::table(), array('product_id' => $product_id), array('%d'));
    }

    public static function clear() {
        global $wpdb;
        if (!self::table_exists()) {
            return true;
        }
        return false !== $wpdb->query('TRUNCATE TABLE `' . esc_sql(self::table()) . '`');
    }

    public static function count_indexed() {
        global $wpdb;
        if (!self::table_exists()) {
            return 0;
        }
        return absint($wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql(self::table()) . '`'));
    }

    public static function count_published() {
        global $wpdb;
        return absint($wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        ));
    }

    public static function status() {
        return array(
            'indexed'   => self::count_indexed(),
            'published' => self::count_published(),
            'last_full' => (string) get_option('seo_dependiente_last_full_index', ''),
            'page_id'   => absint(get_option('seo_dependiente_page_id', 0)),
        );
    }

    public static function get_rows($limit = 1500) {
        global $wpdb;
        if (!self::table_exists()) {
            return array();
        }
        $limit = min(5000, max(1, absint($limit)));
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql(self::table()) . '` ORDER BY featured DESC, updated_at DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
    }

    public static function get_rows_by_ids($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (!$ids || !self::table_exists()) {
            return array();
        }
        $ids = array_slice($ids, 0, 20);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            'SELECT * FROM `' . esc_sql(self::table()) . "` WHERE product_id IN ({$placeholders})",
            $ids
        );
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[absint($row['product_id'])] = $row;
        }
        $ordered = array();
        foreach ($ids as $id) {
            if (isset($mapped[$id])) {
                $ordered[] = $mapped[$id];
            }
        }
        return $ordered;
    }

    public static function decode_row($row) {
        $row = is_array($row) ? $row : array();
        foreach (array('categories', 'tags', 'vocabulary', 'attributes', 'commercial') as $key) {
            $json_key = $key . '_json';
            $decoded = !empty($row[$json_key]) ? json_decode((string) $row[$json_key], true) : array();
            $row[$key] = is_array($decoded) ? $decoded : array();
        }
        return $row;
    }

    private static function nullable_decimal($value) {
        if ('' === (string) $value || null === $value) {
            return null;
        }
        return function_exists('wc_format_decimal') ? wc_format_decimal($value) : (string) (float) $value;
    }

    private static function get_terms($product_id, $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }
        $terms = wp_get_post_terms($product_id, $taxonomy, array('orderby' => 'name', 'order' => 'ASC'));
        if (is_wp_error($terms)) {
            return array();
        }
        $result = array();
        foreach ($terms as $term) {
            $result[] = array(
                'id'    => absint($term->term_id),
                'slug'  => (string) $term->slug,
                'name'  => (string) $term->name,
                'image' => 'product_cat' === $taxonomy ? self::get_term_image_url($term->term_id) : '',
            );
        }
        return $result;
    }

    private static function get_brand($product_id) {
        $taxonomies = array();
        if (function_exists('seo_product_brand_taxonomy')) {
            $candidate = (string) seo_product_brand_taxonomy();
            if ($candidate) {
                $taxonomies[] = $candidate;
            }
        }
        $taxonomies = array_merge($taxonomies, array('product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand'));
        $taxonomies = array_values(array_unique(array_filter($taxonomies)));

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = wp_get_post_terms($product_id, $taxonomy);
            if (!is_wp_error($terms) && !empty($terms[0])) {
                return array(
                    'name'     => (string) $terms[0]->name,
                    'slug'     => (string) $terms[0]->slug,
                    'taxonomy' => $taxonomy,
                );
            }
        }

        foreach (array('_seo_marca_proveedor', '_seo_fabricante') as $meta_key) {
            $name = trim((string) get_post_meta($product_id, $meta_key, true));
            if ($name) {
                return array('name' => $name, 'slug' => sanitize_title($name), 'taxonomy' => '');
            }
        }
        return array('name' => '', 'slug' => '', 'taxonomy' => '');
    }

    private static function get_vocabulary($product_id) {
        global $wpdb;

        $result = array(
            'rol'        => array(),
            'tipo'       => array(),
            'aplicacion' => array(),
            'plataforma' => array(),
            'subtipo'    => array(),
        );
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!self::table_exists($vocabulary) || !self::table_exists($objects)) {
            return $result;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group, v.slug, v.label
                 FROM {$objects} ov
                 INNER JOIN {$vocabulary} v ON v.id = ov.vocabulary_id AND v.active = 1
                 WHERE ov.object_type = 'product'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group ASC, v.label ASC",
                $product_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) $row['semantic_group']);
            if (!isset($result[$group])) {
                continue;
            }
            $result[$group][] = array(
                'id'    => absint($row['id']),
                'slug'  => (string) $row['slug'],
                'label' => (string) $row['label'],
            );
        }
        return $result;
    }

    private static function get_all_attributes($product) {
        $map = array();

        foreach ((array) $product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }
            $label = $attribute->get_name();
            $values = array();
            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_name();
                $label = function_exists('wc_attribute_label') ? wc_attribute_label($taxonomy, $product) : $taxonomy;
                $values = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'names'));
                if (is_wp_error($values)) {
                    $values = array();
                }
            } else {
                $values = $attribute->get_options();
            }
            self::merge_attribute($map, $label, $values, 'woocommerce', 'global');
        }

        if ($product->is_type('variable')) {
            $children = array_slice((array) $product->get_children(), 0, 80);
            foreach ($children as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }
                foreach ((array) $variation->get_variation_attributes(false) as $key => $value) {
                    $taxonomy = str_replace('attribute_', '', (string) $key);
                    $label = function_exists('wc_attribute_label') ? wc_attribute_label($taxonomy, $product) : $taxonomy;
                    $display = $value;
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $value, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $display = $term->name;
                        }
                    }
                    self::merge_attribute($map, $label, array($display), 'variation', 'global');
                }
            }
        }

        foreach (self::get_seo_attributes($product->get_id()) as $attribute) {
            self::merge_attribute(
                $map,
                $attribute['label'],
                $attribute['values'],
                'seo_taxonomy',
                $attribute['scope']
            );
        }

        return array_values($map);
    }

    private static function get_seo_attributes($product_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_attributes';
        if (!self::table_exists($table)) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ambito, attribute_type, attribute_value
                 FROM {$table}
                 WHERE product_id = %d
                 ORDER BY attribute_type ASC, id ASC",
                $product_id
            ),
            ARRAY_A
        );
        $result = array();
        foreach ((array) $rows as $row) {
            $type = trim((string) $row['attribute_type']);
            $value = trim((string) $row['attribute_value']);
            if (!$type || !$value) {
                continue;
            }
            $label = ucwords(str_replace(array('_', '-'), ' ', $type));
            $result[] = array(
                'label'  => $label,
                'values' => self::split_values($value),
                'scope'  => (string) ($row['ambito'] ?: 'global'),
            );
        }
        return $result;
    }

    private static function merge_attribute(&$map, $label, $values, $source, $scope) {
        $label = trim(wp_strip_all_tags((string) $label));
        if (!$label) {
            return;
        }
        $key = sanitize_title(remove_accents($label));
        if (!$key) {
            return;
        }
        if (!isset($map[$key])) {
            $map[$key] = array(
                'key'    => $key,
                'label'  => $label,
                'values' => array(),
                'source' => array(),
                'scope'  => array(),
            );
        }

        $expanded = array();
        foreach ((array) $values as $value) {
            $expanded = array_merge($expanded, self::split_values($value));
        }
        foreach ($expanded as $value) {
            if (!in_array($value, $map[$key]['values'], true)) {
                $map[$key]['values'][] = $value;
            }
        }
        if ($source && !in_array($source, $map[$key]['source'], true)) {
            $map[$key]['source'][] = $source;
        }
        if ($scope && !in_array($scope, $map[$key]['scope'], true)) {
            $map[$key]['scope'][] = $scope;
        }
    }

    private static function split_values($value) {
        if (is_array($value)) {
            $values = $value;
        } else {
            $values = preg_split('/\s*[|;]\s*/u', trim((string) $value));
        }
        $result = array();
        foreach ((array) $values as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private static function get_commercial_data($product_id) {
        global $wpdb;

        $meta_keys = array(
            '_seo_proveedor'             => 'supplier',
            '_seo_proveedor_id_externo'  => 'supplier_external_id',
            '_seo_proveedor_mpn'          => 'mpn',
            '_seo_categoria_proveedor'    => 'supplier_category',
            '_seo_fabricante'             => 'manufacturer',
            '_seo_marca_proveedor'        => 'brand',
            '_global_unique_id'             => 'global_unique_id',
            '_wc_gla_gtin'                  => 'wc_gla_gtin',
            '_alg_ean'                      => 'alg_ean',
            '_ean'                          => 'ean',
            'ean'                           => 'ean_plain',
            '_gtin'                         => 'gtin',
            'gtin'                          => 'gtin_plain',
            'wpm_gtin_code'                 => 'wpm_gtin_code',
        );

        $configured = (string) SEO_Dependiente_Plugin::option('custom_meta_keys', '');
        if (function_exists('seo_search_get_custom_meta_keys')) {
            $configured .= ',' . implode(',', (array) seo_search_get_custom_meta_keys());
        }
        foreach (array_filter(array_map('trim', explode(',', $configured))) as $meta_key) {
            $meta_key = sanitize_key($meta_key);
            if ($meta_key && !isset($meta_keys[$meta_key])) {
                $meta_keys[$meta_key] = ltrim($meta_key, '_');
            }
        }

        $data = array();
        foreach ($meta_keys as $meta_key => $field) {
            $value = get_post_meta($product_id, $meta_key, true);
            if (is_scalar($value) && '' !== trim((string) $value)) {
                $data[$field] = trim((string) $value);
            }
        }

        $supplier_table = $wpdb->prefix . 'seo_proveedores_productos';
        if (self::table_exists($supplier_table)) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT proveedor, proveedor_id_externo, sku, mpn, nombre, descripcion,
                            marca, categoria_proveedor, stock_texto
                     FROM {$supplier_table}
                     WHERE object_id = %d
                     ORDER BY actualizado DESC, id DESC
                     LIMIT 1",
                    $product_id
                ),
                ARRAY_A
            );
            if (is_array($row)) {
                $mapping = array(
                    'proveedor'            => 'supplier',
                    'proveedor_id_externo' => 'supplier_external_id',
                    'mpn'                  => 'mpn',
                    'marca'                => 'brand',
                    'categoria_proveedor'  => 'supplier_category',
                    'stock_texto'          => 'supplier_stock_text',
                    'nombre'               => 'supplier_name',
                    'descripcion'          => 'supplier_description',
                );
                foreach ($mapping as $column => $field) {
                    if (!empty($row[$column]) && empty($data[$field])) {
                        $data[$field] = trim((string) $row[$column]);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Resuelve la imagen de un producto usando todas las fuentes reales del
     * catalogo. No obliga a que la imagen exista en Media: las URLs remotas de
     * proveedor son validas y se sirven directamente desde su hosting.
     *
     * Prioridad:
     * 1) Imagen destacada local de WooCommerce.
     * 2) Capa unificada seo_supplier_images, si esta disponible.
     * 3) Helper legacy de Supplier Sync V2.
     * 4) Tabla seo_supplier_images consultada directamente.
     * 5) Campo imagenes de seo_proveedores_productos enlazado por object_id,
     *    catalog_row_id o proveedor/SKU.
     *
     * Devuelve cadena vacia si no existe ninguna imagen real. El front-end de
     * Dependiente se encarga del fallback corporativo (logo).
     */
    public static function product_image_url($product_or_id) {
        static $cache = array();

        $product = $product_or_id instanceof WC_Product
            ? $product_or_id
            : (function_exists('wc_get_product') ? wc_get_product(absint($product_or_id)) : false);

        if (!$product instanceof WC_Product) {
            return '';
        }

        $product_id = absint($product->get_id());
        if (!$product_id) {
            return '';
        }

        if (array_key_exists($product_id, $cache)) {
            return $cache[$product_id];
        }

        $cache[$product_id] = '';

        // 1) WooCommerce / Media local.
        $image_id = absint($product->get_image_id());
        if ($image_id) {
            $url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
            if ($url) {
                return $cache[$product_id] = esc_url_raw((string) $url);
            }
        }

        // 2) Capa unificada del propio plugin: puede devolver URL remota.
        if (function_exists('seo_images_get_external_primary_url')) {
            $url = esc_url_raw((string) seo_images_get_external_primary_url($product_id));
            if (self::is_remote_image_url($url)) {
                return $cache[$product_id] = $url;
            }
        }

        // 3) Compatibilidad con instalaciones anteriores.
        if (function_exists('seo_supplier_v2_external_primary_url')) {
            $url = esc_url_raw((string) seo_supplier_v2_external_primary_url($product_id));
            if (self::is_remote_image_url($url)) {
                return $cache[$product_id] = $url;
            }
        }

        global $wpdb;

        // 4) Tabla normalizada de imagenes externas.
        $supplier_images = $wpdb->prefix . 'seo_supplier_images';
        if (self::table_exists($supplier_images)) {
            $columns = self::table_columns($supplier_images);
            $link_parts = array();
            $params = array();

            foreach (array('product_id', 'object_id') as $column) {
                if (in_array($column, $columns, true)) {
                    $link_parts[] = "{$column} = %d";
                    $params[] = $product_id;
                }
            }

            if ($link_parts && in_array('image_url', $columns, true)) {
                $status_sql = in_array('status', $columns, true) ? " AND status = 'active'" : '';
                $order = array();
                if (in_array('is_primary', $columns, true)) {
                    $order[] = 'is_primary DESC';
                }
                if (in_array('position', $columns, true)) {
                    $order[] = 'position ASC';
                }
                $order[] = 'id ASC';

                $sql = "SELECT image_url FROM {$supplier_images}
                        WHERE (" . implode(' OR ', $link_parts) . ")
                          {$status_sql}
                          AND image_url IS NOT NULL
                          AND TRIM(image_url) <> ''
                        ORDER BY " . implode(', ', $order) . " LIMIT 1";

                $url = esc_url_raw((string) $wpdb->get_var($wpdb->prepare($sql, $params)));
                if (self::is_remote_image_url($url)) {
                    return $cache[$product_id] = $url;
                }
            }
        }

        // 5) Fallback directo al catalogo de proveedor. Esto cubre staging
        // aunque seo_supplier_images todavia no haya sido materializada.
        $provider_url = self::provider_catalog_image_url($product);
        if ($provider_url) {
            return $cache[$product_id] = $provider_url;
        }

        return '';
    }

    /**
     * Busca la fotografia directamente en wp_seo_proveedores_productos.
     * El campo imagenes puede ser una URL, varias URLs o JSON.
     */
    private static function provider_catalog_image_url($product) {
        global $wpdb;

        if (!$product instanceof WC_Product) {
            return '';
        }

        $product_id = absint($product->get_id());
        $table = $wpdb->prefix . 'seo_proveedores_productos';

        if (!$product_id || !self::table_exists($table)) {
            return '';
        }

        $columns = self::table_columns($table);
        if (!in_array('imagenes', $columns, true)) {
            return '';
        }

        $rows = array();

        // Enlace principal de staging: producto WooCommerce -> object_id.
        if (in_array('object_id', $columns, true)) {
            $rows = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT imagenes FROM {$table}
                     WHERE object_id = %d
                       AND imagenes IS NOT NULL
                       AND TRIM(imagenes) <> ''
                     ORDER BY actualizado DESC, id DESC
                     LIMIT 10",
                    $product_id
                )
            );
        }

        // El importador guarda tambien el ID exacto de la fila de catalogo.
        if (!$rows && in_array('id', $columns, true)) {
            $catalog_row_id = absint(get_post_meta($product_id, '_seo_proveedor_catalogo_id', true));
            if ($catalog_row_id) {
                $value = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT imagenes FROM {$table}
                         WHERE id = %d
                           AND imagenes IS NOT NULL
                           AND TRIM(imagenes) <> ''
                         LIMIT 1",
                        $catalog_row_id
                    )
                );
                if ($value) {
                    $rows[] = $value;
                }
            }
        }

        // Ultimo enlace relacional: proveedor + SKU del producto.
        if (!$rows && in_array('proveedor', $columns, true) && in_array('sku', $columns, true)) {
            $supplier = sanitize_text_field((string) get_post_meta($product_id, '_seo_proveedor', true));
            $sku = trim((string) $product->get_sku());

            if ($supplier !== '' && $sku !== '') {
                $rows = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT imagenes FROM {$table}
                         WHERE proveedor = %s
                           AND sku = %s
                           AND imagenes IS NOT NULL
                           AND TRIM(imagenes) <> ''
                         ORDER BY actualizado DESC, id DESC
                         LIMIT 10",
                        $supplier,
                        $sku
                    )
                );
            }
        }

        foreach ($rows as $value) {
            foreach (self::extract_provider_image_urls($value) as $url) {
                if (self::is_remote_image_url($url)) {
                    return $url;
                }
            }
        }

        return '';
    }

    private static function extract_provider_image_urls($value) {
        if (function_exists('seo_proveedores_extraer_urls_imagenes')) {
            return array_values(array_filter(array_map('esc_url_raw', (array) seo_proveedores_extraer_urls_imagenes($value))));
        }

        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $urls = array();
        $decoded = json_decode($value, true);

        if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
            $stack = array($decoded);
            while ($stack) {
                $item = array_pop($stack);
                if (is_array($item)) {
                    foreach ($item as $nested) {
                        $stack[] = $nested;
                    }
                } elseif (is_scalar($item)) {
                    $candidate = trim((string) $item);
                    if (preg_match('#^https?://#i', $candidate)) {
                        $urls[] = $candidate;
                    }
                }
            }
        }

        if (!$urls) {
            preg_match_all('#https?://[^\\s<>"\\\']+#iu', $value, $matches);
            $urls = (array) ($matches[0] ?? array());
        }

        $clean = array();
        foreach ($urls as $url) {
            $url = rtrim(trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ',;|');
            $url = esc_url_raw($url);
            if (self::is_remote_image_url($url)) {
                $clean[$url] = $url;
            }
        }

        return array_values($clean);
    }

    private static function is_remote_image_url($url) {
        return is_string($url) && (bool) preg_match('#^https?://#i', trim($url));
    }

    private static function table_columns($table) {
        global $wpdb;
        static $cache = array();

        $table = (string) $table;
        if ($table === '' || !self::table_exists($table)) {
            return array();
        }
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = array_values(array_filter(array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0))));
        }
        return $cache[$table];
    }

    private static function get_term_image_url($term_id) {
        $thumbnail_id = absint(get_term_meta($term_id, 'thumbnail_id', true));
        if (!$thumbnail_id) {
            return '';
        }
        $url = wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail');
        return $url ? esc_url_raw($url) : '';
    }

    private static function terms_search_text($prefix, $terms) {
        $chunks = array();
        foreach ((array) $terms as $term) {
            $chunks[] = $prefix . ' ' . ($term['name'] ?? '') . ' ' . ($term['slug'] ?? '');
        }
        return implode(' ', $chunks);
    }

    private static function vocabulary_search_text($vocabulary) {
        $chunks = array();
        foreach ((array) $vocabulary as $group => $items) {
            foreach ((array) $items as $item) {
                $chunks[] = $group . ' ' . ($item['label'] ?? '') . ' ' . ($item['slug'] ?? '');
            }
        }
        return implode(' ', $chunks);
    }

    private static function attributes_search_text($attributes) {
        $chunks = array();
        foreach ((array) $attributes as $attribute) {
            $chunks[] = 'atributo ' . ($attribute['label'] ?? '') . ' ' . implode(' ', (array) ($attribute['values'] ?? array()));
        }
        return implode(' ', $chunks);
    }

    private static function commercial_search_text($commercial) {
        $chunks = array();
        foreach ((array) $commercial as $key => $value) {
            if (is_scalar($value)) {
                $chunks[] = str_replace('_', ' ', $key) . ' ' . $value;
            }
        }
        return implode(' ', $chunks);
    }
}
