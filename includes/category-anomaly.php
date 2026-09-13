<?php
if (!defined('ABSPATH')) exit;


if (!function_exists('seo_debug_anomalia')) {


}
// =========================
// PERFIL PONDERADO CATEGORÍA
// =========================



if (!function_exists('seo_cat_tokens')) {

function seo_cat_tokens($text) {

global $wpdb;

static $ignore_words = null;

if ($ignore_words === null) {

    $ignore_words = $wpdb->get_col("
        SELECT palabra
        FROM {$wpdb->prefix}seo_dictionari
        WHERE puntuacion = 0
    ");

    $ignore_words = array_flip(
        array_map('trim', $ignore_words)
    );
}
$text = seo_clean_text($text);

$parts = preg_split('/\s+/', $text);

$tokens = [];

foreach ($parts as $w) {

    $w = trim($w);

    if (mb_strlen($w) < 3) {
        continue;
    }

    if (
        mb_strlen($w) > 4
        && substr($w, -1) === 's'
    ) {
        $w = substr($w, 0, -1);
    }
        if (isset($ignore_words[$w])) {
        continue;
    }

    $tokens[] = $w;
}

return $tokens;

}

}

  
            
if (!function_exists('seo_cat_similarity_percent')) {

    function seo_cat_similarity_percent($a, $b) {

        return seo_token_similarity($a, $b);

    }
}



/**
 * Devuelve contenido editorial de categoría desde wp_seo_nodes.
 * Las etiquetas semánticas ya no se leen de seo_nodes/category/category.
 */
if (!function_exists('seo_cat_node_text')) {
    function seo_cat_node_text($cat_id, $role) {
        global $wpdb;

        $cat_id = absint($cat_id);
        $role = sanitize_key($role);
        if (!$cat_id || !in_array($role, ['excerpt', 'description'], true)) {
            return '';
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT keywords
                 FROM {$wpdb->prefix}seo_nodes
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = %s
                   AND status = 1
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                $cat_id,
                $role
            )
        );
    }
}

if (!function_exists('seo_cat_keywords_text')) {
    function seo_cat_keywords_text($cat_id) {
        return function_exists('seo_category_vocabulary_text')
            ? seo_category_vocabulary_text($cat_id)
            : '';
    }
}

if (!function_exists('seo_cat_excerpt_text')) {
    function seo_cat_excerpt_text($cat_id) {
        return seo_cat_node_text($cat_id, 'excerpt');
    }
}

if (!function_exists('seo_cat_description_text')) {
    function seo_cat_description_text($cat_id) {
        return seo_cat_node_text($cat_id, 'description');
    }
}

if (!function_exists('seo_category_anomaly_move_relation_with_data_layer')) {
    function seo_category_anomaly_move_relation_with_data_layer($cat_id, $target_hs_id) {
        global $wpdb;

        $cat_id = absint($cat_id);
        $target_hs_id = absint($target_hs_id);
        if ($cat_id < 1 || $target_hs_id < 1) {
            return new WP_Error('seo_category_move_invalid', 'Categoría o Hub Secundario no válido.');
        }
        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            return new WP_Error('seo_category_move_no_datalayer', 'El Data Layer no está disponible.');
        }

        $relations_table = $wpdb->prefix . 'seo_relations';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_id FROM {$relations_table}\n"
                . "WHERE target_id = %d AND relation_type = 'hub_secondary_to_category'\n"
                . "ORDER BY id ASC",
                $cat_id
            ),
            ARRAY_A
        );
        if (!$rows) {
            return new WP_Error('seo_category_move_relation_missing', 'No existe una relación hub_secondary_to_category para esta categoría.');
        }

        $changes = array_values(array_filter((array) $rows, static function ($row) use ($target_hs_id) {
            return absint($row['source_id'] ?? 0) !== $target_hs_id;
        }));
        if (!$changes) {
            return ['changed' => 0, 'operation_id' => 0, 'operation_uuid' => ''];
        }

        $operation = SEO_Data_Layer::operation([
            'type'          => 'move_category_hub_secondary',
            'label'         => 'Mover categoría a Hub Secundario',
            'source_module' => 'category_anomaly',
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => ['category_id' => $cat_id, 'target_hub_secondary_id' => $target_hs_id, 'relations' => count($changes)],
        ]);
        $operation->mark_validated(['category_id' => $cat_id]);
        $operation->mark_previewed(count($changes));

        $operation->execute(
            static function (SEO_Data_Operation $op) use ($changes, $cat_id, $target_hs_id) {
                foreach ($changes as $row) {
                    $relation_id = absint($row['id'] ?? 0);
                    if ($relation_id < 1) {
                        continue;
                    }
                    $op->update('relations', ['id' => $relation_id], ['source_id' => $target_hs_id], [
                        'related_object_type' => 'product_cat',
                        'related_object_id'   => $cat_id,
                        'reason'              => 'category_anomaly_move',
                    ]);
                }
            }
        );

        return ['changed' => count($changes), 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_cat_products_text')) {

    function seo_cat_products_text($products) {

        $txt = '';

        foreach ($products as $p) {

            $txt .= ' '
                . $p->post_title
                . ' '
                . $p->post_excerpt;
        }

        return trim($txt);
    }
}

if (!function_exists('seo_cat_score')) {

function seo_cat_score(
    $cat_id,
    $term,
    $products,
    $hs_obj,
    $hp_obj,
    $cluster_obj,
    $weights = []
) {

    $w_title     = (float) ($weights['title'] ?? 20);
    $w_tags      = (float) ($weights['tags'] ?? 20);
    $w_desc      = (float) ($weights['desc'] ?? 20);
    $w_hierarchy = (float) ($weights['hierarchy'] ?? 20);
    $w_products  = (float) ($weights['products'] ?? 20);

    $total_w = $w_title + $w_tags + $w_desc + $w_hierarchy + $w_products;

    if ($total_w <= 0) {
        $total_w = 1;
    }

    $category_name = '';
    $category_slug = '';

    if ($term && !is_wp_error($term)) {
        $category_name = $term->name ?? '';
        $category_slug = $term->slug ?? '';
    }

    // La semántica se lee de Vocabulary; excerpt y descripción siguen en wp_seo_nodes.
    $category_keywords   = seo_cat_keywords_text($cat_id);
    $category_excerpt    = seo_cat_excerpt_text($cat_id);
    $category_description = seo_cat_description_text($cat_id);

    // El análisis semántico debe trabajar con texto, no con etiquetas HTML.
    $category_excerpt_text = wp_strip_all_tags($category_excerpt);
    $category_description_text = wp_strip_all_tags($category_description);
    $category_content_text = trim(
        $category_excerpt_text . ' ' . $category_description_text
    );

    $products_text = seo_cat_products_text($products);

    $hierarchy_text = '';

    foreach ([$cluster_obj, $hp_obj, $hs_obj] as $o) {
        if ($o) {
            $hierarchy_text .= ' ';
            $hierarchy_text .= $o->post_title ?? '';
            $hierarchy_text .= ' ';
            $hierarchy_text .= $o->post_excerpt ?? '';
            $hierarchy_text .= ' ';
            $hierarchy_text .= wp_strip_all_tags($o->post_content ?? '');
        }
    }

    $category_text = trim(
        $category_name . ' ' .
        $category_slug . ' ' .
        $category_content_text . ' ' .
        $category_keywords
    );

    $name_score = seo_cat_similarity_percent(
        $category_name . ' ' . $category_slug,
        $hierarchy_text . ' ' . $products_text
    );

    $tags_score = seo_cat_similarity_percent(
        $category_keywords,
        $category_name . ' ' .
        $category_content_text . ' ' .
        $hierarchy_text . ' ' .
        $products_text
    );

    // El peso editorial analiza conjuntamente excerpt y description.
    $desc_score = seo_cat_similarity_percent(
        $category_content_text,
        $hierarchy_text . ' ' . $products_text
    );

    $hierarchy_score = seo_cat_similarity_percent(
        $category_text . ' ' . $products_text,
        $hierarchy_text
    );

    $products_score = seo_cat_similarity_percent(
        $products_text,
        $category_text . ' ' . $hierarchy_text
    );

    $final = (
        ($name_score * $w_title) +
        ($tags_score * $w_tags) +
        ($desc_score * $w_desc) +
        ($hierarchy_score * $w_hierarchy) +
        ($products_score * $w_products)
    ) / $total_w;

    return (int) round($final);
}

}




//Busca anomalias
function search_category_anomaly() {
    
    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    
    
        // =========================
        // PROCESAR BOTÓN "MOVER CATEGORÍA"
        // =========================
            if (
                isset($_POST['seo_move_category']) &&
                $_POST['seo_move_category'] == 1
            ) {
            
                if (
                    !isset($_POST['seo_move_category_nonce']) ||
                    !wp_verify_nonce($_POST['seo_move_category_nonce'], 'seo_move_category_action')
                ) {
                    return;
                }
            
                $cat_id = intval($_POST['cat_id']);
                $target_hs_id = intval($_POST['target_hs_id']);
            
                if ($cat_id > 0 && $target_hs_id > 0) {
                    $move_result = seo_category_anomaly_move_relation_with_data_layer($cat_id, $target_hs_id);
                    if (is_wp_error($move_result)) {
                        echo '<div class="notice notice-error"><p>' . esc_html($move_result->get_error_message()) . '</p></div>';
                    } else {
                        wp_safe_redirect(wp_unslash($_SERVER['REQUEST_URI']));
                        exit;
                    }
                }
            }
    

    // =========================
    // FILTROS
    // =========================
    $cluster        = isset($_GET['cluster']) ? intval($_GET['cluster']) : 0;
    $hub_primario   = isset($_GET['hub_primario']) ? intval($_GET['hub_primario']) : 0;
    $hub_secundario = isset($_GET['hub_secundario']) ? intval($_GET['hub_secundario']) : 0;
    $cat            = isset($_GET['cat']) ? intval($_GET['cat']) : 0;

    $run_anomalies = isset($_GET['run_anomalies']) && $_GET['run_anomalies'] == 1;

    // =========================
    // SELECTS
    // =========================
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

    $hub_secundarios_ids = [];
    if ($hub_primario > 0) {
        $hub_secundarios_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hub_primario));
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

    $all_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false
    ]);

    ob_start();
?>

<form method="GET" style="margin-bottom:20px;padding:20px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;">

    <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? 'product-page-admin'); ?>">
    <input type="hidden" name="tab" value="<?php echo esc_attr($_GET['tab'] ?? 'inventario'); ?>">

    <div style="display:flex;gap:15px;flex-wrap:wrap;">

        <select name="cluster" onchange="this.form.submit()">
            <option value="0">Cluster</option>
            <?php foreach ($cluster_ids as $id): $p = get_post($id); ?>
                <option value="<?php echo $id; ?>" <?php selected($cluster, $id); ?>>
                    <?php echo esc_html($p ? $p->post_title : "Cluster $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="hub_primario" onchange="this.form.submit()">
            <option value="0">Hub primario</option>
            <?php foreach ($hub_primarios_ids as $id): $p = get_post($id); ?>
                <option value="<?php echo $id; ?>" <?php selected($hub_primario, $id); ?>>
                    <?php echo esc_html($p ? $p->post_title : "HP $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="hub_secundario" onchange="this.form.submit()">
            <option value="0">Hub secundario</option>
            <?php foreach ($hub_secundarios_ids as $id): $p = get_post($id); ?>
                <option value="<?php echo $id; ?>" <?php selected($hub_secundario, $id); ?>>
                    <?php echo esc_html($p ? $p->post_title : "HS $id"); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="cat" onchange="this.form.submit()">
            <option value="0">Categoría</option>
            <?php foreach ($all_cats as $c): ?>
                <?php if (empty($category_ids_from_db) || in_array($c->term_id, $category_ids_from_db)): ?>
                    <option value="<?php echo $c->term_id; ?>" <?php selected($cat, $c->term_id); ?>>
                        <?php echo esc_html($c->name); ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>

    </div>

    <div style="margin-top:15px;">
        <h3>Pesos de valoración</h3>


        <table class="form-table">
            <tr>
                <th>Nombre categoría</th>
                <td><input type="number" name="w_title" value="<?php echo esc_attr($_GET['w_title'] ?? 20); ?>"></td>
            </tr>
            
            <tr>
                <th>Etiquetas SEO</th>
                <td><input type="number" name="w_tags" value="<?php echo esc_attr($_GET['w_tags'] ?? 20); ?>"></td>
            </tr>
            
            <tr>
                <th>Excerpt + descripción de categoría</th>
                <td><input type="number" name="w_attrs" value="<?php echo esc_attr($_GET['w_attrs'] ?? 20); ?>"></td>
            </tr>
            
            <tr>
                <th>Jerarquía (Cluster + Hubs)</th>
                <td><input type="number" name="w_hierarchy" value="<?php echo esc_attr($_GET['w_hierarchy'] ?? 20); ?>"></td>
            </tr>
            

<tr>
    <th>Productos contenidos</th>
    <td>
        <input type="number" 
        name="w_group" 
        value="<?php echo esc_attr($_GET['w_group'] ?? 20); ?>">
    </td>
</tr>

 
 
 
 
        </table>

        <button type="submit" name="run_anomalies" value="1" class="button button-primary">
            Buscar anomalías
        </button>
    </div>
</form>

<?php
// =========================
// AUTOCARGA DE FILTROS DESDE URL (REVISAR CATEGORÍA)
// =========================


if ($cluster > 0 || $hub_primario > 0 || $hub_secundario > 0 || $cat > 0) {
    
    echo "<script>

    var cluster = " . intval($cluster) . ";
    var hubPrimario = " . intval($hub_primario) . ";
    var hubSecundario = " . intval($hub_secundario) . ";
    var cat = " . intval($cat) . ";

    if (cluster > 0) {
        var el = document.querySelector('select[name=cluster]');
        if (el) el.value = cluster;
    }

    if (hubPrimario > 0) {
        var el = document.querySelector('select[name=hub_primario]');
        if (el) el.value = hubPrimario;
    }

    if (hubSecundario > 0) {
        var el = document.querySelector('select[name=hub_secundario]');
        if (el) el.value = hubSecundario;
    }

    if (cat > 0) {
        var el = document.querySelector('select[name=cat]');
        if (el) el.value = cat;
    }

    </script>";
}



if (!$run_anomalies) {
    echo ob_get_clean();
    return;
}



// =========================
// PESOS DE CATEGORÍA
// =========================
$w_title      = floatval($_GET['w_title'] ?? 20);
$w_tags       = floatval($_GET['w_tags'] ?? 20);
$w_attrs      = floatval($_GET['w_attrs'] ?? 20);
$w_hierarchy  = floatval($_GET['w_hierarchy'] ?? 20);
$w_group      = floatval($_GET['w_group'] ?? 20);

$total_w = $w_title + $w_tags + $w_attrs + $w_hierarchy + $w_group;

// Pesos activos para calcular categorías
$cat_weights = [
    'title'     => $w_title,
    'tags'      => $w_tags,
    'desc'      => $w_attrs,
    'hierarchy' => $w_hierarchy,
    'products'  => $w_group
];



if ($total_w <= 0) $total_w = 1;

// =========================
// CATEGORIAS A ANALIZAR
// =========================
$cats_to_analyze = [];

if ($cat > 0) {

    $cats_to_analyze[] = $cat;

} elseif ($hub_secundario > 0) {

    $cats_to_analyze = $wpdb->get_col($wpdb->prepare("
        SELECT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'hub_secondary_to_category'
    ", $hub_secundario));

} elseif ($hub_primario > 0) {

    $hubs_sec = $wpdb->get_col($wpdb->prepare("
        SELECT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'hub_primary_to_hub_secondary'
    ", $hub_primario));

    foreach ($hubs_sec as $hs) {

        $cats_to_analyze = array_merge(
            $cats_to_analyze,
            $wpdb->get_col($wpdb->prepare("
                SELECT target_id
                FROM $relations_table
                WHERE source_id = %d
                AND relation_type = 'hub_secondary_to_category'
            ", $hs))
        );
    }

} elseif ($cluster > 0) {

    $hubs_pri = $wpdb->get_col($wpdb->prepare("
        SELECT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'cluster_to_primary'
    ", $cluster));

    foreach ($hubs_pri as $hp) {

        $hubs_sec = $wpdb->get_col($wpdb->prepare("
            SELECT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hp));

        foreach ($hubs_sec as $hs) {

            $cats_to_analyze = array_merge(
                $cats_to_analyze,
                $wpdb->get_col($wpdb->prepare("
                    SELECT target_id
                    FROM $relations_table
                    WHERE source_id = %d
                    AND relation_type = 'hub_secondary_to_category'
                ", $hs))
            );
        }
    }
}

$cats_to_analyze = array_unique($cats_to_analyze);

if (empty($cats_to_analyze)) {

    echo '<p>No hay categorías para analizar.</p>';
    echo ob_get_clean();
    return;
}
$results = [];

//Muestra los productos
foreach ($cats_to_analyze as $cat) {

    // Obtener productos y categoría actual
    $products = $wpdb->get_results($wpdb->prepare("
        SELECT ID, post_title, post_excerpt, post_content, post_name
        FROM {$wpdb->posts}
        WHERE post_type = 'product'
        AND post_status = 'publish'
        AND ID IN (
            SELECT object_id
            FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.taxonomy = 'product_cat'
            AND tt.term_id = %d
        )
    ", $cat));
    
    $cat_terms = get_term($cat, 'product_cat');
    
        if (!$cat_terms || is_wp_error($cat_terms)) {
            continue;
        }

// =========================
// SCORES
// =========================
$real_hierarchy = seo_get_category_hierarchy_objects($cat);

$hs = $real_hierarchy['hs'];
$hp = $real_hierarchy['hp'];
$cluster_obj = $real_hierarchy['cluster'];

$score = seo_cat_score(
    $cat,
    $cat_terms,
    $products,
    $hs,
    $hp,
    $cluster_obj,
    $cat_weights
);


// =========================
// RESULTADO CATEGORÍA
// =========================

// Buscar mejor destino para la categoría
$hub_secundario_actual = $real_hierarchy['hs_id'];
$hub_primario_actual   = $real_hierarchy['hp_id'];


// Buscar mejor hub secundario candidato
$suggestion = [
    'score' => 0,
    'hub_secondary_id' => 0,
    'hub_secondary' => '',
    'hub_primary' => '',
    'cluster' => ''
];

if ($hub_primario_actual > 0) {
    
    
$suggestion = seo_find_best_hub_secondary(
    $cat,
    $cat_terms,
    $products,
    $hub_primario_actual,
    $cat_weights,
    $hub_secundario_actual
);

// Si el mejor candidato no mejora la categoría actual,
// no proponemos movimiento pero informamos del motivo

if ($suggestion['score'] <= $score) {

    $suggestion['status'] = 'No tenemos una categoría mejor a asignar.';

}
else {

    $suggestion['status'] = 'better_category';

}



  

}
// =========================
// LÓGICA DE DECISIÓN DE ACCIÓN SOBRE LA CATEGORÍA
// =========================
        $action = 'review';
        
        if ($suggestion['score'] > ($score + 10)) {
        
            $action = 'move';
        
        } elseif (
            count($products) >= 10
            && $score < 30
        ) {
        
            $action = 'split';
        
        } elseif ($score < 60) {
        
            $action = 'description';
        }
    
    $results[] = [
        'cat_id' => $cat,
        'title' => $cat_terms->name,
        'score' => $score,
        'action' => $action,
        'suggested_score' => $suggestion['score'],
        'suggested_cluster' => $suggestion['cluster'],
        'suggested_hp' => $suggestion['hub_primary'],
        'suggested_hs' => $suggestion['hub_secondary'],
        'current_hs' => $real_hierarchy['hs'] ? $real_hierarchy['hs']->post_title : '',
        'suggested_hs_id' => $suggestion['hub_secondary_id']
    ];







} // fin foreach cats_to_analyze


// AQUÍ EMPIEZA EL CÁLCULO DE MEDIAS

$bien = [];
$revisar = [];
$mal = [];

foreach ($results as $row) {

    if ($row['score'] >= 60) {

        $bien[] = $row;

    } elseif ($row['score'] >= 40) {

        $revisar[] = $row;

    } else {

        $mal[] = $row;
    }
}

echo '<h2 style="
background:#d1e7dd;
padding:10px;
border-left:5px solid #198754;
">
✅ Categorías bien clasificadas
</h2>';

echo '<ul>';

foreach ($bien as $row) {

    echo '<li><strong>'
        . esc_html($row['title'])
        . '</strong> - '
        . number_format($row['score'],2)
        . '%</li>';
}

echo '</ul>';

echo '<h2 style="
background:#fff3cd;
padding:10px;
border-left:5px solid #ffc107;
">
⚠️ Categorías revisables
</h2>';

echo '<ul>';


foreach ($revisar as $row) {

    if (empty($row['title'])) {
        continue;
    }

    echo '<li style="margin-bottom:25px;">';

    echo '<strong style="font-size:16px;">'
        . esc_html($row['title'])
        . '</strong> - '
        . number_format($row['score'],2)
        . '%';

    echo '<br><br>';

    echo '<div style="
        background:#f6f7f7;
        padding:12px;
        border-left:4px solid #777;
    ">';

    // Estado inicial
    echo '<strong>Estado:</strong> ';

    if (
        $row['suggested_score'] > $row['score']
    ) {
        echo '<span style="color:#198754;">Mejora de ubicación disponible</span>';
    } else {
        echo '<span style="color:#856404;">Sin ubicación superior encontrada</span>';
    }

    echo '<br><br>';

    echo '<strong>Hub actual:</strong> '
        . esc_html($row['current_hs']);

    echo '<br>';

    echo '<strong>Score actual:</strong> '
        . intval($row['score'])
        . '%';


    // Acción recomendada
    echo '<br><br>';

    echo '<strong>Acción recomendada:</strong> ';

    if (
        $row['suggested_score'] > $row['score']
    ) {

        echo 'Mover categoría al nuevo hub secundario';

    } else {

        echo 'Mejorar nombre, excerpt, descripción y etiquetas de categoría/productos';
    }


    // Mostrar alternativa solamente si existe
    if (!empty($row['suggested_hs_id'])) {

        echo '<br><br>';

        if (
            $row['suggested_score'] > $row['score']
        ) {

            echo '<div style="
                background:#d1e7dd;
                padding:10px;
                border-left:4px solid #198754;
            ">';

            echo '<strong>✅ Nueva ubicación recomendada:</strong><br>';

            echo esc_html(
                $row['suggested_cluster']
                . ' > '
                . $row['suggested_hp']
                . ' > '
                . $row['suggested_hs']
            );

            echo '<br>';

            echo 'Score estimado: '
                . intval($row['suggested_score'])
                . '%';

            echo '</div>';

        } else {

            echo '<div style="
                background:#fff3cd;
                padding:10px;
                border-left:4px solid #ffc107;
            ">';

            echo '<strong>⚠️ Mejor coincidencia analizada:</strong><br>';

            echo esc_html(
                $row['suggested_cluster']
                . ' > '
                . $row['suggested_hp']
                . ' > '
                . $row['suggested_hs']
            );

            echo '<br>';

            echo 'Score alternativa: '
                . intval($row['suggested_score'])
                . '%';

            echo '<br><br>';

            echo 'No existe actualmente una categoría inventariada con mejores condiciones. ';

            echo 'Se recomienda mejorar la información semántica para aumentar la puntuación.';

            echo '</div>';
        }
    }


    // Botón mover SOLO si realmente mejora
    if (
        $row['score'] < 60 &&
        !empty($row['suggested_hs_id']) &&
        $row['suggested_score'] > $row['score']
    ) {

        echo '<br><br>';

        echo '<form method="POST" style="display:inline;">';

        wp_nonce_field(
            'seo_move_category_action',
            'seo_move_category_nonce'
        );

        echo '<input type="hidden" name="cat_id" value="' . intval($row['cat_id']) . '">';
        echo '<input type="hidden" name="target_hs_id" value="' . intval($row['suggested_hs_id']) . '">';

        echo '<button type="submit"
            name="seo_move_category"
            value="1"
            class="button button-secondary">
            Mover categoría al hub sugerido
        </button>';

        echo '</form>';

    }


    // Botón revisar categoría siempre disponible
    $review_url = admin_url(
        'admin.php?page=category-seo-admin'
        . '&tab=categorias'
        . '&cluster=' . intval($cluster)
        . '&hub_primario=' . intval($hub_primario_actual)
        . '&hub_secundario=' . intval(
            $row['suggested_hs_id'] ?: $hub_secundario_actual
        )
        . '&cat=' . intval($row['cat_id'])
    );

    echo '<a href="' . esc_url($review_url) . '" 
        class="button button-primary"
        style="margin-left:10px;">
        Revisar categoría
    </a>';


    echo '</div>';

    echo '</li>';
}



echo '</ul>';

echo '<h2 style="
background:#f8d7da;
padding:10px;
border-left:5px solid #dc3545;
">
❌ Categorías probablemente mal clasificadas
</h2>';

echo '<ul>';

foreach ($mal as $row) {

    if (empty($row['title'])) {
        continue;
    }

    echo '<li>';

    echo '<strong>'
        . esc_html($row['title'])
        . '</strong> - '
        . number_format($row['score'],2)
        . '%';

    echo '<br>';

    echo '<small style="color:#646970;">';

    echo 'Categoría candidata para revisión manual';
    echo '<br><br>';

    if (!empty($row['similar_category'])) {
        echo 'Categoría WooCommerce más parecida: ';
        echo esc_html($row['similar_category']);
        echo ' (' . intval($row['similar_score'] ?? 0) . '%)';
    } else {
        echo 'No se ha calculado una categoría WooCommerce alternativa.';
    }

echo '<br>';
echo 'Hub secundario sugerido: ';
echo esc_html($row['suggested_hs']);

echo '<br>';
echo 'Score actual: ' . intval($row['score']);

echo '<br>';
echo 'Score en nuevo hub: ' . intval($row['suggested_score']);



echo '<strong>Acción recomendada:</strong> ';

switch ($row['action']) {

    case 'move':
        echo 'Mover a otro hub secundario';
        break;

    case 'description':
        echo 'Revisar nombre, excerpt, descripción y etiquetas';
        break;

    case 'split':
        echo 'Separar productos en varias categorías';
        break;

    default:
        echo 'Revisión manual';
}


    
            
if (
    !empty($row['suggested_hs_id'])
    && $row['suggested_score'] > $row['score']
) {

    echo '<br><br>';

    echo '<form method="POST" style="display:inline;">';

    wp_nonce_field(
        'seo_move_category_action',
        'seo_move_category_nonce'
    );
    echo '<input type="hidden" name="cat_id" value="' . intval($row['cat_id']) . '">';

    echo '<input type="hidden" name="target_hs_id" value="' . intval($row['suggested_hs_id']) . '">';

    echo '<button type="submit"
        name="seo_move_category"
        value="1"
        class="button button-secondary">
        Mover categoría al hub sugerido
    </button>';

    echo '</form>';
}
    
    
    
    
            if (
                $row['score'] < 60
                && $row['suggested_score'] > $row['score']
            ) {
        
            echo '<br><br>';
        
            echo 'Sugerido: ';
        
            echo esc_html(
                $row['suggested_cluster']
                . ' > '
                . $row['suggested_hp']
                . ' > '
                . $row['suggested_hs']
            );
            //Sugerencia de categoriaa a mover
            if ($row['suggested_score'] > $row['score']) {
                echo ' (mejora: ' . intval($row['suggested_score']) . '%)';
            } else {
                echo ' (' . intval($row['suggested_score']) . '%)';
            }
        }

    echo '</small>';

    echo '</li>';
}



echo '</ul>';

echo ob_get_clean();

}


// ========================================
// BUSCA EL MEJOR HUB SECUNDARIO CANDIDATO
// ========================================

if (!function_exists('seo_find_best_hub_secondary')) {


function seo_find_best_hub_secondary(
    $cat_id,
    $cat_terms,
    $products,
    $hub_primario,
    $weights = [],
    $current_hs_id = 0)
 {


    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    $best_score = 0;
    $best_hs = '';
    $best_hs_id = 0;

    $best_hp = '';
    $best_cluster = '';


// Obtener únicamente los hubs secundarios pertenecientes al hub primario actual
$hubs_secundarios = $wpdb->get_col(
    $wpdb->prepare(
        "
        SELECT target_id
        FROM {$relations_table}
        WHERE source_id = %d
        AND relation_type = 'hub_primary_to_hub_secondary'
        ",
        $hub_primario
    )
);




    
    foreach ($hubs_secundarios as $hs_id) {

    // Evitar comparar contra el propio hub secundario actual
    if ((int)$hs_id === (int)$current_hs_id) {
        continue;
    }

    $hs = get_post($hs_id);
    
    
        
        // obtener hub primario
        $hp_id = (int) $wpdb->get_var($wpdb->prepare("
            SELECT source_id
            FROM {$relations_table}
            WHERE target_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
            LIMIT 1
        ", $hs_id));
        
        $hp = $hp_id ? get_post($hp_id) : null;
        
        // obtener cluster
        $cluster_id = (int) $wpdb->get_var($wpdb->prepare("
            SELECT source_id
            FROM {$relations_table}
            WHERE target_id = %d
            AND relation_type = 'cluster_to_primary'
            LIMIT 1
        ", $hp_id));
        
        $cluster = $cluster_id ? get_post($cluster_id) : null;
        $score = seo_cat_score(
            $cat_id,
            $cat_terms,
            $products,
            $hs,
            $hp,
            $cluster,
            $weights
        );
        if ($score > $best_score) {

            $best_score = $score;

            $best_hs = $hs->post_title;
            $best_hs_id = $hs_id;


            $best_hp = $hp ? $hp->post_title : '';

            $best_cluster = $cluster ? $cluster->post_title : '';
        }
    }
       return [
        'score' => $best_score,
        'hub_secondary_id' => $best_hs_id,
        'hub_secondary' => $best_hs,
        'hub_primary' => $best_hp,
        'cluster' => $best_cluster
    ];

    } // cierra function
}// cierra if (!function_exists)





if (!function_exists('seo_get_category_hierarchy_objects')) {

function seo_get_category_hierarchy_objects($cat_id) {

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    $hs_id = (int) $wpdb->get_var($wpdb->prepare("
        SELECT source_id
        FROM {$relations_table}
        WHERE target_id = %d
        AND relation_type = 'hub_secondary_to_category'
        LIMIT 1
    ", $cat_id));

    $hp_id = 0;
    $cluster_id = 0;

    if ($hs_id > 0) {
        $hp_id = (int) $wpdb->get_var($wpdb->prepare("
            SELECT source_id
            FROM {$relations_table}
            WHERE target_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
            LIMIT 1
        ", $hs_id));
    }

    if ($hp_id > 0) {
        $cluster_id = (int) $wpdb->get_var($wpdb->prepare("
            SELECT source_id
            FROM {$relations_table}
            WHERE target_id = %d
            AND relation_type = 'cluster_to_primary'
            LIMIT 1
        ", $hp_id));
    }

    return [
        'hs_id'      => $hs_id,
        'hp_id'      => $hp_id,
        'cluster_id' => $cluster_id,
        'hs'         => $hs_id ? get_post($hs_id) : null,
        'hp'         => $hp_id ? get_post($hp_id) : null,
        'cluster'    => $cluster_id ? get_post($cluster_id) : null,
    ];
}

}