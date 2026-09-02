<?php
if (!defined('ABSPATH')) { exit; }
if (!defined('SEO_GROWTH_EXECUTIVE_VERSION')) define('SEO_GROWTH_EXECUTIVE_VERSION', '2.0.0');

/**
 * Informe ejecutivo independiente de la capa Google.
 * Consume las recomendaciones estructuradas de Demanda x Catalogo + Trends,
 * pero no sincroniza ni modifica productos/categorias.
 */



function seo_growth_exec_dimension_text($labels) {
    if (!is_array($labels) || empty($labels)) return '';
    $out = array();
    foreach ($labels as $label) {
        if (is_array($label)) {
            if (!empty($label['label'])) $out[] = (string)$label['label'];
        } elseif (is_scalar($label)) {
            $out[] = (string)$label;
        }
    }
    return implode(' · ', array_slice(array_values(array_unique(array_filter($out))), 0, 6));
}

function seo_growth_exec_tokens($text) {
    $text = remove_accents(mb_strtolower(wp_strip_all_tags((string)$text), 'UTF-8'));
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    $stop = array('de','del','la','las','el','los','y','para','con','sin','en','por','un','una','unos','unas','online','profesional','profesionales');
    $tokens = array();
    foreach (preg_split('/\\s+/', trim($text)) as $t) {
        if ($t === '' || strlen($t) < 3 || in_array($t, $stop, true)) continue;
        $tokens[] = $t;
    }
    return array_values(array_unique($tokens));
}

function seo_growth_exec_mapping_is_reliable($item) {
    if (empty($item['suggested_category']) || !is_array($item['suggested_category'])) return false;
    $score = isset($item['suggested_category']['score']) ? (float)$item['suggested_category']['score'] : 0.0;
    if ($score < 0.82) return false;
    $a = seo_growth_exec_tokens($item['label'] ?? '');
    $b = seo_growth_exec_tokens($item['suggested_category']['name'] ?? '');
    $shared = array_intersect($a, $b);
    // Una sola palabra generica como "manual" o "corte" no basta para ubicar una intencion.
    $generic = array('herramienta','herramientas','accesorio','accesorios','manual','manuales','equipo','equipos','equipamiento','profesional','profesionales','industrial','industriales','corte','taller','transporte');
    $specific_shared = array_values(array_filter($shared, function($t) use ($generic){ return !in_array($t, $generic, true); }));
    if (count($specific_shared) >= 1 && count($shared) >= 2) return true;
    if (count($shared) >= 2 && $score >= 0.90) return true;
    return false;
}

function seo_growth_exec_is_broad_intent($label) {
    $tokens = seo_growth_exec_tokens($label);
    if (empty($tokens)) return true;
    $specific = array('nevera','camion','cinta','transportadora','torneador','cepilladora','remolque','baliza','compresor','rampa','ultrasonido','ultrasonidos','4x4','gato','hidraulico','soldadura','osciloscopio','candado','cofre');
    foreach ($tokens as $t) if (in_array($t, $specific, true)) return false;
    $broad = array('herramienta','herramientas','accesorio','accesorios','equipamiento','equipo','equipos','taller','transporte','suministro','maquinaria');
    $hits = 0;
    foreach ($tokens as $t) if (in_array($t, $broad, true)) $hits++;
    return $hits >= 1 && count($tokens) <= 4;
}

function seo_growth_exec_context_for_term($term_id) {
    global $wpdb;
    $term_id = absint($term_id);
    $ctx = array('category_id'=>$term_id,'category'=>'','secondary_id'=>0,'secondary'=>'','primary_id'=>0,'primary'=>'','cluster_id'=>0,'cluster'=>'');
    if (!$term_id) return $ctx;
    $term = get_term($term_id, 'product_cat');
    if ($term && !is_wp_error($term)) $ctx['category'] = $term->name;

    $rel = $wpdb->get_row($wpdb->prepare(
        "SELECT source_id FROM {$wpdb->prefix}seo_relations
         WHERE source_type='hub_secondary' AND target_type='product_cat'
           AND target_id=%d
         ORDER BY id ASC LIMIT 1", $term_id
    ));
    if (!$rel) return $ctx;
    $ctx['secondary_id'] = (int)$rel->source_id;
    $ctx['secondary'] = (string)get_the_title($ctx['secondary_id']);

    $parent = $wpdb->get_row($wpdb->prepare(
        "SELECT source_id FROM {$wpdb->prefix}seo_relations
         WHERE source_type='hub_primary' AND target_type='hub_secondary'
           AND target_id=%d AND relation_type='hub_primary_to_hub_secondary'
         ORDER BY id ASC LIMIT 1", $ctx['secondary_id']
    ));
    if (!$parent) return $ctx;
    $ctx['primary_id'] = (int)$parent->source_id;
    $ctx['primary'] = (string)get_the_title($ctx['primary_id']);

    $cluster = $wpdb->get_row($wpdb->prepare(
        "SELECT source_id FROM {$wpdb->prefix}seo_relations
         WHERE source_type='cluster' AND target_type='hub_primary'
           AND target_id=%d AND relation_type='cluster_to_primary'
         ORDER BY id ASC LIMIT 1", $ctx['primary_id']
    ));
    if ($cluster) {
        $ctx['cluster_id'] = (int)$cluster->source_id;
        $ctx['cluster'] = (string)get_the_title($ctx['cluster_id']);
    }
    return $ctx;
}

function seo_growth_exec_resolve_term_id($item) {
    if (!empty($item['term_id'])) return absint($item['term_id']);
    if (!empty($item['suggested_category']['term_id']) && seo_growth_exec_mapping_is_reliable($item)) return absint($item['suggested_category']['term_id']);
    return 0;
}

function seo_growth_exec_action_label($row, $trend_count = null) {
    $item = $row['item'];
    $decision = $row['decision'];
    $strategy = isset($item['strategy']['primary']) ? $item['strategy']['primary'] : 'VALIDAR';
    $products = isset($item['products']) && $item['products'] !== null ? (int)$item['products'] : null;
    $market = (int)($decision['market_score'] ?? 0);
    $search = (int)($decision['search_score'] ?? 0);
    $has_trends = $trend_count === null ? ($market > 0 || !empty($row['trend'])) : ((int)$trend_count > 0);
    $broad = seo_growth_exec_is_broad_intent($item['label'] ?? '');
    $mapping_ok = seo_growth_exec_mapping_is_reliable($item);

    if ($strategy === 'PROFUNDIDAD') {
        return array('code'=>'ADD_VARIETY','label'=>'AGREGAR VARIEDAD','level'=>'Categoría','supplier'=>'Buscar más variantes / referencias');
    }
    if ($strategy === 'AMPLITUD') {
        return array('code'=>'EXPAND_NEARBY','label'=>'AUMENTAR CATEGORÍAS','level'=>'Hub secundario','supplier'=>'Buscar familias complementarias');
    }
    if ($strategy === 'NUEVA_FAMILIA') {
        if ($broad) return array('code'=>'DECOMPOSE','label'=>'DESCOMPONER DEMANDA','level'=>'Área / intención','supplier'=>'No buscar proveedor aún; identificar subfamilias concretas');
        if (!$has_trends) return array('code'=>'INVESTIGATE_NEW','label'=>'INVESTIGAR NUEVA CATEGORÍA','level'=>'Nueva categoría candidata','supplier'=>'Validar con Trends antes de buscar proveedor');
        return array('code'=>'NEW_CATEGORY','label'=>'CREAR / PROBAR CATEGORÍA','level'=>'Nueva categoría','supplier'=>'Buscar proveedor de esta nueva familia');
    }
    if ($strategy === 'MAPEO') {
        if (!$mapping_ok) return array('code'=>'VALIDATE_MAPPING','label'=>'VALIDAR ENCAJE','level'=>'Área / categoría','supplier'=>'No buscar proveedor aún; corregir o confirmar ubicación');
        return array('code'=>'MAP_THEN_GROW','label'=>'MAPEAR Y AMPLIAR','level'=>'Categoría / Hub','supplier'=>'Validar encaje y buscar huecos');
    }
    if ($strategy === 'SEO' || ($products !== null && $products >= 40 && $market < 70)) {
        return array('code'=>'SEO_ONLY','label'=>'POTENCIAR SEO','level'=>'Categoría','supplier'=>'No buscar proveedor todavía');
    }
    if ($products !== null && $products <= 5 && ($search >= 55 || $market >= 55)) {
        return array('code'=>'ADD_PRODUCTS','label'=>'AGREGAR PRODUCTOS','level'=>'Categoría','supplier'=>'Buscar más referencias de la familia');
    }
    return array('code'=>'WATCH','label'=>'VALIDAR ANTES DE AMPLIAR','level'=>'Oportunidad','supplier'=>'No buscar proveedor todavía');
}

function seo_growth_exec_build_rows($days=60, $limit=60) {
    /*
     * Adaptador V2 para consumidores existentes, incluido el asistente de
     * proveedores. Solo expone acciones que implican investigar o ampliar
     * catálogo; una recomendación SEO nunca se convierte en compra de surtido.
     */
    if (function_exists('seo_google_opportunity_build')) {
        $days = in_array((int) $days, array(28, 60, 90), true) ? (int) $days : 60;
        $limit = max(10, min(100, absint($limit)));
        $payload = (array) seo_google_opportunity_build($days, false);
        $allowed_actions = array(
            'AMPLIAR_PRODUCTOS',
            'INVESTIGAR_PRODUCTO',
            'ESTUDIAR_CATEGORIA',
            'INVESTIGAR_CATALOGO',
        );
        $rows = array();

        foreach ((array) ($payload['rows'] ?? array()) as $opportunity) {
            $action_code = (string) ($opportunity['action'] ?? '');
            if (!in_array($action_code, $allowed_actions, true)) {
                continue;
            }

            $topic = sanitize_text_field((string) ($opportunity['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }

            $catalog = is_array($opportunity['catalog'] ?? null) ? $opportunity['catalog'] : array();
            $metrics = is_array($opportunity['metrics'] ?? null) ? $opportunity['metrics'] : array();
            $market = is_array($opportunity['market'] ?? null) ? $opportunity['market'] : array();
            $category = sanitize_text_field((string) ($catalog['category'] ?? ''));
            $product_count = isset($catalog['products']) ? (int) $catalog['products'] : null;

            $rows[] = array(
                'item' => array(
                    'label'            => $topic,
                    'strategy'         => array('primary' => $action_code),
                    'dimension_labels' => array_values(array_filter(array($category))),
                    'products'         => $product_count,
                    'impressions'      => (float) ($metrics['impressions'] ?? 0),
                    'position'         => (float) ($metrics['position'] ?? 0),
                ),
                'decision' => array(
                    'priority'     => (int) ($opportunity['priority'] ?? 0),
                    'confidence'   => (string) ($opportunity['confidence'] ?? 'MEDIA'),
                    'market_score' => (int) round((float) ($market['score'] ?? $metrics['market_score'] ?? 0)),
                    'search_score' => (int) round((float) ($metrics['search_score'] ?? 0)),
                    'catalog_gap'  => $product_count === null ? 50 : max(0, min(100, 100 - min(100, $product_count * 8))),
                    'seo_need'     => 0,
                ),
                'exec_action' => array(
                    'code'     => $action_code,
                    'label'    => (string) ($opportunity['action_label'] ?? $action_code),
                    'level'    => 'Catálogo',
                    'supplier' => (string) ($opportunity['reason'] ?? 'Validar la oportunidad antes de ampliar surtido.'),
                ),
                'context' => array(
                    'category_id' => absint($catalog['term_id'] ?? 0),
                    'category'    => $category,
                    'secondary_id'=> 0,
                    'secondary'   => '',
                    'primary_id'  => 0,
                    'primary'     => '',
                    'cluster_id'  => 0,
                    'cluster'     => '',
                ),
                'trend' => $market,
            );

            if (count($rows) >= $limit) {
                break;
            }
        }

        return array(
            'rows'           => $rows,
            'hub_promotions' => array(),
            'source_ready'   => true,
            'engine'         => 'v2',
        );
    }

    $trend_count = function_exists('seo_google_trends_get_signals') ? count(seo_google_trends_get_signals(5000)) : 0;
    if (!function_exists('seo_google_growth_get_guidance')) {
        return array('rows'=>array(),'hub_promotions'=>array(),'source_ready'=>false);
    }
    $raw = seo_google_growth_get_guidance($days, max(40,$limit));
    $rows = array();
    foreach ((array)$raw as $row) {
        $item = $row['item'];
        if (($item['catalog_relevance'] ?? 'catalog') === 'corporate') continue;
        $action = seo_growth_exec_action_label($row, $trend_count);
        $term_id = seo_growth_exec_resolve_term_id($item);
        $ctx = seo_growth_exec_context_for_term($term_id);
        $row['exec_action'] = $action;
        $row['context'] = $ctx;
        $rows[] = $row;
    }

    usort($rows, function($a,$b){ return (int)$b['decision']['priority'] <=> (int)$a['decision']['priority']; });
    $rows = array_slice($rows,0,max(10,min(100,$limit)));

    // Eleva a Hub secundario cuando varias oportunidades distintas convergen.
    $by_hub = array();
    foreach ($rows as $row) {
        $ctx = $row['context'];
        if (empty($ctx['secondary_id'])) continue;
        $code = $row['exec_action']['code'];
        if (in_array($code,array('SEO_ONLY','WATCH','DECOMPOSE','INVESTIGATE_NEW','VALIDATE_MAPPING'),true)) continue;
        $hid = (int)$ctx['secondary_id'];
        if (!isset($by_hub[$hid])) $by_hub[$hid] = array('id'=>$hid,'name'=>$ctx['secondary'],'primary'=>$ctx['primary'],'cluster'=>$ctx['cluster'],'count'=>0,'score'=>0,'items'=>array());
        $by_hub[$hid]['count']++;
        $by_hub[$hid]['score'] += (int)$row['decision']['priority'];
        $by_hub[$hid]['items'][] = $item = $row['item']['label'];
    }
    $hub_promotions = array();
    foreach ($by_hub as $hub) {
        if ($hub['count'] < 3) continue;
        $hub['priority'] = min(100,(int)round($hub['score']/$hub['count'] + min(12,($hub['count']-2)*3)));
        $hub['items'] = array_slice(array_values(array_unique($hub['items'])),0,6);
        $hub_promotions[] = $hub;
    }
    usort($hub_promotions,function($a,$b){return $b['priority']<=>$a['priority'];});
    return array('rows'=>$rows,'hub_promotions'=>$hub_promotions,'source_ready'=>true);
}

function seo_growth_exec_bar($value, $label='') {
    $value=max(0,min(100,(int)$value));
    return '<div style="min-width:130px"><div style="display:flex;justify-content:space-between;font-size:11px;color:#646970;margin-bottom:3px"><span>'.esc_html($label).'</span><strong>'.$value.'</strong></div><div style="height:8px;background:#e9ecef;border-radius:999px;overflow:hidden"><span style="display:block;height:100%;width:'.$value.'%;background:#2271b1"></span></div></div>';
}

function seo_growth_exec_summary_counts($rows) {
    $counts=array('ADD_VARIETY'=>0,'ADD_PRODUCTS'=>0,'NEW_CATEGORY'=>0,'INVESTIGATE_NEW'=>0,'DECOMPOSE'=>0,'EXPAND_NEARBY'=>0,'SEO_ONLY'=>0,'MAP_THEN_GROW'=>0,'VALIDATE_MAPPING'=>0);
    foreach($rows as $r){$c=$r['exec_action']['code'];if(isset($counts[$c]))$counts[$c]++;}
    return $counts;
}

function seo_render_growth_executive_report() {
    if (function_exists('seo_google_opportunity_render')) {
        $days = isset($_GET['growth_exec_days']) ? absint($_GET['growth_exec_days']) : 60;
        $days = in_array($days, array(28, 60, 90), true) ? $days : 60;
        seo_google_opportunity_render('actions', $days);
        return;
    }

    $days=isset($_GET['growth_exec_days'])?absint($_GET['growth_exec_days']):60;
    $days=in_array($days,array(28,60,90),true)?$days:60;
    $payload=seo_growth_exec_build_rows($days,60);
    $rows=$payload['rows'];
    $hubs=$payload['hub_promotions'];
    $counts=seo_growth_exec_summary_counts($rows);
    $trend_count=function_exists('seo_google_trends_get_signals')?count(seo_google_trends_get_signals(5000)):0;

    echo '<style>
    .seo-growth-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin:16px 0 22px}.seo-growth-kpi{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-growth-kpi b{font-size:27px;display:block;margin-top:4px}.seo-growth-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:18px}@media(max-width:1100px){.seo-growth-grid{grid-template-columns:1fr}}.seo-growth-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:18px;margin-bottom:18px}.seo-growth-decision{border-left:5px solid #2271b1;padding:13px 14px;margin:0 0 10px;background:#fff}.seo-growth-pill{display:inline-block;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:700;background:#eef4fb;color:#135e96}.seo-growth-meta{color:#646970;font-size:12px;line-height:1.55}.seo-growth-score{font-size:24px;font-weight:700;min-width:65px}.seo-growth-mini{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.seo-growth-action{font-size:13px;font-weight:700}.seo-growth-table td{vertical-align:top}.seo-growth-hub{border:1px solid #dcdcde;padding:12px;border-radius:6px;margin:8px 0;background:#fcfcfc}
    </style>';

    echo '<div class="seo-growth-card"><div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="margin:0 0 5px">Qué potenciar</h2><p style="margin:0;max-width:820px">Panel ejecutivo para decidir <strong>qué proveedor buscar, dónde ampliar surtido y cuándo ampliar la estructura</strong>. Resume catálogo + Search Console + Google Trends; las pantallas de Google quedan como evidencia técnica.</p><p class="seo-growth-meta">V'.esc_html(SEO_GROWTH_EXECUTIVE_VERSION).' · Horizonte Search Console: '.absint($days).' días · Señales Trends almacenadas: '.number_format_i18n($trend_count).'</p></div><form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="growth_executive"><label><strong>Horizonte</strong> <select name="growth_exec_days">';
    foreach(array(28,60,90) as $d) echo '<option value="'.$d.'" '.selected($days,$d,false).'>'.$d.' días</option>';
    echo '</select></label> '; submit_button('Actualizar','secondary','submit',false); echo '</form></div></div>';

    if(!$payload['source_ready']){
        echo '<div class="notice notice-warning inline"><p>No está disponible el motor <code>seo_google_growth_get_guidance()</code>. Verifica los archivos de Google Intelligence.</p></div>'; return;
    }
    if(!$trend_count){echo '<div class="notice notice-warning inline"><p><strong>Sin Trends importado:</strong> Search Console + catálogo generan candidatos, pero no se autoriza crear categorías nuevas ni buscar proveedores de nuevas familias hasta que Trends aporte confirmación externa.</p></div>';}

    echo '<div class="seo-growth-kpis">';
    $cards=array(
        array('Agregar variedad',$counts['ADD_VARIETY'],'Más versiones dentro de categorías existentes'),
        array('Agregar productos',$counts['ADD_PRODUCTS'],'Categorías con poca profundidad'),
        array('Categorías candidatas',$counts['NEW_CATEGORY']+$counts['INVESTIGATE_NEW'],'Crear solo cuando Trends confirme'),
        array('Ampliar hubs',$counts['EXPAND_NEARBY']+count($hubs),'Oportunidades horizontales / convergentes'),
        array('SEO, no surtido',$counts['SEO_ONLY'],'No comprar más antes de posicionar'),
    );
    foreach($cards as $c) echo '<div class="seo-growth-kpi"><span class="seo-growth-meta">'.esc_html($c[0]).'</span><b>'.number_format_i18n($c[1]).'</b><span class="seo-growth-meta">'.esc_html($c[2]).'</span></div>';
    echo '</div>';

    echo '<div class="seo-growth-grid"><div>';
    echo '<div class="seo-growth-card"><h3 style="margin-top:0">Decisiones recomendadas ahora</h3><p class="seo-growth-meta">Ordenadas por prioridad. Mercado y Search son señales distintas; cobertura estima cuánto hueco existe en tu catálogo actual.</p>';
    foreach(array_slice($rows,0,12) as $r){
        $i=$r['item'];$d=$r['decision'];$a=$r['exec_action'];$ctx=$r['context'];
        $dirs_text=seo_growth_exec_dimension_text($i['dimension_labels']??array());
        $where=$ctx['category']?:($ctx['secondary']?:'Sin ubicación estructural clara');
        echo '<div class="seo-growth-decision"><div style="display:flex;gap:14px;align-items:flex-start"><div class="seo-growth-score">'.absint($d['priority']).'<span style="font-size:12px;font-weight:400">/100</span></div><div style="flex:1"><div><span class="seo-growth-pill">'.esc_html($a['label']).'</span> <strong style="font-size:15px">'.esc_html($i['label']).'</strong></div><div class="seo-growth-meta" style="margin-top:5px">Nivel sugerido: <strong>'.esc_html($a['level']).'</strong> · Encaje actual: '.esc_html($where).'</div>';
        if($dirs_text!=='') echo '<div style="margin-top:6px"><strong>Dirección:</strong> '.esc_html($dirs_text).'</div>'; else echo '<div style="margin-top:6px"><strong>Dirección:</strong> descomponer consultas y familias relacionadas antes de comprar.</div>';
        echo '<div class="seo-growth-mini">'.seo_growth_exec_bar($d['search_score'],'Search').seo_growth_exec_bar($d['market_score'],'Trends').seo_growth_exec_bar($d['catalog_gap'],'Hueco catálogo').seo_growth_exec_bar($d['seo_need'],'Necesidad SEO').'</div>';
        echo '<div class="seo-growth-meta" style="margin-top:8px"><strong>Proveedor:</strong> '.esc_html($a['supplier']).' · Confianza: '.esc_html($d['confidence']).'</div>';
        if(!empty($i['impressions'])) echo '<div class="seo-growth-meta">Search Console: '.number_format_i18n((float)$i['impressions'],0).' impresiones · posición '.($i['position']?number_format_i18n((float)$i['position'],1):'—').'.</div>';
        if(!empty($r['trend'])) echo '<div class="seo-growth-meta">Trends relacionado: '.esc_html($r['trend']['query']).(!empty($r['trend']['breakout'])?' · BREAKOUT':(!empty($r['trend']['max_growth'])?' · +'.number_format_i18n((float)$r['trend']['max_growth'],0).'%':'')).'.</div>';
        echo '</div></div></div>';
    }
    echo '</div>';

    echo '<div class="seo-growth-card"><h3 style="margin-top:0">Lista operativa para búsqueda de proveedores</h3><table class="widefat striped seo-growth-table"><thead><tr><th>Prioridad</th><th>Necesidad</th><th>Decisión</th><th>Dónde encaja</th><th>Qué buscar</th></tr></thead><tbody>';
    foreach(array_slice(array_values(array_filter($rows,function($r){return !in_array($r['exec_action']['code'],array('SEO_ONLY','WATCH','DECOMPOSE','INVESTIGATE_NEW','VALIDATE_MAPPING'),true);})),0,20) as $r){$i=$r['item'];$d=$r['decision'];$a=$r['exec_action'];$c=$r['context'];$dirs_text=seo_growth_exec_dimension_text($i['dimension_labels']??array());$path=array_filter(array($c['cluster'],$c['primary'],$c['secondary'],$c['category']));echo '<tr><td><strong>'.absint($d['priority']).'/100</strong></td><td><strong>'.esc_html($i['label']).'</strong></td><td>'.esc_html($a['label']).'</td><td>'.($path?esc_html(implode(' → ',$path)):'Nueva ubicación por definir').'</td><td>'.($dirs_text!==''?esc_html($dirs_text):esc_html($a['supplier'])).'</td></tr>';}
    echo '</tbody></table></div></div><div>';

    echo '<div class="seo-growth-card"><h3 style="margin-top:0">Top oportunidades</h3>';
    foreach(array_slice($rows,0,10) as $r){$p=absint($r['decision']['priority']);echo '<div style="margin:0 0 12px"><div style="display:flex;justify-content:space-between;gap:10px"><strong>'.esc_html($r['item']['label']).'</strong><span>'.$p.'</span></div><div style="height:10px;background:#e9ecef;border-radius:999px;overflow:hidden;margin-top:4px"><span style="display:block;height:100%;width:'.$p.'%;background:#2271b1"></span></div><div class="seo-growth-meta">'.esc_html($r['exec_action']['label']).'</div></div>';}
    echo '</div>';

    echo '<div class="seo-growth-card"><h3 style="margin-top:0">Hubs a ampliar</h3>';
    if(!$hubs) echo '<p class="seo-growth-meta">Aún no hay suficientes oportunidades convergentes para recomendar ampliar un hub completo. Se exige al menos 3 señales distintas en el mismo hub.</p>';
    foreach(array_slice($hubs,0,8) as $h){echo '<div class="seo-growth-hub"><div style="display:flex;justify-content:space-between"><strong>'.esc_html($h['name']).'</strong><strong>'.absint($h['priority']).'/100</strong></div><div class="seo-growth-meta">'.esc_html(trim($h['cluster'].' → '.$h['primary'],' →')).'</div><div style="margin-top:5px">'.esc_html(implode(' · ',$h['items'])).'</div><div class="seo-growth-meta" style="margin-top:5px"><strong>Decisión:</strong> AUMENTAR CATEGORÍAS dentro de este hub antes de crear un hub nuevo.</div></div>';}
    echo '</div>';

    echo '<div class="seo-growth-card"><h3 style="margin-top:0">Reglas ejecutivas</h3><ul style="margin-left:18px"><li><strong>Mercado alto + cobertura baja:</strong> añadir catálogo.</li><li><strong>Categoría inexistente sin Trends:</strong> investigar, no crear todavía.</li><li><strong>Mercado Trends alto + categoría inexistente:</strong> probar nueva categoría.</li><li><strong>Varias oportunidades en un mismo hub:</strong> ampliar categorías del hub.</li><li><strong>Catálogo profundo + posición mala:</strong> SEO antes de comprar.</li><li><strong>Nuevo hub/cluster:</strong> solo cuando varias familias independientes formen una vertical coherente; no se automatiza con una única keyword.</li></ul></div>';
    echo '</div></div>';
}
