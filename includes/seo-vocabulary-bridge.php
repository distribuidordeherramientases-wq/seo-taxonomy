<?php
/**
 * Puente de compatibilidad entre el almacenamiento legacy de Ámbito
 * (wp_seo_nodes y otros datos históricos) y el vocabulario semántico nuevo.
 *
 * Fuente canónica para productos:
 *   TIPO activo -> wp_seo_type_role_map -> ROL
 *
 * Compatibilidad temporal:
 *   - si no existe TIPO/ROL canónico, se admite el ROL materializado;
 *   - como último fallback de lectura se puede consultar seo_nodes/product/ambito;
 *   - la cabecera CSV legacy "ambito" se interpreta como alias de ROL.
 *
 * Importante: el vocabulario canónico ya no se replica automáticamente en
 * seo_nodes/product/ambito. Esa tabla queda únicamente como fallback de lectura
 * mientras se completa la retirada del modelo legacy.
 *
 * Las categorías NO se derivan del ROL de sus productos en esta fase.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_catalog_allowed_roles')) {
    function seo_catalog_allowed_roles() {
        return [
            'accesorio',
            'herramienta',
            'repuesto',
            'equipamiento',
            'consumible',
        ];
    }
}

if (!function_exists('seo_catalog_normalize_role')) {
    function seo_catalog_normalize_role($role) {
        $role = sanitize_key(remove_accents(mb_strtolower(trim((string) $role))));

        $aliases = [
            'accesorios'     => 'accesorio',
            'herramientas'   => 'herramienta',
            'recambio'       => 'repuesto',
            'recambios'      => 'repuesto',
            'repuestos'      => 'repuesto',
            'equipamientos'  => 'equipamiento',
            'consumibles'    => 'consumible',
        ];

        if (isset($aliases[$role])) {
            $role = $aliases[$role];
        }

        return in_array($role, seo_catalog_allowed_roles(), true) ? $role : '';
    }
}

if (!function_exists('seo_catalog_table_exists')) {
    function seo_catalog_table_exists($table) {
        global $wpdb;

        $table = (string) $table;
        if ($table === '') {
            return false;
        }

        $like = $wpdb->esc_like($table);
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
    }
}

/**
 * Devuelve ROL canónico por producto.
 *
 * Prioridad:
 * 1) TIPO activo -> wp_seo_type_role_map -> ROL.
 * 2) ROL materializado activo en wp_seo_object_vocabulary.
 *
 * @param int[] $product_ids Vacío = todos los productos con clasificación.
 * @return array<int,string> product_id => role_slug
 */
if (!function_exists('seo_catalog_get_product_roles')) {
    function seo_catalog_get_product_roles($product_ids = []) {
        global $wpdb;

        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        $result = [];

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';

        if (!seo_catalog_table_exists($vocabulary_table) || !seo_catalog_table_exists($object_table)) {
            return $result;
        }

        $id_where = '';
        if (!empty($product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
            $id_where = $wpdb->prepare(" AND ot.object_id IN ({$placeholders})", ...$product_ids);
        }

        // 1) Fuente canónica: TIPO -> mapa -> ROL.
        if (seo_catalog_table_exists($type_role_table)) {
            $rows = $wpdb->get_results(
                "SELECT ot.object_id, rv.slug
                 FROM {$object_table} ot
                 JOIN {$vocabulary_table} tv
                   ON tv.id = ot.vocabulary_id
                  AND tv.semantic_group = 'tipo'
                  AND tv.active = 1
                 JOIN {$type_role_table} trm
                   ON trm.type_vocabulary_id = tv.id
                  AND trm.active = 1
                 JOIN {$vocabulary_table} rv
                   ON rv.id = trm.role_vocabulary_id
                  AND rv.semantic_group = 'rol'
                  AND rv.active = 1
                 WHERE ot.object_type = 'product'
                   AND ot.status = 1
                   {$id_where}",
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $product_id = absint($row['object_id'] ?? 0);
                $role = seo_catalog_normalize_role($row['slug'] ?? '');
                if ($product_id > 0 && $role !== '') {
                    $result[$product_id] = $role;
                }
            }
        }

        // 2) Fallback: ROL materializado, solo para productos aún no resueltos.
        $rows = $wpdb->get_results(
            "SELECT ro.object_id, rv.slug
             FROM {$object_table} ro
             JOIN {$vocabulary_table} rv
               ON rv.id = ro.vocabulary_id
              AND rv.semantic_group = 'rol'
              AND rv.active = 1
             WHERE ro.object_type = 'product'
               AND ro.status = 1
               {$id_where}",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $product_id = absint($row['object_id'] ?? 0);
            if ($product_id < 1 || isset($result[$product_id])) {
                continue;
            }

            $role = seo_catalog_normalize_role($row['slug'] ?? '');
            if ($role !== '') {
                $result[$product_id] = $role;
            }
        }

        return $result;
    }
}

if (!function_exists('seo_catalog_get_product_legacy_ambito')) {
    function seo_catalog_get_product_legacy_ambito($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return '';
        }

        $nodes_table = $wpdb->prefix . 'seo_nodes';
        if (!seo_catalog_table_exists($nodes_table)) {
            return '';
        }

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT keywords
                 FROM {$nodes_table}
                 WHERE object_type = 'product'
                   AND object_id = %d
                   AND seo_role = 'ambito'
                   AND status = 1
                 ORDER BY id ASC
                 LIMIT 1",
                $product_id
            )
        );

        return seo_catalog_normalize_role($value);
    }
}

/**
 * Devuelve el ROL/Ámbito efectivo de un producto.
 *
 * @param int  $product_id
 * @param bool $fallback_legacy
 * @return string
 */
if (!function_exists('seo_catalog_get_product_role')) {
    function seo_catalog_get_product_role($product_id, $fallback_legacy = true) {
        $product_id = absint($product_id);
        if ($product_id < 1) {
            return '';
        }

        $roles = seo_catalog_get_product_roles([$product_id]);
        if (isset($roles[$product_id])) {
            return $roles[$product_id];
        }

        return $fallback_legacy ? seo_catalog_get_product_legacy_ambito($product_id) : '';
    }
}

/**
 * Devuelve únicamente el ROL derivado de un TIPO activo.
 * No consulta el ROL materializado ni seo_nodes.
 *
 * @param int $product_id
 * @return string
 */
if (!function_exists('seo_catalog_get_product_role_from_type')) {
    function seo_catalog_get_product_role_from_type($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return '';
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';
        if (
            !seo_catalog_table_exists($vocabulary_table)
            || !seo_catalog_table_exists($object_table)
            || !seo_catalog_table_exists($type_role_table)
        ) {
            return '';
        }

        $role = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rv.slug\n"
                . "FROM {$object_table} ot\n"
                . "JOIN {$vocabulary_table} tv\n"
                . "  ON tv.id = ot.vocabulary_id\n"
                . " AND tv.semantic_group = 'tipo'\n"
                . " AND tv.active = 1\n"
                . "JOIN {$type_role_table} trm\n"
                . "  ON trm.type_vocabulary_id = tv.id\n"
                . " AND trm.active = 1\n"
                . "JOIN {$vocabulary_table} rv\n"
                . "  ON rv.id = trm.role_vocabulary_id\n"
                . " AND rv.semantic_group = 'rol'\n"
                . " AND rv.active = 1\n"
                . "WHERE ot.object_type = 'product'\n"
                . "  AND ot.object_id = %d\n"
                . "  AND ot.status = 1\n"
                . "LIMIT 1",
                $product_id
            )
        );

        return seo_catalog_normalize_role($role);
    }
}

/**
 * Replica un ROL canónico en el nodo legacy product/ambito.
 * Se utiliza únicamente como compatibilidad temporal.
 */
if (!function_exists('seo_catalog_mirror_product_role_to_legacy')) {
    function seo_catalog_mirror_product_role_to_legacy($product_id, $role) {
        $product_id = absint($product_id);
        $role = seo_catalog_normalize_role($role);

        if ($product_id < 1 || $role === '') {
            return false;
        }

        // legacy-engine.php define esta puerta de escritura y conserva su API pública.
        if (function_exists('seo_ie_upsert_node_value')) {
            seo_ie_upsert_node_value('product', $product_id, 'ambito', $role);
            return true;
        }

        return false;
    }
}

/**
 * Asigna un ROL materializado a un producto cuando todavía no existe un TIPO
 * canónico capaz de derivarlo. Está pensado como puente para importaciones
 * legacy y no sustituye el mapa TIPO -> ROL.
 */
if (!function_exists('seo_catalog_assign_provisional_product_role')) {
    function seo_catalog_assign_provisional_product_role($product_id, $role, $source = 'legacy_import_bridge', $confidence = null) {
        global $wpdb;

        $product_id = absint($product_id);
        $role = seo_catalog_normalize_role($role);
        $source = substr(sanitize_key((string) $source), 0, 30);

        if ($product_id < 1 || $role === '') {
            return false;
        }

        // Si ya existe TIPO -> ROL, nunca permitimos que un CSV lo contradiga.
        $derived = function_exists('seo_catalog_get_product_role_from_type')
            ? seo_catalog_get_product_role_from_type($product_id)
            : '';
        if ($derived !== '') {
            return $derived === $role;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';

        $role_id = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$vocabulary_table}
                 WHERE semantic_group = 'rol'
                   AND slug = %s
                   AND active = 1
                 LIMIT 1",
                $role
            )
        ));

        if ($role_id < 1) {
            return false;
        }

        $wpdb->query('START TRANSACTION');

        $deactivated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$object_table} ro
                 JOIN {$vocabulary_table} rv ON rv.id = ro.vocabulary_id
                 SET ro.status = 0, ro.updated_at = CURRENT_TIMESTAMP
                 WHERE ro.object_type = 'product'
                   AND ro.object_id = %d
                   AND ro.status = 1
                   AND rv.semantic_group = 'rol'",
                $product_id
            )
        );

        if ($deactivated === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $existing_id = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$object_table}
                 WHERE object_type = 'product'
                   AND object_id = %d
                   AND vocabulary_id = %d
                 LIMIT 1",
                $product_id,
                $role_id
            )
        ));

        $data = [
            'source'     => $source !== '' ? $source : 'legacy_import_bridge',
            'status'     => 1,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%s', '%d', '%s'];

        if ($confidence !== null && is_numeric($confidence)) {
            $data['confidence'] = max(0, min(1, (float) $confidence));
            $formats[] = '%f';
        }

        if ($existing_id > 0) {
            $ok = $wpdb->update($object_table, $data, ['id' => $existing_id], $formats, ['%d']);
        } else {
            $insert = [
                'object_type'  => 'product',
                'object_id'    => $product_id,
                'vocabulary_id'=> $role_id,
                'source'       => $data['source'],
                'confidence'   => $confidence !== null && is_numeric($confidence) ? max(0, min(1, (float) $confidence)) : null,
                'status'       => 1,
            ];
            $ok = $wpdb->insert($object_table, $insert, ['%s', '%d', '%d', '%s', '%f', '%d']);
        }

        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');

        return true;
    }
}

/**
 * Resincroniza el ROL materializado de un producto desde su TIPO activo.
 * Función preparada para el clasificador/importador futuro.
 */
if (!function_exists('seo_catalog_sync_product_role_from_type')) {
    function seo_catalog_sync_product_role_from_type($product_id, $source = 'catalog_role_sync') {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return false;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';

        if (!seo_catalog_table_exists($type_role_table)) {
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rv.slug AS role_slug, trm.confidence
                 FROM {$object_table} ot
                 JOIN {$vocabulary_table} tv
                   ON tv.id = ot.vocabulary_id
                  AND tv.semantic_group = 'tipo'
                  AND tv.active = 1
                 JOIN {$type_role_table} trm
                   ON trm.type_vocabulary_id = tv.id
                  AND trm.active = 1
                 JOIN {$vocabulary_table} rv
                   ON rv.id = trm.role_vocabulary_id
                  AND rv.semantic_group = 'rol'
                  AND rv.active = 1
                 WHERE ot.object_type = 'product'
                   AND ot.object_id = %d
                   AND ot.status = 1
                 LIMIT 1",
                $product_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['role_slug'])) {
            return false;
        }

        // La función provisional no puede usarse porque detectaría el TIPO y saldría antes.
        $role = seo_catalog_normalize_role($row['role_slug']);
        if ($role === '') {
            return false;
        }

        $role_id = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$vocabulary_table}
                 WHERE semantic_group='rol' AND slug=%s AND active=1 LIMIT 1",
                $role
            )
        ));
        if ($role_id < 1) {
            return false;
        }

        $source = substr(sanitize_key((string) $source), 0, 30);
        $confidence = isset($row['confidence']) && is_numeric($row['confidence']) ? (float) $row['confidence'] : null;

        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$object_table} ro
                 JOIN {$vocabulary_table} rv ON rv.id=ro.vocabulary_id
                 SET ro.status=0, ro.updated_at=CURRENT_TIMESTAMP
                 WHERE ro.object_type='product'
                   AND ro.object_id=%d
                   AND ro.status=1
                   AND rv.semantic_group='rol'",
                $product_id
            )
        );
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $existing_id = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$object_table}
                 WHERE object_type='product' AND object_id=%d AND vocabulary_id=%d LIMIT 1",
                $product_id,
                $role_id
            )
        ));

        if ($existing_id > 0) {
            $data = [
                'source' => $source !== '' ? $source : 'catalog_role_sync',
                'status' => 1,
                'updated_at' => current_time('mysql'),
            ];
            $formats = ['%s', '%d', '%s'];
            if ($confidence !== null) {
                $data['confidence'] = $confidence;
                $formats[] = '%f';
            }
            $ok = $wpdb->update($object_table, $data, ['id' => $existing_id], $formats, ['%d']);
        } else {
            $ok = $wpdb->insert(
                $object_table,
                [
                    'object_type' => 'product',
                    'object_id' => $product_id,
                    'vocabulary_id' => $role_id,
                    'source' => $source !== '' ? $source : 'catalog_role_sync',
                    'confidence' => $confidence,
                    'status' => 1,
                ],
                ['%s', '%d', '%d', '%s', '%f', '%d']
            );
        }

        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');

        return true;
    }
}




/**
 * Grupos canónicos de vocabulario admitidos para productos.
 *
 * @return string[]
 */
if (!function_exists('seo_catalog_product_vocabulary_groups')) {
    function seo_catalog_product_vocabulary_groups() {
        return ['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'];
    }
}

/**
 * Busca un término activo del vocabulario por slug o etiqueta visible.
 * No crea términos: el importador debe trabajar exclusivamente contra el
 * diccionario canónico ya gestionado en WordPress.
 *
 * @param string $group Grupo semántico.
 * @param string $value Slug o etiqueta.
 * @return array|null
 */
if (!function_exists('seo_catalog_find_active_vocabulary_term')) {
    function seo_catalog_find_active_vocabulary_term($group, $value) {
        global $wpdb;

        $group = sanitize_key($group);
        $value = trim((string) $value);
        if ($value === '' || !in_array($group, seo_catalog_product_vocabulary_groups(), true)) {
            return null;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        if (!seo_catalog_table_exists($vocabulary_table)) {
            return null;
        }

        $slug = sanitize_title($value);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, semantic_group, slug, label\n"
                . "FROM {$vocabulary_table}\n"
                . "WHERE semantic_group = %s\n"
                . "  AND active = 1\n"
                . "  AND (slug = %s OR label = %s)\n"
                . "ORDER BY CASE WHEN slug = %s THEN 0 ELSE 1 END, id ASC\n"
                . "LIMIT 1",
                $group,
                $slug,
                sanitize_text_field($value),
                $slug
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}

/**
 * Sustituye las asignaciones activas de un grupo canónico para un producto.
 * Las asignaciones que no cambian se conservan; las nuevas quedan marcadas con
 * el origen indicado. TIPO y ROL son de valor único.
 *
 * @param int      $product_id
 * @param string   $group
 * @param int[]    $vocabulary_ids
 * @param string   $source
 * @param float|null $confidence
 * @return bool
 */
if (!function_exists('seo_catalog_replace_product_vocabulary_group')) {
    function seo_catalog_replace_product_vocabulary_group($product_id, $group, array $vocabulary_ids, $source = 'csv_import', $confidence = 1.0) {
        global $wpdb;

        $product_id = absint($product_id);
        $group = sanitize_key($group);
        $source = substr(sanitize_key((string) $source), 0, 30);
        if ($source === '') {
            $source = 'csv_import';
        }

        if ($product_id < 1 || !in_array($group, seo_catalog_product_vocabulary_groups(), true)) {
            return false;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_catalog_table_exists($vocabulary_table) || !seo_catalog_table_exists($object_table)) {
            return false;
        }

        $vocabulary_ids = array_values(array_unique(array_filter(array_map('absint', $vocabulary_ids))));
        if (in_array($group, ['rol', 'tipo'], true) && count($vocabulary_ids) > 1) {
            return false;
        }

        if ($vocabulary_ids) {
            $placeholders = implode(',', array_fill(0, count($vocabulary_ids), '%d'));
            $params = array_merge([$group], $vocabulary_ids);
            $valid_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$vocabulary_table}\n"
                    . "WHERE semantic_group = %s AND active = 1\n"
                    . "  AND id IN ({$placeholders})",
                    ...$params
                )
            );
            $valid_ids = array_values(array_unique(array_map('intval', (array) $valid_ids)));
            sort($valid_ids);
            $expected = $vocabulary_ids;
            sort($expected);
            if ($valid_ids !== $expected) {
                return false;
            }
            $vocabulary_ids = $valid_ids;
        }

        $current_ids = array_values(array_unique(array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ov.vocabulary_id\n"
                    . "FROM {$object_table} ov\n"
                    . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                    . "WHERE ov.object_type = 'product'\n"
                    . "  AND ov.object_id = %d\n"
                    . "  AND ov.status = 1\n"
                    . "  AND v.semantic_group = %s",
                    $product_id,
                    $group
                )
            )
        )));

        $to_remove = array_values(array_diff($current_ids, $vocabulary_ids));
        $to_add = array_values(array_diff($vocabulary_ids, $current_ids));

        $wpdb->query('START TRANSACTION');

        if ($to_remove) {
            $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
            $params = array_merge([$product_id, $group], $to_remove);
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$object_table} ov\n"
                    . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                    . "SET ov.status = 0, ov.updated_at = CURRENT_TIMESTAMP\n"
                    . "WHERE ov.object_type = 'product'\n"
                    . "  AND ov.object_id = %d\n"
                    . "  AND v.semantic_group = %s\n"
                    . "  AND ov.vocabulary_id IN ({$placeholders})",
                    ...$params
                )
            );
            if ($updated === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        foreach ($to_add as $vocabulary_id) {
            $confidence_sql = $confidence === null || !is_numeric($confidence)
                ? 'NULL'
                : number_format(max(0, min(1, (float) $confidence)), 4, '.', '');

            $sql = $wpdb->prepare(
                "INSERT INTO {$object_table}\n"
                . "    (object_type, object_id, vocabulary_id, source, confidence, status)\n"
                . "VALUES ('product', %d, %d, %s, {$confidence_sql}, 1)\n"
                . "ON DUPLICATE KEY UPDATE\n"
                . "    source = VALUES(source),\n"
                . "    confidence = VALUES(confidence),\n"
                . "    status = 1,\n"
                . "    updated_at = CURRENT_TIMESTAMP",
                $product_id,
                $vocabulary_id,
                $source
            );

            if ($wpdb->query($sql) === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        $wpdb->query('COMMIT');
        return true;
    }
}

/**
 * Devuelve el ROL activo asociado a un TIPO del vocabulario.
 *
 * @param int $type_vocabulary_id
 * @return array|null
 */
if (!function_exists('seo_catalog_get_role_for_type_vocabulary')) {
    function seo_catalog_get_role_for_type_vocabulary($type_vocabulary_id) {
        global $wpdb;

        $type_vocabulary_id = absint($type_vocabulary_id);
        if ($type_vocabulary_id < 1) {
            return null;
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';
        if (!seo_catalog_table_exists($vocabulary_table) || !seo_catalog_table_exists($type_role_table)) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rv.id, rv.slug, rv.label, trm.confidence\n"
                . "FROM {$type_role_table} trm\n"
                . "JOIN {$vocabulary_table} tv\n"
                . "  ON tv.id = trm.type_vocabulary_id\n"
                . " AND tv.semantic_group = 'tipo'\n"
                . " AND tv.active = 1\n"
                . "JOIN {$vocabulary_table} rv\n"
                . "  ON rv.id = trm.role_vocabulary_id\n"
                . " AND rv.semantic_group = 'rol'\n"
                . " AND rv.active = 1\n"
                . "WHERE trm.type_vocabulary_id = %d\n"
                . "  AND trm.active = 1\n"
                . "LIMIT 1",
                $type_vocabulary_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}



/**
 * Aplica cambios de clasificación semántica ya validados a un producto.
 * ROL no se acepta como entrada: cuando cambia TIPO se sincroniza desde
 * seo_type_role_map para mantener la relación TIPO -> ROL canónica.
 *
 * @param int   $product_id
 * @param array $groups Mapa grupo => IDs de vocabulario objetivo.
 * @param string $source
 * @return array{ok:bool,message:string}
 */
if (!function_exists('seo_catalog_apply_product_vocabulary_changes')) {
    function seo_catalog_apply_product_vocabulary_changes($product_id, array $groups, $source = 'vocabulary_csv') {
        global $wpdb;

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return ['ok' => false, 'message' => 'Identificador de producto no válido.'];
        }

        $allowed = ['tipo', 'aplicacion', 'plataforma', 'subtipo'];
        $source = substr(sanitize_key((string) $source), 0, 30);
        if ($source === '') {
            $source = 'vocabulary_csv';
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_table = $wpdb->prefix . 'seo_type_role_map';
        if (!seo_catalog_table_exists($vocabulary_table) || !seo_catalog_table_exists($object_table)) {
            return ['ok' => false, 'message' => 'No están disponibles las tablas canónicas de vocabulario.'];
        }

        $normalized = [];
        $current = [];
        foreach ($groups as $group => $vocabulary_ids) {
            $group = sanitize_key((string) $group);
            if (!in_array($group, $allowed, true)) {
                continue;
            }

            $ids = is_array($vocabulary_ids) ? $vocabulary_ids : [];
            $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
            sort($ids, SORT_NUMERIC);
            if ($group === 'tipo' && count($ids) !== 1) {
                return ['ok' => false, 'message' => 'TIPO debe contener exactamente un término válido.'];
            }

            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $params = array_merge([$group], $ids);
                $valid_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM {$vocabulary_table}\n"
                        . "WHERE semantic_group = %s AND active = 1\n"
                        . "  AND id IN ({$placeholders})",
                        ...$params
                    )
                );
                $valid_ids = array_values(array_unique(array_map('intval', (array) $valid_ids)));
                sort($valid_ids, SORT_NUMERIC);
                if ($valid_ids !== $ids) {
                    return ['ok' => false, 'message' => 'Algún término de ' . $group . ' ya no existe o está inactivo.'];
                }
            }

            $current_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ov.vocabulary_id\n"
                    . "FROM {$object_table} ov\n"
                    . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                    . "WHERE ov.object_type = 'product'\n"
                    . "  AND ov.object_id = %d\n"
                    . "  AND ov.status = 1\n"
                    . "  AND v.semantic_group = %s",
                    $product_id,
                    $group
                )
            );
            $current_ids = array_values(array_unique(array_map('intval', (array) $current_ids)));
            sort($current_ids, SORT_NUMERIC);

            $normalized[$group] = $ids;
            $current[$group] = $current_ids;
        }

        if (!$normalized) {
            return ['ok' => true, 'message' => ''];
        }

        $role_target_id = 0;
        $role_confidence = 1.0;
        $current_role_ids = [];
        if (isset($normalized['tipo'])) {
            if (!seo_catalog_table_exists($type_role_table)) {
                return ['ok' => false, 'message' => 'No está disponible el mapa TIPO → ROL.'];
            }
            $role_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT rv.id AS role_id, trm.confidence\n"
                    . "FROM {$type_role_table} trm\n"
                    . "JOIN {$vocabulary_table} rv\n"
                    . "  ON rv.id = trm.role_vocabulary_id\n"
                    . " AND rv.semantic_group = 'rol'\n"
                    . " AND rv.active = 1\n"
                    . "WHERE trm.type_vocabulary_id = %d\n"
                    . "  AND trm.active = 1\n"
                    . "LIMIT 1",
                    (int) $normalized['tipo'][0]
                ),
                ARRAY_A
            );
            $role_target_id = is_array($role_row) ? absint($role_row['role_id'] ?? 0) : 0;
            if ($role_target_id < 1) {
                return ['ok' => false, 'message' => 'El TIPO seleccionado no tiene un ROL activo asociado.'];
            }
            if (isset($role_row['confidence']) && is_numeric($role_row['confidence'])) {
                $role_confidence = max(0, min(1, (float) $role_row['confidence']));
            }
            $current_role_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ov.vocabulary_id\n"
                    . "FROM {$object_table} ov\n"
                    . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                    . "WHERE ov.object_type = 'product'\n"
                    . "  AND ov.object_id = %d\n"
                    . "  AND ov.status = 1\n"
                    . "  AND v.semantic_group = 'rol'",
                    $product_id
                )
            );
            $current_role_ids = array_values(array_unique(array_map('intval', (array) $current_role_ids)));
            sort($current_role_ids, SORT_NUMERIC);
        }

        $wpdb->query('START TRANSACTION');

        foreach ($normalized as $group => $target_ids) {
            $current_ids = $current[$group] ?? [];
            $to_remove = array_values(array_diff($current_ids, $target_ids));
            $to_add = array_values(array_diff($target_ids, $current_ids));

            if ($to_remove) {
                $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
                $params = array_merge([$product_id, $group], $to_remove);
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$object_table} ov\n"
                        . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                        . "SET ov.status = 0, ov.updated_at = CURRENT_TIMESTAMP\n"
                        . "WHERE ov.object_type = 'product'\n"
                        . "  AND ov.object_id = %d\n"
                        . "  AND v.semantic_group = %s\n"
                        . "  AND ov.vocabulary_id IN ({$placeholders})",
                        ...$params
                    )
                );
                if ($updated === false) {
                    $wpdb->query('ROLLBACK');
                    return ['ok' => false, 'message' => 'No se pudo retirar la asignación anterior de ' . $group . '.'];
                }
            }

            foreach ($to_add as $vocabulary_id) {
                $sql = $wpdb->prepare(
                    "INSERT INTO {$object_table}\n"
                    . "    (object_type, object_id, vocabulary_id, source, confidence, status)\n"
                    . "VALUES ('product', %d, %d, %s, 1.0000, 1)\n"
                    . "ON DUPLICATE KEY UPDATE\n"
                    . "    source = VALUES(source), confidence = VALUES(confidence), status = 1, updated_at = CURRENT_TIMESTAMP",
                    $product_id,
                    $vocabulary_id,
                    $source
                );
                if ($wpdb->query($sql) === false) {
                    $wpdb->query('ROLLBACK');
                    return ['ok' => false, 'message' => 'No se pudo añadir la nueva asignación de ' . $group . '.'];
                }
            }
        }

        if (isset($normalized['tipo'])) {
            $role_target = [$role_target_id];
            $role_remove = array_values(array_diff($current_role_ids, $role_target));
            $role_add = array_values(array_diff($role_target, $current_role_ids));

            if ($role_remove) {
                $placeholders = implode(',', array_fill(0, count($role_remove), '%d'));
                $params = array_merge([$product_id], $role_remove);
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$object_table} ov\n"
                        . "JOIN {$vocabulary_table} v ON v.id = ov.vocabulary_id\n"
                        . "SET ov.status = 0, ov.updated_at = CURRENT_TIMESTAMP\n"
                        . "WHERE ov.object_type = 'product'\n"
                        . "  AND ov.object_id = %d\n"
                        . "  AND v.semantic_group = 'rol'\n"
                        . "  AND ov.vocabulary_id IN ({$placeholders})",
                        ...$params
                    )
                );
                if ($updated === false) {
                    $wpdb->query('ROLLBACK');
                    return ['ok' => false, 'message' => 'No se pudo retirar el ROL anterior.'];
                }
            }

            foreach ($role_add as $role_id) {
                $confidence_sql = number_format($role_confidence, 4, '.', '');
                $sql = $wpdb->prepare(
                    "INSERT INTO {$object_table}\n"
                    . "    (object_type, object_id, vocabulary_id, source, confidence, status)\n"
                    . "VALUES ('product', %d, %d, %s, {$confidence_sql}, 1)\n"
                    . "ON DUPLICATE KEY UPDATE\n"
                    . "    source = VALUES(source), confidence = VALUES(confidence), status = 1, updated_at = CURRENT_TIMESTAMP",
                    $product_id,
                    $role_id,
                    substr($source . '_role', 0, 30)
                );
                if ($wpdb->query($sql) === false) {
                    $wpdb->query('ROLLBACK');
                    return ['ok' => false, 'message' => 'No se pudo materializar el ROL derivado del TIPO.'];
                }
            }
        }

        $wpdb->query('COMMIT');
        return ['ok' => true, 'message' => ''];
    }
}


/**
 * Devuelve las etiquetas semánticas canónicas destinadas a la ficha pública.
 * Orden: APLICACION -> PLATAFORMA -> SUBTIPO.
 */
if (!function_exists('seo_catalog_get_product_public_semantic_labels')) {
    function seo_catalog_get_product_public_semantic_labels($product_id, $limit = 8) {
        global $wpdb;

        $product_id = absint($product_id);
        $limit = max(1, min(30, absint($limit)));
        if ($product_id < 1) {
            return [];
        }

        $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
        $object_table = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_catalog_table_exists($vocabulary_table) || !seo_catalog_table_exists($object_table)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.semantic_group, v.slug, v.label
                 FROM {$object_table} ov
                 JOIN {$vocabulary_table} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                 WHERE ov.object_type = 'product'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.semantic_group IN ('aplicacion','plataforma','subtipo')
                 ORDER BY FIELD(v.semantic_group, 'aplicacion','plataforma','subtipo'),
                          v.label ASC, v.slug ASC
                 LIMIT %d",
                $product_id,
                $limit
            ),
            ARRAY_A
        );

        $result = [];
        $seen = [];
        foreach ((array) $rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower(remove_accents($label));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [
                'semantic_group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                'slug' => sanitize_title((string) ($row['slug'] ?? '')),
                'label' => $label,
            ];
        }

        return $result;
    }
}
