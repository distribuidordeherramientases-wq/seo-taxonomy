<?php
if (!defined('ABSPATH')) exit;

/**
 * Nombres físicos del vocabulario canónico de atributos de producto.
 */
if (!function_exists('seo_attributes_tables')) {
    function seo_attributes_tables() {
        global $wpdb;
        return [
            'definitions' => $wpdb->prefix . 'sql_atributos',
            'terms'       => $wpdb->prefix . 'sql_atributos_terminos',
            'aliases'     => $wpdb->prefix . 'sql_atributos_aliases',
            'values'      => $wpdb->prefix . 'sql_product_atributos',
        ];
    }
}

if (!function_exists('seo_attributes_table_is_innodb')) {
    function seo_attributes_table_is_innodb($table) {
        global $wpdb;
        $like = $wpdb->esc_like((string) $table);
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $like), ARRAY_A);
        return is_array($status)
            && !empty($status['Name'])
            && strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') === 0;
    }
}

/**
 * Registra las cuatro tablas nuevas en el Data Layer.
 * La clave histórica `attributes` sigue apuntando a wp_seo_attributes si
 * existe; el modelo nuevo usa claves propias para no romper otros módulos.
 */
if (!function_exists('seo_attributes_register_data_layer_table')) {
    function seo_attributes_register_data_layer_table($tables) {
        $tables = is_array($tables) ? $tables : [];
        $physical = seo_attributes_tables();

        // Mantener la clave histórica `attributes` si la tabla antigua sigue
        // presente, para no romper otros módulos durante la transición.
        global $wpdb;
        $legacy = $wpdb->prefix . 'seo_attributes';
        if (seo_attributes_table_is_innodb($legacy)) {
            $tables['attributes'] = [
                'table'       => $legacy,
                'primary_key' => ['id'],
                'entity_type' => 'attribute',
            ];
        }

        $map = [
            'attribute_definitions' => [$physical['definitions'], 'attribute_definition'],
            'attribute_terms'       => [$physical['terms'], 'attribute_term'],
            'attribute_aliases'     => [$physical['aliases'], 'attribute_alias'],
            'product_attributes'    => [$physical['values'], 'product_attribute'],
        ];

        foreach ($map as $key => $config) {
            if (!seo_attributes_table_is_innodb($config[0])) {
                continue;
            }
            $tables[$key] = [
                'table'       => $config[0],
                'primary_key' => ['id'],
                'entity_type' => $config[1],
            ];
        }

        return $tables;
    }
}
add_filter('seo_data_layer_tables', 'seo_attributes_register_data_layer_table');

if (!function_exists('seo_attributes_require_schema')) {
    function seo_attributes_require_schema() {
        $tables = seo_attributes_tables();
        foreach ($tables as $table) {
            if (!seo_attributes_table_is_innodb($table)) {
                throw new RuntimeException('La tabla ' . $table . ' no existe o no usa InnoDB.');
            }
        }
        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se han modificado atributos.');
        }
        return $tables;
    }
}

if (!function_exists('seo_attributes_get_definition')) {
    function seo_attributes_get_definition($attribute_type, $active_only = false) {
        global $wpdb;
        $tables = seo_attributes_tables();
        $slug = sanitize_key((string) $attribute_type);
        if ($slug === '') {
            return null;
        }

        $sql = "SELECT * FROM `{$tables['definitions']}` WHERE slug = %s";
        if ($active_only) {
            $sql .= ' AND activo = 1';
        }
        $sql .= ' LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $slug), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('seo_attributes_usage_count')) {
    function seo_attributes_usage_count($attribute_type) {
        global $wpdb;
        $tables = seo_attributes_tables();
        $definition = seo_attributes_get_definition($attribute_type, false);
        if (!$definition) {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$tables['values']}` WHERE atributo_id = %d",
                (int) $definition['id']
            )
        );
    }
}

if (!function_exists('seo_attributes_resolve_term')) {
    function seo_attributes_resolve_term($attribute_id, $value) {
        global $wpdb;
        $tables = seo_attributes_tables();
        $attribute_id = (int) $attribute_id;
        $value = trim((string) $value);
        if ($attribute_id < 1 || $value === '') {
            return null;
        }

        $slug = sanitize_title($value);
        $term = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, atributo_id, slug, nombre
                 FROM `{$tables['terms']}`
                 WHERE atributo_id = %d
                   AND activo = 1
                   AND (LOWER(nombre) = LOWER(%s) OR slug = %s)
                 LIMIT 1",
                $attribute_id,
                $value,
                $slug
            ),
            ARRAY_A
        );
        if (is_array($term)) {
            return $term;
        }

        $term = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT t.id, t.atributo_id, t.slug, t.nombre
                 FROM `{$tables['aliases']}` al
                 INNER JOIN `{$tables['terms']}` t ON t.id = al.termino_id
                 WHERE al.atributo_id = %d
                   AND t.atributo_id = %d
                   AND t.activo = 1
                   AND LOWER(al.alias) = LOWER(%s)
                 LIMIT 1",
                $attribute_id,
                $attribute_id,
                $value
            ),
            ARRAY_A
        );

        return is_array($term) ? $term : null;
    }
}

/**
 * Convierte el antiguo formato tipo:valor en una fila de
 * wp_sql_product_atributos. Para atributos de tipo termino solo se aceptan
 * términos o aliases existentes: no se crea vocabulario de forma implícita.
 */
if (!function_exists('seo_attributes_prepare_product_row')) {
    function seo_attributes_prepare_product_row($product_id, $attribute_type, $attribute_value, $strict = true) {
        $product_id = (int) $product_id;
        $type = sanitize_key((string) $attribute_type);
        $value = sanitize_textarea_field(trim((string) $attribute_value));

        if ($product_id < 1 || $type === '' || $value === '') {
            return null;
        }

        $definition = seo_attributes_get_definition($type, true);
        if (!$definition) {
            if ($strict) {
                throw new InvalidArgumentException('El atributo «' . $type . '» no existe o está inactivo en el vocabulario canónico.');
            }
            return null;
        }

        $row = [
            'product_id'       => $product_id,
            'atributo_id'      => (int) $definition['id'],
            'termino_id'       => null,
            'valor_texto'      => null,
            'valor_numero'     => null,
            'valor_numero_max' => null,
            'unidad'           => null,
            'valor_original'   => $value,
            'orden'            => 0,
        ];

        if ((string) ($definition['tipo'] ?? '') === 'termino') {
            $term = seo_attributes_resolve_term((int) $definition['id'], $value);
            if (!$term) {
                if ($strict) {
                    throw new InvalidArgumentException(
                        'El valor «' . $value . '» no existe como término/alias de «' . $type . '».'
                    );
                }
                return null;
            }
            $row['termino_id'] = (int) $term['id'];
        } else {
            // En esta fase mantenemos la política de migración: los valores
            // no-vocabulary se conservan fielmente como texto/original. La
            // tipificación numérica puede hacerse después sin perder el dato.
            $row['valor_texto'] = $value;
        }

        return $row;
    }
}

if (!function_exists('seo_attributes_product_row_exists')) {
    function seo_attributes_product_row_exists($row) {
        global $wpdb;
        $tables = seo_attributes_tables();

        if (!empty($row['termino_id'])) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$tables['values']}`
                     WHERE product_id = %d AND atributo_id = %d AND termino_id = %d
                     LIMIT 1",
                    (int) $row['product_id'],
                    (int) $row['atributo_id'],
                    (int) $row['termino_id']
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$tables['values']}`
                 WHERE product_id = %d AND atributo_id = %d
                   AND termino_id IS NULL
                   AND COALESCE(valor_original, '') = %s
                 LIMIT 1",
                (int) $row['product_id'],
                (int) $row['atributo_id'],
                (string) ($row['valor_original'] ?? '')
            )
        );
    }
}

/**
 * Lectura canónica de atributos de producto con el mismo shape básico que
 * consumía la interfaz antigua: attribute_type + attribute_value.
 */
if (!function_exists('seo_attributes_get_product_rows')) {
    function seo_attributes_get_product_rows($product_id) {
        global $wpdb;
        $tables = seo_attributes_tables();
        $product_id = (int) $product_id;
        if ($product_id < 1) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    p.*,
                    a.slug AS attribute_type,
                    a.nombre AS attribute_name,
                    a.tipo AS attribute_data_type,
                    a.unidad_base AS canonical_unit,
                    'global' AS ambito,
                    t.nombre AS term_name,
                    t.slug AS term_slug
                 FROM `{$tables['values']}` p
                 INNER JOIN `{$tables['definitions']}` a ON a.id = p.atributo_id
                 LEFT JOIN `{$tables['terms']}` t ON t.id = p.termino_id
                 WHERE p.product_id = %d
                 ORDER BY a.orden ASC, a.nombre ASC, p.orden ASC, p.id ASC",
                $product_id
            )
        );

        foreach ((array) $rows as $row) {
            if (!empty($row->term_name)) {
                $row->attribute_value = (string) $row->term_name;
                continue;
            }
            if ($row->valor_numero !== null && $row->valor_numero !== '') {
                $value = (string) $row->valor_numero;
                if ($row->valor_numero_max !== null && $row->valor_numero_max !== '') {
                    $value .= ' - ' . (string) $row->valor_numero_max;
                }
                if (!empty($row->unidad)) {
                    $value .= ' ' . (string) $row->unidad;
                }
                $row->attribute_value = $value;
                continue;
            }
            $row->attribute_value = (string) ($row->valor_texto ?? $row->valor_original ?? '');
        }

        return is_array($rows) ? $rows : [];
    }
}

/** Elimina una definición y todas sus asignaciones, términos y aliases. */
if (!function_exists('seo_attributes_delete_global_type')) {
    function seo_attributes_delete_global_type($attribute_type) {
        global $wpdb;
        $tables = seo_attributes_require_schema();
        $definition = seo_attributes_get_definition($attribute_type, false);
        if (!$definition) {
            return [
                'operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0,
                'master_deleted' => 0, 'product_deleted' => 0,
                'terms_deleted' => 0, 'aliases_deleted' => 0,
            ];
        }

        $attribute_id = (int) $definition['id'];
        $product_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, product_id FROM `{$tables['values']}` WHERE atributo_id = %d ORDER BY id", $attribute_id),
            ARRAY_A
        );
        $term_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM `{$tables['terms']}` WHERE atributo_id = %d ORDER BY id", $attribute_id),
            ARRAY_A
        );
        $alias_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM `{$tables['aliases']}` WHERE atributo_id = %d ORDER BY id", $attribute_id),
            ARRAY_A
        );

        $operation = SEO_Data_Layer::operation([
            'type'          => 'delete_attribute_type_global_v2',
            'label'         => 'Eliminar atributo canónico: ' . (string) $definition['slug'],
            'source_module' => 'product_attributes',
            'rollbackable'  => true,
            'risk_level'    => 'high',
            'audit_level'   => 'full',
            'metadata'      => [
                'attribute_id'   => $attribute_id,
                'attribute_slug' => (string) $definition['slug'],
                'product_rows'   => count((array) $product_rows),
                'term_rows'      => count((array) $term_rows),
                'alias_rows'     => count((array) $alias_rows),
            ],
        ]);
        $operation->mark_validated(['validated_rows' => count((array) $product_rows) + count((array) $term_rows) + count((array) $alias_rows) + 1]);
        $operation->mark_previewed(count((array) $product_rows) + count((array) $term_rows) + count((array) $alias_rows) + 1, []);

        $deleted = $operation->execute(static function (SEO_Data_Operation $op) use ($product_rows, $term_rows, $alias_rows, $attribute_id) {
            $count = 0;
            foreach ((array) $product_rows as $row) {
                $op->delete('product_attributes', ['id' => (int) $row['id']], [
                    'related_object_type' => 'product',
                    'related_object_id' => (int) $row['product_id'],
                    'reason' => 'delete_attribute_type_global_v2',
                ]);
                $count++;
            }
            foreach ((array) $alias_rows as $row) {
                $op->delete('attribute_aliases', ['id' => (int) $row['id']], ['reason' => 'delete_attribute_type_global_v2']);
                $count++;
            }
            foreach ((array) $term_rows as $row) {
                $op->delete('attribute_terms', ['id' => (int) $row['id']], ['reason' => 'delete_attribute_type_global_v2']);
                $count++;
            }
            $op->delete('attribute_definitions', ['id' => $attribute_id], ['reason' => 'delete_attribute_type_global_v2']);
            return $count + 1;
        });

        return [
            'operation_id'    => $operation->id(),
            'operation_uuid'  => $operation->uuid(),
            'deleted'         => (int) $deleted,
            'master_deleted'  => 1,
            'product_deleted' => count((array) $product_rows),
            'terms_deleted'   => count((array) $term_rows),
            'aliases_deleted' => count((array) $alias_rows),
        ];
    }
}

/** Sustituye el conjunto completo de atributos canónicos de un producto. */
if (!function_exists('seo_attributes_replace_product')) {
    function seo_attributes_replace_product($product_id, $attributes, $source_module = 'product_attributes') {
        global $wpdb;
        $tables = seo_attributes_require_schema();
        $product_id = (int) $product_id;
        if ($product_id < 1) {
            throw new InvalidArgumentException('El ID de producto no es válido.');
        }
        if (!is_array($attributes)) {
            throw new InvalidArgumentException('Los atributos del producto deben recibirse como un array.');
        }

        $normalized = [];
        $seen = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) continue;
            $type = (string) ($attribute['attribute_type'] ?? $attribute['slug'] ?? '');
            $value = (string) ($attribute['attribute_value'] ?? $attribute['value'] ?? '');
            $row = seo_attributes_prepare_product_row($product_id, $type, $value, true);
            if (!$row) continue;
            $key = !empty($row['termino_id'])
                ? ((int) $row['atributo_id'] . ':t:' . (int) $row['termino_id'])
                : ((int) $row['atributo_id'] . ':v:' . mb_strtolower((string) ($row['valor_original'] ?? ''), 'UTF-8'));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $normalized[] = $row;
        }

        $existing = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM `{$tables['values']}` WHERE product_id = %d ORDER BY id", $product_id),
            ARRAY_A
        );
        if (!is_array($existing)) {
            throw new RuntimeException('No se pudieron inventariar los atributos actuales: ' . $wpdb->last_error);
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';
        $operation = SEO_Data_Layer::operation([
            'type'          => 'replace_product_attributes_v2',
            'label'         => 'Actualizar atributos canónicos del producto #' . $product_id,
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => ['product_id' => $product_id, 'previous_count' => count($existing), 'new_count' => count($normalized)],
        ]);
        $operation->mark_validated(['validated_product_id' => $product_id, 'validated_new_rows' => count($normalized)]);
        $operation->mark_previewed(count($existing) + count($normalized), ['preview_delete_rows' => count($existing), 'preview_insert_rows' => count($normalized)]);

        $result = $operation->execute(static function (SEO_Data_Operation $op) use ($existing, $normalized, $product_id) {
            $deleted = 0;
            $inserted = 0;
            foreach ($existing as $row) {
                $op->delete('product_attributes', ['id' => (int) $row['id']], [
                    'related_object_type' => 'product', 'related_object_id' => $product_id,
                    'reason' => 'replace_product_attributes_v2',
                ]);
                $deleted++;
            }
            foreach ($normalized as $row) {
                $op->insert('product_attributes', $row, [
                    'related_object_type' => 'product', 'related_object_id' => $product_id,
                    'reason' => 'replace_product_attributes_v2',
                ]);
                $inserted++;
            }
            return ['deleted' => $deleted, 'inserted' => $inserted];
        });

        return [
            'operation_id' => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
        ];
    }
}

/**
 * Compatibilidad con la antigua alta de "maestros". Crea una definición de
 * texto mínima. La edición completa de tipo/grupo/unidades puede hacerse en
 * la tabla canónica/panel específico sin volver a product_id=0.
 */
if (!function_exists('seo_attributes_add_master_type')) {
    function seo_attributes_add_master_type($attribute_type, $source_module = 'product_attributes') {
        $tables = seo_attributes_require_schema();
        $slug = str_replace('-', '_', sanitize_title(remove_accents((string) $attribute_type)));
        $slug = sanitize_key($slug);
        if ($slug === '') {
            throw new InvalidArgumentException('El tipo de atributo no puede estar vacío.');
        }
        $existing = seo_attributes_get_definition($slug, false);
        if ($existing) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'inserted' => 0, 'existing_id' => (int) $existing['id']];
        }

        $raw_name = trim((string) $attribute_type);
        $name = preg_match('/[ _-]/u', $raw_name)
            ? preg_replace('/[_-]+/u', ' ', $raw_name)
            : $raw_name;
        $name = trim((string) $name);
        if ($name === '') $name = $slug;
        $name = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';
        $operation = SEO_Data_Layer::operation([
            'type' => 'add_attribute_definition_v2',
            'label' => 'Añadir definición de atributo: ' . $slug,
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'low',
            'audit_level' => 'full',
            'metadata' => ['attribute_slug' => $slug],
        ]);
        $operation->mark_validated(['validated_rows' => 1]);
        $operation->mark_previewed(1, ['preview_insert_rows' => 1]);
        $row = $operation->execute(static function (SEO_Data_Operation $op) use ($slug, $name) {
            return $op->insert('attribute_definitions', [
                'slug' => $slug,
                'nombre' => $name,
                'grupo' => 'general',
                'tipo' => 'texto',
                'unidad_tipo' => null,
                'unidad_base' => null,
                'multiple' => 0,
                'filtrable' => 0,
                'visible' => 1,
                'seo' => 0,
                'orden' => 999,
                'activo' => 1,
            ], ['related_object_type' => 'attribute_dictionary', 'related_object_id' => 0, 'reason' => 'add_attribute_definition_v2']);
        });

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'inserted' => 1, 'existing_id' => (int) ($row['id'] ?? 0)];
    }
}

/** Elimina una definición solo si no está asignada a productos. */
if (!function_exists('seo_attributes_delete_master_type')) {
    function seo_attributes_delete_master_type($attribute_type, $source_module = 'product_attributes') {
        global $wpdb;
        $tables = seo_attributes_require_schema();
        $definition = seo_attributes_get_definition($attribute_type, false);
        if (!$definition) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0];
        }
        $attribute_id = (int) $definition['id'];
        $usage = seo_attributes_usage_count((string) $definition['slug']);
        if ($usage > 0) {
            throw new RuntimeException('El atributo «' . (string) $definition['slug'] . '» tiene ' . $usage . ' asignaciones. Usa el borrado global si realmente quieres eliminarlo.');
        }

        $terms = $wpdb->get_results($wpdb->prepare("SELECT id FROM `{$tables['terms']}` WHERE atributo_id = %d ORDER BY id", $attribute_id), ARRAY_A);
        $aliases = $wpdb->get_results($wpdb->prepare("SELECT id FROM `{$tables['aliases']}` WHERE atributo_id = %d ORDER BY id", $attribute_id), ARRAY_A);
        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';

        $operation = SEO_Data_Layer::operation([
            'type' => 'delete_attribute_definition_v2',
            'label' => 'Eliminar definición de atributo: ' . (string) $definition['slug'],
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'medium',
            'audit_level' => 'full',
            'metadata' => ['attribute_id' => $attribute_id, 'attribute_slug' => (string) $definition['slug']],
        ]);
        $operation->mark_validated(['validated_rows' => 1 + count((array) $terms) + count((array) $aliases)]);
        $operation->mark_previewed(1 + count((array) $terms) + count((array) $aliases), []);
        $deleted = $operation->execute(static function (SEO_Data_Operation $op) use ($aliases, $terms, $attribute_id) {
            $count = 0;
            foreach ((array) $aliases as $row) {
                $op->delete('attribute_aliases', ['id' => (int) $row['id']], ['reason' => 'delete_attribute_definition_v2']);
                $count++;
            }
            foreach ((array) $terms as $row) {
                $op->delete('attribute_terms', ['id' => (int) $row['id']], ['reason' => 'delete_attribute_definition_v2']);
                $count++;
            }
            $op->delete('attribute_definitions', ['id' => $attribute_id], ['reason' => 'delete_attribute_definition_v2']);
            return $count + 1;
        });

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'deleted' => (int) $deleted];
    }
}

/** Añade asignaciones canónicas sin borrar las ya existentes. */
if (!function_exists('seo_attributes_append_product')) {
    function seo_attributes_append_product($product_id, $attributes, $source_module = 'product_attributes') {
        $product_id = (int) $product_id;
        if ($product_id < 1) throw new InvalidArgumentException('El ID de producto no es válido.');
        if (!is_array($attributes)) throw new InvalidArgumentException('Los atributos deben recibirse como un array.');
        seo_attributes_require_schema();

        $missing = [];
        $skipped = 0;
        $unresolved = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) continue;
            $type = (string) ($attribute['attribute_type'] ?? $attribute['slug'] ?? '');
            $value = (string) ($attribute['attribute_value'] ?? $attribute['value'] ?? '');
            $row = seo_attributes_prepare_product_row($product_id, $type, $value, false);
            if (!$row) {
                $unresolved[] = sanitize_key($type) . ':' . sanitize_text_field($value);
                $skipped++;
                continue;
            }
            $key = !empty($row['termino_id'])
                ? ((int) $row['atributo_id'] . ':t:' . (int) $row['termino_id'])
                : ((int) $row['atributo_id'] . ':v:' . mb_strtolower((string) ($row['valor_original'] ?? ''), 'UTF-8'));
            if (isset($seen[$key]) || seo_attributes_product_row_exists($row) > 0) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;
            $missing[] = $row;
        }

        if (!$missing) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'inserted' => 0, 'skipped' => $skipped, 'unresolved' => $unresolved];
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';
        $operation = SEO_Data_Layer::operation([
            'type' => 'append_product_attributes_v2',
            'label' => 'Añadir atributos canónicos al producto #' . $product_id,
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'low',
            'audit_level' => 'full',
            'metadata' => ['product_id' => $product_id, 'insert_rows' => count($missing), 'skipped_rows' => $skipped, 'unresolved' => $unresolved],
        ]);
        $operation->mark_validated(['validated_rows' => count($missing)]);
        $operation->mark_previewed(count($missing), ['preview_insert_rows' => count($missing)]);
        $inserted = $operation->execute(static function (SEO_Data_Operation $op) use ($missing, $product_id) {
            $count = 0;
            foreach ($missing as $row) {
                $op->insert('product_attributes', $row, [
                    'related_object_type' => 'product', 'related_object_id' => $product_id,
                    'reason' => 'append_product_attributes_v2',
                ]);
                $count++;
            }
            return $count;
        });

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'inserted' => (int) $inserted, 'skipped' => $skipped, 'unresolved' => $unresolved];
    }
}

/** Detecta términos/aliases y valores numéricos a partir del contenido. */
if (!function_exists('seo_detect_title_attributes')) {
    function seo_detect_title_attributes($product_id) {
        global $wpdb;
        $tables = seo_attributes_tables();
        $product_id = (int) $product_id;
        $product = get_post($product_id);
        if (!$product || $product->post_type !== 'product') return [];

        $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
        if (is_wp_error($tags)) $tags = [];

        $normalize = static function ($text) {
            $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
            $text = wp_strip_all_tags($text);
            $text = mb_strtolower(remove_accents($text), 'UTF-8');
            $text = preg_replace('/([0-9]+(?:[,.][0-9]+)?)\s*(mm|cm|m|v|w|kw|wh|kwh|ah|mah|a|hz|mhz|bar|psi|kg|g|lb|t|l|ml|rpm|nm|cfm|lm|k|pin|pins|pines|uds|ud|piezas|pieza|dientes|ºc|°c)\b/u', '$1 $2', $text);
            $text = str_replace(['/', '-', '_', '+', '&', '(', ')', '[', ']', ',', ';', ':'], ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            return trim($text);
        };

        $text = $normalize(implode(' ', [
            (string) $product->post_title,
            (string) $product->post_name,
            (string) $product->post_excerpt,
            (string) $product->post_content,
            implode(' ', (array) $tags),
        ]));

        $out = [];

        // Vocabularios cerrados: buscar término canónico y cualquiera de sus aliases.
        $term_rows = $wpdb->get_results(
            "SELECT a.id AS atributo_id, a.slug AS attribute_type, t.id AS termino_id, t.nombre AS term_name
             FROM `{$tables['definitions']}` a
             INNER JOIN `{$tables['terms']}` t ON t.atributo_id = a.id
             WHERE a.activo = 1 AND a.tipo = 'termino' AND t.activo = 1
             ORDER BY CHAR_LENGTH(t.nombre) DESC, a.orden ASC, t.orden ASC",
            ARRAY_A
        );

        $aliases_by_term = [];
        $alias_rows = $wpdb->get_results(
            "SELECT al.termino_id, al.alias
             FROM `{$tables['aliases']}` al
             INNER JOIN `{$tables['terms']}` t ON t.id = al.termino_id AND t.activo = 1
             INNER JOIN `{$tables['definitions']}` a ON a.id = al.atributo_id AND a.activo = 1",
            ARRAY_A
        );
        foreach ((array) $alias_rows as $alias) {
            $aliases_by_term[(int) $alias['termino_id']][] = (string) $alias['alias'];
        }

        foreach ((array) $term_rows as $row) {
            $candidates = [(string) $row['term_name']];
            foreach ((array) ($aliases_by_term[(int) $row['termino_id']] ?? []) as $alias) {
                $candidates[] = $alias;
            }
            foreach ($candidates as $candidate) {
                $needle = $normalize($candidate);
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 2) continue;
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/u', $text)) {
                    $out[] = (string) $row['attribute_type'] . ':' . (string) $row['term_name'];
                    break;
                }
            }
        }

        // Atributos no-vocabulary: conservar el detector tipo + número/unidad.
        $definitions = $wpdb->get_results(
            "SELECT slug, nombre FROM `{$tables['definitions']}` WHERE activo = 1 AND tipo <> 'termino' ORDER BY orden ASC, nombre ASC",
            ARRAY_A
        );
        $unit_pattern = 'mm|cm|m|v|w|kw|wh|kwh|ah|mah|a|hz|mhz|bar|psi|kg|g|lb|t|l|ml|rpm|nm|cfm|lm|k|pin|pins|pines|uds|ud|piezas|pieza|dientes|ºc|°c';
        foreach ((array) $definitions as $definition) {
            $labels = array_unique([$normalize($definition['nombre'] ?? ''), $normalize($definition['slug'] ?? '')]);
            foreach ($labels as $label) {
                if ($label === '') continue;
                $pattern = preg_quote($label, '/');
                if (preg_match('/(^|[^a-z0-9])' . $pattern . '([^a-z0-9]{0,30})([0-9]+(?:[,.][0-9]+)?\s*(' . $unit_pattern . '))\b/u', $text, $m)) {
                    $out[] = (string) $definition['slug'] . ':' . trim((string) $m[3]);
                    break;
                }
                if (preg_match('/([0-9]+(?:[,.][0-9]+)?\s*(' . $unit_pattern . '))([^a-z0-9]{0,30})' . $pattern . '([^a-z0-9]|$)/u', $text, $m)) {
                    $out[] = (string) $definition['slug'] . ':' . trim((string) $m[1]);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }
}


/**
 * Elimina definiciones canónicas sin ninguna asignación de producto.
 */
if (!function_exists('seo_attributes_delete_unused_master_rows')) {
    function seo_attributes_delete_unused_master_rows($source_module = 'product_attributes_dashboard') {
        global $wpdb;
        $tables = seo_attributes_require_schema();

        $definitions = $wpdb->get_results(
            "SELECT a.id, a.slug
             FROM `{$tables['definitions']}` a
             WHERE NOT EXISTS (
                SELECT 1 FROM `{$tables['values']}` p WHERE p.atributo_id = a.id
             )
             ORDER BY a.id ASC",
            ARRAY_A
        );
        if (!$definitions) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0, 'types' => 0];
        }

        $ids = array_map('intval', wp_list_pluck($definitions, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $terms = $wpdb->get_results($wpdb->prepare("SELECT id FROM `{$tables['terms']}` WHERE atributo_id IN ($placeholders) ORDER BY id", $ids), ARRAY_A);
        $aliases = $wpdb->get_results($wpdb->prepare("SELECT id FROM `{$tables['aliases']}` WHERE atributo_id IN ($placeholders) ORDER BY id", $ids), ARRAY_A);

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $total = count($definitions) + count((array) $terms) + count((array) $aliases);
        $operation = SEO_Data_Layer::operation([
            'type' => 'cleanup_unused_attribute_definitions_v2',
            'label' => 'Limpiar definiciones de atributos sin uso',
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'medium',
            'audit_level' => 'full',
            'metadata' => ['definitions' => count($definitions), 'rows' => $total],
        ]);
        $operation->mark_validated(['validated_rows' => $total]);
        $operation->mark_previewed($total, []);
        $deleted = $operation->execute(static function (SEO_Data_Operation $op) use ($definitions, $terms, $aliases) {
            $count = 0;
            foreach ((array) $aliases as $row) {
                $op->delete('attribute_aliases', ['id' => (int) $row['id']], ['reason' => 'cleanup_unused_attribute_definitions_v2']);
                $count++;
            }
            foreach ((array) $terms as $row) {
                $op->delete('attribute_terms', ['id' => (int) $row['id']], ['reason' => 'cleanup_unused_attribute_definitions_v2']);
                $count++;
            }
            foreach ((array) $definitions as $row) {
                $op->delete('attribute_definitions', ['id' => (int) $row['id']], ['reason' => 'cleanup_unused_attribute_definitions_v2']);
                $count++;
            }
            return $count;
        });

        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'deleted' => (int) $deleted, 'types' => count($definitions)];
    }
}

/** Elimina asignaciones cuyo producto ya no es un producto válido. */
if (!function_exists('seo_attributes_delete_orphan_rows')) {
    function seo_attributes_delete_orphan_rows($source_module = 'product_attributes_dashboard') {
        global $wpdb;
        $tables = seo_attributes_require_schema();
        $rows = $wpdb->get_results(
            "SELECT pa.id, pa.product_id
             FROM `{$tables['values']}` pa
             LEFT JOIN `{$wpdb->posts}` p ON p.ID = pa.product_id
             WHERE p.ID IS NULL
                OR p.post_type <> 'product'
                OR p.post_status IN ('trash','auto-draft')
             ORDER BY pa.id ASC",
            ARRAY_A
        );
        if (!$rows) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0, 'product_ids' => 0];
        }
        $product_ids = array_values(array_unique(array_map('intval', wp_list_pluck($rows, 'product_id'))));
        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $operation = SEO_Data_Layer::operation([
            'type' => 'cleanup_orphan_product_attributes_v2',
            'label' => 'Limpiar atributos de productos eliminados',
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'medium',
            'audit_level' => 'full',
            'metadata' => ['rows' => count($rows), 'product_ids' => count($product_ids)],
        ]);
        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), []);
        $deleted = $operation->execute(static function (SEO_Data_Operation $op) use ($rows) {
            $count = 0;
            foreach ($rows as $row) {
                $op->delete('product_attributes', ['id' => (int) $row['id']], [
                    'related_object_type' => 'product',
                    'related_object_id' => (int) $row['product_id'],
                    'reason' => 'cleanup_orphan_product_attributes_v2',
                ]);
                $count++;
            }
            return $count;
        });
        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'deleted' => (int) $deleted, 'product_ids' => count($product_ids)];
    }
}

/** Elimina únicamente copias exactamente iguales de una asignación. */
if (!function_exists('seo_attributes_delete_exact_duplicates')) {
    function seo_attributes_delete_exact_duplicates($source_module = 'product_attributes_dashboard') {
        global $wpdb;
        $tables = seo_attributes_require_schema();
        $rows = $wpdb->get_results(
            "SELECT DISTINCT a.id, a.product_id
             FROM `{$tables['values']}` a
             INNER JOIN `{$tables['values']}` b
                ON b.product_id = a.product_id
               AND b.atributo_id = a.atributo_id
               AND (b.termino_id <=> a.termino_id)
               AND (b.valor_texto <=> a.valor_texto)
               AND (b.valor_numero <=> a.valor_numero)
               AND (b.valor_numero_max <=> a.valor_numero_max)
               AND (b.unidad <=> a.unidad)
               AND (b.valor_original <=> a.valor_original)
               AND b.id < a.id
             ORDER BY a.id ASC",
            ARRAY_A
        );
        if (!$rows) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0];
        }
        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $operation = SEO_Data_Layer::operation([
            'type' => 'cleanup_exact_product_attribute_duplicates_v2',
            'label' => 'Eliminar duplicados exactos de atributos canónicos',
            'source_module' => $source_module,
            'rollbackable' => true,
            'risk_level' => 'low',
            'audit_level' => 'full',
            'metadata' => ['rows' => count($rows)],
        ]);
        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), []);
        $deleted = $operation->execute(static function (SEO_Data_Operation $op) use ($rows) {
            $count = 0;
            foreach ($rows as $row) {
                $op->delete('product_attributes', ['id' => (int) $row['id']], [
                    'related_object_type' => 'product',
                    'related_object_id' => (int) $row['product_id'],
                    'reason' => 'cleanup_exact_product_attribute_duplicates_v2',
                ]);
                $count++;
            }
            return $count;
        });
        return ['operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid(), 'deleted' => (int) $deleted];
    }
}

/**
 * Dashboard del vocabulario canónico. El parámetro se conserva por
 * compatibilidad con las llamadas antiguas, pero ya no se usa.
 */
if (!function_exists('seo_attributes_render_dashboard')) {
    function seo_attributes_render_dashboard($unused_table = null) {
        global $wpdb;
        $tables = seo_attributes_tables();

        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')"
        );
        $products_with_attributes = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pa.product_id)
             FROM `{$tables['values']}` pa
             INNER JOIN `{$wpdb->posts}` p ON p.ID = pa.product_id
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')"
        );
        $assignment_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['values']}`");
        $definitions_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['definitions']}` WHERE activo = 1");
        $terms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['terms']}` WHERE activo = 1");
        $aliases_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['aliases']}`");
        $products_without_attributes = max(0, $total_products - $products_with_attributes);
        $coverage = $total_products > 0 ? round(($products_with_attributes / $total_products) * 100, 1) : 0;

        $unresolved_vocab = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$tables['values']}` pa
             INNER JOIN `{$tables['definitions']}` a ON a.id = pa.atributo_id
             WHERE a.tipo = 'termino' AND pa.termino_id IS NULL"
        );
        $orphan_rows = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$tables['values']}` pa
             LEFT JOIN `{$wpdb->posts}` p ON p.ID = pa.product_id
             WHERE p.ID IS NULL OR p.post_type <> 'product' OR p.post_status IN ('trash','auto-draft')"
        );
        $duplicate_rows = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT a.id)
             FROM `{$tables['values']}` a
             INNER JOIN `{$tables['values']}` b
                ON b.product_id = a.product_id
               AND b.atributo_id = a.atributo_id
               AND (b.termino_id <=> a.termino_id)
               AND (b.valor_texto <=> a.valor_texto)
               AND (b.valor_numero <=> a.valor_numero)
               AND (b.valor_numero_max <=> a.valor_numero_max)
               AND (b.unidad <=> a.unidad)
               AND (b.valor_original <=> a.valor_original)
               AND b.id < a.id"
        );

        $top_attributes = $wpdb->get_results(
            "SELECT a.slug, a.nombre, a.tipo,
                    COUNT(pa.id) AS rows_count,
                    COUNT(DISTINCT pa.product_id) AS products
             FROM `{$tables['definitions']}` a
             LEFT JOIN `{$tables['values']}` pa ON pa.atributo_id = a.id
             WHERE a.activo = 1
             GROUP BY a.id, a.slug, a.nombre, a.tipo
             ORDER BY products DESC, rows_count DESC, a.orden ASC, a.nombre ASC
             LIMIT 25",
            ARRAY_A
        );

        $unused_definitions = $wpdb->get_results(
            "SELECT a.id, a.slug, a.nombre, a.tipo
             FROM `{$tables['definitions']}` a
             WHERE a.activo = 1
               AND NOT EXISTS (SELECT 1 FROM `{$tables['values']}` pa WHERE pa.atributo_id = a.id)
             ORDER BY a.orden ASC, a.nombre ASC
             LIMIT 30",
            ARRAY_A
        );

        $detail_slug = isset($_GET['attribute_detail']) ? sanitize_key(wp_unslash($_GET['attribute_detail'])) : '';
        ?>
        <style>
            .seo-attr-dashboard{margin:18px 0 26px}.seo-attr-dashboard h2{margin:0 0 6px}
            .seo-attr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0}
            .seo-attr-card,.seo-attr-panel{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}
            .seo-attr-card .value{font-size:25px;font-weight:650;line-height:1.15;margin:3px 0}.seo-attr-card .label{font-weight:600}
            .seo-attr-card .note,.seo-attr-muted{font-size:12px;color:#646970}.seo-attr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px}
            .seo-attr-table{width:100%;border-collapse:collapse}.seo-attr-table th,.seo-attr-table td{padding:7px 6px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top}
            .seo-attr-table .num{text-align:right;white-space:nowrap}.seo-attr-signal{display:inline-block;padding:2px 7px;border-radius:999px;background:#f0f0f1;font-size:11px;font-weight:600}
            .seo-attr-signal.problem{background:#fbeaea;color:#8a2424}.seo-attr-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.seo-attr-actions form{margin:0}
            .seo-attr-danger{color:#b32d2e!important;border-color:#dba3a3!important}.seo-attr-detail{margin:16px 0;padding:16px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:7px}
        </style>
        <div class="seo-attr-dashboard">
            <h2>Vocabulario canónico de atributos de producto</h2>
            <p class="description">La gestión ya usa <code>wp_sql_atributos</code>, términos, aliases y <code>wp_sql_product_atributos</code>. <code>wp_seo_attributes</code> queda como origen histórico y no se escribe desde esta pantalla.</p>
            <div class="seo-attr-cards">
                <div class="seo-attr-card"><div class="label">Cobertura</div><div class="value"><?php echo esc_html(number_format_i18n($coverage,1)); ?>%</div><div class="note"><?php echo esc_html(number_format_i18n($products_with_attributes)); ?> de <?php echo esc_html(number_format_i18n($total_products)); ?> productos.</div></div>
                <div class="seo-attr-card"><div class="label">Sin atributos</div><div class="value"><?php echo esc_html(number_format_i18n($products_without_attributes)); ?></div><div class="note">Productos activos sin asignaciones.</div></div>
                <div class="seo-attr-card"><div class="label">Asignaciones</div><div class="value"><?php echo esc_html(number_format_i18n($assignment_rows)); ?></div><div class="note">Filas en product_atributos.</div></div>
                <div class="seo-attr-card"><div class="label">Atributos</div><div class="value"><?php echo esc_html(number_format_i18n($definitions_count)); ?></div><div class="note">Definiciones activas.</div></div>
                <div class="seo-attr-card"><div class="label">Términos</div><div class="value"><?php echo esc_html(number_format_i18n($terms_count)); ?></div><div class="note"><?php echo esc_html(number_format_i18n($aliases_count)); ?> aliases.</div></div>
                <div class="seo-attr-card"><div class="label">Vocabulary sin resolver</div><div class="value"><?php echo esc_html(number_format_i18n($unresolved_vocab)); ?></div><div class="note">Tipo término sin termino_id.</div></div>
                <div class="seo-attr-card"><div class="label">Huérfanos</div><div class="value"><?php echo esc_html(number_format_i18n($orphan_rows)); ?></div><div class="note">Producto inexistente/inválido.</div></div>
                <div class="seo-attr-card"><div class="label">Duplicados exactos</div><div class="value"><?php echo esc_html(number_format_i18n($duplicate_rows)); ?></div><div class="note">Copias sobrantes objetivas.</div></div>
            </div>

            <?php if ($detail_slug !== ''):
                $detail = seo_attributes_get_definition($detail_slug, false);
                if ($detail):
                    $term_list = $wpdb->get_results($wpdb->prepare(
                        "SELECT t.id,t.slug,t.nombre,COUNT(pa.id) AS uses
                         FROM `{$tables['terms']}` t
                         LEFT JOIN `{$tables['values']}` pa ON pa.termino_id=t.id
                         WHERE t.atributo_id=%d
                         GROUP BY t.id,t.slug,t.nombre ORDER BY uses DESC,t.orden ASC,t.nombre ASC LIMIT 100",
                        (int)$detail['id']
                    ), ARRAY_A);
                    $raw_values = $wpdb->get_results($wpdb->prepare(
                        "SELECT COALESCE(t.nombre,pa.valor_texto,pa.valor_original,'') AS value_display,COUNT(*) AS uses
                         FROM `{$tables['values']}` pa
                         LEFT JOIN `{$tables['terms']}` t ON t.id=pa.termino_id
                         WHERE pa.atributo_id=%d
                         GROUP BY value_display ORDER BY uses DESC,value_display ASC LIMIT 50",
                        (int)$detail['id']
                    ), ARRAY_A);
                    ?>
                    <div class="seo-attr-detail">
                        <h3><?php echo esc_html((string)$detail['nombre']); ?> <code><?php echo esc_html((string)$detail['slug']); ?></code></h3>
                        <p>Tipo: <strong><?php echo esc_html((string)$detail['tipo']); ?></strong> · Grupo: <?php echo esc_html((string)$detail['grupo']); ?> · Uso: <?php echo esc_html(number_format_i18n(seo_attributes_usage_count((string)$detail['slug']))); ?> asignaciones.</p>
                        <?php if ((string)$detail['tipo'] === 'termino'): ?>
                            <table class="seo-attr-table"><thead><tr><th>Término</th><th>Slug</th><th class="num">Usos</th></tr></thead><tbody>
                            <?php foreach ((array)$term_list as $term): ?><tr><td><?php echo esc_html((string)$term['nombre']); ?></td><td><code><?php echo esc_html((string)$term['slug']); ?></code></td><td class="num"><?php echo esc_html(number_format_i18n((int)$term['uses'])); ?></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php else: ?>
                            <table class="seo-attr-table"><thead><tr><th>Valor</th><th class="num">Usos</th></tr></thead><tbody>
                            <?php foreach ((array)$raw_values as $value): ?><tr><td><?php echo esc_html((string)$value['value_display']); ?></td><td class="num"><?php echo esc_html(number_format_i18n((int)$value['uses'])); ?></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                    </div>
                <?php endif; endif; ?>

            <div class="seo-attr-grid">
                <div class="seo-attr-panel">
                    <h3>Atributos más utilizados</h3>
                    <table class="seo-attr-table"><thead><tr><th>Atributo</th><th>Tipo</th><th class="num">Productos</th><th class="num">Filas</th><th class="num">Acción</th></tr></thead><tbody>
                    <?php foreach ((array)$top_attributes as $row):
                        $detail_url = add_query_arg('attribute_detail', (string)$row['slug']); ?>
                        <tr>
                            <td><a href="<?php echo esc_url($detail_url); ?>"><strong><?php echo esc_html((string)$row['nombre']); ?></strong></a><br><code><?php echo esc_html((string)$row['slug']); ?></code></td>
                            <td><?php echo esc_html((string)$row['tipo']); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int)$row['products'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int)$row['rows_count'])); ?></td>
                            <td class="num"><form method="post" onsubmit="return confirm('Eliminar este atributo, sus términos y todas sus asignaciones de producto. ¿Continuar?');"><?php wp_nonce_field('seo_attributes_dashboard_action','seo_attributes_dashboard_nonce'); ?><input type="hidden" name="seo_attr_dashboard_action" value="delete_global_type"><input type="hidden" name="seo_attr_dashboard_type" value="<?php echo esc_attr((string)$row['slug']); ?>"><button class="button button-small seo-attr-danger">Eliminar globalmente</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>

                <div class="seo-attr-panel">
                    <h3>Definiciones sin uso</h3>
                    <?php if (!$unused_definitions): ?><p class="seo-attr-muted">Todas las definiciones activas se usan.</p><?php else: ?>
                        <table class="seo-attr-table"><thead><tr><th>Atributo</th><th>Tipo</th><th class="num">Acción</th></tr></thead><tbody>
                        <?php foreach ((array)$unused_definitions as $row): ?><tr><td><?php echo esc_html((string)$row['nombre']); ?><br><code><?php echo esc_html((string)$row['slug']); ?></code></td><td><?php echo esc_html((string)$row['tipo']); ?></td><td class="num"><form method="post" onsubmit="return confirm('Eliminar esta definición sin uso y sus términos/aliases. ¿Continuar?');"><?php wp_nonce_field('seo_attributes_dashboard_action','seo_attributes_dashboard_nonce'); ?><input type="hidden" name="seo_attr_dashboard_action" value="delete_unused_master_type"><input type="hidden" name="seo_attr_dashboard_type" value="<?php echo esc_attr((string)$row['slug']); ?>"><button class="button button-small seo-attr-danger">Eliminar</button></form></td></tr><?php endforeach; ?>
                        </tbody></table>
                        <form method="post" style="margin-top:12px" onsubmit="return confirm('Eliminar todas las definiciones que sigan sin uso y sus términos/aliases. ¿Continuar?');"><?php wp_nonce_field('seo_attributes_dashboard_action','seo_attributes_dashboard_nonce'); ?><input type="hidden" name="seo_attr_dashboard_action" value="cleanup_unused_masters"><button class="button seo-attr-danger">Limpiar todas las definiciones sin uso</button></form>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <h3>Integridad de asignaciones</h3>
                    <p><span class="seo-attr-signal <?php echo $orphan_rows ? 'problem' : ''; ?>"><?php echo esc_html(number_format_i18n($orphan_rows)); ?> huérfanos</span> <span class="seo-attr-signal <?php echo $duplicate_rows ? 'problem' : ''; ?>"><?php echo esc_html(number_format_i18n($duplicate_rows)); ?> duplicados</span></p>
                    <div class="seo-attr-actions" style="justify-content:flex-start">
                        <?php if ($orphan_rows): ?><form method="post" onsubmit="return confirm('Eliminar atributos de productos inexistentes, en papelera o inválidos. ¿Continuar?');"><?php wp_nonce_field('seo_attributes_dashboard_action','seo_attributes_dashboard_nonce'); ?><input type="hidden" name="seo_attr_dashboard_action" value="cleanup_orphans"><button class="button seo-attr-danger">Limpiar huérfanos</button></form><?php endif; ?>
                        <?php if ($duplicate_rows): ?><form method="post" onsubmit="return confirm('Eliminar únicamente copias exactamente duplicadas. ¿Continuar?');"><?php wp_nonce_field('seo_attributes_dashboard_action','seo_attributes_dashboard_nonce'); ?><input type="hidden" name="seo_attr_dashboard_action" value="cleanup_duplicates"><button class="button seo-attr-danger">Limpiar duplicados</button></form><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}



/**
 * ==========================
 * PRODUCT ATTRIBUTES PAGE
 * ==========================
 */
function search_product_attributes() {

    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    $attr_table      = $wpdb->prefix . 'sql_product_atributos';

    // =========================
    // FILTROS
    // =========================
    $cluster        = isset($_GET['cluster']) ? intval($_GET['cluster']) : 0;
    $hub_primario   = isset($_GET['hub_primario']) ? intval($_GET['hub_primario']) : 0;
    $hub_secundario = isset($_GET['hub_secundario']) ? intval($_GET['hub_secundario']) : 0;
    $cat            = isset($_GET['cat']) ? intval($_GET['cat']) : 0;

    $search_attributes  = isset($_GET['search_attributes']) ? 1 : 0;
    $propose_attributes = isset($_GET['propose_attributes']) ? 1 : 0;
    $save_attributes    = isset($_POST['save_attributes']) ? 1 : 0;
    
    //Agrega nuevos atributos
    $new_master_attribute = isset($_GET['new_master_attribute'])
        ? sanitize_text_field($_GET['new_master_attribute'])
        : '';
    //Borra los valores cone se atributo para ese articulo
    $delete_master_attribute = isset($_GET['delete_master_attribute'])
        ? sanitize_text_field($_GET['delete_master_attribute'])
        : '';
    //Borra todos los valores cone se atributo
    $delete_global_attribute = isset($_GET['delete_global_attribute'])
        ? sanitize_text_field(wp_unslash($_GET['delete_global_attribute']))
        : '';

    $delete_global_action = isset($_GET['delete_global_action'])
        && '1' === (string) $_GET['delete_global_action'];

    // Acciones del panel semántico. Todas las acciones destructivas nuevas
    // usan POST + nonce y terminan en operaciones rollbackable del Data Layer.
    $dashboard_action = isset($_POST['seo_attr_dashboard_action'])
        ? sanitize_key(wp_unslash($_POST['seo_attr_dashboard_action']))
        : '';
    $dashboard_type = isset($_POST['seo_attr_dashboard_type'])
        ? sanitize_text_field(wp_unslash($_POST['seo_attr_dashboard_type']))
        : '';

    if ($dashboard_action !== '') {
        check_admin_referer(
            'seo_attributes_dashboard_action',
            'seo_attributes_dashboard_nonce'
        );

        try {
            $dashboard_result = [];
            $dashboard_message = '';

            switch ($dashboard_action) {
                case 'delete_global_type':
                    if ($dashboard_type === '') {
                        throw new InvalidArgumentException('No se ha recibido el tipo de atributo a eliminar.');
                    }
                    $dashboard_result = seo_attributes_delete_global_type($dashboard_type);
                    $dashboard_message = sprintf(
                        'Eliminado globalmente «%s»: %s filas (%s de productos y %s maestras).',
                        $dashboard_type,
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0)),
                        number_format_i18n((int) ($dashboard_result['product_deleted'] ?? 0)),
                        number_format_i18n((int) ($dashboard_result['master_deleted'] ?? 0))
                    );
                    break;

                case 'delete_unused_master_type':
                    if ($dashboard_type === '') {
                        throw new InvalidArgumentException('No se ha recibido el atributo maestro a eliminar.');
                    }

                    // Revalidación server-side: el botón solo debe borrar un
                    // maestro si sigue sin uso en productos existentes.
                    $active_use = seo_attributes_usage_count($dashboard_type);
                    if ($active_use > 0) {
                        throw new RuntimeException('El atributo «' . $dashboard_type . '» ya tiene uso activo y no se ha eliminado del diccionario.');
                    }

                    $dashboard_result = seo_attributes_delete_master_type(
                        $dashboard_type,
                        'product_attributes_dashboard_unused_master'
                    );
                    $dashboard_message = sprintf(
                        'Eliminada la definición canónica «%s»: %s filas.',
                        $dashboard_type,
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0))
                    );
                    break;

                case 'cleanup_unused_masters':
                    $dashboard_result = seo_attributes_delete_unused_master_rows();
                    $dashboard_message = sprintf(
                        'Limpieza de vocabulario completada: %s filas maestras de %s tipos sin uso eliminadas.',
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0)),
                        number_format_i18n((int) ($dashboard_result['types'] ?? 0))
                    );
                    break;

                case 'cleanup_orphans':
                    $dashboard_result = seo_attributes_delete_orphan_rows();
                    $dashboard_message = sprintf(
                        'Residuos limpiados: %s filas correspondientes a %s IDs de producto inválidos.',
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0)),
                        number_format_i18n((int) ($dashboard_result['product_ids'] ?? 0))
                    );
                    break;

                case 'cleanup_duplicates':
                    $dashboard_result = seo_attributes_delete_exact_duplicates();
                    $dashboard_message = sprintf(
                        'Duplicados exactos limpiados: %s copias sobrantes eliminadas; se ha conservado una fila de cada combinación.',
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0))
                    );
                    break;

                default:
                    throw new InvalidArgumentException('Acción de atributos no reconocida.');
            }

            $operation_id = (int) ($dashboard_result['operation_id'] ?? 0);
            $operation_uuid = (string) ($dashboard_result['operation_uuid'] ?? '');

            echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>";
            echo esc_html($dashboard_message);
            if ($operation_id > 0) {
                echo ' Operación Data Layer #<strong>' . intval($operation_id) . '</strong>';
                if ($operation_uuid !== '') {
                    echo ' (<code>' . esc_html($operation_uuid) . '</code>)';
                }
                echo ', reversible mediante rollback.';
            } else {
                echo ' No había filas que eliminar.';
            }
            echo '</div>';
        } catch (Throwable $e) {
            echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>";
            echo 'No se ha aplicado la limpieza. <strong>Data Layer:</strong> ' . esc_html($e->getMessage());
            echo '</div>';
        }
    }
    
/**
 * =========================
 * AÑADIR PALABRA CLAVE MAESTRA
 * =========================
 */
if (!empty($new_master_attribute)) {

    check_admin_referer('seo_manage_attribute_definition', 'seo_manage_attribute_definition_nonce');
    try {
        $result = seo_attributes_add_master_type(
            $new_master_attribute,
            'product_attributes_master_add'
        );

        if (!empty($result['inserted'])) {
            echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>
                    Definición de atributo añadida:
                    <strong>" . esc_html($new_master_attribute) . "</strong>
                  </div>";
        } else {
            echo "<div style='padding:10px;background:#fff8e5;border:1px solid #e6c200;margin-bottom:15px;'>
                    La definición de atributo ya existe:
                    <strong>" . esc_html($new_master_attribute) . "</strong>
                  </div>";
        }
    } catch (Throwable $e) {
        echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                No se pudo añadir la definición de atributo: " . esc_html($e->getMessage()) . "
              </div>";
    }
}


    /**
     * =========================
     * BORRAR PALABRA CLAVE MAESTRA PARA UN PRODUCTO
     * =========================
     */
        if (!empty($delete_master_attribute)) {

            check_admin_referer('seo_manage_attribute_definition', 'seo_manage_attribute_definition_nonce');
            try {
                $delete_result = seo_attributes_delete_master_type(
                    $delete_master_attribute,
                    'product_attributes_master_delete'
                );
                $deleted = (int) ($delete_result['deleted'] ?? 0);

                echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                        Eliminadas <strong>" . intval($deleted) . "</strong> filas de definición/vocabulario del atributo =
                        <strong>" . esc_html($delete_master_attribute) . "</strong>
                      </div>";
            } catch (Throwable $e) {
                echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                        No se pudo eliminar la definición de atributo: " . esc_html($e->getMessage()) . "
                      </div>";
            }
        }
        
    /**
     * =========================
     * BORRAR PALABRA CLAVE MAESTRA PARA TODOS LOS PRODUCTOS
     * =========================
     */
        
        if ($delete_global_action && !empty($delete_global_attribute)) {

            check_admin_referer(
                'seo_delete_global_attribute',
                'seo_delete_global_attribute_nonce'
            );

            try {
                $result = seo_attributes_delete_global_type($delete_global_attribute);

                echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>";
                echo "Eliminados <strong>" . intval($result['deleted']) . "</strong> registros con attribute_type = ";
                echo "<strong>" . esc_html($delete_global_attribute) . "</strong>. ";
                echo "Productos: <strong>" . intval($result['product_deleted']) . "</strong>; ";
                echo "diccionario maestro: <strong>" . intval($result['master_deleted']) . "</strong>. ";
                echo "Operación Data Layer #<strong>" . intval($result['operation_id']) . "</strong> ";
                echo "(<code>" . esc_html($result['operation_uuid']) . "</code>), reversible mediante rollback.";
                echo "</div>";
            } catch (Throwable $exception) {
                echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>";
                echo "No se ha eliminado ningún atributo. <strong>Data Layer:</strong> " . esc_html($exception->getMessage());
                echo "</div>";
            }
        }


    // ==========================
    // SELECTS DINÁMICOS
    // ==========================
    $cluster_ids = $wpdb->get_col("
        SELECT DISTINCT source_id
        FROM $relations_table
        WHERE source_type = 'cluster'
        AND source_id > 0
    ");

    $hub_primarios_ids = [];
    if ($cluster > 0) {
        $hub_primarios_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'cluster_to_primary'
        ", $cluster));
    }

    if ($hub_primario > 0 && !in_array($hub_primario, array_map('intval', $hub_primarios_ids))) {
        $hub_primario = 0;
        $hub_secundario = 0;
        $cat = 0;
    }

    $hub_secundarios_ids = [];
    if ($hub_primario > 0) {
        $hub_secundarios_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hub_primario));
    }

    if ($hub_secundario > 0 && !in_array($hub_secundario, array_map('intval', $hub_secundarios_ids))) {
        $hub_secundario = 0;
        $cat = 0;
    }

    $category_ids_from_db = [];
    if ($hub_secundario > 0) {
        $category_ids_from_db = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_secondary_to_category'
        ", $hub_secundario));
    }

    if ($hub_secundario > 0 && $cat > 0 && !in_array($cat, array_map('intval', $category_ids_from_db))) {
        $cat = 0;
    }

    $all_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false
    ]);

    /**
     * =========================
     * GUARDAR ATRIBUTOS PROPUESTOS
     * =========================
     */

    if ($save_attributes && !empty($_POST['attrs'])) {

        $saved_rows = 0;
        $save_errors = [];
        $save_warnings = [];

        foreach ($_POST['attrs'] as $product_id => $list) {

            $product_id = intval($product_id);
            if ($product_id < 1 || !is_array($list)) {
                continue;
            }

            $rows = [];
            foreach ($list as $raw) {

                $parts = explode(':', (string) $raw, 2);
                if (count($parts) !== 2) continue;

                $type  = sanitize_text_field(trim($parts[0]));
                $value = sanitize_text_field(trim($parts[1]));

                if (!$type || !$value) continue;

                $rows[] = [
                    'ambito'          => 'global',
                    'attribute_type'  => $type,
                    'attribute_value' => $value,
                ];
            }

            if (!$rows) {
                continue;
            }

            try {
                $save_result = seo_attributes_append_product(
                    $product_id,
                    $rows,
                    'product_attributes_proposals'
                );
                $saved_rows += (int) ($save_result['inserted'] ?? 0);
                if (!empty($save_result['unresolved'])) {
                    $save_warnings[] = 'Producto #' . $product_id . ': no resueltos ' . implode(', ', (array) $save_result['unresolved']);
                }
            } catch (Throwable $e) {
                $save_errors[] = 'Producto #' . $product_id . ': ' . $e->getMessage();
            }
        }

        if (!$save_errors) {
            echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>
                    Atributos guardados correctamente mediante Data Layer: <strong>" . intval($saved_rows) . "</strong>";
            if ($save_warnings) {
                echo "<br><small>Omitidos por no existir en el vocabulario: " . esc_html(implode(' | ', $save_warnings)) . "</small>";
            }
            echo "</div>";
        } else {
            echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                    Se guardaron <strong>" . intval($saved_rows) . "</strong> atributos, pero hubo errores: " .
                    esc_html(implode(' | ', $save_errors)) . "
                  </div>";
        }
    }


    // El panel semantico se muestra siempre, antes del explorador historico.
    seo_attributes_render_dashboard($attr_table);

    /**
     * =========================
     * FORMULARIO SUPERIOR
     * =========================
     */
    ?>

    <h2 style="margin-top:28px;">Explorar y proponer atributos</h2>
    <p style="max-width:900px;color:#646970;">Esta zona conserva los filtros por estructura y las acciones manuales existentes. El panel superior es la vista principal de gesti&oacute;n.</p>

    <form method="GET" style="margin-bottom:30px;padding:20px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;">

        <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_key($_GET['page'] ?? 'seo-taxonomy')); ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr(sanitize_key($_GET['tab'] ?? 'semantic')); ?>">
        <?php if (!empty($_GET['semantic_tab'])) : ?>
            <input type="hidden" name="semantic_tab" value="<?php echo esc_attr(sanitize_key($_GET['semantic_tab'])); ?>">
        <?php endif; ?>

        <select name="cluster" onchange="this.form.submit()">
            <option value="0">Cluster</option>
            <?php foreach ($cluster_ids as $id): ?>
                <?php $post = get_post($id); ?>
                <option value="<?php echo intval($id); ?>" <?php selected($cluster, $id); ?>>
                    <?php echo esc_html($post ? $post->post_title : "Cluster $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="hub_primario" onchange="this.form.submit()">
            <option value="0">Hub primario</option>
            <?php foreach ($hub_primarios_ids as $id): ?>
                <?php $post = get_post($id); ?>
                <option value="<?php echo intval($id); ?>" <?php selected($hub_primario, $id); ?>>
                    <?php echo esc_html($post ? $post->post_title : "HP $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="hub_secundario" onchange="this.form.submit()">
            <option value="0">Hub secundario</option>
            <?php foreach ($hub_secundarios_ids as $id): ?>
                <?php $post = get_post($id); ?>
                <option value="<?php echo intval($id); ?>" <?php selected($hub_secundario, $id); ?>>
                    <?php echo esc_html($post ? $post->post_title : "HS $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="cat" onchange="this.form.submit()">
            <option value="0">Categoría</option>

            <?php foreach ($all_cats as $c): ?>

                <?php if (empty($category_ids_from_db) || in_array($c->term_id, array_map('intval', $category_ids_from_db))): ?>

                    <option value="<?php echo intval($c->term_id); ?>" <?php selected($cat, $c->term_id); ?>>
                        <?php echo esc_html($c->name); ?>
                    </option>

                <?php endif; ?>

            <?php endforeach; ?>

        </select>


            <button type="submit" name="search_attributes" value="1" class="button button-primary">
                Recopilar información
            </button>
            
             <button type="submit" name="propose_attributes" value="1" class="button">
                Proponer atributos
            </button>
            
            <br><br>
            <?php wp_nonce_field('seo_manage_attribute_definition', 'seo_manage_attribute_definition_nonce'); ?>
            
            <input
                type="text"
                name="new_master_attribute"
                placeholder="nuevo atributo ej: pantalla"
                style="width:260px;"
            >
            
            <button type="submit" class="button button-primary">
                Añadir atributo
            </button>
            
            <br><br>
            
            <input
                type="text"
                name="delete_master_attribute"
                placeholder="borrar atributo sin uso"
                style="width:260px;"
            >
            
            <button type="submit" class="button">
                Borrar definición
            </button>
            
            <?php wp_nonce_field('seo_delete_global_attribute', 'seo_delete_global_attribute_nonce'); ?>
            <input
                type="text"
                name="delete_global_attribute"
                placeholder="eliminar tipo de atributo global"
                style="width:260px;"
            >
            <button
                type="submit"
                name="delete_global_action"
                value="1"
                class="button button-secondary"
                onclick="return confirm('Se eliminará este tipo de atributo de todos los productos y también del diccionario maestro. La operación quedará registrada y será reversible desde el Data Layer. ¿Continuar?');"
            > Borrar atributo global
            </button>
            <p style="margin:6px 0 0;color:#646970;max-width:720px;">
                Esta acción elimina la definición canónica, sus términos/aliases y todas las asignaciones del atributo. El sistema antiguo wp_seo_attributes no se modifica.
            </p>
    </form>

    <?php

    if (!$search_attributes && !$propose_attributes && !$save_attributes) {
        echo "<p><strong>Selecciona una acción.</strong></p>";
        return;
    }






/**
 * =========================
 * RESOLVER CATEGORÍAS FILTRADAS
 * =========================
 */
$target_category_ids = [];

if ($cat > 0) {

    $target_category_ids = [$cat];

} elseif ($hub_secundario > 0) {

    $target_category_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'hub_secondary_to_category'
    ", $hub_secundario));

} elseif ($hub_primario > 0) {

    $hub_secundarios = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'hub_primary_to_hub_secondary'
    ", $hub_primario));

    if (!empty($hub_secundarios)) {

        $placeholders = implode(',', array_fill(0, count($hub_secundarios), '%d'));

        $target_category_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id IN ($placeholders)
            AND relation_type = 'hub_secondary_to_category'
        ", array_map('intval', $hub_secundarios)));
    }

} elseif ($cluster > 0) {

    $hub_primarios = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'cluster_to_primary'
    ", $cluster));

    $all_hub_secundarios = [];

    foreach ($hub_primarios as $hp_id) {

        $hs_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hp_id));

        if (!empty($hs_ids)) {
            $all_hub_secundarios = array_merge($all_hub_secundarios, $hs_ids);
        }
    }

    $all_hub_secundarios = array_unique(array_map('intval', $all_hub_secundarios));

    if (!empty($all_hub_secundarios)) {

        $placeholders = implode(',', array_fill(0, count($all_hub_secundarios), '%d'));

        $target_category_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id IN ($placeholders)
            AND relation_type = 'hub_secondary_to_category'
        ", $all_hub_secundarios));
    }
}

$target_category_ids = array_values(array_unique(array_filter(array_map('intval', $target_category_ids))));

if (empty($target_category_ids)) {
    echo "<p><strong>No hay categorías asociadas a la selección actual.</strong></p>";
    return;
}

/**
 * =========================
 * PRODUCTOS FILTRADOS
 * =========================
 */
$placeholders = implode(',', array_fill(0, count($target_category_ids), '%d'));

$rows = $wpdb->get_results($wpdb->prepare("
    SELECT DISTINCT p.ID, p.post_title, p.post_excerpt, p.post_content
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
    WHERE p.post_type = 'product'
    AND p.post_status IN ('publish','draft','pending','private')
    AND tt.taxonomy = 'product_cat'
    AND tt.term_id IN ($placeholders)
    ORDER BY p.post_title ASC
    LIMIT 100
", $target_category_ids));

echo "<p><strong>Productos encontrados:</strong> " . intval(count($rows)) . "</p>";

if (empty($rows)) {
    echo "<p>No hay productos para esta selección.</p>";
    return;
}

echo "<form method='POST'>";
echo "<input type='hidden' name='save_attributes' value='1'>";

$excluded_attribute_types = ['raw_description', 'raw_excerpt'];

foreach ($rows as $p) {

//Aqui iba la funcion de crear atributos
//seo_detect_title_attributes







    echo "<div style='padding:10px;border:1px solid #ddd;margin-bottom:10px;background:#fff;'>";
    echo "<strong>" . esc_html($p->post_title) . "</strong> (ID: " . intval($p->ID) . ")";

    echo "<div style='margin-top:10px;padding:10px;background:#f8f9fa;border:1px solid #ddd;'>";

    echo "<p><strong>ID:</strong> " . intval($p->ID) . "</p>";
    echo "<p><strong>Slug:</strong> " . esc_html(get_post_field('post_name', $p->ID)) . "</p>";
    echo "<p><strong>Enlace:</strong>
            <a href='" . esc_url(get_permalink($p->ID)) . "' target='_blank'>
                " . esc_html(get_permalink($p->ID)) . "
            </a>
          </p>";

    echo "<p><strong>Excerpt:</strong></p>";
    echo "<div style='padding:8px;background:#fff;border:1px solid #ddd;margin-bottom:10px;'>";
    echo nl2br(esc_html($p->post_excerpt));
    echo "</div>";

    echo "<p><strong>Descripción:</strong></p>";
    echo "<div style='padding:8px;background:#fff;border:1px solid #ddd;max-height:300px;overflow:auto;'>";
    echo wp_kses_post($p->post_content);
    echo "</div>";

    echo "</div>";


/**
 * EXISTENTES
 */
$existing = seo_attributes_get_product_rows($p->ID);

echo "<div style='margin-top:8px;'><strong>Atributos actuales:</strong><br>";

$excluded_attribute_types = [
    'raw_description',
    'raw_excerpt',
];

$has_output = false;

if (!empty($existing)) {

    foreach ($existing as $e) {

        // 🔥 FILTRO REAL (ESTO ES LO QUE TE FALTABA)
        if (in_array($e->attribute_type, $excluded_attribute_types, true)) {
            continue;
        }

        echo esc_html($e->attribute_type . ':' . $e->attribute_value) . "<br>";
        $has_output = true;
    }
}

if (!$has_output) {
    echo "<span style='color:#777;'>Sin atributos guardados</span><br>";
}

echo "</div>";





    /**
     * =========================
     * PROPUESTOS
     * =========================
     */
if ($propose_attributes) {

    // Detectar atributos usando el ID del producto.
    $attrs = seo_detect_title_attributes(
        $p->ID
    );

    // Eliminar duplicados detectados.
    $attrs = array_values(
        array_unique($attrs)
    );

    // Filtrar atributos basura o mal formados.
    $attrs = array_filter(
        $attrs,
        function ($attr) use ($excluded_attribute_types) {

            $parts = explode(':', $attr, 2);

            // Debe ser tipo:valor.
            if (count($parts) !== 2) {
                return false;
            }

            // Excluir atributos internos.
            return !in_array(
                trim($parts[0]),
                $excluded_attribute_types,
                true
            );
        }
    );

    // Crear índice de atributos ya existentes.
    $existing_lookup = [];

    foreach ($existing as $e) {

        $existing_lookup[
            mb_strtolower(
                trim(
                    $e->attribute_type . ':' . $e->attribute_value
                )
            )
        ] = true;
    }

    // Eliminar propuestas ya guardadas.
    $attrs = array_filter(
        $attrs,
        function ($attr) use ($existing_lookup) {

            return !isset(
                $existing_lookup[
                    mb_strtolower(trim($attr))
                ]
            );
        }
    );

    // Mostrar propuestas.
    echo "<div style='margin-top:10px;background:#eef6ff;padding:10px;border-left:3px solid #0073aa;'>";
    echo "<strong>Propuestos:</strong><br>";

    if (!empty($attrs)) {

        foreach ($attrs as $a) {

            echo "<label>
                    <input
                        type='checkbox'
                        name='attrs[" . intval($p->ID) . "][]'
                        value='" . esc_attr($a) . "'
                        checked
                    >
                    " . esc_html($a) . "
                  </label><br>";
        }

    } else {

        echo "<span style='color:#777;'>
                No se han detectado atributos automáticamente.
              </span>";
    }

    echo "</div>";
}





echo "</div>";
}

if ($propose_attributes) {
    echo "<p>
            <button type='submit' class='button button-primary'>
                Guardar atributos seleccionados
            </button>
          </p>";
}

echo "</form>";


}