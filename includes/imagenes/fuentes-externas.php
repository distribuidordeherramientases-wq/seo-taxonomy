<?php
/**
 * Fuentes externas para la limpieza de Media.
 *
 * Una fuente externa es cualquier catálogo que pueda devolver, como mínimo,
 * una URL de imagen. El catálogo canónico de proveedores es la fuente por
 * defecto, pero otros módulos pueden registrar un CDN, DAM o servidor de
 * imágenes mediante el filtro seo_images_cleanup_external_sources.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_images_cleanup_external_sources')) {
    /**
     * Devuelve las fuentes externas de imágenes disponibles.
     *
     * Formato de una fuente tipo table:
     * - key: identificador estable.
     * - label: nombre visible.
     * - type: table.
     * - table: nombre físico de la tabla.
     * - id_column: clave incremental usada para paginar.
     * - url_column: columna URL de imagen.
     * - provider_column: columna opcional de proveedor/origen.
     * - product_id_column: columna opcional de producto WordPress.
     * - status_column / active_value: filtro opcional de filas activas.
     *
     * Otros módulos pueden sustituir o ampliar este array mediante:
     * add_filter('seo_images_cleanup_external_sources', ...).
     *
     * @return array<string,array<string,mixed>>
     */
    function seo_images_cleanup_external_sources() {
        $sources = array();

        if (function_exists('seo_images_table_supplier_images')) {
            $table = seo_images_table_supplier_images();

            if (function_exists('seo_images_table_exists') && seo_images_table_exists($table)) {
                global $wpdb;

                $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

                if (in_array('id', $columns, true) && in_array('image_url', $columns, true)) {
                    $source = array(
                        'key'               => 'supplier_images',
                        'label'             => 'Imágenes externas de proveedores',
                        'type'              => 'table',
                        'table'             => $table,
                        'id_column'         => 'id',
                        'url_column'        => 'image_url',
                        'provider_column'   => in_array('supplier', $columns, true) ? 'supplier' : '',
                        'product_id_column' => in_array('product_id', $columns, true) ? 'product_id' : '',
                        'status_column'     => in_array('status', $columns, true) ? 'status' : '',
                        'active_value'      => 'active',
                    );

                    $sources[$source['key']] = $source;
                }
            }
        }

        /**
         * Permite registrar catálogos externos adicionales.
         *
         * Un servidor externo puede actuar como una fuente siempre que exista
         * un catálogo consultable de sus URLs. La ubicación física del fichero
         * (CDN, servidor de proveedor, DAM, etc.) no cambia el algoritmo.
         *
         * @param array $sources Fuentes ya detectadas.
         */
        $sources = apply_filters('seo_images_cleanup_external_sources', $sources);

        if (!is_array($sources)) {
            return array();
        }

        $normalized = array();

        foreach ($sources as $key => $source) {
            if (!is_array($source)) {
                continue;
            }

            $source_key = isset($source['key']) ? sanitize_key($source['key']) : sanitize_key($key);
            if ($source_key === '') {
                continue;
            }

            $source['key']   = $source_key;
            $source['label'] = isset($source['label'])
                ? sanitize_text_field((string) $source['label'])
                : $source_key;
            $source['type'] = isset($source['type']) ? sanitize_key($source['type']) : 'table';

            if ($source['type'] !== 'table' && empty($source['rows_callback'])) {
                continue;
            }

            $normalized[$source_key] = $source;
        }

        return $normalized;
    }
}

if (!function_exists('seo_images_cleanup_source_table_identifier')) {
    /**
     * Valida un identificador de tabla/columna procedente de una definición
     * interna o de un filtro. Nunca acepta expresiones SQL.
     *
     * @param mixed $identifier Identificador.
     * @return string
     */
    function seo_images_cleanup_source_table_identifier($identifier) {
        $identifier = (string) $identifier;
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) ? $identifier : '';
    }
}

if (!function_exists('seo_images_cleanup_source_count')) {
    /**
     * Cuenta las filas utilizables de una fuente.
     *
     * @param array $source Definición de fuente.
     * @return int
     */
    function seo_images_cleanup_source_count(array $source) {
        if (isset($source['count_callback']) && is_callable($source['count_callback'])) {
            return max(0, (int) call_user_func($source['count_callback'], $source));
        }

        if (($source['type'] ?? '') !== 'table') {
            return 0;
        }

        global $wpdb;

        $table = seo_images_cleanup_source_table_identifier($source['table'] ?? '');
        $url   = seo_images_cleanup_source_table_identifier($source['url_column'] ?? '');

        if ($table === '' || $url === '') {
            return 0;
        }

        $where = "{$url} IS NOT NULL AND TRIM({$url}) <> ''";
        $status_column = seo_images_cleanup_source_table_identifier($source['status_column'] ?? '');

        if ($status_column !== '' && isset($source['active_value'])) {
            $where .= $wpdb->prepare(" AND {$status_column} = %s", (string) $source['active_value']);
        }

        return max(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}"));
    }
}

if (!function_exists('seo_images_cleanup_source_rows')) {
    /**
     * Obtiene un lote normalizado de una fuente.
     *
     * @param array $source Definición.
     * @param mixed $cursor Cursor de la fuente.
     * @param int   $limit  Tamaño de lote.
     * @return array{rows:array<int,array<string,mixed>>,cursor:mixed,done:bool}
     */
    function seo_images_cleanup_source_rows(array $source, $cursor, $limit) {
        $limit = max(1, min(5000, absint($limit)));

        if (isset($source['rows_callback']) && is_callable($source['rows_callback'])) {
            $result = call_user_func($source['rows_callback'], $cursor, $limit, $source);
            if (!is_array($result)) {
                return array('rows' => array(), 'cursor' => $cursor, 'done' => true);
            }

            return array(
                'rows'   => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array(),
                'cursor' => $result['cursor'] ?? $cursor,
                'done'   => !empty($result['done']),
            );
        }

        if (($source['type'] ?? '') !== 'table') {
            return array('rows' => array(), 'cursor' => $cursor, 'done' => true);
        }

        global $wpdb;

        $table     = seo_images_cleanup_source_table_identifier($source['table'] ?? '');
        $id_col    = seo_images_cleanup_source_table_identifier($source['id_column'] ?? '');
        $url_col   = seo_images_cleanup_source_table_identifier($source['url_column'] ?? '');
        $prov_col  = seo_images_cleanup_source_table_identifier($source['provider_column'] ?? '');
        $prod_col  = seo_images_cleanup_source_table_identifier($source['product_id_column'] ?? '');
        $status_col= seo_images_cleanup_source_table_identifier($source['status_column'] ?? '');

        if ($table === '' || $id_col === '' || $url_col === '') {
            return array('rows' => array(), 'cursor' => $cursor, 'done' => true);
        }

        $select = array(
            "{$id_col} AS source_row_key",
            "{$url_col} AS image_url",
        );
        $select[] = $prov_col !== '' ? "{$prov_col} AS provider" : "'' AS provider";
        $select[] = $prod_col !== '' ? "{$prod_col} AS product_id" : 'NULL AS product_id';

        $where = "{$id_col} > %d AND {$url_col} IS NOT NULL AND TRIM({$url_col}) <> ''";
        $params = array(max(0, (int) $cursor));

        if ($status_col !== '' && isset($source['active_value'])) {
            $where .= " AND {$status_col} = %s";
            $params[] = (string) $source['active_value'];
        }

        $params[] = $limit;
        $sql = "SELECT " . implode(', ', $select) . "
                FROM {$table}
                WHERE {$where}
                ORDER BY {$id_col} ASC
                LIMIT %d";

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $next_cursor = $cursor;

        foreach ($rows as &$row) {
            $next_cursor = max((int) $next_cursor, (int) ($row['source_row_key'] ?? 0));
            $row['source_row_key'] = (string) ($row['source_row_key'] ?? '');
            $row['provider']       = sanitize_text_field((string) ($row['provider'] ?? ''));
            $row['product_id']     = absint($row['product_id'] ?? 0);
            $row['image_url']      = (string) ($row['image_url'] ?? '');
        }
        unset($row);

        return array(
            'rows'   => $rows,
            'cursor' => $next_cursor,
            'done'   => count($rows) < $limit,
        );
    }
}
