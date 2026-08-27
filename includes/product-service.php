<?php
/**
 * Servicio unitario de productos para SEO System.
 *
 * Centraliza INSERT/UPDATE de un solo producto para que el alta manual y la
 * edicion utilicen las mismas tablas de vocabulario, atributos, proveedor e
 * imagenes que el resto del sistema.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_table_exists')) {
    function seo_product_table_exists($table_name) {
        global $wpdb;

        $table_name = (string) $table_name;
        if ($table_name === '') {
            return false;
        }

        if (function_exists('seo_catalog_table_exists')) {
            return seo_catalog_table_exists($table_name);
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        ) === $table_name;
    }
}

if (!function_exists('seo_product_get_provider_suggestions')) {
    function seo_product_get_provider_suggestions() {
        global $wpdb;

        $providers = [];
        $catalog_table = $wpdb->prefix . 'seo_proveedores_productos';

        if (seo_product_table_exists($catalog_table)) {
            $providers = array_merge(
                $providers,
                (array) $wpdb->get_col(
                    "SELECT DISTINCT proveedor
                     FROM {$catalog_table}
                     WHERE proveedor IS NOT NULL AND proveedor <> ''
                     ORDER BY proveedor ASC"
                )
            );
        }

        $providers = array_merge(
            $providers,
            (array) $wpdb->get_col(
                "SELECT DISTINCT meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_seo_proveedor'
                   AND meta_value <> ''
                 ORDER BY meta_value ASC"
            )
        );

        $providers = array_values(array_unique(array_filter(array_map('sanitize_text_field', $providers))));
        natcasesort($providers);

        return array_values($providers);
    }
}

if (!function_exists('seo_product_get_vocabulary_terms')) {
    function seo_product_get_vocabulary_terms() {
        global $wpdb;

        $table = $wpdb->prefix . 'seo_vocabulary';
        $groups = [
            'tipo'       => [],
            'rol'        => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo'    => [],
        ];

        if (!seo_product_table_exists($table)) {
            return $groups;
        }

        $rows = $wpdb->get_results(
            "SELECT id, semantic_group, slug, label
             FROM {$table}
             WHERE active = 1
               AND semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
             ORDER BY semantic_group ASC, label ASC, slug ASC",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (isset($groups[$group])) {
                $groups[$group][] = $row;
            }
        }

        return $groups;
    }
}

if (!function_exists('seo_product_get_type_role_map')) {
    function seo_product_get_type_role_map() {
        global $wpdb;

        $map = [];
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';
        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';

        if (!seo_product_table_exists($type_role_table) || !seo_product_table_exists($vocabulary_table)) {
            return $map;
        }

        $rows = $wpdb->get_results(
            "SELECT trm.type_vocabulary_id, trm.role_vocabulary_id, rv.label AS role_label
             FROM {$type_role_table} trm
             JOIN {$vocabulary_table} tv
               ON tv.id = trm.type_vocabulary_id
              AND tv.semantic_group = 'tipo'
              AND tv.active = 1
             JOIN {$vocabulary_table} rv
               ON rv.id = trm.role_vocabulary_id
              AND rv.semantic_group = 'rol'
              AND rv.active = 1
             WHERE trm.active = 1",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $type_id = absint($row['type_vocabulary_id'] ?? 0);
            $role_id = absint($row['role_vocabulary_id'] ?? 0);
            if ($type_id > 0 && $role_id > 0) {
                $map[$type_id] = [
                    'id'    => $role_id,
                    'label' => sanitize_text_field((string) ($row['role_label'] ?? '')),
                ];
            }
        }

        return $map;
    }
}

if (!function_exists('seo_product_get_vocabulary_assignments')) {
    function seo_product_get_vocabulary_assignments($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        $result = [
            'tipo'       => [],
            'rol'        => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo'    => [],
        ];

        if ($product_id < 1) {
            return $result;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';

        if (!seo_product_table_exists($vocabulary_table) || !seo_product_table_exists($object_table)) {
            return $result;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group
                 FROM {$object_table} ov
                 JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id
                 WHERE ov.object_type = 'product'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.active = 1
                   AND v.semantic_group IN ('tipo','rol','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group ASC, v.label ASC",
                $product_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $id = absint($row['id'] ?? 0);
            if ($id > 0 && isset($result[$group])) {
                $result[$group][] = $id;
            }
        }

        return $result;
    }
}

if (!function_exists('seo_product_get_attributes_text')) {
    function seo_product_get_attributes_text($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return '';
        }

        $table = $wpdb->prefix . 'seo_attributes';
        if (!seo_product_table_exists($table)) {
            return '';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ambito, attribute_type, attribute_value
                 FROM {$table}
                 WHERE product_id = %d
                 ORDER BY ambito ASC, attribute_type ASC, id ASC",
                $product_id
            ),
            ARRAY_A
        );

        $lines = [];
        foreach ((array) $rows as $row) {
            $ambito = trim((string) ($row['ambito'] ?? '')) ?: 'global';
            $type = trim((string) ($row['attribute_type'] ?? ''));
            $value = trim((string) ($row['attribute_value'] ?? ''));
            if ($type !== '' && $value !== '') {
                $lines[] = $ambito . '|' . $type . '|' . $value;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('seo_product_parse_attributes_text')) {
    function seo_product_parse_attributes_text($raw) {
        $raw = (string) wp_unslash($raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $attributes = [];
        $invalid = [];

        foreach ((array) $lines as $line_number => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
                $invalid[] = $line_number + 1;
                continue;
            }

            $attributes[] = [
                'ambito'          => sanitize_text_field($parts[0] !== '' ? $parts[0] : 'global'),
                'attribute_type'  => sanitize_text_field($parts[1]),
                'attribute_value' => sanitize_textarea_field($parts[2]),
            ];
        }

        return [
            'attributes'    => $attributes,
            'invalid_lines' => $invalid,
        ];
    }
}

if (!function_exists('seo_product_get_external_images')) {
    function seo_product_get_external_images($product_id) {
        $product_id = absint($product_id);
        if ($product_id < 1) {
            return [];
        }

        if (function_exists('seo_images_get_external_product_images')) {
            $rows = seo_images_get_external_product_images($product_id, 100);
            $urls = [];
            foreach ((array) $rows as $row) {
                if (is_array($row)) {
                    $url = esc_url_raw((string) ($row['image_url'] ?? $row['url'] ?? ''));
                } elseif (is_object($row)) {
                    $url = esc_url_raw((string) ($row->image_url ?? $row->url ?? ''));
                } else {
                    $url = '';
                }
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
            return array_values(array_unique($urls));
        }

        return [];
    }
}

if (!function_exists('seo_product_load_supplier_engine')) {
    function seo_product_load_supplier_engine() {
        if (
            function_exists('seo_proveedores_guardar_imagenes_externas')
            && function_exists('seo_proveedores_desactivar_imagenes_externas')
        ) {
            return true;
        }

        $bridge = SEO_SYSTEM_PATH . 'includes/seo-import-suppliers.php';
        if (is_readable($bridge)) {
            require_once $bridge;
        }

        if (
            function_exists('seo_proveedores_guardar_imagenes_externas')
            && function_exists('seo_proveedores_desactivar_imagenes_externas')
        ) {
            return true;
        }

        return new WP_Error(
            'seo_product_supplier_engine_missing',
            'No está disponible el motor de imágenes externas de proveedores.'
        );
    }
}

if (!function_exists('seo_product_brand_taxonomy')) {
    function seo_product_brand_taxonomy() {
        if (function_exists('seo_proveedores_taxonomia_marca')) {
            return (string) seo_proveedores_taxonomia_marca();
        }

        foreach (['product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                return $taxonomy;
            }
        }

        return '';
    }
}

if (!function_exists('seo_product_assign_brand')) {
    function seo_product_assign_brand($product_id, $brand) {
        $product_id = absint($product_id);
        $brand = sanitize_text_field(trim((string) $brand));

        if ($product_id < 1) {
            return new WP_Error('seo_product_brand_id', 'ID de producto no válido para asignar la marca.');
        }

        if (function_exists('seo_proveedores_asignar_marca') && $brand !== '') {
            return seo_proveedores_asignar_marca($product_id, $brand);
        }

        $taxonomy = seo_product_brand_taxonomy();
        if ($taxonomy === '') {
            if ($brand !== '') {
                update_post_meta($product_id, '_seo_marca_proveedor', $brand);
            } else {
                delete_post_meta($product_id, '_seo_marca_proveedor');
            }
            return true;
        }

        if ($brand === '') {
            wp_set_object_terms($product_id, [], $taxonomy, false);
            delete_post_meta($product_id, '_seo_marca_proveedor');
            delete_post_meta($product_id, '_seo_taxonomia_marca');
            return true;
        }

        $term = term_exists($brand, $taxonomy);
        if (!$term) {
            $term = wp_insert_term($brand, $taxonomy);
        }
        if (is_wp_error($term)) {
            return $term;
        }

        $term_id = is_array($term) ? absint($term['term_id']) : absint($term);
        $assigned = wp_set_object_terms($product_id, [$term_id], $taxonomy, false);
        if (is_wp_error($assigned)) {
            return $assigned;
        }

        update_post_meta($product_id, '_seo_marca_proveedor', $brand);
        update_post_meta($product_id, '_seo_taxonomia_marca', $taxonomy);

        return true;
    }
}

if (!function_exists('seo_product_disable_external_images')) {
    function seo_product_disable_external_images($product_id, array $identities = []) {
        $product_id = absint($product_id);
        $loaded = seo_product_load_supplier_engine();

        if (!is_wp_error($loaded)) {
            foreach ($identities as $identity) {
                if (!is_array($identity)) {
                    continue;
                }
                seo_proveedores_desactivar_imagenes_externas($identity, $product_id);
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seo_supplier_images';
        if ($product_id > 0 && seo_product_table_exists($table)) {
            $wpdb->update(
                $table,
                [
                    'status'     => 'disabled',
                    'updated_at' => current_time('mysql'),
                ],
                ['product_id' => $product_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        delete_post_meta($product_id, '_seo_imagenes_externas');
        delete_post_meta($product_id, '_seo_imagenes_externas_total');
    }
}

if (!function_exists('seo_product_sync_external_images')) {
    function seo_product_sync_external_images($product_id, array $data, array $urls) {
        $product_id = absint($product_id);
        $supplier = sanitize_text_field((string) ($data['provider'] ?? ''));
        $external_id = sanitize_text_field((string) ($data['provider_external_id'] ?? ''));
        $sku = sanitize_text_field((string) ($data['sku'] ?? ''));

        if ($supplier === '' || $sku === '') {
            return new WP_Error(
                'seo_product_external_images_identity',
                'Para usar imágenes externas son obligatorios el proveedor y el SKU.'
            );
        }

        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
        if (empty($urls)) {
            return new WP_Error(
                'seo_product_external_images_empty',
                'Has seleccionado imágenes externas, pero no has indicado ninguna URL válida.'
            );
        }

        $loaded = seo_product_load_supplier_engine();
        if (is_wp_error($loaded)) {
            return $loaded;
        }

        $row = [
            'id'                   => 0,
            'proveedor'            => $supplier,
            'proveedor_id_externo' => $external_id !== '' ? $external_id : $sku,
            'sku'                  => $sku,
            'url_origen'           => esc_url_raw((string) ($data['provider_url'] ?? '')),
            'imagenes'             => wp_json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $result = seo_proveedores_guardar_imagenes_externas($row, $product_id);
        if (is_wp_error($result)) {
            return $result;
        }

        global $wpdb;
        $image_table = $wpdb->prefix . 'seo_supplier_images';
        if (seo_product_table_exists($image_table)) {
            $wpdb->update(
                $image_table,
                [
                    'source_file' => 'product_manual',
                    'updated_at'  => current_time('mysql'),
                ],
                ['product_id' => $product_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        update_post_meta($product_id, '_seo_imagenes_externas', 1);
        update_post_meta($product_id, '_seo_imagenes_externas_total', absint($result['count'] ?? count($urls)));

        return true;
    }
}

if (!function_exists('seo_product_role_for_type')) {
    function seo_product_role_for_type($type_id) {
        global $wpdb;

        $type_id = absint($type_id);
        if ($type_id < 1) {
            return new WP_Error('seo_product_type_required', 'Debes seleccionar un TIPO del vocabulario.');
        }

        if (function_exists('seo_catalog_get_role_for_type_vocabulary')) {
            $role = seo_catalog_get_role_for_type_vocabulary($type_id);
            if (is_array($role) && !empty($role['id'])) {
                return [
                    'id'    => absint($role['id']),
                    'label' => sanitize_text_field((string) ($role['label'] ?? $role['slug'] ?? '')),
                ];
            }
        }

        $map = seo_product_get_type_role_map();
        if (isset($map[$type_id])) {
            return $map[$type_id];
        }

        return new WP_Error(
            'seo_product_type_without_role',
            'El TIPO seleccionado no tiene un ROL activo asociado.'
        );
    }
}

if (!function_exists('seo_product_normalize_form_data')) {
    function seo_product_normalize_form_data(array $raw) {
        $multi_ids = static function ($value) {
            return array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : []))));
        };

        $external_images_raw = (string) ($raw['external_images'] ?? '');
        $external_images = [];
        foreach (preg_split('/\r\n|\r|\n/', wp_unslash($external_images_raw)) as $url) {
            $url = esc_url_raw(trim((string) $url));
            if ($url !== '') {
                $external_images[] = $url;
            }
        }

        $gallery_ids = [];
        foreach (explode(',', sanitize_text_field((string) ($raw['gallery_ids'] ?? ''))) as $gallery_id) {
            $gallery_id = absint($gallery_id);
            if ($gallery_id > 0) {
                $gallery_ids[] = $gallery_id;
            }
        }

        return [
            'product_id'           => absint($raw['product_id'] ?? 0),
            'title'                => sanitize_text_field(wp_unslash($raw['title'] ?? '')),
            'slug'                 => sanitize_title(wp_unslash($raw['slug'] ?? '')),
            'sku'                  => sanitize_text_field(wp_unslash($raw['sku'] ?? '')),
            'status'               => sanitize_key($raw['status'] ?? 'draft'),
            'excerpt'              => wp_kses_post(wp_unslash($raw['excerpt'] ?? '')),
            'description'          => wp_kses_post(wp_unslash($raw['description'] ?? '')),
            'regular_price'        => wc_format_decimal(wp_unslash($raw['regular_price'] ?? '')),
            'sale_price'           => wc_format_decimal(wp_unslash($raw['sale_price'] ?? '')),
            'manage_stock'         => !empty($raw['manage_stock']),
            'stock_quantity'       => wc_stock_amount(wp_unslash($raw['stock_quantity'] ?? 0)),
            'stock_status'         => in_array(($raw['stock_status'] ?? 'instock'), ['instock', 'outofstock', 'onbackorder'], true)
                ? sanitize_key($raw['stock_status'])
                : 'instock',
            'weight'               => wc_format_decimal(wp_unslash($raw['weight'] ?? '')),
            'length'               => wc_format_decimal(wp_unslash($raw['length'] ?? '')),
            'width'                => wc_format_decimal(wp_unslash($raw['width'] ?? '')),
            'height'               => wc_format_decimal(wp_unslash($raw['height'] ?? '')),
            'category_ids'         => $multi_ids($raw['category_ids'] ?? []),
            'brand'                => sanitize_text_field(wp_unslash($raw['brand'] ?? '')),
            'manufacturer'         => sanitize_text_field(wp_unslash($raw['manufacturer'] ?? '')),
            'provider'             => sanitize_text_field(wp_unslash($raw['provider'] ?? '')),
            'provider_external_id' => sanitize_text_field(wp_unslash($raw['provider_external_id'] ?? '')),
            'provider_mpn'         => sanitize_text_field(wp_unslash($raw['provider_mpn'] ?? '')),
            'provider_category'    => sanitize_text_field(wp_unslash($raw['provider_category'] ?? '')),
            'provider_price'       => wc_format_decimal(wp_unslash($raw['provider_price'] ?? '')),
            'provider_url'         => esc_url_raw(wp_unslash($raw['provider_url'] ?? '')),
            'type_id'              => absint($raw['type_id'] ?? 0),
            'application_ids'      => $multi_ids($raw['application_ids'] ?? []),
            'platform_ids'         => $multi_ids($raw['platform_ids'] ?? []),
            'subtype_ids'          => $multi_ids($raw['subtype_ids'] ?? []),
            'attributes_text'      => (string) ($raw['attributes_text'] ?? ''),
            'image_mode'           => in_array(($raw['image_mode'] ?? 'none'), ['media', 'external', 'none'], true)
                ? sanitize_key($raw['image_mode'])
                : 'none',
            'featured_image_id'    => absint($raw['featured_image_id'] ?? 0),
            'gallery_ids'          => array_values(array_unique($gallery_ids)),
            'external_images'      => array_values(array_unique($external_images)),
        ];
    }
}

if (!function_exists('seo_product_save_single')) {
    function seo_product_save_single(array $raw) {
        if (!function_exists('wc_get_product') || !class_exists('WC_Product_Simple')) {
            return new WP_Error('seo_product_woocommerce_missing', 'WooCommerce no está disponible.');
        }

        $data = seo_product_normalize_form_data($raw);
        $product_id = absint($data['product_id']);
        $is_new = $product_id < 1;

        if ($data['title'] === '') {
            return new WP_Error('seo_product_title_required', 'El título es obligatorio.');
        }
        if ($data['sku'] === '') {
            return new WP_Error('seo_product_sku_required', 'El SKU es obligatorio.');
        }
        if (empty($data['category_ids'])) {
            return new WP_Error('seo_product_category_required', 'Debes seleccionar al menos una categoría.');
        }
        if ($is_new && $data['provider'] === '') {
            return new WP_Error('seo_product_provider_required', 'El proveedor es obligatorio en un alta manual.');
        }

        if ($data['provider'] !== '' && $data['provider_external_id'] === '') {
            $data['provider_external_id'] = $data['sku'];
        }

        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if (!in_array($data['status'], $allowed_statuses, true)) {
            $data['status'] = 'draft';
        }

        $existing_sku_id = absint(wc_get_product_id_by_sku($data['sku']));
        if ($existing_sku_id > 0 && $existing_sku_id !== $product_id) {
            return new WP_Error(
                'seo_product_duplicate_sku',
                sprintf('El SKU %s ya pertenece al producto ID %d.', $data['sku'], $existing_sku_id)
            );
        }

        if ($data['provider'] !== '' && $data['provider_external_id'] !== '') {
            global $wpdb;
            $provider_match_id = absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} pm_provider
                       ON pm_provider.post_id = p.ID
                      AND pm_provider.meta_key = '_seo_proveedor'
                     JOIN {$wpdb->postmeta} pm_external
                       ON pm_external.post_id = p.ID
                      AND pm_external.meta_key = '_seo_proveedor_id_externo'
                     WHERE p.post_type = 'product'
                       AND pm_provider.meta_value = %s
                       AND pm_external.meta_value = %s
                     LIMIT 1",
                    $data['provider'],
                    $data['provider_external_id']
                )
            ));

            if ($provider_match_id > 0 && $provider_match_id !== $product_id) {
                return new WP_Error(
                    'seo_product_duplicate_provider_identity',
                    sprintf(
                        'Ya existe el producto ID %d con proveedor %s y referencia externa %s.',
                        $provider_match_id,
                        $data['provider'],
                        $data['provider_external_id']
                    )
                );
            }
        }

        $role = seo_product_role_for_type($data['type_id']);
        if (is_wp_error($role)) {
            return $role;
        }

        $parsed_attributes = seo_product_parse_attributes_text($data['attributes_text']);
        if (!empty($parsed_attributes['invalid_lines'])) {
            return new WP_Error(
                'seo_product_invalid_attributes',
                'Hay atributos con formato incorrecto en las líneas: ' . implode(', ', $parsed_attributes['invalid_lines']) . '. Usa ámbito|tipo|valor.'
            );
        }

        if ($data['image_mode'] === 'external' && empty($data['external_images'])) {
            return new WP_Error('seo_product_external_images_required', 'Añade al menos una URL de imagen externa.');
        }

        $old_identity = [];
        if (!$is_new) {
            $existing = wc_get_product($product_id);
            if (!$existing) {
                return new WP_Error('seo_product_not_found', 'El producto que intentas editar no existe.');
            }
            $product = $existing;
            $old_identity = [
                'proveedor'            => (string) get_post_meta($product_id, '_seo_proveedor', true),
                'proveedor_id_externo' => (string) get_post_meta($product_id, '_seo_proveedor_id_externo', true),
                'sku'                  => (string) $product->get_sku('edit'),
            ];
        } else {
            $product = new WC_Product_Simple();
        }

        try {
            $product->set_name($data['title']);
            $product->set_slug($data['slug'] !== '' ? $data['slug'] : sanitize_title($data['title']));
            $product->set_sku($data['sku']);
            $product->set_status($data['status']);
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['excerpt']);
            $product->set_category_ids($data['category_ids']);
            $product->set_tax_status('taxable');

            if ($data['regular_price'] !== '') {
                $product->set_regular_price($data['regular_price']);
            } else {
                $product->set_regular_price('');
            }

            if (
                $data['sale_price'] !== ''
                && $data['regular_price'] !== ''
                && (float) $data['sale_price'] < (float) $data['regular_price']
            ) {
                $product->set_sale_price($data['sale_price']);
                $product->set_price($data['sale_price']);
            } else {
                $product->set_sale_price('');
                $product->set_price($data['regular_price']);
            }

            $product->set_manage_stock($data['manage_stock']);
            if ($data['manage_stock']) {
                $product->set_stock_quantity($data['stock_quantity']);
            }
            $product->set_stock_status($data['stock_status']);

            $product->set_weight($data['weight']);
            $product->set_length($data['length']);
            $product->set_width($data['width']);
            $product->set_height($data['height']);

            $product_id = absint($product->save());
            if ($product_id < 1) {
                return new WP_Error('seo_product_save_failed', 'WooCommerce no devolvió un ID de producto.');
            }

            update_post_meta($product_id, '_seo_proveedor', $data['provider']);
            update_post_meta($product_id, '_seo_proveedor_id_externo', $data['provider_external_id']);
            update_post_meta($product_id, '_seo_proveedor_mpn', $data['provider_mpn']);
            update_post_meta($product_id, '_seo_categoria_proveedor', $data['provider_category']);
            update_post_meta($product_id, '_seo_precio_proveedor', $data['provider_price']);
            update_post_meta($product_id, '_seo_proveedor_url_origen', $data['provider_url']);
            update_post_meta($product_id, '_seo_proveedor_url_canonica', $data['provider_url']);
            update_post_meta($product_id, '_seo_fabricante', $data['manufacturer']);
            if ($is_new) {
                update_post_meta($product_id, '_seo_origen_importacion', 'manual');
                update_post_meta($product_id, '_seo_alta_manual', 1);
            }
            update_post_meta($product_id, '_seo_category_status', 'assigned');

            $brand_result = seo_product_assign_brand($product_id, $data['brand']);
            if (is_wp_error($brand_result)) {
                throw new RuntimeException($brand_result->get_error_message());
            }

            if (!function_exists('seo_catalog_replace_product_vocabulary_group')) {
                throw new RuntimeException('No está disponible el servicio de vocabulario canónico.');
            }

            $source_module = $is_new ? 'product_manual_create' : 'product_edit';

            $semantic_plan = [
                'tipo'       => [$data['type_id']],
                'rol'        => [absint($role['id'])],
                'aplicacion' => $data['application_ids'],
                'plataforma' => $data['platform_ids'],
                'subtipo'    => $data['subtype_ids'],
            ];

            foreach ($semantic_plan as $group => $ids) {
                $saved = seo_catalog_replace_product_vocabulary_group(
                    $product_id,
                    $group,
                    $ids,
                    $source_module,
                    1.0
                );
                if (!$saved) {
                    throw new RuntimeException('No se pudo guardar el grupo de vocabulario ' . strtoupper($group) . '.');
                }
            }

            if (!function_exists('seo_attributes_replace_product')) {
                throw new RuntimeException('No está disponible el servicio central de atributos.');
            }

            seo_attributes_replace_product(
                $product_id,
                $parsed_attributes['attributes'],
                $source_module
            );

            $current_identity = [
                'proveedor'            => $data['provider'],
                'proveedor_id_externo' => $data['provider_external_id'],
                'sku'                  => $data['sku'],
            ];

            $product = wc_get_product($product_id);
            if (!$product) {
                throw new RuntimeException('No se pudo recargar el producto para guardar sus imágenes.');
            }

            if ($data['image_mode'] === 'media') {
                seo_product_disable_external_images($product_id, array_filter([$old_identity, $current_identity]));
                $product->set_image_id($data['featured_image_id']);
                $product->set_gallery_image_ids($data['gallery_ids']);
                $product->save();
            } elseif ($data['image_mode'] === 'external') {
                $product->set_image_id(0);
                $product->set_gallery_image_ids([]);
                $product->save();

                $external_result = seo_product_sync_external_images($product_id, $data, $data['external_images']);
                if (is_wp_error($external_result)) {
                    throw new RuntimeException($external_result->get_error_message());
                }
            } else {
                seo_product_disable_external_images($product_id, array_filter([$old_identity, $current_identity]));
                $product->set_image_id(0);
                $product->set_gallery_image_ids([]);
                $product->save();
            }

            clean_post_cache($product_id);
            wc_delete_product_transients($product_id);

            return [
                'product_id' => $product_id,
                'created'    => $is_new,
                'role_id'    => absint($role['id']),
                'role_label' => (string) ($role['label'] ?? ''),
            ];
        } catch (Throwable $e) {
            return new WP_Error('seo_product_save_exception', $e->getMessage());
        }
    }
}

if (!function_exists('seo_product_set_admin_notice')) {
    function seo_product_set_admin_notice($type, $message) {
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }

        set_transient(
            'seo_product_notice_' . $user_id,
            [
                'type'    => sanitize_key($type),
                'message' => sanitize_text_field($message),
            ],
            120
        );
    }
}

if (!function_exists('seo_product_render_admin_notice')) {
    function seo_product_render_admin_notice() {
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }

        $key = 'seo_product_notice_' . $user_id;
        $notice = get_transient($key);
        if (!$notice || !is_array($notice)) {
            return;
        }

        delete_transient($key);
        $type = in_array(($notice['type'] ?? ''), ['success', 'warning', 'error', 'info'], true)
            ? $notice['type']
            : 'info';

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>'
            . esc_html((string) ($notice['message'] ?? ''))
            . '</p></div>';
    }
}

if (!function_exists('seo_product_admin_save_single')) {
    function seo_product_admin_save_single() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para modificar productos.');
        }

        check_admin_referer('seo_save_single_product', 'seo_product_nonce');

        $raw = isset($_POST['seo_product']) && is_array($_POST['seo_product'])
            ? $_POST['seo_product']
            : [];

        $result = seo_product_save_single($raw);
        $return_tab = sanitize_key($_POST['return_tab'] ?? 'editar');
        if (!in_array($return_tab, ['nuevo', 'editar'], true)) {
            $return_tab = 'editar';
        }

        if (is_wp_error($result)) {
            seo_product_set_admin_notice('error', $result->get_error_message());
            $redirect = add_query_arg(
                [
                    'page'       => 'product-page-admin',
                    'tab'        => $return_tab,
                    'product_id' => absint($raw['product_id'] ?? 0),
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $product_id = absint($result['product_id'] ?? 0);
        seo_product_set_admin_notice(
            'success',
            !empty($result['created'])
                ? sprintf('Producto #%d creado e integrado en vocabulario y atributos.', $product_id)
                : sprintf('Producto #%d actualizado correctamente.', $product_id)
        );

        $redirect = add_query_arg(
            [
                'page'       => 'product-page-admin',
                'tab'        => 'editar',
                'product_id' => $product_id,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    add_action('admin_post_seo_save_single_product', 'seo_product_admin_save_single');
}
