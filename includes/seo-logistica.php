<?php
/**
 * SEO Taxonomy - Logistica de compras.
 *
 * Vista de solo lectura que cruza pedidos activos de WooCommerce con
 * seo_proveedores_productos mediante object_id. No crea tablas ni persiste
 * estados propios: cada carga recalcula la situacion actual.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_logistica_supplier_table')) {
    function seo_logistica_supplier_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_proveedores_productos';
    }
}

if (!function_exists('seo_logistica_supplier_table_exists')) {
    function seo_logistica_supplier_table_exists() {
        global $wpdb;
        $table = seo_logistica_supplier_table();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }
}

if (!function_exists('seo_logistica_order_edit_url')) {
    function seo_logistica_order_edit_url($order) {
        if (is_object($order) && is_callable(array($order, 'get_edit_order_url'))) {
            $url = $order->get_edit_order_url();
            if (!empty($url)) {
                return $url;
            }
        }

        $order_id = is_object($order) && is_callable(array($order, 'get_id'))
            ? absint($order->get_id())
            : 0;

        if (!$order_id) {
            return '';
        }

        if (
            class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && is_callable(array('Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled'))
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }

        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }
}

if (!function_exists('seo_logistica_active_order_statuses')) {
    function seo_logistica_active_order_statuses() {
        $statuses = array('pending', 'on-hold', 'processing');

        /**
         * Permite ampliar o reducir los estados considerados operativos.
         * Deben pasarse sin el prefijo wc-.
         */
        return array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            (array) apply_filters('seo_logistica_active_order_statuses', $statuses)
        ))));
    }
}

if (!function_exists('seo_logistica_collect_order_lines')) {
    function seo_logistica_collect_order_lines() {
        if (!function_exists('wc_get_orders')) {
            return array(
                'orders'     => array(),
                'lines'      => array(),
                'object_ids' => array(),
            );
        }

        $orders = wc_get_orders(array(
            'status'  => seo_logistica_active_order_statuses(),
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ));

        $lines      = array();
        $object_ids = array();

        foreach ($orders as $order) {
            if (!is_object($order) || !is_callable(array($order, 'get_items'))) {
                continue;
            }

            foreach ($order->get_items('line_item') as $item_id => $item) {
                if (!is_object($item)) {
                    continue;
                }

                $product_id   = absint($item->get_product_id());
                $variation_id = absint($item->get_variation_id());
                $object_id    = $variation_id ?: $product_id;

                if (!$object_id) {
                    continue;
                }

                $product = is_callable(array($item, 'get_product')) ? $item->get_product() : false;
                $sku     = $product && is_callable(array($product, 'get_sku')) ? (string) $product->get_sku() : '';

                $lines[] = array(
                    'order'        => $order,
                    'item_id'      => absint($item_id),
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'object_id'    => $object_id,
                    'name'         => (string) $item->get_name(),
                    'quantity'     => (float) $item->get_quantity(),
                    'sku'          => $sku,
                );

                $object_ids[$object_id] = $object_id;
                if ($variation_id && $product_id && $product_id !== $variation_id) {
                    $object_ids[$product_id] = $product_id;
                }
            }
        }

        return array(
            'orders'     => $orders,
            'lines'      => $lines,
            'object_ids' => array_values($object_ids),
        );
    }
}

if (!function_exists('seo_logistica_load_supplier_map')) {
    function seo_logistica_load_supplier_map($object_ids) {
        global $wpdb;

        $map        = array();
        $object_ids = array_values(array_unique(array_filter(array_map('absint', (array) $object_ids))));

        if (empty($object_ids) || !seo_logistica_supplier_table_exists()) {
            return $map;
        }

        $table        = seo_logistica_supplier_table();
        $placeholders = implode(',', array_fill(0, count($object_ids), '%d'));
        $sql          = "SELECT id, proveedor, proveedor_id_externo, sku, nombre, url_origen, url_canonica, object_id, estado_sincronizacion, actualizado
                         FROM {$table}
                         WHERE object_id IN ({$placeholders})
                         ORDER BY actualizado DESC, id DESC";

        $prepared = $wpdb->prepare($sql, $object_ids);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        foreach ((array) $rows as $row) {
            $object_id = absint($row['object_id'] ?? 0);
            if (!$object_id) {
                continue;
            }
            if (!isset($map[$object_id])) {
                $map[$object_id] = array();
            }
            $map[$object_id][] = $row;
        }

        return $map;
    }
}

if (!function_exists('seo_logistica_supplier_matches')) {
    function seo_logistica_supplier_matches($line, $supplier_map) {
        $object_id  = absint($line['object_id'] ?? 0);
        $product_id = absint($line['product_id'] ?? 0);

        if ($object_id && !empty($supplier_map[$object_id])) {
            return array(
                'rows'        => $supplier_map[$object_id],
                'match_type'  => 'exact',
                'matched_id'  => $object_id,
            );
        }

        if ($product_id && $product_id !== $object_id && !empty($supplier_map[$product_id])) {
            return array(
                'rows'        => $supplier_map[$product_id],
                'match_type'  => 'parent',
                'matched_id'  => $product_id,
            );
        }

        return array(
            'rows'        => array(),
            'match_type'  => 'missing',
            'matched_id'  => 0,
        );
    }
}

if (!function_exists('seo_logistica_catalog_url')) {
    function seo_logistica_catalog_url($supplier_row) {
        $needle = trim((string) ($supplier_row['sku'] ?? ''));
        if ('' === $needle) {
            $needle = trim((string) ($supplier_row['proveedor_id_externo'] ?? ''));
        }

        $url = admin_url('admin.php?page=seo-import-export&seo_ie_tab=catalogo-proveedores');
        if ('' !== $needle) {
            $url = add_query_arg('f_sku', $needle, $url);
        }

        return $url;
    }
}

if (!function_exists('seo_logistica_render_supplier_cells')) {
    function seo_logistica_render_supplier_cells($supplier_row, $match) {
        $provider_name = trim((string) ($supplier_row['proveedor'] ?? ''));
        $external_id   = trim((string) ($supplier_row['proveedor_id_externo'] ?? ''));
        $supplier_sku  = trim((string) ($supplier_row['sku'] ?? ''));
        $supplier_name = trim((string) ($supplier_row['nombre'] ?? ''));
        $purchase_url  = trim((string) ($supplier_row['url_origen'] ?? ''));

        if ('' === $purchase_url) {
            $purchase_url = trim((string) ($supplier_row['url_canonica'] ?? ''));
        }

        echo '<td>' . esc_html($provider_name ?: '-') . '</td>';
        echo '<td>';
        echo '<strong>' . esc_html($external_id ?: '-') . '</strong>';
        if (!empty($supplier_row['id'])) {
            echo '<div class="description">Registro #' . esc_html((string) absint($supplier_row['id'])) . '</div>';
        }
        echo '</td>';
        echo '<td><code>' . esc_html($supplier_sku ?: '-') . '</code></td>';
        echo '<td>';
        echo esc_html($supplier_name ?: '-');
        if ('parent' === ($match['match_type'] ?? '')) {
            echo '<div class="description">Vinculado al producto padre #' . esc_html((string) absint($match['matched_id'] ?? 0)) . '</div>';
        }
        echo '</td>';
        echo '<td class="seo-logistica-actions">';
        if ('' !== $purchase_url) {
            echo '<a class="button button-primary" href="' . esc_url($purchase_url) . '" target="_blank" rel="noopener noreferrer">Comprar</a> ';
        } else {
            echo '<span class="seo-logistica-no-url">Sin URL de compra</span> ';
        }
        echo '<a class="button" href="' . esc_url(seo_logistica_catalog_url($supplier_row)) . '">Ver en cat&aacute;logo</a>';
        echo '</td>';
    }
}

if (!function_exists('seo_logistica_page')) {
    function seo_logistica_page() {
        $capability = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'seo-taxonomy'));
        }

        echo '<div class="wrap seo-logistica-wrap">';
        echo '<h1>Log&iacute;stica</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="Logistica">';
        echo '<a class="nav-tab nav-tab-active" href="' . esc_url(admin_url('admin.php?page=seo-logistica')) . '">Gesti&oacute;n de pedidos</a>';
        echo '<a class="nav-tab" href="' . esc_url(admin_url('admin.php?page=seo-transporte')) . '">Transporte</a>';
        echo '</nav>';
        echo '<p>Pedidos activos de WooCommerce cruzados en tiempo real con <code>' . esc_html(seo_logistica_supplier_table()) . '</code>. Esta pantalla no crea tablas ni guarda estados propios.</p>';

        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            echo '<div class="notice notice-error inline"><p><strong>WooCommerce no est&aacute; disponible.</strong> Log&iacute;stica necesita WooCommerce activo para leer los pedidos.</p></div>';
            echo '</div>';
            return;
        }

        if (!seo_logistica_supplier_table_exists()) {
            echo '<div class="notice notice-error inline"><p><strong>No existe la tabla de proveedores.</strong> Se esperaba <code>' . esc_html(seo_logistica_supplier_table()) . '</code>.</p></div>';
            echo '</div>';
            return;
        }

        $dataset      = seo_logistica_collect_order_lines();
        $supplier_map = seo_logistica_load_supplier_map($dataset['object_ids']);
        $missing      = 0;
        $matches      = 0;

        foreach ($dataset['lines'] as $line) {
            $match = seo_logistica_supplier_matches($line, $supplier_map);
            if (empty($match['rows'])) {
                $missing++;
            } else {
                $matches += count($match['rows']);
            }
        }

        echo '<div class="seo-logistica-summary">';
        echo '<div><strong>' . esc_html((string) count($dataset['orders'])) . '</strong><span>Pedidos activos</span></div>';
        echo '<div><strong>' . esc_html((string) count($dataset['lines'])) . '</strong><span>L&iacute;neas de pedido</span></div>';
        echo '<div><strong>' . esc_html((string) $matches) . '</strong><span>Coincidencias proveedor</span></div>';
        echo '<div><strong>' . esc_html((string) $missing) . '</strong><span>Sin asociar</span></div>';
        echo '</div>';

        if (empty($dataset['lines'])) {
            echo '<div class="notice notice-info inline"><p>No hay pedidos pendientes, en espera o procesando con productos que tramitar.</p></div>';
            echo '</div>';
            return;
        }

        echo '<div class="seo-logistica-table-scroll">';
        echo '<table class="widefat striped seo-logistica-table">';
        echo '<thead><tr>';
        echo '<th>Pedido</th>';
        echo '<th>Estado</th>';
        echo '<th>Object ID</th>';
        echo '<th>Producto WooCommerce</th>';
        echo '<th>Cant.</th>';
        echo '<th>Proveedor</th>';
        echo '<th>ID producto proveedor</th>';
        echo '<th>SKU proveedor</th>';
        echo '<th>Producto proveedor</th>';
        echo '<th>Compra</th>';
        echo '</tr></thead><tbody>';

        foreach ($dataset['lines'] as $line) {
            $order       = $line['order'];
            $order_id    = absint($order->get_id());
            $order_url   = seo_logistica_order_edit_url($order);
            $order_date  = $order->get_date_created();
            $date_label  = $order_date ? wc_format_datetime($order_date, 'd/m/Y H:i') : '';
            $status      = sanitize_key((string) $order->get_status());
            $status_name = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status;
            $match       = seo_logistica_supplier_matches($line, $supplier_map);
            $rows        = !empty($match['rows']) ? $match['rows'] : array(null);

            foreach ($rows as $index => $supplier_row) {
                $row_class = empty($match['rows']) ? ' class="seo-logistica-missing"' : '';
                echo '<tr' . $row_class . '>';

                echo '<td>';
                if ($order_url) {
                    echo '<a href="' . esc_url($order_url) . '"><strong>#' . esc_html((string) $order_id) . '</strong></a>';
                } else {
                    echo '<strong>#' . esc_html((string) $order_id) . '</strong>';
                }
                if ($date_label) {
                    echo '<div class="description">' . esc_html($date_label) . '</div>';
                }
                if ($index > 0) {
                    echo '<div class="description">Otra coincidencia</div>';
                }
                echo '</td>';

                echo '<td><span class="seo-logistica-order-status seo-logistica-order-status--' . esc_attr($status) . '">' . esc_html($status_name) . '</span></td>';
                echo '<td><code>' . esc_html((string) absint($line['object_id'])) . '</code>';
                if (!empty($line['variation_id'])) {
                    echo '<div class="description">Variaci&oacute;n</div>';
                }
                echo '</td>';

                echo '<td>';
                $edit_product_id = absint($line['product_id']);
                $product_url     = $edit_product_id ? get_edit_post_link($edit_product_id, '') : '';
                if ($product_url) {
                    echo '<a href="' . esc_url($product_url) . '"><strong>' . esc_html($line['name']) . '</strong></a>';
                } else {
                    echo '<strong>' . esc_html($line['name']) . '</strong>';
                }
                if (!empty($line['sku'])) {
                    echo '<div class="description">SKU Woo: <code>' . esc_html($line['sku']) . '</code></div>';
                }
                echo '</td>';

                echo '<td>' . esc_html(wc_format_decimal($line['quantity'])) . '</td>';

                if (is_array($supplier_row)) {
                    seo_logistica_render_supplier_cells($supplier_row, $match);
                } else {
                    echo '<td colspan="5"><strong>Sin producto asociado en proveedores.</strong> No existe ninguna fila con <code>object_id = ' . esc_html((string) absint($line['object_id'])) . '</code>';
                    if (!empty($line['variation_id']) && !empty($line['product_id'])) {
                        echo ' ni con el producto padre <code>' . esc_html((string) absint($line['product_id'])) . '</code>';
                    }
                    echo '.</td>';
                }

                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '<p class="description">Se muestran pedidos en estado Pendiente de pago, En espera y Procesando. Al completar, cancelar, fallar o reembolsar un pedido, deja de aparecer en esta bandeja.</p>';
        echo '</div>';
    }
}

add_action('admin_head', static function () {
    if (!is_admin()) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ('seo-logistica' !== $page) {
        return;
    }
    ?>
    <style>
        .seo-logistica-wrap .nav-tab-wrapper { margin-bottom: 14px; }
        .seo-logistica-summary { display:grid; grid-template-columns:repeat(4,minmax(140px,1fr)); gap:12px; margin:18px 0; max-width:980px; }
        .seo-logistica-summary > div { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:14px 16px; }
        .seo-logistica-summary strong { display:block; font-size:24px; line-height:1.1; margin-bottom:5px; }
        .seo-logistica-summary span { color:#646970; }
        .seo-logistica-table-scroll { overflow-x:auto; margin-top:16px; }
        .seo-logistica-table { min-width:1220px; }
        .seo-logistica-table th { white-space:nowrap; }
        .seo-logistica-table td { vertical-align:top; }
        .seo-logistica-table code { white-space:nowrap; }
        .seo-logistica-actions { white-space:nowrap; }
        .seo-logistica-missing td { background:#fff8e5 !important; }
        .seo-logistica-order-status { display:inline-block; border-radius:999px; padding:3px 9px; background:#f0f0f1; white-space:nowrap; }
        .seo-logistica-order-status--processing { background:#e7f5ee; }
        .seo-logistica-order-status--on-hold { background:#fff8e5; }
        .seo-logistica-order-status--pending { background:#f0f0f1; }
        .seo-logistica-no-url { display:inline-block; margin:5px 6px 5px 0; color:#8a2424; }
        @media (max-width: 900px) { .seo-logistica-summary { grid-template-columns:repeat(2,minmax(140px,1fr)); } }
    </style>
    <?php
});
