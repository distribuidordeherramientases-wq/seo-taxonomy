<?php
if (!defined('ABSPATH')) { exit; }

if (!defined('SEO_GOOGLE_TRENDS_VERSION')) define('SEO_GOOGLE_TRENDS_VERSION', '1.1.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_VERSION')) define('SEO_GOOGLE_TRENDS_DB_VERSION', '1.0.0');
if (!defined('SEO_GOOGLE_TRENDS_DB_OPTION')) define('SEO_GOOGLE_TRENDS_DB_OPTION', 'seo_google_trends_db_version');
if (!defined('SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION')) define('SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION', 'seo_google_trends_last_sync_v2');
if (!defined('SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT')) define('SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT', 'seo_google_trends_last_error_v2');
if (!defined('SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT')) define('SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT', 'seo_google_trends_sync_lock_v2');
if (!defined('SEO_GOOGLE_TRENDS_RETRY_TRANSIENT')) define('SEO_GOOGLE_TRENDS_RETRY_TRANSIENT', 'seo_google_trends_retry_after_v2');

add_action('admin_init', 'seo_google_trends_maybe_install', 2);
add_action('admin_post_seo_google_trends_import', 'seo_google_trends_import_handler');
add_action('admin_post_seo_google_trends_sync', 'seo_google_trends_sync_handler');
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
    if (is_int($raw) || is_float($raw)) return array((float)$raw, false);
    $s=trim((string)$raw); $breakout=false;
    if ($s==='') return array(0,false);
    $n=seo_google_trends_normalize($s);
    if (strpos($n,'breakout')!==false || strpos($n,'aumento puntual')!==false || $n==='aumento') { $breakout=true; return array(5000,true); }
    $numeric=preg_replace('/[^0-9,.-]+/','',$s);
    if ($numeric==='') return array(0,false);
    if (preg_match('/^-?\d{1,3}(?:[.,]\d{3})+$/',$numeric)) {
        $numeric=str_replace(array('.',','),'',$numeric);
    } elseif (strpos($numeric,',')!==false && strpos($numeric,'.')===false) {
        $numeric=str_replace(',','.',$numeric);
    } elseif (strpos($numeric,',')!==false && strpos($numeric,'.')!==false) {
        $last_comma=strrpos($numeric,','); $last_dot=strrpos($numeric,'.');
        if ($last_comma>$last_dot) $numeric=str_replace(array('.' , ','),array('','.'),$numeric);
        else $numeric=str_replace(',','',$numeric);
    }
    return array((float)$numeric,$breakout);
}


/**
 * Ultimo error del proveedor automatico de Trends, usado tambien por el panel
 * de Landing Pages para explicar por que no hay senales.
 */
function seo_google_trends_last_error() {
    $error=get_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
    return is_string($error) ? trim($error) : '';
}

function seo_google_trends_last_sync() {
    $state=get_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION,array());
    return is_array($state) ? $state : array();
}

/** Decodifica las respuestas JSON de Trends, que incluyen prefijo anti-XSSI. */
function seo_google_trends_decode_json($body) {
    $body=ltrim((string)$body);
    if ($body==='') return new WP_Error('seo_google_trends_empty_response','Google Trends ha devuelto una respuesta vacia.');
    $object_pos=strpos($body,'{');
    $array_pos=strpos($body,'[');
    if ($object_pos===false && $array_pos===false) return new WP_Error('seo_google_trends_invalid_response','Google Trends no ha devuelto JSON reconocible.');
    if ($object_pos===false) $start=$array_pos;
    elseif ($array_pos===false) $start=$object_pos;
    else $start=min($object_pos,$array_pos);
    $decoded=json_decode(substr($body,$start),true);
    if (!is_array($decoded)) return new WP_Error('seo_google_trends_invalid_json','No se pudo interpretar la respuesta de Google Trends.');
    return $decoded;
}

/**
 * Peticion al proveedor web de Google Trends. No reutiliza OAuth de Search
 * Console porque Trends es una fuente independiente.
 */
function seo_google_trends_web_request($method,$url,$query=array(),$cookies=array(),$geo='ES') {
    if (!empty($query)) $url=add_query_arg($query,$url);
    $args=array(
        'method'=>strtoupper((string)$method),
        'timeout'=>20,
        'redirection'=>3,
        'headers'=>array(
            'Accept'=>'application/json,text/javascript,*/*;q=0.8',
            'Accept-Language'=>'es-ES,es;q=0.9,en;q=0.7',
            'User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'Referer'=>'https://trends.google.com/trends/explore?geo='.rawurlencode($geo),
        ),
    );
    if (!empty($cookies)) $args['cookies']=$cookies;
    $response=wp_remote_request(esc_url_raw($url),$args);
    if (is_wp_error($response)) return $response;
    $code=(int)wp_remote_retrieve_response_code($response);
    if ($code<200 || $code>=300) {
        $message=$code===429
            ? 'Google Trends ha limitado temporalmente las consultas (HTTP 429). Vuelve a sincronizar mas tarde.'
            : 'Google Trends ha devuelto HTTP '.$code.'.';
        return new WP_Error('seo_google_trends_http_'.$code,$message);
    }
    return seo_google_trends_decode_json(wp_remote_retrieve_body($response));
}

/** Obtiene la cookie NID que exige actualmente la interfaz publica de Trends. */
function seo_google_trends_web_cookies($geo='ES') {
    $headers=array(
        'Accept-Language'=>'es-ES,es;q=0.9,en;q=0.7',
        'User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
    );
    $urls=array(
        'https://trends.google.com/_/TrendsUi/data/batchexecute',
        add_query_arg(array('geo'=>$geo),'https://trends.google.com/trends/'),
    );
    $jar=array(); $last_error='';
    foreach ($urls as $url) {
        $args=array('timeout'=>15,'redirection'=>3,'headers'=>$headers);
        if (!empty($jar)) $args['cookies']=array_values($jar);
        $response=wp_remote_get(esc_url_raw($url),$args);
        if (is_wp_error($response)) { $last_error=$response->get_error_message(); continue; }
        foreach ((array)wp_remote_retrieve_cookies($response) as $cookie) {
            if (is_object($cookie) && !empty($cookie->name)) $jar[(string)$cookie->name]=$cookie;
        }
        if (isset($jar['NID'])) return array_values($jar);
        $last_error='La respuesta de Google Trends no ha entregado la cookie NID.';
    }
    return new WP_Error('seo_google_trends_cookie_missing',$last_error!==''?$last_error:'No se pudo iniciar sesion con Google Trends.');
}

/**
 * Recupera Búsquedas relacionadas (Principales + En aumento) para una semilla.
 * Replica las llamadas que usa la interfaz de trends.google.com; la importacion
 * CSV sigue disponible como fallback si Google cambia ese contrato web.
 */
function seo_google_trends_fetch_related($seed,$geo='ES',$timeframe='today 12-m',$cookies=array()) {
    $seed=trim((string)$seed); if ($seed==='') return new WP_Error('seo_google_trends_seed_empty','Semilla vacia.');
    $request=array(
        'comparisonItem'=>array(array('keyword'=>$seed,'time'=>$timeframe,'geo'=>$geo)),
        'category'=>0,
        'property'=>'',
    );
    $explore=seo_google_trends_web_request('GET','https://trends.google.com/trends/api/explore',array(
        'hl'=>'es-ES',
        'tz'=>0,
        'req'=>wp_json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ),$cookies,$geo);
    if (is_wp_error($explore)) return $explore;

    $widget=null;
    foreach ((array)($explore['widgets']??array()) as $candidate) {
        if (strpos((string)($candidate['id']??''),'RELATED_QUERIES')!==false && !empty($candidate['token']) && !empty($candidate['request'])) {
            $widget=$candidate; break;
        }
    }
    if (!$widget) return new WP_Error('seo_google_trends_no_widget','Google Trends no ha devuelto el bloque de búsquedas relacionadas para "'.$seed.'".');

    // Google aplica un limitador agresivo a las llamadas de widgetdata.
    usleep(850000);
    $related=seo_google_trends_web_request('GET','https://trends.google.com/trends/api/widgetdata/relatedsearches',array(
        'hl'=>'es-ES',
        'tz'=>0,
        'req'=>wp_json_encode($widget['request'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'token'=>(string)$widget['token'],
    ),$cookies,$geo);
    if (is_wp_error($related)) return $related;

    $ranked=(array)($related['default']['rankedList']??array());
    $out=array();
    foreach (array(0=>'top',1=>'rising') as $index=>$type) {
        foreach ((array)($ranked[$index]['rankedKeyword']??array()) as $row) {
            $query=sanitize_text_field((string)($row['query']??''));
            if ($query==='') continue;
            $formatted=(string)($row['formattedValue']??'');
            $breakout=false;
            if ($formatted!=='') {
                list($formatted_value,$formatted_breakout)=seo_google_trends_parse_value($formatted);
                $breakout=$formatted_breakout;
            } else $formatted_value=0;
            $value=isset($row['value']) && is_numeric($row['value']) ? (float)$row['value'] : (float)$formatted_value;
            if ($breakout) $value=max(5000,$value);
            $out[]=array('query'=>$query,'type'=>$type,'value'=>$value,'breakout'=>$breakout);
        }
    }
    return $out;
}

/** Guarda/reemplaza las señales automaticas de una semilla. */
function seo_google_trends_store_auto_signals($seed,$rows,$geo='ES',$timeframe='today 12-m') {
    global $wpdb; seo_google_trends_maybe_install(); $table=seo_google_trends_table();
    $seed_hash=hash('sha256',seo_google_trends_normalize($seed));
    $source='Google Trends web automatico';
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE seed_hash=%s AND geo=%s AND timeframe=%s AND source_note=%s",$seed_hash,$geo,$timeframe,$source));
    $count=0; $now=current_time('mysql');
    foreach ((array)$rows as $row) {
        $query=sanitize_text_field((string)($row['query']??'')); if ($query==='') continue;
        $type=sanitize_key((string)($row['type']??'related')); if (!in_array($type,array('top','rising','related'),true)) $type='related';
        $value=(float)($row['value']??0); $breakout=!empty($row['breakout'])?1:0;
        $sql=$wpdb->prepare("INSERT INTO {$table} (seed,seed_hash,query_text,query_hash,result_type,trend_value,is_breakout,geo,timeframe,source_note,imported_at) VALUES (%s,%s,%s,%s,%s,%f,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE query_text=VALUES(query_text), trend_value=VALUES(trend_value), is_breakout=VALUES(is_breakout), source_note=VALUES(source_note), imported_at=VALUES(imported_at)",$seed,$seed_hash,$query,hash('sha256',seo_google_trends_normalize($query)),$type,$value,$breakout,$geo,$timeframe,$source,$now);
        if ($wpdb->query($sql)!==false) $count++;
    }
    return $count;
}

/**
 * Sincroniza automaticamente un grupo pequeño de semillas para evitar ráfagas
 * y bloqueos 429. Se reutiliza durante 12 h; el boton manual puede forzarla.
 */
function seo_google_trends_sync($force=false,$limit=5) {
    seo_google_trends_maybe_install();
    $limit=max(5,min(8,(int)$limit));
    $state=seo_google_trends_last_sync();
    $has_rows=!empty(seo_google_trends_get_signals(1));
    if (!$force && $has_rows && !empty($state['timestamp']) && (time()-(int)$state['timestamp'])<12*HOUR_IN_SECONDS && !empty($state['signals'])) return $state;
    if (!$force && get_transient(SEO_GOOGLE_TRENDS_RETRY_TRANSIENT)) {
        $error=seo_google_trends_last_error();
        return new WP_Error('seo_google_trends_backoff',$error!==''?$error:'Google Trends esta en periodo de espera tras un error reciente.');
    }
    if (get_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT)) return new WP_Error('seo_google_trends_busy','Ya hay una sincronizacion de Google Trends en curso.');
    set_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT,1,3*MINUTE_IN_SECONDS);

    $geo='ES'; $timeframe='today 12-m'; $signals=0; $ok_seeds=0; $errors=array();
    $cookies=seo_google_trends_web_cookies($geo);
    if (is_wp_error($cookies)) {
        delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);
        $message=$cookies->get_error_message();
        set_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT,$message,DAY_IN_SECONDS);
        set_transient(SEO_GOOGLE_TRENDS_RETRY_TRANSIENT,1,10*MINUTE_IN_SECONDS);
        return $cookies;
    }

    $all_seeds=seo_google_trends_seed_candidates(24);
    $seed_total=count($all_seeds);
    $offset=$seed_total>0 ? ((int)($state['next_offset']??0) % $seed_total) : 0;
    $seeds=$seed_total>0 ? array_slice($all_seeds,$offset,$limit) : array();
    if ($seed_total>0 && count($seeds)<$limit) $seeds=array_merge($seeds,array_slice($all_seeds,0,$limit-count($seeds)));
    $seed_position=0; $seed_batch_count=count($seeds); $advance=0;
    foreach ($seeds as $seed_row) {
        $seed_position++;
        $seed=trim((string)($seed_row['label']??''));
        if ($seed==='') { $advance++; continue; }
        $rows=seo_google_trends_fetch_related($seed,$geo,$timeframe,$cookies);
        if (is_wp_error($rows)) {
            $errors[]=$seed.': '.$rows->get_error_message();
            if ($rows->get_error_code()==='seo_google_trends_http_429') break;
            $advance++;
        } else {
            $stored=seo_google_trends_store_auto_signals($seed,$rows,$geo,$timeframe);
            if ($stored>0) { $signals+=$stored; $ok_seeds++; }
            $advance++;
        }
        if ($seed_position<$seed_batch_count) usleep(900000);
    }

    delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);
    if ($signals<=0) {
        $message=!empty($errors)?implode(' | ',array_slice($errors,0,3)):'Google Trends no ha devuelto búsquedas relacionadas para las semillas seleccionadas.';
        set_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT,$message,DAY_IN_SECONDS);
        set_transient(SEO_GOOGLE_TRENDS_RETRY_TRANSIENT,1,10*MINUTE_IN_SECONDS);
        return new WP_Error('seo_google_trends_no_signals',$message);
    }

    delete_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
    delete_transient(SEO_GOOGLE_TRENDS_RETRY_TRANSIENT);
    $state=array(
        'timestamp'=>time(),
        'synced_at'=>current_time('mysql'),
        'signals'=>$signals,
        'seeds'=>$ok_seeds,
        'errors'=>count($errors),
        'provider'=>'Google Trends web',
        'next_offset'=>$seed_total>0 ? (($offset+max(1,$advance))%$seed_total) : 0,
    );
    update_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION,$state,false);

    global $wpdb; $table=seo_google_trends_table();
    $cutoff=date('Y-m-d H:i:s',current_time('timestamp')-(90*DAY_IN_SECONDS));
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source_note=%s AND imported_at < %s",'Google Trends web automatico',$cutoff));
    return $state;
}

function seo_google_trends_sync_handler() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    check_admin_referer('seo_google_trends_sync','seo_google_trends_sync_nonce');
    $result=seo_google_trends_sync(true,5);
    $args=is_wp_error($result)
        ? array('trends_error'=>'sync','detail'=>$result->get_error_message())
        : array('trends_notice'=>'synced','rows'=>(int)($result['signals']??0),'seeds'=>(int)($result['seeds']??0));
    wp_safe_redirect(seo_google_admin_url('trends_market',$args)); exit;
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
    delete_option(SEO_GOOGLE_TRENDS_LAST_SYNC_OPTION);
    delete_transient(SEO_GOOGLE_TRENDS_LAST_ERROR_TRANSIENT);
    delete_transient(SEO_GOOGLE_TRENDS_RETRY_TRANSIENT);
    delete_transient(SEO_GOOGLE_TRENDS_SYNC_LOCK_TRANSIENT);
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
    seo_google_trends_maybe_install(); $seeds=seo_google_trends_seed_candidates(24); $signals=seo_google_trends_get_signals(120); $last_sync=seo_google_trends_last_sync();
    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:6px;margin-bottom:20px;">';
    echo '<h3 style="margin-top:0;">Mercado Google · Trends</h3><p><strong>Objetivo:</strong> descubrir qué busca la gente alrededor de las áreas de la tienda, aunque nuestra web todavía no aparezca. Trends aporta interés relativo, búsquedas relacionadas y crecimiento; no equivale a ventas ni a volumen absoluto.</p>';
    echo '<p><code>V'.esc_html(SEO_GOOGLE_TRENDS_VERSION).'</code> · Fuente separada de Search Console. La sincronización automática usa la interfaz pública de Trends; el CSV queda como respaldo.</p>';
    if (($_GET['trends_notice']??'')==='imported') echo '<div class="notice notice-success inline"><p>Importación completada: '.number_format_i18n(absint($_GET['rows']??0)).' filas procesadas.</p></div>';
    if (($_GET['trends_notice']??'')==='synced') echo '<div class="notice notice-success inline"><p>Google Trends sincronizado: '.number_format_i18n(absint($_GET['rows']??0)).' señales de '.number_format_i18n(absint($_GET['seeds']??0)).' semillas.</p></div>';
    if (!empty($_GET['trends_error'])) {
        $detail=isset($_GET['detail'])?sanitize_text_field(wp_unslash($_GET['detail'])):'';
        echo '<div class="notice notice-error inline"><p>'.esc_html($detail!==''?$detail:'No se pudo completar la operación de Google Trends.').'</p></div>';
    }
    if (!empty($last_sync['synced_at'])) echo '<p><small>Última sincronización automática: '.esc_html($last_sync['synced_at']).' · '.number_format_i18n((int)($last_sync['signals']??0)).' señales.</small></p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px;"><input type="hidden" name="action" value="seo_google_trends_sync">';
    wp_nonce_field('seo_google_trends_sync','seo_google_trends_sync_nonce'); submit_button('Sincronizar Google Trends ahora','primary','submit',false); echo '</form></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-bottom:20px;"><h3 style="margin-top:0;">1. Semillas recomendadas</h3><p>Estas semillas salen de Search Console + catálogo. La sincronización automática consulta sus <strong>Búsquedas relacionadas → Principales y En aumento</strong>. También puedes abrir una semilla manualmente para contrastarla.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px;">';
    foreach ($seeds as $s) { echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:12px;"><strong>'.esc_html($s['label']).'</strong><br><small>'.esc_html($s['source']).' · '.esc_html($s['strategy']).'</small><br><a class="button button-small" target="_blank" rel="noopener" href="'.esc_url(seo_google_trends_explore_url($s['label'])).'">Abrir en Google Trends</a></div>'; }
    echo '</div></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;margin-bottom:20px;"><h3 style="margin-top:0;">2. Importación CSV de respaldo</h3><p class="description">Úsala solo si Google limita temporalmente la sincronización automática o si quieres cargar un análisis concreto.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="seo_google_trends_import">'; wp_nonce_field('seo_google_trends_import','seo_google_trends_nonce');
    echo '<table class="form-table"><tr><th>Semilla analizada</th><td><input required name="seed" class="regular-text" placeholder="Ej.: neveras portátiles"></td></tr><tr><th>Tipo</th><td><select name="result_type"><option value="rising">En aumento</option><option value="top">Principales</option><option value="related">Relacionadas</option></select></td></tr><tr><th>País</th><td><input name="geo" value="ES" size="6"></td></tr><tr><th>Periodo</th><td><input name="timeframe" value="12m" size="12"></td></tr><tr><th>CSV</th><td><input required type="file" name="trends_csv" accept=".csv,text/csv"></td></tr></table>';
    submit_button('Importar Trends'); echo '</form><p class="description">Los datos se guardan localmente y se reutilizan en el informe Qué potenciar.</p></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;overflow:auto;"><h3 style="margin-top:0;">Señales de mercado almacenadas</h3>';
    if (!$signals) echo '<p>Todavía no hay datos de Trends almacenados.</p>'; else { echo '<table class="widefat striped"><thead><tr><th>Semilla</th><th>Búsqueda relacionada</th><th>Tipo</th><th>Crecimiento/valor</th><th>País</th><th>Importado</th></tr></thead><tbody>'; foreach ($signals as $r) { echo '<tr><td>'.esc_html($r['seed']).'</td><td><strong>'.esc_html($r['query_text']).'</strong></td><td>'.esc_html($r['result_type']).'</td><td>'.(!empty($r['is_breakout'])?'<strong>BREAKOUT</strong>':esc_html(number_format_i18n((float)$r['trend_value'],0).'%')).'</td><td>'.esc_html($r['geo']).'</td><td>'.esc_html($r['imported_at']).'</td></tr>'; } echo '</tbody></table>'; }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:14px;" onsubmit="return confirm(\'¿Borrar todos los datos importados de Trends?\');"><input type="hidden" name="action" value="seo_google_trends_clear">'; wp_nonce_field('seo_google_trends_clear','seo_google_trends_clear_nonce'); submit_button('Vaciar datos de Trends','delete','submit',false); echo '</form></div>';
}
