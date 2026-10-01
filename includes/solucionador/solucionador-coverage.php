<?php
/**
 * Solucionador - indice editorial y comprobacion de cobertura RF v1.0.
 *
 * La cobertura es multientidad: posts, paginas/landings/hubs y product_cat.
 * Solucionador no crea contenido desde aqui; solo responde que URL ya existe,
 * cuanto cubre la intencion y si hay riesgo de duplicacion/canibalizacion.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Coverage {
    private static function object_vocabulary_text($object_type, $object_id) {
        global $wpdb;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($ov) || !SEO_Solucionador_DB::table_exists($v)) return '';
        $labels = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT v.label
             FROM {$ov} ov
             INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.object_type=%s AND ov.object_id=%d AND ov.status=1
             ORDER BY v.semantic_group,v.id LIMIT 60",
            sanitize_key((string) $object_type),
            absint($object_id)
        ));
        return implode(' ', array_filter(array_map('sanitize_text_field', $labels)));
    }

    private static function post_category_ids($post_id) {
        global $wpdb;
        $relations = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($relations)) return array();
        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT target_id
             FROM {$relations}
             WHERE source_type='post' AND source_id=%d
               AND target_type='product_cat' AND relation_type='post_to_category'
             ORDER BY target_id ASC LIMIT 30",
            absint($post_id)
        ))))));
    }

    private static function post_category_text($post_id) {
        $names = array();
        foreach (self::post_category_ids($post_id) as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) $names[] = (string) $term->name;
        }
        return implode(' ', $names);
    }

    private static function page_role($page_id) {
        global $wpdb;
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (!SEO_Solucionador_DB::table_exists($nodes)) return 'page';
        $role = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT seo_role FROM {$nodes}
             WHERE object_type='page' AND object_id=%d AND status=1
             ORDER BY FIELD(seo_role,'landing','hub_secondary','hub_primary','cluster','corporate_page') ASC,id ASC
             LIMIT 1",
            absint($page_id)
        ));
        return sanitize_key($role) ?: 'page';
    }

    private static function page_category_ids($page_id, $role = '') {
        global $wpdb;
        $relations = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($relations)) return array();

        $page_id = absint($page_id);
        $role = sanitize_key((string) $role);
        $ids = array();

        // Cada relacion se filtra tambien por source_type. Los IDs de posts,
        // paginas y terminos pueden coincidir numericamente y no deben mezclarse.
        foreach ((array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT target_id FROM {$relations}
             WHERE source_id=%d
               AND target_type='product_cat'
               AND (
                    (source_type='landing' AND relation_type='landing_to_category')
                    OR
                    (source_type IN ('hub_secondary','hub_secundario') AND relation_type='hub_secondary_to_category')
               )
             ORDER BY target_id ASC LIMIT 50",
            $page_id
        )) as $id) {
            if (absint($id)) $ids[] = absint($id);
        }

        // Hubs primarios/clusters heredan categorias descendientes solo como
        // contexto. No se usa esa herencia para afirmar cobertura exacta.
        if (!$ids && $role === 'hub_primary') {
            $children = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id FROM {$relations}
                 WHERE source_type='hub_primary' AND source_id=%d
                   AND target_type IN ('hub_secondary','hub_secundario')
                   AND relation_type IN ('hub_primary_to_hub_secondary','hub_primary_to_secondary')
                 ORDER BY target_id ASC LIMIT 100",
                $page_id
            ));
            foreach ($children as $child_id) {
                foreach ((array) $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT target_id FROM {$relations}
                     WHERE source_type IN ('hub_secondary','hub_secundario')
                       AND source_id=%d
                       AND target_type='product_cat'
                       AND relation_type='hub_secondary_to_category'
                     ORDER BY target_id ASC LIMIT 50",
                    absint($child_id)
                )) as $id) {
                    if (absint($id)) $ids[] = absint($id);
                }
            }
        } elseif (!$ids && $role === 'cluster') {
            $primaries = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id FROM {$relations}
                 WHERE source_type='cluster' AND source_id=%d
                   AND target_type='hub_primary'
                   AND relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
                 ORDER BY target_id ASC LIMIT 100",
                $page_id
            ));
            foreach ($primaries as $primary_id) {
                $secondaries = (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT target_id FROM {$relations}
                     WHERE source_type='hub_primary' AND source_id=%d
                       AND target_type IN ('hub_secondary','hub_secundario')
                       AND relation_type IN ('hub_primary_to_hub_secondary','hub_primary_to_secondary')
                     ORDER BY target_id ASC LIMIT 100",
                    absint($primary_id)
                ));
                foreach ($secondaries as $child_id) {
                    foreach ((array) $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT target_id FROM {$relations}
                         WHERE source_type IN ('hub_secondary','hub_secundario')
                           AND source_id=%d
                           AND target_type='product_cat'
                           AND relation_type='hub_secondary_to_category'
                         ORDER BY target_id ASC LIMIT 50",
                        absint($child_id)
                    )) as $id) {
                        if (absint($id)) $ids[] = absint($id);
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function category_text($term_id) {
        global $wpdb;
        $nodes = $wpdb->prefix . 'seo_nodes';
        $parts = array();
        $term = get_term(absint($term_id), 'product_cat');
        if ($term && !is_wp_error($term)) {
            $parts[] = (string) $term->name;
            if (!empty($term->description)) $parts[] = (string) $term->description;
        }
        if (SEO_Solucionador_DB::table_exists($nodes)) {
            $rows = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT keywords FROM {$nodes}
                 WHERE object_type='category' AND object_id=%d
                   AND seo_role IN ('excerpt','description','category') AND status=1
                 ORDER BY FIELD(seo_role,'excerpt','description','category') ASC,id ASC",
                absint($term_id)
            ));
            foreach ($rows as $text) if (trim((string) $text) !== '') $parts[] = (string) $text;
        }
        return trim(implode("
", $parts));
    }

    private static function headings($html) {
        $out = array();
        if (preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/isu', (string) $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $heading) {
                $heading = trim(wp_strip_all_tags((string) $heading));
                if ($heading !== '') $out[] = $heading;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 60);
    }

    private static function context_supports_object($object, $article_context) {
        $object = SEO_Solucionador_Normalizer::normalize((string) $object);
        $context = SEO_Solucionador_Normalizer::normalize((string) $article_context);
        if ($object === '' || $context === '') return false;
        return false !== strpos(' ' . $context . ' ', ' ' . $object . ' ');
    }

    private static function inherited_heading_profile($heading, array $title_profile, $article_context, $category_id = 0) {
        if (!SEO_Solucionador_Normalizer::is_solution_signal($heading)) return array();
        $profile = SEO_Solucionador_Normalizer::profile($heading,array('category_id'=>absint($category_id)));
        if (!$profile) return array();

        $title_object = (string) ($title_profile['object'] ?? '');
        $heading_object = (string) ($profile['object'] ?? '');
        $supported_object = $heading_object !== '' && (
            $heading_object === $title_object ||
            self::context_supports_object($heading_object, $article_context)
        );

        if (SEO_Solucionador_Normalizer::is_weak_profile($profile) || !$supported_object) {
            if ($title_object === '') return array();
            $profile = SEO_Solucionador_Normalizer::profile(trim($heading . ' ' . $article_context), array(
                'action'=>(string) (($profile['action'] ?? '') ?: ($title_profile['action'] ?? '')),
                'object'=>$title_object,
                'context'=>(string) (($profile['context'] ?? '') ?: ($title_profile['context'] ?? '')),
                'state'=>(string) (($profile['condition'] ?? '') ?: ($title_profile['condition'] ?? '')),
                'intent'=>(string) (($profile['intent'] ?? '') ?: ($title_profile['intent'] ?? '')),
                'category_id'=>absint($category_id),
            ));
        }
        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) return array();
        return $profile;
    }

    private static function insert_item($entity_type,$entity_id,$seo_role,$category_id,$scope,$title,$url,$text,$profile,$vocabulary='') {
        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) return false;
        return SEO_Solucionador_DB::insert_coverage_item(array(
            'entity_type'=>$entity_type,
            'entity_id'=>absint($entity_id),
            'seo_role'=>$seo_role,
            'category_id'=>absint($category_id),
            'scope'=>$scope,
            'title'=>$title,
            'url'=>$url,
            'source_text'=>$text,
            'profile'=>$profile,
            'vocabulary_text'=>$vocabulary,
        ));
    }

    private static function index_post(array $post) {
        $post_id = absint($post['ID'] ?? 0);
        if (!$post_id) return 0;

        $category_ids = self::post_category_ids($post_id);
        $category_id = absint(reset($category_ids));
        $vocab = self::object_vocabulary_text('post',$post_id);
        $categories = self::post_category_text($post_id);
        $title = trim((string) ($post['post_title'] ?? ''));
        $content = (string) ($post['post_content'] ?? '');
        $semantic_context = trim($vocab . ' ' . $categories);
        $article_context = trim($title . ' ' . $semantic_context);
        $editorial_type = SEO_Solucionador_Normalizer::editorial_type($title,$content);

        if (!SEO_Solucionador_Normalizer::coverage_eligible_editorial_type($editorial_type)) return 0;

        $profile = SEO_Solucionador_Normalizer::profile($title,array('category_id'=>$category_id));
        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) {
            $profile = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $semantic_context),array('category_id'=>$category_id));
        }

        $count = 0;
        if ($profile) {
            SEO_Solucionador_DB::insert_post_topic($post_id,'title',$title,$profile); // compatibilidad.
            if (self::insert_item('post',$post_id,'post',$category_id,'title',$title,get_permalink($post_id),$title,$profile,$vocab)) $count++;
        }

        if ($profile) {
            foreach (self::headings($content) as $heading) {
                $hp = self::inherited_heading_profile($heading,$profile,$article_context,$category_id);
                if (!$hp) continue;
                SEO_Solucionador_DB::insert_post_topic($post_id,'heading',$heading,$hp);
                if (self::insert_item('post',$post_id,'post',$category_id,'heading',$title,get_permalink($post_id),$heading,$hp,$vocab)) $count++;
            }

            $plain = trim(wp_strip_all_tags(strip_shortcodes($content)));
            if ($plain !== '') {
                $snippet = wp_trim_words($plain,90,'');
                $cp = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $snippet),array(
                    'action'=>(string) ($profile['action'] ?? ''),
                    'object'=>(string) ($profile['object'] ?? ''),
                    'context'=>(string) ($profile['context'] ?? ''),
                    'state'=>(string) ($profile['condition'] ?? ''),
                    'intent'=>(string) ($profile['intent'] ?? ''),
                    'category_id'=>$category_id,
                ));
                if ($cp && self::insert_item('post',$post_id,'post',$category_id,'content',$title,get_permalink($post_id),$snippet,$cp,$vocab)) $count++;
            }
        }
        return $count;
    }

    private static function index_page(array $page) {
        $page_id = absint($page['ID'] ?? 0);
        if (!$page_id) return 0;
        $role = self::page_role($page_id);
        $category_ids = self::page_category_ids($page_id,$role);
        $category_id = absint(reset($category_ids));
        $vocab = self::object_vocabulary_text('page',$page_id);
        $title = trim((string) ($page['post_title'] ?? ''));
        $content = (string) ($page['post_content'] ?? '');
        $url = get_permalink($page_id);

        $hints = array('category_id'=>$category_id);
        if (in_array($role,array('landing','hub_secondary','hub_primary','cluster'),true)) {
            $hints['intent'] = 'decision';
            $hints['action'] = 'elegir';
        }
        $profile = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $vocab),$hints);
        $count = 0;
        if ($profile && self::insert_item('page',$page_id,$role,$category_id,'title',$title,$url,$title,$profile,$vocab)) $count++;

        if ($profile) {
            $context = trim($title . ' ' . $vocab);
            foreach (self::headings($content) as $heading) {
                $hp = self::inherited_heading_profile($heading,$profile,$context,$category_id);
                if ($hp && self::insert_item('page',$page_id,$role,$category_id,'heading',$title,$url,$heading,$hp,$vocab)) $count++;
            }
            $plain = trim(wp_strip_all_tags(strip_shortcodes($content)));
            if ($plain !== '') {
                $snippet = wp_trim_words($plain,90,'');
                $cp = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $snippet),array_merge($hints,array(
                    'object'=>(string) ($profile['object'] ?? ''),
                    'context'=>(string) ($profile['context'] ?? ''),
                )));
                if ($cp && self::insert_item('page',$page_id,$role,$category_id,'content',$title,$url,$snippet,$cp,$vocab)) $count++;
            }
        }
        return $count;
    }

    private static function index_category($term) {
        if (!$term || is_wp_error($term)) return 0;
        $term_id = absint($term->term_id ?? 0);
        if (!$term_id) return 0;

        $title = (string) $term->name;
        $content = self::category_text($term_id);
        $vocab = self::object_vocabulary_text('product_cat',$term_id);
        $url = get_term_link($term);
        if (is_wp_error($url)) $url = '';

        // Una categoria representa la intencion comercial principal de su familia.
        $profile = SEO_Solucionador_Normalizer::profile('elegir ' . $title,array(
            'intent'=>'decision',
            'action'=>'elegir',
            'object'=>$title,
            'category_id'=>$term_id,
        ));
        $count = 0;
        if ($profile && self::insert_item('product_cat',$term_id,'category',$term_id,'title',$title,$url,$title,$profile,$vocab)) $count++;

        foreach (self::headings($content) as $heading) {
            $hp = self::inherited_heading_profile($heading,$profile,trim($title . ' ' . $vocab),$term_id);
            if ($hp && self::insert_item('product_cat',$term_id,'category',$term_id,'heading',$title,$url,$heading,$hp,$vocab)) $count++;
        }

        $plain = trim(wp_strip_all_tags($content));
        if ($plain !== '' && $profile) {
            $snippet = wp_trim_words($plain,100,'');
            $cp = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $snippet),array(
                'intent'=>'decision',
                'action'=>'elegir',
                'object'=>$title,
                'category_id'=>$term_id,
            ));
            if ($cp && self::insert_item('product_cat',$term_id,'category',$term_id,'content',$title,$url,$snippet,$cp,$vocab)) $count++;
        }
        return $count;
    }

    public static function rebuild_index($limit = 5000) {
        global $wpdb;
        SEO_Solucionador_DB::clear_post_topics();
        SEO_Solucionador_DB::clear_coverage_index();
        $limit = min(12000,max(100,absint($limit)));

        $posts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_title,post_excerpt,post_content
             FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status IN ('publish','future','draft')
             ORDER BY ID ASC LIMIT %d",
            $limit
        ),ARRAY_A);
        $pages = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_title,post_excerpt,post_content
             FROM {$wpdb->posts}
             WHERE post_type='page' AND post_status IN ('publish','future','draft')
             ORDER BY ID ASC LIMIT %d",
            min($limit,5000)
        ),ARRAY_A);
        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        if (is_wp_error($terms)) $terms = array();

        $rows = 0;
        foreach ($posts as $post) $rows += self::index_post($post);
        foreach ($pages as $page) $rows += self::index_page($page);
        foreach ((array) $terms as $term) $rows += self::index_category($term);

        return array(
            'posts'=>count($posts),
            'pages'=>count($pages),
            'categories'=>count((array) $terms),
            'topics'=>$rows,
        );
    }

    // Compatibilidad con el nombre historico.
    public static function rebuild_post_index($limit = 5000) {
        return self::rebuild_index($limit);
    }

    private static function contradiction($left,$right) {
        $a = SEO_Solucionador_Normalizer::normalize((string) $left);
        $b = SEO_Solucionador_Normalizer::normalize((string) $right);
        $pairs = array(
            array('compatible','incompatible'),
            array('se puede','no se puede'),
            array('debe','no debe'),
            array('recomendado','no recomendado'),
            array('seguro','no seguro'),
        );
        foreach ($pairs as $pair) {
            $a1 = strpos($a,$pair[0]) !== false; $a2 = strpos($a,$pair[1]) !== false;
            $b1 = strpos($b,$pair[0]) !== false; $b2 = strpos($b,$pair[1]) !== false;
            if (($a1 && $b2) || ($a2 && $b1)) return true;
        }
        return false;
    }

    private static function score_row(array $profile,array $row) {
        $action = (string) ($profile['action'] ?? '');
        $object = (string) ($profile['object'] ?? '');
        $condition = (string) ($profile['condition'] ?? '');
        $context = (string) ($profile['context'] ?? '');
        $category_id = absint($profile['category_id'] ?? 0);

        $candidate = implode(' ',array_filter(array($action,$object,str_replace('_',' ',$condition),$context)));
        $score = SEO_Solucionador_Normalizer::similarity($candidate,(string) ($row['source_text'] ?? ''));
        if ($action !== '' && $action === (string) ($row['action_term'] ?? '')) $score += 0.22;
        if ($object !== '' && $object === (string) ($row['object_term'] ?? '')) $score += 0.34;
        elseif ($object !== '' && (string) ($row['object_term'] ?? '') !== '') {
            $score += 0.12 * SEO_Solucionador_Normalizer::similarity($object,(string) $row['object_term']);
        }
        if ($condition !== '' && $condition === (string) ($row['condition_term'] ?? '')) $score += 0.16;
        if ($context !== '' && $context === (string) ($row['context_term'] ?? '')) $score += 0.12;
        if ($category_id && $category_id === absint($row['category_id'] ?? 0)) $score += 0.22;
        if ((string) ($row['scope'] ?? '') === 'title') $score += 0.10;
        if ((string) ($row['scope'] ?? '') === 'content') $score -= 0.05;
        return max(0,min(1,$score));
    }

    public static function find(array $profile) {
        global $wpdb;
        $table = SEO_Solucionador_DB::coverage_table();
        $key = sanitize_text_field((string) ($profile['canonical_key'] ?? ''));
        if ($key === '' || !SEO_Solucionador_DB::table_exists($table)) {
            return array('status'=>'uncovered','entity_type'=>'','entity_id'=>0,'post_id'=>0,'score'=>0,'scope'=>'','matches'=>array());
        }

        $object = sanitize_text_field((string) ($profile['object'] ?? ''));
        $action = sanitize_text_field((string) ($profile['action'] ?? ''));
        $category_id = absint($profile['category_id'] ?? 0);

        $where = array('canonical_key=%s');
        $params = array($key);
        if ($object !== '') { $where[]='object_term=%s'; $params[]=$object; }
        if ($action !== '') { $where[]='action_term=%s'; $params[]=$action; }
        if ($category_id) { $where[]='category_id=%d'; $params[]=$category_id; }

        $sql = "SELECT * FROM {$table} WHERE (" . implode(' OR ',$where) . ")
                ORDER BY CASE WHEN canonical_key=%s THEN 0 WHEN category_id=%d AND %d>0 THEN 1 ELSE 2 END,
                         CASE WHEN scope='title' THEN 0 WHEN scope='heading' THEN 1 ELSE 2 END,
                         confidence DESC,id ASC
                LIMIT 700";
        $params[] = $key;
        $params[] = $category_id;
        $params[] = $category_id;
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A);

        $ranked = array();
        foreach ($rows as $row) {
            $score = self::score_row($profile,$row);
            if ($score < 0.42) continue;
            $row['match_score'] = $score;
            $ranked[] = $row;
        }
        usort($ranked,static function($a,$b){ return ($b['match_score'] <=> $a['match_score']); });
        $ranked = array_slice($ranked,0,20);

        if (!$ranked) {
            return array('status'=>'uncovered','entity_type'=>'','entity_id'=>0,'post_id'=>0,'score'=>0,'scope'=>'','matches'=>array());
        }

        $best = $ranked[0];
        $best_score = (float) $best['match_score'];
        $strong = array_values(array_filter($ranked,static function($row){ return (float) ($row['match_score'] ?? 0) >= 0.82; }));
        $entities = array();
        foreach ($strong as $row) $entities[(string)$row['entity_type'] . ':' . absint($row['entity_id'])] = $row;

        $status = 'uncovered';
        if (count($entities) > 1) {
            $status = 'duplicate';
            $strong_values = array_values($entities);
            for ($i=0;$i<count($strong_values);$i++) {
                for ($j=$i+1;$j<count($strong_values);$j++) {
                    if (self::contradiction($strong_values[$i]['source_text'] ?? '',$strong_values[$j]['source_text'] ?? '')) {
                        $status = 'conflict';
                        break 2;
                    }
                }
            }
        } elseif ($best_score >= 0.90 && in_array((string) ($best['scope'] ?? ''),array('title','heading'),true)) {
            $status = 'covered';
        } elseif ($best_score >= 0.72) {
            $status = 'partial_coverage';
        } elseif ($best_score >= 0.52) {
            $status = 'weak_coverage';
        }

        $matches = array();
        foreach (array_slice($ranked,0,10) as $row) {
            $matches[] = array(
                'entity_type'=>(string) ($row['entity_type'] ?? ''),
                'entity_id'=>absint($row['entity_id'] ?? 0),
                'seo_role'=>(string) ($row['seo_role'] ?? ''),
                'category_id'=>absint($row['category_id'] ?? 0),
                'scope'=>(string) ($row['scope'] ?? ''),
                'title'=>(string) ($row['title'] ?? ''),
                'url'=>(string) ($row['url'] ?? ''),
                'source_text'=>(string) ($row['source_text'] ?? ''),
                'score'=>round((float) ($row['match_score'] ?? 0),4),
            );
        }

        $entity_type = (string) ($best['entity_type'] ?? '');
        $entity_id = absint($best['entity_id'] ?? 0);
        return array(
            'status'=>$status,
            'entity_type'=>$entity_type,
            'entity_id'=>$entity_id,
            'post_id'=>$entity_type === 'post' ? $entity_id : 0,
            'score'=>round($best_score,4),
            'scope'=>(string) ($best['scope'] ?? ''),
            'seo_role'=>(string) ($best['seo_role'] ?? ''),
            'category_id'=>absint($best['category_id'] ?? 0),
            'title'=>(string) ($best['title'] ?? ''),
            'url'=>(string) ($best['url'] ?? ''),
            'matches'=>$matches,
        );
    }
}
