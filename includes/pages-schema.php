<?php
if (!defined('ABSPATH')) exit;

function search_pages_schema() {

    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $tabla_relations = $wpdb->prefix . 'seo_relations';

    echo '<div class="wrap">';
    echo '<h1>Jerarquía de páginas SEO</h1>';
    echo '<p>Clusters → Hubs Primarios → Hubs Secundarios</p>';

    $rows = $wpdb->get_results("
         SELECT DISTINCT
            cl.ID            AS cluster_id,
            cl.post_title    AS cluster,
            cl.post_excerpt  AS cluster_excerpt,
        
            hp.ID            AS hub_primary_id,
            hp.post_title    AS hub_primary,
            hp.post_excerpt  AS hub_primary_excerpt,
        
            hs.ID            AS hub_secondary_id,
            hs.post_title    AS hub_secondary,
            hs.post_excerpt  AS hub_secondary_excerpt,
        
            p.ID,
            p.post_title,
            p.post_excerpt,
            p.post_status

        FROM {$tabla_relations} r1

        INNER JOIN {$wpdb->posts} hs
            ON hs.ID = r1.source_id

        INNER JOIN {$tabla_relations} r2
            ON r2.target_id = hs.ID
            AND r2.relation_type IN
            (
                'hub_primary_to_hub_secondary',
                'hub_primary_to_secondary'
            )

        INNER JOIN {$wpdb->posts} hp
            ON hp.ID = r2.source_id

        INNER JOIN {$tabla_relations} r3
            ON r3.target_id = hp.ID
            AND r3.relation_type IN
            (
                'cluster_to_primary',
                'cluster_to_hub_primary'
            )

        INNER JOIN {$wpdb->posts} cl
            ON cl.ID = r3.source_id

        INNER JOIN {$wpdb->posts} p
            ON p.ID = hs.ID

        ORDER BY
            cl.post_title,
            hp.post_title,
            hs.post_title
    ");

    if (!$rows) {

        echo '<p>No hay datos.</p>';
        echo '</div>';
        return;
    }

    $cluster_actual = '';
    $hub_actual = '';

    // =====================================================
    // RAÍZ GPT ACTUAL DEL CLUSTER
    // Se usa para limitar hubs primarios y secundarios.
     // =====================================================
    
    $cluster_gpt_root_actual = '';

    foreach ($rows as $row) {

        if ($cluster_actual != $row->cluster) {

            echo '<div style="
                margin-top:30px;
                background:#1d2327;
                color:#fff;
                padding:15px;
                border-radius:8px;
                font-size:22px;
                font-weight:bold;
            ">';

            echo '📁 '.$row->cluster;
            echo '</div>';

            $cluster_actual = $row->cluster;
            $hub_actual = '';
            
            
            
                // =====================================================
                // ANÁLISIS GPT DEL CLUSTER
                // =====================================================
                
                    // =====================================================
                    // TEXTO COMPLETO DEL CLUSTER PARA GPT
                    // =====================================================
                    
                    $cluster_text = seo_gpt_page_semantic_text(
                        intval($row->cluster_id)
                    );
                $cluster_match = seo_gpt_find_best_match_by_level(
                    $cluster_text,
                    'cluster'
                );
                
                // =====================================================
                // RAÍZ GPT DEL CLUSTER COMO FILTRO
                // Solo se usa filtro duro en raíces realmente fiables.
                // =====================================================
                
                $cluster_gpt_root_detectada = seo_gpt_root_from_path(
                    $cluster_match['path']
                );
                
                $cluster_score = intval(
                    $cluster_match['score']
                );
                
                $roots_gpt_fiables = [
                    'Vehículos y recambios',
                    'Bricolaje'
                ];
                
                if (
                    $cluster_score >= 10
                    &&
                    in_array(
                        $cluster_gpt_root_detectada,
                        $roots_gpt_fiables,
                        true
                    )
                ) {
                    $cluster_gpt_root_actual = $cluster_gpt_root_detectada;
                } else {
                    $cluster_gpt_root_actual = '';
                }
                seo_gpt_render_match_box(
                    'Comparativa GPT del CLUSTER',
                    $cluster_match
                );
                

        }

        if ($hub_actual != $row->hub_primary) {

            echo '<div style="
                margin:15px 0 0 25px;
                padding:10px;
                background:#e7f1ff;
                border-left:5px solid #2271b1;
                font-size:18px;
                font-weight:bold;
            ">';

            echo '📂 '.$row->hub_primary;
            echo '</div>';

            $hub_actual = $row->hub_primary;
                // =====================================================
                // ANÁLISIS GPT DEL HUB PRIMARIO
                // =====================================================
                
                    // =====================================================
                    // TEXTO COMPLETO DEL HUB PRIMARIO PARA GPT
                    // Incluye también el cluster como contexto superior.
                     // =====================================================
                    
                    $hub_primary_text =
                        seo_gpt_page_semantic_text(
                            intval($row->hub_primary_id)
                        )
                        . ' ' .
                        seo_gpt_page_semantic_text(
                            intval($row->cluster_id)
                        );
                
                $hub_primary_match = seo_gpt_find_best_match_by_level(
                    $hub_primary_text,
                    'hub_primary',
                    $cluster_gpt_root_actual
                );
                
                seo_gpt_render_match_box(
                    'Comparativa GPT del HUB PRIMARIO',
                    $hub_primary_match
                );
        }

        echo '<div style="
            margin:10px 0 15px 55px;
            background:#fff;
            border:1px solid #ddd;
            border-radius:8px;
            padding:15px;
        ">';

        echo '<h3 style="margin:0;">';
        echo '📄 '.$row->hub_secondary;
        echo '</h3>';

        echo '<p>';
        echo '<strong>ID:</strong> '.$row->hub_secondary_id.'<br>';

        echo '<strong>Estado:</strong> '.$row->post_status.'<br>';

        echo '<strong>Extracto:</strong><br>';

        if (!empty($row->post_excerpt))
            echo esc_html($row->post_excerpt);
        else
            echo '<em>Sin extracto</em>';

        echo '</p>';
        
        
            // =====================================================
            // COMPARATIVA CON GOOGLE PRODUCT TAXONOMY ES-ES
            // =====================================================
            
                // =====================================================
                // TEXTO COMPLETO DEL HUB SECUNDARIO PARA GPT
                // Da más peso al hub secundario y añade contexto superior.
                 // =====================================================
                
                $hub_secondary_text = seo_gpt_page_semantic_text(
                    intval($row->hub_secondary_id)
                );
                
                $gpt_text =
                    $hub_secondary_text . ' ' .
                    $hub_secondary_text . ' ' .
                    $hub_secondary_text . ' ' .
                    seo_gpt_page_semantic_text(
                        intval($row->hub_primary_id)
                    ) . ' ' .
                    seo_gpt_page_semantic_text(
                        intval($row->cluster_id)
                    ) . ' ' .
                    seo_gpt_hub_products_text(
                        intval($row->hub_secondary_id)
                    );
            
                $gpt_match = seo_gpt_find_best_match_by_level(
                    $gpt_text,
                    'hub_secondary',
                    $cluster_gpt_root_actual
                );
            
            echo '<div style="
                margin-top:15px;
                padding:15px;
                background:#f8fbff;
                border:1px solid #72aee6;
                border-radius:8px;
            ">';
            
            echo '<h4 style="margin:0 0 10px 0;">Google Product Taxonomy ES-ES - HUB SECUNDARIO</h4>';
            
            echo '<p style="margin:0 0 8px 0;">';
            
            echo '<strong>Ruta más parecida:</strong><br>';
            echo esc_html($gpt_match['path']);
            
            echo '<br><br>';
            
            echo '<strong>ID Google:</strong> ';
            
            if (!empty($gpt_match['id'])) {
                echo esc_html($gpt_match['id']);
            } else {
                echo '<em>Sin ID</em>';
            }
            
            echo '<br>';
            
            // =====================================================
            // COLOR VISUAL SEGÚN SIMILITUD GPT
            // =====================================================
            
            $score_gpt = intval($gpt_match['score']);
            
            if ($score_gpt >= 75) {
            
                $score_color = '#2e7d32';
                $score_bg    = '#e8f5e9';
                $score_label = 'ALTA';
            
            } elseif ($score_gpt >= 50) {
            
                $score_color = '#ef6c00';
                $score_bg    = '#fff3e0';
                $score_label = 'MEDIA';
            
            } else {
            
                $score_color = '#c62828';
                $score_bg    = '#ffebee';
                $score_label = 'BAJA';
            }
            
            echo '<strong>Similitud:</strong> ';
            
            echo '<span style="
                display:inline-block;
                padding:4px 10px;
                border-radius:6px;
                background:' . esc_attr($score_bg) . ';
                color:' . esc_attr($score_color) . ';
                font-weight:bold;
            ">';
            
            echo $score_gpt . '% - ' . esc_html($score_label);
            
            echo '</span>';
            
            echo '<br>';
            
            echo '<strong>Diagnóstico:</strong> ';
            
            echo '<span style="
                color:' . esc_attr($score_color) . ';
                font-weight:bold;
            ">';
            
            echo esc_html(
                seo_gpt_diagnosis(
                    $score_gpt
                )
            );
            
            echo '</span>';
            
            echo '</p>';
            
            if (!empty($gpt_match['matches'])) {
            
                echo '<div style="margin-top:10px;">';
                echo '<strong>Coincidencias:</strong><br>';
            
                foreach ($gpt_match['matches'] as $token) {
            
                    echo '<span style="
                        display:inline-block;
                        margin:3px;
                        padding:3px 8px;
                        background:#eef5ff;
                        border-radius:4px;
                        font-size:12px;
                    ">';
            
                    echo esc_html($token);
            
                    echo '</span>';
                }
            
                echo '</div>';
            }
            
            echo '</div>';
        

        echo '</div>';
    }

    echo '</div>';
}

/*************************************************
 * NORMALIZAR TEXTO PARA GOOGLE PRODUCT TAXONOMY
 * Usa wp_seo_dictionari para descartar tokens con puntuacion 0.
 *************************************************/
if (!function_exists('seo_gpt_tokens')) {

function seo_gpt_tokens($text) {

    $text = strtolower(
        remove_accents(
            wp_strip_all_tags((string) $text)
        )
    );

    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    $words = explode(' ', trim($text));

    $tokens = [];

    foreach ($words as $word) {

        $word = trim($word);

        if ($word === '') {
            continue;
        }

        if (strlen($word) < 4) {
            continue;
        }

        if (strlen($word) > 4 && substr($word, -1) === 's') {
            $word = substr($word, 0, -1);
        }

        if (
            function_exists('seo_gpt_token_is_discarded')
            &&
            seo_gpt_token_is_discarded($word)
        ) {
            continue;
        }

        $tokens[] = $word;
    }

    return array_values(array_unique($tokens));
}

}
 
/*************************************************
 * OBTENER PESO DE UN TOKEN SEGÚN DICCIONARIO SEO
 * Si no existe en diccionario, usa peso neutro 1.
 *************************************************/
if (!function_exists('seo_gpt_token_score')) {

function seo_gpt_token_score($token) {

    $scores = seo_gpt_dictionary_scores();

    $token = strtolower(remove_accents(trim((string) $token)));

    if ($token === '') {
        return 0;
    }

    if (array_key_exists($token, $scores)) {
        return intval($scores[$token]);
    }

    return 1;
}

}


/*************************************************
 * CARGAR GOOGLE PRODUCT TAXONOMY ES-ES
 * Descarga la taxonomía oficial en castellano y la guarda en caché.
 *************************************************/
if (!function_exists('seo_gpt_get_taxonomy_es')) {

function seo_gpt_get_taxonomy_es() {

    $cached = get_transient('seo_gpt_taxonomy_es_es');

    if ($cached !== false) {
        return $cached;
    }

    $base = 'https' . '://www.google.com/basepages/producttype/';
    $file = 'taxonomy-with-ids.es-ES.txt';
    $url  = $base . $file;

    $response = wp_remote_get($url, [
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);

    if (empty($body)) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $body);

    $taxonomy = [];

    foreach ($lines as $line) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (strpos($line, '#') === 0) {
            continue;
        }

        $id = '';
        $path = $line;

        if (preg_match('/^([0-9]+)\s*-\s*(.+)$/u', $line, $m)) {
            $id = trim($m[1]);
            $path = trim($m[2]);
        }

        $taxonomy[] = [
            'id'     => $id,
            'path'   => $path,
            'tokens' => seo_gpt_tokens($path)
        ];
    }

    set_transient(
        'seo_gpt_taxonomy_es_es',
        $taxonomy,
        12 * HOUR_IN_SECONDS
    );

    return $taxonomy;
}

}


/*************************************************
 * PRODUCTOS DESCENDIENTES DE UN HUB SECUNDARIO
 * Añade señales reales de productos vinculados a categorías hijas.
 *************************************************/
if (!function_exists('seo_gpt_hub_products_text')) {

function seo_gpt_hub_products_text($hub_secondary_id) {

    global $wpdb;

    $tabla_relations = $wpdb->prefix . 'seo_relations';

    $titles = $wpdb->get_col(
        $wpdb->prepare("
            SELECT DISTINCT p.post_title
            FROM {$tabla_relations} r
            INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = r.target_id
                AND tt.taxonomy = 'product_cat'
            INNER JOIN {$wpdb->term_relationships} tr
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p
                ON p.ID = tr.object_id
                AND p.post_type = 'product'
                AND p.post_status IN ('publish','draft','private')
            WHERE r.source_id = %d
            AND r.relation_type = 'hub_secondary_to_category'
            LIMIT 200
        ", $hub_secondary_id)
    );

    if (empty($titles)) {
        return '';
    }

    return implode(' ', $titles);
}

}



/*************************************************
 * DIAGNÓSTICO DE ALINEACIÓN CON GOOGLE PRODUCT TAXONOMY
 *************************************************/
if (!function_exists('seo_gpt_diagnosis')) {

function seo_gpt_diagnosis($score) {

    if ($score >= 75) {
        return 'Bien alineado con Google Product Taxonomy.';
    }

    if ($score >= 50) {
        return 'Alineación parcial. Revisar si el hub necesita más contexto o una categoría más específica.';
    }

    if ($score >= 30) {
        return 'Coincidencia débil. Puede haber mezcla de familias o una ubicación demasiado amplia.';
    }

    return 'Sin alineación clara. Revisar nombre, descripción, productos vinculados o posible reubicación.';
}

}


/*************************************************
 * DIAGNÓSTICO GPT SEGÚN NIVEL JERÁRQUICO
 * Cluster, hub primario y hub secundario usan criterios distintos.
 *************************************************/
if (!function_exists('seo_gpt_diagnosis_by_level')) {

function seo_gpt_diagnosis_by_level($score, $level = 'hub_secondary', $found = false) {

    $score = intval($score);

    if ($level === 'cluster') {

        if ($found && $score >= 10) {
            return 'Raíz GPT detectada. En clusters el porcentaje es orientativo porque la raíz GPT contiene pocos términos.';
        }

        return 'No se detecta una raíz GPT clara. Revisar nombre, descripción o etiquetas del cluster.';
    }

    if ($level === 'hub_primary') {

        if ($score >= 55) {
            return 'Hub primario alineado con una familia GPT razonable dentro del cluster.';
        }

        if ($score >= 35) {
            return 'Hub primario con alineación parcial. Revisar si agrupa varias familias o necesita más contexto.';
        }

        return 'Hub primario con baja alineación. Revisar ubicación o contenido semántico.';
    }

    if ($score >= 75) {
        return 'Hub secundario bien alineado con Google Product Taxonomy.';
    }

    if ($score >= 50) {
        return 'Hub secundario con alineación parcial. Puede contener varias familias GPT.';
    }

    if ($score >= 30) {
        return 'Coincidencia débil. Revisar si el hub está mezclando familias.';
    }

    return 'Sin alineación clara. Revisar nombre, descripción, productos vinculados o posible reubicación.';
}

}

/*************************************************
 * BUSCAR MEJOR COINCIDENCIA GPT SEGÚN NIVEL JERÁRQUICO
 * Usa pesos de tokens para evitar que una palabra aislada domine el análisis.
 *************************************************/
if (!function_exists('seo_gpt_find_best_match_by_level')) {

function seo_gpt_find_best_match_by_level($text, $level = 'hub_secondary', $preferred_root = '') {

    $taxonomy = seo_gpt_get_taxonomy_es();

    if (empty($taxonomy)) {
        return [
            'found'   => false,
            'id'      => '',
            'path'    => 'No se pudo cargar Google Product Taxonomy ES-ES',
            'score'   => 0,
            'matches' => [],
            'level'   => $level
        ];
    }

    $page_counts = seo_gpt_token_counts($text);
    $page_tokens = array_keys($page_counts);

    if (empty($page_tokens)) {
        return [
            'found'   => false,
            'id'      => '',
            'path'    => 'Sin texto suficiente para comparar',
            'score'   => 0,
            'matches' => [],
            'level'   => $level
        ];
    }

    $total_page_weight = array_sum($page_counts);

    $best = null;
    $best_score = 0;
    $best_matches = [];
    
    

    foreach ($taxonomy as $cat) {

        if (empty($cat['tokens']) || empty($cat['path'])) {
            continue;
        }

        $path_parts = array_map('trim', explode('>', $cat['path']));
        $depth = count($path_parts);
        
            // =====================================================
            // FILTRO POR RAÍZ GPT DEL CLUSTER
            // Si el cluster ya apunta a "Vehículos y recambios",
            // los hubs inferiores no deberían saltar a Electrónica,
            // Salud, Casa y jardín, etc.
            // =====================================================
            
            $cat_root = $path_parts[0] ?? '';
            
            if (
                $preferred_root !== ''
                &&
                $level !== 'cluster'
                &&
                $cat_root !== $preferred_root
            ) {
                continue;
            }

        // Filtro por nivel jerárquico
        if ($level === 'cluster' && $depth !== 1) {
            continue;
        }

        if ($level === 'hub_primary' && ($depth < 2 || $depth > 3)) {
            continue;
        }

        if ($level === 'hub_secondary' && $depth < 3) {
            continue;
        }

        $matches = [];
        $matched_weight = 0;

        foreach ($cat['tokens'] as $token) {

            if (isset($page_counts[$token])) {

                $matches[] = $token;
                $matched_weight += $page_counts[$token];
            }
        }

        $matches = array_values(array_unique($matches));

        if (empty($matches)) {
            continue;
        }

        $leaf = end($path_parts);
        $leaf_tokens = seo_gpt_tokens($leaf);

        $leaf_matches = [];

        foreach ($leaf_tokens as $token) {

            if (isset($page_counts[$token])) {
                $leaf_matches[] = $token;
            }
        }

        $coverage_page_weight = $matched_weight / max(1, $total_page_weight);
        $coverage_cat = count($matches) / max(1, count($cat['tokens']));
        $coverage_leaf = count($leaf_matches) / max(1, count($leaf_tokens));

        if ($level === 'cluster') {

            // En cluster importa más la señal global que una coincidencia aislada.
            $score = round(
                (
                    ($coverage_page_weight * 0.80)
                    +
                    ($coverage_cat * 0.20)
                ) * 100
            );

        } elseif ($level === 'hub_primary') {

            $score = round(
                (
                    ($coverage_page_weight * 0.45)
                    +
                    ($coverage_cat * 0.35)
                    +
                    ($coverage_leaf * 0.20)
                ) * 100
            );

        } else {

            $score = round(
                (
                    ($coverage_leaf * 0.40)
                    +
                    ($coverage_cat * 0.35)
                    +
                    ($coverage_page_weight * 0.25)
                ) * 100
            );
        }

        if ($score > $best_score) {

            $best = $cat;
            $best_score = $score;
            $best_matches = $matches;
        }
    }

    if (!$best) {
        return [
            'found'   => false,
            'id'      => '',
            'path'    => 'Sin coincidencia clara en Google Product Taxonomy',
            'score'   => 0,
            'matches' => [],
            'level'   => $level
        ];
    }

    return [
        'found'   => true,
        'id'      => $best['id'],
        'path'    => $best['path'],
        'score'   => intval($best_score),
        'matches' => array_values(array_unique($best_matches)),
        'level'   => $level
    ];
}

}


/*************************************************
 * PINTAR BLOQUE DE RESULTADO GPT SEGÚN NIVEL
 *************************************************/
if (!function_exists('seo_gpt_render_match_box')) {

function seo_gpt_render_match_box($title, $match) {

$score = intval($match['score']);
$level = $match['level'] ?? 'hub_secondary';

    /*************************************************
     * COLOR SEGÚN NIVEL JERÁRQUICO
     * El cluster no se evalúa igual que un hub secundario.
     *************************************************/
    if ($level === 'cluster') {
    
        if (!empty($match['found']) && $score >= 10) {
            $border = '#2e7d32';
            $bg = '#f0fff4';
            $label = 'RAÍZ DETECTADA';
        } else {
            $border = '#c62828';
            $bg = '#fff5f5';
            $label = 'SIN RAÍZ CLARA';
        }
    
    } elseif ($level === 'hub_primary') {
    
        if ($score >= 55) {
            $border = '#2e7d32';
            $bg = '#f0fff4';
            $label = 'ALTA';
        } elseif ($score >= 35) {
            $border = '#ef6c00';
            $bg = '#fff8ec';
            $label = 'MEDIA';
        } else {
            $border = '#c62828';
            $bg = '#fff5f5';
            $label = 'BAJA';
        }
    
    } else {
    
        if ($score >= 75) {
            $border = '#2e7d32';
            $bg = '#f0fff4';
            $label = 'ALTA';
        } elseif ($score >= 50) {
            $border = '#ef6c00';
            $bg = '#fff8ec';
            $label = 'MEDIA';
        } else {
            $border = '#c62828';
            $bg = '#fff5f5';
            $label = 'BAJA';
        }
    }

    echo '<div style="
        margin-top:12px;
        padding:12px;
        background:' . esc_attr($bg) . ';
        border:1px solid ' . esc_attr($border) . ';
        border-radius:8px;
    ">';

    echo '<strong>' . esc_html($title) . '</strong><br>';

    echo '<strong>Ruta GPT:</strong><br>';
    echo esc_html($match['path']);

    echo '<br><br>';

    echo '<strong>ID Google:</strong> ';

    if (!empty($match['id'])) {
        echo esc_html($match['id']);
    } else {
        echo '<em>Sin ID</em>';
    }

    echo '<br>';

    echo '<strong>Similitud:</strong> ';
    echo '<span style="
        display:inline-block;
        padding:3px 8px;
        border-radius:5px;
        color:' . esc_attr($border) . ';
        font-weight:bold;
        background:#fff;
    ">';
    echo $score . '% - ' . esc_html($label);
    echo '</span>';

    echo '<br>';

    echo '<strong>Diagnóstico:</strong> ';
    echo esc_html(
        seo_gpt_diagnosis_by_level(
            $score,
            $match['level'] ?? 'hub_secondary',
            $match['found'] ?? false
        )
    );

    if (!empty($match['matches'])) {

        echo '<div style="margin-top:8px;">';
        echo '<strong>Coincidencias:</strong><br>';

        foreach ($match['matches'] as $token) {

            echo '<span style="
                display:inline-block;
                margin:3px;
                padding:3px 8px;
                background:#eef5ff;
                border-radius:4px;
                font-size:12px;
            ">';

            echo esc_html($token);

            echo '</span>';
        }

        echo '</div>';
    }

    echo '</div>';
}

}


/*************************************************
 * TEXTO SEMÁNTICO COMPLETO DE UNA PÁGINA SEO
 * Usa título, slug, excerpt, contenido, etiquetas y keywords SEO.
 *************************************************/
if (!function_exists('seo_gpt_page_semantic_text')) {

function seo_gpt_page_semantic_text($page_id) {

    global $wpdb;

    $page = get_post($page_id);

    if (!$page) {
        return '';
    }

    $text = '';

    // Datos propios de la página
    $text .= ' ' . $page->post_title;
    $text .= ' ' . $page->post_name;
    $text .= ' ' . $page->post_excerpt;
    $text .= ' ' . $page->post_content;

    // Etiquetas WordPress
    $tags = wp_get_post_tags(
        $page_id,
        ['fields' => 'names']
    );

    if (!is_wp_error($tags) && !empty($tags)) {
    
        $tags_text = implode(' ', $tags);
    
        // Las etiquetas WordPress también son señales semánticas fuertes.
        $text .= ' ' . $tags_text;
        $text .= ' ' . $tags_text;
    }

    // Keywords estructurales de esta pagina en seo_nodes.
    // Nunca mezclar con nodos legacy de categorias que puedan compartir el mismo ID numerico.
    $keywords = $wpdb->get_var(
        $wpdb->prepare("
            SELECT keywords
            FROM {$wpdb->prefix}seo_nodes
            WHERE object_type = 'page'
              AND object_id = %d
              AND seo_role IN ('cluster','hub_primary','hub_secondary')
              AND status = 1
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ", $page_id)
    );

    if (!empty($keywords)) {
        $text .= ' ' . $keywords;
    }

    return $text;
}

}

/*************************************************
 * CONTAR TOKENS CON PESO SEMÁNTICO
 * Permite que palabras repetidas pesen más que coincidencias aisladas.
 *************************************************/
if (!function_exists('seo_gpt_token_counts')) {

function seo_gpt_token_counts($text) {

    $tokens = seo_gpt_tokens($text);

    $counts = [];

    foreach ($tokens as $token) {

        if (!isset($counts[$token])) {
            $counts[$token] = 0;
        }

        $token_score = seo_gpt_token_score($token);
        
        if ($token_score <= 0) {
            continue;
        }
        
        $counts[$token] += $token_score;

        // Sinónimos semánticos para automoción
        if ($token === 'automocion') {

            $counts['vehiculo'] = ($counts['vehiculo'] ?? 0) + 4;
            $counts['vehiculos'] = ($counts['vehiculos'] ?? 0) + 4;
            $counts['coche'] = ($counts['coche'] ?? 0) + 3;
            $counts['automovil'] = ($counts['automovil'] ?? 0) + 3;
            $counts['recambio'] = ($counts['recambio'] ?? 0) + 2;
            $counts['recambios'] = ($counts['recambios'] ?? 0) + 2;
        }

        if ($token === 'coche' || $token === 'automovil') {

            $counts['vehiculo'] = ($counts['vehiculo'] ?? 0) + 2;
            $counts['vehiculos'] = ($counts['vehiculos'] ?? 0) + 2;
        }

        if ($token === 'mantenimiento') {

            $counts['reparacion'] = ($counts['reparacion'] ?? 0) + 1;
        }
    }

    return $counts;
}

}



/*************************************************
 * OBTENER RAÍZ DE UNA RUTA DE GOOGLE PRODUCT TAXONOMY
 * Ejemplo: "Vehículos y recambios > Piezas..." devuelve "Vehículos y recambios".
 *************************************************/
if (!function_exists('seo_gpt_root_from_path')) {

function seo_gpt_root_from_path($path) {

    $parts = array_map(
        'trim',
        explode('>', (string) $path)
    );

    return $parts[0] ?? '';
}

}



/*************************************************
 * CARGAR DICCIONARIO SEO
 * Usa wp_seo_dictionari como fuente de pesos semánticos.
 *************************************************/
if (!function_exists('seo_gpt_dictionary_scores')) {

function seo_gpt_dictionary_scores() {

    global $wpdb;

    $cached = get_transient('seo_gpt_dictionary_scores');

    if ($cached !== false) {
        return $cached;
    }

    $rows = $wpdb->get_results("
        SELECT palabra, puntuacion
        FROM {$wpdb->prefix}seo_dictionari
    ");

    $scores = [];

    foreach ($rows as $row) {

        $word = strtolower(remove_accents(trim((string) $row->palabra)));

        if ($word === '') {
            continue;
        }

        $scores[$word] = intval($row->puntuacion);
    }

    set_transient(
        'seo_gpt_dictionary_scores',
        $scores,
        12 * HOUR_IN_SECONDS
    );

    return $scores;
}

}



/*************************************************
 * SABER SI UN TOKEN DEBE DESCA*TARSE
 * Los tokens con puntuacion*0 en wp_seo_dictionari no particip*n.
 *************************************************/
/*************************************************
 * SABER SI UN TOKEN DEBE DESCARTARSE
 * Los tokens con puntuacion 0 en wp_seo_dictionari no participan.
 *************************************************/
if (!function_exists('seo_gpt_token_is_discarded')) {

function seo_gpt_token_is_discarded($token) {

    $scores = seo_gpt_dictionary_scores();

    $token = strtolower(remove_accents(trim((string) $token)));

    if ($token === '') {
        return true;
    }

    return (
        array_key_exists($token, $scores)
        &&
        intval($scores[$token]) <= 0
    );
}

}