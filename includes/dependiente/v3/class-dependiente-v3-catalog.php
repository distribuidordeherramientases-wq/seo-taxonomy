<?php

defined('ABSPATH') || exit;

/**
 * Motor de catalogo 3.0.
 *
 * Principio: recuperar amplio, ordenar por cobertura real y no volver a
 * eliminar candidatos por una segunda interpretacion. Solo se descartan
 * productos inexistentes/no publicados o filtros elegidos por el cliente.
 */
final class SEO_Dependiente_V3_Catalog {
    const MAX_GROUPS = 6;
    const MAX_VARIANTS = 4;
    const MAX_CANDIDATES = 800;

    public static function search($interpretation, $args = array()) {
        $started = microtime(true);
        $groups = array_slice((array) ($interpretation['groups'] ?? array()), 0, self::MAX_GROUPS);
        $category_filter = sanitize_title((string) ($args['category'] ?? ''));
        $page = max(1, absint($args['page'] ?? 1));
        $per_page = min(36, max(6, absint($args['per_page'] ?? 18)));

        $debug = array(
            'index_available' => SEO_Dependiente_V3_DB::exists('seo_dependiente_index'),
            'retrieval' => array('exact' => 0, 'conjunctive' => 0, 'partial' => 0, 'vocabulary' => 0, 'routes' => 0, 'learned' => 0, 'live_fallback' => 0),
            'candidates_before_publish_check' => 0,
            'candidates_after_publish_check' => 0,
            'discarded_unpublished' => 0,
            'category_filter' => $category_filter,
            'ranked_top' => array(),
        );

        if (!$debug['index_available'] && !class_exists('WooCommerce')) {
            return new WP_Error('dependiente_v3_no_catalog', 'No esta disponible el indice de Dependiente ni WooCommerce.', array('status' => 503));
        }

        $candidates = array();
        $phrase_candidates = array_values(array_unique(array_filter(array(
            SEO_Dependiente_V3_DB::normalize($interpretation['normalized'] ?? ''),
            SEO_Dependiente_V3_DB::normalize($interpretation['filtered'] ?? ''),
        ))));

        if ($debug['index_available']) {
            foreach ($phrase_candidates as $phrase) {
                if (strlen($phrase) < 3) continue;
                $rows = self::index_phrase_rows($phrase, 180);
                $debug['retrieval']['exact'] += count($rows);
                self::merge_rows($candidates, $rows, 'exact_phrase');
            }

            if ($groups) {
                $rows = self::index_group_rows($groups, true, 500);
                $debug['retrieval']['conjunctive'] = count($rows);
                self::merge_rows($candidates, $rows, 'all_groups');

                // La recuperacion parcial es una red de seguridad. Nunca sustituye
                // ni filtra los resultados que cubren todos los conceptos.
                $rows = self::index_group_rows($groups, false, 650);
                $debug['retrieval']['partial'] = count($rows);
                self::merge_rows($candidates, $rows, 'partial_groups');
            }

            $vocab_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($interpretation['vocabulary_ids'] ?? array())))));
            if ($vocab_ids) {
                $ids = self::product_ids_for_vocabulary($vocab_ids, 500);
                $debug['retrieval']['vocabulary'] = count($ids);
                $rows = self::index_rows_by_ids($ids);
                self::merge_rows($candidates, $rows, 'query_vocabulary', array('vocabulary_ids' => $vocab_ids));
            }

            foreach ((array) ($interpretation['routes'] ?? array()) as $route) {
                $route_ids = array();
                $target_id = absint($route['target_vocabulary_id'] ?? 0);
                $target_slug = SEO_Dependiente_V3_DB::normalize($route['target_slug'] ?? '');
                if ($target_id) {
                    $route_ids = self::product_ids_for_vocabulary(array($target_id), 350);
                }
                if ($route_ids) {
                    $rows = self::index_rows_by_ids($route_ids);
                } elseif ($target_slug) {
                    $rows = self::index_phrase_rows($target_slug, 250);
                } else {
                    $rows = array();
                }
                $debug['retrieval']['routes'] += count($rows);
                self::merge_rows($candidates, $rows, 'semantic_route', array('route' => $route));
            }

            foreach (array_slice((array) ($interpretation['related_search'] ?? array()), 0, 8) as $related) {
                $related = SEO_Dependiente_V3_DB::normalize($related);
                if (!$related) continue;
                $rows = self::index_phrase_rows($related, 160);
                $debug['retrieval']['learned'] += count($rows);
                self::merge_rows($candidates, $rows, 'learned_related', array('related' => $related));
            }
        }

        // Red de seguridad nueva e independiente: si el indice no da suficientes
        // coincidencias conjuntas, consulta el catalogo vivo directamente. No se
        // llama al buscador antiguo ni a funciones del Dependiente historico.
        if ($groups && ($debug['retrieval']['conjunctive'] < 8 || !$debug['index_available'])) {
            $live_ids = self::live_ids_by_groups($groups, 220);
            $missing_ids = array();
            foreach ($live_ids as $id) {
                if (!isset($candidates[$id])) {
                    $missing_ids[] = $id;
                }
            }
            foreach (array_slice($missing_ids, 0, 80) as $id) {
                $row = self::build_live_row($id);
                if ($row) {
                    self::merge_rows($candidates, array($row), 'live_fallback');
                    $debug['retrieval']['live_fallback']++;
                }
            }
        }

        if (count($candidates) > self::MAX_CANDIDATES) {
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES, true);
        }

        $debug['candidates_before_publish_check'] = count($candidates);
        $published = self::published_id_map(array_keys($candidates));
        foreach (array_keys($candidates) as $id) {
            if (empty($published[$id])) {
                unset($candidates[$id]);
                $debug['discarded_unpublished']++;
            }
        }
        $debug['candidates_after_publish_check'] = count($candidates);

        $has_action = !empty($interpretation['actions']);
        $ranked = array();
        foreach ($candidates as $id => $candidate) {
            $score = self::score_candidate($candidate, $groups, $interpretation, $has_action);
            $candidate['ranking'] = $score;
            $ranked[] = $candidate;
        }

        usort($ranked, static function ($a, $b) use ($has_action) {
            $ra = $a['ranking'];
            $rb = $b['ranking'];
            if ($has_action) {
                $cmp = (int) $rb['solution_priority'] <=> (int) $ra['solution_priority'];
                if (0 !== $cmp) return $cmp;
                $cmp = (int) $rb['route_weight'] <=> (int) $ra['route_weight'];
                if (0 !== $cmp) return $cmp;
            }
            $cmp = (int) $rb['coverage'] <=> (int) $ra['coverage'];
            if (0 !== $cmp) return $cmp;
            $cmp = (int) $rb['phrase_hit'] <=> (int) $ra['phrase_hit'];
            if (0 !== $cmp) return $cmp;
            $cmp = (int) $rb['structured_hits'] <=> (int) $ra['structured_hits'];
            if (0 !== $cmp) return $cmp;
            $cmp = (float) $rb['score'] <=> (float) $ra['score'];
            if (0 !== $cmp) return $cmp;
            return absint($b['row']['product_id'] ?? 0) <=> absint($a['row']['product_id'] ?? 0);
        });

        foreach (array_slice($ranked, 0, 20) as $item) {
            $debug['ranked_top'][] = array(
                'id' => absint($item['row']['product_id'] ?? 0),
                'title' => (string) ($item['row']['title'] ?? ''),
                'score' => round((float) $item['ranking']['score'], 2),
                'coverage' => absint($item['ranking']['coverage']),
                'coverage_total' => count($groups),
                'phrase_hit' => !empty($item['ranking']['phrase_hit']),
                'solution_priority' => !empty($item['ranking']['solution_priority']),
                'sources' => array_values(array_unique((array) ($item['sources'] ?? array()))),
                'evidence' => $item['ranking']['evidence'],
            );
        }

        if ($category_filter) {
            $ranked = array_values(array_filter($ranked, static function ($candidate) use ($category_filter) {
                foreach (SEO_Dependiente_V3_DB::json_array($candidate['row']['categories_json'] ?? '') as $cat) {
                    if (sanitize_title((string) ($cat['slug'] ?? $cat['name'] ?? '')) === $category_filter) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $categories = self::build_categories($ranked, $groups);
        $total = count($ranked);
        $offset = ($page - 1) * $per_page;
        $page_rows = array_slice($ranked, $offset, $per_page);
        $products = array();
        foreach ($page_rows as $item) {
            $products[] = self::format_product($item);
        }

        $debug['elapsed_ms'] = round((microtime(true) - $started) * 1000, 1);
        $debug['final_ranked'] = $total;

        return array(
            'products' => $products,
            'categories' => $categories,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $per_page)),
            ),
            'debug' => $debug,
        );
    }

    private static function index_select_sql() {
        return 'product_id,title,normalized_title,excerpt,sku,brand_name,brand_slug,categories_json,tags_json,vocabulary_json,attributes_json,commercial_json,search_text,price,regular_price,sale_price,weight,length,width,height,stock_status,featured,product_type,image_url,permalink,post_modified_gmt,updated_at';
    }

    private static function index_phrase_rows($phrase, $limit) {
        global $wpdb;
        if (!SEO_Dependiente_V3_DB::exists('seo_dependiente_index')) return array();
        $table = SEO_Dependiente_V3_DB::table('seo_dependiente_index');
        $phrase = SEO_Dependiente_V3_DB::normalize($phrase);
        if (!$phrase) return array();
        $like = '%' . $wpdb->esc_like($phrase) . '%';
        $sql = "SELECT " . self::index_select_sql() . " FROM {$table}
                WHERE normalized_title LIKE %s OR search_text LIKE %s
                ORDER BY CASE WHEN normalized_title LIKE %s THEN 0 ELSE 1 END, product_id DESC
                LIMIT %d";
        return (array) $wpdb->get_results($wpdb->prepare($sql, $like, $like, $like, absint($limit)), ARRAY_A);
    }

    private static function index_group_rows($groups, $require_all, $limit) {
        global $wpdb;
        if (!SEO_Dependiente_V3_DB::exists('seo_dependiente_index')) return array();
        $table = SEO_Dependiente_V3_DB::table('seo_dependiente_index');
        $clauses = array();
        $params = array();
        foreach (array_slice((array) $groups, 0, self::MAX_GROUPS) as $group) {
            $variants = array_slice(array_values(array_unique(array_filter((array) ($group['variants'] ?? array())))), 0, self::MAX_VARIANTS);
            if (!$variants) continue;
            $or = array();
            foreach ($variants as $variant) {
                $or[] = 'search_text LIKE %s';
                $params[] = '%' . $wpdb->esc_like(SEO_Dependiente_V3_DB::normalize($variant)) . '%';
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!$clauses) return array();
        $join = $require_all ? ' AND ' : ' OR ';
        $sql = "SELECT " . self::index_select_sql() . " FROM {$table} WHERE " . implode($join, $clauses) . " LIMIT %d";
        $params[] = absint($limit);
        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    private static function index_rows_by_ids($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (!$ids || !SEO_Dependiente_V3_DB::exists('seo_dependiente_index')) return array();
        $table = SEO_Dependiente_V3_DB::table('seo_dependiente_index');
        $sql = "SELECT " . self::index_select_sql() . " FROM {$table} WHERE product_id IN (" . implode(',', $ids) . ')';
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    private static function product_ids_for_vocabulary($vocabulary_ids, $limit) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $vocabulary_ids))));
        if (!$ids || !SEO_Dependiente_V3_DB::exists('seo_object_vocabulary')) return array();
        $table = SEO_Dependiente_V3_DB::table('seo_object_vocabulary');
        $sql = "SELECT DISTINCT object_id FROM {$table}
                WHERE object_type='product' AND status=1 AND vocabulary_id IN (" . implode(',', $ids) . ')
                ORDER BY object_id DESC LIMIT ' . absint($limit);
        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($sql)))));
    }

    private static function merge_rows(&$candidates, $rows, $source, $meta = array()) {
        foreach ((array) $rows as $row) {
            $id = absint($row['product_id'] ?? 0);
            if (!$id) continue;
            if (!isset($candidates[$id])) {
                $candidates[$id] = array('row' => $row, 'sources' => array(), 'route_hits' => array(), 'vocabulary_hits' => array(), 'related_hits' => array());
            }
            $candidates[$id]['sources'][] = $source;
            if ('semantic_route' === $source && !empty($meta['route'])) {
                $candidates[$id]['route_hits'][] = $meta['route'];
            }
            if ('query_vocabulary' === $source && !empty($meta['vocabulary_ids'])) {
                $candidates[$id]['vocabulary_hits'] = array_values(array_unique(array_merge($candidates[$id]['vocabulary_hits'], array_map('absint', (array) $meta['vocabulary_ids']))));
            }
            if ('learned_related' === $source && !empty($meta['related'])) {
                $candidates[$id]['related_hits'][] = $meta['related'];
            }
        }
    }

    private static function published_id_map($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (!$ids) return array();
        $rows = (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND ID IN (" . implode(',', $ids) . ')');
        return array_fill_keys(array_map('absint', $rows), true);
    }

    private static function score_candidate($candidate, $groups, $interpretation, $has_action) {
        $row = $candidate['row'];
        $fields = self::field_texts($row);
        $weights = array('title' => 140, 'vocabulary' => 125, 'category' => 115, 'tags' => 100, 'attributes' => 80, 'search' => 25);
        $score = 0.0;
        $coverage = 0;
        $structured_hits = 0;
        $evidence = array();

        foreach ((array) $groups as $group) {
            $variants = array_slice((array) ($group['variants'] ?? array()), 0, self::MAX_VARIANTS);
            $best_field = '';
            $best_points = 0;
            $best_variant = '';
            foreach ($weights as $field => $points) {
                foreach ($variants as $variant) {
                    if (SEO_Dependiente_V3_DB::contains_term($fields[$field], $variant)) {
                        if ($points > $best_points) {
                            $best_points = $points;
                            $best_field = $field;
                            $best_variant = $variant;
                        }
                    }
                }
            }
            if ($best_points > 0) {
                $coverage++;
                $score += $best_points;
                if (in_array($best_field, array('vocabulary','category','tags','attributes'), true)) {
                    $structured_hits++;
                }
                $evidence[] = array(
                    'concept' => (string) ($group['canonical'] ?? ''),
                    'variant' => $best_variant,
                    'field' => $best_field,
                    'points' => $best_points,
                );
            }
        }

        $raw_phrase = SEO_Dependiente_V3_DB::normalize($interpretation['normalized'] ?? '');
        $filtered_phrase = SEO_Dependiente_V3_DB::normalize($interpretation['filtered'] ?? '');
        $phrase_hit = 0;
        if ($raw_phrase && SEO_Dependiente_V3_DB::contains_term($fields['title'], $raw_phrase)) {
            $phrase_hit = 3;
            $score += 900;
            $evidence[] = array('concept' => $raw_phrase, 'variant' => $raw_phrase, 'field' => 'title_phrase', 'points' => 900);
        } elseif ($filtered_phrase && SEO_Dependiente_V3_DB::contains_term($fields['title'], $filtered_phrase)) {
            $phrase_hit = 2;
            $score += 700;
            $evidence[] = array('concept' => $filtered_phrase, 'variant' => $filtered_phrase, 'field' => 'title_phrase', 'points' => 700);
        } elseif ($filtered_phrase && (SEO_Dependiente_V3_DB::contains_term($fields['category'], $filtered_phrase) || SEO_Dependiente_V3_DB::contains_term($fields['vocabulary'], $filtered_phrase))) {
            $phrase_hit = 1;
            $score += 500;
            $evidence[] = array('concept' => $filtered_phrase, 'variant' => $filtered_phrase, 'field' => 'structured_phrase', 'points' => 500);
        }

        $total_groups = count((array) $groups);
        if ($total_groups > 1 && $coverage === $total_groups) {
            $bonus = 500 + (100 * $total_groups);
            $score += $bonus;
            $evidence[] = array('concept' => 'cobertura_completa', 'variant' => $coverage . '/' . $total_groups, 'field' => 'coverage', 'points' => $bonus);
        }

        if (!empty($candidate['vocabulary_hits'])) {
            $score += 180;
            $structured_hits++;
            $evidence[] = array('concept' => 'vocabulary_asignado', 'variant' => implode(',', array_map('absint', $candidate['vocabulary_hits'])), 'field' => 'vocabulary_id', 'points' => 180);
        }

        $route_weight = 0;
        foreach ((array) ($candidate['route_hits'] ?? array()) as $route) {
            $route_weight = max($route_weight, absint($route['weight'] ?? 0));
        }
        $solution_priority = $has_action && !empty($candidate['route_hits']) ? 1 : 0;
        if ($solution_priority) {
            $bonus = 650 + min(500, $route_weight);
            $score += $bonus;
            $evidence[] = array('concept' => 'ruta_aprendida', 'variant' => (string) ($candidate['route_hits'][0]['target_slug'] ?? ''), 'field' => 'semantic_route', 'points' => $bonus);
        } elseif ($has_action && !empty($candidate['related_hits'])) {
            $score += 320;
            $evidence[] = array('concept' => 'relacion_aprendida', 'variant' => (string) $candidate['related_hits'][0], 'field' => 'lexicon', 'points' => 320);
        }

        return array(
            'score' => $score,
            'coverage' => $coverage,
            'phrase_hit' => $phrase_hit,
            'structured_hits' => $structured_hits,
            'solution_priority' => $solution_priority,
            'route_weight' => $route_weight,
            'evidence' => $evidence,
        );
    }

    private static function field_texts($row) {
        $category = implode(' ', SEO_Dependiente_V3_DB::flatten_strings(SEO_Dependiente_V3_DB::json_array($row['categories_json'] ?? '')));
        $tags = implode(' ', SEO_Dependiente_V3_DB::flatten_strings(SEO_Dependiente_V3_DB::json_array($row['tags_json'] ?? '')));
        $vocabulary = implode(' ', SEO_Dependiente_V3_DB::flatten_strings(SEO_Dependiente_V3_DB::json_array($row['vocabulary_json'] ?? '')));
        $attributes = implode(' ', SEO_Dependiente_V3_DB::flatten_strings(SEO_Dependiente_V3_DB::json_array($row['attributes_json'] ?? '')));
        return array(
            'title' => SEO_Dependiente_V3_DB::normalize($row['title'] ?? ''),
            'category' => $category,
            'tags' => $tags,
            'vocabulary' => $vocabulary,
            'attributes' => $attributes,
            'search' => SEO_Dependiente_V3_DB::normalize($row['search_text'] ?? ''),
        );
    }

    private static function build_categories($ranked, $groups) {
        $map = array();
        foreach (array_slice((array) $ranked, 0, 160) as $position => $candidate) {
            $cats = SEO_Dependiente_V3_DB::json_array($candidate['row']['categories_json'] ?? '');
            foreach ($cats as $cat) {
                $name = trim((string) ($cat['name'] ?? ''));
                $slug = sanitize_title((string) ($cat['slug'] ?? $name));
                if (!$name || !$slug) continue;
                if (!isset($map[$slug])) {
                    $map[$slug] = array('name' => $name, 'slug' => $slug, 'count' => 0, 'first_rank' => $position, 'best_score' => 0, 'coverage' => 0);
                }
                $map[$slug]['count']++;
                $map[$slug]['first_rank'] = min($map[$slug]['first_rank'], $position);
                $map[$slug]['best_score'] = max($map[$slug]['best_score'], (float) ($candidate['ranking']['score'] ?? 0));
                $cat_text = SEO_Dependiente_V3_DB::normalize($name . ' ' . $slug);
                $coverage = 0;
                foreach ((array) $groups as $group) {
                    if (SEO_Dependiente_V3_DB::contains_any($cat_text, (array) ($group['variants'] ?? array()))) {
                        $coverage++;
                    }
                }
                $map[$slug]['coverage'] = max($map[$slug]['coverage'], $coverage);
            }
        }
        $cats = array_values($map);
        usort($cats, static function ($a, $b) {
            $cmp = (int) $b['coverage'] <=> (int) $a['coverage'];
            if (0 !== $cmp) return $cmp;
            $cmp = (int) $a['first_rank'] <=> (int) $b['first_rank'];
            if (0 !== $cmp) return $cmp;
            $cmp = (float) $b['best_score'] <=> (float) $a['best_score'];
            if (0 !== $cmp) return $cmp;
            return (int) $b['count'] <=> (int) $a['count'];
        });
        return array_slice($cats, 0, 12);
    }

    private static function format_product($candidate) {
        $row = $candidate['row'];
        $id = absint($row['product_id'] ?? 0);
        $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
        $categories = array();
        foreach (SEO_Dependiente_V3_DB::json_array($row['categories_json'] ?? '') as $cat) {
            if (!empty($cat['name'])) $categories[] = (string) $cat['name'];
        }
        return array(
            'id' => $id,
            'title' => (string) ($row['title'] ?? get_the_title($id)),
            'url' => (string) ($row['permalink'] ?? get_permalink($id)),
            'image' => (string) ($row['image_url'] ?? ''),
            'price' => isset($row['price']) ? (float) $row['price'] : null,
            'price_html' => $product ? $product->get_price_html() : '',
            'stock_status' => (string) ($row['stock_status'] ?? ''),
            'in_stock' => $product ? $product->is_in_stock() : ('instock' === ($row['stock_status'] ?? '')),
            'brand' => (string) ($row['brand_name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'categories' => array_values(array_unique($categories)),
            'excerpt' => wp_trim_words(wp_strip_all_tags((string) ($row['excerpt'] ?? '')), 28, '…'),
            'score' => round((float) ($candidate['ranking']['score'] ?? 0), 2),
            'coverage' => absint($candidate['ranking']['coverage'] ?? 0),
            'sources' => array_values(array_unique((array) ($candidate['sources'] ?? array()))),
        );
    }

    private static function live_ids_by_groups($groups, $limit) {
        global $wpdb;
        if (!$groups) return array();
        $joins = array(
            "LEFT JOIN {$wpdb->term_relationships} tr3 ON tr3.object_id=p.ID",
            "LEFT JOIN {$wpdb->term_taxonomy} tt3 ON tt3.term_taxonomy_id=tr3.term_taxonomy_id AND tt3.taxonomy='product_cat'",
            "LEFT JOIN {$wpdb->terms} t3 ON t3.term_id=tt3.term_id",
        );
        $use_vocab = SEO_Dependiente_V3_DB::exists('seo_object_vocabulary') && SEO_Dependiente_V3_DB::exists('seo_vocabulary');
        if ($use_vocab) {
            $ov = SEO_Dependiente_V3_DB::table('seo_object_vocabulary');
            $v = SEO_Dependiente_V3_DB::table('seo_vocabulary');
            $joins[] = "LEFT JOIN {$ov} ov3 ON ov3.object_type='product' AND ov3.object_id=p.ID AND ov3.status=1";
            $joins[] = "LEFT JOIN {$v} v3 ON v3.id=ov3.vocabulary_id AND v3.active=1";
        }
        $where = array();
        $params = array();
        foreach (array_slice((array) $groups, 0, self::MAX_GROUPS) as $group) {
            $ors = array();
            foreach (array_slice((array) ($group['variants'] ?? array()), 0, self::MAX_VARIANTS) as $variant) {
                $like = '%' . $wpdb->esc_like(SEO_Dependiente_V3_DB::normalize($variant)) . '%';
                $ors[] = 'LOWER(p.post_title) LIKE %s'; $params[] = $like;
                $ors[] = 'LOWER(p.post_excerpt) LIKE %s'; $params[] = $like;
                $ors[] = 'LOWER(t3.name) LIKE %s'; $params[] = $like;
                if ($use_vocab) {
                    $ors[] = '(LOWER(v3.label) LIKE %s OR LOWER(v3.slug) LIKE %s)'; $params[] = $like; $params[] = $like;
                }
            }
            if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
        }
        if (!$where) return array();
        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p " . implode("\n", $joins) . "
                WHERE p.post_type='product' AND p.post_status='publish' AND " . implode(' AND ', $where) . "
                ORDER BY p.ID DESC LIMIT %d";
        $params[] = absint($limit);
        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params))))));
    }

    private static function build_live_row($product_id) {
        global $wpdb;
        $product_id = absint($product_id);
        if (!$product_id || !function_exists('wc_get_product')) return array();
        $product = wc_get_product($product_id);
        $post = get_post($product_id);
        if (!$product || !$post || 'publish' !== $post->post_status) return array();
        $categories = array();
        foreach ((array) wp_get_post_terms($product_id, 'product_cat') as $term) {
            if ($term instanceof WP_Term) $categories[] = array('id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug);
        }
        $tags = array();
        if (taxonomy_exists('product_tag')) {
            foreach ((array) wp_get_post_terms($product_id, 'product_tag') as $term) {
                if ($term instanceof WP_Term) $tags[] = array('id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug);
            }
        }
        $vocabulary = array();
        if (SEO_Dependiente_V3_DB::exists('seo_object_vocabulary') && SEO_Dependiente_V3_DB::exists('seo_vocabulary')) {
            $ov = SEO_Dependiente_V3_DB::table('seo_object_vocabulary');
            $v = SEO_Dependiente_V3_DB::table('seo_vocabulary');
            $vocabulary = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT v.id,v.semantic_group,v.slug,v.label FROM {$ov} ov INNER JOIN {$v} v ON v.id=ov.vocabulary_id
                 WHERE ov.object_type='product' AND ov.object_id=%d AND ov.status=1 AND v.active=1",
                $product_id
            ), ARRAY_A);
        }
        $attributes = array();
        foreach ((array) $product->get_attributes() as $name => $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'get_options')) {
                $attributes[$name] = $attribute->get_options();
            }
        }
        $title = get_the_title($product_id);
        $search_text = SEO_Dependiente_V3_DB::normalize($title . ' ' . $post->post_excerpt . ' ' . $post->post_content . ' ' . implode(' ', SEO_Dependiente_V3_DB::flatten_strings($categories)) . ' ' . implode(' ', SEO_Dependiente_V3_DB::flatten_strings($tags)) . ' ' . implode(' ', SEO_Dependiente_V3_DB::flatten_strings($vocabulary)) . ' ' . implode(' ', SEO_Dependiente_V3_DB::flatten_strings($attributes)));
        $image_id = $product->get_image_id();
        $image = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : '';
        return array(
            'product_id' => $product_id,
            'title' => $title,
            'normalized_title' => SEO_Dependiente_V3_DB::normalize($title),
            'excerpt' => $post->post_excerpt,
            'sku' => $product->get_sku(),
            'brand_name' => '', 'brand_slug' => '',
            'categories_json' => wp_json_encode($categories),
            'tags_json' => wp_json_encode($tags),
            'vocabulary_json' => wp_json_encode($vocabulary),
            'attributes_json' => wp_json_encode($attributes),
            'commercial_json' => '{}',
            'search_text' => $search_text,
            'price' => '' !== $product->get_price() ? (float) $product->get_price() : null,
            'regular_price' => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : null,
            'sale_price' => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'weight' => $product->get_weight(), 'length' => '', 'width' => '', 'height' => '',
            'stock_status' => $product->get_stock_status(), 'featured' => $product->is_featured() ? 1 : 0,
            'product_type' => $product->get_type(), 'image_url' => $image ?: '', 'permalink' => get_permalink($product_id),
            'post_modified_gmt' => $post->post_modified_gmt, 'updated_at' => current_time('mysql'),
        );
    }
}
