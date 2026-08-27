<?php
if (!defined('ABSPATH')) exit;

/**
 * Registra wp_seo_attributes en el Data Layer centralizado.
 *
 * El registro se declara aquí, aunque las clases del Data Layer se carguen
 * más tarde durante el bootstrap. El filtro se resolverá cuando
 * SEO_Data_Layer::tables() construya el inventario de tablas gobernadas.
 *
 * @param array $tables
 * @return array
 */
if (!function_exists('seo_attributes_register_data_layer_table')) {
    function seo_attributes_register_data_layer_table($tables) {
        global $wpdb;

        $tables = is_array($tables) ? $tables : [];
        $table = $wpdb->prefix . 'seo_attributes';

        // No degradar todo el Data Layer si una instalación antigua conserva
        // esta tabla fuera de InnoDB. En ese caso la operación de atributos
        // fallará de forma cerrada, pero nodes/relations seguirán operativos.
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
            ARRAY_A
        );

        if (!is_array($status) || empty($status['Name']) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            return $tables;
        }

        $tables['attributes'] = [
            'table'       => $table,
            'primary_key' => ['id'],
            'entity_type' => 'attribute',
        ];

        return $tables;
    }
}
add_filter('seo_data_layer_tables', 'seo_attributes_register_data_layer_table');

/**
 * Elimina un tipo de atributo de todo el catálogo mediante una única
 * operación auditable y reversible del Data Layer.
 *
 * Se conserva deliberadamente la semántica histórica de esta acción:
 * elimina tanto las asignaciones product_id > 0 como las filas maestras
 * product_id = 0. De ese modo, el detector automático deja de proponer ese
 * attribute_type hasta que vuelva a incorporarse al diccionario maestro.
 *
 * @param string $attribute_type
 * @return array{operation_id:int,operation_uuid:string,deleted:int,master_deleted:int,product_deleted:int}
 */
if (!function_exists('seo_attributes_delete_global_type')) {
    function seo_attributes_delete_global_type($attribute_type) {
        global $wpdb;

        $attribute_type = sanitize_text_field(trim((string) $attribute_type));
        if ($attribute_type === '') {
            throw new InvalidArgumentException('El tipo de atributo no puede estar vacío.');
        }

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha eliminado ningún atributo.');
        }

        $physical_table = $wpdb->prefix . 'seo_attributes';
        $status = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $physical_table),
            ARRAY_A
        );
        if (!is_array($status) || empty($status['Name'])) {
            throw new RuntimeException('No existe la tabla ' . $physical_table . '.');
        }
        if (strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) {
            throw new RuntimeException(
                $physical_table . ' usa el motor ' . (string) ($status['Engine'] ?? 'desconocido') .
                '. Debe ser InnoDB antes de incorporarla al Data Layer; no se ha eliminado nada.'
            );
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];

        // Inventario previo: la escritura posterior se hará exclusivamente
        // mediante SEO_Data_Operation::delete().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, attribute_type
                 FROM `{$table}`
                 WHERE attribute_type = %s
                 ORDER BY id ASC",
                $attribute_type
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            throw new RuntimeException('No se pudieron inventariar los atributos antes del borrado: ' . $wpdb->last_error);
        }

        $master_rows = 0;
        $product_rows = 0;
        foreach ($rows as $row) {
            if ((int) ($row['product_id'] ?? 0) === 0) {
                $master_rows++;
            } else {
                $product_rows++;
            }
        }

        $operation = SEO_Data_Layer::operation([
            'type'          => 'delete_attribute_type_global',
            'label'         => 'Eliminar atributo global: ' . $attribute_type,
            'source_module' => 'product_attributes',
            'rollbackable'  => true,
            'risk_level'    => 'high',
            'audit_level'   => 'full',
            'metadata'      => [
                'attribute_type' => $attribute_type,
                'master_rows'    => $master_rows,
                'product_rows'   => $product_rows,
            ],
        ]);

        $operation->mark_validated([
            'validated_rows' => count($rows),
        ]);
        $operation->mark_previewed(count($rows), [
            'preview_master_rows'  => $master_rows,
            'preview_product_rows' => $product_rows,
        ]);

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $attribute_type) {
                $count = 0;

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        throw new RuntimeException('Se encontró un atributo sin ID válido durante el borrado global.');
                    }

                    // Verificación defensiva antes de borrar: si la fila cambió
                    // desde el inventario previo, abortamos toda la transacción.
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null) {
                        throw new RuntimeException('Un atributo cambió o desapareció antes de completar el borrado. Operación cancelada.');
                    }

                    if ((string) ($current['attribute_type'] ?? '') !== (string) ($row['attribute_type'] ?? '')) {
                        throw new RuntimeException('Un atributo cambió de tipo durante la operación. Operación cancelada.');
                    }

                    $product_id = (int) ($current['product_id'] ?? 0);

                    $op->delete(
                        'attributes',
                        ['id' => $id],
                        [
                            'related_object_type' => $product_id > 0 ? 'product' : 'attribute_dictionary',
                            'related_object_id'   => $product_id,
                            'attribute_type'      => $attribute_type,
                        ]
                    );

                    $count++;
                }

                return $count;
            }
        );

        return [
            'operation_id'    => $operation->id(),
            'operation_uuid'  => $operation->uuid(),
            'deleted'         => (int) $deleted,
            'master_deleted'  => $master_rows,
            'product_deleted' => $product_rows,
        ];
    }
}


/**
 * Sustituye de forma transaccional todos los atributos SEO de un producto.
 *
 * Esta es la puerta de escritura canónica para editores/importadores que
 * necesiten reemplazar el conjunto completo de atributos de un producto.
 * Las lecturas pueden seguir realizándose directamente mientras dure la
 * migración, pero ningún DELETE/INSERT de esta operación sale del Data Layer.
 *
 * @param int    $product_id
 * @param array  $attributes Filas con ambito, attribute_type y attribute_value.
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,deleted:int,inserted:int}
 */
if (!function_exists('seo_attributes_replace_product')) {
    function seo_attributes_replace_product($product_id, $attributes, $source_module = 'product_attributes') {
        global $wpdb;

        $product_id = (int) $product_id;
        if ($product_id < 1) {
            throw new InvalidArgumentException('El ID de producto no es válido.');
        }

        if (!is_array($attributes)) {
            throw new InvalidArgumentException('Los atributos del producto deben recibirse como un array.');
        }

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se han modificado los atributos.');
        }

        // table('attributes') solo existe cuando la tabla está registrada y es
        // apta para operaciones transaccionales (InnoDB en esta migración).
        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];

        $normalized = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $ambito = sanitize_text_field(trim((string) ($attribute['ambito'] ?? '')));
            $attribute_type = sanitize_text_field(trim((string) ($attribute['attribute_type'] ?? '')));
            $attribute_value = sanitize_textarea_field(trim((string) ($attribute['attribute_value'] ?? '')));

            if ($attribute_type === '' || $attribute_value === '') {
                continue;
            }

            // Compatibilidad con el comportamiento histórico del editor.
            if ($ambito === '') {
                $ambito = 'global';
            }

            $normalized[] = [
                'product_id'      => $product_id,
                'ambito'          => $ambito,
                'attribute_type'  => $attribute_type,
                'attribute_value' => $attribute_value,
            ];
        }

        // Inventario previo. Es una lectura; las escrituras posteriores pasan
        // exclusivamente por SEO_Data_Operation.
        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, ambito, attribute_type, attribute_value
                 FROM `{$table}`
                 WHERE product_id = %d
                 ORDER BY id ASC",
                $product_id
            ),
            ARRAY_A
        );

        if (!is_array($existing)) {
            throw new RuntimeException('No se pudieron inventariar los atributos actuales: ' . $wpdb->last_error);
        }

        $source_module = sanitize_key((string) $source_module);
        if ($source_module === '') {
            $source_module = 'product_attributes';
        }

        $operation = SEO_Data_Layer::operation([
            'type'          => 'replace_product_attributes',
            'label'         => 'Actualizar atributos SEO del producto #' . $product_id,
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [
                'product_id'     => $product_id,
                'previous_count' => count($existing),
                'new_count'      => count($normalized),
            ],
        ]);

        $operation->mark_validated([
            'validated_product_id' => $product_id,
            'validated_new_rows'   => count($normalized),
        ]);

        $operation->mark_previewed(
            count($existing) + count($normalized),
            [
                'preview_delete_rows' => count($existing),
                'preview_insert_rows' => count($normalized),
            ]
        );

        $result = $operation->execute(
            static function (SEO_Data_Operation $op) use ($existing, $normalized, $table, $product_id) {
                $deleted = 0;
                $inserted = 0;

                foreach ($existing as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        throw new RuntimeException('Se encontró un atributo existente sin ID válido.');
                    }

                    // Bloqueo/verificación defensiva: si otra operación cambia
                    // la fila entre el inventario y el borrado, abortamos todo.
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null || (int) ($current['product_id'] ?? 0) !== $product_id) {
                        throw new RuntimeException('Los atributos del producto cambiaron durante la operación. No se ha aplicado el reemplazo.');
                    }

                    $op->delete(
                        'attributes',
                        ['id' => $id],
                        [
                            'related_object_type' => 'product',
                            'related_object_id'   => $product_id,
                            'reason'              => 'replace_product_attributes',
                        ]
                    );
                    $deleted++;
                }

                foreach ($normalized as $row) {
                    $op->insert(
                        'attributes',
                        $row,
                        [
                            'related_object_type' => 'product',
                            'related_object_id'   => $product_id,
                            'reason'              => 'replace_product_attributes',
                        ]
                    );
                    $inserted++;
                }

                return [
                    'deleted'  => $deleted,
                    'inserted' => $inserted,
                ];
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted'        => (int) ($result['deleted'] ?? 0),
            'inserted'       => (int) ($result['inserted'] ?? 0),
        ];
    }
}



/**
 * Añade un tipo al diccionario maestro de atributos mediante el Data Layer.
 * Mantiene la convención histórica product_id = 0 y attribute_value = 0.
 *
 * @param string $attribute_type
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,inserted:int,existing_id:int}
 */
if (!function_exists('seo_attributes_add_master_type')) {
    function seo_attributes_add_master_type($attribute_type, $source_module = 'product_attributes') {
        global $wpdb;

        $attribute_type = sanitize_text_field(trim((string) $attribute_type));
        if ($attribute_type === '') {
            throw new InvalidArgumentException('El tipo de atributo maestro no puede estar vacío.');
        }

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha añadido el atributo maestro.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM `{$table}`
                 WHERE product_id = 0
                 AND attribute_type = %s
                 AND attribute_value = %s
                 LIMIT 1",
                $attribute_type,
                '0'
            )
        );

        if ($existing_id > 0) {
            return [
                'operation_id'   => 0,
                'operation_uuid' => '',
                'inserted'       => 0,
                'existing_id'    => $existing_id,
            ];
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';

        $operation = SEO_Data_Layer::operation([
            'type'          => 'add_attribute_master_type',
            'label'         => 'Añadir atributo maestro: ' . $attribute_type,
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => [
                'attribute_type' => $attribute_type,
            ],
        ]);

        $operation->mark_validated(['validated_rows' => 1]);
        $operation->mark_previewed(1, ['preview_insert_rows' => 1]);

        $row = $operation->execute(
            static function (SEO_Data_Operation $op) use ($attribute_type) {
                return $op->insert(
                    'attributes',
                    [
                        'product_id'      => 0,
                        'ambito'          => 'global',
                        'attribute_type'  => $attribute_type,
                        'attribute_value' => '0',
                    ],
                    [
                        'related_object_type' => 'attribute_dictionary',
                        'related_object_id'   => 0,
                        'reason'              => 'add_master_attribute',
                    ]
                );
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'inserted'       => 1,
            'existing_id'    => (int) ($row['id'] ?? 0),
        ];
    }
}

/**
 * Elimina únicamente las filas maestras product_id = 0 de un tipo de atributo.
 * Las asignaciones ya existentes en productos no se modifican.
 *
 * @param string $attribute_type
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,deleted:int}
 */
if (!function_exists('seo_attributes_delete_master_type')) {
    function seo_attributes_delete_master_type($attribute_type, $source_module = 'product_attributes') {
        global $wpdb;

        $attribute_type = sanitize_text_field(trim((string) $attribute_type));
        if ($attribute_type === '') {
            throw new InvalidArgumentException('El tipo de atributo maestro no puede estar vacío.');
        }

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha eliminado el atributo maestro.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, attribute_type
                 FROM `{$table}`
                 WHERE product_id = 0
                 AND attribute_type = %s
                 ORDER BY id ASC",
                $attribute_type
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            throw new RuntimeException('No se pudo inventariar el atributo maestro antes del borrado: ' . $wpdb->last_error);
        }

        if (!$rows) {
            return [
                'operation_id'   => 0,
                'operation_uuid' => '',
                'deleted'        => 0,
            ];
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';

        $operation = SEO_Data_Layer::operation([
            'type'          => 'delete_attribute_master_type',
            'label'         => 'Eliminar atributo maestro: ' . $attribute_type,
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [
                'attribute_type' => $attribute_type,
                'master_rows'    => count($rows),
            ],
        ]);

        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), ['preview_delete_rows' => count($rows)]);

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $attribute_type) {
                $count = 0;

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        throw new RuntimeException('Se encontró una fila maestra sin ID válido.');
                    }

                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if (
                        $current === null ||
                        (int) ($current['product_id'] ?? -1) !== 0 ||
                        (string) ($current['attribute_type'] ?? '') !== $attribute_type
                    ) {
                        throw new RuntimeException('El atributo maestro cambió durante la operación. No se ha aplicado el borrado.');
                    }

                    $op->delete(
                        'attributes',
                        ['id' => $id],
                        [
                            'related_object_type' => 'attribute_dictionary',
                            'related_object_id'   => 0,
                            'attribute_type'      => $attribute_type,
                            'reason'              => 'delete_master_attribute',
                        ]
                    );
                    $count++;
                }

                return $count;
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted'        => (int) $deleted,
        ];
    }
}

/**
 * Añade atributos nuevos a un producto sin borrar los ya existentes.
 * Se usa para aceptar propuestas desde la pantalla de atributos.
 *
 * @param int    $product_id
 * @param array  $attributes
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,inserted:int,skipped:int}
 */
if (!function_exists('seo_attributes_append_product')) {
    function seo_attributes_append_product($product_id, $attributes, $source_module = 'product_attributes') {
        global $wpdb;

        $product_id = (int) $product_id;
        if ($product_id < 1) {
            throw new InvalidArgumentException('El ID de producto no es válido.');
        }
        if (!is_array($attributes)) {
            throw new InvalidArgumentException('Los atributos deben recibirse como un array.');
        }
        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se han guardado los atributos.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];

        $normalized = [];
        $seen = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $ambito = sanitize_text_field(trim((string) ($attribute['ambito'] ?? '')));
            $type = sanitize_text_field(trim((string) ($attribute['attribute_type'] ?? '')));
            $value = sanitize_text_field(trim((string) ($attribute['attribute_value'] ?? '')));

            if ($type === '' || $value === '') {
                continue;
            }
            if ($ambito === '') {
                $ambito = 'global';
            }

            $input_key = mb_strtolower($type . "\0" . $value, 'UTF-8');
            if (isset($seen[$input_key])) {
                continue;
            }
            $seen[$input_key] = true;

            $normalized[] = [
                'product_id'      => $product_id,
                'ambito'          => $ambito,
                'attribute_type'  => $type,
                'attribute_value' => $value,
            ];
        }

        $missing = [];
        $skipped = 0;
        foreach ($normalized as $row) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM `{$table}`
                     WHERE product_id = %d
                     AND attribute_type = %s
                     AND attribute_value = %s
                     LIMIT 1",
                    $product_id,
                    $row['attribute_type'],
                    $row['attribute_value']
                )
            );

            if ($exists > 0) {
                $skipped++;
                continue;
            }

            $missing[] = $row;
        }

        if (!$missing) {
            return [
                'operation_id'   => 0,
                'operation_uuid' => '',
                'inserted'       => 0,
                'skipped'        => $skipped,
            ];
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes';

        $operation = SEO_Data_Layer::operation([
            'type'          => 'append_product_attributes',
            'label'         => 'Añadir atributos SEO al producto #' . $product_id,
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => [
                'product_id'   => $product_id,
                'insert_rows'  => count($missing),
                'skipped_rows' => $skipped,
            ],
        ]);

        $operation->mark_validated(['validated_rows' => count($missing)]);
        $operation->mark_previewed(count($missing), ['preview_insert_rows' => count($missing)]);

        $inserted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($missing, $product_id) {
                $count = 0;
                foreach ($missing as $row) {
                    $op->insert(
                        'attributes',
                        $row,
                        [
                            'related_object_type' => 'product',
                            'related_object_id'   => $product_id,
                            'reason'              => 'append_product_attributes',
                        ]
                    );
                    $count++;
                }
                return $count;
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'inserted'       => (int) $inserted,
            'skipped'        => $skipped,
        ];
    }
}


//Detectar atributos
if (!function_exists('seo_detect_title_attributes')) {

    /**
     * ===================================================
     * DETECTAR ATRIBUTOS DE UN PRODUCTO
     * ===================================================
     *
     * Esta función:
     * - Recibe el ID del producto.
     * - Lee título, slug, excerpt, descripción y etiquetas.
     * - Usa SOLO comodines del diccionario con product_id = 0.
     * - Evita usar atributos ya cazados de otros productos.
     * - Evita falsos positivos como capacidad:2 o voltaje:20 sin unidad clara.
     * - Devuelve propuestas en formato tipo:valor.
     */
    function seo_detect_title_attributes($product_id) {

        global $wpdb;

        $attr_table = $wpdb->prefix . 'seo_attributes';

        // =========================
        // CARGAR PRODUCTO
        // =========================
        $product = get_post($product_id);

        if (!$product) {
            return [];
        }

        // =========================
        // CARGAR ETIQUETAS DEL PRODUCTO
        // =========================
        $tags = wp_get_post_terms(
            $product_id,
            'product_tag',
            ['fields' => 'names']
        );

        if (is_wp_error($tags)) {
            $tags = [];
        }

        // =========================
        // FUNCIÓN INTERNA DE NORMALIZACIÓN
        // =========================
        $normalize = function ($text) {

            // Convertir texto a string seguro.
            $text = (string) $text;

            // Decodificar entidades HTML.
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

            // Eliminar etiquetas HTML.
            $text = wp_strip_all_tags($text);

            // Pasar a minúsculas.
            $text = mb_strtolower($text, 'UTF-8');

            // Quitar acentos.
            $text = remove_accents($text);

            // Separar números pegados a unidades: 20v -> 20 v, 0,5mm -> 0,5 mm.
            $text = preg_replace(
                '/([0-9]+(?:[,.][0-9]+)?)\s*(mm|cm|m|v|w|kw|ah|mah|a|hz|mhz|bar|psi|kg|g|l|ml|pin|pins|pines|uds|ud|piezas|pieza|dientes|ºc|°c)\b/u',
                '$1 $2',
                $text
            );

            // Normalizar separadores comunes.
            $text = str_replace(['/', '-', '_', '+', '&', '(', ')', '[', ']'], ' ', $text);

            // Normalizar espacios.
            $text = preg_replace('/\s+/u', ' ', $text);

            return trim($text);
        };

        // =========================
        // CONSTRUIR TEXTO COMPLETO DEL PRODUCTO
        // =========================
        $text = implode(' ', [
            (string) $product->post_title,
            (string) $product->post_name,
            (string) $product->post_excerpt,
            (string) $product->post_content,
            implode(' ', $tags)
        ]);

        // Normalizar texto completo del producto.
        $text = $normalize($text);

        // =========================
        // LEER SOLO DICCIONARIO MAESTRO
        // =========================
        $dictionary_rows = $wpdb->get_results("
            SELECT DISTINCT
                attribute_type,
                attribute_value
            FROM {$attr_table}
            WHERE product_id = 0
            AND attribute_type IS NOT NULL
            AND attribute_type <> ''
            AND attribute_type NOT IN ('raw_description', 'raw_excerpt')
            ORDER BY attribute_type ASC, attribute_value ASC
        ");

        $out = [];

        foreach ($dictionary_rows as $row) {

            // Leer tipo y valor del comodín.
            $type_raw  = trim((string) $row->attribute_type);
            $value_raw = trim((string) $row->attribute_value);

            // Ignorar tipos vacíos.
            if ($type_raw === '') {
                continue;
            }

            // Normalizar tipo y valor.
            $type_norm  = $normalize($type_raw);
            $value_norm = $normalize($value_raw);

            if ($type_norm === '') {
                continue;
            }

            // ==================================================
            // CASO 1: COMODÍN CON VALOR REAL
            // Ejemplo:
            // material:acero inoxidable
            // tecnologia:bluetooth
            // protocolo:obd2
            // ==================================================
            if ($value_raw !== '' && $value_raw !== '0') {

                // Evitar números sueltos tipo 2, 20, 100.
                if (preg_match('/^[0-9]+(?:[,.][0-9]+)?$/u', $value_norm)) {
                    continue;
                }

                // Buscar el valor como término completo dentro del texto.
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($value_norm, '/') . '([^a-z0-9]|$)/u', $text)) {
                    $out[] = $type_raw . ':' . $value_raw;
                }

                continue;
            }

            // ==================================================
            // CASO 2: COMODÍN PURO attribute_value = 0
            // Ejemplo:
            // diametro:0
            // longitud:0
            // voltaje:0
            // potencia:0
            // pines:0
            // ==================================================
            $type_pattern = preg_quote($type_norm, '/');

            // Buscar formato: diametro 25 mm / voltaje 20 v / potencia 100 w.
            preg_match_all(
                '/(^|[^a-z0-9])' . $type_pattern . '([^a-z0-9]{0,30})([0-9]+(?:[,.][0-9]+)?\s*(mm|cm|m|v|w|kw|ah|mah|a|hz|mhz|bar|psi|kg|g|l|ml|pin|pins|pines|uds|ud|piezas|pieza|dientes|ºc|°c))\b/u',
                $text,
                $matches_forward
            );

            if (!empty($matches_forward[3])) {

                foreach ($matches_forward[3] as $detected_value) {

                    // Limpiar valor detectado.
                    $detected_value = trim($detected_value);
                    $detected_value = preg_replace('/\s+/u', ' ', $detected_value);

                    if ($detected_value !== '') {
                        $out[] = $type_raw . ':' . $detected_value;
                    }
                }

                continue;
            }

            // Buscar formato inverso: 25 mm de diametro / 16 pin conector.
            preg_match_all(
                '/([0-9]+(?:[,.][0-9]+)?\s*(mm|cm|m|v|w|kw|ah|mah|a|hz|mhz|bar|psi|kg|g|l|ml|pin|pins|pines|uds|ud|piezas|pieza|dientes|ºc|°c))([^a-z0-9]{0,30})' . $type_pattern . '([^a-z0-9]|$)/u',
                $text,
                $matches_reverse
            );

            if (!empty($matches_reverse[1])) {

                foreach ($matches_reverse[1] as $detected_value) {

                    // Limpiar valor detectado.
                    $detected_value = trim($detected_value);
                    $detected_value = preg_replace('/\s+/u', ' ', $detected_value);

                    if ($detected_value !== '') {
                        $out[] = $type_raw . ':' . $detected_value;
                    }
                }

                continue;
            }

            // ==================================================
            // CASO 3: COMODÍN SEMÁNTICO
            // Ejemplo:
            // obd2:si
            // bluetooth:si
            // diesel:si
            // scanner:si
            // ==================================================
            if (preg_match('/(^|[^a-z0-9])' . $type_pattern . '([^a-z0-9]|$)/u', $text)) {
                $out[] = $type_raw . ':si';
            }
        }

        // =========================
        // LIMPIEZA FINAL
        // =========================
        $out = array_values(array_unique($out));

        return $out;
    }
}



/**
 * Elimina en una única operación reversible los atributos maestros que no
 * tienen ninguna asignación en productos existentes.
 *
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,deleted:int,types:int}
 */
if (!function_exists('seo_attributes_delete_unused_master_rows')) {
    function seo_attributes_delete_unused_master_rows($source_module = 'product_attributes_dashboard') {
        global $wpdb;

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha eliminado ningún atributo maestro.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];
        $posts_table = $wpdb->posts;

        $rows = $wpdb->get_results(
            "SELECT m.id, m.product_id, m.attribute_type
             FROM `{$table}` m
             WHERE m.product_id = 0
             AND m.attribute_type IS NOT NULL
             AND m.attribute_type <> ''
             AND m.attribute_type NOT IN ('raw_description','raw_excerpt')
             AND NOT EXISTS (
                SELECT 1
                FROM `{$table}` a
                INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                WHERE a.product_id > 0
                AND a.attribute_type = m.attribute_type
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
             )
             ORDER BY m.attribute_type ASC, m.id ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            throw new RuntimeException('No se pudieron inventariar los atributos maestros sin uso: ' . $wpdb->last_error);
        }
        if (!$rows) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0, 'types' => 0];
        }

        $types = [];
        foreach ($rows as $row) {
            $type = (string) ($row['attribute_type'] ?? '');
            if ($type !== '') {
                $types[$type] = true;
            }
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_unused_attribute_masters',
            'label'         => 'Limpiar atributos maestros sin uso',
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [
                'rows'  => count($rows),
                'types' => count($types),
            ],
        ]);

        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), [
            'preview_delete_rows' => count($rows),
            'preview_types'       => count($types),
        ]);

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $posts_table, $wpdb) {
                $count = 0;

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $type = (string) ($row['attribute_type'] ?? '');
                    if ($id < 1 || $type === '') {
                        throw new RuntimeException('Se encontró un atributo maestro sin identidad válida.');
                    }

                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if (
                        $current === null ||
                        (int) ($current['product_id'] ?? -1) !== 0 ||
                        (string) ($current['attribute_type'] ?? '') !== $type
                    ) {
                        throw new RuntimeException('El diccionario cambió durante la limpieza. Operación cancelada.');
                    }

                    $active_use = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*)
                             FROM `{$table}` a
                             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                             WHERE a.product_id > 0
                             AND a.attribute_type = %s
                             AND p.post_type = 'product'
                             AND p.post_status NOT IN ('trash','auto-draft')",
                            $type
                        )
                    );
                    if ($active_use > 0) {
                        throw new RuntimeException('El atributo "' . $type . '" ha empezado a usarse. Operación cancelada.');
                    }

                    $op->delete('attributes', ['id' => $id], [
                        'related_object_type' => 'attribute_dictionary',
                        'related_object_id'   => 0,
                        'attribute_type'      => $type,
                        'reason'              => 'cleanup_unused_master_attribute',
                    ]);
                    $count++;
                }

                return $count;
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted'        => (int) $deleted,
            'types'          => count($types),
        ];
    }
}

/**
 * Elimina asignaciones de wp_seo_attributes cuyo producto ya no existe, está
 * en papelera/auto-draft o dejó de ser un producto. Conserva snapshot completo
 * para rollback.
 *
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,deleted:int,product_ids:int}
 */
if (!function_exists('seo_attributes_delete_orphan_rows')) {
    function seo_attributes_delete_orphan_rows($source_module = 'product_attributes_dashboard') {
        global $wpdb;

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha eliminado ningún residuo.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];
        $posts_table = $wpdb->posts;

        $rows = $wpdb->get_results(
            "SELECT a.id, a.product_id, a.attribute_type
             FROM `{$table}` a
             LEFT JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN ('raw_description','raw_excerpt')
             AND (
                p.ID IS NULL
                OR p.post_type <> 'product'
                OR p.post_status IN ('trash','auto-draft')
             )
             ORDER BY a.id ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            throw new RuntimeException('No se pudieron inventariar los residuos de atributos: ' . $wpdb->last_error);
        }
        if (!$rows) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0, 'product_ids' => 0];
        }

        $product_ids = [];
        foreach ($rows as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id > 0) {
                $product_ids[$product_id] = true;
            }
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_orphan_attributes',
            'label'         => 'Limpiar atributos de productos eliminados',
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [
                'rows'        => count($rows),
                'product_ids' => count($product_ids),
            ],
        ]);

        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), [
            'preview_delete_rows' => count($rows),
            'preview_product_ids' => count($product_ids),
        ]);

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $posts_table, $wpdb) {
                $count = 0;

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $product_id = (int) ($row['product_id'] ?? 0);
                    if ($id < 1 || $product_id < 1) {
                        throw new RuntimeException('Se encontró un residuo de atributo sin identidad válida.');
                    }

                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null || (int) ($current['product_id'] ?? 0) !== $product_id) {
                        throw new RuntimeException('Los residuos cambiaron durante la limpieza. Operación cancelada.');
                    }

                    $post = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT ID, post_type, post_status FROM `{$posts_table}` WHERE ID = %d LIMIT 1",
                            $product_id
                        ),
                        ARRAY_A
                    );
                    if (
                        is_array($post) &&
                        (string) ($post['post_type'] ?? '') === 'product' &&
                        !in_array((string) ($post['post_status'] ?? ''), ['trash', 'auto-draft'], true)
                    ) {
                        throw new RuntimeException('El producto #' . $product_id . ' vuelve a ser válido. Operación cancelada.');
                    }

                    $op->delete('attributes', ['id' => $id], [
                        'related_object_type' => 'product',
                        'related_object_id'   => $product_id,
                        'attribute_type'      => (string) ($current['attribute_type'] ?? ''),
                        'reason'              => 'cleanup_orphan_attribute',
                    ]);
                    $count++;
                }

                return $count;
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted'        => (int) $deleted,
            'product_ids'    => count($product_ids),
        ];
    }
}

/**
 * Elimina únicamente las copias sobrantes de duplicados exactos y conserva la
 * fila de menor ID de cada combinación producto + ámbito + tipo + valor.
 *
 * @param string $source_module
 * @return array{operation_id:int,operation_uuid:string,deleted:int}
 */
if (!function_exists('seo_attributes_delete_exact_duplicates')) {
    function seo_attributes_delete_exact_duplicates($source_module = 'product_attributes_dashboard') {
        global $wpdb;

        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no está disponible. No se ha eliminado ningún duplicado.');
        }

        $config = SEO_Data_Layer::table('attributes');
        $table = (string) $config['table'];
        $posts_table = $wpdb->posts;

        $rows = $wpdb->get_results(
            "SELECT a.id, a.product_id, a.ambito, a.attribute_type, a.attribute_value
             FROM `{$table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN ('raw_description','raw_excerpt')
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')
             AND EXISTS (
                SELECT 1
                FROM `{$table}` b
                WHERE b.product_id = a.product_id
                AND COALESCE(b.ambito, '') = COALESCE(a.ambito, '')
                AND b.attribute_type = a.attribute_type
                AND COALESCE(b.attribute_value, '') = COALESCE(a.attribute_value, '')
                AND b.id < a.id
             )
             ORDER BY a.id ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            throw new RuntimeException('No se pudieron inventariar los duplicados exactos: ' . $wpdb->last_error);
        }
        if (!$rows) {
            return ['operation_id' => 0, 'operation_uuid' => '', 'deleted' => 0];
        }

        $source_module = sanitize_key((string) $source_module) ?: 'product_attributes_dashboard';
        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_exact_attribute_duplicates',
            'label'         => 'Eliminar duplicados exactos de atributos',
            'source_module' => $source_module,
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => ['rows' => count($rows)],
        ]);

        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows), ['preview_delete_rows' => count($rows)]);

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $wpdb) {
                $count = 0;

                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        throw new RuntimeException('Se encontró un duplicado sin ID válido.');
                    }

                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null) {
                        throw new RuntimeException('Un duplicado cambió o desapareció. Operación cancelada.');
                    }

                    $lower_id = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id
                             FROM `{$table}`
                             WHERE product_id = %d
                             AND COALESCE(ambito, '') = %s
                             AND attribute_type = %s
                             AND COALESCE(attribute_value, '') = %s
                             AND id < %d
                             ORDER BY id ASC
                             LIMIT 1",
                            (int) ($current['product_id'] ?? 0),
                            (string) ($current['ambito'] ?? ''),
                            (string) ($current['attribute_type'] ?? ''),
                            (string) ($current['attribute_value'] ?? ''),
                            $id
                        )
                    );
                    if ($lower_id < 1) {
                        throw new RuntimeException('La fila #' . $id . ' ya no es un duplicado exacto. Operación cancelada.');
                    }

                    $op->delete('attributes', ['id' => $id], [
                        'related_object_type' => 'product',
                        'related_object_id'   => (int) ($current['product_id'] ?? 0),
                        'attribute_type'      => (string) ($current['attribute_type'] ?? ''),
                        'reason'              => 'cleanup_exact_duplicate_attribute',
                        'kept_row_id'         => $lower_id,
                    ]);
                    $count++;
                }

                return $count;
            }
        );

        return [
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'deleted'        => (int) $deleted,
        ];
    }
}


/**
 * ==========================
 * PANEL DE GESTIÓN SEMÁNTICA DE ATRIBUTOS
 * ==========================
 *
 * Convierte la antigua búsqueda de atributos en una vista de control:
 * cobertura, frecuencia, diccionario, residuos de productos eliminados,
 * tipos fuera del diccionario y duplicados exactos.
 *
 * Todas las consultas de este panel son de solo lectura.
 */
if (!function_exists('seo_attributes_render_dashboard')) {
    function seo_attributes_render_dashboard($attr_table) {
        global $wpdb;

        $posts_table = $wpdb->posts;
        $excluded_sql = "('raw_description','raw_excerpt')";

        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$posts_table}`
             WHERE post_type = 'product'
             AND post_status NOT IN ('trash','auto-draft')"
        );

        $products_with_attributes = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT a.product_id)
             FROM `{$attr_table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')"
        );

        $assignment_rows = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$attr_table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')"
        );

        $used_types = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT a.attribute_type)
             FROM `{$attr_table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')"
        );

        $master_types = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT attribute_type)
             FROM `{$attr_table}`
             WHERE product_id = 0
             AND attribute_type IS NOT NULL
             AND attribute_type <> ''
             AND attribute_type NOT IN {$excluded_sql}"
        );

        $products_without_attributes = max(0, $total_products - $products_with_attributes);
        $coverage = $total_products > 0
            ? round(($products_with_attributes / $total_products) * 100, 1)
            : 0;
        $average_attributes = $products_with_attributes > 0
            ? round($assignment_rows / $products_with_attributes, 2)
            : 0;

        // Diccionario maestro que no tiene ninguna asignación en productos
        // existentes. Si las únicas asignaciones pertenecen a productos
        // borrados o en papelera, también aparece aquí como "sin uso".
        $unused_master = $wpdb->get_results(
            "SELECT
                m.attribute_type,
                m.master_rows,
                m.master_values
             FROM (
                SELECT
                    attribute_type,
                    COUNT(*) AS master_rows,
                    COUNT(DISTINCT attribute_value) AS master_values
                FROM `{$attr_table}`
                WHERE product_id = 0
                AND attribute_type IS NOT NULL
                AND attribute_type <> ''
                AND attribute_type NOT IN {$excluded_sql}
                GROUP BY attribute_type
             ) m
             LEFT JOIN (
                SELECT DISTINCT a.attribute_type
                FROM `{$attr_table}` a
                INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                WHERE a.product_id > 0
                AND a.attribute_type IS NOT NULL
                AND a.attribute_type <> ''
                AND a.attribute_type NOT IN {$excluded_sql}
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
             ) u ON u.attribute_type = m.attribute_type
             WHERE u.attribute_type IS NULL
             ORDER BY m.attribute_type ASC",
            ARRAY_A
        );
        $unused_master = is_array($unused_master) ? $unused_master : [];

        // Asignaciones que siguen en wp_seo_attributes pero cuyo producto ya
        // no existe, esta en papelera o ya no es un producto.
        $orphan_summary = $wpdb->get_row(
            "SELECT
                COUNT(*) AS rows_count,
                COUNT(DISTINCT a.product_id) AS product_ids,
                SUM(CASE WHEN p.ID IS NULL THEN 1 ELSE 0 END) AS missing_rows,
                SUM(CASE WHEN p.ID IS NOT NULL AND p.post_status = 'trash' THEN 1 ELSE 0 END) AS trash_rows
             FROM `{$attr_table}` a
             LEFT JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND (
                p.ID IS NULL
                OR p.post_type <> 'product'
                OR p.post_status IN ('trash','auto-draft')
             )",
            ARRAY_A
        );
        $orphan_summary = is_array($orphan_summary) ? $orphan_summary : [];

        $orphan_rows = (int) ($orphan_summary['rows_count'] ?? 0);
        $orphan_product_ids = (int) ($orphan_summary['product_ids'] ?? 0);
        $missing_rows = (int) ($orphan_summary['missing_rows'] ?? 0);
        $trash_rows = (int) ($orphan_summary['trash_rows'] ?? 0);

        $orphan_by_type = $wpdb->get_results(
            "SELECT
                a.attribute_type,
                COUNT(*) AS rows_count,
                COUNT(DISTINCT a.product_id) AS product_ids
             FROM `{$attr_table}` a
             LEFT JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND (
                p.ID IS NULL
                OR p.post_type <> 'product'
                OR p.post_status IN ('trash','auto-draft')
             )
             GROUP BY a.attribute_type
             ORDER BY rows_count DESC, a.attribute_type ASC
             LIMIT 20",
            ARRAY_A
        );
        $orphan_by_type = is_array($orphan_by_type) ? $orphan_by_type : [];

        // Tipos que están asignados a productos reales pero no existen en el
        // diccionario maestro product_id = 0.
        $types_outside_dictionary = $wpdb->get_results(
            "SELECT
                u.attribute_type,
                u.products,
                u.rows_count,
                u.values_count
             FROM (
                SELECT
                    a.attribute_type,
                    COUNT(DISTINCT a.product_id) AS products,
                    COUNT(*) AS rows_count,
                    COUNT(DISTINCT a.attribute_value) AS values_count
                FROM `{$attr_table}` a
                INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                WHERE a.product_id > 0
                AND a.attribute_type IS NOT NULL
                AND a.attribute_type <> ''
                AND a.attribute_type NOT IN {$excluded_sql}
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
                GROUP BY a.attribute_type
             ) u
             LEFT JOIN (
                SELECT DISTINCT attribute_type
                FROM `{$attr_table}`
                WHERE product_id = 0
                AND attribute_type IS NOT NULL
                AND attribute_type <> ''
                AND attribute_type NOT IN {$excluded_sql}
             ) m ON m.attribute_type = u.attribute_type
             WHERE m.attribute_type IS NULL
             ORDER BY u.products DESC, u.rows_count DESC, u.attribute_type ASC",
            ARRAY_A
        );
        $types_outside_dictionary = is_array($types_outside_dictionary)
            ? $types_outside_dictionary
            : [];

        // Ranking de uso real por número de productos.
        $top_types = $wpdb->get_results(
            "SELECT
                a.attribute_type,
                COUNT(DISTINCT a.product_id) AS products,
                COUNT(*) AS rows_count,
                COUNT(DISTINCT a.attribute_value) AS values_count
             FROM `{$attr_table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')
             GROUP BY a.attribute_type
             ORDER BY products DESC, rows_count DESC, a.attribute_type ASC
             LIMIT 20",
            ARRAY_A
        );
        $top_types = is_array($top_types) ? $top_types : [];

        $scope_rows = $wpdb->get_results(
            "SELECT
                COALESCE(NULLIF(TRIM(a.ambito), ''), 'sin ámbito') AS ambito,
                COUNT(DISTINCT a.product_id) AS products,
                COUNT(*) AS rows_count
             FROM `{$attr_table}` a
             INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
             WHERE a.product_id > 0
             AND a.attribute_type IS NOT NULL
             AND a.attribute_type <> ''
             AND a.attribute_type NOT IN {$excluded_sql}
             AND p.post_type = 'product'
             AND p.post_status NOT IN ('trash','auto-draft')
             GROUP BY COALESCE(NULLIF(TRIM(a.ambito), ''), 'sin ámbito')
             ORDER BY rows_count DESC, ambito ASC",
            ARRAY_A
        );
        $scope_rows = is_array($scope_rows) ? $scope_rows : [];

        // Duplicado exacto = mismo producto, ámbito, tipo y valor repetidos.
        $duplicate_summary = $wpdb->get_row(
            "SELECT
                COUNT(*) AS duplicate_groups,
                COALESCE(SUM(d.copies - 1), 0) AS extra_rows
             FROM (
                SELECT
                    a.product_id,
                    a.ambito,
                    a.attribute_type,
                    a.attribute_value,
                    COUNT(*) AS copies
                FROM `{$attr_table}` a
                INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                WHERE a.product_id > 0
                AND a.attribute_type IS NOT NULL
                AND a.attribute_type <> ''
                AND a.attribute_type NOT IN {$excluded_sql}
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
                GROUP BY a.product_id, a.ambito, a.attribute_type, a.attribute_value
                HAVING COUNT(*) > 1
             ) d",
            ARRAY_A
        );
        $duplicate_summary = is_array($duplicate_summary) ? $duplicate_summary : [];
        $duplicate_groups = (int) ($duplicate_summary['duplicate_groups'] ?? 0);
        $duplicate_extra_rows = (int) ($duplicate_summary['extra_rows'] ?? 0);

        $duplicate_examples = [];
        if ($duplicate_groups > 0) {
            $duplicate_examples = $wpdb->get_results(
                "SELECT
                    a.product_id,
                    a.attribute_type,
                    a.attribute_value,
                    COUNT(*) AS copies
                FROM `{$attr_table}` a
                INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                WHERE a.product_id > 0
                AND a.attribute_type IS NOT NULL
                AND a.attribute_type <> ''
                AND a.attribute_type NOT IN {$excluded_sql}
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
                GROUP BY a.product_id, a.ambito, a.attribute_type, a.attribute_value
                HAVING COUNT(*) > 1
                ORDER BY copies DESC, a.product_id ASC
                LIMIT 15",
                ARRAY_A
            );
            $duplicate_examples = is_array($duplicate_examples) ? $duplicate_examples : [];
        }

        $page = sanitize_key($_GET['page'] ?? 'seo-taxonomy');
        $tab = sanitize_key($_GET['tab'] ?? 'semantic');
        $semantic_tab = sanitize_key($_GET['semantic_tab'] ?? 'attributes');
        $detail_type = isset($_GET['attribute_detail'])
            ? sanitize_text_field(wp_unslash($_GET['attribute_detail']))
            : '';

        $base_url = add_query_arg(
            [
                'page' => $page,
                'tab' => $tab,
                'semantic_tab' => $semantic_tab,
            ],
            admin_url('admin.php')
        );
        $dashboard_nonce = wp_create_nonce('seo_attributes_dashboard_action');

        ?>
        <style>
            .seo-attr-dashboard{margin:18px 0 26px}
            .seo-attr-dashboard h2{margin:0 0 6px}
            .seo-attr-dashboard .description{max-width:980px}
            .seo-attr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin:16px 0}
            .seo-attr-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;box-shadow:0 1px 1px rgba(0,0,0,.03)}
            .seo-attr-card .value{font-size:26px;font-weight:650;line-height:1.15;margin:2px 0 5px}
            .seo-attr-card .label{font-weight:600}
            .seo-attr-card .note{font-size:12px;color:#646970;margin-top:5px}
            .seo-attr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;margin-top:16px}
            .seo-attr-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;min-width:0}
            .seo-attr-panel h3{margin-top:0}
            .seo-attr-table{width:100%;border-collapse:collapse}
            .seo-attr-table th,.seo-attr-table td{text-align:left;padding:7px 6px;border-bottom:1px solid #f0f0f1;vertical-align:top}
            .seo-attr-table th.num,.seo-attr-table td.num{text-align:right;white-space:nowrap}
            .seo-attr-bar{height:7px;background:#dcdcde;border-radius:999px;overflow:hidden;margin-top:4px}
            .seo-attr-bar span{display:block;height:100%;background:#2271b1}
            .seo-attr-signal{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#f0f0f1}
            .seo-attr-signal.attention{background:#fff3cd;color:#6f5300}
            .seo-attr-signal.problem{background:#fbeaea;color:#8a2424}
            .seo-attr-muted{color:#646970}
            .seo-attr-actions{display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap}
            .seo-attr-actions form{margin:0}
            .seo-attr-danger{color:#b32d2e!important;border-color:#dba3a3!important}
            .seo-attr-danger:hover{color:#8a2424!important;border-color:#b32d2e!important}
            .seo-attr-panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
            .seo-attr-panel-head h3{margin-bottom:0}
            .seo-attr-detail{margin:16px 0;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;padding:16px}
            @media (max-width:782px){.seo-attr-grid{grid-template-columns:1fr}}
        </style>

        <div class="seo-attr-dashboard">
            <h2>Estado de los atributos</h2>
            <p class="description">
                Vista de control del vocabulario real del catálogo. Las señales de revisión no borran ni modifican datos:
                sirven para localizar cobertura insuficiente, residuos, vocabulario fuera del diccionario y duplicados objetivos.
            </p>

            <div class="seo-attr-cards">
                <div class="seo-attr-card">
                    <div class="label">Cobertura de productos</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($coverage, 1)); ?>%</div>
                    <div class="note"><?php echo esc_html(number_format_i18n($products_with_attributes)); ?> de <?php echo esc_html(number_format_i18n($total_products)); ?> productos tienen atributos.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Productos sin atributos</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($products_without_attributes)); ?></div>
                    <div class="note">Productos existentes sin ninguna asignación SEO.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Asignaciones activas</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($assignment_rows)); ?></div>
                    <div class="note">Media: <?php echo esc_html(number_format_i18n($average_attributes, 2)); ?> por producto con atributos.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Tipos utilizados</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($used_types)); ?></div>
                    <div class="note">Diccionario maestro: <?php echo esc_html(number_format_i18n($master_types)); ?> tipos.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Maestros sin uso</div>
                    <div class="value"><?php echo esc_html(number_format_i18n(count($unused_master))); ?></div>
                    <div class="note">No los usa ningun producto existente. Pueden ser válidos o residuo.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Residuos de productos eliminados</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($orphan_rows)); ?></div>
                    <div class="note"><?php echo esc_html(number_format_i18n($orphan_product_ids)); ?> IDs afectados; <?php echo esc_html(number_format_i18n($missing_rows)); ?> filas sin producto y <?php echo esc_html(number_format_i18n($trash_rows)); ?> en papelera.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Fuera del diccionario</div>
                    <div class="value"><?php echo esc_html(number_format_i18n(count($types_outside_dictionary))); ?></div>
                    <div class="note">Tipos usados por productos que no tienen entrada maestra.</div>
                </div>
                <div class="seo-attr-card">
                    <div class="label">Duplicados exactos</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($duplicate_extra_rows)); ?></div>
                    <div class="note"><?php echo esc_html(number_format_i18n($duplicate_groups)); ?> combinaciones repetidas; esta señal sí es objetiva.</div>
                </div>
            </div>

            <div class="seo-attr-grid">
                <div class="seo-attr-panel">
                    <h3>Atributos más utilizados</h3>
                    <?php if (!$top_types): ?>
                        <p class="seo-attr-muted">No hay asignaciones de atributos en productos existentes.</p>
                    <?php else: ?>
                        <?php
                        $max_products = max(array_map(static function ($row) {
                            return (int) ($row['products'] ?? 0);
                        }, $top_types));
                        ?>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Atributo</th>
                                    <th class="num">Productos</th>
                                    <th class="num">Valores</th>
                                    <th class="num">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($top_types as $row): ?>
                                <?php
                                $type = (string) ($row['attribute_type'] ?? '');
                                $products = (int) ($row['products'] ?? 0);
                                $values = (int) ($row['values_count'] ?? 0);
                                $bar_width = $max_products > 0 ? max(2, round(($products / $max_products) * 100)) : 0;
                                $detail_url = add_query_arg('attribute_detail', $type, $base_url);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($detail_url); ?>"><strong><?php echo esc_html($type); ?></strong></a>
                                        <div class="seo-attr-bar"><span style="width:<?php echo esc_attr($bar_width); ?>%"></span></div>
                                    </td>
                                    <td class="num"><?php echo esc_html(number_format_i18n($products)); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n($values)); ?></td>
                                    <td class="num">
                                        <div class="seo-attr-actions">
                                            <form method="post" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode('Eliminar globalmente el atributo «' . $type . '» de ' . number_format_i18n($products) . ' productos y del diccionario maestro. La operación quedará registrada y podrá revertirse desde Data Layer. ¿Continuar?')); ?>);">
                                                <input type="hidden" name="seo_attributes_dashboard_nonce" value="<?php echo esc_attr($dashboard_nonce); ?>">
                                                <input type="hidden" name="seo_attr_dashboard_action" value="delete_global_type">
                                                <input type="hidden" name="seo_attr_dashboard_type" value="<?php echo esc_attr($type); ?>">
                                                <button type="submit" class="button button-small seo-attr-danger">Eliminar globalmente</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <h3>Distribución por ámbito</h3>
                    <?php if (!$scope_rows): ?>
                        <p class="seo-attr-muted">No hay ámbitos asignados.</p>
                    <?php else: ?>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Ámbito</th>
                                    <th class="num">Productos</th>
                                    <th class="num">Asignaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($scope_rows as $row): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['ambito'] ?? '')); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['products'] ?? 0))); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['rows_count'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <div class="seo-attr-panel-head">
                        <div>
                            <h3>Diccionario maestro sin uso</h3>
                            <p class="seo-attr-muted">Atributos maestros que no usa ningún producto existente. Que estén sin uso no significa automáticamente que deban borrarse.</p>
                        </div>
                        <?php if ($unused_master): ?>
                            <form method="post" onsubmit="return confirm('Se eliminarán únicamente las filas maestras que sigan sin uso en este momento. No se borran asignaciones de productos. La operación será reversible. ¿Continuar?');">
                                <input type="hidden" name="seo_attributes_dashboard_nonce" value="<?php echo esc_attr($dashboard_nonce); ?>">
                                <input type="hidden" name="seo_attr_dashboard_action" value="cleanup_unused_masters">
                                <button type="submit" class="button seo-attr-danger">Eliminar todos los maestros sin uso</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$unused_master): ?>
                        <p><span class="seo-attr-signal">Sin incidencias</span> Todos los tipos maestros se usan al menos una vez.</p>
                    <?php else: ?>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Atributo</th>
                                    <th class="num">Filas maestras</th>
                                    <th class="num">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($unused_master, 0, 30) as $row): ?>
                                <?php
                                $type = (string) ($row['attribute_type'] ?? '');
                                $detail_url = add_query_arg('attribute_detail', $type, $base_url);
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($type); ?></a></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['master_rows'] ?? 0))); ?></td>
                                    <td class="num">
                                        <div class="seo-attr-actions">
                                            <form method="post" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode('Eliminar «' . $type . '» únicamente del diccionario maestro. No tiene uso en productos existentes y la operación será reversible. ¿Continuar?')); ?>);">
                                                <input type="hidden" name="seo_attributes_dashboard_nonce" value="<?php echo esc_attr($dashboard_nonce); ?>">
                                                <input type="hidden" name="seo_attr_dashboard_action" value="delete_unused_master_type">
                                                <input type="hidden" name="seo_attr_dashboard_type" value="<?php echo esc_attr($type); ?>">
                                                <button type="submit" class="button button-small seo-attr-danger">Eliminar maestro</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($unused_master) > 30): ?>
                            <p class="seo-attr-muted">Mostrando 30 de <?php echo esc_html(number_format_i18n(count($unused_master))); ?>.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <div class="seo-attr-panel-head">
                        <div>
                            <h3>Residuos de productos eliminados</h3>
                            <p class="seo-attr-muted">Filas que siguen en la tabla de atributos aunque el producto ya no exista, esté en papelera o ya no sea un producto.</p>
                        </div>
                        <?php if ($orphan_rows > 0): ?>
                            <form method="post" onsubmit="return confirm('Se eliminarán únicamente atributos cuyo producto siga inexistente, en papelera o inválido al ejecutar la operación. Se podrá revertir desde Data Layer. ¿Continuar?');">
                                <input type="hidden" name="seo_attributes_dashboard_nonce" value="<?php echo esc_attr($dashboard_nonce); ?>">
                                <input type="hidden" name="seo_attr_dashboard_action" value="cleanup_orphans">
                                <button type="submit" class="button seo-attr-danger">Limpiar residuos</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($orphan_rows < 1): ?>
                        <p><span class="seo-attr-signal">Limpio</span> No se han encontrado asignaciones huérfanas.</p>
                    <?php else: ?>
                        <p><span class="seo-attr-signal problem"><?php echo esc_html(number_format_i18n($orphan_rows)); ?> filas para revisar</span></p>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Atributo</th>
                                    <th class="num">Filas</th>
                                    <th class="num">IDs</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orphan_by_type as $row): ?>
                                <?php
                                $type = (string) ($row['attribute_type'] ?? '');
                                $detail_url = add_query_arg('attribute_detail', $type, $base_url);
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($type); ?></a></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['rows_count'] ?? 0))); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['product_ids'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <h3>Tipos usados fuera del diccionario</h3>
                    <p class="seo-attr-muted">No es necesariamente un error: señala vocabulario que existe en productos pero no está gobernado por una entrada maestra.</p>
                    <?php if (!$types_outside_dictionary): ?>
                        <p><span class="seo-attr-signal">Sin incidencias</span> Todo tipo usado está presente en el diccionario.</p>
                    <?php else: ?>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Atributo</th>
                                    <th class="num">Productos</th>
                                    <th class="num">Valores</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($types_outside_dictionary, 0, 30) as $row): ?>
                                <?php
                                $type = (string) ($row['attribute_type'] ?? '');
                                $detail_url = add_query_arg('attribute_detail', $type, $base_url);
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($type); ?></a></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['products'] ?? 0))); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['values_count'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="seo-attr-panel">
                    <div class="seo-attr-panel-head">
                        <div>
                            <h3>Duplicados exactos</h3>
                            <p class="seo-attr-muted">Mismo producto + ámbito + tipo + valor repetidos. A diferencia de otras señales, aquí sí hay redundancia objetiva.</p>
                        </div>
                        <?php if ($duplicate_extra_rows > 0): ?>
                            <form method="post" onsubmit="return confirm('Se eliminarán solo las copias sobrantes de duplicados exactos, conservando una fila de cada combinación. La operación será reversible. ¿Continuar?');">
                                <input type="hidden" name="seo_attributes_dashboard_nonce" value="<?php echo esc_attr($dashboard_nonce); ?>">
                                <input type="hidden" name="seo_attr_dashboard_action" value="cleanup_duplicates">
                                <button type="submit" class="button seo-attr-danger">Eliminar duplicados exactos</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($duplicate_groups < 1): ?>
                        <p><span class="seo-attr-signal">Limpio</span> No se han encontrado duplicados exactos.</p>
                    <?php else: ?>
                        <p><span class="seo-attr-signal problem"><?php echo esc_html(number_format_i18n($duplicate_extra_rows)); ?> filas sobrantes</span></p>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Atributo</th>
                                    <th>Valor</th>
                                    <th class="num">Copias</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($duplicate_examples as $row): ?>
                                <?php
                                $product_id = (int) ($row['product_id'] ?? 0);
                                $edit_url = $product_id > 0 ? get_edit_post_link($product_id, 'raw') : '';
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($edit_url): ?>
                                            <a href="<?php echo esc_url($edit_url); ?>">#<?php echo esc_html($product_id); ?></a>
                                        <?php else: ?>
                                            #<?php echo esc_html($product_id); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html((string) ($row['attribute_type'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['attribute_value'] ?? '')); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['copies'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            if ($detail_type !== '') {
                $detail_summary = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT
                            COUNT(*) AS rows_count,
                            COUNT(DISTINCT a.product_id) AS products,
                            COUNT(DISTINCT a.attribute_value) AS values_count,
                            COUNT(DISTINCT COALESCE(NULLIF(TRIM(a.ambito), ''), 'sin ámbito')) AS scopes_count
                         FROM `{$attr_table}` a
                         INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                         WHERE a.product_id > 0
                         AND a.attribute_type = %s
                         AND p.post_type = 'product'
                            AND p.post_status NOT IN ('trash','auto-draft')",
                        $detail_type
                    ),
                    ARRAY_A
                );
                $detail_summary = is_array($detail_summary) ? $detail_summary : [];

                $detail_master_rows = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM `{$attr_table}`
                         WHERE product_id = 0
                         AND attribute_type = %s",
                        $detail_type
                    )
                );

                $detail_orphan_rows = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM `{$attr_table}` a
                         LEFT JOIN `{$posts_table}` p ON p.ID = a.product_id
                         WHERE a.product_id > 0
                         AND a.attribute_type = %s
                         AND (
                            p.ID IS NULL
                            OR p.post_type <> 'product'
                            OR p.post_status IN ('trash','auto-draft')
                         )",
                        $detail_type
                    )
                );

                $detail_values = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT
                            a.attribute_value,
                            COUNT(DISTINCT a.product_id) AS products,
                            COUNT(*) AS rows_count
                         FROM `{$attr_table}` a
                         INNER JOIN `{$posts_table}` p ON p.ID = a.product_id
                         WHERE a.product_id > 0
                         AND a.attribute_type = %s
                         AND p.post_type = 'product'
                         AND p.post_status NOT IN ('trash','auto-draft')
                         GROUP BY a.attribute_value
                         ORDER BY products DESC, rows_count DESC, a.attribute_value ASC
                         LIMIT 30",
                        $detail_type
                    ),
                    ARRAY_A
                );
                $detail_values = is_array($detail_values) ? $detail_values : [];
                ?>
                <div class="seo-attr-detail">
                    <p style="float:right;margin:0"><a href="<?php echo esc_url($base_url); ?>">Cerrar detalle</a></p>
                    <h3>Detalle: <?php echo esc_html($detail_type); ?></h3>
                    <p>
                        Productos: <strong><?php echo esc_html(number_format_i18n((int) ($detail_summary['products'] ?? 0))); ?></strong> ·
                        asignaciones: <strong><?php echo esc_html(number_format_i18n((int) ($detail_summary['rows_count'] ?? 0))); ?></strong> ·
                        valores distintos: <strong><?php echo esc_html(number_format_i18n((int) ($detail_summary['values_count'] ?? 0))); ?></strong> ·
                        filas maestras: <strong><?php echo esc_html(number_format_i18n($detail_master_rows)); ?></strong> ·
                        residuos: <strong><?php echo esc_html(number_format_i18n($detail_orphan_rows)); ?></strong>
                    </p>
                    <?php if ($detail_values): ?>
                        <h4>Valores más frecuentes</h4>
                        <table class="seo-attr-table">
                            <thead>
                                <tr>
                                    <th>Valor</th>
                                    <th class="num">Productos</th>
                                    <th class="num">Filas</th>
                                </tr>
                          </thead>
                            <tbody>
                            <?php foreach ($detail_values as $row): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['attribute_value'] ?? '')); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['products'] ?? 0))); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n((int) ($row['rows_count'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="seo-attr-muted">Este tipo no tiene valores asignados a productos existentes.</p>
                    <?php endif; ?>
                </div>
                <?php
            }
            ?>
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
    $attr_table      = $wpdb->prefix . 'seo_attributes';

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
                    $active_use = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*)
                             FROM `{$attr_table}` a
                             INNER JOIN `{$wpdb->posts}` p ON p.ID = a.product_id
                             WHERE a.product_id > 0
                             AND a.attribute_type = %s
                             AND p.post_type = 'product'
                             AND p.post_status NOT IN ('trash','auto-draft')",
                            $dashboard_type
                        )
                    );
                    if ($active_use > 0) {
                        throw new RuntimeException('El atributo «' . $dashboard_type . '» ya tiene uso activo y no se ha eliminado del diccionario.');
                    }

                    $dashboard_result = seo_attributes_delete_master_type(
                        $dashboard_type,
                        'product_attributes_dashboard_unused_master'
                    );
                    $dashboard_message = sprintf(
                        'Eliminado del diccionario maestro «%s»: %s filas.',
                        $dashboard_type,
                        number_format_i18n((int) ($dashboard_result['deleted'] ?? 0))
                    );
                    break;

                case 'cleanup_unused_masters':
                    $dashboard_result = seo_attributes_delete_unused_master_rows();
                    $dashboard_message = sprintf(
                        'Limpieza de diccionario completada: %s filas maestras de %s tipos sin uso eliminadas.',
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

    try {
        $result = seo_attributes_add_master_type(
            $new_master_attribute,
            'product_attributes_master_add'
        );

        if (!empty($result['inserted'])) {
            echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>
                    Palabra clave maestra añadida:
                    <strong>" . esc_html($new_master_attribute) . "</strong>
                  </div>";
        } else {
            echo "<div style='padding:10px;background:#fff8e5;border:1px solid #e6c200;margin-bottom:15px;'>
                    La palabra clave maestra ya existe:
                    <strong>" . esc_html($new_master_attribute) . "</strong>
                  </div>";
        }
    } catch (Throwable $e) {
        echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                No se pudo añadir la palabra clave maestra: " . esc_html($e->getMessage()) . "
              </div>";
    }
}


    /**
     * =========================
     * BORRAR PALABRA CLAVE MAESTRA PARA UN PRODUCTO
     * =========================
     */
        if (!empty($delete_master_attribute)) {

            try {
                $delete_result = seo_attributes_delete_master_type(
                    $delete_master_attribute,
                    'product_attributes_master_delete'
                );
                $deleted = (int) ($delete_result['deleted'] ?? 0);

                echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                        Eliminadas <strong>" . intval($deleted) . "</strong> palabras clave maestras con attribute_type =
                        <strong>" . esc_html($delete_master_attribute) . "</strong>
                      </div>";
            } catch (Throwable $e) {
                echo "<div style='padding:10px;background:#ffe7e7;border:1px solid #ffaaaa;margin-bottom:15px;'>
                        No se pudo eliminar la palabra clave maestra: " . esc_html($e->getMessage()) . "
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
            } catch (Throwable $e) {
                $save_errors[] = 'Producto #' . $product_id . ': ' . $e->getMessage();
            }
        }

        if (!$save_errors) {
            echo "<div style='padding:10px;background:#e6ffed;border:1px solid #7ad67a;margin-bottom:15px;'>
                    Atributos guardados correctamente mediante Data Layer: <strong>" . intval($saved_rows) . "</strong>
                  </div>";
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
            
            <input
                type="text"
                name="new_master_attribute"
                placeholder="nueva palabra clave ej: pantalla"
                style="width:260px;"
            >
            
            <button type="submit" class="button button-primary">
                Añadir palabra clave
            </button>
            
            <br><br>
            
            <input
                type="text"
                name="delete_master_attribute"
                placeholder="borrar palabra clave del diccionario"
                style="width:260px;"
            >
            
            <button type="submit" class="button">
                Borrar del diccionario
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
                Esta acción elimina también la fila maestra con product_id = 0. El detector automático no volverá a proponer ese tipo hasta que se añada de nuevo al diccionario.
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
$existing = $wpdb->get_results($wpdb->prepare("
    SELECT attribute_type, attribute_value
    FROM $attr_table
    WHERE product_id = %d
    ORDER BY attribute_type ASC, attribute_value ASC
", $p->ID));

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