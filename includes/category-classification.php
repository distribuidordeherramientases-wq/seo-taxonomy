<?php
/*
Plugin Name: SEO Menu Manager
Plugin URI: https://www.distribuidordeherramientas.es/
Description: Clasificación de productos con etiqueas
Version: 1.0.0
Requires PHP: 7.4
Requires at least: 5.8f
Author: David Perez Martorell davidperezmartorell@gmail.com
Author URI: https://focazul.wordpress.com/
License: GPL2
Text Domain: category-classification
*/


if (!defined('ABSPATH')) exit;

/**
 * Lee un campo editorial de categoría desde wp_seo_nodes.
 *
 * Contrato de datos:
 * - category    => etiquetas SEO
 * - excerpt     => extracto SEO
 * - description => descripción HTML SEO
 *
 * @param int    $cat_id ID de product_cat.
 * @param string $role   category, excerpt o description.
 * @return string
 */
if (!function_exists('seo_category_classification_node_text')) {

    function seo_category_classification_node_text($cat_id, $role) {

        global $wpdb;

        $cat_id = absint($cat_id);
        $role = sanitize_key($role);

        if ($cat_id <= 0 || !in_array($role, ['category', 'excerpt', 'description'], true)) {
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



/**
 * Motor semántico explicable para categorías.
 *
 * Prioriza términos compartidos por varios productos y evita que una sola
 * ficha, marca o modelo domine la clasificación de toda la categoría.
 */
if (!function_exists('seo_cc_normalize_text')) {
    function seo_cc_normalize_text($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = remove_accents(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/\\b\\d+(?:[.,]\\d+)?\\s*(?:mm|cm|m|kg|g|w|kw|v|a|ah|bar|psi|rpm|hz|l|ml)\\b/iu', ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\\s+/u', ' ', $text));
    }
}

if (!function_exists('seo_cc_default_stopwords')) {
    function seo_cc_default_stopwords() {
        return [
            'para','como','desde','hasta','entre','sobre','bajo','tras','ante','contra','durante','mediante','segun','sin','con','por','del','las','los','una','uno','unos','unas','que','sus','este','esta','estos','estas','ese','esa','muy','mas','menos','tambien','puede','permite','ofrece','ayuda','facilita','mejora','evita','usar','utilizar','trabajo','trabajos','producto','productos','herramienta','herramientas','equipo','equipos','solucion','profesional','profesionales','calidad','rendimiento','eficiente','eficaz','practico','practica','ideal','versatil','imprescindible','principal','disenado','disenada','adecuado','adecuada','uso','tipo','aplicacion','aplicaciones','caracteristica','caracteristicas','ventaja','ventajas','categoria','categorias'
        ];
    }
}

if (!function_exists('seo_cc_stopwords')) {
    function seo_cc_stopwords() {
        static $cache = null;
        if (null !== $cache) return $cache;
        global $wpdb;
        $words = seo_cc_default_stopwords();
        $table = $wpdb->prefix . 'seo_dictionari';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $words = array_merge($words, (array) $wpdb->get_col("SELECT palabra FROM {$table} WHERE palabra IS NOT NULL AND palabra<>''"));
        }
        $cache=[];
        foreach ($words as $word) {
            $key=seo_cc_normalize_text($word);
            if (''!==$key) $cache[$key]=true;
        }
        return $cache;
    }
}

if (!function_exists('seo_cc_words')) {
    function seo_cc_words($text, $keep_stopwords=false) {
        $text=mb_strtolower(html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8');
        $parts=preg_split('/[^\\p{L}\\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        $stop=seo_cc_stopwords(); $out=[];
        foreach ((array)$parts as $word) {
            $key=seo_cc_normalize_text($word);
            if (''===$key || mb_strlen($key,'UTF-8')<3) continue;
            if (!$keep_stopwords && isset($stop[$key])) continue;
            $out[]=sanitize_text_field($word);
        }
        return $out;
    }
}

if (!function_exists('seo_cc_ngrams')) {
    function seo_cc_ngrams($text,$min=1,$max=3) {
        $words=seo_cc_words($text,true); $stop=seo_cc_stopwords(); $out=[]; $count=count($words);
        for($size=$min;$size<=$max;$size++) for($i=0;$i+$size<=$count;$i++) {
            $slice=array_slice($words,$i,$size);
            $first=seo_cc_normalize_text($slice[0]); $last=seo_cc_normalize_text($slice[count($slice)-1]);
            if(isset($stop[$first])||isset($stop[$last])) continue;
            $phrase=trim(implode(' ',$slice)); $key=seo_cc_normalize_text($phrase);
            if(''!==$key) $out[$key]=$phrase;
        }
        return $out;
    }
}

if (!function_exists('seo_cc_keyword_list')) {
    function seo_cc_keyword_list($value) {
        $out=[];
        foreach(explode(',',(string)$value) as $item) {
            $item=sanitize_text_field(trim($item)); $key=seo_cc_normalize_text($item);
            if(''!==$key&&!isset($out[$key])) $out[$key]=$item;
        }
        return array_values($out);
    }
}

if (!function_exists('seo_cc_candidate_valid')) {
    function seo_cc_candidate_valid($label) {
        $key=seo_cc_normalize_text($label);
        if(''===$key||mb_strlen($key,'UTF-8')<3||mb_strlen($label,'UTF-8')>70) return false;
        if(preg_match('/^\\d+(?:[.,]\\d+)?(?:\\s*(?:mm|cm|m|kg|g|w|kw|v|a|ah|bar|psi|rpm|hz|l|ml))?$/iu',trim($label))) return false;
        if(preg_match('/^(si|no|true|false|activo|inactivo|global)$/iu',trim($label))) return false;
        $content=0; $stop=seo_cc_stopwords(); foreach(explode(' ',$key) as $w) if(!isset($stop[$w])) $content++;
        return $content>0;
    }
}

if (!function_exists('seo_cc_add_candidate')) {
    function seo_cc_add_candidate(&$items,$label,$score,$source,$document_id=0) {
        $label=trim(sanitize_text_field($label)); $key=seo_cc_normalize_text($label);
        if(!seo_cc_candidate_valid($label)) return;
        if(!isset($items[$key])) $items[$key]=['label'=>$label,'score'=>0.0,'sources'=>[],'documents'=>[]];
        $items[$key]['score']+=(float)$score; $items[$key]['sources'][$source]=true;
        if($document_id>0) $items[$key]['documents'][absint($document_id)]=true;
        if(mb_strlen($label,'UTF-8')>mb_strlen($items[$key]['label'],'UTF-8')) $items[$key]['label']=$label;
    }
}

if (!function_exists('seo_cc_product_ids')) {
    function seo_cc_product_ids($cat_id) {
        static $cache=[]; $cat_id=absint($cat_id);
        if(isset($cache[$cat_id])) return $cache[$cat_id];
        return $cache[$cat_id]=get_posts([
            'post_type'=>'product','post_status'=>['publish','draft','pending','private'],'posts_per_page'=>-1,
            'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,
            'update_post_meta_cache'=>false,'update_post_term_cache'=>false,
            'tax_query'=>[['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cat_id,'include_children'=>false]],
        ]);
    }
}

if (!function_exists('seo_cc_build_keyword_proposal')) {
    function seo_cc_build_keyword_proposal($cat_id,$limit=8) {
        global $wpdb;
        $term=get_term(absint($cat_id),'product_cat');
        if(!$term||is_wp_error($term)) return ['labels'=>[],'warnings'=>['Categoría inexistente.'],'stats'=>[]];
        $existing=seo_category_classification_node_text($cat_id,'category');
        $excerpt=seo_category_classification_node_text($cat_id,'excerpt');
        $description=seo_category_classification_node_text($cat_id,'description');
        $items=[]; $warnings=[];
        foreach(seo_cc_ngrams($term->name,1,3) as $key=>$phrase) seo_cc_add_candidate($items,$phrase,75+count(explode(' ',$key))*15,'nombre_categoria');
        foreach(seo_cc_keyword_list($existing) as $label) seo_cc_add_candidate($items,$label,24,'etiqueta_actual');

        $knowledge_table=$wpdb->prefix.'seo_categoria_conocimiento';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$knowledge_table))===$knowledge_table) {
            $knowledge=$wpdb->get_col($wpdb->prepare("SELECT valor FROM {$knowledge_table} WHERE categoria_id=%d AND estado=1 ORDER BY prioridad DESC",$cat_id));
            foreach((array)$knowledge as $value) foreach(seo_cc_ngrams($value,1,3) as $phrase) seo_cc_add_candidate($items,$phrase,55,'conocimiento');
        }

        $product_ids=seo_cc_product_ids($cat_id); $product_count=count($product_ids);
        foreach($product_ids as $product_id) {
            $post=get_post($product_id); if(!$post) continue;
            foreach(seo_cc_ngrams($post->post_title,1,3) as $phrase) seo_cc_add_candidate($items,$phrase,0,'titulo_producto',$product_id);
            $labels='';
            if(function_exists('seo_catalog_get_product_public_semantic_labels')) {
                $semantic_rows=seo_catalog_get_product_public_semantic_labels($product_id,30);
                $labels=implode(', ',array_values(array_filter(array_map(static function($row){return trim((string)($row['label']??''));},(array)$semantic_rows))));
            }
            foreach(seo_cc_keyword_list($labels) as $label) seo_cc_add_candidate($items,$label,0,'faceta_producto',$product_id);
            $attrs=$wpdb->get_results($wpdb->prepare("SELECT attribute_type,attribute_value FROM {$wpdb->prefix}seo_attributes WHERE product_id=%d",$product_id));
            foreach((array)$attrs as $attr) {
                $type=seo_cc_normalize_text($attr->attribute_type);
                if(in_array($type,['tipo','uso','profesion','profesional','sector','target','producto','ambito','raw_description','raw_excerpt'],true)) continue;
                if(seo_cc_candidate_valid($attr->attribute_value)) seo_cc_add_candidate($items,$attr->attribute_value,0,'atributo_producto',$product_id);
            }
        }

        foreach($items as $key=>&$item) {
            $docs=count($item['documents']);
            if($docs>0 && $product_count>0) {
                $ratio=$docs/$product_count;
                $item['score'] += min(70, $docs*5 + $ratio*45);
                if($docs>=2) $item['score']+=10;
            }
            $norm_excerpt=seo_cc_normalize_text($excerpt); $norm_desc=seo_cc_normalize_text($description);
            if(''!==$norm_excerpt && false!==strpos(' '.$norm_excerpt.' ',' '.$key.' ')) {$item['score']+=12;$item['sources']['excerpt']=true;}
            if(''!==$norm_desc && false!==strpos(' '.$norm_desc.' ',' '.$key.' ')) {$item['score']+=7;$item['sources']['description']=true;}
            $item['score']+=min(12,max(0,count($item['sources'])-1)*3);
        }
        unset($item);

        uasort($items,function($a,$b){ if($a['score']===$b['score']) return count($b['documents'])<=>count($a['documents']); return $b['score']<=>$a['score']; });
        $selected=[];
        foreach($items as $key=>$item) {
            $docs=count($item['documents']);
            $trusted=isset($item['sources']['nombre_categoria'])||isset($item['sources']['conocimiento'])||$docs>=max(2,(int)ceil($product_count*0.08));
            if(!$trusted||$item['score']<38) continue;
            $redundant=false;
            foreach($selected as $sel_key=>$unused) {
                if($key===$sel_key||(strlen($key)>4&&false!==strpos($sel_key,$key))||(strlen($sel_key)>4&&false!==strpos($key,$sel_key))) {$redundant=true;break;}
            }
            if($redundant) continue;
            $selected[$key]=$item['label'];
            if(count($selected)>=max(3,absint($limit))) break;
        }
        if($product_count<2) $warnings[]='La categoría tiene pocos productos; la propuesta depende más del nombre y del contenido editorial.';
        if(count($selected)<3) $warnings[]='Pocas señales comunes: revisar manualmente.';
        $payload=['category_id'=>absint($cat_id),'name'=>$term->name,'excerpt'=>wp_strip_all_tags($excerpt),'description'=>wp_trim_words(wp_strip_all_tags($description),250,''),'product_count'=>$product_count,'proposed_labels'=>array_values($selected),'warnings'=>$warnings];
        $ai=apply_filters('seo_category_classification_ai_proposal',null,$payload);
        if(is_array($ai)&&!empty($ai['labels'])&&is_array($ai['labels'])) {
            $validated=[];
            foreach($ai['labels'] as $label) {
                $label=sanitize_text_field($label); $label_key=seo_cc_normalize_text($label);
                if(!seo_cc_candidate_valid($label)||!isset($items[$label_key])) continue;
                $validated[$label_key]=$label;
            }
            if($validated) $selected=$validated;
        }
        return ['labels'=>array_values($selected),'warnings'=>$warnings,'stats'=>['products'=>$product_count,'candidates'=>count($items)],'candidates'=>$items,'ai_payload'=>$payload];
    }
}

if (!function_exists('seo_cc_category_quality')) {
    function seo_cc_category_quality($cat_id,$hs_obj,$hp_obj,$cluster_obj) {
        $term=get_term(absint($cat_id),'product_cat'); if(!$term||is_wp_error($term)) return ['score'=>0,'label'=>'Sin datos','warnings'=>[]];
        $labels=seo_category_classification_node_text($cat_id,'category');
        $excerpt=seo_category_classification_node_text($cat_id,'excerpt');
        $description=seo_category_classification_node_text($cat_id,'description');
        $hierarchy=''; foreach([$hs_obj,$hp_obj,$cluster_obj] as $obj) if($obj) $hierarchy.=' '.$obj->post_title.' '.$obj->post_excerpt;
        $hier_tokens=array_flip(array_map('seo_cc_normalize_text',seo_cc_words($hierarchy,false)));
        $cat_tokens=seo_cc_words($term->name.' '.$labels.' '.$excerpt,false);
        $matched=0; foreach($cat_tokens as $token) if(isset($hier_tokens[seo_cc_normalize_text($token)])) $matched++;
        $hier_score=$cat_tokens?(int)round($matched/count($cat_tokens)*35):0;
        $score=$hier_score;
        if(trim($labels)!=='') $score+=20;
        if(trim($excerpt)!=='') $score+=15;
        if(mb_strlen(wp_strip_all_tags($description),'UTF-8')>=120) $score+=15;
        $products=seo_cc_product_ids($cat_id); if(count($products)>=2) $score+=15; elseif(count($products)===1) $score+=7;
        $score=min(100,$score); $warnings=[];
        if(trim($labels)==='') $warnings[]='Sin etiquetas de categoría.';
        if(trim($excerpt)==='') $warnings[]='Sin excerpt de categoría.';
        if($hier_score<8) $warnings[]='Poca conexión textual con el hub y el cluster actuales.';
        $label=$score>=75?'Alta':($score>=50?'Media':'Baja');
        return ['score'=>$score,'label'=>$label,'warnings'=>$warnings,'products'=>count($products)];
    }
}


// Crea etiquetas automáticas para categorías.

if (!function_exists('seo_build_auto_keywords_for_categories')) {
    function seo_build_auto_keywords_for_categories($cat_id, $limit = 8) {
        $result = seo_cc_build_keyword_proposal($cat_id, $limit);
        return implode(', ', $result['labels']);
    }
}

/**
 * Guarda etiquetas SEO de categorías
 * Tabla: wp_seo_nodes
 */
if (!function_exists('seo_save_category_keywords_value')) {
    function seo_save_category_keywords_value($cat_id, $keywords_value) {
        $cat_id = absint($cat_id);
        if ($cat_id <= 0 || !function_exists('seo_semantic_labels_save_object')) return false;
        try {
            seo_semantic_labels_save_object('category', $cat_id, 'category', $keywords_value, 'category_classification');
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

/**
 * ==========================
 * PRODUCT Classificacion de etiquetas
 * ==========================
 */
function seo_category_classification() {

    if (!current_user_can('manage_options')) return;

    global $wpdb;


$relations_table = $wpdb->prefix . 'seo_relations';
$attr_table      = $wpdb->prefix . 'seo_attributes';

if (!function_exists('seo_semantic_labels_handle_dashboard_action') || !function_exists('seo_semantic_labels_render_dashboard')) {
    echo '<div class="notice notice-error"><p>Falta la capa compartida de gestión semántica de etiquetas. Sustituye también product-classification.php por la versión entregada junto a este archivo.</p></div>';
    return;
}

$dashboard_notice = seo_semantic_labels_handle_dashboard_action('category', 'category');
if (is_array($dashboard_notice) && !empty($dashboard_notice['text'])) {
    $notice_class = ($dashboard_notice['type'] ?? '') === 'error' ? 'notice-error' : ((($dashboard_notice['type'] ?? '') === 'warning') ? 'notice-warning' : 'notice-success');
    echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($dashboard_notice['text']) . '</p></div>';
}

$mutating_actions = ['add_stopword','remove_keyword','save_category_keywords','clear_category_keywords'];
foreach ($mutating_actions as $mutating_action) {
    if (isset($_POST[$mutating_action])) {
        check_admin_referer('seo_category_classification_action','seo_category_classification_nonce');
        break;
    }
}


    
// =========================
// AGREGAR PALABRA BLOQUEANTE
// =========================
if (
    isset($_POST['add_stopword'])
    && $_POST['add_stopword'] == '1'
) {

    $word = wp_unslash($_POST['new_stopword'] ?? '');
    $word = sanitize_text_field(
        strtolower(
            trim($word)
        )
    );

    if ($word === '') {

        echo '<div class="notice notice-warning"><p>Introduce una palabra válida.</p></div>';

    } else {

        global $wpdb;

        $table_dict = $wpdb->prefix . 'seo_dictionari';

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(*)
                FROM {$table_dict}
                WHERE palabra = %s
                ",
                $word
            )
        );

        if ((int)$exists === 0) {

            $inserted = $wpdb->insert(
                $table_dict,
                [
                    'palabra'   => $word,
                    'puntuacion' => 0
                ],
                [
                    '%s',
                    '%d'
                ]
            );

            if ($inserted) {

                echo '<div class="notice notice-success"><p>Palabra bloqueante añadida correctamente: <strong>' . esc_html($word) . '</strong></p></div>';

            } else {

                echo '<div class="notice notice-error"><p>Error al insertar en la tabla seo_dictionari.</p></div>';

                error_log('ERROR INSERT STOPWORD: ' . $wpdb->last_error);
            }

        } else {

            echo '<div class="notice notice-warning"><p>La palabra ya existe en seo_dictionari.</p></div>';
        }
    }
}    



// =========================
// ELIMINAR ETIQUETA GLOBAL DE CATEGORÍAS
// =========================
if (isset($_POST['remove_keyword']) && $_POST['remove_keyword'] == '1' && !empty($_POST['delete_keyword'])) {
    $word = sanitize_text_field(trim(wp_unslash($_POST['delete_keyword'])));
    try {
        $result = seo_semantic_labels_delete_global('category', 'category', $word, 'category_classification');
        echo '<div class="notice notice-success"><p>Etiqueta eliminada de '
            . intval($result['objects'] ?? 0)
            . ' categorías mediante Data Layer. Operación #'
            . intval($result['operation_id'] ?? 0)
            . '; rollback disponible.</p></div>';
    } catch (Throwable $exception) {
        echo '<div class="notice notice-error"><p>No se ha eliminado ninguna etiqueta: '
            . esc_html($exception->getMessage())
            . '</p></div>';
    }
}

     // =========================
    // FILTROS
    // =========================
    $cluster        = intval($_POST['cluster'] ?? $_GET['cluster'] ?? 0);
    $hub_primario   = intval($_POST['hub_primario'] ?? $_GET['hub_primario'] ?? 0);
    $hub_secundario = intval($_POST['hub_secundario'] ?? $_GET['hub_secundario'] ?? 0);
    $cat            = intval($_POST['cat'] ?? $_GET['cat'] ?? 0);
    
    // =========================
    // CAMPOS DE SELECCION
    // =========================
    
    // CLUSTER
    $show_c_id      = isset($_POST['show_c_id']) || isset($_GET['show_c_id']);
    $show_c_slug    = isset($_POST['show_c_slug']) || isset($_GET['show_c_slug']);
    $show_c_excerpt = isset($_POST['show_c_excerpt']) || isset($_GET['show_c_excerpt']);
    $show_c_content = isset($_POST['show_c_content']) || isset($_GET['show_c_content']);
    
    // HUB PRIMARIO
    $show_hp_id      = isset($_POST['show_hp_id']) || isset($_GET['show_hp_id']);
    $show_hp_slug    = isset($_POST['show_hp_slug']) || isset($_GET['show_hp_slug']);
    $show_hp_excerpt = isset($_POST['show_hp_excerpt']) || isset($_GET['show_hp_excerpt']);
    $show_hp_content = isset($_POST['show_hp_content']) || isset($_GET['show_hp_content']);
    
    // HUB SECUNDARIO
    $show_hs_id      = isset($_POST['show_hs_id']) || isset($_GET['show_hs_id']);
    $show_hs_slug    = isset($_POST['show_hs_slug']) || isset($_GET['show_hs_slug']);
    $show_hs_excerpt = isset($_POST['show_hs_excerpt']) || isset($_GET['show_hs_excerpt']);
    $show_hs_content = isset($_POST['show_hs_content']) || isset($_GET['show_hs_content']);
    
    // CATEGORÍA
    $show_cat_id      = isset($_POST['show_cat_id']) || isset($_GET['show_cat_id']);
    $show_cat_slug    = isset($_POST['show_cat_slug']) || isset($_GET['show_cat_slug']);
    $show_cat_desc    = isset($_POST['show_cat_desc']) || isset($_GET['show_cat_desc']);
    $show_cat_excerpt = isset($_POST['show_cat_excerpt']) || isset($_GET['show_cat_excerpt']);
    $show_cat_tags    = isset($_POST['show_cat_tags']) || isset($_GET['show_cat_tags']);
    
    // PRODUCTO
    $show_p_id      = isset($_POST['show_p_id']) || isset($_GET['show_p_id']);
    $show_p_slug    = isset($_POST['show_p_slug']) || isset($_GET['show_p_slug']);
    $show_p_excerpt = isset($_POST['show_p_excerpt']) || isset($_GET['show_p_excerpt']);
    $show_p_content = isset($_POST['show_p_content']) || isset($_GET['show_p_content']);
    $show_p_tags    = isset($_POST['show_p_tags']) || isset($_GET['show_p_tags']);

    
    
    $run_inventory = isset($_POST['run_inventory']) && $_POST['run_inventory'] == 1;
    
    $auto_keywords = isset($_POST['auto_keywords']) && $_POST['auto_keywords'] == 1;
    

    // ==========================
    // SELECTS DINÁMICOS
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
    
    // Cache de etiquetas SEO activas. Si existen duplicados, conserva la fila
    // actualizada más recientemente para cada categoría.
    $category_keywords_cache = [];

    $seo_category_nodes = $wpdb->get_results("
        SELECT object_id, keywords
        FROM {$wpdb->prefix}seo_nodes
        WHERE object_type = 'category'
          AND seo_role = 'category'
          AND status = 1
        ORDER BY object_id ASC, updated_at DESC, id DESC
    ");

    foreach ($seo_category_nodes as $node) {
        $node_object_id = absint($node->object_id);

        if (!array_key_exists($node_object_id, $category_keywords_cache)) {
            $category_keywords_cache[$node_object_id] = (string) $node->keywords;
        }
    }
    

// =========================
// GUARDAR ETIQUETAS CATEGORÍAS
// =========================
if (isset($_POST['save_category_keywords'])) {

    if (!empty($_POST['category_keywords']) && is_array($_POST['category_keywords'])) {

        $saved_keywords = 0;
        $save_errors = [];

        foreach (wp_unslash($_POST['category_keywords']) as $cat_id => $keywords_value) {

            $cat_id = absint($cat_id);
            $keywords_value = sanitize_textarea_field($keywords_value);

            if ($cat_id <= 0) {
                continue;
            }

            try {
                seo_semantic_labels_save_object('category', $cat_id, 'category', $keywords_value, 'category_classification');
                $category_keywords_cache[$cat_id] = implode(', ', seo_semantic_labels_unique($keywords_value));
                $saved_keywords++;
            } catch (Throwable $exception) {
                $save_errors[] = sprintf(
                    'No se pudieron guardar las etiquetas de la categoría %d: %s',
                    $cat_id,
                    $exception->getMessage()
                );
            }
        }

        if ($saved_keywords > 0) {
            echo '<div class="notice notice-success"><p>Etiquetas de categorías guardadas correctamente: '
                . intval($saved_keywords)
                . '.</p></div>';
        }

        foreach ($save_errors as $save_error) {
            echo '<div class="notice notice-error"><p>'
                . esc_html($save_error)
                . '</p></div>';
        }

    } else {

        echo '<div class="notice notice-warning"><p>No hay etiquetas de categorías para guardar.</p></div>';
    }
}



// =========================
// BORRAR ETIQUETAS CATEGORÍA
// =========================
if (isset($_POST['clear_category_keywords'])) {
    $cat_id = absint($_POST['clear_category_keywords']);
    if ($cat_id > 0) {
        try {
            $result = seo_semantic_labels_clear_object('category', $cat_id, 'category', 'category_classification');
            unset($category_keywords_cache[$cat_id]);
            echo '<div class="notice notice-success"><p>Etiquetas SEO eliminadas mediante Data Layer. Filas: '
                . intval($result['deleted'] ?? 0)
                . '; operación #'
                . intval($result['operation_id'] ?? 0)
                . '. El excerpt, la descripción y el ámbito se han conservado.</p></div>';
        } catch (Throwable $exception) {
            echo '<div class="notice notice-error"><p>No se han podido borrar las etiquetas: '
                . esc_html($exception->getMessage())
                . '</p></div>';
        }
    }
}



    ob_start();
    ?>

<strong>Control de calidad:</strong> evalúa contenido editorial, etiquetas, volumen de productos y conexión con la jerarquía. Las propuestas se basan en señales compartidas por varios productos; una marca, modelo o ficha aislada no debe dominar toda la categoría.
</div>

<?php seo_semantic_labels_render_dashboard('category', 'category', 'Etiquetas de categorías'); ?>






        <form method="POST" style="margin-bottom:20px;padding:15px;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;">
            <?php wp_nonce_field('seo_category_classification_action','seo_category_classification_nonce'); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_key($_GET['page'] ?? 'seo-taxonomy')); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr(sanitize_key($_GET['tab'] ?? 'semantic')); ?>">
            <?php if (!empty($_GET['semantic_tab'])) : ?>
                <input type="hidden" name="semantic_tab" value="<?php echo esc_attr(sanitize_key($_GET['semantic_tab'])); ?>">
            <?php endif; ?>

            <select name="cluster" onchange="this.form.submit()">
                <option value="0">Cluster</option>
                <?php foreach ($cluster_ids as $id): ?>
                    <?php $p = get_post($id); ?>
                    <option value="<?php echo intval($id); ?>" <?php selected($cluster, $id); ?>>
                        <?php echo esc_html($p ? $p->post_title : "Cluster $id"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="hub_primario" onchange="this.form.submit()">
                <option value="0">Hub primario</option>
                <?php foreach ($hub_primarios_ids as $id): ?>
                    <?php $p = get_post($id); ?>
                    <option value="<?php echo intval($id); ?>" <?php selected($hub_primario, $id); ?>>
                        <?php echo esc_html($p ? $p->post_title : "HP $id"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="hub_secundario" onchange="this.form.submit()">
                <option value="0">Hub secundario</option>
                <?php foreach ($hub_secundarios_ids as $id): ?>
                    <?php $p = get_post($id); ?>
                    <option value="<?php echo intval($id); ?>" <?php selected($hub_secundario, $id); ?>>
                        <?php echo esc_html($p ? $p->post_title : "HS $id"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="cat" onchange="this.form.submit()">
                <option value="0">Categoría</option>
                <?php foreach ($all_cats as $c): ?>
                    <?php if (empty($category_ids_from_db) || in_array($c->term_id, $category_ids_from_db)): ?>
                        <option value="<?php echo intval($c->term_id); ?>" <?php selected($cat, $c->term_id); ?>>
                            <?php echo esc_html($c->name); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <div style="margin-top:15px;padding:12px;background:#eef1f3;border:1px solid #ccd0d4;border-radius:6px;">

                <strong>Cluster</strong><br>
                <input type="checkbox" name="show_c_id" <?php checked($show_c_id); ?>> ID
                <input type="checkbox" name="show_c_slug" <?php checked($show_c_slug); ?>> Slug
                <input type="checkbox" name="show_c_excerpt" <?php checked($show_c_excerpt); ?>> Excerpt
                <input type="checkbox" name="show_c_content" <?php checked($show_c_content); ?>> Descripción

                <br><br>

                <strong>Hub Primario</strong><br>
                <input type="checkbox" name="show_hp_id" <?php checked($show_hp_id); ?>> ID
                <input type="checkbox" name="show_hp_slug" <?php checked($show_hp_slug); ?>> Slug
                <input type="checkbox" name="show_hp_excerpt" <?php checked($show_hp_excerpt); ?>> Excerpt
                <input type="checkbox" name="show_hp_content" <?php checked($show_hp_content); ?>> Descripción

                <br><br>

                <strong>Hub Secundario</strong><br>
                <input type="checkbox" name="show_hs_id" <?php checked($show_hs_id); ?>> ID
                <input type="checkbox" name="show_hs_slug" <?php checked($show_hs_slug); ?>> Slug
                <input type="checkbox" name="show_hs_excerpt" <?php checked($show_hs_excerpt); ?>> Excerpt
                <input type="checkbox" name="show_hs_content" <?php checked($show_hs_content); ?>> Descripción

                <br><br>

                <strong>Categoría</strong><br>
                <input type="checkbox" name="show_cat_id" <?php checked($show_cat_id); ?>> ID
                <input type="checkbox" name="show_cat_slug" <?php checked($show_cat_slug); ?>> Slug
                <input type="checkbox" name="show_cat_desc" <?php checked($show_cat_desc); ?>> Descripción
                <input type="checkbox" name="show_cat_excerpt" <?php checked($show_cat_excerpt); ?>> Excerpt
                <input type="checkbox" name="show_cat_tags" <?php checked($show_cat_tags); ?>> Etiquetas


                <br><br>

                <strong>Producto</strong><br>
                <input type="checkbox" name="show_p_id" <?php checked($show_p_id); ?>> ID
                <input type="checkbox" name="show_p_slug" <?php checked($show_p_slug); ?>> Slug
                <input type="checkbox" name="show_p_excerpt" <?php checked($show_p_excerpt); ?>> Excerpt
                <input type="checkbox" name="show_p_content" <?php checked($show_p_content); ?>> Descripción
                <input type="checkbox" name="show_p_tags" <?php checked($show_p_tags); ?>> Etiquetas

            </div>

            <br>
<button type="submit" name="run_inventory" value="1">
    Inventario
</button>

<button type="submit" name="auto_keywords" value="1" class="button">
    Proponer etiquetas automáticamente
</button>
<button type="submit" name="save_category_keywords" value="1" class="button button-primary">
    Guardar etiquetas categorías
</button>

<br><br>

<input type="text" name="new_stopword" placeholder="Palabra bloqueante" style="margin-left:10px;">
<button type="submit" name="add_stopword" value="1" class="button">
    Agregar palabra bloqueante
</button>

<input type="text" name="delete_keyword" placeholder="Eliminar etiqueta" style="margin-left:10px;">
<button type="submit" name="remove_keyword" value="1" class="button" onclick="return confirm('¿Eliminar esta etiqueta de todos los objetos donde esté asignada? La acción será reversible mediante Data Layer.');">
    Borrar etiqueta en uso
</button>



<?php
//Saco de aqui debtro la funcion seo build auto keywords for categories


// =========================
// BLOQUEO DE EJECUCIÓN
// =========================
    if ($cluster <= 0) {
        echo ob_get_clean();
        echo '<p>Selecciona un cluster.</p>';
        return;
    }
    // =========================
    // RESOLVER LABELS
    // =========================
    $resolve = function($id, $fallback = 'Elemento') {
        $post = get_post($id);
        if ($post) return $post->post_title;

        $term = get_term($id, 'product_cat');
        if ($term && !is_wp_error($term)) return $term->name;

        return $fallback . " $id";
    };


if ($run_inventory || $auto_keywords) {

    // =========================
    // RECORRIDO JERÁRQUICO
    // =========================

    $hub_primarios = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM $relations_table
        WHERE source_id = %d
        AND relation_type = 'cluster_to_primary'
    ", $cluster));

    foreach ($hub_primarios as $hp_id) {

        if ($hub_primario && intval($hp_id) !== intval($hub_primario)) continue;

        // =========================
        // CLUSTER
        // =========================
        $cluster_obj = get_post($cluster);

        echo '<div style="border:2px solid #1d2327;border-radius:6px;margin-bottom:20px;padding:15px;background:#f0f0f1;">';
        echo '<h2 style="margin:0 0 10px 0;">' . esc_html($resolve($cluster)) . '</h2>';

        if ($cluster_obj) {

            echo '<div style="font-size:12px;color:#666;margin-bottom:10px;">';

            if ($show_c_id) {
                echo '<div><strong>ID:</strong> ' . intval($cluster_obj->ID) . '</div>';
            }

            if ($show_c_slug) {
                echo '<div><strong>Slug:</strong> ' . esc_html($cluster_obj->post_name) . '</div>';
            }

            if ($show_c_excerpt && !empty($cluster_obj->post_excerpt)) {
                echo '<div><strong>Excerpt:</strong> ' . esc_html($cluster_obj->post_excerpt) . '</div>';
            }

            if ($show_c_content && !empty($cluster_obj->post_content)) {
                echo '<div><strong>Descripción:</strong> ' . esc_html(wp_trim_words(strip_tags($cluster_obj->post_content), 25)) . '</div>';
            }

            echo '</div>';
        }

        echo '</div>';

        // =========================
        // HUB PRIMARIO
        // =========================
        echo '<div style="border-left:5px solid #2271b1;margin:15px 0 15px 10px;padding:12px;background:#f6f7f7;">';
        echo '<h3 style="margin:0 0 8px 0;">' . esc_html($resolve($hp_id)) . '</h3>';

        $hp_obj = get_post($hp_id);

        if ($hp_obj) {

            echo '<div style="margin-left:10px;font-size:12px;color:#666;">';

            if ($show_hp_id) {
                echo '<div><strong>ID:</strong> ' . intval($hp_obj->ID) . '</div>';
            }

            if ($show_hp_slug) {
                echo '<div><strong>Slug:</strong> ' . esc_html($hp_obj->post_name) . '</div>';
            }

            if ($show_hp_excerpt && !empty($hp_obj->post_excerpt)) {
                echo '<div><strong>Excerpt:</strong> ' . esc_html($hp_obj->post_excerpt) . '</div>';
            }

            if ($show_hp_content && !empty($hp_obj->post_content)) {
                echo '<div><strong>Descripción:</strong> ' . esc_html(wp_trim_words(strip_tags($hp_obj->post_content), 25)) . '</div>';
            }

            echo '</div>';
        }

        echo '</div>';

        $hub_secundarios = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT target_id
            FROM $relations_table
            WHERE source_id = %d
            AND relation_type = 'hub_primary_to_hub_secondary'
        ", $hp_id));

        foreach ($hub_secundarios as $hs_id) {

            if ($hub_secundario && intval($hs_id) !== intval($hub_secundario)) continue;

            // =========================
            // HUB SECUNDARIO
            // =========================
            echo '<div style="border-left:4px solid #72aee6;margin:10px 0 10px 20px;padding:10px;background:#ffffff;">';
            echo '<h4 style="margin:0 0 6px 0;">' . esc_html($resolve($hs_id)) . '</h4>';

            $hs_obj = get_post($hs_id);

            if ($hs_obj) {

                echo '<div style="margin-left:20px;font-size:13px;color:#666;">';

                if ($show_hs_id) {
                    echo '<div><strong>ID:</strong> ' . intval($hs_obj->ID) . '</div>';
                }

                if ($show_hs_slug) {
                    echo '<div><strong>Slug:</strong> ' . esc_html($hs_obj->post_name) . '</div>';
                }

                if ($show_hs_excerpt && !empty($hs_obj->post_excerpt)) {
                    echo '<div><strong>Excerpt:</strong> ' . esc_html($hs_obj->post_excerpt) . '</div>';
                }

                if ($show_hs_content && !empty($hs_obj->post_content)) {
                    echo '<div><strong>Descripción:</strong> ' . esc_html(wp_trim_words(strip_tags($hs_obj->post_content), 25)) . '</div>';
                }
                
                

                echo '</div>';
            }

            echo '</div>';

            $cats = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT target_id
                FROM $relations_table
                WHERE source_id = %d
                AND relation_type = 'hub_secondary_to_category'
            ", $hs_id));

 
            foreach ($cats as $cat_id) {

    if ($cat && intval($cat_id) !== intval($cat)) {
        continue;
    }

    $term = get_term($cat_id, 'product_cat');
    $cat_name = ($term && !is_wp_error($term)) ? $term->name : "Cat $cat_id";

    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE p.post_type = 'product'
        AND tt.taxonomy = 'product_cat'
        AND tt.term_id = %d
    ", $cat_id));

    echo '<div style="margin:10px 0 10px 40px;padding:10px;border:1px solid #dcdcde;border-radius:6px;background:#fcfcfc;">';

    echo '<strong>' . esc_html($cat_name) . '</strong>';
    echo ' → ' . intval($count) . ' productos';

    $quality = seo_cc_category_quality($cat_id, $hs_obj, $hp_obj, $cluster_obj);
    $quality_color = $quality['score'] >= 75 ? '#00a32a' : ($quality['score'] >= 50 ? '#dba617' : '#d63638');
    echo '<div style="margin:8px 0;padding:8px;background:#f6f7f7;border-left:4px solid ' . esc_attr($quality_color) . ';">';
    echo '<strong>Calidad de clasificación:</strong> ' . intval($quality['score']) . '/100 (' . esc_html($quality['label']) . ')';
    foreach ($quality['warnings'] as $warning) echo '<div style="color:#646970;">' . esc_html($warning) . '</div>';
    echo '</div>';

    echo '<button
        type="submit"
        name="clear_category_keywords"
        value="' . intval($cat_id) . '"
        class="button button-secondary"
        style="margin-left:10px;"
        onclick="return confirm(\'¿Eliminar etiquetas SEO de esta categoría?\');"
    >
        Borrar etiquetas
    </button>';

    if ($show_cat_id) {
        echo '<div><strong>ID:</strong> ' . $term->term_id . '</div>';
    }

    if ($show_cat_slug) {
        echo '<div><strong>Slug:</strong> ' . $term->slug . '</div>';
    }

    if ($show_cat_desc) {
    
        $cat_description = seo_category_classification_node_text($cat_id, 'description');
    
        echo '<br>Descripción: ' .
             esc_html(wp_trim_words(wp_strip_all_tags($cat_description), 25)) .
             '</br>';
    }

        if ($show_cat_excerpt) {
        
            $cat_excerpt = seo_category_classification_node_text($cat_id, 'excerpt');
        
            echo '<div><strong>Excerpt:</strong> '
                . esc_html($cat_excerpt)
                . '</div>';
        }

    if ($show_cat_tags) {

        $cat_keywords = $category_keywords_cache[$cat_id] ?? '';
        $category_proposal = null;
        if ($auto_keywords) {
            $category_proposal = seo_cc_build_keyword_proposal($cat_id, 8);
            $cat_keywords = implode(', ', $category_proposal['labels']);
        }

        echo '<div><strong>Etiquetas SEO:</strong> '
            . esc_html($cat_keywords)
            . '</div>';
        if (is_array($category_proposal) && !empty($category_proposal['warnings'])) {
            echo '<div style="color:#996800;">' . esc_html(implode(' ', $category_proposal['warnings'])) . '</div>';
        }

        echo '<input
            type="text"
            name="category_keywords[' . intval($cat_id) . ']"
            value="' . esc_attr($cat_keywords) . '"
            style="width:100%;max-width:900px;"
        >';
    }

    echo '</div>';
 
 
                
            if ($show_p_id || $show_p_slug || $show_p_excerpt || $show_p_content || $show_p_tags) {
                   //Muestra los productos y sus etiquetas 
                    $products = get_posts([
                        'post_type'      => 'product',
                        'post_status'    => ['publish','draft','pending','private'],
                        'posts_per_page' => -1,
                        'orderby'        => 'title',
                        'order'          => 'ASC',
                        'tax_query'      => [
                            [
                                'taxonomy' => 'product_cat',
                                'field'    => 'term_id',
                                'terms'    => $cat_id
                            ]
                        ]
                    ]);
                    foreach ($products as $p) {
                        
                            echo '<div style="
                                margin:10px 0 10px 60px;
                                padding:10px;
                                background:#fff;
                                border:1px solid #ddd;
                            ">';
                        
                            echo '<strong>' . esc_html($p->post_title) . '</strong>';
                        
                            if ($show_p_id) {
                                echo '<div>ID: '.intval($p->ID).'</div>';
                            }
                        
                            if ($show_p_slug) {
                                echo '<div>Slug: '.esc_html($p->post_name).'</div>';
                            }
                        
                            if ($show_p_excerpt) {
                                echo '<div>Excerpt: '.esc_html($p->post_excerpt).'</div>';
                            }
                        
                            if ($show_p_content) {
                                echo '<div>Descripción: '.esc_html(
                                    wp_trim_words(
                                        strip_tags($p->post_content),
                                        30
                                    )
                                ).'</div>';
                            }
                        
                            $category_keywords = seo_category_classification_node_text($cat_id, 'category');
                        
                            echo '<div style="margin-top:8px;">';
                            echo '<strong>Etiquetas SEO:</strong> ';
                            echo esc_html($category_keywords);
                            echo '</div>';
                            
                            
    
                        
                        echo '</div>';
    
                    } // foreach products
    
                } // foreach cats
            }

        } // foreach hub secundarios

    } // foreach hub primarios

echo '</form>';

    
} // run inventory



echo ob_get_clean();

} // seo_product_classification