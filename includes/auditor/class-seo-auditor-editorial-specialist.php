<?php

defined('ABSPATH') || exit;

/**
 * Especialista editorial de Auditor.
 *
 * Construye un mapa ligero de cobertura para que las auditorias de Posts y
 * Paginas/Landings entiendan primero qué entidad debe poseer cada intención.
 *
 * Reglas de diseño:
 * - solo lectura: nunca crea, publica, mueve ni redirige contenido;
 * - las paginas funcionales/corporativas quedan fuera del analisis editorial;
 * - cluster, hubs y categorias son estructura canonica de navegacion;
 * - las landings deben cubrir necesidades transversales, no duplicar estructura;
 * - los posts deben resolver intenciones informativas concretas;
 * - las propuestas de unir/dividir landings son señales de revision humana.
 */
final class SEO_Auditor_Editorial_Specialist {

    public static function analyze_scope($scope, $current_rows) {
        $scope = sanitize_key((string) $scope);
        $map = self::collect_map();
        $auditable = array();
        $excluded = array(
            'functional'=>0,
            'corporate'=>0,
        );

        foreach ((array) $current_rows as $row) {
            $id = absint($row['ID'] ?? 0);
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (!$id || !in_array($type, array('post','page'), true)) continue;

            if ('page' === $type) {
                $entity = (array) ($map['pages'][$id] ?? array());
                $kind = (string) ($entity['kind'] ?? self::classify_page(
                    $id,
                    (string) ($row['post_name'] ?? ''),
                    (string) ($entity['role'] ?? '')
                ));
                if ('functional' === $kind || 'corporate' === $kind) {
                    $excluded[$kind]++;
                    continue;
                }
            }
            $auditable[] = $row;
        }

        $findings = array();
        if ('posts' === $scope) {
            $findings = self::audit_posts(
                (array) $map['posts'],
                (array) $map['pages'],
                (array) $map['categories']
            );
        } elseif ('pages' === $scope) {
            $findings = self::audit_pages(
                (array) $map['pages'],
                (array) $map['posts'],
                (array) $map['categories'],
                (array) $map['category_to_secondary']
            );
        }

        $kinds = array(
            'canonical'=>0,
            'landing'=>0,
            'editorial'=>0,
            'functional'=>0,
            'corporate'=>0,
        );
        foreach ((array) $map['pages'] as $page) {
            $kind = (string) ($page['kind'] ?? 'editorial');
            if (!isset($kinds[$kind])) $kinds[$kind] = 0;
            $kinds[$kind]++;
        }

        return array(
            'auditable_rows'=>$auditable,
            'findings'=>$findings,
            'summary'=>array(
                'scope'=>$scope,
                'scope_rows_total'=>count((array) $current_rows),
                'scope_rows_audited'=>count($auditable),
                'excluded_functional'=>absint($excluded['functional']),
                'excluded_corporate'=>absint($excluded['corporate']),
                'global_posts'=>count((array) $map['posts']),
                'global_pages'=>count((array) $map['pages']),
                'global_categories'=>count((array) $map['categories']),
                'page_kinds'=>$kinds,
                'cross_entity_findings'=>count($findings),
                'read_only'=>true,
                'creates_new_urls'=>false,
            ),
        );
    }

    private static function collect_map() {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            "SELECT ID,post_type,post_title,post_name
             FROM " . $wpdb->posts . "
             WHERE post_status='publish' AND post_type IN ('post','page')
             ORDER BY ID ASC",
            ARRAY_A
        );

        $roles = self::page_roles();
        $posts = array();
        $pages = array();

        foreach ($rows as $row) {
            $id = absint($row['ID'] ?? 0);
            $type = sanitize_key((string) ($row['post_type'] ?? ''));
            if (!$id || !in_array($type, array('post','page'), true)) continue;

            $entity = array(
                'id'=>$id,
                'type'=>$type,
                'title'=>trim((string) ($row['post_title'] ?? '')),
                'slug'=>sanitize_title((string) ($row['post_name'] ?? '')),
                'url'=>(string) get_permalink($id),
                'role'=>'',
                'kind'=>'post',
                'category_ids'=>array(),
                'vocabulary'=>array(),
            );

            if ('page' === $type) {
                $entity['role'] = sanitize_key((string) ($roles[$id] ?? ''));
                $entity['kind'] = self::classify_page($id, $entity['slug'], $entity['role']);
                $pages[$id] = $entity;
            } else {
                $posts[$id] = $entity;
            }
        }

        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories = array();
        if (!is_wp_error($terms)) {
            foreach ((array) $terms as $term) {
                $id = absint($term->term_id ?? 0);
                if (!$id) continue;
                $url = get_term_link($term, 'product_cat');
                $categories[$id] = array(
                    'id'=>$id,
                    'type'=>'category',
                    'title'=>(string) ($term->name ?? ''),
                    'slug'=>sanitize_title((string) ($term->slug ?? '')),
                    'url'=>is_wp_error($url) ? '' : (string) $url,
                    'role'=>'category',
                    'kind'=>'canonical',
                    'category_ids'=>array($id),
                    'vocabulary'=>array(),
                );
            }
        }

        $category_to_secondary = array();
        $secondary_to_categories = array();
        $primary_to_secondary = array();
        $cluster_to_primary = array();

        $relations = $wpdb->prefix . 'seo_relations';
        if (self::table_exists($relations)) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna validada; consulta fija sin entrada de usuario.
            $rel_rows = (array) $wpdb->get_results(
                "SELECT source_type,source_id,target_type,target_id,relation_type
                 FROM " . $relations . "
                 WHERE source_type IN ('post','page','landing','hub_secondary','hub_primary','cluster')
                   AND target_type IN ('product_cat','hub_secondary','hub_primary')
                 ORDER BY source_type,source_id,target_type,target_id",
                ARRAY_A
            );

            foreach ($rel_rows as $rel) {
                $st = sanitize_key((string) ($rel['source_type'] ?? ''));
                $sid = absint($rel['source_id'] ?? 0);
                $tt = sanitize_key((string) ($rel['target_type'] ?? ''));
                $tid = absint($rel['target_id'] ?? 0);
                if (!$sid || !$tid) continue;

                if ('product_cat' === $tt) {
                    if ('post' === $st && isset($posts[$sid])) {
                        $posts[$sid]['category_ids'][$tid] = true;
                    } elseif (isset($pages[$sid])) {
                        $pages[$sid]['category_ids'][$tid] = true;
                    }

                    if ('hub_secondary' === $st) {
                        $secondary_to_categories[$sid][$tid] = true;
                        $category_to_secondary[$tid][$sid] = true;
                    }
                    if ('landing' === $st && isset($pages[$sid]) && '' === (string) $pages[$sid]['role']) {
                        $pages[$sid]['role'] = 'landing';
                    }
                } elseif ('hub_primary' === $st && 'hub_secondary' === $tt) {
                    $primary_to_secondary[$sid][$tid] = true;
                    if (isset($pages[$sid]) && '' === (string) $pages[$sid]['role']) $pages[$sid]['role'] = 'hub_primary';
                } elseif ('cluster' === $st && 'hub_primary' === $tt) {
                    $cluster_to_primary[$sid][$tid] = true;
                    if (isset($pages[$sid]) && '' === (string) $pages[$sid]['role']) $pages[$sid]['role'] = 'cluster';
                }
            }
        }

        foreach ($pages as $id=>&$page) {
            $role = sanitize_key((string) ($page['role'] ?? ''));
            if ('hub_secondary' === $role) {
                foreach (array_keys((array) ($secondary_to_categories[$id] ?? array())) as $cid) {
                    $page['category_ids'][$cid] = true;
                }
            } elseif ('hub_primary' === $role) {
                foreach (array_keys((array) ($primary_to_secondary[$id] ?? array())) as $secondary_id) {
                    foreach (array_keys((array) ($secondary_to_categories[$secondary_id] ?? array())) as $cid) {
                        $page['category_ids'][$cid] = true;
                    }
                }
            } elseif ('cluster' === $role) {
                foreach (array_keys((array) ($cluster_to_primary[$id] ?? array())) as $primary_id) {
                    foreach (array_keys((array) ($primary_to_secondary[$primary_id] ?? array())) as $secondary_id) {
                        foreach (array_keys((array) ($secondary_to_categories[$secondary_id] ?? array())) as $cid) {
                            $page['category_ids'][$cid] = true;
                        }
                    }
                }
            }
            $page['kind'] = self::classify_page($id, (string) ($page['slug'] ?? ''), $role);
            $page['category_ids'] = array_values(array_map('absint', array_keys((array) $page['category_ids'])));
            sort($page['category_ids'], SORT_NUMERIC);
        }
        unset($page);

        foreach ($posts as &$post) {
            $post['category_ids'] = array_values(array_map('absint', array_keys((array) $post['category_ids'])));
            sort($post['category_ids'], SORT_NUMERIC);
        }
        unset($post);

        $vocab = self::load_vocabulary();
        foreach ($vocab as $key=>$items) {
            list($type,$id) = array_pad(explode(':', (string) $key, 2), 2, '');
            $id = absint($id);
            if ('post' === $type && isset($posts[$id])) $posts[$id]['vocabulary'] = $items;
            elseif ('page' === $type && isset($pages[$id])) $pages[$id]['vocabulary'] = $items;
            elseif ('product_cat' === $type && isset($categories[$id])) $categories[$id]['vocabulary'] = $items;
        }

        return array(
            'posts'=>$posts,
            'pages'=>$pages,
            'categories'=>$categories,
            'category_to_secondary'=>$category_to_secondary,
        );
    }

    private static function page_roles() {
        global $wpdb;
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (!self::table_exists($nodes)) return array();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna validada; consulta fija sin entrada de usuario.
        $rows = (array) $wpdb->get_results(
            "SELECT object_id,seo_role
             FROM " . $nodes . "
             WHERE status=1
               AND object_type='page'
               AND seo_role IN ('cluster','hub_primary','hub_secondary','landing','corporate_page')
             ORDER BY object_id",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $id = absint($row['object_id'] ?? 0);
            $role = sanitize_key((string) ($row['seo_role'] ?? ''));
            if ($id && $role) $out[$id] = $role;
        }
        return $out;
    }

    private static function classify_page($id, $slug, $role) {
        $id = absint($id);
        $slug = sanitize_title((string) $slug);
        $role = sanitize_key((string) $role);

        if (function_exists('seo_analista_functional_page_ids')) {
            $functional_ids = array_map('absint', (array) seo_analista_functional_page_ids());
            if ($id && in_array($id, $functional_ids, true)) return 'functional';
        }

        $functional = array(
            'carrito','cart','finalizar-compra','checkout','mi-cuenta','my-account',
            'tienda','shop','blog','dependiente'
        );
        if (in_array($slug, $functional, true)) return 'functional';

        $corporate = array(
            'inicio','nosotros','nuestro-servicio','contacto','privacidad-de-datos',
            'politica-de-privacidad','politica-privacidad','terminos-y-condiciones',
            'terminos-condiciones','devoluciones-y-reembolsos','condiciones-de-venta',
            'politica-de-cookies','cookies','aviso-legal',
            'proveedores-de-distribuidor-de-herramientas-es'
        );
        if ('corporate_page' === $role || in_array($slug, $corporate, true)) return 'corporate';
        if (in_array($role, array('cluster','hub_primary','hub_secondary'), true)) return 'canonical';
        if ('landing' === $role) return 'landing';
        return 'editorial';
    }

    private static function load_vocabulary() {
        global $wpdb;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!self::table_exists($ov) || !self::table_exists($v)) return array();

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tablas internas validadas; consulta fija sin entrada de usuario.
        $rows = (array) $wpdb->get_results(
            "SELECT ov.object_type,ov.object_id,v.semantic_group,v.slug,v.label
             FROM " . $ov . " ov
             INNER JOIN " . $v . " v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.status=1 AND ov.object_type IN ('post','page','product_cat')
             ORDER BY ov.object_type,ov.object_id,v.semantic_group,v.id",
            ARRAY_A
        );

        $map = array();
        foreach ($rows as $row) {
            $type = sanitize_key((string) ($row['object_type'] ?? ''));
            $id = absint($row['object_id'] ?? 0);
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $slug = sanitize_title((string) ($row['slug'] ?? ''));
            if (!$id || !$type || !$slug) continue;
            $map[$type . ':' . $id][] = $group . ':' . $slug;
        }
        foreach ($map as &$items) $items = array_values(array_unique(array_filter($items)));
        unset($items);
        return $map;
    }

    private static function audit_posts($posts, $pages, $categories) {
        $findings = array();
        $canonical = array_values(array_filter($pages, static function($page) {
            return 'canonical' === (string) ($page['kind'] ?? '');
        }));
        $landings = array_values(array_filter($pages, static function($page) {
            return 'landing' === (string) ($page['kind'] ?? '');
        }));

        foreach ($posts as $post) {
            $exact = self::best_exact_match($post, $canonical);
            if ($exact) {
                $findings[] = self::finding(
                    'editorial_post_canonical_cannibalization',
                    !empty($exact['exact_slug']) ? 'high' : 'medium',
                    'post',
                    absint($post['id'] ?? 0),
                    (string) ($post['title'] ?? ''),
                    'Post solapado con una pagina canonica de la arquitectura',
                    array(
                        'post'=>self::brief($post),
                        'canonical_owner'=>self::brief($exact['entity']),
                        'exact_title'=>!empty($exact['exact_title']),
                        'exact_slug'=>!empty($exact['exact_slug']),
                    ),
                    'La pagina estructural debe concentrar la intencion amplia. Incorporar alli el contenido util exclusivo y, si el post no conserva una intencion informativa claramente distinta, pasarlo a borrador y redirigir 301 a la pagina propietaria.'
                );
                continue;
            }

            $exact = self::best_exact_match($post, $landings);
            if ($exact) {
                $findings[] = self::finding(
                    'editorial_post_landing_cannibalization',
                    !empty($exact['exact_slug']) ? 'high' : 'medium',
                    'post',
                    absint($post['id'] ?? 0),
                    (string) ($post['title'] ?? ''),
                    'Post solapado con una landing',
                    array(
                        'post'=>self::brief($post),
                        'landing'=>self::brief($exact['entity']),
                        'exact_title'=>!empty($exact['exact_title']),
                        'exact_slug'=>!empty($exact['exact_slug']),
                    ),
                    'Definir un unico propietario de la intencion. Mantener la landing si cubre una necesidad transversal y convertir el post en apoyo informativo concreto; si ambos resuelven lo mismo, consolidar contenido y aplicar borrador + 301 al duplicado.'
                );
                continue;
            }

            $category = self::best_category_overlap($post, $categories);
            if ($category && (float) ($category['title_similarity'] ?? 0) >= 0.95) {
                $findings[] = self::finding(
                    'editorial_post_category_cannibalization',
                    'medium',
                    'post',
                    absint($post['id'] ?? 0),
                    (string) ($post['title'] ?? ''),
                    'Post demasiado proximo a una categoria comercial',
                    array(
                        'post'=>self::brief($post),
                        'category'=>self::brief($category['entity']),
                        'title_similarity'=>$category['title_similarity'],
                        'linked_to_category'=>$category['linked'],
                    ),
                    'La categoria debe concentrar la intencion comercial de su familia. Mantener el post solo si responde una pregunta, problema, comparacion o guia claramente distinta; si replica la categoria, consolidar antes de enriquecer.'
                );
                continue;
            }

            $near = self::best_supported_overlap($post, $canonical);
            if ($near) {
                $findings[] = self::finding(
                    'editorial_post_structural_overlap',
                    'low',
                    'post',
                    absint($post['id'] ?? 0),
                    (string) ($post['title'] ?? ''),
                    'Post con posible solapamiento de intencion con una pagina estructural',
                    array(
                        'post'=>self::brief($post),
                        'possible_owner'=>self::brief($near['entity']),
                        'title_similarity'=>$near['title_similarity'],
                        'category_overlap'=>$near['category_overlap'],
                        'vocabulary_overlap'=>$near['vocabulary_overlap'],
                    ),
                    'Revisar la intencion antes de mejorar el post. El cluster/hub debe conservar la cobertura estructural; el post solo debe mantenerse si responde una pregunta o necesidad informativa claramente mas concreta.'
                );
                continue;
            }

            $near = self::best_supported_overlap($post, $landings);
            if ($near) {
                $findings[] = self::finding(
                    'editorial_post_landing_overlap',
                    'low',
                    'post',
                    absint($post['id'] ?? 0),
                    (string) ($post['title'] ?? ''),
                    'Post con posible solapamiento de intencion con una landing',
                    array(
                        'post'=>self::brief($post),
                        'landing'=>self::brief($near['entity']),
                        'title_similarity'=>$near['title_similarity'],
                        'category_overlap'=>$near['category_overlap'],
                        'vocabulary_overlap'=>$near['vocabulary_overlap'],
                    ),
                    'Comprobar que la landing cubre el grupo transversal y el post una pregunta concreta. Si ambos responden a la misma intencion, consolidar en una sola URL antes de enriquecer.'
                );
            }
        }

        return $findings;
    }

    private static function audit_pages($pages, $posts, $categories, $category_to_secondary) {
        $findings = array();
        $canonical = array();
        $landings = array();

        foreach ($pages as $page) {
            $kind = (string) ($page['kind'] ?? 'editorial');
            if ('canonical' === $kind) $canonical[] = $page;
            elseif ('landing' === $kind) $landings[] = $page;
            elseif ('editorial' === $kind) {
                $findings[] = self::finding(
                    'editorial_page_role_unclassified',
                    'low',
                    'page',
                    absint($page['id'] ?? 0),
                    (string) ($page['title'] ?? ''),
                    'Pagina editorial sin funcion estructural explicita',
                    array('page'=>self::brief($page)),
                    'Clasificar la pagina existente antes de crear o mejorar contenido: landing, cluster/hub cuando corresponda o pagina editorial especial. No crear otra URL para resolver esta ambiguedad.'
                );
            }
        }

        foreach ($canonical as $page) {
            $exact = self::best_exact_match($page, array_values($posts));
            if (!$exact) continue;
            $findings[] = self::finding(
                'editorial_canonical_post_cannibalization',
                !empty($exact['exact_slug']) ? 'high' : 'medium',
                'page',
                absint($page['id'] ?? 0),
                (string) ($page['title'] ?? ''),
                'Pagina canonica solapada con un post',
                array(
                    'canonical_owner'=>self::brief($page),
                    'post'=>self::brief($exact['entity']),
                    'exact_title'=>!empty($exact['exact_title']),
                    'exact_slug'=>!empty($exact['exact_slug']),
                ),
                'La pagina estructural debe conservar la intencion amplia. Revisar el post: si no aporta una intencion informativa independiente, integrar su contenido util en la pagina y retirar el duplicado mediante borrador + 301.'
            );
        }

        foreach ($landings as $landing) {
            $id = absint($landing['id'] ?? 0);
            $cats = array_values(array_unique(array_map('absint', (array) ($landing['category_ids'] ?? array()))));

            $exact = self::best_exact_match($landing, $canonical);
            if ($exact) {
                $findings[] = self::finding(
                    'editorial_landing_canonical_cannibalization',
                    !empty($exact['exact_slug']) ? 'high' : 'medium',
                    'page',
                    $id,
                    (string) ($landing['title'] ?? ''),
                    'Landing solapada con cluster o hub canonico',
                    array(
                        'landing'=>self::brief($landing),
                        'canonical_owner'=>self::brief($exact['entity']),
                        'exact_title'=>!empty($exact['exact_title']),
                        'exact_slug'=>!empty($exact['exact_slug']),
                    ),
                    'El cluster/hub debe conservar la cobertura jerarquica. La landing solo se justifica si resuelve una necesidad transversal distinta; de lo contrario, consolidar contenido en la pagina canonica y redirigir la landing.'
                );
            } else {
                $near = self::best_supported_overlap($landing, $canonical);
                if ($near) {
                    $findings[] = self::finding(
                        'editorial_landing_structural_overlap',
                        'low',
                        'page',
                        $id,
                        (string) ($landing['title'] ?? ''),
                        'Landing con posible solapamiento con la estructura canonica',
                        array(
                            'landing'=>self::brief($landing),
                            'possible_owner'=>self::brief($near['entity']),
                            'title_similarity'=>$near['title_similarity'],
                            'category_overlap'=>$near['category_overlap'],
                            'vocabulary_overlap'=>$near['vocabulary_overlap'],
                        ),
                        'Verificar que la landing expresa una necesidad transversal diferente de la taxonomia. Si solo reformula el cluster/hub, consolidar; si es transversal, reforzar titulo, alcance y enlazado para separar claramente ambas intenciones.'
                    );
                }
            }

            $exact_post = self::best_exact_match($landing, array_values($posts));
            if ($exact_post) {
                $findings[] = self::finding(
                    'editorial_landing_post_cannibalization',
                    !empty($exact_post['exact_slug']) ? 'high' : 'medium',
                    'page',
                    $id,
                    (string) ($landing['title'] ?? ''),
                    'Landing solapada con un post',
                    array(
                        'landing'=>self::brief($landing),
                        'post'=>self::brief($exact_post['entity']),
                        'exact_title'=>!empty($exact_post['exact_title']),
                        'exact_slug'=>!empty($exact_post['exact_slug']),
                    ),
                    'Elegir propietario por intencion: landing para una necesidad amplia/transversal; post para una pregunta, guia o problema concreto. Si ambos resuelven lo mismo, fusionar contenido y redirigir el duplicado.'
                );
            }

            $category_match = self::best_category_overlap($landing, $categories);
            if ($category_match) {
                $findings[] = self::finding(
                    'editorial_landing_category_cannibalization',
                    'medium',
                    'page',
                    $id,
                    (string) ($landing['title'] ?? ''),
                    'Landing demasiado proxima a una categoria comercial',
                    array(
                        'landing'=>self::brief($landing),
                        'category'=>self::brief($category_match['entity']),
                        'title_similarity'=>$category_match['title_similarity'],
                        'linked_to_category'=>$category_match['linked'],
                    ),
                    'La categoria debe poseer la intencion comercial de su familia. Mantener la landing solo si agrupa varias categorias o resuelve una necesidad transversal distinta; si no, consolidar en la categoria y planificar 301.'
                );
            }

            if (count($cats) < 2) {
                $findings[] = self::finding(
                    'editorial_landing_scope_narrow',
                    0 === count($cats) ? 'medium' : 'low',
                    'page',
                    $id,
                    (string) ($landing['title'] ?? ''),
                    0 === count($cats) ? 'Landing sin grupo de categorias relacionado' : 'Landing limitada a una sola categoria',
                    array(
                        'landing'=>self::brief($landing),
                        'category_ids'=>$cats,
                    ),
                    0 === count($cats)
                        ? 'Una landing debe tener un alcance transversal verificable. Revisar su funcion y relaciones; si no existe un grupo real que justifique la URL, consolidarla en la entidad canonica adecuada.'
                        : 'Revisar si esta URL aporta una intencion transversal distinta. Si solo replica la familia comercial, la categoria debe ser la propietaria y la landing candidata a consolidacion.'
                );
            }

            $split = self::landing_split_signal($landing, $category_to_secondary);
            if ($split) {
                $findings[] = self::finding(
                    'editorial_landing_split_candidate',
                    'low',
                    'page',
                    $id,
                    (string) ($landing['title'] ?? ''),
                    'Landing con varios grupos estructurales relativamente independientes',
                    $split,
                    'Revisar si los grupos representan intenciones de usuario realmente distintas. Dividir solo cuando cada cohorte pueda sostener una landing propia y no compita con clusters/hubs/categorias; si la necesidad es deliberadamente transversal, conservar una sola landing.'
                );
            }
        }

        $n = count($landings);
        for ($i=0; $i<$n; $i++) {
            for ($j=$i+1; $j<$n; $j++) {
                $a = $landings[$i];
                $b = $landings[$j];
                $identity = self::exact_identity($a, $b);
                if (!empty($identity['exact_title']) || !empty($identity['exact_slug'])) {
                    $findings[] = self::finding(
                        'editorial_landing_duplicate',
                        !empty($identity['exact_slug']) ? 'high' : 'medium',
                        'page',
                        absint($a['id'] ?? 0),
                        (string) ($a['title'] ?? ''),
                        'Dos landings compiten por la misma identidad',
                        array(
                            'landing_a'=>self::brief($a),
                            'landing_b'=>self::brief($b),
                            'exact_title'=>!empty($identity['exact_title']),
                            'exact_slug'=>!empty($identity['exact_slug']),
                        ),
                        'Elegir una landing propietaria, integrar en ella el contenido exclusivo de la otra y redirigir 301 la URL retirada. No mantener dos landings para la misma intencion.'
                    );
                    continue;
                }

                $title_sim = self::title_similarity($a, $b);
                $cat = self::set_overlap((array) ($a['category_ids'] ?? array()), (array) ($b['category_ids'] ?? array()));
                $vocab = self::set_overlap((array) ($a['vocabulary'] ?? array()), (array) ($b['vocabulary'] ?? array()));
                if (
                    $cat['shared'] >= 2
                    && $cat['jaccard'] >= 0.70
                    && ($title_sim >= 0.45 || $vocab['jaccard'] >= 0.60)
                ) {
                    $findings[] = self::finding(
                        'editorial_landing_merge_candidate',
                        'low',
                        'page',
                        absint($a['id'] ?? 0),
                        (string) ($a['title'] ?? ''),
                        'Dos landings presentan una cobertura muy solapada',
                        array(
                            'landing_a'=>self::brief($a),
                            'landing_b'=>self::brief($b),
                            'title_similarity'=>round($title_sim,3),
                            'shared_categories'=>$cat['shared'],
                            'category_overlap'=>round($cat['jaccard'],3),
                            'vocabulary_overlap'=>round($vocab['jaccard'],3),
                        ),
                        'Revisar si ambas landings responden a la misma necesidad transversal. Si es asi, unirlas en la URL con mejor funcion/autoridad, preservar el contenido diferencial y aplicar 301 desde la retirada.'
                    );
                }
            }
        }

        return $findings;
    }

    private static function best_exact_match($entity, $candidates) {
        $best = array();
        foreach ((array) $candidates as $candidate) {
            if (absint($entity['id'] ?? 0) === absint($candidate['id'] ?? 0)
                && (string) ($entity['type'] ?? '') === (string) ($candidate['type'] ?? '')) continue;
            $identity = self::exact_identity($entity, $candidate);
            if (empty($identity['exact_title']) && empty($identity['exact_slug'])) continue;
            $score = (!empty($identity['exact_slug']) ? 2 : 0) + (!empty($identity['exact_title']) ? 1 : 0);
            if (!$best || $score > $best['score']) {
                $best = array(
                    'entity'=>$candidate,
                    'score'=>$score,
                    'exact_title'=>!empty($identity['exact_title']),
                    'exact_slug'=>!empty($identity['exact_slug']),
                );
            }
        }
        return $best;
    }

    private static function best_supported_overlap($entity, $candidates) {
        $best = array();
        foreach ((array) $candidates as $candidate) {
            $title = self::title_similarity($entity, $candidate);
            $cats = self::set_overlap((array) ($entity['category_ids'] ?? array()), (array) ($candidate['category_ids'] ?? array()));
            $vocab = self::set_overlap((array) ($entity['vocabulary'] ?? array()), (array) ($candidate['vocabulary'] ?? array()));
            $supported = ($title >= 0.65 && $cats['shared'] > 0 && $cats['jaccard'] >= 0.40)
                || ($title >= 0.55 && $vocab['shared'] > 0 && $vocab['jaccard'] >= 0.55);
            if (!$supported) continue;
            $score = $title + $cats['jaccard'] + $vocab['jaccard'];
            if (!$best || $score > $best['score']) {
                $best = array(
                    'entity'=>$candidate,
                    'score'=>$score,
                    'title_similarity'=>round($title,3),
                    'category_overlap'=>round($cats['jaccard'],3),
                    'vocabulary_overlap'=>round($vocab['jaccard'],3),
                );
            }
        }
        return $best;
    }

    private static function best_category_overlap($landing, $categories) {
        $linked = array_fill_keys(array_map('absint', (array) ($landing['category_ids'] ?? array())), true);
        $best = array();
        foreach ((array) $categories as $category) {
            $sim = self::title_similarity($landing, $category);
            $cid = absint($category['id'] ?? 0);
            $is_linked = $cid && isset($linked[$cid]);
            if ($sim < 0.84) continue;
            if (!$is_linked && $sim < 0.95) continue;
            if (!$best || $sim > $best['score']) {
                $best = array(
                    'entity'=>$category,
                    'score'=>$sim,
                    'title_similarity'=>round($sim,3),
                    'linked'=>$is_linked,
                );
            }
        }
        return $best;
    }

    private static function landing_split_signal($landing, $category_to_secondary) {
        $categories = array_values(array_unique(array_filter(array_map('absint', (array) ($landing['category_ids'] ?? array())))));
        if (count($categories) < 6) return array();

        $groups = array();
        $unassigned = 0;
        foreach ($categories as $cid) {
            $hubs = array_keys((array) ($category_to_secondary[$cid] ?? array()));
            if (!$hubs) {
                $unassigned++;
                continue;
            }
            foreach ($hubs as $hub_id) $groups[absint($hub_id)][$cid] = true;
        }
        if (count($groups) < 3) return array();

        $counts = array();
        foreach ($groups as $hub_id=>$members) $counts[absint($hub_id)] = count($members);
        arsort($counts, SORT_NUMERIC);
        $dominant = $counts ? max($counts) / max(1, count($categories)) : 0;
        if ($dominant > 0.60) return array();

        $cohorts = array();
        foreach (array_slice($counts, 0, 8, true) as $hub_id=>$count) {
            $cohorts[] = array(
                'hub_secondary_id'=>absint($hub_id),
                'hub_secondary_title'=>(string) get_the_title(absint($hub_id)),
                'categories'=>absint($count),
                'ratio'=>round($count / max(1, count($categories)), 3),
            );
        }

        return array(
            'landing'=>self::brief($landing),
            'categories_total'=>count($categories),
            'secondary_hubs'=>count($groups),
            'dominant_hub_ratio'=>round($dominant,3),
            'unassigned_categories'=>$unassigned,
            'cohorts'=>$cohorts,
            'signal_only'=>true,
        );
    }

    private static function exact_identity($a, $b) {
        $ta = self::norm((string) ($a['title'] ?? ''));
        $tb = self::norm((string) ($b['title'] ?? ''));
        $sa = sanitize_title((string) ($a['slug'] ?? ''));
        $sb = sanitize_title((string) ($b['slug'] ?? ''));
        return array(
            'exact_title'=>$ta !== '' && $ta === $tb,
            'exact_slug'=>$sa !== '' && $sa === $sb,
        );
    }

    private static function title_similarity($a, $b) {
        $aa = self::tokens((string) ($a['title'] ?? ''));
        $bb = self::tokens((string) ($b['title'] ?? ''));
        if (!$aa || !$bb) return 0.0;
        $inter = count(array_intersect($aa, $bb));
        $union = count(array_unique(array_merge($aa, $bb)));
        return $union ? $inter / $union : 0.0;
    }

    private static function set_overlap($a, $b) {
        $aa = array_values(array_unique(array_filter((array) $a, static function($v) { return '' !== (string) $v; })));
        $bb = array_values(array_unique(array_filter((array) $b, static function($v) { return '' !== (string) $v; })));
        if (!$aa || !$bb) return array('shared'=>0,'jaccard'=>0.0);
        $shared = count(array_intersect($aa, $bb));
        $union = count(array_unique(array_merge($aa, $bb)));
        return array('shared'=>$shared,'jaccard'=>$union ? $shared / $union : 0.0);
    }

    private static function brief($entity) {
        return array(
            'type'=>(string) ($entity['type'] ?? ''),
            'id'=>absint($entity['id'] ?? 0),
            'role'=>(string) ($entity['role'] ?? ''),
            'kind'=>(string) ($entity['kind'] ?? ''),
            'title'=>(string) ($entity['title'] ?? ''),
            'slug'=>(string) ($entity['slug'] ?? ''),
            'url'=>(string) ($entity['url'] ?? ''),
            'category_ids'=>array_values(array_map('absint', (array) ($entity['category_ids'] ?? array()))),
        );
    }

    private static function finding($code, $severity, $entity_type, $entity_id, $title, $headline, $evidence, $recommendation) {
        return array(
            'code'=>sanitize_key((string) $code),
            'severity'=>sanitize_key((string) $severity),
            'entity_type'=>sanitize_key((string) $entity_type),
            'entity_id'=>is_numeric($entity_id) ? absint($entity_id) : (string) $entity_id,
            'title'=>(string) $title,
            'headline'=>(string) $headline,
            'evidence'=>is_array($evidence) ? $evidence : array(),
            'recommendation'=>(string) $recommendation,
        );
    }

    private static function tokens($text) {
        $out = array();
        foreach (explode(' ', self::norm($text)) as $token) {
            if ($token === '' || strlen($token) < 3) continue;
            if (in_array($token, array(
                'para','como','este','esta','estos','estas','sobre','guia','guias','todo','todos',
                'todas','mejor','mejores','del','las','los','una','uno','unos','unas','con','sin',
                'por','que','debe','debo','uso','usar','producto','productos'
            ), true)) continue;
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }

    private static function norm($text) {
        $text = remove_accents(wp_strip_all_tags((string) $text));
        $text = strtolower($text);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))) === (string) $table;
    }
}
