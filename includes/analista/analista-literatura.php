<?php
/**
 * Literatura, contenidos y estructura editorial para Analista.
 *
 * Convierte el contenido real de WordPress + la arquitectura SEO + Search
 * Console en directrices concretas: que URL mejorar, que parte revisar y que
 * entidad impulsar cuando ya existe una base correcta y aparece demanda.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_word_count')) {
    function seo_analista_word_count($text) {
        $text = wp_strip_all_tags(strip_shortcodes((string) $text));
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') return 0;
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }
}

if (!function_exists('seo_analista_first_meta')) {
    function seo_analista_first_meta($post_id, array $keys) {
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta(absint($post_id), $key, true));
            if ($value !== '') return $value;
        }
        return '';
    }
}

if (!function_exists('seo_analista_seo_meta_snapshot')) {
    function seo_analista_seo_meta_snapshot($post_id) {
        return array(
            'title' => seo_analista_first_meta($post_id, array(
                '_yoast_wpseo_title', 'rank_math_title', '_rank_math_title',
                '_seopress_titles_title', '_aioseo_title', '_seo_title', 'seo_title',
            )),
            'description' => seo_analista_first_meta($post_id, array(
                '_yoast_wpseo_metadesc', 'rank_math_description', '_rank_math_description',
                '_seopress_titles_desc', '_aioseo_description', '_seo_description', 'seo_description',
            )),
        );
    }
}

if (!function_exists('seo_analista_internal_link_count')) {
    function seo_analista_internal_link_count($html) {
        $html = (string) $html;
        if ($html === '') return 0;
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $count = 0;
        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $href) {
                $href = trim((string) $href);
                if ($href === '') continue;
                if ($href[0] === '/' && (strlen($href) < 2 || $href[1] !== '/')) {
                    $count++;
                    continue;
                }
                $href_host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
                if ($href_host !== '' && $host !== '' && preg_replace('/^www\./', '', $href_host) === preg_replace('/^www\./', '', $host)) {
                    $count++;
                }
            }
        }
        return $count;
    }
}

if (!function_exists('seo_analista_path_key')) {
    function seo_analista_path_key($url) {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') $path = '/';
        $path = untrailingslashit('/' . ltrim($path, '/'));
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('seo_analista_page_metric_maps')) {
    function seo_analista_page_metric_maps(array $data) {
        $current = array();
        $previous = array();
        foreach ((array) ($data['pages'] ?? array()) as $row) {
            $current[seo_analista_path_key($row['page_url'] ?? '')] = $row;
        }
        foreach ((array) ($data['previous_pages'] ?? array()) as $row) {
            $previous[seo_analista_path_key($row['page_url'] ?? '')] = $row;
        }
        return array($current, $previous);
    }
}

if (!function_exists('seo_analista_vocabulary_count_map')) {
    function seo_analista_vocabulary_count_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_object_vocabulary';
        if (!function_exists('seo_analista_table_exists') || !seo_analista_table_exists($table)) return array();
        $rows = (array) $wpdb->get_results(
            "SELECT object_type, object_id, COUNT(*) AS total\n             FROM {$table}\n             WHERE status=1\n             GROUP BY object_type, object_id",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $key = sanitize_key((string) ($row['object_type'] ?? '')) . ':' . absint($row['object_id'] ?? 0);
            if ($key !== ':0') $out[$key] = (int) ($row['total'] ?? 0);
        }
        return $out;
    }
}

if (!function_exists('seo_analista_category_content_map')) {
    function seo_analista_category_content_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_nodes';
        if (!function_exists('seo_analista_table_exists') || !seo_analista_table_exists($table)) return array();
        $rows = (array) $wpdb->get_results(
            "SELECT object_id, seo_role, keywords
             FROM {$table}
             WHERE object_type='category' AND status=1
               AND seo_role IN ('description','excerpt','category','ambito')
             ORDER BY object_id ASC, seo_role ASC, updated_at DESC, id DESC",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $id = absint($row['object_id'] ?? 0);
            $role = sanitize_key((string) ($row['seo_role'] ?? ''));
            if (!$id || $role === '') continue;
            if (!isset($out[$id])) $out[$id] = array('description' => '', 'excerpt' => '', 'category' => '', 'ambito' => '');
            if (isset($out[$id][$role]) && $out[$id][$role] === '') $out[$id][$role] = (string) ($row['keywords'] ?? '');
        }
        return $out;
    }
}

if (!function_exists('seo_analista_structural_role_map')) {
    function seo_analista_structural_role_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_nodes';
        if (!function_exists('seo_analista_table_exists') || !seo_analista_table_exists($table)) return array();
        $rows = (array) $wpdb->get_results(
            "SELECT object_id, seo_role FROM {$table}\n             WHERE status=1 AND object_type='page'\n               AND seo_role IN ('cluster','hub_primary','hub_secondary')",
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
}

if (!function_exists('seo_analista_structural_child_count_map')) {
    function seo_analista_structural_child_count_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_relations';
        if (!function_exists('seo_analista_table_exists') || !seo_analista_table_exists($table)) return array();
        $rows = (array) $wpdb->get_results(
            "SELECT source_type, source_id, COUNT(DISTINCT target_id) AS total
             FROM {$table}
             WHERE (source_type='cluster' AND target_type='hub_primary' AND relation_type='cluster_to_primary')
                OR (source_type='hub_primary' AND target_type='hub_secondary' AND relation_type='hub_primary_to_hub_secondary')
                OR (source_type='hub_secondary' AND target_type='product_cat' AND relation_type='hub_secondary_to_category')
             GROUP BY source_type, source_id",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $key = sanitize_key((string) ($row['source_type'] ?? '')) . ':' . absint($row['source_id'] ?? 0);
            if ($key !== ':0') $out[$key] = (int) ($row['total'] ?? 0);
        }
        return $out;
    }
}

if (!function_exists('seo_analista_functional_page_ids')) {
    function seo_analista_functional_page_ids() {
        $ids = array();
        if (function_exists('wc_get_page_id')) {
            foreach (array('cart', 'checkout', 'myaccount', 'shop', 'terms') as $key) {
                $id = (int) wc_get_page_id($key);
                if ($id > 0) $ids[$id] = true;
            }
        }
        $front = (int) get_option('page_on_front');
        $posts = (int) get_option('page_for_posts');
        if ($front > 0) $ids[$front] = true;
        if ($posts > 0) $ids[$posts] = true;
        return $ids;
    }
}

if (!function_exists('seo_analista_meaningful_tokens')) {
    function seo_analista_meaningful_tokens($text) {
        $stop = array_flip(array(
            'a','al','algo','como','con','de','del','el','en','es','esta','este','la','las','lo','los','mas','o','para','por','que','se','sin','su','sus','un','una','uno','unas','unos','y','e','tu','tus','mi','mis','ya','muy','mejor','mejores','guia','comprar','venta','precio','precios'
        ));
        $tokens = array();
        foreach (explode(' ', seo_analista_normalize_text($text)) as $token) {
            if ($token === '' || strlen($token) < 3 || isset($stop[$token])) continue;
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }
}

if (!function_exists('seo_analista_text_similarity')) {
    function seo_analista_text_similarity($left, $right) {
        $a = seo_analista_meaningful_tokens($left);
        $b = seo_analista_meaningful_tokens($right);
        if (!$a || !$b) return 0.0;
        $intersection = count(array_intersect($a, $b));
        if ($intersection < 1) return 0.0;
        $coverage = $intersection / max(1, min(count($a), count($b)));
        $jaccard = $intersection / max(1, count(array_unique(array_merge($a, $b))));
        return min(1.0, ($coverage * 0.7) + ($jaccard * 0.3));
    }
}

if (!function_exists('seo_analista_related_queries')) {
    function seo_analista_related_queries($subject, array $queries, $limit = 8) {
        $ranked = array();
        foreach ($queries as $row) {
            $query = seo_analista_clean_query($row['query_text'] ?? '');
            if ($query === '' || !seo_analista_query_is_actionable($query)) continue;
            $similarity = seo_analista_text_similarity($subject, $query);
            if ($similarity < 0.18) continue;
            $impressions = max(0.0, (float) ($row['impressions'] ?? 0));
            $score = ($similarity * 100) + min(30, log(1 + $impressions) * 5);
            $ranked[] = array('query' => $query, 'score' => $score, 'impressions' => $impressions);
        }
        usort($ranked, static function ($a, $b) {
            return (float) $b['score'] <=> (float) $a['score'];
        });
        $out = array();
        $seen = array();
        foreach ($ranked as $row) {
            $key = seo_analista_normalize_text($row['query']);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $row['query'];
            if (count($out) >= max(1, absint($limit))) break;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_gsc_demand_score')) {
    function seo_analista_gsc_demand_score(array $current, array $previous = array()) {
        $impressions = max(0.0, (float) ($current['impressions'] ?? 0));
        $position = max(0.0, (float) ($current['position'] ?? 0));
        $score = min(32, log(1 + $impressions) * 5.2);
        if ($position > 0 && $position <= 10) $score += 22;
        elseif ($position <= 20) $score += 18;
        elseif ($position <= 50) $score += 13;
        elseif ($position <= 80) $score += 7;
        elseif ($position <= 100) $score += 3;

        $prev_impressions = max(0.0, (float) ($previous['impressions'] ?? 0));
        if ($impressions > 0 && $prev_impressions <= 0) $score += 10;
        elseif ($prev_impressions > 0) {
            $growth = (($impressions - $prev_impressions) / $prev_impressions) * 100;
            if ($growth >= 100) $score += 14;
            elseif ($growth >= 40) $score += 10;
            elseif ($growth >= 15) $score += 5;
            elseif ($growth <= -40) $score -= 4;
        }
        return max(0, min(60, (int) round($score)));
    }
}

if (!function_exists('seo_analista_content_issue_profile')) {
    function seo_analista_content_issue_profile($type, array $source, array $seo_meta, $vocabulary_count, $internal_links, $taxonomy_count = 0) {
        $title = trim((string) ($source['title'] ?? ''));
        $body = (string) ($source['content'] ?? '');
        $excerpt = (string) ($source['excerpt'] ?? '');
        $words = seo_analista_word_count($body);
        $excerpt_words = seo_analista_word_count($excerpt);
        $issues = array();
        $changes = array();
        $issue_score = 0;

        $body_min = 300;
        if ($type === 'post') $body_min = 500;
        elseif ($type === 'product') $body_min = 140;
        elseif ($type === 'category') $body_min = 120;
        elseif ($type === 'cluster') $body_min = 650;
        elseif ($type === 'hub_primary') $body_min = 500;
        elseif ($type === 'hub_secondary') $body_min = 400;

        if (seo_analista_word_count($title) < 2 || strlen($title) < 14) {
            $issues[] = 'Título poco descriptivo.';
            $changes[] = 'Reescribir el título para expresar con claridad la intención principal y el ámbito de la página.';
            $issue_score += 10;
        } elseif (strlen($title) > 95 && $type !== 'product') {
            $issues[] = 'Título demasiado largo para una lectura clara.';
            $changes[] = 'Acortar el título manteniendo el término principal y la promesa de la página.';
            $issue_score += 5;
        }

        if ($words < $body_min) {
            $issues[] = 'Cobertura textual baja: ' . number_format_i18n($words) . ' palabras.';
            $changes[] = 'Ampliar la literatura con uso, criterios de elección, variantes, limitaciones y preguntas reales del usuario.';
            $issue_score += $words < max(40, (int) ($body_min * 0.35)) ? 22 : 14;
        }

        if ($type === 'product' && $excerpt_words < 25) {
            $issues[] = 'Descripción corta insuficiente.';
            $changes[] = 'Mejorar la descripción corta con tipo de producto, capacidad/prestación clave, uso y diferenciador principal.';
            $issue_score += 10;
        }

        if ($type === 'category' && $excerpt_words < 18) {
            $issues[] = 'Excerpt de categoría insuficiente.';
            $changes[] = 'Redactar un excerpt breve que defina la familia, su uso y el criterio principal de elección.';
            $issue_score += 8;
        }

        if ($type !== 'category' && trim((string) ($seo_meta['title'] ?? '')) === '') {
            $issues[] = 'SEO title no localizado.';
            $changes[] = 'Revisar el SEO title y alinearlo con la intención principal sin duplicar literalmente el H1.';
            $issue_score += 5;
        }
        if ($type !== 'category' && trim((string) ($seo_meta['description'] ?? '')) === '') {
            $issues[] = 'Meta description no localizada.';
            $changes[] = 'Redactar una meta description específica que explique utilidad, alcance y motivo para entrar.';
            $issue_score += 5;
        }

        if (in_array($type, array('post','page','product','cluster','hub_primary','hub_secondary','category'), true) && $vocabulary_count < 1) {
            $issues[] = 'Sin Vocabulary asociado.';
            $changes[] = 'Asignar Vocabulary principal y variantes para fijar el campo semántico que debe cubrir la entidad.';
            $issue_score += 9;
        }

        if (in_array($type, array('post','page','cluster','hub_primary','hub_secondary'), true) && $internal_links < 2) {
            $issues[] = 'Enlazado interno escaso.';
            $changes[] = 'Añadir enlaces contextuales hacia categorías, hubs o productos directamente relacionados.';
            $issue_score += 8;
        }

        if ($type === 'product' && $taxonomy_count < 1) {
            $issues[] = 'Producto sin categoría útil detectada.';
            $changes[] = 'Revisar su clasificación antes de ampliar la literatura para que el contenido refuerce la familia correcta.';
            $issue_score += 16;
        }

        $tag_count = (int) ($source['tag_count'] ?? 0);
        if ($type === 'post' && $tag_count < 1) {
            $issues[] = 'Entrada sin etiquetas editoriales.';
            $changes[] = 'Asignar solo etiquetas útiles y coherentes con el Vocabulary; evitar etiquetas aisladas o casi duplicadas.';
            $issue_score += 4;
        }
        if ($type === 'category' && $tag_count < 1) {
            $issues[] = 'Categoría sin etiquetas SEO.';
            $changes[] = 'Definir etiquetas SEO coherentes con Vocabulary, variantes de búsqueda y alcance real de la categoría.';
            $issue_score += 7;
        }

        $child_count = isset($source['child_count']) ? (int) $source['child_count'] : null;
        if (in_array($type, array('cluster','hub_primary','hub_secondary'), true) && null !== $child_count && $child_count < 1) {
            $issues[] = 'Nodo estructural sin descendencia conectada.';
            $changes[] = 'Revisar sus relaciones estructurales: un cluster/hub sin hijos no distribuye autoridad ni organiza el catálogo.';
            $issue_score += 18;
        }

        return array(
            'word_count' => $words,
            'excerpt_word_count' => $excerpt_words,
            'issues' => array_values(array_unique($issues)),
            'recommended_changes' => array_values(array_unique($changes)),
            'issue_score' => min(60, $issue_score),
        );
    }
}

if (!function_exists('seo_analista_entity_action')) {
    function seo_analista_entity_action($type, $has_issues) {
        $map = array(
            'post' => $has_issues ? 'MEJORAR_POST' : 'IMPULSAR_POST',
            'page' => $has_issues ? 'MEJORAR_PAGINA' : 'IMPULSAR_PAGINA',
            'product' => $has_issues ? 'MEJORAR_PRODUCTO' : 'IMPULSAR_PRODUCTO',
            'category' => $has_issues ? 'MEJORAR_CATEGORIA' : 'IMPULSAR_CATEGORIA',
            'cluster' => $has_issues ? 'MEJORAR_CLUSTER' : 'IMPULSAR_CLUSTER',
            'hub_primary' => $has_issues ? 'MEJORAR_HUB' : 'IMPULSAR_HUB',
            'hub_secondary' => $has_issues ? 'MEJORAR_HUB' : 'IMPULSAR_HUB',
        );
        return $map[$type] ?? ($has_issues ? 'MEJORAR_PAGINA' : 'IMPULSAR_PAGINA');
    }
}

if (!function_exists('seo_analista_entity_label')) {
    function seo_analista_entity_label($type) {
        $labels = array(
            'post' => 'Post', 'page' => 'Página', 'product' => 'Producto', 'category' => 'Categoría',
            'cluster' => 'Cluster', 'hub_primary' => 'Hub primario', 'hub_secondary' => 'Hub secundario',
        );
        return $labels[$type] ?? ucfirst((string) $type);
    }
}

if (!function_exists('seo_analista_build_entity_work_row')) {
    function seo_analista_build_entity_work_row($type, $id, $title, $url, array $source, array $gsc, array $previous_gsc, $vocabulary_count, $taxonomy_count = 0, $edit_url = '') {
        $seo_meta = isset($source['seo_meta']) ? (array) $source['seo_meta'] : array('title' => '', 'description' => '');
        $internal_links = seo_analista_internal_link_count($source['content'] ?? '');
        $profile = seo_analista_content_issue_profile($type, $source, $seo_meta, $vocabulary_count, $internal_links, $taxonomy_count);
        $demand_score = seo_analista_gsc_demand_score($gsc, $previous_gsc);
        $has_issues = !empty($profile['issues']);
        $action = seo_analista_entity_action($type, $has_issues);

        $impressions = (float) ($gsc['impressions'] ?? 0);
        $position = (float) ($gsc['position'] ?? 0);
        $previous_impressions = (float) ($previous_gsc['impressions'] ?? 0);
        $growth = null;
        if ($previous_impressions > 0) $growth = (($impressions - $previous_impressions) / $previous_impressions) * 100;
        elseif ($impressions > 0) $growth = 100.0;

        $ctr = (float) ($gsc['ctr'] ?? 0);
        if ($impressions >= 20 && $position > 0 && $position <= 20 && $ctr < 0.01) {
            $profile['issues'][] = 'CTR bajo para una URL ya visible en posiciones aprovechables.';
            $profile['recommended_changes'][] = 'Revisar SEO title y meta description para responder mejor a las consultas que ya generan impresiones.';
            $profile['issue_score'] = min(60, (int) $profile['issue_score'] + 8);
            $has_issues = true;
            $action = seo_analista_entity_action($type, true);
        }

        $priority = 25 + (int) $profile['issue_score'] + $demand_score;
        if (!$has_issues && $impressions < 5) $priority -= 20;
        if ($growth !== null && $growth >= 40) $priority += 7;
        if ($position > 0 && $position <= 20) $priority += 5;
        $priority = max(0, min(100, $priority));

        $reason_parts = array();
        if ($has_issues) $reason_parts[] = 'La entidad tiene problemas editoriales concretos que se pueden corregir.';
        if ($impressions > 0) $reason_parts[] = 'Google ya la muestra: ' . number_format_i18n($impressions, 0) . ' impresiones, posición ' . number_format_i18n($position, 1) . '.';
        if ($growth !== null && $growth >= 40) $reason_parts[] = 'La demanda asociada está acelerando respecto al periodo anterior.';
        if (!$has_issues && $impressions > 0) $reason_parts[] = 'La base editorial es razonable: conviene impulsarla, no rehacerla.';

        $meta = function_exists('seo_analista_action_meta') ? seo_analista_action_meta($action) : array('label' => $action, 'channel' => 'contenido');
        return array(
            'priority' => $priority,
            'action' => $action,
            'action_label' => (string) ($meta['label'] ?? $action),
            'channel' => (string) ($meta['channel'] ?? 'contenido'),
            'topic' => (string) $title,
            'reason' => implode(' ', $reason_parts),
            'sources' => array_values(array_filter(array('WordPress', $impressions > 0 ? 'Search Console' : '', $vocabulary_count > 0 ? 'Vocabulary' : ''))),
            'source' => '',
            'entity' => array(
                'type' => $type,
                'type_label' => seo_analista_entity_label($type),
                'id' => absint($id),
                'title' => (string) $title,
                'url' => (string) $url,
                'edit_url' => (string) $edit_url,
            ),
            'target' => array('title' => (string) $title, 'url' => (string) $url),
            'issues' => (array) $profile['issues'],
            'recommended_changes' => (array) $profile['recommended_changes'],
            'content' => array(
                'word_count' => (int) $profile['word_count'],
                'excerpt_word_count' => (int) $profile['excerpt_word_count'],
                'internal_links' => (int) $internal_links,
                'vocabulary_count' => (int) $vocabulary_count,
                'taxonomy_count' => (int) $taxonomy_count,
                'tag_count' => (int) ($source['tag_count'] ?? 0),
                'child_count' => isset($source['child_count']) ? (int) $source['child_count'] : null,
            ),
            'metrics' => array(
                'impressions' => $impressions,
                'clicks' => (float) ($gsc['clicks'] ?? 0),
                'ctr' => $ctr,
                'position' => $position,
                'queries' => (int) ($gsc['queries'] ?? 0),
                'impressions_growth_pct' => $growth,
                'search_score' => $demand_score,
                'market_score' => 0,
            ),
            'evidence' => array(),
            'keywords' => array(),
            'catalog' => array(),
        );
    }
}

if (!function_exists('seo_analista_enrich_keyword_directive')) {
    function seo_analista_enrich_keyword_directive(array &$row) {
        $keywords = array_values(array_filter((array) ($row['keywords'] ?? array())));
        if (!$keywords) return;
        $sample = array_slice($keywords, 0, 4);
        $row['recommended_changes'][] = 'Revisar la cobertura explícita de estas búsquedas reales: ' . implode(' · ', $sample) . '.';
        $row['recommended_changes'] = array_values(array_unique($row['recommended_changes']));
    }
}

if (!function_exists('seo_analista_content_work')) {
    function seo_analista_content_work($days = 28, $limit = 120) {
        static $cache = array();
        $days = seo_analista_days($days);
        $requested_limit = max(20, min(250, absint($limit)));
        if (isset($cache[$days])) return array_slice($cache[$days], 0, $requested_limit);

        global $wpdb;
        $data = seo_analista_get_data($days);
        list($gsc_map, $previous_map) = seo_analista_page_metric_maps($data);
        $vocab_map = seo_analista_vocabulary_count_map();
        $category_content_map = seo_analista_category_content_map();
        $role_map = seo_analista_structural_role_map();
        $child_map = seo_analista_structural_child_count_map();
        $functional_ids = seo_analista_functional_page_ids();
        $rows = array();

        // Posts y páginas: se analizan completos porque el volumen es manejable.
        $editorial = (array) $wpdb->get_results(
            "SELECT ID, post_type, post_title, post_content, post_excerpt, post_modified\n             FROM {$wpdb->posts}\n             WHERE post_status='publish' AND post_type IN ('post','page')",
            ARRAY_A
        );
        foreach ($editorial as $post) {
            $id = absint($post['ID'] ?? 0);
            if (!$id || isset($functional_ids[$id])) continue;
            $post_type = (string) ($post['post_type'] ?? 'page');
            $type = isset($role_map[$id]) ? $role_map[$id] : $post_type;
            $url = (string) get_permalink($id);
            if ($url === '') continue;
            $path = seo_analista_path_key($url);
            $gsc = (array) ($gsc_map[$path] ?? array());
            $previous = (array) ($previous_map[$path] ?? array());
            $vocab_key = $post_type . ':' . $id;
            $tags = $post_type === 'post' ? wp_get_post_tags($id, array('fields' => 'ids')) : array();
            $source = array(
                'title' => (string) ($post['post_title'] ?? ''),
                'content' => (string) ($post['post_content'] ?? ''),
                'excerpt' => (string) ($post['post_excerpt'] ?? ''),
                'seo_meta' => seo_analista_seo_meta_snapshot($id),
                'tag_count' => is_wp_error($tags) ? 0 : count((array) $tags),
                'child_count' => isset($role_map[$id]) ? (int) ($child_map[$role_map[$id] . ':' . $id] ?? 0) : null,
            );
            $row = seo_analista_build_entity_work_row(
                $type, $id, $source['title'], $url, $source, $gsc, $previous,
                (int) ($vocab_map[$vocab_key] ?? 0), 0, (string) get_edit_post_link($id, '')
            );
            $subject = $row['topic'];
            $row['keywords'] = seo_analista_related_queries($subject, (array) ($data['queries'] ?? array()), 8);
            $row['evidence'] = $row['keywords'];
            seo_analista_enrich_keyword_directive($row);
            $row['source'] = implode(' + ', $row['sources']);
            if ($row['priority'] >= 45) $rows[] = $row;
        }

        // Productos: priorizamos los que Search Console ya ha mostrado. Asi la
        // lista de trabajo se mantiene accionable incluso con catalogos grandes.
        $product_slugs = array();
        foreach ((array) ($data['pages'] ?? array()) as $gsc_page) {
            $path = seo_analista_path_key($gsc_page['page_url'] ?? '');
            if (preg_match('#^/producto/([^/]+)$#', $path, $m)) $product_slugs[$m[1]] = true;
        }
        if ($product_slugs) {
            $slugs = array_keys($product_slugs);
            foreach (array_chunk($slugs, 300) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $sql = $wpdb->prepare(
                    "SELECT ID, post_title, post_name, post_content, post_excerpt, post_modified\n                     FROM {$wpdb->posts}\n                     WHERE post_status='publish' AND post_type='product' AND post_name IN ({$placeholders})",
                    $chunk
                );
                $products = (array) $wpdb->get_results($sql, ARRAY_A);
                foreach ($products as $post) {
                    $id = absint($post['ID'] ?? 0);
                    $url = (string) get_permalink($id);
                    if (!$id || $url === '') continue;
                    $path = seo_analista_path_key($url);
                    $gsc = (array) ($gsc_map[$path] ?? array());
                    $previous = (array) ($previous_map[$path] ?? array());
                    $cats = wp_get_post_terms($id, 'product_cat', array('fields' => 'ids'));
                    $cat_count = is_wp_error($cats) ? 0 : count((array) $cats);
                    $source = array(
                        'title' => (string) ($post['post_title'] ?? ''),
                        'content' => (string) ($post['post_content'] ?? ''),
                        'excerpt' => (string) ($post['post_excerpt'] ?? ''),
                        'seo_meta' => seo_analista_seo_meta_snapshot($id),
                    );
                    $row = seo_analista_build_entity_work_row(
                        'product', $id, $source['title'], $url, $source, $gsc, $previous,
                        (int) ($vocab_map['product:' . $id] ?? 0), $cat_count, (string) get_edit_post_link($id, '')
                    );
                    $row['keywords'] = seo_analista_related_queries($row['topic'], (array) ($data['queries'] ?? array()), 6);
                    $row['evidence'] = $row['keywords'];
                    seo_analista_enrich_keyword_directive($row);
                    $row['source'] = implode(' + ', $row['sources']);
                    if ($row['priority'] >= 45) $rows[] = $row;
                }
            }
        }

        // Categorías: combinamos literatura, Vocabulary, profundidad de surtido
        // y rendimiento orgánico de la URL de archivo.
        if (taxonomy_exists('product_cat')) {
            $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!is_wp_error($terms)) {
                foreach ((array) $terms as $term) {
                    $term_id = absint($term->term_id ?? 0);
                    if (!$term_id) continue;
                    $url = get_term_link($term, 'product_cat');
                    if (is_wp_error($url)) $url = '';
                    $path = seo_analista_path_key($url);
                    $gsc = (array) ($gsc_map[$path] ?? array());
                    $previous = (array) ($previous_map[$path] ?? array());
                    $stored = (array) ($category_content_map[$term_id] ?? array());
                    $category_tags = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/u', (string) ($stored['category'] ?? '')))));
                    $source = array(
                        'title' => (string) ($term->name ?? ''),
                        'content' => (string) (($stored['description'] ?? '') !== '' ? $stored['description'] : ($term->description ?? '')),
                        'excerpt' => (string) ($stored['excerpt'] ?? ''),
                        'seo_meta' => array('title' => '', 'description' => ''),
                        'tag_count' => count($category_tags),
                        'scope' => (string) ($stored['ambito'] ?? ''),
                    );
                    $row = seo_analista_build_entity_work_row(
                        'category', $term_id, $source['title'], (string) $url, $source, $gsc, $previous,
                        (int) ($vocab_map['product_cat:' . $term_id] ?? 0), (int) ($term->count ?? 0),
                        (string) get_edit_term_link($term_id, 'product_cat')
                    );
                    $row['catalog'] = array(
                        'category' => $source['title'],
                        'products' => (int) ($term->count ?? 0),
                        'term_id' => $term_id,
                    );
                    $row['keywords'] = seo_analista_related_queries($row['topic'], (array) ($data['queries'] ?? array()), 8);
                    $row['evidence'] = $row['keywords'];
                    seo_analista_enrich_keyword_directive($row);
                    $row['source'] = implode(' + ', $row['sources']);
                    if ($row['priority'] >= 45) $rows[] = $row;
                }
            }
        }

        usort($rows, static function ($a, $b) {
            $diff = (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
            if ($diff) return $diff;
            return (float) ($b['metrics']['impressions'] ?? 0) <=> (float) ($a['metrics']['impressions'] ?? 0);
        });

        $cache[$days] = $rows;
        return array_slice($cache[$days], 0, $requested_limit);
    }
}

if (!function_exists('seo_analista_literature_work')) {
    function seo_analista_literature_work($days = 28, $limit = 80) {
        $rows = array();
        foreach (seo_analista_content_work($days, 220) as $row) {
            $type = (string) ($row['entity']['type'] ?? '');
            if (in_array($type, array('post','page','product'), true)) $rows[] = $row;
        }
        return array_slice($rows, 0, max(10, min(150, absint($limit))));
    }
}

if (!function_exists('seo_analista_structure_work')) {
    function seo_analista_structure_work($days = 28, $limit = 80) {
        $rows = array();
        foreach (seo_analista_content_work($days, 220) as $row) {
            $type = (string) ($row['entity']['type'] ?? '');
            if (in_array($type, array('category','cluster','hub_primary','hub_secondary'), true)) $rows[] = $row;
        }
        return array_slice($rows, 0, max(10, min(150, absint($limit))));
    }
}

if (!function_exists('seo_analista_find_best_local_target')) {
    function seo_analista_find_best_local_target($topic, $days = 28) {
        $best = array();
        $best_score = 0.0;
        foreach (seo_analista_content_work($days, 220) as $row) {
            $entity = (array) ($row['entity'] ?? array());
            $score = seo_analista_text_similarity($topic, $entity['title'] ?? '');
            foreach ((array) ($row['keywords'] ?? array()) as $keyword) {
                $score = max($score, seo_analista_text_similarity($topic, $keyword) * 0.92);
            }
            if ($score > $best_score) {
                $best_score = $score;
                $best = $row;
            }
        }
        if ($best_score < 0.32) return array();
        return array('score' => $best_score, 'row' => $best);
    }
}
