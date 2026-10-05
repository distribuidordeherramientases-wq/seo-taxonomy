<?php
/**
 * Repair editorial Vocabulary and landing commercial relations detected by the
 * structural auditor on 2026-10-02.
 *
 * This migration is intentionally idempotent:
 * - it never creates categories or Vocabulary terms;
 * - it resolves content by preferred ID and exact title as a portable fallback;
 * - it inserts landing_to_category only when the relation is absent;
 * - it reactivates/reuses existing object Vocabulary assignments when possible.
 */

defined('ABSPATH') || exit;

return static function () {
    global $wpdb;

    $relations = $wpdb->prefix . 'seo_relations';
    $nodes = $wpdb->prefix . 'seo_nodes';
    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $object_vocabulary = $wpdb->prefix . 'seo_object_vocabulary';

    $required_tables = array($relations, $nodes, $vocabulary, $object_vocabulary);
    foreach ($required_tables as $table) {
        $exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
        if ($exists !== $table) {
            return new WP_Error(
                'seo_migration_238_missing_table',
                'No se puede ejecutar la migración 2.3.8: falta la tabla ' . $table . '.'
            );
        }
    }

    $find_content = static function ($preferred_id, $post_type, $title, $seo_role = '') use ($wpdb, $nodes) {
        $preferred_id = absint($preferred_id);
        $post_type = sanitize_key($post_type);
        $title = trim((string) $title);
        $seo_role = sanitize_key($seo_role);

        if ($preferred_id > 0) {
            $post = get_post($preferred_id);
            if (
                $post
                && $post->post_type === $post_type
                && $post->post_status !== 'trash'
                && trim((string) $post->post_title) === $title
            ) {
                if ($seo_role === '') {
                    return $preferred_id;
                }

                $role_exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM {$nodes}
                         WHERE object_type = 'page'
                           AND object_id = %d
                           AND seo_role = %s
                           AND status = 1",
                        $preferred_id,
                        $seo_role
                    )
                );
                if ($role_exists > 0) {
                    return $preferred_id;
                }
            }
        }

        if ($post_type === 'page' && $seo_role !== '') {
            return absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT p.ID
                         FROM {$wpdb->posts} p
                         INNER JOIN {$nodes} n
                            ON n.object_type = 'page'
                           AND n.object_id = p.ID
                           AND n.seo_role = %s
                           AND n.status = 1
                         WHERE p.post_type = 'page'
                           AND p.post_status <> 'trash'
                           AND p.post_title = %s
                         ORDER BY p.ID DESC
                         LIMIT 1",
                        $seo_role,
                        $title
                    )
                )
            );
        }

        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID
                     FROM {$wpdb->posts}
                     WHERE post_type = %s
                       AND post_status <> 'trash'
                       AND post_title = %s
                     ORDER BY ID DESC
                     LIMIT 1",
                    $post_type,
                    $title
                )
            )
        );
    };

    $resolve_category = static function ($slug, $name = '') use ($wpdb) {
        $slug = sanitize_title((string) $slug);
        $name = trim((string) $name);

        if ($slug !== '') {
            $term_id = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT t.term_id
                         FROM {$wpdb->terms} t
                         INNER JOIN {$wpdb->term_taxonomy} tt
                            ON tt.term_id = t.term_id
                           AND tt.taxonomy = 'product_cat'
                         WHERE t.slug = %s
                         ORDER BY t.term_id ASC
                         LIMIT 1",
                        $slug
                    )
                )
            );
            if ($term_id > 0) {
                return $term_id;
            }
        }

        if ($name !== '') {
            return absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT t.term_id
                         FROM {$wpdb->terms} t
                         INNER JOIN {$wpdb->term_taxonomy} tt
                            ON tt.term_id = t.term_id
                           AND tt.taxonomy = 'product_cat'
                         WHERE t.name = %s
                         ORDER BY t.term_id ASC
                         LIMIT 1",
                        $name
                    )
                )
            );
        }

        return 0;
    };

    $existing_post_categories = static function ($post_id) use ($wpdb, $relations) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return array();
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        'absint',
                        (array) $wpdb->get_col(
                            $wpdb->prepare(
                                "SELECT target_id
                                 FROM {$relations}
                                 WHERE source_type = 'post'
                                   AND source_id = %d
                                   AND target_type = 'product_cat'
                                   AND relation_type = 'post_to_category'
                                 ORDER BY target_id ASC",
                                $post_id
                            )
                        )
                    )
                )
            )
        );
    };

    $ensure_relation = static function ($source_id, $target_id) use ($wpdb, $relations) {
        $source_id = absint($source_id);
        $target_id = absint($target_id);
        if ($source_id <= 0 || $target_id <= 0) {
            return true;
        }

        $exists = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$relations}
                     WHERE source_type = 'landing'
                       AND source_id = %d
                       AND target_type = 'product_cat'
                       AND target_id = %d
                       AND relation_type = 'landing_to_category'
                     LIMIT 1",
                    $source_id,
                    $target_id
                )
            )
        );
        if ($exists > 0) {
            return true;
        }

        $inserted = $wpdb->insert(
            $relations,
            array(
                'source_type' => 'landing',
                'source_id' => $source_id,
                'target_type' => 'product_cat',
                'target_id' => $target_id,
                'relation_type' => 'landing_to_category',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%d', '%s', '%s')
        );

        return $inserted !== false;
    };

    $ensure_assignment = static function ($object_type, $object_id, $vocabulary_id, $confidence = 1.0) use ($wpdb, $object_vocabulary) {
        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        $vocabulary_id = absint($vocabulary_id);
        $confidence = max(0.0, min(1.0, (float) $confidence));

        if ($object_id <= 0 || $vocabulary_id <= 0 || !in_array($object_type, array('post', 'page'), true)) {
            return true;
        }

        $existing_id = absint(
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $object_vocabulary is an internal prefixed migration table; query is migration-only.
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$object_vocabulary}
                     WHERE object_type = %s
                       AND object_id = %d
                       AND vocabulary_id = %d
                     LIMIT 1",
                    $object_type,
                    $object_id,
                    $vocabulary_id
                )
            )
        );

        if ($existing_id > 0) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$object_vocabulary}
                     SET status = 1,
                         updated_at = NOW()
                     WHERE id = %d",
                    $existing_id
                )
            );
            return $updated !== false;
        }

        $inserted = $wpdb->insert(
            $object_vocabulary,
            array(
                'object_type' => $object_type,
                'object_id' => $object_id,
                'vocabulary_id' => $vocabulary_id,
                'source' => 'migration_2_3_8',
                'confidence' => $confidence,
                'status' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%d', '%s', '%f', '%d', '%s', '%s')
        );

        return $inserted !== false;
    };

    $inherit_category_vocabulary = static function ($object_type, $object_id, array $category_ids) use ($wpdb, $vocabulary, $object_vocabulary, $ensure_assignment) {
        $category_ids = array_values(array_unique(array_filter(array_map('absint', $category_ids))));
        if ($object_id <= 0 || empty($category_ids)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $sql = "SELECT ov.vocabulary_id, MAX(ov.confidence) AS confidence
                FROM {$object_vocabulary} ov
                INNER JOIN {$vocabulary} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                WHERE ov.object_type = 'product_cat'
                  AND ov.object_id IN ({$placeholders})
                  AND ov.status = 1
                  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                GROUP BY ov.vocabulary_id";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list contains generated %d tokens only.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $category_ids), ARRAY_A);

        foreach ($rows as $row) {
            if (!$ensure_assignment(
                $object_type,
                $object_id,
                absint($row['vocabulary_id'] ?? 0),
                (float) ($row['confidence'] ?? 1.0)
            )) {
                return false;
            }
        }

        return true;
    };

    $assign_vocab_slugs = static function ($post_id, $group, array $slugs) use ($wpdb, $vocabulary, $ensure_assignment) {
        $post_id = absint($post_id);
        $group = sanitize_key((string) $group);
        if ($post_id <= 0 || $group === '') {
            return true;
        }

        foreach (array_values(array_unique(array_filter(array_map('strval', $slugs)))) as $slug) {
            $vocabulary_id = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id
                         FROM {$vocabulary}
                         WHERE semantic_group = %s
                           AND slug = %s
                           AND active = 1
                         LIMIT 1",
                        $group,
                        $slug
                    )
                )
            );

            if ($vocabulary_id > 0 && !$ensure_assignment('post', $post_id, $vocabulary_id, 1.0)) {
                return false;
            }
        }

        return true;
    };

    $category_specs = array(
        'battery' => array(
            array('slug' => 'baterias-para-herramientas-electricas', 'name' => 'Baterías para herramientas eléctricas'),
            array('slug' => 'kits-de-baterias-y-cargadores-para-herramientas', 'name' => 'Kits de baterías y cargadores para herramientas'),
            array('slug' => 'kits-de-herramientas-a-bateria', 'name' => 'Kits de herramientas a batería'),
        ),
        'drilling' => array(
            array('slug' => 'taladros-percutores', 'name' => 'Taladros percutores'),
            array('slug' => 'martillos-perforadores-a-bateria', 'name' => 'Martillos perforadores a batería'),
        ),
        'aspiration' => array(
            array('slug' => 'aspiradores-profesionales-y-de-taller', 'name' => 'Aspiradores profesionales y de taller'),
        ),
        'saws' => array(
            array('slug' => 'sierras-sable', 'name' => 'Sierras sable'),
            array('slug' => 'sierras-de-calar', 'name' => 'Sierras de calar'),
            array('slug' => 'sierras-circulares-de-mano', 'name' => 'Sierras circulares de mano'),
        ),
        'garden' => array(
            array('slug' => 'herramientas-jardineria-y-exterior', 'name' => 'Herramientas jardinería y exterior'),
            array('slug' => 'cortasetos', 'name' => 'Cortasetos'),
        ),
        'probes' => array(
            array('slug' => 'sondas-y-puntas-de-prueba', 'name' => 'Sondas y puntas de prueba'),
        ),
        'mobility' => array(
            array('slug' => 'proteccion-visibilidad-bicicletas-patinetes', 'name' => 'Protección y visibilidad para bicicletas y patinetes'),
        ),
    );

    $resolved_categories = array();
    foreach ($category_specs as $key => $specs) {
        $resolved_categories[$key] = array();
        foreach ($specs as $spec) {
            $term_id = $resolve_category($spec['slug'], $spec['name']);
            if ($term_id > 0) {
                $resolved_categories[$key][] = $term_id;
            }
        }
        $resolved_categories[$key] = array_values(array_unique($resolved_categories[$key]));
    }

    /*
     * Landing pages. Prefer the commercial selections already curated on the
     * homonymous post; canonical product_cat slugs are an explicit fallback.
     */
    $landing_specs = array(
        array(
            'page_id' => 139381,
            'post_id' => 141184,
            'title' => 'Taladro percutor, martillo perforador o demoledor: qué herramienta elegir',
            'category_key' => 'drilling',
        ),
        array(
            'page_id' => 139382,
            'post_id' => 141185,
            'title' => 'Aspiración en taller y obra: cómo elegir aspirador, extractor y conexión a herramienta',
            'category_key' => 'aspiration',
        ),
        array(
            'page_id' => 139383,
            'post_id' => 141186,
            'title' => 'Sierras eléctricas: sable, caladora, circular e ingletadora según el corte',
            'category_key' => 'saws',
        ),
        array(
            'page_id' => 139384,
            'post_id' => 141187,
            'title' => 'Herramientas de jardín para cortar y mantener: césped, bordes, setos y poda',
            'category_key' => 'garden',
        ),
    );

    foreach ($landing_specs as $spec) {
        $page_id = $find_content($spec['page_id'], 'page', $spec['title'], 'landing');
        if ($page_id <= 0) {
            continue;
        }

        $post_id = $find_content($spec['post_id'], 'post', $spec['title']);
        $category_ids = $post_id > 0 ? $existing_post_categories($post_id) : array();
        $category_ids = array_values(
            array_unique(
                array_merge(
                    $category_ids,
                    (array) ($resolved_categories[$spec['category_key']] ?? array())
                )
            )
        );

        foreach ($category_ids as $category_id) {
            if (!$ensure_relation($page_id, $category_id)) {
                return new WP_Error(
                    'seo_migration_238_landing_relation',
                    'No se pudo guardar una relación landing_to_category para la landing ' . $page_id . '.'
                );
            }
        }

        if (!$inherit_category_vocabulary('page', $page_id, $category_ids)) {
            return new WP_Error(
                'seo_migration_238_landing_vocabulary',
                'No se pudo heredar Vocabulary para la landing ' . $page_id . '.'
            );
        }
    }

    /*
     * Editorial posts outside the mobility series. Their canonical semantics
     * are inherited from the product categories that describe the same subject.
     */
    $post_specs = array(
        array(
            'post_id' => 141183,
            'title' => 'Herramientas a batería: cómo elegir entre cuerpo solo, kit, batería y cargador',
            'category_key' => 'battery',
        ),
        array(
            'post_id' => 141184,
            'title' => 'Taladro percutor, martillo perforador o demoledor: qué herramienta elegir',
            'category_key' => 'drilling',
        ),
        array(
            'post_id' => 141185,
            'title' => 'Aspiración en taller y obra: cómo elegir aspirador, extractor y conexión a herramienta',
            'category_key' => 'aspiration',
        ),
        array(
            'post_id' => 141186,
            'title' => 'Sierras eléctricas: sable, caladora, circular e ingletadora según el corte',
            'category_key' => 'saws',
        ),
        array(
            'post_id' => 141187,
            'title' => 'Herramientas de jardín para cortar y mantener: césped, bordes, setos y poda',
            'category_key' => 'garden',
        ),
        array(
            'post_id' => 143283,
            'title' => 'Sondas y puntas de prueba: cómo elegirlas según señal, instrumento y límite de medida',
            'category_key' => 'probes',
        ),
    );

    foreach ($post_specs as $spec) {
        $post_id = $find_content($spec['post_id'], 'post', $spec['title']);
        if ($post_id <= 0) {
            continue;
        }

        $category_ids = array_values(
            array_unique(
                array_merge(
                    $existing_post_categories($post_id),
                    (array) ($resolved_categories[$spec['category_key']] ?? array())
                )
            )
        );

        if (!$inherit_category_vocabulary('post', $post_id, $category_ids)) {
            return new WP_Error(
                'seo_migration_238_post_vocabulary',
                'No se pudo heredar Vocabulary para la entrada ' . $post_id . '.'
            );
        }
    }

    /*
     * Mobility/news series. These assignments come from the reviewed
     * 2026-10-02 editorial Vocabulary correction file. Category Vocabulary is
     * inherited first so role/platform/subtype can remain canonical.
     */
    $mobility_specs = array(
        143284 => array(
            'title' => 'Nueva normativa para patinetes eléctricos en 2026: casco, edad mínima, luces y visibilidad',
            'tipo' => array('cascos', 'linternas_y_luces_portatiles'),
        ),
        143285 => array(
            'title' => 'Chaleco reflectante en patinete eléctrico: cuándo es obligatorio y para quién',
            'tipo' => array(),
        ),
        143286 => array(
            'title' => 'Casco en bicicleta desde octubre de 2026: cuándo es obligatorio en carretera y ciudad',
            'tipo' => array('cascos'),
        ),
        143287 => array(
            'title' => 'Luces del patinete eléctrico: qué cambia en 2026 y qué se exigirá desde octubre de 2027',
            'tipo' => array('linternas_y_luces_portatiles'),
        ),
        143288 => array(
            'title' => 'Ciclistas de noche: qué elementos luminosos o reflectantes exige la nueva normativa',
            'tipo' => array('linternas_y_luces_portatiles'),
        ),
        143289 => array(
            'title' => 'Repartidores en bicicleta o patinete eléctrico: nuevas obligaciones de casco y chaleco',
            'tipo' => array('cascos'),
        ),
        143290 => array(
            'title' => 'Patines y monopatines sin motor: dónde se puede circular y qué regula cada ayuntamiento',
            'tipo' => array(),
        ),
    );

    foreach ($mobility_specs as $preferred_id => $spec) {
        $post_id = $find_content($preferred_id, 'post', $spec['title']);
        if ($post_id <= 0) {
            continue;
        }

        if (!$inherit_category_vocabulary(
            'post',
            $post_id,
            (array) ($resolved_categories['mobility'] ?? array())
        )) {
            return new WP_Error(
                'seo_migration_238_mobility_inherit',
                'No se pudo heredar Vocabulary de movilidad para la entrada ' . $post_id . '.'
            );
        }

        if (!$assign_vocab_slugs(
            $post_id,
            'aplicacion',
            array('bicicletas_movilidad', 'seguridad_vial')
        )) {
            return new WP_Error(
                'seo_migration_238_mobility_application',
                'No se pudo asignar Vocabulary de aplicación a la entrada ' . $post_id . '.'
            );
        }

        if (!$assign_vocab_slugs($post_id, 'tipo', (array) $spec['tipo'])) {
            return new WP_Error(
                'seo_migration_238_mobility_type',
                'No se pudo asignar Vocabulary de tipo a la entrada ' . $post_id . '.'
            );
        }
    }

    return true;
};
