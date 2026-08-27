<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('seo_schema_search')) {

function seo_schema_search() {

    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $tabla_relations = $wpdb->prefix . 'seo_relations';

    echo '<div class="wrap">';
    echo '<h2>Análisis Semántico de Categorías</h2>';
    echo '<p>Evalúa si los productos encajan dentro de cada categoría.</p>';

    $rows = $wpdb->get_results("
        SELECT
            cat.term_id,
            cat.name,
            cat.slug,
            tt.count AS product_count,
            hs.post_title AS hub_secundario,
            hp.post_title AS hub_primario,
            cl.post_title AS cluster
        FROM {$wpdb->terms} cat
        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_id = cat.term_id
            AND tt.taxonomy = 'product_cat'
        LEFT JOIN {$tabla_relations} r1
            ON r1.target_id = cat.term_id
            AND r1.target_type = 'product_cat'
            AND r1.relation_type = 'hub_secondary_to_category'
        LEFT JOIN {$wpdb->posts} hs
            ON hs.ID = r1.source_id
        LEFT JOIN {$tabla_relations} r2
            ON r2.target_id = hs.ID
            AND r2.relation_type IN ('hub_primary_to_hub_secondary','hub_primary_to_secondary')


        LEFT JOIN {$wpdb->posts} hp
            ON hp.ID = r2.source_id
        LEFT JOIN {$tabla_relations} r3
            ON r3.target_id = hp.ID
            AND r3.relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
        LEFT JOIN {$wpdb->posts} cl
            ON cl.ID = r3.source_id
        ORDER BY cl.post_title ASC, hp.post_title ASC, hs.post_title ASC, cat.name ASC
    ");

    if (empty($rows)) {
        echo '<p>No se encontraron categorías para analizar.</p>';
        echo '</div>';
        return;
    }


        $cluster_actual = '';
        $hub_primario_actual = '';
        $hub_secundario_actual = '';
        
        foreach ($rows as $row) {
        
            $cluster = trim((string)$row->cluster);
            $hub_primario = trim((string)$row->hub_primario);
            $hub_secundario = trim((string)$row->hub_secundario);
        
            if ($cluster === '') {
                $cluster = '⚠️ SIN CLUSTER';
            }
        
            if ($hub_primario === '') {
                $hub_primario = '⚠️ SIN HUB PRIMARIO';
            }
        
            if ($hub_secundario === '') {
                $hub_secundario = '⚠️ SIN HUB SECUNDARIO';
            }
        
            if ($cluster !== $cluster_actual) {
        
                echo '<div style="margin:30px 0 15px 0;padding:15px 20px;background:#1d2327;color:#fff;border-radius:8px;font-size:22px;font-weight:bold;">';
                echo '📁 CLUSTER: ' . esc_html($cluster);
                echo '</div>';
        
                $cluster_actual = $cluster;
                $hub_primario_actual = '';
                $hub_secundario_actual = '';
            }
        
            if ($hub_primario !== $hub_primario_actual) {
        
                echo '<div style="margin:15px 0 10px 20px;padding:10px 15px;background:#e7f1ff;border-left:5px solid #2271b1;font-size:18px;font-weight:bold;">';
                echo '📂 HUB PRIMARY: ' . esc_html($hub_primario);
                echo '</div>';
        
                $hub_primario_actual = $hub_primario;
                $hub_secundario_actual = '';
            }
        
            if ($hub_secundario !== $hub_secundario_actual) {
        
                echo '<div style="margin:10px 0 10px 40px;padding:8px 12px;background:#f6f7f7;border-left:4px solid #72aee6;font-size:15px;font-weight:bold;">';
                echo '📄 HUB SECONDARY: ' . esc_html($hub_secundario);
                echo '</div>';
        
                $hub_secundario_actual = $hub_secundario;
            }
        
            $result = seo_schema_category_diagnosis(
                intval($row->term_id),
                $row->name,
                $row->cluster,
                $row->hub_primario,
                $row->hub_secundario
            );
        
            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:0 0 18px 60px;">';
        
            echo '<h3 style="font-size:18px;margin:0 0 8px 0;">';
            echo esc_html($row->name);
            echo '</h3>';
        
            echo '<div style="font-size:12px;color:#646970;margin-bottom:12px;">';
            echo 'ID: <strong>' . intval($row->term_id) . '</strong> | ';
            echo 'Slug: <strong>' . esc_html($row->slug) . '</strong> | ';
            echo 'Productos: <strong>' . intval($row->product_count) . '</strong>';
            echo '</div>';
        
            echo '<div style="display:grid;grid-template-columns:1fr 120px 180px 1.5fr;gap:10px;align-items:start;">';
        
            echo '<div>';
            echo '<strong>Schema / concepto idóneo</strong><br>';
            echo esc_html($result['schema']);
            echo '</div>';
        
            echo '<div>';
            echo '<strong>Similitud</strong><br>';
            echo intval($result['score']) . '%';
            echo '</div>';
        
            echo '<div>';
            echo '<strong>Diagnóstico</strong><br>';
            echo esc_html($result['diagnosis']);
            echo '</div>';
        
            echo '<div>';
            echo '<strong>Sugerencia</strong><br>';
            echo esc_html($result['suggestion']);
            echo '</div>';
        
            echo '</div>';
            echo '</div>';
        }

    
    

    echo '</div>';
}
}

if (!function_exists('seo_schema_category_diagnosis')) {

function seo_schema_category_diagnosis($cat_id, $cat_name, $cluster = '', $hub_primario = '', $hub_secundario = '') {

    global $wpdb;

    $product_titles = $wpdb->get_col($wpdb->prepare("
        SELECT p.post_title
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr
            ON tr.object_id = p.ID


        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE p.post_type = 'product'
        AND p.post_status IN ('publish','draft','private')
        AND tt.taxonomy = 'product_cat'
        AND tt.term_id = %d
        LIMIT 120
    ", $cat_id));

    if (empty($product_titles)) {
        return [
            'schema' => 'Sin datos suficientes',

            'score' => 0,
            'diagnosis' => 'Sin productos',
            'suggestion' => 'No hay productos suficientes para evaluar esta categoría.'
        ];
    }

    $context_text =
        $cat_name . ' ' .
        $cluster . ' ' .
        $hub_primario . ' ' .
        $hub_secundario;

    $products_text = implode(' ', $product_titles);


    $context_tokens  = seo_schema_tokens($context_text);
    $product_tokens  = seo_schema_tokens($products_text);
    $top_tokens      = seo_schema_top_tokens($product_tokens, 7);

    $matches = 0;

    foreach ($context_tokens as $token) {
        if (in_array($token, $product_tokens, true)) {
            $matches++;
        }
    }

    $base = count($context_tokens);
    $score = ($base > 0) ? round(($matches / $base) * 100) : 0;

    $schema = seo_schema_build_concept($top_tokens);

    if ($score >= 70) {
        $diagnosis = 'OK';
        $suggestion = 'La categoría parece coherente con los productos asociados.';
    } elseif ($score >= 45) {
        $diagnosis = 'Revisar productos';

        $suggestion = 'Hay relación parcial, pero conviene revisar productos fuera de intención principal.';
    } elseif ($score >= 25) {
        $diagnosis = 'Categoría demasiado amplia';
        $suggestion = 'Puede haber mezcla de familias. Revisa si conviene dividir la categoría.';
    } else {
        $diagnosis = 'Dividir categoría';
        $suggestion = 'Los productos no parecen alineados con el nombre o el hub actual.';
    }


    return [
        'schema' => $schema,
        'score' => intval($score),
        'diagnosis' => $diagnosis,
        'suggestion' => $suggestion
    ];
}
}


if (!function_exists('seo_schema_tokens')) {

function seo_schema_tokens($text) {

    $text = strtolower(remove_accents(wp_strip_all_tags($text)));
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    $words = explode(' ', trim($text));

    $stop = [
        'para','con','sin','por','del','las','los','una','uno','unos','unas',
        'de','la','el','en','y','a','mm','cm','kit','set','pack','pieza',
        'piezas','producto','productos','herramienta','herramientas'
    ];

    $tokens = [];

    foreach ($words as $word) {
        if (strlen($word) >= 4 && !in_array($word, $stop, true)) {
            $tokens[] = $word;
        }
    }

    return array_values(array_unique($tokens));
}
}


if (!function_exists('seo_schema_top_tokens')) {

function seo_schema_top_tokens($tokens, $limit = 7) {

    $counts = array_count_values($tokens);
    arsort($counts);

    return array_slice(array_keys($counts), 0, $limit);
}
}


if (!function_exists('seo_schema_build_concept')) {

function seo_schema_build_concept($tokens) {

    if (empty($tokens)) {
        return 'CollectionPage';
    }

    $tokens = array_slice($tokens, 0, 5);
    $label = implode(' / ', array_map('ucfirst', $tokens));

    return $label;
}
}