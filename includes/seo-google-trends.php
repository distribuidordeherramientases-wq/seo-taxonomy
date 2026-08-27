<?php
if (!defined('ABSPATH')) { exit; }

if (!defined('SEO_GOOGLE_TRENDS_VERSION')) define('SEO_GOOGLE_TRENDS_VERSION', '1.0.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_VERSION')) define('SEO_GOOGLE_TRENDS_DB_VERSION', '1.0.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_OPTION')) define('SEO_GOOGLE_TRENDS_DB_OPTION', 'seo_google_trends_db_version');

add_action('admin_init', 'seo_google_trends_maybe_install', 2);
add_action('admin_post_seo_google_trends_import', 'seo_google_trends_import_handler');
add_action('admin_post_seo_google_trends_clear', 'seo_google_trends_clear_handler');

function seo_google_trends_table() { global $wpdb; return $wpdb->prefix . 'seo_google_trends'; }
function seo_google_trends_maybe_install() {
    global $wpdb;
    $table = seo_google_trends_table();
    if (get_option(SEO_GOOGLE_TRENDS_DB_OPTION) === SEO_GOOGLE_TRENDS_DB_VERSION && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        seed varchar(255) NOT NULL,
        seed_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        query_text varchar(512) NOT NULL,
        query_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        result_type varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'related',
        trend_value double NOT NULL DEFAULT 0,
        is_breakout tinyint(1) unsigned NOT NULL DEFAULT 0,
        geo varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ES',
        timeframe varchar(40) NOT NULL DEFAULT '',
        source_note varchar(255) NOT NULL DEFAULT '',
        imported_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_signal (seed_hash,query_hash,result_type,geo,timeframe),
        KEY seed_hash (seed_hash), KEY query_hash (query_hash), KEY result_type (result_type), KEY imported_at (imported_at)
    ) {$collate};";
    dbDelta($sql);
    update_option(SEO_GOOGLE_TRENDS_DB_OPTION, SEO_GOOGLE_TRENDS_DB_VERSION, false);
}

function seo_google_trends_normalize($text) {
    $text = remove_accents(wp_strip_all_tags((string)$text));
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function seo_google_trends_explore_url($term, $geo='ES', $time='today 12-m') {
    return add_query_arg(array('geo'=>$geo,'date'=>$time,'q'=>$term), 'https://trends.google.com/trends/explore');
}

function seo_google_trends_seed_candidates($limit=24) {
    $items=array();
    $settings=function_exists('seo_google_get_settings') ? seo_google_get_settings() : array();
    if (!empty($settings['property_id']) && function_exists('seo_google_demand_get_catalog_guidance')) {
        $g=seo_google_demand_get_catalog_guidance($settings['property_id'], 60, 2, 30);
        foreach ((array)($g['items']??array()) as $row) {
            if (($row['catalog_relevance']??'catalog') === 'corporate') continue;
            $label=trim((string)($row['label']??'')); if ($label==='') continue;
            $items[$label]=array('label'=>$label,'score'=>(int)($row['score']??0),'source'=>'Search Console + catálogo','strategy'=>$row['strategy']['primary']??$row['decision']??'');
        }
    }
    if (taxonomy_exists('product_cat')) {
        $terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'number'=>80,'orderby'=>'count','order'=>'DESC'));
        if (!is_wp_error($terms)) foreach ($terms as $t) {
            if (isset($items[$t->name])) continue;
            $items[$t->name]=array('label'=>$t->name,'score'=>min(55, 15+(int)round(log(1+max(0,(int)$t->count))*8)),'source'=>'Catálogo','strategy'=>'EXPLORAR');
        }
    }
    uasort($items, function($a,$b){ return $b['score']<=>$a['score']; });
    return array_slice(array_values($items),0,max(5,min(60,(int)$limit)));
}

function seo_google_trends_detect_columns($header) {
    $map=array();
    foreach ($header as $i=>$h) {
        $n=seo_google_trends_normalize($h);
        if (preg_match('/^(query|consulta|related query|busqueda relacionada|termino|term)$/',$n)) $map['query']=$i;
        elseif (preg_match('/^(value|valor|growth|crecimiento|interest|interes)$/',$n)) $map['value']=$i;
        elseif (preg_match('/^(type|tipo)$/',$n)) $map['type']=$i;
        elseif (preg_match('/^(seed|semilla|topic|tema)$/',$n)) $map['seed']=$i;
    }
    return $map;
}

function seo_google_trends_parse_value($raw) {
    $s=trim((string)$raw); $breakout=false;
    if ($s==='') return array(0,false);
    $n=seo_google_trends_normalize($s);
    if (strpos($n,'breakout')!==false || strpos($n,'aumento puntual')!==false || $n==='aumento') { $breakout=true; return array(5000,true); }
    $s=str_replace(array('%','+','.',','),array('','','','.'),$s);
    return array((float)$s,$breakout);
}

function seo_google_trends_import_handler() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    check_admin_referer('seo_google_trends_import','seo_google_trends_nonce');
    seo_google_trends_maybe_install();
    $seed=sanitize_text_field(wp_unslash($_POST['seed']??''));
    $type=sanitize_key(wp_unslash($_POST['result_type']??'rising'));
    if (!in_array($type,array('rising','top','related'),true)) $type='related';
    $geo=strtoupper(sanitize_text_field(wp_unslash($_POST['geo']??'ES')));
    $timeframe=sanitize_text_field(wp_unslash($_POST['timeframe']??'12m'));
    if ($seed==='' || empty($_FILES['trends_csv']['tmp_name'])) { wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_error'=>'missing'))); exit; }
    $fh=fopen($_FILES['trends_csv']['tmp_name'],'r'); if (!$fh) { wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_error'=>'file'))); exit; }
    $first=fgets($fh); rewind($fh); $delimiter=(substr_count((string)$first,';')>substr_count((string)$first,','))?';':',';
    $header=fgetcsv($fh,0,$delimiter); if (!$header) { fclose($fh); wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_error'=>'header'))); exit; }
    $cols=seo_google_trends_detect_columns($header);
    if (!isset($cols['query'])) { // Google Trends exports can start with metadata lines; retry scanning next 8 lines.
        for ($k=0;$k<8;$k++) { $h=fgetcsv($fh,0,$delimiter); if (!$h) break; $m=seo_google_trends_detect_columns($h); if (isset($m['query'])) { $header=$h; $cols=$m; break; } }
    }
    if (!isset($cols['query'])) { fclose($fh); wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_error'=>'columns'))); exit; }
    global $wpdb; $table=seo_google_trends_table(); $count=0; $now=current_time('mysql');
    while (($row=fgetcsv($fh,0,$delimiter))!==false) {
        $q=sanitize_text_field($row[$cols['query']]??''); if ($q==='') continue;
        $row_seed=isset($cols['seed']) ? sanitize_text_field($row[$cols['seed']]??'') : $seed; if ($row_seed==='') $row_seed=$seed;
        $row_type=isset($cols['type']) ? sanitize_key($row[$cols['type']]??$type) : $type; if (!in_array($row_type,array('rising','top','related'),true)) $row_type=$type;
        list($value,$breakout)=seo_google_trends_parse_value(isset($cols['value'])?$row[$cols['value']]:0);
        $sql=$wpdb->prepare("INSERT INTO {$table} (seed,seed_hash,query_text,query_hash,result_type,trend_value,is_breakout,geo,timeframe,source_note,imported_at) VALUES (%s,%s,%s,%s,%s,%f,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE query_text=VALUES(query_text), trend_value=VALUES(trend_value), is_breakout=VALUES(is_breakout), imported_at=VALUES(imported_at)", $row_seed,hash('sha256',seo_google_trends_normalize($row_seed)),$q,hash('sha256',seo_google_trends_normalize($q)),$row_type,$value,$breakout?1:0,$geo,$timeframe,'CSV Google Trends',$now);
        if ($wpdb->query($sql)!==false) $count++;
    }
    fclose($fh);
    wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_notice'=>'imported','rows'=>$count))); exit;
}

function seo_google_trends_clear_handler() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    check_admin_referer('seo_google_trends_clear','seo_google_trends_clear_nonce');
    global $wpdb; $wpdb->query('TRUNCATE TABLE '.seo_google_trends_table());
    wp_safe_redirect(seo_google_admin_url('trends_market',array('trends_notice'=>'cleared'))); exit;
}

function seo_google_trends_get_signals($limit=500) {
    global $wpdb; seo_google_trends_maybe_install(); $table=seo_google_trends_table();
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY is_breakout DESC, trend_value DESC, imported_at DESC LIMIT %d", max(1,min(5000,(int)$limit))), ARRAY_A);
}

function seo_google_trends_market_summary($limit=250) {
    $rows=seo_google_trends_get_signals($limit); $out=array();
    foreach ($rows as $r) {
        $k=seo_google_trends_normalize($r['query_text']); if ($k==='') continue;
        if (!isset($out[$k])) $out[$k]=array('query'=>$r['query_text'],'score'=>0,'max_growth'=>0,'breakout'=>false,'seeds'=>array(),'types'=>array());
        $growth=(float)$r['trend_value']; $score=$r['is_breakout']?100:min(100,20+log(1+max(0,$growth))*12);
        $out[$k]['score']=max($out[$k]['score'],$score); $out[$k]['max_growth']=max($out[$k]['max_growth'],$growth); $out[$k]['breakout']=$out[$k]['breakout']||!empty($r['is_breakout']);
        $out[$k]['seeds'][$r['seed']]=true; $out[$k]['types'][$r['result_type']]=true;
    }
    foreach ($out as &$r) { $r['seeds']=array_keys($r['seeds']); $r['types']=array_keys($r['types']); } unset($r);
    uasort($out,function($a,$b){ return $b['score']<=>$a['score']; }); return array_values($out);
}

function seo_google_render_trends_market() {
    seo_google_trends_maybe_install(); $seeds=seo_google_trends_seed_candidates(24); $signals=seo_google_trends_get_signals(120);
    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:6px;margin-bottom:20px;">';
    echo '<h3 style="margin-top:0;">Mercado Google · Trends</h3><p><strong>Objetivo:</strong> descubrir qué busca la gente alrededor de las áreas de la tienda, aunque nuestra web todavía no aparezca. Trends aporta interés relativo, búsquedas relacionadas y crecimiento; no equivale a ventas ni a volumen absoluto.</p>';
    echo '<p><code>V'.esc_html(SEO_GOOGLE_TRENDS_VERSION).'</code> · Fuente separada de Search Console.</p>';
    if (($_GET['trends_notice']??'')==='imported') echo '<div class="notice notice-success inline"><p>Importación completada: '.number_format_i18n(absint($_GET['rows']??0)).' filas procesadas.</p></div>';
    if (!empty($_GET['trends_error'])) echo '<div class="notice notice-error inline"><p>No se pudo importar el CSV. Comprueba que contiene una columna Consulta/Query y, si es posible, Valor/Value.</p></div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-bottom:20px;"><h3 style="margin-top:0;">1. Semillas recomendadas para explorar</h3><p>Estas semillas salen de Search Console + catálogo. Abre Trends, revisa <strong>Búsquedas relacionadas → Principales y En aumento</strong>, exporta CSV e impórtalo abajo.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px;">';
    foreach ($seeds as $s) { echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:12px;"><strong>'.esc_html($s['label']).'</strong><br><small>'.esc_html($s['source']).' · '.esc_html($s['strategy']).'</small><br><a class="button button-small" target="_blank" rel="noopener" href="'.esc_url(seo_google_trends_explore_url($s['label'])).'">Abrir en Google Trends</a></div>'; }
    echo '</div></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-bottom:20px;"><h3 style="margin-top:0;">2. Importar búsquedas relacionadas de Trends</h3><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="seo_google_trends_import">'; wp_nonce_field('seo_google_trends_import','seo_google_trends_nonce');
    echo '<table class="form-table"><tr><th>Semilla analizada</th><td><input required name="seed" class="regular-text" placeholder="Ej.: neveras portátiles"></td></tr><tr><th>Tipo</th><td><select name="result_type"><option value="rising">En aumento</option><option value="top">Principales</option><option value="related">Relacionadas</option></select></td></tr><tr><th>País</th><td><input name="geo" value="ES" size="6"></td></tr><tr><th>Periodo</th><td><input name="timeframe" value="12m" size="12"></td></tr><tr><th>CSV</th><td><input required type="file" name="trends_csv" accept=".csv,text/csv"></td></tr></table>';
    submit_button('Importar Trends'); echo '</form><p class="description">No se consulta Google Ads. Los datos se guardan localmente y se reutilizan en el informe Qué potenciar.</p></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;overflow:auto;"><h3 style="margin-top:0;">Señales de mercado almacenadas</h3>';
    if (!$signals) echo '<p>Todavía no hay datos de Trends importados.</p>'; else { echo '<table class="widefat striped"><thead><tr><th>Semilla</th><th>Búsqueda relacionada</th><th>Tipo</th><th>Crecimiento/valor</th><th>País</th><th>Importado</th></tr></thead><tbody>'; foreach ($signals as $r) { echo '<tr><td>'.esc_html($r['seed']).'</td><td><strong>'.esc_html($r['query_text']).'</strong></td><td>'.esc_html($r['result_type']).'</td><td>'.(!empty($r['is_breakout'])?'<strong>BREAKOUT</strong>':esc_html(number_format_i18n((float)$r['trend_value'],0).'%')).'</td><td>'.esc_html($r['geo']).'</td><td>'.esc_html($r['imported_at']).'</td></tr>'; } echo '</tbody></table>'; }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:14px;" onsubmit="return confirm(\'¿Borrar todos los datos importados de Trends?\');"><input type="hidden" name="action" value="seo_google_trends_clear">'; wp_nonce_field('seo_google_trends_clear','seo_google_trends_clear_nonce'); submit_button('Vaciar datos de Trends','delete','submit',false); echo '</form></div>';
}
