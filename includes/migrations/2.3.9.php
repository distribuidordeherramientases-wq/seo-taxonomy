<?php
/**
 * Curated semantic correction for the editorial findings reviewed on 2026-10-02.
 *
 * This replaces the broad category-Vocabulary inheritance introduced by 2.3.8
 * with explicit, content-reviewed assignments. It also restores the commercial
 * post_to_category relations that were present in the reviewed mobility CSV.
 */

defined('ABSPATH') || exit;

return static function () {
    global $wpdb;

    $relations = $wpdb->prefix . 'seo_relations';
    $nodes = $wpdb->prefix . 'seo_nodes';
    $vocabulary = $wpdb->prefix . 'seo_vocabulary';
    $object_vocabulary = $wpdb->prefix . 'seo_object_vocabulary';

    foreach (array($relations, $nodes, $vocabulary, $object_vocabulary) as $table) {
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return new WP_Error('seo_migration_239_missing_table', 'Falta la tabla requerida ' . $table . '.');
        }
    }

    $find_content = static function ($preferred_id, $post_type, $title, $seo_role = '') use ($wpdb, $nodes) {
        $preferred_id = absint($preferred_id);
        $post_type = sanitize_key($post_type);
        $title = trim((string) $title);
        $seo_role = sanitize_key($seo_role);

        if ($preferred_id > 0) {
            $post = get_post($preferred_id);
            if ($post && $post->post_type === $post_type && $post->post_status !== 'trash' && trim((string) $post->post_title) === $title) {
                if ($seo_role === '') return $preferred_id;
                $role_ok = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$nodes}
                     WHERE object_type='page' AND object_id=%d AND seo_role=%s AND status=1",
                    $preferred_id,
                    $seo_role
                ));
                if ($role_ok > 0) return $preferred_id;
            }
        }

        if ($post_type === 'page' && $seo_role !== '') {
            return absint($wpdb->get_var($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$nodes} n
                    ON n.object_type='page' AND n.object_id=p.ID AND n.seo_role=%s AND n.status=1
                 WHERE p.post_type='page' AND p.post_status<>'trash' AND p.post_title=%s
                 ORDER BY p.ID DESC LIMIT 1",
                $seo_role,
                $title
            )));
        }

        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type=%s AND post_status<>'trash' AND post_title=%s
             ORDER BY ID DESC LIMIT 1",
            $post_type,
            $title
        )));
    };

    $resolve_category = static function ($slug, $name = '') use ($wpdb) {
        $slug = sanitize_title((string) $slug);
        $name = trim((string) $name);

        if ($slug !== '') {
            $id = absint($wpdb->get_var($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat'
                 WHERE t.slug=%s ORDER BY t.term_id ASC LIMIT 1",
                $slug
            )));
            if ($id > 0) return $id;
        }

        if ($name !== '') {
            return absint($wpdb->get_var($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat'
                 WHERE t.name=%s ORDER BY t.term_id ASC LIMIT 1",
                $name
            )));
        }

        return 0;
    };

    $resolve_vocab = static function ($group, $candidate) use ($wpdb, $vocabulary) {
        $group = sanitize_key((string) $group);
        $candidate = trim((string) $candidate);
        if ($group === '' || $candidate === '') return 0;

        $slug_candidates = array_values(array_unique(array_filter(array(
            $candidate,
            sanitize_title($candidate),
            str_replace('-', '_', sanitize_title($candidate)),
            str_replace('_', '-', $candidate),
        ))));

        foreach ($slug_candidates as $slug) {
            $id = absint($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$vocabulary}
                 WHERE semantic_group=%s AND active=1 AND slug=%s
                 LIMIT 1",
                $group,
                $slug
            )));
            if ($id > 0) return $id;
        }

        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$vocabulary}
             WHERE semantic_group=%s AND active=1 AND label=%s
             LIMIT 1",
            $group,
            $candidate
        )));
    };

    $ensure_assignment = static function ($object_type, $object_id, $vocabulary_id) use ($wpdb, $object_vocabulary) {
        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        $vocabulary_id = absint($vocabulary_id);
        if ($object_id <= 0 || $vocabulary_id <= 0) return true;

        $existing_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$object_vocabulary}
             WHERE object_type=%s AND object_id=%d AND vocabulary_id=%d
             LIMIT 1",
            $object_type,
            $object_id,
            $vocabulary_id
        )));

        if ($existing_id > 0) {
            return false !== $wpdb->query($wpdb->prepare(
                "UPDATE {$object_vocabulary}
                 SET status=1, source='migration_2_3_9_reviewed', confidence=1.0000, updated_at=NOW()
                 WHERE id=%d",
                $existing_id
            ));
        }

        return false !== $wpdb->insert(
            $object_vocabulary,
            array(
                'object_type' => $object_type,
                'object_id' => $object_id,
                'vocabulary_id' => $vocabulary_id,
                'source' => 'migration_2_3_9_reviewed',
                'confidence' => 1.0000,
                'status' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s','%d','%d','%s','%f','%d','%s','%s')
        );
    };

    $replace_migration_vocab = static function ($object_type, $object_id, array $groups) use ($wpdb, $object_vocabulary, $resolve_vocab, $ensure_assignment) {
        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        if ($object_id <= 0) return true;

        // Retire only the broad assignments created by migration 2.3.8.
        $retired = $wpdb->query($wpdb->prepare(
            "UPDATE {$object_vocabulary}
             SET status=0, updated_at=NOW()
             WHERE object_type=%s AND object_id=%d AND source='migration_2_3_8'",
            $object_type,
            $object_id
        ));
        if ($retired === false) return false;

        foreach ($groups as $group => $candidates) {
            foreach ((array) $candidates as $candidate) {
                $vocab_id = $resolve_vocab($group, $candidate);
                if ($vocab_id > 0 && !$ensure_assignment($object_type, $object_id, $vocab_id)) return false;
            }
        }

        return true;
    };

    $ensure_relation = static function ($source_type, $source_id, $target_id, $relation_type) use ($wpdb, $relations) {
        $source_type = sanitize_key((string) $source_type);
        $relation_type = sanitize_key((string) $relation_type);
        $source_id = absint($source_id);
        $target_id = absint($target_id);
        if ($source_id <= 0 || $target_id <= 0) return true;

        $exists = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relations}
             WHERE source_type=%s AND source_id=%d AND target_type='product_cat'
               AND target_id=%d AND relation_type=%s
             LIMIT 1",
            $source_type,
            $source_id,
            $target_id,
            $relation_type
        )));
        if ($exists > 0) return true;

        return false !== $wpdb->insert(
            $relations,
            array(
                'source_type' => $source_type,
                'source_id' => $source_id,
                'target_type' => 'product_cat',
                'target_id' => $target_id,
                'relation_type' => $relation_type,
                'created_at' => current_time('mysql'),
            ),
            array('%s','%d','%s','%d','%s','%s')
        );
    };

    $categories = array(
        'battery' => array(
            array('slug'=>'baterias-para-herramientas-electricas','name'=>'Baterías para herramientas eléctricas'),
            array('slug'=>'kits-de-baterias-y-cargadores-para-herramientas','name'=>'Kits de baterías y cargadores para herramientas'),
            array('slug'=>'kits-de-herramientas-a-bateria','name'=>'Kits de herramientas a batería'),
        ),
        'drilling' => array(
            array('slug'=>'taladros-percutores','name'=>'Taladros percutores'),
            array('slug'=>'martillos-perforadores-a-bateria','name'=>'Martillos perforadores a batería'),
        ),
        'aspiration' => array(
            array('slug'=>'aspiradores-profesionales-y-de-taller','name'=>'Aspiradores profesionales y de taller'),
        ),
        'saws' => array(
            array('slug'=>'sierras-sable','name'=>'Sierras sable'),
            array('slug'=>'sierras-de-calar','name'=>'Sierras de calar'),
            array('slug'=>'sierras-circulares-de-mano','name'=>'Sierras circulares de mano'),
        ),
        'garden' => array(
            array('slug'=>'herramientas-jardineria-y-exterior','name'=>'Herramientas jardinería y exterior'),
            array('slug'=>'cortasetos','name'=>'Cortasetos'),
        ),
        'probes' => array(
            array('slug'=>'sondas-y-puntas-de-prueba','name'=>'Sondas y puntas de prueba'),
        ),
        'mobility' => array(
            array('slug'=>'proteccion-visibilidad-bicicletas-patinetes','name'=>'Protección y visibilidad para bicicletas y patinetes'),
        ),
    );

    $category_ids = array();
    foreach ($categories as $key => $rows) {
        $category_ids[$key] = array();
        foreach ($rows as $row) {
            $id = $resolve_category($row['slug'], $row['name']);
            if ($id > 0) $category_ids[$key][] = $id;
        }
        $category_ids[$key] = array_values(array_unique($category_ids[$key]));
    }

    $editorial = array(
        array(
            'post_id'=>141183,
            'title'=>'Herramientas a batería: cómo elegir entre cuerpo solo, kit, batería y cargador',
            'category_key'=>'battery',
            'vocab'=>array(
                'tipo'=>array(
                    'baterias para herramientas electricas',
                    'kits de baterias y cargadores para herramientas',
                    'kits de herramientas a bateria',
                ),
            ),
        ),
        array(
            'post_id'=>141184,
            'title'=>'Taladro percutor, martillo perforador o demoledor: qué herramienta elegir',
            'category_key'=>'drilling',
            'vocab'=>array(
                'tipo'=>array('taladros percutores','martillos perforadores a bateria','martillos demoledores'),
            ),
        ),
        array(
            'post_id'=>141185,
            'title'=>'Aspiración en taller y obra: cómo elegir aspirador, extractor y conexión a herramienta',
            'category_key'=>'aspiration',
            'vocab'=>array(
                'tipo'=>array('aspiradores profesionales y de taller','accesorios y consumibles para aspiradores'),
            ),
        ),
        array(
            'post_id'=>141186,
            'title'=>'Sierras eléctricas: sable, caladora, circular e ingletadora según el corte',
            'category_key'=>'saws',
            'vocab'=>array(
                'tipo'=>array('sierras sable','sierras de calar','sierras circulares de mano','ingletadoras'),
            ),
        ),
        array(
            'post_id'=>141187,
            'title'=>'Herramientas de jardín para cortar y mantener: césped, bordes, setos y poda',
            'category_key'=>'garden',
            'vocab'=>array(
                'tipo'=>array('herramientas jardineria y exterior','cortasetos','cortacesped','cortabordes','podadoras'),
            ),
        ),
        array(
            'post_id'=>143283,
            'title'=>'Sondas y puntas de prueba: cómo elegirlas según señal, instrumento y límite de medida',
            'category_key'=>'probes',
            'vocab'=>array(
                'tipo'=>array('sondas y puntas de prueba'),
            ),
        ),
    );

    foreach ($editorial as $row) {
        $post_id = $find_content($row['post_id'], 'post', $row['title']);
        if ($post_id <= 0) continue;

        if (!$replace_migration_vocab('post', $post_id, $row['vocab'])) {
            return new WP_Error('seo_migration_239_post_vocab', 'No se pudo revisar Vocabulary del post ' . $post_id . '.');
        }

        foreach ((array) ($category_ids[$row['category_key']] ?? array()) as $category_id) {
            if (!$ensure_relation('post', $post_id, $category_id, 'post_to_category')) {
                return new WP_Error('seo_migration_239_post_relation', 'No se pudo guardar post_to_category para ' . $post_id . '.');
            }
        }
    }

    $landings = array(
        array('page_id'=>139381,'title'=>'Taladro percutor, martillo perforador o demoledor: qué herramienta elegir','category_key'=>'drilling','vocab'=>array('tipo'=>array('taladros percutores','martillos perforadores a bateria','martillos demoledores'))),
        array('page_id'=>139382,'title'=>'Aspiración en taller y obra: cómo elegir aspirador, extractor y conexión a herramienta','category_key'=>'aspiration','vocab'=>array('tipo'=>array('aspiradores profesionales y de taller','accesorios y consumibles para aspiradores'))),
        array('page_id'=>139383,'title'=>'Sierras eléctricas: sable, caladora, circular e ingletadora según el corte','category_key'=>'saws','vocab'=>array('tipo'=>array('sierras sable','sierras de calar','sierras circulares de mano','ingletadoras'))),
        array('page_id'=>139384,'title'=>'Herramientas de jardín para cortar y mantener: césped, bordes, setos y poda','category_key'=>'garden','vocab'=>array('tipo'=>array('herramientas jardineria y exterior','cortasetos','cortacesped','cortabordes','podadoras'))),
    );

    foreach ($landings as $row) {
        $page_id = $find_content($row['page_id'], 'page', $row['title'], 'landing');
        if ($page_id <= 0) continue;

        if (!$replace_migration_vocab('page', $page_id, $row['vocab'])) {
            return new WP_Error('seo_migration_239_page_vocab', 'No se pudo revisar Vocabulary de la landing ' . $page_id . '.');
        }

        foreach ((array) ($category_ids[$row['category_key']] ?? array()) as $category_id) {
            if (!$ensure_relation('landing', $page_id, $category_id, 'landing_to_category')) {
                return new WP_Error('seo_migration_239_landing_relation', 'No se pudo guardar landing_to_category para ' . $page_id . '.');
            }
        }
    }

    $mobility = array(
        143284=>array('title'=>'Nueva normativa para patinetes eléctricos en 2026: casco, edad mínima, luces y visibilidad','tipo'=>array('cascos','linternas_y_luces_portatiles')),
        143285=>array('title'=>'Chaleco reflectante en patinete eléctrico: cuándo es obligatorio y para quién','tipo'=>array()),
        143286=>array('title'=>'Casco en bicicleta desde octubre de 2026: cuándo es obligatorio en carretera y ciudad','tipo'=>array('cascos')),
        143287=>array('title'=>'Luces del patinete eléctrico: qué cambia en 2026 y qué se exigirá desde octubre de 2027','tipo'=>array('linternas_y_luces_portatiles')),
        143288=>array('title'=>'Ciclistas de noche: qué elementos luminosos o reflectantes exige la nueva normativa','tipo'=>array('linternas_y_luces_portatiles')),
        143289=>array('title'=>'Repartidores en bicicleta o patinete eléctrico: nuevas obligaciones de casco y chaleco','tipo'=>array('cascos')),
        143290=>array('title'=>'Patines y monopatines sin motor: dónde se puede circular y qué regula cada ayuntamiento','tipo'=>array()),
    );

    foreach ($mobility as $preferred_id => $row) {
        $post_id = $find_content($preferred_id, 'post', $row['title']);
        if ($post_id <= 0) continue;

        $vocab = array(
            'aplicacion'=>array('bicicletas_movilidad','seguridad_vial'),
            'tipo'=>$row['tipo'],
        );
        if (!$replace_migration_vocab('post', $post_id, $vocab)) {
            return new WP_Error('seo_migration_239_mobility_vocab', 'No se pudo revisar Vocabulary de movilidad para ' . $post_id . '.');
        }

        foreach ((array) ($category_ids['mobility'] ?? array()) as $category_id) {
            if (!$ensure_relation('post', $post_id, $category_id, 'post_to_category')) {
                return new WP_Error('seo_migration_239_mobility_relation', 'No se pudo guardar post_to_category de movilidad para ' . $post_id . '.');
            }
        }
    }

    // The recently-created mobility category also needs canonical Vocabulary of its own.
    foreach ((array) ($category_ids['mobility'] ?? array()) as $category_id) {
        foreach (array('bicicletas_movilidad','seguridad_vial') as $candidate) {
            $vocab_id = $resolve_vocab('aplicacion', $candidate);
            if ($vocab_id > 0) {
                $existing = absint($wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$object_vocabulary}
                     WHERE object_type='product_cat' AND object_id=%d AND vocabulary_id=%d LIMIT 1",
                    $category_id,
                    $vocab_id
                )));
                if ($existing > 0) {
                    $ok = $wpdb->query($wpdb->prepare(
                        "UPDATE {$object_vocabulary}
                         SET status=1, source='migration_2_3_9_reviewed', confidence=1.0000, updated_at=NOW()
                         WHERE id=%d",
                        $existing
                    ));
                    if ($ok === false) return new WP_Error('seo_migration_239_category_vocab','No se pudo actualizar Vocabulary de categoría de movilidad.');
                } else {
                    $ok = $wpdb->insert(
                        $object_vocabulary,
                        array(
                            'object_type'=>'product_cat',
                            'object_id'=>$category_id,
                            'vocabulary_id'=>$vocab_id,
                            'source'=>'migration_2_3_9_reviewed',
                            'confidence'=>1.0000,
                            'status'=>1,
                            'created_at'=>current_time('mysql'),
                            'updated_at'=>current_time('mysql'),
                        ),
                        array('%s','%d','%d','%s','%f','%d','%s','%s')
                    );
                    if ($ok === false) return new WP_Error('seo_migration_239_category_vocab','No se pudo guardar Vocabulary de categoría de movilidad.');
                }
            }
        }
    }

    return true;
};
