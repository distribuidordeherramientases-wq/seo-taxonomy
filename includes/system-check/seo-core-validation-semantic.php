<?php
/**
 * SEO System - Semantic and Editorial Health Tests
 *
 * Auditoria de solo lectura para alinear productos, categorias y FAQs.
 * No modifica entradas, terminos, atributos, etiquetas, FAQs ni relaciones.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_CORE_SEMANTIC_TEST_VERSION')) {
    define('SEO_CORE_SEMANTIC_TEST_VERSION', '2.5.0');
}

/**
 * Comprueba si existe una tabla.
 */
function seo_core_system_test_semantic_table_exists($table) {
    global $wpdb;

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $found === $table;
}

/**
 * Comprueba si una tabla contiene una columna concreta.
 *
 * Se usa para tolerar instalaciones antiguas con metadatos legacy sin
 * convertirlos en una dependencia del esquema actual.
 */
function seo_core_system_test_semantic_table_has_column($table, $column) {
    global $wpdb;

    if (!seo_core_system_test_semantic_table_exists($table)) {
        return false;
    }

    $found = $wpdb->get_var(
        $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", (string) $column)
    );

    return (string) $found === (string) $column;
}


/**
 * Inventario agregado de fuentes de imagen por producto.
 *
 * No realiza peticiones HTTP masivas. Usa la integridad del attachment local
 * y el ultimo estado HTTP persistido por seo_supplier_images. Los estados
 * temporales o aun no verificados se separan para no convertirlos en fallos.
 */
function seo_core_system_test_semantic_product_image_inventory($product_ids) {
    global $wpdb;

    $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
    $states = array();
    foreach ($product_ids as $product_id) {
        $states[$product_id] = array(
            'local' => false,
            'external_registered' => false,
            'external_validated' => false,
            'external_retry' => false,
            'external_missing' => false,
        );
    }
    if (!$product_ids) {
        return $states;
    }

    // Attachment local realmente existente.
    $local_ids = (array) $wpdb->get_col(
        "SELECT DISTINCT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p
            ON p.ID = pm.post_id
           AND p.post_type = 'product'
           AND p.post_status NOT IN ('auto-draft','trash')
         INNER JOIN {$wpdb->posts} a
            ON a.ID = CAST(pm.meta_value AS UNSIGNED)
           AND a.post_type = 'attachment'
         WHERE pm.meta_key = '_thumbnail_id'
           AND pm.meta_value IS NOT NULL
           AND pm.meta_value <> ''
           AND pm.meta_value <> '0'"
    );
    foreach ($local_ids as $product_id) {
        $product_id = absint($product_id);
        if (isset($states[$product_id])) {
            $states[$product_id]['local'] = true;
        }
    }

    $table = $wpdb->prefix . 'seo_supplier_images';
    if (!seo_core_system_test_semantic_table_exists($table)) {
        return $states;
    }

    $link_columns = array();
    foreach (array('product_id', 'object_id') as $column) {
        if (seo_core_system_test_semantic_table_has_column($table, $column)) {
            $link_columns[] = $column;
        }
    }
    if (!$link_columns || !seo_core_system_test_semantic_table_has_column($table, 'image_url')) {
        return $states;
    }

    $has_status = seo_core_system_test_semantic_table_has_column($table, 'status');
    $has_http = seo_core_system_test_semantic_table_has_column($table, 'http_status');

    foreach ($link_columns as $column) {
        $http_select = $has_http
            ? "MAX(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS has_valid,\n"
              . "MAX(CASE WHEN http_status IS NULL OR http_status = 0 OR http_status IN (408,425,429) OR http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS has_retry,\n"
              . "MAX(CASE WHEN http_status IN (404,410) THEN 1 ELSE 0 END) AS has_missing"
            : "0 AS has_valid, 1 AS has_retry, 0 AS has_missing";
        $status_where = $has_status ? "AND status = 'active'" : '';
        // $column procede de una lista blanca fija.
        $rows = $wpdb->get_results(
            "SELECT {$column} AS linked_product_id,\n"
            . "       1 AS has_registered,\n"
            . "       {$http_select}\n"
            . "FROM {$table}\n"
            . "WHERE {$column} IS NOT NULL\n"
            . "  AND {$column} > 0\n"
            . "  {$status_where}\n"
            . "  AND image_url IS NOT NULL\n"
            . "  AND TRIM(image_url) <> ''\n"
            . "GROUP BY {$column}",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $product_id = absint($row['linked_product_id'] ?? 0);
            if (!isset($states[$product_id])) {
                continue;
            }
            $states[$product_id]['external_registered'] = true;
            $states[$product_id]['external_validated'] = $states[$product_id]['external_validated'] || !empty($row['has_valid']);
            $states[$product_id]['external_retry'] = $states[$product_id]['external_retry'] || !empty($row['has_retry']);
            $states[$product_id]['external_missing'] = $states[$product_id]['external_missing'] || !empty($row['has_missing']);
        }
    }

    return $states;
}

/**
 * Detecta preguntas casi identicas dentro del mismo objeto sin comparar todo
 * el catalogo contra todo el catalogo. Solo se comparan FAQs activas del mismo
 * object_type + object_id y se excluyen los duplicados exactos.
 */
function seo_core_system_test_semantic_near_duplicate_faq_questions($rows, $threshold = 92.0, $example_limit = 12) {
    $by_object = array();
    foreach ((array) $rows as $row) {
        if (empty($row['active_bool'])) {
            continue;
        }
        $normalized = seo_core_system_test_semantic_normalize_text($row['question'] ?? '');
        if (strlen($normalized) < 12) {
            continue;
        }
        $key = (int) ($row['object_type'] ?? 0) . ':' . (int) ($row['object_id'] ?? 0);
        $by_object[$key][] = array(
            'id' => absint($row['id'] ?? 0),
            'question' => (string) ($row['question'] ?? ''),
            'normalized' => $normalized,
        );
    }

    $pairs = 0;
    $affected = array();
    $examples = array();
    foreach ($by_object as $object_key => $questions) {
        $count = count($questions);
        if ($count < 2) {
            continue;
        }
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $questions[$i]['normalized'];
                $b = $questions[$j]['normalized'];
                if ($a === $b) {
                    continue;
                }
                $max_len = max(strlen($a), strlen($b));
                $min_len = min(strlen($a), strlen($b));
                if ($max_len <= 0 || ($min_len / $max_len) < 0.75) {
                    continue;
                }
                similar_text($a, $b, $percent);
                if ($percent < $threshold) {
                    continue;
                }
                $pairs++;
                $affected[$questions[$i]['id']] = true;
                $affected[$questions[$j]['id']] = true;
                if (count($examples) < $example_limit) {
                    $examples[] = array(
                        'object' => $object_key,
                        'faq_ids' => array($questions[$i]['id'], $questions[$j]['id']),
                        'similarity_percent' => round($percent, 1),
                        'question_a' => seo_core_system_test_semantic_preview($questions[$i]['question'], 140),
                        'question_b' => seo_core_system_test_semantic_preview($questions[$j]['question'], 140),
                    );
                }
            }
        }
    }

    return array(
        'pairs' => $pairs,
        'affected_rows' => count($affected),
        'examples' => $examples,
    );
}

/**
 * Normaliza texto para comparaciones editoriales y deteccion de duplicados.
 */
function seo_core_system_test_semantic_normalize_text($text) {
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = wp_strip_all_tags($text);
    $text = str_replace(array("\xC2\xA0", "\r", "\n", "\t"), ' ', $text);
    $text = remove_accents($text);

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return trim((string) $text);
}

/**
 * Devuelve una version legible y corta de un texto.
 */
function seo_core_system_test_semantic_preview($text, $limit = 180) {
    $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return mb_substr($text, 0, $limit, 'UTF-8') . '...';
    }

    if (strlen($text) > $limit) {
        return substr($text, 0, $limit) . '...';
    }

    return $text;
}

/**
 * Cuenta palabras visibles.
 */
function seo_core_system_test_semantic_word_count($text) {
    $text = trim(wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    if ($text === '') {
        return 0;
    }

    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    return is_array($words) ? count($words) : 0;
}

/**
 * Devuelve los ambitos admitidos por el importador.
 */
function seo_core_system_test_semantic_allowed_scopes() {
    if (function_exists('seo_ie_allowed_ambitos')) {
        return array_values(array_unique(array_map('sanitize_key', (array) seo_ie_allowed_ambitos())));
    }

    return array('accesorio', 'herramienta', 'repuesto', 'equipamiento', 'consumible');
}

/**
 * Valida un ambito. El valor vacio es admisible en contenido aun no clasificado.
 */
function seo_core_system_test_semantic_scope_is_valid($scope, $allow_empty = true, $allow_global = false) {
    $scope = sanitize_key(remove_accents(trim((string) $scope)));

    if ($scope === '') {
        return (bool) $allow_empty;
    }

    if ($allow_global && $scope === 'global') {
        return true;
    }

    return in_array($scope, seo_core_system_test_semantic_allowed_scopes(), true);
}

/**
 * Separa etiquetas CSV y elimina vacios.
 */
function seo_core_system_test_semantic_split_tags($tags) {
    $items = array();

    foreach (explode(',', (string) $tags) as $item) {
        $item = trim(wp_strip_all_tags($item));
        if ($item !== '') {
            $items[] = $item;
        }
    }

    return $items;
}

/**
 * Busca cualquiera de los patrones indicados.
 */
function seo_core_system_test_semantic_matches_patterns($text, $patterns) {
    $text = (string) $text;

    foreach ((array) $patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}


/**
 * Tipos de atributo que pueden compartir legitimamente el mismo identificador
 * comercial que SKU/MPN/modelo. La coincidencia por si sola es informativa.
 */
function seo_core_system_test_semantic_attribute_is_identifier($attribute_type) {
    $attribute_type = sanitize_key(remove_accents((string) $attribute_type));

    return in_array($attribute_type, array(
        'modelo', 'model', 'sku', 'mpn', 'referencia', 'reference',
        'referencia_fabricante', 'manufacturer_reference', 'part_number',
    ), true);
}

/**
 * Detecta la familia de unidad expresada de forma inequívoca en un valor.
 * Si aparecen varias familias, devuelve "mixed" para no sobrediagnosticar.
 */
function seo_core_system_test_semantic_attribute_unit_family($value) {
    $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $families = array();
    $patterns = array(
        'current' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:ma|a|amperios?)\b/iu',
        'voltage' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:mv|kv|v|voltios?)\b/iu',
        'power' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:mw|kw|w|vatios?)\b/iu',
        'weight' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:mg|kg|g|gramos?|kilogramos?)\b/iu',
        'frequency' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:hz|khz|mhz|ghz)\b/iu',
        'temperature' => '/[-+]?\d+(?:[.,]\d+)?\s*(?:°\s*)?(?:c|f)\b/iu',
    );

    foreach ($patterns as $family => $pattern) {
        if (preg_match($pattern, $value)) {
            $families[] = $family;
        }
    }

    $families = array_values(array_unique($families));
    if (count($families) === 1) {
        return $families[0];
    }
    if (count($families) > 1) {
        return 'mixed';
    }

    return '';
}

/**
 * Devuelve la familia de unidad esperada para atributos tecnicos comunes.
 */
function seo_core_system_test_semantic_attribute_expected_unit_family($attribute_type) {
    $attribute_type = sanitize_key(remove_accents((string) $attribute_type));
    $map = array(
        'corriente' => 'current',
        'amperaje' => 'current',
        'tension' => 'voltage',
        'voltaje' => 'voltage',
        'voltage' => 'voltage',
        'potencia' => 'power',
        'power' => 'power',
        'peso' => 'weight',
        'weight' => 'weight',
        'frecuencia' => 'frequency',
        'frequency' => 'frequency',
        'temperatura' => 'temperature',
        'temperature' => 'temperature',
    );

    return $map[$attribute_type] ?? '';
}

/**
 * Solo declara error semantico cuando hay una unidad explicita e incompatible.
 * Valores sin unidad o con varias magnitudes quedan fuera de esta regla.
 */
function seo_core_system_test_semantic_attribute_unit_error($attribute_type, $attribute_value) {
    $expected = seo_core_system_test_semantic_attribute_expected_unit_family($attribute_type);
    if ($expected === '') {
        return '';
    }

    $actual = seo_core_system_test_semantic_attribute_unit_family($attribute_value);
    if ($actual === '' || $actual === 'mixed' || $actual === $expected) {
        return '';
    }

    return 'Unidad incompatible con el tipo de atributo: se esperaba ' . $expected . ' y se detectó ' . $actual . '.';
}

/**
 * Carga la fila activa mas reciente de cada rol de wp_seo_nodes.
 */
function seo_core_system_test_semantic_latest_nodes($object_type, $roles) {
    global $wpdb;

    $table = $wpdb->prefix . 'seo_nodes';
    if (!seo_core_system_test_semantic_table_exists($table)) {
        return array();
    }

    $roles = array_values(array_filter(array_map('sanitize_key', (array) $roles)));
    if (!$roles) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($roles), '%s'));
    $params = array_merge(array(sanitize_key($object_type)), $roles);
    $query = $wpdb->prepare(
        "SELECT id, object_id, seo_role, keywords, status, updated_at
         FROM {$table}
         WHERE object_type = %s
           AND seo_role IN ({$placeholders})
           AND status = 1
         ORDER BY object_id ASC, seo_role ASC, updated_at DESC, id DESC",
        $params
    );

    $rows = $wpdb->get_results($query, ARRAY_A);
    $result = array();

    foreach ((array) $rows as $row) {
        $object_id = absint($row['object_id'] ?? 0);
        $role = sanitize_key((string) ($row['seo_role'] ?? ''));

        if ($object_id <= 0 || $role === '') {
            continue;
        }

        if (!isset($result[$object_id])) {
            $result[$object_id] = array();
        }

        if (!array_key_exists($role, $result[$object_id])) {
            $result[$object_id][$role] = (string) ($row['keywords'] ?? '');
        }
    }

    return $result;
}

/**
 * Carga las asignaciones canonicas de Vocabulary de categorias WooCommerce.
 *
 * Las etiquetas semanticas de product_cat ya no viven en seo_nodes. Esta
 * funcion usa exclusivamente seo_object_vocabulary -> seo_vocabulary y solo
 * considera asignaciones/terminos activos de los cinco grupos canonicos.
 */
function seo_core_system_test_semantic_category_vocabulary($category_ids = array()) {
    global $wpdb;

    $object_table = $wpdb->prefix . 'seo_object_vocabulary';
    $vocab_table = $wpdb->prefix . 'seo_vocabulary';
    $allowed_groups = array('rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo');

    $result = array(
        'available' => seo_core_system_test_semantic_table_exists($object_table)
            && seo_core_system_test_semantic_table_exists($vocab_table),
        'assignments' => 0,
        'objects' => array(),
    );

    if (!$result['available']) {
        return $result;
    }

    $category_ids = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));
    $where_ids = '';
    if ($category_ids) {
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $where_ids = $wpdb->prepare(" AND ov.object_id IN ({$placeholders})", ...$category_ids);
    }

    $rows = $wpdb->get_results(
        "SELECT ov.object_id, ov.vocabulary_id, v.semantic_group, v.slug, v.label\n"
        . "FROM {$object_table} ov\n"
        . "INNER JOIN {$vocab_table} v ON v.id = ov.vocabulary_id\n"
        . "WHERE ov.object_type = 'product_cat'\n"
        . "  AND ov.status = 1\n"
        . "  AND v.active = 1\n"
        . "  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')\n"
        . $where_ids
        . " ORDER BY ov.object_id ASC, FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), v.label ASC, v.id ASC",
        ARRAY_A
    );

    foreach ((array) $rows as $row) {
        $object_id = absint($row['object_id'] ?? 0);
        $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));

        if ($object_id < 1 || !in_array($group, $allowed_groups, true) || $label === '') {
            continue;
        }

        if (!isset($result['objects'][$object_id])) {
            $result['objects'][$object_id] = array(
                'labels' => array(),
                'groups' => array(),
                'roles' => array(),
            );
        }

        $result['assignments']++;
        $result['objects'][$object_id]['labels'][$group . ':' . seo_core_system_test_semantic_normalize_text($label)] = $label;
        if (!isset($result['objects'][$object_id]['groups'][$group])) {
            $result['objects'][$object_id]['groups'][$group] = array();
        }
        $result['objects'][$object_id]['groups'][$group][] = $label;

        if ($group === 'rol') {
            $role = trim((string) ($row['slug'] ?? ''));
            if ($role === '') {
                $role = $label;
            }
            $role = sanitize_key(remove_accents(function_exists('mb_strtolower') ? mb_strtolower($role, 'UTF-8') : strtolower($role)));
            if ($role !== '') {
                $result['objects'][$object_id]['roles'][$role] = true;
            }
        }
    }

    foreach ($result['objects'] as &$object) {
        $object['labels'] = array_values($object['labels']);
        $object['roles'] = array_keys($object['roles']);
        sort($object['roles'], SORT_STRING);
        foreach ($object['groups'] as &$labels) {
            $labels = array_values(array_unique(array_filter(array_map('trim', (array) $labels))));
        }
        unset($labels);
    }
    unset($object);

    return $result;
}

/**
 * Calcula duplicados normalizados. Puede limitar el grupo al mismo objeto.
 */
function seo_core_system_test_semantic_duplicate_stats($rows, $value_key, $scope_keys, $id_key, $active_key = '', $min_chars = 12, $example_limit = 8) {
    $groups = array();

    foreach ((array) $rows as $row) {
        $value = isset($row[$value_key]) ? (string) $row[$value_key] : '';
        $normalized = seo_core_system_test_semantic_normalize_text($value);

        if (strlen($normalized) < $min_chars) {
            continue;
        }

        $scope = array();
        foreach ((array) $scope_keys as $scope_key) {
            $scope[] = isset($row[$scope_key]) ? (string) $row[$scope_key] : '';
        }

        $key = implode(':', $scope) . ':' . sha1($normalized);

        if (!isset($groups[$key])) {
            $groups[$key] = array(
                'normalized' => $normalized,
                'preview' => seo_core_system_test_semantic_preview($value),
                'ids' => array(),
                'active_ids' => array(),
                'scope' => $scope,
            );
        }

        $id = isset($row[$id_key]) ? (string) $row[$id_key] : '';
        if ($id !== '') {
            $groups[$key]['ids'][] = $id;
            if ($active_key !== '' && !empty($row[$active_key])) {
                $groups[$key]['active_ids'][] = $id;
            }
        }
    }

    $stats = array(
        'groups' => 0,
        'affected_rows' => 0,
        'extra_rows' => 0,
        'active_affected_rows' => 0,
        'active_extra_rows' => 0,
        'examples' => array(),
    );

    foreach ($groups as $group) {
        $ids = array_values(array_unique($group['ids']));
        if (count($ids) < 2) {
            continue;
        }

        $active_ids = array_values(array_unique($group['active_ids']));
        $stats['groups']++;
        $stats['affected_rows'] += count($ids);
        $stats['extra_rows'] += count($ids) - 1;

        if (count($active_ids) > 1) {
            $stats['active_affected_rows'] += count($active_ids);
            $stats['active_extra_rows'] += count($active_ids) - 1;
        }

        if (count($stats['examples']) < $example_limit) {
            $stats['examples'][] = array(
                'scope' => $group['scope'],
                'preview' => $group['preview'],
                'ids' => array_slice($ids, 0, 12),
                'active_ids' => array_slice($active_ids, 0, 12),
                'total' => count($ids),
            );
        }
    }

    return $stats;
}

/**
 * Extrae palabras significativas para una comprobacion conservadora de familia.
 */
function seo_core_system_test_semantic_tokens($text) {
    $normalized = seo_core_system_test_semantic_normalize_text($text);
    if ($normalized === '') {
        return array();
    }

    $stop = array_flip(array(
        'de', 'del', 'la', 'las', 'el', 'los', 'y', 'o', 'para', 'con', 'sin', 'por', 'en',
        'un', 'una', 'unos', 'unas', 'producto', 'productos', 'accesorio', 'accesorios',
        'herramienta', 'herramientas', 'equipo', 'equipos', 'repuesto', 'repuestos', 'recambio',
        'recambios', 'general', 'varios', 'otros', 'profesional', 'profesionales', 'satkit', 'vevor'
    ));

    $tokens = array();
    foreach (explode(' ', $normalized) as $token) {
        if ($token === '' || isset($stop[$token]) || strlen($token) < 4) {
            continue;
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

/**
 * Variantes lexicas conservadoras de un token para comparar singular/plural
 * y algunas derivaciones frecuentes sin depender de la profundidad taxonomica.
 */
function seo_core_system_test_semantic_token_variants($token) {
    $token = seo_core_system_test_semantic_normalize_text($token);
    if ($token === '' || strpos($token, ' ') !== false) {
        return $token === '' ? array() : array($token);
    }

    $variants = array($token => true);
    $length = strlen($token);

    // Plurales espanoles frecuentes. Se conservan todas las variantes para
    // no convertir una regla linguistica imperfecta en una decision destructiva.
    if ($length > 4 && substr($token, -1) === 's') {
        $variants[substr($token, 0, -1)] = true;
    }
    if ($length > 5 && substr($token, -2) === 'es') {
        $variants[substr($token, 0, -2)] = true;
    }
    if ($length > 5 && substr($token, -3) === 'ces') {
        $variants[substr($token, 0, -3) . 'z'] = true;
    }
    if ($length > 7 && substr($token, -5) === 'iones') {
        $variants[substr($token, 0, -5) . 'ion'] = true;
    }

    // Derivaciones comunes utiles para categorias: limpieza/limpiador,
    // soldadura/soldador, etc. Solo se aceptan raices de al menos 5 letras.
    foreach (array(
        'adores', 'adoras', 'ador', 'adora',
        'aciones', 'acion', 'amientos', 'amiento', 'imientos', 'imiento',
        'aduras', 'adura', 'ezas', 'eza', 'erias', 'eria',
        'icos', 'icas', 'ico', 'ica'
    ) as $suffix) {
        if (strlen($token) <= strlen($suffix) + 4 || substr($token, -strlen($suffix)) !== $suffix) {
            continue;
        }
        $root = substr($token, 0, -strlen($suffix));
        if (strlen($root) >= 5) {
            $variants[$root] = true;
        }
    }

    return array_values(array_filter(array_keys($variants), static function ($value) {
        return strlen((string) $value) >= 4;
    }));
}

/**
 * Interseccion semantica ligera de tokens. Devuelve tokens del lado izquierdo
 * que encuentran una variante equivalente en el derecho.
 */
function seo_core_system_test_semantic_token_overlap($left, $right) {
    $right_variants = array();
    foreach ((array) $right as $token) {
        foreach (seo_core_system_test_semantic_token_variants($token) as $variant) {
            $right_variants[$variant] = true;
        }
    }

    $matched = array();
    foreach ((array) $left as $token) {
        foreach (seo_core_system_test_semantic_token_variants($token) as $variant) {
            if (isset($right_variants[$variant])) {
                $matched[(string) $token] = true;
                break;
            }
        }
    }

    return array_keys($matched);
}

/**
 * Interseccion literal usada solo para conservar la amplitud del detector
 * historico como dato de auditoria, nunca como indicador de salud.
 */
function seo_core_system_test_semantic_token_overlap_exact($left, $right) {
    return array_values(array_intersect((array) $left, (array) $right));
}

/**
 * Extrae la categoria hoja de una ruta textual de proveedor.
 */
function seo_core_system_test_semantic_supplier_leaf($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    $parts = preg_split('/\s*(?:>|»|›|\|)\s*/u', $path, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || count($parts) <= 1) {
        $parts = preg_split('/\s+\/\s+/u', $path, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($parts) || !$parts) {
        return $path;
    }

    return trim((string) end($parts));
}

/**
 * Devuelve solo las categorias hoja entre las categorias realmente asignadas
 * al producto. Si solo hay una asignada, esa es la hoja operativa.
 */
function seo_core_system_test_semantic_assigned_leaf_categories($categories, $parent_map) {
    $categories = (array) $categories;
    if (!$categories) {
        return array();
    }

    $assigned = array_fill_keys(array_map('absint', array_keys($categories)), true);
    $non_leaf = array();

    foreach (array_keys($assigned) as $term_id) {
        $parent = absint($parent_map[$term_id] ?? 0);
        $guard = 0;
        while ($parent > 0 && $guard < 50) {
            if (isset($assigned[$parent])) {
                $non_leaf[$parent] = true;
            }
            $parent = absint($parent_map[$parent] ?? 0);
            $guard++;
        }
    }

    $leaf = array();
    foreach ($categories as $term_id => $name) {
        $term_id = absint($term_id);
        if ($term_id > 0 && !isset($non_leaf[$term_id])) {
            $leaf[$term_id] = (string) $name;
        }
    }

    return $leaf ?: $categories;
}

/**
 * Indica si una categoria es ancestro de otra dentro de product_cat.
 */
function seo_core_system_test_semantic_category_is_ancestor($ancestor_id, $descendant_id, $parent_map) {
    $ancestor_id = absint($ancestor_id);
    $current = absint($descendant_id);
    if ($ancestor_id <= 0 || $current <= 0 || $ancestor_id === $current) {
        return $ancestor_id > 0 && $ancestor_id === $current;
    }

    $guard = 0;
    while ($current > 0 && $guard < 50) {
        $current = absint($parent_map[$current] ?? 0);
        if ($current === $ancestor_id) {
            return true;
        }
        $guard++;
    }

    return false;
}

/**
 * Busca una categoria interna cuyo nombre sea una equivalencia fuerte de la
 * hoja del proveedor y que tambien este respaldada por el titulo del producto.
 * Se usa solo como evidencia adicional para contradicciones fuertes.
 */
function seo_core_system_test_semantic_best_internal_category_match($supplier_leaf_tokens, $title_tokens, $category_token_map, $category_name_map) {
    $supplier_leaf_tokens = array_values((array) $supplier_leaf_tokens);
    if (!$supplier_leaf_tokens) {
        return array();
    }

    $best = array();
    $best_score = 0.0;
    foreach ((array) $category_token_map as $term_id => $category_tokens) {
        $category_tokens = array_values((array) $category_tokens);
        if (!$category_tokens) {
            continue;
        }

        $supplier_overlap = seo_core_system_test_semantic_token_overlap($supplier_leaf_tokens, $category_tokens);
        $title_overlap = seo_core_system_test_semantic_token_overlap($title_tokens, $category_tokens);
        $overlap_count = count($supplier_overlap);
        if ($overlap_count === 0 || count($title_overlap) === 0) {
            continue;
        }

        $supplier_ratio = $overlap_count / max(1, count($supplier_leaf_tokens));
        $category_ratio = $overlap_count / max(1, count($category_tokens));
        $score = min($supplier_ratio, $category_ratio);

        // Exige equivalencia fuerte de la hoja, no mera coincidencia con una
        // palabra generica de una ruta larga.
        if ($score < 0.75) {
            continue;
        }
        if ($score > $best_score) {
            $best_score = $score;
            $best = array(
                'term_id' => absint($term_id),
                'name' => (string) ($category_name_map[$term_id] ?? ''),
                'score' => round($score, 3),
                'shared_tokens' => $supplier_overlap,
            );
        }
    }

    return $best;
}

/**
 * Crea una accion priorizada para el informe.
 */
function seo_core_system_test_semantic_action($code, $priority, $entity, $field, $count, $recommendation, $examples = array()) {
    return array(
        'code' => sanitize_key($code),
        'priority' => sanitize_key($priority),
        'entity' => sanitize_key($entity),
        'field' => sanitize_key($field),
        'count' => max(0, (int) $count),
        'recommendation' => (string) $recommendation,
        'examples' => array_slice((array) $examples, 0, 10),
    );
}

/**
 * Genera el snapshot completo de productos, categorias y FAQs.
 */
function seo_core_system_test_semantic_snapshot() {
    global $wpdb;

    $snapshot = array(
        'schema_version' => 2,
        'module_version' => SEO_CORE_SEMANTIC_TEST_VERSION,
        'generated_at' => time(),
        'categories' => array(),
        'products' => array(),
        'faqs' => array(),
        'tags' => array(),
        'structure' => array(),
        'alignment' => array(),
        'actions' => array(),
        'coverage' => array(),
        'status' => 'unknown',
        'score' => null,
        'limitations' => array(
            'Las coincidencias semanticas se consideran señales de revision, no decisiones automaticas.',
            'El modulo no consulta fichas externas de fabricantes ni modifica datos.',
            'La ausencia de una longitud concreta no se considera por si sola un error SEO.',
        ),
    );

    $allowed_scopes = seo_core_system_test_semantic_allowed_scopes();

    /* ---------------------------------------------------------------------
     * CATEGORIAS
     * ------------------------------------------------------------------ */
    // seo_nodes conserva unicamente contenido editorial de categoria.
    $category_nodes = seo_core_system_test_semantic_latest_nodes(
        'category',
        array('excerpt', 'description')
    );

    $category_rows = $wpdb->get_results(
        "SELECT t.term_id, t.name, t.slug, tt.parent, tt.count, tt.description
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'product_cat'
         ORDER BY t.term_id ASC",
        ARRAY_A
    );

    $category_meta = array();
    $category_ids = wp_list_pluck((array) $category_rows, 'term_id');
    $category_vocabulary = seo_core_system_test_semantic_category_vocabulary($category_ids);
    if ($category_ids) {
        $ids_sql = implode(',', array_map('absint', $category_ids));
        $meta_rows = $wpdb->get_results(
            "SELECT term_id, meta_key, meta_value
             FROM {$wpdb->termmeta}
             WHERE term_id IN ({$ids_sql})
               AND meta_key = 'thumbnail_id'",
            ARRAY_A
        );
        foreach ((array) $meta_rows as $row) {
            $category_meta[(int) $row['term_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }
    }

    $category_template_patterns = array(
        '/esta categor[ií]a est[aá] destinada/iu',
        '/seleccionar productos de esta familia/iu',
        '/la compatibilidad debe confirmarse/iu',
        '/la agrupaci[oó]n se basa en la funci[oó]n principal/iu',
        '/antes de elegir una referencia/iu',
        '/<h2>\s*productos de\s+/iu',
    );

    $category_entities = array();
    $category_scope_map = array();
    $category_name_map = array();
    $category_parent_map = array();
    $category_token_map = array();
    $category_ids_existing = array();
    $category_product_titles = array();

    $category_product_rows = $wpdb->get_results(
        "SELECT tt.term_id, p.ID AS product_id, p.post_title
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'product_cat'
         INNER JOIN {$wpdb->posts} p
           ON p.ID = tr.object_id
          AND p.post_type = 'product'",
        ARRAY_A
    );
    foreach ((array) $category_product_rows as $row) {
        $term_id = absint($row['term_id'] ?? 0);
        $title_key = seo_core_system_test_semantic_normalize_text($row['post_title'] ?? '');
        if ($term_id > 0 && $title_key !== '') {
            $category_product_titles[$term_id][$title_key] = true;
        }
    }

    $category_counts = array(
        'total' => count((array) $category_rows),
        'empty' => 0,
        'single_product' => 0,
        'root' => 0,
        'without_description' => 0,
        'without_excerpt' => 0,
        'without_tags' => 0,
        'vocabulary_available' => !empty($category_vocabulary['available']),
        'vocabulary_assignments' => (int) ($category_vocabulary['assignments'] ?? 0),
        'without_image' => 0,
        // Se conserva la clave por compatibilidad del informe; ahora valida ROL de Vocabulary.
        'invalid_scope' => 0,
        'template_description' => 0,
        'template_excerpt' => 0,
        'thin_description' => 0,
        'long_excerpt' => 0,
        'title_like_tags' => 0,
        'without_active_faq' => null,
        'examples' => array(),
    );

    foreach ((array) $category_rows as $row) {
        $category_id = absint($row['term_id'] ?? 0);
        $nodes = isset($category_nodes[$category_id]) ? $category_nodes[$category_id] : array();
        $name = (string) ($row['name'] ?? '');
        $description = (string) ($nodes['description'] ?? '');
        $node_excerpt = (string) ($nodes['excerpt'] ?? '');
        $vocabulary = $category_vocabulary['objects'][$category_id] ?? array();
        $tags = implode(', ', (array) ($vocabulary['labels'] ?? array()));
        // Una categoria admite 0..n ROL. El mapa conserva todos los ROL canonicos.
        $scopes = array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($vocabulary['roles'] ?? array())))));
        $count = (int) ($row['count'] ?? 0);
        $image_id = absint($category_meta[$category_id]['thumbnail_id'] ?? 0);

        $category_ids_existing[$category_id] = true;
        $category_scope_map[$category_id] = $scopes;
        $category_name_map[$category_id] = $name;
        $category_parent_map[$category_id] = absint($row['parent'] ?? 0);
        $category_token_map[$category_id] = seo_core_system_test_semantic_tokens($name);

        if ($count === 0) {
            $category_counts['empty']++;
        }
        if ($count === 1) {
            $category_counts['single_product']++;
        }
        if ((int) ($row['parent'] ?? 0) === 0) {
            $category_counts['root']++;
        }
        if (seo_core_system_test_semantic_normalize_text($description) === '') {
            $category_counts['without_description']++;
        }
        if (seo_core_system_test_semantic_normalize_text($node_excerpt) === '') {
            $category_counts['without_excerpt']++;
        }
        if (seo_core_system_test_semantic_normalize_text($tags) === '') {
            $category_counts['without_tags']++;
        }
        if ($image_id <= 0) {
            $category_counts['without_image']++;
        }
        $invalid_category_role = false;
        foreach ($scopes as $scope) {
            if (!seo_core_system_test_semantic_scope_is_valid($scope, false, false)) {
                $invalid_category_role = true;
                break;
            }
        }
        if ($invalid_category_role) {
            $category_counts['invalid_scope']++;
        }
        if (seo_core_system_test_semantic_matches_patterns($description, $category_template_patterns)) {
            $category_counts['template_description']++;
        }
        if (seo_core_system_test_semantic_matches_patterns($node_excerpt, $category_template_patterns)) {
            $category_counts['template_excerpt']++;
        }
        if ($count >= 3 && seo_core_system_test_semantic_word_count($description) > 0 && seo_core_system_test_semantic_word_count($description) < 35) {
            $category_counts['thin_description']++;
        }
        if (seo_core_system_test_semantic_word_count($node_excerpt) > 45) {
            $category_counts['long_excerpt']++;
        }

        $title_like = false;
        $seen_tags = array();
        foreach (seo_core_system_test_semantic_split_tags($tags) as $tag) {
            $tag_key = seo_core_system_test_semantic_normalize_text($tag);
            if ($tag_key === '') {
                continue;
            }
            $seen_tags[$tag_key] = true;
            if (strlen($tag_key) > 90 || isset($category_product_titles[$category_id][$tag_key])) {
                $title_like = true;
            }
        }
        if ($title_like) {
            $category_counts['title_like_tags']++;
        }

        $category_entities[] = array(
            'id' => $category_id,
            'name' => $name,
            'description' => $description,
            'excerpt' => $node_excerpt,
            'tags' => $tags,
        );
    }

    $category_counts['duplicate_descriptions'] = seo_core_system_test_semantic_duplicate_stats(
        $category_entities,
        'description',
        array(),
        'id',
        '',
        40
    );
    $category_counts['duplicate_excerpts'] = seo_core_system_test_semantic_duplicate_stats(
        $category_entities,
        'excerpt',
        array(),
        'id',
        '',
        20
    );
    $category_counts['duplicate_tag_sets'] = seo_core_system_test_semantic_duplicate_stats(
        $category_entities,
        'tags',
        array(),
        'id',
        '',
        10
    );

    $snapshot['categories'] = $category_counts;

    /* ---------------------------------------------------------------------
     * PRODUCTOS
     * ------------------------------------------------------------------ */
    $product_nodes = seo_core_system_test_semantic_latest_nodes('product', array('product', 'ambito'));

    $product_rows = $wpdb->get_results(
        "SELECT ID, post_title, post_excerpt, post_content, post_status, post_date, post_modified
         FROM {$wpdb->posts}
         WHERE post_type = 'product'
           AND post_status NOT IN ('auto-draft', 'trash')
         ORDER BY ID ASC",
        ARRAY_A
    );

    $product_ids = wp_list_pluck((array) $product_rows, 'ID');
    $canonical_product_roles = function_exists('seo_catalog_get_product_roles')
        ? seo_catalog_get_product_roles($product_ids)
        : array();
    $product_meta = array();
    if ($product_ids) {
        $product_ids_sql = implode(',', array_map('absint', $product_ids));
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$product_ids_sql})
               AND meta_key IN ('_sku', '_thumbnail_id', '_stock', '_stock_status', '_price', '_regular_price', '_sale_price', '_seo_categoria_proveedor')",
            ARRAY_A
        );
        foreach ((array) $meta_rows as $meta_row) {
            $product_meta[(int) $meta_row['post_id']][(string) $meta_row['meta_key']] = (string) $meta_row['meta_value'];
        }
    }

    $product_image_inventory = seo_core_system_test_semantic_product_image_inventory($product_ids);

    $product_category_map = array();
    foreach ((array) $category_product_rows as $row) {
        $product_id = absint($row['product_id'] ?? 0);
        $term_id = absint($row['term_id'] ?? 0);
        if ($product_id > 0 && $term_id > 0) {
            $product_category_map[$product_id][$term_id] = $category_name_map[$term_id] ?? '';
        }
    }

    $product_tag_rows = $wpdb->get_results(
        "SELECT tr.object_id AS product_id, t.name
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'product_tag'
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         INNER JOIN {$wpdb->posts} p
           ON p.ID = tr.object_id
          AND p.post_type = 'product'
          AND p.post_status NOT IN ('auto-draft', 'trash')",
        ARRAY_A
    );
    $wc_product_tags = array();
    foreach ((array) $product_tag_rows as $row) {
        $wc_product_tags[(int) $row['product_id']][] = (string) $row['name'];
    }

    $attributes_by_product = array();
    $attribute_table_available = function_exists('seo_attributes_get_rows_for_products')
        && function_exists('seo_attributes_tables');

    if ($attribute_table_available) {
        foreach ((array) seo_attributes_tables() as $attributes_table) {
            if (!seo_core_system_test_semantic_table_exists($attributes_table)) {
                $attribute_table_available = false;
                break;
            }
        }
    }

    if ($attribute_table_available) {
        $attribute_rows = seo_attributes_get_rows_for_products(null);
        foreach ((array) $attribute_rows as $row) {
            $row = (array) $row;
            $attributes_by_product[(int) ($row['product_id'] ?? 0)][] = $row;
        }
    }

    $product_template_patterns = array(
        '/sus especificaciones verificadas incluyen/iu',
        '/el producto est[aá] identificado por su nombre, modelo o referencia/iu',
        '/antes de comprar, comprueba el veh[ií]culo o sistema compatible/iu',
        '/se utiliza para [^\.]{0,80} de acuerdo con la necesidad/iu',
    );
    $known_bad_attribute_values = array_flip(array(
        'os', 'ificaci', 'lectantes', 'low', 'uerzo', 'rigeraci', 'orzado', 'rigerante',
        'rigeracion', 'rigerante', 'ificaci n', 'modelos h2', 'tus dispositivos'
    ));

    $product_entities = array();
    $product_scope_map = array();
    $product_title_map = array();
    $product_status_map = array();
    $product_ids_existing = array();
    $product_counts = array(
        'total' => count((array) $product_rows),
        'published' => 0,
        'draft' => 0,
        'without_excerpt' => 0,
        'without_excerpt_examples' => array(),
        'without_description' => 0,
        // Imagenes: la fuente valida puede ser Media o seo_supplier_images.
        // without_image se conserva como alias de compatibilidad y ya no significa
        // "sin imagen destacada local".
        'without_image' => 0,
        'without_local_image' => 0,
        'with_local_image' => 0,
        'with_external_image' => 0,
        'with_external_validated' => 0,
        'with_both_images' => 0,
        'without_any_image_source' => 0,
        'without_any_valid_image' => 0,
        'external_pending_retry' => 0,
        'external_known_missing' => 0,
        'without_valid_sku' => 0,
        'without_category' => 0,
        'invalid_scope' => 0,
        'template_excerpt' => 0,
        'template_description' => 0,
        'without_seo_attributes' => 0,
        'attribute_error_products' => 0,
        'attribute_error_rows' => 0,
        'attribute_review_products' => 0,
        'attribute_review_rows' => 0,
        'attribute_identifier_matches_sku' => 0,
        // Alias legacy: desde 2.4 representa solo revision heuristica, no error.
        'suspicious_attribute_products' => 0,
        'suspicious_attribute_rows' => 0,
        'title_like_custom_tags' => 0,
        'too_many_custom_tags' => 0,
        'too_many_wc_tags' => 0,
        // Alineacion producto-categoria: el detector amplio se conserva como
        // auditoria historica; solo la contradiccion fuerte puede ser error.
        'supplier_category_review_broad' => 0,
        'supplier_category_review' => 0,
        'category_alignment_errors' => 0,
        'price_sale_mismatch' => 0,
        'artificial_stock' => 0,
        'without_active_faq' => null,
        'examples' => array(),
        'attribute_error_examples' => array(),
        'attribute_examples' => array(),
        'attribute_identifier_examples' => array(),
        'alignment_broad_examples' => array(),
        'alignment_examples' => array(),
        'alignment_error_examples' => array(),
    );

    foreach ((array) $product_rows as $row) {
        $product_id = absint($row['ID'] ?? 0);
        $status = sanitize_key((string) ($row['post_status'] ?? ''));
        $title = (string) ($row['post_title'] ?? '');
        $excerpt = (string) ($row['post_excerpt'] ?? '');
        $description = (string) ($row['post_content'] ?? '');
        $nodes = isset($product_nodes[$product_id]) ? $product_nodes[$product_id] : array();
        $custom_tags = (string) ($nodes['product'] ?? '');
        // Para productos, ROL canonico es la fuente de verdad. El nodo legacy
        // solo queda como fallback de compatibilidad durante la migracion.
        $scope = isset($canonical_product_roles[$product_id])
            ? sanitize_key((string) $canonical_product_roles[$product_id])
            : sanitize_key(remove_accents(trim((string) ($nodes['ambito'] ?? ''))));
        $meta = isset($product_meta[$product_id]) ? $product_meta[$product_id] : array();
        $sku = trim((string) ($meta['_sku'] ?? ''));
        $image_id = absint($meta['_thumbnail_id'] ?? 0);
        $categories = isset($product_category_map[$product_id]) ? $product_category_map[$product_id] : array();
        $attributes = isset($attributes_by_product[$product_id]) ? $attributes_by_product[$product_id] : array();

        $product_ids_existing[$product_id] = true;
        $product_scope_map[$product_id] = $scope;
        $product_title_map[$product_id] = $title;
        $product_status_map[$product_id] = $status;

        if ($status === 'publish') {
            $product_counts['published']++;
        } elseif ($status === 'draft') {
            $product_counts['draft']++;
        }

        if ($status === 'publish') {
            if (seo_core_system_test_semantic_normalize_text($excerpt) === '') {
                $product_counts['without_excerpt']++;
                if (count($product_counts['without_excerpt_examples']) < 20) {
                    $product_counts['without_excerpt_examples'][] = array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'title' => $title,
                        'post_date' => (string) ($row['post_date'] ?? ''),
                        'post_modified' => (string) ($row['post_modified'] ?? ''),
                    );
                }
            }
            if (seo_core_system_test_semantic_normalize_text($description) === '') {
                $product_counts['without_description']++;
            }
            $image_state = $product_image_inventory[$product_id] ?? array(
                'local' => false,
                'external_registered' => false,
                'external_validated' => false,
                'external_retry' => false,
                'external_missing' => false,
            );
            $has_local_image = !empty($image_state['local']);
            $has_external_image = !empty($image_state['external_registered']);
            $has_external_validated = !empty($image_state['external_validated']);
            $has_external_retry = !empty($image_state['external_retry']);
            $has_external_missing = !empty($image_state['external_missing']);

            if ($has_local_image) {
                $product_counts['with_local_image']++;
            } else {
                $product_counts['without_local_image']++;
            }
            if ($has_external_image) {
                $product_counts['with_external_image']++;
            }
            if ($has_external_validated) {
                $product_counts['with_external_validated']++;
            }
            if ($has_local_image && $has_external_image) {
                $product_counts['with_both_images']++;
            }
            if ($has_external_retry) {
                $product_counts['external_pending_retry']++;
            }
            if ($has_external_missing) {
                $product_counts['external_known_missing']++;
            }
            if (!$has_local_image && !$has_external_image) {
                $product_counts['without_any_image_source']++;
            }
            // Un estado pendiente/reintento no se considera imagen invalida: aun no
            // existe evidencia suficiente para penalizarlo.
            if (!$has_local_image && !$has_external_validated && !$has_external_retry) {
                $product_counts['without_any_valid_image']++;
                $product_counts['without_image']++;
            }
            if ($sku === '' || $sku === '0') {
                $product_counts['without_valid_sku']++;
            }
            if (!$categories) {
                $product_counts['without_category']++;
            }
            if (!seo_core_system_test_semantic_scope_is_valid($scope, true, false)) {
                $product_counts['invalid_scope']++;
            }
            if (seo_core_system_test_semantic_matches_patterns($excerpt, $product_template_patterns)) {
                $product_counts['template_excerpt']++;
            }
            if (seo_core_system_test_semantic_matches_patterns($description, $product_template_patterns)) {
                $product_counts['template_description']++;
            }
            if (!$attributes) {
                $product_counts['without_seo_attributes']++;
            }
        }

        $custom_tag_items = seo_core_system_test_semantic_split_tags($custom_tags);
        $title_key = seo_core_system_test_semantic_normalize_text($title);
        $title_like_tag = false;
        foreach ($custom_tag_items as $tag) {
            $tag_key = seo_core_system_test_semantic_normalize_text($tag);
            if ($tag_key === $title_key || strlen($tag_key) > 90) {
                $title_like_tag = true;
                break;
            }
        }
        if ($status === 'publish' && $title_like_tag) {
            $product_counts['title_like_custom_tags']++;
        }
        if ($status === 'publish' && count($custom_tag_items) > 10) {
            $product_counts['too_many_custom_tags']++;
        }
        if ($status === 'publish' && count((array) ($wc_product_tags[$product_id] ?? array())) > 20) {
            $product_counts['too_many_wc_tags']++;
        }

        $product_has_attribute_error = false;
        $product_has_attribute_review = false;
        foreach ($attributes as $attribute) {
            $attribute_type = sanitize_key(remove_accents((string) ($attribute['attribute_type'] ?? '')));
            $attribute_value = trim((string) ($attribute['attribute_value'] ?? ''));
            $attribute_scope = sanitize_key((string) ($attribute['ambito'] ?? ''));
            $value_key = seo_core_system_test_semantic_normalize_text($attribute_value);
            $error_reason = '';
            $review_reason = '';

            if ($attribute_type === '' || $attribute_value === '') {
                $error_reason = 'Atributo sin tipo o sin valor.';
            } elseif (!seo_core_system_test_semantic_scope_is_valid($attribute_scope, true, true)) {
                $error_reason = 'Ambito de atributo no admitido.';
            } elseif (isset($known_bad_attribute_values[$value_key])) {
                $error_reason = 'Valor truncado o generico conocido.';
            } else {
                $unit_error = seo_core_system_test_semantic_attribute_unit_error($attribute_type, $attribute_value);
                if ($unit_error !== '') {
                    $error_reason = $unit_error;
                } elseif (
                    seo_core_system_test_semantic_attribute_is_identifier($attribute_type)
                    && (seo_core_system_test_semantic_word_count($attribute_value) > 8 || strlen($attribute_value) > 80)
                ) {
                    $error_reason = 'El identificador parece una frase descriptiva larga, no un modelo o referencia.';
                } elseif ($sku !== '' && seo_core_system_test_semantic_normalize_text($sku) === $value_key) {
                    if (seo_core_system_test_semantic_attribute_is_identifier($attribute_type)) {
                        // SKU == modelo/MPN/referencia es una practica comercial valida.
                        $product_counts['attribute_identifier_matches_sku']++;
                        if (count($product_counts['attribute_identifier_examples']) < 20) {
                            $product_counts['attribute_identifier_examples'][] = array(
                                'product_id' => $product_id,
                                'title' => $title,
                                'attribute_type' => $attribute_type,
                                'attribute_value' => $attribute_value,
                                'note' => 'Coincide con SKU; coincidencia informativa y no penalizable.',
                            );
                        }
                    } else {
                        $review_reason = 'El valor coincide con el SKU en un atributo no identificador; revisar antes de modificar.';
                    }
                } elseif (
                    $attribute_type === 'temperatura'
                    && preg_match('/^\d{1,2}\s*c$/i', $attribute_value)
                    && preg_match('/\b(iphone|ipad|galaxy|m[oó]vil|smartphone)\b/iu', $title)
                ) {
                    $review_reason = 'Puede ser una referencia de modelo terminada en C; requiere revision contextual.';
                }
            }

            if ($error_reason !== '') {
                $product_has_attribute_error = true;
                $product_counts['attribute_error_rows']++;
                if (count($product_counts['attribute_error_examples']) < 20) {
                    $product_counts['attribute_error_examples'][] = array(
                        'product_id' => $product_id,
                        'title' => $title,
                        'attribute_type' => $attribute_type,
                        'attribute_value' => $attribute_value,
                        'reason' => $error_reason,
                    );
                }
            } elseif ($review_reason !== '') {
                $product_has_attribute_review = true;
                $product_counts['attribute_review_rows']++;
                // Alias legacy para consumidores de informes antiguos.
                $product_counts['suspicious_attribute_rows']++;
                if (count($product_counts['attribute_examples']) < 20) {
                    $product_counts['attribute_examples'][] = array(
                        'product_id' => $product_id,
                        'title' => $title,
                        'attribute_type' => $attribute_type,
                        'attribute_value' => $attribute_value,
                        'reason' => $review_reason,
                    );
                }
            }
        }
        if ($status === 'publish' && $product_has_attribute_error) {
            $product_counts['attribute_error_products']++;
        }
        if ($status === 'publish' && $product_has_attribute_review) {
            $product_counts['attribute_review_products']++;
            // Alias legacy para consumidores de informes antiguos.
            $product_counts['suspicious_attribute_products']++;
        }

        if ($status === 'publish') {
            $regular = (float) ($meta['_regular_price'] ?? 0);
            $sale = (float) ($meta['_sale_price'] ?? 0);
            $current = (float) ($meta['_price'] ?? 0);
            if ($sale > 0 && $regular > $sale && abs($current - $sale) > 0.0001) {
                $product_counts['price_sale_mismatch']++;
            }

            $stock = trim((string) ($meta['_stock'] ?? ''));
            if ($stock !== '' && is_numeric($stock) && (float) $stock >= 1000000) {
                $product_counts['artificial_stock']++;
            }
        }

        /*
         * Auditoria producto-categoria.
         *
         * La ruta completa del proveedor se conserva solo como evidencia. La
         * comparacion operativa prioriza titulo, hoja del proveedor, hoja interna,
         * etiquetas/atributos y equivalencia lexica ligera. La profundidad de las
         * dos taxonomias no se interpreta como desalineacion.
         */
        if ($status === 'publish' && $categories) {
            $supplier_category = trim((string) ($meta['_seo_categoria_proveedor'] ?? ''));
            if ($supplier_category !== '') {
                $title_tokens = seo_core_system_test_semantic_tokens($title);

                // Detector historico amplio: se conserva para no perder candidatos
                // ya conocidos, pero nunca afecta a salud ni integridad.
                $legacy_supplier_tokens = seo_core_system_test_semantic_tokens($supplier_category);
                $legacy_assigned_tokens = array();
                foreach ($categories as $category_name) {
                    $legacy_assigned_tokens = array_merge($legacy_assigned_tokens, seo_core_system_test_semantic_tokens($category_name));
                }
                $legacy_assigned_tokens = array_values(array_unique($legacy_assigned_tokens));
                $legacy_title_supplier = seo_core_system_test_semantic_token_overlap_exact($title_tokens, $legacy_supplier_tokens);
                $legacy_title_assigned = seo_core_system_test_semantic_token_overlap_exact($title_tokens, $legacy_assigned_tokens);
                if (count($legacy_supplier_tokens) >= 2 && count($legacy_title_supplier) >= 1 && count($legacy_title_assigned) === 0) {
                    $product_counts['supplier_category_review_broad']++;
                    if (count($product_counts['alignment_broad_examples']) < 20) {
                        $product_counts['alignment_broad_examples'][] = array(
                            'product_id' => $product_id,
                            'title' => $title,
                            'supplier_category' => $supplier_category,
                            'assigned_categories' => array_values($categories),
                            'note' => 'Detector historico amplio; informativo y no penalizable.',
                        );
                    }
                }

                $supplier_leaf = seo_core_system_test_semantic_supplier_leaf($supplier_category);
                $supplier_leaf_tokens = seo_core_system_test_semantic_tokens($supplier_leaf);
                $assigned_leaf_categories = seo_core_system_test_semantic_assigned_leaf_categories($categories, $category_parent_map);
                $assigned_leaf_tokens = array();
                foreach ($assigned_leaf_categories as $category_name) {
                    $assigned_leaf_tokens = array_merge($assigned_leaf_tokens, seo_core_system_test_semantic_tokens($category_name));
                }
                $assigned_leaf_tokens = array_values(array_unique($assigned_leaf_tokens));

                $tag_context = array_merge($custom_tag_items, (array) ($wc_product_tags[$product_id] ?? array()));
                $tag_tokens = seo_core_system_test_semantic_tokens(implode(' ', $tag_context));
                $attribute_context = array();
                foreach (array_slice((array) $attributes, 0, 40) as $attribute) {
                    $attribute_context[] = (string) ($attribute['attribute_type'] ?? '');
                    $attribute_context[] = (string) ($attribute['attribute_value'] ?? '');
                }
                $attribute_tokens = seo_core_system_test_semantic_tokens(implode(' ', $attribute_context));
                $product_context_tokens = array_values(array_unique(array_merge($title_tokens, $tag_tokens, $attribute_tokens)));

                $title_supplier_overlap = seo_core_system_test_semantic_token_overlap($title_tokens, $supplier_leaf_tokens);
                $context_supplier_overlap = seo_core_system_test_semantic_token_overlap($product_context_tokens, $supplier_leaf_tokens);
                $title_assigned_overlap = seo_core_system_test_semantic_token_overlap($title_tokens, $assigned_leaf_tokens);
                $context_assigned_overlap = seo_core_system_test_semantic_token_overlap($product_context_tokens, $assigned_leaf_tokens);
                $supplier_assigned_overlap = seo_core_system_test_semantic_token_overlap($supplier_leaf_tokens, $assigned_leaf_tokens);

                $supplier_supported = count($title_supplier_overlap) >= 1 || count($context_supplier_overlap) >= 2;
                $assigned_supported = count($title_assigned_overlap) >= 1
                    || count($context_assigned_overlap) >= 1
                    || count($supplier_assigned_overlap) >= 1;

                if ($supplier_leaf_tokens && $supplier_supported && !$assigned_supported) {
                    $product_counts['supplier_category_review']++;

                    $best_internal = seo_core_system_test_semantic_best_internal_category_match(
                        $supplier_leaf_tokens,
                        $title_tokens,
                        $category_token_map,
                        $category_name_map
                    );
                    $best_term_id = absint($best_internal['term_id'] ?? 0);
                    $best_is_assigned_or_parented = false;
                    if ($best_term_id > 0) {
                        foreach (array_keys($categories) as $assigned_term_id) {
                            $assigned_term_id = absint($assigned_term_id);
                            if (
                                $assigned_term_id === $best_term_id
                                || seo_core_system_test_semantic_category_is_ancestor($assigned_term_id, $best_term_id, $category_parent_map)
                            ) {
                                $best_is_assigned_or_parented = true;
                                break;
                            }
                        }
                    }

                    // Solo se declara contradiccion fuerte cuando proveedor y titulo
                    // apuntan de forma muy clara a una categoria interna existente,
                    // esa categoria no esta asignada ni cubierta por un ancestro, y
                    // la categoria hoja asignada carece de cualquier apoyo contextual.
                    $strong_contradiction = $best_term_id > 0
                        && !$best_is_assigned_or_parented
                        && count($title_supplier_overlap) >= min(2, max(1, count($supplier_leaf_tokens)))
                        && count($context_assigned_overlap) === 0
                        && count($supplier_assigned_overlap) === 0;

                    $example = array(
                        'product_id' => $product_id,
                        'title' => $title,
                        'supplier_category' => $supplier_category,
                        'supplier_leaf' => $supplier_leaf,
                        'assigned_leaf_categories' => array_values($assigned_leaf_categories),
                        'title_supplier_tokens' => $title_supplier_overlap,
                        'title_assigned_tokens' => $title_assigned_overlap,
                        'supplier_assigned_tokens' => $supplier_assigned_overlap,
                        'best_internal_category' => $best_internal,
                    );

                    if (count($product_counts['alignment_examples']) < 20) {
                        $product_counts['alignment_examples'][] = $example;
                    }
                    if ($strong_contradiction) {
                        $product_counts['category_alignment_errors']++;
                        if (count($product_counts['alignment_error_examples']) < 20) {
                            $example['reason'] = 'Contradiccion fuerte: titulo y hoja del proveedor respaldan otra categoria interna existente y la categoria asignada no aporta señales compatibles.';
                            $product_counts['alignment_error_examples'][] = $example;
                        }
                    }
                }
            }
        }

        if ($status === 'publish') {
            $product_entities[] = array(
                'id' => $product_id,
                'title' => $title,
                'excerpt' => $excerpt,
                'description' => $description,
                'tags' => $custom_tags,
            );
        }
    }

    $product_counts['duplicate_excerpts'] = seo_core_system_test_semantic_duplicate_stats(
        $product_entities,
        'excerpt',
        array(),
        'id',
        '',
        25
    );
    $product_counts['duplicate_descriptions'] = seo_core_system_test_semantic_duplicate_stats(
        $product_entities,
        'description',
        array(),
        'id',
        '',
        60
    );

    $snapshot['products'] = $product_counts;

    /* ---------------------------------------------------------------------
     * ETIQUETAS WOO
     * ------------------------------------------------------------------ */
    $tag_rows = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tt.count = 0 THEN 1 ELSE 0 END) AS empty_count,
            SUM(CASE WHEN tt.count = 1 THEN 1 ELSE 0 END) AS single_product
         FROM {$wpdb->term_taxonomy} tt
         WHERE tt.taxonomy = 'product_tag'",
        ARRAY_A
    );

    $category_tag_overlap = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT tag.term_id)
         FROM {$wpdb->terms} tag
         INNER JOIN {$wpdb->term_taxonomy} tag_tt
           ON tag_tt.term_id = tag.term_id AND tag_tt.taxonomy = 'product_tag'
         INNER JOIN {$wpdb->terms} cat ON cat.slug = tag.slug
         INNER JOIN {$wpdb->term_taxonomy} cat_tt
           ON cat_tt.term_id = cat.term_id AND cat_tt.taxonomy = 'product_cat'"
    );

    $snapshot['tags'] = array(
        'total' => (int) ($tag_rows['total'] ?? 0),
        'empty' => (int) ($tag_rows['empty_count'] ?? 0),
        'single_product' => (int) ($tag_rows['single_product'] ?? 0),
        'category_name_overlap' => $category_tag_overlap,
        'products_over_20_tags' => (int) $product_counts['too_many_wc_tags'],
    );

    /* ---------------------------------------------------------------------
     * FAQS
     * ------------------------------------------------------------------ */
    $faq_table = $wpdb->prefix . 'seo_faq';
    $faq_available = seo_core_system_test_semantic_table_exists($faq_table);
    $faq_rows = array();
    $faq_has_legacy_scope = $faq_available && seo_core_system_test_semantic_table_has_column($faq_table, 'ambito');
    $faq_counts = array(
        'available' => $faq_available,
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'categories' => 0,
        'products' => 0,
        'invalid_object_type' => 0,
        'invalid_active_state' => 0,
        'orphan_rows' => 0,
        'active_on_unpublished_product' => 0,
        'empty_questions' => 0,
        'empty_answers' => 0,
        'insignificant_answers' => 0,
        'short_answers' => 0,
        'very_short_answers' => 0,
        'template_questions' => 0,
        'template_answers' => 0,
        'attribute_only_questions' => 0,
        'attribute_only_answers' => 0,
        'product_objects_over_5_active' => 0,
        'category_objects_over_8_active' => 0,
        // Metadatos legacy: nunca determinan la pertenencia de una FAQ.
        'legacy_scope_column' => $faq_has_legacy_scope,
        'legacy_scope_invalid' => 0,
        'legacy_scope_mismatch' => 0,
        'examples' => array(),
        'legacy_scope_examples' => array(),
    );

    if ($faq_available) {
        $faq_scope_select = $faq_has_legacy_scope ? 'ambito' : "'' AS ambito";
        $faq_rows = $wpdb->get_results(
            "SELECT id, object_type, object_id, {$faq_scope_select}, question, answer, sort_order, active, load_count, open_count, created_at, updated_at
             FROM {$faq_table}
             ORDER BY object_type ASC, object_id ASC, sort_order ASC, id ASC",
            ARRAY_A
        );

        $faq_counts['total'] = count((array) $faq_rows);
        $active_by_object = array();
        $category_active_objects = array();
        $product_active_objects = array();

        $question_template_patterns = array(
            '/^\s*¿?qu[eé] debo comprobar para/iu',
            '/^\s*¿?qu[eé] datos debo comparar antes de/iu',
            '/^\s*¿?qu[eé] debo verificar antes de montar o utilizar/iu',
        );
        $answer_template_patterns = array(
            '/^\s*el producto est[aá] identificado por su nombre, modelo o referencia/iu',
            '/^\s*la ficha identifica de forma expl[ií]cita/iu',
            '/^\s*este art[ií]culo est[aá] identificado por/iu',
        );
        $attribute_only_pattern = '/^\s*¿?(qu[eé]|cu[aá]l|cu[aá]les)\b.{0,45}\b(potencia|capacidad|tensi[oó]n|voltaje|peso|medidas|dimensiones|material|color|corriente|frecuencia)\b/iu';

        foreach ((array) $faq_rows as &$faq_row) {
            $faq_row['active_bool'] = (int) ($faq_row['active'] ?? 0) === 1 ? 1 : 0;
            $faq_id = absint($faq_row['id'] ?? 0);
            $object_type = (int) ($faq_row['object_type'] ?? 0);
            $object_id = absint($faq_row['object_id'] ?? 0);
            $scope = sanitize_key(remove_accents(trim((string) ($faq_row['ambito'] ?? ''))));
            $question = (string) ($faq_row['question'] ?? '');
            $answer = (string) ($faq_row['answer'] ?? '');
            $raw_active = (int) ($faq_row['active'] ?? 0);
            if (!in_array($raw_active, array(0, 1), true)) {
                $faq_counts['invalid_active_state']++;
            }
            $active = $raw_active === 1;

            if ($active) {
                $faq_counts['active']++;
                $active_by_object[$object_type . ':' . $object_id] = isset($active_by_object[$object_type . ':' . $object_id])
                    ? $active_by_object[$object_type . ':' . $object_id] + 1
                    : 1;
                if ($object_type === 2) {
                    $category_active_objects[$object_id] = true;
                } elseif ($object_type === 3) {
                    $product_active_objects[$object_id] = true;
                }
            } else {
                $faq_counts['inactive']++;
            }

            if ($object_type === 2) {
                $faq_counts['categories']++;
            } elseif ($object_type === 3) {
                $faq_counts['products']++;
            } else {
                $faq_counts['invalid_object_type']++;
            }

            if ($faq_has_legacy_scope && !seo_core_system_test_semantic_scope_is_valid($scope, true, false)) {
                $faq_counts['legacy_scope_invalid']++;
            }

            $orphan = false;
            if ($object_id <= 0) {
                $orphan = true;
            } elseif ($object_type === 1 && !get_post($object_id)) {
                $orphan = true;
            } elseif ($object_type === 2 && !isset($category_ids_existing[$object_id])) {
                $orphan = true;
            } elseif ($object_type === 3 && !isset($product_ids_existing[$object_id])) {
                $orphan = true;
            } elseif (!in_array($object_type, array(1, 2, 3), true)) {
                $orphan = true;
            }
            if ($orphan) {
                $faq_counts['orphan_rows']++;
            }

            if ($active && $object_type === 3 && isset($product_status_map[$object_id]) && $product_status_map[$object_id] !== 'publish') {
                $faq_counts['active_on_unpublished_product']++;
            }

            $question_normalized = seo_core_system_test_semantic_normalize_text($question);
            $answer_normalized = seo_core_system_test_semantic_normalize_text($answer);
            $answer_words = seo_core_system_test_semantic_word_count($answer);
            if ($question_normalized === '') {
                $faq_counts['empty_questions']++;
            }
            if ($answer_normalized === '') {
                $faq_counts['empty_answers']++;
            } elseif ($answer_words <= 2 && strlen($answer_normalized) <= 18) {
                // Muy corta no significa automaticamente mala. Solo se marca como
                // revision cuando es casi vacia; el umbral editorial de 40 palabras
                // queda exclusivamente como estadistica.
                $faq_counts['insignificant_answers']++;
            }
            if ($answer_words < 60) {
                $faq_counts['short_answers']++;
            }
            if ($answer_words < 40) {
                $faq_counts['very_short_answers']++;
            }
            if (seo_core_system_test_semantic_matches_patterns($question, $question_template_patterns)) {
                $faq_counts['template_questions']++;
            }
            if (seo_core_system_test_semantic_matches_patterns($answer, $answer_template_patterns)) {
                $faq_counts['template_answers']++;
            }
            if (preg_match($attribute_only_pattern, $question) && $answer_words < 45) {
                $faq_counts['attribute_only_questions']++;
            }

            // Solo se considera copia de atributo cuando la respuesta normalizada
            // coincide literalmente con un valor real del producto. No se deduce
            // por longitud ni por contener numeros.
            if ($object_type === 3 && $answer_normalized !== '' && !empty($attributes_by_product[$object_id])) {
                foreach ((array) $attributes_by_product[$object_id] as $attribute_row) {
                    $attribute_value_normalized = seo_core_system_test_semantic_normalize_text($attribute_row['attribute_value'] ?? '');
                    if ($attribute_value_normalized !== '' && $answer_normalized === $attribute_value_normalized) {
                        $faq_counts['attribute_only_answers']++;
                        break;
                    }
                }
            }

            $entity_scopes = array();
            if ($object_type === 2) {
                $entity_scopes = array_values(array_filter(array_map('sanitize_key', (array) ($category_scope_map[$object_id] ?? array()))));
            } elseif ($object_type === 3) {
                $product_scope = sanitize_key((string) ($product_scope_map[$object_id] ?? ''));
                if ($product_scope !== '') {
                    $entity_scopes[] = $product_scope;
                }
            }
            if ($faq_has_legacy_scope && $scope !== '' && $entity_scopes && !in_array($scope, $entity_scopes, true)) {
                $faq_counts['legacy_scope_mismatch']++;
                if (count($faq_counts['legacy_scope_examples']) < 20) {
                    $faq_counts['legacy_scope_examples'][] = array(
                        'faq_id' => $faq_id,
                        'object_type' => $object_type,
                        'object_id' => $object_id,
                        'legacy_scope' => $scope,
                        'current_object_role' => implode(', ', $entity_scopes),
                        'question' => seo_core_system_test_semantic_preview($question, 120),
                    );
                }
            }
        }
        unset($faq_row);

        foreach ($active_by_object as $key => $count) {
            list($type) = array_map('intval', explode(':', $key, 2));
            if ($type === 3 && $count > 5) {
                $faq_counts['product_objects_over_5_active']++;
            }
            if ($type === 2 && $count > 8) {
                $faq_counts['category_objects_over_8_active']++;
            }
        }

        $faq_counts['duplicate_questions_same_object'] = seo_core_system_test_semantic_duplicate_stats(
            $faq_rows,
            'question',
            array('object_type', 'object_id'),
            'id',
            'active_bool',
            10
        );
        $faq_counts['duplicate_answers_same_object'] = seo_core_system_test_semantic_duplicate_stats(
            $faq_rows,
            'answer',
            array('object_type', 'object_id'),
            'id',
            'active_bool',
            20
        );
        $faq_counts['duplicate_questions_global'] = seo_core_system_test_semantic_duplicate_stats(
            $faq_rows,
            'question',
            array(),
            'id',
            'active_bool',
            10
        );
        $faq_counts['duplicate_answers_global'] = seo_core_system_test_semantic_duplicate_stats(
            $faq_rows,
            'answer',
            array(),
            'id',
            'active_bool',
            20
        );
        $faq_counts['near_duplicate_questions_same_object'] = seo_core_system_test_semantic_near_duplicate_faq_questions($faq_rows);

        $category_counts['without_active_faq'] = max(0, count($category_ids_existing) - count($category_active_objects));
        $product_counts['without_active_faq'] = 0;
        foreach ($product_status_map as $product_id => $status) {
            if ($status === 'publish' && !isset($product_active_objects[$product_id])) {
                $product_counts['without_active_faq']++;
            }
        }
        $snapshot['categories']['without_active_faq'] = $category_counts['without_active_faq'];
        $snapshot['products']['without_active_faq'] = $product_counts['without_active_faq'];
    }

    $snapshot['faqs'] = $faq_counts;

    /* ---------------------------------------------------------------------
     * ESTRUCTURA CLUSTER / HUB
     * ------------------------------------------------------------------ */
    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $relations_table = $wpdb->prefix . 'seo_relations';
    $structure = array(
        'available' => false,
        'clusters' => 0,
        'hub_primary' => 0,
        'hub_secondary' => 0,
        'relations' => 0,
        'clusters_without_primary' => null,
        'primary_without_secondary' => null,
        'secondary_without_categories' => null,
        'categories_without_secondary_hub' => null,
        'invalid_post_nodes' => null,
        'invalid_category_relations' => null,
    );

    if (seo_core_system_test_semantic_table_exists($nodes_table) && seo_core_system_test_semantic_table_exists($relations_table)) {
        $structure['available'] = true;
        $structure['clusters'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT object_id) FROM {$nodes_table} WHERE seo_role = 'cluster' AND status = 1");
        $structure['hub_primary'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT object_id) FROM {$nodes_table} WHERE seo_role = 'hub_primary' AND status = 1");
        $structure['hub_secondary'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT object_id) FROM {$nodes_table} WHERE seo_role = 'hub_secondary' AND status = 1");
        $structure['relations'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$relations_table}");

        $structure['clusters_without_primary'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT n.object_id
                FROM {$nodes_table} n
                LEFT JOIN {$relations_table} r
                  ON r.source_type = 'cluster'
                 AND r.source_id = n.object_id
                 AND r.target_type = 'hub_primary'
                 AND r.relation_type = 'cluster_to_primary'
                WHERE n.seo_role = 'cluster' AND n.status = 1
                GROUP BY n.object_id
                HAVING COUNT(r.target_id) = 0
             ) q"
        );
        $structure['primary_without_secondary'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT n.object_id
                FROM {$nodes_table} n
                LEFT JOIN {$relations_table} r
                  ON r.source_type = 'hub_primary'
                 AND r.source_id = n.object_id
                 AND r.target_type = 'hub_secondary'
                 AND r.relation_type = 'hub_primary_to_hub_secondary'
                WHERE n.seo_role = 'hub_primary' AND n.status = 1
                GROUP BY n.object_id
                HAVING COUNT(r.target_id) = 0
             ) q"
        );
        $structure['secondary_without_categories'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT n.object_id
                FROM {$nodes_table} n
                LEFT JOIN {$relations_table} r
                  ON r.source_type = 'hub_secondary'
                 AND r.source_id = n.object_id
                 AND r.target_type = 'product_cat'
                 AND r.relation_type = 'hub_secondary_to_category'
                WHERE n.seo_role = 'hub_secondary' AND n.status = 1
                GROUP BY n.object_id
                HAVING COUNT(r.target_id) = 0
             ) q"
        );
        $structure['categories_without_secondary_hub'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->term_taxonomy} tt
             LEFT JOIN {$relations_table} r
               ON r.target_type = 'product_cat'
              AND r.target_id = tt.term_id
              AND r.source_type = 'hub_secondary'
              AND r.relation_type = 'hub_secondary_to_category'
             WHERE tt.taxonomy = 'product_cat'
               AND r.target_id IS NULL"
        );
        $structure['invalid_post_nodes'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT n.id)
             FROM {$nodes_table} n
             LEFT JOIN {$wpdb->posts} p ON p.ID = n.object_id
             WHERE n.seo_role IN ('cluster','hub_primary','hub_secondary')
               AND n.status = 1
               AND (p.ID IS NULL OR p.post_status <> 'publish')"
        );
        $structure['invalid_category_relations'] = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$relations_table} r
             LEFT JOIN {$wpdb->term_taxonomy} tt
               ON tt.term_id = r.target_id
              AND tt.taxonomy = 'product_cat'
             WHERE r.target_type = 'product_cat'
               AND tt.term_id IS NULL"
        );
    }
    $snapshot['structure'] = $structure;

    /* ---------------------------------------------------------------------
     * ACCIONES RECOMENDADAS
     * ------------------------------------------------------------------ */
    $actions = array();

    if ($snapshot['faqs']['available']) {
        $duplicate_faqs = (int) ($snapshot['faqs']['duplicate_questions_same_object']['extra_rows'] ?? 0);
        if ($duplicate_faqs > 0) {
            $actions[] = seo_core_system_test_semantic_action(
                'deduplicate_faqs_same_object',
                'critical',
                'faq',
                'question',
                $duplicate_faqs,
                'Conservar una sola FAQ por pregunta y objeto. Priorizar la activa, con mayor uso y actualizacion mas reciente; desactivar o eliminar las copias mediante una operacion reversible.',
                $snapshot['faqs']['duplicate_questions_same_object']['examples'] ?? array()
            );
        }
        if ((int) ($snapshot['faqs']['empty_answers'] ?? 0) > 0 || (int) ($snapshot['faqs']['empty_questions'] ?? 0) > 0) {
            $actions[] = seo_core_system_test_semantic_action(
                'complete_empty_faq_content',
                'critical',
                'faq',
                'question_answer',
                (int) ($snapshot['faqs']['empty_answers'] ?? 0) + (int) ($snapshot['faqs']['empty_questions'] ?? 0),
                'Completar o desactivar FAQs con pregunta o respuesta vacia. La longitud no se usa como criterio: solo la ausencia real de contenido.'
            );
        }
        if ((int) $snapshot['faqs']['template_answers'] > 0) {
            $actions[] = seo_core_system_test_semantic_action(
                'rewrite_template_faq_answers',
                'high',
                'faq',
                'answer',
                $snapshot['faqs']['template_answers'],
                'Reescribir respuestas que empiezan con formulas repetidas. La respuesta debe resolver compatibilidad, uso, limites, instalacion, accesorios o eleccion sin repetir la ficha tecnica.'
            );
        }
        if ((int) $snapshot['faqs']['template_questions'] > 0) {
            $actions[] = seo_core_system_test_semantic_action(
                'rewrite_template_faq_questions',
                'high',
                'faq',
                'question',
                $snapshot['faqs']['template_questions'],
                'Variar o eliminar preguntas de plantilla. Cada pregunta debe representar una duda concreta que afecte a la compra.'
            );
        }
        if ((int) $snapshot['faqs']['orphan_rows'] > 0) {
            $actions[] = seo_core_system_test_semantic_action(
                'clean_orphan_faqs',
                'high',
                'faq',
                'object_id',
                $snapshot['faqs']['orphan_rows'],
                'Revisar FAQs cuyo producto o categoria ya no existe y eliminarlas solo mediante el sistema reversible del modulo FAQ.'
            );
        }
    }

    if ((int) $snapshot['products']['attribute_error_products'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'correct_confirmed_product_attribute_errors',
            'high',
            'product',
            'attributes',
            $snapshot['products']['attribute_error_products'],
            'Corregir solo inconsistencias confirmadas: atributos vacios, valores truncados conocidos, identificadores descriptivos o unidades incompatibles con el tipo de atributo.',
            $snapshot['products']['attribute_error_examples']
        );
    }
    if ((int) $snapshot['products']['attribute_review_products'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'review_product_attribute_heuristics',
            'low',
            'product',
            'attributes',
            $snapshot['products']['attribute_review_products'],
            'Revisar manualmente las señales heuristicas. No modificar ni regenerar contenido automaticamente: una coincidencia sospechosa no demuestra que el atributo sea incorrecto.',
            $snapshot['products']['attribute_examples']
        );
    }
    if ((int) $snapshot['products']['without_excerpt'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'fill_missing_product_excerpts',
            'high',
            'product',
            'post_excerpt',
            $snapshot['products']['without_excerpt'],
            'Rellenar la descripcion corta nativa de WooCommerce (wp_posts.post_excerpt) solo en productos publicados donde este vacia. No sobrescribir excerpts existentes.',
            $snapshot['products']['without_excerpt_examples'] ?? array()
        );
    }
    if ((int) ($snapshot['products']['duplicate_excerpts']['affected_rows'] ?? 0) > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'rewrite_duplicate_product_excerpts',
            'high',
            'product',
            'excerpt',
            $snapshot['products']['duplicate_excerpts']['affected_rows'],
            'Individualizar los excerpts duplicados con datos verificables del producto. No basta con cambiar el nombre o el modelo dentro de una plantilla.',
            $snapshot['products']['duplicate_excerpts']['examples'] ?? array()
        );
    }
    if ((int) ($snapshot['products']['duplicate_descriptions']['affected_rows'] ?? 0) > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'rewrite_duplicate_product_descriptions',
            'high',
            'product',
            'description',
            $snapshot['products']['duplicate_descriptions']['affected_rows'],
            'Reescribir las descripciones completas repetidas. Deben explicar el articulo concreto, su uso, criterios de eleccion y limites respaldados.',
            $snapshot['products']['duplicate_descriptions']['examples'] ?? array()
        );
    }
    if ((int) ($snapshot['products']['category_alignment_errors'] ?? 0) > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'fix_confirmed_product_category_contradictions',
            'high',
            'product',
            'category',
            $snapshot['products']['category_alignment_errors'],
            'Revisar solo las contradicciones fuertes donde titulo y hoja del proveedor respaldan otra categoria interna existente. No reasignar automaticamente: confirmar cada caso antes de modificarlo.',
            $snapshot['products']['alignment_error_examples'] ?? array()
        );
    }
    if ((int) $snapshot['products']['title_like_custom_tags'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'clean_product_title_tags',
            'medium',
            'product',
            'tags',
            $snapshot['products']['title_like_custom_tags'],
            'Eliminar etiquetas que son el titulo completo del producto o frases excesivamente largas. Las etiquetas deben aportar contexto, no duplicar la ficha.'
        );
    }

    if ((int) $snapshot['categories']['invalid_scope'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'fix_invalid_category_role',
            'critical',
            'category',
            'rol',
            $snapshot['categories']['invalid_scope'],
            'Corregir los ROL de categoria en Vocabulary. Solo son validos accesorio, herramienta, repuesto, equipamiento o consumible; una categoria puede tener 0..n ROL.'
        );
    }
    if ((int) $snapshot['categories']['template_description'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'rewrite_template_category_descriptions',
            'high',
            'category',
            'description',
            $snapshot['categories']['template_description'],
            'Sustituir frases robotizadas por informacion concreta: que contiene la categoria, para que trabajos sirve y que diferencias reales ayudan a elegir.'
        );
    }
    if ((int) ($snapshot['categories']['duplicate_descriptions']['affected_rows'] ?? 0) > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'rewrite_duplicate_category_descriptions',
            'high',
            'category',
            'description',
            $snapshot['categories']['duplicate_descriptions']['affected_rows'],
            'Corregir descripciones exactas repetidas entre categorias. Una descripcion breve y especifica es preferible a una plantilla mas larga.',
            $snapshot['categories']['duplicate_descriptions']['examples'] ?? array()
        );
    }
    if ((int) $snapshot['categories']['title_like_tags'] > 0) {
        $actions[] = seo_core_system_test_semantic_action(
            'clean_category_product_title_tags',
            'medium',
            'category',
            'tags',
            $snapshot['categories']['title_like_tags'],
            'Revisar en Vocabulary las asignaciones de categoria que reproduzcan titulos completos de productos y conservar conceptos canonicos estables.'
        );
    }

    usort($actions, static function ($left, $right) {
        $weight = array('critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3);
        $left_weight = $weight[$left['priority'] ?? 'low'] ?? 9;
        $right_weight = $weight[$right['priority'] ?? 'low'] ?? 9;
        if ($left_weight === $right_weight) {
            return ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0));
        }
        return $left_weight <=> $right_weight;
    });

    $snapshot['actions'] = $actions;

    /* ---------------------------------------------------------------------
     * PUNTUACION CONSERVADORA
     * ------------------------------------------------------------------ */
    $penalty = 0;
    $published = max(1, (int) $snapshot['products']['published']);
    $category_total = max(1, (int) $snapshot['categories']['total']);
    $faq_total = max(1, (int) $snapshot['faqs']['total']);

    // Solo los errores confirmados de atributos penalizan. Las revisiones heuristicas no bajan la puntuacion.
    $penalty += min(15, (int) round(((int) $snapshot['products']['attribute_error_products'] / $published) * 100));
    $penalty += min(12, (int) round(((int) $snapshot['products']['without_excerpt'] / $published) * 30));
    $penalty += min(12, (int) round(((int) ($snapshot['products']['duplicate_excerpts']['affected_rows'] ?? 0) / $published) * 18));
    $penalty += min(12, (int) round(((int) ($snapshot['products']['duplicate_descriptions']['affected_rows'] ?? 0) / $published) * 18));
    $penalty += min(8, (int) round(((int) $snapshot['products']['template_description'] / $published) * 15));
    $penalty += min(8, (int) round(((int) $snapshot['categories']['template_description'] / $category_total) * 12));
    $penalty += min(8, (int) round(((int) ($snapshot['categories']['duplicate_descriptions']['affected_rows'] ?? 0) / $category_total) * 20));
    $penalty += min(8, (int) $snapshot['categories']['invalid_scope'] * 2);

    if ($faq_available) {
        $penalty += min(20, (int) round(((int) ($snapshot['faqs']['duplicate_questions_same_object']['active_extra_rows'] ?? 0) / $faq_total) * 150));
        $penalty += min(12, (int) ($snapshot['faqs']['empty_answers'] ?? 0) + (int) ($snapshot['faqs']['empty_questions'] ?? 0));
        $penalty += min(10, (int) round(((int) $snapshot['faqs']['template_answers'] / $faq_total) * 30));
        $penalty += min(8, (int) round(((int) $snapshot['faqs']['template_questions'] / $faq_total) * 25));
        $penalty += min(8, (int) $snapshot['faqs']['orphan_rows']);
    }

    $snapshot['score'] = max(0, 100 - min(100, $penalty));
    $snapshot['status'] = $snapshot['score'] >= 90
        ? 'ok'
        : ($snapshot['score'] >= 75 ? 'warning' : 'important');
    $snapshot['coverage'] = array(
        'categories' => $snapshot['categories']['total'] > 0 ? 100 : 0,
        'products' => $snapshot['products']['total'] > 0 ? 100 : 0,
        'faqs' => $faq_available ? 100 : 0,
        'attributes' => $attribute_table_available ? 100 : 0,
        'structure' => $structure['available'] ? 100 : 0,
    );
    $snapshot['alignment'] = array(
        'supplier_category_review_broad' => $snapshot['products']['supplier_category_review_broad'] ?? 0,
        'supplier_category_review' => $snapshot['products']['supplier_category_review'],
        'category_alignment_errors' => $snapshot['products']['category_alignment_errors'] ?? 0,
        'faq_legacy_scope_mismatch' => $snapshot['faqs']['legacy_scope_mismatch'] ?? 0,
    );

    return $snapshot;
}

function seo_core_system_test_semantic_setting($key, $default = 0) {
    return function_exists('seo_core_validation_get_setting')
        ? seo_core_validation_get_setting($key, $default)
        : $default;
}

function seo_core_system_test_semantic_percent($count, $total) {
    return $total > 0 ? round(((float) $count / (float) $total) * 100, 2) : 0.0;
}

/**
 * Convierte el snapshot en resultados visibles del test principal.
 */
function seo_core_system_test_semantic_checks() {
    $snapshot = seo_core_system_test_semantic_snapshot();
    $categories = $snapshot['categories'];
    $products = $snapshot['products'];
    $faqs = $snapshot['faqs'];
    $structure = $snapshot['structure'];
    $results = array();

    $category_without_excerpt_limit = (int) seo_core_system_test_semantic_setting('semantic_category_without_excerpt_limit', 0);
    $category_source_critical = empty($categories['vocabulary_available'])
        || (int) $categories['invalid_scope'] > 0;
    $category_source_warning = !$category_source_critical
        && (int) $categories['without_excerpt'] > $category_without_excerpt_limit;
    $category_source_severity = $category_source_critical ? 'ko' : ($category_source_warning ? 'warning' : 'ok');
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.1 Integridad de fuentes de categorías',
        $category_source_severity === 'ok',
        'Fuente editorial canónica: wp_seo_nodes; Vocabulary categorías: ' . (!empty($categories['vocabulary_available']) ? 'disponible' : 'NO disponible') . '; asignaciones activas: ' . number_format_i18n($categories['vocabulary_assignments']) . '; categorías sin Vocabulary: ' . number_format_i18n($categories['without_tags']) . '; ROL inválidos: ' . number_format_i18n($categories['invalid_scope']) . '; sin description en seo_nodes: ' . number_format_i18n($categories['without_description']) . '; sin excerpt en seo_nodes: ' . number_format_i18n($categories['without_excerpt']) . ' (límite ' . number_format_i18n($category_without_excerpt_limit) . '). Los campos nativos/termmeta de WordPress no forman parte de esta validación.',
        $category_source_severity,
        array(
            'owner' => 'contenido',
            'area' => 'semantic',
            'evidence' => array_merge($categories, array('canonical_source' => 'wp_seo_nodes', 'configured_limits' => array('without_excerpt' => $category_without_excerpt_limit))),
            'confidence' => 98,
        )
    );

    $category_editorial_issues = (int) $categories['template_description'] + (int) ($categories['duplicate_descriptions']['affected_rows'] ?? 0) + (int) $categories['title_like_tags'];
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.2 Calidad editorial de categorías',
        $category_editorial_issues === 0,
        'Descripciones con patrones de plantilla: ' . number_format_i18n($categories['template_description']) . '; categorías afectadas por descripciones duplicadas: ' . number_format_i18n($categories['duplicate_descriptions']['affected_rows'] ?? 0) . '; etiquetas que parecen títulos de producto: ' . number_format_i18n($categories['title_like_tags']) . '.',
        $category_editorial_issues === 0 ? 'ok' : 'warning',
        array('owner' => 'contenido', 'area' => 'semantic', 'evidence' => $categories, 'confidence' => 92)
    );

    $published = max(1, (int) $products['published']);
    $duplicate_excerpt_count = (int) ($products['duplicate_excerpts']['affected_rows'] ?? 0);
    $duplicate_description_count = (int) ($products['duplicate_descriptions']['affected_rows'] ?? 0);
    $template_description_count = (int) $products['template_description'];
    $duplicate_excerpt_percent = seo_core_system_test_semantic_percent($duplicate_excerpt_count, $published);
    $duplicate_description_percent = seo_core_system_test_semantic_percent($duplicate_description_count, $published);
    $template_description_percent = seo_core_system_test_semantic_percent($template_description_count, $published);
    $duplicate_excerpt_limit = (float) seo_core_system_test_semantic_setting('semantic_duplicate_excerpt_percent_limit', 0);
    $duplicate_description_limit = (float) seo_core_system_test_semantic_setting('semantic_duplicate_description_percent_limit', 0);
    $template_description_limit = (float) seo_core_system_test_semantic_setting('semantic_template_description_percent_limit', 0);
    $product_content_issue = $duplicate_excerpt_percent > $duplicate_excerpt_limit
        || $duplicate_description_percent > $duplicate_description_limit
        || $template_description_percent > $template_description_limit;
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.3 Singularidad del contenido de producto',
        !$product_content_issue,
        'Productos publicados: ' . number_format_i18n($products['published']) . '; excerpts duplicados: ' . number_format_i18n($duplicate_excerpt_count) . ' (' . number_format_i18n($duplicate_excerpt_percent, 2) . '%, límite ' . number_format_i18n($duplicate_excerpt_limit, 2) . '%); descripciones duplicadas: ' . number_format_i18n($duplicate_description_count) . ' (' . number_format_i18n($duplicate_description_percent, 2) . '%, límite ' . number_format_i18n($duplicate_description_limit, 2) . '%); patrón de plantilla: ' . number_format_i18n($template_description_count) . ' (' . number_format_i18n($template_description_percent, 2) . '%, límite ' . number_format_i18n($template_description_limit, 2) . '%).',
        $product_content_issue ? 'warning' : 'ok',
        array(
            'owner' => 'contenido',
            'area' => 'semantic',
            'evidence' => array(
                'duplicate_excerpts' => $products['duplicate_excerpts'],
                'duplicate_descriptions' => $products['duplicate_descriptions'],
                'template_description' => $template_description_count,
                'percentages' => array('duplicate_excerpts' => $duplicate_excerpt_percent, 'duplicate_descriptions' => $duplicate_description_percent, 'template_descriptions' => $template_description_percent),
                'configured_limits' => array('duplicate_excerpts' => $duplicate_excerpt_limit, 'duplicate_descriptions' => $duplicate_description_limit, 'template_descriptions' => $template_description_limit),
            ),
            'confidence' => 98,
        )
    );

    $without_excerpt_count = (int) $products['without_excerpt'];
    $without_excerpt_limit = (int) seo_core_system_test_semantic_setting('semantic_product_without_excerpt_limit', 0);
    $published_actual = (int) $products['published'];
    $without_excerpt_percent = seo_core_system_test_semantic_percent($without_excerpt_count, $published_actual);
    $excerpt_coverage_percent = $published_actual > 0 ? round(100 - $without_excerpt_percent, 2) : 0.0;
    $without_excerpt_issue = $without_excerpt_count > $without_excerpt_limit;
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.3A Cobertura de descripción corta de producto',
        !$without_excerpt_issue,
        'Productos publicados: ' . number_format_i18n($published_actual) . '; sin descripción corta (wp_posts.post_excerpt): ' . number_format_i18n($without_excerpt_count) . ' (' . number_format_i18n($without_excerpt_percent, 2) . '%; límite ' . number_format_i18n($without_excerpt_limit) . '); cobertura: ' . number_format_i18n($excerpt_coverage_percent, 2) . '%. Fuente canónica: WordPress/WooCommerce post_excerpt.',
        $without_excerpt_issue ? 'ko' : 'ok',
        array(
            'owner' => 'contenido',
            'area' => 'semantic',
            'evidence' => array(
                'published' => $published_actual,
                'with_excerpt' => max(0, $published_actual - $without_excerpt_count),
                'without_excerpt' => $without_excerpt_count,
                'missing_percent' => $without_excerpt_percent,
                'coverage_percent' => $excerpt_coverage_percent,
                'source' => 'wp_posts.post_excerpt',
                'configured_limit' => $without_excerpt_limit,
                'examples' => $products['without_excerpt_examples'] ?? array(),
            ),
            'confidence' => 99,
        )
    );

    // Mantiene la clave de ajuste legacy por compatibilidad, pero ahora solo regula
    // cuando una revision heuristica aparece como warning; nunca la convierte en fail.
    $review_limit = (int) seo_core_system_test_semantic_setting('semantic_suspicious_attribute_limit', 0);
    $title_like_limit = (int) seo_core_system_test_semantic_setting('semantic_title_like_tag_limit', 0);
    $without_attributes_limit = (int) seo_core_system_test_semantic_setting('semantic_without_attributes_limit', 0);
    $attribute_errors = (int) ($products['attribute_error_products'] ?? 0);
    $attribute_reviews = (int) ($products['attribute_review_products'] ?? $products['suspicious_attribute_products']);
    $product_data_critical = (int) $products['invalid_scope'] > 0 || $attribute_errors > 0;
    $product_data_warning = !$product_data_critical && (
        $attribute_reviews > $review_limit
        || (int) $products['title_like_custom_tags'] > $title_like_limit
        || (int) $products['without_seo_attributes'] > $without_attributes_limit
    );
    $product_data_severity = $product_data_critical ? 'ko' : ($product_data_warning ? 'warning' : 'ok');
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.4 Atributos, etiquetas y datos de producto',
        $product_data_severity === 'ok',
        'Errores confirmados de atributos: ' . number_format_i18n($attribute_errors) . '; para revisión heurística: ' . number_format_i18n($attribute_reviews) . ' (umbral informativo ' . number_format_i18n($review_limit) . '); coincidencias SKU con modelo/MPN/referencia: ' . number_format_i18n((int) ($products['attribute_identifier_matches_sku'] ?? 0)) . ' (informativo); sin atributos SEO: ' . number_format_i18n($products['without_seo_attributes']) . ' (límite ' . number_format_i18n($without_attributes_limit) . '); etiquetas que parecen títulos: ' . number_format_i18n($products['title_like_custom_tags']) . ' (límite ' . number_format_i18n($title_like_limit) . '); ámbitos inválidos: ' . number_format_i18n($products['invalid_scope']) . '.',
        $product_data_severity,
        array(
            'owner' => 'contenido',
            'area' => 'semantic',
            'evidence' => array_merge($products, array(
                'classification' => array(
                    'error' => 'Inconsistencia estructural o semántica con evidencia fuerte.',
                    'review' => 'Heurística que requiere revisión humana y no penaliza la puntuación.',
                    'info' => 'Coincidencias legítimas como SKU = modelo/MPN/referencia.',
                ),
                'configured_limits' => array('attribute_review' => $review_limit, 'title_like_tags' => $title_like_limit, 'without_attributes' => $without_attributes_limit),
            )),
            'health_impact' => $product_data_critical ? 1 : ($product_data_warning ? 0 : 1),
            'confidence' => $product_data_critical ? 96 : 90,
        )
    );

    $image_without_valid = (int) ($products['without_any_valid_image'] ?? 0);
    $image_pending_retry = (int) ($products['external_pending_retry'] ?? 0);
    $image_severity = $image_without_valid > 0 ? 'warning' : 'info';
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.4B Cobertura efectiva de imágenes de producto',
        $image_without_valid === 0,
        'Con imagen local válida: ' . number_format_i18n($products['with_local_image'] ?? 0) . '; con imagen externa registrada: ' . number_format_i18n($products['with_external_image'] ?? 0) . '; externas validadas HTTP 2xx: ' . number_format_i18n($products['with_external_validated'] ?? 0) . '; con ambas fuentes: ' . number_format_i18n($products['with_both_images'] ?? 0) . '; sin ninguna imagen válida confirmada: ' . number_format_i18n($image_without_valid) . '; externas pendientes de reintento/verificación: ' . number_format_i18n($image_pending_retry) . '. La ausencia de imagen destacada local no es un problema si existe una fuente externa válida.',
        $image_severity,
        array(
            'status' => $image_without_valid > 0 ? 'warning' : 'info',
            'owner' => 'contenido',
            'area' => 'semantic',
            'evidence' => array(
                'with_local_image' => (int) ($products['with_local_image'] ?? 0),
                'with_external_image' => (int) ($products['with_external_image'] ?? 0),
                'with_external_validated' => (int) ($products['with_external_validated'] ?? 0),
                'with_both_images' => (int) ($products['with_both_images'] ?? 0),
                'without_any_image_source' => (int) ($products['without_any_image_source'] ?? 0),
                'without_any_valid_image' => $image_without_valid,
                'external_pending_retry' => $image_pending_retry,
                'external_known_missing' => (int) ($products['external_known_missing'] ?? 0),
                'local_only_absence_affects_health' => false,
            ),
            'confidence' => 90,
        )
    );

    $alignment_review = (int) ($products['supplier_category_review'] ?? 0);
    $alignment_broad = (int) ($products['supplier_category_review_broad'] ?? 0);
    $alignment_errors = (int) ($products['category_alignment_errors'] ?? 0);
    $alignment_has_error = $alignment_errors > 0;
    $results[] = seo_core_system_test_result(
        'semantic',
        '10.5 Alineación producto-categoría',
        !$alignment_has_error,
        'Contradicciones fuertes confirmables por reglas conservadoras: ' . number_format_i18n($alignment_errors) . '; candidatos refinados a revisión: ' . number_format_i18n($alignment_review) . '; candidatos del detector histórico amplio: ' . number_format_i18n($alignment_broad) . ' (informativo). Las señales de revisión no afectan a la salud del plugin ni implican que el producto esté mal categorizado.',
        $alignment_has_error ? 'ko' : 'info',
        array(
            'status' => $alignment_has_error ? 'fail' : 'info',
            'owner' => 'SEO',
            'area' => 'semantic',
            'evidence' => array(
                'confirmed_strong_contradictions' => $alignment_errors,
                'review_candidates' => $alignment_review,
                'broad_legacy_candidates' => $alignment_broad,
                'comparison_priority' => array('product_title', 'supplier_leaf_category', 'assigned_leaf_category', 'product_tags_and_attributes', 'semantic_equivalence', 'supplier_full_path_as_secondary_evidence'),
                'hierarchy_depth_difference_is_error' => false,
                'review_affects_health' => false,
                'error_examples' => $products['alignment_error_examples'] ?? array(),
                'review_examples' => $products['alignment_examples'] ?? array(),
                'broad_examples' => $products['alignment_broad_examples'] ?? array(),
            ),
            'confidence' => $alignment_has_error ? 92 : 85,
        )
    );

    if (empty($faqs['available'])) {
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.6 Integridad de FAQs',
            true,
            'La tabla de FAQs no está disponible; el chequeo no es evaluable.',
            'info',
            array('status' => 'not_evaluable', 'owner' => 'contenido', 'blocked_by' => 'FAQ_TABLE_UNAVAILABLE', 'coverage' => 0, 'confidence' => 0)
        );
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.7 Utilidad editorial de FAQs',
            true,
            'La tabla de FAQs no está disponible; el chequeo no es evaluable.',
            'info',
            array('status' => 'not_evaluable', 'owner' => 'contenido', 'blocked_by' => 'FAQ_TABLE_UNAVAILABLE', 'coverage' => 0, 'confidence' => 0)
        );
    } else {
        $faq_integrity_issue = (int) ($faqs['duplicate_questions_same_object']['active_extra_rows'] ?? 0) > 0
            || (int) $faqs['orphan_rows'] > 0
            || (int) $faqs['invalid_object_type'] > 0
            || (int) ($faqs['invalid_active_state'] ?? 0) > 0
            || (int) ($faqs['active_on_unpublished_product'] ?? 0) > 0;
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.6 Integridad de FAQs',
            !$faq_integrity_issue,
            'FAQs totales: ' . number_format_i18n($faqs['total']) . '; copias activas sobrantes dentro del mismo objeto: ' . number_format_i18n($faqs['duplicate_questions_same_object']['active_extra_rows'] ?? 0) . '; huérfanas/relaciones rotas: ' . number_format_i18n($faqs['orphan_rows']) . '; tipos de objeto inválidos: ' . number_format_i18n($faqs['invalid_object_type']) . '; estados active inválidos: ' . number_format_i18n($faqs['invalid_active_state'] ?? 0) . '; activas sobre productos no publicados: ' . number_format_i18n($faqs['active_on_unpublished_product']) . '. Metadatos scope legacy: columna ' . (!empty($faqs['legacy_scope_column']) ? 'presente' : 'ausente') . '; no reconocidos: ' . number_format_i18n($faqs['legacy_scope_invalid'] ?? 0) . '; desalineados: ' . number_format_i18n($faqs['legacy_scope_mismatch'] ?? 0) . ' (informativo; no afecta a la integridad ni a la publicación).',
            $faq_integrity_issue ? 'ko' : 'ok',
            array(
                'owner' => 'contenido',
                'area' => 'semantic',
                'evidence' => array_merge($faqs, array('canonical_relation' => 'object_type + object_id', 'legacy_scope_affects_integrity' => false)),
                'confidence' => 98,
            )
        );

        $faq_empty_issues = (int) ($faqs['empty_questions'] ?? 0) + (int) ($faqs['empty_answers'] ?? 0);
        $faq_editorial_warnings = (int) ($faqs['template_questions'] ?? 0)
            + (int) ($faqs['template_answers'] ?? 0)
            + (int) ($faqs['attribute_only_questions'] ?? 0)
            + (int) ($faqs['attribute_only_answers'] ?? 0)
            + (int) ($faqs['insignificant_answers'] ?? 0)
            + (int) ($faqs['duplicate_answers_same_object']['active_extra_rows'] ?? 0)
            + (int) ($faqs['near_duplicate_questions_same_object']['affected_rows'] ?? 0);
        $faq_editorial_severity = $faq_empty_issues > 0 ? 'ko' : ($faq_editorial_warnings > 0 ? 'warning' : 'ok');
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.7 Utilidad editorial de FAQs',
            $faq_editorial_severity === 'ok',
            'Preguntas vacías: ' . number_format_i18n($faqs['empty_questions'] ?? 0) . '; respuestas vacías: ' . number_format_i18n($faqs['empty_answers'] ?? 0) . '; respuestas casi vacías/insignificantes: ' . number_format_i18n($faqs['insignificant_answers'] ?? 0) . '; respuestas duplicadas activas dentro del mismo objeto: ' . number_format_i18n($faqs['duplicate_answers_same_object']['active_extra_rows'] ?? 0) . '; preguntas casi idénticas dentro del mismo objeto: ' . number_format_i18n($faqs['near_duplicate_questions_same_object']['affected_rows'] ?? 0) . '; preguntas con patrón repetido: ' . number_format_i18n($faqs['template_questions']) . '; respuestas con patrón repetido: ' . number_format_i18n($faqs['template_answers']) . '; preguntas que parecen limitarse a repetir un atributo: ' . number_format_i18n($faqs['attribute_only_questions'] ?? 0) . '; respuestas que copian literalmente un atributo: ' . number_format_i18n($faqs['attribute_only_answers'] ?? 0) . '; respuestas de menos de 40 palabras: ' . number_format_i18n($faqs['very_short_answers']) . ' (métrica informativa, sin impacto por longitud).',
            $faq_editorial_severity,
            array(
                'owner' => 'contenido',
                'area' => 'semantic',
                'evidence' => array_merge($faqs, array('minimum_answer_words_for_health' => null, 'short_answer_affects_health' => false)),
                'confidence' => 90,
            )
        );
    }

    if ($faqs['available']) {
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.8 Cobertura editorial de FAQs',
            true,
            'Productos publicados sin FAQ activa: ' . number_format_i18n($snapshot['products']['without_active_faq'] ?? 0) . '; categorías sin FAQ activa: ' . number_format_i18n($snapshot['categories']['without_active_faq'] ?? 0) . '; respuestas de menos de 40 palabras: ' . number_format_i18n($faqs['very_short_answers'] ?? 0) . '. Estas métricas son informativas y no implican un problema técnico ni editorial por sí mismas.',
            'info',
            array(
                'status' => 'info',
                'health_impact' => 0,
                'owner' => 'contenido',
                'area' => 'semantic',
                'evidence' => array(
                    'products_without_active_faq' => $snapshot['products']['without_active_faq'],
                    'categories_without_active_faq' => $snapshot['categories']['without_active_faq'],
                    'answers_under_40_words' => $faqs['very_short_answers'] ?? 0,
                    'products_over_5' => $faqs['product_objects_over_5_active'],
                    'categories_over_8' => $faqs['category_objects_over_8_active'],
                    'coverage_affects_health' => false,
                    'short_answer_affects_health' => false,
                ),
                'confidence' => 99,
            )
        );
    }

    if (!$structure['available']) {
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.9 Cobertura de clusters y hubs',
            true,
            'No están disponibles las tablas estructurales necesarias.',
            'info',
            array('status' => 'not_evaluable', 'owner' => 'SEO', 'blocked_by' => 'SEMANTIC_TABLES_UNAVAILABLE', 'coverage' => 0, 'confidence' => 0)
        );
    } else {
        $problems = (int) $structure['clusters_without_primary'] + (int) $structure['primary_without_secondary'] + (int) $structure['secondary_without_categories'] + (int) $structure['invalid_post_nodes'] + (int) $structure['invalid_category_relations'];
        $results[] = seo_core_system_test_result(
            'semantic',
            '10.9 Cobertura de clusters y hubs',
            $problems === 0,
            'Clusters: ' . number_format_i18n($structure['clusters']) . '; hubs primarios: ' . number_format_i18n($structure['hub_primary']) . '; hubs secundarios: ' . number_format_i18n($structure['hub_secondary']) . '; categorías fuera de hub secundario: ' . number_format_i18n($structure['categories_without_secondary_hub']) . '; relaciones inválidas: ' . number_format_i18n($structure['invalid_category_relations']) . '.',
            $problems === 0 ? 'ok' : 'warning',
            array('owner' => 'SEO', 'area' => 'semantic', 'evidence' => $structure, 'confidence' => 98)
        );
    }

    $results[] = seo_core_system_test_result(
        'semantic',
        '10.10 Preparación editorial global',
        (int) $snapshot['score'] >= 90,
        'Puntuación orientativa: ' . (int) $snapshot['score'] . '/100. Acciones detectadas: ' . number_format_i18n(count($snapshot['actions'])) . '. La puntuación resume señales observables y no declara que el contenido sea perfecto.',
        (int) $snapshot['score'] >= 90 ? 'ok' : ((int) $snapshot['score'] >= 75 ? 'warning' : 'ko'),
        array('owner' => 'contenido', 'area' => 'semantic', 'evidence' => $snapshot, 'coverage' => 100, 'confidence' => 90)
    );

    update_option('seo_core_system_test_semantic_snapshot', $snapshot, false);

    return $results;
}

/**
 * Devuelve el ultimo snapshot. Solo recalcula si todavia no existe.
 */
function seo_core_system_test_get_semantic_snapshot() {
    $snapshot = get_option('seo_core_system_test_semantic_snapshot', array());

    if (!is_array($snapshot) || empty($snapshot) || (int) ($snapshot['schema_version'] ?? 0) < 2) {
        $snapshot = seo_core_system_test_semantic_snapshot();
    }

    return $snapshot;
}
